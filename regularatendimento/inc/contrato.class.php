<?php

/**
 * Plugin Regular Atendimento - contrato de horas de uma entidade (item nativo com formulário, busca e histórico)
 */
class PluginRegularatendimentoContrato extends CommonDBTM
{
    // $rightname e $dohistory não são redeclaradas (tipadas no GLPI 12); o histórico é ligado no construtor.

    public const TABELA = 'glpi_plugin_regularatendimento_contratos';

    public function __construct()
    {
        parent::__construct();
        $this->dohistory = true;
    }

    public static function getTypeName($nb = 0): string
    {
        return $nb > 1 ? 'Contratos de horas' : 'Contrato de horas';
    }

    public static function getTable($classname = null)
    {
        return self::TABELA;
    }

    public static function getIcon()
    {
        return 'ti ti-file-certificate';
    }

    public static function canView(): bool
    {
        return PluginRegularatendimentoConfig::podeVer();
    }

    public static function canCreate(): bool
    {
        return PluginRegularatendimentoConfig::podeGerenciar();
    }

    public static function canUpdate(): bool
    {
        return PluginRegularatendimentoConfig::podeGerenciar();
    }

    public static function canDelete(): bool
    {
        return PluginRegularatendimentoConfig::podeGerenciar();
    }

    public static function canPurge(): bool
    {
        return PluginRegularatendimentoConfig::podeGerenciar();
    }

    public static function arredondamentos(): array
    {
        return [0 => 'Tempo exato (minuto a minuto)', 15 => 'Para cima, de 15 em 15 min', 30 => 'Para cima, de 30 em 30 min', 60 => 'Para cima, hora cheia'];
    }

    public static function obter(int $id): ?array
    {
        global $DB;
        return $DB->request(['FROM' => self::TABELA, 'WHERE' => ['id' => $id], 'LIMIT' => 1])->current() ?: null;
    }

    /** Contrato ativo que vale para a entidade: o dela ou o de uma entidade acima que inclua as filhas */
    public static function paraEntidade(int $entidade): ?array
    {
        global $DB;
        $proprio = $DB->request(['FROM' => self::TABELA, 'WHERE' => ['entities_id' => $entidade, 'is_active' => 1], 'LIMIT' => 1])->current();
        if ($proprio) {
            return $proprio;
        }
        $acima = array_map('intval', array_reverse(array_values(getAncestorsOf('glpi_entities', $entidade))));
        foreach ($acima as $ent) {
            $c = $DB->request(['FROM' => self::TABELA, 'WHERE' => ['entities_id' => $ent, 'is_active' => 1, 'incluir_filhas' => 1], 'LIMIT' => 1])->current();
            if ($c) {
                return $c;
            }
        }
        return null;
    }

    public static function nome(array $c): string
    {
        return trim((string) $c['name']) !== '' ? (string) $c['name'] : PluginRegularatendimentoConfig::nomeEntidade((int) $c['entities_id']);
    }

    // ------------------------------------------------------------------ gravação

    private function normalizar(array $input)
    {
        global $DB;
        $C = PluginRegularatendimentoConfig::class;
        if (array_key_exists('franquia', $input)) {
            $input['minutos_franquia'] = max(0, $C::lerMinutos($input['franquia']));
        }
        foreach (['valor_hora', 'valor_hora_extra'] as $c) {
            if (array_key_exists($c, $input)) {
                $input[$c] = $C::lerValor($input[$c]);
            }
        }
        if (array_key_exists('minimo_minutos', $input)) {
            $input['minimo_minutos'] = max(0, min(600, (int) $input['minimo_minutos']));
        }
        if (array_key_exists('arredondamento', $input) && !array_key_exists((int) $input['arredondamento'], self::arredondamentos())) {
            $input['arredondamento'] = 0;
        }
        if (array_key_exists('dia_fechamento', $input)) {
            $input['dia_fechamento'] = max(1, min(28, (int) $input['dia_fechamento']));
        }
        $ent = (int) ($input['entities_id'] ?? $this->fields['entities_id'] ?? -1);
        if (isset($input['entities_id'])) {
            $dup = $DB->request(['FROM' => self::TABELA, 'WHERE' => ['entities_id' => $ent, 'NOT' => ['id' => (int) ($this->fields['id'] ?? 0)]], 'LIMIT' => 1])->current();
            if ($dup) {
                Session::addMessageAfterRedirect('Já existe um contrato para a entidade "' . $C::nomeEntidade($ent) . '". Edite o existente.', false, ERROR);
                return false;
            }
        }
        return $input;
    }

    public function prepareInputForAdd($input)
    {
        if (!isset($input['entities_id'])) {
            $input['entities_id'] = (int) ($_SESSION['glpiactive_entity'] ?? 0);
        }
        return $this->normalizar($input);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->normalizar($input);
    }

    public function post_addItem()
    {
        PluginRegularatendimentoCiclo::abrir($this->fields);
    }

    public function post_updateItem($history = true)
    {
        // A franquia nova vale já para o ciclo aberto
        if (in_array('minutos_franquia', $this->updates, true) || in_array('is_active', $this->updates, true)) {
            $ciclo = PluginRegularatendimentoCiclo::aberto((int) $this->getID());
            if ($ciclo) {
                global $DB;
                $DB->update(PluginRegularatendimentoCiclo::TABELA, ['minutos_franquia' => (int) $this->fields['minutos_franquia'] + (int) $ciclo['minutos_transportados']], ['id' => $ciclo['id']]);
            } elseif ((int) $this->fields['is_active']) {
                PluginRegularatendimentoCiclo::abrir($this->fields);
            }
        }
    }

    public function cleanDBonPurge()
    {
        global $DB;
        $DB->delete(PluginRegularatendimentoCiclo::TABELA, ['plugin_regularatendimento_contratos_id' => (int) $this->getID()]);
        $DB->delete(PluginRegularatendimentoLancamento::TABELA, ['plugin_regularatendimento_contratos_id' => (int) $this->getID()]);
    }

    // ------------------------------------------------------------------ abas e formulário

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs);
        if (!$this->isNewItem()) {
            $this->addStandardTab('PluginRegularatendimentoCiclo', $tabs, $options);
            $this->addStandardTab('PluginRegularatendimentoLancamento', $tabs, $options);
            $this->addStandardTab('Log', $tabs, $options);
        }
        return $tabs;
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        $C = PluginRegularatendimentoConfig::class;
        $e = [$C, 'e'];
        $novo = $this->isNewItem();
        $f = $this->fields;
        if ($novo) {
            $f['minutos_franquia'] = $C::lerMinutos($C::getConfig('padrao_franquia'));
            $f['valor_hora'] = (float) $C::getConfig('padrao_valor_hora');
            $f['valor_hora_extra'] = (float) $C::getConfig('padrao_valor_extra');
            $f['minimo_minutos'] = (int) $C::getConfig('padrao_minimo');
            $f['arredondamento'] = (int) $C::getConfig('padrao_arredondamento');
            $f['dia_fechamento'] = 1;
            $f['is_active'] = 1;
        }
        $moeda = static fn($v) => (float) $v > 0 ? number_format((float) $v, 2, ',', '.') : '';
        echo $C::scriptEntidades();
        $this->showFormHeader($options);

        echo '<tr class="tab_bg_1"><td>Entidade (cliente) <span class="required">*</span></td><td>';
        Entity::dropdown(['name' => 'entities_id', 'value' => (int) ($f['entities_id'] ?? $_SESSION['glpiactive_entity'] ?? 0), 'entity' => $_SESSION['glpiactiveentities'] ?? [], 'width' => '100%']);
        echo '</td><td>Nome do contrato</td><td><input type="text" class="form-control" name="name" maxlength="255" value="' . $e($f['name'] ?? '') . '" placeholder="Vazio usa o nome da entidade"></td></tr>';

        echo '<tr class="tab_bg_1"><td>Franquia mensal de horas</td><td><input type="text" class="form-control" name="franquia" value="' . $e($C::minutosParaCampo((int) ($f['minutos_franquia'] ?? 0))) . '" placeholder="Ex.: 20 ou 20:30">';
        echo '<div class="regular-ajuda">Horas incluídas no mês. O que passar disso é cobrado como hora extra. 0 = tudo é avulso pela hora normal.</div></td>';
        echo '<td>Ativo</td><td>';
        Dropdown::showYesNo('is_active', (int) ($f['is_active'] ?? 1));
        echo '</td></tr>';

        echo '<tr class="tab_bg_1"><td>Valor da hora normal (R$)</td><td><input type="text" inputmode="decimal" class="form-control" data-regular-moeda name="valor_hora" value="' . $e($moeda($f['valor_hora'] ?? 0)) . '" placeholder="0,00"></td>';
        echo '<td>Valor da hora extra (R$)</td><td><input type="text" inputmode="decimal" class="form-control" data-regular-moeda name="valor_hora_extra" value="' . $e($moeda($f['valor_hora_extra'] ?? 0)) . '" placeholder="0,00"></td></tr>';

        echo '<tr class="tab_bg_1"><td>Tempo mínimo por chamado (min)</td><td><input type="number" min="0" max="600" class="form-control" name="minimo_minutos" value="' . (int) ($f['minimo_minutos'] ?? 30) . '"><div class="regular-ajuda">Chamados mais curtos são cobrados por este tempo.</div></td>';
        echo '<td>Arredondamento</td><td>';
        Dropdown::showFromArray('arredondamento', self::arredondamentos(), ['value' => (int) ($f['arredondamento'] ?? 0), 'width' => '100%']);
        echo '</td></tr>';

        echo '<tr class="tab_bg_1"><td>Horário normal (calendário)</td><td>';
        Calendar::dropdown(['name' => 'calendars_id', 'value' => (int) ($f['calendars_id'] ?? 0), 'width' => '100%']);
        echo '<div class="regular-ajuda">Com um calendário escolhido, o tempo trabalhado fora dele conta como hora extra mesmo dentro da franquia.</div></td>';
        echo '<td>Dia de fechamento do ciclo</td><td><input type="number" min="1" max="28" class="form-control" name="dia_fechamento" value="' . (int) ($f['dia_fechamento'] ?? 1) . '"><div class="regular-ajuda">Dia do mês em que o ciclo fecha e a franquia é renovada.</div></td></tr>';

        echo '<tr class="tab_bg_1"><td>Acumular saldo não usado</td><td>';
        Dropdown::showYesNo('acumular_saldo', (int) ($f['acumular_saldo'] ?? 0));
        echo '<div class="regular-ajuda">Sim: as horas que sobrarem passam para o próximo ciclo.</div></td><td>Incluir entidades filhas</td><td>';
        Dropdown::showYesNo('incluir_filhas', (int) ($f['incluir_filhas'] ?? 0));
        echo '<div class="regular-ajuda">Chamados das filhas (sem contrato próprio) usam este contrato.</div></td></tr>';

        echo '<tr class="tab_bg_1"><td>Observações</td><td colspan="3">';
        Html::textarea(['name' => 'comment', 'value' => $f['comment'] ?? '', 'enable_richtext' => true, 'cols' => 100, 'rows' => 4]);
        echo '</td></tr>';

        if (!$novo) {
            $ciclo = PluginRegularatendimentoCiclo::aberto((int) $ID);
            if ($ciclo) {
                echo '<tr class="tab_bg_1"><td>Ciclo atual</td><td colspan="3">' . PluginRegularatendimentoCiclo::resumoHtml($ciclo, $f) . '</td></tr>';
            }
        }
        $this->showFormButtons($options);
        return true;
    }

    public function rawSearchOptions()
    {
        $t = self::TABELA;
        return [
            ['id' => 'common', 'name' => self::getTypeName(1)],
            ['id' => 1, 'table' => $t, 'field' => 'name', 'name' => __('Name'), 'datatype' => 'itemlink', 'massiveaction' => false],
            ['id' => 2, 'table' => $t, 'field' => 'id', 'name' => 'ID', 'datatype' => 'number', 'massiveaction' => false],
            ['id' => 3, 'table' => $t, 'field' => 'minutos_franquia', 'name' => 'Franquia (min)', 'datatype' => 'number'],
            ['id' => 4, 'table' => $t, 'field' => 'valor_hora', 'name' => 'Valor da hora', 'datatype' => 'decimal'],
            ['id' => 5, 'table' => $t, 'field' => 'valor_hora_extra', 'name' => 'Valor da hora extra', 'datatype' => 'decimal'],
            ['id' => 6, 'table' => $t, 'field' => 'is_active', 'name' => __('Active'), 'datatype' => 'bool'],
            ['id' => 7, 'table' => $t, 'field' => 'minimo_minutos', 'name' => 'Mínimo (min)', 'datatype' => 'number'],
            ['id' => 8, 'table' => 'glpi_calendars', 'field' => 'name', 'linkfield' => 'calendars_id', 'name' => 'Calendário', 'datatype' => 'dropdown'],
            ['id' => 9, 'table' => $t, 'field' => 'dia_fechamento', 'name' => 'Dia de fechamento', 'datatype' => 'number'],
            ['id' => 10, 'table' => $t, 'field' => 'date_mod', 'name' => __('Last update'), 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 80, 'table' => 'glpi_entities', 'field' => 'completename', 'name' => 'Entidade', 'datatype' => 'dropdown', 'massiveaction' => false],
        ];
    }
}
