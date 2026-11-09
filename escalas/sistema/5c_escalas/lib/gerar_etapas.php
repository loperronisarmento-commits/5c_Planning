<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/gerar_etapas.php
// Gerar em etapas (pedido de 05/11): antes de gerar ou recalcular, o sistema
//   1. verifica o que impede a escala de sair certa (pendências), e leva à tela que resolve;
//   2. recalcula o Capacity com os parâmetros de hoje e sincroniza os postos (sempre, sem a trava
//      de 30 minutos), desativa as restrições de quem saiu e guarda a versão das Restrições dos OM;
//   3. gera a escala.
// A tela Gerar chama uma etapa por vez (pages/gerar.php, campo "etapa", resposta JSON) e mostra o
// progresso numa sobreposição que bloqueia o resto da tela (assets/escalas.js). Sem JavaScript, o
// mesmo POST roda as três em sequência.
// Cada etapa é uma requisição: com a conexão caída no meio (ex.: erro 1160), repetir a etapa abre
// uma conexão nova.
// ===============================================

const ESC_GERAR_ACOES = ['gerar', 'recalcular', 'recalcular_minimo'];
const ESC_GERAR_TRAVA = '5c_escalas.gerar';

function escGerarTravaNome(): string {
    return escModoTreino() ? ESC_SCHEMA_TREINO . '.gerar' : ESC_GERAR_TRAVA;
}

// Pendências antes de gerar a semana $seg. 'bloqueios' impedem gerar; 'avisos' deixam continuar.
// Cada item: msg, tela, params (do link) e rotulo (do botão "Resolver em …").
function escGerarPendencias(string $seg): array {
    $out = ['bloqueios' => [], 'avisos' => []];
    // Outra geração rodando agora (a trava é da conexão de quem gera; some quando ela termina).
    $livre = escQ1('SELECT IS_FREE_LOCK(?) AS livre', [escGerarTravaNome()])['livre'] ?? 1;
    if ((string) $livre === '0') {
        $out['bloqueios'][] = ['msg' => t('Outra pessoa está gerando ou recalculando uma escala agora. Espere terminar (até 3 min) e tente de novo.'), 'tela' => null, 'params' => [], 'rotulo' => ''];
        return $out;
    }
    // Saída de página "a conferir" (detectada pelo Capacity, sem decisão) de página que ainda está
    // na estrutura: sem confirmar, a escala sai com a página (ou sem os postos dela, se o site já a
    // desativou). Só os últimos 45 dias, o que a tela Saídas mostra.
    $saidas = escQ("SELECT e.id, e.pagina, DATE(e.data_evento) AS dia
                      FROM `5c_planning`.`fc_pagina_eventos` e
                      LEFT JOIN " . escT('esc_saida_checagem') . " c ON c.evento_id = e.id
                     WHERE e.tipo = 'SAIDA' AND IFNULL(e.status, '') <> 'CANCELADA'
                       AND e.data_evento >= CURDATE() - INTERVAL 45 DAY
                       AND IFNULL(c.decisao, 'PENDENTE') = 'PENDENTE'
                       AND EXISTS (SELECT 1 FROM `5c_planning`.`fc_pagina_carteira` pc WHERE pc.pagina = e.pagina AND pc.vigente_ate IS NULL)
                     ORDER BY e.data_evento, e.pagina");
    if ($saidas) {
        $maisAntiga = (int) floor((time() - strtotime((string) $saidas[0]['dia'])) / 86400);
        $dias = 45;
        foreach ([7, 14, 21, 45] as $d) {
            if ($maisAntiga <= $d) {
                $dias = $d;
                break;
            }
        }
        $out['bloqueios'][] = [
            'msg' => tn(count($saidas), 'Falta conferir a saída de {lista}: confirme (e aplique) ou marque "não saiu" antes de gerar.',
                'Falta conferir a saída de {lista}: confirme (e aplique) ou marque "não saiu" antes de gerar.',
                ['lista' => implode(', ', array_map(static fn($s) => $s['pagina'] . ' (' . dataBr((string) $s['dia']) . ')', $saidas))]),
            'tela' => 'saidas', 'params' => ['dias' => $dias], 'rotulo' => t('Saídas de página'),
        ];
    }
    // Indisponibilidade ainda sem decisão na semana: dá para gerar, mas o pedido não entra.
    $fim = escMaisDias($seg, 6);
    $ind = escQ1("SELECT COUNT(*) AS dias, COUNT(DISTINCT d.indisponibilidade_id) AS pedidos FROM " . escT('esc_indisponibilidade_data') . " d
                   WHERE d.status = 'PENDENTE' AND d.dia BETWEEN ? AND ?", [$seg, $fim]);
    if ((int) ($ind['pedidos'] ?? 0) > 0) {
        $out['avisos'][] = [
            'msg' => tn((int) $ind['pedidos'], '{n} pedido de indisponibilidade da semana ainda sem decisão ({dias} dia(s)): se gerar agora, ele não entra.',
                '{n} pedidos de indisponibilidade da semana ainda sem decisão ({dias} dia(s)): se gerar agora, eles não entram.', ['dias' => (int) $ind['dias']]),
            'tela' => 'aprovar', 'params' => [], 'rotulo' => t('Indisponibilidade'),
        ];
    }
    return $out;
}

// Trava da geração na conexão de gravação (fica até a requisição terminar). false = outra rodando.
function escGerarTravar(): bool {
    // O treino tem trava própria: não disputa com a geração real, mas também só roda uma por vez.
    return (int) escDbW()->query("SELECT GET_LOCK('" . escGerarTravaNome() . "', 0)")->fetchColumn() === 1;
}

// Etapa 2: Capacity e estrutura de hoje. Devolve ok, msg (resumo), erro e repetir (conexão caiu).
function escGerarCapacity(string $seg): array {
    if (!escV4Ativo($seg)) {
        return ['ok' => true, 'msg' => t('Semana na estrutura antiga: o Capacity não entra.'), 'erro' => null, 'repetir' => false];
    }
    if (escModoTreino()) {
        return ['ok' => true, 'msg' => t('Modo Treino: o Capacity real não é recalculado.'), 'erro' => null, 'repetir' => false];
    }
    $r = escEstruturaAtualizar(true);
    if ($r === null) {
        // Desligado em v4_estrutura_auto: segue com a estrutura que está gravada.
        return ['ok' => true, 'msg' => t('Atualização automática do Capacity desligada (v4_estrutura_auto): vale a estrutura gravada.'), 'erro' => null, 'repetir' => false];
    }
    if ($r['erro']) {
        return ['ok' => false, 'msg' => '', 'erro' => t('Não deu para recalcular o Capacity: {erro}', ['erro' => mb_substr((string) $r['erro'], 0, 160)]),
            'repetir' => !empty($r['conexao'])];
    }
    $GLOBALS['ESC_ESTRUTURA_AUTO'] = $r;
    $msg = trim(escEstruturaAviso());
    // Restrições de quem saiu (regra de 04/11) e a versão de hoje das Restrições dos OM (ES_122).
    try {
        $orfas = escOmEncerrarOrfas(date('Y-m-d'));
        escRestricoesRegistrarVersoes();
        if ($orfas) {
            $msg .= ' ' . tn(count($orfas), '{n} restrição de quem saiu desativada.', '{n} restrições de quem saiu desativadas.');
        }
    } catch (Throwable $e) {
        appLog('error', 'Restrições: falha ao desativar as de desligados e páginas que saíram', ['erro' => $e->getMessage()]);
    }
    escSessao(static function () use ($seg, $msg) {
        $_SESSION['esc_gerar_capacity'] = ['seg' => $seg, 'msg' => $msg, 'quando' => time()];
    });
    return ['ok' => true, 'msg' => $msg, 'erro' => null, 'repetir' => false];
}

// Etapa 3: gera ou recalcula (as ações da tela Gerar). Devolve o id do plano e a mensagem da grade.
// $capacityFeito: a etapa 2 já rodou nesta geração (o motor não repete a estrutura nem as restrições).
function escGerarEscala(string $acao, string $seg, string $desde, bool $capacityFeito): array {
    if (!in_array($acao, ESC_GERAR_ACOES, true)) {
        throw new RuntimeException(t('Não deu para entender o pedido. Recarregue a tela e tente de novo.'));
    }
    if ($acao !== 'gerar' && !escPlanoAtual($seg)) {
        throw new RuntimeException(t('Não há versão desta semana para recalcular. Gere primeiro.'));
    }
    @set_time_limit(600);
    ignore_user_abort(true); // fechar a aba no meio não deixa a geração pela metade
    $resumoCap = '';
    if ($capacityFeito) {
        // Fica na sessão até a geração dar certo: uma repetição por queda de conexão ainda aproveita a etapa 2.
        $c = escSessao(static fn() => $_SESSION['esc_gerar_capacity'] ?? null);
        if (is_array($c) && ($c['seg'] ?? '') === $seg && (int) ($c['quando'] ?? 0) > time() - 1800) {
            $GLOBALS['ESC_ESTRUTURA_AUTO'] = ['erro' => null, 'paginas' => [], 'ja_feito' => true];
            $GLOBALS['ESC_OM_ORFAS'] = [];
            $resumoCap = $c['msg'] !== '' ? ' ' . $c['msg'] : '';
        }
    }
    $avisoDesde = $desde !== '' && escPlanoAtual($seg) ? ' ' . (!empty($GLOBALS['ESC_PRE_SITE'])
        ? t('Só mudou a partir de {quando}; antes disso ficou igual ao site (5C Schedule), e o CSV do dia leva os blocos de antes iguais ao site.', ['quando' => escSlotsDaSemana($seg)[$desde]])
        : t('Só mudou a partir de {quando}; antes disso ficou a versão publicada.', ['quando' => escSlotsDaSemana($seg)[$desde]])) : '';
    if ($acao === 'gerar') {
        $id = escMotorGerar($seg, 'NOVA', escUserId(), null, false, $desde);
        $msg = t('Rascunho gerado para a semana de {dia}.', ['dia' => escData($seg)]);
    } else {
        $minimo = $acao === 'recalcular_minimo';
        escW('INSERT INTO ' . escT('esc_recalculo_evento') . " (gatilho, descricao, semana_inicio, status) VALUES ('MANUAL', ?, ?, 'DETECTADO')",
            [$minimo ? 'Recálculo com o mínimo de mudanças' : 'Recálculo pedido na tela', $seg]);
        $ev = escUltimoId();
        try {
            $id = escMotorGerar($seg, 'RECALCULO', escUserId(), $ev, $minimo, $desde);
        } catch (Throwable $e) {
            escEventoErro($ev, $e);
            throw $e;
        }
        $msg = $minimo ? t('Recalculado com o mínimo de mudanças.') : t('Recalculado. Veja o De→Para no fim da tela Gerar escala.');
    }
    $msg .= $avisoDesde . ($acao === 'recalcular_minimo' ? escMinimoAviso() : '') . $resumoCap . escEstruturaAviso() . escEquipesAviso() . escCasaisAviso($seg);
    return ['plano' => $id, 'msg' => $msg];
}
