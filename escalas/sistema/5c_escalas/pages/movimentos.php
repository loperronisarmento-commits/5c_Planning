<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/movimentos.php
// Movimentações e Anjos (1.37.0, lib/base_semana.php, ES_213): o que o Gerar mudou em relação à semana anterior (a base),
// o corte 80/20 e o Anjo de cada OS (1 SR por núcleo que fica mais uma semana na página). O Planejamento registra o Anjo;
// vale no próximo Gerar ou recálculo da semana.
// ===============================================

escExigirRestrito(); // score do KPI por SR: só WFM/Planejamento e Admin.
$hoje = date('Y-m-d');
$semanas = [];
for ($i = -1; $i <= 2; $i++) {
    $semanas[] = date('Y-m-d', strtotime(segundaDaSemana($hoje) . " +{$i} week"));
}
$seg = segundaDaSemana(escDataValida($_GET['semana'] ?? null) ?? $semanas[2]);
if (!in_array($seg, $semanas, true)) {
    $semanas[] = $seg;
    sort($semanas);
}
$nomes = escNomes();
$nome = static fn(?string $r): string => $r ? nomeCurto($nomes[$r] ?? $r) : '—';

if (!escBaseInstalado()) {
    ?>
<header class="cabecalho"><span class="eyebrow"><?php echo h(t('Escala')); ?></span><h1><?php echo h(t('Movimentações e Anjos')); ?></h1></header>
<section class="panel"><p class="aviso erro" role="alert"><?php echo h(t('A tela ainda não foi instalada no banco (script ES_213). Peça ao admin.')); ?></p></section>
    <?php
    return;
}

if (escPost()) {
    escExigir('planejar');
    $segP = segundaDaSemana(escDataValida($_POST['semana'] ?? null) ?? $seg);
    $nid = (int) ($_POST['nucleo_id'] ?? 0);
    $rep = strtoupper(trim((string) ($_POST['rep_code'] ?? '')));
    if ($nid <= 0) {
        throw new RuntimeException(t('Núcleo inválido. Recarregue a tela.'));
    }
    $antes = escQ1('SELECT * FROM ' . escT('esc_anjo') . ' WHERE semana = ? AND nucleo_id = ?', [$segP, $nid]);
    if ($rep === '') {
        escW('DELETE FROM ' . escT('esc_anjo') . ' WHERE semana = ? AND nucleo_id = ?', [$segP, $nid]);
        escLog('anjo.remover', 'esc_anjo', $nid, $antes, null);
        escVoltar('movimentos', ['semana' => $segP], t('Anjo retirado. Vale no próximo Gerar ou recálculo da semana.'), false, 'n' . $nid);
    }
    if (!preg_match('~^[A-Z0-9]{4,30}$~', $rep)) {
        throw new RuntimeException(t('Escolha o SR na lista.'));
    }
    escW('INSERT INTO ' . escT('esc_anjo') . ' (semana, nucleo_id, rep_code, observacao, criado_por) VALUES (?, ?, ?, ?, ?)
          ON DUPLICATE KEY UPDATE rep_code = VALUES(rep_code), observacao = VALUES(observacao), criado_por = VALUES(criado_por), criado_em = NOW()',
        [$segP, $nid, $rep, mb_substr(trim((string) ($_POST['observacao'] ?? '')), 0, 255) ?: null, escUserId() ?: null]);
    escLog('anjo.gravar', 'esc_anjo', $nid, $antes, ['semana' => $segP, 'nucleo_id' => $nid, 'rep_code' => $rep]);
    escVoltar('movimentos', ['semana' => $segP], t('Anjo de {nome} registrado. Gere ou recalcule a semana para valer.', ['nome' => $nome($rep)]), false, 'n' . $nid);
}

$plano = escPlanoAtual($seg);
$movs = $plano ? escQ('SELECT * FROM ' . escT('esc_base_movimento') . ' WHERE plano_id = ? ORDER BY unidade, turno, nucleo_id, tipo, impacto DESC', [(int) $plano['id']]) : [];
$resumo = $plano ? (json_decode((string) ($plano['resumo'] ?? ''), true)['base_semana'] ?? null) : null;
$anjos = escBaseAnjos($seg);
$nucleos = [];
foreach (escQ('SELECT id, unidade, turno, numero, decurio_nome FROM ' . escT('esc_nucleo') . ' WHERE vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?) ORDER BY unidade, FIELD(turno, \'A\', \'C\', \'D\'), numero', [escMaisDias($seg, 6), $seg]) as $n) {
    $nucleos[(int) $n['id']] = $n;
}
$porNucleo = [];
foreach ($movs as $m) {
    $porNucleo[(int) $m['nucleo_id']][] = $m;
}
// Primeiro quem muda (e o Anjo), depois quem fica pelo 80/20 e por último as obrigatórias; dentro, o maior impacto.
$ordemSit = ['MUDA' => 0, 'ANJO' => 1, 'FICA' => 2, 'FORCADA' => 3];
foreach ($porNucleo as &$lsN) {
    usort($lsN, static fn($a, $b) => [$ordemSit[$a['situacao']] ?? 9, -(float) $a['impacto'], $a['rep_code']] <=> [$ordemSit[$b['situacao']] ?? 9, -(float) $b['impacto'], $b['rep_code']]);
}
unset($lsN);
$rotSit = ['MUDA' => t('Muda'), 'FICA' => t('Fica (80/20)'), 'ANJO' => t('Anjo'), 'FORCADA' => t('Obrigatória')];
$clsSit = ['MUDA' => 'laranja', 'FICA' => 'verde', 'ANJO' => 'azul', 'FORCADA' => 'cinza'];
$subSit = static fn(array $m): string => match (true) {
    $m['tipo'] === 'NUCLEO' => t('mudou de unidade, turno ou núcleo'),
    $m['situacao'] === 'MUDA' => t('troca do motor'),
    $m['situacao'] === 'FICA' => t('troca fora dos 20%'),
    $m['situacao'] === 'ANJO' => t('escolhido pelo OS'),
    default => '',
};
$us = static fn($v): string => $v === null ? '—' : 'US$ ' . number_format((float) $v, 0, ',', '.');
?>
<header class="cabecalho">
  <span class="eyebrow"><?php echo h(t('Escala')); ?></span>
  <h1><?php echo h(t('Movimentações e Anjos')); ?></h1>
  <p class="muted"><?php echo h(t('A semana anterior é a base: cada SR fica nas páginas em que estava. Das trocas que o motor propõe, só 20% acontecem, as de maior impacto (faturamento da página × ganho de KPI); mudança de unidade, turno ou núcleo conta nesses 20%. Saída, página que saiu e equipe fixa nova ficam fora da conta. Cada OS pode escolher 1 Anjo: um SR que ia mudar e fica mais uma semana na página.')); ?></p>
</header>

<form method="get" class="acoes nao-imprimir">
  <input type="hidden" name="p" value="movimentos">
  <label><?php echo h(t('Semana')); ?>
    <select name="semana" onchange="this.form.submit()">
      <?php foreach ($semanas as $s): ?><option value="<?php echo h($s); ?>"<?php echo $s === $seg ? ' selected' : ''; ?>><?php echo h(dataBr($s) . ' a ' . dataBr(escMaisDias($s, 6))); ?></option><?php endforeach; ?>
    </select>
  </label>
</form>

<?php if (!$plano): ?>
<section class="panel"><p class="muted"><?php echo h(t('Esta semana ainda não tem escala gerada.')); ?></p></section>
<?php elseif (!$resumo): ?>
<section class="panel"><p class="muted"><?php echo h(t('A versão {versao} foi gerada sem a base da semana anterior (antes da 1.37.0, no mínimo de mudanças ou com o parâmetro v4_base_semana_anterior desligado). Gere ou recalcule a semana para ver as movimentações.', ['versao' => escPlanoRotulo($plano)])); ?></p></section>
<?php else: ?>
<div class="kpis">
  <div class="kpi"><span class="muted"><?php echo h(t('Base')); ?></span><strong><?php echo h(t('semana anterior {v}', ['v' => (string) ($resumo['plano_base_rotulo'] ?? '—')])); ?></strong><span class="muted small"><?php echo h(t('{n} SRs com página', ['n' => (int) $resumo['srs_base']])); ?></span></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Trocas propostas')); ?></span><strong><?php echo (int) $resumo['propostas']; ?></strong><span class="muted small"><?php echo h(t('limite de 20%: {n}', ['n' => (int) $resumo['limite']])); ?></span></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Mudam')); ?></span><strong><?php echo (int) $resumo['muda']; ?></strong><span class="muted small"><?php echo h(t('{n} por unidade, turno ou núcleo', ['n' => (int) $resumo['nucleo']])); ?></span></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Impacto das que mudam')); ?></span><strong><?php echo $resumo['impacto_pct'] === null ? '—' : h(str_replace('.', ',', (string) $resumo['impacto_pct']) . '%'); ?></strong><span class="muted small"><?php echo h(t('do impacto positivo das trocas do motor')); ?></span></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Ficam (80/20) e Anjos')); ?></span><strong><?php echo (int) $resumo['fica'] + (int) $resumo['anjo']; ?></strong><span class="muted small"><?php echo h(t('{n} Anjo(s)', ['n' => (int) $resumo['anjo']])); ?></span></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Obrigatórias')); ?></span><strong><?php echo (int) $resumo['forcada']; ?></strong><span class="muted small"><?php echo h(t('fora da conta dos 20%')); ?></span></div>
</div>
<p class="muted small"><?php echo h(t('Versão {versao} da semana. O Anjo vale no próximo Gerar ou recálculo (Gerar escala › Recalcular).', ['versao' => escPlanoRotulo($plano)])); ?></p>
<?php endif; ?>

<?php foreach ($nucleos as $nid => $n):
    $lista = $porNucleo[$nid] ?? [];
    $candidatos = array_values(array_filter($lista, static fn($m) => $m['tipo'] === 'OPCIONAL' && in_array($m['situacao'], ['MUDA', 'ANJO'], true)));
    $anjo = $anjos[$nid] ?? null;
    if (!$lista && !$anjo) {
        continue;
    }
?>
<section class="panel" id="n<?php echo (int) $nid; ?>">
  <h2><?php echo h('U' . $n['unidade'] . ' · ' . t('turno {t}', ['t' => $n['turno']]) . ' · ' . t('núcleo {n}', ['n' => $n['numero']]) . ' · OS ' . nomeCurto((string) $n['decurio_nome'])); ?></h2>
  <?php if ($lista): ?>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th><?php echo h(t('SR')); ?></th><th><?php echo h(t('Situação')); ?></th><th><?php echo h(t('Página na semana anterior')); ?></th><th><?php echo h(t('Na proposta do motor')); ?></th><th><?php echo h(t('Quem entra')); ?></th><th class="num"><?php echo h(t('KPI sai → entra')); ?></th><th class="num"><?php echo h(t('Impacto')); ?></th><th><?php echo h(t('Motivo')); ?></th></tr></thead>
    <tbody>
    <?php foreach ($lista as $m): ?>
      <tr>
        <td><?php echo h($nome($m['rep_code'])); ?> <span class="muted small"><?php echo h($m['rep_code']); ?></span></td>
        <td><span class="tag <?php echo h($clsSit[$m['situacao']] ?? ''); ?>"><?php echo h($rotSit[$m['situacao']] ?? $m['situacao']); ?></span><?php if ($subSit($m) !== ''): ?><br><span class="muted small"><?php echo h($subSit($m)); ?></span><?php endif; ?></td>
        <td><?php echo h((string) $m['paginas_base']); ?></td>
        <td><?php echo h($m['pagina_para'] ?? t('sem página (buffer)')); ?></td>
        <td><?php echo h($nome($m['entra_rep'])); ?></td>
        <td class="num"><?php echo h(($m['score_sai'] === null ? '—' : str_replace('.', ',', (string) (float) $m['score_sai']) . '%') . ' → ' . ($m['score_entra'] === null ? '—' : str_replace('.', ',', (string) (float) $m['score_entra']) . '%')); ?></td>
        <td class="num"><?php echo h($us($m['impacto'])); ?></td>
        <td class="small"><?php echo h((string) $m['motivo']); ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
  <?php if (escPode('planejar') && ($candidatos || $anjo)): ?>
  <form method="post" action="<?php echo h(link_tela('movimentos', ['semana' => $seg])); ?>" class="acoes nao-imprimir">
    <?php echo campoCsrf(); ?><input type="hidden" name="semana" value="<?php echo h($seg); ?>"><input type="hidden" name="nucleo_id" value="<?php echo (int) $nid; ?>">
    <label style="min-width:0;max-width:100%"><?php echo h(t('Anjo do OS')); ?>
      <select name="rep_code" style="max-width:100%">
        <option value=""><?php echo h(t('— sem Anjo —')); ?></option>
        <?php $tem = false; foreach ($candidatos as $c): $tem = $tem || $c['rep_code'] === $anjo; ?>
        <option value="<?php echo h($c['rep_code']); ?>"<?php echo $c['rep_code'] === $anjo ? ' selected' : ''; ?>><?php echo h($nome($c['rep_code']) . ' · ' . mb_strimwidth((string) $c['paginas_base'], 0, 40, '…')); ?></option>
        <?php endforeach; ?>
        <?php if ($anjo && !$tem): ?><option value="<?php echo h($anjo); ?>" selected><?php echo h($nome($anjo) . ' · ' . t('não muda nesta versão')); ?></option><?php endif; ?>
      </select>
    </label>
    <label><?php echo h(t('Observação')); ?><input type="text" name="observacao" maxlength="255"></label>
    <button type="submit" class="botao"><?php echo h(t('Salvar Anjo')); ?></button>
  </form>
  <?php elseif (escPode('planejar')): ?>
  <p class="muted small"><?php echo h(t('Ninguém deste núcleo muda de página por troca do motor nesta versão: não há quem escolher.')); ?></p>
  <?php endif; ?>
</section>
<?php endforeach; ?>
