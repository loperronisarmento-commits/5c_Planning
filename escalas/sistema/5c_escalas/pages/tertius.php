<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/tertius.php
// Equilíbrio dos Tertius no rascunho: quantos turnos como regular e como
// assistente, faltas das últimas 4 semanas e o alvo.
// O desempenho (eixo do 5C George) ainda não está ligado: o alvo usa o
// parâmetro merito_alvo_padrao (50% se não existir).
// ===============================================

$planoId = (int) ($_GET['plano'] ?? 0);
if (!$planoId) {
    $p = escPlanoAtual(date('Y-m-d', strtotime(segundaDaSemana(date('Y-m-d')) . ' +1 week'))) ?? escPlanoAtual(segundaDaSemana(date('Y-m-d')));
    $planoId = $p ? (int) $p['id'] : 0;
}
$plano = $planoId ? escQ1('SELECT * FROM ' . escT('esc_plano') . ' WHERE id = ?', [$planoId]) : null;
if (!$plano) {
    echo '<header class="cabecalho"><span class="eyebrow">Planejamento</span><h1>Equilíbrio Tertius</h1></header><section class="panel"><p class="muted">Gere um rascunho para ver o equilíbrio.</p></section>';
    return;
}
$seg = substr($plano['semana_inicio'], 0, 10);
$alvoP = escQ1('SELECT valor FROM ' . escT('esc_parametro') . " WHERE chave = 'merito_alvo_padrao'");
$alvo = (float) ($alvoP['valor'] ?? 0.5);

$turnos = [];
foreach (escQ("SELECT rep_code, papel, COUNT(DISTINCT dia, turno) AS n FROM " . escT('esc_plano_alocacao') . " WHERE plano_id = ? AND rep_code IS NOT NULL AND bloco IN ('T2','T4','T6') GROUP BY rep_code, papel", [$planoId]) as $l) {
    $turnos[$l['rep_code']][$l['papel']] = (int) $l['n'];
}
$estrutura = escEstrutura();
$tertius = array_filter($estrutura, static fn($e) => $e['role'] === 'Tertius');
$faltas = [];
if ($tertius) {
    $codes = array_keys($tertius);
    foreach (escQ('SELECT UPPER(codigo) AS code, COUNT(*) AS blocos, SUM(faltou = 1) AS faltas FROM `5c_schedule`.`scheduleday` WHERE codigo IN (' . escIn($codes) . ') AND shiftDateAndHour >= ? AND shiftDateAndHour < ? GROUP BY UPPER(codigo)',
        array_merge($codes, [escMaisDias($seg, -28) . 'T00:00:00', $seg . 'T00:00:00'])) as $f) {
        $faltas[$f['code']] = [(int) $f['blocos'], (int) $f['faltas']];
    }
}
$timeSel = (string) ($_GET['time'] ?? '');
$times = array_values(array_unique(array_map(static fn($e) => $e['team'], $tertius)));
sort($times);
$linhas = [];
foreach ($tertius as $rep => $e) {
    if ($timeSel !== '' && $e['team'] !== $timeSel) {
        continue;
    }
    $reg = $turnos[$rep]['REGULAR'] ?? 0;
    $ass = $turnos[$rep]['ASSISTENTE'] ?? 0;
    $tot = $reg + $ass;
    [$bl, $fa] = $faltas[$rep] ?? [0, 0];
    $linhas[] = ['rep' => $rep, 'nome' => $e['rep_name'], 'time' => $e['team'], 'party' => $e['party'], 'reg' => $reg, 'ass' => $ass, 'pct' => $tot ? $reg / $tot : null, 'faltas' => $bl ? $fa / $bl : null];
}
usort($linhas, static fn($a, $b) => [$a['time'], $a['pct'] ?? 2] <=> [$b['time'], $b['pct'] ?? 2]);
?>
<header class="cabecalho">
  <span class="eyebrow">Planejamento</span>
  <h1>Equilíbrio Tertius · <?php echo h(escPlanoRotulo($plano)); ?></h1>
  <p class="muted">A vaga de regular vai para quem está mais abaixo do alvo; a de assistente, para quem está mais acima. Alvo atual: <strong><?php echo pct($alvo, 0); ?></strong> de turnos como regular.</p>
</header>

<section class="panel">
  <div class="aviso">O eixo de desempenho (quadrantes do 5C George) ainda não está ligado nesta versão. Por enquanto o alvo é o mesmo para todos (parâmetro <code>merito_alvo_padrao</code>) e a tabela mostra as faltas das últimas 4 semanas para apoiar a decisão.</div>
  <form method="get" action="<?php echo h(MODULE_URL); ?>/index.php" class="acoes">
    <input type="hidden" name="p" value="tertius"><input type="hidden" name="plano" value="<?php echo $planoId; ?>">
    <select name="time" onchange="this.form.submit()" aria-label="Time"><option value="">Todos os times</option><?php foreach ($times as $t): ?><option <?php echo $t === $timeSel ? 'selected' : ''; ?>><?php echo h($t); ?></option><?php endforeach; ?></select>
  </form>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th>Tertius</th><th>Time · party</th><th class="num">Turnos regular</th><th class="num">Turnos assistente</th><th class="num">% regular</th><th class="num">Desvio do alvo</th><th class="num">Faltas 4 sem.</th></tr></thead>
    <tbody>
    <?php foreach ($linhas as $l): $desvio = $l['pct'] === null ? null : $l['pct'] - $alvo; ?>
      <tr>
        <td><strong><?php echo h(nomeCurto($l['nome'])); ?></strong><br><span class="muted small mono"><?php echo h($l['rep']); ?></span></td>
        <td class="small"><?php echo h($l['time'] . ' · ' . $l['party']); ?></td>
        <td class="num"><?php echo (int) $l['reg']; ?></td>
        <td class="num"><?php echo (int) $l['ass']; ?></td>
        <td class="num"><?php echo $l['pct'] === null ? '<span class="muted">sem turno</span>' : pct($l['pct'], 0); ?></td>
        <td class="num <?php echo $desvio !== null && abs($desvio) > 0.2 ? 'laranja' : ''; ?>"><?php echo $desvio === null ? '—' : ($desvio > 0 ? '+' : '') . number_format($desvio * 100, 0, ',', '.') . ' p.p.'; ?></td>
        <td class="num"><?php echo $l['faltas'] === null ? '—' : pct($l['faltas'], 0); ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$linhas): ?><tr><td colspan="7" class="muted">Nenhum Tertius neste filtro.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <p class="muted small">Turnos contados uma vez por dia (primeiro bloco do turno). "Sem turno" = de folga a semana toda ou sem configuração.</p>
</section>
