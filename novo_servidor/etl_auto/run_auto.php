<?php
/**
 * etl_auto/run_auto.php — EXECUÇÃO AUTOMÁTICA DAS ETLs (a cada 15 min).
 *
 * Chamado pelo Agendador de Tarefas do Windows (ver README.md):
 *   php.exe C:\WebServer\Apache\htdocs\mis_portal\systems\etl_auto\run_auto.php
 *
 * Um ciclo:
 *   1. pula se estiver pausado, se o ciclo anterior ainda roda, ou se houve
 *      ETL manual nos últimos 5 min (manual tem prioridade);
 *   2. roda, em sequência, cada ETL como processo filho (cli_exec.php),
 *      exatamente o mesmo código das telas:
 *        - Cadastros 5C: Historic Planner (HeadCount e Pages vêm do
 *          servidor novo pelos events do MySQL, a cada 10 min)
 *        - Escala: do dia de (agora - 1 h) até hoje
 *        - Infloww, por agência ativa: all creators, creators, employees,
 *          assigned creators; transações, sales records e reembolsos da
 *          última 1 h (às 3h: últimas 48 h — repescagem)
 *   3. se uma ETL MANUAL pedir passagem (state/preempt.json), mata o filho
 *      na hora (o banco desfaz a transação em andamento), libera a fila do
 *      Infloww e encerra o ciclo; o próximo ciclo tenta de novo.
 *
 * Estado e logs em etl_auto/state/ (status também na tela etl_auto/index.php).
 */

require __DIR__ . '/lib/auto_coord.php';
if (!etlAutoIsCommandLine()) {
    http_response_code(404);
    exit;
}
@set_time_limit(0); // php-cgi.exe aplica o max_execution_time do php.ini

$cfg = require __DIR__ . '/config.php';
date_default_timezone_set($cfg['timezone']);

$sys = rtrim($cfg['systems_dir'], '/\\');
$logDir = etlAutoStatePath('logs');
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}

function autoLog(string $msg): void
{
    global $logDir;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    @file_put_contents($logDir . '/auto_' . date('Y-m-d') . '.log', $line, FILE_APPEND);
    echo $line;
}

function autoHistory(array $entry): void
{
    $hist = etlAutoReadJson('history.json') ?? [];
    array_unshift($hist, $entry);
    etlAutoWriteJson('history.json', array_slice($hist, 0, 200));
}

// ---------------------------------------------------------------- pré-checagens
$cycleId = date('Ymd_His');
$cycleStart = time();

/**
 * Registra TODA chamada do Agendador (inclusive ciclos pulados e erros
 * fatais) em state/last_attempt.json — é o que a tela usa para mostrar
 * "o Agendador disparou às HH:MM e aconteceu X", mesmo sem ciclo completo.
 */
function lastAttempt(string $result, ?string $detail = null): void
{
    global $cycleId, $cycleStart;
    etlAutoWriteJson('last_attempt.json', [
        'at'     => $cycleStart,
        'endedAt'=> time(),
        'cycle'  => $cycleId,
        'result' => $result,
        'detail' => $detail,
        'php'    => PHP_BINARY . ' (' . PHP_VERSION . ')',
        'ext'    => ['curl' => extension_loaded('curl'), 'pdo_mysql' => extension_loaded('pdo_mysql')],
        'user'   => get_current_user(),
    ]);
}
lastAttempt('iniciado');
register_shutdown_function(static function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        lastAttempt('erro fatal', $e['message'] . ' (' . basename($e['file']) . ':' . $e['line'] . ')');
        autoLog('ERRO FATAL: ' . $e['message'] . ' em ' . $e['file'] . ':' . $e['line']);
    }
});

$faltando = array_keys(array_filter(['curl' => !extension_loaded('curl'), 'pdo_mysql' => !extension_loaded('pdo_mysql')]));
if ($faltando) {
    $msg = 'o PHP de linha de comando (' . PHP_BINARY . ') não tem: ' . implode(', ', $faltando) . ' — habilite no php.ini que ele usa (' . (php_ini_loaded_file() ?: 'nenhum php.ini carregado') . ')';
    autoLog("Ciclo {$cycleId}: NÃO executado — {$msg}.");
    lastAttempt('erro', $msg);
    exit(1);
}

// Ciclo FORÇADO pelo botão "Rodar agora" da tela (state/forced.json recente):
// roda mesmo pausado e mesmo com ETL manual recente — é para testes. Só não
// roda por cima de uma ETL manual EM ANDAMENTO nem de outro ciclo.
$forced = etlAutoReadJson('forced.json');
$forced = ($forced && time() - (int) ($forced['at'] ?? 0) <= 180) ? $forced : null;
@unlink(etlAutoStatePath('forced.json'));

if (!$forced && (empty($cfg['enabled']) || is_file(etlAutoStatePath('paused.flag')))) {
    autoLog("Ciclo {$cycleId}: pulado — execução automática pausada.");
    lastAttempt('pulado', 'execução automática pausada');
    exit(0);
}

$runnerLock = fopen(etlAutoStatePath('runner.lock'), 'c');
if (!$runnerLock || !flock($runnerLock, LOCK_EX | LOCK_NB)) {
    autoLog("Ciclo {$cycleId}: pulado — o ciclo anterior ainda está rodando.");
    lastAttempt('pulado', 'o ciclo anterior ainda está rodando');
    exit(0);
}

$manual = etlAutoManualActivity($forced ? 0 : (int) $cfg['manual_quiet_minutes']);
if ($manual !== null) {
    autoLog("Ciclo {$cycleId}: pulado — {$manual}.");
    lastAttempt('pulado', $manual);
    autoHistory(['cycle' => $cycleId, 'startedAt' => $cycleStart, 'finishedAt' => time(), 'status' => 'pulado', 'reason' => $manual, 'steps' => []]);
    exit(0);
}

@unlink(etlAutoStatePath('preempt.json'));

$state = ['cycle' => $cycleId, 'pid' => getmypid(), 'startedAt' => $cycleStart, 'heartbeat' => time(), 'step' => null,
          'forcedBy' => $forced['by'] ?? null];
if ($forced) {
    autoLog("Ciclo {$cycleId}: FORÇADO pela tela por {$forced['by']}.");
}
etlAutoWriteJson('running.json', $state);

$inflowwLockLib = $sys . '/etl_infloww/api/global_lock.php';
register_shutdown_function(static function () use ($inflowwLockLib, $runnerLock) {
    // Sempre: tira o "rodando", devolve a fila do Infloww se era nossa.
    @unlink(etlAutoStatePath('running.json'));
    if (is_file($inflowwLockLib)) {
        require_once $inflowwLockLib;
        inflowwReleaseLease(ETL_AUTO_INFLOWW_TAB_ID);
    }
    if ($runnerLock) {
        flock($runnerLock, LOCK_UN);
    }
});

function heartbeat(?string $step = null): void
{
    global $state;
    $state['heartbeat'] = time();
    if ($step !== null) {
        $state['step'] = $step;
    }
    etlAutoWriteJson('running.json', $state);
}

function preemptRequest(): ?array
{
    global $cycleStart;
    $p = etlAutoReadJson('preempt.json');
    return ($p && (int) ($p['at'] ?? 0) >= $cycleStart - 1) ? $p : null;
}

// ---------------------------------------------------------------- janelas
$now = time();
$windowMinutes = (int) $cfg['window_minutes'];
$from = $now - $windowMinutes * 60;

$isCatchup = false;
$vendasFrom = $from;
$lastCatchup = trim((string) @file_get_contents(etlAutoStatePath('last_catchup.txt')));
if ((int) date('G', $now) === (int) $cfg['catchup_hour'] && $lastCatchup !== date('Y-m-d', $now)) {
    $isCatchup = true;
    $vendasFrom = $now - (int) $cfg['catchup_hours'] * 3600;
}
$isoUtc = static fn(int $t): string => gmdate('Y-m-d\TH:i:s\Z', $t);

// ---------------------------------------------------------------- execução de uma etapa
/**
 * Roda um script de ETL como processo filho e acompanha até terminar,
 * estourar o tempo ou chegar pedido de interrupção.
 * @return array{status:string, output:string, seconds:float}
 */
function runChild(string $script, array $query, string $label): array
{
    global $cfg;
    $tmpOut = tempnam(sys_get_temp_dir(), 'etlauto');
    $tmpErr = tempnam(sys_get_temp_dir(), 'etlauto');
    $env = getenv();
    $env['ETL_AUTO_RUN'] = '1';
    $env['ETL_AUTO_SCRIPT'] = $script;
    $env['ETL_AUTO_QUERY'] = http_build_query($query);

    // Os filhos usam o MESMO php.ini e a MESMA pasta de extensões do runner
    // (no Windows, o php.exe de linha de comando muitas vezes não acha o
    // php.ini do Apache ou resolve "extension_dir = ext" pela pasta atual).
    $cmd = [$cfg['php_binary']];
    if (PHP_SAPI === 'cgi-fcgi') {
        $cmd[] = '-q'; // php-cgi: sem cabeçalhos HTTP na saída
    }
    if ($ini = php_ini_loaded_file()) {
        array_push($cmd, '-c', $ini);
    }
    $extDir = (string) ini_get('extension_dir');
    if ($extDir !== '' && ($extReal = realpath($extDir))) {
        array_push($cmd, '-d', 'extension_dir=' . $extReal);
    }
    array_push($cmd, '-f', __DIR__ . '/cli_exec.php');
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $tmpOut, 'w'], 2 => ['file', $tmpErr, 'w']], $pipes, dirname($script), $env);
    if (!is_resource($proc)) {
        return ['status' => 'erro', 'output' => '', 'stderr' => 'não foi possível iniciar o processo', 'seconds' => 0.0];
    }
    fclose($pipes[0]);

    $t0 = microtime(true);
    $status = 'ok';
    $lastBeat = 0;
    while (true) {
        $st = proc_get_status($proc);
        if (!$st['running']) {
            break;
        }
        if (preemptRequest()) {
            proc_terminate($proc);
            $status = 'interrompido';
            break;
        }
        if (microtime(true) - $t0 > (int) $cfg['step_timeout_seconds']) {
            proc_terminate($proc);
            $status = 'tempo_esgotado';
            break;
        }
        if (time() - $lastBeat >= 2) {
            heartbeat($label);
            $lastBeat = time();
        }
        usleep(300000);
    }
    proc_close($proc);

    // stdout = a resposta da ETL (SSE ou JSON); stderr = avisos/erros do PHP.
    // Separados: um aviso do PHP (ex.: "Deprecated" no PHP 8.5) não pode
    // grudar no JSON e impedir a leitura do resultado.
    $out = (string) @file_get_contents($tmpOut);
    $err = (string) @file_get_contents($tmpErr);
    @unlink($tmpOut);
    @unlink($tmpErr);

    // Avisos ficam no log do dia (sem repetir o mesmo aviso a cada etapa)
    static $avisosJaLogados = [];
    foreach (preg_split('/\R/', trim($err)) as $linha) {
        $linha = trim($linha);
        if ($linha !== '' && !isset($avisosJaLogados[$linha])) {
            $avisosJaLogados[$linha] = true;
            autoLog('    [aviso do PHP] ' . substr($linha, 0, 400));
        }
    }

    return ['status' => $status, 'output' => $out, 'stderr' => $err, 'seconds' => round(microtime(true) - $t0, 1)];
}

/** Lê o resultado de uma ETL da API 5C (stream SSE: evento done/fatal). */
function parseSse(string $out): array
{
    $done = null;
    $fatal = null;
    $ev = null;
    foreach (preg_split('/\R/', $out) as $line) {
        if (strpos($line, 'event: ') === 0) {
            $ev = substr($line, 7);
        } elseif (strpos($line, 'data: ') === 0) {
            if ($ev === 'done') {
                $done = json_decode(substr($line, 6), true);
            } elseif ($ev === 'fatal') {
                $fatal = json_decode(substr($line, 6), true);
            }
        }
    }
    if ($done !== null) {
        $msg = sprintf('%d recebidos, %d novos, %d atualizados, %d removidos, %d erros',
            $done['total'] ?? 0, $done['inserted'] ?? 0, $done['updated'] ?? 0, $done['removed'] ?? 0, $done['errors'] ?? 0);
        if (!empty($done['syncBlocked'])) {
            $msg .= ' — remoção bloqueada: ' . $done['syncBlocked'];
        }
        return ['ok' => true, 'msg' => $msg];
    }
    return ['ok' => false, 'msg' => $fatal['message'] ?? ('sem resposta da ETL: ' . substr(trim($out), -300))];
}

/** Lê o JSON devolvido por um endpoint do Infloww (o último objeto {"success":...} da saída). */
function parseJsonOut(string $out): ?array
{
    $pos = strrpos($out, '{"success"');
    while ($pos !== false) {
        $chunk = substr($out, $pos);
        $d = json_decode($chunk, true);
        if (!is_array($d)) {
            // texto grudado depois do JSON: corta no último "}" e tenta de novo
            $fim = strrpos($chunk, '}');
            while (!is_array($d) && $fim !== false && $fim > 0) {
                $d = json_decode(substr($chunk, 0, $fim + 1), true);
                $fim = is_array($d) ? $fim : strrpos(substr($chunk, 0, $fim), '}');
            }
        }
        if (is_array($d)) {
            return $d;
        }
        $pos = $pos > 0 ? strrpos(substr($out, 0, $pos), '{"success"') : false;
    }
    return null;
}

/** Trecho útil para mensagem de erro: fim da saída + fim do stderr (sem avisos "Deprecated"). */
function errTail(array $r): string
{
    $err = implode("\n", array_filter(preg_split('/\R/', (string) ($r['stderr'] ?? '')), fn($l) => stripos($l, 'Deprecated') === false));
    return substr(trim(trim($r['output']) . ' ' . trim($err)), -300);
}

// ---------------------------------------------------------------- limpeza
// Arquivos de coleta da própria execução automática que ficaram para trás
// (etapa interrompida/ilegível) há mais de 30 min: apaga. Identifica pelo
// cabeçalho do JSON ("collectedByEmail": "etl-auto@sistema") — nunca toca
// em arquivo coletado por uma pessoa.
foreach (glob("$sys/etl_infloww/data_collected/*.json") ?: [] as $velho) {
    if (time() - (int) @filemtime($velho) < 1800) {
        continue;
    }
    $cabecalho = (string) @file_get_contents($velho, false, null, 0, 4096);
    if (preg_match('/"collectedByEmail"\s*:\s*"etl-auto@sistema"/', $cabecalho)) {
        @unlink($velho);
    }
}

// ---------------------------------------------------------------- monta as etapas
$steps = [];
$groups = $cfg['groups'];

if (!empty($groups['cadastros_5c'])) {
    // HeadCount e Pages saíram daqui: vêm do servidor novo (209.159.144.7)
    // pelos events ev_sync_headcount_novo_servidor e ev_sync_pages_novo_servidor
    // do MySQL, a cada 10 min (scripts NS_01..NS_03).
    $steps[] = ['label' => 'Historic Planner', 'kind' => 'sse', 'script' => "$sys/etl_historic_planner/public/import_stream.php", 'query' => []];
}
if (!empty($groups['schedule'])) {
    $steps[] = ['label' => 'Schedule (' . date('d/m', $from) . ($from < strtotime('today', $now) ? '–' . date('d/m', $now) : '') . ')',
        'kind' => 'sse', 'script' => "$sys/5c_schedule/public/import_stream.php",
        'query' => ['start' => date('d/m/Y', $from), 'end' => date('d/m/Y', $now)]];
}

$agencies = [];
if (!empty($groups['infloww_vinculos']) || !empty($groups['infloww_vendas'])) {
    heartbeat('Infloww: lista de agências');
    $r = runChild("$sys/etl_infloww/api/list_agencies.php", [], 'Infloww: lista de agências');
    $d = parseJsonOut($r['output']);
    if ($d && !empty($d['success'])) {
        foreach ($d['agencies'] ?? [] as $a) {
            $agencies[] = is_array($a) ? ($a['code'] ?? null) : $a;
        }
        $agencies = array_values(array_filter($agencies));
    } else {
        autoLog('Infloww: não foi possível listar as agências — ' . errTail($r));
    }
}
foreach ($agencies as $agency) {
    if (!empty($groups['infloww_vinculos'])) {
        foreach ([['all creators', 'collect_all_creators.php'], ['creators', 'collect_creators.php'],
                  ['employees', 'collect_employees.php'], ['vínculos', 'collect_assigned_creators.php']] as [$nome, $file]) {
            $steps[] = ['label' => "Infloww {$agency}: {$nome}", 'kind' => 'infloww', 'script' => "$sys/etl_infloww/api/{$file}",
                'query' => ['agency' => $agency], 'vendas' => false];
        }
    }
    if (!empty($groups['infloww_vendas'])) {
        $janela = ['fromTime' => $isoUtc($vendasFrom), 'toTime' => $isoUtc($now)];
        foreach ([['transações', 'collect.php', []], ['sales records', 'collect_report.php', ['type' => 'salesrecords']],
                  ['reembolsos', 'collect_refunds.php', []]] as [$nome, $file, $extra]) {
            $steps[] = ['label' => "Infloww {$agency}: {$nome}", 'kind' => 'infloww', 'script' => "$sys/etl_infloww/api/{$file}",
                'query' => array_merge(['agency' => $agency], $extra, $janela), 'vendas' => true];
        }
    }
}

autoLog(sprintf('Ciclo %s: início — %d etapa(s); janela vendas %s → %s%s.', $cycleId, count($steps),
    date('d/m H:i', $vendasFrom), date('d/m H:i', $now), $isCatchup ? ' (REPESCAGEM ' . $cfg['catchup_hours'] . ' h)' : ''));

// ---------------------------------------------------------------- roda
$results = [];
$cycleStatus = 'ok';
$vendasOk = true;

foreach ($steps as $step) {
    if ($p = preemptRequest()) {
        $cycleStatus = 'interrompido';
        autoLog("Interrompido antes de \"{$step['label']}\": ETL manual pediu passagem ({$p['by']}).");
        break;
    }
    heartbeat($step['label']);
    $t0 = microtime(true);
    $res = ['label' => $step['label'], 'status' => 'ok', 'msg' => '', 'seconds' => 0];

    if ($step['kind'] === 'sse') {
        $r = runChild($step['script'], $step['query'], $step['label']);
        if ($r['status'] !== 'ok') {
            $res['status'] = $r['status'];
        } else {
            $p = parseSse($r['output']);
            if (!$p['ok'] && $p['msg'] === 'sem resposta da ETL: ' . substr(trim($r['output']), -300)) {
                $p['msg'] = 'sem resposta da ETL: ' . errTail($r);
            }
            $res['status'] = $p['ok'] ? 'ok' : 'erro';
            $res['msg'] = $p['msg'];
        }
    } else {
        // Infloww: coletar (gera um JSON em data_collected/) + gravar no banco
        $q = array_merge($step['query'], ['tabId' => ETL_AUTO_INFLOWW_TAB_ID, 'runId' => 'auto' . bin2hex(random_bytes(5))]);
        $coletaInicio = time();
        $r = runChild($step['script'], $q, $step['label'] . ' (coleta)');
        $d = parseJsonOut($r['output']);
        if ($r['status'] !== 'ok' || !$d || empty($d['success']) || empty($d['file'])) {
            // Coleta interrompida/ilegível: apaga o que ela possa ter deixado
            // em data_collected/ (só arquivos criados durante esta etapa).
            foreach (glob("$sys/etl_infloww/data_collected/*.json") ?: [] as $orfao) {
                if ((int) @filemtime($orfao) >= $coletaInicio) {
                    @unlink($orfao);
                }
            }
        }
        if ($r['status'] !== 'ok') {
            $res['status'] = $r['status'];
        } elseif (!$d || empty($d['success']) || empty($d['file'])) {
            $res['status'] = !empty($d['busy']) ? 'ocupado' : 'erro';
            $res['msg'] = $d['error'] ?? ('resposta inesperada: ' . errTail($r));
        } else {
            $file = $d['file'];
            $res['msg'] = ($d['total'] ?? 0) . ' coletado(s)' . (!empty($d['warning']) ? ' — ' . $d['warning'] : '');
            $q2 = ['agency' => $step['query']['agency'], 'tabId' => ETL_AUTO_INFLOWW_TAB_ID, 'runId' => 'auto' . bin2hex(random_bytes(5)), 'file' => $file];
            $r2 = runChild("$sys/etl_infloww/api/send_to_mysql.php", $q2, $step['label'] . ' (gravação)');
            $d2 = parseJsonOut($r2['output']);
            if ($r2['status'] !== 'ok') {
                $res['status'] = $r2['status'];
            } elseif (!$d2 || empty($d2['success'])) {
                $res['status'] = !empty($d2['busy']) ? 'ocupado' : 'erro';
                $res['msg'] .= '; gravação: ' . ($d2['error'] ?? errTail($r2));
            } else {
                $res['msg'] .= sprintf('; %d novo(s), %d atualizado(s)%s', $d2['inserted'] ?? 0, $d2['updated'] ?? 0,
                    !empty($d2['unassigned']) ? ', ' . $d2['unassigned'] . ' desvinculado(s)' : '');
            }
            // Arquivo que não chegou a ser gravado (interrupção/erro): não deixa lixo
            $leftover = "$sys/etl_infloww/data_collected/" . basename($file);
            if (is_file($leftover)) {
                @unlink($leftover);
            }
        }
        if (!empty($step['vendas']) && $res['status'] !== 'ok') {
            $vendasOk = false;
        }
    }

    $res['seconds'] = round(microtime(true) - $t0, 1);
    $results[] = $res;
    autoLog(sprintf('  %-34s %-14s %6.1fs  %s', $res['label'], $res['status'], $res['seconds'], $res['msg']));

    if ($res['status'] === 'interrompido' || $res['status'] === 'ocupado') {
        // Manual pediu passagem (ou já está segurando a fila do Infloww): para tudo.
        $cycleStatus = 'interrompido';
        $p = preemptRequest();
        autoLog('Ciclo interrompido: ' . ($p ? "ETL manual pediu passagem ({$p['by']})" : 'fila do Infloww ocupada por operação manual') . '. O que já foi gravado fica; o próximo ciclo tenta de novo.');
        break;
    }
    if ($res['status'] !== 'ok') {
        $cycleStatus = 'parcial';
    }
}

if ($isCatchup && $cycleStatus !== 'interrompido' && $vendasOk && !empty($groups['infloww_vendas'])) {
    @file_put_contents(etlAutoStatePath('last_catchup.txt'), date('Y-m-d', $now));
}

autoLog(sprintf('Ciclo %s: fim — %s em %ds.', $cycleId, $cycleStatus, time() - $cycleStart));
lastAttempt($cycleStatus, count($results) . ' etapa(s) em ' . (time() - $cycleStart) . ' s');
autoHistory([
    'cycle'      => $cycleId,
    'startedAt'  => $cycleStart,
    'finishedAt' => time(),
    'status'     => $cycleStatus,
    'catchup'    => $isCatchup,
    'forcedBy'   => $forced['by'] ?? null,
    'window'     => [date('c', $vendasFrom), date('c', $now)],
    'steps'      => $results,
]);

// Limpeza de logs antigos do runner
foreach (glob($logDir . '/auto_*.log') ?: [] as $f) {
    if (time() - (int) @filemtime($f) > (int) $cfg['log_retention_days'] * 86400) {
        @unlink($f);
    }
}
exit(0);
