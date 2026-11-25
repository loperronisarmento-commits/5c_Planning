<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/kpi.php
// KPI dos SRs (Kinetic, Process & Integrity): o indicador oficial de desempenho desde a 1.38.0 (referência da escala
// desde a 1.34.0; contexto/regras_escalacao_kpi.md). A coluna Nível é a que o Gerar usa (cache esc_sr_kpi). Semana da
// escala, filtros de unidade e turno, contagem por faixa, turnos na escala, tabela por SR ordenável, como é calculado,
// recalcular (só o cache em arquivo), exportar .xlsx e PDF, simulador e resumo por unidade e turno.
// Mais o detalhe semana a semana de cada SR (?rep=). Cálculo em lib/kpi.php.
// Não alimenta pagamentos nem notificações. Sem moedas desde a 1.38.0 (o controle de moedas é feito fora do sistema).
// Acesso: só WFM/Planejamento e Admin (dado individual).
// ===============================================

escExigirRestrito();
require_once __DIR__ . '/../lib/kpi.php';

$planoId = (int) ($_GET['plano'] ?? 0);
if (!$planoId) {
    $p = escPlanoAtual(date('Y-m-d', strtotime(segundaDaSemana(date('Y-m-d')) . ' +1 week'))) ?? escPlanoAtual(segundaDaSemana(date('Y-m-d')));
    $planoId = $p ? (int) $p['id'] : 0;
}
$plano = $planoId ? escQ1('SELECT * FROM ' . escT('esc_plano') . ' WHERE id = ?', [$planoId]) : null;
if (!$plano || ($plano['estrutura'] ?? '') !== 'V4') {
    echo '<header class="cabecalho"><span class="eyebrow">' . h(t('Análise')) . '</span><h1>' . h(t('KPI dos SRs')) . '</h1></header><section class="panel"><p class="muted">' . h(t('Gere um rascunho da estrutura nova para ver o KPI.')) . '</p></section>';
    return;
}
$seg = substr($plano['semana_inicio'], 0, 10);
$fim = substr($plano['semana_fim'], 0, 10);
$nomes = escNomes();

// Turnos de cada SR no plano (uma vez por dia e turno).
$turnos = [];
foreach (escQ("SELECT UPPER(rep_code) AS rep, papel, origem, COUNT(DISTINCT dia, turno) AS n FROM " . escT('esc_plano_alocacao') . "
                WHERE plano_id = ? AND rep_code IS NOT NULL AND bloco IN ('T2','T4','T6') GROUP BY UPPER(rep_code), papel, origem", [$planoId]) as $l) {
    $tipo = $l['origem'] === 'OVERLAP' ? 'overlap' : ($l['origem'] === 'BUFFER' ? 'buffer' : ($l['papel'] === 'ASSISTENTE' ? 'assistente' : 'posto'));
    $turnos[$l['rep']][$tipo] = ($turnos[$l['rep']][$tipo] ?? 0) + (int) $l['n'];
}
$srs = escQ("SELECT UPPER(c.rep_code) AS rep, c.unidade, c.turno, c.nivel, n.numero AS nucleo
               FROM " . escT('esc_colaborador_config') . " c LEFT JOIN " . escT('esc_nucleo') . " n ON n.id = c.nucleo_id
              WHERE c.funcao = 'EQUITE' AND c.nucleo_id IS NOT NULL AND c.vigente_desde <= ? AND (c.vigente_ate IS NULL OR c.vigente_ate >= ?)", [$fim, $seg]);
$kpi = escKpiCarregar(array_column($srs, 'rep'));
// Nível de cada SR como o Gerar vê (cache no banco; "Recalcular agora" refaz também este cache).
// "Recalcular agora" grava no banco: só por POST, com CSRF (S-06, 24/11).
if (escPost() && ($_POST['acao'] ?? '') === 'recalcular') {
    if (!escPodeReal('planejar')) {
        throw new RuntimeException(t('Bloqueado: seu perfil não pode fazer isso.'));
    }
    escKpiBanco(true);
    escVoltar('kpi', array_intersect_key($_GET, array_flip(['plano', 'u', 't', 'o', 'd'])), t('KPI recalculado.'));
}
$kpiDb = escKpiBanco();
$calculadoEm = $kpi['calculado_em'] ?? null;
$kpiSr = $kpi['srs'] ?? [];
$semanas = escKpiSemanas();
// Pareto: só os 20% de menor score (entre todos os SRs ativos com score calculado) recebem a ação de correção.
$pareto = escKpiPareto(array_intersect_key($kpiSr, array_flip(array_column($srs, 'rep'))));
$faixaRuim = ['LT25', '25_50', '50_75'];
$compDoSr = static function (?array $k, string $rep) use ($pareto, $faixaRuim): array {
    if ($k && !empty($k['provisorio'])) {
        return ['PROV', t('Novato: 75% provisório até 2 semanas fechadas'), 'cinza'];
    }
    $c = escKpiComportamento($k['score'] ?? null);
    return in_array($c[0], $faixaRuim, true) && !isset($pareto[$rep]) ? [$c[0], t('Mantém a posição (fora dos 20% piores)'), ''] : $c;
};

$uSel = in_array((string) ($_GET['u'] ?? ''), ['1', '2', '3'], true) ? (string) $_GET['u'] : '';
$tSel = in_array((string) ($_GET['t'] ?? ''), ['A', 'C', 'D'], true) ? (string) $_GET['t'] : '';
$repSel = strtoupper(trim((string) ($_GET['rep'] ?? '')));
// Ordenação: o rank (score do maior para o menor) é o padrão; nome e unidade começam crescentes.
$ordens = ['rank' => t('Rank'), 'meta' => t('Meta ponderada'), 'process' => 'Process', 'integrity' => 'Integrity', 'nome' => t('Nome'), 'unidade' => t('Unidade · turno')];
$ordem = isset($ordens[$_GET['o'] ?? '']) ? (string) $_GET['o'] : 'rank';
$crescentePadrao = in_array($ordem, ['nome', 'unidade'], true);
$cresc = isset($_GET['d']) ? $_GET['d'] === 'asc' : $crescentePadrao;
$filtros = array_filter(['plano' => $planoId, 'u' => $uSel, 't' => $tSel, 'o' => $ordem !== 'rank' ? $ordem : null, 'd' => $cresc !== $crescentePadrao ? ($cresc ? 'asc' : 'desc') : null]);

$linhas = [];
$todas = [];
foreach ($srs as $s) {
    $k = $kpiSr[$s['rep']] ?? null;
    $t = $turnos[$s['rep']] ?? [];
    $emPosto = ($t['posto'] ?? 0) + ($t['assistente'] ?? 0);
    $base = $emPosto + ($t['buffer'] ?? 0);
    $l = ['rep' => $s['rep'], 'nome' => $nomes[$s['rep']] ?? $s['rep'], 'u' => (int) $s['unidade'], 't' => $s['turno'], 'nucleo' => $s['nucleo'], 'nivel' => (int) $s['nivel'],
        'posto' => $t['posto'] ?? 0, 'assist' => $t['assistente'] ?? 0, 'buffer' => $t['buffer'] ?? 0, 'overlap' => $t['overlap'] ?? 0,
        'pct' => $base ? $emPosto / $base : null, 'k' => $k, 'score' => $k['score'] ?? null,
        'comp' => $compDoSr($k, $s['rep']), 'pareto' => isset($pareto[$s['rep']])];
    $todas[] = $l;
    if (($uSel === '' || (string) $s['unidade'] === $uSel) && ($tSel === '' || $s['turno'] === $tSel)) {
        $linhas[] = $l;
    }
}
// Rank no filtro escolhido: maior score primeiro; no empate, a maior meta ponderada e depois o nome (o documento
// não define desempate). Sem score fica sem rank, no fim.
usort($linhas, static fn($a, $b) => [-($a['score'] ?? -1), -($a['k']['kinetic_meta'] ?? -1), $a['nome']] <=> [-($b['score'] ?? -1), -($b['k']['kinetic_meta'] ?? -1), $b['nome']]);
foreach ($linhas as $i => &$l) {
    $l['rank'] = $l['score'] !== null ? $i + 1 : null;
}
unset($l);
if ($ordem !== 'rank' || $cresc) {
    $valor = static function (array $l) use ($ordem) {
        $k = $l['k'] ?? [];
        return ['rank' => $l['rank'], 'meta' => $k['kinetic_meta'] ?? null, 'process' => $k['process'] ?? null,
            'integrity' => $k['integrity'] ?? null, 'nome' => mb_strtolower($l['nome']), 'unidade' => $l['u'] . $l['t']][$ordem];
    };
    // Sem valor fica sempre no fim; no empate, vale o rank.
    usort($linhas, static function ($a, $b) use ($valor, $cresc, $ordem) {
        [$va, $vb] = [$valor($a), $valor($b)];
        if ($va === null || $vb === null) {
            return ($va === null) <=> ($vb === null) ?: ($a['rank'] ?? PHP_INT_MAX) <=> ($b['rank'] ?? PHP_INT_MAX);
        }
        $c = $ordem === 'rank' ? $vb <=> $va : $va <=> $vb;
        return ($cresc ? $c : -$c) ?: ($a['rank'] ?? PHP_INT_MAX) <=> ($b['rank'] ?? PHP_INT_MAX);
    });
}
$faixas = ['LT25' => '< 25%', '25_50' => '25% – 50%', '50_75' => '50% – 75%', '75_100' => '75% – 100%', '100_120' => '100% – 119%', '120' => '120%', 'PROV' => t('Novato (75%)'), 'SEM' => t('Sem score')];
$conta = array_fill_keys(array_keys($faixas), 0);
$contaPareto = array_fill_keys(array_keys($faixas), 0);
foreach ($linhas as $l) {
    $conta[$l['comp'][0]]++;
    $contaPareto[$l['comp'][0]] += $l['pareto'] ? 1 : 0;
}
if (!$conta['SEM']) {
    unset($faixas['SEM']);
}
$pc = static fn($v, int $c = 0): string => $v === null ? '—' : pct((float) $v, $c);
$semRot = static fn(string $sg): string => dataBr($sg) . '–' . dataBr(escMaisDias($sg, 6));

// ---------- Exportar (.xlsx e PDF), sem número de versão: a versão do documento gravaria no banco ----------
$soPdf = ($_GET['ver'] ?? '') === 'pdf';
if ($soPdf || isset($_GET['baixar'])) {
    $exportar = [];
    foreach ($linhas as $l) {
        $k = $l['k'] ?? [];
        $exportar[] = [$l['rank'], $l['nome'], $l['rep'], 'U' . $l['u'], $l['t'], $l['nucleo'] !== null ? (int) $l['nucleo'] : null, $l['nivel'],
            (int) $l['posto'], (int) $l['assist'], (int) $l['buffer'], (int) $l['overlap'], $l['pct'],
            $k['kinetic_meta'] ?? null, $k['kinetic_base'] ?? null, $k['process'] ?? null, $k['integrity'] ?? null, $l['score'], $l['comp'][1]];
    }
    $filtroTxt = ($uSel !== '' ? 'U' . $uSel : t('Todas as unidades')) . ' · ' . ($tSel !== '' ? t('Turno {t}', ['t' => $tSel]) : t('Todos os turnos'));
    $infoDoc = t('Semanas de {de} a {ate}', ['de' => dataBr(min(array_keys($semanas))), 'ate' => dataBr(escMaisDias(max(array_keys($semanas)), 6))]) . ' · ' . $filtroTxt
        . ($calculadoEm ? ' · ' . t('Calculado em {quando}', ['quando' => escDataHora((string) $calculadoEm)]) : '');
    $nomeArq = 'KPI SRs' . ($uSel !== '' ? ' U' . $uSel : '') . ($tSel !== '' ? ' ' . $tSel : '') . ' ' . date('d-m-Y');
}
if (($_GET['baixar'] ?? '') === 'xlsx') {
    require_once __DIR__ . '/../lib/xlsx.php';
    $col = static fn(string $titulo, float $largura, string $estilo) => ['titulo' => $titulo, 'largura' => $largura, 'estilo' => $estilo];
    escXlsxBaixar($nomeArq . '.xlsx', 'KPI',
        [t('KPI dos SRs (Kinetic, Process & Integrity)'), $infoDoc,
            implode(' · ', array_map(static fn($f, $n) => $f . ': ' . $n, $faixas, $conta))],
        [$col(t('Rank'), 6, 'inteiro'), $col('SR', 28, 'texto'), $col(t('Código'), 10, 'texto'), $col(t('Unidade'), 8, 'texto'), $col(t('Turno'), 7, 'texto'), $col(t('Núcleo'), 7, 'inteiro'), $col(t('Nível'), 7, 'inteiro'),
            $col(t('Em posto'), 8, 'inteiro'), $col(t('Assistente'), 10, 'inteiro'), $col('Buffer (-2)', 8, 'inteiro'), $col('Overlap (-5)', 8, 'inteiro'), $col(t('% em posto'), 8, 'pct'),
            $col(t('Meta ponderada'), 10, 'pct'), $col('Kinetic', 9, 'pct'), $col('Process', 9, 'pct'), $col('Integrity', 9, 'pct'), $col('Score', 9, 'pct'),
            $col(t('Comportamento sugerido'), 34, 'texto')],
        $exportar, 2);
    return;
}
if (isset($_GET['baixar'])) {
    http_response_code(400);
    echo t('Download desconhecido.');
    return;
}
$detalhe = $repSel !== '' ? ($kpiSr[$repSel] ?? null) : null;
?>
<?php if ($soPdf): ?>
<div class="acoes nao-imprimir">
  <a class="botao" href="<?php echo h(link_tela('kpi', $filtros)); ?>">← <?php echo h(t('Voltar para o KPI dos SRs')); ?></a>
  <button type="button" class="botao primario" onclick="window.print()"><?php echo h(t('Baixar PDF')); ?></button>
  <span class="muted small"><?php echo h(t('Na impressão, escolha "Salvar como PDF".')); ?></span>
</div>
<?php endif; ?>
<header class="cabecalho">
  <span class="eyebrow"><?php echo h(t('Análise')); ?></span>
  <h1><?php echo h(t('KPI dos SRs · {versao}', ['versao' => escPlanoRotulo($plano)])); ?></h1>
  <?php if ($soPdf): ?><p class="muted small"><?php echo h($infoDoc); ?></p>
  <?php else: ?><p class="muted"><?php echo h(t('Kinetic, Process & Integrity: Score = Kinetic × Process × Integrity, de 0% a 120%. É o indicador oficial de desempenho dos SRs: o nível de cada SR (0 a 4) define a ordem no Gerar, a continuidade no posto, o remanejamento, o acúmulo e a sobrecarga.')); ?> <a href="<?php echo h(link_tela('regras')); ?>"><?php echo h(t('Ver as regras')); ?></a></p><?php endif; ?>
</header>

<?php if (!$soPdf): ?>
<p class="aviso" role="status"><?php echo h(t('Ainda sem dado, não entram no cálculo: Mass por turno e "não seguir o plano" (Process), mensagem para DNR (Integrity) e multas.')); ?></p>
<?php if (!escAbsOficialOk()): ?><p class="aviso" role="status"><?php echo h(t('O sistema não consegue ler as faltas oficiais (5c_wfm_db_mis.abs_adh_af): a Integrity fica sem desconto de falta.')); ?></p><?php endif; ?>
<?php endif; ?>

<?php
// Distribuição: SRs por faixa de score (a população do filtro). Barras em cinza; "Sem score" mais apagada.
$versaoArq = static fn(string $arq): string => (string) (@filemtime(__DIR__ . '/../assets/' . $arq) ?: time());
$comScore = count($linhas) - $conta['SEM'] - $conta['PROV'];
$pctPop = static fn(int $n): string => $comScore > 0 ? pct($n / $comScore, 0) : '—';
$compDe = ['LT25' => 0.1, '25_50' => 0.3, '50_75' => 0.6, '75_100' => 0.8, '100_120' => 1.1, '120' => 1.2];
// Cores da marca 5C, divergentes: marsala nas faixas baixas (mais forte = pior), cinza neutro em 75–100%
// e ouro nas faixas de 100% ou mais (mais forte = melhor); "Sem score" num cinza apagado.
$corFaixa = ['LT25' => 'kf1', '25_50' => 'kf2', '50_75' => 'kf3', '75_100' => 'kf4', '100_120' => 'kf6', '120' => 'kf7', 'PROV' => 'kfsem', 'SEM' => 'kfsem'];
$grupos = [];
$tabela = [];
foreach ($faixas as $f => $rot) {
    $n = $conta[$f];
    $extra = in_array($f, ['SEM', 'PROV'], true);
    $acao = $f === 'SEM' ? t('sem dado nas 4 semanas') : ($f === 'PROV' ? t('menos de 2 semanas fechadas com meta') : escKpiComportamento($compDe[$f])[1]);
    if (in_array($f, $faixaRuim, true)) {
        $acao .= ' · ' . t('só para os 20% piores: {n} de {t}', ['n' => $contaPareto[$f], 't' => $n]);
    }
    $grupos[] = ['rot' => $rot, 'barras' => [['seg' => [['v' => $n, 'cls' => $corFaixa[$f]]],
        'rotulo' => $n . (!$extra && $n ? ' · ' . $pctPop($n) : ''), 'tip' => $rot . "\n" . tn($n, '{n} SR', '{n} SRs') . (!$extra ? ' (' . $pctPop($n) . ')' : '') . "\n" . $acao]]];
    $tabela[] = [$rot, (string) $n, !$extra ? $pctPop($n) : '—', $acao];
}
$abaixo75 = $conta['LT25'] + $conta['25_50'] + $conta['50_75'];
$de100 = $conta['100_120'] + $conta['120'];
$tituloGraf = $comScore > 0
    ? t('{a} dos SRs com score estão abaixo de 75%; {b} estão em 100% ou mais', ['a' => $pctPop($abaixo75), 'b' => $pctPop($de100)])
        . ' · ' . t('{n} na ação de correção (20% piores)', ['n' => array_sum(array_intersect_key($contaPareto, array_flip($faixaRuim)))])
    : t('Nenhum SR com score neste filtro');
?>
<?php if (!$soPdf): ?>
  <?php $comFiltro = static fn(array $troca): array => array_filter(array_merge($filtros, $troca), static fn($v) => $v !== null && $v !== ''); ?>
  <div class="kpi-filtros" style="display: flex; flex-direction: column; gap: 8px; margin: 0 0 14px;">
    <div class="chips" style="align-items: center;" role="group" aria-label="<?php echo h(t('Turno')); ?>"><span class="muted small"><?php echo h(t('Turno')); ?></span>
      <?php foreach (['' => t('Todos'), 'A' => 'A', 'C' => 'C', 'D' => 'D'] as $k => $rot): ?>
      <a class="chip-filtro <?php echo $tSel === $k ? 'ativo' : ''; ?>" href="<?php echo h(link_tela('kpi', $comFiltro(['t' => $k]))); ?>"<?php echo $tSel === $k ? ' aria-current="true"' : ''; ?>><?php echo h($rot); ?></a>
      <?php endforeach; ?>
    </div>
    <div class="chips" style="align-items: center;" role="group" aria-label="<?php echo h(t('Unidade')); ?>"><span class="muted small"><?php echo h(t('Unidade')); ?></span>
      <?php foreach (['' => t('Todas'), '1' => 'U1', '2' => 'U2', '3' => 'U3'] as $k => $rot): ?>
      <a class="chip-filtro <?php echo $uSel === (string) $k ? 'ativo' : ''; ?>" href="<?php echo h(link_tela('kpi', $comFiltro(['u' => (string) $k]))); ?>"<?php echo $uSel === (string) $k ? ' aria-current="true"' : ''; ?>><?php echo h($rot); ?></a>
      <?php endforeach; ?>
    </div>
    <div class="chips" style="align-items: center;" role="group" aria-label="<?php echo h(t('Ordenar por')); ?>"><span class="muted small"><?php echo h(t('Ordenar por')); ?></span>
      <?php foreach ($ordens as $k => $rot):
          $ativo = $ordem === $k;
          $padrao = in_array($k, ['nome', 'unidade'], true);
          $novoCresc = $ativo ? !$cresc : $padrao; // clicar de novo inverte
          $seta = $ativo ? ($k === 'rank' ? ($cresc ? ' ↑' : ' ↓') : ($cresc ? ' ↑' : ' ↓')) : ''; ?>
      <a class="chip-filtro <?php echo $ativo ? 'ativo' : ''; ?>" href="<?php echo h(link_tela('kpi', $comFiltro(['o' => $k !== 'rank' ? $k : null, 'd' => $novoCresc !== $padrao ? ($novoCresc ? 'asc' : 'desc') : null]))); ?>"<?php echo $ativo ? ' aria-current="true"' : ''; ?> title="<?php echo h($ativo ? t('Clique de novo para inverter a ordem') : ''); ?>"><?php echo h($rot . $seta); ?></a>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>
<link rel="stylesheet" href="<?php echo h(MODULE_URL); ?>/assets/analise.css?v=<?php echo h($versaoArq('analise.css')); ?>">
<style>
/* Paleta 5C no gráfico do KPI. Escuro (padrão): tons claros para aparecer no fundo preto. */
.kpi-distribuicao { --kf1: #f06aa8; --kf2: #c23d80; --kf3: #8a2d5c; --kf4: #6b6170; --kf5: #8a6a33; --kf6: #d6a250; --kf7: #f0c47a; --kfsem: #3a3238; }
:root[data-tema="claro"] .kpi-distribuicao { --kf1: #650637; --kf2: #970250; --kf3: #c96a96; --kf4: #c9c4cc; --kf5: #e6c993; --kf6: #d6a250; --kf7: #a8782a; --kfsem: #ddd6df; }
@media (prefers-color-scheme: light) {
  :root[data-tema="auto"] .kpi-distribuicao, :root:not([data-tema]) .kpi-distribuicao { --kf1: #650637; --kf2: #970250; --kf3: #c96a96; --kf4: #c9c4cc; --kf5: #e6c993; --kf6: #d6a250; --kf7: #a8782a; --kfsem: #ddd6df; }
}
@media print {
  .kpi-distribuicao { --kf1: #650637; --kf2: #970250; --kf3: #c96a96; --kf4: #c9c4cc; --kf5: #e6c993; --kf6: #d6a250; --kf7: #a8782a; --kfsem: #ddd6df; }
}
.kpi-distribuicao .graf .seg.kf1 { fill: var(--kf1); } .kpi-distribuicao .graf .seg.kf2 { fill: var(--kf2); }
.kpi-distribuicao .graf .seg.kf3 { fill: var(--kf3); } .kpi-distribuicao .graf .seg.kf4 { fill: var(--kf4); }
.kpi-distribuicao .graf .seg.kf5 { fill: var(--kf5); } .kpi-distribuicao .graf .seg.kf6 { fill: var(--kf6); }
.kpi-distribuicao .graf .seg.kf7 { fill: var(--kf7); } .kpi-distribuicao .graf .seg.kfsem { fill: var(--kfsem); }
</style>
<section class="analise kpi-distribuicao">
  <?php echo escGrafFigura($tituloGraf,
      t('SRs por faixa de score ({n} SRs no filtro, {c} com score). O % é sobre quem tem score. Passe o mouse na coluna para ver o comportamento sugerido.', ['n' => count($linhas), 'c' => $comScore]),
      '', escGrafColunas($grupos, ['titulo' => t('SRs por faixa de score'), 'w' => 1200, 'h' => 300, 'larguraMax' => 72, 'eixoY' => 'SRs']), 'largo',
      escGrafTabela(t('Ver tabela'), [t('Faixa de score'), 'SRs', t('% com score'), t('Comportamento sugerido')], $tabela)); ?>
</section>
<script src="<?php echo h(MODULE_URL); ?>/assets/analise.js?v=<?php echo h($versaoArq('analise.js')); ?>" defer></script>

<?php if ($detalhe !== null && !$soPdf): $linhaSel = null; foreach ($todas as $l) { if ($l['rep'] === $repSel) { $linhaSel = $l; } } ?>
<section class="panel" id="detalhe">
  <h2><?php echo h(t('Como o KPI de {nome} foi calculado', ['nome' => nomeCurto($nomes[$repSel] ?? $repSel)])); ?> <span class="muted small mono"><?php echo h($repSel); ?></span></h2>
  <div class="tabela-rolagem"><table data-sem-ordem>
    <thead><tr><th><?php echo h(t('Semana')); ?></th><th class="num"><?php echo h(t('Peso')); ?></th><th class="num"><?php echo h(t('Meta')); ?></th>
      <th class="num">SLA 5</th><th class="num">SLA 10</th><th class="num">SLA 15</th><th class="num">Process</th><th class="num"><?php echo h(t('Faltas')); ?></th><th class="num">P&amp;G</th><th class="num">Integrity</th></tr></thead>
    <tbody>
    <?php foreach ($semanas as $sg => $peso): $w = $detalhe['semanas'][$sg] ?? null; ?>
      <tr>
        <td><?php echo h($semRot($sg)); ?></td>
        <td class="num"><?php echo $peso; ?></td>
        <?php if ($w === null): ?><td colspan="8" class="muted"><?php echo h(t('Sem dado nesta semana: fica fora da média.')); ?></td>
        <?php else: ?>
        <td class="num"><?php echo $pc($w['atingimento'], 1); ?><?php if ($w['meta'] !== null): ?> <span class="muted small"><?php echo num($w['real'], 0); ?> ÷ <?php echo num($w['meta'], 0); ?></span><?php endif; ?></td>
        <?php foreach ([5, 10, 15] as $mi): $v = $w['sla' . $mi]; $d = $w['desc' . $mi]; ?>
        <td class="num"><?php echo $pc($v, 1); ?><?php if ($d): ?> <span class="laranja small">−<?php echo pct($d, 0); ?></span><?php endif; ?></td>
        <?php endforeach; ?>
        <td class="num"><strong><?php echo $pc($w['process']); ?></strong></td>
        <td class="num"><?php echo (int) $w['faltas']; ?><?php if ($w['faltas']): ?> <span class="laranja small">−<?php echo pct(ESC_KPI_FALTA * $w['faltas'], 0); ?></span><?php endif; ?></td>
        <td class="num"><?php if (!empty($w['pg'])): ?><?php echo h(implode(' · ', array_map(static fn($n) => t('nota {n}', ['n' => num($n, 0)]), $w['pg']))); ?> <span class="laranja small">−<?php echo pct($w['desc_pg'], 1); ?></span><?php else: ?>0<?php endif; ?></td>
        <td class="num"><strong><?php echo $pc($w['integrity']); ?></strong></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <ul class="small">
    <li><strong>Kinetic:</strong> <?php echo h(t('meta ponderada {m} (soma do peso × realizado ÷ soma do peso × meta) → base {b}.', ['m' => $pc($detalhe['kinetic_meta'], 1), 'b' => $pc($detalhe['kinetic_base'], 1)])); ?></li>
    <li><strong>Process:</strong> <?php echo h(t('média ponderada das semanas = {p}. Cada semana: 100% − descontos de SLA, com piso de 60%.', ['p' => $pc($detalhe['process'], 1)])); ?></li>
    <li><strong>Integrity:</strong> <?php echo h(t('média ponderada das semanas = {i}. Cada semana: 100% − 15% por falta − quebra de P&G (−10% com nota 80 até −30% com nota 0), até 0%.', ['i' => $pc($detalhe['integrity'], 1)])); ?></li>
    <li><strong>Score:</strong> <?php echo h($pc($detalhe['kinetic_base'], 1) . ' × ' . $pc($detalhe['process'], 1) . ' × ' . $pc($detalhe['integrity'], 1) . ' = ' . $pc($detalhe['score_calculado'] ?? null, 1)); ?><?php if (!empty($detalhe['provisorio'])): ?> · <?php echo h(t('novato: vale o provisório de 75% até ter 2 semanas fechadas com meta')); ?><?php endif; ?> · <?php echo h($compDoSr($detalhe, $repSel)[1]); ?></li>
  </ul>
  <p class="muted small"><?php echo h(t('A média ponderada usa os pesos só das semanas com dado (soma dos pesos × valor ÷ soma dos pesos). Só contam dados a partir de {dia}.', ['dia' => dataBr(escDadosDesde())])); ?> <a href="<?php echo h(link_tela('kpi', $filtros)); ?>"><?php echo h(t('Fechar')); ?></a></p>
</section>
<?php endif; ?>

<section class="panel<?php echo $soPdf ? ' merito-srs-pdf' : ''; ?>">
  <?php if (!$soPdf): ?>
  <details>
    <summary><strong><?php echo h(t('Como o KPI é calculado')); ?></strong></summary>
    <p><?php echo h(t('Últimas 4 semanas fechadas (segunda a domingo), com pesos 4 (a última fechada), 3, 2 e 1, nos três pilares. A semana em andamento não entra. Semana sem dado fica fora da média.')); ?> <?php echo h(t('Só contam dados a partir de {dia}.', ['dia' => dataBr(escDadosDesde())])); ?></p>
    <ul class="small">
      <li><strong>Kinetic:</strong> <?php echo h(t('meta das 4 semanas: soma do peso × realizado ÷ soma do peso × meta 5CReps (a meta rateada pelas mensagens do SR; o realizado pela venda própria do SR: venda direta e tip), então semana com meta pequena pesa menos. Ela vira a base em rampa, pelos pontos da tabela do documento: 50% → 50%, 75% → 75%, 90% → 90%, 100% → 100%, 150% → 110% e 200% → 120%, em linha reta entre eles (ex.: 62% → 62%; 125% → 105%). Mínimo de 25% e máximo de 120%.')); ?></li>
      <li><strong>Process:</strong> <?php echo h(t('100% menos: SLA 15 −4%, SLA 10 −2% e SLA 5 −1% a cada 10% abaixo do esperado (95%, 90% e 80%), proporcional (ex.: SLA 15 em 90% = −2%). Nunca abaixo de 60%. Mass por turno e "não seguir o plano" ainda não são calculados.')); ?></li>
      <li><strong>Integrity:</strong> <?php echo h(t('100% menos 15% por dia de falta (falta aprovada sai da escala, então toda falta lida é devida) e menos a quebra de P&G: avaliação de qualidade com "Model Best Practices" Não Conforme tira 10% com nota 80, crescendo em linha reta até 30% com nota 0 (pela data do contato avaliado). Pode chegar a 0%. DNR ainda não é calculado.')); ?></li>
      <li><strong>Score:</strong> <?php echo h(t('Kinetic × Process × Integrity, de 0% a 120%. Novato (menos de 2 semanas fechadas com meta) fica com 75% provisório.')); ?></li>
      <li><strong><?php echo h(t('Ação de correção')); ?>:</strong> <?php echo h(t('menos turnos, realocar ou substituir valem só para os 20% de menor score (Pareto); os outros das faixas abaixo de 75% mantêm a posição.')); ?></li>
    </ul>
  </details>
  <div class="acoes">
    <form method="post" action="<?php echo h(link_tela('kpi', $filtros)); ?>" style="display:inline" data-longo="<?php echo h(t('Recalculando o KPI… pode levar até 1 min.')); ?>"><?php echo campoCsrf(); ?><input type="hidden" name="acao" value="recalcular"><button type="submit" class="botao"><?php echo h(t('Recalcular agora')); ?></button></form>
    <span class="muted small"><?php echo $calculadoEm ? h(t('Calculado em {quando}; recalcula sozinho a cada {h} h e no Gerar.', ['quando' => escDataHora((string) $calculadoEm), 'h' => (int) (ESC_KPI_CACHE_SEG / 3600)])) : ''; ?></span>
  </div>
  <div class="acoes">
    <a class="botao" href="<?php echo h(link_tela('kpi', $filtros + ['baixar' => 'xlsx'])); ?>"><?php echo h(t('Exportar .xlsx')); ?></a>
    <a class="botao" href="<?php echo h(link_tela('kpi', $filtros + ['ver' => 'pdf', 'imprimir' => 1])); ?>" target="_blank" rel="noopener"><?php echo h(t('Exportar PDF')); ?></a>
    <span class="muted small"><?php echo h(t('Com os filtros de unidade e turno escolhidos acima.')); ?></span>
  </div>
  <?php endif; ?>
  <div class="tabela-rolagem">
  <table<?php echo $soPdf ? ' data-sem-ordem' : ''; ?>>
    <thead><tr><th class="num"><abbr title="<?php echo h(t('Rank pelo score no filtro escolhido')); ?>">#</abbr></th><th>SR</th><th><?php echo h(t('Unidade · turno · núcleo')); ?></th><th class="num"><?php echo h(t('Nível')); ?></th><th class="num"><?php echo h(t('Em posto')); ?></th><th class="num"><?php echo h(t('Assistente')); ?></th><th class="num">Buffer (-2)</th><th class="num">Overlap (-5)</th>
      <th class="num"><?php echo h(t('Meta ponderada')); ?></th><th class="num">Kinetic</th><th class="num">Process</th><th class="num">Integrity</th><th class="num">Score</th><th><?php echo h(t('Nível')); ?></th><th><?php echo h(t('Comportamento sugerido')); ?></th></tr></thead>
    <tbody>
    <?php foreach ($linhas as $l): $k = $l['k'] ?? []; ?>
      <tr>
        <td class="num"><strong><?php echo $l['rank'] ?? '—'; ?></strong></td>
        <td><?php if ($soPdf): ?><strong><?php echo h(nomeCurto($l['nome'])); ?></strong><?php else: ?><a href="<?php echo h(link_tela('kpi', $filtros + ['rep' => $l['rep']]) . '#detalhe'); ?>"><strong><?php echo h(nomeCurto($l['nome'])); ?></strong></a><?php endif; ?><br><span class="muted small mono"><?php echo h($l['rep']); ?></span></td>
        <td class="small">U<?php echo $l['u']; ?> <?php echo chipTurno($l['t']); ?> · <?php echo h(t('núcleo {n}', ['n' => (string) $l['nucleo']])); ?></td>
        <td class="num">SR <?php echo $l['nivel']; ?></td>
        <td class="num"><?php echo (int) $l['posto']; ?></td>
        <td class="num"><?php echo (int) $l['assist']; ?></td>
        <td class="num"><?php echo (int) $l['buffer']; ?></td>
        <td class="num"><?php echo (int) $l['overlap']; ?></td>
        <td class="num"><?php echo $pc($k['kinetic_meta'] ?? null, 1); ?></td>
        <td class="num"><?php echo $pc($k['kinetic_base'] ?? null, 1); ?></td>
        <td class="num"><?php echo $pc($k['process'] ?? null, 1); ?></td>
        <td class="num <?php echo isset($k['integrity']) && $k['integrity'] < 1 ? 'laranja' : ''; ?>"><?php echo $pc($k['integrity'] ?? null, 1); ?></td>
        <td class="num"><strong><?php echo $pc($l['score'], 1); ?></strong></td>
        <?php [$nvRot, $nvCls] = escKpiNivelRotulo((int) ($kpiDb[$l['rep']]['nivel'] ?? 2)); ?><td><span class="tag <?php echo h($nvCls); ?>"><?php echo h($nvRot); ?></span></td>
        <td><span class="tag <?php echo h($l['comp'][2]); ?>"><?php echo h($l['comp'][1]); ?></span></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$linhas): ?><tr><td colspan="15" class="muted"><?php echo h(t('Nenhum SR neste filtro.')); ?></td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <p class="muted small"><?php echo h(t('Turnos na escala desta semana. Kinetic, Process, Integrity e Score são médias ponderadas das últimas 4 semanas. Clique no nome para ver o cálculo semana a semana.')); ?></p>
</section>

<?php if (!$soPdf):
    $porUt = [];
    foreach ($todas as $l) {
        $k = $l['u'] . '|' . $l['t'];
        $porUt[$k] = $porUt[$k] ?? ['u' => $l['u'], 't' => $l['t'], 'srs' => 0, 'scores' => []];
        $porUt[$k]['srs']++;
        if ($l['score'] !== null) {
            $porUt[$k]['scores'][] = $l['score'];
        }
    }
    ksort($porUt);
    $mediana = static function (array $v): ?float {
        if (!$v) {
            return null;
        }
        sort($v);
        return $v[intdiv(count($v), 2)];
    };
?>
<section class="panel">
  <h2><?php echo h(t('Por unidade e turno')); ?></h2>
  <div class="tabela-rolagem"><table>
    <thead><tr><th><?php echo h(t('Unidade · turno')); ?></th><th class="num">SRs</th><th class="num"><?php echo h(t('Com score')); ?></th><th class="num"><?php echo h(t('Score mediano')); ?></th></tr></thead>
    <tbody>
    <?php foreach ($porUt as $l): ?>
      <tr><td>U<?php echo $l['u']; ?> <?php echo chipTurno((string) $l['t']); ?></td><td class="num"><?php echo $l['srs']; ?></td><td class="num"><?php echo count($l['scores']); ?></td>
        <td class="num"><strong><?php echo $pc($mediana($l['scores']), 1); ?></strong></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>

<section class="panel">
  <h2><?php echo h(t('Simulador')); ?></h2>
  <p class="muted small"><?php echo h(t('Uma semana só (sem o histórico de 4 semanas). Mude os números e veja os pilares e o score.')); ?></p>
  <form class="merito-sim" data-kpi-sim onsubmit="return false">
    <label><?php echo h(t('Meta %')); ?><input type="number" name="meta" value="100" min="0" max="2000" step="1" inputmode="decimal"></label>
    <label>SLA 5 %<input type="number" name="sla5" value="80" min="0" max="100" step="1" inputmode="decimal"></label>
    <label>SLA 10 %<input type="number" name="sla10" value="90" min="0" max="100" step="1" inputmode="decimal"></label>
    <label>SLA 15 %<input type="number" name="sla15" value="95" min="0" max="100" step="1" inputmode="decimal"></label>
    <label><?php echo h(t('Dias com falta')); ?><input type="number" name="faltas" value="0" min="0" max="7" step="1" inputmode="numeric"></label>
    <output class="merito-sim-saida" aria-live="polite">
      <span>Kinetic <strong data-sim="k">—</strong></span><span>Process <strong data-sim="p">—</strong></span><span>Integrity <strong data-sim="i">—</strong></span>
      <span>Score <strong data-sim="s">—</strong></span><span class="tag" data-sim="c">—</span>
    </output>
  </form>
</section>
<script>
(function () {
  var f = document.querySelector('[data-kpi-sim]');
  if (!f) { return; }
  var sla = <?php echo json_encode(ESC_KPI_SLA); ?>, passo = <?php echo json_encode(ESC_KPI_SLA_PASSO); ?>, piso = <?php echo json_encode(ESC_KPI_PROCESS_PISO); ?>, falta = <?php echo json_encode(ESC_KPI_FALTA); ?>;
  var faixas = <?php echo json_encode(ESC_KPI_KINETIC); ?>;
  var rot = <?php echo json_encode(array_map(static fn($x) => escKpiComportamento($x)[1], ['lt25' => 0.1, 'a' => 0.3, 'b' => 0.6, 'c' => 0.8, 'd' => 1.1, 'f' => 1.2])); ?>;
  var pc = function (x) { return (Math.round(x * 1000) / 10).toLocaleString(document.documentElement.lang || 'pt-BR') + '%'; };
  function base(m) {
    if (m <= faixas[0][0]) { return faixas[0][1]; }
    for (var i = 1; i < faixas.length; i++) { if (m <= faixas[i][0]) { var a = faixas[i - 1], b = faixas[i]; return a[1] + (b[1] - a[1]) * (m - a[0]) / (b[0] - a[0]); } }
    return faixas[faixas.length - 1][1];
  }
  function calc() {
    var v = function (n) { var x = parseFloat(String(f.elements[n].value).replace(',', '.')); return isNaN(x) ? 0 : x; };
    var m = v('meta') / 100, k = base(m), p = 1;
    [15, 10, 5].forEach(function (mi) { var s = v('sla' + mi) / 100, e = sla[mi][0]; if (s < e) { p -= (e - s) / passo * sla[mi][1]; } });
    p = Math.max(piso, p);
    var i = Math.max(0, 1 - falta * Math.max(0, Math.floor(v('faltas')))), s = k * p * i, sr = Math.round(s * 1e6) / 1e6;
    f.querySelector('[data-sim="k"]').textContent = pc(k);
    f.querySelector('[data-sim="p"]').textContent = pc(p);
    f.querySelector('[data-sim="i"]').textContent = pc(i);
    f.querySelector('[data-sim="s"]').textContent = pc(s);
    f.querySelector('[data-sim="c"]').textContent = sr < 0.25 ? rot.lt25 : sr < 0.5 ? rot.a : sr < 0.75 ? rot.b : sr < 1 ? rot.c : sr < 1.2 ? rot.d : rot.f;
  }
  f.addEventListener('input', calc);
  calc();
})();
</script>
<?php endif; ?>
<?php if ($soPdf): ?>
<?php echo escDocTituloJs($nomeArq); ?>
<?php if (!empty($_GET['imprimir'])): ?><script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });</script><?php endif; ?>
<?php endif; ?>
