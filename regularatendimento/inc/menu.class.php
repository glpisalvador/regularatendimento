<?php

/**
 * Plugin Regular Atendimento - item no menu Ferramentas e abas entre as páginas
 */
class PluginRegularatendimentoMenu extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return 'Regular Atendimento';
    }

    public static function getMenuName(): string
    {
        return 'Regular Atendimento';
    }

    public static function getIcon()
    {
        return 'ti ti-clock-dollar';
    }

    public static function canView(): bool
    {
        return PluginRegularatendimentoConfig::podeVer();
    }

    public static function paginas(): array
    {
        $p = [
            'painel'      => ['Painel', 'ti ti-gauge', 'painel.php'],
            'contrato'    => ['Contratos', 'ti ti-file-certificate', 'contrato.php'],
            'lancamentos' => ['Extrato', 'ti ti-list', 'lancamentos.php'],
        ];
        if (PluginRegularatendimentoConfig::podeGerenciar()) {
            $p['lancar'] = ['Lançar atendimento', 'ti ti-clock-plus', 'lancar.php'];
        }
        if (PluginRegularatendimentoConfig::ehAdmin()) {
            $p['config'] = ['Configuração', 'ti ti-settings', 'config.form.php'];
        }
        return $p;
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }
        $b = '/plugins/regularatendimento/front/';
        $menu = ['title' => self::getMenuName(), 'page' => $b . 'painel.php', 'icon' => 'ti ti-clock-dollar', 'options' => []];
        foreach (self::paginas() as $k => [$t, $i, $pg]) {
            $menu['options'][$k] = ['title' => $t, 'page' => $b . $pg, 'icon' => $i];
        }
        $menu['options']['contrato']['links'] = ['search' => $b . 'contrato.php'] + (PluginRegularatendimentoConfig::podeGerenciar() ? ['add' => $b . 'contrato.form.php'] : []);
        return $menu;
    }

    public static function abas(string $ativa): void
    {
        $e = [PluginRegularatendimentoConfig::class, 'e'];
        echo '<ul class="nav nav-tabs regular-modulos">';
        foreach (self::paginas() as $k => [$t, $i, $pg]) {
            echo '<li class="nav-item"><a class="nav-link' . ($k === $ativa ? ' active' : '') . '" href="' . $e(PluginRegularatendimentoConfig::url($pg)) . '"><i class="' . $i . '"></i> ' . $e($t) . '</a></li>';
        }
        echo '</ul>';
    }
}
