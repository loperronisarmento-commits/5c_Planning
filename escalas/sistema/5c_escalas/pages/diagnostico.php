<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/diagnostico.php
// Confere se o usuário do banco do portal consegue LER cada tabela que
// o módulo usa, e se a sessão está mesmo em READ ONLY.
// ===============================================

escExigir('admin');

$sessao = escQ1('SELECT @@session.transaction_read_only AS so_leitura, @@session.max_execution_time AS limite_ms, VERSION() AS versao')
    ?? ['so_leitura' => null, 'limite_ms' => 0, 'versao' => '?'];

$fontes = [
    t('Planner · trocas de página') => '`5c_historic_planner`.`historic_page_planner`',
    t('Planner · estrutura atual') => '`5c_wfm_db_mis`.`vw_rep_estrutura_atual`',
    t('Escala postada') => '`5c_schedule`.`scheduleday`',
    t('Headcount · turnos') => '`5c_headcount`.`headcount_turno`',
    t('Bot · páginas') => '`5c_bot`.`vw_pagina`',
    t('Bot · reps (Telegram)') => '`5c_bot`.`vw_rep`',
    t('Capacity · buffers') => '`5c_planning`.`fc_buffer_diario`',
    t('Capacity · necessidade') => '`5c_planning`.`fc_escala_pagina`',
    t('Capacity · eventos de página') => '`5c_planning`.`fc_pagina_eventos`',
    t('Capacity · impacto') => '`5c_planning`.`fc_impacto_paginas`',
    t('Capacity · fãs por hora') => '`5c_planning`.`fc_fans_pagina_hora`',
    t('Intraday · tier') => '`5c_wfm_db_mis`.`tab_intraday_base`',
    t('CRM · cards') => '`5c_crm`.`pipeline_cards`',
    t('CRM · clientes') => '`5c_crm`.`clients`',
    t('CRM · contratos') => '`5c_crm`.`contracts`',
    t('Schedule Anomalies · acessos (primeira carga)') => ESC_TABELA_ACESSO,
    t('MIS Portal · usuários (id e login)') => '`5c_wfm_mis_web`.`mis_login`',
    t('5C Escalas · acessos') => escT('esc_acesso'),
    t('5C Escalas · colaboradores') => escT('esc_colaborador_config'),
    t('5C Escalas · planos') => escT('esc_plano'),
];

// Gravação no 5c_escalas: grava uma linha de teste e desfaz (rollback).
$gravacao = 'OK';
try {
    $pdoW = escDbW();
    $pdoW->beginTransaction();
    escW('INSERT INTO ' . escT('esc_log') . " (user_id, canal, acao, entidade) VALUES (?, 'SISTEMA', 'diagnostico.teste', 'esc_log')", [escUserId()]);
    $pdoW->rollBack();
} catch (Throwable $e) {
    if (isset($pdoW) && $pdoW->inTransaction()) {
        $pdoW->rollBack();
    }
    $gravacao = $e instanceof PDOException ? t('erro {codigo}', ['codigo' => $e->errorInfo[1] ?? $e->getCode()]) : $e->getMessage();
}
// A conexão de gravação recusa qualquer outro schema.
$travaOutros = false;
try {
    escConferirEscritaEscalas('UPDATE `5c_planning`.`fc_parametros` SET valor = 1');
} catch (RuntimeException $e) {
    $travaOutros = true;
}
$resultado = [];
foreach ($fontes as $nome => $tabela) {
    $t0 = microtime(true);
    try {
        escQ("SELECT 1 FROM {$tabela} LIMIT 1");
        $resultado[] = [$nome, $tabela, true, '', (int) round((microtime(true) - $t0) * 1000)];
    } catch (Throwable $e) {
        $msg = $e instanceof PDOException ? t('erro {codigo}', ['codigo' => $e->errorInfo[1] ?? $e->getCode()]) : $e->getMessage();
        $resultado[] = [$nome, $tabela, false, $msg, (int) round((microtime(true) - $t0) * 1000)];
    }
}

// Prova de que a trava funciona: o próprio filtro recusa uma escrita.
$travaFiltro = false;
try {
    escConferirSomenteLeitura('UPDATE `x` SET a = 1');
} catch (RuntimeException $e) {
    $travaFiltro = true;
}
?>
<header class="cabecalho">
  <span class="eyebrow"><?php echo h(t('Administração')); ?></span>
  <h1><?php echo h(t('Diagnóstico')); ?></h1>
  <p class="muted"><?php echo h(t('Use depois de instalar: confere se o usuário do banco do portal lê tudo o que o 5C Escalas precisa, se a conexão de leitura está em READ ONLY e se a gravação funciona só no 5c_escalas.')); ?></p>
</header>

<section class="kpis">
  <div class="kpi"><span class="muted"><?php echo h(t('Conexão de leitura')); ?></span><strong class="<?php echo (int) $sessao['so_leitura'] === 1 ? 'verde' : 'vermelho'; ?>"><?php echo (int) $sessao['so_leitura'] === 1 ? 'READ ONLY' : h(t('GRAVÁVEL')); ?></strong><span class="muted small">transaction_read_only = <?php echo (int) $sessao['so_leitura']; ?></span></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Filtro de SQL')); ?></span><strong class="<?php echo $travaFiltro ? 'verde' : 'vermelho'; ?>"><?php echo h($travaFiltro ? t('Ativo') : t('Falhou')); ?></strong><span class="muted small"><?php echo h(t('só SELECT/WITH passam')); ?></span></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Gravação no 5c_escalas')); ?></span><strong class="<?php echo $gravacao === 'OK' ? 'verde' : 'vermelho'; ?>"><?php echo h($gravacao === 'OK' ? 'OK' : t('Falhou')); ?></strong><span class="muted small"><?php echo h($gravacao === 'OK' ? t('teste gravado e desfeito') : t('{erro} · rode o ES_03 (GRANT)', ['erro' => $gravacao])); ?></span></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Trava dos outros schemas')); ?></span><strong class="<?php echo $travaOutros ? 'verde' : 'vermelho'; ?>"><?php echo h($travaOutros ? t('Ativa') : t('Falhou')); ?></strong><span class="muted small"><?php echo h(t('só grava em 5c_escalas')); ?></span></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Seu acesso')); ?></span><strong><?php echo h(escRotulo('perfil', $GLOBALS['ESC_USUARIO']['perfil'] ?? '')); ?></strong><span class="muted small">user_id <?php echo escUserId(); ?></span></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Limite por consulta')); ?></span><strong><?php echo num(((int) $sessao['limite_ms']) / 1000, 0); ?> s</strong><span class="muted small">max_execution_time</span></div>
  <?php $end = escDbEndereco(); ?>
  <div class="kpi"><span class="muted"><?php echo h(t('Endereço do banco')); ?></span><strong><?php echo h($end['host'] . ':' . $end['porta']); ?></strong><span class="muted small"><?php echo h($end['variavel'] === 'ESC_DB_HOST' ? t('ESC_DB_HOST, só deste módulo') : t('DB_HOST do portal')); ?></span></div>
  <div class="kpi"><span class="muted">PHP · MySQL</span><strong><?php echo h(PHP_VERSION); ?></strong><span class="muted small">MySQL <?php echo h($sessao['versao']); ?></span></div>
</section>

<section class="panel">
  <h2><?php echo h(t('Leitura das tabelas')); ?></h2>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th><?php echo h(t('Fonte')); ?></th><th><?php echo h(t('Tabela')); ?></th><th><?php echo h(t('Leitura do banco')); ?></th><th class="num"><?php echo h(t('Tempo')); ?></th></tr></thead>
    <tbody>
    <?php foreach ($resultado as [$nome, $tabela, $ok, $msg, $ms]): ?>
      <tr><td><?php echo h($nome); ?></td><td class="mono small"><?php echo h($tabela); ?></td>
        <td><span class="tag <?php echo $ok ? 'verde' : 'vermelho'; ?>"><?php echo $ok ? 'OK' : h(t('sem acesso · {erro}', ['erro' => $msg])); ?></span></td>
        <td class="num"><?php echo (int) $ms; ?> ms</td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="muted small"><?php echo h(t('"sem acesso · erro 1142" = o usuário do banco do portal não tem SELECT nessa tabela. Peça o GRANT de leitura (só SELECT) para esse schema.')); ?></p>
</section>
