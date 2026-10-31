<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/ajuda.php
// Central de Ajuda: Glossário (?p=glossario) e FAQ (?p=faq), com busca na própria
// tela. Conteúdo em lib/ajuda.php, nos dois idiomas.
// ===============================================

$ehGlossario = $telaAtual === 'glossario';
$itens = $ehGlossario ? escGlossario() : escFaq();
if ($ehGlossario) {
    usort($itens, static fn($a, $b) => strcoll(escAjudaTexto($a, 'termo'), escAjudaTexto($b, 'termo')));
}
?>
<header class="cabecalho">
  <span class="eyebrow"><?php echo h(t('Ajuda')); ?></span>
  <h1><?php echo h($ehGlossario ? t('Glossário') : t('FAQ')); ?></h1>
  <p class="muted"><?php echo h($ehGlossario ? t('Os termos do planejamento de escala, do jeito que aparecem nas telas.') : t('Perguntas frequentes de quem usa o 5C Escalas no dia a dia.')); ?></p>
</header>

<section class="panel">
  <label class="filtros"><?php echo h(t('Buscar')); ?>
    <input type="search" id="ajuda-busca" placeholder="<?php echo h($ehGlossario ? t('termo ou palavra da definição') : t('palavra da pergunta ou da resposta')); ?>" autocomplete="off" aria-controls="ajuda-lista">
  </label>
  <p class="muted small" id="ajuda-contagem" aria-live="polite"><?php echo h(t('{n} itens', ['n' => count($itens)])); ?></p>
  <dl class="ajuda-lista" id="ajuda-lista">
    <?php foreach ($itens as $i => $it): ?>
    <div class="ajuda-item" id="item-<?php echo $i; ?>">
      <?php if ($ehGlossario): ?>
      <dt><?php echo h(escAjudaTexto($it, 'termo')); ?></dt>
      <dd><?php echo h(escAjudaTexto($it, 'def')); ?><?php if (!empty($it['tela'])): ?> <a class="small" href="<?php echo h(link_tela($it['tela'])); ?>"><?php echo h(t('Ver na tela')); ?> →</a><?php endif; ?></dd>
      <?php else: ?>
      <dt><?php echo h(escAjudaTexto($it, 'p')); ?></dt>
      <dd><?php echo h(escAjudaTexto($it, 'r')); ?></dd>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </dl>
  <p class="muted" id="ajuda-vazio" hidden><?php echo h(t('Nada encontrado. Tente outra palavra ou veja o Tutorial.')); ?></p>
</section>
<script>
(function () {
  var busca = document.getElementById('ajuda-busca'), itens = document.querySelectorAll('.ajuda-item');
  var contagem = document.getElementById('ajuda-contagem'), vazio = document.getElementById('ajuda-vazio');
  var modelo = <?php echo json_encode(t('{n} itens')); ?>;
  var tirar = function (t) { return t.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); };
  busca.addEventListener('input', function () {
    var q = tirar(busca.value.trim()), n = 0;
    itens.forEach(function (el) { var ok = !q || tirar(el.textContent).indexOf(q) !== -1; el.hidden = !ok; n += ok ? 1 : 0; });
    contagem.textContent = modelo.replace('{n}', n);
    vazio.hidden = n > 0;
  });
})();
</script>
