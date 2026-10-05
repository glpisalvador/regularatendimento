<?php

/**
 * Plugin Regular Atendimento - extrato: débitos (normal/extra), estornos, créditos, ajustes, abertura e fechamento
 */
class PluginRegularatendimentoLancamento extends CommonDBTM
{
    public const TABELA = 'glpi_plugin_regularatendimento_lancamentos';

    public static function getTypeName($nb = 0): string
    {
        return $nb > 1 ? 'Lançamentos' : 'Lançamento';
    }

    public static function getTable($classname = null)
    {
        return self::TABELA;
    }

    public static function canView(): bool
    {
        return PluginRegularatendimentoConfig::podeVer();
    }

    public static function tipos(): array
    {
        return [
            'debito'     => ['Débito', 'recusada'],
            'estorno'    => ['Estorno', 'ok'],
            'credito'    => ['Crédito de horas', 'ok'],
            'avulso'     => ['Consumo avulso', 'recusada'],
            'abertura'   => ['Abertura do ciclo', 'neutro'],
            'fechamento' => ['Fechamento do ciclo', 'neutro'],
        ];
    }

    public static function obter(int $id): ?array
    {
        global $DB;
        return $DB->request(['FROM' => self::TABELA, 'WHERE' => ['id' => $id], 'LIMIT' => 1])->current() ?: null;
    }

    public static function registrar(array $d): int
    {
        global $DB;
        $DB->insert(self::TABELA, [
            'plugin_regularatendimento_contratos_id' => (int) ($d['contrato'] ?? 0),
            'plugin_regularatendimento_ciclos_id'    => (int) ($d['ciclo'] ?? 0),
            'entities_id'     => (int) ($d['entidade'] ?? 0),
            'tickets_id'      => (int) ($d['ticket'] ?? 0),
            'tipo'            => (string) $d['tipo'],
            'minutos_normais' => (int) ($d['normais'] ?? 0),
            'minutos_extras'  => (int) ($d['extras'] ?? 0),
            'valor_normal'    => round((float) ($d['valor_normal'] ?? 0), 2),
            'valor_extra'     => round((float) ($d['valor_extra'] ?? 0), 2),
            'valor_total'     => round((float) ($d['valor_normal'] ?? 0) + (float) ($d['valor_extra'] ?? 0), 2),
            'saldo_antes'     => (int) ($d['saldo_antes'] ?? 0),
            'saldo_depois'    => (int) ($d['saldo_depois'] ?? 0),
            'observacao'      => mb_substr((string) ($d['observacao'] ?? ''), 0, 2000),
            'users_id'        => is_numeric(Session::getLoginUserID()) ? (int) Session::getLoginUserID() : 0,
        ]);
        return (int) $DB->insertId();
    }

    /** Ciclo aberto do contrato (abre um, se faltar) */
    private static function cicloDe(array $contrato): ?array
    {
        $c = PluginRegularatendimentoCiclo::aberto((int) $contrato['id']);
        if (!$c) {
            $id = PluginRegularatendimentoCiclo::abrir($contrato);
            $c = $id ? PluginRegularatendimentoCiclo::obter($id) : null;
        }
        return $c;
    }

    /**
     * Debita minutos do contrato: o que cabe na franquia é hora normal, o resto (e o tempo fora do calendário) é extra.
     * @return int id do lançamento (0 se não foi possível)
     */
    public static function debitar(array $contrato, int $minutosDentro, int $minutosFora, int $ticket, string $observacao, string $tipo = 'debito'): int
    {
        global $DB;
        $ciclo = self::cicloDe($contrato);
        if (!$ciclo) {
            return 0;
        }
        $saldo = PluginRegularatendimentoCiclo::saldo($ciclo);
        $normais = max(0, min($minutosDentro, $saldo));
        if ((int) $ciclo['minutos_franquia'] <= 0) {
            // Sem franquia: tudo dentro do horário é cobrado pela hora normal
            $normais = $minutosDentro;
        }
        $extras = ($minutosDentro - $normais) + $minutosFora;
        $vn = round($normais / 60 * (float) $contrato['valor_hora'], 2);
        $ve = round($extras / 60 * (float) $contrato['valor_hora_extra'], 2);
        $DB->update(PluginRegularatendimentoCiclo::TABELA, [
            'minutos_normais' => (int) $ciclo['minutos_normais'] + $normais,
            'minutos_extras'  => (int) $ciclo['minutos_extras'] + $extras,
            'valor_normal'    => round((float) $ciclo['valor_normal'] + $vn, 2),
            'valor_extra'     => round((float) $ciclo['valor_extra'] + $ve, 2),
        ], ['id' => $ciclo['id']]);
        return self::registrar([
            'contrato' => (int) $contrato['id'], 'ciclo' => (int) $ciclo['id'], 'entidade' => (int) $contrato['entities_id'], 'ticket' => $ticket, 'tipo' => $tipo,
            'normais' => $normais, 'extras' => $extras, 'valor_normal' => $vn, 'valor_extra' => $ve,
            'saldo_antes' => $saldo, 'saldo_depois' => $saldo - $normais, 'observacao' => $observacao,
        ]);
    }

    /** Desfaz um débito (chamado reaberto ou tempo ajustado) no ciclo dele, ou no ciclo aberto se ele já fechou */
    public static function estornar(int $id, string $observacao): int
    {
        global $DB;
        $l = self::obter($id);
        if (!$l || (int) $l['estornado'] || !in_array($l['tipo'], ['debito', 'avulso'], true)) {
            return 0;
        }
        $ciclo = PluginRegularatendimentoCiclo::obter((int) $l['plugin_regularatendimento_ciclos_id']);
        if (!$ciclo || $ciclo['status'] !== 'aberto') {
            $contrato = PluginRegularatendimentoContrato::obter((int) $l['plugin_regularatendimento_contratos_id']);
            $ciclo = $contrato ? self::cicloDe($contrato) : null;
        }
        if (!$ciclo) {
            return 0;
        }
        $saldo = PluginRegularatendimentoCiclo::saldo($ciclo);
        $DB->update(PluginRegularatendimentoCiclo::TABELA, [
            'minutos_normais' => (int) $ciclo['minutos_normais'] - (int) $l['minutos_normais'],
            'minutos_extras'  => (int) $ciclo['minutos_extras'] - (int) $l['minutos_extras'],
            'valor_normal'    => round((float) $ciclo['valor_normal'] - (float) $l['valor_normal'], 2),
            'valor_extra'     => round((float) $ciclo['valor_extra'] - (float) $l['valor_extra'], 2),
        ], ['id' => $ciclo['id']]);
        $DB->update(self::TABELA, ['estornado' => 1], ['id' => $id]);
        return self::registrar([
            'contrato' => (int) $l['plugin_regularatendimento_contratos_id'], 'ciclo' => (int) $ciclo['id'], 'entidade' => (int) $l['entities_id'], 'ticket' => (int) $l['tickets_id'], 'tipo' => 'estorno',
            'normais' => -(int) $l['minutos_normais'], 'extras' => -(int) $l['minutos_extras'], 'valor_normal' => -(float) $l['valor_normal'], 'valor_extra' => -(float) $l['valor_extra'],
            'saldo_antes' => $saldo, 'saldo_depois' => $saldo + (int) $l['minutos_normais'], 'observacao' => $observacao,
        ]);
    }

    /** Lançamento manual: crédito de horas na franquia do ciclo ou consumo avulso (sem chamado) */
    public static function manual(int $contratoId, string $tipo, int $minutos, string $observacao): array
    {
        global $DB;
        $contrato = PluginRegularatendimentoContrato::obter($contratoId);
        if (!$contrato) {
            return [false, 'Escolha o contrato.'];
        }
        if ($minutos <= 0) {
            return [false, 'Informe o tempo (ex.: 2 ou 1:30).'];
        }
        if (trim($observacao) === '') {
            return [false, 'Escreva o motivo do lançamento.'];
        }
        if ($tipo === 'credito') {
            $ciclo = self::cicloDe($contrato);
            if (!$ciclo) {
                return [false, 'O contrato está inativo.'];
            }
            $saldo = PluginRegularatendimentoCiclo::saldo($ciclo);
            $DB->update(PluginRegularatendimentoCiclo::TABELA, ['minutos_franquia' => (int) $ciclo['minutos_franquia'] + $minutos], ['id' => $ciclo['id']]);
            self::registrar(['contrato' => $contratoId, 'ciclo' => (int) $ciclo['id'], 'entidade' => (int) $contrato['entities_id'], 'tipo' => 'credito', 'saldo_antes' => $saldo, 'saldo_depois' => $saldo + $minutos, 'observacao' => $observacao]);
            return [true, PluginRegularatendimentoConfig::horas($minutos) . ' creditadas no ciclo.'];
        }
        if ($tipo === 'avulso') {
            return self::debitar($contrato, $minutos, 0, 0, $observacao, 'avulso') ? [true, 'Consumo de ' . PluginRegularatendimentoConfig::horas($minutos) . ' lançado.'] : [false, 'O contrato está inativo.'];
        }
        return [false, 'Tipo de lançamento inválido.'];
    }

    // ------------------------------------------------------------------ consulta

    public static function filtros(array $o): array
    {
        return [
            'contrato' => max(0, (int) ($o['contrato'] ?? 0)),
            'ciclo'    => max(0, (int) ($o['ciclo'] ?? 0)),
            'tipo'     => array_key_exists((string) ($o['tipo'] ?? ''), self::tipos()) ? (string) $o['tipo'] : '',
            'de'       => preg_match('/^\d{4}-\d{2}-\d{2}/', (string) ($o['de'] ?? '')) ? substr((string) $o['de'], 0, 10) : '',
            'ate'      => preg_match('/^\d{4}-\d{2}-\d{2}/', (string) ($o['ate'] ?? '')) ? substr((string) $o['ate'], 0, 10) : '',
            'ticket'   => max(0, (int) ltrim((string) ($o['ticket'] ?? ''), '# ')),
        ];
    }

    public static function listar(array $f, int $limite = 500): array
    {
        global $DB;
        $where = [];
        if ($f['contrato']) {
            $where['plugin_regularatendimento_contratos_id'] = $f['contrato'];
        }
        if ($f['ciclo']) {
            $where['plugin_regularatendimento_ciclos_id'] = $f['ciclo'];
        }
        if ($f['tipo'] !== '') {
            $where['tipo'] = $f['tipo'];
        }
        if ($f['ticket']) {
            $where['tickets_id'] = $f['ticket'];
        }
        if ($f['de'] !== '') {
            $where[] = ['date_creation' => ['>=', $f['de'] . ' 00:00:00']];
        }
        if ($f['ate'] !== '') {
            $where[] = ['date_creation' => ['<=', $f['ate'] . ' 23:59:59']];
        }
        $ents = array_values(array_map('intval', $_SESSION['glpiactiveentities'] ?? []));
        if ($ents && !PluginRegularatendimentoConfig::ehAdmin()) {
            $where['entities_id'] = $ents;
        }
        return iterator_to_array($DB->request(['FROM' => self::TABELA, 'WHERE' => $where, 'ORDER' => 'id DESC', 'LIMIT' => $limite]), false);
    }

    /** Tabela de lançamentos */
    public static function tabela(array $linhas, bool $comContrato = true): void
    {
        $C = PluginRegularatendimentoConfig::class;
        $e = [$C, 'e'];
        $tipos = self::tipos();
        if (!$linhas) {
            echo '<div class="regular-vazio"><i class="ti ti-mood-empty"></i> Nenhum lançamento.</div>';
            return;
        }
        $nomes = [];
        echo '<div class="table-responsive"><table class="table table-hover table-sm regular-lista"><thead><tr><th>Data</th>' . ($comContrato ? '<th>Contrato</th>' : '') . '<th>Tipo</th><th>Chamado</th><th class="text-end">Normais</th><th class="text-end">Extras</th><th class="text-end">Valor</th><th class="text-end">Saldo</th><th>Observação</th><th>Por</th></tr></thead><tbody>';
        foreach ($linhas as $l) {
            $cid = (int) $l['plugin_regularatendimento_contratos_id'];
            if ($comContrato && !isset($nomes[$cid])) {
                $ct = PluginRegularatendimentoContrato::obter($cid);
                $nomes[$cid] = $ct ? PluginRegularatendimentoContrato::nome($ct) : '#' . $cid;
            }
            $t = $tipos[$l['tipo']] ?? [$l['tipo'], 'neutro'];
            $neutro = in_array($l['tipo'], ['abertura', 'fechamento'], true);
            echo '<tr class="' . ((int) $l['estornado'] ? 'regular-estornado' : '') . '"><td class="text-nowrap">' . $e(Html::convDateTime($l['date_creation'])) . '</td>';
            if ($comContrato) {
                echo '<td><a href="' . $e(PluginRegularatendimentoContrato::getFormURLWithID($cid)) . '">' . $e($nomes[$cid]) . '</a></td>';
            }
            echo '<td><span class="regular-pill regular-pill-' . $t[1] . '">' . $e($t[0]) . '</span>' . ((int) $l['estornado'] ? ' <span class="regular-ajuda">estornado</span>' : '') . '</td>';
            echo '<td>' . ((int) $l['tickets_id'] ? '<a href="' . $e(Ticket::getFormURLWithID((int) $l['tickets_id'])) . '">#' . (int) $l['tickets_id'] . '</a>' : '—') . '</td>';
            echo '<td class="text-end">' . ($neutro ? '' : $e($C::horas((int) $l['minutos_normais']))) . '</td><td class="text-end">' . ($neutro ? '' : $e($C::horas((int) $l['minutos_extras']))) . '</td>';
            echo '<td class="text-end text-nowrap">' . ($neutro ? '' : '<strong>' . $e($C::reais((float) $l['valor_total'])) . '</strong>') . '</td>';
            echo '<td class="text-end text-nowrap">' . ($l['tipo'] === 'fechamento' ? '' : $e($C::horas((int) $l['saldo_depois']))) . '</td>';
            echo '<td class="regular-obs">' . $e($l['observacao']) . '</td><td>' . $e((int) $l['users_id'] ? getUserName((int) $l['users_id']) : 'Automático') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item instanceof PluginRegularatendimentoContrato && !$item->isNewItem()) {
            return self::createTabEntry('Extrato', 0, null, 'ti ti-list');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item instanceof PluginRegularatendimentoContrato) {
            $e = [PluginRegularatendimentoConfig::class, 'e'];
            echo '<div class="regular"><div class="regular-barra-acoes"><span class="regular-ajuda">Últimos 100 lançamentos.</span><a class="btn btn-sm btn-outline-secondary" href="' . $e(PluginRegularatendimentoConfig::url('lancamentos.php', ['contrato' => (int) $item->getID()])) . '"><i class="ti ti-list"></i> Extrato completo e lançamento manual</a></div>';
            self::tabela(self::listar(self::filtros(['contrato' => (int) $item->getID()]), 100), false);
            echo '</div>';
        }
        return true;
    }
}
