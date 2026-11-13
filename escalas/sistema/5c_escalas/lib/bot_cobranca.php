<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/bot_cobranca.php
// Relatório do bot de cobrança (1.29.0, Análise › Bot de cobrança).
// O bot que cobra os SRs no Telegram entrou em 06/10/2026 (terça). O relatório compara, semana a semana:
//   - SLA de resposta em 5 e 10 min (5c_capacity.cap_rep_hora, horas completas, por turno);
//   - inatividade: página escalada sem mensagem de funcionário por 15 min ou mais (a regra do bot,
//     5c_bot.vw_alerta_inatividade), reconstruída das mensagens pelo ES_143 em esc_bot_inativ_semana
//     e esc_bot_inativ_rep, porque os alertas do próprio bot só existem desde 02/10;
//   - os alertas do bot por semana (5c_bot.alerta_historico), só como contexto.
// Períodos (semanas de segunda a domingo):
//   antes = 07/09 a 04/10 (4 semanas) · transição = semana de 05/10 (o bot entrou na terça) ·
//   depois = 12/10 a 01/11 (3 semanas, ainda na estrutura antiga) · estrutura nova = de 02/11 em diante.
// Não usa o corte de 01/11 (escCorteDados): o relatório existe para comparar setembro e outubro.
// Dados por SR só para WFM/Planejamento e Admin (escPodeVerRestrito).
// ===============================================

const ESC_BOT_INICIO = '2026-10-06';
const ESC_BOT_PRIMEIRA = '2026-08-31';
const ESC_BOT_PERIODOS = [
    'antes' => ['2026-09-07', '2026-09-28'],
    'transicao' => ['2026-10-05', '2026-10-05'],
    'depois' => ['2026-10-12', '2026-10-26'],
    'nova' => ['2026-11-02', '9999-12-31'],
];

function escBotPeriodoNome(string $p): string {
    return [
        'antes' => t('Antes do bot'),
        'transicao' => t('Semana em que o bot entrou'),
        'depois' => t('Com o bot (estrutura antiga)'),
        'nova' => t('Com o bot (3 unidades)'),
    ][$p] ?? $p;
}

function escBotPeriodoDatas(string $p): string {
    [$de, $ate] = ESC_BOT_PERIODOS[$p];
    $fim = $ate === '9999-12-31' ? t('hoje') : dataBr(escMaisDias($ate, 6));
    return t('{de} a {ate}', ['de' => dataBr($de), 'ate' => $fim]);
}

// Período da semana (segunda) ou '' (fora dos quatro, ex.: 31/08).
function escBotPeriodo(string $semana): string {
    foreach (ESC_BOT_PERIODOS as $p => [$de, $ate]) {
        if ($semana >= $de && $semana <= $ate) {
            return $p;
        }
    }
    return '';
}

// Segundas de 31/08 até a semana atual.
function escBotSemanas(): array {
    $s = [];
    $hoje = segundaDaSemana(date('Y-m-d'));
    for ($d = ESC_BOT_PRIMEIRA; $d <= $hoje; $d = escMaisDias($d, 7)) {
        $s[] = $d;
    }
    return $s;
}

// SLA por semana e turno: [semana][turno] = [respostas, em 5 min, em 10 min, horas de SR].
function escBotSla(): array {
    return escCache('bot_sla_v1', 3 * 3600, static function (): array {
        $r = [];
        foreach (escQ("SELECT DATE_SUB(dia, INTERVAL WEEKDAY(dia) DAY) AS semana,
                              CASE WHEN turno IN ('T2', 'T3') THEN 'A' WHEN turno IN ('T4', 'T5') THEN 'C' ELSE 'D' END AS tu,
                              SUM(respostas) AS r, SUM(resp_5min) AS r5, SUM(resp_10min) AS r10, COUNT(*) AS h
                         FROM `5c_capacity`.`cap_rep_hora`
                        WHERE dia >= ? AND hora_completa = 1
                        GROUP BY semana, tu", [ESC_BOT_PRIMEIRA]) as $l) {
            $r[substr((string) $l['semana'], 0, 10)][(string) $l['tu']] = [(int) $l['r'], (int) $l['r5'], (int) $l['r10'], (int) $l['h']];
        }
        return $r;
    });
}

// Inatividade gravada (ES_143): [semana][turno] = linha de esc_bot_inativ_semana.
function escBotInativ(): array {
    $r = [];
    try {
        foreach (escQ('SELECT semana, turno, blocos, episodios, min_inativos, blocos_com_inativ, episodios_30, blocos_sem_msg,
                              episodios_com_msg, min_inativos_com_msg, calculado_em
                         FROM `5c_escalas`.`esc_bot_inativ_semana` WHERE semana >= ?', [ESC_BOT_PRIMEIRA]) as $l) {
            $r[substr((string) $l['semana'], 0, 10)][(string) $l['turno']] = $l;
        }
    } catch (Throwable $e) {
        // ES_143 ainda não rodou
    }
    return $r;
}

// Semanas fechadas que ainda não têm a inatividade calculada.
function escBotSemanasPendentes(array $inativ): array {
    $ultimaFechada = escMaisDias(segundaDaSemana(date('Y-m-d')), -7);
    return array_values(array_filter(escBotSemanas(), static fn(string $s): bool => $s <= $ultimaFechada && !isset($inativ[$s])));
}

// Calcula a semana fechada mais antiga que falta (uma por acesso, ~1 min: lê as mensagens da semana).
// Não roda no Modo Treino nem sem o procedimento do ES_143. Devolve a semana calculada ou ''.
function escBotCalcularPendente(array $pendentes): string {
    if (!$pendentes || escModoTreino()) {
        return '';
    }
    $s = $pendentes[0];
    try {
        @set_time_limit(max(180, (int) ini_get('max_execution_time')));
        $stmt = escDbW()->prepare('CALL `5c_escalas`.`esc_sp_bot_inatividade`(?)');
        $stmt->execute([$s]);
        do {
            try {
                $stmt->fetchAll();
            } catch (Throwable $e) {
                // sem resultado neste conjunto
            }
        } while ($stmt->nextRowset());
        $stmt->closeCursor();
        return $s;
    } catch (Throwable $e) {
        if (function_exists('appLog')) {
            appLog('error', 'Bot de cobrança: cálculo da inatividade', ['semana' => $s, 'erro' => $e->getMessage()]);
        }
        return '';
    }
}

// Alertas do bot por semana e tipo: [semana][tipo] = [n, duração média em min].
function escBotAlertas(): array {
    return escCache('bot_alertas_v1', 3600, static function (): array {
        $r = [];
        try {
            foreach (escQ('SELECT DATE_SUB(DATE(COALESCE(inicio_real, aberto_em)), INTERVAL WEEKDAY(DATE(COALESCE(inicio_real, aberto_em))) DAY) AS semana, tipo,
                                  COUNT(*) AS n,
                                  AVG(CASE WHEN inicio_real IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, inicio_real, dados_ate_ultimo)
                                           ELSE TIMESTAMPDIFF(MINUTE, aberto_em, fechado_em) END) AS dur
                             FROM `5c_bot`.`alerta_historico`
                            GROUP BY semana, tipo') as $l) {
                $r[substr((string) $l['semana'], 0, 10)][(string) $l['tipo']] = [(int) $l['n'], $l['dur'] === null ? null : (float) $l['dur']];
            }
        } catch (Throwable $e) {
            // schema do bot fora do ar
        }
        return $r;
    });
}

// Soma uma lista de semanas (e um turno, ou todos) do SLA: ['r','r5','r10','h'].
function escBotSomaSla(array $sla, array $semanas, string $turno = ''): array {
    $s = ['r' => 0, 'r5' => 0, 'r10' => 0, 'h' => 0];
    foreach ($semanas as $sem) {
        foreach ($sla[$sem] ?? [] as $tu => [$r, $r5, $r10, $h]) {
            if ($turno === '' || $turno === $tu) {
                $s['r'] += $r;
                $s['r5'] += $r5;
                $s['r10'] += $r10;
                $s['h'] += $h;
            }
        }
    }
    return $s;
}

// Soma da inatividade: blocos, episódios (com mensagem no bloco), minutos, blocos sem mensagem.
function escBotSomaInativ(array $inativ, array $semanas, string $turno = ''): array {
    $s = ['blocos' => 0, 'epi' => 0, 'min' => 0, 'sem_msg' => 0, 'com_inativ' => 0, 'epi30' => 0];
    foreach ($semanas as $sem) {
        foreach ($inativ[$sem] ?? [] as $tu => $l) {
            if ($turno === '' || $turno === $tu) {
                $s['blocos'] += (int) $l['blocos'];
                $s['epi'] += (int) $l['episodios_com_msg'];
                $s['min'] += (int) $l['min_inativos_com_msg'];
                $s['sem_msg'] += (int) $l['blocos_sem_msg'];
                $s['com_inativ'] += (int) $l['blocos_com_inativ'];
                $s['epi30'] += (int) $l['episodios_30'];
            }
        }
    }
    return $s;
}

// Indicadores de uma soma: SLA em %, episódios por 100 blocos, minutos inativos por bloco, % de blocos sem mensagem.
function escBotIndicadores(array $sla, array $in): array {
    $blocosMsg = $in['blocos'] - $in['sem_msg'];
    return [
        'sla5' => $sla['r'] ? 100 * $sla['r5'] / $sla['r'] : null,
        'sla10' => $sla['r'] ? 100 * $sla['r10'] / $sla['r'] : null,
        'epi100' => $blocosMsg > 0 ? 100 * $in['epi'] / $blocosMsg : null,
        'epi30_100' => $blocosMsg > 0 ? 100 * $in['epi30'] / $blocosMsg : null,
        'min_bloco' => $blocosMsg > 0 ? $in['min'] / $blocosMsg : null,
        'sem_msg' => $in['blocos'] ? 100 * $in['sem_msg'] / $in['blocos'] : null,
        'horas' => $sla['h'],
        'blocos' => $in['blocos'],
    ];
}

// Semanas de um período que já têm dado.
function escBotSemanasDo(string $p, array $semanas): array {
    [$de, $ate] = ESC_BOT_PERIODOS[$p];
    return array_values(array_filter($semanas, static fn(string $s): bool => $s >= $de && $s <= $ate));
}

// Por SR (só WFM/Planejamento e Admin): SLA e inatividade antes × depois, SRs da estrutura atual (em_nova_estrutura).
// [rep_code => ['nome', 'antes' => [...], 'depois' => [...], 'nova' => [...]]]
function escBotPorSr(): array {
    return escCache('bot_sr_v2', 3 * 3600, static function (): array {
        $cad = [];
        foreach (escQ("SELECT UPPER(rep_code) AS rep, rep_name, code_infloww FROM `5c_wfm_db_mis`.`vw_rep_estrutura_atual`
                        WHERE rep_code IS NOT NULL AND rep_code <> '' AND em_nova_estrutura = 1") as $l) {
            $cad[(string) $l['rep']] = ['nome' => (string) $l['rep_name'], 'cod' => (string) $l['code_infloww']];
        }
        $porCod = [];
        foreach ($cad as $rep => $c) {
            if ($c['cod'] !== '') {
                $porCod[strtoupper($c['cod'])] = $rep;
            }
        }
        $vazio = ['r' => 0, 'r5' => 0, 'h' => 0, 'blocos' => 0, 'epi' => 0, 'min' => 0, 'sem_msg' => 0];
        $out = [];
        $per = static function (string $dia): string {
            return escBotPeriodo(segundaDaSemana($dia));
        };
        foreach (escQ('SELECT UPPER(SUBSTRING_INDEX(rep, \' \', 1)) AS cod, DATE_SUB(dia, INTERVAL WEEKDAY(dia) DAY) AS semana,
                              SUM(respostas) AS r, SUM(resp_5min) AS r5, COUNT(*) AS h
                         FROM `5c_capacity`.`cap_rep_hora`
                        WHERE dia >= ? AND hora_completa = 1
                        GROUP BY cod, semana', [ESC_BOT_PERIODOS['antes'][0]]) as $l) {
            $rep = $porCod[(string) $l['cod']] ?? null;
            $p = $per(substr((string) $l['semana'], 0, 10));
            if ($rep === null || !in_array($p, ['antes', 'depois', 'nova'], true)) {
                continue;
            }
            $out[$rep][$p] = ($out[$rep][$p] ?? $vazio);
            $out[$rep][$p]['r'] += (int) $l['r'];
            $out[$rep][$p]['r5'] += (int) $l['r5'];
            $out[$rep][$p]['h'] += (int) $l['h'];
        }
        try {
            foreach (escQ('SELECT UPPER(rep_code) AS rep, semana, blocos, episodios_com_msg, min_inativos_com_msg, blocos_sem_msg
                             FROM `5c_escalas`.`esc_bot_inativ_rep` WHERE semana >= ?', [ESC_BOT_PERIODOS['antes'][0]]) as $l) {
                $rep = (string) $l['rep'];
                $p = escBotPeriodo(substr((string) $l['semana'], 0, 10));
                if (!isset($cad[$rep]) || !in_array($p, ['antes', 'depois', 'nova'], true)) {
                    continue;
                }
                $out[$rep][$p] = ($out[$rep][$p] ?? $vazio);
                $out[$rep][$p]['blocos'] += (int) $l['blocos'];
                $out[$rep][$p]['epi'] += (int) $l['episodios_com_msg'];
                $out[$rep][$p]['min'] += (int) $l['min_inativos_com_msg'];
                $out[$rep][$p]['sem_msg'] += (int) $l['blocos_sem_msg'];
            }
        } catch (Throwable $e) {
            // ES_143 ainda não rodou
        }
        foreach ($out as $rep => &$o) {
            $o['nome'] = $cad[$rep]['nome'] ?? $rep;
        }
        unset($o);
        return $out;
    });
}
