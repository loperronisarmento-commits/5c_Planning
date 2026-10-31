<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/owners.php
// Owners e backups de cada página: unidade e carteira, núcleo dono e de cobertura, owners (vaga
// de dono) e backups (vaga de cobertura) por turno, atingimento da meta 5CReps e a situação na
// política de páginas por mérito (lib/politica.php), com as trocas sugeridas. Troca de owner é
// aplicada aqui; troca de unidade abre a prévia e só mexe no Capacity depois da confirmação.
// ===============================================

$hoje = date('Y-m-d');
$primeiroDia = escSolPrimeiroDia();
$instalada = escPolInstalada();

if (escPost()) {
    escExigir('decidir');
    if (!$instalada) {
        throw new RuntimeException(t('A política de páginas ainda não foi instalada no banco (script ES_33). Peça ao admin.'));
    }
    $acao = $_POST['acao'] ?? '';
    $pagina = trim((string) ($_POST['pagina'] ?? ''));
    $turno = in_array($_POST['turno'] ?? '', ['A', 'C', 'D'], true) ? $_POST['turno'] : null;
    $desde = (string) ($_POST['desde'] ?? '');
    if ($acao !== 'descartar' && (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $desde) || $desde < $primeiroDia)) {
        throw new RuntimeException(t('A troca vale a partir de {dia} ou depois (não dá para mudar o que já passou).', ['dia' => dataBr($primeiroDia)]));
    }
    $nomes = escNomes();
    if ($acao === 'owner' || $acao === 'definir_owner') {
        $posto = (int) ($_POST['posto'] ?? 0);
        $para = strtoupper(trim((string) ($_POST['para'] ?? '')));
        $de = strtoupper(trim((string) ($_POST['de'] ?? '')));
        $ating = ($_POST['ating'] ?? '') !== '' ? (float) $_POST['ating'] : null;
        if (!$posto || !preg_match('~^[A-Z]\d{6}$~', $para)) {
            throw new RuntimeException(t('Escolha quem vira owner.'));
        }
        $msg = escTx(function () use ($acao, $posto, $para, $de, $desde, $pagina, $turno, $ating, $nomes) {
            if ($acao === 'owner') {
                $r = escPolTrocarOwner($posto, $de, $para, $desde, t('política: troca de owner em {pagina}', ['pagina' => $pagina]));
                escPolRegistrar('OWNER', $pagina, $turno, $de, $para, $ating, 'APLICADA', $desde, t('atingimento abaixo do corte com prazo vencido'), $r);
                return t('{entra} é owner de {pagina} ({turno}) a partir de {dia}; {sai} fica com a vaga que era de {entra}.', ['entra' => nomeCurto($nomes[$para] ?? $para), 'sai' => nomeCurto($nomes[$de] ?? $de), 'pagina' => $pagina, 'turno' => ESC_SOL_TURNO_BLOCOS[$turno] ?? '', 'dia' => dataBr($desde)]);
            }
            escPolDefinirOwner($posto, $para, $desde, t('política: owner de {pagina}', ['pagina' => $pagina]));
            escPolRegistrar('DEFINIR_OWNER', $pagina, $turno, null, $para, $ating, 'APLICADA', $desde, t('prioridade fixa'));
            return t('{entra} é owner de {pagina} ({turno}) a partir de {dia}.', ['entra' => nomeCurto($nomes[$para] ?? $para), 'pagina' => $pagina, 'turno' => ESC_SOL_TURNO_BLOCOS[$turno] ?? '', 'dia' => dataBr($desde)]);
        });
        escVoltar('owners', [], $msg . ' ' . t('Recalcule as semanas a partir dessa data em Gerar escala.'));
    }
    if ($acao === 'descartar') {
        $tipo = in_array($_POST['tipo'] ?? '', ['OWNER', 'UNIDADE', 'DEFINIR_OWNER'], true) ? $_POST['tipo'] : 'OWNER';
        escPolRegistrar($tipo, $pagina, $turno, null, null, null, 'DESCARTADA', null, mb_substr(trim((string) ($_POST['motivo'] ?? '')), 0, 255) ?: t('descartada na tela'));
        escVoltar('owners', [], t('Sugestão descartada: volta a aparecer em 7 dias se a situação continuar.'));
    }
    if ($acao === 'unidade') {
        $destino = (int) ($_POST['destino'] ?? 0);
        $av = escPolAvaliar($hoje);
        $pg = $av['paginas'][$pagina] ?? null;
        if (!$pg || !in_array($destino, [1, 2, 3], true) || $destino === $pg['unidade']) {
            throw new RuntimeException(t('Escolha uma unidade de destino diferente da atual.'));
        }
        $r = escPolAplicarUnidade($pg, $destino, $desde, trim((string) ($_POST['motivo'] ?? '')) ?: t('atingimento abaixo do corte com prazo vencido'));
        escVoltar('owners', [], t('{pagina} passa da U{de} para a U{para} a partir de {dia}: {fechados} posto(s) fechado(s) e {refeitos} refeito(s) na origem, {novos} posto(s) novo(s) no destino, {planos} escala(s) gerada(s) de novo.', [
            'pagina' => $pagina, 'de' => $r['de'], 'para' => $r['para'], 'dia' => dataBr($desde), 'fechados' => count($r['postos_fechados']), 'refeitos' => count($r['postos_refeitos']),
            'novos' => count($r['postos_novos']), 'planos' => count($r['planos'])]));
    }
    throw new RuntimeException(t('Ação desconhecida.'));
}

$av = escPolAvaliar($hoje);
$p = $av['param'];
$nomes = escNomes();
$nucleos = [];
foreach (escQ('SELECT id, unidade, turno, numero, decurio_nome FROM ' . escT('esc_nucleo') . ' WHERE vigente_ate IS NULL OR vigente_ate >= ?', [$hoje]) as $n) {
    $nucleos[(int) $n['id']] = $n;
}
$nucTexto = static fn(?int $id) => isset($nucleos[$id]) ? t('núcleo {n}', ['n' => $nucleos[$id]['numero']]) . ' (OS ' . nomeCurto($nucleos[$id]['decurio_nome']) . ')' : '—';
$pct = static fn($a) => $a === null ? '—' : num($a * 100, 0) . '%';
$SIT = [
    'AGUARDANDO' => [t('aguardando dados'), 'cinza'], 'CARENCIA' => [t('carência'), 'cinza'], 'META' => [t('na meta'), 'verde'], 'DESTAQUE' => [t('destaque'), 'verde'],
    'ACOMPANHAMENTO' => [t('acompanhamento'), 'amarelo'], 'ACOMP_VENCIDO' => [t('acompanhamento vencido'), 'laranja'], 'PRAZO' => [t('prazo correndo'), 'laranja'], 'VENCIDO' => [t('prazo vencido'), 'vermelho'],
];
$sitTexto = static function (array $s) use ($SIT): string {
    $txt = $SIT[$s['situacao']][0];
    if (in_array($s['situacao'], ['PRAZO', 'ACOMPANHAMENTO'], true) && $s['faltam'] !== null) {
        $txt .= ' · ' . tn((int) $s['faltam'], 'falta {n} dia', 'faltam {n} dias');
    } elseif ($s['situacao'] === 'CARENCIA' && $s['vence']) {
        $txt .= ' · ' . t('até {dia}', ['dia' => dataBr($s['vence'])]);
    } elseif ($s['situacao'] === 'AGUARDANDO') {
        $txt .= ' · ' . tn((int) $s['dias'], '{n} dia com dado', '{n} dias com dado');
    }
    return $txt;
};
$tag = static fn(array $s) => '<span class="tag ' . $SIT[$s['situacao']][1] . '">' . h($sitTexto($s)) . '</span>';
$filtroU = (int) ($_GET['unidade'] ?? 0);
$filtroS = (string) ($_GET['situacao'] ?? '');
$GRUPO = ['acao' => ['VENCIDO', 'ACOMP_VENCIDO'], 'prazo' => ['PRAZO', 'ACOMPANHAMENTO'], 'bem' => ['META', 'DESTAQUE']];
$lista = array_filter($av['paginas'], static function ($pg) use ($filtroU, $filtroS, $GRUPO) {
    if ($filtroU && $pg['unidade'] !== $filtroU) {
        return false;
    }
    if ($filtroS === '' || !isset($GRUPO[$filtroS])) {
        return true;
    }
    if (in_array($pg['situacao']['situacao'], $GRUPO[$filtroS], true)) {
        return true;
    }
    foreach ($pg['turnos'] as $tu) {
        if (in_array($tu['situacao']['situacao'], $GRUPO[$filtroS], true)) {
            return true;
        }
    }
    return false;
});
$previa = isset($_GET['previa']) ? ($av['paginas'][(string) $_GET['previa']] ?? null) : null;
$desdePadrao = max($primeiroDia, segundaDaSemana(escMaisDias($hoje, 7)));
$pessoa = static fn(string $rep) => '<span title="' . h($rep) . '">' . h(nomeCurto($nomes[$rep] ?? $rep)) . '</span>';
?>
<header class="cabecalho">
  <span class="eyebrow"><?php echo h(t('Páginas')); ?></span>
  <h1><?php echo h(t('Owners e backups')); ?></h1>
  <p class="muted"><?php echo h(t('Quem é owner (vaga de dono) e backup (vaga de cobertura) de cada página em cada turno, o atingimento da meta 5CReps e a situação na política de páginas por mérito. Abaixo de {corte}% o prazo corre ({min} a {max} dias, conforme o atingimento); entre {corte}% e a meta, acompanhamento de {acomp} dias.', [
      'corte' => num($p['pol_corte_acomp'] * 100, 0), 'min' => (int) $p['pol_prazo_min'], 'max' => (int) $p['pol_prazo_max'], 'acomp' => (int) $p['pol_prazo_acomp']])); ?> <a href="<?php echo h(link_tela('regras')); ?>#politica"><?php echo h(t('Ver as regras')); ?></a></p>
</header>

<?php if (!$instalada): ?><section class="panel"><p class="aviso erro" role="alert"><?php echo h(t('A política de páginas ainda não foi instalada no banco (script ES_33): a tela mostra owners e atingimento, mas sem prioridades nem trocas.')); ?></p></section><?php endif; ?>

<?php if ($previa): $destinos = escPolDestinos($previa, $hoje); $dest = (int) ($_GET['destino'] ?? ($destinos[0]['nucleo']['unidade'] ?? 0)); $prev = escSaidaPrevia([$previa['pagina']], $desdePadrao); $plano = $dest ? escPolPlanoDestino($previa, $dest, $desdePadrao) : null; ?>
<section class="panel" id="previa">
  <h2><?php echo h(t('Prévia: {pagina} sai da U{u}', ['pagina' => $previa['pagina'], 'u' => $previa['unidade']])); ?></h2>
  <p class="small"><?php echo h(t('Atingimento na unidade: {a} ({sit}).', ['a' => $pct($previa['situacao']['ating']), 'sit' => $sitTexto($previa['situacao'])])); ?></p>
  <form method="get" class="acoes"><input type="hidden" name="p" value="owners"><input type="hidden" name="previa" value="<?php echo h($previa['pagina']); ?>">
    <label class="small"><?php echo h(t('Destino')); ?> <select name="destino" onchange="this.form.submit()"><?php foreach ($destinos as $d): $u = $d['nucleo']['unidade']; ?><option value="<?php echo $u; ?>" <?php echo $u === $dest ? 'selected' : ''; ?>><?php echo h('U' . $u . ' · ' . t('nota {n}', ['n' => $d['nota']]) . ' · ' . t('saldo {antes} → {depois}', ['antes' => $d['saldo_u_antes'], 'depois' => $d['saldo_u_depois']])); ?></option><?php endforeach; ?></select></label>
  </form>
  <div class="colunas-acoes small">
    <div><strong><?php echo h(t('Na U{u} (origem)', ['u' => $previa['unidade']])); ?></strong><ul>
      <li><?php echo h(tn(count($prev['fechados'] ?? []), '{n} posto fecha', '{n} postos fecham')); ?></li>
      <li><?php echo h(tn(count($prev['refeitos'] ?? []), '{n} posto é refeito sem a página', '{n} postos são refeitos sem a página')); ?></li>
      <li><?php echo h(tn(count($prev['sem_vaga'] ?? []), '{n} SR perde a vaga fixa (vai para outra vaga do núcleo ou fica livre)', '{n} SRs perdem a vaga fixa (vão para outra vaga do núcleo ou ficam livres)')); ?></li>
    </ul></div>
    <?php if ($plano): ?>
    <div><strong><?php echo h(t('Na U{u} (destino), carteira {c}', ['u' => $dest, 'c' => $plano['carteira']])); ?></strong><ul>
      <?php foreach ($plano['turnos'] as $t => $x): ?>
      <li><?php echo h(ESC_SOL_TURNO_BLOCOS[$t] . ': ' . tn($x['postos'], '{n} posto', '{n} postos') . ' · ' . t('dono {dono}, cobertura {cob}', ['dono' => $nucTexto($x['dono']['id']), 'cob' => $nucTexto($x['cobertura']['id'])])
          . ($x['owner'] ? ' · ' . t('owner {nome} (prioridade)', ['nome' => nomeCurto($nomes[$x['owner']] ?? $x['owner'])]) : '')); ?></li>
      <?php endforeach; ?>
    </ul></div>
    <?php endif; ?>
  </div>
  <?php if ($plano && escPode('decidir') && $instalada): ?>
  <form method="post" action="<?php echo h(link_tela('owners')); ?>" class="acoes">
    <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="unidade"><input type="hidden" name="pagina" value="<?php echo h($previa['pagina']); ?>"><input type="hidden" name="destino" value="<?php echo $dest; ?>">
    <label class="small"><?php echo h(t('Vale a partir de')); ?> <input type="date" name="desde" required min="<?php echo h($primeiroDia); ?>" value="<?php echo h($desdePadrao); ?>"></label>
    <label class="small"><?php echo h(t('Motivo')); ?> <input type="text" name="motivo" maxlength="255"></label>
    <button type="submit" class="botao perigo" data-longo="<?php echo h(t('Trocando a página de unidade (Capacity e escala)…')); ?>" data-confirmar="<?php echo h(t('Confirmar e aplicar? {pagina} sai da carteira da U{de} e entra na U{para} no Capacity, o Capacity é recalculado, os postos são refeitos e as semanas geradas de novo. Para desfazer, é preciso outra troca.', ['pagina' => $previa['pagina'], 'de' => $previa['unidade'], 'para' => $dest])); ?>"><?php echo h(t('Confirmar e aplicar')); ?></button>
    <a class="botao" href="<?php echo h(link_tela('owners')); ?>"><?php echo h(t('Fechar a prévia')); ?></a>
  </form>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($av['sugestoes']): ?>
<section class="panel" id="sugestoes-politica">
  <h2><?php echo h(tn(count($av['sugestoes']), 'Troca sugerida · {n}', 'Trocas sugeridas · {n}')); ?></h2>
  <?php foreach ($av['sugestoes'] as $s): $bl = $s['turno'] ? ESC_SOL_TURNO_BLOCOS[$s['turno']] : ''; ?>
  <article class="cartao-csv">
    <div class="linha-titulo"><strong><?php echo h($s['pagina'] . ($bl ? ' · ' . $bl : '')); ?></strong>
      <span class="tag <?php echo $s['motivo'] === 'prioridade' ? 'amarelo' : 'vermelho'; ?>"><?php echo h(['OWNER' => t('troca de owner'), 'DEFINIR_OWNER' => t('owner da prioridade'), 'UNIDADE' => t('troca de unidade')][$s['tipo']]); ?></span></div>
    <span class="small"><?php
      if ($s['tipo'] === 'UNIDADE') {
          echo h(t('A página está em {a} na U{u} e o prazo venceu: sugestão de levar para outra unidade.', ['a' => $pct($s['ating']), 'u' => $s['de']]));
      } elseif ($s['tipo'] === 'OWNER') {
          echo h(t('O turno está em {a} e o prazo venceu: {sai} sai e {entra} (melhor mérito do núcleo dono) vira owner, trocando de vaga com {sai}.', ['a' => $pct($s['ating']), 'sai' => nomeCurto($nomes[$s['de']] ?? $s['de']), 'entra' => nomeCurto($nomes[$s['para']] ?? $s['para'])]));
      } else {
          echo h(t('{entra} tem prioridade nesta página e está no núcleo dono do turno: vira owner (sem tirar ninguém).', ['entra' => nomeCurto($nomes[$s['para']] ?? $s['para'])]));
      } ?></span>
    <?php if (escPode('decidir') && $instalada): ?>
    <div class="acoes">
      <?php if ($s['tipo'] === 'UNIDADE'): ?>
      <a class="botao primario" href="<?php echo h(link_tela('owners', ['previa' => $s['pagina']])); ?>#previa"><?php echo h(t('Ver a prévia')); ?></a>
      <?php else: ?>
      <form method="post" action="<?php echo h(link_tela('owners')); ?>" class="acoes">
        <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="<?php echo $s['tipo'] === 'OWNER' ? 'owner' : 'definir_owner'; ?>">
        <input type="hidden" name="pagina" value="<?php echo h($s['pagina']); ?>"><input type="hidden" name="turno" value="<?php echo h((string) $s['turno']); ?>"><input type="hidden" name="posto" value="<?php echo (int) $s['posto']; ?>">
        <input type="hidden" name="de" value="<?php echo h($s['de'] ?? ''); ?>"><input type="hidden" name="para" value="<?php echo h($s['para']); ?>"><input type="hidden" name="ating" value="<?php echo h($s['ating'] !== null ? (string) $s['ating'] : ''); ?>">
        <label class="small"><?php echo h(t('Vale a partir de')); ?> <input type="date" name="desde" required min="<?php echo h($primeiroDia); ?>" value="<?php echo h($desdePadrao); ?>"></label>
        <button type="submit" class="botao primario" data-confirmar="<?php echo h(t('Aplicar a troca de owner em {pagina}? Para desfazer, é preciso outra troca.', ['pagina' => $s['pagina']])); ?>"><?php echo h(t('Aplicar')); ?></button>
      </form>
      <?php endif; ?>
      <form method="post" action="<?php echo h(link_tela('owners')); ?>"><?php echo campoCsrf(); ?><input type="hidden" name="acao" value="descartar"><input type="hidden" name="tipo" value="<?php echo h($s['tipo']); ?>"><input type="hidden" name="pagina" value="<?php echo h($s['pagina']); ?>"><input type="hidden" name="turno" value="<?php echo h((string) $s['turno']); ?>"><button type="submit" class="botao"><?php echo h(t('Descartar por 7 dias')); ?></button></form>
    </div>
    <?php endif; ?>
  </article>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<section class="panel">
  <form method="get" class="acoes nao-imprimir"><input type="hidden" name="p" value="owners">
    <label class="small"><?php echo h(t('Unidade')); ?> <select name="unidade" onchange="this.form.submit()"><option value="0"><?php echo h(t('Todas as unidades')); ?></option><?php foreach ([1, 2, 3] as $u): ?><option value="<?php echo $u; ?>" <?php echo $filtroU === $u ? 'selected' : ''; ?>>U<?php echo $u; ?></option><?php endforeach; ?></select></label>
    <label class="small"><?php echo h(t('Situação')); ?> <select name="situacao" onchange="this.form.submit()"><option value=""><?php echo h(t('Todas')); ?></option><option value="acao" <?php echo $filtroS === 'acao' ? 'selected' : ''; ?>><?php echo h(t('Prazo vencido')); ?></option><option value="prazo" <?php echo $filtroS === 'prazo' ? 'selected' : ''; ?>><?php echo h(t('Prazo correndo ou acompanhamento')); ?></option><option value="bem" <?php echo $filtroS === 'bem' ? 'selected' : ''; ?>><?php echo h(t('Na meta ou destaque')); ?></option></select></label>
    <input type="search" id="owners-busca" placeholder="<?php echo h(t('Buscar página ou pessoa…')); ?>" aria-label="<?php echo h(t('Buscar página ou pessoa')); ?>">
    <button type="button" class="botao" onclick="window.print()"><?php echo h(t('Imprimir / PDF')); ?></button>
  </form>
  <div class="tabela-rolagem"><table class="owners-tabela" data-sem-ordem>
    <thead><tr><th scope="col"><?php echo h(t('Página')); ?></th><th scope="col"><?php echo h(t('Unidade')); ?></th><th scope="col"><?php echo h(t('Atingimento')); ?></th>
      <?php foreach (ESC_SOL_TURNO_BLOCOS as $t => $bl): ?><th scope="col"><?php echo h($bl); ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($lista as $pg): $busca = mb_strtolower($pg['pagina'] . ' ' . implode(' ', array_map(static fn($tu) => implode(' ', array_map(static fn($r) => ($nomes[$r] ?? $r) . ' ' . $r, array_merge(array_keys($tu['owners']), array_keys($tu['backups'])))), $pg['turnos'])), 'UTF-8'); ?>
      <tr data-busca="<?php echo h($busca); ?>">
        <td data-rotulo="<?php echo h(t('Página')); ?>"><strong><?php echo h($pg['pagina']); ?></strong><?php if ($pg['prioridade']): ?><br><span class="tag amarelo" title="<?php echo h($pg['prioridade']['motivo']); ?>"><?php echo h(t('prioridade')); ?></span><?php endif; ?></td>
        <td data-rotulo="<?php echo h(t('Unidade')); ?>">U<?php echo (int) $pg['unidade']; ?><br><span class="muted small"><?php echo h($pg['carteira']); ?></span></td>
        <td data-rotulo="<?php echo h(t('Atingimento')); ?>"><strong><?php echo h($pct($pg['situacao']['ating'])); ?></strong> <span class="muted small"><?php echo h(t('7d {a7} · 14d {a14}', ['a7' => $pct($pg['situacao']['ating7']), 'a14' => $pct($pg['situacao']['ating14'])])); ?></span><br><?php echo $tag($pg['situacao']); ?></td>
        <?php foreach (ESC_SOL_TURNO_BLOCOS as $t => $bl): $tu = $pg['turnos'][$t] ?? null; ?>
        <td data-rotulo="<?php echo h($bl); ?>"><div class="owners-turno">
          <?php if ($tu): ?>
            <span class="small"><?php echo h(t('Owner')); ?>: <?php echo $tu['owners'] ? implode(', ', array_map($pessoa, array_keys($tu['owners']))) : '<span class="laranja">' . h(t('sem owner')) . '</span>'; ?></span>
            <span class="small muted"><?php echo h(t('Backup')); ?>: <?php echo $tu['backups'] ? implode(', ', array_map($pessoa, array_keys($tu['backups']))) : '—'; ?></span>
            <span class="small muted"><?php echo h($nucTexto($tu['dono'])); ?></span>
            <span class="small"><?php echo h($pct($tu['situacao']['ating'])); ?> <?php echo $tag($tu['situacao']); ?></span>
          <?php else: ?><span class="muted small">—</span><?php endif; ?>
        </div></td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$lista): ?><tr><td colspan="6" class="muted"><?php echo h(t('Nenhuma página neste filtro.')); ?></td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <?php if ($av['destaque']): ?><p class="small"><?php echo h(t('Núcleos em destaque (candidatos a página maior): {lista}.', ['lista' => implode(', ', array_map(static fn($id, $n) => 'U' . ($nucleos[$id]['unidade'] ?? '?') . ' ' . ($nucleos[$id]['turno'] ?? '') . ' ' . $nucTexto($id) . ' (' . $n . ')', array_keys($av['destaque']), $av['destaque']))])); ?></p><?php endif; ?>
</section>
<script>
(function () {
  var b = document.getElementById('owners-busca');
  if (!b) { return; }
  var tirar = function (s) { return s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); };
  b.addEventListener('input', function () {
    var q = tirar(b.value.trim());
    document.querySelectorAll('.owners-tabela tbody tr[data-busca]').forEach(function (tr) { tr.hidden = q !== '' && tirar(tr.dataset.busca).indexOf(q) === -1; });
  });
})();
</script>
