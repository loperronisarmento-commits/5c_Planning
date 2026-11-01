<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/politica.php
// Política de páginas por mérito (escalas/Politica_paginas_merito_2026-10-30.md; ES_33).
// Atingimento = 5CReps realizado ÷ meta 5CReps (Intraday, tab_intraday_base), por dia:
//   - da página na unidade (todos os turnos, desde que entrou na carteira da unidade);
//   - da página em cada turno (desde que o owner atual assumiu a vaga de dono).
// Faixas: abaixo do corte (85%) o prazo corre, com retorno decrescente
//   prazo = mín + (máx − mín) × ((a − piso) ÷ (corte − piso))^γ ;
// entre o corte e a meta, acompanhamento longo; acima da meta, mantém; destaque sustentado,
// candidata a página maior. Prazo vencido vira sugestão: troca de owner (um turno vai mal)
// ou troca de unidade (a página vai mal na unidade). Prioridades fixas vencem a nota.
// ===============================================

const ESC_POL_TURNO = ['T1' => 'D', 'T2' => 'A', 'T3' => 'A', 'T4' => 'C', 'T5' => 'C', 'T6' => 'D'];

function escPolInstalada(): bool {
    static $ok = null;
    if ($ok === null) {
        $ok = (bool) escQ1("SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'esc_politica_troca'", [ESC_SCHEMA]);
    }
    return $ok;
}

function escPolParam(): array {
    static $p = null;
    if ($p === null) {
        $p = ['pol_piso' => 0.5, 'pol_corte_acomp' => 0.85, 'pol_prazo_min' => 3, 'pol_prazo_max' => 14, 'pol_gamma' => 2.0, 'pol_prazo_acomp' => 28,
              'pol_destaque' => 1.1, 'pol_destaque_dias' => 14, 'pol_carencia' => 7, 'pol_owner_min' => 14, 'pol_max_trocas_semana' => 2,
              'pol_janela' => 28, 'pol_dias_minimos' => 3];
        foreach (escQ('SELECT chave, valor FROM ' . escT('esc_parametro') . " WHERE grupo = 'politica'") as $l) {
            if (isset($p[$l['chave']]) && is_numeric($l['valor'])) {
                $p[$l['chave']] = (float) $l['valor'];
            }
        }
    }
    return $p;
}

// Prazo (dias) para o atingimento $a, abaixo do corte.
function escPolPrazo(float $a, array $p): int {
    if ($a <= $p['pol_piso']) {
        return (int) $p['pol_prazo_min'];
    }
    $x = min(1.0, ($a - $p['pol_piso']) / max(0.0001, $p['pol_corte_acomp'] - $p['pol_piso']));
    return (int) round($p['pol_prazo_min'] + ($p['pol_prazo_max'] - $p['pol_prazo_min']) * pow($x, $p['pol_gamma']));
}

// Realizado e meta por página, dia e turno (A, C, D), dos últimos dias da janela (cache de 15 min).
function escPolSeries(string $hoje): array {
    $p = escPolParam();
    $de = escMaisDias($hoje, -(int) $p['pol_janela'] - 1);
    return escCache('politica_series_' . $hoje, 900, static function () use ($de, $hoje): array {
        $s = [];
        foreach (escQ("SELECT `Date` AS dia, Page AS pagina, Turn AS t, SUM(`5CReps`) AS r, SUM(`Goal 5CReps`) AS g
                         FROM `5c_wfm_db_mis`.`tab_intraday_base` WHERE `Date` >= ? AND `Date` < ? GROUP BY `Date`, Page, Turn", [$de, $hoje]) as $l) {
            $t = ESC_POL_TURNO[$l['t']] ?? null;
            if ($t === null) {
                continue;
            }
            $d = substr((string) $l['dia'], 0, 10);
            $s[$l['pagina']][$t][$d][0] = ($s[$l['pagina']][$t][$d][0] ?? 0) + (float) $l['r'];
            $s[$l['pagina']][$t][$d][1] = ($s[$l['pagina']][$t][$d][1] ?? 0) + (float) $l['g'];
        }
        return $s;
    });
}

// Situação de uma série [dia => [real, meta]] desde $inicio. Devolve situacao, ating (janela),
// ating7, ating14, dias com dado, desde quando abaixo, quando vence o prazo e quantos dias faltam.
function escPolSituacao(array $serie, string $inicio, string $hoje, ?string $ultimaTroca, array $p): array {
    $w0 = max($inicio, escMaisDias($hoje, -(int) $p['pol_janela']), $ultimaTroca ?? '0000-00-00');
    $dias = array_filter($serie, static fn($v, $d) => $d >= $w0 && $d < $hoje && $v[1] > 0, ARRAY_FILTER_USE_BOTH);
    ksort($dias);
    $soma = static function (array $ds): ?float {
        $g = array_sum(array_column($ds, 1));
        return $g > 0 ? array_sum(array_column($ds, 0)) / $g : null;
    };
    $ult = static fn(int $n) => array_filter($dias, static fn($d) => $d >= escMaisDias($hoje, -$n), ARRAY_FILTER_USE_KEY);
    $r = ['situacao' => 'AGUARDANDO', 'ating' => $soma($dias), 'ating7' => $soma($ult(7)), 'ating14' => $soma($ult(14)), 'dias' => count($dias),
          'desde' => $w0, 'abaixo_desde' => null, 'vence' => null, 'faltam' => null];
    if ($ultimaTroca && $hoje < escMaisDias($ultimaTroca, (int) $p['pol_carencia'])) {
        $r['situacao'] = 'CARENCIA';
        $r['vence'] = escMaisDias($ultimaTroca, (int) $p['pol_carencia']);
        return $r;
    }
    if (count($dias) < $p['pol_dias_minimos'] || $r['ating'] === null) {
        return $r;
    }
    $a = $r['ating'];
    // Desde quando o acumulado está abaixo da linha (corte ou meta): dia seguinte ao último em que estava acima.
    $abaixoDesde = static function (float $linha) use ($dias): ?string {
        $re = $me = 0.0;
        $desde = null;
        foreach ($dias as $d => [$x, $g]) {
            $re += $x;
            $me += $g;
            if ($me > 0 && $re / $me >= $linha) {
                $desde = null;
            } elseif ($desde === null) {
                $desde = $d;
            }
        }
        return $desde;
    };
    if ($a >= 1.0) {
        $dest = $ult((int) $p['pol_destaque_dias']);
        $r['situacao'] = count($dias) >= $p['pol_destaque_dias'] && $soma($dest) !== null && $soma($dest) >= $p['pol_destaque'] ? 'DESTAQUE' : 'META';
        return $r;
    }
    if ($a >= $p['pol_corte_acomp']) {
        $r['abaixo_desde'] = $abaixoDesde(1.0) ?? $w0;
        $r['vence'] = escMaisDias($r['abaixo_desde'], (int) $p['pol_prazo_acomp']);
        $r['situacao'] = $hoje >= $r['vence'] ? 'ACOMP_VENCIDO' : 'ACOMPANHAMENTO';
    } else {
        $r['abaixo_desde'] = $abaixoDesde($p['pol_corte_acomp']) ?? $w0;
        $r['vence'] = escMaisDias($r['abaixo_desde'], escPolPrazo($a, $p));
        $r['situacao'] = $hoje >= $r['vence'] ? 'VENCIDO' : 'PRAZO';
    }
    $r['faltam'] = max(0, (int) round((strtotime($r['vence']) - strtotime($hoje)) / 86400));
    return $r;
}

// Páginas da escala no dia, com unidade, carteira e, por turno, postos, owners e backups.
function escPolPaginas(string $dia): array {
    $vig = ' vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)';
    $pg = [];
    $postos = [];
    foreach (escQ('SELECT p.id, p.unidade, p.carteira, p.turno, p.tipo, p.nucleo_dono_id, p.nucleo_cobertura_id, p.vigente_desde, pp.pagina, pp.fans_h
                     FROM ' . escT('esc_posto') . ' p JOIN ' . escT('esc_posto_pagina') . ' pp ON pp.posto_id = p.id
                    WHERE p.' . ltrim($vig), [$dia, $dia]) as $l) {
        $nome = $l['pagina'];
        $pg[$nome] = $pg[$nome] ?? ['pagina' => $nome, 'unidades' => [], 'carteira' => $l['carteira'], 'turnos' => []];
        $pg[$nome]['unidades'][(int) $l['unidade']] = ($pg[$nome]['unidades'][(int) $l['unidade']] ?? 0) + 1;
        $t = $l['turno'];
        $pg[$nome]['turnos'][$t] = $pg[$nome]['turnos'][$t] ?? ['postos' => [], 'owners' => [], 'backups' => [], 'dono' => (int) $l['nucleo_dono_id'], 'cobertura' => (int) $l['nucleo_cobertura_id'], 'fans' => 0.0];
        $pg[$nome]['turnos'][$t]['postos'][] = (int) $l['id'];
        $pg[$nome]['turnos'][$t]['fans'] += (float) $l['fans_h'];
        $postos[(int) $l['id']][] = [$nome, $t];
    }
    if ($postos) {
        $ids = array_keys($postos);
        foreach (escQ('SELECT posto_id, vaga, UPPER(rep_code) AS rep, vigente_desde FROM ' . escT('esc_vaga_equipe') . ' WHERE posto_id IN (' . escIn($ids) . ') AND' . $vig . ' ORDER BY vaga, ordem', array_merge($ids, [$dia, $dia])) as $v) {
            foreach ($postos[(int) $v['posto_id']] as [$nome, $t]) {
                $k = $v['vaga'] === 'DONO' ? 'owners' : 'backups';
                $pg[$nome]['turnos'][$t][$k][$v['rep']] = ['rep' => $v['rep'], 'desde' => substr((string) $v['vigente_desde'], 0, 10), 'posto' => (int) $v['posto_id']];
            }
        }
    }
    foreach ($pg as &$x) {
        arsort($x['unidades']);
        $x['unidade'] = (int) array_key_first($x['unidades']);
        ksort($x['turnos']);
    }
    unset($x);
    ksort($pg, SORT_NATURAL | SORT_FLAG_CASE);
    return $pg;
}

// Avaliação completa: cada página com a situação na unidade e em cada turno, as prioridades
// fixas e as sugestões de troca.
function escPolAvaliar(string $hoje): array {
    $p = escPolParam();
    $paginas = escPolPaginas($hoje);
    $series = escPolSeries($hoje);
    $inicioUnidade = [];
    foreach (escQ('SELECT pagina, MAX(vigente_desde) AS d FROM `5c_planning`.`fc_pagina_carteira` WHERE vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate > ?) GROUP BY pagina', [$hoje, $hoje]) as $l) {
        $inicioUnidade[$l['pagina']] = substr((string) $l['d'], 0, 10);
    }
    $trocas = [];
    $porSemana = [];
    $prioridade = [];
    if (escPolInstalada()) {
        foreach (escQ('SELECT tipo, pagina, turno, de_ref, para_ref, status, criado_em FROM ' . escT('esc_politica_troca') . ' WHERE criado_em >= ? ORDER BY criado_em', [escMaisDias($hoje, -60)]) as $t) {
            $d = substr((string) $t['criado_em'], 0, 10);
            if ($t['status'] === 'APLICADA') {
                $trocas[$t['pagina']][$t['tipo'] === 'UNIDADE' ? '*' : (string) $t['turno']] = $d;
                if ($t['tipo'] === 'UNIDADE' && $d >= segundaDaSemana($hoje)) {
                    $porSemana[(string) $t['de_ref']] = ($porSemana[(string) $t['de_ref']] ?? 0) + 1;
                    $porSemana[(string) $t['para_ref']] = ($porSemana[(string) $t['para_ref']] ?? 0) + 1;
                }
            } elseif ($d >= escMaisDias($hoje, -7)) {
                $trocas['descartada'][$t['pagina'] . '|' . $t['tipo'] . '|' . $t['turno']] = true;
            }
        }
        foreach (escQ('SELECT pagina, rep_codes, motivo FROM ' . escT('esc_pagina_prioridade') . ' WHERE vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)', [$hoje, $hoje]) as $l) {
            $prioridade[$l['pagina']] = ['reps' => array_values(array_filter(array_map('trim', explode(',', strtoupper((string) $l['rep_codes']))))), 'motivo' => (string) $l['motivo']];
        }
    }
    $lugares = escSolLugares(max($hoje, escV4DiaEstrutura()));
    $sug = [];
    foreach ($paginas as $nome => &$pg) {
        $serieTotal = [];
        foreach ($series[$nome] ?? [] as $porDia) {
            foreach ($porDia as $d => [$r, $g]) {
                $serieTotal[$d][0] = ($serieTotal[$d][0] ?? 0) + $r;
                $serieTotal[$d][1] = ($serieTotal[$d][1] ?? 0) + $g;
            }
        }
        $pg['situacao'] = escPolSituacao($serieTotal, $inicioUnidade[$nome] ?? escV4DiaEstrutura(), $hoje, $trocas[$nome]['*'] ?? null, $p);
        $pg['prioridade'] = $prioridade[$nome] ?? null;
        $vencidaUnidade = in_array($pg['situacao']['situacao'], ['VENCIDO', 'ACOMP_VENCIDO'], true);
        foreach ($pg['turnos'] as $t => &$tu) {
            $inicioOwner = $tu['owners'] ? min(array_column($tu['owners'], 'desde')) : ($inicioUnidade[$nome] ?? escV4DiaEstrutura());
            $tu['situacao'] = escPolSituacao($series[$nome][$t] ?? [], $inicioOwner, $hoje, $trocas[$nome][$t] ?? null, $p);
            // Prioridade fixa: quem tem prioridade e está no núcleo dono do turno vira owner
            // (se a troca de owner abaixo já põe essa pessoa, fica só a troca).
            $definir = [];
            if ($pg['prioridade']) {
                foreach ($pg['prioridade']['reps'] as $rep) {
                    $l = $lugares[$rep] ?? null;
                    if ($l && $l['nucleo_id'] === $tu['dono'] && !isset($tu['owners'][$rep]) && !isset($trocas['descartada'][$nome . '|DEFINIR_OWNER|' . $t])) {
                        $definir[$rep] = ['tipo' => 'DEFINIR_OWNER', 'pagina' => $nome, 'turno' => $t, 'posto' => $tu['postos'][0], 'para' => $rep, 'motivo' => 'prioridade', 'ating' => $tu['situacao']['ating']];
                    }
                }
            }
            // Troca de owner: o turno venceu o prazo, a página na unidade não, e o owner já tem o tempo mínimo.
            $tempoOwner = $tu['owners'] ? (strtotime($hoje) - strtotime(min(array_column($tu['owners'], 'desde')))) / 86400 : 0;
            if (!$vencidaUnidade && $tu['owners'] && in_array($tu['situacao']['situacao'], ['VENCIDO', 'ACOMP_VENCIDO'], true) && $tempoOwner >= $p['pol_owner_min']
                && !isset($trocas['descartada'][$nome . '|OWNER|' . $t])) {
                $sai = array_key_first($tu['owners']);
                $cand = escPolCandidatoOwner($tu['dono'], array_keys($tu['owners']), $lugares, $pg['prioridade']['reps'] ?? []);
                if ($cand) {
                    $sug[] = ['tipo' => 'OWNER', 'pagina' => $nome, 'turno' => $t, 'posto' => $tu['owners'][$sai]['posto'], 'de' => $sai, 'para' => $cand, 'motivo' => 'atingimento', 'ating' => $tu['situacao']['ating']];
                    unset($definir[$cand]);
                }
            }
            array_push($sug, ...array_values($definir));
        }
        unset($tu);
        // Troca de unidade: prazo vencido na unidade e limite da semana livre na origem.
        if ($vencidaUnidade && ($porSemana[(string) $pg['unidade']] ?? 0) < $p['pol_max_trocas_semana'] && !isset($trocas['descartada'][$nome . '|UNIDADE|'])) {
            $sug[] = ['tipo' => 'UNIDADE', 'pagina' => $nome, 'turno' => null, 'de' => $pg['unidade'], 'motivo' => 'atingimento', 'ating' => $pg['situacao']['ating']];
        }
    }
    unset($pg);
    // Destaque por núcleo: média das páginas de que é dono, em destaque = candidata a página maior.
    $destaque = [];
    foreach ($paginas as $pg) {
        foreach ($pg['turnos'] as $tu) {
            if ($tu['situacao']['situacao'] === 'DESTAQUE') {
                $destaque[$tu['dono']] = ($destaque[$tu['dono']] ?? 0) + 1;
            }
        }
    }
    return ['paginas' => $paginas, 'sugestoes' => $sug, 'destaque' => $destaque, 'param' => $p, 'lugares' => $lugares];
}

// Melhor SR do núcleo para ser owner: nota de mérito mais alta; quem tem prioridade na página primeiro.
function escPolCandidatoOwner(int $nucleo, array $excluir, array $lugares, array $prioridade): ?string {
    $notas = [];
    foreach (escQ('SELECT UPPER(rep_code) AS rep, nota FROM ' . escT('esc_sr_merito')) as $m) {
        $notas[$m['rep']] = $m['nota'] !== null ? (float) $m['nota'] : null;
    }
    $melhor = null;
    $k = null;
    foreach ($lugares as $rep => $l) {
        if ($l['nucleo_id'] !== $nucleo || $l['funcao'] !== 'EQUITE' || in_array($rep, $excluir, true)) {
            continue;
        }
        $chave = [in_array($rep, $prioridade, true) ? 1 : 0, $notas[$rep] ?? -1, $l['nivel'] ?? -1];
        if ($k === null || $chave > $k) {
            $k = $chave;
            $melhor = $rep;
        }
    }
    return $melhor;
}

// Troca de owner no posto a partir de $desde: a vaga de dono de $sai passa para $entra, e uma vaga
// de $entra (se tiver) passa para $sai — 1 por 1, nenhum posto sem dono. Rodar dentro de escTx.
function escPolTrocarOwner(int $posto, string $sai, string $entra, string $desde, string $motivo): array {
    $tab = escT('esc_vaga_equipe');
    $vA = escQW('SELECT * FROM ' . $tab . " WHERE posto_id = ? AND UPPER(rep_code) = ? AND vaga = 'DONO' AND (vigente_ate IS NULL OR vigente_ate >= ?) LIMIT 1 FOR UPDATE", [$posto, $sai, $desde])[0] ?? null;
    if (!$vA) {
        throw new RuntimeException(t('{rep} não é mais owner deste posto. Recarregue a tela.', ['rep' => $sai]));
    }
    $vB = escQW('SELECT * FROM ' . $tab . ' WHERE UPPER(rep_code) = ? AND posto_id <> ? AND (vigente_ate IS NULL OR vigente_ate >= ?) ORDER BY vaga = \'DONO\' DESC, fatia DESC LIMIT 1 FOR UPDATE', [$entra, $posto, $desde])[0] ?? null;
    $mover = static function (array $v, string $novo) use ($tab, $desde, $motivo): void {
        $mot = mb_substr($motivo . '; ' . (string) $v['motivo'], 0, 255);
        if (substr((string) $v['vigente_desde'], 0, 10) >= $desde) {
            escW('UPDATE ' . $tab . ' SET rep_code = ?, motivo = ? WHERE id = ?', ['#' . $novo, $mot, (int) $v['id']]);
        } else {
            escW('UPDATE ' . $tab . ' SET vigente_ate = ? WHERE id = ?', [escMaisDias($desde, -1), (int) $v['id']]);
            escW('INSERT INTO ' . $tab . ' (posto_id, vaga, rep_code, fatia, ordem, status, motivo, vigente_desde, vigente_ate, origem, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'TELA\', ?)',
                [(int) $v['posto_id'], $v['vaga'], '#' . $novo, $v['fatia'], (int) $v['ordem'], $v['status'], $mot, $desde, $v['vigente_ate'], escUserId()]);
        }
    };
    $mover($vA, $entra);
    if ($vB) {
        $mover($vB, $sai);
    }
    escW('UPDATE ' . $tab . ' SET rep_code = SUBSTRING(rep_code, 2) WHERE rep_code IN (?, ?)', ['#' . $entra, '#' . $sai]);
    return ['posto' => $posto, 'sai' => $sai, 'entra' => $entra, 'contrapartida' => $vB ? (int) $vB['posto_id'] : null];
}

// Põe $rep como owner (vaga de dono) do posto a partir de $desde. Rodar dentro de escTx.
function escPolDefinirOwner(int $posto, string $rep, string $desde, string $motivo): void {
    $ja = escQW('SELECT id FROM ' . escT('esc_vaga_equipe') . " WHERE posto_id = ? AND UPPER(rep_code) = ? AND vaga = 'DONO' AND (vigente_ate IS NULL OR vigente_ate >= ?)", [$posto, $rep, $desde]);
    if ($ja) {
        throw new RuntimeException(t('{rep} já é owner deste posto.', ['rep' => $rep]));
    }
    escW('INSERT INTO ' . escT('esc_vaga_equipe') . " (posto_id, vaga, rep_code, fatia, ordem, status, motivo, vigente_desde, origem, criado_por) VALUES (?, 'DONO', ?, 1.000, 1, 'CONFIRMADA', ?, ?, 'TELA', ?)",
        [$posto, $rep, mb_substr($motivo, 0, 255), $desde, escUserId()]);
}

function escPolRegistrar(string $tipo, string $pagina, ?string $turno, ?string $de, ?string $para, ?float $ating, string $status, ?string $desde, string $motivo, array $resultado = []): void {
    escW('INSERT INTO ' . escT('esc_politica_troca') . ' (tipo, pagina, turno, de_ref, para_ref, atingimento, status, vale_desde, motivo, resultado, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$tipo, $pagina, $turno, $de, $para, $ating !== null ? round($ating, 3) : null, $status, $desde, mb_substr($motivo, 0, 255), $resultado ? json_encode($resultado, JSON_UNESCAPED_UNICODE) : null, escUserId() ?: null]);
}

// ---------- Troca de unidade ----------

// Destinos possíveis para a página (nota do Funil, sem a unidade atual), do melhor para o pior.
function escPolDestinos(array $pg, string $dia): array {
    $vol = ['A' => 0.0, 'C' => 0.0, 'D' => 0.0];
    foreach ($pg['turnos'] as $t => $tu) {
        $vol[$t] = (float) $tu['fans'];
    }
    $sug = escFunilSugerir($vol, null, [$pg['pagina']], escFunilContexto($dia));
    return array_values(array_filter($sug['top'] ?? [], static fn($x) => $x['nucleo']['unidade'] !== $pg['unidade']));
}

// Plano dos postos na unidade de destino: por turno, quantos postos, núcleo dono (melhor nota de
// mérito com menos carga por SR) e de cobertura (o seguinte), e o owner da prioridade fixa.
function escPolPlanoDestino(array $pg, int $destino, string $dia): array {
    $ctx = escFunilContexto($dia);
    $lugares = escSolLugares(max($dia, escV4DiaEstrutura()));
    $carteira = null;
    foreach ($ctx['modelos'] as $porUnidade) {
        foreach ($porUnidade as $u => $info) {
            if ($u === $destino && stripos($pg['pagina'], (string) $info['pagina']) !== false) {
                $carteira = $info['carteira'];
            }
        }
    }
    if ($carteira === null) {
        $c = escQ1('SELECT c.carteira FROM `5c_planning`.`fc_carteira` c LEFT JOIN `5c_planning`.`fc_pagina_carteira` pc ON pc.carteira = c.carteira AND (pc.vigente_ate IS NULL OR pc.vigente_ate > ?)
                     WHERE c.unidade = ? AND c.ativo = 1 GROUP BY c.carteira ORDER BY COUNT(pc.pagina), c.carteira LIMIT 1', [$dia, $destino]);
        $carteira = $c['carteira'] ?? ('U' . $destino . 'C1');
    }
    $turnos = [];
    foreach ($pg['turnos'] as $t => $tu) {
        $ns = array_values(array_filter($ctx['nucleos'], static fn($n) => $n['unidade'] === $destino && $n['turno'] === $t));
        usort($ns, static fn($a, $b) => [-(($a['nota'] ?? 0) * 100), $a['srs'] ? $a['carga'] / $a['srs'] : 999] <=> [-(($b['nota'] ?? 0) * 100), $b['srs'] ? $b['carga'] / $b['srs'] : 999]);
        if (count($ns) < 2) {
            throw new RuntimeException(t('A U{u} não tem 2 núcleos no turno {turno} para receber a página.', ['u' => $destino, 'turno' => $t]));
        }
        $fans = max(1.0, (float) $tu['fans']);
        $n = max(1, (int) ceil($fans / ESC_FUNIL_LIMITE_POSTO));
        $owner = null;
        foreach ($pg['prioridade']['reps'] ?? [] as $rep) {
            if (($lugares[$rep]['nucleo_id'] ?? null) === $ns[0]['id']) {
                $owner = $rep;
            }
        }
        $turnos[$t] = ['postos' => $n, 'carga' => round($fans / $n, 1), 'dono' => $ns[0], 'cobertura' => $ns[1], 'owner' => $owner];
    }
    return ['carteira' => $carteira, 'turnos' => $turnos];
}

// Parte da escala da troca de unidade: os postos da página saem da origem (mesma regra da saída
// de página: as pessoas ficam na unidade) e entram no destino, nos núcleos do plano; as vagas
// de lá são completadas com SRs do destino (lib/equipes.php). Também usada pela atualização da
// estrutura, para troca gravada só no Capacity (ex.: ES_51); lá a saída das páginas da mesma
// data vai de uma vez ($sair = false), porque elas podem dividir posto.
function escPolUnidadeEscala(array $pg, array $plano, int $destino, string $desde, array &$res, bool $sair = true): void {
    if ($sair) {
        escSaidaEscala([$pg['pagina']], $desde, $res);
    }
    escTx(static function () use ($pg, $plano, $destino, $desde, &$res) {
        foreach ($plano['turnos'] as $t => $x) {
            for ($i = 0; $i < $x['postos']; $i++) {
                $seq = (int) (escQW('SELECT COALESCE(MAX(seq), 0) + 1 AS s FROM ' . escT('esc_posto') . ' WHERE carteira = ? AND turno = ?', [$plano['carteira'], $t])[0]['s'] ?? 1);
                $tipo = $i === 0 ? 'titular' : 'assistente';
                escW('INSERT INTO ' . escT('esc_posto') . ' (versao, unidade, carteira, turno, seq, tipo, pagina_principal, n_paginas, assinatura, carga, capacidade, nucleo_dono_id, nucleo_cobertura_id, vigente_desde) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?)',
                    ['POL' . date('Ymd'), $destino, $plano['carteira'], $t, $seq, $tipo, $pg['pagina'], mb_substr($pg['pagina'] . ' [' . $tipo . ']', 0, 400), $x['carga'], ESC_FUNIL_LIMITE_POSTO, $x['dono']['id'], $x['cobertura']['id'], $desde]);
                $novo = escUltimoId();
                escW('INSERT INTO ' . escT('esc_posto_pagina') . ' (posto_id, pagina, page_id, fracao, fans_h, apoio) SELECT ?, ?, MAX(page_id), 1, ?, 0 FROM ' . escT('esc_posto_pagina') . ' WHERE pagina = ?',
                    [$novo, $pg['pagina'], $x['carga'], $pg['pagina']]);
                if ($i === 0 && $x['owner']) {
                    escPolDefinirOwner($novo, $x['owner'], $desde, 'prioridade fixa: owner na troca de unidade');
                }
                $res['postos_novos'][] = ['posto' => $novo, 'pagina' => $pg['pagina'], 'turno' => $t, 'dono' => $x['dono']['id'], 'cobertura' => $x['cobertura']['id'], 'owner' => $i === 0 ? $x['owner'] : null];
            }
        }
    });
}

// Aplica a troca de unidade (só depois da confirmação na tela). Mesma trava da saída de página,
// porque recalcula o Capacity: fecha a página na carteira de origem e abre na de destino,
// recalcula, fecha ou refaz os postos dela na origem (como na saída), cria os postos no destino
// e gera de novo as semanas a partir da data.
function escPolAplicarUnidade(array $pg, int $destino, string $desde, string $motivo): array {
    if (escModoTreino()) {
        throw new RuntimeException(t('No Modo Treino a troca de unidade não mexe no Capacity. Use a prévia para ver o efeito.'));
    }
    if ((int) escDbW()->query("SELECT GET_LOCK('5c_escalas.saida_pagina', 0)")->fetchColumn() !== 1) {
        throw new RuntimeException(t('Já tem uma saída ou troca sendo aplicada (Capacity e escala recalculando). Espere terminar.'));
    }
    try {
        $plano = escPolPlanoDestino($pg, $destino, $desde);
        $res = ['de' => $pg['unidade'], 'para' => $destino, 'carteira' => $plano['carteira'], 'capacity' => [], 'postos_fechados' => [], 'postos_refeitos' => [], 'vagas_passadas' => [], 'livres' => [], 'postos_novos' => [], 'planos' => []];
        $obs = mb_substr('Troca de unidade U' . $pg['unidade'] . ' → U' . $destino . ' a partir de ' . date('d/m/Y', strtotime($desde)) . ' (política de páginas)', 0, 255);
        // 1. Capacity
        escCapacityGravar('carteira_sair', [$desde, $obs, $pg['pagina'], $pg['carteira']]);
        escCapacityGravar('carteira_entrar', [$desde, $plano['carteira'], $obs, $pg['pagina']]);
        $comVersao = (bool) escQ1("SELECT 1 AS ok FROM information_schema.routines WHERE routine_schema = '5c_planning' AND routine_name = 'sp_fc_recalcular_postos'");
        foreach (escSaidaDatasCapacity($desde) as $dc) {
            escCapacityGravar($comVersao ? 'recalcular_versao' : 'recalcular', $comVersao ? [$dc, $obs] : [$dc]);
            $res['capacity'][] = $dc;
        }
        // 2. Escala: sai da origem (mesma regra da saída de página) e entra no destino, na mesma
        //    transação: com erro no meio, a página não fica sem posto nas duas unidades.
        escTx(static function () use ($pg, $plano, $destino, $desde, &$res) {
            escPolUnidadeEscala($pg, $plano, $destino, $desde, $res);
        });
        // 3. Escala de novo a partir da data.
        foreach (escQ("SELECT DISTINCT semana_inicio AS s FROM " . escT('esc_plano') . " WHERE estrutura = 'V4' AND status IN ('RASCUNHO','PUBLICADO') AND semana_fim >= ? ORDER BY semana_inicio", [$desde]) as $r) {
            $seg = substr((string) $r['s'], 0, 10);
            $res['planos'][] = ['semana' => $seg, 'plano_id' => escMotorGerar($seg, 'RECALCULO', escUserId() ?: null, null, true)];
        }
        escPolRegistrar('UNIDADE', $pg['pagina'], null, (string) $pg['unidade'], (string) $destino, $pg['situacao']['ating'] ?? null, 'APLICADA', $desde, $motivo, $res);
        escLog('politica.unidade', 'esc_politica_troca', $pg['pagina'], ['unidade' => $pg['unidade']], $res);
        return $res;
    } finally {
        escDbW()->query("SELECT RELEASE_LOCK('5c_escalas.saida_pagina')")->fetchColumn();
    }
}
