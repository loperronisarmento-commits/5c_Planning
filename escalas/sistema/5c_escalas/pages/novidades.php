<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/novidades.php
// Changelog (patch notes): o que mudou em cada versão do sistema, em português
// (lib/versao.php) ou inglês (lib/versao_en.php), conforme o idioma da tela.
// ===============================================

$en = escEmIngles();
$naoPublicado = $en && ESC_NAO_PUBLICADO_EN ? ESC_NAO_PUBLICADO_EN : ESC_NAO_PUBLICADO;
?>
<header class="cabecalho">
  <span class="eyebrow"><?php echo h(t('Ajuda')); ?></span>
  <h1><?php echo h(t('Changelog')); ?></h1>
  <p class="muted"><?php echo h(t('O que mudou em cada versão do 5C Escalas. Versão atual: v{v}. Quando uma versão pede script no banco, ele aparece ao lado (rodar antes de instalar o zip). Mudanças já instaladas que ainda não viraram versão oficial aparecem primeiro, como "não publicada".', ['v' => ESC_VERSAO])); ?></p>
</header>

<?php if ($naoPublicado): ?>
<section class="panel notas-versao" id="nao-publicado">
  <div class="linha-titulo">
    <h2><span class="tag amarelo"><?php echo h(t('não publicada')); ?></span> <?php echo h(t('Mudanças instaladas desde a v{v}', ['v' => ESC_VERSAO])); ?></h2>
    <span class="muted small"><?php echo h(t('entram na próxima versão oficial')); ?></span>
  </div>
  <ul class="regras small">
    <?php foreach ($naoPublicado as $item): ?><li><?php echo h($item); ?></li><?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php foreach (ESC_PATCH_NOTES as $i => $n): $tr = $en ? (ESC_PATCH_NOTES_EN[$n['versao']] ?? null) : null; ?>
<section class="panel notas-versao" id="v<?php echo h($n['versao']); ?>">
  <div class="linha-titulo">
    <h2><span class="tag <?php echo $i === 0 ? 'verde' : ''; ?>">v<?php echo h($n['versao']); ?></span> <?php echo h($tr['titulo'] ?? $n['titulo']); ?></h2>
    <span class="muted small"><?php echo h(escData($n['data'], false, true)); ?><?php echo $n['sql'] ? ' · ' . h(implode(', ', $n['sql'])) : ''; ?></span>
  </div>
  <ul class="regras small">
    <?php foreach ($tr['itens'] ?? $n['itens'] as $item): ?><li><?php echo h($item); ?></li><?php endforeach; ?>
  </ul>
</section>
<?php endforeach; ?>
