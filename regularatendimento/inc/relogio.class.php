<?php

/**
 * Plugin Regular Atendimento - relógio por chamado
 * Roda enquanto o chamado está em atendimento, pausa quando fica pendente, finaliza e cobra na solução;
 * reabrir estorna a cobrança. Cada trecho trabalhado é um período (com o tempo fora do calendário do contrato).
 */
class PluginRegularatendimentoRelogio
{
    public const TABELA = 'glpi_plugin_regularatendimento_relogios';
    public const PERIODOS = 'glpi_plugin_regularatendimento_periodos';
    public const FK = 'plugin_regularatendimento_relogios_id';

    /** Chamados criados nesta requisição como atendimento retroativo */
    private static array $retroativos = [];
    private static bool $criandoRetroativo = false;

    public static function estados(): array
    {
        return [
            'parado'     => ['Parado', 'neutro', 'ti ti-player-stop'],
            'rodando'    => ['Rodando', 'andamento', 'ti ti-player-play'],
            'pausado'    => ['Pausado', 'aguardando', 'ti ti-player-pause'],
            'finalizado' => ['Finalizado', 'ok', 'ti ti-flag'],
        ];
    }

    public static function obter(int $ticket): ?array
    {
        global $DB;
        return $DB->request(['FROM' => self::TABELA, 'WHERE' => ['tickets_id' => $ticket], 'LIMIT' => 1])->current() ?: null;
    }

    public static function garantir(int $ticket): ?array
    {
        global $DB;
        $r = self::obter($ticket);
        if ($r) {
            return $r;
        }
        $t = new Ticket();
        if (!$t->getFromDB($ticket)) {
            return null;
        }
        $DB->insert(self::TABELA, ['tickets_id' => $ticket, 'entities_id' => (int) $t->fields['entities_id'], 'estado' => 'parado']);
        return self::obter($ticket);
    }

    public static function periodos(int $relogio): array
    {
        global $DB;
        return iterator_to_array($DB->request(['FROM' => self::PERIODOS, 'WHERE' => [self::FK => $relogio], 'ORDER' => 'id ASC']), false);
    }

    public static function segundosAgora(array $r): int
    {
        $s = (int) $r['segundos'];
        if ($r['estado'] === 'rodando' && !empty($r['rodando_desde'])) {
            $s += max(0, time() - strtotime((string) $r['rodando_desde']));
        }
        return $s;
    }

    public static function contratoDo(array $r): ?array
    {
        return PluginRegularatendimentoContrato::paraEntidade((int) $r['entities_id']);
    }

    /** Segundos de um intervalo que ficam fora do calendário do contrato */
    public static function segundosFora(?array $contrato, int $inicio, int $fim): int
    {
        $total = max(0, $fim - $inicio);
        if (!$contrato || (int) $contrato['calendars_id'] <= 0 || $total === 0) {
            return 0;
        }
        $cal = new Calendar();
        if (!$cal->getFromDB((int) $contrato['calendars_id'])) {
            return 0;
        }
        $dentro = (int) $cal->getActiveTimeBetween(date('Y-m-d H:i:s', $inicio), date('Y-m-d H:i:s', $fim));
        return max(0, $total - min($total, $dentro));
    }

    /** Minutos cobráveis: arredonda para cima ao minuto, aplica o mínimo e o passo de arredondamento do contrato */
    public static function minutosCobraveis(int $segundos, ?array $contrato): int
    {
        if ($segundos <= 0) {
            return 0;
        }
        $min = (int) ceil($segundos / 60);
        $minimo = $contrato ? (int) $contrato['minimo_minutos'] : (int) PluginRegularatendimentoConfig::getConfig('padrao_minimo');
        $passo = $contrato ? (int) $contrato['arredondamento'] : (int) PluginRegularatendimentoConfig::getConfig('padrao_arredondamento');
        $min = max($min, $minimo);
        if ($passo > 0) {
            $min = (int) (ceil($min / $passo) * $passo);
        }
        return $min;
    }

    /** Divide os minutos cobrados entre dentro e fora do horário, na proporção dos segundos trabalhados */
    public static function dividir(int $minutos, int $segundos, int $segundosFora): array
    {
        $fora = $segundos > 0 ? (int) round($minutos * min(1, $segundosFora / $segundos)) : 0;
        return [$minutos - $fora, $fora];
    }

    // ------------------------------------------------------------------ ações

    /** @return array{0: bool, 1: string} */
    public static function iniciar(int $ticket, string $origem = 'manual'): array
    {
        global $DB;
        $r = self::garantir($ticket);
        if (!$r) {
            return [false, 'Chamado não encontrado.'];
        }
        if ($r['estado'] === 'rodando') {
            return [false, 'O relógio já está rodando.'];
        }
        if ($r['estado'] === 'finalizado') {
            return [false, 'O atendimento já foi finalizado. Reabra o chamado para continuar.'];
        }
        $agora = date('Y-m-d H:i:s');
        $DB->insert(self::PERIODOS, [self::FK => (int) $r['id'], 'inicio' => $agora, 'origem_inicio' => $origem, 'users_id_inicio' => (int) Session::getLoginUserID()]);
        $DB->update(self::TABELA, ['estado' => 'rodando', 'rodando_desde' => $agora, 'iniciado_em' => $r['iniciado_em'] ?: $agora], ['id' => $r['id']]);
        return [true, $r['estado'] === 'pausado' ? 'Relógio retomado.' : 'Relógio iniciado.'];
    }

    private static function fecharPeriodo(array $r, int $fim, string $motivo, string $origem): void
    {
        global $DB;
        $p = $DB->request(['FROM' => self::PERIODOS, 'WHERE' => [self::FK => (int) $r['id'], 'fim' => null], 'ORDER' => 'id DESC', 'LIMIT' => 1])->current();
        $inicio = $p ? strtotime((string) $p['inicio']) : (!empty($r['rodando_desde']) ? strtotime((string) $r['rodando_desde']) : $fim);
        $seg = max(0, $fim - $inicio);
        $fora = self::segundosFora(self::contratoDo($r), $inicio, $fim);
        if ($p) {
            $DB->update(self::PERIODOS, ['fim' => date('Y-m-d H:i:s', $fim), 'segundos' => $seg, 'segundos_fora' => $fora, 'origem_fim' => $origem, 'motivo_fim' => mb_substr($motivo, 0, 255), 'users_id_fim' => (int) Session::getLoginUserID()], ['id' => $p['id']]);
        }
        $DB->update(self::TABELA, ['segundos' => (int) $r['segundos'] + $seg, 'segundos_fora' => (int) $r['segundos_fora'] + $fora, 'rodando_desde' => null], ['id' => $r['id']]);
    }

    /** @return array{0: bool, 1: string} */
    public static function pausar(int $ticket, string $motivo, string $origem = 'manual'): array
    {
        global $DB;
        $r = self::obter($ticket);
        if (!$r || $r['estado'] !== 'rodando') {
            return [false, 'O relógio não está rodando.'];
        }
        self::fecharPeriodo($r, time(), $motivo !== '' ? $motivo : 'Pausado', $origem);
        $DB->update(self::TABELA, ['estado' => 'pausado'], ['id' => $r['id']]);
        return [true, 'Relógio pausado.'];
    }

    /** Finaliza e cobra (solução do chamado) */
    public static function finalizar(int $ticket, string $motivo = 'Chamado solucionado'): bool
    {
        global $DB;
        $r = self::garantir($ticket);
        if (!$r || $r['estado'] === 'finalizado') {
            return false;
        }
        if ($r['estado'] === 'rodando') {
            self::fecharPeriodo($r, time(), $motivo, 'automatico');
        }
        $DB->update(self::TABELA, ['estado' => 'finalizado', 'finalizado_em' => date('Y-m-d H:i:s')], ['id' => $r['id']]);
        self::cobrar(self::obter($ticket));
        return true;
    }

    private static function cobrar(array $r): void
    {
        global $DB;
        $seg = (int) $r['segundos'];
        $contrato = self::contratoDo($r);
        $min = self::minutosCobraveis($seg, $contrato);
        $lanc = 0;
        if ($contrato && $min > 0) {
            [$dentro, $fora] = self::dividir($min, $seg, (int) $r['segundos_fora']);
            $obs = 'Chamado #' . (int) $r['tickets_id'] . ' solucionado: tempo trabalhado ' . PluginRegularatendimentoConfig::relogio($seg) . ', cobrado ' . PluginRegularatendimentoConfig::horas($min) . ($fora > 0 ? ' (' . PluginRegularatendimentoConfig::horas($fora) . ' fora do horário)' : '');
            $lanc = PluginRegularatendimentoLancamento::debitar($contrato, $dentro, $fora, (int) $r['tickets_id'], $obs);
        }
        $DB->update(self::TABELA, ['minutos_cobrados' => $min, 'plugin_regularatendimento_lancamentos_id' => $lanc], ['id' => $r['id']]);
    }

    /** Chamado reaberto: estorna a cobrança e volta a pausado */
    public static function reabrir(int $ticket): bool
    {
        global $DB;
        $r = self::obter($ticket);
        if (!$r || $r['estado'] !== 'finalizado') {
            return false;
        }
        if ((int) $r['plugin_regularatendimento_lancamentos_id'] > 0) {
            PluginRegularatendimentoLancamento::estornar((int) $r['plugin_regularatendimento_lancamentos_id'], 'Chamado #' . $ticket . ' reaberto: cobrança estornada');
        }
        $DB->update(self::TABELA, ['estado' => 'pausado', 'minutos_cobrados' => 0, 'plugin_regularatendimento_lancamentos_id' => 0, 'is_ajustado' => 0, 'finalizado_em' => null], ['id' => $r['id']]);
        return true;
    }

    /** Ajuste manual do tempo cobrado de um atendimento finalizado */
    public static function ajustar(int $ticket, int $minutos, string $motivo): array
    {
        global $DB;
        $r = self::obter($ticket);
        if (!$r || $r['estado'] !== 'finalizado') {
            return [false, 'Só é possível ajustar o tempo de um atendimento finalizado.'];
        }
        if ($minutos < 0) {
            return [false, 'Informe um tempo válido.'];
        }
        if (trim($motivo) === '') {
            return [false, 'Escreva o motivo do ajuste.'];
        }
        $contrato = self::contratoDo($r);
        if ((int) $r['plugin_regularatendimento_lancamentos_id'] > 0) {
            PluginRegularatendimentoLancamento::estornar((int) $r['plugin_regularatendimento_lancamentos_id'], 'Ajuste do chamado #' . $ticket . ': estorno da cobrança anterior');
        }
        $lanc = 0;
        if ($contrato && $minutos > 0) {
            [$dentro, $fora] = self::dividir($minutos, (int) $r['segundos'], (int) $r['segundos_fora']);
            $lanc = PluginRegularatendimentoLancamento::debitar($contrato, $dentro, $fora, $ticket, 'Ajuste do chamado #' . $ticket . ' para ' . PluginRegularatendimentoConfig::horas($minutos) . ': ' . $motivo);
        }
        $DB->update(self::TABELA, ['minutos_cobrados' => $minutos, 'plugin_regularatendimento_lancamentos_id' => $lanc, 'is_ajustado' => 1], ['id' => $r['id']]);
        Log::history($ticket, 'Ticket', [0, '', 'Tempo cobrado ajustado para ' . PluginRegularatendimentoConfig::horas($minutos) . ': ' . $motivo], 0, Log::HISTORY_LOG_SIMPLE_MESSAGE);
        return [true, 'Tempo cobrado ajustado para ' . PluginRegularatendimentoConfig::horas($minutos) . '.'];
    }

    /** Período já trabalhado (atendimento retroativo) */
    public static function registrarPeriodo(int $ticket, int $inicio, int $fim, string $motivo): void
    {
        global $DB;
        $r = self::garantir($ticket);
        if (!$r || $fim <= $inicio) {
            return;
        }
        $fora = self::segundosFora(self::contratoDo($r), $inicio, $fim);
        $DB->insert(self::PERIODOS, [self::FK => (int) $r['id'], 'inicio' => date('Y-m-d H:i:s', $inicio), 'fim' => date('Y-m-d H:i:s', $fim), 'segundos' => $fim - $inicio, 'segundos_fora' => $fora,
            'origem_inicio' => 'retroativo', 'origem_fim' => 'retroativo', 'motivo_fim' => mb_substr($motivo, 0, 255), 'users_id_inicio' => (int) Session::getLoginUserID(), 'users_id_fim' => (int) Session::getLoginUserID()]);
        $DB->update(self::TABELA, ['segundos' => (int) $r['segundos'] + ($fim - $inicio), 'segundos_fora' => (int) $r['segundos_fora'] + $fora, 'estado' => $r['estado'] === 'parado' ? 'pausado' : $r['estado'], 'iniciado_em' => $r['iniciado_em'] ?: date('Y-m-d H:i:s', $inicio)], ['id' => $r['id']]);
    }

    /** Estimativa do valor do atendimento até agora (para o cartão do chamado) */
    public static function estimativa(array $r): array
    {
        $contrato = self::contratoDo($r);
        $seg = self::segundosAgora($r);
        if ($r['estado'] === 'finalizado') {
            $min = (int) $r['minutos_cobrados'];
            $l = (int) $r['plugin_regularatendimento_lancamentos_id'] ? PluginRegularatendimentoLancamento::obter((int) $r['plugin_regularatendimento_lancamentos_id']) : null;
            return ['contrato' => $contrato, 'minutos' => $min, 'normais' => (int) ($l['minutos_normais'] ?? $min), 'extras' => (int) ($l['minutos_extras'] ?? 0), 'valor' => (float) ($l['valor_total'] ?? 0)];
        }
        $min = self::minutosCobraveis($seg, $contrato);
        if (!$contrato || $min === 0) {
            return ['contrato' => $contrato, 'minutos' => $min, 'normais' => $min, 'extras' => 0, 'valor' => 0.0];
        }
        [$dentro, $fora] = self::dividir($min, max(1, $seg), (int) $r['segundos_fora']);
        $ciclo = PluginRegularatendimentoCiclo::aberto((int) $contrato['id']);
        $saldo = $ciclo ? PluginRegularatendimentoCiclo::saldo($ciclo) : 0;
        $normais = ($ciclo && (int) $ciclo['minutos_franquia'] > 0) ? max(0, min($dentro, $saldo)) : $dentro;
        $extras = $dentro - $normais + $fora;
        return ['contrato' => $contrato, 'minutos' => $min, 'normais' => $normais, 'extras' => $extras,
            'valor' => round($normais / 60 * (float) $contrato['valor_hora'] + $extras / 60 * (float) $contrato['valor_hora_extra'], 2)];
    }

    // ------------------------------------------------------------------ ganchos do chamado

    /** Antes de criar: um atendimento retroativo não deve iniciar o relógio quando o GLPI atribuir o chamado */
    public static function antesDeCriarChamado(Ticket $t): void
    {
        if (is_array($t->input) && is_array($t->input['_regular_retroativo'] ?? null)) {
            self::$criandoRetroativo = true;
        }
    }

    public static function aoCriarChamado(Ticket $t): void
    {
        self::$criandoRetroativo = false;
        $id = (int) $t->getID();
        if ($id <= 0) {
            return;
        }
        self::garantir($id);
        $retro = $t->input['_regular_retroativo'] ?? null;
        if (is_array($retro) && (int) ($retro['minutos'] ?? 0) > 0) {
            // O GLPI muda o status logo após criar (atores); um atendimento já feito não deve voltar a rodar
            self::$retroativos[$id] = true;
            $inicio = (int) $retro['inicio'];
            self::registrarPeriodo($id, $inicio, $inicio + (int) $retro['minutos'] * 60, 'Atendimento lançado depois');
            return;
        }
        if (PluginRegularatendimentoConfig::ligado('auto_iniciar') && in_array((int) $t->fields['status'], [CommonITILObject::ASSIGNED, CommonITILObject::PLANNED], true)) {
            self::iniciar($id, 'automatico');
        }
    }

    public static function aoAtualizarChamado(Ticket $t): void
    {
        if (!in_array('status', $t->updates ?? [], true)) {
            return;
        }
        $id = (int) $t->getID();
        $antigo = (int) ($t->oldvalues['status'] ?? 0);
        $novo = (int) $t->fields['status'];
        $finais = [CommonITILObject::SOLVED, CommonITILObject::CLOSED];
        if (in_array($antigo, $finais, true) && !in_array($novo, $finais, true)) {
            self::reabrir($id);
        }
        if (in_array($novo, $finais, true)) {
            self::finalizar($id, $novo === CommonITILObject::CLOSED ? 'Chamado fechado' : 'Chamado solucionado');
        } elseif ($novo === CommonITILObject::WAITING) {
            self::pausar($id, 'Chamado pendente', 'automatico');
        } elseif (in_array($novo, [CommonITILObject::ASSIGNED, CommonITILObject::PLANNED], true)) {
            if (PluginRegularatendimentoConfig::ligado('auto_iniciar') && empty(self::$retroativos[$id]) && !self::$criandoRetroativo) {
                self::iniciar($id, 'automatico');
            }
        } elseif ($novo === CommonITILObject::INCOMING) {
            self::pausar($id, 'Chamado voltou para novo', 'automatico');
        }
    }

    public static function aoExcluirChamado(Ticket $t): void
    {
        global $DB;
        $r = self::obter((int) $t->getID());
        if ($r) {
            $DB->delete(self::PERIODOS, [self::FK => (int) $r['id']]);
            $DB->delete(self::TABELA, ['id' => (int) $r['id']]);
        }
    }
}
