<?php

/**
 * Plugin Regular Atendimento - relatório A4 de um ciclo (imprimir, baixar PDF, CSV)
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
if (!PluginRegularatendimentoConfig::podeVer()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

global $DB;
$C = PluginRegularatendimentoConfig::class;
$e = [$C, 'e'];
$ciclo = PluginRegularatendimentoCiclo::obter((int) ($_GET['ciclo'] ?? 0));
$contrato = $ciclo ? PluginRegularatendimentoContrato::obter((int) $ciclo[PluginRegularatendimentoCiclo::FK]) : null;
if (!$ciclo || !$contrato || !Session::haveAccessToEntity((int) $contrato['entities_id'])) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

$debitos = iterator_to_array($DB->request(['FROM' => PluginRegularatendimentoLancamento::TABELA, 'WHERE' => ['plugin_regularatendimento_ciclos_id' => (int) $ciclo['id'], 'tipo' => ['debito', 'avulso', 'estorno', 'credito']], 'ORDER' => 'id ASC']), false);
$td = 'padding:4px 6px;border:1px solid #e0e0e0;font-size:10.5px;vertical-align:top;';
$th = $td . 'background:#f8f9fa;font-weight:600;color:#495057;';
$nomeArquivo = 'extrato-' . preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', PluginRegularatendimentoContrato::nome($contrato)) ?: 'contrato') . '-' . $ciclo['referencia'] . '.pdf';

Html::header('Relatório do ciclo', $_SERVER['PHP_SELF'] ?? '', 'tools', 'PluginRegularatendimentoMenu', 'lancamentos');
echo '<div class="regular regular-relatorio" data-regular-relatorio data-arquivo="' . $e($nomeArquivo) . '">';
echo '<div class="regular-barra-acoes"><a class="btn btn-sm btn-outline-secondary" href="' . $e(PluginRegularatendimentoContrato::getFormURLWithID((int) $contrato['id']) . '&forcetab=PluginRegularatendimentoCiclo$1') . '"><i class="ti ti-arrow-left"></i> Voltar ao contrato</a><span class="regular-acoes-linha">';
echo '<button type="button" class="btn btn-sm btn-outline-secondary" data-regular-imprimir><i class="ti ti-printer"></i> Imprimir</button>';
echo '<button type="button" class="btn btn-sm btn-outline-secondary" data-regular-pdf><i class="ti ti-download"></i> Baixar PDF</button>';
echo '<a class="btn btn-sm btn-outline-secondary" href="' . $e($C::url('exportar.php', ['ciclo' => (int) $ciclo['id']])) . '"><i class="ti ti-file-spreadsheet"></i> CSV</a></span></div>';

echo '<div class="regular-folha"><div class="regular-doc" data-regular-documento style="width:190mm;margin:0 auto;background:#fff;color:#333;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.45;">';
echo '<table style="width:100%;border-collapse:collapse;border-bottom:2px solid #dee2e6;margin-bottom:10px;"><tr><td style="padding:6px 0;"><div style="font-size:16px;font-weight:700;">Extrato de horas · ' . $e(PluginRegularatendimentoCiclo::rotulo((string) $ciclo['referencia'])) . '</div>';
echo '<div style="font-size:12px;color:#6c757d;">' . $e(PluginRegularatendimentoContrato::nome($contrato)) . ' · ' . $e($C::nomeEntidade((int) $contrato['entities_id'])) . '</div></td>';
echo '<td style="text-align:right;font-size:11px;color:#6c757d;">' . ($ciclo['status'] === 'aberto' ? 'Ciclo em aberto (parcial)' : 'Ciclo fechado em ' . $e(Html::convDateTime($ciclo['fechado_em']))) . '<br>Período: ' . $e(Html::convDate(substr((string) $ciclo['inicio'], 0, 10))) . ' a ' . $e(Html::convDate(date('Y-m-d', strtotime((string) ($ciclo['fechado_em'] ?: $ciclo['fim'])) - ($ciclo['fechado_em'] ? 0 : 1)))) . '</td></tr></table>';

$fr = (int) $ciclo['minutos_franquia'];
echo '<table style="width:100%;border-collapse:collapse;margin-bottom:12px;"><tr>';
foreach ([
    ['Franquia', $C::horas($fr) . ((int) $ciclo['minutos_transportados'] ? ' (' . $C::horas((int) $ciclo['minutos_transportados']) . ' acumuladas)' : '')],
    ['Horas normais', $C::horas((int) $ciclo['minutos_normais']) . ' · ' . $C::reais((float) $ciclo['valor_normal'])],
    ['Horas extras', $C::horas((int) $ciclo['minutos_extras']) . ' · ' . $C::reais((float) $ciclo['valor_extra'])],
    ['Saldo da franquia', $fr > 0 ? $C::horas(PluginRegularatendimentoCiclo::saldo($ciclo)) : '—'],
    ['Total a faturar', $C::reais((float) $ciclo['valor_normal'] + (float) $ciclo['valor_extra'])],
] as [$r, $v]) {
    echo '<td style="border:1px solid #e0e0e0;padding:6px 8px;width:20%;"><div style="font-size:10px;color:#6c757d;text-transform:uppercase;">' . $e($r) . '</div><div style="font-size:13px;font-weight:700;">' . $e($v) . '</div></td>';
}
echo '</tr></table>';
echo '<div style="font-size:11px;color:#6c757d;margin-bottom:8px;">Hora normal ' . $e($C::reais((float) $contrato['valor_hora'])) . ' · hora extra ' . $e($C::reais((float) $contrato['valor_hora_extra'])) . ' · mínimo ' . (int) $contrato['minimo_minutos'] . ' min por chamado' . ((int) $contrato['calendars_id'] ? ' · fora do horário "' . $e(html_entity_decode(Dropdown::getDropdownName('glpi_calendars', (int) $contrato['calendars_id']), ENT_QUOTES, 'UTF-8')) . '" é hora extra' : '') . '</div>';

echo '<table style="width:100%;border-collapse:collapse;"><tr><th style="' . $th . 'width:13%;">Data</th><th style="' . $th . 'width:33%;">Chamado / descrição</th><th style="' . $th . 'width:10%;">Normais</th><th style="' . $th . 'width:10%;">Extras</th><th style="' . $th . 'width:12%;">Valor</th><th style="' . $th . 'width:22%;">Lançamento</th></tr>';
$tipos = PluginRegularatendimentoLancamento::tipos();
foreach ($debitos as $l) {
    $desc = (string) $l['observacao'];
    if ((int) $l['tickets_id'] > 0) {
        $t = new Ticket();
        $desc = '#' . (int) $l['tickets_id'] . ' ' . ($t->getFromDB((int) $l['tickets_id']) ? $t->fields['name'] : '');
    }
    echo '<tr><td style="' . $td . '">' . $e(Html::convDateTime($l['date_creation'])) . '</td><td style="' . $td . '">' . $e($desc) . '</td>';
    echo '<td style="' . $td . '">' . ($l['tipo'] === 'credito' ? '' : $e($C::horas((int) $l['minutos_normais']))) . '</td><td style="' . $td . '">' . ($l['tipo'] === 'credito' ? '' : $e($C::horas((int) $l['minutos_extras']))) . '</td>';
    echo '<td style="' . $td . '">' . ($l['tipo'] === 'credito' ? '' : $e($C::reais((float) $l['valor_total']))) . '</td><td style="' . $td . '">' . $e($tipos[$l['tipo']][0] ?? $l['tipo']) . ($l['tipo'] === 'credito' ? ': +' . $e($C::horas((int) $l['saldo_depois'] - (int) $l['saldo_antes'])) : '') . ((int) $l['estornado'] ? ' (estornado)' : '') . '</td></tr>';
}
if (!$debitos) {
    echo '<tr><td colspan="6" style="' . $td . 'text-align:center;color:#999;">Nenhum atendimento cobrado neste ciclo.</td></tr>';
}
echo '</table>';
echo '<div style="margin-top:14px;font-size:10px;color:#999;text-align:right;">Gerado em ' . $e(Html::convDateTime(date('Y-m-d H:i:s'))) . ' por ' . $e(getUserName((int) Session::getLoginUserID())) . '</div>';
echo '</div></div></div>';
Html::footer();
