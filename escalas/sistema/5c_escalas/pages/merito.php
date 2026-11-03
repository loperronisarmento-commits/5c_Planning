<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/merito.php
// Mérito explicado: o do SR (nota em pontos, M-01 a M-09, lib/merito.php) e o da unidade
// (mérito da equipe, usado no Funil, e atingimento das páginas na unidade, lib/politica.php).
// Os números vêm dos Parâmetros (o que vale agora). Todos veem a explicação e o simulador; o
// resumo ao vivo por unidade (agregado, sem nomes) fica só para Planejamento e Admin, como o
// mérito individual. Texto em PT e EN lado a lado, como as Regras; imprime no papel timbrado.
// ===============================================

$en = escEmIngles();
$L = static fn(string $pt, string $enTxt): string => $en ? $enTxt : $pt;
$pts = escMeritoPtsParam();
$pol = escPolParam();
$pc = static fn($x, int $c = 0) => pct((float) $x, $c);
$janela = (int) escV4Param('merito_janela_dias', '28');
$verMerito = escPodeReal('planejar');
$n0 = static fn($x): string => str_replace('-', '−', num((float) $x, 0));
$tierTxt = implode(', ', array_map(static fn($k, $v) => $k . ' ' . num($v, 0), array_keys($pts['tier']), $pts['tier']));

// Notas de hoje (agregado; serve para a distribuição por faixa).
$merito = escMeritoCarregar();
$contaFaixa = ['ACIMA' => 0, 'MEDIA' => 0, 'ABAIXO' => 0];
foreach ($merito as $m) {
    if ($m['nota'] !== null) {
        $contaFaixa[$m['faixa'] ?? escMeritoFaixa((float) $m['nota'], $pts)]++;
    }
}

// Exemplos: soma dos pontos de SRs típicos.
$exemplos = [
    [$L('Página B, bate a meta há 2 semanas, sem falta', 'Tier B page, on target for 2 weeks, no absences'), ['tier' => 5, 'meta' => 1, 'faltas' => 0, 'sla' => 0, 'casa' => 1, 'vendas' => 3, 'cargo' => 1, 'infracao' => 0, 'seq' => 3]],
    [$L('Página D, 85% da meta, 2 faltas, SLA 75%', 'Tier D page, 85% of target, 2 absences, SLA 75%'), ['tier' => 2, 'meta' => -1, 'faltas' => -2, 'sla' => -1, 'casa' => 0, 'vendas' => 1, 'cargo' => 1, 'infracao' => 0, 'seq' => 0]],
    [$L('Página A, 120% da meta há 3 semanas, 5 faltas', 'Tier A page, 120% of target for 3 weeks, 5 absences'), ['tier' => 8, 'meta' => 2, 'faltas' => -16, 'sla' => 0, 'casa' => 2, 'vendas' => 4, 'cargo' => 3, 'infracao' => 0, 'seq' => 5]],
    [$L('Novato, página E, sem dados de meta', 'New hire, tier E page, no target data'), ['tier' => 1, 'meta' => 0, 'faltas' => 0, 'sla' => 0, 'casa' => 0, 'vendas' => 0, 'cargo' => 0, 'infracao' => 0, 'seq' => 0]],
];

// Resumo ao vivo por unidade (só Planejamento e Admin).
$porUt = [];
$porUnidade = [];
if ($verMerito) {
    $hoje = date('Y-m-d');
    $dia = max($hoje, escV4DiaEstrutura());
    foreach (escQ('SELECT UPPER(c.rep_code) AS rep, n.unidade, n.turno FROM ' . escT('esc_colaborador_config') . ' c JOIN ' . escT('esc_nucleo') . " n ON n.id = c.nucleo_id
                    WHERE c.funcao = 'EQUITE' AND c.vigente_desde <= ? AND (c.vigente_ate IS NULL OR c.vigente_ate >= ?)", [$dia, $dia]) as $s) {
        $k = (int) $s['unidade'] . '|' . $s['turno'];
        $porUt[$k] = $porUt[$k] ?? ['u' => (int) $s['unidade'], 't' => $s['turno'], 'srs' => 0, 'notas' => [], 'acima' => 0, 'abaixo' => 0];
        $porUt[$k]['srs']++;
        $m = $merito[$s['rep']] ?? null;
        if ($m && $m['nota'] !== null) {
            $porUt[$k]['notas'][] = (float) $m['nota'];
            $fx = $m['faixa'] ?? escMeritoFaixa((float) $m['nota'], $pts);
            $porUt[$k]['acima'] += $fx === 'ACIMA' ? 1 : 0;
            $porUt[$k]['abaixo'] += $fx === 'ABAIXO' ? 1 : 0;
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
$faixaDe = static function (float $nota) use ($pts, $L): array {
    $fx = escMeritoFaixa($nota, $pts);
    return $fx === 'ACIMA' ? [$L('Acima', 'Above'), 'verde'] : ($fx === 'ABAIXO' ? [$L('Abaixo', 'Below'), 'laranja'] : [$L('Na média', 'Average'), '']);
};
$sinal = static fn($v): string => (int) $v > 0 ? '+' . (int) $v : ((int) $v < 0 ? '−' . abs((int) $v) : '0');
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
    <li><a href="#faixas"><?php echo h($L('Faixas e exemplos', 'Bands and examples')); ?></a></li>
    <li><a href="#simulador"><?php echo h($L('Simulador', 'Simulator')); ?></a></li>
    <li><a href="#unidade"><?php echo h($L('Mérito da unidade', 'Unit merit')); ?></a></li>
    <li><a href="#uso"><?php echo h($L('Onde o mérito pesa', 'Where merit counts')); ?></a></li>
  </ol></nav>

  <section class="regras-secao" id="sr">
    <h2>1. <?php echo h($L('Mérito do SR', 'SR merit')); ?></h2>
    <p class="merito-formula"><strong><?php echo h($L('Nota = soma dos pontos de cada componente', 'Score = sum of the points of each component')); ?></strong></p>
    <p class="muted small"><?php echo h($L('Últimos ' . $janela . ' dias até ontem. Sem dado num componente (novato, sem meta ou sem venda), ele vale 0. Com a mesma nota, vem antes quem tem mais tempo de casa.', 'Last ' . $janela . ' days up to yesterday. With no data for a component (new hire, no target or no sales), it is 0. With the same score, whoever has been with the company longer comes first.')); ?></p>
    <div class="tabela-rolagem"><table>
      <thead><tr><th><?php echo h($L('Componente', 'Component')); ?></th><th><?php echo h($L('Pontos', 'Points')); ?></th><th><?php echo h($L('Fonte', 'Source')); ?></th></tr></thead>
      <tbody>
        <tr><td><strong><?php echo h($L('Tier da página', 'Page tier')); ?></strong></td><td><?php echo h($L('Tier da página em que o SR mais esteve, como regular ou assistente, pelo faturamento dela na janela: ' . $tierTxt . '.', 'Tier of the page where the SR spent the most hours, as regular or assistant, by its revenue in the window: ' . $tierTxt . '.')); ?></td><td class="small">5C Schedule · statement · tiers</td></tr>
        <tr><td><strong><?php echo h($L('Meta', 'Target')); ?></strong></td><td><?php echo h($L('5CReps realizado ÷ meta, rateado pelas mensagens do SR em cada página, dia e turno. 100% = 0; cada ' . $pc($pts['meta_passo']) . ' acima soma 1 e abaixo tira 1, cortado em direção ao zero (95% = 0, 89% = −1). Limite de ±' . $pts['meta_limite'] . '.', '5CReps actual ÷ target, split by the SR\'s messages on each page, day and shift. 100% = 0; each ' . $pc($pts['meta_passo']) . ' above adds 1 and below takes 1, rounded towards zero (95% = 0, 89% = −1). Limit ±' . $pts['meta_limite'] . '.')); ?></td><td class="small">Intraday · cap_rep_hora</td></tr>
        <tr><td><strong><?php echo h($L('Faltas', 'Absences')); ?></strong></td><td><?php echo h($L('Cada dia com falta pesa o dobro do anterior: 1 = −1, 2 = −2, 3 = −4, 4 = −8, ' . $pts['falta_max_x'] . ' ou mais = −' . (2 ** ($pts['falta_max_x'] - 1)) . '. A falha administrativa (escala dobrada ou fora do horário) e quem estava presente não contam.', 'Each day absent weighs double the previous one: 1 = −1, 2 = −2, 3 = −4, 4 = −8, ' . $pts['falta_max_x'] . ' or more = −' . (2 ** ($pts['falta_max_x'] - 1)) . '. Administrative failure (double scheduling or outside the shift) and being present do not count.')); ?></td><td class="small">abs_adh_af</td></tr>
        <tr><td><strong>SLA</strong></td><td><?php echo h($L('Respostas em até 5 minutos ÷ respostas. ' . $pc($pts['sla_meta']) . ' ou mais = 0; a cada ' . $pc($pts['sla_faixa']) . ' abaixo a pena dobra: −1, −2, −4, −8, −16. SLA alto não ganha ponto.', 'Replies within 5 minutes ÷ replies. ' . $pc($pts['sla_meta']) . ' or more = 0; every ' . $pc($pts['sla_faixa']) . ' below doubles the penalty: −1, −2, −4, −8, −16. A high SLA earns no points.')); ?></td><td class="small">cap_rep_hora</td></tr>
        <tr><td><strong><?php echo h($L('Tempo de casa', 'Tenure')); ?></strong></td><td><?php echo h($L($n0($pts['ano']) . ' ponto por ano completo de empresa, sem teto. Também é o desempate.', $n0($pts['ano']) . ' point per full year at the company, no cap. It is also the tie-break.')); ?></td><td class="small">headcount</td></tr>
        <tr><td><strong><?php echo h($L('Vendas', 'Sales')); ?></strong></td><td><?php echo h($L('Venda por hora (US$/h), por faixa entre os SRs com dado: de 0 (os 20% que menos vendem) a ' . ($pts['vendas_faixas'] - 1) . ' (os 20% que mais vendem).', 'Sales per hour (US$/h), by band among SRs with data: from 0 (the 20% who sell least) to ' . ($pts['vendas_faixas'] - 1) . ' (the 20% who sell most).')); ?></td><td class="small">cap_rep_hora</td></tr>
        <tr><td><strong><?php echo h($L('Cargo na estrutura antiga', 'Role in the old structure')); ?></strong></td><td><?php echo h($L('O maior cargo que o SR já teve até 31/10: SR do pool 0, segundo da party 1, líder de party 3, líder que era gerente do time 5.', 'The highest role the SR ever had up to 31/10: pool SR 0, party second 1, party leader 3, leader who was team manager 5.')); ?></td><td class="small"><?php echo h($L('histórico do Planner', 'Planner history')); ?></td></tr>
        <tr><td><strong><?php echo h($L('Sequência batendo a meta', 'Target streak')); ?></strong></td><td><?php echo h($L('Semanas fechadas (segunda a domingo) seguidas com 100% ou mais da meta, contadas da última para trás, entre as últimas ' . $pts['seq_semanas'] . ': ' . implode(', ', array_map(static fn($k, $v) => $k . ' = +' . num($v, 0), array_keys($pts['seq']), $pts['seq'])) . ' (o último vale "ou mais"). Semana sem dado do SR é pulada; uma abaixo da meta encerra a contagem. É a única parte que olha semanas, e não os ' . $janela . ' dias.', 'Closed weeks (Monday to Sunday) in a row at 100% or more of target, counted back from the last one, among the last ' . $pts['seq_semanas'] . ': ' . implode(', ', array_map(static fn($k, $v) => $k . ' = +' . num($v, 0), array_keys($pts['seq']), $pts['seq'])) . ' (the last one means "or more"). A week with no data for the SR is skipped; one below target ends the count. It is the only part that looks at weeks instead of the ' . $janela . ' days.')); ?></td><td class="small">Intraday · cap_rep_hora</td></tr>
        <tr><td><strong><?php echo h($L('Infrações', 'Infractions')); ?></strong></td><td><?php echo h($L('−' . $n0($pts['infracao']) . ' por infração dos últimos ' . $pts['infracao_dias'] . ' dias, sem limite. O cadastro ainda está vazio: vale 0 para todos até a carga.', '−' . $n0($pts['infracao']) . ' per infraction in the last ' . $pts['infracao_dias'] . ' days, no limit. The register is still empty: it is 0 for everyone until it is loaded.')); ?></td><td class="small">esc_sr_infraction</td></tr>
      </tbody>
    </table></div>
  </section>

  <section class="regras-secao" id="faixas">
    <h2>2. <?php echo h($L('Faixas e exemplos', 'Bands and examples')); ?></h2>
    <ul>
      <li><strong><?php echo h($L('Acima', 'Above')); ?>:</strong> <?php echo h($L($n0($pts['acima']) . ' pontos ou mais.', $n0($pts['acima']) . ' points or more.')); ?></li>
      <li><strong><?php echo h($L('Na média', 'Average')); ?>:</strong> <?php echo h($L('de ' . $n0($pts['abaixo']) . ' a ' . $n0($pts['acima'] - 1) . ' pontos.', 'from ' . $n0($pts['abaixo']) . ' to ' . $n0($pts['acima'] - 1) . ' points.')); ?></li>
      <li><strong><?php echo h($L('Abaixo', 'Below')); ?>:</strong> <?php echo h($L('menos de ' . $n0($pts['abaixo']) . ' pontos.', 'under ' . $n0($pts['abaixo']) . ' points.')); ?></li>
    </ul>
    <?php if ($verMerito && array_sum($contaFaixa)): ?><p class="muted small"><?php echo h($L('Hoje: ' . $contaFaixa['ACIMA'] . ' Acima, ' . $contaFaixa['MEDIA'] . ' Na média e ' . $contaFaixa['ABAIXO'] . ' Abaixo.', 'Today: ' . $contaFaixa['ACIMA'] . ' Above, ' . $contaFaixa['MEDIA'] . ' Average and ' . $contaFaixa['ABAIXO'] . ' Below.')); ?></p><?php endif; ?>
    <div class="tabela-rolagem"><table>
      <thead><tr><th><?php echo h($L('Exemplo', 'Example')); ?></th><th class="num"><?php echo h($L('Tier', 'Tier')); ?></th><th class="num"><?php echo h($L('Meta', 'Target')); ?></th><th class="num"><?php echo h($L('Faltas', 'Absences')); ?></th><th class="num">SLA</th><th class="num"><?php echo h($L('Casa', 'Tenure')); ?></th><th class="num"><?php echo h($L('Vendas', 'Sales')); ?></th><th class="num"><?php echo h($L('Cargo', 'Role')); ?></th><th class="num"><?php echo h($L('Sequência', 'Streak')); ?></th><th class="num"><?php echo h($L('Nota', 'Score')); ?></th><th><?php echo h($L('Faixa', 'Band')); ?></th></tr></thead>
      <tbody>
      <?php foreach ($exemplos as [$rot, $c]): $tot = array_sum($c); [$fx, $fc] = $faixaDe((float) $tot); ?>
        <tr><td><?php echo h($rot); ?></td><?php foreach (['tier', 'meta', 'faltas', 'sla', 'casa', 'vendas', 'cargo', 'seq'] as $k): ?><td class="num"><?php echo h($sinal($c[$k])); ?></td><?php endforeach; ?><td class="num"><strong><?php echo h($sinal($tot)); ?></strong></td><td><span class="tag <?php echo $fc; ?>"><?php echo h($fx); ?></span></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>

  <section class="regras-secao nao-imprimir" id="simulador">
    <h2>3. <?php echo h($L('Simulador', 'Simulator')); ?></h2>
    <p class="muted small"><?php echo h($L('Mude os números e veja os pontos e a faixa.', 'Change the numbers and see the points and the band.')); ?></p>
    <form class="merito-sim" data-merito-sim data-pts="<?php echo h((string) json_encode($pts)); ?>" onsubmit="return false">
      <label><?php echo h($L('Tier da página', 'Page tier')); ?><select name="tier"><?php foreach ($pts['tier'] as $t => $v): ?><option value="<?php echo h((string) $v); ?>" <?php echo $t === 'C' ? 'selected' : ''; ?>><?php echo h($t); ?></option><?php endforeach; ?></select></label>
      <label><?php echo h($L('Meta %', 'Target %')); ?><input type="number" name="meta" value="100" min="0" max="400" step="1" inputmode="decimal"></label>
      <label><?php echo h($L('Dias com falta', 'Days absent')); ?><input type="number" name="faltas" value="0" min="0" max="28" step="1" inputmode="numeric"></label>
      <label><?php echo h($L('SLA 5 min %', 'SLA 5 min %')); ?><input type="number" name="sla" value="85" min="0" max="100" step="1" inputmode="decimal"></label>
      <label><?php echo h($L('Anos de casa', 'Years at the company')); ?><input type="number" name="anos" value="0" min="0" max="40" step="1" inputmode="numeric"></label>
      <label><?php echo h($L('Faixa de vendas (0 a ' . ($pts['vendas_faixas'] - 1) . ')', 'Sales band (0 to ' . ($pts['vendas_faixas'] - 1) . ')')); ?><input type="number" name="vendas" value="2" min="0" max="<?php echo $pts['vendas_faixas'] - 1; ?>" step="1" inputmode="numeric"></label>
      <label><?php echo h($L('Cargo antigo', 'Old role')); ?><select name="cargo"><option value="0"><?php echo h($L('SR do pool', 'Pool SR')); ?></option><option value="1" selected><?php echo h($L('segundo da party', 'party second')); ?></option><option value="3"><?php echo h($L('líder de party', 'party leader')); ?></option><option value="5"><?php echo h($L('líder e gerente do time', 'leader and team manager')); ?></option></select></label>
      <label><?php echo h($L('Semanas seguidas na meta', 'Weeks in a row on target')); ?><input type="number" name="seq" value="0" min="0" max="<?php echo (int) $pts['seq_semanas']; ?>" step="1" inputmode="numeric"></label>
      <label><?php echo h($L('Infrações (90 dias)', 'Infractions (90 days)')); ?><input type="number" name="infracoes" value="0" min="0" max="20" step="1" inputmode="numeric"></label>
      <output class="merito-sim-saida" aria-live="polite">
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
      <li><strong><?php echo h($L('Mérito da equipe', 'Team merit')); ?>:</strong> <?php echo h($L('a nota média dos SRs do núcleo (em pontos, seção 1). É o que o Funil usa para indicar onde entra uma página nova.', 'the average score of the core\'s SRs (in points, section 1). It is what the Funnel uses to suggest where a new page goes.')); ?></li>
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
      <thead><tr><th><?php echo h($L('Unidade · turno', 'Unit · shift')); ?></th><th class="num">SRs</th><th class="num"><?php echo h($L('Com nota', 'With score')); ?></th><th class="num"><?php echo h($L('Nota mediana', 'Median score')); ?></th><th class="num"><?php echo h($L('Acima', 'Above')); ?></th><th class="num"><?php echo h($L('Abaixo', 'Below')); ?></th></tr></thead>
      <tbody>
      <?php foreach ($porUt as $l): $mn = $medianaDe($l['notas']); ?>
        <tr><td>U<?php echo $l['u']; ?> <?php echo chipTurno((string) $l['t']); ?></td><td class="num"><?php echo $l['srs']; ?></td><td class="num"><?php echo count($l['notas']); ?></td><td class="num"><strong><?php echo $mn !== null ? h($sinal($mn)) : '—'; ?></strong></td>
          <td class="num <?php echo $l['acima'] ? 'verde' : 'muted'; ?>"><?php echo $l['acima']; ?></td><td class="num <?php echo $l['abaixo'] ? 'laranja' : 'muted'; ?>"><?php echo $l['abaixo']; ?></td></tr>
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
      <li><strong><?php echo h($L('Escala (Gerar)', 'Schedule (Generate)')); ?>:</strong> <?php echo h($L('cada página, da que mais fatura para a que menos fatura, escolhe o melhor SR disponível na unidade e no turno: primeiro quem bateu ' . $pc((float) str_replace(',', '.', escV4Param('v4_melhores_meta_min', '0.65'))) . ' ou mais da meta na página nos últimos 30 dias, depois a faixa (Acima, Na média, Abaixo), a nota e o tempo de casa. A equipe fixa da página vem antes. Quem estava no posto fica, a não ser que apareça um SR de faixa acima ou que ele esteja abaixo da meta mínima na página.', 'each page, from the highest-revenue to the lowest, picks the best SR available in the unit and shift: first whoever hit ' . $pc((float) str_replace(',', '.', escV4Param('v4_melhores_meta_min', '0.65'))) . ' or more of the page target in the last 30 days, then the band (Above, Average, Below), the score and tenure. The page\'s fixed team comes first. Whoever was on the post stays, unless an SR from a higher band shows up or they are below the minimum target on the page.')); ?></li>
      <li><strong><?php echo h($L('Página prioritária', 'Priority page')); ?>:</strong> <?php echo h($L('o SR Abaixo só fica como regular numa página de modelo com US$ ' . num((float) str_replace(',', '.', escV4Param('v4_pagina_prioritaria_min', '100000')), 0) . ' ou mais em 30 dias quando não há outro disponível (a célula fica sinalizada) ou quando bateu a meta nela.', 'a Below SR only stays as regular on a page of a model with US$ ' . num((float) str_replace(',', '.', escV4Param('v4_pagina_prioritaria_min', '100000')), 0) . ' or more in 30 days when no one else is available (the cell is flagged) or when they hit the target there.')); ?></li>
      <li><strong><?php echo h($L('Lacunas', 'Gaps')); ?>:</strong> <?php echo h($L('no remanejamento para uma página maior sobe o SR de melhor faixa; no acúmulo de posto e na sobrecarga, a faixa é o primeiro critério e o SR Abaixo vai por último. Nada é proibido: só muda a ordem.', 'when moving someone up to a bigger page, the SR with the best band goes; when stacking a post or covering above capacity, the band comes first and the Below SR goes last. Nothing is forbidden: only the order changes.')); ?></li>
      <li><strong><?php echo h($L('Owner', 'Owner')); ?>:</strong> <?php echo h($L('na troca de owner entra o SR de nota mais alta do núcleo dono, respeitando as prioridades fixas.', 'on an owner change, the core\'s highest-scoring SR comes in, respecting fixed priorities.')); ?></li>
      <li><strong><?php echo h($L('Página nova', 'New page')); ?>:</strong> <?php echo h($L('o mérito da equipe vale 25 de 100 pontos no Funil (35 em página de tier alto, 15 em tier baixo).', 'team merit is worth 25 of 100 points in the Funnel (35 for high-tier pages, 15 for low-tier).')); ?></li>
      <li><strong><?php echo h($L('Equilíbrio', 'Balance')); ?>:</strong> <?php echo h($L('na divisão dos OS, depois do nível, vem a nota.', 'in the supervisor split, after the level, comes the score.')); ?></li>
    </ul>
    <p class="muted small"><?php echo h($L('Os números de cada pessoa ficam só para Planejamento e Admin. Os parâmetros ficam em Parâmetros: grupo "merito" (prefixo merito_pts_) para a nota e grupo "v4" (v4_merito_*) para o Gerar.', 'Each person\'s numbers are shown only to Planning and Admin. The parameters are in Parameters: group "merito" (prefix merito_pts_) for the score and group "v4" (v4_merito_*) for Generate.')); ?></p>
  </section>
</section>
<script>
(function () {
  var f = document.querySelector('[data-merito-sim]');
  if (!f) { return; }
  var p = JSON.parse(f.dataset.pts || '{}');
  var en = <?php echo $en ? 'true' : 'false'; ?>;
  var rot = en ? { acima: 'Above', media: 'Average', abaixo: 'Below' } : { acima: 'Acima', media: 'Na média', abaixo: 'Abaixo' };
  var fmt = function (x) { return (x > 0 ? '+' : (x < 0 ? '−' : '')) + Math.abs(x); };
  function trunca(x) { x = Math.round(x * 1e6) / 1e6; return x < 0 ? Math.ceil(x) : Math.floor(x); }
  function calc() {
    var v = function (n) { var x = parseFloat(String(f.elements[n].value).replace(',', '.')); return isNaN(x) ? 0 : x; };
    var meta = Math.max(-p.meta_limite, Math.min(p.meta_limite, trunca((v('meta') / 100 - 1) / p.meta_passo)));
    var x = Math.min(Math.max(0, Math.floor(v('faltas'))), p.falta_max_x), faltas = x > 0 ? -Math.pow(2, x - 1) : 0;
    var s = v('sla') / 100, sla = 0;
    if (s < p.sla_meta) { var k = Math.max(1, Math.min(p.sla_max_k, Math.ceil(Math.round((p.sla_meta - s) / p.sla_faixa * 1e6) / 1e6))); sla = -Math.pow(2, k - 1); }
    var sem = Math.max(0, Math.floor(v('seq'))), degraus = Object.keys(p.seq || {}).map(Number).sort(function (a, b) { return a - b; }), seq = 0;
    degraus.forEach(function (d) { if (sem >= d) { seq = Math.round(p.seq[d]); } });
    var partes = [v('tier'), meta, faltas, sla, Math.floor(v('anos') * p.ano), Math.min(p.vendas_faixas - 1, Math.max(0, Math.floor(v('vendas')))), v('cargo'), -Math.round(Math.floor(v('infracoes')) * p.infracao), seq];
    var nota = partes.reduce(function (a, b) { return a + b; }, 0);
    f.querySelector('[data-sim="nota"]').textContent = fmt(nota);
    var tag = f.querySelector('[data-sim="faixa"]');
    var fx = nota >= p.acima ? 'acima' : (nota < p.abaixo ? 'abaixo' : 'media');
    tag.textContent = rot[fx];
    tag.className = 'tag ' + (fx === 'acima' ? 'verde' : (fx === 'abaixo' ? 'laranja' : ''));
    f.querySelector('[data-sim="obs"]').textContent = (en ? 'tier ' : 'tier ') + fmt(partes[0]) + ' · ' + (en ? 'target ' : 'meta ') + fmt(meta) + ' · ' + (en ? 'absences ' : 'faltas ') + fmt(faltas) + ' · SLA ' + fmt(sla);
  }
  f.addEventListener('input', calc);
  f.addEventListener('change', calc);
  calc();
})();
</script>
