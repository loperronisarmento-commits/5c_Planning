<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/merito.php
// Mérito dos SRs (eixo do 5C George):
//   nota = Desempenho × SLA × Presença(absenteísmo) ÷ (1 + pena)
// - Desempenho: realizado ÷ meta 5CReps das páginas (Intraday, por dia,
//   turno T1–T6 e página), rateado pelas mensagens do SR na página no turno
//   (5c_capacity.cap_rep_hora), como o 5C George faz com o pct_rep.
// - SLA: respostas em até 5 min ÷ respostas (cap_rep_hora), sem as horas em que o
//   posto forçado pela regra de faturamento passou da capacidade (fc_sla_hora_excluida).
// - Absenteísmo: blocos ABS ÷ blocos escalados sem AF (escAbsRealizado). A falha
//   administrativa (AF) não conta contra o SR.
// - Presença: 1 − absenteísmo até a tolerância (6%); acima dela cai rápido até
//   o piso no absenteísmo crítico (15%). Do crítico para cima a nota fica no
//   máximo em 80% da mediana: o SR nunca aparece "na média" ou "acima".
// - Pena: Σ peso da classificação × 0,5^(dias desde a aplicação ÷ meia-vida).
//   A infração pesa menos a cada dia sem outra ("pagando a pena") e some
//   depois da janela. Sem infração, pena 0 (divide por 1).
// Fica em cache (esc_sr_merito) e é recalculado a cada merito_cache_horas.
// ===============================================

function escMeritoPesos(): array {
    $pesos = [];
    foreach (explode(',', escV4Param('merito_infracao_pesos', 'LEVE:1,MEDIA:2,GRAVE:4')) as $par) {
        $p = explode(':', $par, 2);
        if (count($p) === 2 && trim($p[0]) !== '') {
            $pesos[mb_strtoupper(trim($p[0]))] = (float) str_replace(',', '.', trim($p[1]));
        }
    }
    return $pesos;
}

// Pena de uma infração hoje: peso × 0,5^(dias ÷ meia-vida); 0 fora da janela.
function escMeritoPena(float $peso, int $dias, float $meiaVida, int $janela): float {
    if ($dias < 0) {
        $dias = 0;
    }
    if ($dias > $janela || $meiaVida <= 0) {
        return 0.0;
    }
    return $peso * pow(0.5, $dias / $meiaVida);
}

// Parâmetros do absenteísmo (grupo "merito"). merito_abs_ativo = 0 tira o absenteísmo da nota
// (presença 1 e sem o teto do crítico); ele continua aparecendo nas telas, só como informação.
function escMeritoAbsParam(): array {
    $f = static fn(string $nome, string $padrao): float => (float) str_replace(',', '.', escV4Param($nome, $padrao));
    $tol = min(0.5, max(0.0, $f('merito_abs_tolerancia', '0.06')));
    return [
        'ativo' => trim(escV4Param('merito_abs_ativo', '1')) !== '0',
        'tolerancia' => $tol,
        'critico' => max($tol + 0.01, min(0.9, $f('merito_abs_critico', '0.15'))),
        'piso' => min(1.0, max(0.05, $f('merito_abs_piso', '0.5'))),
        'teto_critico' => min(0.84, max(0.1, $f('merito_abs_teto_critico', '0.8'))),
    ];
}

// Fator de presença: 1 − a até a tolerância; entre a tolerância e o crítico cai em
// linha reta até (1 − tolerância) × piso; depois do crítico continua caindo com a
// própria presença. Contínua: não há salto em nenhum ponto.
function escMeritoPresenca(?float $abs, ?array $p = null): float {
    $p = $p ?? escMeritoAbsParam();
    if (!($p['ativo'] ?? true)) {
        return 1.0;
    }
    $a = min(1.0, max(0.0, (float) $abs));
    if ($a <= $p['tolerancia']) {
        return 1 - $a;
    }
    $base = 1 - $p['tolerancia'];
    if ($a <= $p['critico']) {
        return $base * (1 - (1 - $p['piso']) * ($a - $p['tolerancia']) / ($p['critico'] - $p['tolerancia']));
    }
    return $base * $p['piso'] * (1 - $a) / (1 - $p['critico']);
}

function escMeritoNota(?float $desempenho, ?float $sla, ?float $abs, float $pena, ?array $p = null): ?float {
    if ($desempenho === null || $sla === null) {
        return null;
    }
    return $desempenho * $sla * escMeritoPresenca($abs, $p) / (1 + max(0.0, $pena));
}

// Absenteísmo crítico: a nota fica no máximo em teto_critico × mediana das notas,
// então a faixa é sempre "Abaixo" (abaixo de 85% da mediana) e o SR perde o desempate.
function escMeritoTetoCritico(array $saida, ?array $p = null): array {
    $p = $p ?? escMeritoAbsParam();
    if (!($p['ativo'] ?? true)) {
        return $saida;
    }
    $notas = array_values(array_filter(array_column($saida, 'nota'), static fn($n) => $n !== null));
    if (!$notas) {
        return $saida;
    }
    sort($notas);
    $teto = $notas[intdiv(count($notas), 2)] * $p['teto_critico'];
    foreach ($saida as $rep => $m) {
        if ($m['nota'] !== null && $m['absenteismo'] !== null && (float) $m['absenteismo'] >= $p['critico'] && $m['nota'] > $teto) {
            $saida[$rep]['nota'] = $teto;
        }
    }
    return $saida;
}

// Absenteísmo realizado de $de a $ate, por SR ($por = 'rep') ou por turno A/C/D ($por = 'turno'):
// blocos de falta ÷ blocos escalados, sem a falha administrativa (AF: escala dobrada ou
// fora do horário), que não conta contra o SR nem no numerador nem no denominador.
// Fonte oficial: 5c_wfm_db_mis.abs_adh_af, a classificação do Capacity. Se a leitura falhar,
// o 5C Schedule por SR e horário: o bloco só é falta quando faltou em todas as alocações do
// horário. O "faltou" cru conta cada página (o mesmo bloco várias vezes) e marca falta na
// página dobrada mesmo com o SR trabalhando na outra; contava ~2× a taxa real.
// Devolve ['taxa' => [chave => taxa], 'fonte' => 'oficial' | 'schedule'].
function escAbsRealizado(string $por, string $de, string $ate, array $reps = []): array {
    $reps = array_values(array_unique(array_map('strtoupper', $reps)));
    $filtro = $reps ? ' AND codigo IN (' . escIn($reps) . ')' : '';
    $taxa = [];
    try {
        $chave = $por === 'turno' ? "CASE WHEN hora IN (5, 9) THEN 'A' WHEN hora IN (13, 17) THEN 'C' ELSE 'D' END" : 'UPPER(codigo)';
        foreach (escQ("SELECT {$chave} AS k, SUM(classificacao <> 'AF') AS blocos, SUM(classificacao = 'ABS') AS faltas
                         FROM `5c_wfm_db_mis`.`abs_adh_af` WHERE dia >= ? AND dia <= ?{$filtro} GROUP BY k", array_merge([$de, $ate], $reps)) as $r) {
            $taxa[$r['k']] = (int) $r['blocos'] > 0 ? (int) $r['faltas'] / (int) $r['blocos'] : null;
        }
        return ['taxa' => $taxa, 'fonte' => 'oficial'];
    } catch (Throwable $e) {
        appLog('warning', 'Absenteísmo: abs_adh_af indisponível, usando o 5C Schedule por horário', ['erro' => $e->getMessage()]);
    }
    $chave = $por === 'turno' ? "CASE WHEN h IN ('05', '09') THEN 'A' WHEN h IN ('13', '17') THEN 'C' ELSE 'D' END" : 'rep';
    foreach (escQ("SELECT {$chave} AS k, COUNT(*) AS blocos, SUM(f) AS faltas
                     FROM (SELECT UPPER(codigo) AS rep, SUBSTRING(shiftDateAndHour, 12, 2) AS h, MIN(faltou = 1) AS f
                             FROM `5c_schedule`.`scheduleday` WHERE shiftDateAndHour >= ? AND shiftDateAndHour < ?{$filtro}
                            GROUP BY UPPER(codigo), shiftDateAndHour) AS s GROUP BY k",
        array_merge([$de . 'T00:00:00', escMaisDias($ate, 1) . 'T00:00:00'], $reps)) as $r) {
        $taxa[$r['k']] = (int) $r['blocos'] > 0 ? (int) $r['faltas'] / (int) $r['blocos'] : null;
    }
    return ['taxa' => $taxa, 'fonte' => 'schedule'];
}

// A fonte oficial do absenteísmo (abs_adh_af) está acessível para o usuário do sistema?
function escAbsOficialOk(): bool {
    static $ok = null;
    if ($ok === null) {
        try {
            escQ('SELECT 1 AS ok FROM `5c_wfm_db_mis`.`abs_adh_af` LIMIT 1');
            $ok = true;
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

// O Capacity já grava as horas fora do SLA (script 23)?
function escMeritoSlaExcluidaOk(): bool {
    static $ok = null;
    if ($ok === null) {
        $ok = (bool) escQ1("SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = '5c_planning' AND TABLE_NAME = 'fc_sla_hora_excluida'");
    }
    return $ok;
}

// Calcula o mérito dos reps informados (códigos) com a janela que termina ontem.
function escMeritoCalcular(array $reps): array {
    $reps = array_values(array_unique(array_map('strtoupper', $reps)));
    if (!$reps) {
        return [];
    }
    $n = max(7, (int) escV4Param('merito_janela_dias', '28'));
    $ate = date('Y-m-d', strtotime('-1 day'));
    $de = date('Y-m-d', strtotime('-' . $n . ' day'));
    $minResp = (int) escV4Param('merito_min_respostas', '100');
    $teto = (float) str_replace(',', '.', escV4Param('merito_desempenho_teto', '2'));

    // Código do Infloww (prefixo do remetente, ex.: "SRJ70") → código do SR.
    $repDe = [];
    foreach (escQ('SELECT UPPER(rep_code) AS rep, code_infloww FROM `5c_wfm_db_mis`.`vw_rep_estrutura_atual` WHERE code_infloww IS NOT NULL AND code_infloww <> \'\' AND rep_code IN (' . escIn($reps) . ')', $reps) as $r) {
        $repDe[(string) $r['code_infloww']] = $r['rep'];
    }

    // Meta e realizado 5CReps por dia, turno (T1–T6) e página.
    $pag = [];
    foreach (escQ('SELECT Date AS dia, Turn AS t, Page AS pagina, SUM(`Goal 5CReps`) AS meta, SUM(`5CReps`) AS real5
                     FROM `5c_wfm_db_mis`.`tab_intraday_base` WHERE Date >= ? AND Date <= ? GROUP BY Date, Turn, Page', [$de, $ate]) as $r) {
        $pag[substr((string) $r['dia'], 0, 10) . '|' . $r['t'] . '|' . $r['pagina']] = [(float) $r['meta'], (float) $r['real5']];
    }

    // Mensagens de todos os remetentes por dia, turno e página (as de quem atende
    // mais de uma página no mesmo horário são divididas igualmente entre elas).
    $msgs = [];     // [dia|t|pagina][cod] = mensagens
    $tot = [];      // [dia|t|pagina] = mensagens de todos
    $sla = [];      // [cod] = [respostas, em até 5 min, horas]
    // SLA justo (Capacity, script 23): as horas em que o posto forçado pela regra de faturamento
    // passou da capacidade (fc_sla_hora_excluida, última versão do dia) não entram no SLA.
    $excl = escMeritoSlaExcluidaOk()
        ? "EXISTS (SELECT 1 FROM `5c_planning`.`fc_sla_hora_excluida` x WHERE x.dia = c.dia AND x.hora = c.hora AND FIND_IN_SET(x.pagina, REPLACE(c.paginas, ' + ', ','))
                     AND x.versao = (SELECT MAX(x2.versao) FROM `5c_planning`.`fc_sla_hora_excluida` x2 WHERE x2.dia = x.dia))"
        : '0';
    foreach (escQ("SELECT dia, turno AS t, SUBSTRING_INDEX(rep, ' ', 1) AS cod, paginas, n_paginas, SUM(msgs_rep) AS m,
                          SUM(IF(excl, 0, respostas)) AS resp, SUM(IF(excl, 0, resp_5min)) AS r5, SUM(1 - excl) AS horas
                     FROM (SELECT c.*, " . $excl . " AS excl FROM `5c_capacity`.`cap_rep_hora` c WHERE c.dia >= ? AND c.dia <= ?) h
                    GROUP BY dia, turno, cod, paginas, n_paginas", [$de, $ate]) as $r) {
        $cod = (string) $r['cod'];
        $lista = array_values(array_filter(array_map('trim', explode(' + ', (string) $r['paginas']))));
        $parte = (float) $r['m'] / max(1, count($lista));
        foreach ($lista as $p) {
            $k = substr((string) $r['dia'], 0, 10) . '|' . $r['t'] . '|' . $p;
            $tot[$k] = ($tot[$k] ?? 0) + $parte;
            if (isset($repDe[$cod])) {
                $msgs[$k][$cod] = ($msgs[$k][$cod] ?? 0) + $parte;
            }
        }
        if (isset($repDe[$cod])) {
            $s = $sla[$cod] ?? [0, 0, 0];
            $sla[$cod] = [$s[0] + (int) $r['resp'], $s[1] + (int) $r['r5'], $s[2] + (int) $r['horas']];
        }
    }
    $metaRep = [];
    $realRep = [];
    foreach ($msgs as $k => $porCod) {
        if (!isset($pag[$k]) || empty($tot[$k])) {
            continue;
        }
        [$meta, $real] = $pag[$k];
        foreach ($porCod as $cod => $m) {
            $share = $m / $tot[$k];
            $metaRep[$cod] = ($metaRep[$cod] ?? 0) + $share * $meta;
            $realRep[$cod] = ($realRep[$cod] ?? 0) + $share * $real;
        }
    }

    // Absenteísmo sem a falha administrativa (abs_adh_af; se falhar, o 5C Schedule por horário).
    $abs = escAbsRealizado('rep', $de, $ate, $reps)['taxa'];

    // Infrações (tabela prevista; vazia enquanto não houver carga).
    $pena = [];
    $nInf = [];
    $pesos = escMeritoPesos();
    $meiaVida = (float) escV4Param('merito_infracao_meia_vida_dias', '90');
    $janelaInf = (int) escV4Param('merito_infracao_janela_dias', '365');
    try {
        foreach (escQ('SELECT UPPER(sr_code) AS rep, classification, COALESCE(applied_date, occurrency_date) AS desde
                         FROM ' . escT('esc_sr_infraction') . ' WHERE sr_code IN (' . escIn($reps) . ') AND COALESCE(applied_date, occurrency_date) >= ?',
            array_merge($reps, [date('Y-m-d', strtotime('-' . $janelaInf . ' day'))])) as $r) {
            $dias = (int) floor((strtotime(date('Y-m-d')) - strtotime(substr((string) $r['desde'], 0, 10))) / 86400);
            $peso = $pesos[mb_strtoupper(trim((string) $r['classification']))] ?? 1.0;
            $pena[$r['rep']] = ($pena[$r['rep']] ?? 0) + escMeritoPena($peso, $dias, $meiaVida, $janelaInf);
            $nInf[$r['rep']] = ($nInf[$r['rep']] ?? 0) + 1;
        }
    } catch (Throwable $e) {
        appLog('warning', 'Mérito: tabela de infrações indisponível', ['erro' => $e->getMessage()]);
    }

    $codDe = array_flip($repDe);
    $pAbs = escMeritoAbsParam();
    $saida = [];
    foreach ($reps as $rep) {
        $cod = $codDe[$rep] ?? null;
        $s = $cod !== null ? ($sla[$cod] ?? null) : null;
        $temDados = $s !== null && $s[0] >= $minResp;
        // Teto no desempenho: com meta muito pequena (poucas horas em horário fraco) a razão explode.
        $des = $temDados && !empty($metaRep[$cod]) ? min($teto, $realRep[$cod] / $metaRep[$cod]) : null;
        $sl = $temDados ? $s[1] / max(1, $s[0]) : null;
        $ab = $abs[$rep] ?? null;
        $pe = $pena[$rep] ?? 0.0;
        $saida[$rep] = ['desempenho' => $des, 'sla' => $sl, 'absenteismo' => $ab, 'pena' => $pe, 'infracoes' => $nInf[$rep] ?? 0,
            'nota' => escMeritoNota($des, $sl, $ab, $pe, $pAbs), 'horas' => $s[2] ?? 0, 'respostas' => $s[0] ?? 0, 'janela_de' => $de, 'janela_ate' => $ate];
    }
    return escMeritoTetoCritico($saida, $pAbs);
}

// Atingimento de cada rep em cada página, por período (PDF de metas da Grade): a parte do
// realizado e da meta 5CReps da página que cabe ao rep, pela fatia das mensagens no dia, bloco
// e página (a mesma regra do desempenho do mérito). $periodos = [[de, ate], ...], datas
// inclusivas; nada depois de ontem. Volta [pagina][rep][i] = [realizado, meta].
function escMeritoAtingimentoPaginas(array $paginas, array $reps, array $periodos): array {
    $reps = array_values(array_unique(array_map('strtoupper', $reps)));
    if (!$paginas || !$reps || !$periodos) {
        return [];
    }
    $ontem = date('Y-m-d', strtotime('-1 day'));
    $de = min(array_column($periodos, 0));
    $ate = min($ontem, max(array_column($periodos, 1)));
    if ($de > $ate) {
        return [];
    }
    $periodoDe = static function (string $dia) use ($periodos): ?int {
        foreach ($periodos as $i => [$a, $b]) {
            if ($dia >= $a && $dia <= $b) {
                return $i;
            }
        }
        return null;
    };
    // Os nomes de página às vezes vêm com espaço a mais ("Haven  Free").
    $chave = static fn(string $p): string => mb_strtolower(preg_replace('~\s+~u', ' ', trim($p)), 'UTF-8');
    $nomeDe = [];
    foreach ($paginas as $p) {
        $nomeDe[$chave($p)] = $p;
    }
    $repDe = [];
    foreach (escQ('SELECT UPPER(rep_code) AS rep, code_infloww FROM `5c_wfm_db_mis`.`vw_rep_estrutura_atual` WHERE code_infloww IS NOT NULL AND code_infloww <> \'\' AND rep_code IN (' . escIn($reps) . ')', $reps) as $r) {
        $repDe[(string) $r['code_infloww']] = $r['rep'];
    }
    $pag = [];
    foreach (escQ('SELECT Date AS dia, Turn AS t, Page AS pagina, SUM(`Goal 5CReps`) AS meta, SUM(`5CReps`) AS real5
                     FROM `5c_wfm_db_mis`.`tab_intraday_base` WHERE Date >= ? AND Date <= ? GROUP BY Date, Turn, Page', [$de, $ate]) as $r) {
        $p = $nomeDe[$chave((string) $r['pagina'])] ?? null;
        if ($p !== null) {
            $k = substr((string) $r['dia'], 0, 10) . '|' . $r['t'] . '|' . $p;
            $pag[$k] = [($pag[$k][0] ?? 0) + (float) $r['real5'], ($pag[$k][1] ?? 0) + (float) $r['meta']];
        }
    }
    $msgs = [];
    $tot = [];
    foreach (escQ("SELECT dia, turno AS t, SUBSTRING_INDEX(rep, ' ', 1) AS cod, paginas, SUM(msgs_rep) AS m
                     FROM `5c_capacity`.`cap_rep_hora` WHERE dia >= ? AND dia <= ? GROUP BY dia, turno, cod, paginas", [$de, $ate]) as $r) {
        $lista = array_values(array_filter(array_map('trim', explode(' + ', (string) $r['paginas']))));
        $parte = (float) $r['m'] / max(1, count($lista));
        $cod = (string) $r['cod'];
        foreach ($lista as $p) {
            $p = $nomeDe[$chave($p)] ?? null;
            if ($p === null) {
                continue;
            }
            $k = substr((string) $r['dia'], 0, 10) . '|' . $r['t'] . '|' . $p;
            $tot[$k] = ($tot[$k] ?? 0) + $parte;
            if (isset($repDe[$cod])) {
                $msgs[$k][$repDe[$cod]] = ($msgs[$k][$repDe[$cod]] ?? 0) + $parte;
            }
        }
    }
    $out = [];
    foreach ($msgs as $k => $porRep) {
        if (!isset($pag[$k]) || empty($tot[$k])) {
            continue;
        }
        [$dia, , $p] = explode('|', $k, 3);
        $i = $periodoDe($dia);
        if ($i === null) {
            continue;
        }
        [$real, $meta] = $pag[$k];
        foreach ($porRep as $rep => $m) {
            $share = $m / $tot[$k];
            $out[$p][$rep][$i][0] = ($out[$p][$rep][$i][0] ?? 0) + $share * $real;
            $out[$p][$rep][$i][1] = ($out[$p][$rep][$i][1] ?? 0) + $share * $meta;
        }
    }
    return $out;
}

// SRs da estrutura nova (todos os vigentes ou que começam até a semana seguinte).
function escMeritoSrsAtivos(): array {
    $d = date('Y-m-d', strtotime('+14 day'));
    return array_column(escQ('SELECT DISTINCT UPPER(rep_code) AS rep FROM ' . escT('esc_colaborador_config')
        . " WHERE funcao = 'EQUITE' AND nucleo_id IS NOT NULL AND vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= CURDATE())", [$d]), 'rep');
}

// Mérito em cache; recalcula e grava se estiver velho (ou se $forcar).
function escMeritoCarregar(bool $forcar = false): array {
    $horas = max(1, (int) escV4Param('merito_cache_horas', '20'));
    try {
        $ult = escQ1('SELECT MAX(calculado_em) AS m FROM ' . escT('esc_sr_merito'));
    } catch (Throwable $e) {
        return []; // ES_16 ainda não rodou: sem mérito, o motor segue como antes
    }
    $velho = !$ult || $ult['m'] === null || strtotime((string) $ult['m']) < time() - $horas * 3600;
    if ($forcar || $velho) {
        try {
            $calc = escMeritoCalcular(escMeritoSrsAtivos());
            $agora = date('Y-m-d H:i:s');
            $linhas = [];
            foreach ($calc as $rep => $m) {
                $linhas[] = ['rep_code' => $rep, 'desempenho' => $m['desempenho'], 'sla' => $m['sla'], 'absenteismo' => $m['absenteismo'],
                    'pena' => round($m['pena'], 4), 'infracoes' => $m['infracoes'], 'nota' => $m['nota'], 'horas' => $m['horas'],
                    'respostas' => $m['respostas'], 'janela_de' => $m['janela_de'], 'janela_ate' => $m['janela_ate'], 'calculado_em' => $agora];
            }
            if ($linhas) {
                escTx(static function () use ($linhas, $agora) {
                    escW('DELETE FROM `' . ESC_SCHEMA . '`.`esc_sr_merito` WHERE calculado_em < ?', [$agora]);
                    escInserirLote('esc_sr_merito', array_keys($linhas[0]), $linhas);
                });
            }
        } catch (Throwable $e) {
            appLog('error', 'Mérito: falha ao recalcular', ['erro' => $e->getMessage()]);
        }
    }
    $out = [];
    foreach (escQ('SELECT * FROM ' . escT('esc_sr_merito')) as $r) {
        $out[$r['rep_code']] = $r;
    }
    return $out;
}

// Nota normalizada para o motor: 1 = mediana; sem nota = mediana.
function escMeritoNotasMotor(array $merito): array {
    $notas = [];
    foreach ($merito as $rep => $m) {
        if ($m['nota'] !== null) {
            $notas[$rep] = (float) $m['nota'];
        }
    }
    if (!$notas) {
        return [];
    }
    $v = array_values($notas);
    sort($v);
    $med = $v[intdiv(count($v), 2)] ?: 1.0;
    $out = [];
    foreach ($merito as $rep => $m) {
        $out[$rep] = isset($notas[$rep]) ? $notas[$rep] / $med : 1.0;
    }
    return $out;
}
