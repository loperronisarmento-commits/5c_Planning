<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/parametros.php
// Parâmetros do 5C Escalas (formação, geração, mérito, prazos).
// ===============================================

if (escPost()) {
    escExigir('admin');
    $chave = (string) ($_POST['chave'] ?? '');
    $valor = trim((string) ($_POST['valor'] ?? ''));
    $p = escQ1('SELECT * FROM ' . escT('esc_parametro') . ' WHERE chave = ?', [$chave]);
    if (!$p || $p['grupo'] === 'motor') {
        throw new RuntimeException('Parâmetro não encontrado ou não editável.');
    }
    if (($p['tipo'] === 'INT' && !preg_match('~^-?\d+$~', $valor)) || ($p['tipo'] === 'DECIMAL' && !is_numeric(str_replace(',', '.', $valor))) || ($p['tipo'] === 'BOOL' && !in_array($valor, ['0', '1'], true))) {
        throw new RuntimeException('Valor inválido para ' . $chave . ' (' . strtolower($p['tipo']) . ').');
    }
    $valor = $p['tipo'] === 'DECIMAL' ? str_replace(',', '.', $valor) : $valor;
    escW('UPDATE ' . escT('esc_parametro') . ' SET valor = ?, atualizado_por = ? WHERE chave = ?', [$valor, escUserId(), $chave]);
    escLog('parametro.salvar', 'esc_parametro', $chave, ['valor' => $p['valor']], ['valor' => $valor]);
    escVoltar('parametros', [], $chave . ' = ' . $valor);
}

$lista = escQ('SELECT * FROM ' . escT('esc_parametro') . " WHERE grupo <> 'motor' ORDER BY grupo, chave");
?>
<header class="cabecalho">
  <span class="eyebrow">Administração</span>
  <h1>Parâmetros</h1>
  <p class="muted">Valem a partir do próximo recálculo. Só administrador muda.</p>
</header>
<section class="panel">
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th>Grupo</th><th>Parâmetro</th><th>Descrição</th><th>Valor</th></tr></thead>
    <tbody>
    <?php foreach ($lista as $p): ?>
      <tr>
        <td class="small"><?php echo h($p['grupo']); ?></td>
        <td class="mono small"><?php echo h($p['chave']); ?></td>
        <td class="small"><?php echo h($p['descricao']); ?></td>
        <td>
          <?php if (escPode('admin')): ?>
          <form method="post" action="<?php echo h(link_tela('parametros')); ?>" class="acoes"><?php echo campoCsrf(); ?><input type="hidden" name="chave" value="<?php echo h($p['chave']); ?>"><input type="text" name="valor" value="<?php echo h($p['valor']); ?>" size="8"><button type="submit" class="botao">Salvar</button></form>
          <?php else: ?><?php echo h($p['valor']); ?><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
