<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/dados.php
// Leituras usadas por mais de uma tela. Tudo SELECT (ver lib/db.php).
// ===============================================

// Estrutura atual (Planner): rep_code => time, party, papel.
// A view leva ~3 s; guardamos o resultado durante a requisição.
function escEstrutura(): array {
    static $mapa = null;
    if ($mapa !== null) {
        return $mapa;
    }
    $mapa = [];
    $linhas = escQ("SELECT rep_code, rep_name, team, party, role
                      FROM `5c_wfm_db_mis`.`vw_rep_estrutura_atual`
                     WHERE em_estrutura = 1 AND party IS NOT NULL AND party <> ''");
    foreach ($linhas as $l) {
        $mapa[strtoupper($l['rep_code'])] = $l;
    }
    return $mapa;
}

// Turno fixo e tipo (Time/Buffer) vigentes no headcount: code => linha.
function escTurnosVigentes(): array {
    static $mapa = null;
    if ($mapa !== null) {
        return $mapa;
    }
    $mapa = [];
    $linhas = escQ("SELECT code, turno, tipo_alocacao
                      FROM `5c_headcount`.`headcount_turno`
                     WHERE vigente_desde <= CURDATE()
                       AND (vigente_ate IS NULL OR vigente_ate >= CURDATE())");
    foreach ($linhas as $l) {
        $mapa[strtoupper($l['code'])] = $l;
    }
    return $mapa;
}

function turnoLetra(?string $turnoHc): string {
    return ['T2T3' => 'A', 'T4T5' => 'C', 'T6T1' => 'D'][$turnoHc ?? ''] ?? '';
}

// Formação de cada party: contagem por papel e páginas ativas.
// Conta a partir de escEstrutura() (já carregada na requisição), para a
// view do Planner, que é lenta, ser lida uma vez só por tela.
function escFormacaoParties(): array {
    $porParty = [];
    foreach (escEstrutura() as $e) {
        $chave = strtolower(trim($e['party']));
        if (!isset($porParty[$chave])) {
            $porParty[$chave] = ['team' => $e['team'], 'party' => $e['party'], 'p' => 0, 's' => 0, 't' => 0, 'paginas' => 0, 'nomes' => null];
        }
        if (ehPrimaris($e['role'])) {
            $porParty[$chave]['p']++;
        } elseif ($e['role'] === 'Secundus') {
            $porParty[$chave]['s']++;
        } elseif ($e['role'] === 'Tertius') {
            $porParty[$chave]['t']++;
        }
    }

    $paginas = escQ("SELECT partyName AS party, COUNT(*) AS paginas,
                            GROUP_CONCAT(name ORDER BY name SEPARATOR ', ') AS nomes
                       FROM `5c_bot`.`vw_pagina`
                      WHERE active = 1 AND partyName IS NOT NULL AND partyName <> ''
                      GROUP BY partyName");
    foreach ($paginas as $pg) {
        $chave = strtolower(trim($pg['party']));
        if (isset($porParty[$chave])) {
            $porParty[$chave]['paginas'] = (int) $pg['paginas'];
            $porParty[$chave]['nomes'] = $pg['nomes'];
        }
    }

    $linhas = array_values($porParty);
    usort($linhas, static fn($a, $b) => [$a['team'], $a['party']] <=> [$b['team'], $b['party']]);

    foreach ($linhas as &$l) {
        $regras = [];
        if ($l['p'] === 0) {
            $regras[] = ['sem_primaris', 'Sem Primaris', true, 'Definir o Primaris da party (promover um Secundus ou receber de outra party).'];
        }
        if ($l['p'] > 1) {
            $regras[] = ['dois_primaris', $l['p'] . ' Primaris', false, 'Mover o Primaris extra para uma party sem Primaris.'];
        }
        if ($l['s'] > 2) {
            $regras[] = ['secundus', $l['s'] . ' Secundus (máx. 2)', false, 'Mover ' . ($l['s'] - 2) . ' Secundus para party com falta ou passar a Tertius.'];
        }
        if ($l['s'] < 2) {
            $regras[] = ['secundus', 'Faltam ' . (2 - $l['s']) . ' Secundus', false, 'Receber Secundus de uma party com excesso.'];
        }
        if ($l['paginas'] === 0) {
            $regras[] = ['sem_pagina', 'Sem página ativa', true, 'Alocar página no Planner ou desfazer a party.'];
        }
        $l['regras'] = $regras;
    }
    unset($l);
    return $linhas;
}
