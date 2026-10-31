<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/equipes.php
// Nenhum posto aberto fica sem equipe fixa. Antes de cada geração (escMotorGerar), a vaga
// de dono ou de cobertura sem ninguém recebe SRs do mesmo turno e unidade, nesta ordem:
//   1. do núcleo da vaga, dentro da fatia do nível (quem tem folga);
//   2. de outro núcleo da unidade e turno, dentro da fatia;
//   3. níveis 0–2 acima da fatia, até 1 (usam os dias de buffer), do núcleo e depois dos outros;
//   4. se ainda ninguém: quem do núcleo (ou do turno) estiver menos carregado, mesmo acima de 1.
// Dentro de cada passo vem quem conhece as páginas do posto (dias no 5C Schedule em 8
// semanas), depois a nota de mérito. A equipe é preferência: se faltar gente no dia, o
// gerador continua priorizando as páginas pela mecânica normal (a página pode ficar vazia
// num dia), mas o posto sempre tem a quem chamar. Mesmas regras do montador (gerar_es17.py):
// dono precisa de 1 pessoa inteira; cobertura de um posto só, de meia (a fatia gravada é
// a parte do posto: uso de 0,5 grava 1,0).
// ===============================================

// Vaga de equipe que nunca chegou a valer vai para a data neutra (antes da virada, onde o motor
// não lê). Se a mesma pessoa já tem essa linha neutra na mesma vaga, a repetida é apagada
// (a chave única é posto + vaga + pessoa + início).
function escVagaNeutra(int $id, string $neutra): void {
    $v = escQ1('SELECT posto_id, vaga, rep_code FROM ' . escT('esc_vaga_equipe') . ' WHERE id = ?', [$id]);
    if (!$v) {
        return;
    }
    $ja = escQ1('SELECT 1 AS ok FROM ' . escT('esc_vaga_equipe') . ' WHERE posto_id = ? AND vaga = ? AND rep_code = ? AND vigente_desde = ? AND id <> ?',
        [(int) $v['posto_id'], $v['vaga'], $v['rep_code'], $neutra, $id]);
    if ($ja) {
        escW('DELETE FROM ' . escT('esc_vaga_equipe') . ' WHERE id = ?', [$id]);
        return;
    }
    escW('UPDATE ' . escT('esc_vaga_equipe') . ' SET vigente_desde = ?, vigente_ate = ? WHERE id = ?', [$neutra, $neutra, $id]);
}

// Fatia do nível: 0 = 0,4; 1 = 0,6; 2 = 0,8; 3 a 5 = 1 (ou a fatia regular do cadastro).
function escEquipeFatiaNivel(?float $regular, int $nivel): float {
    if ($regular !== null && $regular > 0) {
        return min(1.0, $regular);
    }
    return $nivel >= 3 ? 1.0 : 0.4 + 0.2 * max(0, $nivel);
}

// Escolhe quem entra na vaga. $cands = [rep => ['nucleo', 'nivel', 'cap', 'uso']], $vaga = ['nucleo',
// 'precisa', 'paginas'], $hist = [rep][pagina] = dias, $notas = [rep] = nota. Devolve
// [[rep, uso que assume, passo]] (vazio só se não houver nenhum SR no turno). Função pura.
function escEquipeEscolher(array $cands, array $vaga, array $hist, array $notas): array {
    $conhece = static function (string $rep) use ($vaga, $hist): int {
        $d = 0;
        foreach ($vaga['paginas'] as $pg) {
            $d += min(28, $hist[$rep][$pg] ?? 0);
        }
        return $d;
    };
    $falta = $vaga['precisa'];
    // Quem conhece as páginas primeiro; vaga inteira vai para nível 3–5 (fixo numa vaga cheia) e o
    // resto de uma vaga já começada, para níveis 0–2 (que dividem a fatia em até 2 vagas).
    $ordem = static function (array $reps, callable $disp) use ($conhece, $notas, $cands, &$falta): array {
        $k = static fn($r) => [$conhece($r), ($cands[$r]['nivel'] >= 3) === ($falta >= 0.999) ? 1 : 0, $notas[$r] ?? -1, $disp($r)];
        usort($reps, static fn($a, $b) => $k($b) <=> $k($a));
        return $reps;
    };
    $escolha = [];
    $passos = [
        1 => [static fn($c) => $c['nucleo'] === $vaga['nucleo'], static fn($c) => $c['cap'] - $c['uso']],
        2 => [static fn($c) => $c['nucleo'] !== $vaga['nucleo'], static fn($c) => $c['cap'] - $c['uso']],
        3 => [static fn($c) => $c['nivel'] < 3, static fn($c) => 1.0 - max($c['uso'], $c['cap'])],
    ];
    $uso = array_map(static fn($c) => $c['uso'], $cands);
    foreach ($passos as $passo => [$filtro, $folga]) {
        $disp = static fn(string $r) => $folga(['uso' => $uso[$r]] + $cands[$r]);
        $reps = array_keys(array_filter($cands, static fn($c, $r) => $filtro($c) && $disp((string) $r) >= 0.2 - 1e-9 && !isset($escolha[$r]), ARRAY_FILTER_USE_BOTH));
        // Um por vez: a ordem muda quando a vaga deixa de ser inteira. No passo 3, o núcleo da vaga primeiro.
        while ($reps) {
            if ($falta <= 1e-6 || count($escolha) >= 3) {
                break 2;
            }
            $mesmo = $passo === 3 ? array_values(array_filter($reps, static fn($r) => $cands[$r]['nucleo'] === $vaga['nucleo'])) : [];
            $r = (string) $ordem($mesmo ?: $reps, $disp)[0];
            $reps = array_values(array_diff($reps, [$r]));
            $f = round(min($falta, $disp($r)), 3);
            $escolha[$r] = [(string) $r, $f, $passo];
            $uso[$r] += $f;
            $falta -= $f;
        }
    }
    if (!$escolha && $cands) {
        // Passo 4: ninguém com folga. Quem estiver menos carregado, do núcleo da vaga se houver.
        $mesmo = array_keys(array_filter($cands, static fn($c) => $c['nucleo'] === $vaga['nucleo']));
        $reps = $mesmo ?: array_keys($cands);
        usort($reps, static fn($a, $b) => [$cands[$a]['uso'] - $cands[$a]['cap'], -$conhece($a)] <=> [$cands[$b]['uso'] - $cands[$b]['cap'], -$conhece($b)]);
        $escolha[$reps[0]] = [(string) $reps[0], $vaga['precisa'], 4];
    }
    return array_values($escolha);
}

// Completa as vagas sem ninguém dos postos vigentes em $ref, a partir de $desde (nunca antes do
// início do posto). Rodar fora de outra transação. Devolve a lista do que foi feito.
function escEquipesCompletar(string $desde, string $ref): array {
    $vig = ' vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)';
    $postos = escQ('SELECT id, unidade, turno, carga, nucleo_dono_id, nucleo_cobertura_id, vigente_desde, vigente_ate FROM ' . escT('esc_posto') . ' WHERE' . $vig . ' ORDER BY carga DESC, id', [$ref, $ref]);
    if (!$postos) {
        return [];
    }
    $tem = [];   // [posto][vaga] = true
    $uso = [];   // [rep] = uso (dono conta a fatia; cobertura, metade)
    $idsVig = array_flip(array_map(static fn($p) => (int) $p['id'], $postos));
    foreach (escQ('SELECT posto_id, vaga, UPPER(rep_code) AS rep, fatia FROM ' . escT('esc_vaga_equipe') . ' WHERE' . $vig, [$ref, $ref]) as $e) {
        if (!isset($idsVig[(int) $e['posto_id']])) {
            continue;
        }
        $tem[(int) $e['posto_id']][$e['vaga']] = true;
        $uso[$e['rep']] = ($uso[$e['rep']] ?? 0) + (float) $e['fatia'] * ($e['vaga'] === 'DONO' ? 1 : 0.5);
    }
    $vazias = [];
    foreach ($postos as $p) {
        foreach (['DONO' => 'nucleo_dono_id', 'COBERTURA' => 'nucleo_cobertura_id'] as $vaga => $col) {
            if (empty($tem[(int) $p['id']][$vaga]) && $p[$col] !== null) {
                $vazias[] = ['posto' => $p, 'vaga' => $vaga, 'nucleo' => (int) $p[$col], 'precisa' => $vaga === 'DONO' ? 1.0 : 0.5];
            }
        }
    }
    if (!$vazias) {
        return [];
    }
    $ids = array_values(array_unique(array_map(static fn($v) => (int) $v['posto']['id'], $vazias)));
    $paginas = [];
    foreach (escQ('SELECT posto_id, pagina FROM ' . escT('esc_posto_pagina') . ' WHERE posto_id IN (' . escIn($ids) . ')', $ids) as $g) {
        $paginas[(int) $g['posto_id']][] = $g['pagina'];
    }
    $pessoas = []; // [unidade|turno][rep] = [...]
    foreach (escQ('SELECT UPPER(rep_code) AS rep, unidade, turno, nucleo_id, nivel, fatia_regular FROM ' . escT('esc_colaborador_config')
        . " WHERE funcao = 'EQUITE' AND nucleo_id IS NOT NULL AND" . $vig . ' ORDER BY vigente_desde', [$ref, $ref]) as $c) {
        $pessoas[$c['unidade'] . '|' . $c['turno']][$c['rep']] = ['nucleo' => (int) $c['nucleo_id'], 'nivel' => (int) $c['nivel'],
            'cap' => escEquipeFatiaNivel($c['fatia_regular'] !== null ? (float) $c['fatia_regular'] : null, (int) $c['nivel']), 'uso' => 0.0];
    }
    $todos = [];
    foreach ($pessoas as $ut => $lista) {
        foreach (array_keys($lista) as $rep) {
            $pessoas[$ut][$rep]['uso'] = $uso[$rep] ?? 0.0;
            $todos[] = $rep;
        }
    }
    $hist = [];
    if ($todos) {
        foreach (escQ('SELECT UPPER(codigo) AS rep, modeloNome AS pagina, COUNT(DISTINCT LEFT(shiftDateAndHour, 10)) AS dias FROM `5c_schedule`.`scheduleday`
                        WHERE codigo IN (' . escIn($todos) . ') AND shiftDateAndHour >= ? GROUP BY UPPER(codigo), modeloNome',
            array_merge($todos, [escMaisDias($ref, -56) . 'T00:00:00'])) as $h) {
            $hist[$h['rep']][$h['pagina']] = (int) $h['dias'];
        }
    }
    $notas = [];
    try {
        foreach (escQ('SELECT UPPER(rep_code) AS rep, nota FROM ' . escT('esc_sr_merito')) as $m) {
            $notas[$m['rep']] = $m['nota'] !== null ? (float) $m['nota'] : null;
        }
    } catch (Throwable $e) {
        // sem mérito: só o histórico decide
    }
    // Ordem das vagas (como no montador): dono antes de cobertura; depois a vaga em que alguém do
    // núcleo conhece as páginas (senão quem conhece uma página é gasto noutra); depois a carga.
    $conhecido = static function (array $v) use ($pessoas, $paginas, $hist): int {
        $melhor = 0;
        foreach ($pessoas[$v['posto']['unidade'] . '|' . $v['posto']['turno']] ?? [] as $rep => $c) {
            if ($c['nucleo'] === $v['nucleo']) {
                $d = 0;
                foreach ($paginas[(int) $v['posto']['id']] ?? [] as $pg) {
                    $d += $hist[$rep][$pg] ?? 0;
                }
                $melhor = max($melhor, $d);
            }
        }
        return $melhor;
    };
    foreach ($vazias as &$v) {
        $v['ordem'] = [$v['vaga'] === 'DONO' ? 0 : 1, -$conhecido($v), -(float) $v['posto']['carga'], (int) $v['posto']['id']];
    }
    unset($v);
    usort($vazias, static fn($a, $b) => $a['ordem'] <=> $b['ordem']);

    $feito = [];
    $gravar = static function () use ($vazias, $paginas, &$pessoas, $hist, $notas, $desde, &$feito) {
        foreach ($vazias as $v) {
            $p = $v['posto'];
            $ut = $p['unidade'] . '|' . $p['turno'];
            $escolha = escEquipeEscolher($pessoas[$ut] ?? [], ['nucleo' => $v['nucleo'], 'precisa' => $v['precisa'], 'paginas' => $paginas[(int) $p['id']] ?? []], $hist, $notas);
            if (!$escolha) {
                $feito[] = ['posto' => (int) $p['id'], 'vaga' => $v['vaga'], 'reps' => [], 'paginas' => $paginas[(int) $p['id']] ?? []];
                continue;
            }
            $de = max($desde, substr((string) $p['vigente_desde'], 0, 10));
            $ate = $p['vigente_ate'] !== null ? substr((string) $p['vigente_ate'], 0, 10) : null;
            $reps = [];
            foreach ($escolha as $ordem => [$rep, $f, $passo]) {
                $pessoas[$ut][$rep]['uso'] += $f;
                $motivo = ['', 'do núcleo, dentro da fatia', 'de outro núcleo do turno, dentro da fatia', 'nível 0–2 acima da fatia, por falta de gente', 'ninguém com folga no turno: o menos carregado'][$passo];
                $dias = 0;
                foreach ($paginas[(int) $p['id']] ?? [] as $pg) {
                    $dias += $hist[$rep][$pg] ?? 0;
                }
                escW('INSERT INTO ' . escT('esc_vaga_equipe') . " (posto_id, vaga, rep_code, fatia, ordem, status, motivo, vigente_desde, vigente_ate, origem) VALUES (?, ?, ?, ?, ?, 'PROPOSTA', ?, ?, ?, 'MONTADOR')",
                    [(int) $p['id'], $v['vaga'], $rep, min(1.0, round($f / $v['precisa'], 3)), $ordem + 1,
                     mb_substr('vaga sem equipe, completada antes de gerar: ' . $motivo . ($dias ? ', ' . min($dias, 99) . ' dias nas páginas em 8 semanas' : ', sem histórico nas páginas'), 0, 255), $de, $ate]);
                $reps[] = $rep;
            }
            $feito[] = ['posto' => (int) $p['id'], 'vaga' => $v['vaga'], 'reps' => $reps, 'paginas' => $paginas[(int) $p['id']] ?? []];
        }
    };
    escDbW()->inTransaction() ? $gravar() : escTx($gravar); // dentro da saída de página já há transação
    $ok = array_values(array_filter($feito, static fn($f) => $f['reps']));
    if ($ok) {
        escLog('equipe_completada', 'esc_vaga_equipe', null, null, $ok);
    }
    return $feito;
}

// Texto curto para a mensagem depois de gerar: quantas vagas vazias foram completadas.
function escEquipesAviso(): string {
    $feito = $GLOBALS['ESC_EQUIPES_AUTO'] ?? [];
    $ok = count(array_filter($feito, static fn($f) => $f['reps']));
    $sem = count($feito) - $ok;
    $txt = $ok ? ' ' . tn($ok, '{n} vaga sem equipe foi completada (Páginas › Owners e backups).', '{n} vagas sem equipe foram completadas (Páginas › Owners e backups).') : '';
    return $txt . ($sem ? ' ' . tn($sem, '{n} vaga ficou sem equipe: não há SR no turno da unidade.', '{n} vagas ficaram sem equipe: não há SR no turno da unidade.') : '');
}

// Postos vigentes em $dia sem ninguém na vaga de dono ou de cobertura (para a tela Gerar).
function escEquipesVazias(string $dia): int {
    $vig = static fn(string $a): string => " {$a}.vigente_desde <= ? AND ({$a}.vigente_ate IS NULL OR {$a}.vigente_ate >= ?)";
    $sem = static fn(string $vaga): string => 'NOT EXISTS (SELECT 1 FROM ' . escT('esc_vaga_equipe') . " e WHERE e.posto_id = p.id AND e.vaga = '{$vaga}' AND" . $vig('e') . ')';
    $r = escQ1('SELECT COUNT(*) AS n FROM ' . escT('esc_posto') . ' p WHERE' . $vig('p') . ' AND (' . $sem('DONO') . ' OR (p.nucleo_cobertura_id IS NOT NULL AND ' . $sem('COBERTURA') . '))',
        [$dia, $dia, $dia, $dia, $dia, $dia]);
    return (int) ($r['n'] ?? 0);
}
