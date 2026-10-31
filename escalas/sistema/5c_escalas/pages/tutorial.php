<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/tutorial.php
// Tutorial: tour guiado pelas telas (assets/escalas.js · tour), Modo Treino
// (lib/treino.php) e "Ações necessárias" agora, separadas entre o que se faz na
// plataforma e o que se faz no site da 5C.
// ===============================================

if (escPost()) {
    $acao = $_POST['acao'] ?? '';
    if ($acao === 'treino_ligar') {
        if (!escTreinoExiste()) {
            throw new RuntimeException(t('O Modo Treino ainda não foi instalado no banco (script ES_28). Peça ao admin.'));
        }
        escTreinoLigar(true);
        escVoltar('painel', [], t('Modo Treino ligado: o que você fizer agora não muda a escala real.'));
    }
    if ($acao === 'treino_tour') {
        // "Começar do início" fora do treino: liga o Modo Treino e abre o primeiro passo do tour
        // (o navegador já marcou o tour como em andamento antes de enviar).
        if (!escTreinoExiste()) {
            throw new RuntimeException(t('O Modo Treino ainda não foi instalado no banco (script ES_28). Peça ao admin.'));
        }
        escTreinoLigar(true);
        escVoltar(escTourPassos()[0]['tela'], [], t('Modo Treino ligado para o tour: pode clicar à vontade, nada muda a escala real.'));
    }
    if ($acao === 'treino_sair') {
        escTreinoLigar(false);
        escVoltar('tutorial', [], t('Você saiu do Modo Treino e voltou para o sistema real.'));
    }
    if ($acao === 'treino_recomecar') {
        $copiadas = escTreinoResetar();
        escVoltar('painel', [], t('Treino recomeçado: cadastros copiados de novo ({n} tabelas) e 3 pedidos fictícios para praticar.', ['n' => count($copiadas)]));
    }
    throw new RuntimeException(t('Ação desconhecida.'));
}

$passos = escTourPassos();
$acoes = escAcoesNecessarias();
$porOnde = ['plataforma' => [], 'site' => []];
foreach ($acoes as $a) {
    $porOnde[$a['onde']][] = $a;
}
?>
<header class="cabecalho">
  <span class="eyebrow"><?php echo h(t('Ajuda')); ?></span>
  <h1><?php echo h(t('Tutorial')); ?></h1>
  <p class="muted"><?php echo h(t('Um tour pelas telas na ordem do trabalho da semana, com o que fazer na plataforma e no site da 5C em cada passo. Para praticar sem risco, ligue o Modo Treino.')); ?></p>
</header>

<section class="panel" id="modo-treino">
  <h2><?php echo h(t('Modo Treino')); ?></h2>
  <p class="small"><?php echo h(t('Uma cópia separada do sistema, com os mesmos cadastros e alguns pedidos fictícios. Tudo o que você gerar, ajustar, publicar ou aprovar no treino fica só no treino: a escala real e o site da 5C não mudam. No treino você pode usar todas as ações, menos as de administração, e a saída de página não mexe no Capacity.')); ?></p>
  <?php if (escModoTreino()): ?>
  <div class="acoes">
    <form method="post" action="<?php echo h(link_tela('tutorial')); ?>" data-confirmar="<?php echo h(t('Recomeçar o treino?') . "\n\n" . t('Apaga tudo o que foi feito no treino e copia de novo os cadastros do sistema real.')); ?>" data-longo="<?php echo h(t('Recomeçando o treino…')); ?>">
      <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="treino_recomecar">
      <button type="submit" class="botao"><?php echo h(t('Recomeçar o treino')); ?></button>
    </form>
    <form method="post" action="<?php echo h(link_tela('tutorial')); ?>">
      <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="treino_sair">
      <button type="submit" class="botao primario"><?php echo h(t('Sair do treino')); ?></button>
    </form>
  </div>
  <?php elseif (escTreinoExiste()): ?>
  <form method="post" action="<?php echo h(link_tela('tutorial')); ?>" class="acoes" data-longo="<?php echo h(t('Preparando o treino…')); ?>">
    <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="treino_ligar">
    <button type="submit" class="botao primario"><?php echo h(t('Ligar o Modo Treino')); ?></button>
    <span class="muted small"><?php echo h(t('Uma faixa aparece em todas as telas enquanto o treino estiver ligado.')); ?></span>
  </form>
  <?php else: ?>
  <p class="aviso"><?php echo h(t('O Modo Treino ainda não foi instalado no banco (script ES_28). Peça ao admin.')); ?></p>
  <?php endif; ?>
</section>

<section class="panel" id="acoes-necessarias">
  <h2><?php echo h(t('Ações necessárias agora')); ?></h2>
  <div class="colunas-acoes">
    <?php foreach (['plataforma' => t('Na plataforma'), 'site' => t('No site 5C')] as $onde => $rot): ?>
    <div>
      <h3><?php echo h($rot); ?></h3>
      <?php if ($porOnde[$onde]): ?>
      <ul class="regras">
        <?php foreach ($porOnde[$onde] as $a): ?><li><a href="<?php echo h($a['link']); ?>"><?php echo h($a['texto']); ?></a></li><?php endforeach; ?>
      </ul>
      <?php else: ?>
      <p class="muted small"><?php echo h(t('Nada pendente.')); ?></p>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<section class="panel">
  <div class="linha-titulo">
    <h2><?php echo h(t('Tour guiado')); ?></h2>
    <span class="muted small" id="tour-progresso" aria-live="polite"></span>
  </div>
  <div class="progresso-tour" aria-hidden="true"><span id="tour-barra"></span></div>
  <div class="acoes">
    <?php if (escModoTreino() || !escTreinoExiste()): ?>
    <button type="button" class="botao primario" data-tour-iniciar="0"><?php echo h(t('Começar do início')); ?></button>
    <?php else: ?>
    <form method="post" action="<?php echo h(link_tela('tutorial')); ?>" data-tour-preparar data-longo="<?php echo h(t('Preparando o treino…')); ?>">
      <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="treino_tour">
      <button type="submit" class="botao primario"><?php echo h(t('Começar do início')); ?></button>
    </form>
    <?php endif; ?>
    <button type="button" class="botao" id="tour-retomar" data-tour-retomar hidden><?php echo h(t('Continuar de onde parei')); ?></button>
  </div>
  <p class="muted small"><?php echo h(escModoTreino() ? t('O Modo Treino está ligado: o tour usa a cópia de treino.') : (escTreinoExiste() ? t('"Começar do início" liga o Modo Treino antes do tour, para você poder clicar à vontade sem mudar a escala real.') : t('O Modo Treino ainda não foi instalado: o tour mostra as telas reais.'))); ?></p>
  <ol class="tour-passos">
    <?php foreach ($passos as $i => $ps): ?>
    <li id="passo-<?php echo $i; ?>" data-passo="<?php echo $i; ?>">
      <div class="linha-titulo"><strong><?php echo h(escAjudaTexto($ps, 'titulo')); ?></strong><button type="button" class="botao" data-tour-iniciar="<?php echo $i; ?>"><?php echo h(t('Ver na tela')); ?></button></div>
      <p class="small"><?php echo h(escAjudaTexto($ps, 'texto')); ?></p>
      <?php $pl = $ps[escEmIngles() ? 'plataforma_en' : 'plataforma']; $st = $ps[escEmIngles() ? 'site_en' : 'site']; ?>
      <?php if ($pl || $st): ?>
      <div class="colunas-acoes small">
        <div><strong><?php echo h(t('Na plataforma')); ?></strong><?php if ($pl): ?><ul><?php foreach ($pl as $x): ?><li><?php echo h($x); ?></li><?php endforeach; ?></ul><?php else: ?><p class="muted"><?php echo h(t('Nada nesta etapa.')); ?></p><?php endif; ?></div>
        <div><strong><?php echo h(t('No site 5C')); ?></strong><?php if ($st): ?><ul><?php foreach ($st as $x): ?><li><?php echo h($x); ?></li><?php endforeach; ?></ul><?php else: ?><p class="muted"><?php echo h(t('Nada nesta etapa.')); ?></p><?php endif; ?></div>
      </div>
      <?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ol>
</section>

<script>
(function () {
  var total = <?php echo count($passos); ?>, modelo = <?php echo json_encode(t('Passo {n} de {total}')); ?>, visto = <?php echo json_encode(t('{n} de {total} passos vistos')); ?>;
  var estado = null;
  try { estado = JSON.parse(localStorage.getItem('esc-tour') || 'null'); } catch (e) { /* sem storage */ }
  var vistos = (estado && estado.vistos) || [];
  document.getElementById('tour-progresso').textContent = visto.replace('{n}', vistos.length).replace('{total}', total);
  document.getElementById('tour-barra').style.width = Math.round(vistos.length / total * 100) + '%';
  vistos.forEach(function (i) { var li = document.getElementById('passo-' + i); if (li) { li.classList.add('visto'); } });
  if (estado && estado.passo !== undefined && estado.passo > 0 && estado.passo < total) {
    var r = document.getElementById('tour-retomar');
    r.hidden = false;
    r.textContent += ' (' + modelo.replace('{n}', estado.passo + 1).replace('{total}', total) + ')';
  }
})();
</script>
