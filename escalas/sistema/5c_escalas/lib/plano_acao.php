<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/plano_acao.php
// Plano de Ação (1.38.0, item C da revisão de 24/11; a tela era "Sugestões de troca"): uma lista única
// das ações sugeridas ou pendentes do sistema, venham de onde vierem (esc_acao, ES_220). Cada origem
// registra a ação com uma chave "ref" (não repete); quando a origem deixa de apontar o problema, a ação
// pendente fecha sozinha como feita ("resolvida na origem"). Descartada não volta.
// Tipos: TROCA_PESSOA, TROCA_PAGINA, COBERTURA, CARGA, RECALCULAR_TIER, ENTRADA_PAGINA, SAIDA_PAGINA,
// ALERTA, PEDIDO, DADOS.
// ===============================================

const ESC_ACAO_TIPOS = ['TROCA_PESSOA', 'TROCA_PAGINA', 'COBERTURA', 'CARGA', 'RECALCULAR_TIER', 'ENTRADA_PAGINA', 'SAIDA_PAGINA', 'ALERTA', 'PEDIDO', 'DADOS'];
const ESC_ACAO_TIERS = ['F', 'E', 'D', 'C', 'B', 'A', 'S', 'SS'];

function escAcaoRotuloTipo(string $tipo): string {
    return [
        'TROCA_PESSOA' => t('Troca de pessoa'), 'TROCA_PAGINA' => t('Troca de página'), 'COBERTURA' => t('Cobertura de lacuna'),
        'CARGA' => t('Carga de trabalho'), 'RECALCULAR_TIER' => t('Recalcular Tier'), 'ENTRADA_PAGINA' => t('Entrada de página'),
        'SAIDA_PAGINA' => t('Saída de página'), 'ALERTA' => t('Alerta'), 'PEDIDO' => t('Pedido pendente'), 'DADOS' => t('Dado a conferir'),
    ][$tipo] ?? $tipo;
}

function escAcaoRotuloOrigem(string $origem): string {
    return [
        'gerar' => t('Gerar escala'), 'grade' => t('Grade da escala'), 'owners' => t('Owners e backups'), 'equilibrio' => t('Equilíbrio das unidades'),
        'funil' => t('Funil de entrada'), 'saidas' => t('Saídas de página'), 'movimentos' => t('Movimentações e Anjos'), 'capacity' => 'Capacity',
        'aprovar' => t('Indisponibilidade'), 'solicitacoes' => t('Solicitações'), 'horas' => t('Horas extras'), 'auditoria' => t('Auditoria'),
    ][$origem] ?? $origem;
}

// A tabela existe (ES_220)?
function escAcaoInstalada(): bool {
    static $ok = null;
    if ($ok === null) {
        try {
            $ok = (bool) escQ1('SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [ESC_SCHEMA, 'esc_acao']);
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

// Registra (ou atualiza) uma ação pela chave. O status não muda: pendente segue pendente, feita ou
// descartada fica como está ($reabrir = true volta uma FEITA para PENDENTE, quando o problema voltou).
function escAcaoRegistrar(array $a, bool $reabrir = false): void {
    if (!escAcaoInstalada()) {
        return;
    }
    $corta = static fn($v, int $n) => $v === null ? null : mb_substr((string) $v, 0, $n);
    escW('INSERT INTO ' . escT('esc_acao') . ' (ref, tipo, titulo, detalhe, envolve, origem, unidade, turno, dia, link_tela, link_params, dados, visto_em)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()) AS n
          ON DUPLICATE KEY UPDATE titulo = n.titulo, detalhe = n.detalhe, envolve = n.envolve, unidade = n.unidade, turno = n.turno, dia = n.dia,
                                  link_tela = n.link_tela, link_params = n.link_params, dados = n.dados, visto_em = NOW()'
          . ($reabrir ? ", status = IF(status = 'FEITA', 'PENDENTE', status)" : ''),
        [$corta($a['ref'], 190), $a['tipo'], $corta($a['titulo'], 255), $a['detalhe'] ?? null, $corta($a['envolve'] ?? null, 255), $a['origem'],
         isset($a['unidade']) && $a['unidade'] !== '' ? (int) $a['unidade'] : null, $a['turno'] ?? null, $a['dia'] ?? null,
         $a['link_tela'] ?? null, isset($a['link_params']) ? json_encode($a['link_params'], JSON_UNESCAPED_UNICODE) : null,
         isset($a['dados']) ? json_encode($a['dados'], JSON_UNESCAPED_UNICODE) : null]);
}

// Fecha como FEITA as ações pendentes de uma origem (prefixo da ref) que a origem não aponta mais.
function escAcaoResolverAusentes(string $prefixo, array $refsAtivas): int {
    if (!escAcaoInstalada()) {
        return 0;
    }
    $pend = escQ('SELECT id, ref FROM ' . escT('esc_acao') . " WHERE status = 'PENDENTE' AND ref LIKE ?", [$prefixo . '%']);
    $ativas = array_flip($refsAtivas);
    $n = 0;
    foreach ($pend as $p) {
        if (!isset($ativas[$p['ref']])) {
            $n += escW('UPDATE ' . escT('esc_acao') . " SET status = 'FEITA', motivo = ?, decidido_em = NOW() WHERE id = ? AND status = 'PENDENTE'",
                [t('resolvida na origem'), (int) $p['id']]);
        }
    }
    return $n;
}

// Tier que o faturamento mensal daria pelas faixas de 5c_crm.tiers (US$ por mês).
function escTierPelaReceita(float $mes): ?string {
    static $faixas = null;
    if ($faixas === null) {
        $faixas = [];
        try {
            foreach (escQ('SELECT letter, min_value, max_value FROM `5c_crm`.`tiers` ORDER BY min_value') as $r) {
                $faixas[] = [strtoupper(trim((string) $r['letter'])), (float) $r['min_value'], $r['max_value'] !== null ? (float) $r['max_value'] : null];
            }
        } catch (Throwable $e) {
            appLog('warning', 'Faixas de tier indisponíveis', ['erro' => $e->getMessage()]);
        }
    }
    $achou = null;
    foreach ($faixas as [$letra, $min, $max]) {
        if ($mes >= $min && ($max === null || $mes < $max)) {
            $achou = $letra;
        }
    }
    return $achou ?? ($faixas ? end($faixas)[0] : null);
}

// Faturamento × Tier das páginas (E2): o Tier cadastrado no site (5c_pages.pages.tier) e o que o
// faturamento de 30 dias (a mesma fonte do Gerar, US$) daria pelas faixas. Página sem venda (nova, OFTV)
// não entra na comparação. Volta [page_id => ['tier', 'tier_receita', 'receita', 'base', 'diverge' => bool]].
function escTierDiagnostico(array $paginas): array {
    if (!$paginas) {
        return [];
    }
    $ids = array_map('intval', array_keys($paginas));
    $tierSite = [];
    try {
        foreach (escQ('SELECT id, tier FROM `5c_pages`.`pages` WHERE id IN (' . escIn($ids) . ')', $ids) as $r) {
            $tierSite[(int) $r['id']] = strtoupper(trim((string) $r['tier']));
        }
    } catch (Throwable $e) {
        appLog('warning', 'Tier das páginas indisponível', ['erro' => $e->getMessage()]);
    }
    $receita = escV4ReceitaPaginas(array_values($paginas), (int) escV4Param('v4_receita_dias', '30'));
    $out = [];
    foreach ($paginas as $pid => $nome) {
        $r = $receita[$nome] ?? null;
        $tier = $tierSite[(int) $pid] ?? '';
        $comVenda = $r && in_array($r['base'], ['HISTORICO', 'EMULADO'], true);
        $tierRec = $comVenda ? escTierPelaReceita((float) $r['total']) : null;
        // Só o faturamento real conta (página nova sem venda, que o Gerar estima pelo meio da faixa do tier, fica com 0).
        $out[(int) $pid] = ['tier' => $tier, 'tier_receita' => $tierRec, 'receita' => $comVenda ? (float) $r['total'] : 0.0, 'base' => $r['base'] ?? 'SEM',
            'diverge' => $comVenda && $tier !== '' && $tierRec !== null && $tierRec !== $tier];
    }
    return $out;
}

// Cria ou atualiza uma ação "Recalcular Tier" para cada página com discrepância e fecha as que não
// divergem mais. $unidadeDe[page_id] = unidade (opcional).
function escAcaoSincronizarTier(array $diag, array $nomes, array $unidadeDe = []): void {
    if (!escAcaoInstalada()) {
        return;
    }
    $refs = [];
    foreach ($diag as $pid => $d) {
        if (!$d['diverge']) {
            continue;
        }
        $ref = 'tier:' . $pid;
        $refs[] = $ref;
        $sobe = array_search($d['tier_receita'], ESC_ACAO_TIERS, true) > array_search($d['tier'], ESC_ACAO_TIERS, true);
        escAcaoRegistrar(['ref' => $ref, 'tipo' => 'RECALCULAR_TIER', 'origem' => 'grade', 'unidade' => $unidadeDe[$pid] ?? null,
            'titulo' => t('Recalcular o Tier de {pagina}: {de} → {para}', ['pagina' => $nomes[$pid] ?? $pid, 'de' => $d['tier'], 'para' => $d['tier_receita']]),
            'detalhe' => t('Faturamento de 30 dias: US$ {receita} ({faixa} pela faixa de 5c_crm.tiers). Tier no cadastro do site: {tier}. O Tier está {lado} do faturamento.',
                ['receita' => num($d['receita'], 0), 'faixa' => $d['tier_receita'], 'tier' => $d['tier'], 'lado' => $sobe ? t('abaixo') : t('acima')]),
            'envolve' => (string) ($nomes[$pid] ?? $pid), 'link_tela' => 'grade', 'link_params' => [],
            'dados' => ['page_id' => (int) $pid, 'tier' => $d['tier'], 'tier_receita' => $d['tier_receita'], 'receita' => round($d['receita'], 2)]], true);
    }
    escAcaoResolverAusentes('tier:', $refs);
}
