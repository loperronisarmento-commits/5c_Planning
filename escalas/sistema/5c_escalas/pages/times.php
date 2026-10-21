<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/times.php
// Configuração dos times: violações da regra 1 Primaris + 2 Secundus,
// parties sem página e a formação de cada time (código e Telegram).
// Só leitura: ações de correção ficam para a fase com gravação.
// ===============================================

$parties = escFormacaoParties();
$filtro = $_GET['regra'] ?? 'todas';
$busca = trim((string) ($_GET['q'] ?? ''));
$timeSel = (string) ($_GET['time'] ?? '');

$contagem = ['todas' => 0, 'sem_primaris' => 0, 'dois_primaris' => 0, 'secundus' => 0, 'sem_pagina' => 0];
foreach ($parties as $l) {
    if ($l['regras']) {
        $contagem['todas']++;
    }
    foreach (array_unique(array_column($l['regras'], 0)) as $r) {
        $contagem[$r]++;
    }
}
$rotulos = ['todas' => 'Todas', 'sem_primaris' => 'Sem Primaris', 'dois_primaris' => '2+ Primaris', 'secundus' => 'Secundus ≠ 2', 'sem_pagina' => 'Sem página'];
if (!isset($rotulos[$filtro])) {
    $filtro = 'todas';
}

$lista = array_filter($parties, static function ($l) use ($filtro, $busca) {
    if (!$l['regras']) {
        return false;
    }
    if ($filtro !== 'todas' && !in_array($filtro, array_column($l['regras'], 0), true)) {
        return false;
    }
    return $busca === '' || stripos($l['team'] . ' ' . $l['party'], $busca) !== false;
});

$times = array_values(array_unique(array_column($parties, 'team')));
sort($times);
if ($timeSel === '' || !in_array($timeSel, $times, true)) {
    $timeSel = $times[0] ?? '';
}

// Formação do time escolhido.
$membros = [];
if ($timeSel !== '') {
    foreach (escEstrutura() as $code => $e) {
        if ($e['team'] === $timeSel) {
            $membros[$code] = $e;
        }
    }
}
$telegram = [];
if ($membros) {
    $codes = array_keys($membros);
    foreach (escQ('SELECT UPPER(code) AS code, telegram FROM `5c_bot`.`vw_rep` WHERE code IN (' . escIn($codes) . ')', $codes) as $r) {
        $telegram[$r['code']] = $r['telegram'];
    }
}
$turnos = escTurnosVigentes();
$ordemPapel = static function (string $papel): int {
    return ehPrimaris($papel) ? 0 : ($papel === 'Secundus' ? 1 : 2);
};
uasort($membros, static function ($a, $b) use ($ordemPapel) {
    return [$a['party'], $ordemPapel($a['role']), $a['rep_name']] <=> [$b['party'], $ordemPapel($b['role']), $b['rep_name']];
});
$porParty = [];
foreach ($membros as $code => $e) {
    $porParty[$e['party']][$code] = $e;
}
$formacaoTime = array_values(array_filter($parties, static fn($l) => $l['team'] === $timeSel));
?>
<header class="cabecalho">
  <span class="eyebrow">Cadastros</span>
  <h1>Configuração dos times</h1>
  <p class="muted">Regra: cada party com 1 Primaris e 2 Secundus, e com página ativa. Tertius atendem o time inteiro. Dados do Planner (estrutura atual) e do cadastro de páginas.</p>
</header>

<section class="panel">
  <div class="linha-titulo">
    <h2>Violações · <?php echo (int) $contagem['todas']; ?> de <?php echo count($parties); ?> parties</h2>
    <form class="busca" method="get" action="<?php echo h(MODULE_URL); ?>/index.php">
      <input type="hidden" name="p" value="times"><input type="hidden" name="regra" value="<?php echo h($filtro); ?>">
      <input type="search" name="q" value="<?php echo h($busca); ?>" placeholder="Buscar time ou party" aria-label="Buscar time ou party">
    </form>
  </div>
  <div class="chips" role="group" aria-label="Filtrar por regra">
    <?php foreach ($rotulos as $k => $rot): ?>
      <a class="chip-filtro <?php echo $filtro === $k ? 'ativo' : ''; ?>" href="<?php echo h(link_tela('times', ['regra' => $k, 'q' => $busca, 'time' => $timeSel])); ?>"><?php echo h($rot); ?> · <?php echo (int) $contagem[$k]; ?></a>
    <?php endforeach; ?>
  </div>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th>Time / Party</th><th>Formação</th><th>Páginas</th><th>Regra</th><th>Ação sugerida</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($lista as $l): ?>
      <tr>
        <td><strong><?php echo h($l['team']); ?></strong><br><span class="muted"><?php echo h($l['party']); ?></span></td>
        <td class="mono"><?php echo (int) $l['p']; ?>P · <?php echo (int) $l['s']; ?>S · <?php echo (int) $l['t']; ?>T</td>
        <td class="small"><?php echo $l['paginas'] ? h($l['nomes']) : '<span class="muted">nenhuma</span>'; ?></td>
        <td><?php foreach ($l['regras'] as $r): ?><span class="tag <?php echo $r[2] ? 'vermelho' : 'amarelo'; ?>"><?php echo h($r[1]); ?><?php echo $r[2] ? ' · bloqueia' : ''; ?></span> <?php endforeach; ?></td>
        <td class="small"><?php echo h(implode(' ', array_column($l['regras'], 3))); ?></td>
        <td><a class="botao" href="<?php echo h(link_tela('times', ['regra' => $filtro, 'q' => $busca, 'time' => $l['team']])); ?>#formacao">Ver formação</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$lista): ?><tr><td colspan="6" class="muted">Nenhuma party nesse filtro.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <p class="muted small">"Bloqueia" = a party fica fora da geração até ser corrigida. Mudanças de formação são feitas no Planner (não há API de escrita).</p>
</section>

<section class="panel" id="formacao">
  <div class="linha-titulo">
    <h2>Formação do time</h2>
    <form method="get" action="<?php echo h(MODULE_URL); ?>/index.php">
      <input type="hidden" name="p" value="times"><input type="hidden" name="regra" value="<?php echo h($filtro); ?>"><input type="hidden" name="q" value="<?php echo h($busca); ?>">
      <select name="time" onchange="this.form.submit()" aria-label="Time">
        <?php foreach ($times as $t): ?><option <?php echo $t === $timeSel ? 'selected' : ''; ?>><?php echo h($t); ?></option><?php endforeach; ?>
      </select>
    </form>
  </div>
  <div class="grade-parties">
    <?php foreach ($formacaoTime as $l): ?>
    <div class="cartao-party <?php echo $l['regras'] ? 'com-violacao' : ''; ?>">
      <div class="linha-titulo"><strong><?php echo h($l['party']); ?></strong><span class="mono small"><?php echo (int) $l['p']; ?>P · <?php echo (int) $l['s']; ?>S · <?php echo (int) $l['t']; ?>T</span></div>
      <span class="muted small"><?php echo $l['paginas'] ? h($l['nomes']) : 'sem página ativa'; ?></span>
      <ul class="membros">
        <?php foreach ($porParty[$l['party']] ?? [] as $code => $e):
            $tg = $telegram[$code] ?? '';
            $turno = turnoLetra($turnos[$code]['turno'] ?? null);
        ?>
        <li title="<?php echo h($e['rep_name'] . ' · ' . $code . ($tg ? ' · Telegram ' . $tg : '')); ?>">
          <span><?php echo h($e['rep_name']); ?> <span class="muted small"><?php echo h($e['role']); ?></span></span>
          <span class="small"><?php echo $turno ? chipTurno($turno) : ''; ?> <span class="mono"><?php echo h($code); ?></span><?php if ($tg): ?> · <a href="https://t.me/<?php echo h(ltrim($tg, '@')); ?>" target="_blank" rel="noopener"><?php echo h('@' . ltrim($tg, '@')); ?></a><?php endif; ?></span>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endforeach; ?>
  </div>
  <p class="muted small">Turno pelo headcount vigente. Passe o mouse no nome para ver código e Telegram.</p>
</section>
