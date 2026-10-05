<?php

/**
 * Plugin Regular Atendimento - painel: contratos no ciclo atual e relógios rodando agora
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
if (!PluginRegularatendimentoConfig::podeVer()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

global $DB;
$C = PluginRegularatendimentoConfig::class;
$e = [$C, 'e'];
$ents = array_values(array_map('intval', $_SESSION['glpiactiveentities'] ?? []));

PluginRegularatendimentoCiclo::executar();

$contratos = iterator_to_array($DB->request(['FROM' => PluginRegularatendimentoContrato::TABELA, 'WHERE' => ['is_active' => 1] + ($ents ? ['entities_id' => $ents] : []), 'ORDER' => 'name']), false);
$tot = ['normais' => 0, 'extras' => 0, 'valor' => 0.0, 'estourados' => 0];
$linhas = [];
foreach ($contratos as $ct) {
    $ciclo = PluginRegularatendimentoCiclo::aberto((int) $ct['id']);
    if (!$ciclo) {
        continue;
    }
    $tot['normais'] += (int) $ciclo['minutos_normais'];
    $tot['extras'] += (int) $ciclo['minutos_extras'];
    $tot['valor'] += (float) $ciclo['valor_normal'] + (float) $ciclo['valor_extra'];
    if ((int) $ciclo['minutos_extras'] > 0) {
        $tot['estourados']++;
    }
    $linhas[] = [$ct, $ciclo];
}
usort($linhas, static fn($a, $b) => strcmp(mb_strtolower(PluginRegularatendimentoContrato::nome($a[0])), mb_strtolower(PluginRegularatendimentoContrato::nome($b[0]))));
$rodando = iterator_to_array($DB->request(['FROM' => PluginRegularatendimentoRelogio::TABELA, 'WHERE' => ['estado' => 'rodando'] + ($ents ? ['entities_id' => $ents] : []), 'ORDER' => 'rodando_desde', 'LIMIT' => 100]), false);

Html::header('Painel', $_SERVER['PHP_SELF'] ?? '', 'tools', 'PluginRegularatendimentoMenu', 'painel');
PluginRegularatendimentoMenu::abas('painel');
echo '<div class="regular">';
$ind = static fn(string $i, string $v, string $r, string $c = '') => '<div class="regular-indicador ' . $c . '"><i class="' . $i . '"></i><div><div class="regular-indicador-valor">' . $e($v) . '</div><div class="regular-indicador-rotulo">' . $e($r) . '</div></div></div>';
echo '<div class="regular-indicadores">';
echo $ind('ti ti-file-certificate', (string) count($linhas), 'Contratos ativos');
echo $ind('ti ti-clock-check', $C::horas($tot['normais']), 'Horas normais no ciclo');
echo $ind('ti ti-clock-exclamation', $C::horas($tot['extras']), 'Horas extras no ciclo', $tot['extras'] ? 'regular-ind-alerta' : '');
echo $ind('ti ti-cash', $C::reais($tot['valor']), 'Valor do ciclo');
echo $ind('ti ti-player-play', (string) count($rodando), 'Relógios rodando agora', count($rodando) ? 'regular-ind-ativo' : '');
echo '</div>';

echo '<div class="card regular-card"><div class="card-header regular-card-header"><h5><i class="ti ti-file-certificate"></i> Contratos no ciclo atual</h5>';
if ($C::podeGerenciar()) {
    echo '<a class="btn btn-sm regular-btn-salvar" href="' . $e($C::url('contrato.form.php')) . '"><i class="ti ti-plus"></i> Novo contrato</a>';
}
echo '</div><div class="card-body p-0">';
if (!$linhas) {
    echo '<div class="regular-vazio"><i class="ti ti-mood-empty"></i> Nenhum contrato ativo. Crie um contrato para a entidade definir a franquia e os valores da hora.</div>';
} else {
    echo '<div class="table-responsive"><table class="table table-hover table-sm regular-lista"><thead><tr><th>Contrato</th><th>Ciclo</th><th class="regular-col-uso">Franquia usada</th><th class="text-end">Extras</th><th class="text-end">Normal</th><th class="text-end">Extra</th><th class="text-end">Total</th><th></th></tr></thead><tbody>';
    foreach ($linhas as [$ct, $ci]) {
        $fr = (int) $ci['minutos_franquia'];
        $us = (int) $ci['minutos_normais'];
        $pct = $fr > 0 ? min(100, round($us / $fr * 100)) : 0;
        $classe = (int) $ci['minutos_extras'] > 0 ? 'estourado' : ($pct >= 80 ? 'perto' : 'ok');
        echo '<tr><td><a href="' . $e(PluginRegularatendimentoContrato::getFormURLWithID((int) $ct['id'])) . '"><strong>' . $e(PluginRegularatendimentoContrato::nome($ct)) . '</strong></a><div class="regular-ajuda">' . $e($C::reais((float) $ct['valor_hora'])) . '/h · extra ' . $e($C::reais((float) $ct['valor_hora_extra'])) . '/h</div></td>';
        echo '<td class="text-nowrap">' . $e(PluginRegularatendimentoCiclo::rotulo((string) $ci['referencia'])) . '<div class="regular-ajuda">fecha ' . $e(Html::convDate(substr((string) $ci['fim'], 0, 10))) . '</div></td>';
        echo '<td>' . ($fr > 0 ? '<div class="regular-uso regular-uso-' . $classe . '"><div class="regular-uso-barra"><div style="width:' . $pct . '%"></div></div><span>' . $e($C::horas($us) . ' de ' . $C::horas($fr) . ' · saldo ' . $C::horas(PluginRegularatendimentoCiclo::saldo($ci))) . '</span></div>' : '<span class="regular-ajuda">Sem franquia · ' . $e($C::horas($us)) . ' avulsas</span>') . '</td>';
        echo '<td class="text-end' . ((int) $ci['minutos_extras'] > 0 ? ' regular-negativo' : '') . '">' . $e($C::horas((int) $ci['minutos_extras'])) . '</td>';
        echo '<td class="text-end text-nowrap">' . $e($C::reais((float) $ci['valor_normal'])) . '</td><td class="text-end text-nowrap">' . $e($C::reais((float) $ci['valor_extra'])) . '</td>';
        echo '<td class="text-end text-nowrap"><strong>' . $e($C::reais((float) $ci['valor_normal'] + (float) $ci['valor_extra'])) . '</strong></td>';
        echo '<td class="text-end"><div class="regular-acoes-linha"><a class="btn btn-sm btn-outline-secondary" href="' . $e($C::url('extrato.php', ['ciclo' => (int) $ci['id']])) . '" title="Relatório do ciclo"><i class="ti ti-file-text"></i></a><a class="btn btn-sm btn-outline-secondary" href="' . $e($C::url('lancamentos.php', ['contrato' => (int) $ct['id']])) . '" title="Extrato"><i class="ti ti-list"></i></a></div></td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '</div></div>';

echo '<div class="card regular-card"><div class="card-header regular-card-header"><h5><i class="ti ti-player-play"></i> Relógios rodando agora</h5><span class="regular-contador">' . count($rodando) . '</span></div><div class="card-body p-0">';
if (!$rodando) {
    echo '<div class="regular-vazio">Nenhum atendimento em andamento.</div>';
} else {
    echo '<div class="table-responsive"><table class="table table-hover table-sm regular-lista"><thead><tr><th>Chamado</th><th>Entidade</th><th>Técnicos</th><th>Rodando desde</th><th class="text-end">Tempo</th></tr></thead><tbody>';
    foreach ($rodando as $r) {
        $t = new Ticket();
        if (!$t->getFromDB((int) $r['tickets_id'])) {
            continue;
        }
        $tecnicos = array_map(static fn($u) => getUserName((int) $u['users_id']), $t->getUsers(CommonITILActor::ASSIGN));
        $seg = PluginRegularatendimentoRelogio::segundosAgora($r);
        echo '<tr><td><a href="' . $e(Ticket::getFormURLWithID((int) $r['tickets_id'])) . '">#' . (int) $r['tickets_id'] . ' ' . $e($t->fields['name']) . '</a></td><td>' . $e($C::nomeEntidade((int) $r['entities_id'])) . '</td>';
        echo '<td>' . $e(implode(', ', $tecnicos) ?: '—') . '</td><td class="text-nowrap">' . $e(Html::convDateTime($r['rodando_desde'])) . '</td>';
        echo '<td class="text-end"><span class="regular-digitos regular-digitos-p" data-regular-segundos="' . $seg . '" data-regular-rodando="1">' . $e($C::relogio($seg)) . '</span></td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '</div></div></div>';
Html::footer();
