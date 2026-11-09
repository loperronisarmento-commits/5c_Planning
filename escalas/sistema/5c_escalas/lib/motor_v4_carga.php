<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/motor_v4_carga.php
// Leitura das entradas do motor v4 (escV4Carregar: pessoas, postos, equipes, restrições, mérito, receita)
// e auxiliares: quem estava no posto na semana anterior, capacidade de postos juntos, fim do turno D,
// presentes na transição e dia de Overlap.
// Separado de lib/motor_v4.php em 04/11 (1.19.18), só movendo as funções, sem mudar nada.
// Carregado por lib/motor_v4.php (require_once no topo).
// ===============================================

function escV4Carregar(string $dia): array {
    $vig = ' vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)';
    $modelo = escQ1('SELECT ciclo, max_seguidos FROM ' . escT('esc_modelo_escala') . " WHERE nome = '5x2 rotativo 21 dias'");
    if (!$modelo) {
        throw new RuntimeException(t('Falta o modelo "5x2 rotativo 21 dias". Peça ao admin para cadastrá-lo.'));
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
    $cargaPagina = []; // [posto][página] = fãs/h
    // Página sob demanda (On demand, ES_79) sai do posto com a carga dela; posto que fica sem
    // página nenhuma não abre vaga. A alocação dela é manual (célula travada na Grade).
    $sobDemanda = escPaginaSobDemanda();
    $tiraDemanda = [];
    if ($postos) {
        foreach (escQ('SELECT posto_id, pagina, page_id, apoio, fracao, fans_h FROM ' . escT('esc_posto_pagina') . ' WHERE posto_id IN (' . escIn(array_keys($postos)) . ')', array_keys($postos)) as $pp) {
            if (isset($sobDemanda[escV4ChavePagina((string) $pp['pagina'])])) {
                $tiraDemanda[(int) $pp['posto_id']] = ($tiraDemanda[(int) $pp['posto_id']] ?? 0) + (float) $pp['fans_h'];
                continue;
            }
            $postos[(int) $pp['posto_id']]['paginas'][] = ['pagina' => $pp['pagina'], 'page_id' => (int) $pp['page_id'], 'apoio' => (int) $pp['apoio'] === 1,
                'fracao' => (float) $pp['fracao'], 'fans_h' => (float) $pp['fans_h']];
            $nomes[$pp['pagina']] = true;
            $cargaPagina[(int) $pp['posto_id']][$pp['pagina']] = ($cargaPagina[(int) $pp['posto_id']][$pp['pagina']] ?? 0) + (float) $pp['fans_h'];
        }
    }
    foreach ($tiraDemanda as $pid => $f) {
        if (!$postos[$pid]['paginas']) {
            unset($postos[$pid]);
            continue;
        }
        $postos[$pid]['carga'] = max(0.0, round((float) $postos[$pid]['carga'] - $f, 1));
    }
    // Posto com página exclusiva (solo, ES_82): o SR dele fica só nele (passos 6 e 7 do Gerar).
    $alocPag = escPaginaAlocacao();
    foreach ($postos as $pid => $p) {
        $postos[$pid]['exclusivo'] = (bool) array_filter($p['paginas'], static fn($pg) => ($alocPag[escV4ChavePagina((string) $pg['pagina'])] ?? '') === 'EXCLUSIVA');
    }
    // Faturamento médio por dia de cada página e turno (US$, statement por bloco): ordem de preenchimento.
    $receita = [];
    if ($nomes) {
        // 30 dias; a página com menos dias de venda é emulada e a sem venda usa o tier.
        $receita = escV4ReceitaPaginas(array_keys($nomes), (int) escV4Param('v4_receita_dias', '30'));
    }
    // Páginas desconsideradas (OFTV que não vende ou marcada no site): sem SLA, carga zero no posto.
    $desconsiderada = [];
    foreach (array_keys($nomes) as $pg) {
        if (escV4Desconsiderada((string) $pg, (float) ($receita[$pg]['total'] ?? 0))) {
            $desconsiderada[$pg] = true;
        }
    }
    foreach ($cargaPagina as $pid => $porPagina) {
        $tira = 0.0;
        foreach ($porPagina as $pg => $f) {
            $tira += isset($desconsiderada[$pg]) ? $f : 0.0;
        }
        if ($tira > 0) {
            $postos[$pid]['carga'] = max(0.0, round((float) $postos[$pid]['carga'] - $tira, 1));
        }
    }
    // Modelo de cada página (Capacity): o over só junta páginas dentro da Política.
    $modeloPag = [];
    if ($nomes) {
        foreach (escQ('SELECT pagina, modelo FROM `5c_planning`.`fc_pagina_carteira` WHERE vigente_ate IS NULL AND pagina IN (' . escIn(array_keys($nomes)) . ')', array_keys($nomes)) as $r) {
            $modeloPag[$r['pagina']] = $r['modelo'];
        }
    }
    // Agência de cada página, a mesma da distribuição dos postos (01/11): a do Infloww
    // (5c_planning.fc_pagina_infloww: 5C Company, Fatbear, Verge); fora do Infloww, o cliente do site
    // (5c_pages.pages.client), com Noah, Independent e Lolly Agency contando como 5C Company. Sem
    // nenhuma, vale a de outra página da mesma modelo; sem essa, a página fica sozinha (a própria modelo).
    // A sobrecarga do passo 7 só junta páginas da mesma agência.
    $agenciaPag = [];
    if ($nomes) {
        $mapaCliente = ['noah' => '5C Company', 'independent' => '5C Company', 'lolly agency' => '5C Company', 'fat bear' => 'Fatbear'];
        $porChave = [];
        foreach (escQ('SELECT name, client FROM `5c_pages`.`pages` ORDER BY active') as $r) {
            $c = trim((string) $r['client']);
            if ($c !== '') {
                $porChave[escV4ChavePagina((string) $r['name'])] = $mapaCliente[mb_strtolower($c)] ?? $c;
            }
        }
        foreach (escQ('SELECT pagina, agencia FROM `5c_planning`.`fc_pagina_infloww` WHERE LENGTH(TRIM(agencia)) > 0') as $r) {
            $porChave[escV4ChavePagina((string) $r['pagina'])] = trim((string) $r['agencia']);
        }
        $agModelo = [];
        foreach (array_keys($nomes) as $pg) {
            if (isset($porChave[escV4ChavePagina((string) $pg)])) {
                $agModelo[$modeloPag[$pg] ?? $pg] ??= $porChave[escV4ChavePagina((string) $pg)];
            }
        }
        foreach (array_keys($nomes) as $pg) {
            $m = $modeloPag[$pg] ?? $pg;
            $agenciaPag[$pg] = $porChave[escV4ChavePagina((string) $pg)] ?? $agModelo[$m] ?? ('modelo:' . $m);
        }
    }
    // Faturamento da modelo por turno: a soma das páginas dela (VIP, Free, OFTV...). As páginas da
    // mesma modelo entram juntas na fila: falhar na Free ou na secundária põe a parceria em risco.
    $receitaModelo = [];
    foreach ($receita as $pg => $r) {
        $m = $modeloPag[$pg] ?? $pg;
        foreach (['A', 'C', 'D', 'total'] as $tu) {
            $receitaModelo[$m][$tu] = ($receitaModelo[$m][$tu] ?? 0) + (float) ($r[$tu] ?? 0);
        }
    }
    // Posto só de página desconsiderada (ex.: a OFTV que ficou com posto próprio numa troca de
    // unidade) não abre vaga: a página entra de apoio no posto da página principal da mesma modelo,
    // na mesma unidade e turno, sem somar carga (o SR desse posto atende as duas). Sem posto da
    // modelo ali, fica como está, no fim da fila.
    foreach ($postos as $pid => $p) {
        if (!$p['paginas'] || array_filter($p['paginas'], static fn($pg) => !isset($desconsiderada[$pg['pagina']]))) {
            continue;
        }
        $modelos = array_flip(array_map(static fn($pg) => $modeloPag[$pg['pagina']] ?? $pg['pagina'], $p['paginas']));
        $alvo = null;
        $kAlvo = null;
        foreach ($postos as $qid => $q) {
            if ($qid === $pid || (int) $q['unidade'] !== (int) $p['unidade'] || $q['turno'] !== $p['turno'] || in_array($q['tipo'], ['apoio', 'assistente'], true)) {
                continue;
            }
            foreach ($q['paginas'] as $pg) {
                if (!$pg['apoio'] && !isset($desconsiderada[$pg['pagina']]) && isset($modelos[$modeloPag[$pg['pagina']] ?? $pg['pagina']])) {
                    $k = [(float) ($receita[$pg['pagina']]['total'] ?? 0), $q['tipo'] === 'titular' ? 1 : 0, -$qid];
                    if ($kAlvo === null || $k > $kAlvo) {
                        $alvo = $qid;
                        $kAlvo = $k;
                    }
                }
            }
        }
        if ($alvo === null) {
            continue;
        }
        foreach ($p['paginas'] as $pg) {
            $postos[$alvo]['paginas'][] = ['pagina' => $pg['pagina'], 'page_id' => $pg['page_id'], 'apoio' => true, 'fracao' => 0.0];
        }
        unset($postos[$pid]);
    }
    $pagOverlap = escQ1('SELECT id, name FROM `5c_pages`.`pages` WHERE name = ? ORDER BY active DESC, id LIMIT 1', [escV4Param('v4_overlap_pagina', '5C Schedule')]);

    // Melhores SRs nas páginas das modelos que mais faturam: modelo com v4_melhores_receita_min
    // (US$ 100 mil) ou mais em 30 dias; página desconsiderada nunca entra. Venda por hora do SR
    // na página (cap_rep_hora, horas só nela, últimos v4_receita_dias dias) e a geral dele.
    $minMelhores = (float) str_replace(',', '.', escV4Param('v4_melhores_receita_min', '100000'));
    $melhores = ['paginas' => [], 'pagina' => [], 'geral' => []];
    foreach (array_keys($nomes) as $pg) {
        $m = $modeloPag[$pg] ?? $pg;
        if (!isset($desconsiderada[$pg]) && (float) ($receitaModelo[$m]['total'] ?? 0) >= $minMelhores) {
            $melhores['paginas'][$pg] = (float) $receitaModelo[$m]['total'];
        }
    }
    if ($melhores['paginas'] && $pessoas) {
        $prio = array_keys($melhores['paginas']);
        $codDe = [];
        foreach (escQ('SELECT UPPER(rep_code) AS rep, code_infloww FROM `5c_wfm_db_mis`.`vw_rep_estrutura_atual` WHERE code_infloww IS NOT NULL AND rep_code IN (' . escIn(array_keys($pessoas)) . ')', array_keys($pessoas)) as $r) {
            $codDe[$r['code_infloww']] = $r['rep'];
        }
        $n = escDiasCorte(max(1, (int) escV4Param('v4_receita_dias', '30')));
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
    // Quem bateu a meta na página: atingimento da meta 5CReps nela nos últimos 30 dias (a parte de cada
    // um, como no PDF da Grade). Com v4_melhores_meta_min (65%) ou mais, passa na frente de quem nunca fez a página.
    $melhores['meta'] = [];
    $melhores['meta_min'] = (float) str_replace(',', '.', escV4Param('v4_melhores_meta_min', '0.65'));
    if ($melhores['paginas'] && $pessoas && function_exists('escMeritoAtingimentoPaginas')) {
        $periodo = [[date('Y-m-d', strtotime('-30 day')), date('Y-m-d', strtotime('-1 day'))]];
        foreach (escMeritoAtingimentoPaginas(array_keys($melhores['paginas']), array_keys($pessoas), $periodo) as $pg => $porRep) {
            foreach ($porRep as $rep => $porPeriodo) {
                [$real, $meta] = ($porPeriodo[0] ?? []) + [0.0, 0.0];
                if ($meta > 0) {
                    $melhores['meta'][$pg][strtoupper((string) $rep)] = $real / $meta;
                }
            }
        }
    }
    // Página prioritária (A-02): modelo com v4_pagina_prioritaria_min (US$ 100 mil) ou mais em 30 dias. O SR
    // Abaixo só fica nela sem outro disponível e não sobe para ela no remanejamento.
    $minPrio = (float) str_replace(',', '.', escV4Param('v4_pagina_prioritaria_min', '100000'));
    $prioritaria = [];
    foreach (array_keys($nomes) as $pg) {
        if (!isset($desconsiderada[$pg]) && (float) ($receitaModelo[$modeloPag[$pg] ?? $pg]['total'] ?? 0) >= $minPrio) {
            $prioritaria[$pg] = true;
        }
    }
    $mer = function_exists('escMeritoCarregar') ? escMeritoMotor(escMeritoCarregar()) : ['nota' => [], 'faixa' => [], 'anos' => []];
    $equipes = [];
    foreach (escQ('SELECT posto_id, vaga, UPPER(rep_code) AS rep, fatia FROM ' . escT('esc_vaga_equipe') . ' WHERE' . $vig, [$dia, $dia]) as $e) {
        $equipes[(int) $e['posto_id']][$e['vaga']][$e['rep']] = (float) $e['fatia'];
    }
    // Equipe fixa da página (esc_pagina_prioridade: Privacy, Molly): quem é da equipe e está no turno
    // fica com a página antes de tudo. esc_pagina_assistente (ES_65): papel ASSISTENTE = o SR entra na
    // página só como assistente, nunca no posto titular dela nem como regular; FORA (ES_67) = não entra nela.
    // Vale por dia: a linha pode cobrir só alguns dias da semana (ex.: quem cobre um SR que saiu).
    $prioridade = [];
    // Papel na equipe (ES_114, pedido do OM da U3): MAIN (titular da página no turno) entra antes, COVER
    // (cobre o titular) depois, EQUIPE (os demais) por último.
    $comPapelEq = (bool) escQ1("SELECT 1 AS ok FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'esc_pagina_prioridade' AND COLUMN_NAME = 'papel'", [ESC_SCHEMA]);
    foreach (escQ('SELECT pagina, rep_codes, vigente_desde, vigente_ate' . ($comPapelEq ? ', papel' : '') . ' FROM ' . escT('esc_pagina_prioridade')
        . ' WHERE vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)', [$dia, escMaisDias($dia, -6)]) as $l) {
        foreach (array_filter(array_map('trim', explode(',', strtoupper((string) $l['rep_codes'])))) as $rep) {
            $prioridade[$l['pagina']][] = ['rep' => $rep, 'de' => substr((string) $l['vigente_desde'], 0, 10),
                'ate' => $l['vigente_ate'] !== null ? substr((string) $l['vigente_ate'], 0, 10) : null,
                'papel' => (string) ($l['papel'] ?? 'EQUIPE') ?: 'EQUIPE'];
        }
    }
    // Divisão do SR entre páginas (esc_sr_divisao, ES_98): [rep] = [['pagina', 'parte', 'de', 'ate'], ...].
    $divisao = [];
    if (escQ1("SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'esc_sr_divisao'", [ESC_SCHEMA])) {
        foreach (escQ('SELECT UPPER(rep_code) AS rep, pagina, parte, vigente_desde, vigente_ate FROM ' . escT('esc_sr_divisao')
            . ' WHERE vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)', [$dia, escMaisDias($dia, -6)]) as $l) {
            $divisao[$l['rep']][] = ['pagina' => (string) $l['pagina'], 'parte' => max(0.0, min(1.0, (float) $l['parte'])),
                'de' => substr((string) $l['vigente_desde'], 0, 10), 'ate' => $l['vigente_ate'] !== null ? substr((string) $l['vigente_ate'], 0, 10) : null];
        }
    }
    $soAssist = [];
    $foraPag = [];
    if (escQ1("SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'esc_pagina_assistente'", [ESC_SCHEMA])) {
        $comPapel = (bool) escQ1("SELECT 1 AS ok FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'esc_pagina_assistente' AND COLUMN_NAME = 'papel'", [ESC_SCHEMA]);
        foreach (escQ('SELECT pagina, UPPER(rep_code) AS rep' . ($comPapel ? ', papel' : '') . ' FROM ' . escT('esc_pagina_assistente') . ' WHERE' . $vig, [$dia, $dia]) as $l) {
            if (($l['papel'] ?? 'ASSISTENTE') === 'FORA') {
                $foraPag[$l['rep']][$l['pagina']] = true;
            } else {
                $soAssist[$l['rep']][$l['pagina']] = true;
            }
        }
    }
    // Plataformas só com SR brasileiro (v4_plataforma_so_brasil, padrão Privacy; pedido de 02/11): quem
    // tem outra nacionalidade no headcount fica FORA das páginas ativas delas (5c_pages.platform).
    $soBrasil = array_values(array_filter(array_map('trim', explode(',', escV4Param('v4_plataforma_so_brasil', 'Privacy')))));
    if ($soBrasil && $pessoas) {
        $pagsBrasil = array_column(escQ('SELECT name FROM `5c_pages`.`pages` WHERE active = 1 AND platform IN (' . escIn($soBrasil) . ')', $soBrasil), 'name');
        if ($pagsBrasil) {
            foreach (escQ('SELECT DISTINCT UPPER(code) AS rep FROM `5c_headcount`.`headcount` WHERE code IN (' . escIn(array_keys($pessoas)) . ") AND nacionality <> '' AND nacionality <> 'Brazil'", array_keys($pessoas)) as $h) {
                foreach ($pagsBrasil as $pg) {
                    $foraPag[$h['rep']][$pg] = true;
                }
            }
        }
    }
    // Quem já conhece cada página: dias escalados nela no 5C Schedule (menos troca de gente).
    $historico = [];
    if ($pessoas && $nomes) {
        $hd = max(7, (int) escV4Param('v4_historico_dias', '56'));
        foreach (escQ('SELECT UPPER(codigo) AS rep, modeloNome AS pagina, COUNT(DISTINCT DATE(DATE_SUB(STR_TO_DATE(shiftDateAndHour, \'%Y-%m-%dT%H:%i:%s\'), INTERVAL 5 HOUR))) AS dias
                         FROM `5c_schedule`.`scheduleday`
                        WHERE codigo IN (' . escIn(array_keys($pessoas)) . ') AND shiftDateAndHour >= ? AND shiftDateAndHour < ?
                        GROUP BY UPPER(codigo), modeloNome', array_merge(array_keys($pessoas), [escCorteDados(escMaisDias($dia, -$hd - 7)) . 'T00:00:00', segundaDaSemana($dia) . 'T00:00:00'])) as $r) {
            if (isset($nomes[$r['pagina']])) {
                $historico[$r['rep']][$r['pagina']] = (int) $r['dias'];
            }
        }
    }
    // P-02: quem conhece a página = v4_cor_conhece_dias (7) dias ou mais nela nos últimos v4_cor_janela_dias (90).
    $conheceCor = [];
    if ($pessoas && $nomes) {
        $jan = max(7, (int) escV4Param('v4_cor_janela_dias', '90'));
        $minDias = max(1, (int) escV4Param('v4_cor_conhece_dias', '7'));
        foreach (escQ('SELECT UPPER(codigo) AS rep, modeloNome AS pagina, COUNT(DISTINCT DATE(DATE_SUB(STR_TO_DATE(shiftDateAndHour, \'%Y-%m-%dT%H:%i:%s\'), INTERVAL 5 HOUR))) AS dias
                         FROM `5c_schedule`.`scheduleday`
                        WHERE codigo IN (' . escIn(array_keys($pessoas)) . ') AND shiftDateAndHour >= ? AND shiftDateAndHour < ?
                        GROUP BY UPPER(codigo), modeloNome', array_merge(array_keys($pessoas), [escCorteDados(escMaisDias($dia, -$jan - 7)) . 'T00:00:00', segundaDaSemana($dia) . 'T00:00:00'])) as $r) {
            if (isset($nomes[$r['pagina']]) && (int) $r['dias'] >= $minDias) {
                $conheceCor[$r['rep']][$r['pagina']] = true;
            }
        }
    }
    $overlap = escV4Param('v4_overlap_posicao_ciclo', '');
    return ['modelo' => $modelo, 'conhece_cor' => $conheceCor, 'nucleos' => $nucleos, 'pessoas' => $pessoas, 'postos' => $postos, 'equipes' => $equipes,
        'historico' => $historico, 'merito' => $mer['nota'], 'faixa' => $mer['faixa'], 'anos' => $mer['anos'], 'prioritaria' => $prioritaria,
        'ancora' => escV4Param('v4_rotacao_ancora', '2026-11-02'),
        'overlap_posicoes' => array_map('intval', array_filter(array_map('trim', explode(',', $overlap)), 'strlen')),
        'overlap_pagina' => $pagOverlap ? ['page_id' => (int) $pagOverlap['id'], 'pagina' => $pagOverlap['name']] : null,
        'receita' => $receita, 'receita_modelo' => $receitaModelo, 'desconsiderada' => $desconsiderada, 'modelo_pagina' => $modeloPag, 'agencia_pagina' => $agenciaPag,
        'over_max' => (float) str_replace(',', '.', escV4Param('v4_over_capacity_max', '0.30')),
        'melhores' => $melhores, 'prioridade' => $prioridade, 'divisao' => $divisao, 'so_assist' => $soAssist, 'fora' => $foraPag] + (function_exists('escCasaisMotor') ? escCasaisMotor($dia) : []);
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
// Página desconsiderada não conta (não tem SLA a cumprir). null = a Política não deixa juntar.
function escV4CapacidadeJunta(array $a, array $b, array $modeloPag, array $desconsiderada = []): ?float {
    $pags = [];
    foreach (array_merge($a['paginas'], $b['paginas']) as $pg) {
        if (!isset($desconsiderada[$pg['pagina']])) {
            $pags[$pg['pagina']] = $modeloPag[$pg['pagina']] ?? ('?' . $pg['pagina']);
        }
    }
    $n = count($pags);
    if ($n === 0) {
        return 35.0;
    }
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
