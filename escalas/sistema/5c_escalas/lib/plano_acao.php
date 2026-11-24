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

// ---------- Sincronização das origens (Plano de Ação) ----------
// Lê cada origem e registra uma ação por item pendente; o que a origem não aponta mais fecha sozinho.
// Cada origem roda isolada (falha de uma não para as outras) e o conjunto no máximo a cada 5 min
// ($forcar ignora o intervalo). Devolve [origem => n ações ativas | 'erro: ...'].
function escAcoesSincronizar(bool $forcar = false): array {
    if (!escAcaoInstalada()) {
        return [];
    }
    $marca = sys_get_temp_dir() . '/5c_escalas_acoes_sync_' . ESC_SCHEMA . '.json';
    if (!$forcar && is_file($marca) && filemtime($marca) > time() - 300) {
        return json_decode((string) @file_get_contents($marca), true) ?: [];
    }
    $hoje = date('Y-m-d');
    $seg = segundaDaSemana($hoje);
    $resumo = [];
    $rodar = static function (string $nome, callable $fn) use (&$resumo): void {
        try {
            $resumo[$nome] = $fn();
        } catch (Throwable $e) {
            appLog('warning', 'Plano de Ação: origem indisponível', ['origem' => $nome, 'erro' => $e->getMessage()]);
            $resumo[$nome] = 'erro: ' . mb_substr($e->getMessage(), 0, 120);
        }
    };
    // Unidade de cada página (carteira aberta).
    $unidadeDe = [];
    foreach (escQ('SELECT pagina, carteira FROM `5c_planning`.`fc_pagina_carteira` WHERE vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate > ?)', [$hoje, $hoje]) as $c) {
        if (preg_match('~^U(\d)~', (string) $c['carteira'], $m)) {
            $unidadeDe[mb_strtolower(trim((string) $c['pagina']))] = (int) $m[1];
        }
    }
    $uDe = static fn(string $pagina): ?int => $unidadeDe[mb_strtolower(trim($pagina))] ?? null;

    // 1. Gerar: lacunas da versão atual de cada semana de hoje em diante (uma ação por dia, turno e unidade)
    //    e as sugestões de carga (WORKLOAD) ainda abertas. A mesma lacuna não repete a cada versão.
    $rodar('gerar', static function () use ($seg, $hoje, $uDe): int {
        $refsCob = $refsCarga = [];
        foreach ([$seg, escMaisDias($seg, 7)] as $s) {
            $pl = escPlanoAtual($s);
            if (!$pl) {
                continue;
            }
            $lac = [];
            foreach (escQ("SELECT dia, turno, pagina FROM " . escT('esc_plano_alocacao') . " WHERE plano_id = ? AND rep_code IS NULL AND origem <> 'TREINO' AND dia >= ? AND bloco IN ('T2','T4','T6') GROUP BY dia, turno, pagina", [(int) $pl['id'], $hoje]) as $l) {
                $u = $uDe((string) $l['pagina']);
                $lac[substr((string) $l['dia'], 0, 10) . '|' . $l['turno'] . '|' . ($u ?? 0)][] = (string) $l['pagina'];
            }
            foreach ($lac as $k => $pags) {
                [$dia, $tu, $u] = explode('|', $k);
                $ref = 'cob:' . $dia . ':' . $tu . ':' . $u;
                $refsCob[] = $ref;
                escAcaoRegistrar(['ref' => $ref, 'tipo' => 'COBERTURA', 'origem' => 'gerar', 'unidade' => (int) $u ?: null, 'turno' => $tu, 'dia' => $dia,
                    'titulo' => tn(count($pags), '{n} página sem ninguém em {dia} no turno {turno}', '{n} páginas sem ninguém em {dia} no turno {turno}', ['dia' => dataBr($dia, true), 'turno' => $tu]),
                    'detalhe' => t('Cobrir na grade com um buffer de plantão do turno (ajuste manual da célula) ou aceitar a lacuna. Versão {versao}.', ['versao' => escPlanoRotulo($pl)]),
                    'envolve' => implode(', ', $pags), 'link_tela' => 'grade', 'link_params' => ['plano' => (int) $pl['id'], 'turno' => $tu] + ((int) $u ? ['u' => (int) $u] : [])]);
            }
            foreach (escQ("SELECT id, titulo, descricao, dados FROM " . escT('esc_sugestao') . " WHERE plano_id = ? AND tipo = 'WORKLOAD' AND status = 'ABERTA'", [(int) $pl['id']]) as $sg) {
                $d = json_decode((string) $sg['dados'], true) ?: [];
                if (($d['dia'] ?? $hoje) < $hoje) {
                    continue;
                }
                $ref = 'carga:' . $s . ':' . ($d['rep_code'] ?? '') . ':' . ($d['dia'] ?? '') . ':' . ($d['turno'] ?? '');
                $refsCarga[] = $ref;
                escAcaoRegistrar(['ref' => $ref, 'tipo' => 'CARGA', 'origem' => 'gerar', 'turno' => $d['turno'] ?? null, 'dia' => $d['dia'] ?? null,
                    'titulo' => (string) $sg['titulo'], 'detalhe' => (string) $sg['descricao'], 'envolve' => (string) ($d['rep_code'] ?? ''),
                    'link_tela' => 'grade', 'link_params' => ['plano' => (int) $pl['id']], 'dados' => ['sugestao_id' => (int) $sg['id'], 'plano_id' => (int) $pl['id']]]);
            }
        }
        escAcaoResolverAusentes('cob:', $refsCob);
        escAcaoResolverAusentes('carga:', $refsCarga);
        return count($refsCob) + count($refsCarga);
    });

    // 2. Owners e backups: trocas sugeridas pela política de páginas.
    $rodar('owners', static function () use ($hoje, $uDe): int {
        $refs = [];
        if (function_exists('escPolInstalada') && escPolInstalada()) {
            $nomes = escNomes();
            foreach (escPolAvaliar($hoje)['sugestoes'] as $sg) {
                $owner = $sg['tipo'] === 'OWNER';
                $ref = 'pol:' . $sg['tipo'] . ':' . mb_strtolower((string) $sg['pagina']) . ':' . ($sg['turno'] ?? '');
                $refs[] = $ref;
                $sai = $owner ? nomeCurto($nomes[$sg['de']] ?? (string) $sg['de']) : '';
                $entra = $owner ? nomeCurto($nomes[$sg['para']] ?? (string) $sg['para']) : '';
                escAcaoRegistrar(['ref' => $ref, 'tipo' => $owner ? 'TROCA_PESSOA' : 'TROCA_PAGINA', 'origem' => 'owners', 'unidade' => $uDe((string) $sg['pagina']), 'turno' => $sg['turno'] ?? null,
                    'titulo' => $owner ? t('Trocar o owner de {pagina} no turno {turno}', ['pagina' => $sg['pagina'], 'turno' => $sg['turno']]) : t('Avaliar a troca de unidade de {pagina}', ['pagina' => $sg['pagina']]),
                    'detalhe' => t('Atingimento da meta de {pct}: o prazo da política de páginas venceu.', ['pct' => pct((float) ($sg['ating'] ?? 0))])
                        . ($owner ? ' ' . t('Sai {sai}, entra {entra}.', ['sai' => $sai, 'entra' => $entra]) : ''),
                    'envolve' => $owner ? $sg['pagina'] . ' · ' . $sai . ' → ' . $entra : (string) $sg['pagina'],
                    'link_tela' => 'owners', 'link_params' => []]);
            }
        }
        escAcaoResolverAusentes('pol:', $refs);
        return count($refs);
    });

    // 3. Equilíbrio das unidades: trocas de unidade sugeridas pelo faturamento.
    $rodar('equilibrio', static function (): int {
        $refs = [];
        $dia = max(date('Y-m-d', strtotime('next monday')), escV4DiaEstrutura());
        $eq = escEqDados(date('Y-m-d'), $dia);
        foreach (escEqSugerirTrocas($eq['paginas'], escEqPesoTotal($eq['fat'], $eq['srs']), 60, 0.005, max(0, (int) escV4Param('v4_conhece_min', '2'))) as $sg) {
            $ref = 'eq:' . mb_strtolower((string) $sg['pagina']) . ':' . $sg['de'] . ':' . $sg['para'];
            $refs[] = $ref;
            escAcaoRegistrar(['ref' => $ref, 'tipo' => 'TROCA_PAGINA', 'origem' => 'equilibrio', 'unidade' => (int) $sg['de'],
                'titulo' => t('Mover {pagina} da U{de} para a U{para}', ['pagina' => $sg['pagina'], 'de' => $sg['de'], 'para' => $sg['para']]),
                'detalhe' => t('Equilíbrio do faturamento entre as unidades: melhora {pct}. Faturamento de 30 dias: US$ {fat}.', ['pct' => pct((float) $sg['melhora'], 1), 'fat' => num((float) $sg['faturamento'], 0)]),
                'envolve' => (string) $sg['pagina'], 'link_tela' => 'equilibrio', 'link_params' => []]);
        }
        escAcaoResolverAusentes('eq:', $refs);
        return count($refs);
    });

    // 4. Funil de entrada: páginas ativas no site e fora da escala.
    $rodar('funil', static function (): int {
        $refs = [];
        foreach (escEntradasDetectadas(false) as $e) {
            if (!empty($e['decisao']) && $e['decisao'] !== 'PENDENTE') {
                continue;
            }
            $ref = 'ent:' . (int) $e['page_id'];
            $refs[] = $ref;
            $desde = !empty($e['ativa_desde']) ? substr((string) $e['ativa_desde'], 0, 10) : null;
            escAcaoRegistrar(['ref' => $ref, 'tipo' => 'ENTRADA_PAGINA', 'origem' => 'funil',
                'titulo' => t('Pôr {pagina} na escala ou ignorar', ['pagina' => $e['pagina']]),
                'detalhe' => t('Página ativa no site e fora da escala ({situacao}), ativa desde {dia}.', ['situacao' => mb_strtolower((string) $e['situacao']), 'dia' => $desde ? dataBr($desde) : '—']),
                'envolve' => (string) $e['pagina'], 'dia' => $desde, 'link_tela' => 'funil', 'link_params' => []]);
        }
        escAcaoResolverAusentes('ent:', $refs);
        return count($refs);
    });

    // 5. Saídas de página a conferir (detectadas pelo Capacity, sem decisão) de página que ainda está
    //    na estrutura (o mesmo critério da verificação do Gerar): a que já saiu não tem o que conferir.
    $rodar('saidas', static function () use ($uDe): int {
        $refs = [];
        foreach (escQ("SELECT e.id, e.pagina, DATE(e.data_evento) AS dia FROM `5c_planning`.`fc_pagina_eventos` e
                        LEFT JOIN " . escT('esc_saida_checagem') . " c ON c.evento_id = e.id
                       WHERE e.tipo = 'SAIDA' AND IFNULL(e.status, '') <> 'CANCELADA' AND e.data_evento >= CURDATE() - INTERVAL 45 DAY
                         AND IFNULL(c.decisao, 'PENDENTE') = 'PENDENTE'
                         AND EXISTS (SELECT 1 FROM `5c_planning`.`fc_pagina_carteira` pc WHERE pc.pagina = e.pagina AND pc.vigente_ate IS NULL)") as $s) {
            $ref = 'sai:' . (int) $s['id'];
            $refs[] = $ref;
            escAcaoRegistrar(['ref' => $ref, 'tipo' => 'SAIDA_PAGINA', 'origem' => 'saidas', 'unidade' => $uDe((string) $s['pagina']), 'dia' => (string) $s['dia'],
                'titulo' => t('Conferir a saída de {pagina}', ['pagina' => $s['pagina']]), 'detalhe' => t('Saída detectada pelo Capacity em {dia}: confirme (e aplique) ou marque "não saiu".', ['dia' => dataBr((string) $s['dia'])]),
                'envolve' => (string) $s['pagina'], 'link_tela' => 'saidas', 'link_params' => []]);
        }
        escAcaoResolverAusentes('sai:', $refs);
        return count($refs);
    });

    // 6. Pedidos pendentes: indisponibilidade, solicitações de SR e interesse em hora extra.
    $rodar('pedidos', static function (): int {
        $nomes = escNomes();
        $por = ['ind:' => [], 'sol:' => [], 'he:' => []];
        foreach (escQ('SELECT id, UPPER(rep_code) AS rep, tipo, solicitado_em FROM ' . escT('esc_indisponibilidade') . " WHERE status = 'PENDENTE'") as $p) {
            $por['ind:'][] = $ref = 'ind:' . (int) $p['id'];
            escAcaoRegistrar(['ref' => $ref, 'tipo' => 'PEDIDO', 'origem' => 'aprovar', 'dia' => substr((string) $p['solicitado_em'], 0, 10),
                'titulo' => t('Decidir a indisponibilidade de {nome}', ['nome' => nomeCurto($nomes[$p['rep']] ?? $p['rep'])]), 'detalhe' => (string) $p['tipo'],
                'envolve' => (string) $p['rep'], 'link_tela' => 'aprovar', 'link_params' => []]);
        }
        if (function_exists('escSolInstalada') && escSolInstalada()) {
            foreach (escQ('SELECT id, UPPER(rep_code) AS rep, tipo, solicitado_em FROM ' . escT('esc_solicitacao') . " WHERE status = 'PENDENTE'") as $p) {
                $por['sol:'][] = $ref = 'sol:' . (int) $p['id'];
                escAcaoRegistrar(['ref' => $ref, 'tipo' => 'PEDIDO', 'origem' => 'solicitacoes', 'dia' => substr((string) $p['solicitado_em'], 0, 10),
                    'titulo' => t('Decidir a solicitação de {nome}', ['nome' => nomeCurto($nomes[$p['rep']] ?? $p['rep'])]), 'detalhe' => (string) $p['tipo'],
                    'envolve' => (string) $p['rep'], 'link_tela' => 'solicitacoes', 'link_params' => []]);
            }
        }
        foreach (escQ('SELECT id, UPPER(rep_code) AS rep, dia, turno, unidade, pagina FROM ' . escT('esc_hora_extra') . " WHERE status = 'INTERESSADO' AND dia >= CURDATE()") as $p) {
            $por['he:'][] = $ref = 'he:' . (int) $p['id'];
            escAcaoRegistrar(['ref' => $ref, 'tipo' => 'PEDIDO', 'origem' => 'horas', 'unidade' => $p['unidade'] !== null ? (int) $p['unidade'] : null, 'turno' => $p['turno'], 'dia' => (string) $p['dia'],
                'titulo' => t('Decidir a hora extra de {nome}', ['nome' => nomeCurto($nomes[$p['rep']] ?? $p['rep'])]), 'detalhe' => (string) ($p['pagina'] ?? ''),
                'envolve' => (string) $p['rep'], 'link_tela' => 'horas', 'link_params' => []]);
        }
        foreach ($por as $prefixo => $refs) {
            escAcaoResolverAusentes($prefixo, $refs);
        }
        return array_sum(array_map('count', $por));
    });

    // 7. Alertas: escala a gerar, publicar ou postar (semana atual e seguinte) e o diário do Capacity.
    $rodar('alertas', static function () use ($seg, $hoje): int {
        $refs = [];
        foreach ([$seg, escMaisDias($seg, 7)] as $s) {
            $atual = escPlanoAtual($s);
            $pub = escPlanoPublicado($s);
            if (!$atual) {
                $refs[] = $ref = 'alerta:gerar:' . $s;
                escAcaoRegistrar(['ref' => $ref, 'tipo' => 'ALERTA', 'origem' => 'gerar', 'dia' => $s, 'titulo' => t('Gerar a escala da semana de {dia}', ['dia' => dataBr($s)]),
                    'link_tela' => 'gerar', 'link_params' => ['semana' => $s]]);
            } elseif ($atual['status'] === 'RASCUNHO') {
                $refs[] = $ref = 'alerta:publicar:' . $s;
                escAcaoRegistrar(['ref' => $ref, 'tipo' => 'ALERTA', 'origem' => 'grade', 'dia' => $s,
                    'titulo' => t('Publicar o rascunho {versao} da semana de {dia}', ['versao' => escPlanoRotulo($atual), 'dia' => dataBr($s)]),
                    'link_tela' => 'grade', 'link_params' => ['plano' => (int) $atual['id']]]);
            }
            if ($pub) {
                $postadas = (int) (escQ1('SELECT COUNT(*) AS n FROM ' . escT('esc_plano_postagem') . ' WHERE plano_id = ? AND postado_em IS NOT NULL', [(int) $pub['id']])['n'] ?? 0);
                if ($postadas === 0) {
                    $refs[] = $ref = 'alerta:postar:' . $s . ':' . (int) $pub['id'];
                    escAcaoRegistrar(['ref' => $ref, 'tipo' => 'ALERTA', 'origem' => 'grade', 'dia' => $s,
                        'titulo' => t('Postar a {versao} da semana de {dia} no site da 5C', ['versao' => escPlanoRotulo($pub), 'dia' => dataBr($s)]),
                        'detalhe' => t('Nenhuma página marcada como postada. Use o pacote (.json) da Grade.'), 'link_tela' => 'grade', 'link_params' => ['plano' => (int) $pub['id']]]);
                }
            }
        }
        $log = escQ1("SELECT etapa, status, LEFT(mensagem, 200) AS msg FROM `5c_planning`.`fc_log_execucao` WHERE DATE(inicio) = ? ORDER BY id DESC LIMIT 1", [$hoje]);
        if ($log && $log['status'] === 'ERRO') {
            $refs[] = $ref = 'alerta:diario:' . $hoje;
            escAcaoRegistrar(['ref' => $ref, 'tipo' => 'ALERTA', 'origem' => 'capacity', 'dia' => $hoje,
                'titulo' => t('O diário do Capacity de hoje falhou na etapa {etapa}', ['etapa' => $log['etapa']]), 'detalhe' => (string) $log['msg'],
                'link_tela' => 'capacity', 'link_params' => []]);
        }
        // Capacity automático (ES_222): pedido parado há mais de 10 min ou recálculo com erro nas últimas 24 h.
        $fila = function_exists('escCapacityFila') ? escCapacityFila() : ['pendentes' => 0, 'desde' => null, 'erro' => null];
        if (($fila['pendentes'] && $fila['desde'] && strtotime((string) $fila['desde']) < time() - 600) || $fila['erro']) {
            $refs[] = $ref = 'alerta:capacity:' . $hoje;
            escAcaoRegistrar(['ref' => $ref, 'tipo' => 'ALERTA', 'origem' => 'capacity', 'dia' => $hoje,
                'titulo' => t('Capacity pendente: o recálculo automático não rodou'),
                'detalhe' => $fila['erro'] ? t('Último erro: {erro}. O Gerar e o diário recalculam.', ['erro' => $fila['erro']])
                    : tn($fila['pendentes'], '{n} mudança esperando desde {quando} (outro recálculo ou um Gerar rodando?).', '{n} mudanças esperando desde {quando} (outro recálculo ou um Gerar rodando?).', ['quando' => escDataHora((string) $fila['desde'])]),
                'link_tela' => 'capacity', 'link_params' => []], true);
        }
        escAcaoResolverAusentes('alerta:', $refs);
        return count($refs);
    });

    // 8. Faturamento × Tier das páginas da escala (a Grade também sincroniza).
    $rodar('tier', static function () use ($uDe): int {
        $pags = [];
        foreach (escQ("SELECT DISTINCT pp.page_id, pp.pagina FROM " . escT('esc_posto_pagina') . " pp JOIN " . escT('esc_posto') . " p ON p.id = pp.posto_id WHERE p.vigente_desde <= CURDATE() AND (p.vigente_ate IS NULL OR p.vigente_ate >= CURDATE())") as $l) {
            $pags[(int) $l['page_id']] = (string) $l['pagina'];
        }
        $diag = escTierDiagnostico($pags);
        escAcaoSincronizarTier($diag, $pags, array_map($uDe, $pags));
        return count(array_filter($diag, static fn($d) => $d['diverge']));
    });

    @file_put_contents($marca, json_encode($resumo));
    return $resumo;
}

// Ações pendentes (selo do menu).
function escAcoesPendentes(): int {
    if (!escAcaoInstalada()) {
        return 0;
    }
    return (int) (escQ1('SELECT COUNT(*) AS n FROM ' . escT('esc_acao') . " WHERE status = 'PENDENTE'")['n'] ?? 0);
}
