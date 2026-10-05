<?php

/**
 * Plugin Regular Atendimento - GLPI 11 e 12 (une os antigos "relogio" e "relogiodeatendimento")
 * Relógio de atendimento no chamado, contratos de horas por entidade (franquia, hora normal, hora extra),
 * ciclos mensais com fechamento automático, extrato, painel e lançamento retroativo.
 */

define('PLUGIN_REGULARATENDIMENTO_VERSION', '1.0.0');
define('PLUGIN_REGULARATENDIMENTO_MIN_GLPI', '11.0.0');
define('PLUGIN_REGULARATENDIMENTO_MAX_GLPI', '12.99.99');

function plugin_init_regularatendimento(): void
{
    global $PLUGIN_HOOKS;

    // Chave literal: a constante Hooks::CSRF_COMPLIANT não existe no GLPI 12
    $PLUGIN_HOOKS['csrf_compliant']['regularatendimento'] = true;

    $plugin = new Plugin();
    if (!$plugin->isActivated('regularatendimento')) {
        return;
    }

    Plugin::registerClass('PluginRegularatendimentoContrato');
    Plugin::registerClass('PluginRegularatendimentoCiclo');
    Plugin::registerClass('PluginRegularatendimentoLancamento');
    Plugin::registerClass('PluginRegularatendimentoMenu');
    Plugin::registerClass('PluginRegularatendimentoChamado', ['addtabon' => ['Ticket']]);
    Plugin::registerClass('PluginRegularatendimentoProfile', ['addtabon' => ['Profile']]);

    $PLUGIN_HOOKS['config_page']['regularatendimento'] = 'front/config.form.php';

    // O relógio reage ao status do chamado
    $R = 'PluginRegularatendimentoRelogio';
    $PLUGIN_HOOKS['pre_item_add']['regularatendimento'] = ['Ticket' => [$R, 'antesDeCriarChamado']];
    $PLUGIN_HOOKS['item_add']['regularatendimento'] = ['Ticket' => [$R, 'aoCriarChamado']];
    $PLUGIN_HOOKS['item_update']['regularatendimento'] = ['Ticket' => [$R, 'aoAtualizarChamado']];
    $PLUGIN_HOOKS['item_purge']['regularatendimento'] = ['Ticket' => [$R, 'aoExcluirChamado']];

    // Cartão do relógio no formulário do chamado
    $PLUGIN_HOOKS['pre_item_form']['regularatendimento'] = ['PluginRegularatendimentoChamado', 'cartao'];

    if (Session::getLoginUserID()) {
        $PLUGIN_HOOKS['add_css']['regularatendimento'] = ['css/regularatendimento.css'];
        $PLUGIN_HOOKS['add_javascript']['regularatendimento'] = ['js/regularatendimento.js'];
        if (PluginRegularatendimentoConfig::podeVer()) {
            $PLUGIN_HOOKS['menu_toadd']['regularatendimento'] = ['tools' => 'PluginRegularatendimentoMenu'];
        }
    }
}

function plugin_version_regularatendimento(): array
{
    return [
        'name'         => 'Regular Atendimento',
        'version'      => PLUGIN_REGULARATENDIMENTO_VERSION,
        'author'       => 'GLPI Salvador',
        'license'      => 'GPLv2+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_REGULARATENDIMENTO_MIN_GLPI,
                'max' => PLUGIN_REGULARATENDIMENTO_MAX_GLPI,
            ],
            'php'  => ['min' => '8.1'],
        ],
    ];
}

function plugin_regularatendimento_check_prerequisites(): bool
{
    return version_compare(GLPI_VERSION, PLUGIN_REGULARATENDIMENTO_MIN_GLPI, '>=');
}

function plugin_regularatendimento_check_config($verbose = false): bool
{
    return true;
}
