<?php
// Textos em inglês da tela Equilíbrio dos SRs (pages/tertius.php). O português é a chave.
return [
    'Mérito recalculado.' => 'Merit recalculated.',
    'Gere um rascunho para ver o equilíbrio.' => 'Generate a draft to see the balance.',
    'Esta escala é da estrutura antiga (antes de {dia}). O equilíbrio dos SRs vale para as escalas da estrutura nova.' => 'This schedule uses the old structure (before {dia}). SR balance applies to schedules in the new structure.',
    'Equilíbrio dos SRs · {versao}' => 'SR balance · {versao}',
    'Turnos de cada SR na escala e o mérito do 5C George. O motor mexe o mínimo nas pessoas: primeiro fica quem já estava no posto e quem conhece a página; no empate, o SR de nota maior fica com o posto e o de nota menor fica de buffer.' => 'Each SR\'s shifts in the schedule and their 5C George merit. The engine moves people as little as possible: whoever was already on the post and whoever knows the page stays first; on a tie, the SR with the higher score keeps the post and the one with the lower score goes to buffer.',

    // faixas e KPIs
    'Acima' => 'Above',
    'Na média' => 'Average',
    'Abaixo' => 'Below',
    'Sem dados' => 'No data',
    'Nota acima da média' => 'Score above average',
    '≥ 115% da mediana' => '≥ 115% of the median',
    '85% a 115%' => '85% to 115%',
    'Abaixo da média' => 'Below average',
    '< 85% da mediana' => '< 85% of the median',
    'menos de {n} respostas em {dias} dias' => 'fewer than {n} replies in {dias} days',
    'O mérito de cada SR (nota, absenteísmo e infrações) aparece só para os perfis Planejamento e Admin. Aqui ficam os turnos de cada SR na escala.' => 'Each SR\'s merit (score, absenteeism and infractions) is shown only to the Planning and Admin profiles. Here you see each SR\'s shifts in the schedule.',

    // como a nota é calculada
    'Como a nota é calculada' => 'How the score is calculated',
    '{formula}, nos últimos {n} dias.' => '{formula}, over the last {n} days.',
    'Nota = Desempenho × SLA × Presença ÷ (1 + pena)' => 'Score = Performance × SLA × Attendance ÷ (1 + penalty)',
    'Desempenho:' => 'Performance:',
    'realizado ÷ meta 5CReps das páginas em que o SR atendeu, rateado pelas mensagens dele em cada página e turno (a mesma lógica do 5C George).' => 'actual ÷ 5CReps target of the pages the SR worked on, weighted by their messages on each page and shift (same logic as 5C George).',
    'respostas em até 5 minutos ÷ respostas.' => 'replies within 5 minutes ÷ replies.',
    'Absenteísmo:' => 'Absenteeism:',
    'blocos de falta (ABS) ÷ blocos escalados, a mesma classificação do Capacity. Falha administrativa não conta contra o SR.' => 'absence blocks (ABS) ÷ scheduled blocks, the same classification Capacity uses. Administrative failures do not count against the SR.',
    'Presença:' => 'Attendance:',
    '1 − absenteísmo até {tol}; acima disso cai rápido até {critico}, onde vale metade. Com {critico} ou mais a nota fica no máximo em {teto} da mediana: o SR nunca fica na média nem acima.' => '1 − absenteeism up to {tol}; above that it drops fast down to {critico}, where it is worth half. At {critico} or more the score is capped at {teto} of the median: the SR is never on or above average.',
    'Ver a explicação completa' => 'See the full explanation',
    'Pena das infrações:' => 'Infraction penalty:',
    'cada infração vale o peso da classificação ({pesos}) e cai pela metade a cada {meia} dias sem nova infração, até sumir depois de {janela} dias. Exemplo: peso {peso} hoje divide a nota por {d0}; em {meia} dias por {d1}; em {meia2} dias por {d2}. Sem infração, divide por 1.' => 'each infraction counts its classification weight ({pesos}) and halves every {meia} days without a new infraction, until it disappears after {janela} days. Example: weight {peso} today divides the score by {d0}; in {meia} days by {d1}; in {meia2} days by {d2}. With no infraction, it divides by 1.',
    'O cadastro de infrações já existe e ainda está vazio. Quando começar a ser preenchida, a pena entra sozinha. Os parâmetros ficam em Parâmetros, no grupo "merito".' => 'The infraction register already exists and is still empty. Once it starts being filled in, the penalty applies on its own. The settings are in Parameters, under the "merito" group.',

    // filtros e recálculo
    'Todos os turnos' => 'All shifts',
    'Recalculando o mérito… pode levar até 1 min.' => 'Recalculating merit… this can take up to 1 min.',
    'Recalcular mérito agora' => 'Recalculate merit now',
    'Calculado em {quando}; recalcula sozinho a cada {h} h.' => 'Calculated on {quando}; recalculates on its own every {h} h.',
    'Ainda não calculado (rode o ES_16).' => 'Not calculated yet (run ES_16).',

    // tabela
    'Unidade · turno · núcleo' => 'Unit · shift · core',
    'Nível' => 'Level',
    'Em posto' => 'On post',
    '% em posto × alvo' => '% on post × target',
    'Desempenho' => 'Performance',
    'SLA 5 min' => 'SLA 5 min',
    'Absent.' => 'Absent.',
    'Pena' => 'Penalty',
    'Nota' => 'Score',
    'Faixa' => 'Band',
    'núcleo {n}' => 'core {n}',
    'sem turno' => 'no shift',
    'Nenhum SR neste filtro.' => 'No SR in this filter.',
    'Turnos contados uma vez por dia e turno. "% em posto" = em posto (regular ou assistente) ÷ (em posto + buffer de plantão); o Overlap fica fora. O alvo é a fatia do nível: SR 0 = 40%, SR 1 = 60%, SR 2 = 80%, SR 3 a 5 = 100%. A nota aparece × 100 (ex.: 62,5 = desempenho × SLA × presença de 0,625). A faixa compara com a mediana de todos os SRs. Absenteísmo em laranja: acima da tolerância; em vermelho: crítico.' => 'Shifts are counted once per day and shift. "% on post" = on post (regular or assistant) ÷ (on post + on-call buffer); Overlap is left out. The target is the level\'s share: SR 0 = 40%, SR 1 = 60%, SR 2 = 80%, SR 3 to 5 = 100%. The score is shown × 100 (e.g. 62.5 = performance × SLA × attendance of 0.625). The band compares against the median of all SRs. Absenteeism in orange: above tolerance; in red: critical.',
    'O sistema não consegue ler o absenteísmo oficial (5c_wfm_db_mis.abs_adh_af): o mérito usa o 5C Schedule por horário, já sem a escala dobrada. Peça ao DBA o SELECT nessa tabela para o usuário do sistema.' => 'The system cannot read the official absenteeism (5c_wfm_db_mis.abs_adh_af): merit uses 5C Schedule per time slot, already without double scheduling. Ask the DBA for SELECT on that table for the system user.',
];
