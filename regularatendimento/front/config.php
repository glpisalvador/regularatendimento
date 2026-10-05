<?php

/**
 * Plugin Regular Atendimento - atalho da configuração (marketplace)
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
Html::redirect(PluginRegularatendimentoConfig::url('config.form.php'));
