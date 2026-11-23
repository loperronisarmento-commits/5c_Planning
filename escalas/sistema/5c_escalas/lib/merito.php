<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/merito.php
// Mérito dos SRs: nota em pontos (1.19.0, regras M-01 a M-09 de contexto/regras_merito.md),
// a soma dos componentes, na janela de merito_janela_dias (28) dias até ontem:
// - M-02 tier da página principal (a de mais horas no 5C Schedule, regular ou assistente):
//   F 0, E 1, D 2, C 3, B 5, A/S/SS 8. Tier pelo faturamento da página na janela, levado a 30 dias.
// - M-03 meta: realizado ÷ meta 5CReps (Intraday), rateado pelas mensagens do SR na página no
//   turno (cap_rep_hora), como o 5C George. 100% = 0; ±1 a cada 10%, cortado em direção ao zero; ±10.
// - M-04 faltas: dias com falta (abs_adh_af ABS, já sem a falha administrativa): −2^(x−1), x até 5.
// - M-05 SLA (respostas em até 5 min, sem fc_sla_hora_excluida): 0 com 80% ou mais; a pena dobra a
//   cada 10% abaixo: −1, −2, −4, −8, −16.
// - M-06 tempo de casa: 1 por ano completo (headcount.startContract); também desempata.
// - M-07 venda por hora (cap_rep_hora): 0 a 4 pelo quintil entre os SRs com dado.
// - M-08 maior cargo da estrutura antiga (historic_members_planner até 31/10): 0, 1, 3 ou 5.
// - M-09 infrações (esc_sr_infraction): −2 por infração dos últimos 90 dias.
// - M-10 sequência de semanas fechadas (segunda a domingo, as últimas 4) batendo 100% da meta, da
//   última para trás: 1 semana +1, 2 +3, 3 ou mais +5. Semana sem dado é pulada; abaixo da meta encerra.
// Sem dado num componente, ele vale 0. Faixa: Acima com 8 ou mais, Abaixo com menos de −8.
// Fica em cache (esc_sr_merito) e é recalculado a cada merito_cache_horas.
// ===============================================

// Parâmetros da nota em pontos (grupo "merito", prefixo merito_pts_).
function escMeritoPtsParam(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $f = static fn(string $k, string $d): float => (float) str_replace(',', '.', escV4Param($k, $d));
    $mapa = static function (string $txt): array {
        $out = [];
        foreach (explode(',', $txt) as $par) {
            $p = explode(':', $par, 2);
            if (count($p) === 2 && trim($p[0]) !== '') {
                $out[mb_strtoupper(trim($p[0]))] = (float) str_replace(',', '.', trim($p[1]));
            }
        }
        return $out;
    };
    $cache = [
        'tier' => $mapa(escV4Param('merito_pts_tier', 'F:0,E:1,D:2,C:3,B:5,A:8,S:8,SS:8')),
        'meta_passo' => max(0.01, $f('merito_pts_meta_passo', '0.10')),
        'meta_limite' => (int) $f('merito_pts_meta_limite', '10'),
        'falta_max_x' => max(1, (int) $f('merito_pts_falta_max_x', '5')),
        'sla_meta' => $f('merito_pts_sla_meta', '0.80'),
        'sla_faixa' => max(0.01, $f('merito_pts_sla_faixa', '0.10')),
        'sla_max_k' => max(1, (int) $f('merito_pts_sla_max_k', '5')),
        'ano' => $f('merito_pts_ano', '1'),
        'vendas_faixas' => max(2, (int) $f('merito_pts_vendas_faixas', '5')),
        // Pelo código do cargo no histórico (newRoleId): 1 = líder que era gerente do time, 2 e 3 = outros
        // líderes de party, 4 = segundo da party, 5 = SR do pool.
        'cargo' => $mapa(escV4Param('merito_pts_cargo_antigo', '1:5,2:3,3:3,4:1,5:0')),
        'cargo_ate' => escV4Param('merito_pts_cargo_ate', '2026-10-31'),
        'infracao' => $f('merito_pts_infracao', '2'),
        'infracao_dias' => max(1, (int) $f('merito_pts_infracao_dias', '90')),
        'seq_semanas' => max(1, (int) $f('merito_pts_seq_semanas', '4')),
        'seq_meta' => $f('merito_pts_seq_meta', '1.00'),
        'seq' => $mapa(escV4Param('merito_pts_seq', '1:1,2:3,3:5')),
        'acima' => $f('merito_pts_faixa_acima', '8'),
        'abaixo' => $f('merito_pts_faixa_abaixo', '-8'),
    ];
    return $cache;
}

// M-03: 1 ponto a cada meta_passo (10%) acima ou abaixo de 100%, cortado em direção ao zero.
function escMeritoPtsMeta(?float $desempenho, array $p): int {
    if ($desempenho === null) {
        return 0;
    }
    $x = round(($desempenho - 1) / $p['meta_passo'], 6);
    $v = (int) ($x < 0 ? ceil($x) : floor($x));
    return max(-$p['meta_limite'], min($p['meta_limite'], $v));
}

// M-04: −2^(x−1), com x = dias com falta, até falta_max_x.
function escMeritoPtsFaltas(int $dias, array $p): int {
    return $dias <= 0 ? 0 : -(2 ** (min($dias, $p['falta_max_x']) - 1));
}

// M-05: com k faixas de 10% abaixo da meta de SLA, −2^(k−1); até sla_max_k faixas.
function escMeritoPtsSla(?float $sla, array $p): int {
    if ($sla === null || $sla >= $p['sla_meta']) {
        return 0;
    }
    $k = max(1, min($p['sla_max_k'], (int) ceil(round(($p['sla_meta'] - $sla) / $p['sla_faixa'], 6))));
    return -(2 ** ($k - 1));
}

// M-10: pontos pela sequência de semanas batendo a meta; o último degrau vale "ou mais".
function escMeritoPtsSeq(int $semanas, array $p): int {
    if ($semanas <= 0 || !$p['seq']) {
        return 0;
    }
    $degraus = array_map('intval', array_keys($p['seq']));
    $d = min($semanas, max($degraus));
    while ($d > 0 && !isset($p['seq'][(string) $d])) {
        $d--;
    }
    return $d > 0 ? (int) round($p['seq'][(string) $d]) : 0;
}

// Faixa pelos pontos: ACIMA, MEDIA ou ABAIXO (sem nota = MEDIA).
function escMeritoFaixa(?float $pontos, ?array $p = null): string {
    $p = $p ?? escMeritoPtsParam();
    if ($pontos === null) {
        return 'MEDIA';
    }
    return $pontos >= $p['acima'] ? 'ACIMA' : ($pontos < $p['abaixo'] ? 'ABAIXO' : 'MEDIA');
}

// Rótulo e cor da faixa nas telas.
function escMeritoFaixaRotulo(string $faixa): array {
    return ['ACIMA' => [t('Acima'), 'verde'], 'ABAIXO' => [t('Abaixo'), 'laranja']][$faixa] ?? [t('Na média'), ''];
}

// Absenteísmo realizado de $de a $ate, por SR ($por = 'rep') ou por turno A/C/D ($por = 'turno'):
// blocos de falta ÷ blocos escalados, sem a falha administrativa (AF: escala dobrada ou
// fora do horário), que não conta contra o SR nem no numerador nem no denominador.
// Fonte oficial: 5c_wfm_db_mis.abs_adh_af, a classificação do Capacity. Se a leitura falhar,
// o 5C Schedule por SR e horário: o bloco só é falta quando faltou em todas as alocações do
// horário. O "faltou" cru conta cada página (o mesmo bloco várias vezes) e marca falta na
// página dobrada mesmo com o SR trabalhando na outra; contava ~2× a taxa real.
// Devolve ['taxa' => [chave => taxa], 'fonte' => 'oficial' | 'schedule'].
function escAbsRealizado(string $por, string $de, string $ate, array $reps = []): array {
    $reps = array_values(array_unique(array_map('strtoupper', $reps)));
    $de = escCorteDados($de);
    $filtro = $reps ? ' AND codigo IN (' . escIn($reps) . ')' : '';
    $taxa = [];
    try {
        $chave = $por === 'turno' ? "CASE WHEN hora IN (5, 9) THEN 'A' WHEN hora IN (13, 17) THEN 'C' ELSE 'D' END" : 'UPPER(codigo)';
        foreach (escQ("SELECT {$chave} AS k, SUM(classificacao <> 'AF') AS blocos, SUM(classificacao = 'ABS') AS faltas
                         FROM `5c_wfm_db_mis`.`abs_adh_af` WHERE dia >= ? AND dia <= ?{$filtro} GROUP BY k", array_merge([$de, $ate], $reps)) as $r) {
            $taxa[$r['k']] = (int) $r['blocos'] > 0 ? (int) $r['faltas'] / (int) $r['blocos'] : null;
        }
        return ['taxa' => $taxa, 'fonte' => 'oficial'];
    } catch (Throwable $e) {
        appLog('warning', 'Absenteísmo: abs_adh_af indisponível, usando o 5C Schedule por horário', ['erro' => $e->getMessage()]);
    }
    $chave = $por === 'turno' ? "CASE WHEN h IN ('05', '09') THEN 'A' WHEN h IN ('13', '17') THEN 'C' ELSE 'D' END" : 'rep';
    foreach (escQ("SELECT {$chave} AS k, COUNT(*) AS blocos, SUM(f) AS faltas
                     FROM (SELECT UPPER(codigo) AS rep, SUBSTRING(shiftDateAndHour, 12, 2) AS h, MIN(faltou = 1) AS f
                             FROM `5c_schedule`.`scheduleday` WHERE shiftDateAndHour >= ? AND shiftDateAndHour < ?{$filtro}
                            GROUP BY UPPER(codigo), shiftDateAndHour) AS s GROUP BY k",
        array_merge([$de . 'T00:00:00', escMaisDias($ate, 1) . 'T00:00:00'], $reps)) as $r) {
        $taxa[$r['k']] = (int) $r['blocos'] > 0 ? (int) $r['faltas'] / (int) $r['blocos'] : null;
    }
    return ['taxa' => $taxa, 'fonte' => 'schedule'];
}

// A fonte oficial do absenteísmo (abs_adh_af) está acessível para o usuário do sistema?
function escAbsOficialOk(): bool {
    static $ok = null;
    if ($ok === null) {
        try {
            escQ('SELECT 1 AS ok FROM `5c_wfm_db_mis`.`abs_adh_af` LIMIT 1');
            $ok = true;
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

// O Capacity já grava as horas fora do SLA (script 23)?
function escMeritoSlaExcluidaOk(): bool {
    static $ok = null;
    if ($ok === null) {
        $ok = (bool) escQ1("SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = '5c_planning' AND TABLE_NAME = 'fc_sla_hora_excluida'");
    }
    return $ok;
}

// Calcula o mérito dos reps informados (códigos) com a janela que termina ontem.
function escMeritoCalcular(array $reps): array {
    $reps = array_values(array_unique(array_map('strtoupper', $reps)));
    if (!$reps) {
        return [];
    }
    $n = max(7, (int) escV4Param('merito_janela_dias', '28'));
    $ate = date('Y-m-d', strtotime('-1 day'));
    $de = escCorteDados(date('Y-m-d', strtotime('-' . $n . ' day')));
    $teto = (float) str_replace(',', '.', escV4Param('merito_desempenho_teto', '2'));
    $pts = escMeritoPtsParam();
    // M-10: as últimas seq_semanas semanas fechadas (segunda a domingo), que podem começar antes da janela.
    $segAtual = segundaDaSemana(date('Y-m-d'));
    $ateSem = escMaisDias($segAtual, -1);
    $deSem = escCorteDados(escMaisDias($segAtual, -7 * $pts['seq_semanas']));
    $deDados = min($de, $deSem);

    // Código do Infloww (prefixo do remetente, ex.: "SRJ70") → código do SR.
    $repDe = [];
    foreach (escQ('SELECT UPPER(rep_code) AS rep, code_infloww FROM `5c_wfm_db_mis`.`vw_rep_estrutura_atual` WHERE code_infloww IS NOT NULL AND code_infloww <> \'\' AND rep_code IN (' . escIn($reps) . ')', $reps) as $r) {
        $repDe[(string) $r['code_infloww']] = $r['rep'];
    }

    // Meta e realizado 5CReps por dia, turno (T1–T6) e página.
    $pag = [];
    foreach (escQ('SELECT Date AS dia, Turn AS t, Page AS pagina, SUM(`Goal 5CReps`) AS meta, SUM(`5CReps`) AS real5
                     FROM `5c_wfm_db_mis`.`tab_intraday_base` WHERE Date >= ? AND Date <= ? GROUP BY Date, Turn, Page', [$deDados, $ate]) as $r) {
        $pag[substr((string) $r['dia'], 0, 10) . '|' . $r['t'] . '|' . $r['pagina']] = [(float) $r['meta'], (float) $r['real5']];
    }

    // Mensagens de todos os remetentes por dia, turno e página (as de quem atende
    // mais de uma página no mesmo horário são divididas igualmente entre elas).
    $msgs = [];     // [dia|t|pagina][cod] = mensagens
    $tot = [];      // [dia|t|pagina] = mensagens de todos
    $sla = [];      // [cod] = [respostas, em até 5 min, horas]
    $venda = [];    // [cod] = [US$ comprado, horas] (M-07)
    // SLA justo (Capacity, script 23): as horas em que o posto forçado pela regra de faturamento
    // passou da capacidade (fc_sla_hora_excluida, última versão do dia) não entram no SLA.
    // LOCATE em vez de FIND_IN_SET(REPLACE(...)): a trava de leitura (escQ) bloqueia a palavra REPLACE.
    $excl = escMeritoSlaExcluidaOk()
        ? "EXISTS (SELECT 1 FROM `5c_planning`.`fc_sla_hora_excluida` x WHERE x.dia = c.dia AND x.hora = c.hora AND LOCATE(CONCAT(' + ', x.pagina, ' + '), CONCAT(' + ', c.paginas, ' + ')) > 0
                     AND x.versao = (SELECT MAX(x2.versao) FROM `5c_planning`.`fc_sla_hora_excluida` x2 WHERE x2.dia = x.dia))"
        : '0';
    foreach (escQ("SELECT dia, turno AS t, SUBSTRING_INDEX(rep, ' ', 1) AS cod, paginas, n_paginas, SUM(msgs_rep) AS m,
                          SUM(IF(excl, 0, respostas)) AS resp, SUM(IF(excl, 0, resp_5min)) AS r5, SUM(1 - excl) AS horas,
                          SUM(valor_comprado) AS v, COUNT(*) AS hh
                     FROM (SELECT c.*, " . $excl . " AS excl FROM `5c_capacity`.`cap_rep_hora` c WHERE c.dia >= ? AND c.dia <= ?) h
                    GROUP BY dia, turno, cod, paginas, n_paginas", [$deDados, $ate]) as $r) {
        $cod = (string) $r['cod'];
        $lista = array_values(array_filter(array_map('trim', explode(' + ', (string) $r['paginas']))));
        $parte = (float) $r['m'] / max(1, count($lista));
        foreach ($lista as $p) {
            $k = substr((string) $r['dia'], 0, 10) . '|' . $r['t'] . '|' . $p;
            $tot[$k] = ($tot[$k] ?? 0) + $parte;
            if (isset($repDe[$cod])) {
                $msgs[$k][$cod] = ($msgs[$k][$cod] ?? 0) + $parte;
            }
        }
        if (isset($repDe[$cod]) && substr((string) $r['dia'], 0, 10) >= $de) {
            $s = $sla[$cod] ?? [0, 0, 0];
            $sla[$cod] = [$s[0] + (int) $r['resp'], $s[1] + (int) $r['r5'], $s[2] + (int) $r['horas']];
            $vd = $venda[$cod] ?? [0.0, 0];
            $venda[$cod] = [$vd[0] + (float) $r['v'], $vd[1] + (int) $r['hh']];
        }
    }
    $metaRep = [];
    $realRep = [];
    $semana = []; // [cod][segunda] = [realizado, meta] (M-10)
    $segDe = [];
    foreach ($msgs as $k => $porCod) {
        // Página sem meta no Intraday (ex.: página nova, ainda com Goal 0) fica fora da meta e do realizado:
        // senão o realizado dela entra sem meta e infla a M-03 e a M-10 (1.32.2).
        if (!isset($pag[$k]) || empty($tot[$k]) || $pag[$k][0] <= 0) {
            continue;
        }
        [$meta, $real] = $pag[$k];
        $dia = substr($k, 0, 10);
        foreach ($porCod as $cod => $m) {
            $share = $m / $tot[$k];
            if ($dia >= $de) {
                $metaRep[$cod] = ($metaRep[$cod] ?? 0) + $share * $meta;
                $realRep[$cod] = ($realRep[$cod] ?? 0) + $share * $real;
            }
            if ($dia >= $deSem && $dia <= $ateSem) {
                $sg = $segDe[$dia] ?? ($segDe[$dia] = segundaDaSemana($dia));
                $semana[$cod][$sg] = [($semana[$cod][$sg][0] ?? 0) + $share * $real, ($semana[$cod][$sg][1] ?? 0) + $share * $meta];
            }
        }
    }

    // Absenteísmo sem a falha administrativa (abs_adh_af; se falhar, o 5C Schedule por horário).
    $abs = escAbsRealizado('rep', $de, $ate, $reps)['taxa'];

    $codDe = array_flip($repDe);

    // M-04: dias com falta (abs_adh_af já sem a falha administrativa, regra da AF v2).
    $faltas = [];
    try {
        foreach (escQ("SELECT UPPER(codigo) AS rep, COUNT(DISTINCT dia) AS dias FROM `5c_wfm_db_mis`.`abs_adh_af`
                        WHERE dia >= ? AND dia <= ? AND classificacao = 'ABS' AND codigo IN (" . escIn($reps) . ') GROUP BY UPPER(codigo)',
            array_merge([$de, $ate], $reps)) as $r) {
            $faltas[$r['rep']] = (int) $r['dias'];
        }
    } catch (Throwable $e) {
        appLog('warning', 'Mérito: faltas indisponíveis (abs_adh_af)', ['erro' => $e->getMessage()]);
    }

    // M-02: página principal (mais horas no 5C Schedule na janela, regular ou assistente, sem a página do
    // Overlap; no empate, a que mais fatura) e o tier dela pelo faturamento da janela levado a 30 dias.
    $horasPag = [];
    $receitaPag = [];
    $tiers = [];
    try {
        foreach (escQ('SELECT UPPER(codigo) AS rep, modeloNome AS pagina, COUNT(*) AS n FROM `5c_schedule`.`scheduleday`
                        WHERE shiftDateAndHour >= ? AND shiftDateAndHour < ? AND modeloNome <> ? AND codigo IN (' . escIn($reps) . ')
                        GROUP BY UPPER(codigo), modeloNome',
            array_merge([$de . 'T00:00:00', escMaisDias($ate, 1) . 'T00:00:00', escV4Param('v4_overlap_pagina', '5C Schedule')], $reps)) as $r) {
            $horasPag[$r['rep']][(string) $r['pagina']] = (int) $r['n'];
        }
        foreach (escQ("SELECT CREATOR_NAME AS pagina, SUM(US_AMOUNT) AS v FROM `5c_wfm_db_mis`.`tab_statement_interval`
                        WHERE Date >= ? AND Date <= ? AND STATUS IN ('complete', 'pending') GROUP BY CREATOR_NAME", [$de, $ate]) as $r) {
            $k = escV4ChavePagina((string) $r['pagina']);
            $receitaPag[$k] = ($receitaPag[$k] ?? 0) + (float) $r['v'] * 30 / $n;
        }
        $tiers = escQ('SELECT letter, min_value FROM `5c_crm`.`tiers` ORDER BY min_value DESC');
    } catch (Throwable $e) {
        appLog('warning', 'Mérito: tier da página indisponível', ['erro' => $e->getMessage()]);
    }
    $tierDe = static function (float $v) use ($tiers): ?string {
        foreach ($tiers as $t) {
            if ($v >= (float) $t['min_value']) {
                return strtoupper(trim((string) $t['letter']));
            }
        }
        return null;
    };

    // M-06: anos completos de casa (só o código e o início do contrato do headcount).
    $anos = [];
    try {
        $hoje = new DateTime(date('Y-m-d'));
        foreach (escQ('SELECT UPPER(code) AS rep, startContract AS ini FROM `5c_headcount`.`headcount` WHERE code IN (' . escIn($reps) . ')', $reps) as $r) {
            $d = DateTime::createFromFormat('!d/m/Y', trim((string) $r['ini']));
            if ($d && $d <= $hoje) {
                $anos[$r['rep']] = max($anos[$r['rep']] ?? 0, (int) $d->diff($hoje)->y);
            }
        }
    } catch (Throwable $e) {
        appLog('warning', 'Mérito: tempo de casa indisponível (headcount)', ['erro' => $e->getMessage()]);
    }

    // M-07: venda por hora; quintil entre os SRs com dado (o mesmo valor fica no mesmo quintil).
    $vendaH = [];
    foreach ($reps as $rep) {
        $cod = $codDe[$rep] ?? null;
        if ($cod !== null && !empty($venda[$cod][1])) {
            $vendaH[$rep] = $venda[$cod][0] / $venda[$cod][1];
        }
    }
    $ordem = array_values($vendaH);
    sort($ordem);
    $primeiro = [];
    foreach ($ordem as $i => $v) {
        $primeiro[(string) round($v, 6)] = $primeiro[(string) round($v, 6)] ?? $i;
    }
    $ptsVenda = [];
    foreach ($vendaH as $rep => $v) {
        $ptsVenda[$rep] = min($pts['vendas_faixas'] - 1, intdiv($pts['vendas_faixas'] * $primeiro[(string) round($v, 6)], max(1, count($ordem))));
    }

    // M-08: maior cargo que o SR já teve na estrutura antiga (eventos Added e Role Changed até cargo_ate).
    $ptsCargo = [];
    try {
        foreach (escQ("SELECT UPPER(repCode) AS rep, newRoleId AS papel FROM `5c_historic_planner`.`historic_members_planner`
                        WHERE changeType IN ('Added', 'Role Changed') AND `timestamp` < ? AND repCode IN (" . escIn($reps) . ') GROUP BY UPPER(repCode), newRoleId',
            array_merge([escMaisDias($pts['cargo_ate'], 1)], $reps)) as $r) {
            $v = $pts['cargo'][(string) (int) $r['papel']] ?? 0.0;
            $ptsCargo[$r['rep']] = max($ptsCargo[$r['rep']] ?? 0.0, $v);
        }
    } catch (Throwable $e) {
        appLog('warning', 'Mérito: histórico de cargos indisponível', ['erro' => $e->getMessage()]);
    }

    // M-09: infrações dos últimos infracao_dias dias (tabela prevista; vazia enquanto não houver carga).
    $nInf = [];
    try {
        foreach (escQ('SELECT UPPER(sr_code) AS rep, COUNT(*) AS n FROM ' . escT('esc_sr_infraction') . ' WHERE sr_code IN (' . escIn($reps) . ')
                         AND COALESCE(applied_date, occurrency_date) >= ? GROUP BY UPPER(sr_code)',
            array_merge($reps, [escCorteDados(date('Y-m-d', strtotime('-' . $pts['infracao_dias'] . ' day')))])) as $r) {
            $nInf[$r['rep']] = (int) $r['n'];
        }
    } catch (Throwable $e) {
        appLog('warning', 'Mérito: tabela de infrações indisponível', ['erro' => $e->getMessage()]);
    }

    $saida = [];
    foreach ($reps as $rep) {
        $cod = $codDe[$rep] ?? null;
        $s = $cod !== null ? ($sla[$cod] ?? null) : null;
        // Teto no desempenho: com meta muito pequena (poucas horas em horário fraco) a razão explode.
        $des = $cod !== null && !empty($metaRep[$cod]) ? min($teto, $realRep[$cod] / $metaRep[$cod]) : null;
        $sl = $s !== null && $s[0] > 0 ? $s[1] / $s[0] : null;
        $principal = null;
        $hPrincipal = -1;
        foreach ($horasPag[$rep] ?? [] as $pg => $h) {
            $pg = (string) $pg;
            $rec = $receitaPag[escV4ChavePagina($pg)] ?? 0.0;
            if ($h > $hPrincipal || ($h === $hPrincipal && $rec > ($receitaPag[escV4ChavePagina((string) $principal)] ?? 0.0))) {
                $principal = $pg;
                $hPrincipal = $h;
            }
        }
        // M-10: da última semana fechada para trás; semana sem dado é pulada, abaixo da meta encerra.
        $seq = 0;
        for ($sg = escMaisDias($segAtual, -7); $sg >= $deSem; $sg = escMaisDias($sg, -7)) {
            $x = $cod !== null ? ($semana[$cod][$sg] ?? null) : null;
            if ($x === null || $x[1] <= 0) {
                continue;
            }
            if ($x[0] / $x[1] < $pts['seq_meta']) {
                break;
            }
            $seq++;
        }
        $tier = $principal !== null ? $tierDe($receitaPag[escV4ChavePagina($principal)] ?? 0.0) : null;
        $comp = [
            'pts_tier' => $tier !== null ? (int) round($pts['tier'][$tier] ?? 0) : 0,
            'pts_meta' => escMeritoPtsMeta($des, $pts),
            'pts_faltas' => escMeritoPtsFaltas($faltas[$rep] ?? 0, $pts),
            'pts_sla' => escMeritoPtsSla($sl, $pts),
            'pts_casa' => (int) floor(($anos[$rep] ?? 0) * $pts['ano']),
            'pts_vendas' => (int) ($ptsVenda[$rep] ?? 0),
            'pts_cargo' => (int) round($ptsCargo[$rep] ?? 0),
            'pts_infracao' => -(int) round(($nInf[$rep] ?? 0) * $pts['infracao']),
            'pts_seq' => escMeritoPtsSeq($seq, $pts),
        ];
        $total = (float) array_sum($comp);
        $saida[$rep] = ['desempenho' => $des, 'sla' => $sl, 'absenteismo' => $abs[$rep] ?? null, 'pena' => 0.0, 'infracoes' => $nInf[$rep] ?? 0,
            'nota' => $total, 'horas' => $s[2] ?? 0, 'respostas' => $s[0] ?? 0, 'janela_de' => $de, 'janela_ate' => $ate]
            + $comp + ['faixa' => escMeritoFaixa($total, $pts), 'faltas' => $faltas[$rep] ?? 0, 'tier' => $tier,
                'pagina_principal' => $principal !== null ? mb_substr($principal, 0, 120) : null,
                'venda_h' => isset($vendaH[$rep]) ? round($vendaH[$rep], 2) : null, 'anos' => $anos[$rep] ?? 0, 'semanas_meta' => $seq];
    }
    return $saida;
}

// Atingimento de cada rep na MODELO (pedido de 11/11): soma o realizado e a meta de todas as páginas abertas
// da modelo das $paginas (escModeloPaginasAbertas). Volta [modelo][rep][i] = [realizado, meta].
function escMeritoAtingimentoModelos(array $paginas, array $reps, array $periodos): array {
    $abertas = escModeloPaginasAbertas();
    $todas = [];
    foreach ($paginas as $p) {
        $todas[(string) $p] = true;
        foreach ($abertas[escModeloDaPagina((string) $p)] ?? [] as $irma) {
            $todas[$irma] = true;
        }
    }
    $out = [];
    foreach (escMeritoAtingimentoPaginas(array_keys($todas), $reps, $periodos) as $pg => $porRep) {
        $m = escModeloDaPagina((string) $pg);
        foreach ($porRep as $rep => $porPeriodo) {
            foreach ($porPeriodo as $i => [$real, $meta]) {
                $out[$m][$rep][$i] = [($out[$m][$rep][$i][0] ?? 0.0) + (float) $real, ($out[$m][$rep][$i][1] ?? 0.0) + (float) $meta];
            }
        }
    }
    return $out;
}

// Atingimento de cada rep em cada página, por período (PDF de metas da Grade): a parte do
// realizado e da meta 5CReps da página que cabe ao rep, pela fatia das mensagens no dia, bloco
// e página (a mesma regra do desempenho do mérito). $periodos = [[de, ate], ...], datas
// inclusivas; nada depois de ontem. Volta [pagina][rep][i] = [realizado, meta].
function escMeritoAtingimentoPaginas(array $paginas, array $reps, array $periodos): array {
    $reps = array_values(array_unique(array_map('strtoupper', $reps)));
    if (!$paginas || !$reps || !$periodos) {
        return [];
    }
    $ontem = date('Y-m-d', strtotime('-1 day'));
    $de = escCorteDados(min(array_column($periodos, 0)));
    $ate = min($ontem, max(array_column($periodos, 1)));
    if ($de > $ate) {
        return [];
    }
    $periodoDe = static function (string $dia) use ($periodos): ?int {
        foreach ($periodos as $i => [$a, $b]) {
            if ($dia >= $a && $dia <= $b) {
                return $i;
            }
        }
        return null;
    };
    // Os nomes de página às vezes vêm com espaço a mais ("Haven  Free").
    $chave = static fn(string $p): string => mb_strtolower(preg_replace('~\s+~u', ' ', trim($p)), 'UTF-8');
    $nomeDe = [];
    foreach ($paginas as $p) {
        $nomeDe[$chave($p)] = $p;
    }
    $repDe = [];
    foreach (escQ('SELECT UPPER(rep_code) AS rep, code_infloww FROM `5c_wfm_db_mis`.`vw_rep_estrutura_atual` WHERE code_infloww IS NOT NULL AND code_infloww <> \'\' AND rep_code IN (' . escIn($reps) . ')', $reps) as $r) {
        $repDe[(string) $r['code_infloww']] = $r['rep'];
    }
    $pag = [];
    foreach (escQ('SELECT Date AS dia, Turn AS t, Page AS pagina, SUM(`Goal 5CReps`) AS meta, SUM(`5CReps`) AS real5
                     FROM `5c_wfm_db_mis`.`tab_intraday_base` WHERE Date >= ? AND Date <= ? GROUP BY Date, Turn, Page', [$de, $ate]) as $r) {
        $p = $nomeDe[$chave((string) $r['pagina'])] ?? null;
        if ($p !== null) {
            $k = substr((string) $r['dia'], 0, 10) . '|' . $r['t'] . '|' . $p;
            $pag[$k] = [($pag[$k][0] ?? 0) + (float) $r['real5'], ($pag[$k][1] ?? 0) + (float) $r['meta']];
        }
    }
    $msgs = [];
    $tot = [];
    foreach (escQ("SELECT dia, turno AS t, SUBSTRING_INDEX(rep, ' ', 1) AS cod, paginas, SUM(msgs_rep) AS m
                     FROM `5c_capacity`.`cap_rep_hora` WHERE dia >= ? AND dia <= ? GROUP BY dia, turno, cod, paginas", [$de, $ate]) as $r) {
        $lista = array_values(array_filter(array_map('trim', explode(' + ', (string) $r['paginas']))));
        $parte = (float) $r['m'] / max(1, count($lista));
        $cod = (string) $r['cod'];
        foreach ($lista as $p) {
            $p = $nomeDe[$chave($p)] ?? null;
            if ($p === null) {
                continue;
            }
            $k = substr((string) $r['dia'], 0, 10) . '|' . $r['t'] . '|' . $p;
            $tot[$k] = ($tot[$k] ?? 0) + $parte;
            if (isset($repDe[$cod])) {
                $msgs[$k][$repDe[$cod]] = ($msgs[$k][$repDe[$cod]] ?? 0) + $parte;
            }
        }
    }
    $out = [];
    foreach ($msgs as $k => $porRep) {
        // Sem meta no Intraday, o realizado não entra (somado na modelo, inflaria o "bateu a meta").
        if (!isset($pag[$k]) || empty($tot[$k]) || $pag[$k][1] <= 0) {
            continue;
        }
        [$dia, , $p] = explode('|', $k, 3);
        $i = $periodoDe($dia);
        if ($i === null) {
            continue;
        }
        [$real, $meta] = $pag[$k];
        foreach ($porRep as $rep => $m) {
            $share = $m / $tot[$k];
            $out[$p][$rep][$i][0] = ($out[$p][$rep][$i][0] ?? 0) + $share * $real;
            $out[$p][$rep][$i][1] = ($out[$p][$rep][$i][1] ?? 0) + $share * $meta;
        }
    }
    return $out;
}

// SRs da estrutura nova (todos os vigentes ou que começam até a semana seguinte).
function escMeritoSrsAtivos(): array {
    $d = date('Y-m-d', strtotime('+14 day'));
    return array_column(escQ('SELECT DISTINCT UPPER(rep_code) AS rep FROM ' . escT('esc_colaborador_config')
        . " WHERE funcao = 'EQUITE' AND nucleo_id IS NOT NULL AND vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= CURDATE())", [$d]), 'rep');
}

// Mérito em cache; recalcula e grava se estiver velho (ou se $forcar).
function escMeritoCarregar(bool $forcar = false): array {
    $horas = max(1, (int) escV4Param('merito_cache_horas', '20'));
    try {
        $ult = escQ1('SELECT MAX(calculado_em) AS m FROM ' . escT('esc_sr_merito'));
    } catch (Throwable $e) {
        return []; // ES_16 ainda não rodou: sem mérito, o motor segue como antes
    }
    $velho = !$ult || $ult['m'] === null || strtotime((string) $ult['m']) < time() - $horas * 3600;
    // Cache ainda da fórmula antiga (antes da 1.19.0): sem a faixa, recalcula em pontos.
    if (!$velho && escQ1('SELECT 1 AS ok FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', [ESC_SCHEMA, 'esc_sr_merito', 'faixa'])) {
        $velho = !escQ1('SELECT 1 AS ok FROM ' . escT('esc_sr_merito') . ' WHERE faixa IS NOT NULL LIMIT 1');
    }
    // Desde a 1.34.0 o mérito é Legado quando o KPI é a fonte (v4_fonte_desempenho): o cache só recalcula forçado.
    $fonteMerito = !function_exists('escDesempenhoFonte') || escDesempenhoFonte() === 'MERITO';
    if ($forcar || ($velho && $fonteMerito && (PHP_SAPI === 'cli' || escPodeVerRestrito()))) {
        try {
            $calc = escMeritoCalcular(escMeritoSrsAtivos());
            $agora = date('Y-m-d H:i:s');
            // Só as colunas que o cache tem (as dos pontos vêm do ES_91; sem ele, grava só a nota).
            $cols = array_flip(array_column(escQ('SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [ESC_SCHEMA, 'esc_sr_merito']), 'c'));
            $linhas = [];
            foreach ($calc as $rep => $m) {
                $m['pena'] = round((float) $m['pena'], 4);
                $linhas[] = array_intersect_key(['rep_code' => $rep] + $m + ['calculado_em' => $agora], $cols);
            }
            if ($linhas) {
                escTx(static function () use ($linhas, $agora) {
                    escW('DELETE FROM `' . ESC_SCHEMA . '`.`esc_sr_merito` WHERE calculado_em < ?', [$agora]);
                    escInserirLote('esc_sr_merito', array_keys($linhas[0]), $linhas);
                });
            }
        } catch (Throwable $e) {
            appLog('error', 'Mérito: falha ao recalcular', ['erro' => $e->getMessage()]);
        }
    }
    $out = [];
    foreach (escQ('SELECT * FROM ' . escT('esc_sr_merito')) as $r) {
        $out[$r['rep_code']] = $r;
    }
    return $out;
}

// Nota em pontos de cada SR (sem nota = 0, como o novato da M-01).
function escMeritoNotasMotor(array $merito): array {
    $out = [];
    foreach ($merito as $rep => $m) {
        $out[$rep] = $m['nota'] !== null ? (float) $m['nota'] : 0.0;
    }
    return $out;
}

// O que o Gerar usa do mérito: a nota em pontos, a faixa (Acima = 2, Na média = 1, Abaixo = 0) e os
// anos de casa (desempate da M-06). Quem não está no cache conta como Na média, com 0 pontos.
function escMeritoMotor(array $merito): array {
    $p = escMeritoPtsParam();
    $valor = ['ACIMA' => 2, 'MEDIA' => 1, 'ABAIXO' => 0];
    $out = ['nota' => escMeritoNotasMotor($merito), 'faixa' => [], 'anos' => []];
    foreach ($merito as $rep => $m) {
        $out['faixa'][$rep] = $valor[$m['faixa'] ?? escMeritoFaixa($m['nota'] !== null ? (float) $m['nota'] : null, $p)] ?? 1;
        $out['anos'][$rep] = (int) ($m['anos'] ?? 0);
    }
    return $out;
}
