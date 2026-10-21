<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/buffers.php
// Buffers no rascunho: quantos o Capacity pede por dia e turno, quantos o
// plano usou e quem ficou sem página. Pool com a semana de cada um.
// ===============================================

$planoId = (int) ($_GET['plano'] ?? 0);
if (!$planoId) {
    $p = escPlanoAtual(date('Y-m-d', strtotime(segundaDaSemana(date('Y-m-d')) . ' +1 week'))) ?? escPlanoAtual(segundaDaSemana(date('Y-m-d')));
    $planoId = $p ? (int) $p['id'] : 0;
}
$plano = $planoId ? escQ1('SELECT * FROM ' . escT('esc_plano') . ' WHERE id = ?', [$planoId]) : null;
if (!$plano) {
    echo '<header class="cabecalho"><span class="eyebrow">Planejamento</span><h1>Buffers</h1></header><section class="panel"><p class="muted">Gere um rascunho para ver os Buffers.</p></section>';
    return;
}
$seg = substr($plano['semana_inicio'], 0, 10);
$dias = escDiasDaSemana($seg);

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
  <span class="eyebrow">Planejamento</span>
  <h1>Buffers · <?php echo h(escPlanoRotulo($plano)); ?></h1>
  <p class="muted">O pool cobre o que sobra depois dos times. "Precisa" vem do Capacity (fc_buffer_diario, já com absenteísmo); "usados" é o que o rascunho alocou; Buffer sem página vira sugestão de remoção por falta de workload.</p>
</header>

<section class="panel">
  <h2>Por dia e turno · precisa / usados / sem página</h2>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th>Turno</th><?php foreach ($dias as $d): ?><th><?php echo h(dataBr($d, true)); ?></th><?php endforeach; ?></tr></thead>
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
  <p class="muted small">Laranja: usou menos Buffers do que o Capacity reserva (a falta foi coberta pelos times, ou ficou lacuna). Azul: sobrou Buffer sem página.</p>
</section>

<section class="panel">
  <h2>Pool · <?php echo count($pool); ?> Buffers (A <?php echo $porTurno['A']; ?> · C <?php echo $porTurno['C']; ?> · D <?php echo $porTurno['D']; ?>)</h2>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th>Buffer</th><th>Turno</th><th>Modelo</th><?php foreach ($dias as $d): ?><th><?php echo h(dataBr($d, true)); ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($pool as $rep => $c): ?>
      <tr>
        <td><strong><?php echo h(nomeCurto($nomes[$rep] ?? $rep)); ?></strong><br><span class="muted small mono"><?php echo h($rep); ?></span></td>
        <td><?php echo chipTurno($c['turno']); ?></td>
        <td class="small"><?php echo h($c['modelo']); ?></td>
        <?php foreach ($dias as $d): ?>
          <td class="small"><?php echo isset($folgas[$rep][$d]) ? '<span class="muted">folga</span>' : (isset($alocPorRep[$rep][$d]) ? h($alocPorRep[$rep][$d]) : '<span class="azul">sem página</span>'); ?></td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
