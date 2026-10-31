<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/painel.php
// Painel: o que precisa de atenção, com dados reais. A verificação de mudanças
// nas entradas roda só pelo botão (não recalcula sozinha ao abrir a tela).
// ===============================================

if (escPost()) {
    // Verificar mudanças só pelo botão (sem recálculo sozinho ao abrir a tela).
    escExigir('planejar');
    if (($_POST['acao'] ?? '') !== 'verificar') {
        throw new RuntimeException(t('Ação desconhecida.'));
    }
    $r = escMotorVerificar(escUserId(), true);
    escVoltar('painel', [], $r['recalculados']
        ? t('Mudanças nas entradas: {lista}. O rascunho foi recalculado; veja o De→Para na grade.', ['lista' => implode('; ', array_map(static fn($x) => t('semana de {dia} ({fontes})', ['dia' => dataBr($x['semana']), 'fontes' => implode(', ', array_map('escNomeFonte', $x['fontes']))]), $r['recalculados']))])
        : t('Nada mudou nas entradas desde a última versão.'));
}

$legado = escLegadoAtivo();
$parties = $legado ? escFormacaoParties() : [];
$foraDaRegra = array_filter($parties, static fn($l) => !empty($l['regras']));
$bloqueantes = array_filter($parties, static function ($l) {
    foreach ($l['regras'] as $r) {
        if ($r[2]) {
            return true;
        }
    }
    return false;
});

$trocas = escQ1("SELECT COUNT(*) AS n, MIN(`timestamp`) AS proxima
                   FROM `5c_historic_planner`.`historic_page_planner`
                  WHERE changeTypeId = 40 AND isApplied = 0
                    AND `timestamp` >= DATE_FORMAT(NOW() - INTERVAL 1 DAY, '%Y-%m-%dT%H:%i:%s')
                    AND `timestamp` < DATE_FORMAT(NOW() + INTERVAL 30 DAY, '%Y-%m-%dT%H:%i:%s')");

$saidas = escQ1("SELECT COUNT(*) AS n, MAX(data_evento) AS ultima
                   FROM `5c_planning`.`fc_pagina_eventos`
                  WHERE tipo = 'SAIDA' AND status <> 'CANCELADA' AND data_evento >= CURDATE() - INTERVAL 7 DAY");

// Estrutura nova: buffer de plantão por unidade e turno (mesma fonte de Horas extras e Buffers).
$necessidade = $legado ? [] : escQ("SELECT data_calculo, unidade, turno, presentes_dia, taxa_abs, taxa_af FROM `5c_planning`.`fc_necessidade_estrutura` WHERE data_calculo = (SELECT MAX(data_calculo) FROM `5c_planning`.`fc_necessidade_estrutura`) ORDER BY unidade, FIELD(turno, 'A', 'C', 'D')");
$buffers = !$legado ? [] : escQ("SELECT data_calculo, dia, a, c, d, total, taxa_abs_a, taxa_abs_c, taxa_abs_d
                   FROM `5c_planning`.`fc_buffer_diario`
                  WHERE data_calculo = (SELECT MAX(data_calculo) FROM `5c_planning`.`fc_buffer_diario`)
                  ORDER BY dia LIMIT 8");

$ultimaVerificacao = escQ1("SELECT valor FROM " . escT('esc_parametro') . " WHERE chave = 'motor_ultima_verificacao'")['valor'] ?? null;
$segAtual = segundaDaSemana(date('Y-m-d'));
$semanasPainel = [$segAtual, escMaisDias($segAtual, 7)];
$planosPainel = [];
foreach ($semanasPainel as $s) {
    $planosPainel[$s] = escPlanoAtual($s);
}
$pend = escQ1('SELECT (SELECT COUNT(*) FROM ' . escT('esc_indisponibilidade') . " WHERE status = 'PENDENTE') AS indisp,
                      (SELECT COUNT(*) FROM " . escT('esc_estrutura_pendencia') . " WHERE status IN ('ABERTA','ATRASADA','DIVERGENTE')) AS planner,
                      (SELECT COUNT(*) FROM " . escT('esc_saida_checagem') . " WHERE decisao <> 'PENDENTE') AS saidas_conferidas");
?>
<header class="cabecalho">
  <span class="eyebrow"><?php echo h(t('Escala')); ?></span>
  <h1><?php echo h(t('Painel')); ?></h1>
  <p class="muted"><?php echo h(t('O que precisa de atenção agora. Números lidos do Planner, do Capacity e do CRM.')); ?></p>
</header>
<?php $acoesAgora = escAcoesNecessarias(); if ($acoesAgora): ?>
<section class="panel" id="acoes-necessarias">
  <div class="linha-titulo"><h2><?php echo h(t('Ações necessárias agora')); ?></h2><a class="small" href="<?php echo h(link_tela('tutorial')); ?>#acoes-necessarias"><?php echo h(t('Ver no Tutorial')); ?></a></div>
  <div class="colunas-acoes">
    <?php foreach (['plataforma' => t('Na plataforma'), 'site' => t('No site 5C')] as $onde => $rot): $lista = array_filter($acoesAgora, static fn($a) => $a['onde'] === $onde); ?>
    <div><h3><?php echo h($rot); ?></h3><?php if ($lista): ?><ul class="regras"><?php foreach ($lista as $a): ?><li><a href="<?php echo h($a['link']); ?>"><?php echo h($a['texto']); ?></a></li><?php endforeach; ?></ul><?php else: ?><p class="muted small"><?php echo h(t('Nada pendente.')); ?></p><?php endif; ?></div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="panel">
  <div class="linha-titulo">
    <h2><?php echo h(t('Mudanças nas entradas')); ?></h2>
    <span class="muted small"><?php echo h(t('última verificação: {quando}', ['quando' => $ultimaVerificacao ? escDataHora($ultimaVerificacao) : t('nunca')])); ?></span>
  </div>
  <p class="muted small"><?php echo h(t('Confere se o Capacity, a estrutura, as páginas, os colaboradores, os pedidos aprovados ou os modelos mudaram desde a versão atual de cada semana em aberto. Se mudou, recalcula o rascunho (nova subversão) mantendo os ajustes travados.')); ?></p>
  <?php if (escPode('planejar')): ?>
  <form method="post" action="<?php echo h(link_tela('painel')); ?>" class="acoes" data-longo="<?php echo h(t('Verificando e recalculando… pode levar até 3 min.')); ?>">
    <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="verificar">
    <button type="submit" class="botao"><?php echo h(t('Verificar mudanças')); ?></button>
  </form>
  <?php endif; ?>
</section>

<section class="kpis">
  <?php foreach ($planosPainel as $s => $pl): $r = $pl ? (json_decode((string) $pl['resumo'], true) ?: []) : []; ?>
  <a class="kpi" href="<?php echo h($pl ? link_tela('grade', ['plano' => $pl['id']]) : link_tela('gerar', ['semana' => $s])); ?>">
    <span class="muted"><?php echo h(t('Escala de {de} a {ate}', ['de' => dataBr($s), 'ate' => dataBr(escMaisDias($s, 6))])); ?></span>
    <strong><?php echo $pl ? h(escPlanoRotulo($pl) . ' · ' . escRotulo('status_plano', $pl['status'])) : h(t('sem rascunho')); ?></strong>
    <span class="muted small"><?php echo $pl ? h(tn((int) ($r['lacunas'] ?? 0), '{n} lacuna', '{n} lacunas') . ' · ' . tn((int) ($r['anomalias'] ?? 0), '{n} anomalia', '{n} anomalias')) : h(t('clique para gerar')); ?></span>
  </a>
  <?php endforeach; ?>
  <a class="kpi" href="<?php echo h(link_tela('aprovar')); ?>">
    <span class="muted"><?php echo h(t('Pedidos a aprovar')); ?></span>
    <strong class="<?php echo (int) ($pend['indisp'] ?? 0) ? 'laranja' : ''; ?>"><?php echo (int) ($pend['indisp'] ?? 0); ?></strong>
    <span class="muted small"><?php echo h(t('indisponibilidade')); ?></span>
  </a>
  <?php if ($legado || (int) ($pend['planner'] ?? 0)): ?>
  <a class="kpi" href="<?php echo h(link_tela('times')); ?>#pendencias">
    <span class="muted"><?php echo h(t('Pendências no Planner')); ?></span>
    <strong><?php echo (int) ($pend['planner'] ?? 0); ?></strong>
    <span class="muted small"><?php echo h(t('abertas, atrasadas ou divergentes · estrutura antiga')); ?></span>
  </a>
  <?php endif; ?>
</section>

<section class="kpis">
  <?php if ($legado): ?>
  <a class="kpi" href="<?php echo h(link_tela('times')); ?>">
    <span class="muted"><?php echo h(t('Parties fora da regra (estrutura antiga, até {dia})', ['dia' => escData('2026-10-31')])); ?></span>
    <strong class="laranja"><?php echo h(t('{n} de {total}', ['n' => count($foraDaRegra), 'total' => count($parties)])); ?></strong>
    <span class="muted small"><?php echo h(t('{n} bloqueiam a geração (sem líder ou sem página)', ['n' => count($bloqueantes)])); ?></span>
  </a>
  <?php endif; ?>
  <a class="kpi" href="<?php echo h(link_tela('trocas')); ?>">
    <span class="muted"><?php echo h(t('Trocas de página previstas')); ?></span>
    <strong><?php echo (int) ($trocas['n'] ?? 0); ?></strong>
    <span class="muted small"><?php echo !empty($trocas['proxima']) ? h(t('próxima: {dia} {hora}', ['dia' => dataBr($trocas['proxima'], true), 'hora' => substr($trocas['proxima'], 11, 5)])) : h(t('nenhuma ainda não aplicada no Planner')); ?></span>
  </a>
  <a class="kpi" href="<?php echo h(link_tela('saidas')); ?>">
    <span class="muted"><?php echo h(t('Saídas de página (7 dias)')); ?></span>
    <strong class="azul"><?php echo (int) ($saidas['n'] ?? 0); ?></strong>
    <span class="muted small"><?php echo !empty($saidas['ultima']) ? h(t('última em {dia}', ['dia' => dataBr($saidas['ultima'])])) : '—'; ?> · <?php echo h(t('conferir antes de aplicar')); ?></span>
  </a>
  <a class="kpi" href="<?php echo h(link_tela('funil')); ?>">
    <span class="muted"><?php echo h(t('Funil de entrada')); ?></span>
    <strong>CRM</strong>
    <span class="muted small"><?php echo h(t('páginas previstas e arquétipos')); ?></span>
  </a>
</section>

<?php if (!$legado): ?>
<section class="panel">
  <div class="linha-titulo">
    <h2><?php echo h(t('Buffer de plantão pelo Capacity')); ?></h2>
    <span class="muted small"><?php echo h(t('cálculo de {dia}', ['dia' => dataBr($necessidade[0]['data_calculo'] ?? null)])); ?></span>
  </div>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th><?php echo h(t('Unidade')); ?></th><th><?php echo h(t('Turno')); ?></th><th class="num"><?php echo h(t('Presentes por dia')); ?></th><th class="num"><?php echo h(t('Absenteísmo')); ?></th><th class="num"><?php echo h(t('Afastamento')); ?></th></tr></thead>
    <tbody>
    <?php foreach ($necessidade as $n): ?>
      <tr><td>U<?php echo (int) $n['unidade']; ?></td><td><?php echo chipTurno($n['turno']); ?></td><td class="num"><?php echo (int) ceil((float) $n['presentes_dia'] - 1e-9); ?></td><td class="num"><?php echo pct($n['taxa_abs']); ?></td><td class="num"><?php echo pct($n['taxa_af']); ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$necessidade): ?><tr><td colspan="5" class="muted"><?php echo h(t('Sem cálculo de necessidade no Capacity.')); ?></td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <p class="muted small"><?php echo t('Quantos buffers de plantão cada unidade e turno precisa por dia, já com absenteísmo. A semana planejada × o pedido fica em {link}.', ['link' => '<a href="' . h(link_tela('buffers')) . '">' . h(t('Buffers')) . '</a>']); ?></p>
</section>
<?php else: ?>
<section class="panel">
  <div class="linha-titulo">
    <h2><?php echo h(t('Buffers pelo Capacity')); ?></h2>
    <span class="muted small"><?php echo h(t('cálculo de {dia}', ['dia' => dataBr($buffers[0]['data_calculo'] ?? null)])); ?></span>
  </div>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th><?php echo h(t('Dia')); ?></th><th class="num">A</th><th class="num">C</th><th class="num">D</th><th class="num"><?php echo h(t('Total')); ?></th><th class="num">ABS A</th><th class="num">ABS C</th><th class="num">ABS D</th></tr></thead>
    <tbody>
    <?php foreach ($buffers as $b): ?>
      <tr><td><?php echo h(dataBr($b['dia'], true)); ?></td>
        <td class="num"><?php echo (int) $b['a']; ?></td><td class="num"><?php echo (int) $b['c']; ?></td><td class="num"><?php echo (int) $b['d']; ?></td>
        <td class="num"><strong><?php echo (int) $b['total']; ?></strong></td>
        <td class="num"><?php echo pct($b['taxa_abs_a']); ?></td><td class="num"><?php echo pct($b['taxa_abs_c']); ?></td><td class="num"><?php echo pct($b['taxa_abs_d']); ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$buffers): ?><tr><td colspan="8" class="muted"><?php echo h(t('Sem cálculo de buffers no Capacity.')); ?></td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <p class="muted small"><?php echo h(t('Buffers necessários por dia e turno, já com absenteísmo (ABS + AF). Se as taxas parecerem baixas, confira se o cálculo diário do Capacity rodou depois da correção da AF.')); ?></p>
</section>
<?php endif; ?>
