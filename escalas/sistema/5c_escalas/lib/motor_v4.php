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
// A escala antiga vai até 01/11 T6 (parâmetro v4_virada = 02/11): o motor
// começa no turno A de 02/11 e o T1 de 02/11 (fim do turno D de 01/11) vem
// do scheduleday no CSV. Se v4_dia_transicao tiver um dia, esse dia é
// montado com quem já está escalado no scheduleday, no turno em que está.
//
// Saída no mesmo formato do escMotorAlocar (v3), com posto_id e vaga.
// ===============================================

const ESC_V4_ORDEM_TIPO = ['titular' => 0, 'double' => 1, 'apoio' => 2, 'assistente' => 3];

function escV4Param(string $chave, string $padrao): string {
    static $p = null;
    if ($p === null) {
        $p = [];
        foreach (escQ('SELECT chave, valor FROM ' . escT('esc_parametro') . " WHERE grupo = 'v4'") as $l) {
            $p[$l['chave']] = $l['valor'];
        }
    }
    return (string) ($p[$chave] ?? $padrao);
}

function escV4DiaVirada(): string {
    return substr(escV4Param('v4_virada', '2026-11-01 05:00'), 0, 10);
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

// ---------- leituras ----------

function escV4Carregar(string $dia): array {
    $vig = ' vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)';
    $modelo = escQ1('SELECT ciclo, max_seguidos FROM ' . escT('esc_modelo_escala') . " WHERE nome = '5x2 rotativo 21 dias'");
    if (!$modelo) {
        throw new RuntimeException('Falta o modelo "5x2 rotativo 21 dias". Rode o ES_04.');
    }
    $nucleos = [];
    foreach (escQ('SELECT id, unidade, turno, numero, decurio_code, decurio_nome, deslocamento FROM ' . escT('esc_nucleo') . ' WHERE' . $vig, [$dia, $dia]) as $n) {
        $nucleos[(int) $n['id']] = $n;
    }
    $pessoas = [];
    foreach (escQ('SELECT UPPER(rep_code) AS rep, unidade, turno, nucleo_id, nivel FROM ' . escT('esc_colaborador_config')
        . " WHERE funcao = 'EQUITE' AND nucleo_id IS NOT NULL AND" . $vig . ' ORDER BY vigente_desde', [$dia, $dia]) as $p) {
        $pessoas[$p['rep']] = ['rep' => $p['rep'], 'unidade' => (int) $p['unidade'], 'turno' => $p['turno'],
            'nucleo' => (int) $p['nucleo_id'], 'nivel' => (int) $p['nivel']];
    }
    $postos = [];
    foreach (escQ('SELECT id, unidade, carteira, turno, seq, tipo, pagina_principal, carga, nucleo_dono_id, nucleo_cobertura_id FROM '
        . escT('esc_posto') . ' WHERE' . $vig, [$dia, $dia]) as $p) {
        $postos[(int) $p['id']] = $p + ['paginas' => []];
    }
    if ($postos) {
        foreach (escQ('SELECT posto_id, pagina, page_id, apoio FROM ' . escT('esc_posto_pagina') . ' WHERE posto_id IN (' . escIn(array_keys($postos)) . ')', array_keys($postos)) as $pp) {
            $postos[(int) $pp['posto_id']]['paginas'][] = ['pagina' => $pp['pagina'], 'page_id' => (int) $pp['page_id'], 'apoio' => (int) $pp['apoio'] === 1];
        }
    }
    $equipes = [];
    foreach (escQ('SELECT posto_id, vaga, UPPER(rep_code) AS rep, fatia FROM ' . escT('esc_vaga_equipe') . ' WHERE' . $vig, [$dia, $dia]) as $e) {
        $equipes[(int) $e['posto_id']][$e['vaga']][$e['rep']] = (float) $e['fatia'];
    }
    $overlap = escV4Param('v4_overlap_posicao_ciclo', '');
    return ['modelo' => $modelo, 'nucleos' => $nucleos, 'pessoas' => $pessoas, 'postos' => $postos, 'equipes' => $equipes,
        'ancora' => escV4Param('v4_rotacao_ancora', '2026-11-02'), 'overlap_idx' => $overlap === '' ? null : (int) $overlap];
}

// Quem fecha o turno D às 05:00 de $dia (T1): do plano da semana anterior; sem
// ele, o T1 postado ou, se não houver, quem está no T6 postado da véspera.
function escV4FimTurnoD(string $dia): array {
    $ant = escPlanoAtual(segundaDaSemana(escMaisDias($dia, -1)));
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
// Só SRs da estrutura nova entram (quem vai virar OS, OM ou OUM fica de fora).
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

// ---------- alocação ----------

function escMotorAlocarV4(array $in): array {
    $dias = $in['dias'];
    $est = $in['est'];
    $indisp = $in['indisp'];
    $virada = $in['virada'];
    $diaTransicao = $in['transicao'] ?? $virada; // '' = sem dia de transição
    $presentes = $in['presentes'] ?? [];
    $ciclo = $est['modelo']['ciclo'];
    $nucleos = $est['nucleos'];
    $pessoas = $est['pessoas'];
    $equipes = $est['equipes'];
    // Dia de treinamento do 5x2 (o 15º dia de trabalho do ciclo): o SR entra como Overlap
    // no posto dele, fora da conta das vagas. null = sem Overlap.
    $overlapIdx = $est['overlap_idx'] ?? null;

    // Páginas que cada pessoa conhece (em qualquer equipe) — afinidade.
    // Posto de cada pessoa para o Overlap: a vaga de maior fatia, a do dono antes.
    $conhece = [];
    $postoDe = [];
    foreach ($equipes as $pid => $vagas) {
        foreach ($vagas as $vaga => $reps) {
            foreach ($reps as $rep => $f) {
                foreach ($est['postos'][$pid]['paginas'] ?? [] as $pg) {
                    $conhece[$rep][$pg['pagina']] = true;
                }
                $chave = [(float) $f, $vaga === 'DONO' ? 1 : 0, -(int) $pid];
                if (isset($est['postos'][$pid]) && (!isset($postoDe[$rep]) || $chave > $postoDe[$rep]['chave'])) {
                    $postoDe[$rep] = ['pid' => (int) $pid, 'vaga' => $vaga, 'chave' => $chave];
                }
            }
        }
    }
    // Postos por unidade e turno, na ordem de preenchimento.
    $postosUT = [];
    foreach ($est['postos'] as $pid => $p) {
        $postosUT[(int) $p['unidade']][$p['turno']][] = $pid;
    }
    foreach ($postosUT as &$porTurno) {
        foreach ($porTurno as &$lista) {
            usort($lista, static fn($a, $b) => [ESC_V4_ORDEM_TIPO[$est['postos'][$a]['tipo']] ?? 9, -(float) $est['postos'][$a]['carga'], $a]
                <=> [ESC_V4_ORDEM_TIPO[$est['postos'][$b]['tipo']] ?? 9, -(float) $est['postos'][$b]['carga'], $b]);
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
    $ultimo = [];    // [posto] = quem atuou no posto da última vez (menos troca de gente)
    $trabalhou = []; // [rep][dia] = true (para anomalias; o Overlap não conta)
    $overlaps = [];  // [rep, dia, turno, posto] no dia de treinamento

    foreach ($dias as $dia) {
        if ($dia < $virada) {
            continue;
        }
        $transicao = $dia === $diaTransicao;
        foreach (ESC_TURNOS as $turno => $blocos) {
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
                    if ($transicao) {
                        if (!empty($presentes[$rep][$turno])) {
                            $disp[$rep] = $p;
                        }
                        continue;
                    }
                    if (!isset($ativos[$p['nucleo']])) {
                        $ind = $indisp[$rep][$dia][$turno] ?? null;
                        if ($overlapIdx !== null && $ind === null
                            && escV4IndiceCiclo($nucleos[$p['nucleo']], $ciclo, $est['ancora'], $dia) === $overlapIdx) {
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
                foreach ($postosUT[$u][$turno] ?? [] as $pid) {
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
                $estadoUT[$u] = ['ativos' => $ativos, 'disp' => $disp, 'vagas' => $vagas];
            }

            $afinidade = static function (string $rep, array $p) use ($conhece): int {
                $n = 0;
                foreach ($p['paginas'] as $pg) {
                    $n += isset($conhece[$rep][$pg['pagina']]) ? 1 : 0;
                }
                return $n;
            };
            $escolher = static function (array $cands, array $p) use ($afinidade, $pessoas, $ultimo): ?string {
                $melhor = null;
                $chaveMelhor = null;
                foreach ($cands as $rep) {
                    $k = [($ultimo[(int) $p['id']] ?? null) === $rep ? 1 : 0, $afinidade($rep, $p), $pessoas[$rep]['nivel'] ?? 0, $rep];
                    if ($chaveMelhor === null || $k > $chaveMelhor) {
                        $melhor = $rep;
                        $chaveMelhor = $k;
                    }
                }
                return $melhor;
            };

            foreach ($estadoUT as $u => &$st) {
                // 1. equipe fixa da vaga (preferência). Fica quem atuou da última
                //    vez enquanto estiver abaixo da própria fatia (menos troca de
                //    gente na página); senão, quem está mais abaixo da fatia.
                foreach ($st['vagas'] as &$v) {
                    $melhor = null;
                    $chaveMelhor = null;
                    foreach ($v['equipe'] as $rep => $fatia) {
                        if (!isset($st['disp'][$rep]) || isset($usado[$rep])) {
                            continue;
                        }
                        $share = ($feito[$rep][$v['chave']] ?? 0) / max(1, $diasVaga[$v['chave']]);
                        $continua = ($ultimo[$v['pid']] ?? null) === $rep && $share < $fatia + 0.15 ? 1 : 0;
                        $k = [$continua, $fatia - $share, $fatia, $rep];
                        if ($chaveMelhor === null || $k > $chaveMelhor) {
                            $melhor = $rep;
                            $chaveMelhor = $k;
                        }
                    }
                    if ($melhor !== null) {
                        $v['rep'] = $melhor;
                        $v['origem'] = $transicao ? 'TRANSICAO' : ($v['vaga'] === 'DONO' ? 'VAGA_DONO' : 'VAGA_COBERTURA');
                        $v['motivo'] = ($transicao ? 'transição 01/11: já escalado no turno; ' : '') . 'equipe fixa da vaga ('
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
                        $v['motivo'] = $transicao ? 'transição 01/11: já escalado no turno' : 'buffer do próprio núcleo (vaga sem a equipe fixa no dia)';
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

            // Transição: quem está escalado no turno mas é de outra unidade (ou outro turno na estrutura nova)
            if ($transicao) {
                $sobra = [];
                foreach ($presentes as $rep => $ts) {
                    if (!empty($ts[$turno]) && !isset($usado[$rep]) && isset($pessoas[$rep])) {
                        $sobra[] = $rep;
                    }
                }
                foreach ($estadoUT as $u => &$st) {
                    foreach ($st['vagas'] as &$v) {
                        if ($v['rep'] !== null || !$sobra) {
                            continue;
                        }
                        $rep = $escolher($sobra, $est['postos'][$v['pid']]);
                        $sobra = array_values(array_diff($sobra, [$rep]));
                        $v['rep'] = $rep;
                        $v['origem'] = 'TRANSICAO';
                        $v['motivo'] = 'transição 01/11: escalado neste turno, de outra unidade ou turno na estrutura nova';
                        $usado[$rep] = true;
                    }
                    unset($v);
                }
                unset($st);
            }

            // Saída por página e bloco
            foreach ($estadoUT as $u => $st) {
                foreach ($st['vagas'] as $v) {
                    $vagasDia++;
                    $p = $est['postos'][$v['pid']];
                    if ($v['rep'] === null) {
                        $vagasSemNinguem++;
                        $lacunasUT[$u . $turno] = ($lacunasUT[$u . $turno] ?? 0) + 1;
                    } else {
                        $feito[$v['rep']][$v['chave']] = ($feito[$v['rep']][$v['chave']] ?? 0) + 1;
                        $trabalhou[$v['rep']][$dia] = true;
                        $ultimo[$v['pid']] = $v['rep'];
                    }
                    foreach ($p['paginas'] as $pg) {
                        foreach ($blocos as $bloco) {
                            $diaB = $diaBloco[$bloco];
                            if (isset($travadasPorCelula[$diaB . '|' . $bloco . '|' . $pg['page_id']])) {
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
                // Quem ficou sem vaga é o buffer de plantão do núcleo (não vai para o CSV).
                foreach ($st['disp'] as $rep => $p) {
                    if (!isset($usado[$rep])) {
                        $ociosos[] = ['rep_code' => $rep, 'dia' => $dia, 'turno' => $turno, 'tipo' => 'BUFFER_V4', 'motivo' => 'buffer de plantão do núcleo'];
                        $trabalhou[$rep][$dia] = true;
                    }
                }
            }

            // Overlap: no dia de treinamento o SR fica no posto dele como Overlap (no CSV, código
            // com "-5"), além de quem cobre a vaga. Depois se decide se vira buffer ou treinamento.
            foreach ($overlapUT as $u => $reps) {
                foreach ($reps as $rep => $p) {
                    $alvo = $postoDe[$rep] ?? null;
                    if ($alvo === null) {
                        // Sem equipe: o maior posto de que o núcleo dele é dono.
                        foreach ($postosUT[$u][$turno] ?? [] as $pid) {
                            if ((int) $est['postos'][$pid]['nucleo_dono_id'] === (int) $p['nucleo']) {
                                $alvo = ['pid' => $pid, 'vaga' => 'DONO'];
                                break;
                            }
                        }
                    }
                    if ($alvo === null) {
                        $folgas[] = ['rep_code' => $rep, 'dia' => $dia, 'tipo' => 'ROTACAO', 'ref_id' => null];
                        continue;
                    }
                    $po = $est['postos'][$alvo['pid']];
                    foreach ($po['paginas'] as $pg) {
                        foreach ($blocos as $bloco) {
                            $diaB = $diaBloco[$bloco];
                            if (isset($travadasPorCelula[$diaB . '|' . $bloco . '|' . $pg['page_id']])) {
                                continue;
                            }
                            $ind = $indisp[$rep][$dia][$turno] ?? null;
                            if ($ind && is_array($ind['blocos']) && in_array($bloco, $ind['blocos'], true)) {
                                continue;
                            }
                            $alocacoes[] = [
                                'dia' => $diaB, 'bloco' => $bloco, 'turno' => $turno,
                                'page_id' => $pg['page_id'], 'pagina' => $pg['pagina'],
                                'posto_id' => $alvo['pid'], 'vaga' => $alvo['vaga'],
                                'rep_code' => $rep,
                                'papel' => ($po['tipo'] === 'assistente' || $pg['apoio']) ? 'ASSISTENTE' : 'REGULAR',
                                'origem' => 'OVERLAP', 'travada' => 0,
                                'motivo' => 'Overlap: dia de treinamento do 5x2 (buffer ou treinamento a decidir)',
                            ];
                            $resumoOrigem['OVERLAP'] = ($resumoOrigem['OVERLAP'] ?? 0) + 1;
                        }
                    }
                    $overlaps[] = ['rep_code' => $rep, 'dia' => $dia, 'turno' => $turno, 'posto_id' => $alvo['pid']];
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
    $limite = (int) $est['modelo']['max_seguidos'];
    $realizado = $in['realizado'] ?? [];
    $inicioPlano = max($dias[0], $virada);
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

    $lacunas = count(array_filter($alocacoes, static fn($a) => $a['rep_code'] === null));
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
            'anomalias_lista' => array_slice($anomalias, 0, 100),
            'buffers_ociosos' => count($ociosos),
            'pessoas' => count($pessoas),
            'vagas_dia' => $vagasDia,
            'vagas_sem_ninguem' => $vagasSemNinguem,
            'vagas_sem_ninguem_por_unidade_turno' => $lacunasUT,
            'paginas_sem_party' => [],
            'overlaps' => count($overlaps),
        ],
    ];
}
