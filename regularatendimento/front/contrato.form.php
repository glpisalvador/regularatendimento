<?php

/**
 * Plugin Regular Atendimento - formulário do contrato (criar, salvar, excluir, fechar ciclo)
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
if (!PluginRegularatendimentoConfig::podeVer()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$contrato = new PluginRegularatendimentoContrato();

if (($_POST['save_action'] ?? '') === 'fechar_ciclo') {
    if (!PluginRegularatendimentoConfig::podeGerenciar()) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    $id = (int) ($_POST['id'] ?? 0);
    $ciclo = PluginRegularatendimentoCiclo::aberto($id);
    if ($ciclo && PluginRegularatendimentoCiclo::fechar((int) $ciclo['id'])) {
        Session::addMessageAfterRedirect('Ciclo ' . PluginRegularatendimentoCiclo::rotulo((string) $ciclo['referencia']) . ' fechado e novo ciclo aberto.', false, INFO);
    }
    Html::redirect(PluginRegularatendimentoContrato::getFormURLWithID($id) . '&forcetab=PluginRegularatendimentoCiclo$1');
}

if (isset($_POST['add'])) {
    $contrato->check(-1, CREATE, $_POST);
    $id = (int) $contrato->add($_POST);
    Html::redirect($id ? $contrato->getFormURLWithID($id) : PluginRegularatendimentoConfig::url('contrato.form.php'));
} elseif (isset($_POST['update'])) {
    $contrato->check((int) $_POST['id'], UPDATE);
    $contrato->update($_POST);
    Html::back();
} elseif (isset($_POST['purge']) || isset($_POST['delete'])) {
    $contrato->check((int) $_POST['id'], PURGE);
    $contrato->delete($_POST, true);
    $contrato->redirectToList();
}

$id = (int) ($_GET['id'] ?? 0);
if ($id > 0) {
    $contrato->check($id, READ);
} elseif (!PluginRegularatendimentoContrato::canCreate()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
Html::header(PluginRegularatendimentoContrato::getTypeName(1), $_SERVER['PHP_SELF'] ?? '', 'tools', 'PluginRegularatendimentoMenu', 'contrato');
$contrato->display(['id' => $id]);
Html::footer();
