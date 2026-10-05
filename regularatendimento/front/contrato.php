<?php

/**
 * Plugin Regular Atendimento - contratos (busca nativa)
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
if (!PluginRegularatendimentoConfig::podeVer()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

Html::header('Contratos', $_SERVER['PHP_SELF'] ?? '', 'tools', 'PluginRegularatendimentoMenu', 'contrato');
PluginRegularatendimentoMenu::abas('contrato');
Search::show('PluginRegularatendimentoContrato');
Html::footer();
