<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/parametros.php
// Parâmetros do 5C Escalas (formação, geração, KPI, prazos). Os do Mérito ficam em Legacy, só leitura.
// ===============================================

escExigir('admin');
if (escPost()) {
    $chave = (string) ($_POST['chave'] ?? '');
    $valor = trim((string) ($_POST['valor'] ?? ''));
    $p = escQ1('SELECT * FROM ' . escT('esc_parametro') . ' WHERE chave = ?', [$chave]);
    if (!$p || $p['grupo'] === 'motor' || escParametroLegado($p)) {
        throw new RuntimeException(t('Parâmetro não encontrado ou não editável.'));
    }
    if (($p['tipo'] === 'INT' && !preg_match('~^-?\d+$~', $valor)) || ($p['tipo'] === 'DECIMAL' && !is_numeric(str_replace(',', '.', $valor))) || ($p['tipo'] === 'BOOL' && !in_array($valor, ['0', '1'], true))) {
        throw new RuntimeException(t('Valor inválido para {chave} ({tipo}).', ['chave' => $chave, 'tipo' => strtolower($p['tipo'])]));
    }
    $valor = $p['tipo'] === 'DECIMAL' ? str_replace(',', '.', $valor) : $valor;
    escW('UPDATE ' . escT('esc_parametro') . ' SET valor = ?, atualizado_por = ? WHERE chave = ?', [$valor, escUserId(), $chave]);
    escLog('parametro.salvar', 'esc_parametro', $chave, ['valor' => $p['valor']], ['valor' => $valor]);
    escVoltar('parametros', [], $chave . ' = ' . $valor);
}

// Parâmetros legados (Legado › Parâmetros legados): os que nenhum código lê mais, só para consulta.
$legado = ($p ?? '') === 'parametros_legado';
$lista = array_values(array_filter(
    escQ('SELECT * FROM ' . escT('esc_parametro') . " WHERE grupo <> 'motor' ORDER BY grupo, chave"),
    static fn($l) => escParametroLegado($l) === $legado
));
$nLegado = $legado ? 0 : (int) (escQ1('SELECT COUNT(*) AS n FROM ' . escT('esc_parametro') . " WHERE grupo = 'merito' OR chave IN (" . escIn(ESC_PARAMETROS_LEGADO) . ')', ESC_PARAMETROS_LEGADO)['n'] ?? 0);
?>
<header class="cabecalho">
  <span class="eyebrow"><?php echo h(t($legado ? 'Legacy' : 'Administração')); ?></span>
  <h1><?php echo h(t($legado ? 'Parâmetros legados' : 'Parâmetros')); ?></h1>
  <?php if ($legado): ?>
  <p class="muted"><?php echo h(t('Nenhuma parte do sistema lê estes parâmetros: são da estrutura antiga (times e parties) ou de regras que já saíram. Ficam aqui só para consulta, sem edição.')); ?> <a href="<?php echo h(link_tela('parametros')); ?>"><?php echo h(t('Voltar aos parâmetros em uso')); ?></a></p>
  <?php else: ?>
  <p class="muted"><?php echo h(t('Valem a partir do próximo recálculo. Só administrador muda.')); ?><?php if ($nLegado): ?> <a href="<?php echo h(link_tela('parametros_legado')); ?>"><?php echo h(t('{n} parâmetros sem uso ou do Mérito estão em Legacy.', ['n' => $nLegado])); ?></a><?php endif; ?></p>
  <?php endif; ?>
</header>
<section class="panel">
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th><?php echo h(t('Grupo')); ?></th><th><?php echo h(t('Parâmetro')); ?></th><th><?php echo h(t('Descrição')); ?></th><th><?php echo h(t('Valor')); ?></th></tr></thead>
    <tbody>
    <?php if (!$lista): ?><tr><td colspan="12" class="muted"><?php echo h(t('Nada para mostrar.')); ?></td></tr><?php endif; ?>
    <?php foreach ($lista as $p): ?>
      <tr>
        <td class="small"><?php echo h($p['grupo']); ?></td>
        <td class="mono small"><?php echo h($p['chave']); ?></td>
        <td class="small"><?php echo h($p['descricao']); ?></td>
        <td>
          <?php if (escPode('admin') && !$legado): ?>
          <form method="post" action="<?php echo h(link_tela('parametros')); ?>" class="acoes"><?php echo campoCsrf(); ?><input type="hidden" name="chave" value="<?php echo h($p['chave']); ?>"><input type="text" name="valor" aria-label="<?php echo h(t('Valor de {chave}', ['chave' => $p['chave']])); ?>" value="<?php echo h($p['valor']); ?>" size="8"><button type="submit" class="botao"><?php echo h(t('Salvar')); ?></button></form>
          <?php else: ?><?php echo h($p['valor']); ?><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
