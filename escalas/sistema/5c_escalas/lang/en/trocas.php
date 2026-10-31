<?php
// Textos da tela Trocas de página (pages/trocas.php) em inglês. O português é a chave.
// O conteúdo dos CSVs, os nomes de arquivo e as datas digitadas no site da 5C não passam por aqui.
return [
    // avisos e erros
    'Página ou semana fora do pacote.' => 'Page or week is not in the package.',
    '{pagina}: marcação desfeita.' => '{pagina}: mark undone.',
    '{pagina}: marcado como apagado.' => '{pagina}: marked as deleted.',
    '{pagina}: marcado como postado.' => '{pagina}: marked as posted.',
    'ver blocos' => 'see blocks',

    // cabeçalho e filtro
    'Lê as trocas de party do Planner e refaz a escala já postada: quem é da party nova e já está escalado no mesmo bloco assume a página. Depois monta o que apagar e o CSV para postar no Schedule Ajustment.' => 'Reads the party swaps from the Planner and rebuilds the schedule already posted: whoever is in the new party and is already scheduled in the same block takes over the page. Then it lists what to delete and the CSV to post in Schedule Ajustment.',
    'Lote de trocas (horário marcado no Planner)' => 'Swap batch (time set in the Planner)',
    '{dia} {hora} · {n} página(s) · {pendentes} a aplicar' => '{dia} {hora} · {n} page(s) · {pendentes} to apply',
    '{dia} {hora} · {n} página(s) · já aplicada' => '{dia} {hora} · {n} page(s) · already applied',
    'Ver' => 'View',
    'Nenhuma troca de página no Planner nos últimos 14 dias nem prevista.' => 'No page swaps in the Planner in the last 14 days and none planned.',

    // indicadores
    'Páginas no lote' => 'Pages in the batch',
    'corte no bloco {bloco} de {dia} ({hora}:00)' => 'cutoff at block {bloco} on {dia} ({hora}:00)',
    'Blocos que mudam' => 'Blocks that change',
    'na escala postada até {dia}' => 'in the posted schedule up to {dia}',
    'Sem substituto' => 'No replacement',
    'blocos em que a party nova não tem titular escalado: quem está continua' => 'blocks where the new party has no regular scheduled: whoever is there stays',
    'Situação no Planner' => 'Status in the Planner',
    'A aplicar' => 'To apply',
    'escala postada já está com as parties novas' => 'posted schedule already has the new parties',
    'escala postada ainda com as parties antigas' => 'posted schedule still has the old parties',

    // 1 · o que muda
    '1 · O que muda' => '1 · What changes',
    'Página' => 'Page',
    'De' => 'From',
    'Para' => 'To',
    'Blocos postados' => 'Posted blocks',
    'Mudam' => 'Change',
    'muda de time' => 'changes team',
    '{codigo} · {party} · {n} bloco(s)' => '{codigo} · {party} · {n} block(s)',
    '{codigo} · {papel} · {n} bloco(s)' => '{codigo} · {papel} · {n} block(s)',
    'sem party' => 'no party',
    'Titular' => 'Regular',
    '(líder e titulares da party) segue a party: entra na página nova nos blocos em que já está escalado em alguma página da própria party.' => '(party leader and regulars) follows the party: joins the new page in the blocks where they are already scheduled on another page of their own party.',
    'Quem é do {timeNovo} (outra party do time cobrindo, ou SR de rodízio do time) fica; {buffer} fica sempre (não tem time).' => 'Whoever is in the {timeNovo} (another party of the team covering, or a rotation SR of the team) stays; {buffer} always stays (it has no team).',
    'time novo' => 'new team',
    'Quem é de outro time só sai se a party nova tiver titular escalado no mesmo bloco. Se não tiver, continua e o bloco aparece em "sem substituto" para alguém decidir.' => 'Whoever is from another team only leaves if the new party has a regular scheduled in the same block. If not, they stay and the block shows up under "no replacement" for someone to decide.',
    'Se o lote já foi aplicado e a escala refeita, nada muda aqui: serve para conferir.' => 'If the batch was already applied and the schedule rebuilt, nothing changes here: use it to check.',

    // 2 · postar no site
    '2 · Postar no site da 5C (Schedule Ajustment)' => '2 · Post on the 5C website (Schedule Ajustment)',
    'Faça em todas as páginas antes de postar qualquer uma. Delete Range apaga só a página do Model.' => 'Do this on every page before posting any of them. Delete Range only deletes the Model page.',
    '{acao} de {ini} até o último dia postado de cada página, e apague {aMao} em {dia} os blocos {blocos}.' => '{acao} from {ini} to the last posted day of each page, and delete {aMao} on {dia} the blocks {blocos}.',
    'à mão' => 'by hand',
    '{acao} de {ini} até o último dia postado de cada página.' => '{acao} from {ini} to the last posted day of each page.',
    '{acao} com Date Schedule {dia}. No dia {dia} só vão os blocos a partir do corte; o resto fica em branco (o intervalo precisa começar nesse dia).' => '{acao} with Date Schedule {dia}. On {dia} only the blocks from the cutoff go in; the rest stays blank (the range must start on that day).',
    'Copiar CSV' => 'Copy CSV',
    'Baixar .csv' => 'Download .csv',
    '{n} lacuna' => '{n} gap',
    '{n} lacunas' => '{n} gaps',
    'mudou depois de postado: postar de novo' => 'changed after posting: post again',
    'postado {quando}' => 'posted {quando}',
    'intervalo apagado' => 'range deleted',
    'a fazer' => 'to do',
    '1 · Apaguei' => '1 · Deleted',
    '2 · Postei' => '2 · Posted',
    '2 · Postei de novo' => '2 · Posted again',
    'Desfazer' => 'Undo',
    '{pagina}: sem escala postada a partir do corte.' => '{pagina}: no schedule posted from the cutoff.',
    'Linhas T1 a T6, colunas de segunda a domingo. "#" = assistente. O "$" (turno secundário) o site calcula sozinho no input.' => 'Rows T1 to T6, columns Monday to Sunday. "#" = assistant. The website works out the "$" (secondary shift) on its own at input.',
];
