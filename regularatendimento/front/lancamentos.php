<?php

/**
 * Plugin Regular Atendimento - extrato com filtros e lançamento manual (crédito de horas ou consumo avulso)
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
if (!PluginRegularatendimentoConfig::podeVer()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

global $DB;
$C = PluginRegularatendimentoConfig::class;
$L = PluginRegularatendimentoLancamento::class;
$e = [$C, 'e'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['save_action'] ?? '') === 'lancar') {
    if (!$C::podeGerenciar()) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    [$ok, $msg] = $L::manual((int) ($_POST['contrato'] ?? 0), (string) ($_POST['tipo'] ?? ''), $C::lerMinutos($_POST['tempo'] ?? ''), trim((string) ($_POST['observacao'] ?? '')));
    Session::addMessageAfterRedirect($msg, false, $ok ? INFO : ERROR);
}

$f = $L::filtros($_GET);
$ents = array_values(array_map('intval', $_SESSION['glpiactiveentities'] ?? []));
$contratos = ['0' => 'Todos'];
foreach ($DB->request(['FROM' => PluginRegularatendimentoContrato::TABELA, 'WHERE' => $ents ? ['entities_id' => $ents] : []]) as $ct) {
    $contratos[(int) $ct['id']] = PluginRegularatendimentoContrato::nome($ct);
}
asort($contratos);
$contratos = ['0' => 'Todos'] + array_diff_key($contratos, ['0' => '']);
$tipos = ['' => 'Todos'] + array_map(static fn($t) => $t[0], $L::tipos());

Html::header('Extrato', $_SERVER['PHP_SELF'] ?? '', 'tools', 'PluginRegularatendimentoMenu', 'lancamentos');
PluginRegularatendimentoMenu::abas('lancamentos');
echo '<div class="regular">';

if ($C::podeGerenciar() && count($contratos) > 1) {
    echo '<form method="post" action="' . $e($C::url('lancamentos.php', array_filter($_GET))) . '"><input type="hidden" name="save_action" value="lancar">';
    echo '<table class="tab_cadre_fixe regular-tabela-form"><tr class="tab_bg_2"><th colspan="4"><i class="ti ti-plus"></i> Lançamento manual</th></tr>';
    echo '<tr class="tab_bg_1"><td class="regular-rotulo">Contrato</td><td>';
    Dropdown::showFromArray('contrato', array_diff_key($contratos, ['0' => '']), ['value' => $f['contrato'], 'width' => '100%']);
    echo '</td><td class="regular-rotulo">Tipo</td><td>';
    Dropdown::showFromArray('tipo', ['credito' => 'Crédito de horas na franquia do ciclo', 'avulso' => 'Consumo avulso (sem chamado)'], ['width' => '100%']);
    echo '</td></tr><tr class="tab_bg_1"><td class="regular-rotulo">Tempo</td><td><input type="text" class="form-control" name="tempo" placeholder="Ex.: 2 ou 1:30" required></td>';
    echo '<td class="regular-rotulo">Motivo</td><td><input type="text" class="form-control" name="observacao" maxlength="500" required></td></tr>';
    echo '<tr class="tab_bg_2"><td colspan="4" class="center"><button type="submit" class="btn btn-primary regular-btn-salvar"><i class="ti ti-device-floppy"></i> Lançar</button></td></tr></table>';
    Html::closeForm();
}

echo '<form method="get" action="' . $e($C::url('lancamentos.php')) . '" class="card regular-card"><div class="card-body regular-linha-filtros">';
echo '<div class="regular-filtro"><label>Contrato</label>';
Dropdown::showFromArray('contrato', $contratos, ['value' => $f['contrato'], 'width' => '240px']);
echo '</div><div class="regular-filtro"><label>Tipo</label>';
Dropdown::showFromArray('tipo', $tipos, ['value' => $f['tipo'], 'width' => '180px']);
echo '</div><div class="regular-filtro"><label>Chamado</label><input type="text" class="form-control" name="ticket" value="' . ($f['ticket'] ?: '') . '" placeholder="Nº" style="width:90px"></div>';
echo '<div class="regular-filtro"><label>De</label>';
Html::showDateField('de', ['value' => $f['de'], 'maybeempty' => true]);
echo '</div><div class="regular-filtro"><label>Até</label>';
Html::showDateField('ate', ['value' => $f['ate'], 'maybeempty' => true]);
echo '</div>';
if ($f['ciclo']) {
    echo '<input type="hidden" name="ciclo" value="' . $f['ciclo'] . '">';
}
echo '<div class="regular-filtro regular-filtro-botoes"><button type="submit" class="btn btn-sm regular-btn-salvar"><i class="ti ti-filter"></i> Filtrar</button>';
echo '<a class="btn btn-sm btn-outline-secondary" href="' . $e($C::url('lancamentos.php')) . '"><i class="ti ti-x"></i> Limpar</a>';
echo '<a class="btn btn-sm btn-outline-secondary" href="' . $e($C::url('exportar.php', array_filter($f))) . '"><i class="ti ti-file-spreadsheet"></i> CSV</a></div>';
echo '</div></form>';

if ($f['ciclo'] && ($ci = PluginRegularatendimentoCiclo::obter($f['ciclo']))) {
    $ct = PluginRegularatendimentoContrato::obter((int) $ci[PluginRegularatendimentoCiclo::FK]);
    echo '<div class="regular-alerta regular-alerta-info"><i class="ti ti-calendar"></i><div>Ciclo ' . $e(PluginRegularatendimentoCiclo::rotulo((string) $ci['referencia'])) . ($ct ? ' · ' . $e(PluginRegularatendimentoContrato::nome($ct)) : '') . ' — <a href="' . $e($C::url('extrato.php', ['ciclo' => $f['ciclo']])) . '">ver relatório do ciclo</a></div></div>';
}
$linhas = $L::listar($f, 1000);
echo '<div class="card regular-card"><div class="card-header regular-card-header"><h5><i class="ti ti-list"></i> Lançamentos</h5><span class="regular-contador">' . count($linhas) . '</span></div><div class="card-body p-0">';
$L::tabela($linhas);
echo '</div></div></div>';
Html::footer();
