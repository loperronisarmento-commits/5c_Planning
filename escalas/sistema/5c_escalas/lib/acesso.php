<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/acesso.php
// Perfis, permissões por ação, proteção de formulário (CSRF) e log.
//
// Perfis (5c_escalas.esc_acesso):
//   ADMIN ........ tudo, inclusive acessos, modelos e parâmetros
//   PLANEJAMENTO . gerar e ajustar a escala, cadastros, decisões
//   WFM .......... aprovar indisponibilidade, cadastros, decisões
//   GESTOR_TIME .. ver; registrar ações de correção do próprio time
//   LEITURA ...... só ver
//
// Primeiro acesso: enquanto esc_acesso estiver vazia, os aprovados no
// Schedule Anomalies entram sozinhos (admin de lá = ADMIN aqui; o resto
// = LEITURA). Depois disso, só quem está na tabela.
// ===============================================

const ESC_PODE = [
    'ver'       => ['ADMIN', 'PLANEJAMENTO', 'WFM', 'GESTOR_TIME', 'LEITURA'],
    'planejar'  => ['ADMIN', 'PLANEJAMENTO'],
    'aprovar'   => ['ADMIN', 'WFM'],
    'cadastrar' => ['ADMIN', 'PLANEJAMENTO', 'WFM'],
    'decidir'   => ['ADMIN', 'PLANEJAMENTO', 'WFM'],
    'corrigir'  => ['ADMIN', 'PLANEJAMENTO', 'WFM', 'GESTOR_TIME'],
    'admin'     => ['ADMIN'],
];

function escCarregarPerfil(int $userId): ?array {
    $linha = escQ1('SELECT user_id, nome, perfil, times, ativo FROM ' . escT('esc_acesso') . ' WHERE user_id = ?', [$userId]);
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
    $perfil = $GLOBALS['ESC_USUARIO']['perfil'] ?? '';
    return in_array($perfil, ESC_PODE[$acao] ?? [], true);
}

function escExigir(string $acao): void {
    if (!escPode($acao)) {
        throw new RuntimeException('Bloqueado: seu perfil não pode fazer isso.');
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
        throw new RuntimeException('Bloqueado: formulário expirado. Recarregue a página e tente de novo.');
    }
}

// Depois de gravar, volta para a tela com o aviso (evita reenviar o form).
function escVoltar(string $p, array $params, string $aviso, bool $erro = false): void {
    $params[$erro ? 'erro' : 'ok'] = $aviso;
    header('Location: ' . link_tela($p, $params));
    exit;
}

function avisos(): string {
    $html = '';
    if (!empty($_GET['ok'])) {
        $html .= '<div class="aviso ok" role="status">' . h((string) $_GET['ok']) . '</div>';
    }
    if (!empty($_GET['erro'])) {
        $html .= '<div class="aviso erro" role="alert">' . h((string) $_GET['erro']) . '</div>';
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
