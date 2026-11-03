<?php
// ===============================================
// ARQUIVO: deploy/merito_recalcular.php
// Roda no servidor (CLI) o recálculo do cache do mérito (esc_sr_merito), com o usuário do portal,
// e mostra as faixas e quantos SRs têm cada componente diferente de 0. Um componente com 0 em
// todos os SRs indica que o portal não leu a tabela dele (o erro vai para o log do módulo).
//   phpdbg -qrr merito_recalcular.php <pasta do módulo>
// ===============================================

$modulo = $argv[1] ?? '';
if (!is_dir($modulo)) {
    fwrite(STDERR, "uso: php merito_recalcular.php <pasta do módulo>\n");
    exit(1);
}
chdir($modulo);
define('APP_RESPONSE_FORMAT', 'json');
foreach (['config/logger.php', 'config/config.php'] as $f) {
    require_once $modulo . '/' . $f;
}
require_once SHARED_ROOT . 'helpers/auth.php';
foreach (['db', 'i18n', 'helpers', 'acesso', 'dados', 'motor', 'motor_v4', 'merito'] as $f) {
    require_once $modulo . '/lib/' . $f . '.php';
}

$m = escMeritoCarregar(true);
$faixas = array_count_values(array_map(static fn($r) => (string) ($r['faixa'] ?? '-'), $m));
ksort($faixas);
echo count($m) . ' SRs no cache · ' . implode(' · ', array_map(static fn($k, $v) => "$k $v", array_keys($faixas), $faixas)) . "\n";
foreach (['pts_tier', 'pts_meta', 'pts_faltas', 'pts_sla', 'pts_casa', 'pts_vendas', 'pts_cargo', 'pts_infracao', 'pts_seq'] as $c) {
    $n = count(array_filter($m, static fn($r) => (int) ($r[$c] ?? 0) !== 0));
    echo str_pad($c, 13) . " $n SRs com pontos\n";
}
