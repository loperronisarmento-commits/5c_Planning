<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/dados.php
// Leituras usadas por mais de uma tela. Tudo SELECT (ver lib/db.php).
// ===============================================

// Cache em arquivo (pasta temporária do servidor) para leituras lentas e que mudam pouco.
// ?recarregar=1 na URL ignora o cache.
function escCache(string $chave, int $segundos, callable $ler): array {
    $arq = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . '5c_escalas_' . preg_replace('~[^a-z0-9_]~i', '_', $chave) . '.json';
    if (empty($_GET['recarregar']) && is_file($arq) && filemtime($arq) > time() - $segundos) {
        $dados = json_decode((string) @file_get_contents($arq), true);
        if (is_array($dados)) {
            return $dados;
        }
    }
    $dados = $ler();
    $tmp = $arq . '.' . getmypid();
    if (@file_put_contents($tmp, json_encode($dados, JSON_UNESCAPED_UNICODE)) !== false) {
        @rename($tmp, $arq);
    }
    return $dados;
}

// Estrutura atual (Planner): rep_code => time, party, papel.
// A view leva ~3 s: fica 5 minutos em cache.
function escEstrutura(): array {
    static $mapa = null;
    if ($mapa !== null) {
        return $mapa;
    }
    $mapa = escCache('estrutura', 300, static function (): array {
        $m = [];
        foreach (escQ("SELECT rep_code, rep_name, team, party, role
                         FROM `5c_wfm_db_mis`.`vw_rep_estrutura_atual`
                        WHERE em_estrutura = 1 AND party IS NOT NULL AND party <> ''") as $l) {
            $m[strtoupper($l['rep_code'])] = $l;
        }
        return $m;
    });
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

// Nome de cada código: cadastro do headcount (nome social quando tem) e, para quem
// não está nele, o controle de turnos. Não usa a view do Planner (lenta).
function escNomes(): array {
    static $mapa = null;
    if ($mapa !== null) {
        return $mapa;
    }
    $mapa = escCache('nomes', 300, static function (): array {
        $m = [];
        foreach (escQ('SELECT UPPER(code) AS code, MAX(nome_controle) AS nome FROM `5c_headcount`.`headcount_turno` GROUP BY UPPER(code)') as $l) {
            $m[$l['code']] = $l['nome'];
        }
        foreach (escQ("SELECT UPPER(code) AS code, name, socialName FROM `5c_headcount`.`headcount` WHERE code IS NOT NULL AND code <> ''") as $l) {
            $nome = trim((string) $l['socialName']) !== '' ? trim((string) $l['socialName']) : trim((string) $l['name']);
            if ($nome !== '') {
                $m[$l['code']] = $nome;
            }
        }
        return $m;
    });
    return $mapa;
}

function nomeCurto(?string $nome): string {
    $partes = preg_split('~\s+~', trim((string) $nome));
    if (count($partes) <= 2) {
        return (string) $nome;
    }
    return $partes[0] . ' ' . end($partes);
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
            $regras[] = ['sem_primaris', t('Sem líder'), true, t('Definir o líder da party (promover um titular ou receber de outra party).')];
        }
        if ($l['p'] > 1) {
            $regras[] = ['dois_primaris', t('{n} líderes', ['n' => $l['p']]), false, t('Mover o líder extra para uma party sem líder.')];
        }
        if ($l['s'] > 2) {
            $regras[] = ['secundus', t('{n} titulares (máx. 2)', ['n' => $l['s']]), false, t('Mover {n} titular(es) para party com falta ou passar ao rodízio.', ['n' => $l['s'] - 2])];
        }
        if ($l['s'] < 2) {
            $regras[] = ['secundus', t('Faltam {n} titulares', ['n' => 2 - $l['s']]), false, t('Receber titulares de uma party com excesso.')];
        }
        if ($l['paginas'] === 0) {
            $regras[] = ['sem_pagina', t('Sem página ativa'), true, t('Alocar página no Planner ou desfazer a party.')];
        }
        $l['regras'] = $regras;
    }
    unset($l);
    return $linhas;
}
