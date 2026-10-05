<?php

/**
 * Plugin Regular Atendimento - instalação e desinstalação (a desinstalação nunca remove tabelas)
 */

function plugin_regularatendimento_install(): bool
{
    global $DB;

    $o = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC';
    $p = 'glpi_plugin_regularatendimento_';

    $tabelas = [
        $p . 'configs' => "CREATE TABLE `{$p}configs` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL,
            `value` longtext,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `name` (`name`)
        ) $o",
        $p . 'contratos' => "CREATE TABLE `{$p}contratos` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `entities_id` int unsigned NOT NULL DEFAULT 0,
            `name` varchar(255) NOT NULL DEFAULT '',
            `minutos_franquia` int unsigned NOT NULL DEFAULT 0,
            `valor_hora` decimal(12,2) NOT NULL DEFAULT 0.00,
            `valor_hora_extra` decimal(12,2) NOT NULL DEFAULT 0.00,
            `minimo_minutos` int unsigned NOT NULL DEFAULT 30,
            `arredondamento` int unsigned NOT NULL DEFAULT 0,
            `acumular_saldo` tinyint(1) NOT NULL DEFAULT 0,
            `incluir_filhas` tinyint(1) NOT NULL DEFAULT 0,
            `calendars_id` int unsigned NOT NULL DEFAULT 0,
            `dia_fechamento` tinyint unsigned NOT NULL DEFAULT 1,
            `is_active` tinyint(1) NOT NULL DEFAULT 1,
            `comment` longtext,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `entities_id` (`entities_id`),
            KEY `is_active` (`is_active`)
        ) $o",
        $p . 'ciclos' => "CREATE TABLE `{$p}ciclos` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `plugin_regularatendimento_contratos_id` int unsigned NOT NULL DEFAULT 0,
            `entities_id` int unsigned NOT NULL DEFAULT 0,
            `referencia` varchar(7) NOT NULL DEFAULT '',
            `inicio` timestamp NULL DEFAULT NULL,
            `fim` timestamp NULL DEFAULT NULL,
            `minutos_franquia` int NOT NULL DEFAULT 0,
            `minutos_transportados` int NOT NULL DEFAULT 0,
            `minutos_normais` int NOT NULL DEFAULT 0,
            `minutos_extras` int NOT NULL DEFAULT 0,
            `valor_normal` decimal(14,2) NOT NULL DEFAULT 0.00,
            `valor_extra` decimal(14,2) NOT NULL DEFAULT 0.00,
            `status` varchar(10) NOT NULL DEFAULT 'aberto',
            `fechado_em` timestamp NULL DEFAULT NULL,
            `users_id_fechou` int unsigned NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `contrato_status` (`plugin_regularatendimento_contratos_id`, `status`),
            KEY `referencia` (`referencia`)
        ) $o",
        $p . 'lancamentos' => "CREATE TABLE `{$p}lancamentos` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `plugin_regularatendimento_contratos_id` int unsigned NOT NULL DEFAULT 0,
            `plugin_regularatendimento_ciclos_id` int unsigned NOT NULL DEFAULT 0,
            `entities_id` int unsigned NOT NULL DEFAULT 0,
            `tickets_id` int unsigned NOT NULL DEFAULT 0,
            `tipo` varchar(15) NOT NULL DEFAULT 'debito',
            `minutos_normais` int NOT NULL DEFAULT 0,
            `minutos_extras` int NOT NULL DEFAULT 0,
            `valor_normal` decimal(14,2) NOT NULL DEFAULT 0.00,
            `valor_extra` decimal(14,2) NOT NULL DEFAULT 0.00,
            `valor_total` decimal(14,2) NOT NULL DEFAULT 0.00,
            `saldo_antes` int NOT NULL DEFAULT 0,
            `saldo_depois` int NOT NULL DEFAULT 0,
            `estornado` tinyint(1) NOT NULL DEFAULT 0,
            `observacao` text,
            `users_id` int unsigned NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `ciclo` (`plugin_regularatendimento_ciclos_id`),
            KEY `contrato` (`plugin_regularatendimento_contratos_id`),
            KEY `tickets_id` (`tickets_id`),
            KEY `tipo` (`tipo`),
            KEY `date_creation` (`date_creation`)
        ) $o",
        $p . 'relogios' => "CREATE TABLE `{$p}relogios` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `tickets_id` int unsigned NOT NULL DEFAULT 0,
            `entities_id` int unsigned NOT NULL DEFAULT 0,
            `estado` varchar(12) NOT NULL DEFAULT 'parado',
            `rodando_desde` timestamp NULL DEFAULT NULL,
            `segundos` int unsigned NOT NULL DEFAULT 0,
            `segundos_fora` int unsigned NOT NULL DEFAULT 0,
            `minutos_cobrados` int unsigned NOT NULL DEFAULT 0,
            `plugin_regularatendimento_lancamentos_id` int unsigned NOT NULL DEFAULT 0,
            `is_ajustado` tinyint(1) NOT NULL DEFAULT 0,
            `iniciado_em` timestamp NULL DEFAULT NULL,
            `finalizado_em` timestamp NULL DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tickets_id` (`tickets_id`),
            KEY `estado` (`estado`),
            KEY `entities_id` (`entities_id`)
        ) $o",
        $p . 'periodos' => "CREATE TABLE `{$p}periodos` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `plugin_regularatendimento_relogios_id` int unsigned NOT NULL DEFAULT 0,
            `inicio` timestamp NULL DEFAULT NULL,
            `fim` timestamp NULL DEFAULT NULL,
            `segundos` int unsigned NOT NULL DEFAULT 0,
            `segundos_fora` int unsigned NOT NULL DEFAULT 0,
            `origem_inicio` varchar(15) NOT NULL DEFAULT 'automatico',
            `origem_fim` varchar(15) NOT NULL DEFAULT '',
            `motivo_fim` varchar(255) NOT NULL DEFAULT '',
            `users_id_inicio` int unsigned NOT NULL DEFAULT 0,
            `users_id_fim` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `relogio` (`plugin_regularatendimento_relogios_id`)
        ) $o",
    ];
    foreach ($tabelas as $nome => $sql) {
        if (!$DB->tableExists($nome, false)) {
            $DB->doQuery($sql);
        }
    }

    foreach (PluginRegularatendimentoConfig::padroes() as $nome => $valor) {
        if (count($DB->request(['FROM' => $p . 'configs', 'WHERE' => ['name' => $nome]])) === 0) {
            $DB->insert($p . 'configs', ['name' => $nome, 'value' => $valor]);
        }
    }

    // Direito nativo: quem administra o GLPI vê e gerencia
    foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_profiles']) as $perfil) {
        $pid = (int) $perfil['id'];
        if (count($DB->request(['FROM' => 'glpi_profilerights', 'WHERE' => ['profiles_id' => $pid, 'name' => PluginRegularatendimentoConfig::DIREITO], 'LIMIT' => 1])) > 0) {
            continue;
        }
        $config = $DB->request(['SELECT' => ['rights'], 'FROM' => 'glpi_profilerights', 'WHERE' => ['profiles_id' => $pid, 'name' => 'config'], 'LIMIT' => 1])->current();
        $direito = ($config && ((int) $config['rights'] & UPDATE)) ? (READ | UPDATE) : 0;
        $DB->insert('glpi_profilerights', ['profiles_id' => $pid, 'name' => PluginRegularatendimentoConfig::DIREITO, 'rights' => $direito]);
        if (isset($_SESSION['glpiactiveprofile']['id']) && (int) $_SESSION['glpiactiveprofile']['id'] === $pid) {
            $_SESSION['glpiactiveprofile'][PluginRegularatendimentoConfig::DIREITO] = $direito;
        }
    }

    CronTask::register('PluginRegularatendimentoCiclo', 'RegularatendimentoFechamento', HOUR_TIMESTAMP, [
        'mode'    => CronTask::MODE_EXTERNAL,
        'state'   => CronTask::STATE_WAITING,
        'comment' => 'Regular Atendimento: fecha os ciclos vencidos e abre os novos',
    ]);

    return true;
}

/** Desinstalar mantém todas as tabelas e direitos (os dados ficam para uma reinstalação) */
function plugin_regularatendimento_uninstall(): bool
{
    return true;
}
