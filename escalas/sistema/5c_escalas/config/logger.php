<?php
// ===============================================
// ARQUIVO: 5c_escalas/config/logger.php
// Registra erros, eventos e avisos em logs/app.log — pra achar a causa
// de um problema sem precisar de screenshot de tela de erro genérica.
//
// TEM QUE SER O PRIMEIRO require de qualquer arquivo (antes até de
// config.php) — assim pega erro em QUALQUER etapa depois dele,
// incluindo se config.php ou algum arquivo compartilhado falhar.
//
// Formato de cada linha do log:
// [2026-08-15 17:00:00] [NIVEL] mensagem {"contexto":"em json"}
//
// Níveis usados: event (fluxo normal), warning (algo estranho mas não
// quebrou), error (falhou uma etapa específica, com try/catch),
// fatal / fatal_shutdown (o request inteiro quebrou).
// ===============================================

if (!defined('LOG_DIR')) {
    define('LOG_DIR', __DIR__ . '/../logs/');
    define('LOG_FILE', LOG_DIR . 'app.log');
    define('LOG_MAX_BYTES', 5 * 1024 * 1024); // 5 MB — depois disso, rotaciona
}

if (!is_dir(LOG_DIR)) {
    @mkdir(LOG_DIR, 0755, true);
}

if (!function_exists('appLogTimestamp')) {
    // Horário explícito de Brasília pro log, sem mexer no timezone
    // global do PHP pra requisição inteira (isso poderia afetar algo
    // do código compartilhado da plataforma que eu não vejo) — só
    // formata ESTE timestamp com o timezone certo.
    function appLogTimestamp(): string {
        try {
            return (new \DateTime('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return date('Y-m-d H:i:s'); // fallback se o timezone não existir no sistema por algum motivo
        }
    }
}

if (!function_exists('appLog')) {
    function appLog(string $nivel, string $mensagem, array $contexto = []): void {
        // rotação simples: se o log já está grande, arquiva e começa de novo
        if (is_file(LOG_FILE) && filesize(LOG_FILE) > LOG_MAX_BYTES) {
            @rename(LOG_FILE, LOG_DIR . 'app.log.' . date('Ymd_His') . '.old');
        }

        $linha = sprintf(
            "[%s] [%s] %s%s\n",
            appLogTimestamp(),
            strtoupper($nivel),
            $mensagem,
            $contexto ? ' ' . json_encode($contexto, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : ''
        );
        @file_put_contents(LOG_FILE, $linha, FILE_APPEND | LOCK_EX);
    }
}

// Captura warnings/notices/deprecated do PHP (não impede a execução,
// só registra) — devolve false pra deixar o comportamento normal do
// PHP também continuar acontecendo (log do Apache etc.).
if (!function_exists('appLogErrorHandler')) {
    function appLogErrorHandler($nivel, $mensagem, $arquivo, $linha) {
        if (!(error_reporting() & $nivel)) {
            return false; // este tipo de erro está suprimido (@) — não loga
        }
        appLog('php_warning', $mensagem, ['arquivo' => $arquivo, 'linha' => $linha]);
        return false;
    }
}
set_error_handler('appLogErrorHandler');

// Captura Exception/Error não tratados em nenhum try/catch — é o que
// mais comumente vira uma tela de "500" sem explicação nenhuma.
// APP_RESPONSE_FORMAT ('json' ou 'html') deve ser definido pelo
// arquivo que chama, ANTES de dar require neste logger.php — se não
// for definido, assume 'html' (mais seguro pra uma página, já que
// JSON cru numa tela normal é pior do que HTML simples numa API).
if (!function_exists('appLogExceptionHandler')) {
    function appLogExceptionHandler(\Throwable $e): void {
        appLog('fatal', $e->getMessage(), [
            'tipo' => get_class($e),
            'arquivo' => $e->getFile(),
            'linha' => $e->getLine(),
        ]);
        if (!headers_sent()) {
            http_response_code(500);
        }
        $formato = defined('APP_RESPONSE_FORMAT') ? APP_RESPONSE_FORMAT : 'html';
        if ($formato === 'json') {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'success' => false,
                'message' => 'Erro interno. Detalhes em logs/app.log.',
            ]);
        } else {
            if (!headers_sent()) {
                header('Content-Type: text/html; charset=utf-8');
            }
            echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">'
                . '<title>Erro — 5C Escalas</title></head>'
                . '<body style="background:#000;color:#f5f5f7;font-family:-apple-system,sans-serif;'
                . 'display:flex;align-items:center;justify-content:center;height:100vh;margin:0;">'
                . '<div style="text-align:center;max-width:420px;padding:0 20px;">'
                . '<h1 style="margin:0 0 8px;">Erro interno</h1>'
                . '<p style="color:#9ca0ab;margin:0;">Alguma coisa quebrou inesperadamente. '
                . 'Já foi registrado — tente novamente em instantes.</p>'
                . '</div></body></html>';
        }
    }
}
set_exception_handler('appLogExceptionHandler');

// Rede de segurança final: erro fatal (parse error, memória etc.) que
// nem o exception handler acima consegue pegar.
if (!function_exists('appLogShutdownHandler')) {
    function appLogShutdownHandler(): void {
        $erro = error_get_last();
        if ($erro && in_array($erro['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            appLog('fatal_shutdown', $erro['message'], [
                'arquivo' => $erro['file'],
                'linha' => $erro['line'],
            ]);
        }
    }
}
register_shutdown_function('appLogShutdownHandler');
