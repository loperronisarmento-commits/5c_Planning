<?php
// ===============================================
// ARQUIVO: deploy/recalcular_semana.php
// Roda no servidor (CLI) o mesmo "Recalcular tudo (mantém os ajustes travados)" da tela Gerar escala,
// sem a etapa do Capacity (escGerarEscala('recalcular', ...) de lib/gerar_etapas.php).
//   phpdbg -qrr recalcular_semana.php <pasta do módulo> <segunda AAAA-MM-DD>
// Imprime o id e a versão do plano novo.
// ===============================================

[$modulo, $seg] = array_pad(array_slice($argv, 1), 2, '');
if (!is_dir($modulo) || !preg_match('~^\d{4}-\d{2}-\d{2}$~', $seg)) {
    fwrite(STDERR, "uso: php recalcular_semana.php <pasta do módulo> <AAAA-MM-DD>\n");
    exit(1);
}
chdir($modulo);
define('APP_RESPONSE_FORMAT', 'json');
foreach (['config/logger.php', 'config/config.php'] as $f) {
    require_once $modulo . '/' . $f;
}
require_once SHARED_ROOT . 'helpers/auth.php';
foreach (['db', 'i18n', 'helpers', 'acesso', 'dados', 'motor', 'motor_v4', 'merito', 'tags', 'saida', 'equipes', 'estrutura', 'minimo', 'lideranca', 'versao', 'versao_en',
          'documentos', 'treino', 'ajuda', 'solicitacao', 'roster', 'funil_sugestao', 'politica', 'equilibrio', 'treinamento', 'casais', 'gerar_etapas'] as $f) {
    require_once $modulo . '/lib/' . $f . '.php';
}
if (session_status() === PHP_SESSION_NONE) {
    $_SESSION = [];
}
$antes = escPlanoAtual($seg);
echo 'antes: plano ' . ($antes['id'] ?? '-') . ' ' . ($antes ? escPlanoRotulo($antes) : '') . "\n";
$r = escGerarEscala('recalcular', $seg, '', false);
$novo = escPlanoAtual($seg);
echo 'novo: plano ' . $r['plano'] . ' ' . ($novo ? escPlanoRotulo($novo) . ' ' . $novo['status'] : '') . "\n";
echo strip_tags($r['msg']) . "\n";
