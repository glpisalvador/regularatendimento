<?php

/**
 * Plugin Regular Atendimento - lançar atendimento já feito (cria o chamado solucionado e cobra o tempo informado)
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
if (!PluginRegularatendimentoConfig::podeGerenciar()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$C = PluginRegularatendimentoConfig::class;
$e = [$C, 'e'];
$v = $_POST;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['save_action'] ?? '') === 'lancar') {
    $erros = [];
    $entidade = (int) ($v['entities_id'] ?? -1);
    $titulo = trim((string) ($v['name'] ?? ''));
    $inicio = strtotime((string) ($v['inicio'] ?? ''));
    $minutos = $C::lerMinutos($v['duracao'] ?? '');
    $requerente = (int) ($v['requerente'] ?? 0);
    $tecnico = (int) ($v['tecnico'] ?? 0);
    if ($entidade < 0 || !Session::haveAccessToEntity($entidade)) {
        $erros[] = 'Escolha a entidade.';
    }
    if ($titulo === '') {
        $erros[] = 'Informe o título.';
    }
    if (!$inicio || $inicio > time()) {
        $erros[] = 'Informe quando o atendimento começou (não pode ser no futuro).';
    }
    if ($minutos <= 0) {
        $erros[] = 'Informe a duração (ex.: 1:30).';
    }
    if ($inicio && $minutos > 0 && $inicio + $minutos * 60 > time()) {
        $erros[] = 'O atendimento informado termina no futuro.';
    }
    if ($tecnico <= 0) {
        $erros[] = 'Escolha o técnico.';
    }
    if ($erros) {
        foreach ($erros as $m) {
            Session::addMessageAfterRedirect($m, false, ERROR);
        }
    } else {
        $atores = ['assign' => [['itemtype' => 'User', 'items_id' => $tecnico, 'use_notification' => 0]]];
        if ($requerente > 0) {
            $atores['requester'] = [['itemtype' => 'User', 'items_id' => $requerente, 'use_notification' => 0]];
        }
        $t = new Ticket();
        $input = [
            'name'              => mb_substr($titulo, 0, 250),
            'content'           => trim(strip_tags((string) ($v['content'] ?? ''))) !== '' ? (string) $v['content'] : '<p>' . $e($titulo) . '</p>',
            'entities_id'       => $entidade,
            'itilcategories_id' => (int) ($v['itilcategories_id'] ?? 0),
            'date'              => date('Y-m-d H:i:s', $inicio),
            '_actors'           => $atores,
            '_regular_retroativo' => ['inicio' => $inicio, 'minutos' => $minutos],
            '_disablenotif'     => true,
        ];
        $id = $t->can(-1, CREATE, $input) ? (int) $t->add($input) : 0;
        if ($id <= 0) {
            Session::addMessageAfterRedirect('O GLPI não aceitou o chamado (verifique a entidade e os campos obrigatórios).', false, ERROR);
        } else {
            $solucao = trim(strip_tags((string) ($v['solucao'] ?? ''))) !== '' ? (string) $v['solucao'] : '<p>Atendimento realizado em ' . $e(Html::convDateTime(date('Y-m-d H:i:s', $inicio))) . ' (' . $e($C::horas($minutos)) . ').</p>';
            (new ITILSolution())->add(['itemtype' => 'Ticket', 'items_id' => $id, 'content' => $solucao, '_disablenotif' => true]);
            // Se a solução não mudou o status (ex.: regras da entidade), finaliza o relógio mesmo assim
            PluginRegularatendimentoRelogio::finalizar($id, 'Atendimento lançado depois');
            $r = PluginRegularatendimentoRelogio::obter($id);
            Session::addMessageAfterRedirect('Chamado #' . $id . ' criado e solucionado. Cobrado: ' . $C::horas((int) ($r['minutos_cobrados'] ?? 0)) . '.', false, INFO);
            Html::redirect(Ticket::getFormURLWithID($id) . '&forcetab=PluginRegularatendimentoChamado$1');
        }
    }
}

Html::header('Lançar atendimento', $_SERVER['PHP_SELF'] ?? '', 'tools', 'PluginRegularatendimentoMenu', 'lancar');
PluginRegularatendimentoMenu::abas('lancar');
echo $C::scriptEntidades();
echo '<div class="regular">';
echo '<p class="text-muted regular-dica"><i class="ti ti-info-circle"></i> Para atendimentos feitos fora do GLPI (por telefone, no local, fora do horário): cria o chamado já solucionado e cobra a duração informada pelo contrato da entidade.</p>';
echo '<form method="post" action="' . $e($C::url('lancar.php')) . '"><input type="hidden" name="save_action" value="lancar">';
echo '<table class="tab_cadre_fixe regular-tabela-form"><tr class="tab_bg_2"><th colspan="4"><i class="ti ti-clock-plus"></i> Atendimento realizado</th></tr>';
echo '<tr class="tab_bg_1"><td class="regular-rotulo">Entidade <span class="required">*</span></td><td>';
Entity::dropdown(['name' => 'entities_id', 'value' => (int) ($v['entities_id'] ?? $_SESSION['glpiactive_entity'] ?? 0), 'entity' => $_SESSION['glpiactiveentities'] ?? [], 'width' => '100%']);
echo '</td><td class="regular-rotulo">Categoria</td><td>';
ITILCategory::dropdown(['name' => 'itilcategories_id', 'value' => (int) ($v['itilcategories_id'] ?? 0), 'entity' => $_SESSION['glpiactiveentities'] ?? [], 'width' => '100%']);
echo '</td></tr>';
echo '<tr class="tab_bg_1"><td class="regular-rotulo">Título <span class="required">*</span></td><td colspan="3"><input type="text" class="form-control" name="name" maxlength="250" required value="' . $e($v['name'] ?? '') . '"></td></tr>';
echo '<tr class="tab_bg_1"><td class="regular-rotulo">Requerente</td><td>';
User::dropdown(['name' => 'requerente', 'value' => (int) ($v['requerente'] ?? 0), 'right' => 'all', 'entity' => $_SESSION['glpiactiveentities'] ?? [], 'width' => '100%']);
echo '</td><td class="regular-rotulo">Técnico <span class="required">*</span></td><td>';
User::dropdown(['name' => 'tecnico', 'value' => (int) ($v['tecnico'] ?? Session::getLoginUserID()), 'right' => 'own_ticket', 'entity' => $_SESSION['glpiactiveentities'] ?? [], 'width' => '100%']);
echo '</td></tr>';
echo '<tr class="tab_bg_1"><td class="regular-rotulo">Início do atendimento <span class="required">*</span></td><td>';
Html::showDateTimeField('inicio', ['value' => $v['inicio'] ?? date('Y-m-d H:00:00', strtotime('-1 hour')), 'maxDate' => date('Y-m-d H:i:s')]);
echo '</td><td class="regular-rotulo">Duração <span class="required">*</span></td><td><input type="text" class="form-control" name="duracao" required placeholder="Ex.: 1:30" value="' . $e($v['duracao'] ?? '') . '"><div class="regular-ajuda">O mínimo e o arredondamento do contrato são aplicados.</div></td></tr>';
echo '<tr class="tab_bg_1"><td class="regular-rotulo">Descrição</td><td colspan="3">';
Html::textarea(['name' => 'content', 'value' => $v['content'] ?? '', 'enable_richtext' => true, 'cols' => 100, 'rows' => 4]);
echo '</td></tr><tr class="tab_bg_1"><td class="regular-rotulo">Solução</td><td colspan="3">';
Html::textarea(['name' => 'solucao', 'value' => $v['solucao'] ?? '', 'enable_richtext' => true, 'cols' => 100, 'rows' => 4]);
echo '</td></tr>';
echo '<tr class="tab_bg_2"><td colspan="4" class="center"><button type="submit" class="btn btn-primary regular-btn-salvar"><i class="ti ti-device-floppy"></i> Criar chamado solucionado e cobrar</button></td></tr></table>';
Html::closeForm();
echo '</div>';
Html::footer();
