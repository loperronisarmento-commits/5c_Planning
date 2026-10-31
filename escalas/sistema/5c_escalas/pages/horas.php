<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/horas.php
// Horas extras: o que ficou vazio na escala da semana (postos sem ninguém e
// buffers que faltam para a necessidade do Capacity), quem se ofereceu para
// cobrir cada um e a alocação na escala (rascunho), com as travas da
// Política: nada no mesmo bloco, no máximo 12 h seguidas e 52 h na semana.
// Tudo por bloco de 4 h (T1 a T6): quem faz um turno de 8 h pode pegar o bloco
// vizinho (12 h seguidas), o que um turno inteiro a mais nunca deixaria (ES_38).
// ===============================================

$semanas = [];
for ($i = -1; $i < 4; $i++) {
    $semanas[] = date('Y-m-d', strtotime(segundaDaSemana(date('Y-m-d')) . " +{$i} week"));
}
$seg = segundaDaSemana(is_string($_GET['semana'] ?? null) && preg_match('~^\d{4}-\d{2}-\d{2}$~', $_GET['semana']) ? $_GET['semana'] : $semanas[2]);
$pagina5c = escV4Param('v4_overlap_pagina', '5C Schedule');

// Blocos de 4 h: T1 01–05, T2 05–09, T3 09–13, T4 13–17, T5 17–21, T6 21–01. O T1 do turno D
// é do dia seguinte. Posição contínua no tempo = dia × 6 + índice do bloco (o T6 emenda no T1).
const ESC_HE_BLOCO_IDX = ['T1' => 0, 'T2' => 1, 'T3' => 2, 'T4' => 3, 'T5' => 4, 'T6' => 5];
const ESC_HE_BLOCO_HORA = ['T1' => '01h–05h', 'T2' => '05h–09h', 'T3' => '09h–13h', 'T4' => '13h–17h', 'T5' => '17h–21h', 'T6' => '21h–01h'];
$posBloco = static fn(string $diaCal, string $bloco): int => intdiv(strtotime($diaCal . ' 12:00:00'), 86400) * 6 + ESC_HE_BLOCO_IDX[$bloco];
// Dia do calendário de um bloco do turno (o T1 do turno D é no dia seguinte ao dia do turno).
$diaDoBloco = static fn(string $diaTurno, string $turno, string $bloco): string => $turno === 'D' && $bloco === 'T1' ? escMaisDias($diaTurno, 1) : $diaTurno;
$heTemBloco = (bool) escQ1("SELECT 1 AS ok FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'esc_hora_extra' AND COLUMN_NAME = 'bloco'", [ESC_SCHEMA]);

if (escPost()) {
    $acao = $_POST['acao'] ?? '';
    $segPost = segundaDaSemana((string) ($_POST['semana'] ?? $seg));
    if ($acao === 'interesse') {
        escExigir('cadastrar');
        $rep = strtoupper(trim((string) ($_POST['rep_code'] ?? '')));
        $tipo = (string) ($_POST['tipo'] ?? '');
        $dia = (string) ($_POST['dia'] ?? '');
        $turno = (string) ($_POST['turno'] ?? '');
        if (!preg_match('~^[A-Z]\d{6}$~', $rep) || !in_array($tipo, ['POSTO', 'BUFFER'], true) || !preg_match('~^\d{4}-\d{2}-\d{2}$~', $dia) || !isset(ESC_TURNOS[$turno])) {
            throw new RuntimeException(t('Escolha o colaborador na busca (nome ou código).'));
        }
        $bloco = (string) ($_POST['bloco'] ?? '');
        if (!in_array($bloco, ESC_TURNOS[$turno], true)) {
            throw new RuntimeException(t('Escolha o bloco do turno.'));
        }
        if (!$heTemBloco) {
            throw new RuntimeException(t('As horas extras por bloco de 4 h ainda não foram instaladas no banco (script ES_38). Peça ao admin.'));
        }
        $postoId = $tipo === 'POSTO' ? (int) ($_POST['posto_id'] ?? 0) : null;
        $posto = $postoId ? escQ1('SELECT unidade, pagina_principal FROM ' . escT('esc_posto') . ' WHERE id = ?', [$postoId]) : null;
        if ($tipo === 'POSTO' && !$posto) {
            throw new RuntimeException(t('Posto não encontrado.'));
        }
        $unidade = $posto ? (int) $posto['unidade'] : (int) ($_POST['unidade'] ?? 0);
        escW('INSERT INTO ' . escT('esc_hora_extra') . ' (semana_inicio, dia, turno, bloco, unidade, tipo, posto_id, pagina, rep_code, observacao, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $segPost, $dia, $turno, $bloco, $unidade ?: null, $tipo, $postoId, $posto['pagina_principal'] ?? null, $rep,
            mb_substr(trim((string) ($_POST['observacao'] ?? '')), 0, 255) ?: null, escUserId(),
        ]);
        escLog('hora_extra.interesse', 'esc_hora_extra', escUltimoId(), null, ['rep' => $rep, 'tipo' => $tipo, 'dia' => $dia, 'turno' => $turno, 'bloco' => $bloco, 'posto' => $postoId]);
        escVoltar('horas', ['semana' => $segPost], t('Interesse de {rep} registrado.', ['rep' => $rep]));
    }
    if ($acao === 'recusar') {
        escExigir('planejar');
        $id = (int) ($_POST['id'] ?? 0);
        escW('UPDATE ' . escT('esc_hora_extra') . " SET status = 'RECUSADO', decidido_em = NOW(), decidido_por = ? WHERE id = ? AND status = 'INTERESSADO'", [escUserId(), $id]);
        escLog('hora_extra.recusar', 'esc_hora_extra', $id);
        escVoltar('horas', ['semana' => $segPost], t('Interesse recusado.'));
    }
    if ($acao === 'alocar') {
        escExigir('planejar');
        $id = (int) ($_POST['id'] ?? 0);
        $he = escQ1('SELECT * FROM ' . escT('esc_hora_extra') . " WHERE id = ? AND status = 'INTERESSADO'", [$id]);
        if (!$he) {
            throw new RuntimeException(t('Pedido não encontrado ou já decidido.'));
        }
        $semanaHe = substr($he['semana_inicio'], 0, 10);
        $plano = escPlanoAtual($semanaHe);
        if (!$plano || $plano['status'] !== 'RASCUNHO') {
            throw new RuntimeException(t('A escala desta semana não está em rascunho. Crie um rascunho na Grade para alocar a hora extra.'));
        }
        $planoId = (int) $plano['id'];
        $rep = $he['rep_code'];
        $dia = substr($he['dia'], 0, 10);
        $turno = $he['turno'];
        // Blocos pedidos: o do pedido (4 h) ou, em pedido antigo sem bloco, o turno inteiro.
        $blocosHe = !empty($he['bloco']) ? [$he['bloco']] : ESC_TURNOS[$turno];
        $celulas = array_map(static fn($b) => [$diaDoBloco($dia, $turno, $b), $b], $blocosHe);
        // Blocos em que a pessoa já está na escala (qualquer página), na linha do tempo.
        $ocupado = [];
        foreach (escQ('SELECT DISTINCT dia, bloco FROM ' . escT('esc_plano_alocacao') . ' WHERE plano_id = ? AND rep_code = ?', [$planoId, $rep]) as $l) {
            $ocupado[$posBloco(substr((string) $l['dia'], 0, 10), $l['bloco'])] = true;
        }
        foreach ($celulas as [$d, $b]) {
            if (isset($ocupado[$posBloco($d, $b)])) {
                throw new RuntimeException(t('{rep} já está na escala no bloco {bloco} de {dia}.', ['rep' => $rep, 'bloco' => $b, 'dia' => dataBr($d)]));
            }
        }
        // Horas seguidas: a sequência de blocos encostados, contando os novos.
        $novos = array_map(static fn($c) => $posBloco($c[0], $c[1]), $celulas);
        $todos = $ocupado + array_fill_keys($novos, true);
        $ini = min($novos);
        $fim = max($novos);
        while (isset($todos[$ini - 1])) {
            $ini--;
        }
        while (isset($todos[$fim + 1])) {
            $fim++;
        }
        $seguidas = ($fim - $ini + 1) * 4;
        $maxSeguidas = (int) escV4Param('v4_horas_seguidas_max', '12');
        if ($seguidas > $maxSeguidas) {
            throw new RuntimeException(t('{rep}: com a hora extra seriam {h} h seguidas (Política: no máximo {max} h). Ofereça um bloco que não emende tanto.', ['rep' => $rep, 'h' => $seguidas, 'max' => $maxSeguidas]));
        }
        $horasMax = (int) escV4Param('v4_horas_semana_max', '52');
        if ((count($ocupado) + count($novos)) * 4 > $horasMax) {
            throw new RuntimeException(t('{rep} passaria de {max} h na semana (Política).', ['rep' => $rep, 'max' => $horasMax]));
        }
        escTx(function () use ($he, $planoId, $rep, $turno, $celulas, $pagina5c, $id) {
            if (escW('UPDATE ' . escT('esc_hora_extra') . " SET status = 'ALOCADO', plano_id = ?, decidido_em = NOW(), decidido_por = ? WHERE id = ? AND status = 'INTERESSADO'", [$planoId, escUserId(), $id]) === 0) {
                throw new RuntimeException(t('Pedido não encontrado ou já decidido.'));
            }
            if ($he['tipo'] === 'POSTO') {
                $n = 0;
                foreach ($celulas as [$d, $b]) {
                    $n += escW('UPDATE ' . escT('esc_plano_alocacao') . " SET rep_code = ?, origem = 'MANUAL', travada = 1, motivo = ?, alterado_em = NOW(), alterado_por = ?
                                 WHERE plano_id = ? AND posto_id = ? AND rep_code IS NULL AND dia = ? AND bloco = ?",
                        [$rep, 'hora extra (pedido #' . $id . ')', escUserId(), $planoId, (int) $he['posto_id'], $d, $b]);
                }
                if ($n === 0) {
                    throw new RuntimeException(t('Esse posto já não está vazio nesta versão da escala.'));
                }
            } else {
                $pg = escQ1('SELECT id, name FROM `5c_pages`.`pages` WHERE name = ? ORDER BY active DESC, id LIMIT 1', [$pagina5c]);
                if (!$pg) {
                    throw new RuntimeException(t('Página {pagina} não encontrada no cadastro.', ['pagina' => $pagina5c]));
                }
                foreach ($celulas as [$d, $b]) {
                    escW('INSERT INTO ' . escT('esc_plano_alocacao') . " (plano_id, dia, bloco, turno, page_id, pagina, rep_code, papel, origem, travada, motivo, alterado_em, alterado_por)
                          VALUES (?, ?, ?, ?, ?, ?, ?, 'REGULAR', 'BUFFER', 1, ?, NOW(), ?)",
                        [$planoId, $d, $b, $turno, (int) $pg['id'], $pg['name'], $rep, 'hora extra · buffer (pedido #' . $id . ')', escUserId()]);
                }
            }
            escLog('hora_extra.alocar', 'esc_hora_extra', $id, $he, ['plano' => $planoId]);
        });
        escVoltar('horas', ['semana' => $semanaHe], t('Hora extra de {rep} alocada na escala (travada). Confira na Grade.', ['rep' => $rep]));
    }
    throw new RuntimeException(t('Ação desconhecida.'));
}

// ---------- dados da semana ----------
$plano = escPlanoAtual($seg);
$nomes = escNomes();
$postosVazios = [];
$buffersVazios = [];
$receita = [];
if ($plano) {
    $planoId = (int) $plano['id'];
    // Postos sem ninguém por bloco de 4 h (dia do turno; o T1 do D conta no dia do turno).
    foreach (escQ("SELECT a.posto_id, a.turno, a.bloco, IF(a.turno = 'D' AND a.bloco = 'T1', DATE_SUB(a.dia, INTERVAL 1 DAY), a.dia) AS dt,
                          p.unidade, p.carteira, p.tipo, p.pagina_principal, p.carga, p.capacidade
                     FROM " . escT('esc_plano_alocacao') . ' a JOIN ' . escT('esc_posto') . ' p ON p.id = a.posto_id
                    WHERE a.plano_id = ? AND a.rep_code IS NULL AND a.posto_id IS NOT NULL AND a.origem <> \'TREINO\'
                    GROUP BY a.posto_id, a.turno, a.bloco, dt, p.unidade, p.carteira, p.tipo, p.pagina_principal, p.carga, p.capacidade', [$planoId]) as $l) {
        $postosVazios[] = $l + ['dia' => substr($l['dt'], 0, 10)];
    }
    // Faturamento da página no turno (US$/dia, últimos dias do statement): ordem da lista.
    $pags = array_values(array_unique(array_column($postosVazios, 'pagina_principal')));
    if ($pags) {
        $n = max(1, (int) escV4Param('v4_receita_dias', '28'));
        foreach (escQ("SELECT CREATOR_NAME AS pagina,
                              SUM(CASE WHEN Turno IN ('T2','T3') THEN US_AMOUNT ELSE 0 END) AS a,
                              SUM(CASE WHEN Turno IN ('T4','T5') THEN US_AMOUNT ELSE 0 END) AS c,
                              SUM(CASE WHEN Turno IN ('T6','T1') THEN US_AMOUNT ELSE 0 END) AS d
                         FROM `5c_wfm_db_mis`.`tab_statement_interval`
                        WHERE Date >= DATE_SUB(CURDATE(), INTERVAL {$n} DAY) AND Date < CURDATE() AND STATUS = 'complete'
                          AND CREATOR_NAME IN (" . escIn($pags) . ') GROUP BY CREATOR_NAME', $pags) as $r) {
            $receita[$r['pagina']] = ['A' => (float) $r['a'] / $n, 'C' => (float) $r['c'] / $n, 'D' => (float) $r['d'] / $n];
        }
    }
    usort($postosVazios, static fn($a, $b) => [$a['dia'], -($receita[$a['pagina_principal']][$a['turno']] ?? 0), $a['pagina_principal'], ESC_HE_BLOCO_IDX[$a['bloco']] + ($a['bloco'] === 'T1' ? 6 : 0)]
        <=> [$b['dia'], -($receita[$b['pagina_principal']][$b['turno']] ?? 0), $b['pagina_principal'], ESC_HE_BLOCO_IDX[$b['bloco']] + ($b['bloco'] === 'T1' ? 6 : 0)]);

    // Buffers: presentes por dia que o Capacity pede (por unidade e turno) × buffers de plantão escalados.
    $precisa = [];
    foreach (escQ('SELECT unidade, turno, presentes_dia FROM `5c_planning`.`fc_necessidade_estrutura` WHERE data_calculo = (SELECT MAX(data_calculo) FROM `5c_planning`.`fc_necessidade_estrutura`)') as $l) {
        $precisa[(int) $l['unidade'] . '|' . $l['turno']] = (int) ceil((float) $l['presentes_dia'] - 1e-9);
    }
    $unidadeDe = [];
    $fim = escMaisDias($seg, 6);
    foreach (escQ('SELECT UPPER(rep_code) AS rep, unidade FROM ' . escT('esc_colaborador_config') . " WHERE funcao = 'EQUITE' AND unidade IS NOT NULL AND vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)", [$fim, $fim]) as $l) {
        $unidadeDe[$l['rep']] = (int) $l['unidade'];
    }
    $temBuffer = [];
    foreach (escQ("SELECT DISTINCT UPPER(rep_code) AS rep, turno, bloco, IF(turno = 'D' AND bloco = 'T1', DATE_SUB(dia, INTERVAL 1 DAY), dia) AS dt FROM " . escT('esc_plano_alocacao') . "
                    WHERE plano_id = ? AND pagina = ? AND origem = 'BUFFER' AND rep_code IS NOT NULL", [$planoId, $pagina5c]) as $l) {
        $u = $unidadeDe[$l['rep']] ?? 0;
        $k = substr($l['dt'], 0, 10) . '|' . $u . '|' . $l['turno'] . '|' . $l['bloco'];
        $temBuffer[$k] = ($temBuffer[$k] ?? 0) + 1;
    }
    $virada = escV4DiaVirada();
    $turnoVirada = escV4TurnoVirada();
    foreach (escDiasDaSemana($seg) as $d) {
        if ($d < $virada) {
            continue;
        }
        foreach ([1, 2, 3] as $u) {
            foreach (['A', 'C', 'D'] as $t) {
                if (!escV4TurnoNovo($d, $t, $virada, $turnoVirada)) {
                    continue;
                }
                foreach (ESC_TURNOS[$t] as $b) {
                    $tem = $temBuffer[$d . '|' . $u . '|' . $t . '|' . $b] ?? 0;
                    $falta = ($precisa[$u . '|' . $t] ?? 0) - $tem;
                    if ($falta > 0) {
                        $buffersVazios[] = ['dia' => $d, 'unidade' => $u, 'turno' => $t, 'bloco' => $b, 'precisa' => $precisa[$u . '|' . $t], 'tem' => $tem, 'falta' => $falta];
                    }
                }
            }
        }
    }
}
$interesses = [];
foreach (escQ('SELECT * FROM ' . escT('esc_hora_extra') . " WHERE semana_inicio = ? AND status <> 'CANCELADO' ORDER BY id", [$seg]) as $l) {
    foreach (!empty($l['bloco']) ? [$l['bloco']] : ESC_TURNOS[$l['turno']] as $b) {
        $chave = $l['tipo'] === 'POSTO'
            ? 'P|' . (int) $l['posto_id'] . '|' . substr($l['dia'], 0, 10) . '|' . $b
            : 'B|' . (int) $l['unidade'] . '|' . $l['turno'] . '|' . substr($l['dia'], 0, 10) . '|' . $b;
        $interesses[$chave][] = $l;
    }
}
$totFalta = array_sum(array_column($buffersVazios, 'falta'));
$abertos = count(array_unique(array_column(array_filter(array_merge(...array_values($interesses ?: [[]])), static fn($l) => $l['status'] === 'INTERESSADO'), 'id')));

// Texto pronto para pedir hora extra (WhatsApp / Telegram).
$rotDia = static fn(string $d): string => escData($d, true);
$hora = ['A' => '05h–13h', 'C' => '13h–21h', 'D' => '21h–05h'];
$msg = t('Hora extra · semana de {dia}', ['dia' => escData($seg)]) . "\n" . t('Blocos de 4 h. Quem puder, responda com o dia e o bloco (até 12 h seguidas contando a sua escala).') . "\n";
foreach ($postosVazios as $l) {
    $msg .= "\n• " . t('{dia} · {bloco} ({hora}) · U{unidade} · {pagina}', ['dia' => $rotDia($diaDoBloco($l['dia'], $l['turno'], $l['bloco'])), 'bloco' => $l['bloco'], 'hora' => ESC_HE_BLOCO_HORA[$l['bloco']], 'unidade' => $l['unidade'], 'pagina' => $l['pagina_principal']]);
}
foreach ($buffersVazios as $l) {
    $varsBuf = ['dia' => $rotDia($diaDoBloco($l['dia'], $l['turno'], $l['bloco'])), 'bloco' => $l['bloco'], 'hora' => ESC_HE_BLOCO_HORA[$l['bloco']], 'unidade' => $l['unidade'], 'n' => $l['falta']];
    $msg .= "\n• " . ($l['falta'] > 1 ? t('{dia} · {bloco} ({hora}) · U{unidade} · buffer ({n} vagas)', $varsBuf) : t('{dia} · {bloco} ({hora}) · U{unidade} · buffer', $varsBuf));
}
$ehRascunho = $plano && $plano['status'] === 'RASCUNHO';
// Tipo do posto (esc_posto.tipo) como rótulo no idioma da tela.
$tipoPosto = ['titular' => t('titular'), 'double' => t('double'), 'apoio' => t('apoio'), 'assistente' => t('assistente')];

$formInteresse = static function (array $campos) use ($seg): string {
    $html = '<form method="post" action="' . h(link_tela('horas', ['semana' => $seg])) . '" class="acoes he-form" data-manter-rolagem>' . campoCsrf()
        . '<input type="hidden" name="acao" value="interesse"><input type="hidden" name="semana" value="' . h($seg) . '">';
    foreach ($campos as $k => $v) {
        $html .= '<input type="hidden" name="' . h($k) . '" value="' . h((string) $v) . '">';
    }
    return $html . '<input type="search" name="rep_code" list="lista-colaboradores" autocomplete="off" placeholder="' . h(t('quem quer fazer (nome ou código)')) . '" required>'
        . '<button type="submit" class="botao">' . h(t('Registrar')) . '</button></form>';
};
$listaInteresses = static function (array $lista) use ($nomes, $seg, $ehRascunho): string {
    if (!$lista) {
        return '<span class="muted small">' . h(t('ninguém ainda')) . '</span>';
    }
    $html = '<ul class="he-lista">';
    foreach ($lista as $l) {
        $tag = ['INTERESSADO' => 'amarelo', 'ALOCADO' => 'verde', 'RECUSADO' => ''][$l['status']] ?? '';
        $html .= '<li><span>' . h(nomeCurto($nomes[$l['rep_code']] ?? $l['rep_code'])) . ' · ' . h($l['rep_code']) . '</span> <span class="tag ' . $tag . '">' . h(escRotulo('status_he', $l['status'])) . '</span>';
        if ($l['status'] === 'INTERESSADO' && escPode('planejar')) {
            foreach (['alocar' => [h(t('Alocar')), 'botao primario'], 'recusar' => [h(t('Recusar')), 'botao']] as $acao => [$rot, $cls]) {
                if ($acao === 'alocar' && !$ehRascunho) {
                    continue;
                }
                $html .= ' <form method="post" action="' . h(link_tela('horas', ['semana' => $seg])) . '" class="he-inline" data-manter-rolagem>' . campoCsrf()
                    . '<input type="hidden" name="acao" value="' . $acao . '"><input type="hidden" name="id" value="' . (int) $l['id'] . '"><input type="hidden" name="semana" value="' . h($seg) . '">'
                    . '<button type="submit" class="' . $cls . '">' . $rot . '</button></form>';
            }
        }
        $html .= '</li>';
    }
    return $html . '</ul>';
};
?>
<header class="cabecalho">
  <span class="eyebrow"><?php echo h(t('Escala')); ?></span>
  <h1><?php echo h(t('Horas extras')); ?></h1>
  <p class="muted"><?php echo h(t('O que ficou vazio na escala da semana, por bloco de 4 h: postos sem ninguém (da página de maior faturamento para a de menor) e buffers que faltam para a necessidade do Capacity. Registre quem se ofereceu e aloque: quem faz um turno de 8 h pode pegar um bloco vizinho (até 12 h seguidas). A hora extra entra travada no rascunho; buffer vai para a página {pagina} com -2 no CSV.', ['pagina' => $pagina5c])); ?></p>
  <?php if (!$heTemBloco): ?><p class="aviso erro" role="alert"><?php echo h(t('As horas extras por bloco de 4 h ainda não foram instaladas no banco (script ES_38). Peça ao admin.')); ?></p><?php endif; ?>
</header>

<section class="panel">
  <form method="get" action="<?php echo h(MODULE_URL); ?>/index.php" class="linha-titulo">
    <input type="hidden" name="p" value="horas">
    <label class="filtros"><?php echo h(t('Semana')); ?>
      <select name="semana" onchange="this.form.submit()">
        <?php foreach ($semanas as $s): ?><option value="<?php echo h($s); ?>" <?php echo $s === $seg ? 'selected' : ''; ?>><?php echo h(t('{ini} a {fim}', ['ini' => dataBr($s), 'fim' => dataBr(escMaisDias($s, 6))])); ?></option><?php endforeach; ?>
      </select>
    </label>
    <span class="muted small"><?php echo h(t('Escala:')); ?> <strong><?php echo h($plano ? escPlanoRotulo($plano) . ' · ' . escRotulo('status_plano', $plano['status']) : t('nenhuma gerada')); ?></strong><?php if ($plano): ?> · <a href="<?php echo h(link_tela('grade', ['plano' => (int) $plano['id']])); ?>"><?php echo h(t('abrir na Grade')); ?></a><?php endif; ?></span>
  </form>
  <?php if (!$plano): ?>
    <p class="muted"><?php echo h(t('Gere a escala desta semana para ver o que ficou vazio.')); ?></p>
  <?php else: ?>
  <div class="kpis">
    <div class="kpi"><span class="muted"><?php echo h(t('Postos sem ninguém')); ?></span><strong class="<?php echo $postosVazios ? 'laranja' : 'verde'; ?>"><?php echo count($postosVazios); ?></strong><span class="muted small"><?php echo h(t('blocos de 4 h na semana')); ?></span></div>
    <div class="kpi"><span class="muted"><?php echo h(t('Buffers faltando')); ?></span><strong class="<?php echo $totFalta ? 'laranja' : 'verde'; ?>"><?php echo (int) $totFalta; ?></strong><span class="muted small"><?php echo h(t('pessoa-blocos abaixo do que o Capacity pede')); ?></span></div>
    <div class="kpi"><span class="muted"><?php echo h(t('Interessados a decidir')); ?></span><strong><?php echo (int) $abertos; ?></strong><span class="muted small"><?php echo h($ehRascunho ? t('alocar ou recusar abaixo') : t('escala publicada: crie um rascunho na Grade para alocar')); ?></span></div>
  </div>
  <?php if ($postosVazios || $buffersVazios): ?>
  <details>
    <summary class="botao"><?php echo h(t('Texto para pedir hora extra')); ?></summary>
    <div class="cartao-csv">
      <pre class="csv" id="msg-hora-extra"><?php echo h($msg); ?></pre>
      <div class="acoes"><button type="button" class="botao primario" data-copiar="msg-hora-extra"><?php echo h(t('Copiar')); ?></button></div>
    </div>
  </details>
  <?php endif; ?>
  <?php endif; ?>
</section>

<?php if ($plano): ?>
<section class="panel">
  <h2><?php echo h(t('Postos sem ninguém')); ?></h2>
  <?php if (!$postosVazios): ?><p class="muted"><?php echo h(t('Nenhum posto vazio nesta versão.')); ?></p><?php else: ?>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th><?php echo h(t('Dia')); ?></th><th><?php echo h(t('Bloco')); ?></th><th><?php echo h(t('Unidade')); ?></th><th><?php echo h(t('Página (posto)')); ?></th><th class="num"><?php echo h(t('US$/dia no turno')); ?></th><th><?php echo h(t('Interessados')); ?></th><th><?php echo h(t('Quem se ofereceu')); ?></th></tr></thead>
    <tbody>
    <?php foreach ($postosVazios as $l): $k = 'P|' . (int) $l['posto_id'] . '|' . $l['dia'] . '|' . $l['bloco']; ?>
      <tr>
        <td class="nowrap"><?php echo h(dataBr($diaDoBloco($l['dia'], $l['turno'], $l['bloco']), true)); ?></td>
        <td class="nowrap"><span class="chip chip-<?php echo strtolower($l['turno']); ?>"><?php echo h($l['bloco']); ?></span> <span class="muted small"><?php echo h(ESC_HE_BLOCO_HORA[$l['bloco']]); ?></span></td>
        <td>U<?php echo (int) $l['unidade']; ?> <span class="muted small"><?php echo h($l['carteira']); ?></span></td>
        <td><strong><?php echo h($l['pagina_principal']); ?></strong><br><span class="muted small"><?php echo h(t('{tipo} · carga {carga} de {capacidade} fãs/h', ['tipo' => $tipoPosto[$l['tipo']] ?? $l['tipo'], 'carga' => num($l['carga']), 'capacidade' => num($l['capacidade'])])); ?></span></td>
        <td class="num"><?php echo isset($receita[$l['pagina_principal']]) ? h(num($receita[$l['pagina_principal']][$l['turno']], 0)) : '—'; ?></td>
        <td><?php echo $listaInteresses($interesses[$k] ?? []); ?></td>
        <td><?php echo escPode('cadastrar') ? $formInteresse(['tipo' => 'POSTO', 'posto_id' => (int) $l['posto_id'], 'dia' => $l['dia'], 'turno' => $l['turno'], 'bloco' => $l['bloco']]) : ''; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>

<section class="panel">
  <h2><?php echo h(t('Buffers faltando')); ?></h2>
  <p class="muted small"><?php echo h(t('Necessidade de buffers presentes por dia (Capacity, por unidade e turno) menos os buffers de plantão escalados (página {pagina}, -2).', ['pagina' => $pagina5c])); ?></p>
  <?php if (!$buffersVazios): ?><p class="muted"><?php echo h(t('Nenhum buffer faltando nesta versão.')); ?></p><?php else: ?>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th><?php echo h(t('Dia')); ?></th><th><?php echo h(t('Bloco')); ?></th><th><?php echo h(t('Unidade')); ?></th><th class="num"><?php echo h(t('Precisa')); ?></th><th class="num"><?php echo h(t('Escalados')); ?></th><th class="num"><?php echo h(t('Faltam')); ?></th><th><?php echo h(t('Interessados')); ?></th><th><?php echo h(t('Quem se ofereceu')); ?></th></tr></thead>
    <tbody>
    <?php foreach ($buffersVazios as $l): $k = 'B|' . $l['unidade'] . '|' . $l['turno'] . '|' . $l['dia'] . '|' . $l['bloco']; ?>
      <tr>
        <td class="nowrap"><?php echo h(dataBr($diaDoBloco($l['dia'], $l['turno'], $l['bloco']), true)); ?></td>
        <td class="nowrap"><span class="chip chip-<?php echo strtolower($l['turno']); ?>"><?php echo h($l['bloco']); ?></span> <span class="muted small"><?php echo h(ESC_HE_BLOCO_HORA[$l['bloco']]); ?></span></td>
        <td>U<?php echo (int) $l['unidade']; ?></td>
        <td class="num"><?php echo (int) $l['precisa']; ?></td>
        <td class="num"><?php echo (int) $l['tem']; ?></td>
        <td class="num laranja"><strong><?php echo (int) $l['falta']; ?></strong></td>
        <td><?php echo $listaInteresses($interesses[$k] ?? []); ?></td>
        <td><?php echo escPode('cadastrar') ? $formInteresse(['tipo' => 'BUFFER', 'unidade' => $l['unidade'], 'dia' => $l['dia'], 'turno' => $l['turno'], 'bloco' => $l['bloco']]) : ''; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>
