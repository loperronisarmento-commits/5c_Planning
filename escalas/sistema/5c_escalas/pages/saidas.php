<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/saidas.php
// Saídas de página detectadas pelo Capacity (fc_pagina_eventos) com o
// efeito calculado (fc_impacto_paginas). Na versão final cada saída
// passa por checagem antes de entrar na escala; aqui só mostra.
// ===============================================

$dias = (int) ($_GET['dias'] ?? 21);
if (!in_array($dias, [7, 14, 21, 45], true)) {
    $dias = 21;
}

$saidas = escQ("SELECT e.id, e.pagina, e.page_id, e.data_evento, e.origem, e.status,
                       i.reps_h_a, i.reps_h_c, i.reps_h_d, i.hc_total
                  FROM `5c_planning`.`fc_pagina_eventos` e
                  LEFT JOIN `5c_planning`.`fc_impacto_paginas` i
                    ON i.ref_id = e.id AND i.tipo = 'SAIDA'
                   AND i.data_calculo = (SELECT MAX(data_calculo) FROM `5c_planning`.`fc_impacto_paginas`)
                 WHERE e.tipo = 'SAIDA' AND e.data_evento >= CURDATE() - INTERVAL {$dias} DAY
                 ORDER BY e.data_evento DESC, e.pagina");

$soma = 0.0;
$somaHc = 0.0;
foreach ($saidas as $s) {
    if ($s['status'] !== 'CANCELADA' && $s['reps_h_a'] !== null) {
        $soma += ((float) $s['reps_h_a'] + (float) $s['reps_h_c'] + (float) $s['reps_h_d']) / 3;
        $somaHc += (float) $s['hc_total'];
    }
}
?>
<header class="cabecalho">
  <span class="eyebrow">Páginas</span>
  <h1>Saídas de página</h1>
  <p class="muted">Saídas que o Capacity detectou (página inativada no cadastro ou sem escala há 3 dias) e quanto cada uma tira da necessidade. Na versão com gravação, cada saída precisa ser confirmada por quem opera o sistema antes de entrar na escala.</p>
</header>

<section class="kpis">
  <div class="kpi"><span class="muted">Saídas no período</span><strong><?php echo count($saidas); ?></strong><span class="muted small">últimos <?php echo $dias; ?> dias</span></div>
  <div class="kpi"><span class="muted">Efeito por turno</span><strong class="azul"><?php echo num($soma, 2); ?> reps/h</strong><span class="muted small">média de A, C e D</span></div>
  <div class="kpi"><span class="muted">Efeito em pessoas</span><strong class="azul"><?php echo num($somaHc, 1); ?></strong><span class="muted small">com presença 75% e absenteísmo</span></div>
</section>

<section class="panel">
  <div class="linha-titulo">
    <h2>Saídas detectadas</h2>
    <div class="chips">
      <?php foreach ([7, 14, 21, 45] as $d): ?><a class="chip-filtro <?php echo $d === $dias ? 'ativo' : ''; ?>" href="<?php echo h(link_tela('saidas', ['dias' => $d])); ?>"><?php echo $d; ?> dias</a><?php endforeach; ?>
    </div>
  </div>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th>Data</th><th>Página</th><th>Origem</th><th class="num">A</th><th class="num">C</th><th class="num">D</th><th class="num">Pessoas</th><th>Situação</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($saidas as $s): ?>
      <tr>
        <td><?php echo h(dataBr($s['data_evento'], true)); ?></td>
        <td><strong><?php echo h($s['pagina']); ?></strong></td>
        <td class="small"><?php echo $s['origem'] === 'AUTO' ? 'detecção automática' : h(strtolower($s['origem'])); ?></td>
        <td class="num"><?php echo num($s['reps_h_a'], 2); ?></td>
        <td class="num"><?php echo num($s['reps_h_c'], 2); ?></td>
        <td class="num"><?php echo num($s['reps_h_d'], 2); ?></td>
        <td class="num"><?php echo num($s['hc_total'], 1); ?></td>
        <td><span class="tag <?php echo $s['status'] === 'CANCELADA' ? '' : 'laranja'; ?>"><?php echo $s['status'] === 'CANCELADA' ? 'Cancelada' : 'A conferir'; ?></span></td>
        <td class="nowrap"><button type="button" class="botao" disabled title="Fase com gravação">Confirmar</button> <button type="button" class="botao" disabled title="Fase com gravação">Não saiu</button></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$saidas): ?><tr><td colspan="9" class="muted">Nenhuma saída no período.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <p class="muted small">O que conferir: a página está como churn ou cancelada no CRM? Saiu do Planner? Não é só uma pausa? Efeito vazio = o Capacity ainda não calculou o impacto dessa saída.</p>
</section>
