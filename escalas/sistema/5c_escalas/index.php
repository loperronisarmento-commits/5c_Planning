<?php
// ===============================================
// ARQUIVO: 5c_escalas/index.php
// 5C Escalas. Ponto de entrada único: index.php?p=<tela>.
//
// Autorização em dois níveis, como os outros módulos:
// 1) logado no MIS Portal (sessão da plataforma);
// 2) cadastrado em 5c_escalas.esc_acesso (perfil por tela/ação).
//
// Gravação: só no schema 5c_escalas (ver lib/db.php). As outras bases
// continuam só sendo lidas.
// Formulários: POST com token (CSRF); depois de gravar, volta para a
// tela com o aviso (?ok=...). Downloads: ?baixar=... antes do HTML.
// ===============================================

define('APP_RESPONSE_FORMAT', 'html');
require_once __DIR__ . '/config/logger.php';
require_once __DIR__ . '/config/config.php';
require_once SHARED_ROOT . 'helpers/auth.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/dados.php';
require_once __DIR__ . '/lib/motor.php';
require_once __DIR__ . '/lib/motor_v4.php';

// ---------- nível 1: logado no MIS Portal? ----------
startSecureSession();
if (empty($_SESSION['user_id'])) {
    header('Location: /login');
    exit;
}
$userId = (int) $_SESSION['user_id'];
if (empty($_SESSION['esc_csrf'])) {
    $_SESSION['esc_csrf'] = bin2hex(random_bytes(16));
}
$GLOBALS['ESC_CSRF'] = $_SESSION['esc_csrf'];
// Libera o lock da sessão: as consultas podem levar alguns segundos e
// não devem travar outras abas do portal.
session_write_close();

$telas = [
    'painel'       => ['Painel', 'pages/painel.php'],
    'gerar'        => ['Gerar escala', 'pages/gerar.php'],
    'grade'        => ['Grade da escala', 'pages/grade.php'],
    'tertius'      => ['Equilíbrio Tertius', 'pages/tertius.php'],
    'sugestoes'    => ['Sugestões de troca', 'pages/sugestoes.php'],
    'buffers'      => ['Buffers', 'pages/buffers.php'],
    'trocas'       => ['Trocas de página', 'pages/trocas.php'],
    'saidas'       => ['Saídas de página', 'pages/saidas.php'],
    'funil'        => ['Funil de entrada', 'pages/funil.php'],
    'times'        => ['Configuração dos times', 'pages/times.php'],
    'colaboradores'=> ['Colaboradores', 'pages/colaboradores.php'],
    'modelos'      => ['Modelos de escala', 'pages/modelos.php'],
    'aprovar'      => ['Indisponibilidade', 'pages/aprovar.php'],
    'acessos'      => ['Acessos', 'pages/acessos.php'],
    'parametros'   => ['Parâmetros', 'pages/parametros.php'],
    'diagnostico'  => ['Diagnóstico', 'pages/diagnostico.php'],
];
$p = $_GET['p'] ?? 'painel';
if (!isset($telas[$p])) {
    $p = 'painel';
}

// ---------- nível 2: cadastrado no módulo? ----------
$erroBanco = null;
$usuario = null;
if (!checarMysqlSaudavel((string) env('DB_HOST'), (int) env('DB_PORT', '3306'), 3)) {
    $erroBanco = 'Não foi possível alcançar o banco de dados agora. Tente novamente em instantes.';
} else {
    try {
        $usuario = escCarregarPerfil($userId);
    } catch (Throwable $e) {
        appLog('error', 'Falha ao checar acesso', ['erro' => $e->getMessage()]);
        $erroBanco = 'Não foi possível verificar suas permissões agora. Confira se o schema 5c_escalas foi criado (ES_01) e a permissão dada (ES_03).';
    }
}
if ($erroBanco !== null || $usuario === null) {
    http_response_code($erroBanco !== null ? 503 : 403);
    $tituloBloqueio = $erroBanco !== null ? 'Banco indisponível' : 'Acesso restrito';
    $textoBloqueio = $erroBanco ?? 'Você está logado, mas ainda não foi liberado para o 5C Escalas. Peça a um administrador (Administração › Acessos). Seu user_id: ' . $userId . '.';
    ?><!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($tituloBloqueio); ?> — 5C Escalas</title>
<link rel="stylesheet" href="<?php echo h(MODULE_URL); ?>/assets/escalas.css?v=<?php echo @filemtime(__DIR__ . '/assets/escalas.css') ?: time(); ?>">
</head><body class="bloqueio"><div class="panel" style="max-width:480px;text-align:center">
<h1><?php echo h($tituloBloqueio); ?></h1><p class="muted"><?php echo h($textoBloqueio); ?></p>
</div></body></html><?php
    exit;
}
$GLOBALS['ESC_USUARIO'] = $usuario;

// ---------- POST e downloads (antes de qualquer HTML) ----------
if (escPost() || isset($_GET['baixar'])) {
    try {
        if (escPost()) {
            escConferirCsrf();
        }
        require __DIR__ . '/' . $telas[$p][1];
    } catch (Throwable $e) {
        appLog('error', 'Erro em ação', ['p' => $p, 'erro' => $e->getMessage(), 'linha' => $e->getLine(), 'arquivo' => basename($e->getFile())]);
        $msg = $e instanceof RuntimeException ? $e->getMessage() : 'Não deu para concluir. Detalhes em logs/app.log.';
        $volta = array_intersect_key($_GET, array_flip(['semana', 'plano', 'time', 'turno', 'status', 'corte']));
        escVoltar($p, $volta, $msg, true);
    }
    exit;
}

appLog('event', 'tela aberta', ['p' => $p, 'user_id' => $userId]);

// ---------- conteúdo da tela ----------
ob_start();
try {
    require __DIR__ . '/' . $telas[$p][1];
} catch (Throwable $e) {
    appLog('error', 'Erro na tela', ['p' => $p, 'erro' => $e->getMessage(), 'linha' => $e->getLine(), 'arquivo' => basename($e->getFile())]);
    echo '<section class="panel erro"><h2>Não deu para carregar esta tela</h2><p class="muted">'
        . h($e instanceof RuntimeException ? $e->getMessage() : 'Erro ao consultar o banco. Detalhes em logs/app.log.')
        . '</p></section>';
}
$conteudo = ob_get_clean();

$menu = [
    'Planejamento' => [['painel', 'Painel'], ['gerar', 'Gerar escala'], ['grade', 'Grade da escala'], ['tertius', 'Equilíbrio Tertius'], ['sugestoes', 'Sugestões de troca'], ['buffers', 'Buffers']],
    'Páginas' => [['trocas', 'Trocas de página'], ['saidas', 'Saídas de página'], ['funil', 'Funil de entrada']],
    'Cadastros' => [['times', 'Configuração dos times'], ['colaboradores', 'Colaboradores'], ['modelos', 'Modelos de escala']],
    'Indisponibilidade' => [['aprovar', 'Pedidos e aprovação']],
];
$adminMenu = [['acessos', 'Acessos'], ['parametros', 'Parâmetros'], ['diagnostico', 'Diagnóstico']];
$badges = [];
try {
    $b = escQ1('SELECT (SELECT COUNT(*) FROM ' . escT('esc_indisponibilidade') . " WHERE status = 'PENDENTE') AS indisp,
                       (SELECT COUNT(*) FROM " . escT('esc_sugestao') . " s JOIN " . escT('esc_plano') . " p ON p.id = s.plano_id WHERE s.status = 'ABERTA' AND p.status IN ('RASCUNHO','PUBLICADO') AND p.semana_fim >= CURDATE()) AS sug");
    $badges = ['aprovar' => (int) ($b['indisp'] ?? 0), 'sugestoes' => (int) ($b['sug'] ?? 0)];
} catch (Throwable $e) {
    $badges = [];
}
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
  <div class="marca"><span class="eyebrow">MIS Portal</span><strong>5C Escalas</strong><span class="tag-teste">perfil <?php echo h(strtolower($usuario['perfil'])); ?></span></div>
  <?php foreach ($menu as $secao => $itens): ?>
  <div class="grupo"><span class="grupo-titulo"><?php echo h($secao); ?></span>
    <?php foreach ($itens as $it): ?>
    <a href="<?php echo h(link_tela($it[0])); ?>" class="<?php echo $p === $it[0] ? 'ativo' : ''; ?>"><?php echo h($it[1]); ?><?php if (!empty($badges[$it[0]])): ?> <span class="badge"><?php echo (int) $badges[$it[0]]; ?></span><?php endif; ?></a>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
  <div class="grupo"><span class="grupo-titulo">Monitoramento</span>
    <a href="<?php echo h(ESC_URL_ANOMALIAS); ?>">Anomalias da escala ↗</a>
    <a href="<?php echo h(ESC_URL_AUDITORIA); ?>">Auditoria de acesso ↗</a>
  </div>
  <div class="grupo"><span class="grupo-titulo">Administração</span>
    <?php foreach ($adminMenu as $it): ?>
    <a href="<?php echo h(link_tela($it[0])); ?>" class="<?php echo $p === $it[0] ? 'ativo' : ''; ?>"><?php echo h($it[1]); ?></a>
    <?php endforeach; ?>
  </div>
</nav>
<main class="conteudo">
  <?php echo avisos(); ?>
  <?php echo $conteudo; ?>
</main>
</div>
<script src="<?php echo h(MODULE_URL); ?>/assets/escalas.js?v=<?php echo @filemtime(__DIR__ . '/assets/escalas.js') ?: time(); ?>"></script>
</body>
</html>
