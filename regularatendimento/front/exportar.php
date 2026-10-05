<?php

/**
 * Plugin Regular Atendimento - exportação CSV do extrato (filtros da página ou um ciclo)
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
if (!PluginRegularatendimentoConfig::podeVer()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$C = PluginRegularatendimentoConfig::class;
$L = PluginRegularatendimentoLancamento::class;
$f = $L::filtros($_GET);
$linhas = $L::listar($f, 50000);
$tipos = $L::tipos();
$nomes = [];

$saida = [['Data', 'Contrato', 'Entidade', 'Ciclo', 'Tipo', 'Chamado', 'Minutos normais', 'Minutos extras', 'Horas normais', 'Horas extras', 'Valor normal', 'Valor extra', 'Valor total', 'Saldo depois (min)', 'Estornado', 'Observação', 'Usuário']];
foreach ($linhas as $l) {
    $cid = (int) $l['plugin_regularatendimento_contratos_id'];
    if (!isset($nomes[$cid])) {
        $ct = PluginRegularatendimentoContrato::obter($cid);
        $nomes[$cid] = $ct ? PluginRegularatendimentoContrato::nome($ct) : '#' . $cid;
    }
    $ci = PluginRegularatendimentoCiclo::obter((int) $l['plugin_regularatendimento_ciclos_id']);
    $saida[] = [
        Html::convDateTime($l['date_creation']), $nomes[$cid], $C::nomeEntidade((int) $l['entities_id']), $ci ? $ci['referencia'] : '', $tipos[$l['tipo']][0] ?? $l['tipo'],
        (int) $l['tickets_id'] ?: '', (int) $l['minutos_normais'], (int) $l['minutos_extras'], $C::horas((int) $l['minutos_normais']), $C::horas((int) $l['minutos_extras']),
        number_format((float) $l['valor_normal'], 2, ',', '.'), number_format((float) $l['valor_extra'], 2, ',', '.'), number_format((float) $l['valor_total'], 2, ',', '.'),
        (int) $l['saldo_depois'], (int) $l['estornado'] ? 'Sim' : 'Não', (string) $l['observacao'], (int) $l['users_id'] ? getUserName((int) $l['users_id']) : 'Automático',
    ];
}

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="extrato_horas_' . date('Ymd_His') . '.csv"');
header('Cache-Control: no-store');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
foreach ($saida as $linha) {
    fwrite($out, implode(';', array_map(static fn($v) => '"' . str_replace('"', '""', (string) $v) . '"', $linha)) . "\r\n");
}
fclose($out);
exit;
