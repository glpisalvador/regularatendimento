<?php

/**
 * Plugin Regular Atendimento - configurações (chave/valor), direito nativo e utilitários
 */
class PluginRegularatendimentoConfig extends CommonDBTM
{
    // $rightname não é redeclarada: é tipada (string) no GLPI 12 e sem tipo no 11.

    public const TABELA = 'glpi_plugin_regularatendimento_configs';
    public const DIREITO = 'plugin_regularatendimento';

    public static function getTypeName($nb = 0): string
    {
        return 'Regular Atendimento';
    }

    public static function getTable($classname = null)
    {
        return self::TABELA;
    }

    public static function canView(): bool
    {
        return self::ehAdmin();
    }

    public static function canCreate(): bool
    {
        return self::ehAdmin();
    }

    public static function canUpdate(): bool
    {
        return self::ehAdmin();
    }

    public static function canDelete(): bool
    {
        return self::ehAdmin();
    }

    public static function canPurge(): bool
    {
        return self::ehAdmin();
    }

    public static function ehAdmin(): bool
    {
        return (bool) Session::getLoginUserID() && Session::haveRight('config', UPDATE);
    }

    /** Ver painel, contratos, extratos e relatórios */
    public static function podeVer(): bool
    {
        return (bool) Session::getLoginUserID() && (self::ehAdmin() || Session::haveRight(self::DIREITO, READ));
    }

    /** Gerenciar contratos, lançamentos, ajustes de tempo e atendimentos retroativos */
    public static function podeGerenciar(): bool
    {
        return (bool) Session::getLoginUserID() && (self::ehAdmin() || Session::haveRight(self::DIREITO, UPDATE));
    }

    // =====================================================================
    // Chave/valor
    // =====================================================================

    public static function padroes(): array
    {
        return [
            'auto_iniciar'        => '1',
            'mostrar_sem_contrato' => '1',
            'mostrar_solicitante' => '0',
            'padrao_valor_hora'   => '0',
            'padrao_valor_extra'  => '0',
            'padrao_minimo'       => '30',
            'padrao_arredondamento' => '0',
            'padrao_franquia'     => '0',
        ];
    }

    private static ?array $raCache = null;

    public static function getConfig(string $name, $default = null)
    {
        global $DB;
        if (self::$raCache === null) {
            self::$raCache = [];
            if ($DB->tableExists(self::TABELA)) {
                foreach ($DB->request(['SELECT' => ['name', 'value'], 'FROM' => self::TABELA]) as $row) {
                    self::$raCache[$row['name']] = $row['value'];
                }
            }
        }
        if (array_key_exists($name, self::$raCache)) {
            return self::$raCache[$name];
        }
        return $default ?? (self::padroes()[$name] ?? null);
    }

    public static function setConfig(string $name, $value): bool
    {
        global $DB;
        if (count($DB->request(['FROM' => self::TABELA, 'WHERE' => ['name' => $name], 'LIMIT' => 1])) > 0) {
            $ok = (bool) $DB->update(self::TABELA, ['value' => $value], ['name' => $name]);
        } else {
            $ok = (bool) $DB->insert(self::TABELA, ['name' => $name, 'value' => $value]);
        }
        self::$raCache = null;
        return $ok;
    }

    public static function getAllConfigs(): array
    {
        global $DB;
        $todas = [];
        foreach ($DB->request(['FROM' => self::TABELA]) as $row) {
            $todas[$row['name']] = $row['value'];
        }
        return $todas;
    }

    public static function getArrayConfig(string $name): array
    {
        $lista = json_decode((string) self::getConfig($name), true);
        return is_array($lista) ? $lista : [];
    }

    public static function setArrayConfig(string $name, array $value): bool
    {
        return self::setConfig($name, json_encode(array_values($value)));
    }

    public static function ligado(string $name): bool
    {
        return (bool) (int) self::getConfig($name);
    }

    // =====================================================================
    // Utilitários
    // =====================================================================

    public static function e($texto): string
    {
        return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
    }

    public static function url(string $arquivo, array $params = []): string
    {
        global $CFG_GLPI;
        return $CFG_GLPI['root_doc'] . '/plugins/regularatendimento/front/' . $arquivo . ($params ? '?' . http_build_query($params) : '');
    }

    public static function tokenCsrf(): string
    {
        return (version_compare(GLPI_VERSION, '12.0.0-dev', '<') && session_status() === PHP_SESSION_ACTIVE) ? Session::getNewCSRFToken() : '';
    }

    public static function reais(float $v): string
    {
        return ($v < 0 ? '- ' : '') . 'R$ ' . number_format(abs($v), 2, ',', '.');
    }

    public static function lerValor($texto): float
    {
        $t = trim((string) $texto);
        if (str_contains($t, ',')) {
            $t = str_replace(['.', ','], ['', '.'], $t);
        }
        return max(0, round((float) preg_replace('/[^0-9.]/', '', $t), 2));
    }

    /** "1:30", "1,5", "90min" ou "2" (horas) em minutos */
    public static function lerMinutos($texto): int
    {
        $t = trim(mb_strtolower((string) $texto));
        if ($t === '') {
            return 0;
        }
        if (preg_match('/^(\d+):(\d{1,2})$/', $t, $m)) {
            return (int) $m[1] * 60 + (int) $m[2];
        }
        if (preg_match('/^(\d+)\s*h\s*(\d{1,2})?\s*(min)?$/', $t, $m)) {
            return (int) $m[1] * 60 + (int) ($m[2] ?? 0);
        }
        if (preg_match('/^(\d+)\s*min$/', $t, $m)) {
            return (int) $m[1];
        }
        return (int) round((float) str_replace(',', '.', $t) * 60);
    }

    /** Minutos em "12h05" */
    public static function horas(int $minutos): string
    {
        $sinal = $minutos < 0 ? '-' : '';
        $minutos = abs($minutos);
        return $sinal . intdiv($minutos, 60) . 'h' . str_pad((string) ($minutos % 60), 2, '0', STR_PAD_LEFT);
    }

    /** Segundos em "01:02:03" */
    public static function relogio(int $segundos): string
    {
        $segundos = max(0, $segundos);
        return sprintf('%02d:%02d:%02d', intdiv($segundos, 3600), intdiv($segundos % 3600, 60), $segundos % 60);
    }

    public static function minutosParaCampo(int $minutos): string
    {
        return $minutos > 0 ? intdiv($minutos, 60) . ':' . str_pad((string) ($minutos % 60), 2, '0', STR_PAD_LEFT) : '';
    }

    public static function entidadesFilhas(): array
    {
        global $DB;
        return array_map('intval', array_column(iterator_to_array($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_entities', 'WHERE' => ['entities_id' => ['>', 0]]]), false), 'id'));
    }

    public static function scriptEntidades(): string
    {
        return '<script>window.regularEntidadesOcultas = ' . json_encode(self::entidadesFilhas()) . ';</script>';
    }

    public static function nomeEntidade(int $id): string
    {
        return html_entity_decode((string) Dropdown::getDropdownName('glpi_entities', $id), ENT_QUOTES, 'UTF-8');
    }
}
