<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/funil.php
// Funil de entrada: cards do CRM (Pipeline, Onboarding e Active da
// última semana) e os arquétipos usados para estimar o volume de uma
// página nova a partir do tier (faturamento) e do perfil.
// ===============================================

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
