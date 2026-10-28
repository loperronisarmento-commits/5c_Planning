<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/saidas.php
// Saídas de página detectadas pelo Capacity (fc_pagina_eventos) com o
// efeito calculado (fc_impacto_paginas) e a checagem de quem opera o
// sistema (5c_escalas.esc_saida_checagem).
// ===============================================

if (escPost()) {
    escExigir('decidir');
    $evento = (int) ($_POST['evento_id'] ?? 0);
    $decisao = (string) ($_POST['decisao'] ?? '');
    if (!in_array($decisao, ['CONFIRMADA', 'NAO_SAIU', 'PENDENTE'], true)) {
        throw new RuntimeException('Decisão inválida.');
    }
    $e = escQ1("SELECT e.id, e.pagina, e.page_id, e.data_evento, i.reps_h_a, i.reps_h_c, i.reps_h_d, i.hc_total
                  FROM `5c_planning`.`fc_pagina_eventos` e
                  LEFT JOIN `5c_planning`.`fc_impacto_paginas` i ON i.ref_id = e.id AND i.tipo = 'SAIDA'
                   AND i.data_calculo = (SELECT MAX(data_calculo) FROM `5c_planning`.`fc_impacto_paginas`)
                 WHERE e.id = ? AND e.tipo = 'SAIDA'", [$evento]);
    if (!$e) {
        throw new RuntimeException('Saída não encontrada.');
    }
    $efeito = $e['reps_h_a'] === null ? null : round(((float) $e['reps_h_a'] + (float) $e['reps_h_c'] + (float) $e['reps_h_d']) / 3, 2);
    $dias = (int) (escQ1('SELECT valor FROM ' . escT('esc_parametro') . " WHERE chave = 'saida_rechecar_d'")['valor'] ?? 3);
    escW('INSERT INTO ' . escT('esc_saida_checagem') . ' (evento_id, page_id, pagina, data_evento, efeito_reps_turno, efeito_pessoas, decisao, observacao, rechecar_em, decidido_em, decidido_por)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?) AS n
          ON DUPLICATE KEY UPDATE decisao = n.decisao, observacao = n.observacao, rechecar_em = n.rechecar_em, decidido_em = n.decidido_em, decidido_por = n.decidido_por', [
        $evento, $e['page_id'], $e['pagina'], substr($e['data_evento'], 0, 10), $efeito, $e['hc_total'], $decisao,
        mb_substr(trim((string) ($_POST['observacao'] ?? '')), 0, 500) ?: null,
        $decisao === 'NAO_SAIU' ? date('Y-m-d', strtotime('+' . $dias . ' day')) : null, escUserId(),
    ]);
    escLog('saida.checar', 'esc_saida_checagem', $evento, null, ['pagina' => $e['pagina'], 'decisao' => $decisao]);
    escVoltar('saidas', ['dias' => $_GET['dias'] ?? 21], $e['pagina'] . ': ' . ($decisao === 'CONFIRMADA' ? 'saída confirmada.' : ($decisao === 'NAO_SAIU' ? 'marcada como "não saiu"; nova checagem em ' . $dias . ' dias.' : 'voltou para "a conferir".')));
}

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
$checagem = [];
foreach (escQ('SELECT evento_id, decisao, observacao, rechecar_em, decidido_em, decidido_por FROM ' . escT('esc_saida_checagem')) as $c) {
    $checagem[(int) $c['evento_id']] = $c;
}

$soma = 0.0;
$somaHc = 0.0;
$pendentes = 0;
foreach ($saidas as $s) {
    $ch = $checagem[(int) $s['id']]['decisao'] ?? 'PENDENTE';
    if ($ch === 'PENDENTE' && $s['status'] !== 'CANCELADA') {
        $pendentes++;
    }
    if ($s['status'] !== 'CANCELADA' && $s['reps_h_a'] !== null && $ch !== 'NAO_SAIU') {
        $soma += ((float) $s['reps_h_a'] + (float) $s['reps_h_c'] + (float) $s['reps_h_d']) / 3;
        $somaHc += (float) $s['hc_total'];
    }
}
?>
<header class="cabecalho">
  <span class="eyebrow">Páginas</span>
  <h1>Saídas de página</h1>
  <p class="muted">Saídas que o Capacity detectou (página inativada no cadastro ou sem escala há 3 dias) e quanto cada uma tira da necessidade. Quem opera o sistema confere cada uma: confirmar ou "não saiu".</p>
</header>

<section class="kpis">
  <div class="kpi"><span class="muted">A conferir</span><strong class="<?php echo $pendentes ? 'laranja' : ''; ?>"><?php echo $pendentes; ?></strong><span class="muted small">de <?php echo count($saidas); ?> saídas nos últimos <?php echo $dias; ?> dias</span></div>
  <div class="kpi"><span class="muted">Efeito por turno</span><strong class="azul"><?php echo num($soma, 2); ?> reps/h</strong><span class="muted small">sem contar as marcadas "não saiu"</span></div>
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
    <thead><tr><th>Data</th><th>Página</th><th class="num">A</th><th class="num">C</th><th class="num">D</th><th class="num">Pessoas</th><th>Checagem</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($saidas as $s): $ch = $checagem[(int) $s['id']] ?? null; $dec = $ch['decisao'] ?? 'PENDENTE'; ?>
      <tr>
        <td><?php echo h(dataBr($s['data_evento'], true)); ?></td>
        <td><strong><?php echo h($s['pagina']); ?></strong><br><span class="muted small"><?php echo $s['origem'] === 'AUTO' ? 'detecção automática' : h(strtolower($s['origem'])); ?></span></td>
        <td class="num"><?php echo num($s['reps_h_a'], 2); ?></td>
        <td class="num"><?php echo num($s['reps_h_c'], 2); ?></td>
        <td class="num"><?php echo num($s['reps_h_d'], 2); ?></td>
        <td class="num"><?php echo num($s['hc_total'], 1); ?></td>
        <td>
          <span class="tag <?php echo $dec === 'CONFIRMADA' ? 'verde' : ($dec === 'NAO_SAIU' ? '' : 'laranja'); ?>"><?php echo $dec === 'CONFIRMADA' ? 'Saída confirmada' : ($dec === 'NAO_SAIU' ? 'Não saiu' : 'A conferir'); ?></span>
          <?php if ($ch && $ch['decidido_em']): ?><br><span class="muted small"><?php echo h(date('d/m H:i', strtotime($ch['decidido_em']))); ?><?php echo $ch['rechecar_em'] ? ' · rechecar em ' . h(dataBr($ch['rechecar_em'])) : ''; ?><?php echo $ch['observacao'] ? ' · ' . h($ch['observacao']) : ''; ?></span><?php endif; ?>
        </td>
        <td class="nowrap">
          <?php if (escPode('decidir')): ?>
          <form method="post" action="<?php echo h(link_tela('saidas', ['dias' => $dias])); ?>" class="acoes">
            <?php echo campoCsrf(); ?><input type="hidden" name="evento_id" value="<?php echo (int) $s['id']; ?>">
            <input type="text" name="observacao" placeholder="observação" size="12" onkeydown="if (event.key === 'Enter') { event.preventDefault(); }">
            <?php if ($dec !== 'CONFIRMADA'): ?><button type="submit" name="decisao" value="CONFIRMADA" class="botao primario">Confirmar</button><?php endif; ?>
            <?php if ($dec !== 'NAO_SAIU'): ?><button type="submit" name="decisao" value="NAO_SAIU" class="botao">Não saiu</button><?php endif; ?>
            <?php if ($dec !== 'PENDENTE'): ?><button type="submit" name="decisao" value="PENDENTE" class="botao">Desfazer</button><?php endif; ?>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$saidas): ?><tr><td colspan="8" class="muted">Nenhuma saída no período.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <p class="muted small">O que conferir: a página está como churn ou cancelada no CRM? Saiu do Planner? Não é só uma pausa? A decisão fica registrada (quem e quando). Nesta versão a necessidade da escala segue o Capacity; "não saiu" serve de alerta para corrigir o Capacity.</p>
</section>
