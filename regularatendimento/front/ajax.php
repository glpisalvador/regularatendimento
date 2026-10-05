<?php

/**
 * Plugin Regular Atendimento - endpoints AJAX (JSON) do relógio: estado, iniciar, pausar, ajustar
 */

while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();
include('../../../inc/includes.php');
while (ob_get_level() > 0) {
    ob_end_clean();
}

register_shutdown_function(static function (): void {
    $erro = error_get_last();
    if ($erro && in_array($erro['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['success' => false, 'message' => 'Erro interno ao processar a solicitação.']);
    }
});

function regular_responder(array $dados): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $dados['new_token'] = PluginRegularatendimentoConfig::tokenCsrf();
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!Session::getLoginUserID()) {
    regular_responder(['success' => false, 'message' => 'Sessão expirada.']);
}

$acao = (string) ($_REQUEST['action'] ?? '');
$t = new Ticket();
$id = (int) ($_REQUEST['ticket'] ?? 0);
if ($id <= 0 || !$t->getFromDB($id) || !PluginRegularatendimentoChamado::podeVerRelogio($t)) {
    regular_responder(['success' => false, 'message' => 'Chamado não encontrado.']);
}
$controlar = PluginRegularatendimentoChamado::podeControlar($t);

switch ($acao) {
    case 'estado':
        $d = PluginRegularatendimentoChamado::dados($id);
        regular_responder(['success' => true, 'dados' => $d, 'html' => PluginRegularatendimentoChamado::htmlCartao($d, $controlar)]);

        // no break
    case 'iniciar':
    case 'pausar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$controlar) {
            regular_responder(['success' => false, 'message' => 'Você não pode controlar o relógio deste chamado.']);
        }
        [$ok, $msg] = $acao === 'iniciar'
            ? PluginRegularatendimentoRelogio::iniciar($id, 'manual')
            : PluginRegularatendimentoRelogio::pausar($id, trim((string) ($_POST['motivo'] ?? '')) ?: 'Pausado manualmente', 'manual');
        $d = PluginRegularatendimentoChamado::dados($id);
        regular_responder(['success' => $ok, 'message' => $msg, 'dados' => $d, 'html' => PluginRegularatendimentoChamado::htmlCartao($d, $controlar)]);

        // no break
    case 'ajustar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !PluginRegularatendimentoConfig::podeGerenciar()) {
            regular_responder(['success' => false, 'message' => 'Sem permissão para ajustar o tempo.']);
        }
        [$ok, $msg] = PluginRegularatendimentoRelogio::ajustar($id, PluginRegularatendimentoConfig::lerMinutos($_POST['minutos'] ?? ''), (string) ($_POST['motivo'] ?? ''));
        if ($ok) {
            Session::addMessageAfterRedirect($msg, false, INFO);
        }
        regular_responder(['success' => $ok, 'message' => $msg]);

        // no break
    default:
        regular_responder(['success' => false, 'message' => 'Ação desconhecida.']);
}
