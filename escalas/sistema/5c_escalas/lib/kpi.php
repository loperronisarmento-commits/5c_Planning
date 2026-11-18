<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/kpi.php
// KPI (Kinetic, Process & Integrity) dos SRs: SIMULAÇÃO, só visualização (pages/kpi.php).
// Regras do documento "KPI — Kinetic, Process e Integrity" (Versão para Simulação WFM, nov/2026), à risca:
// - Últimas 4 semanas (segunda a domingo) com pesos 4-3-2-1: a atual (até ontem) 4, a anterior 3, a
//   antepenúltima 2 e a de 4 semanas atrás 1. Vale para os três pilares.
// - Kinetic_base: média ponderada da meta semanal → 25% / 50% / 75% / 90% / 100% / 110% / 120%.
// - Process: 100% − SLA 15 (−4% a cada 10% abaixo do esperado) − SLA 10 (−2%) − SLA 5 (−1%); piso de 60%.
//   Mass por turno e "não seguir plano" ainda sem dado: não calculados.
// - Integrity: 100% − 15% por falta (abs_adh_af, ABS); pode zerar. DNR e P&G ainda sem dado: não calculados.
// - Score = Kinetic_base × Process × Integrity (0% a 120%). Moedas = só a meta semanal.
// Isolamento: só lê o banco (escQ tem trava de leitura), guarda o resultado em arquivo temporário (escCache)
// e não alimenta o Gerar, o mérito, pagamentos nem notificações. Não usa nem altera o lib/merito.php
// além de funções só de leitura.
// ===============================================

const ESC_KPI_PESOS = [4, 3, 2, 1];                 // semana atual, anterior, antepenúltima, 4 semanas atrás
// SLA: minutos => [esperado, desconto a cada 10% abaixo]. Esperado escalonado decidido pelo usuário em 18/11.
const ESC_KPI_SLA = [15 => [0.95, 0.04], 10 => [0.90, 0.02], 5 => [0.80, 0.01]];
const ESC_KPI_SLA_PASSO = 0.10;
const ESC_KPI_PROCESS_PISO = 0.60;
const ESC_KPI_FALTA = 0.15;                         // Integrity: −15% por falta
// Kinetic_base: [até (exclusivo), base]; o limite fica na faixa de cima. 150%–200% inclui o 200%.
const ESC_KPI_KINETIC = [[0.50, 0.25], [0.75, 0.50], [0.90, 0.75], [1.00, 0.90], [1.50, 1.00]];
const ESC_KPI_CACHE_SEG = 3 * 3600;

// As 4 semanas do KPI na data de hoje: [segunda => peso], da atual para trás.
function escKpiSemanas(?string $hoje = null): array {
    $seg = segundaDaSemana($hoje ?? date('Y-m-d'));
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
    foreach (ESC_KPI_KINETIC as [$ate, $base]) {
        if ($meta < $ate) {
            return $base;
        }
    }
    return $meta <= 2.0 ? 1.10 : 1.20;
}

// Moedas de uma semana pela meta: < 85% = 0; 85% a 100% linear de 1 a 20; acima, 20 × (1 + log2(meta)).
function escKpiMoedas(?float $meta): ?float {
    if ($meta === null) {
        return null;
    }
    if ($meta < 0.85) {
        return 0.0;
    }
    if ($meta <= 1.0) {
        return 1 + 19 * ($meta - 0.85) / 0.15;
    }
    return 20 * (1 + log($meta, 2));
}

// Desconto de um SLA (fração): a cada 10% COMPLETOS abaixo do esperado. Sem dado, null.
function escKpiDescontoSla(?float $sla, int $minutos): ?float {
    if ($sla === null) {
        return null;
    }
    [$esperado, $passo] = ESC_KPI_SLA[$minutos];
    $faixas = $sla < $esperado ? (int) floor(round(($esperado - $sla) / ESC_KPI_SLA_PASSO, 6)) : 0;
    return $faixas * $passo;
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
    if ($s <= 1.10) {
        return ['100_110', t('Só sai para alguém com 120%'), 'verde'];
    }
    if ($s >= 1.20) {
        return ['120', t('Prioridade nas páginas maiores'), 'verde'];
    }
    return ['110_120', t('Sem regra no documento (entre 110% e 120%)'), 'cinza'];
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
    $chave = 'kpi_v1_' . date('Y-m-d') . '_' . md5(implode(',', $reps));
    return escCache($chave, ESC_KPI_CACHE_SEG, static fn(): array => ['calculado_em' => date('Y-m-d H:i:s'), 'srs' => escKpiCalcular($reps)]);
}

// Calcula o KPI semana a semana (só leitura). Por rep:
//   semanas[segunda] = [meta, real, atingimento, moedas, respostas, r5, r10, r15, sla5, sla10, sla15,
//                       desc5, desc10, desc15, process, faltas, integrity]
//   kinetic_meta (média ponderada), kinetic_base, process, integrity, score, moedas (soma das semanas).
function escKpiCalcular(array $reps): array {
    $semanas = escKpiSemanas();
    $ontem = date('Y-m-d', strtotime('-1 day'));
    $de = escCorteDados(min(array_keys($semanas)));
    $ate = $ontem;
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

    // Kinetic: meta e realizado 5CReps por dia, turno (T1–T6) e página, rateados pelas mensagens do SR
    // (a mesma conta da meta semanal do mérito, M-10).
    $pag = [];
    foreach (escQ('SELECT Date AS dia, Turn AS t, Page AS pagina, SUM(`Goal 5CReps`) AS meta, SUM(`5CReps`) AS real5
                     FROM `5c_wfm_db_mis`.`tab_intraday_base` WHERE Date >= ? AND Date <= ? GROUP BY Date, Turn, Page', [$de, $ate]) as $r) {
        $pag[substr((string) $r['dia'], 0, 10) . '|' . $r['t'] . '|' . $r['pagina']] = [(float) $r['meta'], (float) $r['real5']];
    }
    $msgs = [];
    $tot = [];
    $temR15 = escKpiTemSla15();
    $r15 = $temR15 ? 'SUM(resp_15min)' : 'NULL';
    foreach (escQ("SELECT dia, turno AS t, SUBSTRING_INDEX(rep, ' ', 1) AS cod, paginas, SUM(msgs_rep) AS m,
                          SUM(respostas) AS resp, SUM(resp_5min) AS r5, SUM(resp_10min) AS r10, " . $r15 . " AS r15
                     FROM `5c_capacity`.`cap_rep_hora` WHERE dia >= ? AND dia <= ? GROUP BY dia, turno, cod, paginas", [$de, $ate]) as $r) {
        $dia = substr((string) $r['dia'], 0, 10);
        $cod = (string) $r['cod'];
        $lista = array_values(array_filter(array_map('trim', explode(' + ', (string) $r['paginas']))));
        $parte = (float) $r['m'] / max(1, count($lista));
        foreach ($lista as $p) {
            $k = $dia . '|' . $r['t'] . '|' . $p;
            $tot[$k] = ($tot[$k] ?? 0) + $parte;
            if (isset($repDe[$cod])) {
                $msgs[$k][$cod] = ($msgs[$k][$cod] ?? 0) + $parte;
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
        if (!isset($pag[$k]) || empty($tot[$k])) {
            continue;
        }
        [$meta, $real] = $pag[$k];
        $sg = $semanaDe(substr($k, 0, 10));
        foreach ($porCod as $cod => $m) {
            $share = $m / $tot[$k];
            $s = &$sem[$repDe[$cod]][$sg];
            $s['meta'] = ($s['meta'] ?? 0) + $share * $meta;
            $s['real'] = ($s['real'] ?? 0) + $share * $real;
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

    foreach ($reps as $rep) {
        $vazio[$rep] = $sem[$rep] ?? [];
    }
    return escKpiFechar($vazio, $semanas);
}

// Fecha cada semana (atingimento, moedas, SLA, Process, Integrity) e os pilares ponderados.
function escKpiFechar(array $porRep, array $semanas): array {
    $out = [];
    foreach ($porRep as $rep => $porSemana) {
        $linhas = [];
        $atg = [];
        $proc = [];
        $integ = [];
        $moedas = null;
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
            $i = max(0.0, 1 - ESC_KPI_FALTA * $faltas);
            $m = escKpiMoedas($at);
            $linhas[$sg] = ['peso' => $peso, 'meta' => $s['meta'] ?? null, 'real' => $s['real'] ?? null, 'atingimento' => $at, 'moedas' => $m,
                'respostas' => $resp, 'sla5' => $sla5, 'sla10' => $sla10, 'sla15' => $sla15, 'desc5' => $d5, 'desc10' => $d10, 'desc15' => $d15,
                'process' => $p, 'faltas' => $faltas, 'integrity' => $i];
            $atg[$sg] = $at;
            $proc[$sg] = $p;
            $integ[$sg] = $i;
            if ($m !== null) {
                $moedas = ($moedas ?? 0) + $m;
            }
        }
        $kMeta = escKpiMediaPonderada($atg, $semanas);
        $kBase = escKpiKineticBase($kMeta);
        $pMed = escKpiMediaPonderada($proc, $semanas);
        $iMed = escKpiMediaPonderada($integ, $semanas);
        $score = $kBase !== null && $pMed !== null && $iMed !== null ? $kBase * $pMed * $iMed : null;
        $out[$rep] = ['semanas' => $linhas, 'kinetic_meta' => $kMeta, 'kinetic_base' => $kBase, 'process' => $pMed,
            'integrity' => $iMed, 'score' => $score, 'moedas' => $moedas];
    }
    return $out;
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
