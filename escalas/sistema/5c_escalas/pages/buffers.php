<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/buffers.php
// Buffers no rascunho. Estrutura nova: buffer de plantão por unidade e turno (mesma fonte de
// Horas extras). Estrutura antiga: quantos o Capacity pede por dia e turno, quantos o plano
// usou e quem ficou sem página, com o pool e a semana de cada um.
// ===============================================

$planoId = (int) ($_GET['plano'] ?? 0);
if (!$planoId) {
    $p = escPlanoAtual(date('Y-m-d', strtotime(segundaDaSemana(date('Y-m-d')) . ' +1 week'))) ?? escPlanoAtual(segundaDaSemana(date('Y-m-d')));
    $planoId = $p ? (int) $p['id'] : 0;
}
$plano = $planoId ? escQ1('SELECT * FROM ' . escT('esc_plano') . ' WHERE id = ?', [$planoId]) : null;
if (!$plano) {
    echo '<header class="cabecalho"><span class="eyebrow">' . h(t('Análise')) . '</span><h1>' . h(t('Buffers')) . '</h1></header><section class="panel"><p class="muted">' . h(t('Gere um rascunho para ver os Buffers.')) . '</p></section>';
    return;
}
$seg = substr($plano['semana_inicio'], 0, 10);
$dias = escDiasDaSemana($seg);

// Estrutura nova: buffer de plantão por unidade e turno, com a MESMA fonte das telas Horas extras
// e Análise (Capacity: presentes por dia em fc_necessidade_estrutura × buffers na página 5C
// Schedule com -2). A visão por pool (times) fica só para os planos da estrutura antiga.
if (($plano['estrutura'] ?? '') === 'V4') {
    $pagina5c = escV4Param('v4_overlap_pagina', '5C Schedule');
    $precisa = [];
    $dcNec = escQ1('SELECT MAX(data_calculo) AS d FROM `5c_planning`.`fc_necessidade_estrutura`')['d'] ?? null;
    foreach ($dcNec ? escQ('SELECT unidade, turno, presentes_dia FROM `5c_planning`.`fc_necessidade_estrutura` WHERE data_calculo = ?', [$dcNec]) : [] as $l) {
        $precisa[(int) $l['unidade'] . '|' . $l['turno']] = (int) ceil((float) $l['presentes_dia'] - 1e-9);
    }
    $unidadeDe = [];
    $fimSem = escMaisDias($seg, 6);
    foreach (escQ('SELECT UPPER(rep_code) AS rep, unidade FROM ' . escT('esc_colaborador_config') . " WHERE unidade IS NOT NULL AND vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)", [$fimSem, $fimSem]) as $l) {
        $unidadeDe[$l['rep']] = (int) $l['unidade'];
    }
    $nomes = escNomes();
    $tem = [];
    $quem = [];
    foreach (escQ("SELECT DISTINCT UPPER(rep_code) AS rep, turno, IF(bloco = 'T1', DATE_SUB(dia, INTERVAL 1 DAY), dia) AS dt FROM " . escT('esc_plano_alocacao') . "
                    WHERE plano_id = ? AND pagina = ? AND origem = 'BUFFER' AND rep_code IS NOT NULL", [$planoId, $pagina5c]) as $l) {
        $k = substr((string) $l['dt'], 0, 10) . '|' . ($unidadeDe[$l['rep']] ?? 0) . '|' . $l['turno'];
        $tem[$k] = ($tem[$k] ?? 0) + 1;
        $quem[$k][] = $l['rep'];
    }
    $virada = escV4DiaVirada();
    $turnoVirada = escV4TurnoVirada();
    $faltaTotal = 0;
    $sobraTotal = 0;
    ?>
<header class="cabecalho">
  <span class="eyebrow"><?php echo h(t('Análise')); ?></span>
  <h1><?php echo h(t('Buffers · {versao}', ['versao' => escPlanoRotulo($plano)])); ?></h1>
  <p class="muted"><?php echo h(t('Buffer de plantão por unidade e turno: quem fica sem posto no dia vai para a página {pagina} com -2 no CSV e cobre faltas. "Precisa" vem do Capacity (presentes por dia, já com absenteísmo; cálculo de {dia}), a mesma fonte das telas Horas extras e Análise.', ['pagina' => $pagina5c, 'dia' => dataBr($dcNec)])); ?></p>
</header>
<?php foreach ([1, 2, 3] as $u): ?>
<section class="panel">
  <h2><?php echo h(t('U{u} · precisa / tem', ['u' => $u])); ?></h2>
  <div class="tabela-rolagem">
  <table data-sem-cartao>
    <thead><tr><th><?php echo h(t('Turno')); ?></th><?php foreach ($dias as $d): ?><th><?php echo h(dataBr($d, true)); ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach (['A', 'C', 'D'] as $t): ?>
      <tr><th><?php echo chipTurno($t); ?></th>
      <?php foreach ($dias as $d):
          if (!escV4TurnoNovo($d, $t, $virada, $turnoVirada)) { echo '<td class="muted small">' . h(t('antes da virada')) . '</td>'; continue; }
          $k = $d . '|' . $u . '|' . $t;
          $pr = $precisa[$u . '|' . $t] ?? null;
          $tm = $tem[$k] ?? 0;
          $dif = $pr === null ? 0 : $tm - $pr;
          $faltaTotal += max(0, -$dif);
          $sobraTotal += max(0, $dif);
          $nomesK = array_map(static fn($r) => nomeCurto($nomes[$r] ?? $r), $quem[$k] ?? []);
      ?>
        <td class="<?php echo $dif < 0 ? 'laranja' : ($dif > 0 ? 'azul' : ''); ?>" title="<?php echo h(implode(', ', $nomesK)); ?>"><?php echo $pr === null ? '—' : (int) $pr; ?> / <?php echo $tm; ?><?php echo $dif < 0 ? ' <span class="small">' . h(t('(falta {n})', ['n' => -$dif])) . '</span>' : ($dif > 0 ? ' <span class="small">(+' . $dif . ')</span>' : ''); ?></td>
      <?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
<?php endforeach; ?>
<section class="panel">
  <p class="small"><?php echo t('{falta} buffer(s)-turno faltando e {sobra} sobrando na semana.', ['falta' => '<strong class="laranja">' . (int) $faltaTotal . '</strong>', 'sobra' => '<strong class="azul">' . (int) $sobraTotal . '</strong>']); ?> <?php echo t('Laranja: menos buffers de plantão do que o Capacity pede (a falta pode virar hora extra em {link}). Azul: mais do que o pedido. Passe o mouse para ver quem.', ['link' => '<a href="' . h(link_tela('horas', ['semana' => $seg])) . '">' . h(t('Horas extras')) . '</a>']); ?></p>
</section>
<?php
    return;
}

$capacity = [];
foreach (escQ('SELECT dia, a, c, d FROM `5c_planning`.`fc_buffer_diario` WHERE data_calculo = (SELECT MAX(data_calculo) FROM `5c_planning`.`fc_buffer_diario`)') as $b) {
    $capacity[(int) date('N', strtotime($b['dia']))] = ['A' => (int) $b['a'], 'C' => (int) $b['c'], 'D' => (int) $b['d']];
}
$usados = [];
foreach (escQ("SELECT dia, turno, bloco, COUNT(DISTINCT rep_code) AS n FROM " . escT('esc_plano_alocacao') . " WHERE plano_id = ? AND origem = 'BUFFER' GROUP BY dia, turno, bloco", [$planoId]) as $u) {
    $dia = substr($u['dia'], 0, 10);
    if ($u['bloco'] === ESC_TURNOS[$u['turno']][0]) {
        $usados[$dia][$u['turno']] = (int) $u['n'];
    }
}
$ociosos = [];
foreach (escQ("SELECT dados FROM " . escT('esc_sugestao') . " WHERE plano_id = ? AND tipo = 'WORKLOAD' AND dados LIKE '%buffer_ocioso%'", [$planoId]) as $s) {
    $d = json_decode((string) $s['dados'], true);
    if ($d) {
        $ociosos[$d['dia']][$d['turno']] = count($d['reps']);
    }
}
$config = escMotorConfig($seg);
$nomes = escNomes();
$pool = array_filter($config, static fn($c) => $c['tipo'] === 'BUFFER');
$alocPorRep = [];
foreach (escQ("SELECT rep_code, dia, MIN(pagina) AS pagina FROM " . escT('esc_plano_alocacao') . " WHERE plano_id = ? AND origem = 'BUFFER' GROUP BY rep_code, dia", [$planoId]) as $a) {
    $alocPorRep[$a['rep_code']][substr($a['dia'], 0, 10)] = $a['pagina'];
}
$folgas = [];
foreach (escQ('SELECT rep_code, dia FROM ' . escT('esc_plano_folga') . ' WHERE plano_id = ?', [$planoId]) as $f) {
    $folgas[$f['rep_code']][substr($f['dia'], 0, 10)] = true;
}
$porTurno = ['A' => 0, 'C' => 0, 'D' => 0];
foreach ($pool as $c) {
    $porTurno[$c['turno']]++;
}
?>
<header class="cabecalho">
  <span class="eyebrow"><?php echo h(t('Análise')); ?></span>
  <h1><?php echo h(t('Buffers · {versao}', ['versao' => escPlanoRotulo($plano)])); ?></h1>
  <p class="muted"><?php echo h(t('Estrutura antiga (antes de {dia}). O pool cobre o que sobra depois dos times. "Precisa" vem do Capacity (já com absenteísmo); "usados" é o que o rascunho alocou; Buffer sem página vira sugestão de remoção por falta de workload.', ['dia' => escData('2026-11-01')])); ?></p>
</header>

<section class="panel">
  <h2><?php echo h(t('Por dia e turno · precisa / usados / sem página')); ?></h2>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th><?php echo h(t('Turno')); ?></th><?php foreach ($dias as $d): ?><th><?php echo h(dataBr($d, true)); ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach (['A', 'C', 'D'] as $t): ?>
      <tr><th><?php echo chipTurno($t); ?></th>
      <?php foreach ($dias as $d):
          $precisa = $capacity[(int) date('N', strtotime($d))][$t] ?? null;
          $u = $usados[$d][$t] ?? 0;
          $o = $ociosos[$d][$t] ?? 0;
          $classe = $precisa !== null && $u < $precisa ? 'laranja' : ($o > 0 ? 'azul' : '');
      ?>
        <td class="<?php echo $classe; ?>"><?php echo $precisa === null ? '—' : (int) $precisa; ?> / <?php echo $u; ?><?php echo $o ? ' / ' . $o : ''; ?></td>
      <?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="muted small"><?php echo h(t('Laranja: usou menos Buffers do que o Capacity reserva (a falta foi coberta pelos times, ou ficou lacuna). Azul: sobrou Buffer sem página.')); ?></p>
</section>

<section class="panel">
  <h2><?php echo h(t('Pool · {n} Buffers (A {a} · C {c} · D {d})', ['n' => count($pool), 'a' => $porTurno['A'], 'c' => $porTurno['C'], 'd' => $porTurno['D']])); ?></h2>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th><?php echo h(t('Buffer')); ?></th><th><?php echo h(t('Turno')); ?></th><th><?php echo h(t('Modelo')); ?></th><?php foreach ($dias as $d): ?><th><?php echo h(dataBr($d, true)); ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($pool as $rep => $c): ?>
      <tr>
        <td><strong><?php echo h(nomeCurto($nomes[$rep] ?? $rep)); ?></strong><br><span class="muted small mono"><?php echo h($rep); ?></span></td>
        <td><?php echo chipTurno($c['turno']); ?></td>
        <td class="small"><?php echo h($c['modelo']); ?></td>
        <?php foreach ($dias as $d): ?>
          <td class="small"><?php echo isset($folgas[$rep][$d]) ? '<span class="muted">' . h(t('folga')) . '</span>' : (isset($alocPorRep[$rep][$d]) ? h($alocPorRep[$rep][$d]) : '<span class="azul">' . h(t('sem página')) . '</span>'); ?></td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
