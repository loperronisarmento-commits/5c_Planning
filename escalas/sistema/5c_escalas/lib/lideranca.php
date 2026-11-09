<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/lideranca.php
// Escala da liderança (estrutura de 01/11), separada da dos SRs.
//
// - Operational Unit Manager (OM, 1 por unidade): não segue escala.
// - Operational Coordinator (OC, 1 por unidade e turno, 3 por turno) e
//   Operational Supervisor (OS, 1 por núcleo, 3 por unidade e turno):
//   5x2 no mesmo ciclo de 21 dias dos SRs. O OS segue o núcleo dele; os 3 OC
//   de cada turno usam os 3 deslocamentos dos núcleos (esc_lideranca.deslocamento).
// - Operational Assistant (OA, sem unidade): 1 por turno, todo dia (24/7), numa
//   semana fixa (esc_lideranca.padrao_semana, seg a dom: A, C, D ou "." = folga).
//   Quem faz mais de um turno sempre muda para um turno mais tarde (11 h de
//   descanso). Sem semana fixa, o OA segue o ciclo de 21 dias num turno só.
// - OC e OS ficam num turno só (A = T2/T3, C = T4/T5, D = T6/T1).
// - Sem o dia de treinamento separado dos SRs: o 15º dia de trabalho cai na
//   posição do ciclo do parâmetro v4_lideranca_overlap_posicao, a mesma para
//   todos. Como cada um tem um deslocamento, nesse dia os 3 do grupo estão no
//   turno (Overlap); nos outros, 2 de 3.
// Só lê: nada é gravado.
// ===============================================

const ESC_LID_CARGO = ['OM' => 'Operational Unit Manager', 'OC' => 'Operational Coordinator', 'OS' => 'Operational Supervisor', 'OA' => 'Operational Assistant'];
// Mínimo no turno por grupo (OA: 1, cobertura 24/7; OC e OS: 2 de 3).
const ESC_LID_MINIMO = ['OA' => 1];
const ESC_LID_HORARIO = ['A' => '05:00–13:00', 'C' => '13:00–21:00', 'D' => '21:00–05:00'];
const ESC_LID_SITUACAO = ['TRABALHO' => 'Trabalha', 'OVERLAP' => 'Overlap (os 3)', 'FOLGA' => 'Folga', 'AUSENTE' => 'Ausente', 'ANTES' => 'Antes da virada', 'OUTRO' => 'Em outro turno'];

// Pessoas da liderança vigentes no dia: ['om' => [...], 'grupos' => [turno][unidade|'OC'] => [pessoas]].
// OS de cada núcleo no dia: quem tem a configuração de OS (funcao DECURIO) naquele núcleo
// e data, porque ela tem vigência (troca pelo Roster a partir de uma data); sem ela, o
// decurio gravado no núcleo. Devolve o núcleo com decurio_code/decurio_nome do dia.
function escNucleoComOsDoDia(array $n, string $dia): array {
    $porDia = &$GLOBALS['ESC_OS_DO_DIA']; // o Roster limpa ao mudar configuração
    if (!isset($porDia[$dia])) {
        $porDia[$dia] = [];
        foreach (escQ('SELECT UPPER(rep_code) AS rep, nucleo_id FROM ' . escT('esc_colaborador_config') . " WHERE funcao = 'DECURIO' AND nucleo_id IS NOT NULL AND vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)", [$dia, $dia]) as $c) {
            $porDia[$dia][(int) $c['nucleo_id']] = $c['rep'];
        }
    }
    $rep = $porDia[$dia][(int) $n['id']] ?? null;
    if ($rep !== null && $rep !== strtoupper((string) $n['decurio_code'])) {
        $n['decurio_code'] = $rep;
        $n['decurio_nome'] = escNomes()[$rep] ?? $rep;
    }
    return $n;
}

function escLiderancaCarregar(string $dia): array {
    $vig = ' vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)';
    $deslNumero = [];
    $os = [];
    foreach (escQ('SELECT id, unidade, turno, numero, decurio_code, decurio_nome, deslocamento FROM ' . escT('esc_nucleo') . ' WHERE' . $vig . ' ORDER BY unidade, turno, numero', [$dia, $dia]) as $n) {
        $n = escNucleoComOsDoDia($n, $dia);
        $deslNumero[$n['turno']][(int) $n['numero']] = (int) $n['deslocamento'];
        $os[$n['turno']][(int) $n['unidade']][] = ['cargo' => 'OS', 'rep' => $n['decurio_code'] ? strtoupper($n['decurio_code']) : null,
            'nome' => (string) $n['decurio_nome'], 'unidade' => (int) $n['unidade'], 'turno' => $n['turno'], 'nucleo' => (int) $n['numero'],
            'deslocamento' => (int) $n['deslocamento']];
    }
    $cols = array_column(escQ("SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'esc_lideranca'", [ESC_SCHEMA]), 'c');
    $om = [];
    $lider = ['OC' => [], 'OA' => []];
    foreach (escQ('SELECT nivel, unidade, turno, rep_code, nome' . (in_array('deslocamento', $cols, true) ? ', deslocamento' : ', NULL AS deslocamento')
        . (in_array('padrao_semana', $cols, true) ? ', padrao_semana' : ', NULL AS padrao_semana') . ' FROM ' . escT('esc_lideranca')
        . ' WHERE' . $vig . ' ORDER BY nivel, unidade, turno, nome', [$dia, $dia]) as $l) {
        $p = ['cargo' => $l['nivel'], 'rep' => $l['rep_code'] ? strtoupper($l['rep_code']) : null, 'nome' => (string) $l['nome'],
            'unidade' => (int) $l['unidade'], 'turno' => $l['turno'], 'nucleo' => null];
        $semana = strtoupper((string) $l['padrao_semana']);
        if ($l['nivel'] === 'OM') {
            $om[] = $p;
        } elseif ($l['nivel'] === 'OA' && strlen($semana) === 7) {
            // Semana fixa: entra no grupo de cada turno que faz.
            $p['padrao'] = $semana;
            foreach (['A', 'C', 'D'] as $t) {
                if (strpos($semana, $t) !== false) {
                    $lider['OA'][$t][] = $p;
                }
            }
        } elseif (isset($lider[$l['nivel']]) && $l['turno']) {
            // Sem deslocamento gravado: OC, o do núcleo de mesmo número da unidade (U1 → núcleo 1…);
            // OA, 0 e 14 pela ordem no turno (os 2 nunca folgam no mesmo dia).
            $padrao = $l['nivel'] === 'OC' ? ($deslNumero[$l['turno']][(int) $l['unidade']] ?? 0) : [0, 14, 7][count($lider['OA'][$l['turno']] ?? []) % 3];
            $p['deslocamento'] = $l['deslocamento'] !== null ? (int) $l['deslocamento'] : $padrao;
            $lider[$l['nivel']][$l['turno']][] = $p;
        }
    }
    $grupos = [];
    foreach (['A', 'C', 'D'] as $t) {
        $grupos[$t] = ['OC' => $lider['OC'][$t] ?? []] + (isset($lider['OA'][$t]) ? ['OA' => $lider['OA'][$t]] : []) + ($os[$t] ?? []);
    }
    return ['om' => $om, 'grupos' => $grupos];
}

// Escala da semana: ['dias', 'om', 'grupos', 'sit' => [chave][dia] => situação, 'presentes' => [turno][grupo][dia] => n].
function escLiderancaSemana(string $seg): array {
    $dias = escDiasDaSemana($seg);
    $modelo = escQ1('SELECT ciclo FROM ' . escT('esc_modelo_escala') . " WHERE nome = '5x2 rotativo 21 dias'");
    if (!$modelo) {
        throw new RuntimeException(t('Falta o modelo "5x2 rotativo 21 dias". Peça ao admin para cadastrá-lo.'));
    }
    $ciclo = $modelo['ciclo'];
    $ancora = escV4Param('v4_rotacao_ancora', '2026-11-02');
    $overlap = array_map('intval', array_filter(array_map('trim', explode(',', escV4Param('v4_lideranca_overlap_posicao', '5'))), 'strlen'));
    $virada = escV4DiaVirada();
    $turnoVirada = escV4TurnoVirada();
    $est = escLiderancaCarregar(max($seg, escV4DiaEstrutura()));
    $indisp = escMotorIndisp($dias);
    // OS que muda no meio da semana (ex.: ES_84, Kodama do núcleo 2 para o 1 em 03/11): a vaga de OS é do
    // núcleo, então em cada dia vale quem é o OS do núcleo naquele dia. OC e OA seguem a lista do início da semana.
    $osDia = [];
    foreach ($dias as $d) {
        if ($d < escV4DiaEstrutura()) {
            continue;
        }
        foreach (escLiderancaCarregar($d)['grupos'] as $turno => $grupos) {
            foreach ($grupos as $g => $pessoas) {
                if (is_int($g)) {
                    foreach ($pessoas as $i => $p) {
                        $osDia[$turno . '|' . $g . '|' . $i][$d] = $p;
                    }
                }
            }
        }
    }
    $repDia = [];

    $sit = [];
    $presentes = [];
    foreach ($est['grupos'] as $turno => $grupos) {
        foreach ($grupos as $g => $pessoas) {
            foreach ($pessoas as $i => $p) {
                $chave = $turno . '|' . $g . '|' . $i;
                foreach ($dias as $d) {
                    $pd = $osDia[$chave][$d] ?? null;
                    $rp = $pd !== null && $pd['nucleo'] === $p['nucleo'] ? $pd['rep'] : $p['rep'];
                    $repDia[$chave][$d] = $rp;
                    if (!escV4TurnoNovo($d, $turno, $virada, $turnoVirada)) {
                        $sit[$chave][$d] = 'ANTES';
                        continue;
                    }
                    if (isset($p['padrao'])) {
                        $ch = $p['padrao'][(int) date('N', strtotime($d)) - 1];
                        $s = $ch === $turno ? 'TRABALHO' : ($ch === '.' ? 'FOLGA' : 'OUTRO');
                    } else {
                        $idx = escV4IndiceCiclo($p, $ciclo, $ancora, $d);
                        $s = $ciclo[$idx] === '1' ? 'TRABALHO' : (in_array($idx, $overlap, true) ? 'OVERLAP' : 'FOLGA');
                    }
                    if (!in_array($s, ['FOLGA', 'OUTRO'], true) && $rp && isset($indisp[$rp][$d][$turno])) {
                        $s = 'AUSENTE';
                    }
                    $sit[$chave][$d] = $s;
                    $presentes[$turno][$g][$d] = ($presentes[$turno][$g][$d] ?? 0) + (in_array($s, ['TRABALHO', 'OVERLAP'], true) ? 1 : 0);
                }
            }
        }
    }
    return ['dias' => $dias, 'om' => $est['om'], 'grupos' => $est['grupos'], 'sit' => $sit, 'presentes' => $presentes, 'rep_dia' => $repDia];
}

// Origem da alocação da liderança no plano e sufixo do código no CSV (página 5C Schedule).
const ESC_LID_ORIGEM = ['OS' => 'LIDER_OS', 'OC' => 'LIDER_OC', 'OA' => 'LIDER_OA'];
const ESC_LID_SUFIXO = ['LIDER_OS' => '-8', 'LIDER_OC' => '-7', 'LIDER_OA' => '-6'];

// Alocações da liderança na semana, para entrar no plano junto com os SRs: quem trabalha
// no turno vai para a página 5C Schedule nos blocos do turno (D = T6 do dia + T1 do dia
// seguinte). Célula travada na grade (dia, bloco, página) fica como está.
function escLiderancaAlocacoes(string $seg, array $travadas, ?array $pagina): array {
    if (!$pagina) {
        return [];
    }
    $trav = [];
    foreach ($travadas as $t) {
        $trav[substr((string) $t['dia'], 0, 10) . '|' . $t['bloco'] . '|' . (int) $t['page_id']] = true;
    }
    $e = escLiderancaSemana($seg);
    $alocacoes = [];
    foreach ($e['grupos'] as $turno => $grupos) {
        foreach ($grupos as $g => $pessoas) {
            foreach ($pessoas as $i => $p) {
                foreach ($e['dias'] as $d) {
                    $rp = $e['rep_dia'][$turno . '|' . $g . '|' . $i][$d] ?? $p['rep'];
                    $s = $e['sit'][$turno . '|' . $g . '|' . $i][$d];
                    if (!$rp || ($s !== 'TRABALHO' && $s !== 'OVERLAP')) {
                        continue;
                    }
                    $onde = $p['cargo'] === 'OS' ? 'núcleo U' . $p['unidade'] . $turno . $p['nucleo'] : ($p['unidade'] ? 'U' . $p['unidade'] : 'turno ' . $turno);
                    foreach (ESC_TURNOS[$turno] as $bloco) {
                        $diaB = $turno === 'D' && $bloco === 'T1' ? escMaisDias($d, 1) : $d;
                        if (isset($trav[$diaB . '|' . $bloco . '|' . (int) $pagina['page_id']])) {
                            continue;
                        }
                        $alocacoes[] = [
                            'dia' => $diaB, 'bloco' => $bloco, 'turno' => $turno,
                            'page_id' => $pagina['page_id'], 'pagina' => $pagina['pagina'],
                            'posto_id' => null, 'vaga' => null,
                            'rep_code' => $rp, 'papel' => 'REGULAR', 'origem' => ESC_LID_ORIGEM[$p['cargo']], 'travada' => 0,
                            'motivo' => ESC_LID_CARGO[$p['cargo']] . ' · ' . $onde . ($s === 'OVERLAP' ? ' · Overlap (os 3 no turno)' : ''),
                        ];
                    }
                }
            }
        }
    }
    return $alocacoes;
}

// CSV (";", UTF-8 com BOM para o Excel): uma linha por pessoa e dia, a partir da virada
// (o OA de semana fixa entra uma vez por dia: no turno em que trabalha, ou na folga).
function escLiderancaCsv(array $e): string {
    $linhas = ['Data;Dia;Turno;Horário;Cargo;Unidade;Núcleo;Código;Nome;Situação'];
    $feito = [];
    foreach ($e['grupos'] as $turno => $grupos) {
        foreach ($grupos as $g => $pessoas) {
            foreach ($pessoas as $i => $p) {
                foreach ($e['dias'] as $d) {
                    $s = $e['sit'][$turno . '|' . $g . '|' . $i][$d];
                    $um = isset($p['padrao']) ? $p['nome'] . '|' . $d : null;
                    if ($s === 'ANTES' || $s === 'OUTRO' || ($um !== null && isset($feito[$um]))) {
                        continue;
                    }
                    if ($um !== null) {
                        $feito[$um] = true;
                    }
                    $linhas[] = implode(';', [date('d/m/Y', strtotime($d)), ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'][(int) date('w', strtotime($d))] . ' ' . date('d/m', strtotime($d)), $turno, ESC_LID_HORARIO[$turno], ESC_LID_CARGO[$p['cargo']],
                        $p['unidade'] ? 'U' . $p['unidade'] : '', $p['nucleo'] ? 'U' . $p['unidade'] . $turno . $p['nucleo'] : '', $p['rep'] ?? 'sem código',
                        preg_replace('~^([=+\-@])~', "'$1", str_replace(';', ',', $p['nome'])), ESC_LID_SITUACAO[$s]]);
                }
            }
        }
    }
    return "\xEF\xBB\xBF" . implode("\r\n", $linhas) . "\r\n";
}

// ---------- quem não pode ser subordinado de quem (v4_nao_subordinado) ----------
// Pares "subordinado:líder" separados por vírgula (ex.: casal, pedido de 04/11: J110041:R096272, o
// João Paulo Caial não fica sob o Rafael Gauze). Devolve o motivo se, no núcleo $nucleoId em $dia, o
// SR $rep ficaria sob um líder proibido (OS do núcleo, OC da unidade e turno ou OM da unidade); senão ''.
function escSubordinacaoProibida(string $rep, int $nucleoId, string $dia): string {
    $rep = strtoupper($rep);
    $proibidos = [];
    foreach (explode(',', escV4Param('v4_nao_subordinado', '')) as $par) {
        $x = array_map(static fn($v) => strtoupper(trim($v)), explode(':', $par, 2));
        if (count($x) === 2 && $x[0] === $rep && $x[1] !== '') {
            $proibidos[$x[1]] = true;
        }
    }
    if (!$proibidos) {
        return '';
    }
    $n = escQ1('SELECT id, unidade, turno, numero, decurio_code, decurio_nome FROM ' . escT('esc_nucleo') . ' WHERE id = ?', [$nucleoId]);
    if (!$n) {
        return '';
    }
    $n = escNucleoComOsDoDia($n, $dia);
    $nomes = escNomes();
    if (isset($proibidos[strtoupper((string) $n['decurio_code'])])) {
        return t('{lider} é OS deste núcleo, e {rep} não pode ficar sob essa pessoa.', ['lider' => $nomes[strtoupper((string) $n['decurio_code'])] ?? $n['decurio_code'], 'rep' => $nomes[$rep] ?? $rep]);
    }
    foreach (escQ('SELECT nivel, UPPER(rep_code) AS rep, nome FROM ' . escT('esc_lideranca') . " WHERE unidade = ? AND (nivel = 'OM' OR (nivel = 'OC' AND turno = ?)) AND vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)",
        [(int) $n['unidade'], $n['turno'], $dia, $dia]) as $l) {
        if (isset($proibidos[(string) $l['rep']])) {
            return t('{lider} é {nivel} da U{u}{turno}, e {rep} não pode ficar sob essa pessoa.', ['lider' => $l['nome'], 'nivel' => $l['nivel'], 'u' => $n['unidade'],
                'turno' => $l['nivel'] === 'OC' ? ' ' . $n['turno'] : '', 'rep' => $nomes[$rep] ?? $rep]);
        }
    }
    return '';
}
