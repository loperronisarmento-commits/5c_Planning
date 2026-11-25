<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/atingimento.php
// Atingimento da meta 5CReps por SR (por modelo e por página), absenteísmo realizado e SRs ativos.
// Usado pelo Gerar (motor), pela Grade (PDF de metas), pelas tags, pelo KPI e pela Análise.
// Saiu de lib/merito.php na 1.38.0: o Mérito virou Legacy (só leitura) e o KPI é o indicador oficial.
// ===============================================

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
    $de = escCorteDados($de);
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

// Atingimento de cada rep na MODELO (pedido de 11/11): soma o realizado e a meta de todas as páginas abertas
// da modelo das $paginas (escModeloPaginasAbertas). Volta [modelo][rep][i] = [realizado, meta].
function escAtingimentoModelos(array $paginas, array $reps, array $periodos): array {
    $abertas = escModeloPaginasAbertas();
    $todas = [];
    foreach ($paginas as $p) {
        $todas[(string) $p] = true;
        foreach ($abertas[escModeloDaPagina((string) $p)] ?? [] as $irma) {
            $todas[$irma] = true;
        }
    }
    $out = [];
    foreach (escAtingimentoPaginas(array_keys($todas), $reps, $periodos) as $pg => $porRep) {
        $m = escModeloDaPagina((string) $pg);
        foreach ($porRep as $rep => $porPeriodo) {
            foreach ($porPeriodo as $i => [$real, $meta]) {
                $out[$m][$rep][$i] = [($out[$m][$rep][$i][0] ?? 0.0) + (float) $real, ($out[$m][$rep][$i][1] ?? 0.0) + (float) $meta];
            }
        }
    }
    return $out;
}

// Parte do realizado 5CReps da página no bloco que é do SR (1.39.0, regra do usuário de 24/11): do SR
// são só a venda direta (as mensagens que ele mesmo vendeu: cap_rep_hora.valor_comprado) e a tip;
// assinatura, live tip, live, recurring e post são da página. A tip não liga ao SR pelo fã (o msgdashboard
// guarda o nome de exibição do fã e o extrato, o username), então a tip do bloco vai pela fatia de
// mensagens. Antes da 1.39.0 o realizado ia todo pela fatia de mensagens: o assistente ou o new hire
// ao lado de um regular forte ficava com o atingimento da página (Alison, Mikayla Paid, 16/11: 21% das
// mensagens, 2% da venda, 108% da meta). Sem venda direta nem tip no bloco, vale a fatia de mensagens.
// v4_rateio_venda_propria = 0 volta à regra antiga. $msgs/$tot: mensagens do SR e de todos no bloco;
// $venda/$vtot: venda direta do SR e de todos; $tip: tip da página no bloco.
function escFatiaRealizado(float $msgs, float $tot, float $venda, float $vtot, float $tip): float {
    if ($tot <= 0) {
        return 0.0;
    }
    $fMsg = $msgs / $tot;
    static $liga = null;
    $liga ??= !function_exists('escV4Param') || escV4Param('v4_rateio_venda_propria', '1') === '1';
    $base = $vtot + $tip;
    if (!$liga || $base <= 0) {
        return $fMsg;
    }
    return ($venda + $tip * $fMsg) / $base;
}

// Atingimento de cada rep em cada página, por período (PDF de metas da Grade): a parte do
// realizado e da meta 5CReps da página que cabe ao rep no dia, bloco e página (a mesma regra do
// Kinetic do KPI): a meta pela fatia das mensagens; o realizado pela venda própria (escFatiaRealizado).
// $periodos = [[de, ate], ...], datas inclusivas; nada depois de ontem. Volta [pagina][rep][i] = [realizado, meta].
function escAtingimentoPaginas(array $paginas, array $reps, array $periodos): array {
    $reps = array_values(array_unique(array_map('strtoupper', $reps)));
    if (!$paginas || !$reps || !$periodos) {
        return [];
    }
    $ontem = date('Y-m-d', strtotime('-1 day'));
    $de = escCorteDados(min(array_column($periodos, 0)));
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
    foreach (escQ('SELECT Date AS dia, Turn AS t, Page AS pagina, SUM(`Goal 5CReps`) AS meta, SUM(`5CReps`) AS real5, SUM(TIP) AS tip
                     FROM `5c_wfm_db_mis`.`tab_intraday_base` WHERE Date >= ? AND Date <= ? GROUP BY Date, Turn, Page', [$de, $ate]) as $r) {
        $p = $nomeDe[$chave((string) $r['pagina'])] ?? null;
        if ($p !== null) {
            $k = substr((string) $r['dia'], 0, 10) . '|' . $r['t'] . '|' . $p;
            $pag[$k] = [($pag[$k][0] ?? 0) + (float) $r['real5'], ($pag[$k][1] ?? 0) + (float) $r['meta'], ($pag[$k][2] ?? 0) + max(0.0, (float) $r['tip'])];
        }
    }
    $msgs = [];
    $tot = [];
    $venda = [];
    $vtot = [];
    foreach (escQ("SELECT dia, turno AS t, SUBSTRING_INDEX(rep, ' ', 1) AS cod, paginas, SUM(msgs_rep) AS m, SUM(valor_comprado) AS v
                     FROM `5c_capacity`.`cap_rep_hora` WHERE dia >= ? AND dia <= ? GROUP BY dia, turno, cod, paginas", [$de, $ate]) as $r) {
        $lista = array_values(array_filter(array_map('trim', explode(' + ', (string) $r['paginas']))));
        $parte = (float) $r['m'] / max(1, count($lista));
        $parteV = max(0.0, (float) $r['v']) / max(1, count($lista));
        $cod = (string) $r['cod'];
        foreach ($lista as $p) {
            $p = $nomeDe[$chave($p)] ?? null;
            if ($p === null) {
                continue;
            }
            $k = substr((string) $r['dia'], 0, 10) . '|' . $r['t'] . '|' . $p;
            $tot[$k] = ($tot[$k] ?? 0) + $parte;
            $vtot[$k] = ($vtot[$k] ?? 0) + $parteV;
            if (isset($repDe[$cod])) {
                $msgs[$k][$repDe[$cod]] = ($msgs[$k][$repDe[$cod]] ?? 0) + $parte;
                $venda[$k][$repDe[$cod]] = ($venda[$k][$repDe[$cod]] ?? 0) + $parteV;
            }
        }
    }
    $out = [];
    foreach ($msgs as $k => $porRep) {
        // Sem meta no Intraday, o realizado não entra (somado na modelo, inflaria o "bateu a meta").
        if (!isset($pag[$k]) || empty($tot[$k]) || $pag[$k][1] <= 0) {
            continue;
        }
        [$dia, , $p] = explode('|', $k, 3);
        $i = $periodoDe($dia);
        if ($i === null) {
            continue;
        }
        [$real, $meta, $tip] = $pag[$k];
        foreach ($porRep as $rep => $m) {
            $share = $m / $tot[$k];
            $out[$p][$rep][$i][0] = ($out[$p][$rep][$i][0] ?? 0) + escFatiaRealizado($m, $tot[$k], $venda[$k][$rep] ?? 0.0, $vtot[$k] ?? 0.0, $tip) * $real;
            $out[$p][$rep][$i][1] = ($out[$p][$rep][$i][1] ?? 0) + $share * $meta;
        }
    }
    return $out;
}

// SRs da estrutura nova (todos os vigentes ou que começam até a semana seguinte).
function escSrsAtivos(): array {
    $d = date('Y-m-d', strtotime('+14 day'));
    return array_column(escQ('SELECT DISTINCT UPPER(rep_code) AS rep FROM ' . escT('esc_colaborador_config')
        . " WHERE funcao = 'EQUITE' AND nucleo_id IS NOT NULL AND vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= CURDATE())", [$d]), 'rep');
}
