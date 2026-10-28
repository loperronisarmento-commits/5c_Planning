<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/db.php
// Duas conexões, com as MESMAS variáveis do .env da plataforma (via
// env()), então nenhuma credencial fica neste módulo:
//
// 1) LEITURA (escDb / escQ): sessão em READ ONLY. Lê tudo (Planner,
//    escala postada, Capacity, CRM, headcount e o próprio 5c_escalas).
//    Só passa SELECT/WITH.
// 2) GRAVAÇÃO (escDbW / escW): só pode gravar no schema 5c_escalas.
//    Toda escrita passa por escConferirEscritaEscalas(): INSERT, UPDATE
//    ou DELETE em `5c_escalas`.`tabela`, sem citar outro schema, sem DDL.
//    Além disso, o GRANT do usuário do portal só dá escrita no 5c_escalas.
//
// Nenhuma das duas usa o $pdo compartilhado nem conexão persistente, para
// nada vazar para outros módulos.
// ===============================================

require_once SHARED_ROOT . 'config/env.php';

const ESC_SCHEMA = '5c_escalas';

if (!function_exists('checarMysqlSaudavel')) {
    // Mesma pré-checagem dos outros módulos: TCP + leitura com timeout
    // curto, para não prender a thread do Apache se o MySQL calar.
    function checarMysqlSaudavel(string $host, int $porta, int $timeoutSegundos): bool {
        $conexao = @stream_socket_client("tcp://{$host}:{$porta}", $errno, $errstr, $timeoutSegundos);
        if (!$conexao) {
            return false;
        }
        stream_set_timeout($conexao, $timeoutSegundos);
        $bytes = fread($conexao, 16);
        $meta = stream_get_meta_data($conexao);
        fclose($conexao);
        return !($meta['timed_out'] || $bytes === '' || $bytes === false);
    }
}

// ESC_DB_HOST e ESC_DB_PORT (opcionais no .env) valem só para este módulo, sem mexer nas
// outras aplicações do portal. Ex.: ESC_DB_HOST=127.0.0.1 quando o MySQL está na mesma
// máquina do portal. Sem eles, usa DB_HOST e DB_PORT.
function escDbEndereco(): array {
    $host = (string) env('ESC_DB_HOST', '');
    $porta = (string) env('ESC_DB_PORT', '');
    return [
        'host' => $host !== '' ? $host : (string) env('DB_HOST'),
        'porta' => $porta !== '' ? $porta : (string) env('DB_PORT', '3306'),
        'variavel' => $host !== '' ? 'ESC_DB_HOST' : 'DB_HOST',
    ];
}

function escNovaConexao(): PDO {
    ['host' => $host, 'porta' => $porta] = escDbEndereco();
    $banco = (string) env('DB_NAME', '');
    $charset = (string) env('DB_CHARSET', 'utf8mb4');

    $dsn = "mysql:host={$host};port={$porta};charset={$charset}";
    if ($banco !== '') {
        $dsn .= ";dbname={$banco}";
    }

    $opcoes = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => false,
        PDO::ATTR_TIMEOUT => 5,
    ];
    if (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
        $opcoes[PDO::MYSQL_ATTR_MULTI_STATEMENTS] = false;
    }

    // Conexão recusada (2002) costuma ser passageira no caminho até o MySQL: tenta de novo
    // por até ~15 s (0,5 · 1 · 2 · 3 · 4 · 5 s) antes de desistir, e registra cada tentativa.
    $esperas = [0.5, 1, 2, 3, 4, 5];
    for ($tentativa = 0; ; $tentativa++) {
        try {
            $pdo = new PDO($dsn, (string) env('DB_USER'), (string) env('DB_PASS'), $opcoes);
            if ($tentativa > 0 && function_exists('appLog')) {
                appLog('warning', 'Conexão com o banco aceita depois de nova tentativa', ['tentativas' => $tentativa + 1, 'host' => $host]);
            }
            break;
        } catch (PDOException $e) {
            if ($tentativa >= count($esperas) || strpos($e->getMessage(), '[2002]') === false) {
                if (function_exists('appLog')) {
                    appLog('error', 'Conexão com o banco recusada', ['tentativas' => $tentativa + 1, 'host' => $host, 'erro' => $e->getMessage()]);
                }
                throw $e;
            }
            usleep((int) ($esperas[$tentativa] * 1000000));
        }
    }
    $pdo->exec('SET SESSION max_execution_time = ' . (int) ESC_MAX_EXECUCAO_MS);
    return $pdo;
}

// ---------- leitura ----------

// Ações que gravam (POST) usam uma conexão só, a de gravação, também para ler: abrir uma
// segunda conexão no meio da ação é onde a conexão recusada (2002) costuma aparecer. A
// leitura continua passando pelo filtro de SELECT/WITH de escQ.
function escConexaoUnica(bool $ligar = true): void {
    $GLOBALS['ESC_CONEXAO_UNICA'] = $ligar;
}

function escDb(): PDO {
    static $pdo = null;
    if (!empty($GLOBALS['ESC_CONEXAO_UNICA']) && !$pdo instanceof PDO) {
        return escDbW();
    }
    if (!$pdo instanceof PDO) {
        $pdo = escNovaConexao();
        $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    }
    return $pdo;
}

// Tira textos entre aspas, nomes entre crases e comentários, para olhar
// só as palavras do comando.
function escSqlSemTexto(string $sql): string {
    $s = preg_replace("~'(?:[^'\\\\]|\\\\.)*'~s", "''", $sql);
    $s = preg_replace('~`[^`]*`~', '``', $s);
    return preg_replace('~/\*.*?\*/|--[^\n]*~s', ' ', $s);
}

function escConferirSomenteLeitura(string $sql): void {
    $semTexto = escSqlSemTexto($sql);
    if (!preg_match('~^\s*\(?\s*(SELECT|WITH)\b~i', $semTexto)) {
        throw new RuntimeException('Bloqueado: nesta conexão só consultas SELECT/WITH.');
    }
    if (strpos($semTexto, ';') !== false) {
        throw new RuntimeException('Bloqueado: uma consulta por vez, sem ";".');
    }
    if (preg_match('~\b(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|CREATE|TRUNCATE|GRANT|REVOKE|CALL|LOAD|RENAME|LOCK|UNLOCK|HANDLER|INTO|OUTFILE|DUMPFILE)\b~i', $semTexto, $m)) {
        throw new RuntimeException('Bloqueado: palavra de escrita na consulta (' . strtoupper($m[1]) . ').');
    }
}

function escQ(string $sql, array $params = []): array {
    escConferirSomenteLeitura($sql);
    $stmt = escDb()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function escQ1(string $sql, array $params = []): ?array {
    $linhas = escQ($sql, $params);
    return $linhas[0] ?? null;
}

function escIn(array $valores): string {
    return implode(',', array_fill(0, max(1, count($valores)), '?'));
}

// ---------- gravação (só 5c_escalas) ----------

function escDbW(): PDO {
    static $pdo = null;
    if (!$pdo instanceof PDO) {
        $pdo = escNovaConexao();
    }
    return $pdo;
}

function escConferirEscritaEscalas(string $sql): void {
    if (!preg_match('~^\s*(INSERT(\s+IGNORE)?\s+INTO|UPDATE|DELETE\s+FROM)\s+`' . ESC_SCHEMA . '`\.`[a-z_]+`~i', $sql)) {
        throw new RuntimeException('Bloqueado: só INSERT, UPDATE ou DELETE em `' . ESC_SCHEMA . '`.`tabela`.');
    }
    // Qualquer outro schema citado na instrução bloqueia.
    preg_match_all('~`([^`]+)`\s*\.\s*`~', $sql, $m);
    foreach ($m[1] as $schema) {
        if ($schema !== ESC_SCHEMA) {
            throw new RuntimeException('Bloqueado: gravação só no ' . ESC_SCHEMA . ' (citou ' . $schema . ').');
        }
    }
    $semTexto = escSqlSemTexto($sql);
    if (strpos($semTexto, ';') !== false) {
        throw new RuntimeException('Bloqueado: uma instrução por vez, sem ";".');
    }
    if (preg_match('~\b(ALTER|DROP|CREATE|TRUNCATE|GRANT|REVOKE|CALL|LOAD|RENAME|LOCK|UNLOCK|HANDLER|OUTFILE|DUMPFILE)\b~i', $semTexto, $x)) {
        throw new RuntimeException('Bloqueado: ' . strtoupper($x[1]) . ' não é permitido.');
    }
}

// Executa uma escrita no 5c_escalas e devolve as linhas afetadas.
function escW(string $sql, array $params = []): int {
    escConferirEscritaEscalas($sql);
    $stmt = escDbW()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

function escUltimoId(): int {
    return (int) escDbW()->lastInsertId();
}

// Lê do 5c_escalas pela conexão de gravação (para ver o que acabou de ser
// gravado dentro da mesma transação).
function escQW(string $sql, array $params = []): array {
    escConferirSomenteLeitura($sql);
    preg_match_all('~`([^`]+)`\s*\.\s*`~', $sql, $m);
    foreach ($m[1] as $schema) {
        if ($schema !== ESC_SCHEMA) {
            throw new RuntimeException('Bloqueado: leitura pela conexão de gravação só no ' . ESC_SCHEMA . '.');
        }
    }
    $stmt = escDbW()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function escTx(callable $fn) {
    $pdo = escDbW();
    $pdo->beginTransaction();
    try {
        $r = $fn();
        $pdo->commit();
        return $r;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// INSERT em lotes: tabela sem schema (o schema é sempre 5c_escalas).
function escInserirLote(string $tabela, array $colunas, array $linhas, int $porLote = 400): int {
    if (!$linhas || !preg_match('~^[a-z_]+$~', $tabela)) {
        return 0;
    }
    $total = 0;
    $marcas = '(' . implode(',', array_fill(0, count($colunas), '?')) . ')';
    $cols = implode(', ', array_map(static fn($c) => '`' . $c . '`', $colunas));
    foreach (array_chunk($linhas, $porLote) as $lote) {
        $params = [];
        foreach ($lote as $l) {
            foreach ($colunas as $c) {
                $params[] = $l[$c] ?? null;
            }
        }
        $sql = 'INSERT INTO `' . ESC_SCHEMA . '`.`' . $tabela . '` (' . $cols . ') VALUES '
            . implode(',', array_fill(0, count($lote), $marcas));
        $total += escW($sql, $params);
    }
    return $total;
}

// Tabela do 5c_escalas já qualificada, para as consultas de leitura.
function escT(string $tabela): string {
    return '`' . ESC_SCHEMA . '`.`' . $tabela . '`';
}
