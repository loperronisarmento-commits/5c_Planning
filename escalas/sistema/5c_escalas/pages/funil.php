<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/funil.php
// Funil de entrada: cards do CRM (Pipeline, Onboarding e Active da
// última semana) e os arquétipos usados para estimar o volume de uma
// página nova a partir do tier (faturamento) e do perfil.
// ===============================================

// Ajuste de previsão e destino (5c_escalas.esc_pagina_previsao); o CRM não muda.
if (escPost()) {
    escExigir('decidir');
    $card = (int) ($_POST['crm_card_id'] ?? 0);
    if ($card <= 0) {
        throw new RuntimeException('Card inválido.');
    }
    $num = static fn($k) => ($_POST[$k] ?? '') === '' ? null : (float) str_replace(',', '.', (string) $_POST[$k]);
    $perfil = in_array($_POST['perfil'] ?? '', ['MAIN', 'VIP', 'FREE', 'OFTV'], true) ? $_POST['perfil'] : null;
    $base = in_array($_POST['base'] ?? '', ['ARQUETIPO', 'REFERENCIA', 'INFORMADO'], true) ? $_POST['base'] : 'ARQUETIPO';
    $data = preg_match('~^\d{4}-\d{2}-\d{2}$~', (string) ($_POST['data_prevista'] ?? '')) ? $_POST['data_prevista'] : null;
    $prob = $num('probabilidade');
    $timeDaParty = array_column(escFormacaoParties(), 'team', 'party');
    $_POST['destino_time'] = $timeDaParty[$_POST['destino_party'] ?? ''] ?? '';
    escW('INSERT INTO ' . escT('esc_pagina_previsao') . ' (crm_card_id, pagina, perfil, base, pagina_referencia, fans_h_a, fans_h_c, fans_h_d, probabilidade, data_prevista, destino_time, destino_party, destino_motivo, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) AS n
          ON DUPLICATE KEY UPDATE pagina = n.pagina, perfil = n.perfil, base = n.base, pagina_referencia = n.pagina_referencia, fans_h_a = n.fans_h_a, fans_h_c = n.fans_h_c, fans_h_d = n.fans_h_d, probabilidade = n.probabilidade, data_prevista = n.data_prevista, destino_time = n.destino_time, destino_party = n.destino_party, destino_motivo = n.destino_motivo, atualizado_por = n.criado_por', [
        $card, mb_substr(trim((string) ($_POST['pagina'] ?? '')), 0, 150) ?: null, $perfil, $base,
        mb_substr(trim((string) ($_POST['pagina_referencia'] ?? '')), 0, 150) ?: null,
        $num('fans_h_a'), $num('fans_h_c'), $num('fans_h_d'), $prob === null ? null : max(0, min(1, $prob > 1 ? $prob / 100 : $prob)), $data,
        mb_substr(trim((string) ($_POST['destino_time'] ?? '')), 0, 100) ?: null, mb_substr(trim((string) ($_POST['destino_party'] ?? '')), 0, 100) ?: null,
        mb_substr(trim((string) ($_POST['destino_motivo'] ?? '')), 0, 255) ?: null, escUserId(),
    ]);
    escLog('funil.previsao', 'esc_pagina_previsao', $card, null, array_diff_key($_POST, ['csrf' => 1]));
    escVoltar('funil', [], 'Previsão do card #' . $card . ' salva (o CRM não foi alterado).');
}

$cards = escQ("SELECT pc.id, st.slug, st.name AS etapa, c.name AS cliente, t.letter AS tier,
                      t.min_value, t.max_value, ct.start_date, ct.value, ct.currency, ct.status AS contrato
                 FROM `5c_crm`.`pipeline_cards` pc
                 JOIN `5c_crm`.`pipeline_stages` st ON st.id = pc.stage_id
                 LEFT JOIN `5c_crm`.`clients` c ON c.id = pc.client_id
                 LEFT JOIN `5c_crm`.`tiers` t ON t.id = c.tier_id
                 LEFT JOIN `5c_crm`.`contracts` ct ON ct.id = pc.contract_id
                WHERE st.slug IN ('pipeline', 'onboarding')
                   OR (st.slug = 'active' AND ct.start_date >= CURDATE() - INTERVAL 7 DAY)
                ORDER BY st.position, ct.start_date");

// Arquétipos: tier real (tab_intraday_base) × perfil, fãs/h dos últimos 28 dias.
$arquetipos = escQ("WITH tp AS (
                        SELECT Page COLLATE utf8mb4_unicode_ci AS pagina, MAX(Tier) AS tier
                          FROM `5c_wfm_db_mis`.`tab_intraday_base`
                         WHERE `Date` >= CURDATE() - INTERVAL 8 DAY AND `Date` < CURDATE() AND Tier IS NOT NULL
                         GROUP BY Page
                    ),
                    pt AS (
                        SELECT pagina COLLATE utf8mb4_unicode_ci AS pagina, turno, AVG(fans) AS fh, COUNT(DISTINCT dia_turno) AS dias
                          FROM `5c_planning`.`fc_fans_pagina_hora`
                         WHERE dia_turno >= CURDATE() - INTERVAL 29 DAY AND dia_turno < CURDATE() - INTERVAL 1 DAY
                         GROUP BY pagina, turno
                    ),
                    j AS (
                        SELECT tp.tier,
                               CASE WHEN tp.pagina LIKE '%VIP%' THEN 'VIP'
                                    WHEN tp.pagina LIKE '%free%' THEN 'Free'
                                    WHEN tp.pagina LIKE '%OFTV%' THEN 'OFTV'
                                    ELSE 'Main' END AS perfil,
                               tp.pagina, pt.turno, pt.fh
                          FROM tp JOIN pt ON pt.pagina = tp.pagina
                         WHERE pt.dias >= 14
                    ),
                    pg AS (
                        SELECT tier, perfil, pagina, AVG(fh) AS fh FROM j GROUP BY tier, perfil, pagina
                    )
                    SELECT j.tier, j.perfil, COUNT(DISTINCT j.pagina) AS paginas,
                           AVG(IF(j.turno = 'A', j.fh, NULL)) AS a,
                           AVG(IF(j.turno = 'C', j.fh, NULL)) AS c,
                           AVG(IF(j.turno = 'D', j.fh, NULL)) AS d,
                           (SELECT MIN(fh) FROM pg WHERE pg.tier = j.tier AND pg.perfil = j.perfil) AS minimo,
                           (SELECT MAX(fh) FROM pg WHERE pg.tier = j.tier AND pg.perfil = j.perfil) AS maximo
                      FROM j
                     GROUP BY j.tier, j.perfil
                     ORDER BY FIELD(j.tier, 'SS', 'S', 'A', 'B', 'C', 'D', 'E', 'F'), j.perfil");

$previsoes = [];
foreach (escQ('SELECT * FROM ' . escT('esc_pagina_previsao')) as $pv) {
    $previsoes[(int) $pv['crm_card_id']] = $pv;
}
$partiesNomes = array_column(escFormacaoParties(), 'party', 'party');
$porEtapa = [];
foreach ($cards as $c) {
    $porEtapa[$c['etapa']][] = $c;
}
$confianca = static function (int $n): array {
    return $n >= 5 ? ['Alta', 'verde'] : ($n >= 3 ? ['Média', 'amarelo'] : ['Baixa', 'vermelho']);
};
?>
<header class="cabecalho">
  <span class="eyebrow">Páginas</span>
  <h1>Funil de entrada</h1>
  <p class="muted">Espelha o funil do CRM. Como o CRM normalmente só traz o tier, o volume de cada página nova vem do arquétipo: páginas ativas com faturamento e perfil parecidos.</p>
</header>

<section class="panel">
  <div class="linha-titulo"><h2>Cards do CRM · <?php echo count($cards); ?></h2><span class="muted small">Pipeline, Onboarding e Active desde 7 dias atrás</span></div>
  <?php if (!$cards): ?><p class="muted">Nenhum card no funil.</p><?php endif; ?>
  <div class="colunas-funil">
    <?php foreach ($porEtapa as $etapa => $lista): ?>
    <div class="coluna">
      <strong><?php echo h($etapa); ?> · <?php echo count($lista); ?></strong>
      <?php foreach ($lista as $c): ?>
      <div class="cartao">
        <div class="linha-titulo"><strong><?php echo h($c['cliente'] ?: 'sem cliente'); ?></strong><span class="tag"><?php echo h($c['tier'] ?: 'sem tier'); ?></span></div>
        <span class="muted small">CRM card #<?php echo (int) $c['id']; ?><?php echo $c['start_date'] ? ' · início ' . h(dataBr($c['start_date'])) : ''; ?><?php echo $c['contrato'] ? ' · contrato ' . h($c['contrato']) : ''; ?></span>
        <?php if (!$c['tier'] || !$c['start_date']): ?><span class="tag laranja">dados faltando no CRM: <?php echo h(implode(' e ', array_filter([!$c['tier'] ? 'tier' : '', !$c['start_date'] ? 'data de início' : '']))); ?></span><?php endif; ?>
        <?php $pv = $previsoes[(int) $c['id']] ?? null; if ($pv): ?>
          <span class="small">Previsão: <?php echo h(strtolower((string) $pv['perfil'])); ?> · <?php echo h(strtolower($pv['base'])); ?> · A <?php echo num($pv['fans_h_a']); ?> / C <?php echo num($pv['fans_h_c']); ?> / D <?php echo num($pv['fans_h_d']); ?> fãs/h<?php echo $pv['destino_party'] ? ' · destino ' . h($pv['destino_party']) : ''; ?></span>
        <?php endif; ?>
        <?php if (escPode('decidir')): ?>
        <details><summary class="botao">Ajustar previsão e destino</summary>
          <form method="post" action="<?php echo h(link_tela('funil')); ?>" class="form-grade">
            <?php echo campoCsrf(); ?><input type="hidden" name="crm_card_id" value="<?php echo (int) $c['id']; ?>">
            <label>Página<input type="search" name="pagina" list="lista-paginas" autocomplete="off" value="<?php echo h($pv['pagina'] ?? $c['cliente']); ?>"></label>
            <label>Perfil<select name="perfil"><?php foreach (['MAIN' => 'Main', 'VIP' => 'VIP', 'FREE' => 'Free', 'OFTV' => 'OFTV'] as $k => $r): ?><option value="<?php echo $k; ?>" <?php echo ($pv['perfil'] ?? 'MAIN') === $k ? 'selected' : ''; ?>><?php echo $r; ?></option><?php endforeach; ?></select></label>
            <label>Base<select name="base"><option value="ARQUETIPO">Arquétipo</option><option value="REFERENCIA" <?php echo ($pv['base'] ?? '') === 'REFERENCIA' ? 'selected' : ''; ?>>Página de referência</option><option value="INFORMADO" <?php echo ($pv['base'] ?? '') === 'INFORMADO' ? 'selected' : ''; ?>>Fãs/h informados</option></select></label>
            <label>Página de referência<input type="search" name="pagina_referencia" list="lista-paginas" autocomplete="off" value="<?php echo h($pv['pagina_referencia'] ?? ''); ?>"></label>
            <label>Fãs/h A<input type="text" name="fans_h_a" value="<?php echo h($pv['fans_h_a'] ?? ''); ?>"></label>
            <label>Fãs/h C<input type="text" name="fans_h_c" value="<?php echo h($pv['fans_h_c'] ?? ''); ?>"></label>
            <label>Fãs/h D<input type="text" name="fans_h_d" value="<?php echo h($pv['fans_h_d'] ?? ''); ?>"></label>
            <label>Probabilidade (%)<input type="text" name="probabilidade" value="<?php echo $pv && $pv['probabilidade'] !== null ? h((float) $pv['probabilidade'] * 100) : ''; ?>"></label>
            <label>Data prevista<input type="date" name="data_prevista" value="<?php echo h($pv['data_prevista'] ?? ($c['start_date'] ? substr($c['start_date'], 0, 10) : '')); ?>"></label>
            <label>Destino · party<select name="destino_party"><option value="">(definir depois)</option><?php foreach ($partiesNomes as $pn): ?><option <?php echo ($pv['destino_party'] ?? '') === $pn ? 'selected' : ''; ?>><?php echo h($pn); ?></option><?php endforeach; ?></select></label>
            <label>Motivo do destino<input type="text" name="destino_motivo" value="<?php echo h($pv['destino_motivo'] ?? ''); ?>"></label>
            <button type="submit" class="botao primario">Salvar</button>
          </form>
        </details>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<section class="panel">
  <div class="linha-titulo"><h2>Arquétipos de página</h2><span class="muted small">fãs/h médios dos últimos 28 dias · tier de tab_intraday_base</span></div>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th>Arquétipo</th><th class="num">Páginas</th><th class="num">A</th><th class="num">C</th><th class="num">D</th><th>Faixa por página</th><th>Confiança</th></tr></thead>
    <tbody>
    <?php foreach ($arquetipos as $a): [$conf, $cor] = $confianca((int) $a['paginas']); ?>
      <tr>
        <td><strong><?php echo h($a['tier'] . ' · ' . $a['perfil']); ?></strong></td>
        <td class="num"><?php echo (int) $a['paginas']; ?></td>
        <td class="num"><?php echo num($a['a']); ?></td><td class="num"><?php echo num($a['c']); ?></td><td class="num"><?php echo num($a['d']); ?></td>
        <td class="small"><?php echo num($a['minimo']); ?> a <?php echo num($a['maximo']); ?></td>
        <td><span class="tag <?php echo $cor; ?>"><?php echo $conf; ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="muted small">Com menos de 3 páginas, a estimativa junta os perfis do mesmo tier; sem nenhuma, interpola entre os tiers vizinhos. O tier de vw_pagina está quase todo como C (padrão do cadastro), por isso o tier vem de tab_intraday_base.</p>
</section>
