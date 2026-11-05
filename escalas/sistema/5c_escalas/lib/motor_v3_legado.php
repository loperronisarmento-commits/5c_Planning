<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/motor_v3_legado.php
// Motor v3 (estrutura antiga, times e parties): só para as semanas antes da virada de 31/10.
// Ordem de preenchimento de cada página, por dia e turno (nomes internos de papel do Planner):
//   1. titulares da party dona (os de folga não entram);
//   2. titulares de outras parties do mesmo time;
//   3. SR do rodízio do time (regular para quem está mais abaixo do alvo);
//   4. SR do rodízio como assistente (página que pede 2 ou mais reps);
//   5. Buffers do turno; 6. o que sobrar vira lacuna.
// Titular ou SR do rodízio de serviço sem página vira assistente na maior página da party
// (titular) ou do time (rodízio). Buffer sem página vira sugestão de remoção por falta de workload.
// Separado de lib/motor.php em 04/11 (1.19.18), só movendo as funções, sem mudar nada.
// Carregado por lib/motor.php (require_once no topo).
// ===============================================

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
                            $extras[] = ['nome' => $alvo['nome'], 'pg' => $alvo['pg'], 'papel' => 'ASSISTENTE', 'rep' => $rep, 'origem' => 'TERTIUS_ASSISTENTE', 'motivo' => 'SR do rodízio sem página livre no turno: assistente na maior página do time'];
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
        case 'TERTIUS': return 'SR do rodízio como regular';
        case 'TERTIUS_ASSISTENTE': return 'SR do rodízio como assistente';
        case 'BUFFER': return 'Buffer do turno';
        default: return 'ninguém disponível no turno';
    }
}
