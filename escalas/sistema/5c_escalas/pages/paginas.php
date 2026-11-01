<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/paginas.php
// Configuração das páginas (esc_pagina_config, ES_57): faturamento de 30 dias de cada página e
// da modelo (o que ordena a escala) e a chave que manda o sistema desconsiderar a página
// (sem SLA a cumprir, carga zero no posto, último lugar na fila). Vale para o motor e para o
// Capacity (script 27 do Capacity).
// ===============================================

$instalada = (bool) escQ1("SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'esc_pagina_config'", [ESC_SCHEMA]);
$modos = ['AUTO' => t('Automático'), 'NORMAL' => t('Sempre normal'), 'DESCONSIDERAR' => t('Desconsiderar')];

if (escPost()) {
    escExigir('planejar');
    if (!$instalada) {
        throw new RuntimeException(t('A configuração das páginas ainda não foi instalada no banco (script ES_57). Peça ao admin.'));
    }
    $pagina = trim((string) ($_POST['pagina'] ?? ''));
    $modo = (string) ($_POST['sla_modo'] ?? '');
    if (!isset($modos[$modo])) {
        throw new RuntimeException(t('Escolha uma opção da lista.'));
    }
    if (!escQ1('SELECT 1 AS ok FROM `5c_pages`.`pages` WHERE active = 1 AND name = ?', [$pagina])) {
        throw new RuntimeException(t('Página não encontrada entre as ativas do site. Recarregue a tela.'));
    }
    $antes = escQ1('SELECT pagina, sla_modo FROM ' . escT('esc_pagina_config') . ' WHERE pagina = ?', [$pagina]);
    escW('INSERT INTO ' . escT('esc_pagina_config') . ' (pagina, sla_modo, atualizado_por) VALUES (?, ?, ?)
          ON DUPLICATE KEY UPDATE sla_modo = VALUES(sla_modo), atualizado_por = VALUES(atualizado_por)', [$pagina, $modo, escUserId() ?: null]);
    escLog('pagina.config', 'esc_pagina_config', $pagina, $antes, ['sla_modo' => $modo]);
    escVoltar('paginas', array_intersect_key($_GET, ['f' => 1]), t('{pagina}: {modo}. Vale a partir do próximo "Gerar" e do próximo cálculo do Capacity.', ['pagina' => $pagina, 'modo' => $modos[$modo]]));
}

// Páginas ativas do site que estão na estrutura (carteira vigente).
$lista = escQ('SELECT p.name AS pagina, p.tier, c.modelo, c.carteira, c.regra
                 FROM `5c_pages`.`pages` p
                 JOIN `5c_planning`.`fc_pagina_carteira` c ON c.pagina = p.name AND c.vigente_ate IS NULL
                WHERE p.active = 1');
$dias = max(1, (int) escV4Param('v4_receita_dias', '30'));
$receita = escV4ReceitaPaginas(array_column($lista, 'pagina'), $dias);
$config = $instalada ? escPaginaConfig() : [];
$minimo = (float) escV4Param('v4_trivial_receita_min', '8000');
$porModelo = [];
foreach ($lista as &$l) {
    $l['rec'] = $receita[$l['pagina']] ?? ['total' => 0.0, 'dias' => 0, 'base' => 'SEM', 'tier' => null];
    $l['modo'] = $config[escV4ChavePagina($l['pagina'])] ?? 'AUTO';
    $l['desc'] = escV4Desconsiderada($l['pagina'], (float) $l['rec']['total']);
    $porModelo[$l['modelo']] = ($porModelo[$l['modelo']] ?? 0) + (float) $l['rec']['total'];
}
unset($l);
usort($lista, static fn($a, $b) => [-$porModelo[$a['modelo']], -$a['rec']['total'], $a['pagina']] <=> [-$porModelo[$b['modelo']], -$b['rec']['total'], $b['pagina']]);

$filtro = in_array($_GET['f'] ?? '', ['desconsideradas', 'oftv', 'emuladas'], true) ? $_GET['f'] : 'todas';
$filtros = ['todas' => t('Todas'), 'desconsideradas' => t('Desconsideradas'), 'oftv' => t('Com cara de OFTV'), 'emuladas' => t('Menos de 30 dias ou pelo tier')];
$mostrar = array_values(array_filter($lista, static function ($l) use ($filtro) {
    if ($filtro === 'desconsideradas') {
        return $l['desc'];
    }
    if ($filtro === 'oftv') {
        return escV4Oftv($l['pagina']);
    }
    if ($filtro === 'emuladas') {
        return $l['rec']['base'] !== 'HISTORICO';
    }
    return true;
}));
$nDesc = count(array_filter($lista, static fn($l) => $l['desc']));
$usd = static fn(float $v): string => 'US$ ' . number_format($v, 0, ',', '.');
$baseTxt = static function (array $r) use ($dias): string {
    if ($r['base'] === 'EMULADO') {
        return t('emulado de {n} dias de venda', ['n' => $r['dias']]);
    }
    if ($r['base'] === 'TIER') {
        return t('sem venda: meio da faixa do tier {tier}', ['tier' => $r['tier']]);
    }
    if ($r['base'] === 'SEM') {
        return t('sem venda');
    }
    return t('{n} dias de venda', ['n' => $dias]);
};
?>
<header class="cabecalho">
  <span class="eyebrow"><?php echo h(t('Páginas')); ?></span>
  <h1><?php echo h(t('Configuração das páginas')); ?></h1>
  <p class="muted"><?php echo h(t('A escala é preenchida da modelo que mais fatura para a que menos fatura: as páginas da mesma modelo (VIP, Free, OFTV) contam juntas. O faturamento é o dos últimos {n} dias, com as vendas fechadas e as pendentes. Página com menos dias de venda tem a média emulada para {n} dias; sem venda nenhuma, vale o meio da faixa do tier.', ['n' => $dias])); ?></p>
  <p class="muted"><?php echo h(t('Página desconsiderada não tem SLA a cumprir: a carga dela fica zero no posto e ela é a última a receber gente. No automático, é a página com cara de OFTV (OFTV no nome ou código terminado em t) que faturou menos de {min} em {n} dias.', ['min' => $usd($minimo), 'n' => $dias])); ?></p>
</header>

<?php if (!$instalada): ?>
<section class="panel"><p class="aviso erro" role="alert"><?php echo h(t('A configuração das páginas ainda não foi instalada no banco (script ES_57). Peça ao admin. Até lá, todas as páginas ficam no automático.')); ?></p></section>
<?php endif; ?>

<div class="kpis">
  <div class="kpi"><span class="muted"><?php echo h(t('Páginas na estrutura')); ?></span><strong><?php echo count($lista); ?></strong></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Modelos')); ?></span><strong><?php echo count($porModelo); ?></strong></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Desconsideradas')); ?></span><strong><?php echo $nDesc; ?></strong></div>
</div>

<section class="panel">
  <nav class="acoes" aria-label="<?php echo h(t('Filtro')); ?>">
    <?php foreach ($filtros as $k => $rot): ?>
    <a class="botao<?php echo $k === $filtro ? ' primario' : ''; ?>" href="<?php echo h(link_tela('paginas', $k === 'todas' ? [] : ['f' => $k])); ?>"<?php echo $k === $filtro ? ' aria-current="page"' : ''; ?>><?php echo h($rot); ?></a>
    <?php endforeach; ?>
  </nav>
  <?php if (!$mostrar): ?><p class="muted"><?php echo h(t('Nenhuma página neste filtro.')); ?></p><?php else: ?>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th><?php echo h(t('Página')); ?></th><th><?php echo h(t('Faturamento da página')); ?></th><th><?php echo h(t('Faturamento da modelo')); ?></th><th><?php echo h(t('Situação')); ?></th><th><?php echo h(t('Configuração')); ?></th></tr></thead>
    <tbody>
    <?php foreach ($mostrar as $l): ?>
      <tr>
        <td><strong><?php echo h($l['pagina']); ?></strong><br><span class="muted small"><?php echo h(t('modelo {m} · {c} · tier {t}', ['m' => $l['modelo'], 'c' => $l['carteira'], 't' => $l['tier'] ?: '—'])); ?></span></td>
        <td class="num"><?php echo h($usd((float) $l['rec']['total'])); ?><br><span class="muted small"><?php echo h($baseTxt($l['rec'])); ?></span></td>
        <td class="num"><?php echo h($usd((float) $porModelo[$l['modelo']])); ?></td>
        <td>
          <?php if ($l['desc']): ?><span class="tag laranja"><?php echo h($l['modo'] === 'DESCONSIDERAR' ? t('Desconsiderada (marcada)') : t('Desconsiderada (OFTV abaixo de {min})', ['min' => $usd($minimo)])); ?></span>
          <?php else: ?><span class="tag verde"><?php echo h($l['modo'] === 'NORMAL' ? t('Normal (marcada)') : t('Normal')); ?></span><?php endif; ?>
        </td>
        <td>
          <?php if ($instalada && escPode('planejar')): ?>
          <form method="post" action="<?php echo h(link_tela('paginas', $filtro === 'todas' ? [] : ['f' => $filtro])); ?>" class="acoes small">
            <?php echo campoCsrf(); ?><input type="hidden" name="pagina" value="<?php echo h($l['pagina']); ?>">
            <select name="sla_modo" aria-label="<?php echo h(t('Configuração de {pagina}', ['pagina' => $l['pagina']])); ?>">
              <?php foreach ($modos as $k => $rot): ?><option value="<?php echo h($k); ?>"<?php echo $k === $l['modo'] ? ' selected' : ''; ?>><?php echo h($rot); ?></option><?php endforeach; ?>
            </select>
            <button type="submit" class="botao"><?php echo h(t('Salvar')); ?></button>
          </form>
          <?php else: ?><?php echo h($modos[$l['modo']]); ?><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>
