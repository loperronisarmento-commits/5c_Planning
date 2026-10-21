<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/painel.php
// Painel: o que precisa de atenção, com dados reais.
// ===============================================

$parties = escFormacaoParties();
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

$buffers = escQ("SELECT data_calculo, dia, a, c, d, total, taxa_abs_a, taxa_abs_c, taxa_abs_d
                   FROM `5c_planning`.`fc_buffer_diario`
                  WHERE data_calculo = (SELECT MAX(data_calculo) FROM `5c_planning`.`fc_buffer_diario`)
                  ORDER BY dia LIMIT 8");
?>
<header class="cabecalho">
  <span class="eyebrow">Planejamento</span>
  <h1>Painel</h1>
  <p class="muted">O que precisa de atenção agora. Números lidos do Planner, do Capacity e do CRM.</p>
</header>

<section class="kpis">
  <a class="kpi" href="<?php echo h(link_tela('times')); ?>">
    <span class="muted">Parties fora da regra</span>
    <strong class="laranja"><?php echo count($foraDaRegra); ?> de <?php echo count($parties); ?></strong>
    <span class="muted small"><?php echo count($bloqueantes); ?> bloqueiam a geração (sem Primaris ou sem página)</span>
  </a>
  <a class="kpi" href="<?php echo h(link_tela('trocas')); ?>">
    <span class="muted">Trocas de página previstas</span>
    <strong><?php echo (int) ($trocas['n'] ?? 0); ?></strong>
    <span class="muted small"><?php echo !empty($trocas['proxima']) ? 'próxima: ' . h(dataBr($trocas['proxima'], true)) . ' ' . h(substr($trocas['proxima'], 11, 5)) : 'nenhuma ainda não aplicada no Planner'; ?></span>
  </a>
  <a class="kpi" href="<?php echo h(link_tela('saidas')); ?>">
    <span class="muted">Saídas de página (7 dias)</span>
    <strong class="azul"><?php echo (int) ($saidas['n'] ?? 0); ?></strong>
    <span class="muted small"><?php echo !empty($saidas['ultima']) ? 'última em ' . h(dataBr($saidas['ultima'])) : '—'; ?> · conferir antes de aplicar</span>
  </a>
  <a class="kpi" href="<?php echo h(link_tela('funil')); ?>">
    <span class="muted">Funil de entrada</span>
    <strong>CRM</strong>
    <span class="muted small">páginas previstas e arquétipos</span>
  </a>
</section>

<section class="panel">
  <div class="linha-titulo">
    <h2>Buffers pelo Capacity (fc_buffer_diario)</h2>
    <span class="muted small">cálculo de <?php echo h(dataBr($buffers[0]['data_calculo'] ?? null)); ?></span>
  </div>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th>Dia</th><th class="num">A</th><th class="num">C</th><th class="num">D</th><th class="num">Total</th><th class="num">ABS A</th><th class="num">ABS C</th><th class="num">ABS D</th></tr></thead>
    <tbody>
    <?php foreach ($buffers as $b): ?>
      <tr><td><?php echo h(dataBr($b['dia'], true)); ?></td>
        <td class="num"><?php echo (int) $b['a']; ?></td><td class="num"><?php echo (int) $b['c']; ?></td><td class="num"><?php echo (int) $b['d']; ?></td>
        <td class="num"><strong><?php echo (int) $b['total']; ?></strong></td>
        <td class="num"><?php echo pct($b['taxa_abs_a']); ?></td><td class="num"><?php echo pct($b['taxa_abs_c']); ?></td><td class="num"><?php echo pct($b['taxa_abs_d']); ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$buffers): ?><tr><td colspan="8" class="muted">Sem cálculo de buffers no Capacity.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <p class="muted small">Buffers necessários por dia e turno, já com absenteísmo (ABS + AF). Se as taxas parecerem baixas, confira se o <code>sp_fc_rodar_dia()</code> rodou depois da correção da AF.</p>
</section>
