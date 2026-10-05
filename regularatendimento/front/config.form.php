<?php

/**
 * Plugin Regular Atendimento - configuração
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
if (!PluginRegularatendimentoConfig::ehAdmin()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$C = PluginRegularatendimentoConfig::class;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_action'])) {
    switch ($_POST['save_action']) {
        case 'salvar_relogio':
            foreach (['auto_iniciar', 'mostrar_sem_contrato', 'mostrar_solicitante'] as $c) {
                $C::setConfig($c, !empty($_POST[$c]) ? '1' : '0');
            }
            Session::addMessageAfterRedirect('Configuração do relógio salva.', false, INFO);
            break;
        case 'salvar_padroes':
            $C::setConfig('padrao_franquia', (string) $C::lerMinutos($_POST['padrao_franquia'] ?? '') === '0' ? '' : $C::minutosParaCampo($C::lerMinutos($_POST['padrao_franquia'] ?? '')));
            $C::setConfig('padrao_valor_hora', (string) $C::lerValor($_POST['padrao_valor_hora'] ?? 0));
            $C::setConfig('padrao_valor_extra', (string) $C::lerValor($_POST['padrao_valor_extra'] ?? 0));
            $C::setConfig('padrao_minimo', (string) max(0, min(600, (int) ($_POST['padrao_minimo'] ?? 30))));
            $C::setConfig('padrao_arredondamento', (string) (array_key_exists((int) ($_POST['padrao_arredondamento'] ?? 0), PluginRegularatendimentoContrato::arredondamentos()) ? (int) $_POST['padrao_arredondamento'] : 0));
            Session::addMessageAfterRedirect('Valores padrão salvos.', false, INFO);
            break;
        case 'fechar_vencidos':
            $n = PluginRegularatendimentoCiclo::executar();
            Session::addMessageAfterRedirect($n ? $n . ' ciclo(s) processado(s).' : 'Nenhum ciclo vencido.', false, INFO);
            break;
    }
}

$e = [$C, 'e'];
$acao = $e($C::url('config.form.php'));
$botao = '<tr class="tab_bg_2"><td colspan="4" class="center"><button type="submit" class="btn btn-primary regular-btn-salvar"><i class="ti ti-device-floppy"></i> Salvar</button></td></tr>';
$sw = static function (string $nome, string $rotulo) use ($C, $e): string {
    $id = 'regular_' . $nome;
    return '<div class="form-check form-switch regular-switch"><input type="hidden" name="' . $nome . '" value="0"><input class="form-check-input" type="checkbox" role="switch" id="' . $id . '" name="' . $nome . '" value="1"' . ($C::ligado($nome) ? ' checked' : '') . '><label class="form-check-label" for="' . $id . '">' . $e($rotulo) . '</label></div>';
};
$moeda = static fn($v) => (float) $v > 0 ? number_format((float) $v, 2, ',', '.') : '';

Html::header('Configuração', $_SERVER['PHP_SELF'] ?? '', 'tools', 'PluginRegularatendimentoMenu', 'config');
PluginRegularatendimentoMenu::abas('config');
echo '<div class="regular">';

echo '<form method="post" action="' . $acao . '"><input type="hidden" name="save_action" value="salvar_relogio">';
echo '<table class="tab_cadre_fixe regular-tabela-form"><tr class="tab_bg_2"><th colspan="4"><i class="ti ti-clock-hour-4"></i> Relógio no chamado</th></tr>';
echo '<tr class="tab_bg_1"><td class="regular-rotulo">Início automático</td><td>' . $sw('auto_iniciar', 'Rodar quando o chamado fica "Em atendimento"') . '<div class="regular-ajuda">Desligado, o técnico inicia pelo botão. A pausa (pendente) e a finalização (solucionado/fechado) são sempre automáticas.</div></td>';
echo '<td class="regular-rotulo">Exibição</td><td>' . $sw('mostrar_sem_contrato', 'Mostrar também em entidades sem contrato') . $sw('mostrar_solicitante', 'Mostrar para quem usa a interface simplificada') . '</td></tr>';
echo $botao . '</table>';
Html::closeForm();

echo '<form method="post" action="' . $acao . '"><input type="hidden" name="save_action" value="salvar_padroes">';
echo '<table class="tab_cadre_fixe regular-tabela-form"><tr class="tab_bg_2"><th colspan="4"><i class="ti ti-file-certificate"></i> Valores padrão de novos contratos</th></tr>';
echo '<tr class="tab_bg_1"><td class="regular-rotulo">Franquia mensal</td><td><input type="text" class="form-control" name="padrao_franquia" value="' . $e($C::getConfig('padrao_franquia')) . '" placeholder="Ex.: 20 ou 20:30"></td>';
echo '<td class="regular-rotulo">Tempo mínimo (min)</td><td><input type="number" min="0" max="600" class="form-control" name="padrao_minimo" value="' . (int) $C::getConfig('padrao_minimo') . '"></td></tr>';
echo '<tr class="tab_bg_1"><td class="regular-rotulo">Valor da hora normal (R$)</td><td><input type="text" class="form-control" data-regular-moeda name="padrao_valor_hora" value="' . $e($moeda($C::getConfig('padrao_valor_hora'))) . '" placeholder="0,00"></td>';
echo '<td class="regular-rotulo">Valor da hora extra (R$)</td><td><input type="text" class="form-control" data-regular-moeda name="padrao_valor_extra" value="' . $e($moeda($C::getConfig('padrao_valor_extra'))) . '" placeholder="0,00"></td></tr>';
echo '<tr class="tab_bg_1"><td class="regular-rotulo">Arredondamento</td><td>';
Dropdown::showFromArray('padrao_arredondamento', PluginRegularatendimentoContrato::arredondamentos(), ['value' => (int) $C::getConfig('padrao_arredondamento'), 'width' => '100%']);
echo '</td><td colspan="2"><div class="regular-ajuda">Também valem para chamados de entidades sem contrato (tempo cobrável mostrado no relógio).</div></td></tr>';
echo $botao . '</table>';
Html::closeForm();

echo '<form method="post" action="' . $acao . '"><input type="hidden" name="save_action" value="fechar_vencidos">';
echo '<table class="tab_cadre_fixe regular-tabela-form"><tr class="tab_bg_2"><th colspan="2"><i class="ti ti-calendar"></i> Fechamento mensal</th></tr>';
echo '<tr class="tab_bg_1"><td><p class="text-muted regular-dica mb-0"><i class="ti ti-info-circle"></i> A tarefa automática "RegularatendimentoFechamento" (de hora em hora) fecha os ciclos no dia de fechamento de cada contrato e abre o próximo com a franquia renovada.</p></td>';
echo '<td class="text-end"><button type="submit" class="btn btn-sm btn-outline-secondary"><i class="ti ti-refresh"></i> Processar agora</button></td></tr></table>';
Html::closeForm();

echo '<p class="text-muted regular-dica"><i class="ti ti-info-circle"></i> Quem vê e gerencia a gestão de tempos é definido pelo direito nativo "Regular Atendimento", na aba de mesmo nome em Administração › Perfis.</p>';
echo '</div>';
Html::footer();
