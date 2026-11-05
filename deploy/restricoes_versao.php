<?php
// ===============================================
// ARQUIVO: deploy/restricoes_versao.php
// Roda no servidor (CLI) o registro da versão de hoje das Restrições dos OM (U1, U2, U3 e todas),
// o mesmo que o Gerar faz antes de gerar (escRestricoesRegistrarVersoes), e lista as versões
// guardadas de cada documento.
//   phpdbg -qrr restricoes_versao.php <pasta do módulo>
// ===============================================

$modulo = $argv[1] ?? '';
if (!is_dir($modulo)) {
    fwrite(STDERR, "uso: php restricoes_versao.php <pasta do módulo>\n");
    exit(1);
}
chdir($modulo);
define('APP_RESPONSE_FORMAT', 'json');
foreach (['config/logger.php', 'config/config.php'] as $f) {
    require_once $modulo . '/' . $f;
}
require_once SHARED_ROOT . 'helpers/auth.php';
foreach (['db', 'i18n', 'helpers', 'acesso', 'dados', 'motor', 'motor_v4', 'merito', 'tags', 'lideranca', 'documentos'] as $f) {
    require_once $modulo . '/lib/' . $f . '.php';
}

escRestricoesRegistrarVersoes();
foreach (['U1', 'U2', 'U3', 'todas'] as $esc) {
    $v = escDocVersoes('restricoes', $esc);
    $atual = $v[0] ?? null;
    echo str_pad($esc, 6) . ' ' . count($v) . ' versões'
        . ($atual ? ' · atual v' . $atual['versao'] . ' desde ' . $atual['desde'] . ($atual['guardado'] ? ' (guardada)' : ' (sem conteúdo)') : '') . "\n";
}
