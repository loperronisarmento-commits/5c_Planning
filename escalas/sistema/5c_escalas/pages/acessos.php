<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/acessos.php
// Quem acessa o 5C Escalas e com qual perfil (só ADMIN muda).
// ===============================================

if (escPost()) {
    escExigir('admin');
    $uid = (int) ($_POST['user_id'] ?? 0);
    $perfil = (string) ($_POST['perfil'] ?? 'LEITURA');
    if ($uid <= 0 || !in_array($perfil, ['ADMIN', 'WFM', 'PLANEJAMENTO', 'GESTOR_TIME', 'LEITURA'], true)) {
        throw new RuntimeException('user_id e perfil são obrigatórios.');
    }
    if ($uid === escUserId() && $perfil !== 'ADMIN') {
        throw new RuntimeException('Você não pode tirar o seu próprio perfil de administrador.');
    }
    $dados = [mb_substr(trim((string) ($_POST['nome'] ?? '')), 0, 120) ?: null, $perfil, mb_substr(trim((string) ($_POST['times'] ?? '')), 0, 500) ?: null, !empty($_POST['ativo']) ? 1 : 0];
    $antes = escQ1('SELECT * FROM ' . escT('esc_acesso') . ' WHERE user_id = ?', [$uid]);
    if ($antes) {
        escW('UPDATE ' . escT('esc_acesso') . ' SET nome = ?, perfil = ?, times = ?, ativo = ?, atualizado_por = ? WHERE user_id = ?', array_merge($dados, [escUserId(), $uid]));
    } else {
        escW('INSERT INTO ' . escT('esc_acesso') . ' (nome, perfil, times, ativo, criado_por, user_id) VALUES (?, ?, ?, ?, ?, ?)', array_merge($dados, [escUserId(), $uid]));
    }
    escLog('acesso.salvar', 'esc_acesso', $uid, $antes, ['perfil' => $perfil, 'ativo' => $dados[3]]);
    escVoltar('acessos', [], 'Acesso do user_id ' . $uid . ' salvo.');
}

$lista = escQ('SELECT * FROM ' . escT('esc_acesso') . ' ORDER BY ativo DESC, perfil, user_id');
$log = escQ('SELECT quando, user_id, acao, entidade, entidade_id FROM ' . escT('esc_log') . ' ORDER BY id DESC LIMIT 30');
?>
<header class="cabecalho">
  <span class="eyebrow">Administração</span>
  <h1>Acessos</h1>
  <p class="muted">Admin: tudo. Planejamento: gerar e ajustar a escala. WFM: aprovar pedidos e cadastros. Gestor do time: ver e registrar ação de correção. Leitura: só ver. Seu user_id: <strong><?php echo escUserId(); ?></strong>.</p>
</header>

<section class="panel">
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th class="num">user_id</th><th>Nome</th><th>Perfil</th><th>Times</th><th>Ativo</th><?php if (escPode('admin')): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($lista as $a): ?>
      <tr>
        <?php if (escPode('admin')): $fid = 'fa' . (int) $a['user_id']; ?>
          <td class="num"><?php echo (int) $a['user_id']; ?></td>
          <td><input type="text" name="nome" form="<?php echo $fid; ?>" value="<?php echo h($a['nome']); ?>"></td>
          <td><select name="perfil" form="<?php echo $fid; ?>"><?php foreach (['ADMIN', 'PLANEJAMENTO', 'WFM', 'GESTOR_TIME', 'LEITURA'] as $pf): ?><option <?php echo $a['perfil'] === $pf ? 'selected' : ''; ?>><?php echo $pf; ?></option><?php endforeach; ?></select></td>
          <td><input type="text" name="times" form="<?php echo $fid; ?>" value="<?php echo h($a['times']); ?>" placeholder="só para gestor"></td>
          <td><input type="checkbox" name="ativo" form="<?php echo $fid; ?>" value="1" <?php echo (int) $a['ativo'] ? 'checked' : ''; ?>></td>
          <td><form id="<?php echo $fid; ?>" method="post" action="<?php echo h(link_tela('acessos')); ?>"><?php echo campoCsrf(); ?><input type="hidden" name="user_id" value="<?php echo (int) $a['user_id']; ?>"><button type="submit" class="botao">Salvar</button></form></td>
        <?php else: ?>
          <td class="num"><?php echo (int) $a['user_id']; ?></td><td><?php echo h($a['nome']); ?></td><td><?php echo h($a['perfil']); ?></td><td><?php echo h($a['times']); ?></td><td><?php echo (int) $a['ativo'] ? 'sim' : 'não'; ?></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if (escPode('admin')): ?>
  <h2>Adicionar</h2>
  <form method="post" action="<?php echo h(link_tela('acessos')); ?>" class="form-grade">
    <?php echo campoCsrf(); ?>
    <label>user_id do portal<input type="number" name="user_id" min="1" required></label>
    <label>Nome<input type="text" name="nome"></label>
    <label>Perfil<select name="perfil"><option>LEITURA</option><option>GESTOR_TIME</option><option>WFM</option><option>PLANEJAMENTO</option><option>ADMIN</option></select></label>
    <label>Times (gestor)<input type="text" name="times" placeholder="Cavalry, Aeterna"></label>
    <input type="hidden" name="ativo" value="1">
    <div class="acoes"><button type="submit" class="botao primario">Adicionar</button></div>
  </form>
  <p class="muted small">Quem ainda não tem acesso vê o próprio user_id na tela de "Acesso restrito".</p>
  <?php endif; ?>
</section>

<section class="panel">
  <h2>Últimas alterações (log)</h2>
  <ul class="regras small">
    <?php foreach ($log as $l): ?><li><?php echo h(date('d/m H:i', strtotime($l['quando']))); ?> · user <?php echo (int) $l['user_id']; ?> · <?php echo h($l['acao']); ?> · <?php echo h($l['entidade'] . ' ' . $l['entidade_id']); ?></li><?php endforeach; ?>
    <?php if (!$log): ?><li class="muted">Nada ainda.</li><?php endif; ?>
  </ul>
</section>
