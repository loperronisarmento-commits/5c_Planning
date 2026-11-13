<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/bot_cobranca.php
// Bot de cobrança (1.29.0, seção Análise). Só leitura, todos os perfis; a tabela por SR só para
// WFM/Planejamento e Admin (escPodeVerRestrito). Regras e fontes em lib/bot_cobranca.php.
// A cada acesso, calcula a inatividade de uma semana fechada que ainda falte (ES_143, ~1 min).
// ===============================================

$tSel = in_array($_GET['turno'] ?? '', ['A', 'C', 'D'], true) ? $_GET['turno'] : '';
$versao = static fn(string $arq): string => (string) (@filemtime(__DIR__ . '/../assets/' . $arq) ?: time());

$inativ = escBotInativ();
$pendentes = escBotSemanasPendentes($inativ);
$calculada = '';
// Só quem planeja (ou WFM/Admin) dispara o cálculo, que leva cerca de 1 minuto; os outros veem o aviso.
if ($pendentes && (escPode('planejar') || escPodeVerRestrito())) {
    $calculada = escBotCalcularPendente($pendentes);
    if ($calculada !== '') {
        $inativ = escBotInativ();
        $pendentes = escBotSemanasPendentes($inativ);
    }
}
$sla = escBotSla();
$alertas = escBotAlertas();
$semanas = escBotSemanas();
$semAtual = segundaDaSemana(date('Y-m-d'));

// Indicadores de cada período (no turno escolhido).
$ind = [];
foreach (array_keys(ESC_BOT_PERIODOS) as $p) {
    $ss = escBotSemanasDo($p, $semanas);
    $ind[$p] = escBotIndicadores(escBotSomaSla($sla, $ss, $tSel), escBotSomaInativ($inativ, $ss, $tSel));
}
$fmtPct = static fn(?float $v): string => $v === null ? '—' : num($v, 1) . '%';
$fmtN = static fn(?float $v, int $c = 1): string => $v === null ? '—' : num($v, $c);
$dif = static function (?float $a, ?float $b, int $c = 1, string $suf = ''): string {
    if ($a === null || $b === null) {
        return '—';
    }
    $d = round($b - $a, $c);
    return ($d > 0 ? '+' : ($d < 0 ? '−' : '±')) . num(abs($d), $c) . $suf;
};

// Leitura automática: SLA muda se andar 1 ponto ou mais; inatividade, se andar 10% ou mais.
$a = $ind['antes'];
$d = $ind['depois'];
$leituraSla = '';
if ($a['sla5'] !== null && $d['sla5'] !== null) {
    $dd = $d['sla5'] - $a['sla5'];
    $leituraSla = abs($dd) < 1 ? t('O SLA de 5 min ficou estável: {a} antes e {d} com o bot ({dif} pontos).', ['a' => $fmtPct($a['sla5']), 'd' => $fmtPct($d['sla5']), 'dif' => $dif($a['sla5'], $d['sla5'])])
        : ($dd > 0 ? t('O SLA de 5 min melhorou: de {a} para {d} ({dif} pontos).', ['a' => $fmtPct($a['sla5']), 'd' => $fmtPct($d['sla5']), 'dif' => $dif($a['sla5'], $d['sla5'])])
                   : t('O SLA de 5 min piorou: de {a} para {d} ({dif} pontos).', ['a' => $fmtPct($a['sla5']), 'd' => $fmtPct($d['sla5']), 'dif' => $dif($a['sla5'], $d['sla5'])]));
}
$leituraIn = '';
if ($a['epi100'] !== null && $d['epi100'] !== null && $a['epi100'] > 0) {
    $rel = ($d['epi100'] - $a['epi100']) / $a['epi100'];
    $vars = ['a' => $fmtN($a['epi100']), 'd' => $fmtN($d['epi100']), 'pct' => num(abs(100 * $rel), 0)];
    $leituraIn = abs($rel) < 0.10 ? t('As inatividades ficaram no mesmo nível: {a} episódios por 100 blocos antes e {d} com o bot.', $vars)
        : ($rel < 0 ? t('As inatividades caíram {pct}%: de {a} para {d} episódios por 100 blocos.', $vars)
                    : t('As inatividades subiram {pct}%: de {a} para {d} episódios por 100 blocos.', $vars));
}
?>
<link rel="stylesheet" href="<?php echo h(MODULE_URL); ?>/assets/analise.css?v=<?php echo h($versao('analise.css')); ?>">
<div class="analise quadro">
<header class="cabecalho">
  <span class="eyebrow"><?php echo h(t('Análise')); ?></span>
  <h1><?php echo h(t('Bot de cobrança')); ?></h1>
  <p class="muted"><?php echo h(t('O bot que cobra os SRs no Telegram entrou em {dia}. Aqui, semana a semana, o SLA de resposta e as inatividades (página escalada sem mensagem por 15 min ou mais) antes e depois dele.', ['dia' => dataBr(ESC_BOT_INICIO)])); ?></p>
</header>

<section class="panel">
  <form method="get" action="<?php echo h(MODULE_URL); ?>/index.php" class="acoes">
    <input type="hidden" name="p" value="bot">
    <select name="turno" data-envio-auto aria-label="<?php echo h(t('Turno')); ?>">
      <option value=""><?php echo h(t('Todos os turnos')); ?></option>
      <?php foreach (ESC_QD_TURNOS as $tu => $faixa): ?><option value="<?php echo $tu; ?>" <?php echo $tSel === $tu ? 'selected' : ''; ?>><?php echo h($tu . ' · ' . $faixa); ?></option><?php endforeach; ?>
    </select>
  </form>
  <?php if ($leituraSla !== '' || $leituraIn !== ''): ?>
  <p><strong><?php echo h(trim($leituraSla . ' ' . $leituraIn)); ?></strong></p>
  <?php endif; ?>
  <p class="muted small"><?php echo h(t('Antes = {antes}; com o bot = {depois}, ainda na estrutura antiga. A semana em que o bot entrou ({trans}) e as semanas das 3 unidades (desde {nova}) ficam à parte, porque a escala mudou junto.', [
      'antes' => escBotPeriodoDatas('antes'), 'depois' => escBotPeriodoDatas('depois'), 'trans' => escBotPeriodoDatas('transicao'), 'nova' => dataBr(ESC_BOT_PERIODOS['nova'][0])])); ?></p>
  <?php if ($calculada !== ''): ?>
  <p class="muted small"><?php echo h(t('Inatividade da semana de {dia} calculada agora.', ['dia' => dataBr($calculada)])); ?></p>
  <?php endif; ?>
  <?php if ($pendentes): ?>
  <p class="muted small laranja"><?php echo h(t('{n} semana(s) fechada(s) ainda sem a inatividade calculada (a partir de {dia}). Quem planeja calcula uma a cada acesso, em cerca de 1 minuto.', ['n' => count($pendentes), 'dia' => dataBr($pendentes[0])])); ?></p>
  <?php endif; ?>
</section>

<section class="kpis">
  <div class="kpi"><span class="muted"><?php echo h(t('SLA em 5 min')); ?></span>
    <strong class="numero-heroi"><?php echo h($fmtPct($d['sla5'])); ?></strong>
    <span class="muted small"><?php echo h(t('com o bot; antes {a} ({dif} pontos)', ['a' => $fmtPct($a['sla5']), 'dif' => $dif($a['sla5'], $d['sla5'])])); ?></span>
    <span class="muted small"><?php echo h(t('3 unidades: {n}', ['n' => $fmtPct($ind['nova']['sla5'])])); ?></span></div>
  <div class="kpi"><span class="muted"><?php echo h(t('SLA em 10 min')); ?></span>
    <strong><?php echo h($fmtPct($d['sla10'])); ?></strong>
    <span class="muted small"><?php echo h(t('com o bot; antes {a} ({dif} pontos)', ['a' => $fmtPct($a['sla10']), 'dif' => $dif($a['sla10'], $d['sla10'])])); ?></span>
    <span class="muted small"><?php echo h(t('3 unidades: {n}', ['n' => $fmtPct($ind['nova']['sla10'])])); ?></span></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Inatividades por 100 blocos')); ?></span>
    <strong><?php echo h($fmtN($d['epi100'])); ?></strong>
    <span class="muted small"><?php echo h(t('com o bot; antes {a} ({dif})', ['a' => $fmtN($a['epi100']), 'dif' => $dif($a['epi100'], $d['epi100'])])); ?></span>
    <span class="muted small"><?php echo h(t('3 unidades: {n}', ['n' => $fmtN($ind['nova']['epi100'])])); ?></span></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Minutos inativos por bloco')); ?></span>
    <strong><?php echo h($fmtN($d['min_bloco'])); ?></strong>
    <span class="muted small"><?php echo h(t('com o bot; antes {a} ({dif})', ['a' => $fmtN($a['min_bloco']), 'dif' => $dif($a['min_bloco'], $d['min_bloco'])])); ?></span>
    <span class="muted small"><?php echo h(t('Blocos sem nenhuma mensagem: {a} antes, {d} com o bot', ['a' => $fmtPct($a['sem_msg']), 'd' => $fmtPct($d['sem_msg'])])); ?></span></div>
</section>

<div class="graficos">
<?php
// Rótulos e marcos (semana em que o bot entrou; primeira semana das 3 unidades).
$rot = array_map(static fn(string $s): string => dataBr($s), $semanas);
$marcos = [];
foreach ($semanas as $i => $s) {
    if ($s === ESC_BOT_PERIODOS['transicao'][0]) {
        $marcos[] = ['i' => $i, 'txt' => t('bot')];
    } elseif ($s === ESC_BOT_PERIODOS['nova'][0]) {
        $marcos[] = ['i' => $i, 'txt' => t('3 unidades')];
    }
}
$parcial = static fn(string $s): string => $s === $semAtual ? ' ' . t('(semana em andamento)') : '';

// ---------- SLA por semana ----------
$v5 = [];
$v10 = [];
$dicas = [];
$tab = [];
foreach ($semanas as $s) {
    $x = escBotIndicadores(escBotSomaSla($sla, [$s], $tSel), escBotSomaInativ([], [], ''));
    $v5[] = $x['sla5'] === null ? null : round($x['sla5'], 1);
    $v10[] = $x['sla10'] === null ? null : round($x['sla10'], 1);
    $dicas[] = t('Semana de {dia}', ['dia' => dataBr($s)]) . $parcial($s) . "\n" . t('SLA 5 min {a} · 10 min {b}', ['a' => $fmtPct($x['sla5']), 'b' => $fmtPct($x['sla10'])]) . "\n" . t('{h} horas de SR', ['h' => num($x['horas'], 0)]);
    $tab[] = [dataBr($s) . $parcial($s), $fmtPct($x['sla5']), $fmtPct($x['sla10']), num($x['horas'], 0)];
}
$titulo = $leituraSla !== '' ? $leituraSla : t('SLA de resposta por semana');
$svg = escGrafLinhas($rot, [
    ['nome' => t('SLA 10 min'), 'cls' => 'cinza', 'tracejada' => true, 'v' => $v10, 'rotulo' => t('10 min')],
    ['nome' => t('SLA 5 min'), 'cls' => 'destaque', 'grossa' => true, 'v' => $v5, 'rotulo' => t('5 min')],
], ['titulo' => $titulo, 'dicas' => $dicas, 'marcos' => $marcos, 'w' => 760, 'fmt' => static fn(float $v): string => num($v, 0) . '%']);
echo escGrafFigura($titulo, t('Respostas do SR em até 5 e 10 minutos, sobre todas as respostas (horas completas no Infloww). A linha pontilhada marca a semana em que o bot entrou e a das 3 unidades.'),
    escGrafLegenda(['destaque' => t('SLA 5 min'), 'tracejada' => t('SLA 10 min')]), $svg, 'largo',
    escGrafTabela(t('Ver tabela'), [t('Semana'), t('SLA 5 min'), t('SLA 10 min'), t('Horas de SR')], array_reverse($tab)));

// ---------- Inatividade por semana ----------
$vE = [];
$v30 = [];
$dicasIn = [];
$tab = [];
foreach ($semanas as $s) {
    if (!isset($inativ[$s])) {
        $vE[] = null;
        $v30[] = null;
        $dicasIn[] = t('Semana de {dia}: ainda sem cálculo', ['dia' => dataBr($s)]);
        continue;
    }
    $x = escBotIndicadores(escBotSomaSla([], [], ''), escBotSomaInativ($inativ, [$s], $tSel));
    $vE[] = $x['epi100'] === null ? null : round($x['epi100'], 1);
    $v30[] = $x['epi30_100'] === null ? null : round($x['epi30_100'], 1);
    $dicasIn[] = t('Semana de {dia}', ['dia' => dataBr($s)]) . "\n" . t('{e} inatividades por 100 blocos ({e30} de 30 min ou mais) · {m} min inativos por bloco', ['e' => $fmtN($x['epi100']), 'e30' => $fmtN($x['epi30_100']), 'm' => $fmtN($x['min_bloco'])])
        . "\n" . t('{b} blocos escalados · {s} sem nenhuma mensagem', ['b' => num($x['blocos'], 0), 's' => $fmtPct($x['sem_msg'])]);
    $tab[] = [dataBr($s), num($x['blocos'], 0), $fmtN($x['epi100']), $fmtN($x['epi30_100']), $fmtN($x['min_bloco']), $fmtPct($x['sem_msg'])];
}
$titulo = $leituraIn !== '' ? $leituraIn : t('Inatividades por semana');
if (array_filter($vE, static fn($v) => $v !== null)) {
    $svg = escGrafLinhas($rot, [
        ['nome' => t('30 min ou mais'), 'cls' => 'cinza', 'tracejada' => true, 'v' => $v30, 'rotulo' => t('30 min ou mais')],
        ['nome' => t('15 min ou mais'), 'cls' => 'destaque', 'grossa' => true, 'v' => $vE, 'rotulo' => t('15 min ou mais')],
    ], ['titulo' => $titulo, 'dicas' => $dicasIn, 'marcos' => $marcos, 'w' => 760, 'zero' => true]);
    echo escGrafFigura($titulo, t('Inatividades por 100 blocos. Episódio = 15 min ou mais sem mensagem de funcionário numa página escalada (a regra do bot), contado nos blocos que tiveram alguma mensagem. Bloco = página × bloco de 4 h do 5C Schedule. Conta toda lacuna, não só as que o bot avisou, por isso o número é bem maior que o de alertas.'),
        escGrafLegenda(['destaque' => t('15 min ou mais'), 'tracejada' => t('30 min ou mais')]), $svg, 'largo',
        escGrafTabela(t('Ver tabela'), [t('Semana'), t('Blocos'), t('Inatividades por 100 blocos'), t('De 30 min ou mais'), t('Minutos inativos por bloco'), t('Blocos sem nenhuma mensagem')], array_reverse($tab)));
} else {
    echo '<figure class="graf-cartao largo"><figcaption><strong>' . h(t('Inatividades por semana')) . '</strong><span class="muted small">' . h(t('Ainda sem semanas calculadas (o ES_143 grava o histórico).')) . '</span></figcaption></figure>';
}

// ---------- Alertas do bot ----------
$tipos = ['inatividade' => t('Inatividade'), 'sem_conversao' => t('Sem conversão'), 'sem_sr' => t('Sem SR'), 'divergencia_a' => t('Divergência A'), 'divergencia_b' => t('Divergência B')];
$tab = [];
foreach (array_reverse($semanas) as $s) {
    if (empty($alertas[$s])) {
        continue;
    }
    $l = [dataBr($s) . $parcial($s)];
    foreach (array_keys($tipos) as $tp) {
        $l[] = isset($alertas[$s][$tp]) ? num($alertas[$s][$tp][0], 0) : '—';
    }
    $tab[] = $l;
}
if ($tab) {
    echo '<figure class="graf-cartao largo"><figcaption><strong>' . h(t('Alertas que o bot abriu por semana')) . '</strong><span class="muted small">'
        . h(t('Só para contexto: o bot registra alertas desde 02/10 e mudou de patamar na semana de 02/11 (escala das 3 unidades), então estes números não servem para comparar antes e depois.')) . '</span></figcaption>'
        . escGrafTabela(t('Ver tabela'), array_merge([t('Semana')], array_values($tipos)), $tab) . '</figure>';
}
?>
</div>

<section class="panel">
  <h2><?php echo h(t('Por período e turno')); ?></h2>
  <div class="tabela-rolagem"><table>
    <thead><tr><th><?php echo h(t('Período')); ?></th><th><?php echo h(t('Turno')); ?></th><th class="num"><?php echo h(t('SLA 5 min')); ?></th><th class="num"><?php echo h(t('SLA 10 min')); ?></th>
      <th class="num"><?php echo h(t('Inatividades por 100 blocos')); ?></th><th class="num"><?php echo h(t('De 30 min ou mais')); ?></th><th class="num"><?php echo h(t('Minutos inativos por bloco')); ?></th><th class="num"><?php echo h(t('Blocos sem nenhuma mensagem')); ?></th></tr></thead>
    <tbody>
    <?php foreach (array_keys(ESC_BOT_PERIODOS) as $p): $ss = escBotSemanasDo($p, $semanas); ?>
      <?php foreach (['', 'A', 'C', 'D'] as $tu): if ($tSel !== '' && $tu !== '' && $tu !== $tSel) { continue; } $x = escBotIndicadores(escBotSomaSla($sla, $ss, $tu), escBotSomaInativ($inativ, $ss, $tu)); ?>
      <tr<?php echo $tu === '' ? ' class="destaque-linha"' : ''; ?>><td><?php if ($tu === ''): ?><strong><?php echo h(escBotPeriodoNome($p)); ?></strong><br><span class="muted small"><?php echo h(escBotPeriodoDatas($p)); ?></span><?php endif; ?></td>
        <td><?php echo h($tu === '' ? t('Todos') : $tu); ?></td>
        <td class="num"><?php echo h($fmtPct($x['sla5'])); ?></td><td class="num"><?php echo h($fmtPct($x['sla10'])); ?></td>
        <td class="num"><?php echo h($fmtN($x['epi100'])); ?></td><td class="num"><?php echo h($fmtN($x['epi30_100'])); ?></td><td class="num"><?php echo h($fmtN($x['min_bloco'])); ?></td><td class="num"><?php echo h($fmtPct($x['sem_msg'])); ?></td></tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>

<?php if (escPodeVerRestrito()):
    $porSr = escBotPorSr();
    $linhas = [];
    foreach ($porSr as $rep => $o) {
        $ia = isset($o['antes']) ? escBotIndicadores(['r' => $o['antes']['r'], 'r5' => $o['antes']['r5'], 'r10' => 0, 'h' => $o['antes']['h']], ['blocos' => $o['antes']['blocos'], 'epi' => $o['antes']['epi'], 'min' => $o['antes']['min'], 'sem_msg' => $o['antes']['sem_msg'], 'com_inativ' => 0, 'epi30' => 0]) : null;
        $id = isset($o['depois']) ? escBotIndicadores(['r' => $o['depois']['r'], 'r5' => $o['depois']['r5'], 'r10' => 0, 'h' => $o['depois']['h']], ['blocos' => $o['depois']['blocos'], 'epi' => $o['depois']['epi'], 'min' => $o['depois']['min'], 'sem_msg' => $o['depois']['sem_msg'], 'com_inativ' => 0, 'epi30' => 0]) : null;
        if (!$ia || !$id || $ia['horas'] < 20 || $id['horas'] < 20) {
            continue;
        }
        $linhas[] = ['rep' => $rep, 'nome' => $o['nome'], 'a' => $ia, 'd' => $id, 'dsla' => ($id['sla5'] ?? 0) - ($ia['sla5'] ?? 0)];
    }
    usort($linhas, static fn($x, $y) => $y['dsla'] <=> $x['dsla']);
    $melhor = count(array_filter($linhas, static fn($l) => $l['dsla'] >= 1));
    $pior = count(array_filter($linhas, static fn($l) => $l['dsla'] <= -1));
?>
<section class="panel">
  <h2><?php echo h(t('Por SR: antes × com o bot')); ?></h2>
  <p class="muted small"><?php echo h(t('Só WFM/Planejamento e Admin veem. SRs do cadastro atual com 20 horas ou mais no Infloww nos dois períodos ({n}): {m} melhoraram o SLA de 5 min em 1 ponto ou mais e {p} pioraram. Inatividade do SR = inatividades das páginas nos blocos em que ele estava escalado.', ['n' => count($linhas), 'm' => $melhor, 'p' => $pior])); ?></p>
  <?php if ($linhas): ?>
  <details class="ver-tabela"><summary class="small"><?php echo h(t('Ver os {n} SRs (maior melhora do SLA primeiro)', ['n' => count($linhas)])); ?></summary>
  <div class="tabela-rolagem"><table>
    <thead><tr><th><?php echo h(t('SR')); ?></th><th class="num"><?php echo h(t('SLA 5 min antes')); ?></th><th class="num"><?php echo h(t('SLA 5 min com o bot')); ?></th><th class="num"><?php echo h(t('Diferença')); ?></th>
      <th class="num"><?php echo h(t('Inatividades por 100 blocos antes')); ?></th><th class="num"><?php echo h(t('Com o bot')); ?></th></tr></thead>
    <tbody><?php foreach ($linhas as $l): ?>
      <tr><td><?php echo h($l['nome']); ?> <span class="muted small"><?php echo h($l['rep']); ?></span></td>
        <td class="num"><?php echo h($fmtPct($l['a']['sla5'])); ?></td><td class="num"><?php echo h($fmtPct($l['d']['sla5'])); ?></td>
        <td class="num <?php echo $l['dsla'] >= 1 ? 'verde' : ($l['dsla'] <= -1 ? 'laranja' : ''); ?>"><?php echo h($dif($l['a']['sla5'], $l['d']['sla5'])); ?></td>
        <td class="num"><?php echo h($fmtN($l['a']['epi100'])); ?></td><td class="num"><?php echo h($fmtN($l['d']['epi100'])); ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div></details>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="panel">
  <h2><?php echo h(t('Como é medido')); ?></h2>
  <ul class="small">
    <li><?php echo h(t('SLA: respostas do SR em até 5 e 10 minutos sobre todas as respostas, nas horas completas de cada SR no Infloww (5c_capacity.cap_rep_hora). Não tira as horas excluídas do SLA justo do mérito.')); ?></li>
    <li><?php echo h(t('Inatividade: a mesma regra do bot (15 min ou mais sem mensagem de funcionário numa página escalada), refeita das mensagens (msgdashboard) e da escala postada (5C Schedule, sem quem faltou), para ter também as semanas antes do bot. Os blocos em que a página não teve nenhuma mensagem ficam à parte: costumam ser página sem fluxo ou fora do msgdashboard.')); ?></li>
    <li><?php echo h(t('Os alertas do bot (5c_bot) começam em 02/10 e mudaram de patamar em 02/11, por isso aparecem só como contexto.')); ?></li>
    <li><?php echo h(t('O relatório não usa o corte de 01/11: compara setembro e outubro.')); ?></li>
  </ul>
</section>
</div>
<script src="<?php echo h(MODULE_URL); ?>/assets/analise.js?v=<?php echo h($versao('analise.js')); ?>" defer></script>
