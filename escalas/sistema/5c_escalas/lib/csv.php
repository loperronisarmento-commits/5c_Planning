<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/csv.php
// CSV de postagem: marca de cada célula, antes do corte igual ao site (5C Schedule) e blocos T1 a T6.
// Separado de lib/motor.php em 04/11 (1.19.18), só movendo as funções, sem mudar nada.
// Carregado por lib/motor.php (require_once no topo).
// ===============================================

// Sufixo do código no CSV: "-5" Overlap, "-4" trainee (New Hire), "-2" Buffer, "-8" Operational
// Supervisor, "-7" Operational Coordinator e "-6" Operational Assistant (na página 5C Schedule,
// motivos que o site lê); true = assistente ("#").
function escMarcaCsv(array $a) {
    if ($a['origem'] === 'OVERLAP') {
        return '-5';
    }
    if ($a['origem'] === 'TREINO') {
        return ESC_TRN_SUFIXO;
    }
    if (isset(ESC_LID_SUFIXO[$a['origem']])) {
        return ESC_LID_SUFIXO[$a['origem']];
    }
    if ($a['origem'] === 'BUFFER' && $a['pagina'] === escV4Param('v4_overlap_pagina', '5C Schedule')) {
        return '-2';
    }
    return $a['papel'] === 'ASSISTENTE';
}

// $celulas[slot 'Y-m-dTHH:00:00'] = [rep => assistente(bool) ou o sufixo do código, ex. '-5']
// "A partir de" com base no site: antes do corte, cada célula é a do site (5c_schedule.scheduleday), como está,
// do dia da virada em diante. O papel vem do assistente do site. Na 5C Schedule (Overlap, plantão, liderança,
// trainee) a origem, que dá o sufixo do CSV, vem da versão publicada para a mesma pessoa e célula; senão, do
// cargo (OS -8, OC -7, OA -6, trainee -4) e, por fim, plantão (-2). Nas outras páginas fica como ajuste manual.
function escMotorPreCorteDoSite(string $seg, string $desde, array $refPub): array {
    $ini = max($seg, escV4DiaVirada());
    $fim = escMaisDias($seg, 6);
    $pub = [];
    foreach ($refPub as $l) {
        $pub[$l['dia'] . '|' . $l['bloco'] . '|' . (int) $l['page_id'] . '|' . strtoupper((string) $l['rep_code'])] = $l;
    }
    $overlap = escV4Param('v4_overlap_pagina', '5C Schedule');
    $cargo = [];
    foreach (escQ('SELECT UPPER(rep_code) AS rep, nivel FROM ' . escT('esc_lideranca') . ' WHERE rep_code IS NOT NULL AND vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)', [$fim, $ini]) as $l) {
        $cargo[$l['rep']] = $l['nivel'] === 'OA' ? 'LIDER_OA' : 'LIDER_OC';
    }
    foreach (escQ('SELECT DISTINCT UPPER(rep_code) AS rep FROM ' . escT('esc_colaborador_config') . " WHERE funcao = 'DECURIO' AND vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)", [$fim, $ini]) as $l) {
        $cargo[$l['rep']] = 'LIDER_OS';
    }
    if (function_exists('escTrnTurmas') && escTrnInstalado()) {
        foreach (escTrnTurmas(['RASCUNHO', 'ATIVA'], $ini, $fim) as $t) {
            foreach (array_keys($t['trainees']) as $r) {
                $cargo[strtoupper((string) $r)] ??= 'TREINO';
            }
        }
    }
    $turnoDo = ['T1' => 'D', 'T2' => 'A', 'T3' => 'A', 'T4' => 'C', 'T5' => 'C', 'T6' => 'D'];
    $out = [];
    foreach (escQ('SELECT shiftDateAndHour AS slot, UPPER(codigo) AS rep, modeloId AS page_id, modeloNome AS pagina, assitente, MAX(imported_at) AS em FROM `5c_schedule`.`scheduleday`
                    WHERE shiftDateAndHour >= ? AND shiftDateAndHour < ? AND codigo IS NOT NULL AND codigo <> \'\'
                    GROUP BY shiftDateAndHour, UPPER(codigo), modeloId, modeloNome, assitente', [$ini . 'T00:00:00', $desde]) as $l) {
        $dia = substr((string) $l['slot'], 0, 10);
        $bloco = ESC_BLOCOS[substr((string) $l['slot'], 11, 2)] ?? null;
        if ($bloco === null || $dia > $fim) {
            continue;
        }
        $rep = (string) $l['rep'];
        $k = $dia . '|' . $bloco . '|' . (int) $l['page_id'] . '|' . $rep;
        $origem = 'MANUAL';
        if ((string) $l['pagina'] === $overlap) {
            $origem = isset($pub[$k]) ? (string) $pub[$k]['origem'] : ($cargo[$rep] ?? 'BUFFER');
        }
        $out[] = ['dia' => $dia, 'bloco' => $bloco, 'turno' => $turnoDo[$bloco], 'page_id' => (int) $l['page_id'], 'pagina' => (string) $l['pagina'],
            'posto_id' => $pub[$k]['posto_id'] ?? null, 'vaga' => $pub[$k]['vaga'] ?? null, 'rep_code' => $rep,
            'papel' => (int) $l['assitente'] === 1 ? 'ASSISTENTE' : 'REGULAR', 'origem' => $origem, 'travada' => 0,
            'motivo' => mb_substr('igual ao site (5C Schedule, lido em ' . date('d/m H:i', strtotime((string) $l['em'])) . ')', 0, 255)];
    }
    // Antes da virada, ou sem nada no site para o período: fica a versão publicada.
    return $out ?: $refPub;
}

function escCsvT1T6(array $celulas, string $seg, string $desdeSlot = ''): string {
    $linhas = [];
    foreach (ESC_BLOCOS as $hora => $bloco) {
        $cols = [];
        for ($d = 0; $d < 7; $d++) {
            $slot = date('Y-m-d', strtotime("{$seg} +{$d} day")) . "T{$hora}:00:00";
            $cel = '';
            // No dia do corte, os blocos de antes dele também saem (iguais ao site), para o site ficar idêntico.
            if (($slot >= $desdeSlot || ($desdeSlot !== '' && substr($slot, 0, 10) === substr($desdeSlot, 0, 10))) && !empty($celulas[$slot])) {
                // O regular vai primeiro: o site trata como assistente todo código depois do primeiro,
                // e uma célula que começa pelo assistente fica sem regular.
                $regulares = $assistentes = [];
                foreach ($celulas[$slot] as $code => $assist) {
                    if ($assist === true) {
                        $assistentes[] = $code . '#';
                    } else {
                        $regulares[] = $code . (is_string($assist) ? $assist : '');
                    }
                }
                $cel = implode(', ', array_merge($regulares, $assistentes));
            }
            $cols[] = $cel;
        }
        $linhas[] = implode(';', $cols);
    }
    return implode("\n", $linhas);
}

function escHoraDoBloco(string $bloco): string {
    return array_search($bloco, ESC_BLOCOS, true) ?: '00';
}
