<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/funil.php
// Funil de entrada: cards do CRM (Pipeline, Onboarding e Active da
// última semana) e os arquétipos usados para estimar o volume de uma
// página nova a partir do tier (faturamento) e do perfil.
// ===============================================

// Destino na estrutura nova (ES_27): unidade · turno (· núcleo). Sem o script, fica o destino por party.
$destinoV4 = (bool) escQ1("SELECT 1 AS ok FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'esc_pagina_previsao' AND COLUMN_NAME = 'destino_unidade'", [ESC_SCHEMA]);
$diaNucleos = max(date('Y-m-d'), escV4DiaEstrutura());
$nucleosFunil = [];
foreach (escQ('SELECT id, unidade, turno, numero FROM ' . escT('esc_nucleo') . " WHERE vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?) ORDER BY unidade, FIELD(turno, 'A', 'C', 'D'), numero", [$diaNucleos, $diaNucleos]) as $n) {
    $nucleosFunil[(int) $n['id']] = $n;
}

// Páginas ativas no site, fora da escala (lib/funil_entrada.php, ES_138): pôr na escala, ignorar ou desfazer.
$acaoEntrada = (string) ($_POST['acao'] ?? '');
if (escPost() && in_array($acaoEntrada, ['entrada_aplicar', 'entrada_ignorar', 'entrada_desfazer'], true)) {
    escExigir('decidir');
    $pgEntrada = trim((string) ($_POST['pagina'] ?? ''));
    if ($acaoEntrada === 'entrada_aplicar') {
        $desdeEntrada = escDataValida($_POST['desde'] ?? '') ?? '';
        if ($desdeEntrada === '') {
            throw new RuntimeException(t('Data inválida.'));
        }
        $nucleoEntrada = (int) ($_POST['nucleo'] ?? 0);
        $r = escEntradaAplicar($pgEntrada, trim((string) ($_POST['carteira'] ?? '')), $desdeEntrada, trim((string) ($_POST['observacao'] ?? '')), $nucleoEntrada > 0 ? $nucleoEntrada : null);
        $msg = t('{pagina} entrou na carteira {carteira} a partir de {dia}. Os postos dela entram na escala no próximo Gerar.', ['pagina' => $r['pagina'], 'carteira' => $r['carteira'], 'dia' => dataBr($r['desde'])]);
        if ($r['erro_recalculo']) {
            $msg .= ' ' . t('O recálculo do Capacity falhou ({erro}); o próximo Gerar recalcula.', ['erro' => mb_substr((string) $r['erro_recalculo'], 0, 120)]);
        }
        escVoltar('funil', [], $msg, (bool) $r['erro_recalculo'], 'ativas');
    }
    $diaEntrada = escDataValida($_POST['ativa_desde'] ?? '') ?? '';
    escEntradaIgnorar($pgEntrada, $diaEntrada, $acaoEntrada === 'entrada_ignorar', trim((string) ($_POST['observacao'] ?? '')));
    escVoltar('funil', array_filter(['ignoradas' => $_GET['ignoradas'] ?? null]), $acaoEntrada === 'entrada_ignorar'
        ? t('{pagina}: ignorada (não é página de escala). Se sair e voltar, aparece de novo.', ['pagina' => $pgEntrada])
        : t('{pagina}: voltou para a lista.', ['pagina' => $pgEntrada]), false, 'ativas');
}

// Ajuste de previsão e destino (5c_escalas.esc_pagina_previsao); o CRM não muda.
if (escPost()) {
    escExigir('decidir');
    $card = (int) ($_POST['crm_card_id'] ?? 0);
    if ($card <= 0) {
        throw new RuntimeException(t('Card inválido.'));
    }
    $num = static fn($k) => ($_POST[$k] ?? '') === '' ? null : (float) str_replace(',', '.', (string) $_POST[$k]);
    $perfil = in_array($_POST['perfil'] ?? '', ['MAIN', 'VIP', 'FREE', 'OFTV'], true) ? $_POST['perfil'] : null;
    $base = in_array($_POST['base'] ?? '', ['ARQUETIPO', 'REFERENCIA', 'INFORMADO'], true) ? $_POST['base'] : 'ARQUETIPO';
    $data = preg_match('~^\d{4}-\d{2}-\d{2}$~', (string) ($_POST['data_prevista'] ?? '')) ? $_POST['data_prevista'] : null;
    $prob = $num('probabilidade');
    if ($destinoV4) {
        $_POST['destino_time'] = '';
        $_POST['destino_party'] = '';
    } else {
        $timeDaParty = array_column(escFormacaoParties(), 'team', 'party');
        $_POST['destino_time'] = $timeDaParty[$_POST['destino_party'] ?? ''] ?? '';
    }
    if ($destinoV4) {
        // "u|t|núcleo" (núcleo opcional); vazio = definir depois. Valida antes de gravar.
        $partes = explode('|', (string) ($_POST['destino'] ?? ''));
        $u = (int) ($partes[0] ?? 0);
        $t = (string) ($partes[1] ?? '');
        $nid = (int) ($partes[2] ?? 0);
        if ($nid && !isset($nucleosFunil[$nid])) {
            throw new RuntimeException(t('Núcleo de destino não encontrado.'));
        }
        $ok = $u >= 1 && $u <= 3 && isset(ESC_TURNOS[$t]);
    }
    escTx(function () use ($card, $perfil, $base, $num, $prob, $data, $destinoV4, &$u, &$t, &$nid, &$ok) {
        escW('INSERT INTO ' . escT('esc_pagina_previsao') . ' (crm_card_id, pagina, perfil, base, pagina_referencia, fans_h_a, fans_h_c, fans_h_d, probabilidade, data_prevista, destino_time, destino_party, destino_motivo, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) AS n
              ON DUPLICATE KEY UPDATE pagina = n.pagina, perfil = n.perfil, base = n.base, pagina_referencia = n.pagina_referencia, fans_h_a = n.fans_h_a, fans_h_c = n.fans_h_c, fans_h_d = n.fans_h_d, probabilidade = n.probabilidade, data_prevista = n.data_prevista, destino_time = n.destino_time, destino_party = n.destino_party, destino_motivo = n.destino_motivo, atualizado_por = n.criado_por', [
            $card, mb_substr(trim((string) ($_POST['pagina'] ?? '')), 0, 150) ?: null, $perfil, $base,
            mb_substr(trim((string) ($_POST['pagina_referencia'] ?? '')), 0, 150) ?: null,
            $num('fans_h_a'), $num('fans_h_c'), $num('fans_h_d'), $prob === null ? null : max(0, min(1, $prob / 100)), $data,
            mb_substr(trim((string) ($_POST['destino_time'] ?? '')), 0, 100) ?: null, mb_substr(trim((string) ($_POST['destino_party'] ?? '')), 0, 100) ?: null,
            mb_substr(trim((string) ($_POST['destino_motivo'] ?? '')), 0, 255) ?: null, escUserId(),
        ]);
        if ($destinoV4) {
            escW('UPDATE ' . escT('esc_pagina_previsao') . ' SET destino_unidade = ?, destino_turno = ?, destino_nucleo_id = ? WHERE crm_card_id = ?',
                [$ok ? $u : null, $ok ? $t : null, $ok && $nid ? $nid : null, $card]);
        }
    });
    escLog('funil.previsao', 'esc_pagina_previsao', $card, null, array_diff_key($_POST, ['csrf' => 1]));
    escVoltar('funil', [], t('Previsão do card #{card} salva (o CRM não foi alterado).', ['card' => $card]));
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
$arquetipos = escFunilArquetipos();

$previsoes = [];
foreach (escQ('SELECT * FROM ' . escT('esc_pagina_previsao')) as $pv) {
    $previsoes[(int) $pv['crm_card_id']] = $pv;
}
$partiesNomes = $destinoV4 ? [] : array_column(escFormacaoParties(), 'party', 'party');
$rotuloDestino = static function (?array $pv) use ($destinoV4, $nucleosFunil): string {
    if (!$pv) {
        return '';
    }
    if ($destinoV4 && !empty($pv['destino_unidade'])) {
        $n = $nucleosFunil[(int) ($pv['destino_nucleo_id'] ?? 0)] ?? null;
        return 'U' . (int) $pv['destino_unidade'] . ' · ' . $pv['destino_turno'] . ($n ? ' · ' . t('núcleo {n}', ['n' => (int) $n['numero']]) : '');
    }
    return (string) ($pv['destino_party'] ?? '');
};
$porEtapa = [];
foreach ($cards as $c) {
    $porEtapa[$c['etapa']][] = $c;
}
// Destinos indicados (lib/funil_sugestao.php): só na estrutura nova (ES_27). O KPI em número
// aparece só para quem pode ver o KPI individual (Planejamento, WFM e Admin).
$ctxFunil = $destinoV4 ? escFunilContexto($diaNucleos) : null;
$verKpi = escPodeReal('planejar');
$ORIGEM_VOL = ['previsao' => t('previsão salva'), 'arquetipo' => t('arquétipo do tier e perfil'), 'perfil' => t('média do perfil (sem tier)'), 'padrao' => t('valor padrão (sem arquétipo)')];
$confianca = static function (int $n): array {
    return $n >= 5 ? [t('Alta'), 'verde'] : ($n >= 3 ? [t('Média'), 'amarelo'] : [t('Baixa'), 'vermelho']);
};

// Ativas no site, fora da escala (sem carteira aberta no Capacity). Ignoradas só com ?ignoradas=1.
$entradas = escEntradasDetectadas();
$verIgnoradas = !empty($_GET['ignoradas']);
$nIgnoradas = count(array_filter($entradas, static fn($e) => $e['situacao'] === 'IGNORADA'));
$nPresas = count(array_filter($entradas, static fn($e) => $e['situacao'] === 'PRESA'));
$entradasVer = array_values(array_filter($entradas, static fn($e) => $verIgnoradas || $e['situacao'] !== 'IGNORADA'));
$entradaTabela = escEntradaTemTabela();
$carteirasEntrada = $entradaTabela && escPode('decidir') ? escEntradaCarteiras() : [];
$entradaMinimo = max(date('Y-m-d'), escV4DiaEstrutura());
$entradaNucleo = escEntradaTemNucleo();
$entradasDecididas = $entradaTabela ? escQ("SELECT pagina, ativa_desde, tipo, decisao, carteira, vale_desde, " . ($entradaNucleo ? 'nucleo_numero' : 'NULL AS nucleo_numero') . ", observacao, decidido_em FROM " . escT('esc_pagina_entrada_checagem') . " WHERE decisao = 'ENTROU' ORDER BY decidido_em DESC LIMIT 10") : [];
$SITUACAO_ENTRADA = [
    'PRESA' => ['vermelho', t('não volta sozinha')],
    'RECENTE' => ['amarelo', t('ativou agora, sem movimento: decida')],
    'AUTO' => ['verde', t('entra sozinha no próximo Gerar')],
    'PARADA' => ['cinza', t('sem escala nem venda')],
    'ENTROU' => ['verde', t('já entrou')],
    'IGNORADA' => ['cinza', t('ignorada')],
];
?>
<header class="cabecalho">
  <span class="eyebrow"><?php echo h(t('Páginas')); ?></span>
  <h1><?php echo h(t('Funil de entrada')); ?></h1>
  <p class="muted"><?php echo h(t('Espelha o funil do CRM. Como o CRM normalmente só traz o tier, o volume de cada página nova vem do arquétipo: páginas ativas com faturamento e perfil parecidos.')); ?></p>
</header>

<section class="panel" id="ativas">
  <div class="linha-titulo"><h2><?php echo h(t('Ativas no site, fora da escala · {n}', ['n' => count($entradas) - $nIgnoradas])); ?></h2>
    <span class="muted small"><?php echo h(t('Detectadas pela DB, não pelo CRM: página ativa no site da 5C e sem carteira no Capacity.')); ?></span></div>
  <p class="muted small"><?php echo h(t('Retorno é a página que já esteve no Capacity ou na escala. Quem saiu pela tela Saídas de página não volta sozinha por {dias} dias: decida aqui. As outras entram sozinhas no próximo Gerar quando têm escala no site ou venda; "Pôr na escala" serve para escolher a carteira antes.', ['dias' => (int) escV4Param('v4_saida_bloqueio_dias', '60')])); ?></p>
  <p class="muted small"><?php echo h(t('A lista lê o site na hora. A que ativou depois da foto das 3h e ainda não tem escala nem venda aparece como "ativou agora": ponha na escala se ela começa já. Página renomeada no site é reconhecida pelo id e volta para a carteira de antes.')); ?></p>
  <?php if (!$entradaTabela): ?><p class="tag laranja"><?php echo h(t('Falta rodar o script ES_138: a lista aparece, mas sem as ações.')); ?></p><?php endif; ?>
  <?php if ($nPresas): ?><p><span class="tag vermelho"><?php echo h(tn($nPresas, '{n} página não volta sozinha', '{n} páginas não voltam sozinhas')); ?></span></p><?php endif; ?>
  <?php // Paradas (sem escala nem venda: PH, X, testes...) ficam recolhidas abaixo das que pedem decisão.
  $gruposEntrada = [
      ['lista' => array_values(array_filter($entradasVer, static fn($e) => $e['situacao'] !== 'PARADA')), 'recolher' => false],
      ['lista' => array_values(array_filter($entradasVer, static fn($e) => $e['situacao'] === 'PARADA')), 'recolher' => true],
  ];
  foreach ($gruposEntrada as $gi => $grupo): if ($gi > 0 && !$grupo['lista']) { continue; } ?>
  <?php if ($grupo['recolher']): ?><details class="entrada-paradas"><summary class="small"><?php echo h(tn(count($grupo['lista']), '{n} ativa sem escala nem venda (entra sozinha quando tiver movimento; PH, X e testes: ignore)', '{n} ativas sem escala nem venda (entram sozinhas quando tiverem movimento; PH, X e testes: ignore)')); ?></summary><?php endif; ?>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th><?php echo h(t('Página')); ?></th><th><?php echo h(t('Ativa no site desde')); ?></th><th><?php echo h(t('Antes')); ?></th><th class="num"><?php echo h(t('Dias com escala no site')); ?></th><th class="num"><?php echo h(t('Venda')); ?></th><th><?php echo h(t('Situação')); ?></th><th aria-label="<?php echo h(t('Ações')); ?>"></th></tr></thead>
    <tbody>
    <?php if (!$grupo['lista']): ?><tr><td colspan="7" class="muted"><?php echo h(t('Nenhuma página ativa fora da escala pedindo decisão.')); ?></td></tr><?php endif; ?>
    <?php foreach ($grupo['lista'] as $e): [$corSit, $rotSit] = $SITUACAO_ENTRADA[$e['situacao']]; $ult = $e['ultima']; ?>
      <tr>
        <td><strong><?php echo h($e['pagina']); ?></strong> <span class="tag <?php echo $e['tipo'] === 'RETORNO' ? 'amarelo' : ''; ?>"><?php echo h($e['tipo'] === 'RETORNO' ? t('retorno') : t('nova')); ?></span></td>
        <td><?php echo h($e['desde_antes'] ? t('{dia} ou antes', ['dia' => dataBr($e['ativa_desde'])]) : dataBr($e['ativa_desde'])); ?><?php echo !empty($e['recente']) ? '<br><span class="muted small">' . h(t('depois da foto das 3h')) . '</span>' : ''; ?></td>
        <td class="small entrada-antes"><?php
            $antes = [];
            if (!empty($e['nome_antes'])) { $antes[] = t('no site antes como "{nome}"', ['nome' => $e['nome_antes']]); }
            if ($ult) { $antes[] = t('carteira {carteira} até {dia}', ['carteira' => $ult['carteira'], 'dia' => $ult['vigente_ate'] ? dataBr(substr((string) $ult['vigente_ate'], 0, 10)) : '—']); }
            if ($e['saida']) { $antes[] = t('saída aplicada a partir de {dia}', ['dia' => dataBr(substr((string) $e['saida']['vale_desde'], 0, 10))]); }
            if (!$ult && $e['irma']) { $antes[] = t('a modelo tem {pagina} na carteira {carteira}', ['pagina' => $e['irma']['pagina'], 'carteira' => $e['irma']['carteira']]); }
            echo $antes ? h(implode(' · ', $antes)) : '<span class="muted">—</span>'; ?></td>
        <td class="num"><?php echo (int) $e['dias_escala']; ?></td>
        <td class="num"><?php echo $e['venda'] > 0 ? num($e['venda'], 0) : '<span class="muted">0</span>'; ?></td>
        <td><span class="tag <?php echo $corSit; ?>"><?php echo h($rotSit); ?></span><?php echo $e['decisao'] && $e['decisao']['observacao'] ? '<br><span class="muted small">' . h($e['decisao']['observacao']) . '</span>' : ''; ?></td>
        <td>
          <?php if ($entradaTabela && escPode('decidir')): ?>
            <?php if ($e['situacao'] === 'IGNORADA'): ?>
            <form method="post" action="<?php echo h(link_tela('funil', ['ignoradas' => 1])); ?>" class="acoes">
              <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="entrada_desfazer"><input type="hidden" name="pagina" value="<?php echo h($e['pagina']); ?>"><input type="hidden" name="ativa_desde" value="<?php echo h($e['ativa_desde']); ?>">
              <button type="submit" class="botao"><?php echo h(t('Voltar para a lista')); ?></button>
            </form>
            <?php elseif ($e['situacao'] !== 'ENTROU'): ?>
            <details><summary class="botao <?php echo in_array($e['situacao'], ['PRESA', 'RECENTE'], true) ? 'primario' : ''; ?>"><?php echo h(t('Decidir')); ?></summary>
              <form method="post" action="<?php echo h(link_tela('funil')); ?>" class="form-grade" data-confirmar="<?php echo h(t('Pôr {pagina} na escala? A carteira é gravada no Capacity, que é recalculado agora; os postos entram no próximo Gerar.', ['pagina' => $e['pagina']])); ?>">
                <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="entrada_aplicar"><input type="hidden" name="pagina" value="<?php echo h($e['pagina']); ?>">
                <label><?php echo h(t('Carteira')); ?><select name="carteira" required>
                  <?php if (!$e['carteira_sugerida']): ?><option value=""><?php echo h(t('(escolha)')); ?></option><?php endif; ?>
                  <?php foreach ($carteirasEntrada as $c): ?><option value="<?php echo h($c['carteira']); ?>" <?php echo $c['carteira'] === $e['carteira_sugerida'] ? 'selected' : ''; ?>><?php echo h($c['carteira'] . ($c['carteira'] === ($ult['carteira'] ?? null) ? ' · ' . t('a de antes') : ($c['carteira'] === ($e['irma']['carteira'] ?? null) ? ' · ' . t('a da mesma modelo') : ''))); ?></option><?php endforeach; ?>
                </select></label>
                <label><?php echo h(t('A partir de')); ?><input type="date" name="desde" required min="<?php echo h($entradaMinimo); ?>" value="<?php echo h(max($entradaMinimo, escMaisDias(date('Y-m-d'), 1))); ?>"></label>
                <?php if ($entradaNucleo): ?>
                <label><?php echo h(t('Núcleo (OS) nos 3 turnos')); ?><select name="nucleo"><option value=""><?php echo h(t('automático (o menos carregado)')); ?></option><?php foreach ([1, 2, 3] as $nn): ?><option value="<?php echo $nn; ?>"><?php echo h(t('núcleo {n} (OS{n})', ['n' => $nn])); ?></option><?php endforeach; ?></select></label>
                <?php endif; ?>
                <label><?php echo h(t('Observação')); ?><input type="text" name="observacao" maxlength="255"></label>
                <button type="submit" class="botao primario"><?php echo h(t('Pôr na escala')); ?></button>
              </form>
              <form method="post" action="<?php echo h(link_tela('funil')); ?>" class="acoes">
                <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="entrada_ignorar"><input type="hidden" name="pagina" value="<?php echo h($e['pagina']); ?>"><input type="hidden" name="ativa_desde" value="<?php echo h($e['ativa_desde']); ?>">
                <label class="small"><?php echo h(t('Por que ignorar')); ?> <input type="text" name="observacao" maxlength="255" placeholder="<?php echo h(t('ex.: página de PH, teste')); ?>"></label>
                <button type="submit" class="botao"><?php echo h(t('Ignorar: não é página de escala')); ?></button>
              </form>
            </details>
            <?php endif; ?>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if ($grupo['recolher']): ?></details><?php endif; ?>
  <?php endforeach; ?>
  <?php if ($nIgnoradas): ?><p class="small"><a href="<?php echo h(link_tela('funil', $verIgnoradas ? [] : ['ignoradas' => 1])); ?>#ativas"><?php echo h($verIgnoradas ? t('Esconder as ignoradas') : tn($nIgnoradas, 'Mostrar {n} ignorada', 'Mostrar {n} ignoradas')); ?></a></p><?php endif; ?>
  <?php if ($entradasDecididas): ?>
  <details><summary class="small"><?php echo h(t('Entradas feitas por esta seção · {n}', ['n' => count($entradasDecididas)])); ?></summary>
    <div class="tabela-rolagem">
    <table>
      <thead><tr><th><?php echo h(t('Quando')); ?></th><th><?php echo h(t('Página')); ?></th><th><?php echo h(t('Carteira')); ?></th><th><?php echo h(t('Núcleo')); ?></th><th><?php echo h(t('A partir de')); ?></th><th><?php echo h(t('Observação')); ?></th></tr></thead>
      <tbody>
      <?php foreach ($entradasDecididas as $d): ?>
        <tr><td class="small"><?php echo h(dataBr(substr((string) $d['decidido_em'], 0, 10)) . ' ' . substr((string) $d['decidido_em'], 11, 5)); ?></td><td><strong><?php echo h($d['pagina']); ?></strong> <span class="muted small"><?php echo h($d['tipo'] === 'RETORNO' ? t('retorno') : t('nova')); ?></span></td><td><?php echo h((string) $d['carteira']); ?></td><td><?php echo $d['nucleo_numero'] ? h(t('núcleo {n} (OS{n})', ['n' => (int) $d['nucleo_numero']])) : '<span class="muted">' . h(t('automático')) . '</span>'; ?></td><td><?php echo h(dataBr(substr((string) $d['vale_desde'], 0, 10))); ?></td><td class="small"><?php echo h((string) $d['observacao']); ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </details>
  <?php endif; ?>
</section>

<section class="panel">
  <div class="linha-titulo"><h2><?php echo h(t('Cards do CRM · {n}', ['n' => count($cards)])); ?></h2><span class="muted small"><?php echo h(t('Pipeline, Onboarding e Active desde 7 dias atrás')); ?></span></div>
  <?php if (!$cards): ?><p class="muted"><?php echo h(t('Nenhum card no funil.')); ?></p><?php endif; ?>
  <div class="colunas-funil">
    <?php foreach ($porEtapa as $etapa => $lista): ?>
    <div class="coluna">
      <strong><?php echo h($etapa); ?> · <?php echo count($lista); ?></strong>
      <?php foreach ($lista as $c): ?>
      <div class="cartao">
        <div class="linha-titulo"><strong><?php echo h($c['cliente'] ?: t('sem cliente')); ?></strong><span class="tag"><?php echo h($c['tier'] ?: t('sem tier')); ?></span></div>
        <span class="muted small">CRM card #<?php echo (int) $c['id']; ?><?php echo $c['start_date'] ? ' · ' . h(t('início {dia}', ['dia' => dataBr($c['start_date'])])) : ''; ?><?php echo $c['contrato'] ? ' · ' . h(t('contrato {status}', ['status' => $c['contrato']])) : ''; ?></span>
        <?php if (!$c['tier'] || !$c['start_date']): ?><span class="tag laranja"><?php echo h(t('dados faltando no CRM: {campos}', ['campos' => !$c['tier'] && !$c['start_date'] ? t('tier e data de início') : (!$c['tier'] ? 'tier' : t('data de início'))])); ?></span><?php endif; ?>
        <?php $pv = $previsoes[(int) $c['id']] ?? null; if ($pv): ?>
          <span class="small"><?php echo h(t('Previsão: {perfil} · {base} · A {a} / C {c} / D {d} fãs/h', ['perfil' => strtolower((string) $pv['perfil']), 'base' => escRotulo('base_previsao', $pv['base']), 'a' => num($pv['fans_h_a']), 'c' => num($pv['fans_h_c']), 'd' => num($pv['fans_h_d'])])); ?><?php echo $rotuloDestino($pv) !== '' ? ' · ' . h(t('destino {destino}', ['destino' => $rotuloDestino($pv)])) : ''; ?></span>
        <?php endif; ?>
        <?php if ($ctxFunil): [$vol, $origemVol] = escFunilVolume($pv, $c['tier'] ?: null, $arquetipos); $sug = escFunilSugerir($vol, $c['tier'] ?: null, [(string) $c['cliente'], (string) ($pv['pagina'] ?? '')], $ctxFunil); ?>
        <?php if ($sug && $sug['top']): ?>
        <div class="funil-sugestao">
          <strong><?php echo h(t('Destinos indicados')); ?></strong>
          <span class="muted small"><?php echo h(t('Cerca de {a}/{c}/{d} postos (A/C/D) e {sr} SRs; turno principal {turno}. Volume: {origem}.', ['a' => $sug['postos']['A'], 'c' => $sug['postos']['C'], 'd' => $sug['postos']['D'], 'sr' => array_sum($sug['precisa']), 'turno' => ESC_SOL_TURNO_BLOCOS[$sug['principal']], 'origem' => $ORIGEM_VOL[$origemVol]])); ?></span>
          <ol class="funil-top">
            <?php foreach ($sug['top'] as $i => $s): $n = $s['nucleo']; $bl = ESC_SOL_TURNO_BLOCOS[$n['turno']];
                $motivos = [t('Capacity da U{u}: saldo {antes} → {depois} SRs com a página', ['u' => $n['unidade'], 'antes' => $s['saldo_u_antes'], 'depois' => $s['saldo_u_depois']])];
                $motivos[] = $verKpi ? ($n['nota'] !== null ? t('KPI da equipe {nota} (média dos SRs)', ['nota' => num($n['nota'], 2)]) : t('equipe sem KPI')) : t('KPI da equipe considerado');
                $motivos[] = $s['carga_sr'] !== null ? t('{carga} fãs/h por SR já no núcleo', ['carga' => num($s['carga_sr'], 1)]) : t('núcleo sem SRs');
                if ($s['afinidade']) { $motivos[] = t('a modelo já tem página nesta unidade ({pagina}, carteira {carteira})', $s['afinidade']); }
                if (($s['desvio'] ?? null) !== null) { $motivos[] = t('faturamento da U{u} no turno {turno}: {d} da média', ['u' => $n['unidade'], 'turno' => $n['turno'], 'd' => ($s['desvio'] > 0 ? '+' : '') . pct($s['desvio'], 0)]); }
                $rotulo = 'U' . $n['unidade'] . ' · ' . $bl . ' · ' . t('núcleo {n}', ['n' => $n['numero']]) . ' (OS ' . nomeCurto($n['os']) . ')';
                $motivoCampo = mb_substr(t('Sugestão do sistema, nota {nota}: {motivos}', ['nota' => $s['nota'], 'motivos' => implode('; ', $motivos)]), 0, 255); ?>
            <li>
              <div class="linha-titulo"><span><strong><?php echo ($i + 1) . 'º · ' . h($rotulo); ?></strong></span><span class="tag <?php echo $i === 0 ? 'verde' : ''; ?>"><?php echo h(t('nota {n}', ['n' => $s['nota']])); ?></span></div>
              <span class="small muted"><?php echo h(implode(' · ', $motivos)); ?></span>
              <?php if (escPode('decidir')): ?><button type="button" class="botao funil-usar" data-destino="<?php echo h($n['unidade'] . '|' . $n['turno'] . '|' . $n['id']); ?>" data-motivo="<?php echo h($motivoCampo); ?>"><?php echo h(t('Usar este destino')); ?></button><?php endif; ?>
            </li>
            <?php endforeach; ?>
          </ol>
          <span class="muted small"><?php echo h(isset($sug['peso']['equilibrio'])
              ? t('Um destino por unidade, na ordem da nota (0 a 100): Capacity {cap}, KPI {mer}, carga do núcleo {car}, afinidade com a modelo {afi} e equilíbrio das unidades {equ} (faturamento abaixo da média ganha); em página de tier alto o KPI pesa mais. A página entra nos 3 turnos da unidade; o núcleo indicado é o do turno principal.', ['cap' => $sug['peso']['cap_u'] + $sug['peso']['cap_t'], 'mer' => $sug['peso']['desempenho'], 'car' => $sug['peso']['carga'], 'afi' => $sug['peso']['afinidade'], 'equ' => $sug['peso']['equilibrio']])
              : t('Um destino por unidade, na ordem da nota (0 a 100): Capacity {cap}, KPI {mer}, carga do núcleo {car} e afinidade com a modelo {afi}; em página de tier alto o KPI pesa mais. A página entra nos 3 turnos da unidade; o núcleo indicado é o do turno principal.', ['cap' => $sug['peso']['cap_u'] + $sug['peso']['cap_t'], 'mer' => $sug['peso']['desempenho'], 'car' => $sug['peso']['carga'], 'afi' => $sug['peso']['afinidade']])); ?></span>
        </div>
        <?php endif; endif; ?>
        <?php if (escPode('decidir')): ?>
        <details><summary class="botao"><?php echo h(t('Ajustar previsão e destino')); ?></summary>
          <form method="post" action="<?php echo h(link_tela('funil')); ?>" class="form-grade">
            <?php echo campoCsrf(); ?><input type="hidden" name="crm_card_id" value="<?php echo (int) $c['id']; ?>">
            <label><?php echo h(t('Página')); ?><input type="search" name="pagina" list="lista-paginas" autocomplete="off" value="<?php echo h($pv['pagina'] ?? $c['cliente']); ?>"></label>
            <label><?php echo h(t('Perfil')); ?><select name="perfil"><?php foreach (['MAIN' => 'Main', 'VIP' => 'VIP', 'FREE' => 'Free', 'OFTV' => 'OFTV'] as $k => $r): ?><option value="<?php echo $k; ?>" <?php echo ($pv['perfil'] ?? 'MAIN') === $k ? 'selected' : ''; ?>><?php echo $r; ?></option><?php endforeach; ?></select></label>
            <label><?php echo h(t('Base')); ?><select name="base"><option value="ARQUETIPO"><?php echo h(t('Arquétipo')); ?></option><option value="REFERENCIA" <?php echo ($pv['base'] ?? '') === 'REFERENCIA' ? 'selected' : ''; ?>><?php echo h(t('Página de referência')); ?></option><option value="INFORMADO" <?php echo ($pv['base'] ?? '') === 'INFORMADO' ? 'selected' : ''; ?>><?php echo h(t('Fãs/h informados')); ?></option></select></label>
            <label><?php echo h(t('Página de referência')); ?><input type="search" name="pagina_referencia" list="lista-paginas" autocomplete="off" value="<?php echo h($pv['pagina_referencia'] ?? ''); ?>"></label>
            <label><?php echo h(t('Fãs/h {turno}', ['turno' => 'A'])); ?><input type="text" name="fans_h_a" value="<?php echo h($pv['fans_h_a'] ?? ''); ?>"></label>
            <label><?php echo h(t('Fãs/h {turno}', ['turno' => 'C'])); ?><input type="text" name="fans_h_c" value="<?php echo h($pv['fans_h_c'] ?? ''); ?>"></label>
            <label><?php echo h(t('Fãs/h {turno}', ['turno' => 'D'])); ?><input type="text" name="fans_h_d" value="<?php echo h($pv['fans_h_d'] ?? ''); ?>"></label>
            <label><?php echo h(t('Probabilidade (%, de 0 a 100)')); ?><input type="number" name="probabilidade" min="0" max="100" step="1" inputmode="numeric" value="<?php echo $pv && $pv['probabilidade'] !== null ? h(round((float) $pv['probabilidade'] * 100)) : ''; ?>"></label>
            <label><?php echo h(t('Data prevista')); ?><input type="date" name="data_prevista" value="<?php echo h($pv['data_prevista'] ?? ($c['start_date'] ? substr($c['start_date'], 0, 10) : '')); ?>"></label>
            <?php if ($destinoV4):
                $atualDest = $pv && !empty($pv['destino_unidade']) ? (int) $pv['destino_unidade'] . '|' . $pv['destino_turno'] . '|' . ((int) ($pv['destino_nucleo_id'] ?? 0) ?: '') : ''; ?>
            <label><?php echo h(t('Destino · unidade, turno e núcleo')); ?><select name="destino"><option value=""><?php echo h(t('(definir depois)')); ?></option>
              <?php foreach ([1, 2, 3] as $u): foreach (['A', 'C', 'D'] as $t): $v = $u . '|' . $t . '|'; ?>
              <option value="<?php echo $v; ?>" <?php echo $atualDest === $v ? 'selected' : ''; ?>>U<?php echo $u; ?> · <?php echo $t; ?> · <?php echo h(t('núcleo a definir')); ?></option>
              <?php foreach ($nucleosFunil as $nid => $n): if ((int) $n['unidade'] !== $u || $n['turno'] !== $t) { continue; } $v = $u . '|' . $t . '|' . $nid; ?>
              <option value="<?php echo $v; ?>" <?php echo $atualDest === $v ? 'selected' : ''; ?>>U<?php echo $u; ?> · <?php echo $t; ?> · <?php echo h(t('núcleo {n}', ['n' => (int) $n['numero']])); ?></option>
              <?php endforeach; endforeach; endforeach; ?>
            </select></label>
            <?php else: ?>
            <label><?php echo h(t('Destino · party (estrutura antiga; rode o ES_27 para unidade e núcleo)')); ?><select name="destino_party"><option value=""><?php echo h(t('(definir depois)')); ?></option><?php foreach ($partiesNomes as $pn): ?><option <?php echo ($pv['destino_party'] ?? '') === $pn ? 'selected' : ''; ?>><?php echo h($pn); ?></option><?php endforeach; ?></select></label>
            <?php endif; ?>
            <label><?php echo h(t('Motivo do destino')); ?><input type="text" name="destino_motivo" value="<?php echo h($pv['destino_motivo'] ?? ''); ?>"></label>
            <button type="submit" class="botao primario"><?php echo h(t('Salvar')); ?></button>
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
  <div class="linha-titulo"><h2><?php echo h(t('Arquétipos de página')); ?></h2><span class="muted small"><?php echo h(t('fãs/h médios dos últimos 28 dias · tier de tab_intraday_base')); ?></span></div>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th><?php echo h(t('Arquétipo')); ?></th><th class="num"><?php echo h(t('Páginas')); ?></th><th class="num">A</th><th class="num">C</th><th class="num">D</th><th><?php echo h(t('Faixa por página')); ?></th><th><?php echo h(t('Confiança')); ?></th></tr></thead>
    <tbody>
    <?php if (!$arquetipos): ?><tr><td colspan="12" class="muted"><?php echo h(t('Nada para mostrar.')); ?></td></tr><?php endif; ?>
    <?php foreach ($arquetipos as $a): [$conf, $cor] = $confianca((int) $a['paginas']); ?>
      <tr>
        <td><strong><?php echo h($a['tier'] . ' · ' . $a['perfil']); ?></strong></td>
        <td class="num"><?php echo (int) $a['paginas']; ?></td>
        <td class="num"><?php echo num($a['a']); ?></td><td class="num"><?php echo num($a['c']); ?></td><td class="num"><?php echo num($a['d']); ?></td>
        <td class="small"><?php echo h(t('{min} a {max}', ['min' => num($a['minimo']), 'max' => num($a['maximo'])])); ?></td>
        <td><span class="tag <?php echo $cor; ?>"><?php echo h($conf); ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="muted small"><?php echo h(t('Com menos de 3 páginas, a estimativa junta os perfis do mesmo tier; sem nenhuma, interpola entre os tiers vizinhos. O tier do cadastro de páginas está quase todo como C (padrão), por isso o tier vem da base intraday.')); ?></p>
</section>

<script>
// "Usar este destino": abre o ajuste do card, escolhe o destino e preenche o motivo (salvar continua com você).
document.addEventListener('click', function (ev) {
  var b = ev.target.closest('.funil-usar');
  if (!b) { return; }
  var card = b.closest('.cartao'), det = card && card.querySelector('details');
  if (!det) { return; }
  det.open = true;
  var sel = det.querySelector('select[name="destino"]'), mot = det.querySelector('input[name="destino_motivo"]');
  if (sel) { sel.value = b.dataset.destino; sel.dispatchEvent(new Event('change', { bubbles: true })); sel.focus(); }
  if (mot) { mot.value = b.dataset.motivo; }
});
</script>
