<?php

/**
 * Plugin Regular Atendimento - relógio no formulário do chamado e aba "Tempo de atendimento"
 */
class PluginRegularatendimentoChamado extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return 'Tempo de atendimento';
    }

    /** Quem vê o relógio: interface padrão; solicitantes só se a configuração permitir */
    public static function podeVerRelogio(Ticket $t): bool
    {
        if (!Session::getLoginUserID() || !$t->canViewItem()) {
            return false;
        }
        if ((Session::getCurrentInterface() ?: 'central') !== 'central' && !PluginRegularatendimentoConfig::ligado('mostrar_solicitante')) {
            return false;
        }
        return true;
    }

    public static function podeControlar(Ticket $t): bool
    {
        return (Session::getCurrentInterface() ?: 'central') === 'central' && $t->canUpdateItem();
    }

    /** Dados do relógio para a tela e para o AJAX */
    public static function dados(int $ticket): ?array
    {
        $r = PluginRegularatendimentoRelogio::garantir($ticket);
        if (!$r) {
            return null;
        }
        $C = PluginRegularatendimentoConfig::class;
        $est = PluginRegularatendimentoRelogio::estimativa($r);
        $contrato = $est['contrato'];
        $ciclo = $contrato ? PluginRegularatendimentoCiclo::aberto((int) $contrato['id']) : null;
        $estados = PluginRegularatendimentoRelogio::estados();
        return [
            'ticket'     => $ticket,
            'estado'     => $r['estado'],
            'rotulo'     => $estados[$r['estado']][0] ?? $r['estado'],
            'segundos'   => PluginRegularatendimentoRelogio::segundosAgora($r),
            'rodando'    => $r['estado'] === 'rodando',
            'contrato'   => $contrato ? PluginRegularatendimentoContrato::nome($contrato) : '',
            'cobrado'    => $C::horas((int) $est['minutos']),
            'normais'    => $C::horas((int) $est['normais']),
            'extras'     => (int) $est['extras'] > 0 ? $C::horas((int) $est['extras']) : '',
            'valor'      => $contrato ? $C::reais((float) $est['valor']) : '',
            'saldo'      => $ciclo && (int) $ciclo['minutos_franquia'] > 0 ? $C::horas(PluginRegularatendimentoCiclo::saldo($ciclo)) : '',
            'ajustado'   => (bool) (int) $r['is_ajustado'],
        ];
    }

    /** Hook pre_item_form: cartão do relógio no topo do formulário do chamado */
    public static function cartao(array $params): void
    {
        $t = $params['item'] ?? null;
        if (!$t instanceof Ticket || $t->isNewItem() || !self::podeVerRelogio($t)) {
            return;
        }
        $contrato = PluginRegularatendimentoContrato::paraEntidade((int) $t->fields['entities_id']);
        if (!$contrato && !PluginRegularatendimentoConfig::ligado('mostrar_sem_contrato')) {
            return;
        }
        $d = self::dados((int) $t->getID());
        if (!$d) {
            return;
        }
        echo self::htmlCartao($d, self::podeControlar($t));
    }

    public static function htmlCartao(array $d, bool $controlar): string
    {
        $C = PluginRegularatendimentoConfig::class;
        $e = [$C, 'e'];
        $est = PluginRegularatendimentoRelogio::estados()[$d['estado']] ?? ['', 'neutro', 'ti ti-clock'];
        $h = '<div class="regular regular-cartao" data-regular-cartao="' . (int) $d['ticket'] . '" data-estado="' . $e($d['estado']) . '">';
        $h .= '<div class="regular-cartao-tempo"><i class="ti ti-clock-hour-4"></i><span class="regular-digitos" data-regular-segundos="' . (int) $d['segundos'] . '" data-regular-rodando="' . ($d['rodando'] ? 1 : 0) . '">' . $e($C::relogio((int) $d['segundos'])) . '</span>';
        $h .= '<span class="regular-pill regular-pill-' . $est[1] . '"><i class="' . $est[2] . '"></i> ' . $e($d['rotulo']) . '</span></div>';
        $h .= '<div class="regular-cartao-info">';
        if ($d['contrato'] !== '') {
            $h .= '<span><i class="ti ti-file-certificate"></i> ' . $e($d['contrato']) . '</span>';
            $h .= '<span>' . ($d['estado'] === 'finalizado' ? 'Cobrado' : 'Previsto') . ': <b>' . $e($d['cobrado']) . '</b>' . ($d['extras'] !== '' ? ' (' . $e($d['extras']) . ' extra)' : '') . ' · <b>' . $e($d['valor']) . '</b>' . ($d['ajustado'] ? ' <span class="regular-ajuda">ajustado</span>' : '') . '</span>';
            if ($d['saldo'] !== '') {
                $h .= '<span>Saldo do contrato: <b>' . $e($d['saldo']) . '</b></span>';
            }
        } else {
            $h .= '<span class="text-muted"><i class="ti ti-info-circle"></i> Entidade sem contrato de horas: o tempo é contado, mas não é cobrado.</span>';
        }
        $h .= '</div><div class="regular-cartao-acoes">';
        if ($controlar) {
            if ($d['estado'] === 'parado') {
                $h .= '<button type="button" class="btn btn-sm regular-btn-primario" data-regular-acao="iniciar"><i class="ti ti-player-play"></i> Iniciar</button>';
            } elseif ($d['estado'] === 'pausado') {
                $h .= '<button type="button" class="btn btn-sm regular-btn-primario" data-regular-acao="iniciar"><i class="ti ti-player-play"></i> Retomar</button>';
            } elseif ($d['estado'] === 'rodando') {
                $h .= '<span class="regular-pausa" hidden><input type="text" class="form-control form-control-sm" maxlength="255" placeholder="Motivo da pausa" data-regular-motivo><button type="button" class="btn btn-sm regular-btn-primario" data-regular-acao="pausar_confirmar">Pausar</button><button type="button" class="btn btn-sm btn-ghost-secondary" data-regular-acao="pausar_cancelar" title="Cancelar"><i class="ti ti-x"></i></button></span>';
                $h .= '<button type="button" class="btn btn-sm btn-outline-secondary" data-regular-acao="pausar"><i class="ti ti-player-pause"></i> Pausar</button>';
            }
        }
        $h .= '<a class="btn btn-sm btn-ghost-secondary" href="' . $e(Ticket::getFormURLWithID((int) $d['ticket']) . '&forcetab=PluginRegularatendimentoChamado$1') . '" title="Períodos, pausas e cobrança"><i class="ti ti-list-details"></i></a>';
        $h .= '</div></div>';
        return $h;
    }

    // ------------------------------------------------------------------ aba no chamado

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item instanceof Ticket && !$item->isNewItem() && (Session::getCurrentInterface() ?: 'central') === 'central') {
            return self::createTabEntry('Tempo de atendimento', 0, null, 'ti ti-clock-hour-4');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!$item instanceof Ticket) {
            return true;
        }
        $C = PluginRegularatendimentoConfig::class;
        $e = [$C, 'e'];
        $id = (int) $item->getID();
        $d = self::dados($id);
        $r = PluginRegularatendimentoRelogio::obter($id);
        echo '<div class="regular regular-aba">';
        echo self::htmlCartao($d, self::podeControlar($item));

        $periodos = PluginRegularatendimentoRelogio::periodos((int) $r['id']);
        $origem = ['automatico' => 'Automático', 'manual' => 'Manual', 'retroativo' => 'Lançado depois'];
        echo '<table class="tab_cadre_fixehov regular-tabela"><tr class="noHover"><th colspan="7"><i class="ti ti-clock-play"></i> Períodos trabalhados (' . count($periodos) . ')</th></tr>';
        if (!$periodos) {
            echo '<tr class="tab_bg_1"><td colspan="7" class="center regular-vazio">O relógio ainda não rodou neste chamado.</td></tr>';
        } else {
            echo '<tr class="tab_bg_2"><th>Início</th><th>Fim</th><th>Duração</th><th>Fora do horário</th><th>Iniciado</th><th>Encerrado</th><th>Motivo do encerramento</th></tr>';
            foreach ($periodos as $p) {
                $aberto = empty($p['fim']);
                $seg = $aberto ? max(0, time() - strtotime((string) $p['inicio'])) : (int) $p['segundos'];
                echo '<tr class="tab_bg_1"><td class="text-nowrap">' . $e(Html::convDateTime($p['inicio'])) . '</td><td class="text-nowrap">' . ($aberto ? '<span class="regular-pill regular-pill-andamento">rodando</span>' : $e(Html::convDateTime($p['fim']))) . '</td>';
                echo '<td>' . $e($C::relogio($seg)) . '</td><td>' . ((int) $p['segundos_fora'] > 0 ? $e($C::relogio((int) $p['segundos_fora'])) : '—') . '</td>';
                echo '<td>' . $e(($origem[$p['origem_inicio']] ?? $p['origem_inicio']) . ((int) $p['users_id_inicio'] && $p['origem_inicio'] !== 'automatico' ? ' · ' . getUserName((int) $p['users_id_inicio']) : '')) . '</td>';
                echo '<td>' . ($aberto ? '' : $e(($origem[$p['origem_fim']] ?? $p['origem_fim']) . ((int) $p['users_id_fim'] && $p['origem_fim'] === 'manual' ? ' · ' . getUserName((int) $p['users_id_fim']) : ''))) . '</td>';
                echo '<td>' . $e($p['motivo_fim']) . '</td></tr>';
            }
            echo '<tr class="tab_bg_2"><td colspan="2"><strong>Total</strong></td><td><strong>' . $e($C::relogio((int) $d['segundos'])) . '</strong></td><td>' . ((int) $r['segundos_fora'] > 0 ? $e($C::relogio((int) $r['segundos_fora'])) : '—') . '</td><td colspan="3"></td></tr>';
        }
        echo '</table>';

        $lancs = PluginRegularatendimentoLancamento::listar(PluginRegularatendimentoLancamento::filtros(['ticket' => $id]), 50);
        echo '<div class="card regular-card"><div class="card-header regular-card-header"><h5><i class="ti ti-cash"></i> Cobrança deste chamado</h5></div><div class="card-body p-0">';
        PluginRegularatendimentoLancamento::tabela($lancs);
        echo '</div></div>';

        if ($r['estado'] === 'finalizado' && $C::podeGerenciar() && PluginRegularatendimentoRelogio::contratoDo($r)) {
            echo '<form class="regular-ajuste" data-regular-ajuste="' . $id . '"><table class="tab_cadre_fixe"><tr class="tab_bg_2"><th colspan="4"><i class="ti ti-adjustments"></i> Ajustar o tempo cobrado</th></tr>';
            echo '<tr class="tab_bg_1"><td class="regular-rotulo">Tempo cobrado</td><td><input type="text" class="form-control" name="minutos" value="' . $e($C::minutosParaCampo((int) $r['minutos_cobrados'])) . '" placeholder="Ex.: 1:30" required></td>';
            echo '<td class="regular-rotulo">Motivo</td><td><input type="text" class="form-control" name="motivo" maxlength="255" required></td></tr>';
            echo '<tr class="tab_bg_2"><td colspan="4" class="center"><button type="submit" class="btn btn-primary regular-btn-salvar"><i class="ti ti-device-floppy"></i> Ajustar e refazer a cobrança</button></td></tr></table></form>';
        }
        echo '</div>';
        return true;
    }
}
