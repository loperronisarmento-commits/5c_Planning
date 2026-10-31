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
// Destinos indicados (lib/funil_sugestao.php): só na estrutura nova (ES_27). O mérito em número
// aparece só para quem pode ver o mérito (Planejamento, WFM e Admin).
$ctxFunil = $destinoV4 ? escFunilContexto($diaNucleos) : null;
$verMerito = escPodeReal('planejar');
$ORIGEM_VOL = ['previsao' => t('previsão salva'), 'arquetipo' => t('arquétipo do tier e perfil'), 'perfil' => t('média do perfil (sem tier)'), 'padrao' => t('valor padrão (sem arquétipo)')];
$confianca = static function (int $n): array {
    return $n >= 5 ? [t('Alta'), 'verde'] : ($n >= 3 ? [t('Média'), 'amarelo'] : [t('Baixa'), 'vermelho']);
};
?>
<header class="cabecalho">
  <span class="eyebrow"><?php echo h(t('Páginas')); ?></span>
  <h1><?php echo h(t('Funil de entrada')); ?></h1>
  <p class="muted"><?php echo h(t('Espelha o funil do CRM. Como o CRM normalmente só traz o tier, o volume de cada página nova vem do arquétipo: páginas ativas com faturamento e perfil parecidos.')); ?></p>
</header>

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
                $motivos[] = $verMerito ? ($n['nota'] !== null ? t('mérito da equipe {nota} (média dos SRs)', ['nota' => num($n['nota'], 2)]) : t('equipe sem nota de mérito')) : t('mérito da equipe considerado');
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
              ? t('Um destino por unidade, na ordem da nota (0 a 100): Capacity {cap}, mérito {mer}, carga do núcleo {car}, afinidade com a modelo {afi} e equilíbrio das unidades {equ} (faturamento abaixo da média ganha); em página de tier alto o mérito pesa mais. A página entra nos 3 turnos da unidade; o núcleo indicado é o do turno principal.', ['cap' => $sug['peso']['cap_u'] + $sug['peso']['cap_t'], 'mer' => $sug['peso']['merito'], 'car' => $sug['peso']['carga'], 'afi' => $sug['peso']['afinidade'], 'equ' => $sug['peso']['equilibrio']])
              : t('Um destino por unidade, na ordem da nota (0 a 100): Capacity {cap}, mérito {mer}, carga do núcleo {car} e afinidade com a modelo {afi}; em página de tier alto o mérito pesa mais. A página entra nos 3 turnos da unidade; o núcleo indicado é o do turno principal.', ['cap' => $sug['peso']['cap_u'] + $sug['peso']['cap_t'], 'mer' => $sug['peso']['merito'], 'car' => $sug['peso']['carga'], 'afi' => $sug['peso']['afinidade']])); ?></span>
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
