<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/solicitacao.php
// Solicitações do SR (pages/solicitacoes.php, tabela esc_solicitacao do ES_30):
// onde a pessoa está, quem serve de par para a troca e a troca 1 por 1. Na troca
// cada um vai para o núcleo do outro (configuração) e assume as vagas fixas do
// outro, a partir de uma data; nenhum posto fica sem dono. Mesmo núcleo: só as
// vagas trocam.
// ===============================================

const ESC_SOL_TIPOS = ['ASSISTENTE', 'DUPLA', 'TURNO', 'NUCLEO', 'UNIDADE', 'OUTRO'];
const ESC_SOL_TURNO_BLOCOS = ['D' => 'T6/T1', 'A' => 'T2/T3', 'C' => 'T4/T5'];
// Tipos de posto que atendem cada pedido (em todos, a pessoa não fica sozinha na página).
const ESC_SOL_POSTOS = ['ASSISTENTE' => ['assistente', 'apoio'], 'DUPLA' => ['double', 'assistente', 'apoio']];

function escSolInstalada(): bool {
    static $ok = null;
    if ($ok === null) {
        $ok = (bool) escQ1("SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'esc_solicitacao'", [ESC_SCHEMA]);
    }
    return $ok;
}

// Primeiro dia que uma troca pode valer: amanhã, nunca antes da estrutura nova.
function escSolPrimeiroDia(): string {
    return max(escMaisDias(date('Y-m-d'), 1), escV4DiaEstrutura());
}

// Onde cada SR e OS está no dia: núcleo, unidade, turno, nível e os tipos dos postos das vagas fixas.
// [rep => ['rep', 'funcao', 'nivel', 'nucleo_id', 'unidade', 'turno', 'numero', 'os', 'vagas' => [tipo => n], 'paginas' => [pagina => true]]]
function escSolLugares(string $dia): array {
    $vig = ' vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)';
    $lug = [];
    foreach (escQ('SELECT UPPER(c.rep_code) AS rep, c.funcao, c.nivel, c.nucleo_id, n.unidade, n.turno, n.numero, n.decurio_nome AS os
                     FROM ' . escT('esc_colaborador_config') . ' c JOIN ' . escT('esc_nucleo') . ' n ON n.id = c.nucleo_id
                    WHERE c.vigente_desde <= ? AND (c.vigente_ate IS NULL OR c.vigente_ate >= ?)', [$dia, $dia]) as $l) {
        $lug[$l['rep']] = ['rep' => $l['rep'], 'funcao' => $l['funcao'], 'nivel' => $l['nivel'] !== null ? (int) $l['nivel'] : null, 'nucleo_id' => (int) $l['nucleo_id'],
            'unidade' => (int) $l['unidade'], 'turno' => $l['turno'], 'numero' => (int) $l['numero'], 'os' => (string) $l['os'], 'vagas' => [], 'paginas' => []];
    }
    foreach (escQ('SELECT UPPER(v.rep_code) AS rep, p.tipo, COUNT(*) AS n FROM ' . escT('esc_vaga_equipe') . ' v JOIN ' . escT('esc_posto') . ' p ON p.id = v.posto_id
                    WHERE v.vigente_desde <= ? AND (v.vigente_ate IS NULL OR v.vigente_ate >= ?) GROUP BY UPPER(v.rep_code), p.tipo', [$dia, $dia]) as $v) {
        if (isset($lug[$v['rep']])) {
            $lug[$v['rep']]['vagas'][$v['tipo']] = (int) $v['n'];
        }
    }
    // Páginas das vagas fixas (para achar quem já trabalha com a pessoa pedida na dupla ou assistência).
    foreach (escQ('SELECT DISTINCT UPPER(v.rep_code) AS rep, pp.pagina FROM ' . escT('esc_vaga_equipe') . ' v JOIN ' . escT('esc_posto_pagina') . ' pp ON pp.posto_id = v.posto_id
                    WHERE v.vigente_desde <= ? AND (v.vigente_ate IS NULL OR v.vigente_ate >= ?)', [$dia, $dia]) as $v) {
        if (isset($lug[$v['rep']])) {
            $lug[$v['rep']]['paginas'][$v['pagina']] = true;
        }
    }
    return $lug;
}

// "U3 · T6/T1 · núcleo 2" (+ nível ou OS).
function escSolLugarTexto(?array $l, bool $nivel = true): string {
    if (!$l) {
        return t('sem núcleo');
    }
    return 'U' . $l['unidade'] . ' · ' . (ESC_SOL_TURNO_BLOCOS[$l['turno']] ?? $l['turno']) . ' · ' . t('núcleo {n}', ['n' => $l['numero']])
        . ($nivel ? ' · ' . ($l['funcao'] === 'DECURIO' ? 'OS' : 'SR ' . ($l['nivel'] ?? '?')) : '');
}

// "2 titular · 1 dupla" das vagas fixas.
function escSolVagasTexto(array $vagas): string {
    if (!$vagas) {
        return t('sem vaga fixa');
    }
    $rot = ['titular' => t('titular'), 'double' => t('dupla'), 'apoio' => t('apoio'), 'assistente' => t('assistente')];
    $out = [];
    foreach ($vagas as $tipo => $n) {
        $out[] = $n . ' ' . ($rot[$tipo] ?? $tipo);
    }
    return implode(' · ', $out);
}

// O que a pessoa quer, em texto ("trabalhar como assistente", "ir para U2 · T4/T5"...).
function escSolDestinoTexto(array $s, array $nucleos = []): string {
    switch ($s['tipo']) {
        case 'ASSISTENTE':
        case 'DUPLA':
            $base = $s['tipo'] === 'ASSISTENTE' ? t('trabalhar como assistente (não sozinho)') : t('trabalhar em dupla ou como assistente (não sozinho)');
            $alvo = strtoupper((string) ($s['destino_rep'] ?? ''));
            $n = $nucleos[(int) ($s['destino_nucleo_id'] ?? 0)] ?? null;
            if ($alvo !== '') {
                $base .= ' · ' . t($s['tipo'] === 'ASSISTENTE' ? 'assistente de {pessoa}' : 'em dupla com {pessoa}', ['pessoa' => nomeCurto(escNomes()[$alvo] ?? $alvo)]);
            }
            if ($n) {
                $base .= ' · U' . $n['unidade'] . ' · ' . (ESC_SOL_TURNO_BLOCOS[$n['turno']] ?? $n['turno']) . ' · ' . t('núcleo {n}', ['n' => $n['numero']]) . ' (OS ' . nomeCurto($n['decurio_nome']) . ')';
            }
            return $base;
        case 'TURNO':
            return t('ir para o turno {turno}', ['turno' => ESC_SOL_TURNO_BLOCOS[$s['destino_turno']] ?? (string) $s['destino_turno']]) . ($s['destino_unidade'] ? ' · U' . (int) $s['destino_unidade'] : '');
        case 'UNIDADE':
            return t('ir para a unidade {u}', ['u' => (int) $s['destino_unidade']]) . ($s['destino_turno'] ? ' · ' . (ESC_SOL_TURNO_BLOCOS[$s['destino_turno']] ?? '') : '');
        case 'NUCLEO':
            $n = $nucleos[(int) $s['destino_nucleo_id']] ?? null;
            return $n ? t('ir para o núcleo {nucleo}', ['nucleo' => 'U' . $n['unidade'] . ' · ' . (ESC_SOL_TURNO_BLOCOS[$n['turno']] ?? $n['turno']) . ' · ' . $n['numero'] . ' (OS ' . nomeCurto($n['decurio_nome']) . ')']) : t('mudar de núcleo');
    }
    return t('outro pedido');
}

// O lugar $l atende o destino da solicitação $s?
function escSolAtende(array $s, array $l, ?array $origem): bool {
    switch ($s['tipo']) {
        case 'ASSISTENTE':
        case 'DUPLA':
            // No lugar pedido (unidade e turno do núcleo ou da pessoa); sem pedido, onde a pessoa está.
            $alvo = $s['_alvo'] ?? $origem;
            return $alvo && $l['unidade'] === $alvo['unidade'] && $l['turno'] === $alvo['turno']
                && (array_intersect(array_keys($l['vagas']), ESC_SOL_POSTOS[$s['tipo']]) || (!empty($s['_paginas_alvo']) && array_intersect_key($l['paginas'], $s['_paginas_alvo'])));
        case 'TURNO':
            return $l['turno'] === $s['destino_turno'] && $l['unidade'] === (int) ($s['destino_unidade'] ?: ($origem['unidade'] ?? 0));
        case 'UNIDADE':
            return $l['unidade'] === (int) $s['destino_unidade'] && $l['turno'] === ($s['destino_turno'] ?: ($origem['turno'] ?? ''));
        case 'NUCLEO':
            return $l['nucleo_id'] === (int) $s['destino_nucleo_id'];
    }
    return false;
}

// Pares possíveis para a troca: SRs no lugar que a pessoa quer. Primeiro quem pediu o
// contrário (troca que atende os dois), depois o nível mais perto.
function escSolCandidatos(array $s, string $dia, ?array $lugares = null, ?array $abertas = null): array {
    $lugares = $lugares ?? escSolLugares($dia);
    $rep = strtoupper($s['rep_code']);
    $origem = $lugares[$rep] ?? null;
    if (!$origem || $s['tipo'] === 'OUTRO') {
        return [];
    }
    $abertas = $abertas ?? escQ('SELECT * FROM ' . escT('esc_solicitacao') . " WHERE status IN ('PENDENTE', 'APROVADO')");
    // Assistente e dupla: o lugar pedido (núcleo escolhido ou o da pessoa) e as páginas da pessoa.
    $pessoaAlvo = strtoupper((string) ($s['destino_rep'] ?? ''));
    if (in_array($s['tipo'], ['ASSISTENTE', 'DUPLA'], true)) {
        if (!empty($s['destino_nucleo_id'])) {
            $n = escQ1('SELECT id, unidade, turno FROM ' . escT('esc_nucleo') . ' WHERE id = ?', [(int) $s['destino_nucleo_id']]);
            $s['_alvo'] = $n ? ['unidade' => (int) $n['unidade'], 'turno' => $n['turno'], 'nucleo_id' => (int) $n['id']] : null;
        } elseif ($pessoaAlvo !== '' && isset($lugares[$pessoaAlvo])) {
            $s['_alvo'] = $lugares[$pessoaAlvo];
        }
        $s['_paginas_alvo'] = $pessoaAlvo !== '' ? ($lugares[$pessoaAlvo]['paginas'] ?? []) : [];
    }
    $querem = [];
    foreach ($abertas as $o) {
        $querem[strtoupper($o['rep_code'])][] = $o;
    }
    $lista = [];
    foreach ($lugares as $r => $l) {
        if ($r === $rep || $r === $pessoaAlvo || $l['funcao'] !== 'EQUITE' || !escSolAtende($s, $l, $origem)) {
            continue;
        }
        // Pediu o contrário: a solicitação dele é atendida pelo lugar de quem pede agora.
        $contrario = null;
        foreach ($querem[$r] ?? [] as $o) {
            if (in_array($o['tipo'], ['TURNO', 'UNIDADE', 'NUCLEO'], true) && escSolAtende($o, $origem, $l)) {
                $contrario = (int) $o['id'];
            }
        }
        $lista[] = $l + ['contrario' => $contrario, 'dif_nivel' => abs(($l['nivel'] ?? 0) - ($origem['nivel'] ?? 0)),
            'com_pessoa' => !empty($s['_paginas_alvo']) && (bool) array_intersect_key($l['paginas'], $s['_paginas_alvo']),
            'no_nucleo' => isset($s['_alvo']['nucleo_id']) && $l['nucleo_id'] === $s['_alvo']['nucleo_id']];
    }
    // Primeiro quem pediu o contrário, depois quem já trabalha nas páginas da pessoa pedida,
    // quem está no núcleo pedido e o nível mais perto.
    usort($lista, static fn($a, $b) => [$a['contrario'] === null, !$a['com_pessoa'], !$a['no_nucleo'], $a['dif_nivel'], -array_sum($a['vagas']), $a['rep']]
        <=> [$b['contrario'] === null, !$b['com_pessoa'], !$b['no_nucleo'], $b['dif_nivel'], -array_sum($b['vagas']), $b['rep']]);
    return $lista;
}

// Troca 1 por 1 a partir de $desde (rodar dentro de escTx). Devolve o resumo para o log.
function escSolTrocar(string $a, string $b, string $desde, string $motivo): array {
    $a = strtoupper($a);
    $b = strtoupper($b);
    $tab = escT('esc_colaborador_config');
    $cfg = [];
    foreach ([$a, $b] as $r) {
        // Configuração marcada para depois: é cancelada e vale a troca (lib/equipes.php).
        escConfigCancelarFuturas($r, $desde);
        $linha = escQW('SELECT * FROM ' . $tab . ' WHERE rep_code = ? AND vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?) ORDER BY vigente_desde DESC LIMIT 1 FOR UPDATE', [$r, $desde, $desde]);
        if (!$linha || $linha[0]['funcao'] !== 'EQUITE' || !$linha[0]['nucleo_id']) {
            throw new RuntimeException(t('{rep} não está como SR num núcleo em {dia}.', ['rep' => $r, 'dia' => dataBr($desde)]));
        }
        $cfg[$r] = $linha[0];
    }
    $nucleo = [];
    foreach ($cfg as $r => $c) {
        $nucleo[$r] = escQW('SELECT id, unidade, turno FROM ' . escT('esc_nucleo') . ' WHERE id = ?', [(int) $c['nucleo_id']])[0];
    }
    $resumo = ['desde' => $desde, 'nucleo' => [$a => (int) $nucleo[$b]['id'], $b => (int) $nucleo[$a]['id']], 'vagas' => []];

    // 1. Configuração: cada um no núcleo do outro (mesmo núcleo: nada muda).
    if ((int) $nucleo[$a]['id'] !== (int) $nucleo[$b]['id']) {
        $fixas = ['id', 'rep_code', 'vigente_desde', 'vigente_ate', 'criado_em', 'criado_por', 'atualizado_em', 'atualizado_por', 'origem', 'nucleo_id', 'unidade', 'turno', 'observacao'];
        foreach ([[$a, $b], [$b, $a]] as [$quem, $par]) {
            $c = $cfg[$quem];
            $n = $nucleo[$par];
            $obs = mb_substr($motivo . '; ' . (string) $c['observacao'], 0, 255);
            if (substr((string) $c['vigente_desde'], 0, 10) === $desde) {
                escW('UPDATE ' . $tab . ' SET nucleo_id = ?, unidade = ?, turno = ?, observacao = ?, origem = \'TELA\', atualizado_por = ? WHERE id = ?',
                    [(int) $n['id'], (int) $n['unidade'], $n['turno'], $obs, escUserId(), (int) $c['id']]);
            } else {
                escW('UPDATE ' . $tab . ' SET vigente_ate = ?, atualizado_por = ? WHERE id = ?', [escMaisDias($desde, -1), escUserId(), (int) $c['id']]);
                $cols = array_values(array_diff(array_keys($c), $fixas));
                $valores = array_map(static fn($k) => $c[$k], $cols);
                $cols = array_merge(['rep_code'], $cols, ['nucleo_id', 'unidade', 'turno', 'observacao', 'vigente_desde', 'vigente_ate', 'origem', 'criado_por']);
                $valores = array_merge([$c['rep_code']], $valores, [(int) $n['id'], (int) $n['unidade'], $n['turno'], $obs, $desde, $c['vigente_ate'], 'TELA', escUserId()]);
                escW('INSERT INTO ' . $tab . ' (' . implode(', ', array_map(static fn($k) => '`' . str_replace('`', '', $k) . '`', $cols)) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')', $valores);
            }
        }
    }

    // 2. Vagas fixas: as de cada um passam para o outro (as duas listas são lidas antes de mexer).
    //    Grava com "#" na frente e tira no fim: se os dois têm vaga no mesmo posto, a chave
    //    única (posto, vaga, pessoa, data) não colide no meio da troca.
    $vagas = [];
    foreach ([$a, $b] as $r) {
        $vagas[$r] = escQW('SELECT * FROM ' . escT('esc_vaga_equipe') . ' WHERE rep_code = ? AND (vigente_ate IS NULL OR vigente_ate >= ?) FOR UPDATE', [$r, $desde]);
    }
    foreach ([[$a, $b], [$b, $a]] as [$dono, $novo]) {
        foreach ($vagas[$dono] as $v) {
            $mot = mb_substr($motivo . '; ' . (string) $v['motivo'], 0, 255);
            if (substr((string) $v['vigente_desde'], 0, 10) >= $desde) {
                escW('UPDATE ' . escT('esc_vaga_equipe') . ' SET rep_code = ?, motivo = ? WHERE id = ?', ['#' . $novo, $mot, (int) $v['id']]);
            } else {
                escW('UPDATE ' . escT('esc_vaga_equipe') . ' SET vigente_ate = ? WHERE id = ?', [escMaisDias($desde, -1), (int) $v['id']]);
                escW('INSERT INTO ' . escT('esc_vaga_equipe') . ' (posto_id, vaga, rep_code, fatia, ordem, status, motivo, vigente_desde, vigente_ate, origem, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'TELA\', ?)',
                    [(int) $v['posto_id'], $v['vaga'], '#' . $novo, $v['fatia'], (int) $v['ordem'], $v['status'], $mot, $desde, $v['vigente_ate'], escUserId()]);
            }
            $resumo['vagas'][$novo][] = (int) $v['posto_id'];
        }
    }
    escW('UPDATE ' . escT('esc_vaga_equipe') . ' SET rep_code = SUBSTRING(rep_code, 2) WHERE rep_code IN (?, ?)', ['#' . $a, '#' . $b]);
    return $resumo;
}

// ---------- Times legados juntos (só para o início da estrutura nova) ----------
// Pedido do Planejamento: no começo, 2 ou 3 pessoas de cada time legado (headcount_turno.time)
// no mesmo núcleo, para mostrar que a convivência foi levada em conta. Depois vale a regra
// normal. Só troca 1 por 1 dentro da mesma unidade e turno (escSolTrocar), sem mexer em quem
// tem prioridade fixa, pedido aberto ou já foi agrupado, e sem desfazer um par que já existe.
// Times "SOLO n" não são times. Função pura: recebe os SRs e devolve as trocas sugeridas.
// $srs = [rep => ['ut' => 'U|T', 'nucleo' => id, 'nivel' => n, 'time' => ?, 'party' => ?]]
function escSolLegadoPlano(array $srs, array $travados = []): array {
    $ehTime = static fn($t) => $t !== null && $t !== '' && stripos((string) $t, 'SOLO') !== 0;
    $trav = array_fill_keys(array_map('strtoupper', $travados), true);
    $porUt = [];
    foreach ($srs as $rep => $s) {
        $porUt[$s['ut']][$rep] = $s;
    }
    $trocas = [];
    foreach ($porUt as $ut => $gente) {
        $preso = $trav;
        $times = [];
        foreach ($gente as $rep => $s) {
            if ($ehTime($s['time'])) {
                $times[$s['time']][] = $rep;
            }
        }
        uasort($times, static fn($a, $b) => count($b) <=> count($a));
        foreach ($times as $time => $membros) {
            if (count($membros) < 2) {
                continue;
            }
            $alvoN = count($membros) >= 4 ? 3 : 2;
            $porNucleo = [];
            foreach ($membros as $r) {
                $porNucleo[$gente[$r]['nucleo']][] = $r;
            }
            ksort($porNucleo);
            uasort($porNucleo, static fn($a, $b) => count($b) <=> count($a)); // empate: o núcleo de menor id
            $alvo = array_key_first($porNucleo);
            while (count($porNucleo[$alvo]) < $alvoN) {
                $partyAlvo = array_filter(array_map(static fn($r) => $gente[$r]['party'], $porNucleo[$alvo]));
                $melhor = null;
                foreach ($membros as $m) {
                    if ($gente[$m]['nucleo'] === $alvo || isset($preso[$m])) {
                        continue;
                    }
                    foreach ($gente as $p => $ps) {
                        if ($ps['nucleo'] !== $alvo || isset($preso[$p]) || $ps['time'] === $time) {
                            continue;
                        }
                        // Não desfaz par: o time do parceiro não pode ter outra pessoa no núcleo alvo.
                        $parDele = $ehTime($ps['time']) && count(array_filter($gente, static fn($x, $r) => $r !== $p && $x['time'] === $ps['time'] && $x['nucleo'] === $alvo, ARRAY_FILTER_USE_BOTH)) > 0;
                        if ($parDele) {
                            continue;
                        }
                        $junta = $ehTime($ps['time']) && count(array_filter($gente, static fn($x) => $x['time'] === $ps['time'] && $x['nucleo'] === $gente[$m]['nucleo'])) > 0;
                        $nota = ($gente[$m]['nivel'] === $ps['nivel'] ? 5 : 0) + ($junta ? 4 : 0) + (!$ehTime($ps['time']) ? 1 : 0)
                              + ($gente[$m]['party'] && in_array($gente[$m]['party'], $partyAlvo, true) ? 2 : 0);
                        if (!$melhor || $nota > $melhor['nota']) {
                            $melhor = ['nota' => $nota, 'vem' => $m, 'sai' => $p, 'junta' => $junta];
                        }
                    }
                }
                if (!$melhor) {
                    break;
                }
                $de = $gente[$melhor['vem']]['nucleo'];
                $gente[$melhor['vem']]['nucleo'] = $alvo;
                $gente[$melhor['sai']]['nucleo'] = $de;
                $porNucleo[$de] = array_values(array_diff($porNucleo[$de], [$melhor['vem']]));
                $porNucleo[$alvo][] = $melhor['vem'];
                $preso[$melhor['vem']] = $preso[$melhor['sai']] = true;
                $trocas[] = ['ut' => $ut, 'time' => $time, 'vem' => $melhor['vem'], 'sai' => $melhor['sai'], 'para' => $alvo, 'de' => $de,
                             'mesmo_nivel' => $srs[$melhor['vem']]['nivel'] === $srs[$melhor['sai']]['nivel'], 'junta_outro' => $melhor['junta'],
                             'time_sai' => $srs[$melhor['sai']]['time']];
            }
            foreach ($porNucleo[$alvo] as $r) {
                $preso[$r] = true;
            }
        }
    }
    return $trocas;
}

// SRs da estrutura no dia, com o time legado (headcount_turno vigente), e quem não pode mudar.
function escSolLegadoDados(string $dia): array {
    $srs = [];
    foreach (escQ("SELECT UPPER(c.rep_code) AS rep, c.unidade, c.turno, c.nucleo_id, c.nivel, ht.time, ht.party
                     FROM " . escT('esc_colaborador_config') . " c
                     LEFT JOIN `5c_headcount`.`headcount_turno` ht ON UPPER(ht.code) COLLATE utf8mb4_unicode_ci = UPPER(c.rep_code) COLLATE utf8mb4_unicode_ci AND ht.vigente_ate IS NULL
                    WHERE c.funcao = 'EQUITE' AND c.nucleo_id IS NOT NULL AND c.vigente_desde <= ? AND (c.vigente_ate IS NULL OR c.vigente_ate >= ?)
                    ORDER BY c.unidade, c.turno, c.nucleo_id, c.rep_code", [$dia, $dia]) as $l) {
        $srs[$l['rep']] ??= ['ut' => $l['unidade'] . '|' . $l['turno'], 'nucleo' => (int) $l['nucleo_id'], 'nivel' => $l['nivel'] !== null ? (int) $l['nivel'] : null,
                             'time' => $l['time'], 'party' => $l['party']];
    }
    $trav = [];
    if (escSolInstalada()) {
        foreach (escQ("SELECT DISTINCT UPPER(rep_code) AS r FROM " . escT('esc_solicitacao') . " WHERE status IN ('PENDENTE', 'APROVADO')") as $l) {
            $trav[] = $l['r'];
        }
    }
    if (function_exists('escPolInstalada') && escPolInstalada()) {
        foreach (escQ('SELECT rep_codes FROM ' . escT('esc_pagina_prioridade') . ' WHERE vigente_ate IS NULL') as $l) {
            array_push($trav, ...array_filter(array_map('trim', explode(',', strtoupper((string) $l['rep_codes'])))));
        }
    }
    // Quem já tem configuração depois do dia (a troca não pode passar por cima).
    foreach (escQ("SELECT DISTINCT UPPER(rep_code) AS r FROM " . escT('esc_colaborador_config') . " WHERE vigente_desde > ?", [$dia]) as $l) {
        $trav[] = $l['r'];
    }
    return ['srs' => $srs, 'travados' => array_values(array_unique($trav))];
}
