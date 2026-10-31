<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/merito.php
// Mérito explicado: o do SR (nota do 5C George, lib/merito.php) e o da unidade (mérito da
// equipe, usado no Funil, e atingimento das páginas na unidade, lib/politica.php). Os números
// vêm dos Parâmetros (o que vale agora). Todos veem a explicação e o simulador; o resumo ao vivo
// por unidade (agregado, sem nomes) fica só para Planejamento e Admin, como o mérito individual.
// Texto em PT e EN lado a lado, como as Regras; imprime no papel timbrado.
// ===============================================

$en = escEmIngles();
$L = static fn(string $pt, string $enTxt): string => $en ? $enTxt : $pt;
$pAbs = escMeritoAbsParam();
$absAtivo = $pAbs['ativo']; // merito_abs_ativo = 0: o absenteísmo sai da nota e fica só como informação
$pol = escPolParam();
$pc = static fn($x, int $c = 0) => pct((float) $x, $c);
$janela = (int) escV4Param('merito_janela_dias', '28');
$minResp = (int) escV4Param('merito_min_respostas', '100');
$tetoDes = (float) str_replace(',', '.', escV4Param('merito_desempenho_teto', '2'));
$pesos = escMeritoPesos();
$meiaVida = (int) escV4Param('merito_infracao_meia_vida_dias', '90');
$janelaInf = (int) escV4Param('merito_infracao_janela_dias', '365');
$verMerito = escPodeReal('planejar');

// Curva da presença: pontos fixos mais os três limites.
$pontos = array_values(array_unique(array_merge([0, 0.03], [$pAbs['tolerancia']], [0.08, 0.10, 0.12], [$pAbs['critico']], [0.20, 0.29, 0.40])));
sort($pontos);

// Mediana das notas de hoje (agregado; serve para a faixa do simulador).
$merito = escMeritoCarregar();
$notas = array_values(array_filter(array_map(static fn($m) => $m['nota'] !== null ? (float) $m['nota'] : null, $merito), static fn($n) => $n !== null));
sort($notas);
$mediana = $notas ? $notas[intdiv(count($notas), 2)] : null;

// Exemplos: mesmo desempenho e SLA, absenteísmo diferente.
$exemplos = [[1.44, 0.97, 0.03], [1.44, 0.97, 0.10], [1.44, 0.97, 0.19], [1.44, 0.97, 0.29], [0.95, 0.85, 0.00]];

// Resumo ao vivo por unidade (só Planejamento e Admin).
$porUt = [];
$porUnidade = [];
if ($verMerito) {
    $hoje = date('Y-m-d');
    $dia = max($hoje, escV4DiaEstrutura());
    foreach (escQ('SELECT UPPER(c.rep_code) AS rep, n.unidade, n.turno FROM ' . escT('esc_colaborador_config') . ' c JOIN ' . escT('esc_nucleo') . " n ON n.id = c.nucleo_id
                    WHERE c.funcao = 'EQUITE' AND c.vigente_desde <= ? AND (c.vigente_ate IS NULL OR c.vigente_ate >= ?)", [$dia, $dia]) as $s) {
        $k = (int) $s['unidade'] . '|' . $s['turno'];
        $porUt[$k] = $porUt[$k] ?? ['u' => (int) $s['unidade'], 't' => $s['turno'], 'srs' => 0, 'notas' => [], 'abs' => [], 'criticos' => 0];
        $porUt[$k]['srs']++;
        $m = $merito[$s['rep']] ?? null;
        if ($m && $m['nota'] !== null) {
            $porUt[$k]['notas'][] = (float) $m['nota'];
        }
        if ($m && $m['absenteismo'] !== null) {
            $porUt[$k]['abs'][] = (float) $m['absenteismo'];
            $porUt[$k]['criticos'] += (float) $m['absenteismo'] >= $pAbs['critico'] ? 1 : 0;
        }
    }
    ksort($porUt);
    try {
        $av = escPolAvaliar($hoje);
        $series = escPolSeries($hoje);
        $de = escMaisDias($hoje, -(int) $pol['pol_janela']);
        foreach ($av['paginas'] as $nome => $pg) {
            $u = (int) $pg['unidade'];
            $porUnidade[$u] = $porUnidade[$u] ?? ['r' => 0.0, 'g' => 0.0, 'paginas' => 0, 'sit' => []];
            $porUnidade[$u]['paginas']++;
            $sit = $pg['situacao']['situacao'];
            $porUnidade[$u]['sit'][$sit] = ($porUnidade[$u]['sit'][$sit] ?? 0) + 1;
            foreach ($series[$nome] ?? [] as $porDia) {
                foreach ($porDia as $d => [$r, $g]) {
                    if ($d >= $de) {
                        $porUnidade[$u]['r'] += $r;
                        $porUnidade[$u]['g'] += $g;
                    }
                }
            }
        }
        ksort($porUnidade);
    } catch (Throwable $e) {
        appLog('warning', 'Mérito: resumo da política indisponível', ['erro' => $e->getMessage()]);
    }
}
$mediaNum = static function (array $v): ?float {
    return $v ? array_sum($v) / count($v) : null;
};
$medianaDe = static function (array $v): ?float {
    if (!$v) {
        return null;
    }
    sort($v);
    return $v[intdiv(count($v), 2)];
};
$rotSit = [
    'META' => [$L('na meta', 'on target'), 'verde'], 'DESTAQUE' => [$L('destaque', 'highlight'), 'verde'],
    'ACOMPANHAMENTO' => [$L('acompanhamento', 'monitoring'), 'amarelo'], 'PRAZO' => [$L('prazo correndo', 'deadline running'), 'laranja'],
    'ACOMP_VENCIDO' => [$L('acompanhamento vencido', 'monitoring overdue'), 'laranja'], 'VENCIDO' => [$L('prazo vencido', 'deadline missed'), 'vermelho'],
    'CARENCIA' => [$L('carência', 'grace period'), 'cinza'], 'AGUARDANDO' => [$L('aguardando dados', 'waiting for data'), 'cinza'],
];
$faixaDe = static function (?float $nota) use ($mediana, $L): array {
    if ($nota === null || !$mediana) {
        return ['—', ''];
    }
    $rel = $nota / $mediana;
    return $rel >= 1.15 ? [$L('Acima', 'Above'), 'verde'] : ($rel < 0.85 ? [$L('Abaixo', 'Below'), 'laranja'] : [$L('Na média', 'Average'), '']);
};
?>
<header class="cabecalho">
  <span class="eyebrow"><?php echo h($L('Ajuda', 'Help')); ?></span>
  <h1><?php echo h($L('Mérito do SR e da unidade', 'SR and unit merit')); ?></h1>
  <p class="muted"><?php echo h($L('Como o 5C Escalas mede o mérito de cada representante e de cada unidade, e onde isso pesa. Os números são os dos Parâmetros de hoje.', 'How 5C Escalas measures the merit of each representative and each unit, and where it counts. Numbers are today\'s Parameters.')); ?> <?php echo h($L('Versão', 'Version')); ?> <?php echo h(ESC_VERSAO); ?> · <?php echo h(dataBr(date('Y-m-d'))); ?></p>
</header>

<section class="panel regras merito">
  <div class="acoes nao-imprimir"><button type="button" class="botao primario" onclick="window.print()"><?php echo h($L('Imprimir / PDF', 'Print / PDF')); ?></button> <a class="botao" href="<?php echo h(link_tela('regras')); ?>"><?php echo h($L('Todas as regras', 'All rules')); ?></a><?php if ($verMerito): ?> <a class="botao" href="<?php echo h(link_tela('tertius')); ?>"><?php echo h($L('Mérito de cada SR', 'Each SR\'s merit')); ?></a><?php endif; ?></div>
  <nav class="regras-indice" aria-label="<?php echo h($L('Índice', 'Contents')); ?>"><ol>
    <li><a href="#sr"><?php echo h($L('Mérito do SR', 'SR merit')); ?></a></li>
    <li><a href="#absenteismo"><?php echo h($L('Absenteísmo', 'Absenteeism')); ?></a></li>
    <li><a href="#simulador"><?php echo h($L('Simulador', 'Simulator')); ?></a></li>
    <li><a href="#unidade"><?php echo h($L('Mérito da unidade', 'Unit merit')); ?></a></li>
    <li><a href="#uso"><?php echo h($L('Onde o mérito pesa', 'Where merit counts')); ?></a></li>
  </ol></nav>

  <section class="regras-secao" id="sr">
    <h2>1. <?php echo h($L('Mérito do SR', 'SR merit')); ?></h2>
    <p class="merito-formula"><strong><?php echo h($absAtivo ? $L('Nota = Desempenho × SLA × Presença ÷ (1 + pena)', 'Score = Performance × SLA × Attendance ÷ (1 + penalty)') : $L('Nota = Desempenho × SLA ÷ (1 + pena)', 'Score = Performance × SLA ÷ (1 + penalty)')); ?></strong></p>
    <p class="muted small"><?php echo h($L('Últimos ' . $janela . ' dias até ontem. A nota aparece × 100 (0,625 = 62,5). Sem pelo menos ' . $minResp . ' respostas na janela o SR fica "sem dados" e conta como a mediana.', 'Last ' . $janela . ' days up to yesterday. The score is shown × 100 (0.625 = 62.5). With fewer than ' . $minResp . ' replies in the window the SR is "no data" and counts as the median.')); ?></p>
    <div class="tabela-rolagem"><table>
      <thead><tr><th><?php echo h($L('Parte', 'Part')); ?></th><th><?php echo h($L('Como mede', 'How it is measured')); ?></th><th><?php echo h($L('Fonte', 'Source')); ?></th></tr></thead>
      <tbody>
        <tr><td><strong><?php echo h($L('Desempenho', 'Performance')); ?></strong></td><td><?php echo h($L('5CReps realizado ÷ meta 5CReps das páginas que o SR atendeu, rateado pelas mensagens dele em cada página, dia e turno (a lógica do 5C George). Teto de ' . $pc($tetoDes) . ', para meta muito pequena não explodir.', '5CReps actual ÷ 5CReps target of the pages the SR worked, split by their messages on each page, day and shift (the 5C George logic). Capped at ' . $pc($tetoDes) . ' so a tiny target does not blow up.')); ?></td><td class="small">Intraday · cap_rep_hora</td></tr>
        <tr><td><strong>SLA</strong></td><td><?php echo h($L('Respostas em até 5 minutos ÷ respostas.', 'Replies within 5 minutes ÷ replies.')); ?></td><td class="small">cap_rep_hora</td></tr>
        <tr><td><strong><?php echo h($L('Presença', 'Attendance')); ?></strong></td><td><?php echo h($absAtivo ? $L('Vem do absenteísmo (seção 2): até ' . $pc($pAbs['tolerancia']) . ' quase não pesa; acima disso cai rápido.', 'Comes from absenteeism (section 2): up to ' . $pc($pAbs['tolerancia']) . ' it barely counts; above that it drops fast.')
              : $L('Desligada (merito_abs_ativo = 0): vale 1 para todos. O absenteísmo aparece só como informação.', 'Turned off (merito_abs_ativo = 0): it is 1 for everyone. Absenteeism is shown for information only.')); ?></td><td class="small">abs_adh_af</td></tr>
        <tr><td><strong><?php echo h($L('Pena', 'Penalty')); ?></strong></td><td><?php echo h($L('Soma das infrações: peso da classificação (' . implode(', ', array_map(static fn($k, $v) => $k . ' ' . num($v, 0), array_keys($pesos), $pesos)) . ') que cai pela metade a cada ' . $meiaVida . ' dias sem outra e some depois de ' . $janelaInf . ' dias. Sem infração, divide por 1.', 'Sum of infractions: classification weight (' . implode(', ', array_map(static fn($k, $v) => $k . ' ' . num($v, 0), array_keys($pesos), $pesos)) . ') that halves every ' . $meiaVida . ' days without another one and disappears after ' . $janelaInf . ' days. No infraction divides by 1.')); ?></td><td class="small">esc_sr_infraction</td></tr>
      </tbody>
    </table></div>
    <p><?php echo h($L('Faixa: a nota comparada com a mediana de todos os SRs. Acima = 115% da mediana ou mais; Na média = 85% a 115%; Abaixo = menos de 85%.', 'Band: the score compared with the median of all SRs. Above = 115% of the median or more; Average = 85% to 115%; Below = under 85%.')); ?><?php if ($mediana): ?> <span class="muted"><?php echo h($L('Mediana de hoje: ', 'Today\'s median: ') . num($mediana * 100, 1)); ?>.</span><?php endif; ?></p>
  </section>

  <section class="regras-secao" id="absenteismo">
    <h2>2. <?php echo h($L('Absenteísmo e presença', 'Absenteeism and attendance')); ?></h2>
    <?php if (!$absAtivo): ?>
    <p><strong><?php echo h($L('O absenteísmo está fora da nota (Parâmetros › merito_abs_ativo = 0): a presença vale 1 e não há teto para o absenteísmo crítico. Ele continua medido e aparece nas telas, só como informação. Para voltar, ponha 1 no parâmetro.', 'Absenteeism is left out of the score (Parameters › merito_abs_ativo = 0): attendance is 1 and there is no cap for critical absenteeism. It is still measured and shown on the screens, for information only. To turn it back on, set the parameter to 1.')); ?></strong></p>
    <?php endif; ?>
    <ul<?php echo $absAtivo ? '' : ' class="muted"'; ?>>
      <li><?php echo h($L('Absenteísmo = blocos de falta (ABS) ÷ blocos escalados, na mesma classificação que o Capacity usa para os buffers. Falha administrativa (AF: escala dobrada, quando o SR estava em outra página no mesmo horário, ou fora do horário) não conta contra o SR, nem como falta nem como bloco escalado; bloco substituído também não.', 'Absenteeism = absence blocks (ABS) ÷ scheduled blocks, the same classification Capacity uses for buffers. Administrative failure (AF: double scheduling, when the SR was on another page at the same time, or outside the shift) does not count against the SR, neither as an absence nor as a scheduled block; neither does a substituted block.')); ?></li>
      <li><?php echo h($L('Até ' . $pc($pAbs['tolerancia']) . ' (tolerância): presença = 1 − absenteísmo.', 'Up to ' . $pc($pAbs['tolerancia']) . ' (tolerance): attendance = 1 − absenteeism.')); ?></li>
      <li><?php echo h($L('De ' . $pc($pAbs['tolerancia']) . ' a ' . $pc($pAbs['critico']) . ': a presença cai em linha reta até ' . $pc($pAbs['piso']) . ' do que valia na tolerância.', 'From ' . $pc($pAbs['tolerancia']) . ' to ' . $pc($pAbs['critico']) . ': attendance drops in a straight line down to ' . $pc($pAbs['piso']) . ' of its value at tolerance.')); ?></li>
      <li><strong><?php echo h($L('Com ' . $pc($pAbs['critico']) . ' ou mais (crítico): a nota fica no máximo em ' . $pc($pAbs['teto_critico']) . ' da mediana. O SR nunca aparece na média nem acima, por melhor que seja o desempenho e o SLA.', 'At ' . $pc($pAbs['critico']) . ' or more (critical): the score is capped at ' . $pc($pAbs['teto_critico']) . ' of the median. The SR never shows as average or above, however good performance and SLA are.')); ?></strong></li>
    </ul>
    <div class="tabela-rolagem"><table class="merito-curva">
      <thead><tr><th class="num"><?php echo h($L('Absenteísmo', 'Absenteeism')); ?></th><th class="num"><?php echo h($L('Presença', 'Attendance')); ?></th><th class="num"><?php echo h($L('Antes (1 − abs.)', 'Before (1 − abs.)')); ?></th><th><?php echo h($L('Leitura', 'Reading')); ?></th></tr></thead>
      <tbody>
      <?php foreach ($pontos as $a): $pr = escMeritoPresenca($a, $pAbs); $cls = $a >= $pAbs['critico'] ? 'vermelho' : ($a > $pAbs['tolerancia'] ? 'laranja' : ''); ?>
        <tr><td class="num <?php echo $cls; ?>"><?php echo $pc($a); ?></td><td class="num"><strong><?php echo num($pr, 2); ?></strong> <span class="barra-presenca" style="--w:<?php echo round($pr * 100); ?>%"></span></td><td class="num muted"><?php echo num(1 - $a, 2); ?></td>
          <td class="small"><?php echo h($a <= $pAbs['tolerancia'] ? $L('dentro da tolerância', 'within tolerance') : ($a < $pAbs['critico'] ? $L('pesa mais a cada ponto', 'weighs more with every point') : $L('crítico: sempre abaixo da média', 'critical: always below average'))); ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <h3><?php echo h($L('Exemplos', 'Examples')); ?></h3>
    <div class="tabela-rolagem"><table>
      <thead><tr><th class="num"><?php echo h($L('Desempenho', 'Performance')); ?></th><th class="num">SLA</th><th class="num"><?php echo h($L('Absenteísmo', 'Absenteeism')); ?></th><th class="num"><?php echo h($L('Nota', 'Score')); ?></th><th><?php echo h($L('Faixa hoje', 'Band today')); ?></th></tr></thead>
      <tbody>
      <?php foreach ($exemplos as [$d, $s, $a]):
          $n = escMeritoNota($d, $s, $a, 0.0, $pAbs);
          if ($absAtivo && $mediana && $a >= $pAbs['critico']) {
              $n = min($n, $mediana * $pAbs['teto_critico']);
          }
          [$fx, $fc] = $faixaDe($n); ?>
        <tr><td class="num"><?php echo $pc($d); ?></td><td class="num"><?php echo $pc($s); ?></td><td class="num <?php echo $a >= $pAbs['critico'] ? 'vermelho' : ($a > $pAbs['tolerancia'] ? 'laranja' : ''); ?>"><?php echo $pc($a); ?></td><td class="num"><strong><?php echo num($n * 100, 1); ?></strong></td><td><span class="tag <?php echo $fc; ?>"><?php echo h($fx); ?></span></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>

  <section class="regras-secao nao-imprimir" id="simulador">
    <h2>3. <?php echo h($L('Simulador', 'Simulator')); ?></h2>
    <p class="muted small"><?php echo h($L('Mude os números e veja a nota. A faixa usa a mediana de hoje.', 'Change the numbers and see the score. The band uses today\'s median.')); ?></p>
    <form class="merito-sim" data-merito-sim data-tol="<?php echo h((string) $pAbs['tolerancia']); ?>" data-critico="<?php echo h((string) $pAbs['critico']); ?>" data-piso="<?php echo h((string) $pAbs['piso']); ?>" data-teto="<?php echo h((string) $pAbs['teto_critico']); ?>" data-abs-ativo="<?php echo $absAtivo ? '1' : '0'; ?>" data-mediana="<?php echo h((string) ($mediana ?? 0)); ?>" data-teto-des="<?php echo h((string) $tetoDes); ?>" onsubmit="return false">
      <label><?php echo h($L('Desempenho %', 'Performance %')); ?><input type="number" name="des" value="144" min="0" max="400" step="1" inputmode="decimal"></label>
      <label><?php echo h($L('SLA 5 min %', 'SLA 5 min %')); ?><input type="number" name="sla" value="97" min="0" max="100" step="1" inputmode="decimal"></label>
      <label><?php echo h($L('Absenteísmo %', 'Absenteeism %')); ?><input type="number" name="abs" value="29" min="0" max="100" step="0.5" inputmode="decimal"></label>
      <label><?php echo h($L('Pena', 'Penalty')); ?><input type="number" name="pena" value="0" min="0" max="20" step="0.1" inputmode="decimal"></label>
      <output class="merito-sim-saida" aria-live="polite">
        <span><?php echo h($L('Presença', 'Attendance')); ?> <strong data-sim="presenca">—</strong></span>
        <span><?php echo h($L('Nota', 'Score')); ?> <strong data-sim="nota">—</strong></span>
        <span class="tag" data-sim="faixa">—</span>
        <span class="small muted" data-sim="obs"></span>
      </output>
    </form>
  </section>

  <section class="regras-secao" id="unidade">
    <h2>4. <?php echo h($L('Mérito da unidade', 'Unit merit')); ?></h2>
    <p><?php echo h($L('A unidade é medida de dois jeitos, um pelas pessoas e outro pelas páginas:', 'The unit is measured two ways, one by its people and one by its pages:')); ?></p>
    <ul>
      <li><strong><?php echo h($L('Mérito da equipe', 'Team merit')); ?>:</strong> <?php echo h($L('a nota média dos SRs do núcleo (o mesmo cálculo da seção 1' . ($absAtivo ? ', já com a presença' : '') . '). É o que o Funil usa para indicar onde entra uma página nova.', 'the average score of the core\'s SRs (the section 1 calculation' . ($absAtivo ? ', attendance included' : '') . '). It is what the Funnel uses to suggest where a new page goes.')); ?></li>
      <li><strong><?php echo h($L('Atingimento das páginas', 'Page attainment')); ?>:</strong> <?php echo h($L('5CReps realizado ÷ meta 5CReps (Intraday) de cada página na unidade, em todos os turnos, desde que a página entrou na unidade; e de cada página em cada turno, desde que o owner atual assumiu. Janela de ' . (int) $pol['pol_janela'] . ' dias, com pelo menos ' . (int) $pol['pol_dias_minimos'] . ' dias de dados.', '5CReps actual ÷ 5CReps target (Intraday) of each page in the unit, across all shifts, since the page joined the unit; and of each page in each shift, since the current owner took over. ' . (int) $pol['pol_janela'] . '-day window, with at least ' . (int) $pol['pol_dias_minimos'] . ' days of data.')); ?></li>
    </ul>
    <div class="tabela-rolagem"><table>
      <thead><tr><th><?php echo h($L('Atingimento', 'Attainment')); ?></th><th><?php echo h($L('Situação', 'Status')); ?></th><th><?php echo h($L('O que acontece', 'What happens')); ?></th></tr></thead>
      <tbody>
        <tr><td><?php echo h($L($pc($pol['pol_destaque']) . ' ou mais por ' . (int) $pol['pol_destaque_dias'] . ' dias', $pc($pol['pol_destaque']) . ' or more for ' . (int) $pol['pol_destaque_dias'] . ' days')); ?></td><td><span class="tag verde"><?php echo h($rotSit['DESTAQUE'][0]); ?></span></td><td><?php echo h($L('Mantém e o núcleo vira candidato a página maior.', 'Keeps the page and the core becomes a candidate for a bigger page.')); ?></td></tr>
        <tr><td><?php echo h($L('100% ou mais', '100% or more')); ?></td><td><span class="tag verde"><?php echo h($rotSit['META'][0]); ?></span></td><td><?php echo h($L('Mantém.', 'Keeps the page.')); ?></td></tr>
        <tr><td><?php echo h($pc($pol['pol_corte_acomp']) . ' – 100%'); ?></td><td><span class="tag amarelo"><?php echo h($rotSit['ACOMPANHAMENTO'][0]); ?></span></td><td><?php echo h($L((int) $pol['pol_prazo_acomp'] . ' dias para voltar à meta antes de sugerir troca.', (int) $pol['pol_prazo_acomp'] . ' days to get back to target before a swap is suggested.')); ?></td></tr>
        <tr><td><?php echo h($L('abaixo de ', 'below ') . $pc($pol['pol_corte_acomp'])); ?></td><td><span class="tag laranja"><?php echo h($rotSit['PRAZO'][0]); ?></span></td><td><?php echo h($L('Prazo = ' . (int) $pol['pol_prazo_min'] . ' + (' . (int) $pol['pol_prazo_max'] . ' − ' . (int) $pol['pol_prazo_min'] . ') × ((atingimento − ' . $pc($pol['pol_piso']) . ') ÷ (' . $pc($pol['pol_corte_acomp']) . ' − ' . $pc($pol['pol_piso']) . '))^' . str_replace('.', ',', (string) $pol['pol_gamma']) . ' dias. Quanto pior, menos tempo.', 'Deadline = ' . (int) $pol['pol_prazo_min'] . ' + (' . (int) $pol['pol_prazo_max'] . ' − ' . (int) $pol['pol_prazo_min'] . ') × ((attainment − ' . $pc($pol['pol_piso']) . ') ÷ (' . $pc($pol['pol_corte_acomp']) . ' − ' . $pc($pol['pol_piso']) . '))^' . (string) $pol['pol_gamma'] . ' days. The worse, the less time.')); ?>
          <span class="muted small"><?php echo h(implode(' · ', array_map(static fn($a) => $pc($a) . ' → ' . escPolPrazo($a, $pol) . ($en ? 'd' : 'd'), [0.50, 0.60, 0.70, 0.80]))); ?></span></td></tr>
        <tr><td><?php echo h($L('prazo passou', 'deadline passed')); ?></td><td><span class="tag vermelho"><?php echo h($rotSit['VENCIDO'][0]); ?></span></td><td><?php echo h($L('Só um turno vai mal: troca de owner no núcleo dono (entra o melhor mérito). A página vai mal na unidade: troca de unidade, com prévia e confirmação, até ' . (int) $pol['pol_max_trocas_semana'] . ' páginas por unidade por semana.', 'Only one shift does badly: owner change in the owning core (best merit comes in). The page does badly in the unit: unit change, with preview and confirmation, up to ' . (int) $pol['pol_max_trocas_semana'] . ' pages per unit per week.')); ?></td></tr>
      </tbody>
    </table></div>
    <p class="muted small"><?php echo h($L('Depois de uma troca a página fica ' . (int) $pol['pol_carencia'] . ' dias em carência, sem reavaliar. Prioridades fixas (ex.: páginas do time legado) vencem a nota.', 'After a swap the page has a ' . (int) $pol['pol_carencia'] . '-day grace period, without re-evaluation. Fixed priorities (e.g. legacy team pages) override the score.')); ?></p>

    <?php if ($verMerito && ($porUt || $porUnidade)): ?>
    <h3><?php echo h($L('Hoje, por unidade', 'Today, by unit')); ?> <span class="muted small">(<?php echo h($L('só Planejamento e Admin', 'Planning and Admin only')); ?>)</span></h3>
    <div class="tabela-rolagem"><table>
      <thead><tr><th><?php echo h($L('Unidade · turno', 'Unit · shift')); ?></th><th class="num">SRs</th><th class="num"><?php echo h($L('Com nota', 'With score')); ?></th><th class="num"><?php echo h($L('Nota mediana', 'Median score')); ?></th><th class="num"><?php echo h($L('Absenteísmo médio', 'Average absenteeism')); ?></th><th class="num"><?php echo h($L('Críticos', 'Critical')); ?></th></tr></thead>
      <tbody>
      <?php foreach ($porUt as $l): $mn = $medianaDe($l['notas']); $ma = $mediaNum($l['abs']); ?>
        <tr><td>U<?php echo $l['u']; ?> <?php echo chipTurno((string) $l['t']); ?></td><td class="num"><?php echo $l['srs']; ?></td><td class="num"><?php echo count($l['notas']); ?></td><td class="num"><strong><?php echo $mn !== null ? num($mn * 100, 1) : '—'; ?></strong></td>
          <td class="num <?php echo $ma !== null && $ma >= $pAbs['critico'] ? 'vermelho' : ($ma !== null && $ma > $pAbs['tolerancia'] ? 'laranja' : ''); ?>"><?php echo $ma !== null ? $pc($ma, 1) : '—'; ?></td><td class="num <?php echo $l['criticos'] ? 'vermelho' : 'muted'; ?>"><?php echo $l['criticos']; ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if ($porUnidade): ?>
    <div class="tabela-rolagem"><table>
      <thead><tr><th><?php echo h($L('Unidade', 'Unit')); ?></th><th class="num"><?php echo h($L('Páginas', 'Pages')); ?></th><th class="num"><?php echo h($L('Atingimento ' . (int) $pol['pol_janela'] . ' dias', (int) $pol['pol_janela'] . '-day attainment')); ?></th><th><?php echo h($L('Situação das páginas', 'Page status')); ?></th></tr></thead>
      <tbody>
      <?php foreach ($porUnidade as $u => $l): $at = $l['g'] > 0 ? $l['r'] / $l['g'] : null; ?>
        <tr><td><strong>U<?php echo (int) $u; ?></strong></td><td class="num"><?php echo $l['paginas']; ?></td>
          <td class="num <?php echo $at === null ? '' : ($at >= 1 ? 'verde' : ($at >= $pol['pol_corte_acomp'] ? '' : 'laranja')); ?>"><strong><?php echo $at !== null ? $pc($at, 1) : '—'; ?></strong></td>
          <td><?php foreach ($rotSit as $k => [$rot, $cls]): if (!empty($l['sit'][$k])): ?><span class="tag <?php echo $cls; ?>"><?php echo h($rot); ?> <?php echo (int) $l['sit'][$k]; ?></span> <?php endif; endforeach; ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <p class="muted small"><?php echo h($L('Detalhe por página e turno em Páginas › Owners e backups.', 'Detail by page and shift in Pages › Owners and backups.')); ?> <a href="<?php echo h(link_tela('owners')); ?>"><?php echo h($L('Abrir', 'Open')); ?></a></p>
    <?php endif; ?>
    <?php endif; ?>
  </section>

  <section class="regras-secao" id="uso">
    <h2>5. <?php echo h($L('Onde o mérito pesa', 'Where merit counts')); ?></h2>
    <ul>
      <li><strong><?php echo h($L('Escala', 'Schedule')); ?>:</strong> <?php echo h($L('no empate (depois de quem já estava no posto e de quem conhece a página), o SR de nota maior fica com o posto e o de nota menor fica de buffer.', 'on a tie (after whoever was already on the post and whoever knows the page), the SR with the higher score gets the post and the lower one becomes buffer.')); ?></li>
      <li><strong><?php echo h($L('Owner', 'Owner')); ?>:</strong> <?php echo h($L('na troca de owner entra o SR de nota mais alta do núcleo dono, respeitando as prioridades fixas.', 'on an owner change, the core\'s highest-scoring SR comes in, respecting fixed priorities.')); ?></li>
      <li><strong><?php echo h($L('Página nova', 'New page')); ?>:</strong> <?php echo h($L('o mérito da equipe vale 25 de 100 pontos no Funil (35 em página de tier alto, 15 em tier baixo).', 'team merit is worth 25 of 100 points in the Funnel (35 for high-tier pages, 15 for low-tier).')); ?></li>
      <li><strong><?php echo h($L('Troca de unidade', 'Unit change')); ?>:</strong> <?php echo h($L('no destino, o núcleo dono é o de melhor mérito com menos carga por SR.', 'at the destination, the owning core is the one with the best merit and the least load per SR.')); ?></li>
    </ul>
    <p class="muted small"><?php echo h($L('Os números de cada pessoa ficam só para Planejamento e Admin. Os parâmetros (liga/desliga, tolerância, crítico, piso e teto do absenteísmo) ficam em Parâmetros, grupo "merito".', 'Each person\'s numbers are shown only to Planning and Admin. The parameters (absenteeism on/off, tolerance, critical, floor and cap) are in Parameters, group "merito".')); ?></p>
  </section>
</section>
<script>
(function () {
  var f = document.querySelector('[data-merito-sim]');
  if (!f) { return; }
  var d = f.dataset, tol = +d.tol, cri = +d.critico, piso = +d.piso, teto = +d.teto, med = +d.mediana, tetoDes = +d.tetoDes, absAtivo = d.absAtivo !== '0';
  var en = <?php echo $en ? 'true' : 'false'; ?>;
  var rot = en ? { acima: 'Above', media: 'Average', abaixo: 'Below', crit: 'Critical absenteeism: capped at ', med: ' of the median.', semMed: 'No median yet.' }
               : { acima: 'Acima', media: 'Na média', abaixo: 'Abaixo', crit: 'Absenteísmo crítico: limitado a ', med: ' da mediana.', semMed: 'Ainda sem mediana.' };
  var fmt = function (x, c) { return x.toLocaleString(en ? 'en-US' : 'pt-BR', { minimumFractionDigits: c, maximumFractionDigits: c }); };
  function presenca(a) {
    if (!absAtivo) { return 1; }
    a = Math.min(1, Math.max(0, a));
    if (a <= tol) { return 1 - a; }
    var b = 1 - tol;
    if (a <= cri) { return b * (1 - (1 - piso) * (a - tol) / (cri - tol)); }
    return b * piso * (1 - a) / (1 - cri);
  }
  function calc() {
    var v = function (n) { var x = parseFloat(String(f.elements[n].value).replace(',', '.')); return isNaN(x) ? 0 : x; };
    var a = v('abs') / 100, p = presenca(a);
    var nota = Math.min(tetoDes, v('des') / 100) * Math.min(1, v('sla') / 100) * p / (1 + Math.max(0, v('pena')));
    var obs = '';
    if (absAtivo && a >= cri && med > 0 && nota > med * teto) { nota = med * teto; obs = rot.crit + fmt(teto * 100, 0) + '%' + rot.med; }
    f.querySelector('[data-sim="presenca"]').textContent = fmt(p, 2);
    f.querySelector('[data-sim="nota"]').textContent = fmt(nota * 100, 1);
    var tag = f.querySelector('[data-sim="faixa"]');
    if (med > 0) {
      var r = nota / med;
      tag.textContent = r >= 1.15 ? rot.acima : (r < 0.85 ? rot.abaixo : rot.media);
      tag.className = 'tag ' + (r >= 1.15 ? 'verde' : (r < 0.85 ? 'laranja' : ''));
    } else { tag.textContent = '—'; obs = obs || rot.semMed; }
    f.querySelector('[data-sim="obs"]').textContent = obs;
  }
  f.addEventListener('input', calc);
  calc();
})();
</script>
