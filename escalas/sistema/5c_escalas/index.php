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
// Todas as datas e horas do planejamento são de Brasília (virada às 21:00, "hoje", semana atual).
date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/lib/db.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    escConexaoUnica();
}
require_once __DIR__ . '/lib/i18n.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/dados.php';
require_once __DIR__ . '/lib/motor.php';
require_once __DIR__ . '/lib/motor_v4.php';
require_once __DIR__ . '/lib/merito.php';
require_once __DIR__ . '/lib/saida.php';
require_once __DIR__ . '/lib/equipes.php';
require_once __DIR__ . '/lib/estrutura.php';
require_once __DIR__ . '/lib/minimo.php';
require_once __DIR__ . '/lib/lideranca.php';
require_once __DIR__ . '/lib/versao.php';
require_once __DIR__ . '/lib/versao_en.php';
require_once __DIR__ . '/lib/treino.php';
require_once __DIR__ . '/lib/ajuda.php';
require_once __DIR__ . '/lib/solicitacao.php';
require_once __DIR__ . '/lib/roster.php';
require_once __DIR__ . '/lib/funil_sugestao.php';
require_once __DIR__ . '/lib/politica.php';
require_once __DIR__ . '/lib/equilibrio.php';
require_once __DIR__ . '/lib/treinamento.php';
require_once __DIR__ . '/lib/casais.php';

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
// Tema escolhido (cookie gravado pelo escalas.js): o servidor já manda certo, sem piscar.
$tema = in_array($_COOKIE['esc_tema'] ?? '', ['claro', 'escuro', 'auto'], true) ? $_COOKIE['esc_tema'] : 'auto';
// Libera o lock da sessão: as consultas podem levar alguns segundos e
// não devem travar outras abas do portal.
session_write_close();

$telas = [
    'painel'       => ['Painel', 'pages/painel.php'],
    'gerar'        => ['Gerar escala', 'pages/gerar.php'],
    'grade'        => ['Grade da escala', 'pages/grade.php'],
    'lideranca'    => ['Escala da liderança', 'pages/lideranca.php'],
    'horas'        => ['Horas extras', 'pages/horas.php'],
    'analise'      => ['Análise', 'pages/analise.php'],
    'tertius'      => ['Equilíbrio dos SRs', 'pages/tertius.php'],
    'sugestoes'    => ['Sugestões de troca', 'pages/sugestoes.php'],
    'buffers'      => ['Buffers', 'pages/buffers.php'],
    'trocas'       => ['Trocas de página', 'pages/trocas.php'],
    'saidas'       => ['Saídas de página', 'pages/saidas.php'],
    'funil'        => ['Funil de entrada', 'pages/funil.php'],
    'equilibrio'   => ['Equilíbrio das unidades', 'pages/equilibrio.php'],
    'owners'       => ['Owners e backups', 'pages/owners.php'],
    'organograma'  => ['Organograma', 'pages/organograma.php'],
    'times'        => ['Configuração dos times', 'pages/times.php'],
    'colaboradores'=> ['Colaboradores', 'pages/colaboradores.php'],
    'modelos'      => ['Modelos de escala', 'pages/modelos.php'],
    'aprovar'      => ['Indisponibilidade', 'pages/aprovar.php'],
    'solicitacoes' => ['Solicitações', 'pages/solicitacoes.php'],
    'roster'       => ['Roster da liderança', 'pages/roster.php'],
    'acessos'      => ['Acessos', 'pages/acessos.php'],
    'parametros'   => ['Parâmetros', 'pages/parametros.php'],
    'diagnostico'  => ['Diagnóstico', 'pages/diagnostico.php'],
    'novidades'    => ['Changelog', 'pages/novidades.php'],
    'glossario'    => ['Glossário', 'pages/ajuda.php'],
    'faq'          => ['FAQ', 'pages/ajuda.php'],
    'tutorial'     => ['Tutorial', 'pages/tutorial.php'],
    'regras'       => ['Regras', 'pages/regras.php'],
    'merito'       => ['Mérito', 'pages/merito.php'],
    'treinamento'  => ['Treinamento', 'pages/treinamento.php'],
    'casais'       => ['Folga juntos', 'pages/casais.php'],
    'dba'          => ['Documentação do DBA', 'pages/dba.php'],
];
$p = $_GET['p'] ?? 'painel';
if (!isset($telas[$p])) {
    $p = 'painel';
}

// ---------- nível 2: cadastrado no módulo? ----------
$erroBanco = null;
$usuario = null;
if (!checarMysqlSaudavel((string) env('DB_HOST'), (int) env('DB_PORT', '3306'), 3)) {
    $erroBanco = t('Não foi possível alcançar o banco de dados agora. Tente novamente em instantes.');
} else {
    try {
        $usuario = escCarregarPerfil($userId);
    } catch (Throwable $e) {
        appLog('error', 'Falha ao checar acesso', ['erro' => $e->getMessage()]);
        $erroBanco = t('Não foi possível verificar suas permissões agora. Confira se o schema 5c_escalas foi criado (ES_01) e a permissão dada (ES_03).');
    }
}
if ($erroBanco !== null || $usuario === null) {
    http_response_code($erroBanco !== null ? 503 : 403);
    $tituloBloqueio = $erroBanco !== null ? t('Banco indisponível') : t('Acesso restrito');
    $textoBloqueio = $erroBanco ?? t('Você está logado, mas ainda não foi liberado para o 5C Escalas. Peça a um administrador (Administração › Acessos). Seu user_id: {id}.', ['id' => $userId]);
    ?><!DOCTYPE html>
<html lang="<?php echo h(escIdioma()); ?>" data-tema="<?php echo h($tema); ?>"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($tituloBloqueio); ?> — 5C Escalas</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700;800&amp;display=swap">
<link rel="stylesheet" href="<?php echo h(MODULE_URL); ?>/assets/escalas.css?v=<?php echo h(ESC_VERSAO . '-' . (@filemtime(__DIR__ . '/assets/escalas.css') ?: 0)); ?>">
</head><body class="bloqueio"><div class="panel" style="max-width:480px;text-align:center">
<h1><?php echo h($tituloBloqueio); ?></h1><p class="muted"><?php echo h($textoBloqueio); ?></p>
</div></body></html><?php
    exit;
}
$GLOBALS['ESC_USUARIO'] = $usuario;

// Modo Treino: se o schema de treino não existe, desliga; no primeiro acesso, copia os cadastros.
if (escModoTreino()) {
    if (!escTreinoExiste()) {
        escTreinoLigar(false);
        header('Location: ' . link_tela('tutorial'));
        exit;
    }
    if (escTreinoVazio()) {
        try {
            escTreinoResetar();
        } catch (Throwable $e) {
            appLog('error', 'Modo Treino: primeira carga', ['erro' => $e->getMessage()]);
        }
    }
}

// Os campos de busca de colaborador mandam "Nome (CÓDIGO)": fica só o código.
$soCodigo = static function (&$v): void {
    if (is_string($v) && preg_match('~\(([A-Za-z]\d{6})\)\s*$~', $v, $m)) {
        $v = strtoupper($m[1]);
    }
};
// Só nos campos de busca de colaborador (texto livre, como comentário e motivo, fica como foi digitado).
foreach (['rep_code', 'regular', 'assistentes', 'rep', 'q', 'editar'] as $campo) {
    if (isset($_POST[$campo])) {
        is_array($_POST[$campo]) ? array_walk_recursive($_POST[$campo], $soCodigo) : $soCodigo($_POST[$campo]);
    }
    if (isset($_GET[$campo])) {
        is_array($_GET[$campo]) ? array_walk_recursive($_GET[$campo], $soCodigo) : $soCodigo($_GET[$campo]);
    }
}

// As telas usam $p nos próprios laços (ex.: foreach ($pedidos as $p)): guarda qual tela é.
$telaAtual = $p;

// ---------- POST e downloads (antes de qualquer HTML) ----------
if (escPost() || isset($_GET['baixar'])) {
    try {
        if (escPost()) {
            escConferirCsrf();
        }
        require __DIR__ . '/' . $telas[$p][1];
    } catch (Throwable $e) {
        $p = $telaAtual;
        // Código da ocorrência: aparece para o usuário e no log, para achar o detalhe técnico.
        $ocorrencia = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        appLog('error', 'Erro em ação', ['ocorrencia' => $ocorrencia, 'p' => $p, 'erro' => $e->getMessage(), 'linha' => $e->getLine(), 'arquivo' => basename($e->getFile())]);
        $msg = $e instanceof RuntimeException ? $e->getMessage() : t('Não deu para concluir. Tente de novo; se repetir, avise o admin com o código {codigo}.', ['codigo' => $ocorrencia]);
        // Para o admin, o detalhe técnico já vai na mensagem (sem precisar abrir o log do servidor).
        if (!$e instanceof RuntimeException && escPodeReal('admin')) {
            $msg .= ' ' . t('Detalhe (só admin): {erro} ({arquivo}:{linha})', ['erro' => mb_substr($e->getMessage(), 0, 300), 'arquivo' => basename($e->getFile()), 'linha' => $e->getLine()]);
        }
        // Volta para a mesma tela, com os mesmos filtros (o formulário reabre preenchido).
        $volta = array_diff_key($_GET, array_flip(['p', 'baixar', 'ok', 'erro']));
        escVoltar($p, $volta, $msg, true);
    }
    exit;
}

appLog('event', 'tela aberta', ['p' => $p, 'user_id' => $userId]);
escFlashLer(); // antes do conteúdo: a sessão ainda não mandou nada para a tela

// ---------- conteúdo da tela ----------
ob_start();
try {
    require __DIR__ . '/' . $telas[$p][1];
} catch (Throwable $e) {
    $p = $telaAtual;
    $ocorrencia = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    appLog('error', 'Erro na tela', ['ocorrencia' => $ocorrencia, 'p' => $p, 'erro' => $e->getMessage(), 'linha' => $e->getLine(), 'arquivo' => basename($e->getFile())]);
    echo '<section class="panel erro" role="alert"><h2>' . h(t('Não deu para carregar esta tela')) . '</h2><p class="muted">'
        . h($e instanceof RuntimeException ? $e->getMessage() : t('Não deu para ler os dados agora. Tente de novo em instantes; se repetir, avise o admin com o código {codigo}.', ['codigo' => $ocorrencia]))
        . '</p><div class="acoes"><a class="botao primario" href="' . h($_SERVER['REQUEST_URI'] ?? link_tela($p)) . '">' . h(t('Tentar de novo')) . '</a><a class="botao" href="' . h(link_tela('painel')) . '">' . h(t('Ir para o Painel')) . '</a></div></section>';
}
$conteudo = ob_get_clean();
$p = $telaAtual;

// Listas de busca (só quando a tela usa): colaboradores ativos e páginas ativas.
$listas = '';
try {
    if (strpos($conteudo, 'list="lista-colaboradores"') !== false) {
        $listas .= '<datalist id="lista-colaboradores">';
        foreach (escQ("SELECT UPPER(code) AS code, name, position FROM `5c_headcount`.`headcount` WHERE active = 1 AND code IS NOT NULL ORDER BY name") as $l) {
            $listas .= '<option value="' . h(trim((string) $l['name']) . ' (' . $l['code'] . ')') . '">' . h((string) $l['position']) . '</option>';
        }
        $listas .= '</datalist>';
    }
    if (strpos($conteudo, 'list="lista-paginas"') !== false) {
        $listas .= '<datalist id="lista-paginas">';
        foreach (escQ('SELECT DISTINCT name FROM `5c_pages`.`pages` WHERE active = 1 ORDER BY name') as $l) {
            $listas .= '<option value="' . h((string) $l['name']) . '"></option>';
        }
        $listas .= '</datalist>';
    }
} catch (Throwable $e) {
    appLog('error', 'Listas de busca', ['erro' => $e->getMessage()]);
}

// Menu: o rótulo é sempre o título da tela ($telas), o mesmo na aba do navegador.
$menu = [
    'Escala' => ['painel', 'gerar', 'grade', 'lideranca', 'horas'],
    'Análise' => ['analise', 'tertius', 'sugestoes', 'buffers'],
    'Páginas' => ['owners', 'trocas', 'saidas', 'funil', 'equilibrio'],
    'Pessoas' => ['organograma', 'roster', 'colaboradores', 'aprovar', 'solicitacoes', 'treinamento', 'casais', 'modelos'],
    'Ajuda' => array_merge(['tutorial', 'regras', 'merito', 'glossario', 'faq', 'novidades'], escPode('admin') ? ['dba'] : []),
];
$adminMenu = escPode('admin') ? ['acessos', 'parametros', 'diagnostico'] : [];
// Estrutura antiga (times e parties): vale até a virada de 31/10 21:00.
$legadoMenu = ['times'];
$badges = [];
try {
    $b = escQ1('SELECT (SELECT COUNT(*) FROM ' . escT('esc_indisponibilidade') . " WHERE status = 'PENDENTE') AS indisp,
                       (SELECT COUNT(*) FROM " . escT('esc_sugestao') . " s JOIN " . escT('esc_plano') . " p ON p.id = s.plano_id WHERE s.status = 'ABERTA' AND p.status IN ('RASCUNHO','PUBLICADO') AND p.semana_fim >= CURDATE()) AS sug");
    $badges = ['aprovar' => (int) ($b['indisp'] ?? 0), 'sugestoes' => (int) ($b['sug'] ?? 0)];
    try {
        $badges['solicitacoes'] = escSolInstalada() ? (int) (escQ1('SELECT COUNT(*) AS n FROM ' . escT('esc_solicitacao') . " WHERE status = 'PENDENTE'")['n'] ?? 0) : 0;
    } catch (Throwable $e) {
        // tabela ainda não criada (ES_30)
    }
    try {
        $badges['horas'] = (int) (escQ1('SELECT COUNT(*) AS n FROM ' . escT('esc_hora_extra') . " WHERE status = 'INTERESSADO' AND dia >= CURDATE()")['n'] ?? 0);
    } catch (Throwable $e) {
        // tabela ainda não criada (ES_14)
    }
} catch (Throwable $e) {
    $badges = [];
}
// Textos dos scripts (escalas.js) no idioma da tela.
$textosJs = escEmIngles() ? (include __DIR__ . '/lang/en/js.php') : [];
// Tour guiado: os passos vão para a tela quando o tour está ligado (cookie) ou no Tutorial.
$tour = null;
if (($_COOKIE['esc_tour'] ?? '') === '1' || $p === 'tutorial') {
    $tour = ['tela' => $p, 'fim' => link_tela('tutorial'), 'treino' => escModoTreino(), 'treino_url' => link_tela('tutorial') . '#modo-treino', 'passos' => array_map(static fn($ps) => [
        'tela' => $ps['tela'], 'url' => link_tela($ps['tela']), 'alvo' => $ps['alvo'],
        'titulo' => escAjudaTexto($ps, 'titulo'), 'texto' => escAjudaTexto($ps, 'texto'),
        'plataforma' => $ps[escEmIngles() ? 'plataforma_en' : 'plataforma'], 'site' => $ps[escEmIngles() ? 'site_en' : 'site'],
    ], escTourPassos())];
}
?><!DOCTYPE html>
<html lang="<?php echo h(escIdioma()); ?>" data-tema="<?php echo h($tema); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h(t($telas[$p][0])); ?> — 5C Escalas</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700;800&amp;display=swap">
<link rel="stylesheet" href="<?php echo h(MODULE_URL); ?>/assets/escalas.css?v=<?php echo h(ESC_VERSAO . '-' . (@filemtime(__DIR__ . '/assets/escalas.css') ?: 0)); ?>">
<script>window.ESC_I18N = <?php echo json_encode((object) $textosJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;<?php if ($tour): ?> window.ESC_TOUR = <?php echo json_encode($tour, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES); ?>;<?php endif; ?></script>
</head>
<body>
<div class="app">
<nav class="menu" aria-label="<?php echo h(t('Menu do sistema')); ?>">
  <div class="menu-topo">
    <div class="marca"><img class="marca-logo marca-logo-escuro" src="<?php echo h(MODULE_URL); ?>/assets/marca/logo_5c_branco.png" alt="5C Company"><img class="marca-logo marca-logo-claro" src="<?php echo h(MODULE_URL); ?>/assets/marca/logo_5c_marsala.png" alt="5C Company"><strong>Escalas</strong><span class="eyebrow">MIS Portal</span><span class="tag-teste"><?php echo h(t('perfil')); ?> <?php echo h(strtolower(escRotulo('perfil', $usuario['perfil']))); ?></span></div>
    <button type="button" class="menu-botao" aria-expanded="false" aria-controls="menu-itens"><span aria-hidden="true">☰</span> <?php echo h(t('Menu')); ?></button>
  </div>
  <div class="menu-itens" id="menu-itens">
  <div class="preferencias">
  <div class="tema" role="group" aria-label="<?php echo h(t('Tema')); ?>">
    <?php foreach (['claro' => 'Claro', 'escuro' => 'Escuro', 'auto' => 'Automático'] as $k => $rot): ?>
    <button type="button" data-tema-escolha="<?php echo $k; ?>" aria-pressed="<?php echo $tema === $k ? 'true' : 'false'; ?>"<?php echo $k === 'auto' ? ' title="' . h(t('Segue o tema do sistema')) . '"' : ''; ?>><?php echo h(t($rot)); ?></button>
    <?php endforeach; ?>
  </div>
  <div class="tema" role="group" aria-label="<?php echo h(t('Idioma')); ?>">
    <?php foreach (['pt-BR' => 'PT', 'en' => 'EN'] as $k => $rot): ?>
    <button type="button" data-idioma-escolha="<?php echo $k; ?>" lang="<?php echo $k; ?>" title="<?php echo h(ESC_IDIOMAS[$k]); ?>" aria-pressed="<?php echo escIdioma() === $k ? 'true' : 'false'; ?>"><?php echo $rot; ?></button>
    <?php endforeach; ?>
  </div>
  </div>
  <?php foreach ($menu as $secao => $itens): ?>
  <div class="grupo"><span class="grupo-titulo"><?php echo h(t($secao)); ?></span>
    <?php foreach ($itens as $it): ?>
    <a href="<?php echo h(link_tela($it)); ?>"<?php echo $p === $it ? ' class="ativo" aria-current="page"' : ''; ?>><?php echo h(t($telas[$it][0])); ?><?php if (!empty($badges[$it])): ?> <span class="badge" aria-label="<?php echo h(t('{n} pendentes', ['n' => (int) $badges[$it]])); ?>"><?php echo (int) $badges[$it]; ?></span><?php endif; ?></a>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
  <div class="grupo"><span class="grupo-titulo"><?php echo h(t('Monitoramento')); ?></span>
    <a href="<?php echo h(ESC_URL_ANOMALIAS); ?>"><?php echo h(t('Anomalias da escala')); ?> ↗</a>
    <a href="<?php echo h(ESC_URL_AUDITORIA); ?>"><?php echo h(t('Auditoria de acesso')); ?> ↗</a>
  </div>
  <?php if ($adminMenu): ?>
  <div class="grupo"><span class="grupo-titulo"><?php echo h(t('Administração')); ?></span>
    <?php foreach ($adminMenu as $it): ?>
    <a href="<?php echo h(link_tela($it)); ?>"<?php echo $p === $it ? ' class="ativo" aria-current="page"' : ''; ?>><?php echo h(t($telas[$it][0])); ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div class="grupo legado"><span class="grupo-titulo"><?php echo h(t('Legado (até {dia})', ['dia' => escData(escV4DiaVirada())])); ?></span>
    <?php foreach ($legadoMenu as $it): ?>
    <a href="<?php echo h(link_tela($it)); ?>"<?php echo $p === $it ? ' class="ativo" aria-current="page"' : ''; ?>><?php echo h(t($telas[$it][0])); ?></a>
    <?php endforeach; ?>
  </div>
  </div>
</nav>
<main class="conteudo">
  <?php if (escModoTreino()): ?>
  <div class="faixa-treino" role="status"><strong><?php echo h(t('MODO TREINO')); ?></strong><span><?php echo h(t('Dados de treino: o que você fizer aqui não muda a escala real nem o site da 5C.')); ?></span>
    <form method="post" action="<?php echo h(link_tela('tutorial')); ?>" data-confirmar="<?php echo h(t('Recomeçar o treino?') . "\n\n" . t('Apaga tudo o que foi feito no treino e copia de novo os cadastros do sistema real.')); ?>" data-longo="<?php echo h(t('Recomeçando o treino…')); ?>"><?php echo campoCsrf(); ?><input type="hidden" name="acao" value="treino_recomecar"><button type="submit" class="botao"><?php echo h(t('Recomeçar o treino')); ?></button></form>
    <form method="post" action="<?php echo h(link_tela('tutorial')); ?>"><?php echo campoCsrf(); ?><input type="hidden" name="acao" value="treino_sair"><button type="submit" class="botao primario"><?php echo h(t('Sair do treino')); ?></button></form>
  </div>
  <?php endif; ?>
  <?php echo avisos(); ?>
  <div class="timbre" aria-hidden="true"><span class="timbre-faixa"></span><img class="timbre-aguia" src="<?php echo h(MODULE_URL); ?>/assets/marca/aguia_timbrado.png" alt=""><img class="timbre-logo" src="<?php echo h(MODULE_URL); ?>/assets/marca/logo_5c_marsala.png" alt=""><span class="timbre-site">www.<strong>5ccompany</strong>.com</span></div>
  <?php echo $conteudo; ?>
  <?php echo $listas; ?>
  <footer class="rodape">5C Company · 5C Escalas · <?php echo h(t('versão {v}', ['v' => ESC_VERSAO])); ?> · <a href="<?php echo h(link_tela('novidades')); ?>"><?php echo h(t('Changelog')); ?></a></footer>
</main>
</div>
<script src="<?php echo h(MODULE_URL); ?>/assets/escalas.js?v=<?php echo h(ESC_VERSAO . '-' . (@filemtime(__DIR__ . '/assets/escalas.js') ?: 0)); ?>"></script>
</body>
</html>
