<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/kpi.php
// KPI (Kinetic, Process & Integrity) dos SRs: SIMULAÇÃO, só visualização (pages/kpi.php).
// Regras do documento "KPI — Kinetic, Process e Integrity" (Versão para Simulação WFM, nov/2026), à risca:
// - Últimas 4 semanas FECHADAS (segunda a domingo; a em andamento fica de fora, ajuste de 23/11) com
//   pesos 4-3-2-1: a última fechada 4, a anterior 3, a antepenúltima 2 e a de 4 semanas atrás 1.
// - Kinetic_base: meta ponderada = Σ(peso × realizado) ÷ Σ(peso × meta) das semanas (ajuste de 23/11: semana
//   com meta pequena pesa menos), em rampa pelos pontos da tabela do documento (50% → 50%, 75% → 75%,
//   90% → 90%, 100% → 100%, 150% → 110%, 200% → 120%; piso 25%, teto 120%).
// - Process: 100% − SLA 15 (−4% a cada 10% abaixo do esperado) − SLA 10 (−2%) − SLA 5 (−1%), proporcional
//   ao quanto falta (ajuste de 23/11: −0,4 ponto por ponto abaixo no SLA 15); piso de 60%.
//   Mass por turno e "não seguir plano" ainda sem dado: não calculados.
// - Integrity: 100% − 15% por falta (abs_adh_af, ABS) − quebra de P&G (avaliação de qualidade com a seção
//   "Model Best Practices" Não Conforme: −10% com nota 80, gradual até −30% com nota 0); pode zerar.
//   DNR ainda sem dado: não calculado.
// - Score = Kinetic_base × Process × Integrity (0% a 120%). As moedas saíram na 1.38.0 (controle fora do sistema).
// - Novato (menos de 2 semanas fechadas com meta): score provisório de 75% (ajuste de 23/11).
// Desde a 1.34.0 o KPI é a referência da escalação (contexto/regras_escalacao_kpi.md, K-01 a K-08) e, desde a 1.38.0,
// o indicador oficial (o Mérito é Legacy, só leitura). O nível 0 a 4 de cada SR vai para o Gerar, o
// Equilíbrio, o Funil, os Owners e as tags; o cache fica em esc_sr_kpi (ES_198). A tela guarda o detalhe semana
// a semana em arquivo temporário (escCache). Não alimenta pagamentos nem notificações.
// ===============================================

const ESC_KPI_PESOS = [4, 3, 2, 1];                 // última semana fechada, anterior, antepenúltima, 4 semanas atrás
const ESC_KPI_NOVATO_SEMANAS = 2;                   // menos que isso de semanas com meta: score provisório
const ESC_KPI_NOVATO_SCORE = 0.75;
const ESC_KPI_PARETO = 0.20;                        // ação de correção só para os 20% de menor score (pages/kpi.php)
// SLA: minutos => [esperado, desconto a cada 10% abaixo]. Esperado escalonado decidido pelo usuário em 18/11.
const ESC_KPI_SLA = [15 => [0.95, 0.04], 10 => [0.90, 0.02], 5 => [0.80, 0.01]];
const ESC_KPI_SLA_PASSO = 0.10;
const ESC_KPI_PROCESS_PISO = 0.60;
const ESC_KPI_FALTA = 0.15;                         // Integrity: −15% por falta
// Integrity: quebra de P&G pela avaliação de qualidade (5c_quality_db, seção "Model Best Practices" Não Conforme).
// A nota é feita de 5 módulos de 20%: com 80 só falhou o P&G (−10%); cai em linha reta até −30% com nota 0
// (decisão do usuário em 23/11).
const ESC_KPI_PG = [80.0, 0.10, 0.30];               // [nota de referência, desconto nela, desconto com nota 0]
// Kinetic_base em rampa (decisão do usuário em 18/11, opção B): os pontos da tabela do documento
// [meta, base], ligados em linha reta. Sem os degraus, o score não se amontoa no limite das faixas.
// Abaixo do primeiro ponto vale 25%; a partir de 200%, 120%.
const ESC_KPI_KINETIC = [[0.0, 0.25], [0.50, 0.50], [0.75, 0.75], [0.90, 0.90], [1.00, 1.00], [1.50, 1.10], [2.00, 1.20]];
const ESC_KPI_CACHE_SEG = 3 * 3600;

// As 4 semanas fechadas do KPI na data de hoje: [segunda => peso], da última fechada para trás.
function escKpiSemanas(?string $hoje = null): array {
    $seg = escMaisDias(segundaDaSemana($hoje ?? date('Y-m-d')), -7);
    $out = [];
    foreach (ESC_KPI_PESOS as $i => $peso) {
        $out[escMaisDias($seg, -7 * $i)] = $peso;
    }
    return $out;
}

// Kinetic_base pela média ponderada da meta (fração: 1.0 = 100%).
function escKpiKineticBase(?float $meta): ?float {
    if ($meta === null) {
        return null;
    }
    $pts = ESC_KPI_KINETIC;
    if ($meta <= $pts[0][0]) {
        return $pts[0][1];
    }
    for ($i = 1, $n = count($pts); $i < $n; $i++) {
        [$x0, $y0] = $pts[$i - 1];
        [$x1, $y1] = $pts[$i];
        if ($meta <= $x1) {
            return $y0 + ($y1 - $y0) * ($meta - $x0) / ($x1 - $x0);
        }
    }
    return $pts[$n - 1][1];
}

// Desconto de um SLA (fração), proporcional ao quanto falta para o esperado: o desconto do documento
// "a cada 10%" vale por ponto (ex.: SLA 15 em 90% com esperado de 95% = 5 pontos × 0,4 = −2%). Sem dado, null.
function escKpiDescontoSla(?float $sla, int $minutos): ?float {
    if ($sla === null) {
        return null;
    }
    [$esperado, $passo] = ESC_KPI_SLA[$minutos];
    return max(0.0, $esperado - $sla) / ESC_KPI_SLA_PASSO * $passo;
}

// Desconto de uma quebra de P&G pela nota da avaliação: 80 (ou mais) → −10%; 0 → −30%, em linha reta.
function escKpiDescontoPg(float $nota): float {
    [$ref, $min, $max] = ESC_KPI_PG;
    return $min + ($max - $min) * (max(0.0, $ref - min($ref, $nota)) / $ref);
}

// Comportamento sugerido pelo Score (seção 5 do documento; só um rótulo, não pesa em nada).
function escKpiComportamento(?float $score): array {
    if ($score === null) {
        return ['SEM', t('Sem score'), ''];
    }
    $s = round($score, 6);
    if ($s < 0.25) {
        return ['LT25', t('Menos turnos e só coberturas'), 'vermelho'];
    }
    if ($s < 0.50) {
        return ['25_50', t('Realocar para páginas menores'), 'laranja'];
    }
    if ($s < 0.75) {
        return ['50_75', t('Substituir se houver alguém acima de 100%'), 'amarelo'];
    }
    if ($s < 1.00) {
        return ['75_100', t('Mantém a posição'), ''];
    }
    // A faixa "100% – 110%" do documento foi esticada até 119% (decisão do usuário em 19/11): o documento
    // não dizia o que fazer entre 110% e 120%.
    if ($s < 1.20) {
        return ['100_120', t('Só sai para alguém com 120%'), 'verde'];
    }
    return ['120', t('Prioridade nas páginas maiores'), 'verde'];
}

// Média ponderada de [segunda => valor] com os pesos das semanas que têm valor.
function escKpiMediaPonderada(array $porSemana, array $semanas): ?float {
    $soma = 0.0;
    $pesos = 0;
    foreach ($semanas as $sg => $peso) {
        if (isset($porSemana[$sg]) && $porSemana[$sg] !== null) {
            $soma += $peso * $porSemana[$sg];
            $pesos += $peso;
        }
    }
    return $pesos > 0 ? $soma / $pesos : null;
}

// KPI dos reps (códigos), com cache em arquivo. Volta [rep => detalhe] (ver escKpiCalcular).
function escKpiCarregar(array $reps): array {
    $reps = array_values(array_unique(array_map('strtoupper', $reps)));
    sort($reps);
    if (!$reps) {
        return [];
    }
    $chave = 'kpi_v3_' . date('Y-m-d') . '_' . md5(implode(',', $reps));
    return escCache($chave, ESC_KPI_CACHE_SEG, static fn(): array => ['calculado_em' => date('Y-m-d H:i:s'), 'srs' => escKpiCalcular($reps)]);
}

// Calcula o KPI semana a semana (só leitura). Por rep:
//   semanas[segunda] = [meta, real, atingimento, respostas, r5, r10, r15, sla5, sla10, sla15,
//                       desc5, desc10, desc15, process, faltas, integrity]
//   kinetic_meta (média ponderada), kinetic_base, process, integrity, score.
function escKpiCalcular(array $reps): array {
    $semanas = escKpiSemanas();
    $de = escCorteDados(min(array_keys($semanas)));
    $ate = escMaisDias(max(array_keys($semanas)), 6); // domingo da última semana fechada
    $vazio = [];
    foreach ($reps as $rep) {
        $vazio[$rep] = [];
    }
    if ($de > $ate) {
        return escKpiFechar($vazio, $semanas);
    }
    $sem = [];        // [rep][segunda] = dados da semana
    $segDe = [];
    $semanaDe = static function (string $dia) use (&$segDe): string {
        return $segDe[$dia] ?? ($segDe[$dia] = segundaDaSemana($dia));
    };

    // Código do Infloww (prefixo do remetente, ex.: "SRJ70") → código do SR.
    $repDe = [];
    foreach (escQ('SELECT UPPER(rep_code) AS rep, code_infloww FROM `5c_wfm_db_mis`.`vw_rep_estrutura_atual` WHERE code_infloww IS NOT NULL AND code_infloww <> \'\' AND rep_code IN (' . escIn($reps) . ')', $reps) as $r) {
        $repDe[(string) $r['code_infloww']] = $r['rep'];
    }

    // Kinetic: meta e realizado 5CReps por dia, turno (T1–T6) e página. A meta vai pela fatia de mensagens
    // do SR; o realizado, pela venda própria (venda direta e tip, escFatiaRealizado em lib/atingimento.php,
    // 1.39.0). É a mesma conta do atingimento por página.
    $pag = [];
    foreach (escQ('SELECT Date AS dia, Turn AS t, Page AS pagina, SUM(`Goal 5CReps`) AS meta, SUM(`5CReps`) AS real5, SUM(TIP) AS tip
                     FROM `5c_wfm_db_mis`.`tab_intraday_base` WHERE Date >= ? AND Date <= ? GROUP BY Date, Turn, Page', [$de, $ate]) as $r) {
        // Nome da página sem diferença de maiúsculas (B1 da auditoria de 24/11: "Brandi Love FREE" no
        // cap_rep_hora e "Brandi Love Free" no Intraday): a chave do PHP diferencia, o SQL não.
        $kp = substr((string) $r['dia'], 0, 10) . '|' . $r['t'] . '|' . mb_strtolower(trim((string) $r['pagina']));
        $pag[$kp] = [($pag[$kp][0] ?? 0) + (float) $r['meta'], ($pag[$kp][1] ?? 0) + (float) $r['real5'], ($pag[$kp][2] ?? 0) + max(0.0, (float) $r['tip'])];
    }
    $msgs = [];
    $tot = [];
    $venda = [];
    $vtot = [];
    $temR15 = escKpiTemSla15();
    $r15 = $temR15 ? 'SUM(resp_15min)' : 'NULL';
    foreach (escQ("SELECT dia, turno AS t, SUBSTRING_INDEX(rep, ' ', 1) AS cod, paginas, SUM(msgs_rep) AS m, SUM(valor_comprado) AS v,
                          SUM(respostas) AS resp, SUM(resp_5min) AS r5, SUM(resp_10min) AS r10, " . $r15 . " AS r15
                     FROM `5c_capacity`.`cap_rep_hora` WHERE dia >= ? AND dia <= ? GROUP BY dia, turno, cod, paginas", [$de, $ate]) as $r) {
        $dia = substr((string) $r['dia'], 0, 10);
        $cod = (string) $r['cod'];
        $lista = array_values(array_filter(array_map('trim', explode(' + ', (string) $r['paginas']))));
        $parte = (float) $r['m'] / max(1, count($lista));
        $parteV = max(0.0, (float) $r['v']) / max(1, count($lista));
        foreach ($lista as $p) {
            $k = $dia . '|' . $r['t'] . '|' . mb_strtolower($p);
            $tot[$k] = ($tot[$k] ?? 0) + $parte;
            $vtot[$k] = ($vtot[$k] ?? 0) + $parteV;
            if (isset($repDe[$cod])) {
                $msgs[$k][$cod] = ($msgs[$k][$cod] ?? 0) + $parte;
                $venda[$k][$cod] = ($venda[$k][$cod] ?? 0) + $parteV;
            }
        }
        // Process: SLA 5, 10 e 15 do SR na semana (todas as respostas, sem exclusão de hora).
        if (isset($repDe[$cod])) {
            $s = &$sem[$repDe[$cod]][$semanaDe($dia)];
            $s['respostas'] = ($s['respostas'] ?? 0) + (int) $r['resp'];
            $s['r5'] = ($s['r5'] ?? 0) + (int) $r['r5'];
            $s['r10'] = ($s['r10'] ?? 0) + (int) $r['r10'];
            if ($r['r15'] !== null) {
                $s['r15'] = ($s['r15'] ?? 0) + (int) $r['r15'];
            } else {
                $s['r15_falta'] = true;
            }
            unset($s);
        }
    }
    foreach ($msgs as $k => $porCod) {
        // Página sem meta no Intraday (ex.: página nova, ainda com Goal 0) fica fora da meta e do
        // realizado: senão o realizado dela entra sem meta e a meta da semana explode (1.32.1).
        if (!isset($pag[$k]) || empty($tot[$k]) || $pag[$k][0] <= 0) {
            continue;
        }
        [$meta, $real, $tip] = $pag[$k];
        $sg = $semanaDe(substr($k, 0, 10));
        foreach ($porCod as $cod => $m) {
            $share = $m / $tot[$k];
            $s = &$sem[$repDe[$cod]][$sg];
            $s['meta'] = ($s['meta'] ?? 0) + $share * $meta;
            $s['real'] = ($s['real'] ?? 0) + escFatiaRealizado($m, $tot[$k], $venda[$k][$cod] ?? 0.0, $vtot[$k] ?? 0.0, $tip) * $real;
            unset($s);
        }
    }

    // Integrity: dias com falta (abs_adh_af, classificação ABS). Falta aprovada sai da escala, então
    // toda falta lida aqui é devida (decisão do usuário em 18/11).
    if (escAbsOficialOk()) {
        foreach (escQ("SELECT UPPER(codigo) AS rep, dia FROM `5c_wfm_db_mis`.`abs_adh_af`
                        WHERE dia >= ? AND dia <= ? AND classificacao = 'ABS' AND codigo IN (" . escIn($reps) . ') GROUP BY UPPER(codigo), dia',
            array_merge([$de, $ate], $reps)) as $r) {
            $sg = $semanaDe(substr((string) $r['dia'], 0, 10));
            $sem[$r['rep']][$sg]['faltas'] = ($sem[$r['rep']][$sg]['faltas'] ?? 0) + 1;
        }
    }

    // Integrity: quebras de P&G das avaliações de qualidade, pela data do contato avaliado. O avaliado vem pelo
    // nome; liga ao código pelo headcount (só os SRs pedidos).
    try {
        foreach (escQ("SELECT UPPER(h.code) AS rep, DATE(e.data_contato) AS dia, e.codigo_avaliacao AS aval, MIN(e.nota) AS nota
                         FROM `5c_quality_db`.`extracao_base_resumida` e
                         JOIN `5c_headcount`.`headcount` h ON LOWER(TRIM(h.name)) COLLATE utf8mb4_unicode_ci = LOWER(TRIM(e.avaliado)) COLLATE utf8mb4_unicode_ci
                        WHERE e.versao_atual = 1 AND e.secao = 'Model Best Practices' AND e.tipo_resposta = 'Não Conforme'
                          AND e.data_contato >= ? AND e.data_contato < ? AND h.code IN (" . escIn($reps) . ')
                        GROUP BY UPPER(h.code), DATE(e.data_contato), e.codigo_avaliacao',
            array_merge([$de, escMaisDias($ate, 1)], $reps)) as $r) {
            $sg = $semanaDe(substr((string) $r['dia'], 0, 10));
            $sem[$r['rep']][$sg]['pg'][] = (float) $r['nota'];
        }
    } catch (Throwable $e) {
        appLog('warning', 'KPI: avaliações de qualidade indisponíveis (5c_quality_db)', ['erro' => $e->getMessage()]);
    }

    foreach ($reps as $rep) {
        $vazio[$rep] = $sem[$rep] ?? [];
    }
    $out = escKpiFechar($vazio, $semanas);
    // Anos completos de casa (desempate, como a M-06): só o código e o início do contrato do headcount.
    try {
        $hoje = new DateTime(date('Y-m-d'));
        foreach (escQ('SELECT UPPER(code) AS rep, startContract AS ini FROM `5c_headcount`.`headcount` WHERE code IN (' . escIn($reps) . ')', $reps) as $r) {
            $d = DateTime::createFromFormat('!d/m/Y', trim((string) $r['ini']));
            if ($d && $d <= $hoje && isset($out[$r['rep']])) {
                $out[$r['rep']]['anos'] = max($out[$r['rep']]['anos'] ?? 0, (int) $d->diff($hoje)->y);
            }
        }
    } catch (Throwable $e) {
        appLog('warning', 'KPI: tempo de casa indisponível (headcount)', ['erro' => $e->getMessage()]);
    }
    return $out;
}

// Fecha cada semana (atingimento, SLA, Process, Integrity) e os pilares ponderados.
function escKpiFechar(array $porRep, array $semanas): array {
    $out = [];
    foreach ($porRep as $rep => $porSemana) {
        $linhas = [];
        $proc = [];
        $integ = [];
        foreach ($semanas as $sg => $peso) {
            $s = $porSemana[$sg] ?? null;
            if ($s === null) {
                continue;
            }
            $at = !empty($s['meta']) ? ($s['real'] ?? 0) / $s['meta'] : null;
            $resp = (int) ($s['respostas'] ?? 0);
            $sla5 = $resp > 0 ? $s['r5'] / $resp : null;
            $sla10 = $resp > 0 ? $s['r10'] / $resp : null;
            $sla15 = $resp > 0 && empty($s['r15_falta']) ? ($s['r15'] ?? 0) / $resp : null;
            $d15 = escKpiDescontoSla($sla15, 15);
            $d10 = escKpiDescontoSla($sla10, 10);
            $d5 = escKpiDescontoSla($sla5, 5);
            // Sem respostas na semana não há Process a calcular (Mass e plano ainda não têm dado).
            $p = $resp > 0 ? max(ESC_KPI_PROCESS_PISO, 1 - ($d15 ?? 0) - ($d10 ?? 0) - ($d5 ?? 0)) : null;
            $faltas = (int) ($s['faltas'] ?? 0);
            $pg = $s['pg'] ?? [];
            $descPg = array_sum(array_map('escKpiDescontoPg', $pg));
            $i = max(0.0, 1 - ESC_KPI_FALTA * $faltas - $descPg);
            $linhas[$sg] = ['peso' => $peso, 'meta' => $s['meta'] ?? null, 'real' => $s['real'] ?? null, 'atingimento' => $at,
                'respostas' => $resp, 'sla5' => $sla5, 'sla10' => $sla10, 'sla15' => $sla15, 'desc5' => $d5, 'desc10' => $d10, 'desc15' => $d15,
                'process' => $p, 'faltas' => $faltas, 'pg' => $pg, 'desc_pg' => $descPg, 'integrity' => $i];
            $proc[$sg] = $p;
            $integ[$sg] = $i;
        }
        // Meta ponderada pelo tamanho da meta: Σ(peso × realizado) ÷ Σ(peso × meta).
        $somaReal = 0.0;
        $somaMeta = 0.0;
        $semMeta = 0;
        foreach ($linhas as $w) {
            if (!empty($w['meta'])) {
                $somaReal += $w['peso'] * (float) $w['real'];
                $somaMeta += $w['peso'] * (float) $w['meta'];
                $semMeta++;
            }
        }
        $kMeta = $somaMeta > 0 ? $somaReal / $somaMeta : null;
        $kBase = escKpiKineticBase($kMeta);
        $pMed = escKpiMediaPonderada($proc, $semanas);
        $iMed = escKpiMediaPonderada($integ, $semanas);
        $calc = $kBase !== null && $pMed !== null && $iMed !== null ? $kBase * $pMed * $iMed : null;
        // Novato (ou sem histórico): menos de ESC_KPI_NOVATO_SEMANAS semanas com meta → score provisório.
        $provisorio = $semMeta < ESC_KPI_NOVATO_SEMANAS;
        $out[$rep] = ['semanas' => $linhas, 'kinetic_meta' => $kMeta, 'kinetic_base' => $kBase, 'process' => $pMed,
            'integrity' => $iMed, 'score' => $provisorio ? ESC_KPI_NOVATO_SCORE : $calc, 'score_calculado' => $calc,
            'provisorio' => $provisorio, 'semanas_meta' => $semMeta];
    }
    return $out;
}

// Pareto (ajuste de 23/11): só os ESC_KPI_PARETO (20%) de menor score recebem a ação de correção da faixa
// (menos turnos, realocar, substituir). Conta entre todos os SRs com score calculado (o novato provisório
// fica de fora). Volta [rep => true].
function escKpiPareto(array $kpiSr): array {
    $scores = [];
    foreach ($kpiSr as $rep => $k) {
        if (empty($k['provisorio']) && $k['score'] !== null) {
            $scores[$rep] = (float) $k['score'];
        }
    }
    asort($scores);
    return array_fill_keys(array_slice(array_keys($scores), 0, (int) ceil(count($scores) * ESC_KPI_PARETO)), true);
}

// K-01: nível do SR para a escala (contexto/regras_escalacao_kpi.md). 4 = 120%; 3 = 100% a 119%; 2 = mantém a
// posição (75% a 99%, novato provisório e quem está abaixo de 75% fora dos 20% piores); 1 = entre os 20% piores
// com 50% a 74%; 0 = entre os 20% piores abaixo de 50%.
function escKpiNivel(?float $score, bool $provisorio, bool $pareto): int {
    if ($provisorio || $score === null) {
        return 2;
    }
    $s = round($score, 6);
    if ($s >= 1.20) {
        return 4;
    }
    if ($s >= 1.00) {
        return 3;
    }
    if ($s < 0.75 && $pareto) {
        return $s < 0.50 ? 0 : 1;
    }
    return 2;
}

// Rótulo do nível nas telas e nos motivos da Grade.
function escKpiNivelRotulo(int $nivel): array {
    return [4 => [t('Nível 4 · 120%'), 'verde'], 3 => [t('Nível 3 · 100% a 119%'), 'verde'], 2 => [t('Nível 2 · mantém'), ''],
        1 => [t('Nível 1 · substituir'), 'amarelo'], 0 => [t('Nível 0 · realocar'), 'laranja']][$nivel] ?? [t('Nível 2 · mantém'), ''];
}

// Fonte do desempenho na escalação: desde a 1.38.0 sempre o KPI (indicador oficial). O Mérito é Legacy, só
// leitura, e o parâmetro v4_fonte_desempenho não existe mais.
function escDesempenhoFonte(): string {
    return 'KPI';
}

// SRs ativos da estrutura nova (vigentes ou que começam nas próximas 2 semanas).
function escKpiSrsAtivos(): array {
    $d = date('Y-m-d', strtotime('+14 day'));
    return array_column(escQ('SELECT DISTINCT UPPER(rep_code) AS rep FROM ' . escT('esc_colaborador_config')
        . " WHERE funcao = 'EQUITE' AND nucleo_id IS NOT NULL AND vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= CURDATE())", [$d]), 'rep');
}

// KPI no banco (esc_sr_kpi, K-08): recalcula e grava se estiver com mais de 3 h (ou se $forcar).
// Volta [rep => linha do cache].
function escKpiBanco(bool $forcar = false): array {
    // Prévia (ESC_SO_PREVIA, deploy/base_previa.php): calcula com o código da cópia e não grava o cache.
    if (!empty($GLOBALS['ESC_SO_PREVIA'])) {
        static $previa = null;
        if ($previa === null) {
            $previa = [];
            foreach (escKpiLinhas(escKpiCalcular(escKpiSrsAtivos()), date('Y-m-d H:i:s')) as $l) {
                $previa[strtoupper((string) $l['rep_code'])] = $l;
            }
        }
        return $previa;
    }
    try {
        $ult = escQ1('SELECT MAX(calculado_em) AS m FROM ' . escT('esc_sr_kpi'));
    } catch (Throwable $e) {
        return []; // ES_198 ainda não rodou
    }
    $velho = !$ult || $ult['m'] === null || strtotime((string) $ult['m']) < time() - ESC_KPI_CACHE_SEG;
    if ($forcar || ($velho && (PHP_SAPI === 'cli' || escPodeVerRestrito()))) {
        try {
            $agora = date('Y-m-d H:i:s');
            $linhas = escKpiLinhas(escKpiCalcular(escKpiSrsAtivos()), $agora);
            if ($linhas) {
                escTx(static function () use ($linhas, $agora) {
                    escW('DELETE FROM `' . ESC_SCHEMA . '`.`esc_sr_kpi` WHERE calculado_em < ?', [$agora]);
                    escInserirLote('esc_sr_kpi', array_keys($linhas[0]), $linhas);
                });
            }
        } catch (Throwable $e) {
            appLog('error', 'KPI: falha ao recalcular o cache', ['erro' => $e->getMessage()]);
        }
    }
    $out = [];
    foreach (escQ('SELECT * FROM ' . escT('esc_sr_kpi')) as $r) {
        $out[strtoupper((string) $r['rep_code'])] = $r;
    }
    return $out;
}

// Para as tags da Grade (ES_199): faltas, SLA 5 e quebras de P&G das 4 semanas, e as semanas fechadas seguidas
// com 100% ou mais da meta, da última para trás (semana sem meta é pulada, como a M-10).
function escKpiResumoTags(array $k): array {
    $sem = is_array($k['semanas'] ?? null) ? $k['semanas'] : [];
    krsort($sem);
    $faltas = 0;
    $resp = 0;
    $r5 = 0.0;
    $pg = 0;
    $seq = 0;
    $seguindo = true;
    foreach ($sem as $w) {
        $faltas += (int) ($w['faltas'] ?? 0);
        $pg += count($w['pg'] ?? []);
        if (!empty($w['respostas']) && $w['sla5'] !== null) {
            $resp += (int) $w['respostas'];
            $r5 += (float) $w['sla5'] * (int) $w['respostas'];
        }
        if ($seguindo && ($w['atingimento'] ?? null) !== null) {
            if ($w['atingimento'] >= 1.0) {
                $seq++;
            } else {
                $seguindo = false;
            }
        }
    }
    $semDados = !$sem;
    return ['faltas' => $semDados ? null : $faltas, 'sla5' => $resp > 0 ? round($r5 / $resp, 4) : null, 'pg' => $semDados ? null : $pg, 'semanas_meta' => $semDados ? null : $seq];
}

// Linhas do cache esc_sr_kpi a partir do cálculo (nível com o Pareto entre todos os SRs calculados).
function escKpiLinhas(array $calc, string $agora): array {
    $pareto = escKpiPareto($calc);
    $ref = max(array_keys(escKpiSemanas()));
    $r4 = static fn($v) => $v === null ? null : round((float) $v, 4);
    $linhas = [];
    foreach ($calc as $rep => $k) {
        $par = isset($pareto[$rep]);
        $linhas[] = ['rep_code' => $rep, 'semana_ref' => $ref, 'kinetic_meta' => $r4($k['kinetic_meta']), 'kinetic' => $r4($k['kinetic_base']),
            'process' => $r4($k['process']), 'integrity' => $r4($k['integrity']), 'score' => $r4($k['score']), 'score_calculado' => $r4($k['score_calculado']),
            'provisorio' => !empty($k['provisorio']) ? 1 : 0, 'pareto' => $par ? 1 : 0,
            'nivel' => escKpiNivel($k['score'] !== null ? (float) $k['score'] : null, !empty($k['provisorio']), $par)] + escKpiResumoTags($k) + ['anos' => $k['anos'] ?? 0, 'calculado_em' => $agora];
    }
    return $linhas;
}

// O que o Gerar usa do desempenho (KPI): 'nota' (score em %), 'faixa' (nível 0 a 4), 'anos', 'fonte' e
// 'so_cobertura' (D2: abaixo de 25% entre os 20% piores). Quem não está no cache fica no meio (nível 2).
function escDesempenhoMotor(): array {
    $corte = (float) str_replace(',', '.', escV4Param('v4_kpi_so_cobertura', '0.25'));
    $out = ['nota' => [], 'faixa' => [], 'anos' => [], 'fonte' => 'KPI', 'so_cobertura' => [], 'neutro' => 2];
    foreach (escKpiBanco() as $rep => $k) {
        $score = $k['score'] !== null ? (float) $k['score'] : null;
        $out['nota'][$rep] = $score !== null ? round($score * 100, 2) : 75.0;
        $out['faixa'][$rep] = (int) $k['nivel'];
        $out['anos'][$rep] = (int) ($k['anos'] ?? 0);
        if ((int) $k['nivel'] === 0 && $score !== null && empty($k['provisorio']) && $score < $corte) {
            $out['so_cobertura'][$rep] = true;
        }
    }
    return $out;
}

// Nota de desempenho por SR para Equilíbrio, Funil, Owners e equipes: o score do KPI em %.
function escDesempenhoNotas(): array {
    $m = escDesempenhoMotor();
    return $m['nota'];
}

// A coluna resp_15min (5C_Planning_41) já existe no cap_rep_hora?
function escKpiTemSla15(): bool {
    static $ok = null;
    if ($ok === null) {
        try {
            $ok = (bool) escQ1('SELECT 1 AS ok FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', ['5c_capacity', 'cap_rep_hora', 'resp_15min']);
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}
