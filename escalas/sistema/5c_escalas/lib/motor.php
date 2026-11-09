<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/motor.php
// Geração e recálculo do rascunho da escala (escMotorGerar): leituras das entradas
// (Capacity, páginas, cadastro, indisponibilidade, realizado), releitura do que já foi
// postado, versões do plano, proteção da versão publicada e De→Para da escala.
//
// Lê: Capacity (fc_escala_pagina), páginas (vw_pagina), escala postada (scheduleday)
// e o 5c_escalas. Grava: só no 5c_escalas (esc_plano*, esc_sugestao, esc_ciclo_estado,
// esc_entrada_*, esc_recalculo_evento, esc_plano_diferenca).
//
// Desde a 1.19.18 ficam em arquivos próprios, carregados abaixo: o calendário, os
// parâmetros, o motor v3 da estrutura antiga, as fotos das entradas, o CSV e os
// alertas da Grade. O motor da estrutura nova é o lib/motor_v4.php.
// ===============================================

require_once __DIR__ . '/calendario.php';
require_once __DIR__ . '/parametros.php';
require_once __DIR__ . '/entradas.php';
require_once __DIR__ . '/csv.php';
require_once __DIR__ . '/grade_conflitos.php';

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

// ---------- fotos das entradas e De→Para das entradas ----------

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
// $desde: "a partir de" (slot do início de um turno da semana, ex. 2026-11-03T21:00:00). Antes dele
// as células e as folgas são as da versão publicada (sem ela, a atual), como estão; o cálculo novo
// só vale dali em diante. Quem trabalha antes do corte só volta com 11 h de descanso.
function escMotorGerar(string $seg, string $modo, ?int $userId, ?int $recalculoId = null, bool $minimo = false, string $desde = ''): int {
    // Reinicia o relógio sem encurtar o limite de quem chamou (Gerar 600 s, Saída 900 s).
    $limiteTempo = (int) ini_get('max_execution_time');
    if ($limiteTempo !== 0) {
        @set_time_limit(max(180, $limiteTempo));
    }
    if (PHP_SAPI !== 'cli' && escMaisDias($seg, 6) < date('Y-m-d')) {
        throw new RuntimeException(t('Esta semana já terminou: não dá para gerar nem recalcular.'));
    }
    // Uma geração por vez, em qualquer caminho (Gerar, Criar rascunho, Verificar, Saída, Troca de unidade).
    if (function_exists('escGerarTravar') && !escGerarTravar()) {
        throw new RuntimeException(t('Outra pessoa está gerando ou recalculando uma escala agora. Espere terminar (até 3 min) e tente de novo.'));
    }
    $dias = escDiasDaSemana($seg);
    $base = escPlanoAtual($seg);
    $travasBase = $base ? escQ1('SELECT COUNT(*) AS n, MAX(alterado_em) AS m FROM ' . escT('esc_plano_alocacao') . ' WHERE plano_id = ? AND travada = 1', [(int) $base['id']]) : null;
    $desde = escSlotDaSemana($seg, $desde);
    $refDesde = $desde !== '' ? (escPlanoPublicado($seg) ?? $base) : null;
    $refPre = [];
    $refFolgas = [];
    if ($refDesde) {
        foreach (escQ('SELECT dia, bloco, turno, page_id, pagina, posto_id, vaga, rep_code, papel, origem, travada, motivo FROM ' . escT('esc_plano_alocacao') . ' WHERE plano_id = ?', [(int) $refDesde['id']]) as $l) {
            $l['dia'] = substr((string) $l['dia'], 0, 10);
            if ($l['dia'] . 'T' . escHoraDoBloco((string) $l['bloco']) . ':00:00' < $desde) {
                $refPre[] = $l;
            }
        }
        $refFolgas = escQ('SELECT rep_code, dia, tipo, ref_id FROM ' . escT('esc_plano_folga') . ' WHERE plano_id = ? AND dia < ?', [(int) $refDesde['id'], substr($desde, 0, 10)]);
        // Antes do corte igual ao site (opção do Gerar, pedido de 04/11): as células vêm do 5C Schedule.
        if (!empty($GLOBALS['ESC_PRE_SITE'])) {
            $refPre = escMotorPreCorteDoSite($seg, $desde, $refPre);
        }
    } else {
        $desde = '';
    }

    $fotos = escMotorFotosAtuais();
    [$fotoIds] = escMotorRegistrarFotos($fotos);

    $config = escMotorConfig($seg);
    $realizado = escMotorRealizado(escMaisDias($seg, -28), escMaisDias($seg, -1));
    $estado = escMotorReleitura($config, $realizado, $seg);
    $cap = escMotorCapacity($dias);
    $travadas = [];
    if ($modo === 'RECALCULO' && $base) {
        $travadas = escQ('SELECT dia, bloco, turno, page_id, pagina, posto_id, vaga, rep_code, papel, origem, motivo FROM ' . escT('esc_plano_alocacao') . ' WHERE plano_id = ? AND travada = 1', [(int) $base['id']]);
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
        // Regra de 04/11: restrições de quem foi desligado e de página que saiu ficam desativadas.
        if (!isset($GLOBALS['ESC_OM_ORFAS']) && !escDbW()->inTransaction()) {
            try {
                $GLOBALS['ESC_OM_ORFAS'] = escOmEncerrarOrfas(date('Y-m-d'));
                // Versão de hoje das Restrições dos OM guardada antes de gerar (histórico, ES_122).
                escRestricoesRegistrarVersoes();
            } catch (Throwable $e) {
                appLog('error', 'Restrições: falha ao desativar as de desligados e páginas que saíram', ['erro' => $e->getMessage()]);
                $GLOBALS['ESC_OM_ORFAS'] = [];
            }
        }
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
        // "A partir de": quem trabalha antes do corte (na versão de referência) só entra num turno
        // depois dele com 11 h de descanso desde o fim do último bloco (cada bloco tem 4 h).
        if ($desde !== '') {
            $fim = [];
            foreach ($refPre as $l) {
                if ($l['rep_code'] !== null) {
                    $t = strtotime($l['dia'] . ' ' . escHoraDoBloco((string) $l['bloco']) . ':00') + 4 * 3600;
                    $fim[strtoupper((string) $l['rep_code'])] = max($fim[strtoupper((string) $l['rep_code'])] ?? 0, $t);
                }
            }
            $corte = strtotime(str_replace('T', ' ', $desde));
            foreach ($fim as $code => $t) {
                foreach ($dias as $d) {
                    foreach (ESC_TURNO_HORA as $tb => $hora) {
                        $ini = strtotime($d . ' ' . $hora . ':00');
                        if ($ini >= $corte && $ini < $t + 11 * 3600) {
                            $indisp[$code][$d][$tb] ??= ['id' => null, 'blocos' => null];
                        }
                    }
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
        throw new RuntimeException(t('Esta semana já terminou: não dá para gerar nem recalcular.'));
    }

    $est = $est ?? []; // estrutura v4 (pessoas e postos), para proteger só quem continua nela
    if ($desde !== '') {
        // Folgas antes do dia do corte: as da versão de referência.
        $diaCorte = substr($desde, 0, 10);
        $saida['folgas'] = array_merge($refFolgas, array_values(array_filter($saida['folgas'] ?? [], static fn($f) => substr((string) $f['dia'], 0, 10) >= $diaCorte)));
    }
    return escTx(function () use ($travasBase, $seg, $dias, $modo, $base, $userId, $recalculoId, $saida, $cap, $fotoIds, $estado, $estruturaPlano, $travadas, $minimo, $est, $desde, $refPre, $refDesde) {
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

        // A versão de partida não pode ter mudado enquanto o motor calculava (publicação ou ajuste na Grade).
        if ($base) {
            $statusBase = escQW('SELECT status FROM ' . escT('esc_plano') . ' WHERE id = ? FOR UPDATE', [(int) $base['id']])[0]['status'] ?? null;
            $travasAgora = escQW('SELECT COUNT(*) AS n, MAX(alterado_em) AS m FROM ' . escT('esc_plano_alocacao') . ' WHERE plano_id = ? AND travada = 1', [(int) $base['id']])[0];
            if ($statusBase !== $base['status'] || (int) $travasAgora['n'] !== (int) $travasBase['n'] || (string) $travasAgora['m'] !== (string) $travasBase['m']) {
                throw new RuntimeException(t('A versão mudou enquanto o sistema calculava (publicação ou ajuste na grade). Gere de novo para levar a mudança em conta.'));
            }
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
                if (isset($est['pessoas'], $est['postos'])) {
                    // Estrutura v4: só fica protegido quem continua na estrutura, na unidade e no turno de
                    // um posto da página. Quem saiu da empresa, mudou de unidade ou de turno, ou a página
                    // que mudou de unidade, não volta (antes voltavam e dobravam a célula com o novo).
                    $ondePag = [];
                    foreach ($est['postos'] as $po) {
                        foreach ($po['paginas'] as $pg) {
                            $ondePag[(int) $pg['page_id'] . '|' . $po['unidade'] . '|' . $po['turno']] = true;
                        }
                    }
                    // Quem está FORA da página (pedido do manager ou plataforma só com SR brasileiro) também não volta.
                    $linhasPub = array_values(array_filter($linhasPub, static function ($l) use ($est, $ondePag) {
                        $rep = strtoupper((string) $l['rep_code']);
                        $p = $est['pessoas'][$rep] ?? null;
                        return $p !== null && $p['turno'] === $l['turno'] && isset($ondePag[(int) $l['page_id'] . '|' . $p['unidade'] . '|' . $l['turno']])
                            && !isset($est['fora'][$rep][(string) $l['pagina']]);
                    }));
                }
                [$alocacoes, $sugestoesWorkload, $protegidos] = escMotorProtegerPublicado($linhasPub, $alocacoes, $removidos, $travadas);
            }
        }
        // "A partir de": antes do corte fica a versão de referência, célula por célula.
        if ($desde !== '') {
            $antesDoCorte = static fn(array $a): bool => substr((string) $a['dia'], 0, 10) . 'T' . escHoraDoBloco((string) $a['bloco']) . ':00:00' < $desde;
            $alocacoes = array_merge($refPre, array_values(array_filter($alocacoes, static fn($a) => !$antesDoCorte($a))));
            $sugestoesWorkload = array_values(array_filter($sugestoesWorkload, static fn($w) => substr((string) $w['dia'], 0, 10) >= substr($desde, 0, 10)));
            $protegidos = array_values(array_filter($protegidos, static fn($d) => !$antesDoCorte($d)));
            $saida['resumo']['lacunas'] = count(array_filter($alocacoes, static fn($a) => $a['rep_code'] === null && $a['origem'] === 'LACUNA'));
            $saida['resumo']['desde'] = ['slot' => $desde, 'base' => escPlanoRotulo($refDesde)];
        }
        if ($base) {
            $anteriores = escQW('SELECT dia, bloco, turno, page_id, pagina, rep_code, papel, origem, travada, motivo FROM ' . escT('esc_plano_alocacao') . ' WHERE plano_id = ?', [(int) $base['id']]);
            $diferencas = array_merge(escMotorDeParaEscala($anteriores, $alocacoes), $protegidos);
        }

        $parametros = ['fotos' => $fotoIds, 'ancora' => ESC_ANCORA, 'modo' => $modo] + ($minimo ? ['minimo' => true] : []) + ($desde !== '' ? ['desde' => $desde] : []);
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
                    : 'Cobrir na grade (ajuste manual) com um SR do rodízio ou Buffer de folga, ou aceitar a lacuna.',
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
                escW('UPDATE ' . escT('esc_plano') . " SET status = 'SUBSTITUIDO' WHERE id = ? AND status = 'RASCUNHO'", [(int) $base['id']]);
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
            ['semana' => $seg, 'versao' => $versao . '.' . $subversao, 'lacunas' => $saida['resumo']['lacunas']] + ($desde !== '' ? ['desde' => $desde] : []), $userId ? 'SITE' : 'SISTEMA');
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

// ---------- CSV do Schedule Ajustment a partir do plano ----------

