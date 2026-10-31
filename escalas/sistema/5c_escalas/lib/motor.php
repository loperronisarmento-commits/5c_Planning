<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/motor.php
// Motor do rascunho da escala: releitura do realizado, geração,
// recálculo, De→Para da escala e fotos/De→Para das entradas.
//
// Lê: Capacity (fc_escala_pagina), páginas (vw_pagina), estrutura do
// Planner, escala postada (scheduleday) e o 5c_escalas.
// Grava: só no 5c_escalas (esc_plano*, esc_sugestao, esc_ciclo_estado,
// esc_entrada_*, esc_recalculo_evento, esc_plano_diferenca).
//
// Ordem de preenchimento de cada página, por dia e turno:
//   1. titulares da party dona (Primaris/Secundus de folga não entram);
//   2. titulares de outras parties do mesmo time;
//   3. Tertius do time (regular para quem está mais abaixo do alvo);
//   4. Tertius como assistente (página que pede 2 ou mais reps);
//   5. Buffers do turno;
//   6. o que sobrar vira lacuna.
// Titular ou Tertius de serviço sem página vira assistente na maior
// página da party (titular) ou do time (Tertius). Buffer sem página
// vira sugestão de remoção por falta de workload.
// ===============================================

const ESC_ANCORA = '2026-10-05';
const ESC_TURNOS = ['A' => ['T2', 'T3'], 'C' => ['T4', 'T5'], 'D' => ['T6', 'T1']];

function escDiasDaSemana(string $seg): array {
    $dias = [];
    for ($i = 0; $i < 7; $i++) {
        $dias[] = date('Y-m-d', strtotime("{$seg} +{$i} day"));
    }
    return $dias;
}

function escMaisDias(string $dia, int $n): string {
    return date('Y-m-d', strtotime("{$dia} {$n} day"));
}

function escMaxSeguidos(string $ciclo): int {
    if (strpos($ciclo, '0') === false) {
        return strlen($ciclo);
    }
    $maior = 0;
    $atual = 0;
    foreach (str_split($ciclo . $ciclo) as $c) {
        $atual = $c === '1' ? $atual + 1 : 0;
        $maior = max($maior, $atual);
    }
    return min($maior, strlen($ciclo));
}

// Trabalha no dia pelo ciclo? (folgas fixas valem para modelo SEMANA)
function escTrabalhaNoDia(array $cfg, int $trilha, string $dia): bool {
    $ciclo = $cfg['ciclo'];
    $L = strlen($ciclo);
    if ($cfg['mtipo'] === 'SEMANA') {
        $dow = (int) date('N', strtotime($dia)) - 1;
        if (!empty($cfg['folgas_fixas'])) {
            return $cfg['folgas_fixas'][$dow] !== '1';
        }
        return $ciclo[(($dow + $trilha) % 7) % $L] === '1';
    }
    $ancora = $cfg['ancora'] ?: ESC_ANCORA;
    $dias = (int) round((strtotime($dia) - strtotime($ancora)) / 86400);
    $idx = ((($dias + $trilha + $L - 1) % $L) + $L) % $L;
    return $ciclo[$idx] === '1';
}

// ---------- leituras ----------

function escMotorCapacity(array $dias): array {
    $dc = escQ1('SELECT MAX(data_calculo) AS dc FROM `5c_planning`.`fc_escala_pagina`');
    $dc = $dc['dc'] ?? null;
    $grp = escQ1("SELECT valor FROM `5c_planning`.`fc_parametros` WHERE chave = 'capacidade_grupo'");
    $grp = (float) ($grp['valor'] ?? 23) ?: 23.0;
    if (!$dc) {
        return ['dc' => null, 'nec' => [], 'dedicada' => []];
    }
    $linhas = escQ('SELECT dia, bloco, pagina, fans_pico, reps_dedicados FROM `5c_planning`.`fc_escala_pagina` WHERE data_calculo = ?', [$dc]);
    $porData = [];
    $porDow = [];
    foreach ($linhas as $l) {
        $nome = mb_strtolower(trim($l['pagina']));
        $ded = (int) $l['reps_dedicados'];
        $need = $ded > 0 ? (float) $ded : max((float) $l['fans_pico'] / $grp, 1 / 3);
        $dia = substr($l['dia'], 0, 10);
        $porData[$dia][$nome][$l['bloco']] = [$need, $ded > 0];
        $porDow[(int) date('N', strtotime($dia))][$nome][$l['bloco']] = [$need, $ded > 0];
    }
    // Dias fora do horizonte do Capacity usam o mesmo dia da semana.
    $todos = $dias;
    $todos[] = escMaisDias(end($dias), 1);
    $nec = [];
    $dedicada = [];
    foreach ($todos as $dia) {
        $fonte = $porData[$dia] ?? ($porDow[(int) date('N', strtotime($dia))] ?? []);
        foreach ($fonte as $nome => $blocos) {
            foreach ($blocos as $bloco => [$need, $ded]) {
                $nec[$nome][$dia][$bloco] = $need;
                if ($ded) {
                    $dedicada[$nome][$dia][$bloco] = true;
                }
            }
        }
    }
    return ['dc' => $dc, 'nec' => $nec, 'dedicada' => $dedicada];
}

function escMotorPaginas(): array {
    $mapa = [];
    foreach (escQ('SELECT page_id, name, teamName, partyName FROM `5c_bot`.`vw_pagina` WHERE active = 1') as $p) {
        $mapa[mb_strtolower(trim($p['name']))] = [
            'page_id' => (int) $p['page_id'],
            'nome' => $p['name'],
            'time' => $p['teamName'] ?: '',
            'party' => $p['partyName'] ?: '',
        ];
    }
    return $mapa;
}

function escMotorConfig(string $dia): array {
    $linhas = escQ('SELECT c.id, UPPER(c.rep_code) AS rep_code, c.tipo, c.turno, c.trilha, c.ancora, c.folgas_fixas, c.pode_assistente, c.modelo_id,
                           m.ciclo, m.tipo AS mtipo, m.max_seguidos, m.nome AS modelo
                      FROM ' . escT('esc_colaborador_config') . ' c
                      JOIN ' . escT('esc_modelo_escala') . ' m ON m.id = c.modelo_id
                     WHERE c.vigente_desde <= ? AND (c.vigente_ate IS NULL OR c.vigente_ate >= ?)
                     ORDER BY c.vigente_desde', [$dia, $dia]);
    $mapa = [];
    foreach ($linhas as $l) {
        $mapa[$l['rep_code']] = $l;
    }
    return $mapa;
}

// Indisponibilidade aprovada: [rep][dia][turno] = null (turno todo) ou lista de blocos.
function escMotorIndisp(array $dias): array {
    $linhas = escQ('SELECT d.id, UPPER(i.rep_code) AS rep_code, d.dia, d.turno, d.blocos
                      FROM ' . escT('esc_indisponibilidade_data') . ' d
                      JOIN ' . escT('esc_indisponibilidade') . ' i ON i.id = d.indisponibilidade_id
                     WHERE d.status = \'APROVADO\' AND d.dia BETWEEN ? AND ?', [reset($dias), end($dias)]);
    $mapa = [];
    foreach ($linhas as $l) {
        $mapa[$l['rep_code']][substr($l['dia'], 0, 10)][$l['turno']] = [
            'id' => (int) $l['id'],
            'blocos' => $l['blocos'] ? explode(',', $l['blocos']) : null,
        ];
    }
    return $mapa;
}

// Dias trabalhados (dia do turno = horário do bloco − 5 h) de cada rep.
function escMotorRealizado(string $de, string $ate): array {
    $linhas = escQ("SELECT UPPER(codigo) AS code,
                           DATE(DATE_SUB(STR_TO_DATE(shiftDateAndHour, '%Y-%m-%dT%H:%i:%s'), INTERVAL 5 HOUR)) AS dia
                      FROM `5c_schedule`.`scheduleday`
                     WHERE shiftDateAndHour >= ? AND shiftDateAndHour < ? AND modeloId <> 3
                     GROUP BY code, dia",
        [$de . 'T05:00:00', escMaisDias($ate, 1) . 'T05:00:00']);
    $mapa = [];
    foreach ($linhas as $l) {
        $mapa[$l['code']][substr($l['dia'], 0, 10)] = true;
    }
    return $mapa;
}

// ---------- releitura: posição no ciclo ----------

function escMotorReleitura(array $config, array $realizado, string $seg): array {
    $fim = escMaisDias($seg, -1);
    $inicio = escMaisDias($seg, -28);
    $janela = [];
    for ($d = $inicio; $d <= $fim; $d = escMaisDias($d, 1)) {
        $janela[] = $d;
    }
    $estado = [];
    foreach ($config as $rep => $cfg) {
        $feitos = $realizado[$rep] ?? [];
        $L = $cfg['mtipo'] === 'SEMANA' ? 7 : strlen($cfg['ciclo']);
        $primeiro = $feitos ? min(array_keys($feitos)) : null;
        $melhor = null;
        $melhorScore = -1;
        if ($primeiro !== null && !($cfg['mtipo'] === 'SEMANA' && !empty($cfg['folgas_fixas']))) {
            for ($t = 0; $t < $L; $t++) {
                $score = 0;
                foreach ($janela as $d) {
                    if ($d < $primeiro) {
                        continue;
                    }
                    if (escTrabalhaNoDia($cfg, $t, $d) === isset($feitos[$d])) {
                        $score++;
                    }
                }
                if ($score > $melhorScore) {
                    $melhorScore = $score;
                    $melhor = $t;
                }
            }
        }
        // Sem histórico: espalha pelas trilhas para não folgar todo mundo no mesmo dia.
        $trilhaCalc = $melhor ?? (abs(crc32($rep)) % max(1, $L));
        $trilha = $cfg['trilha'] !== null ? (int) $cfg['trilha'] : $trilhaCalc;

        $seguidos = 0;
        for ($d = $fim; $d >= $inicio && isset($feitos[$d]); $d = escMaisDias($d, -1)) {
            $seguidos++;
        }
        $ultimaFolga = null;
        for ($d = $fim; $d >= $inicio; $d = escMaisDias($d, -1)) {
            if (!isset($feitos[$d])) {
                $ultimaFolga = $d;
                break;
            }
        }
        $estado[$rep] = [
            'trilha' => $trilha,
            'trilha_calculada' => $melhor,
            'seguidos' => $seguidos,
            'ultima_folga' => $ultimaFolga,
            'divergente' => $cfg['trilha'] !== null && $melhor !== null && (int) $cfg['trilha'] !== $melhor,
            'ate_dia' => $fim,
        ];
    }
    return $estado;
}

// ---------- alocação (pura: só arrays) ----------

/*
 * $in = [
 *   'dias' => [7 datas], 'config' => rep => cfg, 'estado' => rep => releitura,
 *   'estrutura' => rep => [team, party, role], 'paginas' => nome => página,
 *   'cap' => escMotorCapacity(), 'indisp' => escMotorIndisp(), 'travadas' => [linhas]
 * ]
 * Devolve ['alocacoes' => [...], 'folgas' => [...], 'resumo' => [...], 'ociosos' => [...]]
 */
function escMotorAlocar(array $in): array {
    $dias = $in['dias'];
    $config = $in['config'];
    $estrutura = $in['estrutura'];
    $paginas = $in['paginas'];
    $nec = $in['cap']['nec'];
    $dedicada = $in['cap']['dedicada'];
    $indisp = $in['indisp'];

    // Quem trabalha em cada dia, pelo ciclo + indisponibilidade de turno todo.
    $trabalha = [];
    $folgas = [];
    foreach ($config as $rep => $cfg) {
        $trilha = (int) ($in['estado'][$rep]['trilha'] ?? 0);
        foreach ($dias as $dia) {
            $ind = $indisp[$rep][$dia][$cfg['turno']] ?? null;
            if (!escTrabalhaNoDia($cfg, $trilha, $dia)) {
                $folgas[] = ['rep_code' => $rep, 'dia' => $dia, 'tipo' => $cfg['mtipo'] === 'SEMANA' && $cfg['folgas_fixas'] ? 'FOLGA_FIXA' : 'CICLO', 'ref_id' => null];
            } elseif ($ind !== null && $ind['blocos'] === null) {
                $folgas[] = ['rep_code' => $rep, 'dia' => $dia, 'tipo' => 'INDISPONIBILIDADE', 'ref_id' => $ind['id']];
            } else {
                $trabalha[$rep][$dia] = true;
            }
        }
    }

    // Anomalias programadas: sequência do realizado + plano acima do máximo do modelo.
    $anomalias = [];
    foreach ($config as $rep => $cfg) {
        $run = (int) ($in['estado'][$rep]['seguidos'] ?? 0);
        foreach ($dias as $dia) {
            $run = isset($trabalha[$rep][$dia]) ? $run + 1 : 0;
            if ($run > (int) $cfg['max_seguidos']) {
                $anomalias[] = ['rep_code' => $rep, 'dia' => $dia, 'seguidos' => $run, 'limite' => (int) $cfg['max_seguidos']];
            }
        }
    }

    // Páginas por party e por time.
    $paginaPorNome = [];
    foreach ($nec as $nome => $x) {
        $pg = $paginas[$nome] ?? null;
        $paginaPorNome[$nome] = $pg ?? ['page_id' => 0, 'nome' => $nome, 'time' => '', 'party' => ''];
    }

    $travadasPorCelula = [];
    foreach ($in['travadas'] ?? [] as $t) {
        $travadasPorCelula[$t['dia'] . '|' . $t['bloco'] . '|' . $t['page_id']][] = $t;
    }

    $alocacoes = [];
    $ociosos = [];
    $merito = []; // Tertius: [rep] = [regular, total]
    $resumoOrigem = [];

    foreach ($dias as $dia) {
        foreach (ESC_TURNOS as $turno => $blocos) {
            $diaBloco = [$blocos[0] => $dia, $blocos[1] => $turno === 'D' ? escMaisDias($dia, 1) : $dia];

            // Necessidade do turno por página: o maior dos dois blocos.
            $slots = [];
            foreach ($nec as $nome => $porDia) {
                $n0 = $porDia[$diaBloco[$blocos[0]]][$blocos[0]] ?? 0;
                $n1 = $porDia[$diaBloco[$blocos[1]]][$blocos[1]] ?? 0;
                $need = max($n0, $n1);
                if ($need <= 0) {
                    continue;
                }
                $ded = isset($dedicada[$nome][$diaBloco[$blocos[0]]][$blocos[0]]) || isset($dedicada[$nome][$diaBloco[$blocos[1]]][$blocos[1]]);
                $pg = $paginaPorNome[$nome];
                if ($ded) {
                    for ($k = 0; $k < (int) ceil($need); $k++) {
                        $slots[] = ['nome' => $nome, 'pg' => $pg, 'peso' => 1.0, 'papel' => $k === 0 ? 'REGULAR' : 'ASSISTENTE', 'rep' => null, 'origem' => null, 'need' => $need];
                    }
                } else {
                    $slots[] = ['nome' => $nome, 'pg' => $pg, 'peso' => $need, 'papel' => 'REGULAR', 'rep' => null, 'origem' => null, 'need' => $need];
                }
            }
            usort($slots, static fn($a, $b) => [$a['papel'] === 'ASSISTENTE', -$a['need']] <=> [$b['papel'] === 'ASSISTENTE', -$b['need']]);

            // Quem está de serviço neste turno.
            $cap = [];
            $titulares = [];
            $tertius = [];
            $buffers = [];
            foreach ($config as $rep => $cfg) {
                if ($cfg['turno'] !== $turno || !isset($trabalha[$rep][$dia])) {
                    continue;
                }
                $cap[$rep] = 1.0;
                $e = $estrutura[$rep] ?? null;
                if ($cfg['tipo'] === 'BUFFER') {
                    $buffers[] = $rep;
                } elseif ($e && $e['role'] === 'Tertius') {
                    $tertius[$e['team']][] = $rep;
                } elseif ($e) {
                    $titulares[$e['party']][] = $rep;
                }
            }

            $atribuir = static function (array &$slot, array $candidatos, string $origem) use (&$cap): bool {
                $melhor = null;
                foreach ($candidatos as $rep) {
                    $livre = $cap[$rep] ?? 0;
                    if ($slot['peso'] >= 0.999) {
                        if ($livre >= 0.999) {
                            $melhor = $rep;
                            break;
                        }
                    } elseif ($livre + 1e-9 >= $slot['peso'] && ($melhor === null || $livre < $cap[$melhor])) {
                        $melhor = $rep; // encaixe mais justo
                    }
                }
                if ($melhor === null) {
                    return false;
                }
                $cap[$melhor] -= $slot['peso'];
                $slot['rep'] = $melhor;
                $slot['origem'] = $origem;
                return true;
            };

            // 1. party dona
            foreach ($slots as &$s) {
                if ($s['pg']['party'] !== '' && isset($titulares[$s['pg']['party']])) {
                    $atribuir($s, $titulares[$s['pg']['party']], 'PARTY_DONA');
                }
            }
            unset($s);
            // 2. outras parties do mesmo time
            foreach ($slots as &$s) {
                if ($s['rep'] !== null || $s['pg']['time'] === '') {
                    continue;
                }
                $outros = [];
                foreach ($titulares as $party => $reps) {
                    if ($party !== $s['pg']['party'] && ($estrutura[$reps[0]]['team'] ?? '') === $s['pg']['time']) {
                        $outros = array_merge($outros, $reps);
                    }
                }
                $atribuir($s, $outros, 'OUTRA_PARTY');
            }
            unset($s);
            // 3 e 4. Tertius do time: regular para quem está mais abaixo do alvo.
            foreach ($slots as &$s) {
                if ($s['rep'] !== null || $s['pg']['time'] === '' || empty($tertius[$s['pg']['time']])) {
                    continue;
                }
                $lista = $tertius[$s['pg']['time']];
                usort($lista, static function ($a, $b) use ($merito, $s) {
                    $ra = ($merito[$a][0] ?? 0) / max(1, $merito[$a][1] ?? 0);
                    $rb = ($merito[$b][0] ?? 0) / max(1, $merito[$b][1] ?? 0);
                    return $s['papel'] === 'REGULAR' ? $ra <=> $rb : $rb <=> $ra;
                });
                if ($atribuir($s, $lista, $s['papel'] === 'REGULAR' ? 'TERTIUS' : 'TERTIUS_ASSISTENTE')) {
                    $merito[$s['rep']][0] = ($merito[$s['rep']][0] ?? 0) + ($s['papel'] === 'REGULAR' ? 1 : 0);
                    $merito[$s['rep']][1] = ($merito[$s['rep']][1] ?? 0) + 1;
                }
            }
            unset($s);
            // 5. Buffers
            foreach ($slots as &$s) {
                if ($s['rep'] === null) {
                    $atribuir($s, $buffers, 'BUFFER');
                }
            }
            unset($s);

            // Titular/Tertius de serviço sem página: assistente na maior página.
            $extras = [];
            foreach ($titulares as $party => $reps) {
                foreach ($reps as $rep) {
                    if (($cap[$rep] ?? 0) >= 0.999) {
                        $alvo = null;
                        foreach ($slots as $s) {
                            if ($s['pg']['party'] === $party && ($alvo === null || $s['need'] > $alvo['need'])) {
                                $alvo = $s;
                            }
                        }
                        if ($alvo) {
                            $extras[] = ['nome' => $alvo['nome'], 'pg' => $alvo['pg'], 'papel' => 'ASSISTENTE', 'rep' => $rep, 'origem' => 'PARTY_DONA', 'motivo' => 'titular sem página livre no turno: assistente na maior página da party'];
                            $cap[$rep] = 0;
                        } else {
                            $ociosos[] = ['rep_code' => $rep, 'dia' => $dia, 'turno' => $turno, 'tipo' => 'TITULAR', 'motivo' => 'party sem página com necessidade no turno'];
                        }
                    }
                }
            }
            foreach ($tertius as $time => $reps) {
                foreach ($reps as $rep) {
                    if (($cap[$rep] ?? 0) >= 0.999) {
                        $alvo = null;
                        foreach ($slots as $s) {
                            if ($s['pg']['time'] === $time && ($alvo === null || $s['need'] > $alvo['need'])) {
                                $alvo = $s;
                            }
                        }
                        if ($alvo) {
                            $extras[] = ['nome' => $alvo['nome'], 'pg' => $alvo['pg'], 'papel' => 'ASSISTENTE', 'rep' => $rep, 'origem' => 'TERTIUS_ASSISTENTE', 'motivo' => 'Tertius sem página livre no turno: assistente na maior página do time'];
                            $merito[$rep][1] = ($merito[$rep][1] ?? 0) + 1;
                            $cap[$rep] = 0;
                        } else {
                            $ociosos[] = ['rep_code' => $rep, 'dia' => $dia, 'turno' => $turno, 'tipo' => 'TERTIUS', 'motivo' => 'time sem página com necessidade no turno'];
                        }
                    }
                }
            }
            foreach ($buffers as $rep) {
                if (($cap[$rep] ?? 0) >= 0.999) {
                    $ociosos[] = ['rep_code' => $rep, 'dia' => $dia, 'turno' => $turno, 'tipo' => 'BUFFER', 'motivo' => 'sem página precisando no turno'];
                }
            }

            // Saída por bloco (só nos blocos em que a página tem necessidade).
            foreach (array_merge($slots, $extras) as $s) {
                foreach ($blocos as $bloco) {
                    $diaB = $diaBloco[$bloco];
                    if (($nec[$s['nome']][$diaB][$bloco] ?? 0) <= 0) {
                        continue;
                    }
                    $pid = (int) $s['pg']['page_id'];
                    $chave = $diaB . '|' . $bloco . '|' . $pid;
                    if (isset($travadasPorCelula[$chave])) {
                        continue; // ajuste manual travado vence
                    }
                    $rep = $s['rep'];
                    if ($rep !== null) {
                        $ind = $indisp[$rep][$dia][$turno] ?? null;
                        if ($ind && is_array($ind['blocos']) && in_array($bloco, $ind['blocos'], true)) {
                            $rep = null; // indisponível só neste bloco
                        }
                    }
                    $origem = $rep === null ? 'LACUNA' : $s['origem'];
                    $alocacoes[] = [
                        'dia' => $diaB, 'bloco' => $bloco, 'turno' => $turno,
                        'page_id' => $pid, 'pagina' => $s['pg']['nome'],
                        'rep_code' => $rep, 'papel' => $s['papel'], 'origem' => $origem,
                        'travada' => 0,
                        'motivo' => $s['motivo'] ?? escMotivoOrigem($origem, $s),
                    ];
                    $resumoOrigem[$origem] = ($resumoOrigem[$origem] ?? 0) + 1;
                }
            }
        }
    }

    // Travadas entram como estão.
    foreach ($in['travadas'] ?? [] as $t) {
        $alocacoes[] = [
            'dia' => $t['dia'], 'bloco' => $t['bloco'], 'turno' => $t['turno'],
            'page_id' => (int) $t['page_id'], 'pagina' => $t['pagina'],
            'rep_code' => $t['rep_code'], 'papel' => $t['papel'], 'origem' => $t['origem'],
            'travada' => 1, 'motivo' => $t['motivo'],
        ];
    }

    $lacunas = count(array_filter($alocacoes, static fn($a) => $a['rep_code'] === null));
    $semParty = array_values(array_unique(array_map(static fn($p) => $p['nome'], array_filter($paginaPorNome, static fn($p) => $p['party'] === ''))));
    return [
        'alocacoes' => $alocacoes,
        'folgas' => $folgas,
        'ociosos' => $ociosos,
        'resumo' => [
            'blocos' => count($alocacoes),
            'lacunas' => $lacunas,
            'por_origem' => $resumoOrigem,
            'anomalias' => count($anomalias),
            'anomalias_lista' => array_slice($anomalias, 0, 100),
            'buffers_ociosos' => count(array_filter($ociosos, static fn($o) => $o['tipo'] === 'BUFFER')),
            'paginas_sem_party' => $semParty,
            'pessoas' => count($config),
        ],
    ];
}

function escMotivoOrigem(string $origem, array $s): string {
    $party = $s['pg']['party'] ?: 'sem party';
    switch ($origem) {
        case 'PARTY_DONA': return 'titular da party dona (' . $party . ')';
        case 'OUTRA_PARTY': return 'cobertura de outra party do time ' . $s['pg']['time'];
        case 'TERTIUS': return 'Tertius do time como regular';
        case 'TERTIUS_ASSISTENTE': return 'Tertius do time como assistente';
        case 'BUFFER': return 'Buffer do turno';
        default: return 'ninguém disponível no turno';
    }
}

// ---------- fotos das entradas e De→Para das entradas ----------

function escMotorFotosAtuais(): array {
    $fotos = [];
    $hoje = date('Y-m-d');

    $cap = escMotorCapacity(escDiasDaSemana(segundaDaSemana($hoje)));
    $m = [];
    foreach ($cap['nec'] as $nome => $porDia) {
        foreach ($porDia as $dia => $blocos) {
            foreach ($blocos as $bloco => $need) {
                $m[$nome . '|' . date('N', strtotime($dia)) . '|' . $bloco] = round($need, 2);
            }
        }
    }
    $fotos['CAPACITY'] = [$m, (string) $cap['dc']];

    $m = [];
    foreach (escEstrutura() as $rep => $e) {
        $m[$rep] = $e['team'] . '|' . $e['party'] . '|' . $e['role'];
    }
    $fotos['ESTRUTURA'] = [$m, null];

    $m = [];
    foreach (escMotorPaginas() as $p) {
        $m[(string) $p['page_id']] = $p['nome'] . '|' . $p['party'];
    }
    $fotos['PAGINAS'] = [$m, null];

    $m = [];
    foreach (escMotorConfig($hoje) as $rep => $c) {
        $m[$rep] = $c['turno'] . '|' . $c['tipo'] . '|' . $c['modelo_id'] . '|' . ($c['trilha'] ?? '') . '|' . ($c['folgas_fixas'] ?? '');
    }
    $fotos['COLABORADORES'] = [$m, null];

    $m = [];
    foreach (escQ('SELECT d.id, UPPER(i.rep_code) AS rep, d.dia, d.turno, d.blocos FROM ' . escT('esc_indisponibilidade_data') . ' d JOIN ' . escT('esc_indisponibilidade') . ' i ON i.id = d.indisponibilidade_id WHERE d.status = \'APROVADO\' AND d.dia >= CURDATE() - INTERVAL 7 DAY') as $l) {
        $m[(string) $l['id']] = $l['rep'] . '|' . substr($l['dia'], 0, 10) . '|' . $l['turno'] . '|' . ($l['blocos'] ?? '');
    }
    $fotos['INDISPONIBILIDADE'] = [$m, null];

    $m = [];
    foreach (escQ('SELECT id, ciclo, tipo, trilhas, ativo FROM ' . escT('esc_modelo_escala')) as $l) {
        $m[(string) $l['id']] = $l['ciclo'] . '|' . $l['tipo'] . '|' . $l['trilhas'] . '|' . $l['ativo'];
    }
    $fotos['MODELOS'] = [$m, null];

    $m = [];
    foreach (escQ("SELECT pageId, MAX(partyName) AS party, MAX(`timestamp`) AS ts FROM `5c_historic_planner`.`historic_page_planner` WHERE changeTypeId = 40 AND isApplied = 0 AND `timestamp` >= DATE_FORMAT(NOW() - INTERVAL 1 DAY, '%Y-%m-%dT%H:%i:%s') GROUP BY pageId") as $l) {
        $m[(string) $l['pageId']] = $l['party'] . '|' . $l['ts'];
    }
    $fotos['TROCAS_PAGINA'] = [$m, null];

    $saida = [];
    foreach ($fotos as $fonte => [$mapa, $ref]) {
        ksort($mapa);
        $saida[$fonte] = ['conteudo' => $mapa, 'hash' => hash('sha256', json_encode($mapa, JSON_UNESCAPED_UNICODE)), 'referencia' => $ref, 'linhas' => count($mapa)];
    }
    return $saida;
}

// Grava as fotos que mudaram e o De→Para delas. Devolve [fonte => foto_id] e as fontes que mudaram.
function escMotorRegistrarFotos(array $fotos): array {
    $ids = [];
    $mudaram = [];
    foreach ($fotos as $fonte => $f) {
        $ultima = escQ1('SELECT id, hash, conteudo FROM ' . escT('esc_entrada_foto') . ' WHERE fonte = ? ORDER BY id DESC LIMIT 1', [$fonte]);
        if ($ultima && $ultima['hash'] === $f['hash']) {
            $ids[$fonte] = (int) $ultima['id'];
            continue;
        }
        escW('INSERT INTO ' . escT('esc_entrada_foto') . ' (fonte, referencia, hash, linhas, conteudo) VALUES (?, ?, ?, ?, ?)',
            [$fonte, $f['referencia'], $f['hash'], $f['linhas'], json_encode($f['conteudo'], JSON_UNESCAPED_UNICODE)]);
        $novo = escUltimoId();
        $ids[$fonte] = $novo;
        if (!$ultima) {
            continue; // primeira foto: nada a comparar
        }
        $mudaram[$fonte] = $novo;
        $antes = json_decode((string) $ultima['conteudo'], true) ?: [];
        $linhas = [];
        foreach (array_unique(array_merge(array_keys($antes), array_keys($f['conteudo']))) as $chave) {
            $de = $antes[$chave] ?? null;
            $para = $f['conteudo'][$chave] ?? null;
            if ((string) $de !== (string) $para) {
                $linhas[] = ['foto_de_id' => (int) $ultima['id'], 'foto_para_id' => $novo, 'fonte' => $fonte,
                    'chave' => mb_substr((string) $chave, 0, 150), 'campo' => escCampoDaFonte($fonte),
                    'valor_de' => $de === null ? null : mb_substr((string) $de, 0, 255),
                    'valor_para' => $para === null ? null : mb_substr((string) $para, 0, 255)];
            }
            if (count($linhas) >= 2000) {
                break;
            }
        }
        escInserirLote('esc_entrada_diferenca', ['foto_de_id', 'foto_para_id', 'fonte', 'chave', 'campo', 'valor_de', 'valor_para'], $linhas);
    }
    return [$ids, $mudaram];
}

function escCampoDaFonte(string $fonte): string {
    return [
        'CAPACITY' => 'reps_necessarios', 'ESTRUTURA' => 'time|party|papel', 'PAGINAS' => 'pagina|party',
        'COLABORADORES' => 'turno|tipo|modelo|trilha|folgas', 'INDISPONIBILIDADE' => 'rep|dia|turno|blocos',
        'MODELOS' => 'ciclo|tipo|trilhas|ativo', 'TROCAS_PAGINA' => 'party_para|corte',
    ][$fonte] ?? 'valor';
}

function escHashEntradas(array $ids): string {
    ksort($ids);
    return hash('sha256', json_encode($ids));
}

// ---------- geração e recálculo ----------

function escPlanoAtual(string $seg): ?array {
    return escQ1('SELECT * FROM ' . escT('esc_plano') . " WHERE semana_inicio = ? AND time_nome = '' AND status IN ('RASCUNHO','PUBLICADO') ORDER BY versao DESC, subversao DESC LIMIT 1", [$seg]);
}

function escPlanoPublicado(string $seg): ?array {
    return escQ1('SELECT * FROM ' . escT('esc_plano') . " WHERE semana_inicio = ? AND time_nome = '' AND status = 'PUBLICADO' ORDER BY versao DESC, subversao DESC LIMIT 1", [$seg]);
}

function escPlanoRotulo(array $p): string {
    return 'v' . (int) $p['versao'] . ((int) $p['subversao'] > 0 ? '.' . (int) $p['subversao'] : '');
}

/*
 * Gera uma versão do plano.
 *   $modo = 'NOVA'      -> próxima versão inteira (v4)
 *   $modo = 'RECALCULO' -> subversão da atual (v3.1), mantém travadas e
 *                          grava o De→Para da escala.
 */
// $minimo: "Recalcular com o mínimo de mudanças" (lib/minimo.php), a partir da versão publicada.
function escMotorGerar(string $seg, string $modo, ?int $userId, ?int $recalculoId = null, bool $minimo = false): int {
    @set_time_limit(180);
    $dias = escDiasDaSemana($seg);
    $base = escPlanoAtual($seg);

    $fotos = escMotorFotosAtuais();
    [$fotoIds] = escMotorRegistrarFotos($fotos);

    $config = escMotorConfig($seg);
    $realizado = escMotorRealizado(escMaisDias($seg, -28), escMaisDias($seg, -1));
    $estado = escMotorReleitura($config, $realizado, $seg);
    $cap = escMotorCapacity($dias);
    $travadas = [];
    if ($modo === 'RECALCULO' && $base) {
        $travadas = escQ('SELECT dia, bloco, turno, page_id, pagina, rep_code, papel, origem, motivo FROM ' . escT('esc_plano_alocacao') . ' WHERE plano_id = ? AND travada = 1', [(int) $base['id']]);
        foreach ($travadas as &$t) {
            $t['dia'] = substr($t['dia'], 0, 10);
        }
        unset($t);
    }

    if (escV4Ativo($seg)) {
        // Estrutura de 01/11 (motor_v4.php). Na semana da virada só entram os
        // dias a partir dela; antes disso vale a escala já postada.
        $virada = escV4DiaVirada();
        $turnoVirada = escV4TurnoVirada();
        $transicoes = escV4DiasTransicao(); // [] = sem transição
        $inicio = max($seg, $virada);
        $indisp = escMotorIndisp($dias);
        $presentes = [];
        foreach ($transicoes as $dt) {
            if (in_array($dt, $dias, true)) {
                $presentes[$dt] = escV4PresentesTransicao($dt);
            }
        }
        if (!in_array($inicio, $transicoes, true) && ($inicio !== $virada || $turnoVirada === 'A')) {
            // Quem fecha o turno D às 05:00 do primeiro dia do plano fica fora do
            // turno A desse dia (seriam 16 h seguidas; acontece na virada).
            foreach (escV4FimTurnoD($inicio) as $code) {
                $indisp[$code][$inicio]['A'] ??= ['id' => null, 'blocos' => null];
            }
        }
        // Capacity recalculado e postos atualizados antes de gerar (lib/estrutura.php), para a
        // escala não sair com a estrutura defasada; depois nenhum posto sem equipe (lib/equipes.php).
        $GLOBALS['ESC_ESTRUTURA_AUTO'] = $GLOBALS['ESC_ESTRUTURA_AUTO'] ?? escEstruturaAtualizar();
        $GLOBALS['ESC_EQUIPES_AUTO'] = escEquipesCompletar($inicio, $dias[count($dias) - 1]);
        $est = escV4Carregar(end($dias));
        // New Hires (lib/treinamento.php): fora da escala normal até o dia em que ficam
        // disponíveis, mesmo que alguém já tenha cadastrado a pessoa num núcleo. E quem ficou
        // sem página depois de uma saída, até a página nova entrar, com decisão "remover" (ES_37).
        $bloqueios = escTrnBloqueios($dias);
        foreach (escSaidaSemPaginaBloqueios($dias) as $code => $diasBloq) {
            $bloqueios[$code] = ($bloqueios[$code] ?? []) + $diasBloq;
        }
        foreach ($bloqueios as $code => $diasBloq) {
            if (count($diasBloq) === count($dias)) {
                unset($est['pessoas'][$code]);
                continue;
            }
            foreach (array_keys($diasBloq) as $d) {
                foreach (array_keys(ESC_TURNOS) as $tb) {
                    $indisp[$code][$d][$tb] ??= ['id' => null, 'blocos' => null];
                }
            }
        }
        $saida = escMotorAlocarV4([
            'dias' => $dias, 'est' => $est, 'indisp' => $indisp,
            'travadas' => $travadas, 'virada' => $virada, 'turno_virada' => $turnoVirada, 'transicao' => $transicoes,
            'treino_cobre' => escTrnCobertura($dias),
            'presentes_por_dia' => $presentes,
            'ultimo' => escV4UltimoDoPlanoAnterior($seg),
            // Até o dia da virada inclusive: quem fez o A ou o C antigo de 31/10 conta nos dias seguidos de 01/11.
            'realizado' => escMotorRealizado(escMaisDias($inicio, -14), max(escMaisDias($inicio, -1), min($virada, escMaisDias($inicio, 6)))),
        ]);
        // Liderança (OS, OC e OA) no mesmo plano, na página 5C Schedule (-8, -7, -6 no CSV).
        $lider = escLiderancaAlocacoes($seg, $travadas, $est['overlap_pagina'] ?? null);
        // Trainees das turmas confirmadas, na mesma página 5C Schedule (-4 no CSV).
        $lider = array_merge($lider, escTrnAlocacoes($seg, $travadas, $est['overlap_pagina'] ?? null));
        $saida['alocacoes'] = array_merge($saida['alocacoes'], $lider);
        foreach ($lider as $a) {
            $saida['resumo']['por_origem'][$a['origem']] = ($saida['resumo']['por_origem'][$a['origem']] ?? 0) + 1;
        }
        // Mínimo de mudanças: parte da versão publicada (a postada) e muda só o necessário.
        $ref = $minimo ? (escPlanoPublicado($seg) ?? $base) : null;
        if ($ref) {
            $refLinhas = escQ('SELECT dia, bloco, turno, page_id, pagina, posto_id, vaga, rep_code, papel, origem, travada, motivo FROM ' . escT('esc_plano_alocacao') . ' WHERE plano_id = ?', [(int) $ref['id']]);
            $info = [];
            foreach ($est['postos'] as $p) {
                foreach ($p['paginas'] as $pg) {
                    $k = (int) $pg['page_id'] . '|' . $p['turno'];
                    $info[$k] = ['fans' => ($info[$k]['fans'] ?? 0) + (float) $p['carga'] * (float) $pg['fracao'], 'unidade' => (int) $p['unidade'],
                        'modelo' => $est['modelo_pagina'][$pg['pagina']] ?? null];
                }
            }
            // Folga do cálculo novo vale também no mínimo (emenda, rotação e indisponibilidade), menos
            // para quem trabalha no dia no cálculo novo (ex.: Overlap ou outro turno).
            $folgaNova = [];
            foreach ($saida['folgas'] ?? [] as $f) {
                $folgaNova[$f['rep_code'] . '|' . substr((string) $f['dia'], 0, 10)] = true;
            }
            foreach ($saida['alocacoes'] as $a) {
                if ($a['rep_code'] !== null) {
                    unset($folgaNova[$a['rep_code'] . '|' . ($a['turno'] === 'D' && $a['bloco'] === 'T1' ? escMaisDias(substr((string) $a['dia'], 0, 10), -1) : substr((string) $a['dia'], 0, 10))]);
                }
            }
            [$saida['alocacoes'], $resMin] = escMotorMinimo($refLinhas, $saida['alocacoes'], $est['pessoas'], $indisp, $bloqueios, $info,
                (float) str_replace(',', '.', escV4Param('v4_minimo_carga_max', '35')), $folgaNova);
            $resMin['base'] = escPlanoRotulo($ref);
            $saida['resumo']['minimo'] = $resMin;
            $saida['resumo']['lacunas'] = count(array_filter($saida['alocacoes'], static fn($a) => $a['rep_code'] === null && $a['origem'] === 'LACUNA'));
            $GLOBALS['ESC_MINIMO'] = $resMin;
        }
        $estruturaPlano = 'V4';
    } else {
        $saida = escMotorAlocar([
            'dias' => $dias, 'config' => $config, 'estado' => $estado,
            'estrutura' => escEstrutura(), 'paginas' => escMotorPaginas(),
            'cap' => $cap, 'indisp' => escMotorIndisp($dias), 'travadas' => $travadas,
        ]);
        $estruturaPlano = 'V3';
    }

    return escTx(function () use ($seg, $dias, $modo, $base, $userId, $recalculoId, $saida, $cap, $fotoIds, $estado, $estruturaPlano, $travadas, $minimo) {
        // Releitura do realizado.
        $linhasEstado = [];
        foreach ($estado as $rep => $e) {
            $linhasEstado[] = ['rep_code' => $rep, 'ate_dia' => $e['ate_dia'], 'dias_seguidos' => min(255, $e['seguidos']),
                'ultima_folga' => $e['ultima_folga'], 'trilha_calculada' => $e['trilha_calculada'], 'divergente_config' => $e['divergente'] ? 1 : 0];
        }
        foreach (array_chunk($linhasEstado, 300) as $lote) {
            $params = [];
            foreach ($lote as $l) {
                array_push($params, $l['rep_code'], $l['ate_dia'], $l['dias_seguidos'], $l['ultima_folga'], $l['trilha_calculada'], $l['divergente_config']);
            }
            escW('INSERT INTO ' . escT('esc_ciclo_estado') . ' (rep_code, ate_dia, dias_seguidos, ultima_folga, trilha_calculada, divergente_config) VALUES '
                . implode(',', array_fill(0, count($lote), '(?, ?, ?, ?, ?, ?)'))
                . ' AS n ON DUPLICATE KEY UPDATE dias_seguidos = n.dias_seguidos, ultima_folga = n.ultima_folga, trilha_calculada = n.trilha_calculada, divergente_config = n.divergente_config, lido_em = CURRENT_TIMESTAMP', $params);
        }

        $ult = escQW('SELECT COALESCE(MAX(versao), 0) AS v FROM ' . escT('esc_plano') . " WHERE semana_inicio = ? AND time_nome = ''", [$seg]);
        if ($modo === 'RECALCULO' && $base) {
            $versao = (int) $base['versao'];
            $sub = escQW('SELECT COALESCE(MAX(subversao), 0) AS s FROM ' . escT('esc_plano') . " WHERE semana_inicio = ? AND time_nome = '' AND versao = ?", [$seg, $versao]);
            $subversao = (int) $sub[0]['s'] + 1;
        } else {
            $versao = (int) $ult[0]['v'] + 1;
            $subversao = 0;
        }

        // Quem está na escala PUBLICADA da semana não sai sozinho: fica e
        // vira sugestão de workload. Depois, De→Para contra a versão anterior.
        $alocacoes = $saida['alocacoes'];
        $diferencas = [];
        $sugestoesWorkload = [];
        $publicado = escQW('SELECT id FROM ' . escT('esc_plano') . " WHERE semana_inicio = ? AND time_nome = '' AND status = 'PUBLICADO' ORDER BY versao DESC, subversao DESC LIMIT 1", [$seg]);
        $protegidos = [];
        if ($publicado) {
            $linhasPub = escQW('SELECT dia, bloco, turno, page_id, pagina, rep_code, papel, origem, motivo FROM ' . escT('esc_plano_alocacao') . ' WHERE plano_id = ? AND rep_code IS NOT NULL', [(int) $publicado[0]['id']]);
            // Remoções já decididas (sugestão de workload aplicada) não voltam.
            $removidos = [];
            foreach (escQW('SELECT s.dados FROM ' . escT('esc_sugestao') . ' s JOIN ' . escT('esc_plano') . " p ON p.id = s.plano_id WHERE p.semana_inicio = ? AND s.tipo = 'WORKLOAD' AND s.status = 'APLICADA'", [$seg]) as $r) {
                $d = json_decode((string) $r['dados'], true);
                if (isset($d['rep_code'], $d['dia'])) {
                    $removidos[$d['rep_code'] . '|' . $d['dia']] = true;
                }
            }
            // Folga de emenda (dias seguidos) e indisponibilidade do cálculo novo também tiram do publicado.
            foreach ($saida['folgas'] ?? [] as $f) {
                if (in_array($f['tipo'], ['EMENDA', 'INDISPONIBILIDADE'], true)) {
                    $removidos[$f['rep_code'] . '|' . substr((string) $f['dia'], 0, 10)] = true;
                }
            }
            if (!$minimo) { // no mínimo de mudanças o publicado já é a base
                [$alocacoes, $sugestoesWorkload, $protegidos] = escMotorProtegerPublicado($linhasPub, $alocacoes, $removidos, $travadas);
            }
        }
        if ($base) {
            $anteriores = escQW('SELECT dia, bloco, turno, page_id, pagina, rep_code, papel, origem, travada, motivo FROM ' . escT('esc_plano_alocacao') . ' WHERE plano_id = ?', [(int) $base['id']]);
            $diferencas = array_merge(escMotorDeParaEscala($anteriores, $alocacoes), $protegidos);
        }

        $parametros = ['fotos' => $fotoIds, 'ancora' => ESC_ANCORA, 'modo' => $modo] + ($minimo ? ['minimo' => true] : []);
        escW('INSERT INTO ' . escT('esc_plano') . ' (semana_inicio, semana_fim, time_nome, estrutura, versao, subversao, status, plano_anterior_id, recalculo_id, capacity_calculo, entradas_hash, parametros, resumo, gerado_por) VALUES (?, ?, \'\', ?, ?, ?, \'RASCUNHO\', ?, ?, ?, ?, ?, ?, ?)', [
            $seg, end($dias), $estruturaPlano, $versao, $subversao, $base ? (int) $base['id'] : null, $recalculoId,
            $cap['dc'], escHashEntradas($fotoIds), json_encode($parametros), json_encode($saida['resumo'], JSON_UNESCAPED_UNICODE), $userId,
        ]);
        $planoId = escUltimoId();

        foreach ($alocacoes as &$a) {
            $a['plano_id'] = $planoId;
        }
        unset($a);
        escInserirLote('esc_plano_alocacao', ['plano_id', 'dia', 'bloco', 'turno', 'page_id', 'pagina', 'posto_id', 'vaga', 'rep_code', 'papel', 'origem', 'travada', 'motivo'], $alocacoes);

        // Folga de emenda (ES_22): sem o tipo novo no banco, grava como folga da rotação.
        $temEmenda = strpos((string) (escQW("SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'esc_plano_folga' AND COLUMN_NAME = 'tipo'", [ESC_SCHEMA])[0]['t'] ?? ''), 'EMENDA') !== false;
        $folgas = array_map(static fn($f) => ['tipo' => $f['tipo'] === 'EMENDA' && !$temEmenda ? 'ROTACAO' : $f['tipo']] + $f + ['plano_id' => $planoId], $saida['folgas']);
        escInserirLote('esc_plano_folga', ['plano_id', 'rep_code', 'dia', 'tipo', 'ref_id'], $folgas);

        // Sugestões: workload (escala publicada), Buffers sem página e lacunas.
        $sugs = [];
        foreach ($sugestoesWorkload as $w) {
            $sugs[] = ['plano_id' => $planoId, 'recalculo_id' => $recalculoId, 'tipo' => 'WORKLOAD',
                'titulo' => 'Remover turno de ' . $w['rep_code'] . ' em ' . date('d/m', strtotime($w['dia'])) . ' (' . $w['turno'] . ')',
                'descricao' => 'O recálculo tiraria esta pessoa de ' . $w['blocos'] . ' bloco(s). Ela já estava na escala publicada, então foi mantida.',
                'efeito' => 'Aplicar remove a pessoa desses blocos no rascunho. Avisar com antecedência.',
                'dados' => json_encode($w, JSON_UNESCAPED_UNICODE), 'status' => 'ABERTA'];
        }
        $porTurno = [];
        foreach ($saida['ociosos'] as $o) {
            if ($o['tipo'] === 'BUFFER') {
                $porTurno[$o['dia'] . '|' . $o['turno']][] = $o['rep_code'];
            }
        }
        foreach ($porTurno as $k => $reps) {
            [$dia, $turno] = explode('|', $k);
            $sugs[] = ['plano_id' => $planoId, 'recalculo_id' => $recalculoId, 'tipo' => 'WORKLOAD',
                'titulo' => count($reps) . ' Buffer(s) sem página em ' . date('d/m', strtotime($dia)) . ' no turno ' . $turno,
                'descricao' => 'Ninguém precisou desses Buffers neste turno: ' . implode(', ', $reps) . '.',
                'efeito' => 'Aplicar marca a folga desses Buffers no rascunho (não estão alocados em página).',
                'dados' => json_encode(['dia' => $dia, 'turno' => $turno, 'reps' => $reps, 'acao' => 'buffer_ocioso']), 'status' => 'ABERTA'];
        }
        $lac = [];
        foreach ($alocacoes as $a) {
            if ($a['rep_code'] === null && $a['origem'] !== 'TREINO') {
                $lac[$a['dia'] . '|' . $a['turno']][] = $a['pagina'] . ' ' . $a['bloco'];
            }
        }
        foreach ($lac as $k => $itens) {
            [$dia, $turno] = explode('|', $k);
            $sugs[] = ['plano_id' => $planoId, 'recalculo_id' => $recalculoId, 'tipo' => 'COBERTURA',
                'titulo' => count($itens) . ' bloco(s) sem ninguém em ' . date('d/m', strtotime($dia)) . ' no turno ' . $turno,
                'descricao' => implode(', ', array_slice(array_unique($itens), 0, 30)),
                'efeito' => $estruturaPlano === 'V4'
                    ? 'Cobrir na grade (ajuste manual) com um buffer de plantão do turno, ou aceitar a lacuna.'
                    : 'Cobrir na grade (ajuste manual) com Tertius ou Buffer de folga, ou aceitar a lacuna.',
                'dados' => json_encode(['dia' => $dia, 'turno' => $turno]), 'status' => 'ABERTA'];
        }
        escInserirLote('esc_sugestao', ['plano_id', 'recalculo_id', 'tipo', 'titulo', 'descricao', 'efeito', 'dados', 'status'], $sugs);

        if ($base) {
            $idsSug = [];
            if ($sugestoesWorkload) {
                foreach (escQW('SELECT id, dados FROM ' . escT('esc_sugestao') . " WHERE plano_id = ? AND tipo = 'WORKLOAD'", [$planoId]) as $r) {
                    $d = json_decode((string) $r['dados'], true);
                    if (isset($d['rep_code'], $d['dia'])) {
                        $idsSug[$d['rep_code'] . '|' . $d['dia']] = (int) $r['id'];
                    }
                }
            }
            foreach ($diferencas as &$d) {
                $d['recalculo_id'] = $recalculoId;
                $d['plano_de_id'] = (int) $base['id'];
                $d['plano_para_id'] = $planoId;
                $d['sugestao_id'] = $d['aplicado'] ? null : ($idsSug[$d['rep_de'] . '|' . $d['dia_turno']] ?? null);
                unset($d['dia_turno']);
            }
            unset($d);
            escInserirLote('esc_plano_diferenca', ['recalculo_id', 'plano_de_id', 'plano_para_id', 'dia', 'bloco', 'page_id', 'pagina', 'tipo', 'rep_de', 'rep_para', 'papel_de', 'papel_para', 'motivo', 'aplicado', 'sugestao_id'], $diferencas);
            if ($base['status'] === 'RASCUNHO') {
                escW('UPDATE ' . escT('esc_plano') . " SET status = 'SUBSTITUIDO' WHERE id = ?", [(int) $base['id']]);
            }
        }
        if ($recalculoId) {
            escW('UPDATE ' . escT('esc_recalculo_evento') . " SET status = 'RECALCULADO', plano_de_id = ?, plano_para_id = ?, processado_em = NOW(), efeito = ? WHERE id = ?", [
                $base ? (int) $base['id'] : null, $planoId,
                json_encode(['diferencas' => count($diferencas), 'mantidos_publicado' => count($sugestoesWorkload), 'lacunas' => $saida['resumo']['lacunas']]),
                $recalculoId,
            ]);
        }
        escLog($modo === 'RECALCULO' ? 'plano.recalcular' : 'plano.gerar', 'esc_plano', $planoId, null,
            ['semana' => $seg, 'versao' => $versao . '.' . $subversao, 'lacunas' => $saida['resumo']['lacunas']], $userId ? 'SITE' : 'SISTEMA');
        return $planoId;
    });
}

// Mantém quem está na escala publicada e o recálculo deixaria sem trabalho
// naquele turno. Trocar de página no mesmo turno não é tirar o dia: aí a
// pessoa só muda de página. Devolve [alocações finais, sugestões de
// workload, linhas do De→Para com aplicado = 0].
function escMotorProtegerPublicado(array $publicadas, array $novas, array $removidos = [], array $travadas = []): array {
    // Célula ajustada à mão (travada) vale como está: o publicado não volta para ela.
    $celTravada = [];
    foreach ($travadas as $t) {
        $celTravada[substr((string) $t['dia'], 0, 10) . '|' . $t['bloco'] . '|' . (int) $t['page_id']] = true;
    }
    $temNa = [];
    foreach ($novas as $n) {
        if ($n['rep_code'] !== null) {
            $diaT = $n['turno'] === 'D' && $n['bloco'] === 'T1' ? escMaisDias($n['dia'], -1) : $n['dia'];
            $temNa[$n['rep_code'] . '|' . $diaT] = true;
        }
    }
    $mantidas = [];
    $workload = [];
    $dif = [];
    foreach ($publicadas as $p) {
        $dia = substr($p['dia'], 0, 10);
        if (isset($celTravada[$dia . '|' . $p['bloco'] . '|' . (int) $p['page_id']])) {
            continue;
        }
        $diaDoTurno = $p['turno'] === 'D' && $p['bloco'] === 'T1' ? escMaisDias($dia, -1) : $dia;
        if (isset($temNa[$p['rep_code'] . '|' . $diaDoTurno])) {
            continue; // continua trabalhando no turno (talvez em outra página)
        }
        if (isset($removidos[$p['rep_code'] . '|' . $diaDoTurno])) {
            continue; // remoção já aprovada em sugestão
        }
        $mantidas[] = ['dia' => $dia, 'bloco' => $p['bloco'], 'turno' => $p['turno'], 'page_id' => (int) $p['page_id'], 'pagina' => $p['pagina'],
            'rep_code' => $p['rep_code'], 'papel' => $p['papel'], 'origem' => $p['origem'], 'travada' => 0,
            'motivo' => 'mantido: já estava na escala publicada (ver sugestão de workload)'];
        $diaTurno = $p['turno'] === 'D' && $p['bloco'] === 'T1' ? escMaisDias($dia, -1) : $dia;
        $chave = $p['rep_code'] . '|' . $diaTurno;
        if (!isset($workload[$chave])) {
            $workload[$chave] = ['rep_code' => $p['rep_code'], 'dia' => $diaTurno, 'turno' => $p['turno'], 'blocos' => 0, 'celulas' => []];
        }
        $workload[$chave]['blocos']++;
        $workload[$chave]['celulas'][] = ['dia' => $dia, 'bloco' => $p['bloco'], 'page_id' => (int) $p['page_id']];
        $dif[] = escDif($dia, $p['bloco'], $p['page_id'], $p['pagina'], 'SAI', $p['rep_code'], null, $p['papel'], null,
            'o recálculo tiraria; mantido porque já estava publicado', 0, $p['turno']);
    }
    return [array_merge($novas, $mantidas), array_values($workload), $dif];
}

// De→Para entre a versão anterior e a nova, célula a célula.
function escMotorDeParaEscala(array $anteriores, array $novas): array {
    $celA = [];
    foreach ($anteriores as $a) {
        $a['dia'] = substr($a['dia'], 0, 10);
        $celA[$a['dia'] . '|' . $a['bloco'] . '|' . $a['page_id']][] = $a;
    }
    $celN = [];
    foreach ($novas as $n) {
        $celN[$n['dia'] . '|' . $n['bloco'] . '|' . $n['page_id']][] = $n;
    }
    $dif = [];
    foreach (array_unique(array_merge(array_keys($celA), array_keys($celN))) as $k) {
        $repsA = [];
        foreach ($celA[$k] ?? [] as $a) {
            $repsA[$a['rep_code'] ?? ''] = $a;
        }
        $repsN = [];
        foreach ($celN[$k] ?? [] as $n) {
            $repsN[$n['rep_code'] ?? ''] = $n;
        }
        [$dia, $bloco, $pid] = explode('|', $k);
        $lista = $celN[$k] ?? $celA[$k];
        $ref = $lista[0];
        $saem = array_diff_key($repsA, $repsN);
        $entram = array_diff_key($repsN, $repsA);
        unset($saem[''], $entram['']);
        foreach (array_intersect_key($repsA, $repsN) as $rep => $a) {
            if ($rep !== '' && $a['papel'] !== $repsN[$rep]['papel']) {
                $dif[] = escDif($dia, $bloco, $pid, $ref['pagina'], 'PAPEL', $rep, $rep, $a['papel'], $repsN[$rep]['papel'], 'papel mudou', 1, $ref['turno']);
            }
        }
        if (count($saem) === 1 && count($entram) === 1) {
            $repDe = array_key_first($saem);
            $repPara = array_key_first($entram);
            $dif[] = escDif($dia, $bloco, $pid, $ref['pagina'], 'TROCA', $repDe, $repPara, $saem[$repDe]['papel'], $entram[$repPara]['papel'], $entram[$repPara]['motivo'], 1, $ref['turno']);
        } else {
            foreach ($saem as $rep => $a) {
                $dif[] = escDif($dia, $bloco, $pid, $a['pagina'], 'SAI', $rep, null, $a['papel'], null, 'saiu no recálculo', 1, $a['turno']);
            }
            foreach ($entram as $rep => $n) {
                $dif[] = escDif($dia, $bloco, $pid, $n['pagina'], 'ENTRA', null, $rep, null, $n['papel'], $n['motivo'], 1, $n['turno']);
            }
        }
        $tinhaLacuna = isset($repsA['']);
        $temLacuna = isset($repsN['']);
        if ($tinhaLacuna !== $temLacuna) {
            $dif[] = escDif($dia, $bloco, $pid, $ref['pagina'], $temLacuna ? 'LACUNA_ABERTA' : 'LACUNA_FECHADA', null, null, null, null, $temLacuna ? 'bloco ficou sem ninguém' : 'bloco coberto', 1, $ref['turno']);
        }
    }
    return $dif;
}

function escDif($dia, $bloco, $pid, $pagina, $tipo, $de, $para, $papelDe, $papelPara, $motivo, $aplicado, $turno): array {
    return ['dia' => $dia, 'bloco' => $bloco, 'page_id' => (int) $pid, 'pagina' => $pagina, 'tipo' => $tipo,
        'rep_de' => $de, 'rep_para' => $para, 'papel_de' => $papelDe, 'papel_para' => $papelPara,
        'motivo' => mb_substr((string) $motivo, 0, 255), 'aplicado' => $aplicado,
        'dia_turno' => $turno === 'D' && $bloco === 'T1' ? escMaisDias($dia, -1) : $dia];
}

// ---------- detecção de mudanças e recálculo automático ----------

// Compara as entradas atuais com as usadas no plano de cada semana em
// aberto. Se mudou, registra o evento e recalcula (nova subversão).
function escMotorVerificar(?int $userId, bool $forcar = false): array {
    $ultima = escQ1("SELECT valor FROM " . escT('esc_parametro') . " WHERE chave = 'motor_ultima_verificacao'");
    if (!$forcar && $ultima && strtotime($ultima['valor']) > time() - 15 * 60) {
        return ['verificado' => false, 'recalculados' => []];
    }
    $fotos = escMotorFotosAtuais();
    [$ids, $mudaram] = escMotorRegistrarFotos($fotos);
    escW('INSERT INTO ' . escT('esc_parametro') . " (chave, valor, tipo, grupo, descricao) VALUES ('motor_ultima_verificacao', ?, 'TEXTO', 'motor', 'Última verificação de mudanças nas entradas') AS n ON DUPLICATE KEY UPDATE valor = n.valor", [date('Y-m-d H:i:s')]);

    $planos = escQ("SELECT p.* FROM " . escT('esc_plano') . " p
                     WHERE p.time_nome = '' AND p.status IN ('RASCUNHO','PUBLICADO') AND p.semana_fim >= CURDATE()
                       AND NOT EXISTS (SELECT 1 FROM " . escT('esc_plano') . " q WHERE q.semana_inicio = p.semana_inicio AND q.time_nome = '' AND q.status IN ('RASCUNHO','PUBLICADO') AND (q.versao > p.versao OR (q.versao = p.versao AND q.subversao > p.subversao)))");
    $recalculados = [];
    foreach ($planos as $p) {
        $usadas = json_decode((string) $p['parametros'], true)['fotos'] ?? [];
        $diferentes = [];
        foreach ($ids as $fonte => $id) {
            if (($usadas[$fonte] ?? null) !== $id) {
                $diferentes[] = $fonte;
            }
        }
        if (!$diferentes) {
            continue;
        }
        $gatilho = escGatilhoDaFonte($diferentes[0]);
        escW('INSERT INTO ' . escT('esc_recalculo_evento') . ' (gatilho, descricao, foto_id, semana_inicio, plano_de_id, status) VALUES (?, ?, ?, ?, ?, \'DETECTADO\')', [
            $gatilho, 'Mudou: ' . implode(', ', array_map(static fn($f) => escNomeFonte($f, false), $diferentes)), $ids[$diferentes[0]], substr($p['semana_inicio'], 0, 10), (int) $p['id'],
        ]);
        $eventoId = escUltimoId();
        try {
            $novo = escMotorGerar(substr($p['semana_inicio'], 0, 10), 'RECALCULO', $userId, $eventoId);
            $recalculados[] = ['semana' => substr($p['semana_inicio'], 0, 10), 'plano' => $novo, 'fontes' => $diferentes];
        } catch (Throwable $e) {
            escW('UPDATE ' . escT('esc_recalculo_evento') . " SET status = 'ERRO', erro = ?, processado_em = NOW() WHERE id = ?", [mb_substr($e->getMessage(), 0, 500), $eventoId]);
            appLog('error', 'Recálculo automático falhou', ['erro' => $e->getMessage()]);
        }
    }
    return ['verificado' => true, 'recalculados' => $recalculados, 'mudaram' => array_keys($mudaram)];
}

function escGatilhoDaFonte(string $fonte): string {
    return ['CAPACITY' => 'CAPACITY', 'ESTRUTURA' => 'ESTRUTURA', 'PAGINAS' => 'PAGINA_ENTRADA', 'COLABORADORES' => 'COLABORADOR',
        'INDISPONIBILIDADE' => 'INDISPONIBILIDADE', 'MODELOS' => 'MODELO', 'TROCAS_PAGINA' => 'TROCA_PAGINA'][$fonte] ?? 'MANUAL';
}

// Nome da fonte para a tela (traduzido); $traduzir = false para o que é gravado no banco.
function escNomeFonte(string $fonte, bool $traduzir = true): string {
    $nome = ['CAPACITY' => 'Capacity', 'ESTRUTURA' => 'estrutura/membros', 'PAGINAS' => 'páginas', 'COLABORADORES' => 'colaboradores',
        'INDISPONIBILIDADE' => 'indisponibilidade', 'MODELOS' => 'modelos', 'TROCAS_PAGINA' => 'trocas de página'][$fonte] ?? null;
    if ($nome === null) {
        return strtolower($fonte);
    }
    if (!$traduzir) {
        return $nome;
    }
    return ['Capacity' => t('Capacity'), 'estrutura/membros' => t('estrutura/membros'), 'páginas' => t('páginas'), 'colaboradores' => t('colaboradores'),
        'indisponibilidade' => t('indisponibilidade'), 'modelos' => t('modelos'), 'trocas de página' => t('trocas de página')][$nome];
}

// ---------- CSV do Schedule Ajustment a partir do plano ----------

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
function escCsvT1T6(array $celulas, string $seg, string $desdeSlot = ''): string {
    $linhas = [];
    foreach (ESC_BLOCOS as $hora => $bloco) {
        $cols = [];
        for ($d = 0; $d < 7; $d++) {
            $slot = date('Y-m-d', strtotime("{$seg} +{$d} day")) . "T{$hora}:00:00";
            $cel = '';
            if ($slot >= $desdeSlot && !empty($celulas[$slot])) {
                $codigos = [];
                foreach ($celulas[$slot] as $code => $assist) {
                    $codigos[] = $code . (is_string($assist) ? $assist : ($assist ? '#' : ''));
                }
                $cel = implode(', ', $codigos);
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

// Conflitos de quem vai entrar numa célula da grade (ajuste manual). Devolve textos de alerta.
function escGradeConflitos(int $planoId, int $pageId, string $dia, string $turno, array $celulas, array $pessoas): array {
    $alertas = [];
    $in = escIn($pessoas);
    // Folga programada no plano.
    foreach (escQ('SELECT UPPER(rep_code) AS r, tipo FROM ' . escT('esc_plano_folga') . ' WHERE plano_id = ? AND dia = ? AND UPPER(rep_code) IN (' . $in . ')', array_merge([$planoId, $dia], $pessoas)) as $f) {
        $alertas[] = t('{rep} está de folga neste dia ({tipo})', ['rep' => $f['r'], 'tipo' => mb_strtolower(escRotulo('tipo_folga', (string) $f['tipo']))]);
    }
    // Indisponibilidade aprovada.
    $indisp = escMotorIndisp([$dia]);
    foreach ($pessoas as $r) {
        if (array_key_exists($turno, $indisp[$r][$dia] ?? [])) {
            $alertas[] = t('{rep} tem indisponibilidade aprovada neste turno', ['rep' => $r]);
        }
    }
    // Turno do cadastro.
    foreach (escQ('SELECT UPPER(rep_code) AS r, turno FROM ' . escT('esc_colaborador_config') . ' WHERE UPPER(rep_code) IN (' . $in . ') AND vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)', array_merge($pessoas, [$dia, $dia])) as $c) {
        if ($c['turno'] && $c['turno'] !== $turno) {
            $alertas[] = t('{rep} é do turno {turno}', ['rep' => $c['r'], 'turno' => $c['turno']]);
        }
    }
    // Já está em outra página no mesmo bloco.
    $params = [$planoId, $pageId, $celulas[0][0], $celulas[0][1], $celulas[1][0], $celulas[1][1]];
    foreach (escQ('SELECT UPPER(rep_code) AS r, GROUP_CONCAT(DISTINCT pagina ORDER BY pagina SEPARATOR \', \') AS paginas FROM ' . escT('esc_plano_alocacao') . ' WHERE plano_id = ? AND page_id <> ? AND ((dia = ? AND bloco = ?) OR (dia = ? AND bloco = ?)) AND UPPER(rep_code) IN (' . $in . ') GROUP BY UPPER(rep_code)', array_merge($params, $pessoas)) as $o) {
        $alertas[] = t('{rep} já está em {paginas} neste turno', ['rep' => $o['r'], 'paginas' => $o['paginas']]);
    }
    // 12 h seguidas e 52 h na semana (turnos de 8 h; o T1 conta no dia do turno D).
    $vizinhos = ['A' => [[escMaisDias($dia, -1), 'D'], [$dia, 'C']], 'C' => [[$dia, 'A'], [$dia, 'D']], 'D' => [[$dia, 'C'], [escMaisDias($dia, 1), 'A']]][$turno] ?? [];
    $horasMax = (int) escV4Param('v4_horas_semana_max', '52');
    $ja = [];
    foreach (escQ("SELECT UPPER(rep_code) AS r, turno, IF(bloco = 'T1', DATE_SUB(dia, INTERVAL 1 DAY), dia) AS dt FROM " . escT('esc_plano_alocacao') . ' WHERE plano_id = ? AND UPPER(rep_code) IN (' . $in . ') AND NOT (page_id = ? AND turno = ? AND IF(bloco = \'T1\', DATE_SUB(dia, INTERVAL 1 DAY), dia) = ?) GROUP BY UPPER(rep_code), turno, dt', array_merge([$planoId], $pessoas, [$pageId, $turno, $dia])) as $l) {
        $ja[$l['r']][substr((string) $l['dt'], 0, 10) . '|' . $l['turno']] = true;
    }
    // Vizinho em outra semana: lê a versão publicada (ou a atual) daquela semana.
    $plano = escQ1('SELECT semana_inicio, semana_fim FROM ' . escT('esc_plano') . ' WHERE id = ?', [$planoId]);
    foreach ($vizinhos as [$d2, $t2]) {
        if ($plano && ($d2 < substr((string) $plano['semana_inicio'], 0, 10) || $d2 > substr((string) $plano['semana_fim'], 0, 10))) {
            $segV = segundaDaSemana($d2);
            $outro = escPlanoPublicado($segV) ?? escPlanoAtual($segV);
            if ($outro) {
                foreach (escQ("SELECT DISTINCT UPPER(rep_code) AS r FROM " . escT('esc_plano_alocacao') . " WHERE plano_id = ? AND turno = ? AND IF(bloco = 'T1', DATE_SUB(dia, INTERVAL 1 DAY), dia) = ? AND UPPER(rep_code) IN (" . $in . ')', array_merge([(int) $outro['id'], $t2, $d2], $pessoas)) as $o) {
                    $ja[$o['r']][$d2 . '|' . $t2] = true;
                }
            }
        }
    }
    foreach ($pessoas as $r) {
        foreach ($vizinhos as [$d2, $t2]) {
            if (isset($ja[$r][$d2 . '|' . $t2])) {
                $alertas[] = t('{rep} está no turno {turno} de {dia}: seriam 16 h seguidas (máximo 12 h)', ['rep' => $r, 'turno' => $t2, 'dia' => dataBr($d2)]);
            }
        }
        $turnosSemana = count($ja[$r] ?? []) + (isset($ja[$r][$dia . '|' . $turno]) ? 0 : 1);
        if ($turnosSemana * 8 > $horasMax) {
            $alertas[] = t('{rep} passaria de {max} h na semana ({horas} h)', ['rep' => $r, 'max' => $horasMax, 'horas' => $turnosSemana * 8]);
        }
    }
    return array_values(array_unique($alertas));
}
