<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/organograma.php
// Organograma da equipe operacional (estrutura de 01/11), no visual do roster:
// COO → Director → Operational Manager de cada unidade (os Operational Assistants ficam
// ao lado, ligados aos 3 OMs: são assistentes dos OMs como um todo)
// → turnos T6/T1, T2/T3 e T4/T5, cada um com o OC e os 3 OS. Clicar no OS abre os
// SRs do núcleo; a busca acha qualquer pessoa. "Baixar PDF" sai como na tela (A3 em pé,
// preenchendo a folha), com a data da estrutura e o nome do arquivo já com a data.
// Lê esc_lideranca, esc_nucleo e esc_colaborador_config na data (?dia=); COO e
// Director vêm de esc_parametro (org_coo, org_diretor: "Nome|código"). O brasão do
// topo é assets/org_logo.png quando existe (senão, o desenho em SVG daqui).
// ===============================================

$dia = preg_match('~^\d{4}-\d{2}-\d{2}$~', (string) ($_GET['dia'] ?? '')) ? $_GET['dia'] : max(date('Y-m-d'), escV4DiaEstrutura());
$vig = ' vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)';
$nomes = escNomes();
$lid = escLiderancaCarregar($dia);
$om = [];
foreach ($lid['om'] as $p) {
    $om[$p['unidade']] = $p;
}
$oc = [];
foreach ($lid['grupos'] as $t => $g) {
    foreach ($g['OC'] ?? [] as $p) {
        $oc[$p['unidade']][$t] = $p;
    }
}
$oa = [];
foreach ($lid['grupos'] as $t => $g) {
    foreach ($g['OA'] ?? [] as $p) {
        $oa[$p['nome']] = $p;
    }
}
$nucleos = [];
$porId = [];
foreach (escQ('SELECT id, unidade, turno, numero, decurio_code, decurio_nome FROM ' . escT('esc_nucleo') . ' WHERE' . $vig . ' ORDER BY unidade, turno, numero', [$dia, $dia]) as $n) {
    $n = escNucleoComOsDoDia($n, $dia);
    $nucleos[(int) $n['unidade']][$n['turno']][(int) $n['numero']] = $n + ['srs' => []];
    $porId[(int) $n['id']] = [(int) $n['unidade'], $n['turno'], (int) $n['numero']];
}
foreach (escQ('SELECT UPPER(rep_code) AS rep, nucleo_id, nivel FROM ' . escT('esc_colaborador_config') . " WHERE funcao = 'EQUITE' AND nucleo_id IS NOT NULL AND" . $vig, [$dia, $dia]) as $s) {
    if (isset($porId[(int) $s['nucleo_id']])) {
        [$u, $t, $k] = $porId[(int) $s['nucleo_id']];
        $nucleos[$u][$t][$k]['srs'][] = ['rep' => $s['rep'], 'nome' => $nomes[$s['rep']] ?? $s['rep'], 'nivel' => $s['nivel'] !== null ? (int) $s['nivel'] : null];
    }
}
$totalSr = 0;
foreach ($nucleos as &$porTurno) {
    foreach ($porTurno as &$porNum) {
        foreach ($porNum as &$n) {
            usort($n['srs'], static fn($a, $b) => [-($a['nivel'] ?? -1), $a['nome']] <=> [-($b['nivel'] ?? -1), $b['nome']]);
            $totalSr += count($n['srs']);
        }
    }
}
unset($porTurno, $porNum, $n);
// Ordem do roster: T6/T1, T2/T3, T4/T5.
$TURNOS = ['D' => ['T6/T1', '21:00–05:00'], 'A' => ['T2/T3', '05:00–13:00'], 'C' => ['T4/T5', '13:00–21:00']];
$ICONE = [
    'coroa' => '<path d="M3 8l4.5 4L12 5l4.5 7L21 8l-2 10H5L3 8z"/><rect x="5" y="19.5" width="14" height="2" rx="1"/>',
    'pessoa' => '<circle cx="12" cy="6" r="3.6"/><path d="M6.5 22v-6.5A5.5 5.5 0 0 1 12 10a5.5 5.5 0 0 1 5.5 5.5V22z"/>',
    'grupo' => '<circle cx="12" cy="6.5" r="3"/><circle cx="5" cy="8.5" r="2.4"/><circle cx="19" cy="8.5" r="2.4"/><path d="M7.5 19v-4a4.5 4.5 0 0 1 9 0v4z"/><path d="M1 19v-3a4 4 0 0 1 5.6-3.7A6 6 0 0 0 6 15v4zM23 19v-3a4 4 0 0 0-5.6-3.7A6 6 0 0 1 18 15v4z"/>',
    'relogio' => '<circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2.4"/><path d="M12 7v5.5l3.5 2.2" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/>',
];
$icone = static fn(string $nome) => '<svg class="org-icone" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">' . $ICONE[$nome] . '</svg>';
// Uma pessoa do quadro: ícone, nome inteiro e cargo; o código vai na dica e na busca.
$pessoa = static function (?string $nome, ?string $rep, string $cargo, string $classe, string $ic, string $extra = '') use ($icone) {
    return '<div class="org-pessoa ' . $classe . '" data-busca="' . h(mb_strtolower(($nome ?? '') . ' ' . ($rep ?? ''), 'UTF-8')) . '"' . ($rep ? ' title="' . h($rep) . '"' : '') . '>'
        . $icone($ic) . '<span class="org-pessoa-txt"><strong>' . h($nome ?: '—') . '</strong><small>' . h($cargo) . '</small></span>'
        . (!$rep && $nome ? '<span class="org-sem-codigo">' . h(t('sem código')) . '</span>' : '') . $extra . '</div>';
};
// Modo "Ver páginas" (?paginas=1): cada OS mostra as páginas de que o núcleo é dono (com o owner),
// cada SR as páginas em que é owner ou backup, e cada OM quantas páginas a unidade tem.
$verPaginas = !empty($_GET['paginas']);
$pgNucleo = $pgRep = $pgUnidade = [];
if ($verPaginas) {
    foreach (escPolPaginas($dia) as $pg) {
        $pgUnidade[$pg['unidade']] = ($pgUnidade[$pg['unidade']] ?? 0) + 1;
        foreach ($pg['turnos'] as $tu) {
            $pgNucleo[$tu['dono']][$pg['pagina']] = array_keys($tu['owners']);
            foreach (array_keys($tu['owners']) as $r) {
                $pgRep[$r]['dono'][$pg['pagina']] = true;
            }
            foreach (array_keys($tu['backups']) as $r) {
                $pgRep[$r]['backup'][$pg['pagina']] = true;
            }
        }
    }
}
// Trocas de unidade já marcadas para depois da data (ex.: Equilíbrio a partir de 02/11): o
// organograma da data ainda não mostra; o aviso leva à data da troca.
$trocas = [];
foreach (escQ("SELECT pagina, de_ref, para_ref, DATE(vale_desde) AS d FROM " . escT('esc_politica_troca') . " WHERE tipo = 'UNIDADE' AND status = 'APLICADA' AND vale_desde > ? ORDER BY vale_desde, pagina", [$dia]) as $tr) {
    $trocas[substr((string) $tr['d'], 0, 10)][] = $tr['pagina'] . ' (U' . (int) $tr['de_ref'] . ' → U' . (int) $tr['para_ref'] . ')';
}
$logo = is_file(__DIR__ . '/../assets/org_logo.png') ? MODULE_URL . '/assets/org_logo.png?v=' . (int) @filemtime(__DIR__ . '/../assets/org_logo.png') : null;
[$ceoNome, $ceoCod] = array_pad(explode('|', escV4Param('org_coo', 'Marcus V. Silva|M067432'), 2), 2, null);
[$dirNome, $dirCod] = array_pad(explode('|', escV4Param('org_diretor', 'Natan Dalla Corte|N077356'), 2), 2, null);
?>
<header class="cabecalho">
  <span class="eyebrow"><?php echo h(t('Pessoas')); ?></span>
  <h1><?php echo h(t('Organograma')); ?></h1>
  <p class="muted"><?php echo h(t('Estrutura vigente em {dia}: {om} Operational Managers, {oc} Operational Coordinators, {os} Operational Supervisors e {sr} Sales Representatives.', ['dia' => dataBr($dia), 'om' => count($om), 'oc' => array_sum(array_map('count', $oc)), 'os' => array_sum(array_map(static fn($pt) => array_sum(array_map('count', $pt)), $nucleos)), 'sr' => $totalSr])); ?> <span class="org-dica"><?php echo h(t('Clique num OS para ver os SRs do núcleo.')); ?></span></p>
  <?php foreach ($trocas as $d => $lista): ?>
  <p class="muted small"><?php echo h(t('Troca de unidade a partir de {dia}: {lista}. As pessoas ficam no time; a página passa para o time do destino.', ['dia' => dataBr($d), 'lista' => implode(', ', $lista)])); ?> <a href="<?php echo h(link_tela('organograma', ['dia' => $d] + ($verPaginas ? ['paginas' => 1] : []))); ?>"><?php echo h(t('Ver em {dia}', ['dia' => dataBr($d)])); ?></a></p>
  <?php endforeach; ?>
</header>

<section class="panel org-barra">
  <input type="search" id="org-busca" placeholder="<?php echo h(t('Buscar pessoa ou código…')); ?>" aria-label="<?php echo h(t('Buscar pessoa no organograma')); ?>">
  <select id="org-unidade" aria-label="<?php echo h(t('Unidade')); ?>"><option value=""><?php echo h(t('Todas as unidades')); ?></option><?php foreach (array_keys($nucleos) as $u): ?><option value="<?php echo (int) $u; ?>"><?php echo h(t('Unidade {u}', ['u' => (int) $u])); ?></option><?php endforeach; ?></select>
  <button type="button" class="botao" id="org-abrir"><?php echo h(t('Abrir todos os núcleos')); ?></button>
  <button type="button" class="botao" id="org-fechar"><?php echo h(t('Recolher')); ?></button>
  <form method="get" class="acoes" style="margin:0"><input type="hidden" name="p" value="organograma"><label class="small muted"><?php echo h(t('Data')); ?> <input type="date" name="dia" value="<?php echo h($dia); ?>" onchange="this.form.submit()"></label></form>
  <a class="botao<?php echo $verPaginas ? ' ativo' : ''; ?>" href="<?php echo h(link_tela('organograma', $verPaginas ? ['dia' => $dia] : ['dia' => $dia, 'paginas' => 1])); ?>" aria-pressed="<?php echo $verPaginas ? 'true' : 'false'; ?>"><?php echo h($verPaginas ? t('Só liderança') : t('Ver páginas')); ?></a>
  <?php if (escPode('decidir')): ?><a class="botao" href="<?php echo h(link_tela('roster', ['dia' => $dia])); ?>"><?php echo h(t('Ajustar roster')); ?></a><?php endif; ?>
  <button type="button" class="botao primario" id="org-imprimir" title="<?php echo h(t('Abre a impressão: escolha "Salvar como PDF". Sai como está na tela (núcleos recolhidos = só a liderança).')); ?>"><?php echo h(t('Baixar PDF')); ?></button>
  <span class="muted small" id="org-achados" aria-live="polite"></span>
</section>

<section class="org" aria-label="<?php echo h(t('Organograma')); ?>">
  <header class="org-marca">
    <?php if ($logo): ?>
    <img class="org-logo" src="<?php echo h($logo); ?>" alt="5C">
    <?php else: ?>
    <svg class="org-logo" viewBox="0 0 160 110" role="img" aria-label="5C">
      <defs><linearGradient id="org-ouro" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ffe9a3"/><stop offset=".5" stop-color="#e7b52c"/><stop offset="1" stop-color="#9a6a12"/></linearGradient></defs>
      <g fill="url(#org-ouro)">
        <path d="M80 8c-6 10-7 20-4 30L66 30 50 6l4 26-22-14 12 24-28-6 22 18-26 4 30 10-18 10 34-4 20 18 8-26z"/>
        <path d="M80 8c6 10 7 20 4 30l10-8 16-24-4 26 22-14-12 24 28-6-22 18 26 4-30 10 18 10-34-4-20 18-8-26z"/>
      </g>
      <circle cx="80" cy="60" r="21" fill="#5a0f22" stroke="url(#org-ouro)" stroke-width="3"/>
      <text x="80" y="69" text-anchor="middle" font-family="Georgia, 'Times New Roman', serif" font-size="25" font-weight="700" fill="url(#org-ouro)">5C</text>
    </svg>
    <?php endif; ?>
    <h2 class="org-titulo"><?php echo h(t('Nova estrutura operacional')); ?></h2>
    <p class="org-sub"><?php echo h(t('Estrutura de liderança')); ?></p>
    <p class="org-data"><?php echo h(t('Vigente em {dia}', ['dia' => date('d/m/Y', strtotime($dia))])); ?></p>
  </header>

  <div class="org-topo">
    <?php echo $pessoa($ceoNome, $ceoCod, 'COO', 'org-ceo', 'coroa'); ?>
    <span class="org-seta" aria-hidden="true"></span>
    <?php echo $pessoa($dirNome, $dirCod, 'Director', 'org-diretor', 'pessoa'); ?>
    <?php if ($oa): ?>
    <!-- A linha do Director desce direto para os OMs; os OA ficam ao lado, ligados (tracejado) à barra dos OMs. -->
    <div class="org-staff">
      <span class="org-staff-linha" aria-hidden="true"></span>
      <div class="org-oa">
        <div class="org-oa-cab"><strong>Operational Assistants</strong><small><?php echo h(t('24/7 · assistentes dos Operational Managers')); ?></small></div>
        <div class="org-oa-lista"><?php foreach ($oa as $p): echo $pessoa($p['nome'], $p['rep'], 'OA', 'org-oa-card', 'pessoa'); endforeach; ?></div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="org-unidades">
    <?php foreach ($nucleos as $u => $porTurno): $nu = 0; foreach ($porTurno as $pn) { foreach ($pn as $n) { $nu += count($n['srs']); } } ?>
    <div class="org-unidade" data-unidade="<?php echo (int) $u; ?>">
      <?php echo $pessoa($om[$u]['nome'] ?? null, $om[$u]['rep'] ?? null, 'OM', 'org-om', 'grupo', '<span class="org-qtd">U' . (int) $u . ' · ' . h(tn($nu, '{n} SR', '{n} SRs')) . ($verPaginas ? ' · ' . h(tn($pgUnidade[$u] ?? 0, '{n} página', '{n} páginas')) : '') . '</span>'); ?>
      <span class="org-seta org-seta-curta" aria-hidden="true"></span>
      <div class="org-turnos">
        <?php foreach ($TURNOS as $t => [$blocos, $hora]): if (empty($porTurno[$t])) { continue; } ?>
        <section class="org-turno" aria-label="<?php echo h('U' . $u . ' · ' . $blocos . ' · ' . $hora); ?>">
          <h3 class="org-turno-cab" title="<?php echo h($hora); ?>"><?php echo $icone('relogio'); ?><?php echo h($blocos); ?></h3>
          <?php echo $pessoa($oc[$u][$t]['nome'] ?? null, $oc[$u][$t]['rep'] ?? null, 'OC', 'org-oc', 'pessoa'); ?>
          <?php foreach ($porTurno[$t] as $k => $n): ?>
          <details class="org-nucleo">
            <summary><?php echo $pessoa($n['decurio_nome'], $n['decurio_code'] ? strtoupper($n['decurio_code']) : null, 'OS ' . $k, 'org-os', 'pessoa', '<span class="org-qtd">' . h(tn(count($n['srs']), '{n} SR', '{n} SRs')) . '<svg class="org-seta-abrir" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg></span>'); ?></summary>
            <ul class="org-srs">
              <?php foreach ($n['srs'] as $s): ?>
              <li data-busca="<?php echo h(mb_strtolower($s['nome'] . ' ' . $s['rep'], 'UTF-8')); ?>" title="<?php echo h($s['rep']); ?>"><span><?php echo h($s['nome']); ?></span><span class="org-nivel">SR <?php echo $s['nivel'] ?? '?'; ?></span><?php if ($verPaginas && isset($pgRep[$s['rep']])): $pr = $pgRep[$s['rep']]; ?><span class="org-sr-paginas"><?php echo h(implode(' · ', array_filter([isset($pr['dono']) ? t('dono: {lista}', ['lista' => implode(', ', array_keys($pr['dono']))]) : '', isset($pr['backup']) ? t('backup: {lista}', ['lista' => implode(', ', array_keys($pr['backup']))]) : '']))); ?></span><?php endif; ?></li>
              <?php endforeach; ?>
              <?php if (!$n['srs']): ?><li class="org-vazio"><?php echo h(t('sem SRs')); ?></li><?php endif; ?>
            </ul>
          </details>
          <?php if ($verPaginas): $doNucleo = $pgNucleo[(int) $n['id']] ?? []; ?>
          <ul class="org-paginas" aria-label="<?php echo h(t('Páginas do núcleo {n}', ['n' => $k])); ?>">
            <?php foreach ($doNucleo as $pag => $owners): ?>
            <li class="org-pagina" data-busca="<?php echo h(mb_strtolower($pag . ' ' . implode(' ', $owners), 'UTF-8')); ?>"><strong><?php echo h($pag); ?></strong><?php echo $owners ? ' · ' . h(implode(', ', array_map(static fn($r) => nomeCurto($nomes[$r] ?? $r), $owners))) : ' · <span class="org-sem-codigo">' . h(t('sem owner')) . '</span>'; ?></li>
            <?php endforeach; ?>
            <?php if (!$doNucleo): ?><li class="org-pagina org-vazio"><?php echo h(t('nenhuma página como dono')); ?></li><?php endif; ?>
          </ul>
          <?php endif; ?>
          <?php endforeach; ?>
        </section>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<script>
(function () {
  var busca = document.getElementById('org-busca'), unidade = document.getElementById('org-unidade'), info = document.getElementById('org-achados');
  var org = document.querySelector('.org'), nucleos = document.querySelectorAll('.org-nucleo');
  function tirar(t) { return t.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); }
  function aplicar() {
    var q = tirar(busca.value.trim()), u = unidade.value, n = 0;
    document.querySelectorAll('.org-unidade').forEach(function (el) { el.hidden = u !== '' && el.dataset.unidade !== u; });
    org.classList.toggle('org-uma', u !== '');
    document.querySelectorAll('[data-busca]').forEach(function (el) {
      var ok = q !== '' && tirar(el.dataset.busca).indexOf(q) !== -1 && !el.closest('.org-unidade[hidden]');
      el.classList.toggle('org-achado', ok);
      if (ok) { n++; }
    });
    nucleos.forEach(function (d) { if (q !== '') { d.open = !!d.querySelector('li.org-achado'); } });
    org.classList.toggle('org-buscando', q !== '');
    info.textContent = q === '' ? '' : (n ? <?php echo json_encode(t('{n} encontrado(s)')); ?>.replace('{n}', n) : <?php echo json_encode(t('ninguém encontrado')); ?>);
    var primeiro = document.querySelector('.org-achado');
    if (primeiro && q.length >= 3) { primeiro.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
  }
  busca.addEventListener('input', aplicar);
  unidade.addEventListener('change', aplicar);
  document.getElementById('org-abrir').addEventListener('click', function () { nucleos.forEach(function (d) { d.open = true; }); });
  document.getElementById('org-fechar').addEventListener('click', function () { nucleos.forEach(function (d) { d.open = false; }); });
  // PDF como está na tela: com os núcleos recolhidos, sai só a liderança (como o roster). O título
  // da página vira o nome do arquivo sugerido ao salvar.
  var tituloTela = document.title;
  document.getElementById('org-imprimir').addEventListener('click', function () {
    document.title = <?php echo json_encode(t('Organograma 5C {dia}', ['dia' => date('d-m-Y', strtotime($dia))])); ?> + (unidade.value ? ' U' + unidade.value : '');
    window.print();
  });
  window.addEventListener('afterprint', function () { document.title = tituloTela; });
})();
</script>
