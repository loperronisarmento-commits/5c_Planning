<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/acessos.php
// Quem acessa o 5C Escalas e com qual perfil (só ADMIN muda).
// Usuários e logins vêm do MIS Portal (5c_wfm_mis_web.mis_login: só id e
// login são lidos).
// ===============================================

if (escPost()) {
    escExigir('admin');
    $uid = (int) ($_POST['user_id'] ?? 0);
    $perfil = (string) ($_POST['perfil'] ?? 'LEITURA');
    if ($uid <= 0 || !in_array($perfil, ['ADMIN', 'WFM', 'PLANEJAMENTO', 'GESTOR_TIME', 'LEITURA'], true)) {
        throw new RuntimeException('Escolha o usuário e o perfil.');
    }
    if ($uid === escUserId() && $perfil !== 'ADMIN') {
        throw new RuntimeException('Você não pode tirar o seu próprio perfil de administrador.');
    }
    $login = escUsuariosPortal()[$uid] ?? '';
    $nome = mb_substr(trim((string) ($_POST['nome'] ?? '')), 0, 120) ?: ($login !== '' ? nomeDoLogin($login) : null);
    $dados = [$nome, $perfil, mb_substr(trim((string) ($_POST['times'] ?? '')), 0, 500) ?: null, !empty($_POST['ativo']) ? 1 : 0];
    $antes = escQ1('SELECT * FROM ' . escT('esc_acesso') . ' WHERE user_id = ?', [$uid]);
    if ($antes) {
        escW('UPDATE ' . escT('esc_acesso') . ' SET nome = ?, perfil = ?, times = ?, ativo = ?, atualizado_por = ? WHERE user_id = ?', array_merge($dados, [escUserId(), $uid]));
    } else {
        escW('INSERT INTO ' . escT('esc_acesso') . ' (nome, perfil, times, ativo, criado_por, user_id) VALUES (?, ?, ?, ?, ?, ?)', array_merge($dados, [escUserId(), $uid]));
    }
    escLog('acesso.salvar', 'esc_acesso', $uid, $antes, ['nome' => $nome, 'perfil' => $perfil, 'ativo' => $dados[3]]);
    escVoltar('acessos', [], 'Acesso de ' . ($nome ?: 'user_id ' . $uid) . ' salvo.');
}

$lista = escQ('SELECT * FROM ' . escT('esc_acesso') . ' ORDER BY ativo DESC, perfil, user_id');
$logins = [];
try {
    $logins = escUsuariosPortal();
} catch (Throwable $e) {
    appLog('warning', 'Não deu para ler os usuários do portal', ['erro' => $e->getMessage()]);
}
$comAcesso = array_flip(array_map(static fn($a) => (int) $a['user_id'], $lista));
$semAcesso = array_diff_key($logins, $comAcesso);
$nomeDe = static function (int $uid, ?string $nome) use ($logins): string {
    return $nome ?: (isset($logins[$uid]) ? nomeDoLogin($logins[$uid]) : 'user_id ' . $uid);
};
$log = escQ('SELECT quando, user_id, user_nome, canal, acao, entidade, entidade_id FROM ' . escT('esc_log') . ' ORDER BY id DESC LIMIT 30');
?>
<header class="cabecalho">
  <span class="eyebrow">Administração</span>
  <h1>Acessos</h1>
  <p class="muted">Admin: tudo. Planejamento: gerar e ajustar a escala. WFM: aprovar pedidos e cadastros. Gestor do time: ver e registrar ação de correção. Leitura: só ver. Você: <strong><?php echo h($nomeDe(escUserId(), $GLOBALS['ESC_USUARIO']['nome'] ?? null)); ?></strong> (user_id <?php echo escUserId(); ?>).</p>
</header>

<section class="panel">
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th>Usuário do portal</th><th>Nome</th><th>Perfil</th><th>Times</th><th>Ativo</th><?php if (escPode('admin')): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($lista as $a): $uid = (int) $a['user_id']; ?>
      <tr>
        <td><?php echo h($logins[$uid] ?? 'não encontrado no portal'); ?><br><span class="muted small">user_id <?php echo $uid; ?></span></td>
        <?php if (escPode('admin')): $fid = 'fa' . $uid; ?>
          <td><input type="text" name="nome" form="<?php echo $fid; ?>" value="<?php echo h($nomeDe($uid, $a['nome'])); ?>"></td>
          <td><select name="perfil" form="<?php echo $fid; ?>"><?php foreach (['ADMIN', 'PLANEJAMENTO', 'WFM', 'GESTOR_TIME', 'LEITURA'] as $pf): ?><option <?php echo $a['perfil'] === $pf ? 'selected' : ''; ?>><?php echo $pf; ?></option><?php endforeach; ?></select></td>
          <td><input type="text" name="times" form="<?php echo $fid; ?>" value="<?php echo h($a['times']); ?>" placeholder="só para gestor"></td>
          <td><input type="checkbox" name="ativo" form="<?php echo $fid; ?>" value="1" <?php echo (int) $a['ativo'] ? 'checked' : ''; ?>></td>
          <td><form id="<?php echo $fid; ?>" method="post" action="<?php echo h(link_tela('acessos')); ?>"><?php echo campoCsrf(); ?><input type="hidden" name="user_id" value="<?php echo $uid; ?>"><button type="submit" class="botao">Salvar</button></form></td>
        <?php else: ?>
          <td><?php echo h($nomeDe($uid, $a['nome'])); ?></td><td><?php echo h($a['perfil']); ?></td><td><?php echo h($a['times']); ?></td><td><?php echo (int) $a['ativo'] ? 'sim' : 'não'; ?></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if (escPode('admin')): ?>
  <h2>Adicionar</h2>
  <?php if ($semAcesso): ?>
  <form method="post" action="<?php echo h(link_tela('acessos')); ?>" class="form-grade">
    <?php echo campoCsrf(); ?>
    <label>Usuário do portal<select name="user_id" required><option value="">escolha…</option><?php foreach ($semAcesso as $uid => $login): ?><option value="<?php echo (int) $uid; ?>"><?php echo h($login); ?></option><?php endforeach; ?></select></label>
    <label>Nome (vazio = pelo login)<input type="text" name="nome"></label>
    <label>Perfil<select name="perfil"><option>LEITURA</option><option>GESTOR_TIME</option><option>WFM</option><option>PLANEJAMENTO</option><option>ADMIN</option></select></label>
    <label>Times (gestor)<input type="text" name="times" placeholder="Cavalry, Aeterna"></label>
    <input type="hidden" name="ativo" value="1">
    <div class="acoes"><button type="submit" class="botao primario">Adicionar</button></div>
  </form>
  <?php else: ?>
  <p class="muted small">Todos os usuários aprovados do portal já estão na lista.</p>
  <?php endif; ?>
  <p class="muted small">A lista mostra os usuários aprovados no MIS Portal. O nome em branco é preenchido a partir do login.</p>
  <?php endif; ?>
</section>

<section class="panel">
  <h2>Últimas alterações (log)</h2>
  <ul class="regras small">
    <?php foreach ($log as $l): ?><li><?php echo h(date('d/m H:i', strtotime($l['quando']))); ?> · <?php echo h($l['user_id'] ? $nomeDe((int) $l['user_id'], $l['user_nome']) : strtolower($l['canal'])); ?> · <?php echo h($l['acao']); ?> · <?php echo h($l['entidade'] . ' ' . $l['entidade_id']); ?></li><?php endforeach; ?>
    <?php if (!$log): ?><li class="muted">Nada ainda.</li><?php endif; ?>
  </ul>
</section>
