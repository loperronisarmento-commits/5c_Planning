<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/regras.php
// Regras do 5C Escalas para ver e imprimir: estrutura, alocação, liderança, pedidos, páginas,
// pessoas, mérito e a política de páginas por mérito. Os números vêm dos Parâmetros (o que vale
// agora). O texto fica em PT e EN lado a lado (como o Glossário); impresso no papel timbrado.
// ===============================================

$par = [];
foreach (escQ('SELECT chave, valor FROM ' . escT('esc_parametro')) as $l) {
    $par[$l['chave']] = (string) $l['valor'];
}
$v = static fn(string $k, string $padrao) => $par[$k] ?? $padrao;
$pol = escPolParam();
$pct = static fn($x) => num((float) $x * 100, 0) . '%';
$prazos = [];
foreach ([0.50, 0.60, 0.70, 0.80] as $a) {
    $prazos[] = $pct($a) . ' → ' . escPolPrazo($a, $pol) . 'd';
}
$prio = [];
if (escPolInstalada()) {
    $nomes = escNomes();
    foreach (escQ('SELECT pagina, rep_codes, motivo FROM ' . escT('esc_pagina_prioridade') . ' WHERE vigente_ate IS NULL ORDER BY pagina') as $l) {
        $prio[] = $l['pagina'] . ': ' . implode(', ', array_map(static fn($r) => nomeCurto($nomes[trim($r)] ?? trim($r)), explode(',', strtoupper((string) $l['rep_codes'])))) . ($l['motivo'] ? ' (' . $l['motivo'] . ')' : '');
    }
}
$x = [
    '{virada}' => dataBr(substr($v('v4_virada', '2026-10-31 21:00'), 0, 10)) . ' ' . substr($v('v4_virada', '2026-10-31 21:00'), 11, 5),
    '{h12}' => $v('v4_horas_seguidas_max', '12'), '{h52}' => $v('v4_horas_semana_max', '52'), '{fator}' => str_replace('.', ',', $v('v4_fator_pessoas_posto', '1.5')),
    '{limite}' => $v('limite_fans_rep', '35'), '{over}' => $pct($v('v4_over_capacity_max', '0.15')), '{antec}' => $v('indisp_antecedencia_h', '72'),
    '{meses}' => $v('indisp_recorrente_max_meses', '3'), '{janela_m}' => $v('merito_janela_dias', '28'), '{meia}' => $v('merito_infracao_meia_vida_dias', '90'),
    '{piso}' => $pct($pol['pol_piso']), '{corte}' => $pct($pol['pol_corte_acomp']), '{pmin}' => (int) $pol['pol_prazo_min'], '{pmax}' => (int) $pol['pol_prazo_max'],
    '{gamma}' => str_replace('.', ',', (string) $pol['pol_gamma']), '{acomp}' => (int) $pol['pol_prazo_acomp'], '{dest}' => $pct($pol['pol_destaque']), '{destd}' => (int) $pol['pol_destaque_dias'],
    '{car}' => (int) $pol['pol_carencia'], '{omin}' => (int) $pol['pol_owner_min'], '{maxs}' => (int) $pol['pol_max_trocas_semana'], '{jan}' => (int) $pol['pol_janela'], '{dmin}' => (int) $pol['pol_dias_minimos'],
    '{abs_tol}' => $pct(escMeritoAbsParam()['tolerancia']), '{abs_crit}' => $pct(escMeritoAbsParam()['critico']), '{abs_teto}' => $pct(escMeritoAbsParam()['teto_critico']),
    '{trn_min}' => $pct($v('treinamento_ating_min', '0.85')),
    '{prazos}' => implode(' · ', $prazos), '{prio}' => $prio ? implode('; ', $prio) : '—',
];
$en = escEmIngles();
$R = [
    'estrutura' => ['Estrutura da operação', 'Operation structure', [
        ['3 unidades (U1, U2, U3). Cada unidade tem os 3 turnos: T6/T1 (21:00–05:00), T2/T3 (05:00–13:00) e T4/T5 (13:00–21:00).', '3 units (U1, U2, U3). Each unit has the 3 shifts: T6/T1 (21:00–05:00), T2/T3 (05:00–13:00) and T4/T5 (13:00–21:00).'],
        ['Cada unidade e turno tem 3 núcleos, cada um com um Operational Supervisor (OS) e os seus SRs. Rotação 5x2 de 21 dias: todo dia 2 dos 3 núcleos trabalham.', 'Each unit and shift has 3 cores, each with an Operational Supervisor (OS) and its SRs. 21-day 5x2 rotation: every day 2 of the 3 cores work.'],
        ['Cargos: Operational Manager (OM, por unidade), Operational Coordinator (OC, por unidade e turno), Operational Supervisor (OS, por núcleo), Operational Assistant (OA, assistentes dos OMs, semana fixa) e Sales Representative (SR, níveis 0 a 5).', 'Roles: Operational Manager (OM, per unit), Operational Coordinator (OC, per unit and shift), Operational Supervisor (OS, per core), Operational Assistant (OA, assistants to the OMs, fixed week) and Sales Representative (SR, levels 0 to 5).'],
        ['A estrutura nova vale a partir de {virada}. O roster da liderança muda em Pessoas › Roster da liderança, sempre a partir de uma data.', 'The new structure applies from {virada}. The leadership roster changes in People › Leadership roster, always from a date.'],
    ]],
    'alocacao' => ['Alocação: como a escala é montada', 'Allocation: how the schedule is built', [
        ['As páginas ficam na carteira de uma unidade (Capacity). Cada página tem posto(s) por turno, com {limite} fãs/h por posto; o Capacity calcula {fator} SRs por posto.', 'Pages sit in a unit portfolio (Capacity). Each page has post(s) per shift, with {limite} fans/h per post; Capacity counts {fator} SRs per post.'],
        ['Cada posto tem um núcleo dono (vaga de dono: os owners) e um núcleo de cobertura (vaga de cobertura: os backups), que cobre nos dias de folga do dono.', 'Each post has an owning core (owner slot: the owners) and a covering core (cover slot: the backups), which covers on the owner\'s days off.'],
        ['Os postos seguem o Capacity de hoje: antes de gerar ele é recalculado, página ativa com movimento que faltava entra sozinha (arquétipo de página parecida) e página sem posto num turno vira apoio no posto da mesma modelo. OFTV sem faturamento tem a menor prioridade.', 'Posts follow today\'s Capacity: it is recalculated before generating, an active page with activity that was missing is added automatically (archetype of a similar page) and a page with no post on a shift becomes support in the same model\'s post. OFTV without revenue has the lowest priority.'],
        ['Nenhum posto fica sem equipe: antes de gerar, a vaga sem ninguém é completada com SRs do turno (núcleo da vaga, outro núcleo, nível 0–2 acima da fatia e, por último, o menos carregado).', 'No post is left without a team: before generating, an empty slot is filled with SRs from the shift (the slot\'s core, another core, levels 0–2 above their share and, last, the least loaded).'],
        ['O motor preenche cada vaga, por dia, nesta ordem: 1) a equipe fixa da vaga (preferência, não trava); 2) outra pessoa do mesmo núcleo, primeiro quem já atua na página; 3) buffer (pessoa de outro núcleo em serviço no turno); 4) o que sobra vira lacuna. Quem fica sem vaga no dia é o buffer de plantão.', 'The engine fills each slot, per day, in this order: 1) the slot\'s fixed team (a preference, not a lock); 2) someone else from the same core, first those who already work the page; 3) buffer (someone from another core on shift); 4) what is left is a gap. Whoever has no slot that day is the on-call buffer.'],
        ['Assistente (posto de assistente ou de apoio) é só para SR 0 a 2: SR 3 ou mais nunca entra nessas vagas, nem pela equipe fixa. Se não houver SR 0 a 2 no turno, o posto de assistente fica vazio.', 'Assistant (assistant or support post) is only for SR 0 to 2: SR 3 or above never takes these slots, not even through the fixed team. If there is no SR 0 to 2 on shift, the assistant post stays empty.'],
        ['Página de faturamento maior vazia: o SR da página menor do turno vai para ela, também nos dias de transição da virada (31/10).', 'A bigger-revenue page left empty: the SR of the smallest page of the shift moves to it, also on the switch-over transition days (10/31).'],
        ['Limites: até {h12} h seguidas e {h52} h por semana; um SR pode passar até {over} da capacidade do posto (marcado na grade).', 'Limits: up to {h12} h in a row and {h52} h per week; an SR may go up to {over} over the post capacity (flagged on the grid).'],
        ['Ajuste manual na Grade fica travado: o recálculo mantém. Publicar mostra o resumo antes; o que vale para postar no site da 5C é a Grade publicada.', 'Manual adjustments on the Grid stay locked: recalculation keeps them. Publishing shows a summary first; what gets posted on the 5C website is the published Grid.'],
    ]],
    'lideranca' => ['Liderança', 'Leadership', [
        ['OC e OS seguem o 5x2 de 21 dias (o OS segue o núcleo dele). OA têm semana fixa, com o turno de cada dia. OM não segue escala.', 'OCs and OSs follow the 21-day 5x2 (the OS follows their core). OAs have a fixed week, with the shift of each day. OMs do not follow a schedule.'],
        ['Na página 5C Schedule, o sufixo diz o que a pessoa faz: -2 buffer, -4 trainee (New Hire), -5 overlap, -6 OA, -7 OC, -8 OS.', 'On the 5C Schedule page, the suffix says what the person does: -2 buffer, -4 trainee (New Hire), -5 overlap, -6 OA, -7 OC, -8 OS.'],
        ['Troca de cargo (Roster da liderança): quem entra sai do núcleo de SR; quem sai fica com o lugar de quem entrou (núcleo e vagas fixas), vira SR no mesmo núcleo (OS) ou fica sem núcleo.', 'Role change (Leadership roster): the newcomer leaves their SR core; the person leaving takes the newcomer\'s place (core and fixed slots), becomes an SR in the same core (OS) or is left without a core.'],
    ]],
    'pedidos' => ['Pedidos das pessoas', 'People\'s requests', [
        ['Indisponibilidade: só o pedido aprovado vira folga, no próximo recálculo. Com menos de {antec} h de antecedência entra como urgente; recorrente vai até {meses} meses. Recusar pede comentário.', 'Unavailability: only an approved request becomes a day off, in the next recalculation. With less than {antec} h notice it is urgent; recurring requests go up to {meses} months. Declining needs a comment.'],
        ['Solicitações do SR (trabalhar como assistente ou em dupla, mudar de turno, núcleo ou unidade): o líder registra, o Planejamento aprova e aplica uma troca 1 por 1 com outro SR a partir de uma data. Ninguém fica sem vaga e nenhum posto fica sem dono; quem pediu o contrário aparece primeiro como par.', 'SR requests (work as an assistant or in a pair, change shift, core or unit): the leader records it, Planning approves it and applies a one-for-one swap with another SR from a date. No one is left without a slot and no post without an owner; those who asked for the opposite show up first as partners.'],
        ['Assistente e dupla dizem onde a pessoa fica fixa (núcleo: unidade, turno e OS) e, se souber, com quem (o titular que vai assistir ou o par). O par da troca vem desse lugar, primeiro quem já trabalha nas páginas dessa pessoa.', 'Assistant and pair requests say where the person is fixed (core: unit, shift and OS) and, if known, with whom (the owner to assist or the partner). The swap partner comes from that place, first whoever already works the pages of that person.'],
        ['Horas extras: o sistema confere {h12} h seguidas e {h52} h na semana antes de alocar.', 'Overtime: the system checks {h12} h in a row and {h52} h a week before allocating.'],
    ]],
    'paginas' => ['Páginas: entrada, saída e destino', 'Pages: entry, exit and destination', [
        ['Página nova (Funil): o sistema indica 3 destinos, um por unidade, por uma nota de 0 a 100: Capacity da unidade e do turno depois de entrar a página (35), mérito da equipe (25; 35 em tier alto, 15 em tier baixo), carga do núcleo (20), afinidade com a modelo (20) e, com esses a 80%, equilíbrio das unidades (20: faturamento de 30 dias abaixo da média no turno principal ganha).', 'New page (Funnel): the system suggests 3 destinations, one per unit, by a 0–100 score: unit and shift Capacity after the page joins (35), team merit (25; 35 for high tiers, 15 for low tiers), core load (20), model affinity (20) and, with those at 80%, unit balance (20: 30-day revenue below average on the main shift wins).'],
        ['Equilíbrio das unidades: o mesmo faturamento total de 30 dias em cada unidade e em cada coordenador (unidade × turno); o faturamento por SR fica para acompanhar. A troca de unidade leva todas as páginas da mesma modelo e só vale depois de 28 dias na unidade; o planejador decide cada troca. Divisão dos OS pelo faturamento de 30 dias dos postos: OS1 com as maiores páginas, OS3 com as menores e OS2 misto (metade de cima OS1, OS1, OS2; metade de baixo OS2, OS3, OS3); o OS2 cobre o OS1 e o OS3, e nos postos do OS2 os grandes ficam com o OS1 e os pequenos com o OS3; os melhores SRs vão para o OS1 e, no OS2, quem conhece páginas grandes e pequenas.', 'Unit balance: the same total 30-day revenue in each unit and under each coordinator (unit × shift); revenue per SR is shown for follow-up. A unit move takes all pages of the same model and is only allowed after 28 days in the unit; the planner decides each move. OS split by the posts\' 30-day revenue: OS1 gets the biggest pages, OS3 the smallest and OS2 a mix (top half OS1, OS1, OS2; bottom half OS2, OS3, OS3); OS2 covers OS1 and OS3, and on OS2 posts the big ones are covered by OS1 and the small ones by OS3; the best SRs go to OS1 and OS2 gets those who know both big and small pages.'],
        ['Página que entra no lugar de uma que saiu (na prévia da saída): entra na mesma carteira e nos mesmos núcleos; quem perdeu a vaga fixa passa para ela a partir da data de entrada. Nos dias até lá, quem fica sem página vira buffer (-2) se o Capacity diz que falta gente no turno da unidade; se não falta, sai da escala nesses dias (dá para trocar pessoa a pessoa).', 'Page coming in instead of one that left (in the exit preview): it joins the same portfolio and cores; whoever lost their fixed slot moves to it from the entry date. On the days until then, whoever is left without a page becomes buffer (-2) if Capacity says the unit\'s shift is short of people; if not, they leave the schedule on those days (can be changed person by person).'],
        ['Saída de página: prévia antes; aplicar fecha a página no Capacity, recalcula, fecha ou refaz os postos e passa quem ficou sem vaga para uma vaga sem ninguém do núcleo. Página que o Capacity já desativou só ajusta a escala.', 'Page exit: preview first; applying closes the page in Capacity, recalculates, closes or rebuilds posts and moves whoever lost a slot to an empty slot in the core. A page Capacity already deactivated only adjusts the schedule.'],
    ]],
    'merito' => ['Mérito dos SRs', 'SR merit', [
        ['Nota = Desempenho × SLA × Presença ÷ (1 + pena). Desempenho é o realizado ÷ meta 5CReps das páginas, rateado pelas mensagens do SR; SLA é a parte das respostas em até 5 min; janela de {janela_m} dias.', 'Score = Performance × SLA × Attendance ÷ (1 + penalty). Performance is 5CReps actual ÷ target of the pages, split by the SR\'s messages; SLA is the share of replies within 5 min; {janela_m}-day window.'],
        !escMeritoAbsParam()['ativo'] ? ['Absenteísmo = blocos de falta (ABS) ÷ blocos escalados, a mesma classificação do Capacity. Hoje ele está fora da nota do mérito (Parâmetros › merito_abs_ativo = 0): aparece só como informação. Detalhe em Ajuda › Mérito.', 'Absenteeism = absence blocks (ABS) ÷ scheduled blocks, the same classification as Capacity. It is currently left out of the merit score (Parameters › merito_abs_ativo = 0): shown for information only. Details in Help › Merit.'] :
        ['Absenteísmo = blocos de falta (ABS) ÷ blocos escalados, a mesma classificação do Capacity. Presença = 1 − absenteísmo até {abs_tol}; acima disso cai rápido; com {abs_crit} ou mais a nota fica no máximo em {abs_teto} da mediana: o SR nunca fica na média nem acima. Detalhe e simulador em Ajuda › Mérito.', 'Absenteeism = absence blocks (ABS) ÷ scheduled blocks, the same classification as Capacity. Attendance = 1 − absenteeism up to {abs_tol}; above that it drops fast; at {abs_crit} or more the score is capped at {abs_teto} of the median: the SR is never average or above. Details and a simulator in Help › Merit.'],
        ['A infração pesa menos a cada dia sem outra (meia-vida de {meia} dias). O mérito em número aparece só para Planejamento, WFM e Admin.', 'An infraction weighs less each day without another one ({meia}-day half-life). Merit numbers are shown only to Planning, WFM and Admin.'],
    ]],
    'treinamento' => ['Treinamento de New Hires', 'New Hire training', [
        ['O e-mail do Treinamento é colado em Pessoas › Treinamento: o sistema lê turmas, horário, datas, nesting, páginas pedidas e trainees, e avisa o que não bate (turno × horário, datas, lista de contratados).', 'The Training e-mail is pasted in People › Training: the system reads classes, hours, dates, nesting, requested pages and trainees, and flags what does not match (shift × hours, dates, hire list).'],
        ['Páginas emprestadas: a menor de um posto com 2 ou mais páginas, as mesmas no ciclo de 2 semanas, nunca OFTV, sem mês abaixo de {trn_min}, sem prioridade fixa e sem repetir entre turnos ao mesmo tempo. Até 4 trainees por página, de 5 a 8 em 2, 9 ou mais em 3. O dono do turno continua dono e com a comissão; nas pausas do treinamento ele assume.', 'Lent pages: the smallest page of a post with 2 or more pages, the same for the 2-week cycle, never OFTV, no month below {trn_min}, no fixed priority and no repeats across shifts at the same time. Up to 4 trainees per page, 5 to 8 in 2, 9 or more in 3. The shift owner stays the owner and keeps the commission; during training breaks they take over.'],
        ['Turma confirmada: os trainees vão para a página 5C Schedule com -4, de segunda a sexta, no horário da turma. Até o dia disponível para a operação ficam fora da escala normal, mesmo com cadastro.', 'Confirmed class: trainees go on the 5C Schedule page with -4, Monday to Friday, in the class hours. Until the day available for operation they stay out of the regular schedule, even with a record.'],
    ]],
    'politica' => ['Política de páginas por mérito', 'Merit-based page policy', [
        ['Atingimento = 5CReps realizado ÷ meta 5CReps (Intraday). Medido para a página na unidade (todos os turnos, desde que entrou na unidade) e para a página em cada turno (desde que o owner atual assumiu). Janela de {jan} dias; são precisos {dmin} dias com dados.', 'Attainment = 5CReps actual ÷ 5CReps target (Intraday). Measured for the page in the unit (all shifts, since it joined the unit) and for the page in each shift (since the current owner took over). {jan}-day window; {dmin} days of data are needed.'],
        ['Abaixo de {corte} o prazo corre, com retorno decrescente: prazo = {pmin} + ({pmax} − {pmin}) × ((atingimento − {piso}) ÷ ({corte} − {piso}))^{gamma}. Igual ou abaixo de {piso}: {pmin} dias. Hoje: {prazos}.', 'Below {corte} the deadline runs, with diminishing returns: deadline = {pmin} + ({pmax} − {pmin}) × ((attainment − {piso}) ÷ ({corte} − {piso}))^{gamma}. At or below {piso}: {pmin} days. Today: {prazos}.'],
        ['Entre {corte} e a meta: acompanhamento por {acomp} dias antes de sugerir troca. Na meta: mantém. {dest} ou mais por {destd} dias: destaque (candidata a página maior).', 'Between {corte} and target: monitoring for {acomp} days before suggesting a swap. On target: keeps the page. {dest} or more for {destd} days: highlight (candidate for a bigger page).'],
        ['Prazo vencido só no turno: troca de owner dentro do núcleo dono (o melhor mérito entra e troca de vaga com quem sai), depois de pelo menos {omin} dias do owner na página. Prazo vencido na unidade: troca de unidade, com prévia; o Capacity só muda depois da confirmação.', 'Deadline missed only in the shift: owner change within the owning core (best merit comes in and swaps slots with the person leaving), after at least {omin} days of the owner on the page. Deadline missed in the unit: unit change, with a preview; Capacity only changes after confirmation.'],
        ['Estabilidade: {car} dias sem reavaliar depois de uma troca; no máximo {maxs} páginas por unidade por semana. Descartar uma sugestão a esconde por 7 dias.', 'Stability: {car} days without re-evaluating after a swap; at most {maxs} pages per unit per week. Dismissing a suggestion hides it for 7 days.'],
        ['Prioridades fixas (vencem a nota): {prio}.', 'Fixed priorities (override the score): {prio}.'],
    ]],
];
?>
<header class="cabecalho">
  <span class="eyebrow"><?php echo h(t('Ajuda')); ?></span>
  <h1><?php echo h(t('Regras')); ?></h1>
  <p class="muted"><?php echo h(t('As regras de alocação, mérito e trocas do 5C Escalas, com os valores que valem hoje (Parâmetros). Versão {v} · {dia}.', ['v' => ESC_VERSAO, 'dia' => dataBr(date('Y-m-d'))])); ?></p>
</header>
<section class="panel regras">
  <div class="acoes nao-imprimir"><button type="button" class="botao primario" onclick="window.print()"><?php echo h(t('Imprimir / PDF')); ?></button></div>
  <nav class="regras-indice" aria-label="<?php echo h(t('Índice')); ?>"><ol>
    <?php foreach ($R as $id => [$tit, $titEn]): ?><li><a href="#<?php echo $id; ?>"><?php echo h($en ? $titEn : $tit); ?></a></li><?php endforeach; ?>
  </ol></nav>
  <?php $k = 0; foreach ($R as $id => [$tit, $titEn, $itens]): $k++; ?>
  <section class="regras-secao" id="<?php echo $id; ?>">
    <h2><?php echo $k . '. ' . h($en ? $titEn : $tit); ?></h2>
    <ul><?php foreach ($itens as [$pt, $enTxt]): ?><li><?php echo h(strtr($en ? $enTxt : $pt, $x)); ?></li><?php endforeach; ?></ul>
  </section>
  <?php endforeach; ?>
</section>
