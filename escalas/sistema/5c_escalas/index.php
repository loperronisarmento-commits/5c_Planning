<?php
// ===============================================
// ARQUIVO: 5c_escalas/index.php
// 5C Escalas — versão de TESTE, somente leitura.
// Ponto de entrada único: index.php?p=<tela>.
//
// Autorização em dois níveis, como os outros módulos:
// 1) logado no MIS Portal (sessão da plataforma);
// 2) aprovado no módulo (nesta fase, a mesma lista do Schedule
//    Anomalies — ver ESC_TABELA_ACESSO em config/config.php).
// ===============================================

define('APP_RESPONSE_FORMAT', 'html');
require_once __DIR__ . '/config/logger.php';
require_once __DIR__ . '/config/config.php';
require_once SHARED_ROOT . 'helpers/auth.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/dados.php';

// ---------- nível 1: logado no MIS Portal? ----------
startSecureSession();
if (empty($_SESSION['user_id'])) {
    header('Location: /login');
    exit;
}
$userId = (int) $_SESSION['user_id'];
// Libera o lock da sessão: as consultas podem levar alguns segundos e
// não devem travar outras abas do portal.
session_write_close();

$telas = [
    'painel'      => ['Painel', 'pages/painel.php'],
    'times'       => ['Configuração dos times', 'pages/times.php'],
    'trocas'      => ['Trocas de página', 'pages/trocas.php'],
    'saidas'      => ['Saídas de página', 'pages/saidas.php'],
    'funil'       => ['Funil de entrada', 'pages/funil.php'],
    'diagnostico' => ['Diagnóstico', 'pages/diagnostico.php'],
];
$p = $_GET['p'] ?? 'painel';
if (!isset($telas[$p])) {
    $p = 'painel';
}

// ---------- nível 2: aprovado no módulo? ----------
$erroBanco = null;
$autorizado = false;
if (!checarMysqlSaudavel((string) env('DB_HOST'), (int) env('DB_PORT', '3306'), 3)) {
    $erroBanco = 'Não foi possível alcançar o banco de dados agora. Tente novamente em instantes.';
} else {
    try {
        $autorizado = escQ1('SELECT 1 AS ok FROM ' . ESC_TABELA_ACESSO . ' WHERE user_id = ? LIMIT 1', [$userId]) !== null;
    } catch (Throwable $e) {
        appLog('error', 'Falha ao checar acesso', ['erro' => $e->getMessage()]);
        $erroBanco = 'Não foi possível verificar suas permissões agora.';
    }
}
if ($erroBanco !== null || !$autorizado) {
    http_response_code($erroBanco !== null ? 503 : 403);
    $tituloBloqueio = $erroBanco !== null ? 'Banco indisponível' : 'Acesso restrito';
    $textoBloqueio = $erroBanco ?? 'Você está logado, mas ainda não foi liberado para o 5C Escalas. Nesta fase de teste valem os mesmos acessos do Schedule Anomalies.';
    ?><!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($tituloBloqueio); ?> — 5C Escalas</title>
<link rel="stylesheet" href="<?php echo h(MODULE_URL); ?>/assets/escalas.css?v=<?php echo @filemtime(__DIR__ . '/assets/escalas.css') ?: time(); ?>">
</head><body class="bloqueio"><div class="panel" style="max-width:480px;text-align:center">
<h1><?php echo h($tituloBloqueio); ?></h1><p class="muted"><?php echo h($textoBloqueio); ?></p>
</div></body></html><?php
    exit;
}

// ---------- downloads (antes de qualquer HTML) ----------
if ($p === 'trocas' && ($_GET['baixar'] ?? '') === 'csv') {
    require __DIR__ . '/pages/trocas.php';
    exit;
}

appLog('event', 'tela aberta', ['p' => $p, 'user_id' => $userId]);

// ---------- conteúdo da tela ----------
ob_start();
try {
    require __DIR__ . '/' . $telas[$p][1];
} catch (Throwable $e) {
    appLog('error', 'Erro na tela', ['p' => $p, 'erro' => $e->getMessage(), 'linha' => $e->getLine()]);
    echo '<section class="panel erro"><h2>Não deu para carregar esta tela</h2><p class="muted">'
        . h($e instanceof RuntimeException && strpos($e->getMessage(), 'Bloqueado') === 0 ? $e->getMessage() : 'Erro ao consultar o banco. Detalhes em logs/app.log.')
        . '</p></section>';
}
$conteudo = ob_get_clean();

$menu = [
    'Planejamento' => [['painel', 'Painel']],
    'Páginas' => [['trocas', 'Trocas de página'], ['saidas', 'Saídas de página'], ['funil', 'Funil de entrada']],
    'Cadastros' => [['times', 'Configuração dos times']],
];
$emBreve = ['Gerar escala', 'Grade da escala', 'Equilíbrio Tertius', 'Sugestões de troca', 'Buffers', 'Colaboradores', 'Modelos de escala', 'Aprovar indisponibilidade'];
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($telas[$p][0]); ?> — 5C Escalas</title>
<link rel="stylesheet" href="<?php echo h(MODULE_URL); ?>/assets/escalas.css?v=<?php echo @filemtime(__DIR__ . '/assets/escalas.css') ?: time(); ?>">
</head>
<body>
<div class="app">
<nav class="menu" aria-label="Menu do sistema">
  <div class="marca"><span class="eyebrow">MIS Portal</span><strong>5C Escalas</strong><span class="tag-teste">teste · só leitura</span></div>
  <?php foreach ($menu as $secao => $itens): ?>
  <div class="grupo"><span class="grupo-titulo"><?php echo h($secao); ?></span>
    <?php foreach ($itens as $it): ?>
    <a href="<?php echo h(link_tela($it[0])); ?>" class="<?php echo $p === $it[0] ? 'ativo' : ''; ?>"><?php echo h($it[1]); ?></a>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
  <div class="grupo"><span class="grupo-titulo">Monitoramento</span>
    <a href="<?php echo h(ESC_URL_ANOMALIAS); ?>">Anomalias da escala ↗</a>
    <a href="<?php echo h(ESC_URL_AUDITORIA); ?>">Auditoria de acesso ↗</a>
  </div>
  <div class="grupo"><span class="grupo-titulo">Administração</span>
    <a href="<?php echo h(link_tela('diagnostico')); ?>" class="<?php echo $p === 'diagnostico' ? 'ativo' : ''; ?>">Diagnóstico</a>
  </div>
  <div class="grupo"><span class="grupo-titulo">Em breve</span>
    <?php foreach ($emBreve as $nome): ?><span class="desligado" title="Ainda não ligado nesta versão de teste. Veja no rascunho."><?php echo h($nome); ?></span><?php endforeach; ?>
    <a href="<?php echo h(ESC_URL_RASCUNHO); ?>" target="_blank" rel="noopener">Ver rascunho das telas ↗</a>
  </div>
</nav>
<main class="conteudo">
  <div class="faixa-teste" role="note">Versão de teste: <strong>somente leitura</strong>. Nada é gravado no banco; botões de ação ficam desligados.</div>
  <?php echo $conteudo; ?>
</main>
</div>
<script src="<?php echo h(MODULE_URL); ?>/assets/escalas.js?v=<?php echo @filemtime(__DIR__ . '/assets/escalas.js') ?: time(); ?>"></script>
</body>
</html>
