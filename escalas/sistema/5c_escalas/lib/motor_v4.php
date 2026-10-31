<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/motor_v4.php
// Motor da estrutura de 01/11/2026 (spec v4).
//
// Unidade → turno → 3 núcleos (OS + SRs) na rotação de 21 dias
// (P1 ter/sáb/dom · P2 seg/sex · P3 qua/qui): todo dia 2 núcleos de cada
// unidade e turno trabalham. Cada posto (vem do Capacity, foto em
// esc_posto) tem a vaga do DONO (núcleo dono, nos dias dele) e a de
// COBERTURA (outro núcleo, nos dias de folga do dono).
//
// Preenchimento de cada vaga, por dia, unidade e turno:
//   1. equipe fixa da vaga (esc_vaga_equipe), quem está mais abaixo da
//      própria fatia primeiro — a equipe é PREFERÊNCIA, não trava;
//   2. outra pessoa do mesmo núcleo, primeiro quem já atua nas páginas;
//   3. buffer: pessoa do outro núcleo em serviço no mesmo turno;
//   4. o que sobrar vira lacuna.
// Quem fica sem vaga no dia é o buffer de plantão do núcleo.
//
// A estrutura nova começa no parâmetro v4_virada (dia e hora): às 05:00 no
// turno A, às 21:00 no turno D (T6 do dia + T1 do dia seguinte). O que vem
// antes sai do scheduleday no CSV. Os dias de v4_dia_transicao (separados por
// vírgula) são montados com quem já está escalado no scheduleday, no turno em
// que está.
//
// Saída no mesmo formato do escMotorAlocar (v3), com posto_id e vaga.
// ===============================================

const ESC_V4_ORDEM_TIPO = ['titular' => 0, 'double' => 1, 'apoio' => 2, 'assistente' => 3];

function escV4Param(string $chave, string $padrao): string {
    static $p = null;
    if ($p === null) {
        $p = [];
        foreach (escQ('SELECT chave, valor FROM ' . escT('esc_parametro') . " WHERE grupo IN ('v4', 'merito')") as $l) {
            $p[$l['chave']] = $l['valor'];
        }
    }
    return (string) ($p[$chave] ?? $padrao);
}

function escV4DiaVirada(): string {
    return substr(escV4Param('v4_virada', '2026-11-01 05:00'), 0, 10);
}

// Postagem da semana da virada: o que vem antes da virada continua a escala já postada no site.
// Devolve o primeiro bloco da estrutura nova ('Y-m-dTHH:00:00') se a virada cai depois do início
// da semana $seg (segunda T1), senão ''. A Grade apaga e posta só daí em diante.
function escPostagemCorte(string $seg): string {
    $virada = escV4Param('v4_virada', '2026-11-01 05:00');
    $corte = substr($virada, 0, 10) . 'T' . substr($virada, 11, 2) . ':00:00';
    return $corte > $seg . 'T01:00:00' && $corte < escMaisDias($seg, 7) . 'T01:00:00' ? $corte : '';
}

// Primeiro turno da estrutura nova no dia da virada: 05:00 → A, 13:00 → C, 21:00 → D
// (a virada às 21:00 começa no turno D: T6 do dia + T1 do dia seguinte).
function escV4TurnoVirada(): string {
    $h = (int) substr(escV4Param('v4_virada', '2026-11-01 05:00'), 11, 2);
    return $h >= 21 ? 'D' : ($h >= 13 ? 'C' : 'A');
}

// O turno já é da estrutura nova nesse dia?
function escV4TurnoNovo(string $dia, string $turno, string $virada, string $turnoVirada): bool {
    $ordem = ['A' => 0, 'C' => 1, 'D' => 2];
    return $dia > $virada || ($dia === $virada && ($ordem[$turno] ?? 0) >= ($ordem[$turnoVirada] ?? 0));
}

// Dia em que a estrutura nova (postos, núcleos, equipes) já vale: com a virada no turno C ou D,
// o dia seguinte (o turno D da virada usa a estrutura do dia seguinte).
function escV4DiaEstrutura(): string {
    return escV4TurnoVirada() === 'A' ? escV4DiaVirada() : escMaisDias(escV4DiaVirada(), 1);
}

// Dias de transição (parâmetro v4_dia_transicao, separados por vírgula; vazio = sem transição).
function escV4DiasTransicao(): array {
    $v = escV4Param('v4_dia_transicao', escV4DiaVirada());
    return array_values(array_filter(array_map(static fn($x) => substr(trim($x), 0, 10), explode(',', $v)), 'strlen'));
}

// A estrutura antiga (times e parties) ainda vale agora? Depois da virada as telas dela ficam
// só para consulta (grupo Legado do menu).
function escLegadoAtivo(): bool {
    return time() < strtotime(escV4Param('v4_virada', '2026-11-01 05:00'));
}

// A semana tem algum dia na estrutura nova?
function escV4Ativo(string $seg): bool {
    return escMaisDias($seg, 6) >= escV4DiaVirada();
}

// Posição do núcleo no ciclo de 21 dias nesse dia.
function escV4IndiceCiclo(array $nucleo, string $ciclo, string $ancora, string $dia): int {
    $L = strlen($ciclo);
    $dias = (int) round((strtotime($dia) - strtotime($ancora)) / 86400);
    return ((($dias + (int) $nucleo['deslocamento']) % $L) + $L) % $L;
}

function escV4NucleoTrabalha(array $nucleo, string $ciclo, string $ancora, string $dia): bool {
    return $ciclo[escV4IndiceCiclo($nucleo, $ciclo, $ancora, $dia)] === '1';
}

// Faturamento de arquétipo das páginas novas: a mediana, por turno, das páginas do mesmo tier
// que já faturam (as novas ficam fora da conta). A página nova fica com o maior entre o
// faturamento dela e o do arquétipo. $receita[pagina] = [A, C, D]; $tierDe[pagina] = tier.
function escV4ReceitaArquetipo(array $receita, array $tierDe, array $novas): array {
    $porTier = [];
    foreach ($receita as $pg => $r) {
        $t = $tierDe[$pg] ?? null;
        if ($t === null || isset($novas[$pg]) || array_sum($r) <= 0) {
            continue;
        }
        foreach (['A', 'C', 'D'] as $tu) {
            $porTier[$t][$tu][] = (float) ($r[$tu] ?? 0);
        }
    }
    $mediana = static function (array $v): float {
        sort($v);
        $k = count($v);
        return $k ? ($k % 2 ? $v[intdiv($k, 2)] : ($v[$k / 2 - 1] + $v[$k / 2]) / 2) : 0.0;
    };
    foreach ($novas as $pg => $_) {
        $t = $tierDe[$pg] ?? null;
        if ($t === null || !isset($porTier[$t])) {
            continue;
        }
        foreach (['A', 'C', 'D'] as $tu) {
            $receita[$pg][$tu] = max((float) ($receita[$pg][$tu] ?? 0), $mediana($porTier[$t][$tu]));
        }
    }
    return $receita;
}

// ---------- leituras ----------

function escV4Carregar(string $dia): array {
    $vig = ' vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)';
    $modelo = escQ1('SELECT ciclo, max_seguidos FROM ' . escT('esc_modelo_escala') . " WHERE nome = '5x2 rotativo 21 dias'");
    if (!$modelo) {
        throw new RuntimeException(t('Falta o modelo "5x2 rotativo 21 dias". Rode o ES_04.'));
    }
    $nucleos = [];
    foreach (escQ('SELECT id, unidade, turno, numero, decurio_code, decurio_nome, deslocamento FROM ' . escT('esc_nucleo') . ' WHERE' . $vig, [$dia, $dia]) as $n) {
        $nucleos[(int) $n['id']] = $n;
    }
    $pessoas = [];
    foreach (escQ('SELECT UPPER(rep_code) AS rep, unidade, turno, nucleo_id, nivel, pode_assistente FROM ' . escT('esc_colaborador_config')
        . " WHERE funcao = 'EQUITE' AND nucleo_id IS NOT NULL AND" . $vig . ' ORDER BY vigente_desde', [$dia, $dia]) as $p) {
        $pessoas[$p['rep']] = ['rep' => $p['rep'], 'unidade' => (int) $p['unidade'], 'turno' => $p['turno'],
            'nucleo' => (int) $p['nucleo_id'], 'nivel' => (int) $p['nivel'], 'pode_assistente' => (int) ($p['pode_assistente'] ?? 1) === 1];
    }
    $postos = [];
    foreach (escQ('SELECT id, unidade, carteira, turno, seq, tipo, pagina_principal, carga, capacidade, nucleo_dono_id, nucleo_cobertura_id FROM '
        . escT('esc_posto') . ' WHERE' . $vig, [$dia, $dia]) as $p) {
        $postos[(int) $p['id']] = $p + ['paginas' => []];
    }
    $nomes = [];
    if ($postos) {
        foreach (escQ('SELECT posto_id, pagina, page_id, apoio, fracao FROM ' . escT('esc_posto_pagina') . ' WHERE posto_id IN (' . escIn(array_keys($postos)) . ')', array_keys($postos)) as $pp) {
            $postos[(int) $pp['posto_id']]['paginas'][] = ['pagina' => $pp['pagina'], 'page_id' => (int) $pp['page_id'], 'apoio' => (int) $pp['apoio'] === 1,
                'fracao' => (float) $pp['fracao']];
            $nomes[$pp['pagina']] = true;
        }
    }
    // Faturamento médio por dia de cada página e turno (US$, statement por bloco): ordem de preenchimento.
    $receita = [];
    if ($nomes) {
        $n = max(1, (int) escV4Param('v4_receita_dias', '28'));
        foreach (escQ("SELECT CREATOR_NAME AS pagina,
                              SUM(CASE WHEN Turno IN ('T2','T3') THEN US_AMOUNT ELSE 0 END) AS a,
                              SUM(CASE WHEN Turno IN ('T4','T5') THEN US_AMOUNT ELSE 0 END) AS c,
                              SUM(CASE WHEN Turno IN ('T6','T1') THEN US_AMOUNT ELSE 0 END) AS d
                         FROM `5c_wfm_db_mis`.`tab_statement_interval`
                        WHERE Date >= DATE_SUB(CURDATE(), INTERVAL {$n} DAY) AND Date < CURDATE() AND STATUS = 'complete'
                          AND CREATOR_NAME IN (" . escIn(array_keys($nomes)) . ')
                        GROUP BY CREATOR_NAME', array_keys($nomes)) as $r) {
            $receita[$r['pagina']] = ['A' => (float) $r['a'] / $n, 'C' => (float) $r['c'] / $n, 'D' => (float) $r['d'] / $n];
        }
        // Página nova (cadastrada no site há menos de v4_receita_dias): ainda sem faturamento
        // próprio, entra na fila com o arquétipo do tier dela (pedido de 31/10).
        $tierDe = [];
        $novas = [];
        foreach (escQ('SELECT name, tier, imported_at FROM `5c_pages`.`pages` WHERE active = 1 AND name IN (' . escIn(array_keys($nomes)) . ')', array_keys($nomes)) as $g) {
            $tierDe[$g['name']] = $g['tier'] !== null && $g['tier'] !== '' ? (string) $g['tier'] : null;
            if ($g['imported_at'] !== null && substr((string) $g['imported_at'], 0, 10) > date('Y-m-d', strtotime('-' . $n . ' day'))) {
                $novas[$g['name']] = true;
            }
        }
        $receita = escV4ReceitaArquetipo($receita, $tierDe, $novas);
    }
    // Modelo de cada página (Capacity): o over só junta páginas dentro da Política.
    $modeloPag = [];
    if ($nomes) {
        foreach (escQ('SELECT pagina, modelo FROM `5c_planning`.`fc_pagina_carteira` WHERE vigente_ate IS NULL AND pagina IN (' . escIn(array_keys($nomes)) . ')', array_keys($nomes)) as $r) {
            $modeloPag[$r['pagina']] = $r['modelo'];
        }
    }
    $pagOverlap = escQ1('SELECT id, name FROM `5c_pages`.`pages` WHERE name = ? ORDER BY active DESC, id LIMIT 1', [escV4Param('v4_overlap_pagina', '5C Schedule')]);

    // Melhores SRs nas páginas dos tiers prioritários (Intraday: SS, A, B): venda por hora do SR
    // na página (cap_rep_hora, horas só nela, últimos v4_receita_dias dias) e a geral dele.
    $tiersPrio = array_filter(array_map('trim', explode(',', escV4Param('v4_tiers_melhores_srs', 'SS,A,B'))));
    $melhores = ['tiers' => array_fill_keys($tiersPrio, true), 'tier' => [], 'pagina' => [], 'geral' => []];
    if ($nomes && $tiersPrio && $pessoas) {
        foreach (escQ('SELECT Page AS pagina, MAX(Tier) AS tier FROM `5c_wfm_db_mis`.`tab_intraday_base`
                        WHERE Date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND Page IN (' . escIn(array_keys($nomes)) . ') GROUP BY Page', array_keys($nomes)) as $r) {
            $melhores['tier'][$r['pagina']] = (string) $r['tier'];
        }
        $prio = array_keys(array_filter($melhores['tier'], static fn($t) => isset($melhores['tiers'][$t])));
        $codDe = [];
        foreach (escQ('SELECT UPPER(rep_code) AS rep, code_infloww FROM `5c_wfm_db_mis`.`vw_rep_estrutura_atual` WHERE code_infloww IS NOT NULL AND rep_code IN (' . escIn(array_keys($pessoas)) . ')', array_keys($pessoas)) as $r) {
            $codDe[$r['code_infloww']] = $r['rep'];
        }
        $n = max(1, (int) escV4Param('v4_receita_dias', '28'));
        if ($codDe) {
            foreach (escQ("SELECT SUBSTRING_INDEX(rep, ' ', 1) AS cod, SUM(valor_comprado) / COUNT(*) AS usd_h
                             FROM `5c_capacity`.`cap_rep_hora` WHERE dia >= DATE_SUB(CURDATE(), INTERVAL {$n} DAY) GROUP BY cod") as $r) {
                if (isset($codDe[$r['cod']])) {
                    $melhores['geral'][$codDe[$r['cod']]] = (float) $r['usd_h'];
                }
            }
            if ($prio) {
                foreach (escQ("SELECT SUBSTRING_INDEX(rep, ' ', 1) AS cod, paginas AS pagina, SUM(valor_comprado) / COUNT(*) AS usd_h
                                 FROM `5c_capacity`.`cap_rep_hora`
                                WHERE dia >= DATE_SUB(CURDATE(), INTERVAL {$n} DAY) AND n_paginas = 1 AND paginas IN (" . escIn($prio) . ')
                                GROUP BY cod, paginas HAVING COUNT(*) >= 3', $prio) as $r) {
                    if (isset($codDe[$r['cod']])) {
                        $melhores['pagina'][$r['pagina']][$codDe[$r['cod']]] = (float) $r['usd_h'];
                    }
                }
            }
        }
    }
    $equipes = [];
    foreach (escQ('SELECT posto_id, vaga, UPPER(rep_code) AS rep, fatia FROM ' . escT('esc_vaga_equipe') . ' WHERE' . $vig, [$dia, $dia]) as $e) {
        $equipes[(int) $e['posto_id']][$e['vaga']][$e['rep']] = (float) $e['fatia'];
    }
    // Quem já conhece cada página: dias escalados nela no 5C Schedule (menos troca de gente).
    $historico = [];
    if ($pessoas && $nomes) {
        $hd = max(7, (int) escV4Param('v4_historico_dias', '56'));
        foreach (escQ('SELECT UPPER(codigo) AS rep, modeloNome AS pagina, COUNT(DISTINCT LEFT(shiftDateAndHour, 10)) AS dias
                         FROM `5c_schedule`.`scheduleday`
                        WHERE codigo IN (' . escIn(array_keys($pessoas)) . ') AND shiftDateAndHour >= ? AND shiftDateAndHour < ?
                        GROUP BY UPPER(codigo), modeloNome', array_merge(array_keys($pessoas), [escMaisDias($dia, -$hd - 7) . 'T00:00:00', $dia . 'T00:00:00'])) as $r) {
            if (isset($nomes[$r['pagina']])) {
                $historico[$r['rep']][$r['pagina']] = (int) $r['dias'];
            }
        }
    }
    $overlap = escV4Param('v4_overlap_posicao_ciclo', '');
    return ['modelo' => $modelo, 'nucleos' => $nucleos, 'pessoas' => $pessoas, 'postos' => $postos, 'equipes' => $equipes,
        'historico' => $historico, 'merito' => function_exists('escMeritoCarregar') ? escMeritoNotasMotor(escMeritoCarregar()) : [],
        'ancora' => escV4Param('v4_rotacao_ancora', '2026-11-02'),
        'overlap_posicoes' => array_map('intval', array_filter(array_map('trim', explode(',', $overlap)), 'strlen')),
        'overlap_pagina' => $pagOverlap ? ['page_id' => (int) $pagOverlap['id'], 'pagina' => $pagOverlap['name']] : null,
        'receita' => $receita, 'modelo_pagina' => $modeloPag, 'over_max' => (float) escV4Param('v4_over_capacity_max', '0.15'),
        'melhores' => $melhores] + (function_exists('escCasaisMotor') ? escCasaisMotor($dia) : []);
}

// Quem atuou por último em cada posto no plano atual da semana anterior: a semana nova
// começa com a mesma pessoa no posto (menos troca de página de uma semana para a outra).
function escV4UltimoDoPlanoAnterior(string $seg): array {
    $ant = escPlanoPublicado(escMaisDias($seg, -7)) ?? escPlanoAtual(escMaisDias($seg, -7));
    if (!$ant) {
        return [];
    }
    $ultimo = [];
    foreach (escQ("SELECT posto_id, UPPER(rep_code) AS rep FROM " . escT('esc_plano_alocacao') . "
                    WHERE plano_id = ? AND posto_id IS NOT NULL AND rep_code IS NOT NULL
                      AND origem NOT IN ('OVERLAP','BUFFER','SOBRECARGA') ORDER BY dia, bloco", [(int) $ant['id']]) as $r) {
        $ultimo[(int) $r['posto_id']] = $r['rep'];
    }
    return $ultimo;
}

// Capacidade de um SR com as páginas de 2 postos juntas, pela Política do Capacity:
// mesma modelo até 3 páginas (35 × (1 − 10% × (n − 1))); modelos diferentes até 2 (28).
// null = a Política não deixa juntar.
function escV4CapacidadeJunta(array $a, array $b, array $modeloPag): ?float {
    $pags = [];
    foreach (array_merge($a['paginas'], $b['paginas']) as $pg) {
        $pags[$pg['pagina']] = $modeloPag[$pg['pagina']] ?? ('?' . $pg['pagina']);
    }
    $n = count($pags);
    if (count(array_unique($pags)) === 1 && $n <= 3) {
        return 35.0 * (1 - 0.10 * ($n - 1));
    }
    return $n <= 2 ? 28.0 : null;
}

// Quem fecha o turno D às 05:00 de $dia (T1): do plano da semana anterior; sem
// ele, o T1 postado ou, se não houver, quem está no T6 postado da véspera.
function escV4FimTurnoD(string $dia): array {
    $segAnt = segundaDaSemana(escMaisDias($dia, -1));
    $ant = escPlanoPublicado($segAnt) ?? escPlanoAtual($segAnt);
    if ($ant) {
        $l = escQ('SELECT DISTINCT UPPER(rep_code) AS code FROM ' . escT('esc_plano_alocacao') . " WHERE plano_id = ? AND dia = ? AND bloco = 'T1' AND rep_code IS NOT NULL", [(int) $ant['id'], $dia]);
        if ($l) {
            return array_column($l, 'code');
        }
    }
    foreach ([$dia . 'T01:00:00', escMaisDias($dia, -1) . 'T21:00:00'] as $slot) {
        $l = escQ('SELECT DISTINCT UPPER(codigo) AS code FROM `5c_schedule`.`scheduleday` WHERE shiftDateAndHour = ? AND modeloId <> 3', [$slot]);
        if ($l) {
            return array_column($l, 'code');
        }
    }
    return [];
}

// Quem já está escalado no dia da virada (scheduleday), por turno: T2/T3 → A, T4/T5 → C, T6 → D.
// Só SRs da estrutura nova entram (quem vai virar OS, OC ou OM fica de fora).
function escV4PresentesTransicao(string $dia): array {
    $linhas = escQ("SELECT UPPER(codigo) AS code, SUBSTRING(shiftDateAndHour, 12, 2) AS hora
                      FROM `5c_schedule`.`scheduleday`
                     WHERE shiftDateAndHour >= ? AND shiftDateAndHour < ? AND modeloId <> 3
                     GROUP BY code, hora", [$dia . 'T05:00:00', escMaisDias($dia, 1) . 'T01:00:00']);
    $mapa = [];
    foreach ($linhas as $l) {
        $t = ['05' => 'A', '09' => 'A', '13' => 'C', '17' => 'C', '21' => 'D'][$l['hora']] ?? null;
        if ($t) {
            $mapa[$l['code']][$t] = true;
        }
    }
    return $mapa;
}

// Dia de Overlap de cada SR (posição do ciclo): os SRs de cada núcleo (por código) se revezam
// entre as posições do parâmetro. Folga juntos (ES_45): num par no mesmo ponto do ciclo, o SR
// fica com o dia de Overlap do outro (o do SR de menor código; com OC, OS ou OA, o da liderança),
// para os dois folgarem nos mesmos dias. $pares = [[rep_a, rep_b]];
// $lider[rep] = ['deslocamento' => n, 'overlap' => posição] de quem do par é da liderança.
function escV4OverlapDe(array $pessoas, array $posicoes, array $nucleos, array $pares = [], array $lider = []): array {
    $de = [];
    if (!$posicoes) {
        return $de;
    }
    $porNucleo = [];
    foreach ($pessoas as $rep => $p) {
        $porNucleo[$p['nucleo']][] = (string) $rep;
    }
    foreach ($porNucleo as $reps) {
        sort($reps);
        foreach ($reps as $i => $rep) {
            $de[$rep] = $posicoes[$i % count($posicoes)];
        }
    }
    $desl = static fn(string $r): ?int => isset($pessoas[$r]) ? (int) ($nucleos[$pessoas[$r]['nucleo']]['deslocamento'] ?? -1)
        : (isset($lider[$r]['deslocamento']) ? (int) $lider[$r]['deslocamento'] : null);
    foreach ($pares as [$a, $b]) {
        $a = strtoupper((string) $a);
        $b = strtoupper((string) $b);
        if ($desl($a) === null || $desl($a) !== $desl($b)) {
            continue;
        }
        if (isset($pessoas[$a], $pessoas[$b])) {
            [$x, $y] = strcmp($a, $b) <= 0 ? [$a, $b] : [$b, $a];
            $de[$y] = $de[$x];
        } elseif (isset($pessoas[$a]) || isset($pessoas[$b])) {
            $sr = isset($pessoas[$a]) ? $a : $b;
            $li = $sr === $a ? $b : $a;
            if (isset($lider[$li]['overlap'])) {
                $de[$sr] = (int) $lider[$li]['overlap'];
            }
        }
    }
    return $de;
}

// ---------- alocação ----------

function escMotorAlocarV4(array $in): array {
    $dias = $in['dias'];
    $est = $in['est'];
    $indisp = $in['indisp'];
    $virada = $in['virada'];
    $turnoVirada = $in['turno_virada'] ?? 'A';
    $diasTransicao = (array) ($in['transicao'] ?? $virada); // [] ou '' = sem dia de transição
    // Páginas no treinamento: [dia][pid] = ['total' => o posto todo é do treinamento, 'paginas' => [pagina => motivo]].
    // Posto todo: ninguém da escala normal vai para ele (a pessoa fica livre e cobre lacuna ou vira buffer).
    // Só algumas páginas do posto: a pessoa fica com as outras e a do treinamento sai como TREINO.
    $treinoCobre = $in['treino_cobre'] ?? [];
    // Na transição também remaneja por faturamento e acumula (pedido de 30/10; 0 desliga).
    $remanejaTransicao = escV4Param('v4_remanejar_transicao', '1') === '1';
    // SR de nível 3 ou mais não trabalha como assistente: posto de assistente ou de apoio só
    // recebe SR até este nível e com "pode ser assistente" no cadastro (em todas as etapas,
    // inclusive a equipe fixa).
    $nivelMaxAssist = (int) escV4Param('v4_nivel_max_assistente', '2');
    // Presentes por dia de transição; o formato antigo (um dia só) continua valendo.
    $presentesDia = $in['presentes_por_dia'] ?? [(string) ($diasTransicao[0] ?? '') => $in['presentes'] ?? []];
    $ciclo = $est['modelo']['ciclo'];
    $nucleos = $est['nucleos'];
    $pessoas = $est['pessoas'];
    $equipes = $est['equipes'];
    // Dia de treinamento do 5x2 (o 15º dia de trabalho do ciclo): o SR entra como Overlap na
    // página 5C Schedule, fora da conta das vagas, ou cobre lacuna. null = sem Overlap.
    // Cada SR tem o próprio dia de Overlap: os SRs de cada núcleo (por código) se revezam
    // entre as posições de folga do ciclo do parâmetro, para não cair todo mundo no mesmo dia.
    $posicoes = $est['overlap_posicoes'] ?? (isset($est['overlap_idx']) ? [(int) $est['overlap_idx']] : []);
    $overlapDe = escV4OverlapDe($pessoas, $posicoes, $nucleos, $est['pares'] ?? [], $est['pares_lider'] ?? []);

    // Páginas que cada pessoa conhece (em qualquer equipe) — afinidade.
    $conhece = [];
    foreach ($equipes as $pid => $vagas) {
        foreach ($vagas as $reps) {
            foreach ($reps as $rep => $f) {
                foreach ($est['postos'][$pid]['paginas'] ?? [] as $pg) {
                    $conhece[$rep][$pg['pagina']] = true;
                }
            }
        }
    }
    // Faturamento de cada posto no turno dele: Σ (faturamento da página × fração do posto na página).
    $receitaPosto = [];
    foreach ($est['postos'] as $pid => $p) {
        $receitaPosto[$pid] = 0.0;
        foreach ($p['paginas'] as $pg) {
            $receitaPosto[$pid] += ($est['receita'][$pg['pagina']][$p['turno']] ?? 0) * ($pg['fracao'] ?? (1 / max(1, count($p['paginas']))));
        }
        // Posto só de OFTV sem faturamento: prioridade mínima, abaixo de qualquer outro (também
        // dos que ainda não faturaram): é o primeiro a ceder gente e o último a receber.
        if ($receitaPosto[$pid] <= 0 && $p['paginas'] && !array_filter($p['paginas'], static fn($pg) => !preg_match('~oftv|\b\d+t\b~i', (string) $pg['pagina']))) {
            $receitaPosto[$pid] = -1.0;
        }
    }
    // Postos por unidade e turno, na ordem de preenchimento: do maior faturamento para o menor
    // (no mesmo faturamento, titular antes de double, apoio e assistente; depois a maior carga).
    $postosUT = [];
    foreach ($est['postos'] as $pid => $p) {
        $postosUT[(int) $p['unidade']][$p['turno']][] = $pid;
    }
    foreach ($postosUT as &$porTurno) {
        foreach ($porTurno as &$lista) {
            usort($lista, static fn($a, $b) => [-round($receitaPosto[$a], 2), ESC_V4_ORDEM_TIPO[$est['postos'][$a]['tipo']] ?? 9, -(float) $est['postos'][$a]['carga'], $a]
                <=> [-round($receitaPosto[$b], 2), ESC_V4_ORDEM_TIPO[$est['postos'][$b]['tipo']] ?? 9, -(float) $est['postos'][$b]['carga'], $b]);
        }
        unset($lista);
    }
    unset($porTurno);
    $nucUT = [];
    foreach ($nucleos as $nid => $n) {
        $nucUT[(int) $n['unidade']][$n['turno']][] = $nid;
    }

    $travadasPorCelula = [];
    foreach ($in['travadas'] ?? [] as $t) {
        $travadasPorCelula[$t['dia'] . '|' . $t['bloco'] . '|' . $t['page_id']][] = $t;
    }

    $alocacoes = [];
    $folgas = [];
    $ociosos = [];
    $resumoOrigem = [];
    $lacunasUT = [];
    $vagasDia = 0;
    $vagasSemNinguem = 0;
    $feito = [];     // [rep][posto|vaga] = dias feitos na semana
    $diasVaga = [];  // [posto|vaga] = dias em que a vaga abriu
    $ultimo = $in['ultimo'] ?? []; // [posto] = quem atuou no posto da última vez (começa com a semana anterior)
    $trabalhou = []; // [rep][dia] = true (para anomalias; o Overlap não conta)
    $overlaps = [];  // [rep, dia, turno] na página 5C Schedule
    $sobrecargas = 0; // vagas-dia cobertas acima da capacidade (até over_max)

    // Folga de emenda: quem já vem de max_seguidos dias seguidos (realizado antes do plano
    // + dias do plano) folga no dia, para não passar do limite do modelo. Na rotação de 21
    // dias isso não acontece; acontece na virada, com quem vinha sem folga na escala antiga.
    $limite = (int) $est['modelo']['max_seguidos'];
    $realizado = $in['realizado'] ?? [];
    $inicioPlano = max($dias[0], $virada);
    $seguidosAntes = static function (string $rep, string $dia) use (&$trabalhou, $realizado, $inicioPlano): int {
        $n = 0;
        for ($d = escMaisDias($dia, -1); $n < 30; $d = escMaisDias($d, -1)) {
            if (!(isset($trabalhou[$rep][$d]) || isset($realizado[$rep][$d]))) {
                break;
            }
            $n++;
        }
        return $n;
    };
    $emendas = [];
    $emFolga = []; // [rep][dia] = folga de emenda

    foreach ($dias as $dia) {
        if ($dia < $virada) {
            continue;
        }
        $transicao = in_array($dia, $diasTransicao, true);
        $presentes = $transicao ? ($presentesDia[$dia] ?? []) : [];
        $rotuloTransicao = 'transição ' . date('d/m', strtotime($dia));
        foreach (ESC_TURNOS as $turno => $blocos) {
            if (!escV4TurnoNovo($dia, $turno, $virada, $turnoVirada)) {
                continue; // antes da virada: vale a escala já postada
            }
            $diaBloco = [$blocos[0] => $dia, $blocos[1] => $turno === 'D' ? escMaisDias($dia, 1) : $dia];
            $estadoUT = [];
            $usado = [];
            $overlapUT = [];

            foreach ([1, 2, 3] as $u) {
                $nids = $nucUT[$u][$turno] ?? [];
                $ativos = [];
                foreach ($nids as $nid) {
                    if ($transicao || escV4NucleoTrabalha($nucleos[$nid], $ciclo, $est['ancora'], $dia)) {
                        $ativos[$nid] = true;
                    }
                }
                // Quem está disponível
                $disp = [];
                foreach ($pessoas as $rep => $p) {
                    if ($p['unidade'] !== $u || $p['turno'] !== $turno) {
                        continue;
                    }
                    $emenda = $limite > 0 && ($transicao ? !empty($presentes[$rep][$turno]) : isset($ativos[$p['nucleo']]))
                        && $seguidosAntes($rep, $dia) >= $limite;
                    if ($emenda) {
                        $folgas[] = ['rep_code' => $rep, 'dia' => $dia, 'tipo' => 'EMENDA', 'ref_id' => null];
                        $emendas[] = ['rep_code' => $rep, 'dia' => $dia, 'turno' => $turno, 'seguidos' => $seguidosAntes($rep, $dia)];
                        $emFolga[$rep][$dia] = true;
                        continue;
                    }
                    if ($transicao) {
                        // Indisponibilidade aprovada vale também na transição (o "presente" vem da
                        // escala antiga, que pode ter sido montada antes da aprovação).
                        $ind = $indisp[$rep][$dia][$turno] ?? null;
                        if ($ind !== null && $ind['blocos'] === null) {
                            if (!empty($presentes[$rep][$turno])) {
                                $folgas[] = ['rep_code' => $rep, 'dia' => $dia, 'tipo' => 'INDISPONIBILIDADE', 'ref_id' => $ind['id']];
                            }
                            continue;
                        }
                        if (!empty($presentes[$rep][$turno])) {
                            $disp[$rep] = $p;
                        }
                        continue;
                    }
                    if (!isset($ativos[$p['nucleo']])) {
                        $ind = $indisp[$rep][$dia][$turno] ?? null;
                        $diaOverlap = isset($overlapDe[$rep]) && $ind === null
                            && escV4IndiceCiclo($nucleos[$p['nucleo']], $ciclo, $est['ancora'], $dia) === $overlapDe[$rep];
                        if ($diaOverlap && $limite > 0 && $seguidosAntes($rep, $dia) >= $limite) {
                            // Já vem do limite de dias seguidos (ex.: emenda da virada): folga em vez do treinamento.
                            $folgas[] = ['rep_code' => $rep, 'dia' => $dia, 'tipo' => 'EMENDA', 'ref_id' => null];
                            $emendas[] = ['rep_code' => $rep, 'dia' => $dia, 'turno' => $turno, 'seguidos' => $seguidosAntes($rep, $dia)];
                            $emFolga[$rep][$dia] = true;
                        } elseif ($diaOverlap) {
                            $overlapUT[$u][$rep] = $p;
                        } else {
                            $folgas[] = ['rep_code' => $rep, 'dia' => $dia, 'tipo' => 'ROTACAO', 'ref_id' => null];
                        }
                        continue;
                    }
                    $ind = $indisp[$rep][$dia][$turno] ?? null;
                    if ($ind !== null && $ind['blocos'] === null) {
                        $folgas[] = ['rep_code' => $rep, 'dia' => $dia, 'tipo' => 'INDISPONIBILIDADE', 'ref_id' => $ind['id']];
                        continue;
                    }
                    $disp[$rep] = $p;
                }
                // Vagas do dia
                $vagas = [];
                $cobertos = [];
                foreach ($postosUT[$u][$turno] ?? [] as $pid) {
                    if (!empty($treinoCobre[$dia][$pid]['total'])) {
                        $cobertos[$pid] = $treinoCobre[$dia][$pid]['paginas'];
                        continue; // treinamento usa a página: ninguém da escala normal vai para lá
                    }
                    $p = $est['postos'][$pid];
                    $dono = (int) $p['nucleo_dono_id'];
                    $cob = $p['nucleo_cobertura_id'] !== null ? (int) $p['nucleo_cobertura_id'] : null;
                    if ($transicao) {
                        $vaga = 'DONO';
                        $nuc = null;
                        $equipe = ($equipes[$pid]['DONO'] ?? []) + ($equipes[$pid]['COBERTURA'] ?? []);
                    } elseif (isset($ativos[$dono])) {
                        $vaga = 'DONO';
                        $nuc = $dono;
                        $equipe = $equipes[$pid]['DONO'] ?? [];
                    } else {
                        $vaga = 'COBERTURA';
                        $nuc = ($cob !== null && isset($ativos[$cob])) ? $cob : null;
                        $equipe = $equipes[$pid]['COBERTURA'] ?? [];
                    }
                    $chave = $pid . '|' . $vaga;
                    $diasVaga[$chave] = ($diasVaga[$chave] ?? 0) + 1;
                    $vagas[] = ['pid' => $pid, 'vaga' => $vaga, 'nuc' => $nuc, 'equipe' => $equipe, 'chave' => $chave,
                        'rep' => null, 'origem' => null, 'motivo' => null];
                }
                $estadoUT[$u] = ['ativos' => $ativos, 'disp' => $disp, 'vagas' => $vagas, 'cobertos' => $cobertos];
            }

            $afinidade = static function (string $rep, array $p) use ($conhece): int {
                $n = 0;
                foreach ($p['paginas'] as $pg) {
                    $n += isset($conhece[$rep][$pg['pagina']]) ? 1 : 0;
                }
                return $n;
            };
            // Menos troca de gente: quem estava no posto, quem é da equipe de alguma página dele,
            // quem mais trabalhou nas páginas dele (5C Schedule); depois o mérito (5C George) e o nível.
            $historico = $est['historico'] ?? [];
            $merito = $est['merito'] ?? [];
            $pode = static fn(string $rep, array $p): bool => !in_array($p['tipo'], ['assistente', 'apoio'], true)
                || ((int) ($pessoas[$rep]['nivel'] ?? 0) <= $nivelMaxAssist && ($pessoas[$rep]['pode_assistente'] ?? true));
            $escolher = static function (array $cands, array $p) use ($afinidade, $pessoas, $ultimo, $historico, $merito, $pode): ?string {
                $melhor = null;
                $chaveMelhor = null;
                foreach ($cands as $rep) {
                    if (!$pode($rep, $p)) {
                        continue;
                    }
                    $dias = 0;
                    foreach ($p['paginas'] as $pg) {
                        $dias += min(28, $historico[$rep][$pg['pagina']] ?? 0);
                    }
                    $k = [($ultimo[(int) $p['id']] ?? null) === $rep ? 1 : 0, $afinidade($rep, $p), min(4, intdiv($dias, 7)),
                        round($merito[$rep] ?? 1.0, 2), $pessoas[$rep]['nivel'] ?? 0, $rep];
                    if ($chaveMelhor === null || $k > $chaveMelhor) {
                        $melhor = $rep;
                        $chaveMelhor = $k;
                    }
                }
                return $melhor;
            };

            // 0. Páginas dos tiers prioritários (SS, A, B): o melhor SR do turno na página entra antes
            //    da equipe fixa (maior venda/hora na página; sem histórico nela, a venda/hora geral).
            $mel = $est['melhores'] ?? null;
            if (!$transicao && $mel && !empty($mel['tiers'])) {
                foreach ($estadoUT as $u => &$st) {
                    foreach ($st['vagas'] as &$v) {
                        $p = $est['postos'][$v['pid']];
                        $pag = null;
                        foreach ($p['paginas'] as $pg) {
                            if (!$pg['apoio'] && ($pag === null || ($est['receita'][$pg['pagina']][$turno] ?? 0) > ($est['receita'][$pag][$turno] ?? 0))) {
                                $pag = $pg['pagina'];
                            }
                        }
                        $pag = $pag ?? ($p['paginas'][0]['pagina'] ?? null);
                        if ($pag === null || !isset($mel['tiers'][$mel['tier'][$pag] ?? ''])) {
                            continue;
                        }
                        $melhor = null;
                        $kMelhor = null;
                        foreach ($st['disp'] as $rep => $pp) {
                            if (isset($usado[$rep]) || !$pode($rep, $p)) {
                                continue;
                            }
                            $naPag = $mel['pagina'][$pag][$rep] ?? null;
                            $geral = $mel['geral'][$rep] ?? 0.0;
                            $k = [$naPag !== null ? 1 : 0, $naPag ?? $geral, $geral, (int) ($pp['nivel'] ?? 0), $rep];
                            if ($kMelhor === null || $k > $kMelhor) {
                                $kMelhor = $k;
                                $melhor = $rep;
                            }
                        }
                        if ($melhor === null || $kMelhor[1] <= 0) {
                            continue;
                        }
                        $v['rep'] = $melhor;
                        $v['origem'] = isset($v['equipe'][$melhor]) ? ($v['vaga'] === 'DONO' ? 'VAGA_DONO' : 'VAGA_COBERTURA') : 'BUFFER_NUCLEO';
                        $v['motivo'] = 'melhor SR do turno para ' . $pag . ' (tier ' . $mel['tier'][$pag] . ', US$ '
                            . number_format($kMelhor[1], 0, ',', '.') . '/h ' . ($kMelhor[0] ? 'na página' : 'no geral') . ')';
                        $usado[$melhor] = true;
                    }
                    unset($v);
                }
                unset($st);
            }

            foreach ($estadoUT as $u => &$st) {
                // 1. equipe fixa da vaga (preferência). Fica quem atuou da última
                //    vez enquanto estiver abaixo da própria fatia (menos troca de
                //    gente na página); senão, quem está mais abaixo da fatia.
                foreach ($st['vagas'] as &$v) {
                    if ($v['rep'] !== null) {
                        continue; // já tem o melhor SR da página (etapa 0)
                    }
                    $melhor = null;
                    $chaveMelhor = null;
                    foreach ($v['equipe'] as $rep => $fatia) {
                        if (!isset($st['disp'][$rep]) || isset($usado[$rep]) || !$pode($rep, $est['postos'][$v['pid']])) {
                            continue;
                        }
                        $share = ($feito[$rep][$v['chave']] ?? 0) / max(1, $diasVaga[$v['chave']]);
                        $continua = ($ultimo[$v['pid']] ?? null) === $rep && $share < $fatia + 0.15 ? 1 : 0;
                        $k = [$continua, round($fatia - $share, 2), $fatia, round($est['merito'][$rep] ?? 1.0, 2), $rep];
                        if ($chaveMelhor === null || $k > $chaveMelhor) {
                            $melhor = $rep;
                            $chaveMelhor = $k;
                        }
                    }
                    if ($melhor !== null) {
                        $v['rep'] = $melhor;
                        $v['origem'] = $transicao ? 'TRANSICAO' : ($v['vaga'] === 'DONO' ? 'VAGA_DONO' : 'VAGA_COBERTURA');
                        $v['motivo'] = ($transicao ? $rotuloTransicao . ': já escalado no turno; ' : '') . 'equipe fixa da vaga ('
                            . strtolower($v['vaga']) . ', fatia ' . str_replace('.', ',', (string) round($v['equipe'][$melhor], 2)) . ')';
                        $usado[$melhor] = true;
                    }
                }
                unset($v);
                // 2. outra pessoa do mesmo núcleo (transição: da unidade e turno), quem conhece as páginas primeiro
                foreach ($st['vagas'] as &$v) {
                    if ($v['rep'] !== null) {
                        continue;
                    }
                    $cands = [];
                    foreach ($st['disp'] as $rep => $p) {
                        if (!isset($usado[$rep]) && ($transicao || $v['nuc'] === null || $p['nucleo'] === $v['nuc'])) {
                            $cands[] = $rep;
                        }
                    }
                    $rep = $escolher($cands, $est['postos'][$v['pid']]);
                    if ($rep !== null) {
                        $v['rep'] = $rep;
                        $v['origem'] = $transicao ? 'TRANSICAO' : 'BUFFER_NUCLEO';
                        $v['motivo'] = $transicao ? $rotuloTransicao . ': já escalado no turno' : 'buffer do próprio núcleo (vaga sem a equipe fixa no dia)';
                        $usado[$rep] = true;
                    }
                }
                unset($v);
                // 3. buffer do outro núcleo em serviço no mesmo turno da unidade
                foreach ($st['vagas'] as &$v) {
                    if ($v['rep'] !== null) {
                        continue;
                    }
                    $cands = array_values(array_filter(array_keys($st['disp']), static fn($r) => !isset($usado[$r])));
                    $rep = $escolher($cands, $est['postos'][$v['pid']]);
                    if ($rep !== null) {
                        $v['rep'] = $rep;
                        $v['origem'] = $transicao ? 'TRANSICAO' : 'BUFFER_NUCLEO';
                        $v['motivo'] = 'buffer de outro núcleo do turno';
                        $usado[$rep] = true;
                    }
                }
                unset($v);
            }
            unset($st);

            // Transição: quem está escalado no turno mas é de outra unidade (ou outro turno na estrutura nova).
            // Quem já trabalhou noutro turno do dia (vaga ou buffer) fica de fora: na escala antiga a pessoa
            // podia estar em dois turnos (ex.: o dela e assistente noutro); turno a mais só por hora extra pedida.
            if ($transicao) {
                $sobra = [];
                foreach ($presentes as $rep => $ts) {
                    $indS = $indisp[$rep][$dia][$turno] ?? null;
                    if (!empty($ts[$turno]) && !isset($usado[$rep]) && !isset($trabalhou[$rep][$dia]) && isset($pessoas[$rep]) && !isset($emFolga[$rep][$dia])
                        && !($indS !== null && $indS['blocos'] === null)
                        && !($limite > 0 && $seguidosAntes($rep, $dia) >= $limite)) {
                        $sobra[] = $rep;
                    }
                }
                foreach ($estadoUT as $u => &$st) {
                    foreach ($st['vagas'] as &$v) {
                        if ($v['rep'] !== null || !$sobra) {
                            continue;
                        }
                        $rep = $escolher($sobra, $est['postos'][$v['pid']]);
                        if ($rep === null) {
                            continue; // ninguém que sobra pode ficar neste posto (assistente: só SR até o nível máximo)
                        }
                        $sobra = array_values(array_diff($sobra, [$rep]));
                        $v['rep'] = $rep;
                        $v['origem'] = 'TRANSICAO';
                        $v['motivo'] = $rotuloTransicao . ': escalado neste turno, de outra unidade ou turno na estrutura nova';
                        $usado[$rep] = true;
                    }
                    unset($v);
                }
                unset($st);
            }

            if (!$transicao || $remanejaTransicao) {
                // Lacunas do turno, da página de maior faturamento para a de menor.
                $listarLacunas = static function () use (&$estadoUT, $receitaPosto): array {
                    $l = [];
                    foreach ($estadoUT as $u => $st) {
                        foreach ($st['vagas'] as $i => $v) {
                            if ($v['rep'] === null) {
                                $l[] = [$u, $i, $receitaPosto[$v['pid']]];
                            }
                        }
                    }
                    usort($l, static fn($a, $b) => $b[2] <=> $a[2]);
                    return $l;
                };
                $lacunas = $listarLacunas();

                // 4. Quem está de Overlap (dia de treinamento) e sobra cobre a lacuna no posto,
                //    dentro da capacidade dele: primeiro da mesma unidade, depois das outras do turno.
                foreach ($lacunas as [$u, $i]) {
                    $cands = array_keys($overlapUT[$u] ?? []);
                    if (!$cands) {
                        foreach ($overlapUT as $u2 => $rs) {
                            $cands = array_merge($cands, array_keys($rs));
                        }
                    }
                    $rep = $cands ? $escolher($cands, $est['postos'][$estadoUT[$u]['vagas'][$i]['pid']]) : null;
                    if ($rep !== null) {
                        $estadoUT[$u]['vagas'][$i]['rep'] = $rep;
                        $estadoUT[$u]['vagas'][$i]['origem'] = 'BUFFER_NUCLEO';
                        $estadoUT[$u]['vagas'][$i]['motivo'] = 'dia de treinamento (Overlap) usado para cobrir a lacuna';
                        $estadoUT[$u]['vagas'][$i]['treino'] = true; // não conta nos dias seguidos, como o Overlap
                        foreach (array_keys($overlapUT) as $u2) {
                            unset($overlapUT[$u2][$rep]);
                        }
                        $usado[$rep] = true;
                    }
                }

                // 5. Página de faturamento maior ainda vazia: o SR do posto de menor faturamento da unidade
                //    (abaixo dela) muda para lá; a lacuna fica na página menor. Não tira o regular de uma
                //    página que tem assistente alocado.
                $temAssistente = static function (array $vagasU, int $j) use ($est): bool {
                    $w = $est['postos'][$vagasU[$j]['pid']];
                    if ($w['tipo'] === 'assistente') {
                        return false;
                    }
                    foreach ($w['paginas'] as $pg) {
                        if ($pg['apoio']) {
                            continue;
                        }
                        $assist = false;
                        $outroRegular = false;
                        foreach ($vagasU as $k => $x) {
                            if ($k === $j || $x['rep'] === null) {
                                continue;
                            }
                            foreach ($est['postos'][$x['pid']]['paginas'] as $pg2) {
                                if ($pg2['pagina'] !== $pg['pagina']) {
                                    continue;
                                }
                                if ($est['postos'][$x['pid']]['tipo'] === 'assistente' || $pg2['apoio']) {
                                    $assist = true;
                                } else {
                                    $outroRegular = true;
                                }
                            }
                        }
                        if ($assist && !$outroRegular) {
                            return true;
                        }
                    }
                    return false;
                };
                foreach ($lacunas as [$u, $i, $rec]) {
                    if ($estadoUT[$u]['vagas'][$i]['rep'] !== null || $rec <= 0) {
                        continue;
                    }
                    $fonte = null;
                    $recFonte = null;
                    foreach ($estadoUT[$u]['vagas'] as $j => $w) {
                        if ($w['rep'] === null || $j === $i || !empty($w['treino']) || $receitaPosto[$w['pid']] >= $rec
                            || !$pode($w['rep'], $est['postos'][$estadoUT[$u]['vagas'][$i]['pid']])) {
                            continue;
                        }
                        if ($fonte === null || $receitaPosto[$w['pid']] < $recFonte) {
                            if (!$temAssistente($estadoUT[$u]['vagas'], $j)) {
                                $fonte = $j;
                                $recFonte = $receitaPosto[$w['pid']];
                            }
                        }
                    }
                    if ($fonte !== null) {
                        $w = $estadoUT[$u]['vagas'][$fonte];
                        $estadoUT[$u]['vagas'][$i]['rep'] = $w['rep'];
                        $estadoUT[$u]['vagas'][$i]['origem'] = 'BUFFER_NUCLEO';
                        $estadoUT[$u]['vagas'][$i]['motivo'] = 'remanejado de ' . $est['postos'][$w['pid']]['pagina_principal']
                            . ' (faturamento menor) para a página de faturamento maior';
                        $estadoUT[$u]['vagas'][$fonte]['rep'] = null;
                        $estadoUT[$u]['vagas'][$fonte]['origem'] = null;
                        $estadoUT[$u]['vagas'][$fonte]['motivo'] = null;
                    }
                }
                $lacunas = $listarLacunas();

                // 6. Ainda vazio: um SR já alocado no turno da unidade acumula o posto, se a Política deixa
                //    juntar as páginas e a carga fica até over_max acima da capacidade. Acima de 100% fica
                //    sinalizado (SOBRECARGA): previsão de problema de capacity.
                $maxOver = (float) ($est['over_max'] ?? 0);
                $semAcumular = ['assistente' => true, 'apoio' => true];
                $acumula = [];
                foreach ($lacunas as [$u, $i]) {
                    $v = $estadoUT[$u]['vagas'][$i];
                    $alvo = $est['postos'][$v['pid']];
                    if ($v['rep'] !== null || isset($semAcumular[$alvo['tipo']])) {
                        continue;
                    }
                    $melhor = null;
                    $kMelhor = null;
                    foreach ($estadoUT[$u]['vagas'] as $j => $w) {
                        $rep = $w['rep'];
                        if ($rep === null || $j === $i || isset($acumula[$rep]) || $w['origem'] === 'SOBRECARGA') {
                            continue;
                        }
                        $base = $est['postos'][$w['pid']];
                        if (isset($semAcumular[$base['tipo']])) {
                            continue;
                        }
                        $cap = escV4CapacidadeJunta($base, $alvo, $est['modelo_pagina'] ?? []);
                        if ($cap === null) {
                            continue;
                        }
                        $carga = (float) $base['carga'] + (float) $alvo['carga'];
                        $razao = $carga / $cap;
                        if ($razao > 1 + $maxOver + 1e-9) {
                            continue;
                        }
                        $k = [-round($razao, 3), $afinidade($rep, $alvo), $rep];
                        if ($kMelhor === null || $k > $kMelhor) {
                            $kMelhor = $k;
                            $melhor = ['rep' => $rep, 'base' => $base, 'carga' => $carga, 'cap' => $cap, 'razao' => $razao];
                        }
                    }
                    if ($melhor !== null) {
                        $acima = $melhor['razao'] > 1 + 1e-9;
                        $estadoUT[$u]['vagas'][$i]['rep'] = $melhor['rep'];
                        $estadoUT[$u]['vagas'][$i]['origem'] = $acima ? 'SOBRECARGA' : 'BUFFER_NUCLEO';
                        $estadoUT[$u]['vagas'][$i]['motivo'] = 'acumula com ' . $melhor['base']['pagina_principal'] . ': '
                            . str_replace('.', ',', (string) round($melhor['carga'], 1)) . ' de ' . str_replace('.', ',', (string) round($melhor['cap'], 1))
                            . ' fãs/h (' . round($melhor['razao'] * 100) . '% da capacidade)' . ($acima ? ' · previsão de problema de capacity' : '');
                        $acumula[$melhor['rep']] = true;
                        $sobrecargas += $acima ? 1 : 0;
                    }
                }
            }

            // Saída por página e bloco
            foreach ($estadoUT as $u => $st) {
                // Postos cobertos pelo treinamento: a célula fica marcada (sem SR da escala normal).
                foreach ($st['cobertos'] ?? [] as $pid => $motivosTreino) {
                    foreach ($est['postos'][$pid]['paginas'] as $pg) {
                        $motivoTreino = $motivosTreino[$pg['pagina']] ?? reset($motivosTreino);
                        foreach ($blocos as $bloco) {
                            $diaB = $diaBloco[$bloco];
                            if (isset($travadasPorCelula[$diaB . '|' . $bloco . '|' . $pg['page_id']])) {
                                continue;
                            }
                            $alocacoes[] = ['dia' => $diaB, 'bloco' => $bloco, 'turno' => $turno, 'page_id' => $pg['page_id'], 'pagina' => $pg['pagina'],
                                'posto_id' => $pid, 'vaga' => null, 'rep_code' => null, 'papel' => 'REGULAR', 'origem' => 'TREINO', 'travada' => 0, 'motivo' => $motivoTreino];
                            $resumoOrigem['TREINO'] = ($resumoOrigem['TREINO'] ?? 0) + 1;
                        }
                    }
                }
                foreach ($st['vagas'] as $v) {
                    $vagasDia++;
                    $p = $est['postos'][$v['pid']];
                    if ($v['rep'] === null) {
                        $vagasSemNinguem++;
                        $lacunasUT[$u . $turno] = ($lacunasUT[$u . $turno] ?? 0) + 1;
                    } else {
                        $feito[$v['rep']][$v['chave']] = ($feito[$v['rep']][$v['chave']] ?? 0) + 1;
                        $trabalhou[$v['rep']][$dia] = true; // inclusive o Overlap usado para cobrir lacuna
                        $ultimo[$v['pid']] = $v['rep'];
                    }
                    foreach ($p['paginas'] as $pg) {
                        $paginaTreino = $treinoCobre[$dia][$v['pid']]['paginas'][$pg['pagina']] ?? null;
                        foreach ($blocos as $bloco) {
                            $diaB = $diaBloco[$bloco];
                            if (isset($travadasPorCelula[$diaB . '|' . $bloco . '|' . $pg['page_id']])) {
                                continue;
                            }
                            if ($paginaTreino !== null) {
                                // Página do posto que está no treinamento: o SR fica só com as outras.
                                $alocacoes[] = ['dia' => $diaB, 'bloco' => $bloco, 'turno' => $turno, 'page_id' => $pg['page_id'], 'pagina' => $pg['pagina'],
                                    'posto_id' => $v['pid'], 'vaga' => $v['vaga'], 'rep_code' => null, 'papel' => 'REGULAR', 'origem' => 'TREINO', 'travada' => 0, 'motivo' => $paginaTreino];
                                $resumoOrigem['TREINO'] = ($resumoOrigem['TREINO'] ?? 0) + 1;
                                continue;
                            }
                            $rep = $v['rep'];
                            if ($rep !== null) {
                                $ind = $indisp[$rep][$dia][$turno] ?? null;
                                if ($ind && is_array($ind['blocos']) && in_array($bloco, $ind['blocos'], true)) {
                                    $rep = null; // indisponível só neste bloco
                                }
                            }
                            $origem = $rep === null ? 'LACUNA' : $v['origem'];
                            $alocacoes[] = [
                                'dia' => $diaB, 'bloco' => $bloco, 'turno' => $turno,
                                'page_id' => $pg['page_id'], 'pagina' => $pg['pagina'],
                                'posto_id' => $v['pid'], 'vaga' => $v['vaga'],
                                'rep_code' => $rep,
                                'papel' => ($p['tipo'] === 'assistente' || $pg['apoio']) ? 'ASSISTENTE' : 'REGULAR',
                                'origem' => $origem, 'travada' => 0,
                                'motivo' => $rep === null ? 'ninguém disponível na unidade e turno' : $v['motivo'],
                            ];
                            $resumoOrigem[$origem] = ($resumoOrigem[$origem] ?? 0) + 1;
                        }
                    }
                }
                // Quem ficou sem vaga é o buffer de plantão do núcleo: vai para a página 5C Schedule
                // como Buffer (no CSV, código com "-2").
                $pgBuf = $est['overlap_pagina'] ?? null;
                foreach ($st['disp'] as $rep => $p) {
                    if (isset($usado[$rep])) {
                        continue;
                    }
                    $ociosos[] = ['rep_code' => $rep, 'dia' => $dia, 'turno' => $turno, 'tipo' => 'BUFFER_V4', 'motivo' => 'buffer de plantão do núcleo'];
                    $trabalhou[$rep][$dia] = true;
                    if ($pgBuf === null) {
                        continue;
                    }
                    foreach ($blocos as $bloco) {
                        $diaB = $diaBloco[$bloco];
                        $ind = $indisp[$rep][$dia][$turno] ?? null;
                        if (isset($travadasPorCelula[$diaB . '|' . $bloco . '|' . $pgBuf['page_id']])
                            || ($ind && is_array($ind['blocos']) && in_array($bloco, $ind['blocos'], true))) {
                            continue;
                        }
                        $alocacoes[] = [
                            'dia' => $diaB, 'bloco' => $bloco, 'turno' => $turno,
                            'page_id' => $pgBuf['page_id'], 'pagina' => $pgBuf['pagina'], 'posto_id' => null, 'vaga' => null,
                            'rep_code' => $rep, 'papel' => 'REGULAR', 'origem' => 'BUFFER', 'travada' => 0,
                            'motivo' => 'buffer de plantão do núcleo (unidade ' . $u . ')',
                        ];
                        $resumoOrigem['BUFFER'] = ($resumoOrigem['BUFFER'] ?? 0) + 1;
                    }
                }
            }

            // Overlap: no dia de treinamento o SR que não cobriu lacuna vai para a página 5C Schedule
            // (no CSV, código com "-5"). De lá se decide se vira buffer ou treinamento.
            $pgOv = $est['overlap_pagina'] ?? null;
            foreach ($overlapUT as $u => $reps) {
                foreach ($reps as $rep => $p) {
                    if ($pgOv === null) {
                        $folgas[] = ['rep_code' => $rep, 'dia' => $dia, 'tipo' => 'ROTACAO', 'ref_id' => null];
                        continue;
                    }
                    foreach ($blocos as $bloco) {
                        $diaB = $diaBloco[$bloco];
                        if (isset($travadasPorCelula[$diaB . '|' . $bloco . '|' . $pgOv['page_id']])) {
                            continue;
                        }
                        $ind = $indisp[$rep][$dia][$turno] ?? null;
                        if ($ind && is_array($ind['blocos']) && in_array($bloco, $ind['blocos'], true)) {
                            continue;
                        }
                        $alocacoes[] = [
                            'dia' => $diaB, 'bloco' => $bloco, 'turno' => $turno,
                            'page_id' => $pgOv['page_id'], 'pagina' => $pgOv['pagina'],
                            'posto_id' => null, 'vaga' => null,
                            'rep_code' => $rep, 'papel' => 'REGULAR', 'origem' => 'OVERLAP', 'travada' => 0,
                            'motivo' => 'Overlap: dia de treinamento do 5x2 (buffer ou treinamento a decidir)',
                        ];
                        $resumoOrigem['OVERLAP'] = ($resumoOrigem['OVERLAP'] ?? 0) + 1;
                    }
                    $overlaps[] = ['rep_code' => $rep, 'dia' => $dia, 'turno' => $turno];
                    $trabalhou[$rep][$dia] = true; // o dia de treinamento é dia de trabalho (dias seguidos)
                }
            }
        }
    }

    // Travadas entram como estão.
    foreach ($in['travadas'] ?? [] as $t) {
        $alocacoes[] = [
            'dia' => $t['dia'], 'bloco' => $t['bloco'], 'turno' => $t['turno'],
            'page_id' => (int) $t['page_id'], 'pagina' => $t['pagina'], 'posto_id' => null, 'vaga' => null,
            'rep_code' => $t['rep_code'], 'papel' => $t['papel'], 'origem' => $t['origem'],
            'travada' => 1, 'motivo' => $t['motivo'],
        ];
    }

    // Anomalias: dias seguidos (realizado + plano) acima do modelo.
    $anomalias = [];
    foreach ($pessoas as $rep => $p) {
        $run = 0;
        for ($d = escMaisDias($inicioPlano, -14); $d < $inicioPlano; $d = escMaisDias($d, 1)) {
            $run = isset($realizado[$rep][$d]) ? $run + 1 : 0;
        }
        foreach ($dias as $dia) {
            if ($dia < $virada) {
                continue;
            }
            $run = isset($trabalhou[$rep][$dia]) ? $run + 1 : 0;
            if ($run > $limite) {
                $anomalias[] = ['rep_code' => $rep, 'dia' => $dia, 'seguidos' => $run, 'limite' => $limite];
            }
        }
    }

    $lacunas = count(array_filter($alocacoes, static fn($a) => $a['rep_code'] === null && $a['origem'] !== 'TREINO'));
    ksort($lacunasUT);
    return [
        'alocacoes' => $alocacoes,
        'folgas' => $folgas,
        'ociosos' => $ociosos,
        'resumo' => [
            'estrutura' => 'V4',
            'blocos' => count($alocacoes),
            'lacunas' => $lacunas,
            'por_origem' => $resumoOrigem,
            'anomalias' => count($anomalias),
            'folgas_emenda' => count($emendas),
            'folgas_emenda_lista' => array_slice($emendas, 0, 100),
            'anomalias_lista' => array_slice($anomalias, 0, 100),
            'buffers_ociosos' => count($ociosos),
            'pessoas' => count($pessoas),
            'vagas_dia' => $vagasDia,
            'vagas_sem_ninguem' => $vagasSemNinguem,
            'vagas_sem_ninguem_por_unidade_turno' => $lacunasUT,
            'paginas_sem_party' => [],
            'overlaps' => count($overlaps),
            'sobrecargas' => $sobrecargas,
        ],
    ];
}
