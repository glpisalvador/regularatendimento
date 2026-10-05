<?php

/**
 * Plugin Regular Atendimento - aba no perfil (direito nativo plugin_regularatendimento)
 */
class PluginRegularatendimentoProfile extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return 'Regular Atendimento';
    }

    public static function getAllRights(): array
    {
        return [[
            'itemtype' => 'PluginRegularatendimentoContrato',
            'label'    => 'Gestão de tempos',
            'field'    => PluginRegularatendimentoConfig::DIREITO,
            'rights'   => [READ => 'Ver painel e extratos', UPDATE => 'Gerenciar contratos, lançamentos e ajustes'],
        ]];
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item instanceof Profile && !$item->isNewItem()) {
            return self::createTabEntry('Regular Atendimento', 0, null, 'ti ti-clock-dollar');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!$item instanceof Profile) {
            return true;
        }
        $perfil = new Profile();
        $perfil->getFromDB($item->getID());
        $pode = Session::haveRight('profile', UPDATE);
        echo '<div class="spaced">';
        if ($pode) {
            echo '<form method="post" action="' . PluginRegularatendimentoConfig::e(Profile::getFormURL()) . '">';
        }
        $perfil->displayRightsChoiceMatrix(self::getAllRights(), ['canedit' => $pode, 'default_class' => 'tab_bg_2', 'title' => 'Regular Atendimento']);
        if ($pode) {
            echo '<div class="center">' . Html::hidden('id', ['value' => $item->getID()])
                . Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']) . '</div>';
            Html::closeForm();
        }
        echo '<p class="text-muted" style="font-size:12px;"><i class="ti ti-info-circle"></i> O relógio aparece no chamado para todos da interface padrão; quem pode alterar o chamado pode iniciar, pausar e retomar. Estes direitos liberam a gestão de tempos (painel, contratos, extratos, lançamentos e ajustes).</p>';
        echo '</div>';
        return true;
    }
}
