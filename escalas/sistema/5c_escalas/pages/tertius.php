<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/tertius.php
// Mérito dos SRs (antes "Equilíbrio dos SRs"): turnos de cada SR no rascunho (em posto,
// assistente, buffer de plantão, Overlap) × a fatia do nível, e o mérito em
// pontos (M-01 a M-09, lib/merito.php): a soma dos componentes e a faixa.
// No Gerar (1.19.0) a faixa e a nota escolhem o SR do passo 0 (depois de quem
// bateu a meta na página) e pesam no remanejamento, no acúmulo e na sobrecarga.
// ===============================================

if (escPost()) {
    if (!escPodeReal('planejar')) {
        throw new RuntimeException(t('Bloqueado: seu perfil não pode fazer isso.'));
    }
    escMeritoCarregar(true);
    escVoltar('srs', array_filter(['plano' => $_GET['plano'] ?? null, 'u' => $_GET['u'] ?? null, 't' => $_GET['t'] ?? null]), t('Mérito recalculado.'));
}

$planoId = (int) ($_GET['plano'] ?? 0);
if (!$planoId) {
    $p = escPlanoAtual(date('Y-m-d', strtotime(segundaDaSemana(date('Y-m-d')) . ' +1 week'))) ?? escPlanoAtual(segundaDaSemana(date('Y-m-d')));
    $planoId = $p ? (int) $p['id'] : 0;
}
$plano = $planoId ? escQ1('SELECT * FROM ' . escT('esc_plano') . ' WHERE id = ?', [$planoId]) : null;
if (!$plano) {
    echo '<header class="cabecalho"><span class="eyebrow">' . h(t('Análise')) . '</span><h1>' . h(t('Mérito dos SRs')) . '</h1></header><section class="panel"><p class="muted">' . h(t('Gere um rascunho para ver o equilíbrio.')) . '</p></section>';
    return;
}
$seg = substr($plano['semana_inicio'], 0, 10);
$fim = substr($plano['semana_fim'], 0, 10);
$nomes = escNomes();

if (($plano['estrutura'] ?? '') !== 'V4') {
    echo '<header class="cabecalho"><span class="eyebrow">' . h(t('Análise')) . '</span><h1>' . h(t('Mérito dos SRs')) . '</h1></header><section class="panel"><p class="muted">' . h(t('Esta escala é da estrutura antiga (antes de {dia}). O mérito dos SRs vale para as escalas da estrutura nova.', ['dia' => escData('2026-11-01')])) . '</p></section>';
    return;
}

// Turnos de cada SR no plano (uma vez por dia e turno).
$turnos = [];
foreach (escQ("SELECT UPPER(rep_code) AS rep, papel, origem, COUNT(DISTINCT dia, turno) AS n FROM " . escT('esc_plano_alocacao') . "
                WHERE plano_id = ? AND rep_code IS NOT NULL AND bloco IN ('T2','T4','T6') GROUP BY UPPER(rep_code), papel, origem", [$planoId]) as $l) {
    $tipo = $l['origem'] === 'OVERLAP' ? 'overlap' : ($l['origem'] === 'BUFFER' ? 'buffer' : ($l['papel'] === 'ASSISTENTE' ? 'assistente' : 'posto'));
    $turnos[$l['rep']][$tipo] = ($turnos[$l['rep']][$tipo] ?? 0) + (int) $l['n'];
}
$srs = escQ("SELECT UPPER(c.rep_code) AS rep, c.unidade, c.turno, c.nivel, c.fatia_regular, n.numero AS nucleo
               FROM " . escT('esc_colaborador_config') . " c LEFT JOIN " . escT('esc_nucleo') . " n ON n.id = c.nucleo_id
              WHERE c.funcao = 'EQUITE' AND c.nucleo_id IS NOT NULL AND c.vigente_desde <= ? AND (c.vigente_ate IS NULL OR c.vigente_ate >= ?)", [$fim, $seg]);
// Mérito individual (pontos, faltas, infrações): só Planejamento e Admin.
$verMerito = escPodeReal('planejar');
$merito = $verMerito ? escMeritoCarregar() : [];
$pts = escMeritoPtsParam();
$calculadoEm = $merito ? max(array_column($merito, 'calculado_em')) : null;

$uSel = (string) ($_GET['u'] ?? '');
$tSel = (string) ($_GET['t'] ?? '');
$fatiaNivel = [0 => 0.40, 1 => 0.60, 2 => 0.80];
$linhas = [];
foreach ($srs as $s) {
    if (($uSel !== '' && (string) $s['unidade'] !== $uSel) || ($tSel !== '' && $s['turno'] !== $tSel)) {
        continue;
    }
    $t = $turnos[$s['rep']] ?? [];
    $emPosto = ($t['posto'] ?? 0) + ($t['assistente'] ?? 0);
    $base = $emPosto + ($t['buffer'] ?? 0);
    $nivel = (int) $s['nivel'];
    $alvo = $s['fatia_regular'] !== null ? (float) $s['fatia_regular'] : ($nivel >= 3 ? 1.0 : $fatiaNivel[$nivel] ?? 1.0);
    $m = $merito[$s['rep']] ?? null;
    $nota = $m && $m['nota'] !== null ? (float) $m['nota'] : null;
    $linhas[] = ['rep' => $s['rep'], 'nome' => $nomes[$s['rep']] ?? $s['rep'], 'u' => (int) $s['unidade'], 't' => $s['turno'], 'nucleo' => $s['nucleo'], 'nivel' => $nivel,
        'posto' => $t['posto'] ?? 0, 'assist' => $t['assistente'] ?? 0, 'buffer' => $t['buffer'] ?? 0, 'overlap' => $t['overlap'] ?? 0,
        'pct' => $base ? $emPosto / $base : null, 'alvo' => $alvo, 'm' => $m, 'nota' => $nota,
        'faixa' => $nota === null ? null : ($m['faixa'] ?? escMeritoFaixa($nota, $pts)), 'anos' => (int) ($m['anos'] ?? 0)];
}
// Maior nota primeiro; no empate, mais tempo de casa (M-06).
usort($linhas, static fn($a, $b) => [$a['u'], $a['t'], -($a['nota'] ?? -999), -$a['anos']] <=> [$b['u'], $b['t'], -($b['nota'] ?? -999), -$b['anos']]);
$contaFaixa = ['ACIMA' => 0, 'MEDIA' => 0, 'ABAIXO' => 0, '' => 0];
foreach ($linhas as $l) {
    $contaFaixa[$l['faixa'] ?? '']++;
}
$sinal = static fn($v): string => $v === null ? '—' : ((int) $v > 0 ? '+' . (int) $v : ((int) $v < 0 ? '−' . abs((int) $v) : '0'));
$corPts = static fn($v): string => $v === null ? '' : ((int) $v > 0 ? 'verde' : ((int) $v < 0 ? 'laranja' : 'muted'));
?>
<header class="cabecalho">
  <span class="eyebrow"><?php echo h(t('Análise')); ?></span>
  <h1><?php echo h(t('Mérito dos SRs · {versao}', ['versao' => escPlanoRotulo($plano)])); ?></h1>
  <p class="muted"><?php echo h(t('Turnos de cada SR na escala e o mérito em pontos. No Gerar, quem bateu a meta na página vem primeiro; depois a faixa do mérito, a nota e o tempo de casa. Quem estava no posto fica, a não ser que apareça um SR de faixa acima ou que ele esteja abaixo da meta mínima na página.')); ?></p>
</header>

<?php if ($verMerito && !escAbsOficialOk()): ?>
<p class="aviso" role="status"><?php echo h(t('O sistema não consegue ler as faltas oficiais (5c_wfm_db_mis.abs_adh_af): as faltas ficam com 0 ponto. Peça ao DBA o SELECT nessa tabela para o usuário do sistema.')); ?></p>
<?php endif; ?>
<?php if ($verMerito): ?>
<section class="kpis">
  <div class="kpi"><span class="muted"><?php echo h(t('Acima')); ?></span><strong class="verde"><?php echo $contaFaixa['ACIMA']; ?></strong><span class="muted small"><?php echo h(t('{n} pontos ou mais', ['n' => num($pts['acima'], 0)])); ?></span></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Na média')); ?></span><strong><?php echo $contaFaixa['MEDIA']; ?></strong><span class="muted small"><?php echo h(t('de {a} a {b} pontos', ['a' => str_replace('-', '−', num($pts['abaixo'], 0)), 'b' => num($pts['acima'] - 1, 0)])); ?></span></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Abaixo')); ?></span><strong class="laranja"><?php echo $contaFaixa['ABAIXO']; ?></strong><span class="muted small"><?php echo h(t('menos de {n} pontos', ['n' => str_replace('-', '−', num($pts['abaixo'], 0))])); ?></span></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Sem nota')); ?></span><strong><?php echo $contaFaixa['']; ?></strong><span class="muted small"><?php echo h(t('fora do último cálculo; no Gerar contam como Na média')); ?></span></div>
</section>
<?php else: ?>
<div class="faixa-legado" role="status"><?php echo h(t('O mérito de cada SR (pontos, faltas e infrações) aparece só para os perfis Planejamento e Admin. Aqui ficam os turnos de cada SR na escala.')); ?></div>
<?php endif; ?>

<section class="panel">
  <details>
    <summary><strong><?php echo h(t('Como a nota é calculada')); ?></strong></summary>
    <p><?php echo h(t('A nota é a soma dos pontos abaixo, nos últimos {n} dias até ontem. Sem dado num componente, ele vale 0.', ['n' => (int) escV4Param('merito_janela_dias', '28')])); ?> <a href="<?php echo h(link_tela('merito')); ?>"><?php echo h(t('Ver a explicação completa')); ?></a></p>
    <ul class="small">
      <li><strong><?php echo h(t('Tier:')); ?></strong> <?php echo h(t('tier da página em que o SR mais esteve, regular ou assistente: F 0, E 1, D 2, C 3, B 5, A ou acima 8.')); ?></li>
      <li><strong><?php echo h(t('Meta:')); ?></strong> <?php echo h(t('realizado ÷ meta 5CReps, rateado pelas mensagens do SR: 1 ponto a cada 10% acima ou abaixo de 100%, até ±10.')); ?></li>
      <li><strong><?php echo h(t('Faltas:')); ?></strong> <?php echo h(t('cada dia com falta pesa o dobro do anterior: 1 = −1, 2 = −2, 3 = −4, 4 = −8, 5 ou mais = −16. Falha administrativa não conta.')); ?></li>
      <li><strong>SLA:</strong> <?php echo h(t('80% ou mais = 0; a cada 10% abaixo a pena dobra: −1, −2, −4, −8, −16.')); ?></li>
      <li><strong><?php echo h(t('Casa:')); ?></strong> <?php echo h(t('1 ponto por ano completo de empresa; também desempata.')); ?></li>
      <li><strong><?php echo h(t('Vendas:')); ?></strong> <?php echo h(t('venda por hora, de 0 (20% que menos vendem) a 4 (20% que mais vendem).')); ?></li>
      <li><strong><?php echo h(t('Cargo:')); ?></strong> <?php echo h(t('maior cargo que o SR já teve na estrutura antiga: 0, 1, 3 ou 5.')); ?></li>
      <li><strong><?php echo h(t('Sequência:')); ?></strong> <?php echo h(t('semanas fechadas seguidas batendo 100% da meta, da última para trás (até 4): 1 semana +1, 2 semanas +3, 3 ou mais +5. Semana sem dado é pulada; uma abaixo da meta encerra.')); ?></li>
      <li><strong><?php echo h(t('Infrações:')); ?></strong> <?php echo h(t('−2 por infração dos últimos 90 dias. O cadastro ainda está vazio: vale 0 para todos até a carga.')); ?></li>
    </ul>
  </details>
  <form method="get" action="<?php echo h(MODULE_URL); ?>/index.php" class="acoes">
    <input type="hidden" name="p" value="tertius"><input type="hidden" name="plano" value="<?php echo $planoId; ?>">
    <select name="u" data-envio-auto aria-label="<?php echo h(t('Unidade')); ?>" data-sem-busca><option value=""><?php echo h(t('Todas as unidades')); ?></option><?php foreach ([1, 2, 3] as $u): ?><option value="<?php echo $u; ?>" <?php echo (string) $u === $uSel ? 'selected' : ''; ?>>U<?php echo $u; ?></option><?php endforeach; ?></select>
    <select name="t" data-envio-auto aria-label="<?php echo h(t('Turno')); ?>" data-sem-busca><option value=""><?php echo h(t('Todos os turnos')); ?></option><?php foreach (['A', 'C', 'D'] as $t): ?><option <?php echo $t === $tSel ? 'selected' : ''; ?>><?php echo $t; ?></option><?php endforeach; ?></select>
  </form>
  <?php if (escPodeReal('planejar')): ?>
  <form method="post" action="<?php echo h(link_tela('srs', array_filter(['plano' => $planoId, 'u' => $uSel, 't' => $tSel]))); ?>" class="acoes">
    <?php echo campoCsrf(); ?><button type="submit" class="botao" data-longo="<?php echo h(t('Recalculando o mérito… pode levar até 1 min.')); ?>"><?php echo h(t('Recalcular mérito agora')); ?></button>
    <span class="muted small"><?php echo $calculadoEm ? h(t('Calculado em {quando}; recalcula sozinho a cada {h} h.', ['quando' => escDataHora((string) $calculadoEm), 'h' => (int) escV4Param('merito_cache_horas', '20')])) : h(t('Ainda não calculado (rode o ES_16).')); ?></span>
  </form>
  <?php endif; ?>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th>SR</th><th><?php echo h(t('Unidade · turno · núcleo')); ?></th><th class="num"><?php echo h(t('Nível')); ?></th><th class="num"><?php echo h(t('Em posto')); ?></th><th class="num"><?php echo h(t('Assistente')); ?></th><th class="num">Buffer (-2)</th><th class="num">Overlap (-5)</th><th class="num"><?php echo h(t('% em posto × alvo')); ?></th>
      <?php if ($verMerito): ?><th class="num"><?php echo h(t('Tier')); ?></th><th class="num"><?php echo h(t('Meta')); ?></th><th class="num"><?php echo h(t('Faltas')); ?></th><th class="num">SLA</th><th class="num"><?php echo h(t('Casa')); ?></th><th class="num"><?php echo h(t('Vendas')); ?></th><th class="num"><?php echo h(t('Cargo')); ?></th><th class="num"><abbr title="<?php echo h(t('Infrações')); ?>"><?php echo h(t('Infr.')); ?></abbr></th><th class="num"><abbr title="<?php echo h(t('Semanas seguidas batendo a meta')); ?>"><?php echo h(t('Seq.')); ?></abbr></th><th class="num"><?php echo h(t('Nota')); ?></th><th><?php echo h(t('Faixa')); ?></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($linhas as $l): $m = $l['m']; $desvio = $l['pct'] === null ? null : $l['pct'] - $l['alvo']; ?>
      <tr>
        <td><strong><?php echo h(nomeCurto($l['nome'])); ?></strong><br><span class="muted small mono"><?php echo h($l['rep']); ?></span></td>
        <td class="small">U<?php echo $l['u']; ?> <?php echo chipTurno($l['t']); ?> · <?php echo h(t('núcleo {n}', ['n' => (string) $l['nucleo']])); ?></td>
        <td class="num">SR <?php echo $l['nivel']; ?></td>
        <td class="num"><?php echo (int) $l['posto']; ?></td>
        <td class="num"><?php echo (int) $l['assist']; ?></td>
        <td class="num"><?php echo (int) $l['buffer']; ?></td>
        <td class="num"><?php echo (int) $l['overlap']; ?></td>
        <td class="num <?php echo $desvio !== null && $desvio < -0.2 ? 'laranja' : ''; ?>"><?php echo $l['pct'] === null ? '<span class="muted">' . h(t('sem turno')) . '</span>' : pct($l['pct'], 0); ?> <span class="muted small">× <?php echo pct($l['alvo'], 0); ?></span></td>
        <?php if ($verMerito):
            $c = static fn(string $k) => $m && array_key_exists($k, $m) && $m[$k] !== null ? (int) $m[$k] : null;
            [$fxRot, $fxCls] = $l['faixa'] !== null ? escMeritoFaixaRotulo($l['faixa']) : [t('Sem nota'), '']; ?>
        <td class="num <?php echo $corPts($c('pts_tier')); ?>"><?php echo $sinal($c('pts_tier')); ?><?php if ($m && !empty($m['tier'])): ?> <span class="muted small" title="<?php echo h((string) ($m['pagina_principal'] ?? '')); ?>"><?php echo h($m['tier']); ?></span><?php endif; ?></td>
        <td class="num <?php echo $corPts($c('pts_meta')); ?>"><?php echo $sinal($c('pts_meta')); ?><?php if ($m && $m['desempenho'] !== null): ?> <span class="muted small"><?php echo pct((float) $m['desempenho'], 0); ?></span><?php endif; ?></td>
        <td class="num <?php echo $corPts($c('pts_faltas')); ?>"><?php echo $sinal($c('pts_faltas')); ?><?php if ($m && isset($m['faltas']) && (int) $m['faltas'] > 0): ?> <span class="muted small"><?php echo h(tn((int) $m['faltas'], '{n} dia', '{n} dias')); ?></span><?php endif; ?></td>
        <td class="num <?php echo $corPts($c('pts_sla')); ?>"><?php echo $sinal($c('pts_sla')); ?><?php if ($m && $m['sla'] !== null): ?> <span class="muted small"><?php echo pct((float) $m['sla'], 0); ?></span><?php endif; ?></td>
        <td class="num <?php echo $corPts($c('pts_casa')); ?>"><?php echo $sinal($c('pts_casa')); ?></td>
        <td class="num <?php echo $corPts($c('pts_vendas')); ?>"><?php echo $sinal($c('pts_vendas')); ?><?php if ($m && isset($m['venda_h']) && $m['venda_h'] !== null): ?> <span class="muted small">US$ <?php echo num((float) $m['venda_h'], 0); ?>/h</span><?php endif; ?></td>
        <td class="num <?php echo $corPts($c('pts_cargo')); ?>"><?php echo $sinal($c('pts_cargo')); ?></td>
        <td class="num <?php echo $corPts($c('pts_infracao')); ?>"><?php echo $sinal($c('pts_infracao')); ?></td>
        <td class="num <?php echo $corPts($c('pts_seq')); ?>"><?php echo $sinal($c('pts_seq')); ?><?php if ($m && !empty($m['semanas_meta'])): ?> <span class="muted small"><?php echo h(tn((int) $m['semanas_meta'], '{n} sem.', '{n} sem.')); ?></span><?php endif; ?></td>
        <td class="num"><strong><?php echo $l['nota'] !== null ? $sinal($l['nota']) : '—'; ?></strong></td>
        <td><span class="tag <?php echo $fxCls; ?>"><?php echo h($fxRot); ?></span></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$linhas): ?><tr><td colspan="<?php echo $verMerito ? 19 : 8; ?>" class="muted"><?php echo h(t('Nenhum SR neste filtro.')); ?></td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <p class="muted small"><?php echo h(t('Turnos contados uma vez por dia e turno. "% em posto" = em posto (regular ou assistente) ÷ (em posto + buffer de plantão); o Overlap fica fora. O alvo é a fatia do nível: SR 0 = 40%, SR 1 = 60%, SR 2 = 80%, SR 3 a 5 = 100%. Cada coluna do mérito mostra os pontos e, ao lado, o dado de origem. A nota é a soma; com a mesma nota, vem antes quem tem mais tempo de casa.')); ?></p>
</section>
