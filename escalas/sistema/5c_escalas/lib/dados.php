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
function escFormacaoParties(): array {
    $linhas = escQ("WITH e AS (
                        SELECT team, party, role
                          FROM `5c_wfm_db_mis`.`vw_rep_estrutura_atual`
                         WHERE em_estrutura = 1 AND party IS NOT NULL AND party <> ''
                    ),
                    f AS (
                        SELECT team, party,
                               SUM(role LIKE '%Primaris') AS p,
                               SUM(role = 'Secundus') AS s,
                               SUM(role = 'Tertius') AS t
                          FROM e GROUP BY team, party
                    ),
                    pg AS (
                        SELECT partyName COLLATE utf8mb4_unicode_ci AS party,
                               COUNT(*) AS paginas,
                               GROUP_CONCAT(name ORDER BY name SEPARATOR ', ') AS nomes
                          FROM `5c_bot`.`vw_pagina`
                         WHERE active = 1 AND partyName IS NOT NULL AND partyName <> ''
                         GROUP BY partyName
                    )
                    SELECT f.team, f.party, f.p, f.s, f.t,
                           COALESCE(pg.paginas, 0) AS paginas, pg.nomes
                      FROM f
                      LEFT JOIN pg ON pg.party = f.party COLLATE utf8mb4_unicode_ci
                     ORDER BY f.team, f.party");

    foreach ($linhas as &$l) {
        $l['p'] = (int) $l['p'];
        $l['s'] = (int) $l['s'];
        $l['t'] = (int) $l['t'];
        $l['paginas'] = (int) $l['paginas'];
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
