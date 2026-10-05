<?php

/**
 * Plugin Regular Atendimento - ciclos mensais do contrato (abertura, fechamento e tarefa automática)
 */
class PluginRegularatendimentoCiclo extends CommonDBTM
{
    public const TABELA = 'glpi_plugin_regularatendimento_ciclos';
    public const FK = 'plugin_regularatendimento_contratos_id';

    public static function getTypeName($nb = 0): string
    {
        return $nb > 1 ? 'Ciclos' : 'Ciclo';
    }

    public static function getTable($classname = null)
    {
        return self::TABELA;
    }

    public static function canView(): bool
    {
        return PluginRegularatendimentoConfig::podeVer();
    }

    public static function obter(int $id): ?array
    {
        global $DB;
        return $DB->request(['FROM' => self::TABELA, 'WHERE' => ['id' => $id], 'LIMIT' => 1])->current() ?: null;
    }

    public static function aberto(int $contrato): ?array
    {
        global $DB;
        return $DB->request(['FROM' => self::TABELA, 'WHERE' => [self::FK => $contrato, 'status' => 'aberto'], 'ORDER' => 'id DESC', 'LIMIT' => 1])->current() ?: null;
    }

    /** Início nominal do ciclo que contém o instante e o próximo fechamento (dia D do mês, 00:00) */
    public static function janela(int $dia, int $agora): array
    {
        $dia = max(1, min(28, $dia));
        $esteMes = mktime(0, 0, 0, (int) date('n', $agora), $dia, (int) date('Y', $agora));
        $inicio = $esteMes <= $agora ? $esteMes : strtotime('-1 month', $esteMes);
        $fim = strtotime('+1 month', $inicio);
        return [$inicio, $fim];
    }

    public static function saldo(array $ciclo): int
    {
        return (int) $ciclo['minutos_franquia'] - (int) $ciclo['minutos_normais'];
    }

    public static function abrir(array $contrato, int $transporte = 0): int
    {
        global $DB;
        if (!(int) $contrato['is_active']) {
            return 0;
        }
        if ($existente = self::aberto((int) $contrato['id'])) {
            return (int) $existente['id'];
        }
        [$inicio, $fim] = self::janela((int) $contrato['dia_fechamento'], time());
        $franquia = (int) $contrato['minutos_franquia'] + max(0, $transporte);
        $DB->insert(self::TABELA, [
            self::FK                => (int) $contrato['id'],
            'entities_id'           => (int) $contrato['entities_id'],
            'referencia'            => date('Y-m', $inicio),
            'inicio'                => date('Y-m-d H:i:s', max($inicio, time() - 1)),
            'fim'                   => date('Y-m-d H:i:s', $fim),
            'minutos_franquia'      => $franquia,
            'minutos_transportados' => max(0, $transporte),
        ]);
        $id = (int) $DB->insertId();
        PluginRegularatendimentoLancamento::registrar([
            'contrato' => (int) $contrato['id'], 'ciclo' => $id, 'entidade' => (int) $contrato['entities_id'], 'tipo' => 'abertura',
            'saldo_antes' => 0, 'saldo_depois' => $franquia,
            'observacao' => 'Abertura do ciclo ' . self::rotulo(date('Y-m', $inicio)) . ' com franquia de ' . PluginRegularatendimentoConfig::horas($franquia) . ($transporte > 0 ? ' (inclui ' . PluginRegularatendimentoConfig::horas($transporte) . ' acumuladas)' : ''),
        ]);
        return $id;
    }

    /** Fecha o ciclo e abre o seguinte (com a sobra, se o contrato acumular) */
    public static function fechar(int $id): bool
    {
        global $DB;
        $c = self::obter($id);
        if (!$c || $c['status'] !== 'aberto') {
            return false;
        }
        $contrato = PluginRegularatendimentoContrato::obter((int) $c[self::FK]);
        $saldo = self::saldo($c);
        $DB->update(self::TABELA, ['status' => 'fechado', 'fechado_em' => date('Y-m-d H:i:s'), 'users_id_fechou' => (int) Session::getLoginUserID()], ['id' => $id]);
        $C = PluginRegularatendimentoConfig::class;
        PluginRegularatendimentoLancamento::registrar([
            'contrato' => (int) $c[self::FK], 'ciclo' => $id, 'entidade' => (int) $c['entities_id'], 'tipo' => 'fechamento',
            'saldo_antes' => $saldo, 'saldo_depois' => 0,
            'observacao' => 'Fechamento: ' . $C::horas((int) $c['minutos_normais']) . ' normais (' . $C::reais((float) $c['valor_normal']) . ') e ' . $C::horas((int) $c['minutos_extras']) . ' extras (' . $C::reais((float) $c['valor_extra']) . '). Total ' . $C::reais((float) $c['valor_normal'] + (float) $c['valor_extra']) . '.',
        ]);
        if ($contrato && (int) $contrato['is_active']) {
            self::abrir($contrato, (int) $contrato['acumular_saldo'] ? max(0, $saldo) : 0);
        }
        return true;
    }

    public static function rotulo(string $referencia): string
    {
        $meses = ['01' => 'janeiro', '02' => 'fevereiro', '03' => 'março', '04' => 'abril', '05' => 'maio', '06' => 'junho', '07' => 'julho', '08' => 'agosto', '09' => 'setembro', '10' => 'outubro', '11' => 'novembro', '12' => 'dezembro'];
        [$a, $m] = explode('-', $referencia . '-01');
        return ($meses[$m] ?? $m) . '/' . $a;
    }

    // ------------------------------------------------------------------ tarefa automática

    public static function cronInfo($name)
    {
        return ['description' => 'Regular Atendimento: fecha os ciclos vencidos e abre os novos'];
    }

    public static function cronRegularatendimentoFechamento($task = null)
    {
        $n = self::executar();
        if ($task instanceof CronTask) {
            $task->addVolume($n);
        }
        return 1;
    }

    public static function executar(): int
    {
        global $DB;
        $n = 0;
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => self::TABELA, 'WHERE' => ['status' => 'aberto', 'fim' => ['<=', date('Y-m-d H:i:s')]]]) as $c) {
            if (self::fechar((int) $c['id'])) {
                $n++;
            }
        }
        foreach ($DB->request(['FROM' => PluginRegularatendimentoContrato::TABELA, 'WHERE' => ['is_active' => 1]]) as $contrato) {
            if (!self::aberto((int) $contrato['id'])) {
                self::abrir($contrato);
                $n++;
            }
        }
        return $n;
    }

    // ------------------------------------------------------------------ telas

    public static function resumoHtml(array $c, array $contrato): string
    {
        $C = PluginRegularatendimentoConfig::class;
        $e = [$C, 'e'];
        $franquia = (int) $c['minutos_franquia'];
        $usado = (int) $c['minutos_normais'];
        $pct = $franquia > 0 ? min(100, round($usado / $franquia * 100)) : 0;
        $classe = (int) $c['minutos_extras'] > 0 || ($franquia > 0 && $usado >= $franquia) ? 'estourado' : ($pct >= 80 ? 'perto' : 'ok');
        $h = '<div class="regular-resumo"><div class="regular-resumo-linha"><strong>' . $e(self::rotulo((string) $c['referencia'])) . '</strong> <span class="regular-ajuda">até ' . $e(Html::convDate(date('Y-m-d', strtotime((string) $c['fim']) - 1))) . '</span></div>';
        if ($franquia > 0) {
            $h .= '<div class="regular-uso regular-uso-' . $classe . '"><div class="regular-uso-barra"><div style="width:' . $pct . '%"></div></div><span>' . $e($C::horas($usado) . ' de ' . $C::horas($franquia) . ' · saldo ' . $C::horas(self::saldo($c))) . '</span></div>';
        }
        $h .= '<div class="regular-resumo-valores"><span>Normais: <b>' . $e($C::horas($usado)) . '</b> · ' . $e($C::reais((float) $c['valor_normal'])) . '</span>'
            . '<span>Extras: <b>' . $e($C::horas((int) $c['minutos_extras'])) . '</b> · ' . $e($C::reais((float) $c['valor_extra'])) . '</span>'
            . '<span>Total: <b>' . $e($C::reais((float) $c['valor_normal'] + (float) $c['valor_extra'])) . '</b></span></div></div>';
        return $h;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item instanceof PluginRegularatendimentoContrato && !$item->isNewItem()) {
            $n = ($_SESSION['glpishow_count_on_tabs'] ?? false) ? countElementsInTable(self::TABELA, [self::FK => (int) $item->getID()]) : 0;
            return self::createTabEntry('Ciclos', $n, null, 'ti ti-calendar');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        global $DB;
        if (!$item instanceof PluginRegularatendimentoContrato) {
            return true;
        }
        $C = PluginRegularatendimentoConfig::class;
        $e = [$C, 'e'];
        $id = (int) $item->getID();
        echo '<div class="regular">';
        $aberto = self::aberto($id);
        if ($aberto && $C::podeGerenciar()) {
            echo '<form method="post" action="' . $e($C::url('contrato.form.php')) . '" class="regular-barra-acoes"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="save_action" value="fechar_ciclo">';
            echo '<span class="regular-ajuda">O ciclo aberto fecha sozinho em ' . $e(Html::convDateTime($aberto['fim'])) . '.</span>';
            echo '<button type="submit" class="btn btn-sm btn-outline-secondary" data-regular-confirmar><i class="ti ti-lock"></i> Fechar o ciclo agora</button>';
            Html::closeForm();
        }
        echo '<table class="tab_cadre_fixehov regular-tabela"><tr class="noHover"><th colspan="9"><i class="ti ti-calendar"></i> Ciclos</th></tr>';
        echo '<tr class="tab_bg_2"><th>Referência</th><th>Período</th><th>Franquia</th><th>Normais</th><th>Extras</th><th>Saldo</th><th>Valor</th><th>Situação</th><th></th></tr>';
        foreach ($DB->request(['FROM' => self::TABELA, 'WHERE' => [self::FK => $id], 'ORDER' => 'id DESC']) as $c) {
            echo '<tr class="tab_bg_1"><td><strong>' . $e(self::rotulo((string) $c['referencia'])) . '</strong></td>';
            echo '<td class="text-nowrap">' . $e(Html::convDate(substr((string) $c['inicio'], 0, 10))) . ' a ' . $e(Html::convDate(date('Y-m-d', strtotime((string) ($c['fechado_em'] ?: $c['fim'])) - ($c['fechado_em'] ? 0 : 1)))) . '</td>';
            echo '<td>' . $e($C::horas((int) $c['minutos_franquia'])) . '</td><td>' . $e($C::horas((int) $c['minutos_normais'])) . '</td><td>' . $e($C::horas((int) $c['minutos_extras'])) . '</td>';
            echo '<td>' . $e($C::horas(self::saldo($c))) . '</td><td class="text-nowrap"><strong>' . $e($C::reais((float) $c['valor_normal'] + (float) $c['valor_extra'])) . '</strong></td>';
            echo '<td>' . ($c['status'] === 'aberto' ? '<span class="regular-pill regular-pill-andamento">Aberto</span>' : '<span class="regular-pill regular-pill-neutro">Fechado</span>') . '</td>';
            echo '<td class="text-end"><div class="regular-acoes-linha"><a class="btn btn-sm btn-outline-secondary" href="' . $e($C::url('extrato.php', ['ciclo' => (int) $c['id']])) . '" title="Relatório do ciclo"><i class="ti ti-file-text"></i></a>';
            echo '<a class="btn btn-sm btn-outline-secondary" href="' . $e($C::url('lancamentos.php', ['ciclo' => (int) $c['id']])) . '" title="Lançamentos"><i class="ti ti-list"></i></a></div></td></tr>';
        }
        echo '</table></div>';
        return true;
    }
}
