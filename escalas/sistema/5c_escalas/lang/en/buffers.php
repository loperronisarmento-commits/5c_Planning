<?php
// Textos em inglês da tela Buffers (pages/buffers.php). O português é a chave.
return [
    'Gere um rascunho para ver os Buffers.' => 'Generate a draft to see the Buffers.',
    'Buffers · {versao}' => 'Buffers · {versao}',

    // estrutura nova
    'Buffer de plantão por unidade e turno: quem fica sem posto no dia vai para a página {pagina} com -2 no CSV e cobre faltas. "Precisa" vem do Capacity (presentes por dia, já com absenteísmo; cálculo de {dia}), a mesma fonte das telas Horas extras e Análise.' => 'On-call buffer per unit and shift: whoever has no post that day goes to the {pagina} page with -2 in the CSV and covers absences. "Needs" comes from Capacity (present per day, absenteeism included; calculated on {dia}), the same source as the Overtime and Analysis screens.',
    'U{u} · precisa / tem' => 'U{u} · needs / has',
    'antes da virada' => 'before cutover',
    '(falta {n})' => '({n} short)',
    '{falta} buffer(s)-turno faltando e {sobra} sobrando na semana.' => '{falta} buffer-shift(s) short and {sobra} extra in the week.',
    'Laranja: menos buffers de plantão do que o Capacity pede (a falta pode virar hora extra em {link}). Azul: mais do que o pedido. Passe o mouse para ver quem.' => 'Orange: fewer on-call buffers than Capacity asks for (the shortfall can become overtime in {link}). Blue: more than requested. Hover to see who.',

    // estrutura antiga
    'Estrutura antiga (antes de {dia}). O pool cobre o que sobra depois dos times. "Precisa" vem do Capacity (já com absenteísmo); "usados" é o que o rascunho alocou; Buffer sem página vira sugestão de remoção por falta de workload.' => 'Old structure (before {dia}). The pool covers what is left after the teams. "Needs" comes from Capacity (absenteeism included); "used" is what the draft allocated; a Buffer with no page becomes a removal suggestion for lack of workload.',
    'Por dia e turno · precisa / usados / sem página' => 'By day and shift · needs / used / no page',
    'Laranja: usou menos Buffers do que o Capacity reserva (a falta foi coberta pelos times, ou ficou lacuna). Azul: sobrou Buffer sem página.' => 'Orange: fewer Buffers used than Capacity reserves (the shortfall was covered by the teams, or left a gap). Blue: a Buffer was left with no page.',
    'Pool · {n} Buffers (A {a} · C {c} · D {d})' => 'Pool · {n} Buffers (A {a} · C {c} · D {d})',
    'Modelo' => 'Model',
    'folga' => 'day off',
    'sem página' => 'no page',
];
