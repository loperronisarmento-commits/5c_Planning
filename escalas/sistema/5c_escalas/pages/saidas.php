<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/saidas.php
// Saídas de página detectadas pelo Capacity (fc_pagina_eventos) com o
// efeito calculado (fc_impacto_paginas) e a checagem de quem opera o
// sistema (5c_escalas.esc_saida_checagem).
// ===============================================

if (escPost() && ($_POST['acao'] ?? '') === 'aplicar') {
    // Saída aplicada pelo sistema: Capacity (com foto antes e depois), postos, vagas e escala.
    escExigir('planejar');
    $pagina = trim((string) ($_POST['pagina'] ?? ''));
    $desde = (string) ($_POST['desde'] ?? '');
    $eventoAplicar = (int) ($_POST['evento_id'] ?? 0) ?: null;
    if (preg_match('~^\d{4}-\d{2}-\d{2}$~', $desde)) {
        $desde = max($desde, date('Y-m-d'));
    }
    // Página que entra no lugar (opcional, ES_37): quem perder a vaga vai para ela.
    $entra = null;
    if (trim((string) ($_POST['entra_pagina'] ?? '')) !== '' && escSaidaComEntrada()) {
        $entra = ['pagina' => trim((string) $_POST['entra_pagina']), 'desde' => (string) ($_POST['entra_desde'] ?? ''),
                  'fans' => ['A' => $_POST['entra_fans_a'] ?? null, 'C' => $_POST['entra_fans_c'] ?? null, 'D' => $_POST['entra_fans_d'] ?? null],
                  'decisoes' => is_array($_POST['dec'] ?? null) ? $_POST['dec'] : []];
    }
    $r = escAplicarSaidaPagina($pagina, $desde, trim((string) ($_POST['motivo'] ?? $_POST['observacao'] ?? '')), $eventoAplicar, $entra);
    $ev = $eventoAplicar ? escQ1("SELECT page_id, data_evento FROM `5c_planning`.`fc_pagina_eventos` WHERE id = ? AND tipo = 'SAIDA'", [$eventoAplicar]) : null;
    if ($ev) {
        escW('INSERT INTO ' . escT('esc_saida_checagem') . " (evento_id, page_id, pagina, data_evento, decisao, observacao, decidido_em, decidido_por)
              VALUES (?, ?, ?, ?, 'CONFIRMADA', 'aplicada pelo sistema', NOW(), ?) AS n
              ON DUPLICATE KEY UPDATE decisao = n.decisao, observacao = n.observacao, decidido_em = n.decidido_em, decidido_por = n.decidido_por",
            [$eventoAplicar, $ev['page_id'], $pagina, substr((string) $ev['data_evento'], 0, 10), escUserId()]);
    }
    escVoltar('saidas', ['saida' => $r['id']], t('{pagina}: saída aplicada a partir de {dia}. {fechados} posto(s) fechado(s), {refeitos} refeito(s) sem a página, {vagas} SR(s) com vaga nova, {livres} livre(s) para o motor; {planos} escala(s) gerada(s) de novo.', [
        'pagina' => $pagina, 'dia' => dataBr($desde), 'fechados' => count($r['postos_fechados']), 'refeitos' => count($r['postos_refeitos']),
        'vagas' => count($r['vagas_passadas']), 'livres' => count($r['livres']), 'planos' => count($r['planos']),
    ]) . (!empty($r['ja_inativas_no_capacity']) ? ' ' . t('A página já estava desativada no Capacity: só a escala foi ajustada.') : '')
       . (!empty($r['entrada']) ? ' ' . t('{nova} entra no lugar a partir de {dia}: {n} SR(s) passaram para ela; {buffer} como buffer e {fora} fora da escala até lá.', [
           'nova' => $r['entrada']['pagina'], 'dia' => dataBr($r['entrada']['desde']), 'n' => count($r['entrada']['equipe']),
           'buffer' => count(array_filter($r['entrada']['sem_pagina'], static fn($x) => $x['decisao'] === 'BUFFER')),
           'fora' => count(array_filter($r['entrada']['sem_pagina'], static fn($x) => $x['decisao'] === 'REMOVER'))]) : ''));
}

// Checagem de uma saída: confirmada, "não saiu" (rechecar em N dias) ou de volta para "a conferir".
$decidir = static function (int $evento, string $decisao, string $obs): array {
    if (!in_array($decisao, ['CONFIRMADA', 'NAO_SAIU', 'PENDENTE'], true)) {
        throw new RuntimeException(t('Decisão inválida.'));
    }
    $e = escQ1("SELECT e.id, e.pagina, e.page_id, e.data_evento, i.reps_h_a, i.reps_h_c, i.reps_h_d, i.hc_total
                  FROM `5c_planning`.`fc_pagina_eventos` e
                  LEFT JOIN `5c_planning`.`fc_impacto_paginas` i ON i.ref_id = e.id AND i.tipo = 'SAIDA'
                   AND i.data_calculo = (SELECT MAX(data_calculo) FROM `5c_planning`.`fc_impacto_paginas`)
                 WHERE e.id = ? AND e.tipo = 'SAIDA'", [$evento]);
    if (!$e) {
        throw new RuntimeException(t('Saída não encontrada.'));
    }
    $efeito = $e['reps_h_a'] === null ? null : round(((float) $e['reps_h_a'] + (float) $e['reps_h_c'] + (float) $e['reps_h_d']) / 3, 2);
    $dias = (int) (escQ1('SELECT valor FROM ' . escT('esc_parametro') . " WHERE chave = 'saida_rechecar_d'")['valor'] ?? 3);
    escW('INSERT INTO ' . escT('esc_saida_checagem') . ' (evento_id, page_id, pagina, data_evento, efeito_reps_turno, efeito_pessoas, decisao, observacao, rechecar_em, decidido_em, decidido_por)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?) AS n
          ON DUPLICATE KEY UPDATE decisao = n.decisao, observacao = n.observacao, rechecar_em = n.rechecar_em, decidido_em = n.decidido_em, decidido_por = n.decidido_por', [
        $evento, $e['page_id'], $e['pagina'], substr($e['data_evento'], 0, 10), $efeito, $e['hc_total'], $decisao,
        mb_substr(trim($obs), 0, 500) ?: null,
        $decisao === 'NAO_SAIU' ? date('Y-m-d', strtotime('+' . $dias . ' day')) : null, escUserId(),
    ]);
    escLog('saida.checar', 'esc_saida_checagem', $evento, null, ['pagina' => $e['pagina'], 'decisao' => $decisao]);
    return ['pagina' => $e['pagina'], 'dias' => $dias];
};

// Prévia (GET ?previa=1): o que a saída faria, antes de aplicar.
$previa = null;
if (!escPost() && !empty($_GET['previa']) && escPode('planejar')) {
    $pgPrevia = trim((string) ($_GET['pagina'] ?? ''));
    $desdePrevia = preg_match('~^\d{4}-\d{2}-\d{2}$~', (string) ($_GET['desde'] ?? '')) ? (string) $_GET['desde'] : '';
    if ($pgPrevia !== '' && $desdePrevia !== '') {
        $previa = ['pagina' => $pgPrevia, 'desde' => $desdePrevia, 'evento' => (int) ($_GET['evento'] ?? 0) ?: null,
                   'motivo' => mb_substr(trim((string) ($_GET['motivo'] ?? '')), 0, 255), 'r' => escSaidaPrevia([$pgPrevia], $desdePrevia),
                   'entrada' => null, 'entrada_erro' => null, 'entra' => null];
        $nomesPrevia = escNomes();
        // Página que entra no lugar (opcional): o plano sai na própria prévia.
        if (escSaidaComEntrada() && trim((string) ($_GET['entra'] ?? '')) !== '') {
            $previa['entra'] = ['pagina' => trim((string) $_GET['entra']), 'desde' => (string) ($_GET['entra_desde'] ?? $desdePrevia),
                'fans' => ['A' => $_GET['fans_a'] ?? null, 'C' => $_GET['fans_c'] ?? null, 'D' => $_GET['fans_d'] ?? null]];
            try {
                $previa['entrada'] = escSaidaPlanoEntrada($pgPrevia, $desdePrevia, $previa['entra']);
            } catch (RuntimeException $e) {
                $previa['entrada_erro'] = $e->getMessage();
            }
        }
    }
}

if (escPost() && ($_POST['acao'] ?? '') === 'lote') {
    // Várias saídas selecionadas de uma vez.
    $ids = array_values(array_unique(array_filter(array_map('intval', is_array($_POST['evento_ids'] ?? null) ? $_POST['evento_ids'] : []))));
    $decisao = (string) ($_POST['decisao'] ?? '');
    $obs = (string) ($_POST['observacao'] ?? '');
    if (!$ids) {
        throw new RuntimeException(t('Selecione ao menos uma saída.'));
    }
    if ($decisao === 'APLICAR') {
        // Confirmar e aplicar: as páginas da mesma data saem juntas (um recálculo do Capacity e da escala).
        escExigir('planejar');
        $eventos = escQ("SELECT id, pagina, page_id, data_evento FROM `5c_planning`.`fc_pagina_eventos` WHERE tipo = 'SAIDA' AND id IN (" . escIn($ids) . ')', $ids);
        // Saída já aplicada (mesmo evento) que veio na seleção: fica de fora, sem travar as outras.
        $jaAplicadas = [];
        foreach (escQ('SELECT DISTINCT evento_id FROM ' . escT('esc_saida_pagina') . " WHERE status = 'APLICADA' AND evento_id IN (" . escIn($ids) . ')', $ids) as $a) {
            $jaAplicadas[(int) $a['evento_id']] = true;
        }
        $puladas = array_values(array_map(static fn($ev) => $ev['pagina'], array_filter($eventos, static fn($ev) => isset($jaAplicadas[(int) $ev['id']]))));
        $eventos = array_values(array_filter($eventos, static fn($ev) => !isset($jaAplicadas[(int) $ev['id']])));
        if (!$eventos) {
            throw new RuntimeException(t('Todas as saídas selecionadas já foram aplicadas.'));
        }
        $porData = [];
        foreach ($eventos as $ev) {
            $porData[max(substr((string) $ev['data_evento'], 0, 10), date('Y-m-d'))][] = $ev;
        }
        ksort($porData);
        // Valida cada saída antes de aplicar a primeira (um erro no meio não deixa metade aplicada)
        // e mostra todos os problemas de uma vez.
        // Página fora do Capacity e da escala (saiu antes da estrutura nova): nada a aplicar, só confirma.
        $problemas = [];
        $semNada = [];
        foreach ($porData as $desdeV => $evsV) {
            foreach ($evsV as $k => $ev) {
                try {
                    if (escSaidaValidar([['pagina' => $ev['pagina'], 'evento_id' => (int) $ev['id']]], $desdeV, true)['inexistentes']) {
                        $semNada[] = $ev;
                        unset($porData[$desdeV][$k]);
                    }
                } catch (RuntimeException $e) {
                    $problemas[] = $e->getMessage();
                }
            }
            if (!$porData[$desdeV]) {
                unset($porData[$desdeV]);
            }
        }
        if ($problemas) {
            throw new RuntimeException(tn(count($problemas), 'Nada foi aplicado. Desmarque esta saída ou corrija e aplique de novo: {lista}', 'Nada foi aplicado. Desmarque estas {n} saídas ou corrija e aplique de novo: {lista}', ['lista' => implode(' · ', $problemas)]));
        }
        foreach ($semNada as $ev) {
            escW('INSERT INTO ' . escT('esc_saida_checagem') . " (evento_id, page_id, pagina, data_evento, decisao, observacao, decidido_em, decidido_por)
                  VALUES (?, ?, ?, ?, 'CONFIRMADA', 'fora do Capacity e da escala: nada a ajustar', NOW(), ?) AS n
                  ON DUPLICATE KEY UPDATE decisao = n.decisao, observacao = n.observacao, decidido_em = n.decidido_em, decidido_por = n.decidido_por",
                [(int) $ev['id'], $ev['page_id'], $ev['pagina'], substr((string) $ev['data_evento'], 0, 10), escUserId()]);
        }
        $semNadaTxt = $semNada ? ' ' . tn(count($semNada), '{n} saída só foi confirmada: a página já não estava no Capacity nem na escala ({lista}).', '{n} saídas só foram confirmadas: as páginas já não estavam no Capacity nem na escala ({lista}).', ['lista' => implode(', ', array_unique(array_column($semNada, 'pagina')))]) : '';
        if (!$porData) {
            escVoltar('saidas', ['dias' => $_GET['dias'] ?? 21], trim($semNadaTxt . ($puladas ? ' ' . tn(count($puladas), '{n} já estava aplicada e ficou de fora ({lista}).', '{n} já estavam aplicadas e ficaram de fora ({lista}).', ['lista' => implode(', ', $puladas)]) : '')));
        }
        $msg = [];
        $inativasTotal = [];
        foreach ($porData as $desde => $evs) {
            $r = escAplicarSaidasPaginas(array_map(static fn($ev) => ['pagina' => $ev['pagina'], 'evento_id' => (int) $ev['id']], $evs), $desde, trim($obs));
            $inativasTotal = array_merge($inativasTotal, $r['ja_inativas_no_capacity'] ?? []);
            foreach ($evs as $ev) {
                escW('INSERT INTO ' . escT('esc_saida_checagem') . " (evento_id, page_id, pagina, data_evento, decisao, observacao, decidido_em, decidido_por)
                      VALUES (?, ?, ?, ?, 'CONFIRMADA', 'aplicada pelo sistema', NOW(), ?) AS n
                      ON DUPLICATE KEY UPDATE decisao = n.decisao, observacao = n.observacao, decidido_em = n.decidido_em, decidido_por = n.decidido_por",
                    [(int) $ev['id'], $ev['page_id'], $ev['pagina'], substr((string) $ev['data_evento'], 0, 10), escUserId()]);
            }
            $msg[] = t('{n} página(s) a partir de {dia}: {fechados} posto(s) fechado(s), {refeitos} refeito(s), {vagas} SR(s) com vaga nova, {livres} livre(s); {planos} escala(s) gerada(s) de novo', [
                'n' => count($evs), 'dia' => dataBr($desde), 'fechados' => count($r['postos_fechados']), 'refeitos' => count($r['postos_refeitos']),
                'vagas' => count($r['vagas_passadas']), 'livres' => count($r['livres']), 'planos' => count($r['planos']),
            ]);
            $ultima = $r['id'];
        }
        escVoltar('saidas', ['dias' => $_GET['dias'] ?? 21, 'saida' => $ultima ?? null], t('Saídas aplicadas. {detalhe}.', ['detalhe' => implode(' · ', $msg)])
            . ($inativasTotal ? ' ' . tn(count($inativasTotal), '{n} página já estava desativada no Capacity: só a escala foi ajustada ({lista}).', '{n} páginas já estavam desativadas no Capacity: só a escala foi ajustada ({lista}).', ['lista' => implode(', ', $inativasTotal)]) : '')
            . ($puladas ? ' ' . tn(count($puladas), '{n} já estava aplicada e ficou de fora ({lista}).', '{n} já estavam aplicadas e ficaram de fora ({lista}).', ['lista' => implode(', ', $puladas)]) : '')
            . $semNadaTxt);
    }
    escExigir('decidir');
    $feitas = [];
    foreach ($ids as $id) {
        $feitas[] = $decidir($id, $decisao, $obs)['pagina'];
    }
    $varsLote = ['n' => count($feitas), 'paginas' => implode(', ', $feitas)];
    escVoltar('saidas', ['dias' => $_GET['dias'] ?? 21], $decisao === 'CONFIRMADA' ? t('{n} saída(s) confirmada(s): {paginas}.', $varsLote)
        : ($decisao === 'NAO_SAIU' ? t('{n} saída(s) marcada(s) como "não saiu": {paginas}.', $varsLote) : t('{n} saída(s) de volta para "a conferir": {paginas}.', $varsLote)));
}

if (escPost()) {
    escExigir('decidir');
    $decisao = (string) ($_POST['decisao'] ?? '');
    $r = $decidir((int) ($_POST['evento_id'] ?? 0), $decisao, (string) ($_POST['observacao'] ?? ''));
    escVoltar('saidas', ['dias' => $_GET['dias'] ?? 21], $decisao === 'CONFIRMADA' ? t('{pagina}: saída confirmada.', ['pagina' => $r['pagina']])
        : ($decisao === 'NAO_SAIU' ? t('{pagina}: marcada como "não saiu"; nova checagem em {dias} dias.', ['pagina' => $r['pagina'], 'dias' => $r['dias']]) : t('{pagina}: voltou para "a conferir".', ['pagina' => $r['pagina']])));
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

// Saídas aplicadas pelo sistema e a comparação do Capacity antes × depois.
$aplicadas = [];
$aplicadaEvento = [];
$aplicadaPagina = [];
$comparacao = [];
try {
    $aplicadas = escQ('SELECT id, pagina, vale_desde, motivo, status, capacity_antes, capacity_depois, resultado, erro, criado_em FROM ' . escT('esc_saida_pagina') . ' ORDER BY id DESC LIMIT 20');
    // Saídas já aplicadas: por evento detectado e por página (a linha não oferece aplicar de novo).
    foreach (escQ('SELECT evento_id, pagina, vale_desde, criado_em FROM ' . escT('esc_saida_pagina') . " WHERE status = 'APLICADA'") as $ap) {
        if ($ap['evento_id']) {
            $aplicadaEvento[(int) $ap['evento_id']] = $ap;
        }
        $aplicadaPagina[$ap['pagina']] = $ap;
    }
    $saidaSel = (int) ($_GET['saida'] ?? ($aplicadas[0]['id'] ?? 0));
    foreach ($aplicadas as $a) {
        if ((int) $a['id'] === $saidaSel && $a['capacity_antes'] && $a['capacity_depois']) {
            $versoes = escQ('SELECT id, data_calculo, momento, postos, titulares, buffer, precisa FROM ' . escT('esc_capacity_versao') . ' WHERE id IN (?, ?)', [$a['capacity_antes'], $a['capacity_depois']]);
            $ut = [];
            foreach (escQ("SELECT versao_id, unidade, turno, postos, titulares, buffer, precisa, tem, saldo FROM " . escT('esc_capacity_versao_ut') . " WHERE versao_id IN (?, ?) ORDER BY unidade, FIELD(turno, 'A', 'C', 'D')", [$a['capacity_antes'], $a['capacity_depois']]) as $l) {
                $ut[$l['unidade'] . $l['turno']][(int) $l['versao_id'] === (int) $a['capacity_antes'] ? 'antes' : 'depois'] = $l;
            }
            $comparacao = ['saida' => $a, 'versoes' => array_column($versoes, null, 'momento'), 'ut' => $ut];
        }
    }
} catch (Throwable $e) {
    appLog('warning', 'Saídas aplicadas indisponíveis (rodar ES_16)', ['erro' => $e->getMessage()]);
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
  <span class="eyebrow"><?php echo h(t('Páginas')); ?></span>
  <h1><?php echo h(t('Saídas de página')); ?></h1>
  <p class="muted"><?php echo h(t('Saídas que o Capacity detectou (página inativada no cadastro ou sem escala há 3 dias) e quanto cada uma tira da necessidade. Quem opera o sistema confere cada uma: confirmar ou "não saiu".')); ?></p>
</header>

<section class="kpis">
  <div class="kpi"><span class="muted"><?php echo h(t('A conferir')); ?></span><strong class="<?php echo $pendentes ? 'laranja' : ''; ?>"><?php echo $pendentes; ?></strong><span class="muted small"><?php echo h(t('de {total} saídas nos últimos {dias} dias', ['total' => count($saidas), 'dias' => $dias])); ?></span></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Efeito por turno')); ?></span><strong class="azul"><?php echo num($soma, 2); ?> reps/h</strong><span class="muted small"><?php echo h(t('sem contar as marcadas "não saiu"')); ?></span></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Efeito em pessoas')); ?></span><strong class="azul"><?php echo num($somaHc, 1); ?></strong><span class="muted small"><?php echo h(t('com presença 75% e absenteísmo')); ?></span></div>
</section>

<?php if (escPode('planejar')): ?>
<section class="panel">
  <h2><?php echo h(t('Aplicar saída de página')); ?></h2>
  <p class="muted"><?php echo h(t('Para quando a página saiu e ainda está ativa no cadastro, ou para aplicar uma saída detectada. Faz tudo de uma vez:')); ?></p>
  <ul class="muted small">
    <li><?php echo h(t('guarda a foto do Capacity, fecha a página a partir da data e recalcula;')); ?></li>
    <li><?php echo t('fecha os postos só dela e refaz sem ela os que têm outras páginas, {destaque};', ['destaque' => '<strong>' . h(t('com a mesma equipe')) . '</strong>']); ?></li>
    <li><?php echo h(t('quem ficou sem vaga vai para uma vaga sem ninguém do próprio núcleo, de preferência numa página que já conhece;')); ?></li>
    <li><?php echo h(t('gera de novo as escalas da data em diante. Os ajustes travados ficam.')); ?></li>
  </ul>
  <form method="get" action="<?php echo h(MODULE_URL); ?>/index.php" class="acoes">
    <input type="hidden" name="p" value="saidas"><input type="hidden" name="previa" value="1">
    <label><?php echo h(t('Página')); ?><input type="text" name="pagina" list="lista-paginas" required size="28" placeholder="<?php echo h(t('buscar página')); ?>"></label>
    <label><?php echo h(t('Sem a página a partir de')); ?><input type="date" name="desde" required min="<?php echo h(date('Y-m-d')); ?>" value="<?php echo h(date('Y-m-d', strtotime('+1 day'))); ?>"></label>
    <label><?php echo h(t('Motivo')); ?><input type="text" name="motivo" size="24" placeholder="<?php echo h(t('ex.: modelo saiu')); ?>" onkeydown="if (event.key === 'Enter') { event.preventDefault(); }"></label>
    <button type="submit" class="botao primario"><?php echo h(t('Ver prévia')); ?></button>
  </form>
  <p class="muted small"><?php echo h(t('A prévia mostra os postos que fecham, os refeitos, quem perde a vaga fixa e as semanas geradas de novo, antes de aplicar.')); ?></p>
</section>
<?php endif; ?>

<?php if ($previa): $pv = $previa['r']; ?>
<section class="panel" id="previa" aria-labelledby="previa-titulo">
  <h2 id="previa-titulo"><?php echo h(t('Prévia · saída de {pagina} a partir de {dia}', ['pagina' => $previa['pagina'], 'dia' => dataBr($previa['desde'])])); ?></h2>
  <?php if ($pv['ja_aplicada']): ?><div class="aviso erro" role="alert"><?php echo h(t('Já tem saída aplicada para esta página ({lista}). Confira antes de aplicar de novo.', ['lista' => implode(', ', array_map(static fn($j) => t('a partir de {dia}, aplicada em {quando}', ['dia' => dataBr($j['vale_desde']), 'quando' => escDataHora((string) $j['criado_em'])]), $pv['ja_aplicada']))])); ?></div><?php endif; ?>
  <div class="kpis">
    <div class="kpi"><span class="muted"><?php echo h(t('Postos que fecham')); ?></span><strong><?php echo count($pv['fechados']); ?></strong><span class="muted small"><?php echo h(t('só tinham esta página')); ?></span></div>
    <div class="kpi"><span class="muted"><?php echo h(t('Postos refeitos')); ?></span><strong><?php echo count($pv['refeitos']); ?></strong><span class="muted small"><?php echo h(t('continuam com as outras páginas e a mesma equipe')); ?></span></div>
    <div class="kpi"><span class="muted"><?php echo h(t('SRs sem vaga fixa')); ?></span><strong class="<?php echo $pv['sem_vaga'] ? 'laranja' : ''; ?>"><?php echo count($pv['sem_vaga']); ?></strong><span class="muted small"><?php echo h(t('vão para uma vaga sem ninguém do próprio núcleo, ou ficam livres para o motor')); ?></span></div>
    <div class="kpi"><span class="muted"><?php echo h(t('Semanas geradas de novo')); ?></span><strong><?php echo count($pv['semanas']); ?></strong><span class="muted small"><?php echo h(t('{semanas} · ajustes travados ficam', ['semanas' => implode(', ', array_map('dataBr', $pv['semanas'])) ?: t('nenhuma')])); ?></span></div>
  </div>
  <?php if ($pv['fechados'] || $pv['refeitos']): ?>
  <ul class="regras small">
    <?php foreach ($pv['fechados'] as $f): ?><li><strong><?php echo h(t('Fecha')); ?></strong> <?php echo h(t('{posto} · equipe: {equipe}', ['posto' => $f['posto'], 'equipe' => implode(', ', array_map(static fn($r) => nomeCurto($nomesPrevia[$r] ?? $r), $f['equipe'])) ?: '—'])); ?></li><?php endforeach; ?>
    <?php foreach ($pv['refeitos'] as $f): ?><li><strong><?php echo h(t('Refaz')); ?></strong> <?php echo h(t('{posto} → fica {fica}', ['posto' => $f['posto'], 'fica' => $f['fica']])); ?></li><?php endforeach; ?>
  </ul>
  <?php else: ?>
  <p class="muted small"><?php echo h(t('Nenhum posto da estrutura nova tem esta página a partir dessa data. A saída só fecha a página no Capacity e gera as semanas de novo.')); ?></p>
  <?php endif; ?>
  <?php if (escSaidaComEntrada()):
      $pe = $previa['entrada'];
      $cands = escSaidaCandidatasEntrada();
      $nucTxt = [];
      foreach (escQ('SELECT id, unidade, turno, numero, decurio_nome FROM ' . escT('esc_nucleo')) as $n) {
          $nucTxt[(int) $n['id']] = 'U' . $n['unidade'] . ' · ' . ESC_SOL_TURNO_BLOCOS[$n['turno']] . ' · ' . t('núcleo {n}', ['n' => $n['numero']]) . ($n['decurio_nome'] ? ' (' . nomeCurto($n['decurio_nome']) . ')' : '');
      }
  ?>
  <h3><?php echo h(t('Página que entra no lugar (opcional)')); ?></h3>
  <p class="muted small"><?php echo h(t('Se outra página vai ocupar o lugar desta, quem perder a vaga fixa passa para ela, nos mesmos núcleos, a partir da data de entrada. Até lá, quem ficar sem página vira buffer (-2) se falta gente no turno da unidade, ou sai da escala nesses dias.')); ?></p>
  <form method="get" action="<?php echo h(MODULE_URL); ?>/index.php#previa" class="form-grade saida-entrada">
    <input type="hidden" name="p" value="saidas"><input type="hidden" name="previa" value="1">
    <input type="hidden" name="pagina" value="<?php echo h($previa['pagina']); ?>"><input type="hidden" name="desde" value="<?php echo h($previa['desde']); ?>">
    <?php if ($previa['evento']): ?><input type="hidden" name="evento" value="<?php echo (int) $previa['evento']; ?>"><?php endif; ?>
    <input type="hidden" name="motivo" value="<?php echo h($previa['motivo']); ?>">
    <label><?php echo h(t('Página (Funil ou site da 5C)')); ?><input type="search" name="entra" list="lista-entra" value="<?php echo h((string) ($previa['entra']['pagina'] ?? '')); ?>" autocomplete="off" placeholder="<?php echo h(t('nome da página')); ?>"></label>
    <datalist id="lista-entra"><?php foreach ($cands as $c): ?><option value="<?php echo h($c['pagina']); ?>"><?php echo h(($c['origem'] === 'FUNIL' ? t('Funil') : t('site da 5C')) . (!$c['page_id'] ? ' · ' . t('ainda não está no site') : '') . ($c['data'] ? ' · ' . dataBr($c['data']) : '')); ?></option><?php endforeach; ?></datalist>
    <label><?php echo h(t('Entra em')); ?><input type="date" name="entra_desde" value="<?php echo h((string) ($previa['entra']['desde'] ?? $previa['desde'])); ?>" min="<?php echo h($previa['desde']); ?>"></label>
    <?php foreach (['A' => 'fans_a', 'C' => 'fans_c', 'D' => 'fans_d'] as $tt => $campo): ?>
    <label><?php echo h(t('Fãs/h {turno}', ['turno' => ESC_SOL_TURNO_BLOCOS[$tt]])); ?><input type="number" name="<?php echo $campo; ?>" min="0" step="0.5" inputmode="decimal" value="<?php echo h((string) ($previa['entra']['fans'][$tt] ?? '')); ?>" placeholder="<?php echo h(t('igual à que sai')); ?>"></label>
    <?php endforeach; ?>
    <div class="acoes"><button type="submit" class="botao"><?php echo h(t('Ver com a página nova')); ?></button><?php if ($previa['entra']): ?> <a class="botao" href="<?php echo h(link_tela('saidas', array_filter(['previa' => 1, 'pagina' => $previa['pagina'], 'desde' => $previa['desde'], 'evento' => $previa['evento'], 'motivo' => $previa['motivo']]))); ?>#previa"><?php echo h(t('Sem página nova')); ?></a><?php endif; ?></div>
  </form>
  <?php if ($previa['entrada_erro']): ?><p class="aviso erro" role="alert"><?php echo h($previa['entrada_erro']); ?></p><?php endif; ?>
  <?php if ($pe): ?>
  <div class="tabela-rolagem"><table>
    <thead><tr><th><?php echo h(t('Turno')); ?></th><th class="num"><?php echo h(t('Fãs/h')); ?></th><th class="num"><?php echo h(t('Postos')); ?></th><th><?php echo h(t('Núcleo dono')); ?></th><th><?php echo h(t('Núcleo de cobertura')); ?></th></tr></thead>
    <tbody><?php foreach ($pe['turnos'] as $tt => $x): ?><tr><td><?php echo chipTurno($tt); ?> <?php echo h(ESC_SOL_TURNO_BLOCOS[$tt]); ?></td><td class="num"><?php echo num($x['fans'], 1); ?></td><td class="num"><?php echo (int) $x['postos']; ?></td><td class="small"><?php echo h($nucTxt[$x['dono']] ?? '—'); ?></td><td class="small"><?php echo h($x['cob'] ? ($nucTxt[$x['cob']] ?? '—') : '—'); ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
  <?php endif; ?>

  <form method="post" action="<?php echo h(link_tela('saidas')); ?>" class="acoes" data-confirmar="<?php echo h(t('Aplicar a saída de {pagina} a partir de {dia}?', ['pagina' => $previa['pagina'], 'dia' => dataBr($previa['desde'])]) . "\n\n"
      . t('{fechados} posto(s) fecham, {refeitos} refeito(s), {semvaga} SR(s) sem vaga fixa, {semanas} semana(s) geradas de novo. Recalcula o Capacity. Não dá para desfazer pelo sistema.', [
          'fechados' => count($pv['fechados']), 'refeitos' => count($pv['refeitos']), 'semvaga' => count($pv['sem_vaga']), 'semanas' => count($pv['semanas']),
      ])); ?>" data-longo="<?php echo h(t('Aplicando a saída… recalcula o Capacity e a escala, pode levar alguns minutos.')); ?>">
    <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="aplicar">
    <input type="hidden" name="pagina" value="<?php echo h($previa['pagina']); ?>"><input type="hidden" name="desde" value="<?php echo h($previa['desde']); ?>">
    <?php if ($previa['evento']): ?><input type="hidden" name="evento_id" value="<?php echo (int) $previa['evento']; ?>"><?php endif; ?>
    <input type="hidden" name="motivo" value="<?php echo h($previa['motivo']); ?>">
    <?php if (!empty($pe)): ?>
    <input type="hidden" name="entra_pagina" value="<?php echo h($pe['pagina']); ?>"><input type="hidden" name="entra_desde" value="<?php echo h($pe['desde']); ?>">
    <?php foreach (['A' => 'entra_fans_a', 'C' => 'entra_fans_c', 'D' => 'entra_fans_d'] as $tt => $campo): ?><input type="hidden" name="<?php echo $campo; ?>" value="<?php echo h((string) ($previa['entra']['fans'][$tt] ?? '')); ?>"><?php endforeach; ?>
    <div class="tabela-rolagem largo"><table>
      <thead><tr><th>SR</th><th><?php echo h(t('Na página nova')); ?></th><th><?php echo h(t('De {de} a {ate} (sem página)', ['de' => dataBr($previa['desde']), 'ate' => dataBr(escMaisDias($pe['desde'], -1))])); ?></th></tr></thead>
      <tbody>
      <?php foreach ($pe['equipe'] as $rep => $e): $sp = $pe['sem_pagina'][$rep] ?? null; ?>
        <tr><td><strong><?php echo h(nomeCurto($nomesPrevia[$rep] ?? $rep)); ?></strong> <span class="mono muted small"><?php echo h($rep); ?></span><br><span class="small muted">SR <?php echo (int) $e['nivel']; ?></span></td>
          <td class="small"><?php echo chipTurno($e['turno']); ?> <?php echo h(t('vaga de {vaga}', ['vaga' => $e['vaga'] === 'DONO' ? t('dono') : t('cobertura')])); ?> · <?php echo h($e['posto_idx'] === 0 ? t('titular') : t('assistente')); ?></td>
          <td class="small"><?php if ($sp): ?>
            <select name="dec[<?php echo h($rep); ?>]" aria-label="<?php echo h(t('Dias sem página de {rep}', ['rep' => $rep])); ?>">
              <option value="AUTO"><?php echo h(t('Automático: {decisao}', ['decisao' => $sp['auto'] === 'BUFFER' ? t('buffer (-2)') : t('fora da escala')])); ?></option>
              <option value="BUFFER"><?php echo h(t('Buffer (-2)')); ?></option>
              <option value="REMOVER"><?php echo h(t('Fora da escala')); ?></option>
            </select>
            <span class="muted"><?php echo h($sp['saldo'] === null ? t('sem saldo do Capacity') : t('saldo do turno: {s}', ['s' => num($sp['saldo'], 0)])); ?></span>
          <?php else: ?><span class="muted"><?php echo h(t('entra no mesmo dia: sem dias vazios')); ?></span><?php endif; ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$pe['equipe']): ?><tr><td colspan="3" class="muted"><?php echo h(t('Ninguém perde a vaga fixa: os postos desta página têm outras páginas. A página nova entra com a equipe montada pelo motor.')); ?></td></tr><?php endif; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
    <button type="submit" class="botao perigo-cheio"><?php echo h(!empty($pe) ? t('Aplicar saída e entrada de {pagina}', ['pagina' => $pe['pagina']]) : t('Aplicar saída')); ?></button>
    <a class="botao" href="<?php echo h(link_tela('saidas')); ?>"><?php echo h(t('Cancelar')); ?></a>
  </form>
</section>
<?php endif; ?>

<?php if ($aplicadas): ?>
<section class="panel" id="aplicadas">
  <h2><?php echo h(t('Saídas aplicadas pelo sistema')); ?></h2>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th><?php echo h(t('Quando')); ?></th><th><?php echo h(t('Página')); ?></th><th><?php echo h(t('A partir de')); ?></th><th class="num"><?php echo h(t('Postos fechados')); ?></th><th class="num"><?php echo h(t('Refeitos')); ?></th><th class="num"><?php echo h(t('SRs com vaga nova')); ?></th><th class="num"><?php echo h(t('Livres')); ?></th><th><?php echo h(t('Escalas')); ?></th><th></th></tr></thead>
    <tbody>
    <?php $nomesAplic = escNomes(); foreach ($aplicadas as $a): $r = json_decode((string) $a['resultado'], true) ?: []; ?>
      <tr>
        <td class="small"><?php echo h(escDataHora((string) $a['criado_em'])); ?></td>
        <td><strong><?php echo h($a['pagina']); ?></strong><?php echo $a['motivo'] ? '<br><span class="muted small">' . h($a['motivo']) . '</span>' : ''; ?><?php echo !empty($r['lote_de']) ? '<br><span class="muted small">' . h(t('aplicada em lote com {n} outra(s); números na primeira do lote', ['n' => count($r['paginas'] ?? []) - 1])) . '</span>' : (!empty($r['paginas']) ? '<br><span class="muted small">' . h(t('lote: {paginas}', ['paginas' => implode(', ', $r['paginas'])])) . '</span>' : ''); ?><?php echo $a['status'] === 'ERRO' ? '<br><span class="tag laranja">' . h(t('erro')) . '</span> <span class="small">' . h((string) $a['erro']) . '</span>' : ''; ?><?php if (!empty($r['entrada'])): $en = $r['entrada']; $plE = escPlanoAtual(segundaDaSemana($en['desde'])); ?>
          <br><span class="small"><?php echo h(t('→ {nova} entra em {dia}', ['nova' => $en['pagina'], 'dia' => dataBr($en['desde'])])); ?><?php if ($plE): ?> · <a href="<?php echo h(link_tela('grade', ['plano' => (int) $plE['id']])); ?>"><?php echo h(t('ver na Grade (CSV)')); ?></a><?php endif; ?></span>
          <details class="small"><summary><?php echo h(t('{n} SR(s) na página nova', ['n' => count($en['equipe'])])); ?></summary><ul>
            <?php foreach ($en['equipe'] as $x): $sp = array_values(array_filter($en['sem_pagina'], static fn($y) => $y['rep'] === $x['rep']))[0] ?? null; ?>
            <li><?php echo h(nomeCurto($nomesAplic[$x['rep']] ?? $x['rep'])); ?> <span class="mono muted"><?php echo h($x['rep']); ?></span> · <?php echo h($x['turno'] . ' · ' . ($x['vaga'] === 'DONO' ? t('dono') : t('cobertura'))); ?><?php if ($sp): ?> · <?php echo h(t('{de} a {ate}: {decisao}', ['de' => dataBr($sp['de']), 'ate' => dataBr($sp['ate']), 'decisao' => $sp['decisao'] === 'BUFFER' ? t('buffer (-2)') : t('fora da escala')])); ?><?php endif; ?></li>
            <?php endforeach; ?>
          </ul></details>
          <?php endif; ?></td>
        <td><?php echo h(dataBr($a['vale_desde'])); ?></td>
        <td class="num"><?php echo count($r['postos_fechados'] ?? []); ?></td>
        <td class="num"><?php echo count($r['postos_refeitos'] ?? []); ?></td>
        <td class="num"><?php echo count($r['vagas_passadas'] ?? []); ?></td>
        <td class="num"><?php echo count($r['livres'] ?? []); ?></td>
        <td class="small"><?php foreach ($r['planos'] ?? [] as $pl): ?><a href="<?php echo h(link_tela('grade', ['plano' => $pl['plano_id']])); ?>"><?php echo h(dataBr($pl['semana'])); ?></a> <?php endforeach; ?></td>
        <td><a class="botao" href="<?php echo h(link_tela('saidas', ['saida' => $a['id']])); ?>"><?php echo h(t('Comparar')); ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if ($comparacao): $va = $comparacao['versoes']['ANTES'] ?? null; $vd = $comparacao['versoes']['DEPOIS'] ?? null; ?>
  <h3><?php echo h(t('Capacity antes × depois · {pagina} (cálculo de {dia})', ['pagina' => $comparacao['saida']['pagina'], 'dia' => dataBr($va['data_calculo'] ?? '')])); ?></h3>
  <?php if ($va && $vd): ?>
  <div class="kpis">
    <?php foreach (['postos' => t('Postos'), 'titulares' => t('Titulares'), 'buffer' => t('Buffer'), 'precisa' => t('Precisa')] as $c => $rot): $dlt = (int) $vd[$c] - (int) $va[$c]; ?>
    <div class="kpi"><span class="muted"><?php echo h($rot); ?></span><strong><?php echo (int) $va[$c]; ?> → <?php echo (int) $vd[$c]; ?></strong><span class="small <?php echo $dlt < 0 ? 'verde' : ($dlt > 0 ? 'laranja' : 'muted'); ?>"><?php echo ($dlt > 0 ? '+' : '') . $dlt; ?></span></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th><?php echo h(t('Unidade · turno')); ?></th><th class="num"><?php echo h(t('Postos')); ?></th><th class="num"><?php echo h(t('Titulares')); ?></th><th class="num"><?php echo h(t('Buffer')); ?></th><th class="num"><?php echo h(t('Precisa')); ?></th><th class="num"><?php echo h(t('Tem')); ?></th><th class="num"><?php echo h(t('Saldo')); ?></th></tr></thead>
    <tbody>
    <?php foreach ($comparacao['ut'] as $k => $l): $a0 = $l['antes'] ?? []; $d0 = $l['depois'] ?? []; ?>
      <tr>
        <td>U<?php echo h(substr((string) $k, 0, -1)); ?> <?php echo chipTurno(substr((string) $k, -1)); ?></td>
        <?php foreach (['postos', 'titulares', 'buffer', 'precisa', 'tem', 'saldo'] as $c): $x = $a0[$c] ?? null; $y = $d0[$c] ?? null; ?>
        <td class="num"><?php echo $x === null ? '—' : (int) $x; ?><?php if ($x !== null && $y !== null && (int) $x !== (int) $y): ?> → <strong><?php echo (int) $y; ?></strong><?php endif; ?></td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="muted small"><?php echo h(t('As fotos ficam guardadas no sistema de escalas. O Capacity regrava o cálculo do dia, mas a versão anterior continua aqui para comparar.')); ?></p>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="panel">
  <div class="linha-titulo">
    <h2><?php echo h(t('Saídas detectadas')); ?></h2>
    <div class="chips">
      <?php foreach ([7, 14, 21, 45] as $d): ?><a class="chip-filtro <?php echo $d === $dias ? 'ativo' : ''; ?>" href="<?php echo h(link_tela('saidas', ['dias' => $d])); ?>"><?php echo h(t('{n} dias', ['n' => $d])); ?></a><?php endforeach; ?>
    </div>
  </div>
  <?php $podeLote = escPode('decidir') && $saidas; ?>
  <?php if ($podeLote): ?>
  <form method="post" action="<?php echo h(link_tela('saidas', ['dias' => $dias])); ?>" id="saidas-lote" class="acoes lote-barra">
    <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="lote">
    <span class="small"><strong id="lote-n">0</strong> <?php echo h(t('selecionada(s)')); ?></span>
    <button type="button" class="botao" id="lote-pendentes"><?php echo h(t('Selecionar as "a conferir"')); ?></button>
    <input type="text" name="observacao" placeholder="<?php echo h(t('observação (vale para todas)')); ?>" size="24" onkeydown="if (event.key === 'Enter') { event.preventDefault(); }">
    <button type="submit" name="decisao" value="CONFIRMADA" class="botao" data-lote disabled><?php echo h(t('Confirmar')); ?></button>
    <button type="submit" name="decisao" value="NAO_SAIU" class="botao" data-lote disabled><?php echo h(t('Não saiu')); ?></button>
    <?php if (escPode('planejar')): ?><span class="separador" aria-hidden="true">·</span><button type="submit" name="decisao" value="APLICAR" class="botao perigo" data-lote data-longo="<?php echo h(t('Aplicando as saídas… recalcula o Capacity e a escala uma vez, pode levar alguns minutos.')); ?>" disabled><?php echo h(t('Confirmar e aplicar…')); ?></button><?php endif; ?>
  </form>
  <?php endif; ?>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><?php if ($podeLote): ?><th data-sem-ordem class="col-sel"><input type="checkbox" id="lote-todas" aria-label="<?php echo h(t('Selecionar todas')); ?>"></th><?php endif; ?><th><?php echo h(t('Data')); ?></th><th><?php echo h(t('Página')); ?></th><th class="num">A</th><th class="num">C</th><th class="num">D</th><th class="num"><?php echo h(t('Pessoas')); ?></th><th><?php echo h(t('Checagem')); ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($saidas as $s): $ch = $checagem[(int) $s['id']] ?? null; $dec = $ch['decisao'] ?? 'PENDENTE';
        $ap = $aplicadaEvento[(int) $s['id']] ?? (isset($aplicadaPagina[$s['pagina']]) && abs(strtotime((string) $aplicadaPagina[$s['pagina']]['vale_desde']) - strtotime(max(substr((string) $s['data_evento'], 0, 10), date('Y-m-d')))) <= ESC_SAIDA_JANELA_DIAS * 86400 ? $aplicadaPagina[$s['pagina']] : null);
        $jaAplicada = $ap !== null; ?>
      <tr>
        <?php if ($podeLote): ?><td class="col-sel"><input type="checkbox" name="evento_ids[]" value="<?php echo (int) $s['id']; ?>" form="saidas-lote" class="lote-sel" data-pendente="<?php echo $dec === 'PENDENTE' && $s['status'] !== 'CANCELADA' ? '1' : '0'; ?>" data-aplicada="<?php echo $jaAplicada ? '1' : '0'; ?>" data-pagina="<?php echo h($s['pagina']); ?>" aria-label="<?php echo h(t('Selecionar {pagina}', ['pagina' => $s['pagina']])); ?>"></td><?php endif; ?>
        <td><?php echo h(dataBr($s['data_evento'], true)); ?></td>
        <td><strong><?php echo h($s['pagina']); ?></strong><br><span class="muted small"><?php echo h(escRotulo('origem_pedido', $s['origem'])); ?></span></td>
        <td class="num"><?php echo num($s['reps_h_a'], 2); ?></td>
        <td class="num"><?php echo num($s['reps_h_c'], 2); ?></td>
        <td class="num"><?php echo num($s['reps_h_d'], 2); ?></td>
        <td class="num"><?php echo num($s['hc_total'], 1); ?></td>
        <td>
          <span class="tag <?php echo $dec === 'CONFIRMADA' ? 'verde' : ($dec === 'NAO_SAIU' ? '' : 'laranja'); ?>"><?php echo h(escRotulo('decisao_saida', $dec)); ?></span>
          <?php if ($jaAplicada): ?><br><span class="tag verde"><?php echo h(t('Aplicada em {dia}', ['dia' => escData((string) $ap['criado_em'])])); ?></span> <a class="small" href="#aplicadas"><?php echo h(t('comparar')); ?></a><?php endif; ?>
          <?php if ($ch && $ch['decidido_em']): ?><br><span class="muted small"><?php echo h(escDataHora($ch['decidido_em'])); ?><?php echo $ch['rechecar_em'] ? ' · ' . h(t('rechecar em {dia}', ['dia' => dataBr($ch['rechecar_em'])])) : ''; ?><?php echo $ch['observacao'] ? ' · ' . h($ch['observacao']) : ''; ?></span><?php endif; ?>
        </td>
        <td class="nowrap">
          <?php if (escPode('decidir')): ?>
          <form method="post" action="<?php echo h(link_tela('saidas', ['dias' => $dias])); ?>" class="acoes">
            <?php echo campoCsrf(); ?><input type="hidden" name="evento_id" value="<?php echo (int) $s['id']; ?>">
            <input type="text" name="observacao" placeholder="<?php echo h(t('observação')); ?>" size="12" onkeydown="if (event.key === 'Enter') { event.preventDefault(); }">
            <?php if ($dec !== 'CONFIRMADA'): ?><button type="submit" name="decisao" value="CONFIRMADA" class="botao"><?php echo h(t('Confirmar')); ?></button><?php endif; ?>
            <?php if ($dec !== 'NAO_SAIU' && !$jaAplicada): ?><button type="submit" name="decisao" value="NAO_SAIU" class="botao"><?php echo h(t('Não saiu')); ?></button><?php endif; ?>
            <?php if ($dec !== 'PENDENTE' && !$jaAplicada): ?><button type="submit" name="decisao" value="PENDENTE" class="botao" title="<?php echo h(t('Só a checagem volta; a escala não muda.')); ?>"><?php echo h(t('Voltar para "a conferir"')); ?></button><?php endif; ?>
            <?php if (escPode('planejar') && !$jaAplicada): $desdeLinha = max(substr((string) $s['data_evento'], 0, 10), date('Y-m-d')); ?>
            <span class="separador" aria-hidden="true">·</span>
            <a class="botao perigo" href="<?php echo h(link_tela('saidas', ['previa' => 1, 'pagina' => $s['pagina'], 'desde' => $desdeLinha, 'evento' => (int) $s['id'], 'dias' => $dias])); ?>#previa"><?php echo h(t('Aplicar…')); ?></a>
            <?php endif; ?>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$saidas): ?><tr><td colspan="9" class="muted"><?php echo h(t('Nenhuma saída no período.')); ?></td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <p class="muted small"><?php echo h(t('O que conferir: a página está como churn ou cancelada no CRM? Saiu do Planner? Não é só uma pausa? A decisão fica registrada (quem e quando). "Confirmar e aplicar" faz a saída de uma vez (Capacity, postos e escala) e não é desfeita pelo sistema; "não saiu" serve de alerta para corrigir o Capacity; "Voltar para a conferir" só muda a checagem. Marque várias na primeira coluna para decidir todas juntas: as páginas aplicadas juntas recalculam o Capacity e a escala uma vez só.')); ?></p>
</section>
<?php if ($podeLote): ?>
<script>
(function () {
  var TX = <?php echo json_encode([
      'jaAplicadas' => t('Já aplicada(s): {paginas}.'),
      'desmarque' => t('Desmarque antes de aplicar.'),
      'aplicar' => t('Aplicar a saída de {n} página(s)?'),
      'aplicarDetalhe' => t('Recalcula o Capacity uma vez, fecha os postos delas e gera a escala de novo. Pode levar alguns minutos. Não dá para desfazer pelo sistema.'),
      'naoSaiu' => t('Marcar {n} saída(s) como "não saiu"?'),
      'confirmar' => t('Confirmar {n} saída(s)?'),
      'botao' => t('Confirmar'),
  ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE); ?>;
  var form = document.getElementById('saidas-lote'), todas = document.getElementById('lote-todas'), n = document.getElementById('lote-n');
  var sel = function () { return Array.prototype.slice.call(document.querySelectorAll('.lote-sel')); };
  function contar() {
    var marcadas = sel().filter(function (c) { return c.checked; }).length;
    n.textContent = marcadas;
    form.querySelectorAll('[data-lote]').forEach(function (b) { b.disabled = marcadas === 0; });
    todas.checked = marcadas > 0 && marcadas === sel().length;
    todas.indeterminate = marcadas > 0 && marcadas < sel().length;
  }
  document.addEventListener('change', function (ev) { if (ev.target.classList.contains('lote-sel')) { contar(); } });
  todas.addEventListener('change', function () { sel().forEach(function (c) { c.checked = todas.checked; }); contar(); });
  document.getElementById('lote-pendentes').addEventListener('click', function () { sel().forEach(function (c) { c.checked = c.dataset.pendente === '1'; }); contar(); });
  form.addEventListener('submit', function (ev) {
    var b = ev.submitter, marcadas = sel().filter(function (c) { return c.checked; });
    var nomes = marcadas.map(function (c) { return c.dataset.pagina; }).join(', ');
    var aplicar = b && b.value === 'APLICAR';
    var jaAplicadas = aplicar ? marcadas.filter(function (c) { return c.dataset.aplicada === '1'; }) : [];
    if (jaAplicadas.length) {
      alert(TX.jaAplicadas.replace('{paginas}', jaAplicadas.map(function (c) { return c.dataset.pagina; }).join(', ')) + '\n' + TX.desmarque);
      ev.preventDefault();
      return;
    }
    var texto = aplicar
      ? TX.aplicar.replace('{n}', marcadas.length) + ' ' + nomes + '\n\n' + TX.aplicarDetalhe
      : (b && b.value === 'NAO_SAIU' ? TX.naoSaiu : TX.confirmar).replace('{n}', marcadas.length) + '\n' + nomes;
    if (form.dataset.confirmado === '1') { delete form.dataset.confirmado; return; }
    ev.preventDefault();
    ev.stopImmediatePropagation();
    escConfirmar(texto, { botao: b ? b.textContent.replace(/[…\s]+$/, '').trim() : TX.botao, perigo: aplicar, origem: b }).then(function (ok) {
      if (!ok) { return; }
      form.dataset.confirmado = '1';
      form.requestSubmit(b || undefined);
    });
  });
  contar();
})();
</script>
<?php endif; ?>
