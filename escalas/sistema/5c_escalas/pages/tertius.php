<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/tertius.php
// Equilíbrio dos SRs: turnos de cada SR no rascunho (em posto,
// assistente, buffer de plantão, Overlap) × a fatia do nível, e o mérito do
// 5C George: nota = Desempenho × SLA × Presença(absenteísmo) ÷ (1 + pena) (lib/merito.php).
// O motor usa a nota como desempate: depois de quem já conhece a página
// (menos troca de gente), o SR de nota maior fica com o posto e o de nota
// menor fica de buffer.
// ===============================================

if (escPost()) {
    if (!escPodeReal('planejar')) {
        throw new RuntimeException(t('Bloqueado: seu perfil não pode fazer isso.'));
    }
    escMeritoCarregar(true);
    escVoltar('tertius', array_filter(['plano' => $_GET['plano'] ?? null, 'u' => $_GET['u'] ?? null, 't' => $_GET['t'] ?? null]), t('Mérito recalculado.'));
}

$planoId = (int) ($_GET['plano'] ?? 0);
if (!$planoId) {
    $p = escPlanoAtual(date('Y-m-d', strtotime(segundaDaSemana(date('Y-m-d')) . ' +1 week'))) ?? escPlanoAtual(segundaDaSemana(date('Y-m-d')));
    $planoId = $p ? (int) $p['id'] : 0;
}
$plano = $planoId ? escQ1('SELECT * FROM ' . escT('esc_plano') . ' WHERE id = ?', [$planoId]) : null;
if (!$plano) {
    echo '<header class="cabecalho"><span class="eyebrow">' . h(t('Análise')) . '</span><h1>' . h(t('Equilíbrio dos SRs')) . '</h1></header><section class="panel"><p class="muted">' . h(t('Gere um rascunho para ver o equilíbrio.')) . '</p></section>';
    return;
}
$seg = substr($plano['semana_inicio'], 0, 10);
$fim = substr($plano['semana_fim'], 0, 10);
$nomes = escNomes();

if (($plano['estrutura'] ?? '') !== 'V4') {
    echo '<header class="cabecalho"><span class="eyebrow">' . h(t('Análise')) . '</span><h1>' . h(t('Equilíbrio dos SRs')) . '</h1></header><section class="panel"><p class="muted">' . h(t('Esta escala é da estrutura antiga (antes de {dia}). O equilíbrio dos SRs vale para as escalas da estrutura nova.', ['dia' => escData('2026-11-01')])) . '</p></section>';
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
// Mérito individual (nota, absenteísmo, infrações): só Planejamento e Admin.
$verMerito = escPodeReal('planejar');
$merito = $verMerito ? escMeritoCarregar() : [];
$notasNorm = escMeritoNotasMotor($merito);
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
    $rel = $notasNorm[$s['rep']] ?? null;
    $linhas[] = ['rep' => $s['rep'], 'nome' => $nomes[$s['rep']] ?? $s['rep'], 'u' => (int) $s['unidade'], 't' => $s['turno'], 'nucleo' => $s['nucleo'], 'nivel' => $nivel,
        'posto' => $t['posto'] ?? 0, 'assist' => $t['assistente'] ?? 0, 'buffer' => $t['buffer'] ?? 0, 'overlap' => $t['overlap'] ?? 0,
        'pct' => $base ? $emPosto / $base : null, 'alvo' => $alvo, 'm' => $m, 'rel' => ($m && $m['nota'] !== null) ? $rel : null];
}
usort($linhas, static fn($a, $b) => [$a['u'], $a['t'], -($a['rel'] ?? -1)] <=> [$b['u'], $b['t'], -($b['rel'] ?? -1)]);
$faixa = static function (?float $rel): array {
    if ($rel === null) {
        return ['Sem dados', ''];
    }
    return $rel >= 1.15 ? ['Acima', 'verde'] : ($rel < 0.85 ? ['Abaixo', 'laranja'] : ['Na média', '']);
};
$contaFaixa = ['Acima' => 0, 'Na média' => 0, 'Abaixo' => 0, 'Sem dados' => 0];
foreach ($linhas as $l) {
    $contaFaixa[$faixa($l['rel'])[0]]++;
}
$meiaVida = (int) escV4Param('merito_infracao_meia_vida_dias', '90');
$janelaInf = (int) escV4Param('merito_infracao_janela_dias', '365');
$pesos = escMeritoPesos();
$pesoEx = max($pesos ?: [1]);
$pAbs = escMeritoAbsParam();
$rotFaixa = ['Acima' => t('Acima'), 'Na média' => t('Na média'), 'Abaixo' => t('Abaixo'), 'Sem dados' => t('Sem dados')];
?>
<header class="cabecalho">
  <span class="eyebrow"><?php echo h(t('Análise')); ?></span>
  <h1><?php echo h(t('Equilíbrio dos SRs · {versao}', ['versao' => escPlanoRotulo($plano)])); ?></h1>
  <p class="muted"><?php echo h(t('Turnos de cada SR na escala e o mérito do 5C George. O motor mexe o mínimo nas pessoas: primeiro fica quem já estava no posto e quem conhece a página; no empate, o SR de nota maior fica com o posto e o de nota menor fica de buffer.')); ?></p>
</header>

<?php if ($verMerito && !escAbsOficialOk()): ?>
<p class="aviso" role="status"><?php echo h(t('O sistema não consegue ler o absenteísmo oficial (5c_wfm_db_mis.abs_adh_af): o mérito usa o 5C Schedule por horário, já sem a escala dobrada. Peça ao DBA o SELECT nessa tabela para o usuário do sistema.')); ?></p>
<?php endif; ?>
<?php if ($verMerito): ?>
<section class="kpis">
  <div class="kpi"><span class="muted"><?php echo h(t('Nota acima da média')); ?></span><strong class="verde"><?php echo $contaFaixa['Acima']; ?></strong><span class="muted small"><?php echo h(t('≥ 115% da mediana')); ?></span></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Na média')); ?></span><strong><?php echo $contaFaixa['Na média']; ?></strong><span class="muted small"><?php echo h(t('85% a 115%')); ?></span></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Abaixo da média')); ?></span><strong class="laranja"><?php echo $contaFaixa['Abaixo']; ?></strong><span class="muted small"><?php echo h(t('< 85% da mediana')); ?></span></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Sem dados')); ?></span><strong><?php echo $contaFaixa['Sem dados']; ?></strong><span class="muted small"><?php echo h(t('menos de {n} respostas em {dias} dias', ['n' => (int) escV4Param('merito_min_respostas', '100'), 'dias' => (int) escV4Param('merito_janela_dias', '28')])); ?></span></div>
</section>
<?php else: ?>
<div class="faixa-legado" role="status"><?php echo h(t('O mérito de cada SR (nota, absenteísmo e infrações) aparece só para os perfis Planejamento e Admin. Aqui ficam os turnos de cada SR na escala.')); ?></div>
<?php endif; ?>

<section class="panel">
  <details>
    <summary><strong><?php echo h(t('Como a nota é calculada')); ?></strong></summary>
    <p><?php echo t('{formula}, nos últimos {n} dias.', ['formula' => '<strong>' . h(t('Nota = Desempenho × SLA × Presença ÷ (1 + pena)')) . '</strong>', 'n' => (int) escV4Param('merito_janela_dias', '28')]); ?></p>
    <ul class="small">
      <li><strong><?php echo h(t('Desempenho:')); ?></strong> <?php echo h(t('realizado ÷ meta 5CReps das páginas em que o SR atendeu, rateado pelas mensagens dele em cada página e turno (a mesma lógica do 5C George).')); ?></li>
      <li><strong>SLA:</strong> <?php echo h(t('respostas em até 5 minutos ÷ respostas.')); ?></li>
      <li><strong><?php echo h(t('Absenteísmo:')); ?></strong> <?php echo h(t('blocos de falta (ABS) ÷ blocos escalados, a mesma classificação do Capacity. Falha administrativa não conta contra o SR.')); ?></li>
      <li><strong><?php echo h(t('Presença:')); ?></strong> <?php echo h(t('1 − absenteísmo até {tol}; acima disso cai rápido até {critico}, onde vale metade. Com {critico} ou mais a nota fica no máximo em {teto} da mediana: o SR nunca fica na média nem acima.', ['tol' => pct($pAbs['tolerancia'], 0), 'critico' => pct($pAbs['critico'], 0), 'teto' => pct($pAbs['teto_critico'], 0)])); ?> <a href="<?php echo h(link_tela('merito')); ?>"><?php echo h(t('Ver a explicação completa')); ?></a></li>
      <li><strong><?php echo h(t('Pena das infrações:')); ?></strong> <?php echo h(t('cada infração vale o peso da classificação ({pesos}) e cai pela metade a cada {meia} dias sem nova infração, até sumir depois de {janela} dias. Exemplo: peso {peso} hoje divide a nota por {d0}; em {meia} dias por {d1}; em {meia2} dias por {d2}. Sem infração, divide por 1.', [
          'pesos' => implode(', ', array_map(static fn($k, $v) => $k . ' ' . num($v, 0), array_keys($pesos), $pesos)), 'meia' => $meiaVida, 'janela' => $janelaInf, 'peso' => num($pesoEx, 0),
          'd0' => num(1 + $pesoEx, 1), 'd1' => num(1 + $pesoEx / 2, 1), 'meia2' => 2 * $meiaVida, 'd2' => num(1 + $pesoEx / 4, 2)])); ?></li>
    </ul>
    <p class="muted small"><?php echo h(t('O cadastro de infrações já existe e ainda está vazio. Quando começar a ser preenchida, a pena entra sozinha. Os parâmetros ficam em Parâmetros, no grupo "merito".')); ?></p>
  </details>
  <form method="get" action="<?php echo h(MODULE_URL); ?>/index.php" class="acoes">
    <input type="hidden" name="p" value="tertius"><input type="hidden" name="plano" value="<?php echo $planoId; ?>">
    <select name="u" onchange="this.form.submit()" aria-label="<?php echo h(t('Unidade')); ?>" data-sem-busca><option value=""><?php echo h(t('Todas as unidades')); ?></option><?php foreach ([1, 2, 3] as $u): ?><option value="<?php echo $u; ?>" <?php echo (string) $u === $uSel ? 'selected' : ''; ?>>U<?php echo $u; ?></option><?php endforeach; ?></select>
    <select name="t" onchange="this.form.submit()" aria-label="<?php echo h(t('Turno')); ?>" data-sem-busca><option value=""><?php echo h(t('Todos os turnos')); ?></option><?php foreach (['A', 'C', 'D'] as $t): ?><option <?php echo $t === $tSel ? 'selected' : ''; ?>><?php echo $t; ?></option><?php endforeach; ?></select>
  </form>
  <?php if (escPodeReal('planejar')): ?>
  <form method="post" action="<?php echo h(link_tela('tertius', array_filter(['plano' => $planoId, 'u' => $uSel, 't' => $tSel]))); ?>" class="acoes">
    <?php echo campoCsrf(); ?><button type="submit" class="botao" data-longo="<?php echo h(t('Recalculando o mérito… pode levar até 1 min.')); ?>"><?php echo h(t('Recalcular mérito agora')); ?></button>
    <span class="muted small"><?php echo $calculadoEm ? h(t('Calculado em {quando}; recalcula sozinho a cada {h} h.', ['quando' => escDataHora((string) $calculadoEm), 'h' => (int) escV4Param('merito_cache_horas', '20')])) : h(t('Ainda não calculado (rode o ES_16).')); ?></span>
  </form>
  <?php endif; ?>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th>SR</th><th><?php echo h(t('Unidade · turno · núcleo')); ?></th><th class="num"><?php echo h(t('Nível')); ?></th><th class="num"><?php echo h(t('Em posto')); ?></th><th class="num"><?php echo h(t('Assistente')); ?></th><th class="num">Buffer (-2)</th><th class="num">Overlap (-5)</th><th class="num"><?php echo h(t('% em posto × alvo')); ?></th><?php if ($verMerito): ?><th class="num"><?php echo h(t('Desempenho')); ?></th><th class="num"><?php echo h(t('SLA 5 min')); ?></th><th class="num"><abbr title="<?php echo h(t('Absenteísmo')); ?>"><?php echo h(t('Absent.')); ?></abbr></th><th class="num"><?php echo h(t('Pena')); ?></th><th class="num"><?php echo h(t('Nota')); ?></th><th><?php echo h(t('Faixa')); ?></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($linhas as $l): $m = $l['m']; [$fx, $cls] = $faixa($l['rel']); $desvio = $l['pct'] === null ? null : $l['pct'] - $l['alvo']; ?>
      <tr>
        <td><strong><?php echo h(nomeCurto($l['nome'])); ?></strong><br><span class="muted small mono"><?php echo h($l['rep']); ?></span></td>
        <td class="small">U<?php echo $l['u']; ?> <?php echo chipTurno($l['t']); ?> · <?php echo h(t('núcleo {n}', ['n' => (string) $l['nucleo']])); ?></td>
        <td class="num">SR <?php echo $l['nivel']; ?></td>
        <td class="num"><?php echo (int) $l['posto']; ?></td>
        <td class="num"><?php echo (int) $l['assist']; ?></td>
        <td class="num"><?php echo (int) $l['buffer']; ?></td>
        <td class="num"><?php echo (int) $l['overlap']; ?></td>
        <td class="num <?php echo $desvio !== null && $desvio < -0.2 ? 'laranja' : ''; ?>"><?php echo $l['pct'] === null ? '<span class="muted">' . h(t('sem turno')) . '</span>' : pct($l['pct'], 0); ?> <span class="muted small">× <?php echo pct($l['alvo'], 0); ?></span></td>
        <?php if ($verMerito): ?>
        <td class="num"><?php echo $m && $m['desempenho'] !== null ? pct((float) $m['desempenho'], 0) : '—'; ?></td>
        <td class="num"><?php echo $m && $m['sla'] !== null ? pct((float) $m['sla'], 0) : '—'; ?></td>
        <td class="num <?php echo $m && $m['absenteismo'] !== null ? ((float) $m['absenteismo'] >= $pAbs['critico'] ? 'vermelho' : ((float) $m['absenteismo'] > $pAbs['tolerancia'] ? 'laranja' : '')) : ''; ?>"><?php echo $m && $m['absenteismo'] !== null ? pct((float) $m['absenteismo'], 0) : '—'; ?></td>
        <td class="num"><?php echo $m && (float) $m['pena'] > 0 ? num((float) $m['pena'], 2) . ' <span class="muted small">(' . (int) $m['infracoes'] . ')</span>' : '<span class="muted">0</span>'; ?></td>
        <td class="num"><strong><?php echo $m && $m['nota'] !== null ? num((float) $m['nota'] * 100, 1) : '—'; ?></strong></td>
        <td><span class="tag <?php echo $cls; ?>"><?php echo h($rotFaixa[$fx] ?? $fx); ?></span></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$linhas): ?><tr><td colspan="<?php echo $verMerito ? 14 : 8; ?>" class="muted"><?php echo h(t('Nenhum SR neste filtro.')); ?></td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <p class="muted small"><?php echo h(t('Turnos contados uma vez por dia e turno. "% em posto" = em posto (regular ou assistente) ÷ (em posto + buffer de plantão); o Overlap fica fora. O alvo é a fatia do nível: SR 0 = 40%, SR 1 = 60%, SR 2 = 80%, SR 3 a 5 = 100%. A nota aparece × 100 (ex.: 62,5 = desempenho × SLA × presença de 0,625). A faixa compara com a mediana de todos os SRs. Absenteísmo em laranja: acima da tolerância; em vermelho: crítico.')); ?></p>
</section>
