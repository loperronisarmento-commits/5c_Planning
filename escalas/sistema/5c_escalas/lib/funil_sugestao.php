<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/funil_sugestao.php
// Funil de entrada: os 3 destinos mais indicados (unidade · turno · núcleo) para uma
// página nova, um por unidade (a página entra na unidade nos 3 turnos; o núcleo indicado é o
// do turno de maior volume). Cada núcleo desse turno ganha uma nota de 0 a 100:
//   - Capacity (35): saldo de SRs da unidade depois de entrar a página (todos os turnos)
//     e do turno principal (Capacity mais recente, fc_necessidade_estrutura);
//   - Mérito da equipe (25): nota média dos SRs do núcleo (esc_sr_merito); pesa mais
//     para página de tier alto (SS, S, A) e menos para tier baixo;
//   - Carga do núcleo (20): fãs/h dos postos que o núcleo já é dono, por SR;
//   - Afinidade (20): a modelo já tem página na unidade (o Capacity junta páginas da mesma
//     modelo no mesmo posto, e a equipe já conhece o público).
//   - Equilíbrio (20, com os outros a 80%): unidade com faturamento total de 30 dias abaixo
//     da média no turno principal (lib/equilibrio.php).
// ===============================================

const ESC_FUNIL_LIMITE_POSTO = 35.0; // fãs/h por posto (limite padrão do Capacity)
const ESC_FUNIL_FATOR_SR = 1.5;      // SRs por posto (fator do Capacity)

// Dados da operação usados em todas as sugestões da tela (uma leitura por página aberta).
function escFunilContexto(string $dia): array {
    $vig = ' vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)';
    $cap = [];
    $dc = escQ1('SELECT MAX(data_calculo) AS d FROM `5c_planning`.`fc_necessidade_estrutura`');
    foreach ($dc && $dc['d'] ? escQ('SELECT unidade, turno, precisa, tem, saldo FROM `5c_planning`.`fc_necessidade_estrutura` WHERE data_calculo = ?', [$dc['d']]) : [] as $c) {
        $cap[(int) $c['unidade']][$c['turno']] = ['precisa' => (int) $c['precisa'], 'tem' => (int) $c['tem'], 'saldo' => (int) $c['saldo']];
    }
    $nucleos = [];
    foreach (escQ('SELECT n.id, n.unidade, n.turno, n.numero, n.decurio_nome,
                          (SELECT COUNT(*) FROM ' . escT('esc_colaborador_config') . " c WHERE c.nucleo_id = n.id AND c.funcao = 'EQUITE' AND c.vigente_desde <= ? AND (c.vigente_ate IS NULL OR c.vigente_ate >= ?)) AS srs,
                          (SELECT AVG(m.nota) FROM " . escT('esc_colaborador_config') . ' c JOIN ' . escT('esc_sr_merito') . " m ON m.rep_code = c.rep_code
                            WHERE c.nucleo_id = n.id AND c.funcao = 'EQUITE' AND c.vigente_desde <= ? AND (c.vigente_ate IS NULL OR c.vigente_ate >= ?)) AS nota,
                          (SELECT SUM(p.carga) FROM " . escT('esc_posto') . ' p WHERE p.nucleo_dono_id = n.id AND p.vigente_desde <= ? AND (p.vigente_ate IS NULL OR p.vigente_ate >= ?)) AS carga
                     FROM ' . escT('esc_nucleo') . ' n WHERE n.' . ltrim($vig), [$dia, $dia, $dia, $dia, $dia, $dia, $dia, $dia]) as $n) {
        $nucleos[(int) $n['id']] = ['id' => (int) $n['id'], 'unidade' => (int) $n['unidade'], 'turno' => $n['turno'], 'numero' => (int) $n['numero'], 'os' => (string) $n['decurio_nome'],
            'srs' => (int) $n['srs'], 'nota' => $n['nota'] !== null ? (float) $n['nota'] : null, 'carga' => (float) $n['carga']];
    }
    // Modelos (chave sem espaço, minúscula) e em que unidade e carteira já têm página.
    $modelos = [];
    foreach (escQ('SELECT pc.modelo, pc.pagina, pc.carteira, c.unidade FROM `5c_planning`.`fc_pagina_carteira` pc JOIN `5c_planning`.`fc_carteira` c ON c.carteira = pc.carteira
                    WHERE pc.vigente_ate IS NULL OR pc.vigente_ate > ?', [$dia]) as $m) {
        $modelos[escFunilChave((string) $m['modelo'])][(int) $m['unidade']] = ['pagina' => $m['pagina'], 'carteira' => $m['carteira']];
    }
    // Faturamento total de cada unidade e turno contra a média (lib/equilibrio.php).
    $desvio = function_exists('escEqDesvioUT') ? escEqDesvioUT(date('Y-m-d')) : [];
    return ['cap' => $cap, 'nucleos' => $nucleos, 'modelos' => $modelos, 'desvio' => $desvio, 'data_capacity' => $dc['d'] ?? null];
}

// Arquétipos: fãs/h médios por tier (Intraday) e perfil (Main, VIP, Free, OFTV), das páginas com
// 14 dias ou mais de chat no último mês. Base do volume de página nova (Funil e estrutura).
function escFunilArquetipos(): array {
    return escQ("WITH tp AS (
                            SELECT Page COLLATE utf8mb4_unicode_ci AS pagina, MAX(Tier) AS tier
                              FROM `5c_wfm_db_mis`.`tab_intraday_base`
                             WHERE `Date` >= CURDATE() - INTERVAL 8 DAY AND `Date` < CURDATE() AND Tier IS NOT NULL
                             GROUP BY Page
                        ),
                        pt AS (
                            SELECT pagina COLLATE utf8mb4_unicode_ci AS pagina, turno, AVG(fans) AS fh, COUNT(DISTINCT dia_turno) AS dias
                              FROM `5c_planning`.`fc_fans_pagina_hora`
                             WHERE dia_turno >= CURDATE() - INTERVAL 29 DAY AND dia_turno < CURDATE() - INTERVAL 1 DAY
                             GROUP BY pagina, turno
                        ),
                        j AS (
                            SELECT tp.tier,
                                   CASE WHEN tp.pagina LIKE '%VIP%' THEN 'VIP'
                                        WHEN tp.pagina LIKE '%free%' THEN 'Free'
                                        WHEN tp.pagina LIKE '%OFTV%' THEN 'OFTV'
                                        ELSE 'Main' END AS perfil,
                                   tp.pagina, pt.turno, pt.fh
                              FROM tp JOIN pt ON pt.pagina = tp.pagina
                             WHERE pt.dias >= 14
                        ),
                        pg AS (
                            SELECT tier, perfil, pagina, AVG(fh) AS fh FROM j GROUP BY tier, perfil, pagina
                        )
                        SELECT j.tier, j.perfil, COUNT(DISTINCT j.pagina) AS paginas,
                               AVG(IF(j.turno = 'A', j.fh, NULL)) AS a,
                               AVG(IF(j.turno = 'C', j.fh, NULL)) AS c,
                               AVG(IF(j.turno = 'D', j.fh, NULL)) AS d,
                               (SELECT MIN(fh) FROM pg WHERE pg.tier = j.tier AND pg.perfil = j.perfil) AS minimo,
                               (SELECT MAX(fh) FROM pg WHERE pg.tier = j.tier AND pg.perfil = j.perfil) AS maximo
                          FROM j
                         GROUP BY j.tier, j.perfil
                         ORDER BY FIELD(j.tier, 'SS', 'S', 'A', 'B', 'C', 'D', 'E', 'F'), j.perfil");
}

function escFunilChave(string $s): string {
    $s = strtolower(function_exists('iconv') ? (iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s) : $s);
    return preg_replace('~[^a-z0-9]~', '', $s);
}

// Fãs/h previstos da página por turno: o que foi informado na previsão; senão, o arquétipo do
// tier e perfil; senão, a média do perfil. Devolve [['A' => .., 'C' => .., 'D' => ..], origem].
function escFunilVolume(?array $pv, ?string $tier, array $arquetipos): array {
    if ($pv && ($pv['fans_h_a'] !== null || $pv['fans_h_c'] !== null || $pv['fans_h_d'] !== null)) {
        return [['A' => (float) $pv['fans_h_a'], 'C' => (float) $pv['fans_h_c'], 'D' => (float) $pv['fans_h_d']], 'previsao'];
    }
    $perfil = ['MAIN' => 'Main', 'VIP' => 'VIP', 'FREE' => 'Free', 'OFTV' => 'OFTV'][$pv['perfil'] ?? 'MAIN'] ?? 'Main';
    $mesmo = array_values(array_filter($arquetipos, static fn($a) => $a['perfil'] === $perfil && ($tier === null || $a['tier'] === $tier)));
    if (!$mesmo) {
        $mesmo = array_values(array_filter($arquetipos, static fn($a) => $a['perfil'] === $perfil)) ?: $arquetipos;
    }
    if (!$mesmo) {
        return [['A' => 10.0, 'C' => 10.0, 'D' => 10.0], 'padrao'];
    }
    $media = static fn(string $k) => array_sum(array_map(static fn($a) => (float) $a[$k], $mesmo)) / count($mesmo);
    return [['A' => $media('a'), 'C' => $media('c'), 'D' => $media('d')], $tier !== null && count($mesmo) && $mesmo[0]['tier'] === $tier ? 'arquetipo' : 'perfil'];
}

// Os 3 destinos mais indicados. $nomes: nomes da página e da cliente (para achar a modelo).
function escFunilSugerir(array $vol, ?string $tier, array $nomes, array $ctx): array {
    if (!$ctx['nucleos']) {
        return [];
    }
    $postos = [];
    $precisa = [];
    foreach (['A', 'C', 'D'] as $t) {
        $postos[$t] = $vol[$t] > 0 ? (int) ceil($vol[$t] / ESC_FUNIL_LIMITE_POSTO) : 0;
        $precisa[$t] = (int) ceil($postos[$t] * ESC_FUNIL_FATOR_SR);
    }
    $principal = array_keys($vol, max($vol))[0];
    // Pesos: mérito pesa mais para tier alto.
    $alto = in_array($tier, ['SS', 'S', 'A'], true);
    $baixo = in_array($tier, ['D', 'E', 'F'], true);
    $peso = ['cap_u' => 25, 'cap_t' => 10, 'merito' => $alto ? 35 : ($baixo ? 15 : 25), 'carga' => 20, 'afinidade' => 20];
    if ($alto) {
        $peso['cap_u'] = 15;
    } elseif ($baixo) {
        $peso['cap_u'] = 35;
    }
    // Equilíbrio (20): unidade com faturamento total abaixo da média no turno principal tem
    // prioridade (o gap que as trocas de unidade não cobrem). Os outros pesos caem para 80%.
    $desvios = [];
    foreach ($ctx['desvio'] ?? [] as $u => $porT) {
        $desvios[$u] = $porT[$principal] ?? null;
    }
    if (array_filter($desvios, static fn($d) => $d !== null)) {
        $peso = array_map(static fn($p) => (int) round($p * 0.8), $peso) + ['equilibrio' => 20];
    }
    // Afinidade: a modelo já tem página em alguma unidade.
    $chaves = array_filter(array_map('escFunilChave', $nomes), static fn($k) => strlen($k) >= 3);
    $afinidade = [];
    foreach ($ctx['modelos'] as $modelo => $porUnidade) {
        foreach ($chaves as $k) {
            if (strlen($modelo) >= 3 && ($k === $modelo || strpos($k, $modelo) === 0 || strpos($modelo, $k) === 0)) {
                foreach ($porUnidade as $u => $info) {
                    $afinidade[$u] = $info;
                }
            }
        }
    }
    // Saldo da unidade (soma dos turnos) e do turno principal depois de entrar a página.
    $saldoU = [];
    $saldoT = [];
    foreach ($ctx['cap'] as $u => $porTurno) {
        $saldoU[$u] = 0;
        foreach (['A', 'C', 'D'] as $t) {
            $saldoU[$u] += ($porTurno[$t]['saldo'] ?? 0) - $precisa[$t];
        }
        $saldoT[$u] = ($porTurno[$principal]['saldo'] ?? 0) - $precisa[$principal];
    }
    $norm = static function (array $valores, $v, bool $maiorMelhor = true): float {
        $valores = array_values(array_filter($valores, static fn($x) => $x !== null));
        if ($v === null || !$valores || max($valores) == min($valores)) {
            return 0.5;
        }
        $x = ($v - min($valores)) / (max($valores) - min($valores));
        return $maiorMelhor ? $x : 1 - $x;
    };
    $doTurno = array_filter($ctx['nucleos'], static fn($n) => $n['turno'] === $principal);
    $cargaSr = static fn($n) => $n['srs'] > 0 ? $n['carga'] / $n['srs'] : null;
    $notas = array_map(static fn($n) => $n['nota'], $doTurno);
    $cargas = array_map($cargaSr, $doTurno);
    $lista = [];
    foreach ($doTurno as $n) {
        $u = $n['unidade'];
        $f = [
            'cap_u' => $norm($saldoU, $saldoU[$u] ?? null),
            'cap_t' => $norm($saldoT, $saldoT[$u] ?? null),
            'merito' => $norm($notas, $n['nota']),
            'carga' => $norm($cargas, $cargaSr($n), false),
            'afinidade' => isset($afinidade[$u]) ? 1.0 : 0.0,
            'equilibrio' => $norm($desvios, $desvios[$u] ?? null, false),
        ];
        $nota = 0.0;
        foreach ($peso as $k => $p) {
            $nota += $p * $f[$k];
        }
        $lista[] = ['nucleo' => $n, 'nota' => round($nota), 'fatores' => $f, 'desvio' => $desvios[$u] ?? null,
            'saldo_u_antes' => array_sum(array_map(static fn($x) => $x['saldo'], $ctx['cap'][$u] ?? [])), 'saldo_u_depois' => $saldoU[$u] ?? null,
            'saldo_t_depois' => $saldoT[$u] ?? null, 'carga_sr' => $cargaSr($n), 'afinidade' => $afinidade[$u] ?? null];
    }
    usort($lista, static fn($a, $b) => [$b['nota'], $b['fatores']['merito']] <=> [$a['nota'], $a['fatores']['merito']]);
    // O melhor núcleo de cada unidade, na ordem da nota (a escolha que importa é a unidade).
    $top = [];
    foreach ($lista as $l) {
        $top[$l['nucleo']['unidade']] = $top[$l['nucleo']['unidade']] ?? $l;
    }
    return ['principal' => $principal, 'postos' => $postos, 'precisa' => $precisa, 'peso' => $peso, 'top' => array_slice(array_values($top), 0, 3)];
}
