<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/paginas.php
// Configuração das páginas (esc_pagina_config, ES_57): faturamento de 30 dias de cada página e
// da modelo (o que ordena a escala) e a chave que manda o sistema desconsiderar a página
// (sem SLA a cumprir, carga zero no posto, último lugar na fila). Vale para o motor e para o
// Capacity (script 27 do Capacity). E a alocação (ES_79): Active team (padrão) ou On demand, que o
// "Gerar" não preenche (quando pedem, a alocação é manual, travada na Grade).
// ===============================================

$instalada = (bool) escQ1("SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'esc_pagina_config'", [ESC_SCHEMA]);
$modos = ['AUTO' => t('Automático'), 'NORMAL' => t('Sempre normal'), 'DESCONSIDERAR' => t('Desconsiderar')];
$comAlocacao = $instalada && (bool) escQ1("SELECT 1 AS ok FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'esc_pagina_config' AND COLUMN_NAME = 'alocacao'", [ESC_SCHEMA]);
$alocacoes = ['EQUIPE' => t('Equipe ativa'), 'SOB_DEMANDA' => t('Sob demanda'), 'EXCLUSIVA' => t('Exclusiva (solo)')];
// Sensibilidade (cor) e plataforma da página (ES_85, regras P-02 e P-04): por enquanto só registro.
$comFlags = $instalada && (bool) escQ1("SELECT 1 AS ok FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'esc_pagina_config' AND COLUMN_NAME = 'sensibilidade'", [ESC_SCHEMA]);
$cores = ['VERDE' => t('Verde'), 'AMARELA' => t('Amarela'), 'VERMELHA' => t('Vermelha')];
$corTag = ['VERDE' => 'verde', 'AMARELA' => 'amarelo', 'VERMELHA' => 'vermelho'];
$plataformas = ['OnlyFans', 'Fanvue', 'Privacy', 'PlayBoy', 'Pornhub', 'X', 'MYM'];

if (escPost()) {
    escExigir('planejar');
    if (!$instalada) {
        throw new RuntimeException(t('A configuração das páginas ainda não foi instalada no banco (script ES_57). Peça ao admin.'));
    }
    $pagina = trim((string) ($_POST['pagina'] ?? ''));
    $modo = (string) ($_POST['sla_modo'] ?? '');
    $aloc = $comAlocacao ? (string) ($_POST['alocacao'] ?? 'EQUIPE') : 'EQUIPE';
    $cor = $comFlags ? (string) ($_POST['sensibilidade'] ?? '') : '';
    $plat = $comFlags ? (string) ($_POST['plataforma'] ?? '') : '';
    if (!isset($modos[$modo]) || !isset($alocacoes[$aloc]) || ($comFlags && (!isset($cores[$cor]) || !in_array($plat, $plataformas, true)))) {
        throw new RuntimeException(t('Escolha uma opção da lista.'));
    }
    if (!escQ1('SELECT 1 AS ok FROM `5c_pages`.`pages` WHERE active = 1 AND name = ?', [$pagina])) {
        throw new RuntimeException(t('Página não encontrada entre as ativas do site. Recarregue a tela.'));
    }
    if ($comAlocacao) {
        $antes = escQ1('SELECT pagina, sla_modo, alocacao FROM ' . escT('esc_pagina_config') . ' WHERE pagina = ?', [$pagina]);
        escW('INSERT INTO ' . escT('esc_pagina_config') . ' (pagina, sla_modo, alocacao, atualizado_por) VALUES (?, ?, ?, ?)
              ON DUPLICATE KEY UPDATE sla_modo = VALUES(sla_modo), alocacao = VALUES(alocacao), atualizado_por = VALUES(atualizado_por)', [$pagina, $modo, $aloc, escUserId() ?: null]);
    } else {
        $antes = escQ1('SELECT pagina, sla_modo FROM ' . escT('esc_pagina_config') . ' WHERE pagina = ?', [$pagina]);
        escW('INSERT INTO ' . escT('esc_pagina_config') . ' (pagina, sla_modo, atualizado_por) VALUES (?, ?, ?)
              ON DUPLICATE KEY UPDATE sla_modo = VALUES(sla_modo), atualizado_por = VALUES(atualizado_por)', [$pagina, $modo, escUserId() ?: null]);
    }
    $depois = ['sla_modo' => $modo] + ($comAlocacao ? ['alocacao' => $aloc] : []);
    if ($comFlags) {
        // A origem só muda quando o valor muda (fica a do Telegram, do cadastro ou do site até alguém trocar).
        $flagsAntes = escQ1('SELECT sensibilidade, plataforma FROM ' . escT('esc_pagina_config') . ' WHERE pagina = ?', [$pagina]) ?: [];
        $antes = ($antes ?: []) + $flagsAntes;
        $origem = mb_substr('manual: ' . ($GLOBALS['ESC_USUARIO']['nome'] ?? t('usuário')) . ' ' . date('d/m'), 0, 60);
        escW('UPDATE ' . escT('esc_pagina_config') . '
                 SET sensibilidade_origem = IF(IFNULL(sensibilidade = ?, 0), sensibilidade_origem, ?), sensibilidade = ?,
                     plataforma_origem = IF(IFNULL(plataforma = ?, 0), plataforma_origem, ?), plataforma = ?
               WHERE pagina = ?', [$cor, $origem, $cor, $plat, $origem, $plat, $pagina]);
        $depois += ['sensibilidade' => $cor, 'plataforma' => $plat];
    }
    escLog('pagina.config', 'esc_pagina_config', $pagina, $antes, $depois);
    escVoltar('paginas', array_intersect_key($_GET, ['f' => 1]), t('{pagina}: {modo}. Vale a partir do próximo "Gerar" e do próximo cálculo do Capacity.', ['pagina' => $pagina, 'modo' => $modos[$modo] . ($comAlocacao ? ' · ' . $alocacoes[$aloc] : '')])
        . ($comFlags ? ' ' . t('Cor {cor} e plataforma {plat} registradas.', ['cor' => $cores[$cor], 'plat' => $plat]) : ''));
}

// Páginas ativas do site que estão na estrutura (carteira vigente).
$lista = escQ('SELECT p.name AS pagina, p.tier, c.modelo, c.carteira, c.regra
                 FROM `5c_pages`.`pages` p
                 JOIN `5c_planning`.`fc_pagina_carteira` c ON c.pagina = p.name AND c.vigente_ate IS NULL
                WHERE p.active = 1');
$dias = max(1, (int) escV4Param('v4_receita_dias', '30'));
$receita = escV4ReceitaPaginas(array_column($lista, 'pagina'), $dias);
$config = $instalada ? escPaginaConfig() : [];
$alocPag = $comAlocacao ? escPaginaAlocacao() : [];
$minimo = (float) escV4Param('v4_trivial_receita_min', '8000');
$flags = [];
if ($comFlags) {
    foreach (escQ('SELECT pagina, sensibilidade, sensibilidade_origem, plataforma, plataforma_origem FROM ' . escT('esc_pagina_config')) as $r) {
        $flags[escV4ChavePagina((string) $r['pagina'])] = $r;
    }
}
// De onde veio a cor ou a plataforma, em texto para quem usa a tela.
$origemTxt = static function (?string $o): string {
    if ($o === null || $o === '') {
        return '—';
    }
    if (strncmp($o, '5c_wfm_db.client', 16) === 0) {
        return t('cadastro do cliente');
    }
    if ($o === '5c_pages.pages') {
        return t('site');
    }
    return $o;
};
$porModelo = [];
foreach ($lista as &$l) {
    $f = $flags[escV4ChavePagina($l['pagina'])] ?? [];
    $l['cor'] = $f['sensibilidade'] ?? null;
    $l['cor_origem'] = $f['sensibilidade_origem'] ?? null;
    $l['plat'] = $f['plataforma'] ?? null;
    $l['plat_origem'] = $f['plataforma_origem'] ?? null;
    $l['rec'] = $receita[$l['pagina']] ?? ['total' => 0.0, 'dias' => 0, 'base' => 'SEM', 'tier' => null];
    $l['modo'] = $config[escV4ChavePagina($l['pagina'])] ?? 'AUTO';
    $l['aloc'] = $alocPag[escV4ChavePagina($l['pagina'])] ?? 'EQUIPE';
    $l['desc'] = escV4Desconsiderada($l['pagina'], (float) $l['rec']['total']);
    $porModelo[$l['modelo']] = ($porModelo[$l['modelo']] ?? 0) + (float) $l['rec']['total'];
}
unset($l);
usort($lista, static fn($a, $b) => [-$porModelo[$a['modelo']], -$a['rec']['total'], $a['pagina']] <=> [-$porModelo[$b['modelo']], -$b['rec']['total'], $b['pagina']]);

$filtro = in_array($_GET['f'] ?? '', ['desconsideradas', 'sob_demanda', 'vermelhas', 'amarelas', 'outras_plataformas', 'oftv', 'emuladas'], true) ? $_GET['f'] : 'todas';
$filtros = ['todas' => t('Todas'), 'desconsideradas' => t('Desconsideradas')] + ($comAlocacao ? ['sob_demanda' => t('Sob demanda')] : [])
    + ($comFlags ? ['vermelhas' => t('Vermelhas'), 'amarelas' => t('Amarelas'), 'outras_plataformas' => t('Fora do OnlyFans')] : [])
    + ['oftv' => t('Com cara de OFTV'), 'emuladas' => t('Menos de 30 dias ou pelo tier')];
$mostrar = array_values(array_filter($lista, static function ($l) use ($filtro) {
    if ($filtro === 'sob_demanda') {
        return $l['aloc'] === 'SOB_DEMANDA';
    }
    if ($filtro === 'vermelhas') {
        return $l['cor'] === 'VERMELHA';
    }
    if ($filtro === 'amarelas') {
        return $l['cor'] === 'AMARELA';
    }
    if ($filtro === 'outras_plataformas') {
        return $l['plat'] !== null && $l['plat'] !== 'OnlyFans';
    }
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
$nDemanda = count(array_filter($lista, static fn($l) => $l['aloc'] === 'SOB_DEMANDA'));
$nVermelhas = count(array_filter($lista, static fn($l) => $l['cor'] === 'VERMELHA'));
$nAmarelas = count(array_filter($lista, static fn($l) => $l['cor'] === 'AMARELA'));
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
  <?php if ($comAlocacao): ?><p class="muted"><?php echo h(t('Página sob demanda (On demand) não recebe ninguém no "Gerar". Quando alguém pede, a alocação é feita à mão na Grade, com a célula travada.')); ?> <?php echo h(t('Página exclusiva (solo): o SR dela fica só nela, sem acumular nem cobrir outra página, e ela não vai para o SR de outra página. O assistente vem do posto fixo do Capacity (2 pessoas).')); ?></p><?php endif; ?>
  <?php if ($comFlags): ?><p class="muted"><?php echo h(t('Cor (sensibilidade): vermelha só recebe quem já trabalhou nela (7 dias ou mais em 90) ou é da equipe fixa dela, e fica em lacuna se não houver ninguém; amarela aceita no máximo 20% dos turnos da semana com quem não a conhece; verde segue o mérito. Plataforma: o SR não mistura plataformas no turno.')); ?> <strong><?php echo h(t('Por enquanto a cor e a plataforma ficam só registradas: o "Gerar" ainda não as usa.')); ?></strong></p><?php endif; ?>
</header>

<?php if (!$instalada): ?>
<section class="panel"><p class="aviso erro" role="alert"><?php echo h(t('A configuração das páginas ainda não foi instalada no banco (script ES_57). Peça ao admin. Até lá, todas as páginas ficam no automático.')); ?></p></section>
<?php endif; ?>

<div class="kpis">
  <div class="kpi"><span class="muted"><?php echo h(t('Páginas na estrutura')); ?></span><strong><?php echo count($lista); ?></strong></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Modelos')); ?></span><strong><?php echo count($porModelo); ?></strong></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Desconsideradas')); ?></span><strong><?php echo $nDesc; ?></strong></div>
  <?php if ($comAlocacao): ?><div class="kpi"><span class="muted"><?php echo h(t('Sob demanda')); ?></span><strong><?php echo $nDemanda; ?></strong></div><?php endif; ?>
  <?php if ($comFlags): ?>
  <div class="kpi"><span class="muted"><?php echo h(t('Vermelhas')); ?></span><strong><?php echo $nVermelhas; ?></strong></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Amarelas')); ?></span><strong><?php echo $nAmarelas; ?></strong></div>
  <?php endif; ?>
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
          <?php if ($l['aloc'] !== 'EQUIPE'): ?><span class="tag"><?php echo h($alocacoes[$l['aloc']]); ?></span><?php endif; ?>
          <?php if ($comFlags && $l['cor'] !== null): ?><br><span class="tag <?php echo h($corTag[$l['cor']]); ?>"><?php echo h(t('Cor: {cor}', ['cor' => $cores[$l['cor']]])); ?></span><?php endif; ?>
          <?php if ($comFlags && $l['plat'] !== null && $l['plat'] !== 'OnlyFans'): ?><span class="tag"><?php echo h($l['plat']); ?></span><?php endif; ?>
          <?php if ($comFlags): ?><br><span class="muted small"><?php echo h(t('cor: {o} · plataforma: {p}', ['o' => $origemTxt($l['cor_origem']), 'p' => $origemTxt($l['plat_origem'])])); ?></span><?php endif; ?>
        </td>
        <td>
          <?php if ($instalada && escPode('planejar')): ?>
          <form method="post" action="<?php echo h(link_tela('paginas', $filtro === 'todas' ? [] : ['f' => $filtro])); ?>" class="acoes small">
            <?php echo campoCsrf(); ?><input type="hidden" name="pagina" value="<?php echo h($l['pagina']); ?>">
            <select name="sla_modo" aria-label="<?php echo h(t('Configuração de {pagina}', ['pagina' => $l['pagina']])); ?>">
              <?php foreach ($modos as $k => $rot): ?><option value="<?php echo h($k); ?>"<?php echo $k === $l['modo'] ? ' selected' : ''; ?>><?php echo h($rot); ?></option><?php endforeach; ?>
            </select>
            <?php if ($comAlocacao): ?>
            <select name="alocacao" aria-label="<?php echo h(t('Alocação de {pagina}', ['pagina' => $l['pagina']])); ?>">
              <?php foreach ($alocacoes as $k => $rot): ?><option value="<?php echo h($k); ?>"<?php echo $k === $l['aloc'] ? ' selected' : ''; ?>><?php echo h($rot); ?></option><?php endforeach; ?>
            </select>
            <?php endif; ?>
            <?php if ($comFlags): ?>
            <select name="sensibilidade" aria-label="<?php echo h(t('Cor de {pagina}', ['pagina' => $l['pagina']])); ?>">
              <?php foreach ($cores as $k => $rot): ?><option value="<?php echo h($k); ?>"<?php echo $k === ($l['cor'] ?? 'VERDE') ? ' selected' : ''; ?>><?php echo h($rot); ?></option><?php endforeach; ?>
            </select>
            <select name="plataforma" aria-label="<?php echo h(t('Plataforma de {pagina}', ['pagina' => $l['pagina']])); ?>">
              <?php foreach ($plataformas as $k): ?><option value="<?php echo h($k); ?>"<?php echo $k === ($l['plat'] ?? 'OnlyFans') ? ' selected' : ''; ?>><?php echo h($k); ?></option><?php endforeach; ?>
            </select>
            <?php endif; ?>
            <button type="submit" class="botao"><?php echo h(t('Salvar')); ?></button>
          </form>
          <?php else: ?><?php echo h($modos[$l['modo']] . ($comAlocacao ? ' · ' . $alocacoes[$l['aloc']] : '') . ($comFlags ? ' · ' . ($l['cor'] !== null ? $cores[$l['cor']] : '—') . ' · ' . ($l['plat'] ?? '—') : '')); ?><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>
