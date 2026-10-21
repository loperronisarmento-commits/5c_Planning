<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/db.php
// Conexão SOMENTE LEITURA do 5C Escalas.
//
// Por que uma conexão própria (e não o $pdo de config/database.php):
// a sessão aqui é colocada em READ ONLY. Se fosse o $pdo compartilhado e
// ele fosse persistente, essa trava poderia vazar para outros módulos no
// mesmo processo do Apache. Usa as MESMAS variáveis do .env da
// plataforma (via env()), então nenhuma credencial fica neste módulo.
//
// Três travas contra gravação:
// 1) SET SESSION TRANSACTION READ ONLY: o MySQL recusa INSERT/UPDATE/
//    DELETE/DDL nesta conexão (erro 1792).
// 2) escConferirSomenteLeitura(): só deixa passar SELECT/WITH, sem ";"
//    e sem palavras de escrita (INSERT, UPDATE, CALL, INTO...).
// 3) Nenhum arquivo do módulo monta SQL de escrita.
// ===============================================

require_once SHARED_ROOT . 'config/env.php';

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

function escDb(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = (string) env('DB_HOST');
    $porta = (string) env('DB_PORT', '3306');
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

    $pdo = new PDO($dsn, (string) env('DB_USER'), (string) env('DB_PASS'), $opcoes);
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->exec('SET SESSION max_execution_time = ' . (int) ESC_MAX_EXECUCAO_MS);
    return $pdo;
}

function escConferirSomenteLeitura(string $sql): void {
    // Tira textos entre aspas e nomes entre crases antes de olhar as
    // palavras, para "updated_at" ou 'Delete' num texto não confundirem.
    $semTexto = preg_replace("~'(?:[^'\\\\]|\\\\.)*'~s", "''", $sql);
    $semTexto = preg_replace('~`[^`]*`~', '``', $semTexto);
    $semTexto = preg_replace('~/\*.*?\*/|--[^\n]*~s', ' ', $semTexto);

    if (!preg_match('~^\s*\(?\s*(SELECT|WITH)\b~i', $semTexto)) {
        throw new RuntimeException('Bloqueado: nesta fase só consultas SELECT/WITH.');
    }
    if (strpos($semTexto, ';') !== false) {
        throw new RuntimeException('Bloqueado: uma consulta por vez, sem ";".');
    }
    if (preg_match('~\b(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|CREATE|TRUNCATE|GRANT|REVOKE|CALL|LOAD|RENAME|LOCK|UNLOCK|HANDLER|INTO|OUTFILE|DUMPFILE)\b~i', $semTexto, $m)) {
        throw new RuntimeException('Bloqueado: palavra de escrita na consulta (' . strtoupper($m[1]) . ').');
    }
}

// Executa uma consulta de leitura e devolve todas as linhas.
function escQ(string $sql, array $params = []): array {
    escConferirSomenteLeitura($sql);
    $stmt = escDb()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Uma linha só (ou null).
function escQ1(string $sql, array $params = []): ?array {
    $linhas = escQ($sql, $params);
    return $linhas[0] ?? null;
}

// Lista de "?" para IN (...).
function escIn(array $valores): string {
    return implode(',', array_fill(0, max(1, count($valores)), '?'));
}
