<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/dba.php
// Documentação do DBA (só admin): bancos, conexões e permissões, tabelas (lidas ao
// vivo do information_schema), chaves, parâmetros, scripts SQL, rotinas e
// rollback. PDF pela impressão do navegador; Markdown em ?baixar=md.
// ===============================================

escExigir('admin');
$schema = ESC_SCHEMA_REAL;
$en = escEmIngles();
$tx = static fn(array $par): string => $en ? $par[1] : $par[0];

$bancos = [
    [$schema, ['Grava (INSERT, UPDATE, DELETE). Todo o planejamento: planos, alocações, folgas, cadastros, pedidos, saídas, log.', 'Writes (INSERT, UPDATE, DELETE). All planning: plans, allocations, days off, records, requests, exits, log.']],
    [ESC_SCHEMA_TREINO, ['Modo Treino (ES_28): mesmas tabelas; "Recomeçar o treino" apaga e copia os cadastros do 5c_escalas.', 'Training mode (ES_28): same tables; "Restart training" wipes it and copies the records from 5c_escalas.']],
    ['5c_planning', ['Capacity. Só leitura, exceto na saída de página: UPDATE fc_pagina_carteira (vigente_ate) e CALL sp_fc_postos / sp_fc_recalcular_postos.', 'Capacity. Read-only, except for page exits: UPDATE fc_pagina_carteira (vigente_ate) and CALL sp_fc_postos / sp_fc_recalcular_postos.']],
    ['5c_schedule', ['Escala postada (scheduleday). Só leitura.', 'Posted schedule (scheduleday). Read-only.']],
    ['5c_headcount', ['Cadastro de pessoas (headcount, headcount_turno). Só leitura; só code, name, socialName, position e active.', 'People records (headcount, headcount_turno). Read-only; only code, name, socialName, position and active.']],
    ['5c_historic_planner', ['Histórico do Planner (trocas de página). Só leitura.', 'Planner history (page swaps). Read-only.']],
    ['5c_pages', ['Cadastro de páginas. Só leitura.', 'Page records. Read-only.']],
    ['5c_crm', ['Funil do CRM (cards, etapas, clientes, tiers). Só leitura.', 'CRM funnel (cards, stages, clients, tiers). Read-only.']],
    ['5c_wfm_db_mis', ['Faturamento e intraday (mérito, arquétipos, horas extras). Só leitura.', 'Revenue and intraday (merit, archetypes, overtime). Read-only.']],
    ['5c_wfm_mis_web', ['Login do portal: só id e login de mis_login (nunca senha ou CPF) e os acessos do Schedule Anomalies na primeira carga.', 'Portal login: only id and login from mis_login (never password or CPF) and the Schedule Anomalies access list on the first load.']],
];
$scripts = [
    ['ES_01', ['Cria o schema 5c_escalas', 'Creates the 5c_escalas schema']], ['ES_02', ['Carga inicial', 'Initial load']],
    ['ES_03', ['Permissão do usuário do portal', 'Portal user grant']], ['ES_04', ['Estrutura: unidades e núcleos', 'Structure: units and cores']],
    ['ES_05', ['Carga da estrutura de 27/10', 'Structure load of Oct 27']], ['ES_06', ['Ajuste de pessoas para 01/11', 'People adjustment for Nov 1']],
    ['ES_07', ['Conferência da estrutura nova', 'New structure check']], ['ES_08', ['Virada de 02/11', 'Switch-over of Nov 2']],
    ['ES_09', ['Dias de transição', 'Transition days']], ['ES_10', ['Postos do Capacity de 28/10', 'Capacity posts of Oct 28']],
    ['ES_11', ['Overlap (dia de treinamento)', 'Overlap (training day)']], ['ES_12', ['Faturamento, Overlap e acúmulo', 'Revenue, Overlap and over-capacity']],
    ['ES_13', ['Overlap defasado', 'Staggered Overlap']], ['ES_14', ['Horas extras', 'Overtime']],
    ['ES_15', ['Saída da Skylar Mae VIP', 'Skylar Mae VIP exit']], ['ES_16', ['Mérito, infrações e saídas', 'Merit, infractions and exits']],
    ['ES_17', ['Equipes das vagas pelo histórico', 'Slot teams from history']], ['ES_18', ['Virada em 31/10 21:00', 'Switch-over on Oct 31 21:00']],
    ['ES_19', ['Escala da liderança', 'Leadership schedule']], ['ES_20', ['Liderança no plano', 'Leadership in the plan']],
    ['ES_21', ['Quadro da liderança de 29/10', 'Leadership roster of Oct 29']], ['ES_22', ['5x2 com até 5 dias seguidos', '5x2 with up to 5 days in a row']],
    ['ES_23', ['Controle de postagem da grade', 'Grid posting control']], ['ES_24', ['Fecha cadastros 3x1 em 31/10', 'Closes 3x1 records on Oct 31']],
    ['ES_25', ['Trocas por preferência de turno', 'Swaps by shift preference']], ['ES_26', ['Pedidos de ausência do formulário', 'Absence requests from the form']],
    ['ES_27', ['Funil: destino por unidade, turno e núcleo', 'Funnel: destination by unit, shift and core']], ['ES_28', ['Modo Treino (schema 5c_escalas_treino)', 'Training mode (5c_escalas_treino schema)']],
    ['ES_29', ['Liderança pelo roster de 30/10', 'Leadership from the Oct 30 roster']],
    ['ES_30', ['Solicitações do SR (esc_solicitacao, também no treino)', 'SR requests (esc_solicitacao, also in training)']],
    ['ES_31', ['Corrige 2 trocas do ES_25 que rodou depois do roster (ES_29)', 'Fixes 2 ES_25 swaps run after the roster (ES_29)']],
    ['ES_32', ['Andressa Urach (Privacy) a partir de 02/11: Capacity e postos na U2', 'Andressa Urach (Privacy) from Nov 2: Capacity and posts in U2']],
    ['ES_33', ['Política de páginas por mérito: parâmetros, prioridades fixas e registro de trocas', 'Merit-based page policy: parameters, fixed priorities and swap log']],
    ['ES_34', ['Solicitações: pessoa da dupla ou da assistência (destino_rep)', 'Requests: pair or assisted person (destino_rep)']],
    ['ES_35', ['Renumera as unidades: Victoria = U1, João = U2, Keller = U3 (Escalas e Capacity)', 'Renumbers the units: Victoria = U1, João = U2, Keller = U3 (Escalas and Capacity)']],
    ['ES_36', ['Treinamento de New Hires (turmas, trainees, páginas, origem TREINO) e parâmetros do absenteísmo no mérito', 'New Hire training (classes, trainees, pages, TREINO origin) and absenteeism parameters in merit']],
    ['ES_37', ['Saída de página com outra página entrando no lugar: página e data na saída, dias sem página (buffer ou fora da escala)', 'Page exit with another page coming in instead: page and date on the exit, days without a page (buffer or off the schedule)']],
    ['ES_38', ['Treinamento: página de lacuna (o treinamento cobre e o gerador não escala ninguém nela) ou emprestada; horas extras por bloco de 4 h', 'Training: gap page (the training covers it and the generator schedules no one there) or lent page; overtime by 4-hour block']],
    ['ES_39', ['Correção de dados: desfaz as saídas de Cynthia, Ms Red VIP e Ms Red Free (30/10) e fecha os postos refeitos que ficaram duplicados a partir de 01/11', 'Data fix: rolls back the Cynthia, Ms Red VIP and Ms Red Free exits (10/30) and closes the rebuilt posts left duplicated from 11/01']],
    ['ES_40', ['Correção de dados: desfaz a atualização de estrutura de 30/10 23:30 (159 postos reabertos, 28 fechados) e acerta a vigência das páginas novas no Capacity', 'Data fix: rolls back the 10/30 23:30 structure update (159 posts reopened, 28 closed) and fixes the start date of the new pages in Capacity']],
    ['ES_41', ['Correção de dados: tira do Capacity a Cherie Deville 257t (gravada pelo zip antigo com vigência de 30/10) e libera a próxima atualização de estrutura', 'Data fix: removes Cherie Deville 257t from Capacity (written by the old zip with a 10/30 start) and frees the next structure update']],
    ['ES_42', ['Correção de dados: código da página no site nos postos novos (Mandi CAT, Eden The Doll) que a atualização de estrutura gravou sem', 'Data fix: website page code on the new posts (Mandi CAT, Eden The Doll) that the structure update saved without it']],
    ['ES_43', ['Correção de dados: reabre os postos da Andressa Urach (de 02/11) e marca a Maddie ativa na foto de páginas do Capacity de 30/10', 'Data fix: reopens the Andressa Urach posts (from 11/02) and marks Maddie active in the Capacity page snapshot of 10/30']],
    ['ES_51', ['Primeira geração: trocas de unidade do Equilíbrio já em 02/11 (Mikayla Free + Paid para a U3; Nicole Doshi + Nicole OFTV para a U1), descarta a v22.0 da semana de 02/11 e recalcula o Capacity', 'First generation: Unit balance moves already on 11/02 (Mikayla Free + Paid to U3; Nicole Doshi + Nicole OFTV to U1), discards v22.0 of the week of 11/02 and recalculates Capacity']],
    ['ES_50', ['Folga juntos: Marcos Cid na U2 D núcleo 2 a partir de 09/11, o ciclo do Rafael Garcia da Costa', 'Days off together: Marcos Cid in U2 D core 2 from 11/09, the cycle of Rafael Garcia da Costa']],
    ['ES_49', ['Descarta a v19.1 da semana de 02/11 (apagada do site para gerar do zero)', 'Discards v19.1 of the week of 11/02 (deleted from the website to generate from scratch)']],
    ['ES_48', ['Remanejamento de 15 SRs pelas preferências do Shift Preference Form, para equilibrar a cobertura das unidades × turnos (73% a 76% do que o Capacity pede); cada um mantém o ciclo de folgas; a partir de 09/11, 16/11 ou 23/11', 'Reassignment of 15 SRs based on the Shift Preference Form, to balance the coverage of units × shifts (73% to 76% of what Capacity requires); each keeps their day-off cycle; from 11/09, 11/16 or 11/23']],
    ['ES_47', ['Indisponibilidade recorrente aprovada do Rhazzel John Manabo Marfa (R025361): todos os dias de 01/11/2026 a 01/02/2027, turno D inteiro', 'Approved recurring unavailability for Rhazzel John Manabo Marfa (R025361): every day from 11/01/2026 to 02/01/2027, the whole D shift']],
    ['ES_46', ['Folga juntos: alinha o ciclo dos 3 casais a partir de 09/11 (Rafael Gauze troca o ciclo com Raphael Estevam; Marcos Cid vai para a U2 D núcleo 3, o turno que marcou e o ciclo do Rafael Garcia da Costa; Iris Moreira vai para o núcleo 3 da U3 C), como o botão Aplicar da tela', 'Days off together: aligns the cycle of the 3 couples from 11/09 (Rafael Gauze swaps cycles with Raphael Estevam; Marcos Cid moves to U2 D core 3, the shift he chose and Rafael Garcia da Costa\'s cycle; Iris Moreira moves to core 3 of U3 C), like the Apply button on the screen']],
    ['ES_45', ['Folga juntos: tabela esc_folga_par (também no treino) e os 3 pares já conhecidos (Rafael Gauze e João Caial; Marcos Cid e Rafael Garcia da Costa; Matheus Boaventura e Iris Moreira)', 'Days off together: esc_folga_par table (also in training) and the 3 known pairs (Rafael Gauze and João Caial; Marcos Cid and Rafael Garcia da Costa; Matheus Boaventura and Iris Moreira)']],
    ['ES_44', ['Correção de dados: v23.1 e v19.1 a partir das versões postadas, com só a Maddie (junto da Britt VIP) e sem turno dobrado em 01/11; descarta os rascunhos gerados do zero', 'Data fix: v23.1 and v19.1 from the posted versions, with only Maddie (with Britt VIP) and no double shift on 11/01; discards the drafts generated from scratch']],
    ['ES_98', ['Desfaz a estrutura nova', 'Rolls back the new structure']], ['ES_99', ['Desfaz o 5c_escalas inteiro', 'Rolls back the whole 5c_escalas']],
];
$regras = [
    ['Conexão de leitura em READ ONLY; só passa SELECT/WITH, uma instrução por vez (lib/db.php · escQ).', 'Read connection in READ ONLY; only SELECT/WITH, one statement at a time (lib/db.php · escQ).'],
    ['Conexão de gravação: só INSERT, UPDATE ou DELETE em `5c_escalas`.`tabela`, sem citar outro schema, sem DDL (escW). O GRANT do usuário do portal só dá escrita no 5c_escalas e no 5c_escalas_treino.', 'Write connection: only INSERT, UPDATE or DELETE on `5c_escalas`.`table`, no other schema, no DDL (escW). The portal user\'s GRANT only allows writes to 5c_escalas and 5c_escalas_treino.'],
    ['Exceções: as duas instruções do Capacity na saída de página (escCapacityGravar) e a cópia do "Recomeçar o treino" (lib/treino.php), ambas com SQL fixo e nomes de tabela de lista fechada.', 'Exceptions: the two Capacity statements on page exit (escCapacityGravar) and the "Restart training" copy (lib/treino.php), both with fixed SQL and table names from a closed list.'],
    ['max_execution_time por sessão: ESC_MAX_EXECUCAO_MS (30 s). Consulta acima de 1 s vai para logs/app.log como "Consulta lenta".', 'Per-session max_execution_time: ESC_MAX_EXECUCAO_MS (30 s). Queries over 1 s go to logs/app.log as "Consulta lenta".'],
    ['Travas (GET_LOCK): 5c_escalas.saida_pagina (uma saída aplicada por vez).', 'Locks (GET_LOCK): 5c_escalas.saida_pagina (one page exit applied at a time).'],
    ['Formulários com token CSRF por sessão; tudo o que grava registra em esc_log (quem, quando, antes e depois).', 'Forms carry a per-session CSRF token; every write is recorded in esc_log (who, when, before and after).'],
    ['Não há tarefa agendada: a verificação de mudanças roda só pelo botão (Painel e Gerar escala); o mérito é recalculado sob demanda a cada merito_cache_horas.', 'No scheduled jobs: change detection runs only from the button (Dashboard and Generate schedule); merit is recalculated on demand every merito_cache_horas.'],
    ['Script novo de estrutura: rodar também no 5c_escalas_treino (ou rodar o ES_28 de novo e "Recomeçar o treino"); a cópia do treino usa só as colunas que existem nos dois.', 'New structure script: also run it on 5c_escalas_treino (or run ES_28 again and "Restart training"); the training copy only uses columns present in both.'],
];

$tabelas = escQ("SELECT TABLE_NAME AS t, TABLE_TYPE AS tipo, TABLE_ROWS AS linhas, TABLE_COMMENT AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME", [$schema]);
$colunas = [];
foreach (escQ('SELECT TABLE_NAME AS t, COLUMN_NAME AS c, COLUMN_TYPE AS tipo, IS_NULLABLE AS nulo, COLUMN_KEY AS chave, COLUMN_DEFAULT AS padrao, COLUMN_COMMENT AS com FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION', [$schema]) as $c) {
    $colunas[$c['t']][] = $c;
}
$fks = [];
foreach (escQ('SELECT k.TABLE_NAME AS t, k.CONSTRAINT_NAME AS n, k.COLUMN_NAME AS c, k.REFERENCED_TABLE_NAME AS rt, k.REFERENCED_COLUMN_NAME AS rc, r.DELETE_RULE AS del
                 FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
                WHERE k.CONSTRAINT_SCHEMA = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME', [$schema]) as $f) {
    $fks[$f['t']][] = $f;
}
$parametros = escQ('SELECT chave, valor, grupo, descricao FROM `' . $schema . '`.`esc_parametro` ORDER BY grupo, chave');
$treinoOk = escTreinoExiste();

// ---------- Markdown ----------
if (($_GET['baixar'] ?? '') === 'md') {
    $md = '# ' . t('5C Escalas · Documentação do DBA') . "\n\n" . t('Versão {v} · gerado em {quando}', ['v' => ESC_VERSAO, 'quando' => escDataHora(time(), true)]) . "\n\n";
    $md .= '## ' . t('Bancos') . "\n\n| Schema | " . t('Uso') . " |\n|---|---|\n";
    foreach ($bancos as [$b, $u]) {
        $md .= '| `' . $b . '` | ' . $tx($u) . " |\n";
    }
    $md .= "\n## " . t('Conexões, segurança e rotinas') . "\n\n";
    foreach ($regras as $r) {
        $md .= '- ' . $tx($r) . "\n";
    }
    $md .= "\n## " . t('Tabelas de {schema}', ['schema' => $schema]) . "\n";
    foreach ($tabelas as $tb) {
        $md .= "\n### `" . $tb['t'] . '`' . ($tb['tipo'] === 'VIEW' ? ' (view)' : '') . "\n\n" . ($tb['c'] && $tb['c'] !== 'VIEW' ? $tb['c'] . "\n\n" : '');
        $md .= '| ' . t('Coluna') . ' | ' . t('Tipo') . ' | ' . t('Nulo') . ' | ' . t('Chave') . ' | ' . t('Comentário') . " |\n|---|---|---|---|---|\n";
        foreach ($colunas[$tb['t']] ?? [] as $c) {
            $md .= '| `' . $c['c'] . '` | ' . $c['tipo'] . ' | ' . $c['nulo'] . ' | ' . $c['chave'] . ' | ' . str_replace('|', '/', (string) $c['com']) . " |\n";
        }
        foreach ($fks[$tb['t']] ?? [] as $f) {
            $md .= '- FK `' . $f['n'] . '`: `' . $f['c'] . '` → `' . $f['rt'] . '.' . $f['rc'] . '` (ON DELETE ' . $f['del'] . ")\n";
        }
    }
    $md .= "\n## " . t('Parâmetros') . "\n\n| " . t('Chave') . ' | ' . t('Valor') . ' | ' . t('Grupo') . ' | ' . t('Descrição') . " |\n|---|---|---|---|\n";
    foreach ($parametros as $pr) {
        $md .= '| `' . $pr['chave'] . '` | ' . str_replace('|', '/', (string) $pr['valor']) . ' | ' . $pr['grupo'] . ' | ' . str_replace('|', '/', (string) $pr['descricao']) . " |\n";
    }
    $md .= "\n## " . t('Scripts SQL') . "\n\n";
    foreach ($scripts as [$s, $d]) {
        $md .= '- `' . $s . '`: ' . $tx($d) . "\n";
    }
    header('Content-Type: text/markdown; charset=utf-8');
    header('Content-Disposition: attachment; filename="5c_escalas_dba_' . date('Y-m-d') . '.md"');
    echo $md;
    return;
}
?>
<div class="dba">
<header class="cabecalho">
  <span class="eyebrow"><?php echo h(t('Ajuda · só admin')); ?></span>
  <h1><?php echo h(t('Documentação do DBA')); ?></h1>
  <p class="muted"><?php echo h(t('Bancos, conexões e permissões, tabelas e chaves (lidas agora do banco), parâmetros, scripts SQL e rotinas do 5C Escalas. Versão {v}.', ['v' => ESC_VERSAO])); ?></p>
  <div class="acoes dba-acoes">
    <button type="button" class="botao primario" onclick="window.print()"><?php echo h(t('Imprimir / PDF')); ?></button>
    <a class="botao" href="<?php echo h(link_tela('dba', ['baixar' => 'md'])); ?>"><?php echo h(t('Baixar Markdown')); ?></a>
  </div>
</header>

<section class="panel">
  <h2><?php echo h(t('Bancos')); ?></h2>
  <div class="tabela-rolagem"><table data-sem-ordem>
    <thead><tr><th>Schema</th><th><?php echo h(t('Uso')); ?></th></tr></thead>
    <tbody><?php foreach ($bancos as [$b, $u]): ?><tr><td class="mono"><?php echo h($b); ?><?php echo $b === ESC_SCHEMA_TREINO && !$treinoOk ? ' <span class="tag laranja">' . h(t('não instalado')) . '</span>' : ''; ?></td><td class="small"><?php echo h($tx($u)); ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
</section>

<section class="panel">
  <h2><?php echo h(t('Conexões, segurança e rotinas')); ?></h2>
  <ul class="regras small"><?php foreach ($regras as $r): ?><li><?php echo h($tx($r)); ?></li><?php endforeach; ?></ul>
</section>

<section class="panel">
  <h2><?php echo h(t('Tabelas de {schema}', ['schema' => $schema])); ?> · <?php echo count($tabelas); ?></h2>
  <?php foreach ($tabelas as $tb): ?>
  <div class="dba-tabela" id="t-<?php echo h($tb['t']); ?>">
    <h3 class="mono"><?php echo h($tb['t']); ?><?php echo $tb['tipo'] === 'VIEW' ? ' (view)' : ''; ?> <span class="muted small"><?php echo $tb['linhas'] !== null ? h(t('~{n} linhas', ['n' => num((int) $tb['linhas'], 0)])) : ''; ?></span></h3>
    <?php if ($tb['c'] && $tb['c'] !== 'VIEW'): ?><p class="muted small"><?php echo h($tb['c']); ?></p><?php endif; ?>
    <details><summary class="small"><?php echo h(t('{n} colunas', ['n' => count($colunas[$tb['t']] ?? [])])); ?><?php echo !empty($fks[$tb['t']]) ? ' · ' . h(t('{n} chaves estrangeiras', ['n' => count($fks[$tb['t']])])) : ''; ?></summary>
      <div class="tabela-rolagem"><table data-sem-ordem>
        <thead><tr><th><?php echo h(t('Coluna')); ?></th><th><?php echo h(t('Tipo')); ?></th><th><?php echo h(t('Nulo')); ?></th><th><?php echo h(t('Chave')); ?></th><th><?php echo h(t('Comentário')); ?></th></tr></thead>
        <tbody><?php foreach ($colunas[$tb['t']] ?? [] as $c): ?><tr><td class="mono small"><?php echo h($c['c']); ?></td><td class="mono small"><?php echo h($c['tipo']); ?></td><td class="small"><?php echo h($c['nulo']); ?></td><td class="small"><?php echo h($c['chave']); ?></td><td class="small"><?php echo h((string) $c['com']); ?></td></tr><?php endforeach; ?></tbody>
      </table></div>
      <?php if (!empty($fks[$tb['t']])): ?><ul class="regras small"><?php foreach ($fks[$tb['t']] as $f): ?><li class="mono"><?php echo h($f['n'] . ': ' . $f['c'] . ' → ' . $f['rt'] . '.' . $f['rc'] . ' (ON DELETE ' . $f['del'] . ')'); ?></li><?php endforeach; ?></ul><?php endif; ?>
    </details>
  </div>
  <?php endforeach; ?>
</section>

<section class="panel">
  <h2><?php echo h(t('Parâmetros')); ?> · <?php echo count($parametros); ?></h2>
  <div class="tabela-rolagem"><table>
    <thead><tr><th><?php echo h(t('Chave')); ?></th><th><?php echo h(t('Valor')); ?></th><th><?php echo h(t('Grupo')); ?></th><th><?php echo h(t('Descrição')); ?></th></tr></thead>
    <tbody><?php foreach ($parametros as $pr): ?><tr><td class="mono small"><?php echo h($pr['chave']); ?></td><td class="mono small"><?php echo h((string) $pr['valor']); ?></td><td class="small"><?php echo h($pr['grupo']); ?></td><td class="small"><?php echo h((string) $pr['descricao']); ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
</section>

<section class="panel">
  <h2><?php echo h(t('Scripts SQL')); ?></h2>
  <p class="muted small"><?php echo h(t('Ficam na pasta escalas/sql do repositório e rodam no Navicat, na ordem. ES_98 e ES_99 desfazem.')); ?></p>
  <ul class="regras small"><?php foreach ($scripts as [$s, $d]): ?><li><span class="mono"><?php echo h($s); ?></span> · <?php echo h($tx($d)); ?></li><?php endforeach; ?></ul>
</section>
</div>
