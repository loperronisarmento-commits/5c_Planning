<?php
// Mensagens das bibliotecas (lib/motor.php, motor_v4.php, saida.php, lideranca.php, dados.php)
// em inglês. O português é a chave. O que é gravado no banco (motivos, descrições de
// recálculo, CSV) fica em português e não passa por aqui.
return [
    // lib/motor.php · escNomeFonte (fontes de entrada mostradas na tela)
    'estrutura/membros' => 'structure/members',
    'páginas' => 'pages',
    'colaboradores' => 'employees',
    'indisponibilidade' => 'unavailability',
    'modelos' => 'schedule models',
    'trocas de página' => 'page swaps',

    // lib/motor.php · escGradeConflitos (alertas do ajuste manual na grade)
    '{rep} está de folga neste dia ({tipo})' => '{rep} has a day off on this day ({tipo})',
    '{rep} tem indisponibilidade aprovada neste turno' => '{rep} has approved unavailability in this shift',
    '{rep} é do turno {turno}' => '{rep} belongs to shift {turno}',
    '{rep} já está em {paginas} neste turno' => '{rep} is already on {paginas} in this shift',
    '{rep} está no turno {turno} de {dia}: seriam 16 h seguidas (máximo 12 h)' => '{rep} is on shift {turno} on {dia}: that would be 16 h in a row (max. 12 h)',
    '{rep} passaria de {max} h na semana ({horas} h)' => '{rep} would go over {max} h in the week ({horas} h)',

    // lib/motor_v4.php e lib/lideranca.php
    'Falta o modelo "5x2 rotativo 21 dias". Rode o ES_04.' => 'The "5x2 rotativo 21 dias" schedule model is missing. Run ES_04.',

    // lib/saida.php
    'Gravação no Capacity não permitida: {acao}' => 'Write to Capacity not allowed: {acao}',
    'Informe a página e a data.' => 'Enter the page and the date.',
    '{pagina}: esta saída já foi aplicada em {quando} (sem a página a partir de {desde}). Confira em "Saídas aplicadas".' => '{pagina}: this page exit was already applied on {quando} (without the page from {desde}). Check "Applied exits".',
    'Já tem uma saída sendo aplicada (Capacity e escala recalculando). Espere terminar e confira em "Saídas aplicadas".' => 'A page exit is already being applied (Capacity and schedule recalculating). Wait for it to finish and check "Applied exits".',

    // lib/dados.php · regras de formação das parties (estrutura legada)
    'Sem líder' => 'No party leader',
    'Definir o líder da party (promover um titular ou receber de outra party).' => 'Set the party leader (promote a fixed member or receive one from another party).',
    '{n} líderes' => '{n} party leaders',
    'Mover o líder extra para uma party sem líder.' => 'Move the extra leader to a party with no leader.',
    '{n} titulares (máx. 2)' => '{n} fixed members (max. 2)',
    'Mover {n} titular(es) para party com falta ou passar ao rodízio.' => 'Move {n} fixed member(s) to a party that is short, or move them to rotation.',
    'Faltam {n} titulares' => '{n} fixed member(s) missing',
    'Receber titulares de uma party com excesso.' => 'Receive fixed members from a party with too many.',
    'Sem página ativa' => 'No active page',
    'Alocar página no Planner ou desfazer a party.' => 'Assign a page in the Planner or dissolve the party.',
    'A saída vale a partir de hoje ou de uma data futura: não dá para aplicar no passado.' => 'The exit applies from today or a future date: it cannot be applied in the past.',
    '{pagina} não está ativa no Capacity a partir de {desde} (confira o nome da página).' => '{pagina} is not active in Capacity from {desde} (check the page name).',
    '{n} vaga sem equipe foi completada (Páginas › Owners e backups).' => '{n} slot without a team was filled (Pages › Owners and backups).',
    '{n} vagas sem equipe foram completadas (Páginas › Owners e backups).' => '{n} slots without a team were filled (Pages › Owners and backups).',
    '{n} vaga ficou sem equipe: não há SR no turno da unidade.' => '{n} slot is still without a team: there is no SR on the unit\'s shift.',
    '{n} vagas ficaram sem equipe: não há SR no turno da unidade.' => '{n} slots are still without a team: there is no SR on the unit\'s shift.',
    'Não deu para atualizar o Capacity antes de gerar ({erro}): a escala usou a estrutura anterior.' => 'Could not update Capacity before generating ({erro}): the schedule used the previous structure.',
    'Capacity recalculado antes de gerar: {m} postos iguais, {n} novos, {f} fechados.' => 'Capacity recalculated before generating: {m} posts unchanged, {n} new, {f} closed.',
    'Entraram no Capacity: {lista}.' => 'Added to Capacity: {lista}.',
    'Postos levados para a unidade nova: {lista}.' => 'Posts moved to the new unit: {lista}.',    'o Capacity de hoje veio com {n} páginas e a escala tem {m}; os postos não foram trocados. Confira o cálculo do Capacity' => 'today\'s Capacity came with {n} pages and the schedule has {m}; posts were not replaced. Check the Capacity calculation',
    'Mínimo de mudanças sobre a {base}: {mantidas} células mantidas; {paginas} páginas mudaram ({novas} vagas preenchidas, {mais} como página a mais, {lacunas} sem ninguém).' => 'Minimum changes on {base}: {mantidas} cells kept; {paginas} pages changed ({novas} slots filled, {mais} as an extra page, {lacunas} with no one).',
];
