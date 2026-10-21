<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/trocas.php
// Trocas de página entre parties (Planned Move do Planner).
//
// Para cada lote (mesmo horário de corte no historic_page_planner):
// 1) lê as páginas que mudam de party;
// 2) lê a escala já postada dessas páginas a partir do corte;
// 3) refaz cada bloco: sai quem é da party antiga; entram os titulares
//    da party nova que já estão escalados naquele mesmo bloco (em
//    qualquer página da party); Buffer fica; Tertius fica se for do time
//    novo;
// 4) monta o CSV do Schedule Ajustment (6 linhas T1..T6 × 7 colunas
//    seg..dom, "," entre códigos, "#" para assistente; o "$" o site
//    calcula sozinho) e o roteiro de apagar/postar.
// Também atende o download do CSV (index.php?p=trocas&baixar=csv).
// ===============================================

$tabela = '`5c_historic_planner`.`historic_page_planner`';

$cortes = escQ("SELECT `timestamp` AS ts, COUNT(*) AS n, SUM(isApplied = 0) AS pendentes
                  FROM {$tabela}
                 WHERE changeTypeId = 40
                   AND `timestamp` >= DATE_FORMAT(NOW() - INTERVAL 14 DAY, '%Y-%m-%dT00:00:00')
                   AND `timestamp` < DATE_FORMAT(NOW() + INTERVAL 60 DAY, '%Y-%m-%dT00:00:00')
                 GROUP BY `timestamp`
                 ORDER BY ts DESC
                 LIMIT 40");

$tsValidos = array_column($cortes, 'ts');
$ts = $_GET['corte'] ?? '';
if (!in_array($ts, $tsValidos, true)) {
    // padrão: a próxima troca ainda não aplicada; senão, a mais recente
    $ts = '';
    foreach (array_reverse($cortes) as $c) {
        if ((int) $c['pendentes'] > 0) {
            $ts = $c['ts'];
            break;
        }
    }
    if ($ts === '' && $cortes) {
        $ts = $cortes[0]['ts'];
    }
}

// ---------- bloco de corte ----------
// Os blocos começam às 01, 05, 09, 13, 17 e 21 h. O corte é o bloco que
// começa até 59 min antes ou em qualquer hora depois do horário marcado
// (20:59 -> T6 das 21h; 12:59 -> T4 das 13h; 21:00 -> T6).
function escBlocoDeCorte(string $ts): string {
    $limite = strtotime(str_replace('T', ' ', substr($ts, 0, 19))) - 59 * 60;
    $dia = date('Y-m-d', $limite);
    for ($i = 0; $i < 2; $i++) {
        foreach (['01', '05', '09', '13', '17', '21'] as $hora) {
            $inicio = strtotime("{$dia} {$hora}:00:00");
            if ($inicio >= $limite) {
                return date('Y-m-d\TH:i:s', $inicio);
            }
        }
        $dia = date('Y-m-d', strtotime($dia . ' +1 day'));
    }
    return date('Y-m-d\TH:i:s', $limite);
}

$movs = [];
$pacote = [];
$corte = '';
if ($ts !== '') {
    $corte = escBlocoDeCorte($ts);

    $movs = escQ("SELECT m.pageId AS page_id, MAX(m.pageName) AS pagina,
                         MAX(m.teamName) AS para_time, MAX(m.partyName) AS para_party,
                         MAX(m.isApplied) AS aplicada,
                         MAX(o.teamName) AS de_time, MAX(o.partyName) AS de_party,
                         MAX(m.modifiedAt) AS lancada_em
                    FROM {$tabela} m
                    LEFT JOIN {$tabela} o
                      ON o.pageId = m.pageId AND o.`timestamp` = m.`timestamp` AND o.changeTypeId = 15
                   WHERE m.changeTypeId = 40 AND m.`timestamp` = ?
                   GROUP BY m.pageId
                   ORDER BY para_party, pagina", [$ts]);

    // Troca ainda não aplicada não tem a linha "Unassigned from Party":
    // a party de origem é a atual da página.
    $semOrigem = array_values(array_filter(array_map(static fn($m) => $m['de_party'] ? null : (int) $m['page_id'], $movs)));
    if ($semOrigem) {
        $atuais = escQ('SELECT page_id, teamName, partyName FROM `5c_bot`.`vw_pagina` WHERE page_id IN (' . escIn($semOrigem) . ')', $semOrigem);
        $porId = array_column($atuais, null, 'page_id');
        foreach ($movs as &$m) {
            if (!$m['de_party'] && isset($porId[$m['page_id']])) {
                $m['de_party'] = $porId[$m['page_id']]['partyName'];
                $m['de_time'] = $porId[$m['page_id']]['teamName'];
            }
        }
        unset($m);
    }
}

if ($movs) {
    $estrutura = escEstrutura();
    $turnos = escTurnosVigentes();

    $ids = array_map(static fn($m) => (int) $m['page_id'], $movs);
    $postado = escQ('SELECT modeloId, shiftDateAndHour AS slot, UPPER(codigo) AS codigo, colaboradorNome AS nome, assitente
                       FROM `5c_schedule`.`scheduleday`
                      WHERE modeloId IN (' . escIn($ids) . ') AND shiftDateAndHour >= ?
                      ORDER BY shiftDateAndHour', array_merge($ids, [$corte]));

    $porPagina = [];
    $ultimoSlot = $corte;
    $nomes = [];
    foreach ($postado as $r) {
        $porPagina[(int) $r['modeloId']][$r['slot']][$r['codigo']] = (int) $r['assitente'] === 1;
        $nomes[$r['codigo']] = $r['nome'];
        if ($r['slot'] > $ultimoSlot) {
            $ultimoSlot = $r['slot'];
        }
    }

    // Titulares (não Tertius) das parties de destino e onde estão escalados.
    $partiesDestino = array_unique(array_filter(array_column($movs, 'para_party')));
    $titulares = [];
    foreach ($estrutura as $code => $e) {
        if (in_array($e['party'], $partiesDestino, true) && $e['role'] !== 'Tertius') {
            $titulares[] = $code;
        }
    }
    $deServico = [];
    if ($titulares && $postado) {
        $linhas = escQ('SELECT DISTINCT shiftDateAndHour AS slot, UPPER(codigo) AS codigo
                          FROM `5c_schedule`.`scheduleday`
                         WHERE codigo IN (' . escIn($titulares) . ')
                           AND shiftDateAndHour >= ? AND shiftDateAndHour <= ? AND modeloId <> 3',
                       array_merge($titulares, [$corte, $ultimoSlot]));
        foreach ($linhas as $l) {
            $party = $estrutura[$l['codigo']]['party'] ?? null;
            if ($party) {
                $deServico[$l['slot']][$party][$l['codigo']] = true;
            }
        }
    }

    foreach ($movs as $m) {
        $pid = (int) $m['page_id'];
        $slots = $porPagina[$pid] ?? [];
        $paraParty = $m['para_party'];
        $paraTime = $m['para_time'];
        $celulas = [];
        $sai = [];
        $entra = [];
        $lacunas = [];
        $semSubstituto = [];
        $mudou = 0;

        foreach ($slots as $slot => $ocupantes) {
            $fica = [];
            $candidatos = [];
            foreach ($ocupantes as $code => $assist) {
                $e = $estrutura[$code] ?? null;
                $ehBuffer = ($turnos[$code]['tipo_alocacao'] ?? '') === 'Buffer' || $e === null;
                // Fica: Buffer, quem já é da party nova e quem é do time
                // novo (outra party do time cobrindo, ou Tertius do time).
                if ($ehBuffer || $e['party'] === $paraParty || $e['team'] === $paraTime) {
                    $fica[$code] = $assist;
                } else {
                    $candidatos[$code] = $assist;
                }
            }
            $novos = [];
            foreach (array_keys($deServico[$slot][$paraParty] ?? []) as $code) {
                if (!isset($fica[$code])) {
                    $novos[$code] = false;
                }
            }
            // Só sai quem tem substituto: sem titular da party nova no
            // bloco, quem está escalado continua (e o bloco vira pendência).
            if ($novos) {
                $saiu = $candidatos;
            } else {
                $saiu = [];
                $fica += $candidatos;
                if ($candidatos) {
                    $semSubstituto[] = $slot;
                }
            }
            $final = $fica + $novos;
            $celulas[$slot] = $final;
            if ($saiu || $novos) {
                $mudou++;
            }
            if (!$final && $ocupantes) {
                $lacunas[] = $slot;
            }
            foreach ($saiu as $code => $x) {
                $sai[$code][] = $slot;
            }
            foreach ($novos as $code => $x) {
                $entra[$code][] = $slot;
            }
        }

        $primeiro = $slots ? array_key_first($slots) : null;
        $ultimo = $slots ? array_key_last($slots) : null;
        $pacote[$pid] = [
            'mov' => $m,
            'celulas' => $celulas,
            'sai' => $sai,
            'entra' => $entra,
            'lacunas' => $lacunas,
            'semSubstituto' => $semSubstituto,
            'mudou' => $mudou,
            'blocos' => count($slots),
            'ultimo' => $ultimo,
            'primeiro' => $primeiro,
        ];
    }
}

// CSV de uma semana (segunda $seg) para uma página do pacote.
function escCsvSemana(array $itemPacote, string $seg, string $corte): string {
    $linhas = [];
    foreach (ESC_BLOCOS as $hora => $bloco) {
        $cols = [];
        for ($d = 0; $d < 7; $d++) {
            $dia = date('Y-m-d', strtotime("{$seg} +{$d} day"));
            $slot = "{$dia}T{$hora}:00:00";
            $cel = '';
            if ($slot >= $corte && isset($itemPacote['celulas'][$slot])) {
                $codigos = [];
                foreach ($itemPacote['celulas'][$slot] as $code => $assist) {
                    $codigos[] = $code . ($assist ? '#' : '');
                }
                $cel = implode(', ', $codigos);
            }
            $cols[] = $cel;
        }
        $linhas[] = implode(';', $cols);
    }
    return implode("\n", $linhas);
}

function escSemanasDoItem(array $item, string $corte): array {
    if (!$item['ultimo']) {
        return [];
    }
    $semanas = [];
    $seg = segundaDaSemana(substr($corte, 0, 10));
    $fim = segundaDaSemana(substr($item['ultimo'], 0, 10));
    while ($seg <= $fim) {
        $semanas[] = $seg;
        $seg = date('Y-m-d', strtotime("{$seg} +7 day"));
    }
    return $semanas;
}

// ---------- marcar apagado / postado (5c_escalas.esc_troca_postagem) ----------
if (escPost()) {
    escExigir('decidir');
    $pid = (int) ($_POST['pagina'] ?? 0);
    $seg = (string) ($_POST['semana'] ?? '');
    $passo = (string) ($_POST['passo'] ?? '');
    if (!isset($pacote[$pid]) || !preg_match('~^\d{4}-\d{2}-\d{2}$~', $seg) || !in_array($passo, ['apagado', 'postado', 'desfazer'], true)) {
        throw new RuntimeException('Página ou semana fora do pacote.');
    }
    $it = $pacote[$pid];
    $csv = escCsvSemana($it, $seg, $corte);
    $ini = max(substr($corte, 0, 10), $seg);
    $fim = min(substr((string) $it['ultimo'], 0, 10), date('Y-m-d', strtotime("{$seg} +6 day")));
    escW('INSERT INTO ' . escT('esc_troca_postagem') . ' (corte, page_id, pagina, party_de, party_para, semana, date_schedule, date_end, csv, csv_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) AS n
          ON DUPLICATE KEY UPDATE csv = n.csv, csv_hash = n.csv_hash, date_schedule = n.date_schedule, date_end = n.date_end', [
        $ts, $pid, $it['mov']['pagina'], $it['mov']['de_party'], $it['mov']['para_party'], $seg, $ini, $fim, $csv, hash('sha256', $csv),
    ]);
    if ($passo === 'apagado') {
        escW('UPDATE ' . escT('esc_troca_postagem') . ' SET apagado_em = NOW(), apagado_por = ? WHERE corte = ? AND page_id = ? AND semana = ?', [escUserId(), $ts, $pid, $seg]);
    } elseif ($passo === 'postado') {
        escW('UPDATE ' . escT('esc_troca_postagem') . ' SET postado_em = NOW(), postado_por = ?, apagado_em = COALESCE(apagado_em, NOW()) WHERE corte = ? AND page_id = ? AND semana = ?', [escUserId(), $ts, $pid, $seg]);
    } else {
        escW('UPDATE ' . escT('esc_troca_postagem') . ' SET apagado_em = NULL, apagado_por = NULL, postado_em = NULL, postado_por = NULL WHERE corte = ? AND page_id = ? AND semana = ?', [$ts, $pid, $seg]);
    }
    escLog('troca.' . $passo, 'esc_troca_postagem', $ts . ':' . $pid . ':' . $seg);
    escVoltar('trocas', ['corte' => $ts], $it['mov']['pagina'] . ': ' . ($passo === 'desfazer' ? 'marcação desfeita.' : 'marcado como ' . $passo . '.'));
}
$postagens = [];
if ($ts !== '') {
    foreach (escQ('SELECT page_id, semana, apagado_em, postado_em, conferido_em FROM ' . escT('esc_troca_postagem') . ' WHERE corte = ?', [$ts]) as $pp) {
        $postagens[(int) $pp['page_id'] . '|' . substr($pp['semana'], 0, 10)] = $pp;
    }
}

// ---------- download ----------
if (($_GET['baixar'] ?? '') === 'csv') {
    $pid = (int) ($_GET['pagina'] ?? 0);
    $seg = (string) ($_GET['semana'] ?? '');
    if (!isset($pacote[$pid]) || !preg_match('~^\d{4}-\d{2}-\d{2}$~', $seg)) {
        http_response_code(404);
        echo 'Página ou semana fora do pacote.';
        return;
    }
    $nomeArquivo = preg_replace('~[^A-Za-z0-9_-]+~', '_', $pacote[$pid]['mov']['pagina']) . '_' . $seg . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');
    echo escCsvSemana($pacote[$pid], $seg, $corte);
    return;
}

$diaCorte = $corte ? substr($corte, 0, 10) : '';
$horaCorte = $corte ? substr($corte, 11, 2) : '';
$blocosManuais = [];
if ($corte && $horaCorte !== '01') {
    $passou = false;
    foreach (ESC_BLOCOS as $hora => $bloco) {
        if ($hora === $horaCorte) {
            $passou = true;
        }
        if ($passou && $hora !== '01') {
            $blocosManuais[] = $bloco;
        }
    }
}
$totalMudou = array_sum(array_column($pacote, 'mudou'));
$totalLacunas = array_sum(array_map(static fn($i) => count($i['lacunas']), $pacote));
$totalSemSubstituto = array_sum(array_map(static fn($i) => count($i['semSubstituto']), $pacote));
$nomePessoa = static function (string $code) use (&$estrutura, &$nomes): string {
    return $estrutura[$code]['rep_name'] ?? ($nomes[$code] ?? $code);
};
?>
<header class="cabecalho">
  <span class="eyebrow">Páginas</span>
  <h1>Trocas de página</h1>
  <p class="muted">Lê as trocas de party do Planner e refaz a escala já postada: quem é da party nova e já está escalado no mesmo bloco assume a página. Depois monta o que apagar e o CSV para postar no Schedule Ajustment.</p>
</header>

<form class="filtros" method="get" action="<?php echo h(MODULE_URL); ?>/index.php">
  <input type="hidden" name="p" value="trocas">
  <label>Lote de trocas (horário marcado no Planner)
    <select name="corte" onchange="this.form.submit()">
      <?php foreach ($cortes as $c): ?>
        <option value="<?php echo h($c['ts']); ?>" <?php echo $c['ts'] === $ts ? 'selected' : ''; ?>>
          <?php echo h(dataBr($c['ts'], true) . ' ' . substr($c['ts'], 11, 5) . ' · ' . $c['n'] . ' página(s)' . ((int) $c['pendentes'] > 0 ? ' · ' . $c['pendentes'] . ' a aplicar' : ' · já aplicada')); ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>
  <noscript><button type="submit">Ver</button></noscript>
</form>

<?php if (!$cortes): ?>
  <section class="panel"><p class="muted">Nenhuma troca de página no Planner nos últimos 14 dias nem prevista.</p></section>
<?php return; endif; ?>

<section class="kpis">
  <div class="kpi"><span class="muted">Páginas no lote</span><strong><?php echo count($movs); ?></strong><span class="muted small">corte no bloco <?php echo h(blocoDaHora($horaCorte)); ?> de <?php echo h(dataBr($diaCorte, true)); ?> (<?php echo h($horaCorte); ?>:00)</span></div>
  <div class="kpi"><span class="muted">Blocos que mudam</span><strong><?php echo (int) $totalMudou; ?></strong><span class="muted small">na escala postada até <?php echo h(dataBr(max(array_column($pacote, 'ultimo') ?: [$corte]), true)); ?></span></div>
  <div class="kpi"><span class="muted">Sem substituto</span><strong class="<?php echo $totalSemSubstituto ? 'laranja' : ''; ?>"><?php echo (int) $totalSemSubstituto; ?></strong><span class="muted small">blocos em que a party nova não tem titular escalado: quem está continua</span></div>
  <div class="kpi"><span class="muted">Situação no Planner</span><strong><?php echo array_sum(array_column($movs, 'aplicada')) === count($movs) ? 'Aplicada' : 'A aplicar'; ?></strong><span class="muted small"><?php echo $totalMudou === 0 ? 'escala postada já está com as parties novas' : 'escala postada ainda com as parties antigas'; ?></span></div>
</section>

<section class="panel">
  <h2>1 · O que muda</h2>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th>Página</th><th>De</th><th>Para</th><th class="num">Blocos postados</th><th class="num">Mudam</th><th class="num">Sem substituto</th><th>Sai</th><th>Entra</th></tr></thead>
    <tbody>
    <?php foreach ($pacote as $pid => $it): $m = $it['mov']; ?>
      <tr>
        <td><strong><?php echo h($m['pagina']); ?></strong></td>
        <td><?php echo h($m['de_party'] ?: '—'); ?></td>
        <td><?php echo h($m['para_party']); ?><?php echo $m['de_time'] && $m['de_time'] !== $m['para_time'] ? ' <span class="tag">muda de time</span>' : ''; ?></td>
        <td class="num"><?php echo (int) $it['blocos']; ?></td>
        <td class="num"><?php echo (int) $it['mudou']; ?></td>
        <td class="num <?php echo $it['semSubstituto'] ? 'laranja' : ''; ?>"><?php echo count($it['semSubstituto']); ?></td>
        <td class="small"><?php foreach ($it['sai'] as $code => $sl): ?><span class="pessoa" title="<?php echo h($code . ' · ' . ($estrutura[$code]['party'] ?? 'sem party') . ' · ' . count($sl) . ' bloco(s)'); ?>"><?php echo h($nomePessoa($code)); ?> <span class="muted">(<?php echo count($sl); ?>)</span></span><?php endforeach; ?><?php echo !$it['sai'] ? '<span class="muted">—</span>' : ''; ?></td>
        <td class="small"><?php foreach ($it['entra'] as $code => $sl): ?><span class="pessoa" title="<?php echo h($code . ' · ' . ($estrutura[$code]['role'] ?? '') . ' · ' . count($sl) . ' bloco(s)'); ?>"><?php echo h($nomePessoa($code)); ?> <span class="muted">(<?php echo count($sl); ?>)</span></span><?php endforeach; ?><?php echo !$it['entra'] ? '<span class="muted">—</span>' : ''; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <ul class="muted small regras">
    <li><strong>Titular</strong> (Primaris, Secundus) segue a party: entra na página nova nos blocos em que já está escalado em alguma página da própria party.</li>
    <li>Quem é do <strong>time novo</strong> (outra party do time cobrindo, ou Tertius do time) fica; <strong>Buffer</strong> fica sempre (não tem time).</li>
    <li>Quem é de outro time só sai se a party nova tiver titular escalado no mesmo bloco. Se não tiver, continua e o bloco aparece em "sem substituto" para alguém decidir.</li>
    <li>Se o lote já foi aplicado e a escala refeita, nada muda aqui: serve para conferir.</li>
  </ul>
</section>

<section class="panel">
  <h2>2 · Postar no site da 5C (Schedule Ajustment)</h2>
  <p class="muted small">Faça em todas as páginas antes de postar qualquer uma. Delete Range apaga só a página do Model.</p>
  <ol class="roteiro">
    <?php if ($blocosManuais): ?>
    <li><strong>Delete Range</strong> de <?php echo h(dataBr(date('Y-m-d', strtotime($diaCorte . ' +1 day')))); ?> até o último dia postado de cada página, e apague <strong>à mão</strong> em <?php echo h(dataBr($diaCorte, true)); ?> os blocos <?php echo h(implode(', ', $blocosManuais)); ?>.</li>
    <?php else: ?>
    <li><strong>Delete Range</strong> de <?php echo h(dataBr($diaCorte)); ?> até o último dia postado de cada página.</li>
    <?php endif; ?>
    <li><strong>Save Schedule CSV</strong> com Date Schedule <?php echo h(dataBr($diaCorte)); ?>. No dia <?php echo h(dataBr($diaCorte)); ?> só vão os blocos a partir do corte; o resto fica em branco (o intervalo precisa começar nesse dia).</li>
  </ol>

  <?php foreach ($pacote as $pid => $it): $m = $it['mov']; ?>
    <?php foreach (escSemanasDoItem($it, $corte) as $seg):
        $ini = max($diaCorte, $seg);
        $fim = min(substr((string) $it['ultimo'], 0, 10), date('Y-m-d', strtotime("{$seg} +6 day")));
        $csv = escCsvSemana($it, $seg, $corte);
        $idPre = 'csv-' . $pid . '-' . $seg;
    ?>
    <div class="cartao-csv">
      <div class="linha-titulo">
        <div><strong><?php echo h($m['pagina']); ?></strong> <span class="muted small">→ <?php echo h($m['para_party']); ?></span></div>
        <span class="muted small">Model <strong><?php echo h($m['pagina']); ?></strong> · Date Schedule <strong><?php echo h(date('d/m/Y', strtotime($ini))); ?></strong> · Date End <strong><?php echo h(date('d/m/Y', strtotime($fim))); ?></strong></span>
      </div>
      <pre id="<?php echo h($idPre); ?>" class="csv"><?php echo h($csv); ?></pre>
      <div class="acoes">
        <button type="button" class="botao primario" data-copiar="<?php echo h($idPre); ?>">Copiar CSV</button>
        <a class="botao" href="<?php echo h(link_tela('trocas', ['corte' => $ts, 'baixar' => 'csv', 'pagina' => $pid, 'semana' => $seg])); ?>">Baixar .csv</a>
        <?php if ($it['lacunas']): ?><span class="tag laranja"><?php echo count($it['lacunas']); ?> lacuna(s)</span><?php endif; ?>
        <?php $pp = $postagens[$pid . '|' . $seg] ?? null; ?>
        <span class="tag <?php echo $pp && $pp['postado_em'] ? 'verde' : ($pp && $pp['apagado_em'] ? 'amarelo' : 'laranja'); ?>"><?php echo $pp && $pp['postado_em'] ? 'postado ' . h(date('d/m H:i', strtotime($pp['postado_em']))) : ($pp && $pp['apagado_em'] ? 'intervalo apagado' : 'a fazer'); ?></span>
        <?php if (escPode('decidir')): ?>
        <form method="post" action="<?php echo h(link_tela('trocas', ['corte' => $ts])); ?>" class="acoes">
          <?php echo campoCsrf(); ?><input type="hidden" name="pagina" value="<?php echo (int) $pid; ?>"><input type="hidden" name="semana" value="<?php echo h($seg); ?>">
          <?php if (!$pp || !$pp['apagado_em']): ?><button type="submit" name="passo" value="apagado" class="botao">1 · Apaguei</button><?php endif; ?>
          <?php if (!$pp || !$pp['postado_em']): ?><button type="submit" name="passo" value="postado" class="botao">2 · Postei</button><?php endif; ?>
          <?php if ($pp && ($pp['apagado_em'] || $pp['postado_em'])): ?><button type="submit" name="passo" value="desfazer" class="botao">Desfazer</button><?php endif; ?>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if (!$it['blocos']): ?>
      <p class="muted small"><?php echo h($m['pagina']); ?>: sem escala postada a partir do corte.</p>
    <?php endif; ?>
  <?php endforeach; ?>
  <p class="muted small">Linhas T1 a T6, colunas de segunda a domingo. "#" = assistente. O "$" (turno secundário) o site calcula sozinho no input.</p>
</section>
