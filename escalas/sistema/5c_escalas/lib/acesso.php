<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/acesso.php
// Perfis, permissões por ação, proteção de formulário (CSRF) e log.
//
// Perfis (5c_escalas.esc_acesso):
//   ADMIN ........ tudo, inclusive acessos, modelos, parâmetros e Diagnóstico
//   PLANEJAMENTO . Planejamento e WFM (as mesmas pessoas): gerar e ajustar a
//                  escala, publicar, horas extras, aprovar indisponibilidade,
//                  cadastros e decisões
//   LEITURA ...... só ver
//   WFM e GESTOR_TIME saíram das opções (28/10): WFM vale como PLANEJAMENTO e
//   GESTOR_TIME como LEITURA (+ ação de correção), para quem já tinha.
//
// Primeiro acesso: enquanto esc_acesso estiver vazia, os aprovados no
// Schedule Anomalies entram sozinhos (admin de lá = ADMIN aqui; o resto
// = LEITURA). Depois disso, só quem está na tabela.
// ===============================================

const ESC_PODE = [
    'ver'       => ['ADMIN', 'PLANEJAMENTO', 'WFM', 'GESTOR_TIME', 'LEITURA'],
    'planejar'  => ['ADMIN', 'PLANEJAMENTO', 'WFM'],
    'aprovar'   => ['ADMIN', 'PLANEJAMENTO', 'WFM'],
    'cadastrar' => ['ADMIN', 'PLANEJAMENTO', 'WFM'],
    'decidir'   => ['ADMIN', 'PLANEJAMENTO', 'WFM'],
    'corrigir'  => ['ADMIN', 'PLANEJAMENTO', 'WFM', 'GESTOR_TIME'],
    'admin'     => ['ADMIN'],
];

function escCarregarPerfil(int $userId): ?array {
    // O acesso vale sempre o do 5c_escalas, também no Modo Treino.
    $linha = escQ1('SELECT user_id, nome, perfil, times, ativo FROM `' . ESC_SCHEMA_REAL . '`.`esc_acesso` WHERE user_id = ?', [$userId]);
    if (escModoTreino()) {
        return $linha && (int) $linha['ativo'] === 1 ? $linha : null;
    }
    if ($linha) {
        if ((int) $linha['ativo'] !== 1) {
            return null;
        }
        if (($linha['nome'] ?? '') === '') {
            // Primeiro acesso sem nome: usa o login do portal e grava.
            try {
                $login = escUsuariosPortal()[$userId] ?? '';
                if ($login !== '') {
                    $linha['nome'] = nomeDoLogin($login);
                    escW('UPDATE ' . escT('esc_acesso') . ' SET nome = ? WHERE user_id = ? AND (nome IS NULL OR nome = \'\')', [$linha['nome'], $userId]);
                }
            } catch (Throwable $e) {
                appLog('warning', 'Não deu para ler o login do portal', ['erro' => $e->getMessage()]);
            }
        }
        return $linha;
    }
    $vazia = escQ1('SELECT COUNT(*) AS n FROM ' . escT('esc_acesso'));
    if ((int) ($vazia['n'] ?? 0) > 0) {
        return null;
    }
    // Tabela vazia: copia os acessos do Schedule Anomalies (uma vez só).
    $origem = escQ('SELECT user_id, is_admin FROM `5c_wfm_mis_web`.`mis_module_access_schedule_anomalies`');
    $logins = [];
    try {
        $logins = escUsuariosPortal();
    } catch (Throwable $e) {
        $logins = [];
    }
    foreach ($origem as $o) {
        $uid = (int) $o['user_id'];
        escW('INSERT IGNORE INTO ' . escT('esc_acesso') . ' (user_id, nome, perfil, criado_por) VALUES (?, ?, ?, 0)',
            [$uid, isset($logins[$uid]) ? nomeDoLogin($logins[$uid]) : null, (int) $o['is_admin'] === 1 ? 'ADMIN' : 'LEITURA']);
    }
    $linha = escQ1('SELECT user_id, nome, perfil, times, ativo FROM ' . escT('esc_acesso') . ' WHERE user_id = ?', [$userId]);
    return $linha && (int) $linha['ativo'] === 1 ? $linha : null;
}

// Usuários do MIS Portal (só id e login; nunca as colunas de senha/CPF).
function escUsuariosPortal(): array {
    static $mapa = null;
    if ($mapa === null) {
        $mapa = [];
        foreach (escQ('SELECT id, mis_usr_login FROM `5c_wfm_mis_web`.`mis_login` WHERE is_approved = 1 ORDER BY mis_usr_login') as $u) {
            $mapa[(int) $u['id']] = (string) $u['mis_usr_login'];
        }
    }
    return $mapa;
}

// "luis.sarmento@5ccompany.com" -> "Luis Sarmento"
function nomeDoLogin(?string $login): string {
    $local = strtolower((string) strstr((string) $login, '@', true) ?: (string) $login);
    $local = preg_replace('~\+.*$~', '', $local);
    return ucwords(str_replace(['.', '_', '-'], ' ', $local));
}

function escPode(string $acao): bool {
    // No Modo Treino quem está aprendendo pode fazer tudo, menos administrar o sistema.
    if (escModoTreino() && $acao !== 'admin' && !empty($GLOBALS['ESC_USUARIO'])) {
        return true;
    }
    $perfil = $GLOBALS['ESC_USUARIO']['perfil'] ?? '';
    return in_array($perfil, ESC_PODE[$acao] ?? [], true);
}

// Permissão do perfil real, sem a liberação do Modo Treino (para ver dado restrito, como o mérito).
function escPodeReal(string $acao): bool {
    return in_array($GLOBALS['ESC_USUARIO']['perfil'] ?? '', ESC_PODE[$acao] ?? [], true);
}

function escExigir(string $acao): void {
    if (!escPode($acao)) {
        throw new RuntimeException(t('Bloqueado: seu perfil não pode fazer isso.'));
    }
}

function escUserId(): int {
    return (int) ($GLOBALS['ESC_USUARIO']['user_id'] ?? 0);
}

// ---------- formulários ----------

function escCsrf(): string {
    return (string) ($GLOBALS['ESC_CSRF'] ?? '');
}

function campoCsrf(): string {
    return '<input type="hidden" name="csrf" value="' . h(escCsrf()) . '">';
}

function escPost(): bool {
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function escConferirCsrf(): void {
    $enviado = (string) ($_POST['csrf'] ?? '');
    if (escCsrf() === '' || !hash_equals(escCsrf(), $enviado)) {
        throw new RuntimeException(t('Bloqueado: formulário expirado. Recarregue a página e tente de novo.'));
    }
}

// A sessão é fechada logo no começo (index.php) para não travar outras abas; o flash
// reabre só pelo tempo de ler ou gravar.
function escSessao(callable $f) {
    $aberta = session_status() === PHP_SESSION_ACTIVE;
    if (!$aberta) {
        @session_start();
    }
    try {
        return $f();
    } finally {
        if (!$aberta) {
            session_write_close();
        }
    }
}

// Depois de gravar, volta para a tela com o aviso (evita reenviar o form). O aviso vai na
// sessão (flash: aparece uma vez, não fica na URL). Com erro, o que foi digitado vai junto
// para o formulário reabrir preenchido (escalas.js).
// $ancora (opcional): id de um elemento da tela para a volta cair nele (ex.: o cartão que acabou de mudar).
function escVoltar(string $p, array $params, string $aviso, bool $erro = false, string $ancora = ''): void {
    $flash = ['tipo' => $erro ? 'erro' : 'ok', 'msg' => $aviso];
    if ($erro && escPost()) {
        $post = $_POST;
        unset($post['csrf']);
        if (strlen((string) json_encode($post)) <= 60000) {
            $flash['post'] = $post;
        }
    }
    escSessao(static function () use ($flash) {
        $_SESSION['esc_flash'] = $flash;
    });
    unset($params['ok'], $params['erro']);
    header('Location: ' . link_tela($p, $params) . ($ancora !== '' && preg_match('~^[a-z0-9_-]+$~i', $ancora) ? '#' . $ancora : ''));
    exit;
}

// Lê e apaga o flash (uma vez por tela).
function escFlashLer(): ?array {
    if (array_key_exists('ESC_FLASH', $GLOBALS)) {
        return $GLOBALS['ESC_FLASH'];
    }
    $GLOBALS['ESC_FLASH'] = escSessao(static function () {
        $f = $_SESSION['esc_flash'] ?? null;
        unset($_SESSION['esc_flash']);
        return is_array($f) ? $f : null;
    });
    return $GLOBALS['ESC_FLASH'];
}

// Aviso fixo no topo da área de conteúdo (fica visível ao rolar), com botão de fechar.
function avisos(): string {
    $f = escFlashLer();
    if (!$f || ($f['msg'] ?? '') === '') {
        return '<div class="avisos" id="avisos" aria-live="polite"></div>';
    }
    $erro = ($f['tipo'] ?? '') === 'erro';
    $html = '<div class="avisos" id="avisos"><div class="aviso ' . ($erro ? 'erro' : 'ok') . '" role="' . ($erro ? 'alert' : 'status') . '">'
        . '<span>' . h((string) $f['msg']) . '</span>'
        . '<button type="button" class="aviso-fechar" aria-label="' . h(t('Fechar aviso')) . '" data-fechar-aviso>×</button></div></div>';
    if ($erro && !empty($f['post'])) {
        $html .= '<script type="application/json" id="esc-flash-post">' . json_encode(['msg' => (string) $f['msg'], 'post' => $f['post']], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>';
    }
    return $html;
}

// ---------- log ----------

function escLog(string $acao, string $entidade, $entidadeId, $antes = null, $depois = null, string $canal = 'SITE'): void {
    escW('INSERT INTO ' . escT('esc_log') . ' (user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        escUserId() ?: null,
        $GLOBALS['ESC_USUARIO']['nome'] ?? null,
        $canal,
        $acao,
        $entidade,
        $entidadeId === null ? null : (string) $entidadeId,
        $antes === null ? null : json_encode($antes, JSON_UNESCAPED_UNICODE),
        $depois === null ? null : json_encode($depois, JSON_UNESCAPED_UNICODE),
        substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
    ]);
}

// ---------- banco fora do ar (o MySQL do .11 reinicia de vez em quando) ----------

// Erro de conexão com o MySQL (recusada, caiu ou sumiu no meio), e não erro de SQL.
function escErroConexao(Throwable $e): bool {
    for ($x = $e; $x !== null; $x = $x->getPrevious()) {
        if ($x instanceof PDOException && preg_match('~\[(2002|2003|2006|2013)\]|server has gone away|Lost connection~i', $x->getMessage())) {
            return true;
        }
    }
    return false;
}

// O banco responde? Até $tentativas checagens (TCP + leitura, 2 s cada), com 1 s e 2 s entre elas.
function escBancoNoAr(int $tentativas = 1): bool {
    for ($i = 0; $i < $tentativas; $i++) {
        if ($i > 0) {
            usleep($i * 1000000);
        }
        $end = escDbEndereco(); // o mesmo host e porta da conexão (ESC_DB_HOST/PORT ou DB_HOST/PORT)
        if (checarMysqlSaudavel((string) $end['host'], (int) $end['porta'], 2)) {
            return true;
        }
    }
    return false;
}

// Campos de um POST como inputs escondidos (para reenviar o formulário quando o banco voltar).
function escCamposEscondidos(array $dados, string $prefixo = ''): string {
    $out = '';
    foreach ($dados as $k => $v) {
        $nome = $prefixo === '' ? (string) $k : $prefixo . '[' . $k . ']';
        $out .= is_array($v) ? escCamposEscondidos($v, $nome) : '<input type="hidden" name="' . h($nome) . '" value="' . h((string) $v) . '">';
    }
    return $out;
}

// Aviso "aguardando o banco": testa ?saude=1 sozinho, com espera crescente, e abre a tela quando o
// banco volta. Num POST não reenvia sozinho: mostra o botão "Enviar de novo" com os mesmos campos.
function escEsperaBancoHtml(bool $post = false): string {
    $saude = MODULE_URL . '/index.php?saude=1';
    $html = '<section class="panel espera-banco" role="status" aria-live="polite" data-espera-banco data-saude="' . h($saude) . '" data-post="' . ($post ? '1' : '0') . '">'
        . '<div class="espera-banco-roda" aria-hidden="true"></div>'
        . '<h1>' . h(t('Conectando ao banco…')) . '</h1>'
        . '<p class="muted">' . h($post
            ? t('O banco de dados está reiniciando ou demorando para responder, e a ação ainda não foi feita. Quando ele voltar, use "Enviar de novo".')
            : t('O banco de dados está reiniciando ou demorando para responder. A tela abre sozinha assim que ele voltar.')) . '</p>'
        . '<p class="small muted" data-espera-info></p>';
    if ($post) {
        $html .= '<form method="post" action="' . h($_SERVER['REQUEST_URI'] ?? MODULE_URL . '/index.php') . '" data-espera-reenviar hidden>'
            . escCamposEscondidos($_POST) . '<button type="submit" class="botao primario">' . h(t('Enviar de novo')) . '</button></form>';
    }
    $html .= '<div class="acoes"><a class="botao" href="' . h($_SERVER['REQUEST_URI'] ?? MODULE_URL . '/index.php') . '" data-espera-agora' . ($post ? ' hidden' : '') . '>' . h(t('Tentar agora')) . '</a></div></section>';
    $txt = ['testando' => t('Testando a conexão…'), 'proxima' => t('Nova tentativa em {s} s · aguardando há {t}.'), 'voltou' => t('O banco voltou.'),
        'parou' => t('O banco segue fora depois de {t}. Tente de novo mais tarde ou avise o admin.')];
    $html .= '<script>(function () {
  var box = document.querySelector("[data-espera-banco]");
  if (!box) { return; }
  var T = ' . json_encode($txt, JSON_UNESCAPED_UNICODE) . ';
  var info = box.querySelector("[data-espera-info]"), post = box.dataset.post === "1";
  var esperas = [3, 3, 5, 5, 10, 10, 15, 15, 20, 30], i = 0, ini = Date.now(), limite = 10 * 60 * 1000;
  function dur() { var s = Math.round((Date.now() - ini) / 1000); return s < 60 ? s + " s" : Math.floor(s / 60) + " min " + (s % 60) + " s"; }
  function voltou() {
    info.textContent = T.voltou;
    if (post) { box.querySelector("[data-espera-reenviar]").hidden = false; box.querySelector(".espera-banco-roda").hidden = true; } else { location.reload(); }
  }
  function proxima() {
    if (Date.now() - ini > limite) { info.textContent = T.parou.replace("{t}", dur()); box.querySelector(".espera-banco-roda").hidden = true; var a = box.querySelector("[data-espera-agora]"); if (a) { a.hidden = false; } return; }
    var s = esperas[Math.min(i, esperas.length - 1)]; i++;
    info.textContent = T.proxima.replace("{s}", s).replace("{t}", dur());
    setTimeout(testar, s * 1000);
  }
  function testar() {
    info.textContent = T.testando;
    fetch(box.dataset.saude + "&_=" + Date.now(), { cache: "no-store", credentials: "same-origin" })
      .then(function (r) { return r.ok ? r.json() : { ok: false }; })
      .catch(function () { return { ok: false }; })
      .then(function (j) { if (j && j.ok) { voltou(); } else { proxima(); } });
  }
  proxima();
})();</script>';
    return $html;
}
