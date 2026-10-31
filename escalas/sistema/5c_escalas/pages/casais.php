<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/casais.php
// Folga juntos (lib/casais.php, ES_45): associar as pessoas que precisam folgar nos mesmos dias,
// ver as folgas dos próximos 21 dias de cada par e aplicar o ajuste que alinha o ciclo.
// ===============================================

if (!escCasaisInstalado()) {
    ?>
<header class="cabecalho"><span class="eyebrow"><?php echo h(t('Pessoas')); ?></span><h1><?php echo h(t('Folga juntos')); ?></h1></header>
<section class="panel"><p class="aviso erro" role="alert"><?php echo h(t('A tela Folga juntos ainda não foi instalada no banco (script ES_45). Peça ao admin.')); ?></p></section>
    <?php
    return;
}

$hoje = date('Y-m-d');
$base = max($hoje, escV4DiaEstrutura());
$proxSeg = max(date('Y-m-d', strtotime('next monday')), escV4DiaEstrutura());
$nomes = escNomes();
$nome = static fn(string $r): string => ($nomes[$r] ?? $r);

if (escPost()) {
    escExigir('planejar');
    $acao = $_POST['acao'] ?? '';
    $data = static fn(string $c, string $padrao): string => preg_match('~^\d{4}-\d{2}-\d{2}$~', (string) ($_POST[$c] ?? '')) ? $_POST[$c] : $padrao;
    if ($acao === 'incluir') {
        $a = escCasalCodigo((string) ($_POST['pessoa_a'] ?? ''));
        $b = escCasalCodigo((string) ($_POST['pessoa_b'] ?? ''));
        if (!$a || !$b) {
            throw new RuntimeException(t('Escolha as duas pessoas na lista (nome e código).'));
        }
        if ($a === $b) {
            throw new RuntimeException(t('Escolha duas pessoas diferentes.'));
        }
        if (!isset($nomes[$a]) || !isset($nomes[$b])) {
            throw new RuntimeException(t('Código não encontrado no headcount: {rep}.', ['rep' => !isset($nomes[$a]) ? $a : $b]));
        }
        $desde = $data('desde', $hoje);
        foreach (escCasaisPares($desde, true) as $p) {
            foreach ([$a, $b] as $r) {
                if (in_array($r, [$p['rep_a'], $p['rep_b']], true)) {
                    throw new RuntimeException(t('{nome} já está num par de Folga juntos. Encerre o par antes.', ['nome' => $nome($r)]));
                }
            }
        }
        escW('INSERT INTO ' . escT('esc_folga_par') . ' (rep_a, rep_b, observacao, vigente_desde, criado_por) VALUES (?, ?, ?, ?, ?)',
            [$a, $b, mb_substr(trim((string) ($_POST['observacao'] ?? '')), 0, 255) ?: null, $desde, escUserId() ?: null]);
        escLog('casal.incluir', 'esc_folga_par', escUltimoId(), null, ['rep_a' => $a, 'rep_b' => $b, 'desde' => $desde]);
        escVoltar('casais', [], t('{a} e {b} associados: folgam juntos a partir do próximo recálculo, se o ciclo dos dois já bate. Se não bater, aplique um dos ajustes do par.', ['a' => nomeCurto($nome($a)), 'b' => nomeCurto($nome($b))]));
    }
    $id = (int) ($_POST['par_id'] ?? 0);
    $par = escQ1('SELECT * FROM ' . escT('esc_folga_par') . ' WHERE id = ?', [$id]);
    if (!$par) {
        throw new RuntimeException(t('Par não encontrado. Recarregue a tela.'));
    }
    if ($acao === 'encerrar') {
        if (substr((string) $par['vigente_desde'], 0, 10) >= $hoje) {
            escW('DELETE FROM ' . escT('esc_folga_par') . ' WHERE id = ?', [$id]);
        } else {
            escW('UPDATE ' . escT('esc_folga_par') . ' SET vigente_ate = ? WHERE id = ?', [escMaisDias($hoje, -1), $id]);
        }
        escLog('casal.encerrar', 'esc_folga_par', $id, $par, null);
        escVoltar('casais', [], t('Par encerrado: as folgas seguem o ciclo de cada um a partir do próximo recálculo.'));
    }
    if ($acao === 'ajustar') {
        $desde = $data('desde', $proxSeg);
        $op = escCasalAplicar($id, (int) ($_POST['opcao'] ?? -1), $desde);
        escVoltar('casais', [], t('{ajuste}, a partir de {dia}. Recalcule as semanas a partir dessa data (Gerar escala › Recalcular com o mínimo de mudanças).', [
            'ajuste' => escCasalTextoOpcao($op, $nomes), 'dia' => date('d/m/Y', strtotime($desde))]));
    }
    throw new RuntimeException(t('Ação desconhecida.'));
}

$sit = escCasaisSituacao($base);
$estrutura = escCasalPessoas($base)['pessoas'];
$cargoTxt = static function (?array $p): string {
    if (!$p) {
        return t('fora da estrutura');
    }
    $partes = [$p['cargo'] . ($p['cargo'] === 'SR' && $p['nivel'] !== null ? ' ' . $p['nivel'] : '')];
    if ($p['unidade']) {
        $partes[] = 'U' . $p['unidade'];
    }
    if ($p['turno']) {
        $partes[] = t('turno {t}', ['t' => $p['turno']]);
    }
    if ($p['numero']) {
        $partes[] = t('núcleo {n}', ['n' => $p['numero']]);
    }
    $partes[] = $p['padrao'] !== null ? t('semana fixa {s}', ['s' => $p['padrao']]) : ($p['deslocamento'] !== null ? t('ciclo {d}', ['d' => $p['deslocamento']]) : t('sem ciclo'));
    return implode(' · ', $partes);
};
$diasTxt = static fn(array $dias): string => $dias ? implode(' ', array_map(static fn($d) => date('d/m', strtotime($d)), $dias)) : '—';
$alinhados = count(array_filter($sit, static fn($s) => $s['alinhado']));
?>
<header class="cabecalho">
  <span class="eyebrow"><?php echo h(t('Pessoas')); ?></span>
  <h1><?php echo h(t('Folga juntos')); ?></h1>
  <p class="muted"><?php echo h(t('Pessoas que precisam folgar nos mesmos dias (casais). Folgam juntas quando estão no mesmo ponto do ciclo de 21 dias: SR e OS pelo núcleo (núcleo 1, 2 ou 3 de qualquer unidade), OC e OA pelo ciclo do Roster. O motor dá aos dois o mesmo dia de Overlap.')); ?></p>
</header>

<?php if ($sit): ?>
<div class="kpis">
  <div class="kpi"><span class="muted"><?php echo h(t('Pares')); ?></span><strong><?php echo count($sit); ?></strong></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Folgam juntos')); ?></span><strong><?php echo $alinhados; ?></strong></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Precisam de ajuste')); ?></span><strong><?php echo count($sit) - $alinhados; ?></strong></div>
</div>
<?php endif; ?>

<section class="panel">
  <h2><?php echo h(t('Pares')); ?></h2>
  <?php if (!$sit): ?><p class="muted"><?php echo h(t('Nenhum par cadastrado. Associe abaixo.')); ?></p><?php else: ?>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th><?php echo h(t('Pessoas')); ?></th><th><?php echo h(t('Folgas nos próximos 21 dias (a partir de {dia})', ['dia' => dataBr($base)])); ?></th><th><?php echo h(t('Situação')); ?></th><th><?php echo h(t('Ajuste')); ?></th></tr></thead>
    <tbody>
    <?php foreach ($sit as $s): ?>
      <tr>
        <td>
          <?php foreach ([['rep_a', 'a'], ['rep_b', 'b']] as [$r, $k]): ?>
          <div><strong><?php echo h($nome($s[$r])); ?></strong> <span class="mono small"><?php echo h($s[$r]); ?></span><br><span class="muted small"><?php echo h($cargoTxt($s[$k])); ?></span></div>
          <?php endforeach; ?>
          <?php if ($s['observacao']): ?><div class="muted small"><?php echo h($s['observacao']); ?></div><?php endif; ?>
          <?php if (substr((string) $s['vigente_desde'], 0, 10) > $base): ?><span class="tag azul"><?php echo h(t('vale a partir de {dia}', ['dia' => dataBr(substr((string) $s['vigente_desde'], 0, 10))])); ?></span><?php endif; ?>
        </td>
        <td class="small">
          <div><?php echo h(nomeCurto($nome($s['rep_a']))); ?>: <span class="mono"><?php echo h($diasTxt($s['folgas_a'])); ?></span></div>
          <div><?php echo h(nomeCurto($nome($s['rep_b']))); ?>: <span class="mono"><?php echo h($diasTxt($s['folgas_b'])); ?></span></div>
        </td>
        <td>
          <?php if ($s['alinhado']): ?><span class="tag verde"><?php echo h(t('Folgam juntos')); ?></span>
          <?php elseif ($s['sem_ciclo']): ?><span class="tag amarelo"><?php echo h(t('Sem ciclo para comparar')); ?></span>
          <?php else: ?><span class="tag laranja"><?php echo h(t('{n} de {m} folgas juntos', ['n' => count($s['juntos']), 'm' => max(count($s['folgas_a']), count($s['folgas_b']))])); ?></span><?php endif; ?>
        </td>
        <td>
          <?php if (!$s['alinhado'] && $s['opcoes'] && escPode('planejar')): ?>
            <?php foreach ($s['opcoes'] as $i => $op): ?>
            <form method="post" action="<?php echo h(link_tela('casais')); ?>" class="acoes small" data-confirmar="<?php echo h(t('Aplicar: {ajuste}?', ['ajuste' => escCasalTextoOpcao($op, $nomes)])); ?>">
              <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="ajustar"><input type="hidden" name="par_id" value="<?php echo (int) $s['id']; ?>"><input type="hidden" name="opcao" value="<?php echo (int) $i; ?>">
              <span><?php echo h(escCasalTextoOpcao($op, $nomes)); ?></span>
              <label class="small"><?php echo h(t('a partir de')); ?> <input type="date" name="desde" value="<?php echo h($proxSeg); ?>" min="<?php echo h($hoje); ?>"></label>
              <button type="submit" class="botao<?php echo $i === 0 ? ' primario' : ''; ?>"><?php echo h(t('Aplicar')); ?></button>
            </form>
            <?php endforeach; ?>
            <p class="muted small"><?php echo h(t('Mudar o ciclo no meio da rotação pode dar uma folga de emenda na semana da mudança. Quem muda de núcleo sai da equipe atual; a geração completa as vagas.')); ?></p>
          <?php elseif (!$s['alinhado'] && !$s['sem_ciclo']): ?><span class="muted small"><?php echo h(t('Sem ajuste automático (semana fixa ou cargo fora do ciclo): ajuste no Roster da liderança.')); ?></span>
          <?php endif; ?>
          <?php if (escPode('planejar')): ?>
          <form method="post" action="<?php echo h(link_tela('casais')); ?>" class="acoes" data-confirmar="<?php echo h(t('Encerrar este par?')); ?>">
            <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="encerrar"><input type="hidden" name="par_id" value="<?php echo (int) $s['id']; ?>">
            <button type="submit" class="botao"><?php echo h(t('Encerrar par')); ?></button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>

<?php if (escPode('planejar')): ?>
<section class="panel">
  <h2><?php echo h(t('Associar um par')); ?></h2>
  <datalist id="lista-pessoas">
    <?php foreach ($estrutura as $r => $p): ?><option value="<?php echo h($nome($r) . ' (' . $r . ')'); ?>"><?php echo h($cargoTxt($p)); ?></option><?php endforeach; ?>
  </datalist>
  <form method="post" action="<?php echo h(link_tela('casais')); ?>" class="form-grade">
    <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="incluir">
    <label><?php echo h(t('Pessoa 1')); ?><input type="search" name="pessoa_a" list="lista-pessoas" placeholder="<?php echo h(t('buscar nome ou código')); ?>" autocomplete="off" required></label>
    <label><?php echo h(t('Pessoa 2')); ?><input type="search" name="pessoa_b" list="lista-pessoas" placeholder="<?php echo h(t('buscar nome ou código')); ?>" autocomplete="off" required></label>
    <label><?php echo h(t('Observação')); ?><input type="text" name="observacao" maxlength="255" placeholder="<?php echo h(t('ex.: casal')); ?>"></label>
    <label><?php echo h(t('A partir de')); ?><input type="date" name="desde" value="<?php echo h($hoje); ?>"></label>
    <button type="submit" class="botao primario"><?php echo h(t('Associar')); ?></button>
  </form>
  <p class="muted small"><?php echo h(t('Cada pessoa fica em um par só. Depois de associar, veja na tabela se o par já folga junto; se não, aplique um dos ajustes e recalcule as semanas.')); ?></p>
</section>
<?php endif; ?>
