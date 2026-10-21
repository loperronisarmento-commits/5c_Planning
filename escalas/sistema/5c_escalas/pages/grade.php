<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/grade.php
// Grade do rascunho: por página, por pessoa e De→Para. Ajuste manual de
// célula (fica travado nos recálculos), publicação e CSV para postar.
// ===============================================

$planoId = (int) ($_GET['plano'] ?? 0);
if (!$planoId) {
    $prox = escPlanoAtual(date('Y-m-d', strtotime(segundaDaSemana(date('Y-m-d')) . ' +1 week')))
        ?? escPlanoAtual(segundaDaSemana(date('Y-m-d')));
    $planoId = $prox ? (int) $prox['id'] : 0;
}
$plano = $planoId ? escQ1('SELECT * FROM ' . escT('esc_plano') . ' WHERE id = ?', [$planoId]) : null;

if (escPost()) {
    escExigir('planejar');
    if (!$plano) {
        throw new RuntimeException('Plano não encontrado.');
    }
    $acao = $_POST['acao'] ?? '';
    if ($acao === 'editar') {
        if ($plano['status'] !== 'RASCUNHO') {
            throw new RuntimeException('Só dá para ajustar um rascunho. Para mexer na versão publicada, use "Criar rascunho a partir desta".');
        }
        $pid = (int) $_POST['page_id'];
        $dia = (string) $_POST['dia'];
        $turno = (string) $_POST['turno'];
        if (!isset(ESC_TURNOS[$turno]) || !preg_match('~^\d{4}-\d{2}-\d{2}$~', $dia)) {
            throw new RuntimeException('Célula inválida.');
        }
        $pagina = escQ1('SELECT pagina FROM ' . escT('esc_plano_alocacao') . ' WHERE plano_id = ? AND page_id = ? LIMIT 1', [$planoId, $pid]);
        $regular = strtoupper(trim((string) ($_POST['regular'] ?? '')));
        $assist = array_filter(array_map(static fn($x) => strtoupper(trim($x)), explode(',', (string) ($_POST['assistentes'] ?? ''))));
        foreach (array_merge([$regular], $assist) as $c) {
            if ($c !== '' && !preg_match('~^[A-Z]\d{6}$~', $c)) {
                throw new RuntimeException('Código inválido: ' . $c . ' (formato letra + 6 números).');
            }
        }
        [$b0, $b1] = ESC_TURNOS[$turno];
        $celulas = [[$dia, $b0], [$turno === 'D' ? escMaisDias($dia, 1) : $dia, $b1]];
        escTx(function () use ($planoId, $pid, $turno, $celulas, $regular, $assist, $pagina) {
            $antes = [];
            foreach ($celulas as [$d, $b]) {
                $antes[$d . ' ' . $b] = escQW('SELECT rep_code, papel FROM ' . escT('esc_plano_alocacao') . ' WHERE plano_id = ? AND page_id = ? AND dia = ? AND bloco = ?', [$planoId, $pid, $d, $b]);
                escW('DELETE FROM ' . escT('esc_plano_alocacao') . ' WHERE plano_id = ? AND page_id = ? AND dia = ? AND bloco = ?', [$planoId, $pid, $d, $b]);
                $linhas = [];
                if ($regular === '' && !$assist) {
                    $linhas[] = ['rep' => null, 'papel' => 'REGULAR', 'origem' => 'LACUNA'];
                }
                if ($regular !== '') {
                    $linhas[] = ['rep' => $regular, 'papel' => 'REGULAR', 'origem' => 'MANUAL'];
                }
                foreach ($assist as $a) {
                    $linhas[] = ['rep' => $a, 'papel' => 'ASSISTENTE', 'origem' => 'MANUAL'];
                }
                foreach ($linhas as $l) {
                    escW('INSERT INTO ' . escT('esc_plano_alocacao') . ' (plano_id, dia, bloco, turno, page_id, pagina, rep_code, papel, origem, travada, motivo, alterado_em, alterado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), ?)',
                        [$planoId, $d, $b, $turno, $pid, $pagina['pagina'] ?? '', $l['rep'], $l['papel'], $l['origem'], 'ajuste manual na grade (travado)', escUserId()]);
                }
            }
            escLog('grade.ajustar', 'esc_plano_alocacao', $planoId . ':' . $pid . ':' . $celulas[0][0] . ':' . $turno, $antes, ['regular' => $regular, 'assistentes' => array_values($assist)]);
        });
        escVoltar('grade', ['plano' => $planoId, 'turno' => $turno, 'time' => $_GET['time'] ?? ''], 'Célula ajustada e travada (recálculos mantêm).');
    }
    if ($acao === 'publicar') {
        $resumo = json_decode((string) $plano['resumo'], true) ?: [];
        if (!empty($resumo['anomalias']) && empty($_POST['ciente'])) {
            throw new RuntimeException('Há ' . (int) $resumo['anomalias'] . ' anomalia(s) programada(s). Marque "estou ciente" para publicar assim.');
        }
        escTx(function () use ($plano, $planoId) {
            escW('UPDATE ' . escT('esc_plano') . " SET status = 'SUBSTITUIDO' WHERE semana_inicio = ? AND time_nome = '' AND status = 'PUBLICADO' AND id <> ?", [substr($plano['semana_inicio'], 0, 10), $planoId]);
            escW('UPDATE ' . escT('esc_plano') . " SET status = 'PUBLICADO', publicado_em = NOW(), publicado_por = ? WHERE id = ?", [escUserId(), $planoId]);
            escLog('plano.publicar', 'esc_plano', $planoId, ['status' => $plano['status']], ['status' => 'PUBLICADO']);
        });
        escVoltar('grade', ['plano' => $planoId], 'Versão ' . escPlanoRotulo($plano) . ' publicada. Baixe os CSVs e poste no site da 5C.');
    }
    if ($acao === 'rascunho') {
        escW('INSERT INTO ' . escT('esc_recalculo_evento') . " (gatilho, descricao, semana_inicio, status) VALUES ('MANUAL', 'Rascunho a partir da versão publicada', ?, 'DETECTADO')", [substr($plano['semana_inicio'], 0, 10)]);
        $novo = escMotorGerar(substr($plano['semana_inicio'], 0, 10), 'RECALCULO', escUserId(), escUltimoId());
        escVoltar('grade', ['plano' => $novo], 'Rascunho criado. Quem está na versão publicada foi mantido.');
    }
    throw new RuntimeException('Ação desconhecida.');
}

if (!$plano) {
    echo '<header class="cabecalho"><span class="eyebrow">Planejamento</span><h1>Grade da escala</h1></header>';
    echo '<section class="panel"><p class="muted">Nenhum rascunho gerado ainda.</p><a class="botao primario" href="' . h(link_tela('gerar')) . '">Gerar escala</a></section>';
    return;
}

$seg = substr($plano['semana_inicio'], 0, 10);
$dias = escDiasDaSemana($seg);
$aloc = escQ('SELECT dia, bloco, turno, page_id, pagina, rep_code, papel, origem, travada, motivo FROM ' . escT('esc_plano_alocacao') . ' WHERE plano_id = ?', [$planoId]);

// ---------- downloads ----------
if (isset($_GET['baixar'])) {
    // O T1 de segunda é o fim do turno D do domingo anterior: vem do plano da semana anterior.
    $anterior = escPlanoAtual(escMaisDias($seg, -7));
    if ($anterior) {
        $aloc = array_merge($aloc, escQ('SELECT dia, bloco, turno, page_id, pagina, rep_code, papel, origem, travada, motivo FROM ' . escT('esc_plano_alocacao') . " WHERE plano_id = ? AND dia = ? AND bloco = 'T1'", [(int) $anterior['id'], $seg]));
    }
    $porPagina = [];
    foreach ($aloc as $a) {
        if ($a['rep_code'] === null) {
            continue;
        }
        $slot = substr($a['dia'], 0, 10) . 'T' . escHoraDoBloco($a['bloco']) . ':00:00';
        $porPagina[(int) $a['page_id']]['nome'] = $a['pagina'];
        $porPagina[(int) $a['page_id']]['cel'][$slot][$a['rep_code']] = $a['papel'] === 'ASSISTENTE';
    }
    $arquivoDe = static fn(string $nome) => preg_replace('~[^A-Za-z0-9_-]+~', '_', $nome) . '_' . $seg . '.csv';
    if ($_GET['baixar'] === 'csv') {
        $pid = (int) ($_GET['pagina'] ?? 0);
        if (!isset($porPagina[$pid])) {
            http_response_code(404);
            echo 'Página sem alocação neste plano.';
            return;
        }
        $csv = escCsvT1T6($porPagina[$pid]['cel'], $seg);
        escW('INSERT INTO ' . escT('esc_exportacao') . " (plano_id, formato, page_id, pagina, semana, date_schedule, date_end, csv_hash, gerado_por) VALUES (?, 'T1T6', ?, ?, ?, ?, ?, ?, ?)",
            [$planoId, $pid, $porPagina[$pid]['nome'], $seg, $seg, escMaisDias($seg, 6), hash('sha256', $csv), escUserId()]);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $arquivoDe($porPagina[$pid]['nome']) . '"');
        echo $csv;
        return;
    }
    if ($_GET['baixar'] === 'zip') {
        if (!class_exists('ZipArchive')) {
            http_response_code(500);
            echo 'O PHP do servidor não tem ZipArchive. Baixe página a página.';
            return;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'esc');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        foreach ($porPagina as $pid => $pg) {
            $csv = escCsvT1T6($pg['cel'], $seg);
            $zip->addFromString($arquivoDe($pg['nome']), $csv);
        }
        $zip->close();
        escW('INSERT INTO ' . escT('esc_exportacao') . " (plano_id, formato, semana, date_schedule, date_end, csv_hash, gerado_por) VALUES (?, 'T1T6', ?, ?, ?, ?, ?)",
            [$planoId, $seg, $seg, escMaisDias($seg, 6), hash_file('sha256', $tmp), escUserId()]);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="escala_' . $seg . '_' . escPlanoRotulo($plano) . '.zip"');
        readfile($tmp);
        @unlink($tmp);
        return;
    }
    http_response_code(400);
    echo 'Download desconhecido.';
    return;
}

$aba = $_GET['aba'] ?? 'pagina';
$turnoSel = $_GET['turno'] ?? 'C';
if (!isset(ESC_TURNOS[$turnoSel])) {
    $turnoSel = 'C';
}
$paginasBot = escMotorPaginas();
$porId = [];
foreach ($paginasBot as $pg) {
    $porId[$pg['page_id']] = $pg;
}
$times = array_values(array_unique(array_filter(array_map(static fn($p) => $p['time'], $paginasBot))));
sort($times);
$timeSel = (string) ($_GET['time'] ?? '');
$nomes = escNomes();
$resumo = json_decode((string) $plano['resumo'], true) ?: [];

// Primeiro bloco do turno representa o turno na grade.
$blocoRef = ESC_TURNOS[$turnoSel][0];
$grade = [];
$paginasPlano = [];
foreach ($aloc as $a) {
    $pid = (int) $a['page_id'];
    $paginasPlano[$pid] = $a['pagina'];
    if ($a['bloco'] !== $blocoRef) {
        continue;
    }
    $grade[$pid][substr($a['dia'], 0, 10)][] = $a;
}
$listaPaginas = [];
foreach ($paginasPlano as $pid => $nome) {
    $time = $porId[$pid]['time'] ?? '';
    if ($timeSel !== '' && $time !== $timeSel) {
        continue;
    }
    $listaPaginas[] = ['id' => $pid, 'nome' => $nome, 'time' => $time, 'party' => $porId[$pid]['party'] ?? ''];
}
usort($listaPaginas, static fn($a, $b) => [$a['time'], $a['party'], $a['nome']] <=> [$b['time'], $b['party'], $b['nome']]);

$sel = null;
if (isset($_GET['pg'], $_GET['dia'])) {
    $sel = ['page_id' => (int) $_GET['pg'], 'dia' => (string) $_GET['dia'], 'linhas' => $grade[(int) $_GET['pg']][(string) $_GET['dia']] ?? []];
}
$params = ['plano' => $planoId, 'turno' => $turnoSel, 'time' => $timeSel];
$ehRascunho = $plano['status'] === 'RASCUNHO';
?>
<header class="cabecalho">
  <span class="eyebrow">Planejamento</span>
  <h1>Grade da escala · <?php echo h(escPlanoRotulo($plano)); ?></h1>
  <p class="muted">Semana de <?php echo h(dataBr($seg)); ?> a <?php echo h(dataBr(escMaisDias($seg, 6))); ?> · <span class="tag <?php echo $plano['status'] === 'PUBLICADO' ? 'verde' : 'amarelo'; ?>"><?php echo h(strtolower($plano['status'])); ?></span> · Capacity de <?php echo h(dataBr($plano['capacity_calculo'])); ?></p>
</header>

<section class="kpis">
  <div class="kpi"><span class="muted">Blocos alocados</span><strong><?php echo (int) ($resumo['blocos'] ?? 0); ?></strong><span class="muted small"><?php echo (int) ($resumo['pessoas'] ?? 0); ?> pessoas configuradas</span></div>
  <div class="kpi"><span class="muted">Lacunas</span><strong class="<?php echo !empty($resumo['lacunas']) ? 'laranja' : ''; ?>"><?php echo (int) ($resumo['lacunas'] ?? 0); ?></strong><span class="muted small">blocos sem ninguém</span></div>
  <div class="kpi"><span class="muted">Anomalias programadas</span><strong class="<?php echo !empty($resumo['anomalias']) ? 'vermelho' : ''; ?>"><?php echo (int) ($resumo['anomalias'] ?? 0); ?></strong><span class="muted small">dias seguidos acima do modelo</span></div>
  <div class="kpi"><span class="muted">Buffers sem página</span><strong><?php echo (int) ($resumo['buffers_ociosos'] ?? 0); ?></strong><span class="muted small">turnos sem necessidade</span></div>
</section>

<section class="panel">
  <div class="linha-titulo">
    <div class="chips">
      <?php foreach (['pagina' => 'Por página', 'pessoa' => 'Por pessoa', 'depara' => 'De→Para'] as $k => $rot): ?>
        <a class="chip-filtro <?php echo $aba === $k ? 'ativo' : ''; ?>" href="<?php echo h(link_tela('grade', $params + ['aba' => $k])); ?>"><?php echo h($rot); ?></a>
      <?php endforeach; ?>
    </div>
    <form method="get" action="<?php echo h(MODULE_URL); ?>/index.php" class="acoes">
      <input type="hidden" name="p" value="grade"><input type="hidden" name="plano" value="<?php echo $planoId; ?>"><input type="hidden" name="aba" value="<?php echo h($aba); ?>">
      <select name="turno" onchange="this.form.submit()" aria-label="Turno">
        <?php foreach (['A' => 'Turno A (05–13)', 'C' => 'Turno C (13–21)', 'D' => 'Turno D (21–05)'] as $t => $rot): ?><option value="<?php echo $t; ?>" <?php echo $t === $turnoSel ? 'selected' : ''; ?>><?php echo h($rot); ?></option><?php endforeach; ?>
      </select>
      <select name="time" onchange="this.form.submit()" aria-label="Time">
        <option value="">Todos os times</option>
        <?php foreach ($times as $t): ?><option <?php echo $t === $timeSel ? 'selected' : ''; ?>><?php echo h($t); ?></option><?php endforeach; ?>
      </select>
    </form>
  </div>

  <div class="acoes">
    <a class="botao" href="<?php echo h(link_tela('grade', ['plano' => $planoId, 'baixar' => 'zip'])); ?>">Baixar CSVs (.zip)</a>
    <?php if (escPode('planejar') && $ehRascunho): ?>
    <form method="post" action="<?php echo h(link_tela('grade', $params)); ?>" class="acoes">
      <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="publicar">
      <?php if (!empty($resumo['anomalias'])): ?><label class="small"><input type="checkbox" name="ciente" value="1"> estou ciente das <?php echo (int) $resumo['anomalias']; ?> anomalias</label><?php endif; ?>
      <button type="submit" class="botao primario">Publicar esta versão</button>
    </form>
    <?php elseif (escPode('planejar') && $plano['status'] === 'PUBLICADO'): ?>
    <form method="post" action="<?php echo h(link_tela('grade', $params)); ?>">
      <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="rascunho">
      <button type="submit" class="botao">Criar rascunho a partir desta</button>
    </form>
    <?php endif; ?>
  </div>

<?php if ($aba === 'pagina'): ?>
  <div class="tabela-rolagem">
  <table class="grade">
    <thead><tr><th>Página</th><?php foreach ($dias as $d): ?><th><?php echo h(dataBr($d, true)); ?></th><?php endforeach; ?><th></th></tr></thead>
    <tbody>
    <?php foreach ($listaPaginas as $pg): ?>
      <tr>
        <td><strong><?php echo h($pg['nome']); ?></strong><br><span class="muted small"><?php echo h($pg['party'] ?: 'sem party'); ?></span></td>
        <?php foreach ($dias as $d): $cel = $grade[$pg['id']][$d] ?? []; $lac = $cel && !array_filter($cel, static fn($x) => $x['rep_code'] !== null); ?>
          <td class="celula <?php echo $lac ? 'lacuna' : ''; ?> <?php echo array_filter($cel, static fn($x) => (int) $x['travada'] === 1) ? 'travada' : ''; ?>">
            <a href="<?php echo h(link_tela('grade', $params + ['pg' => $pg['id'], 'dia' => $d])); ?>#detalhe">
            <?php if (!$cel): ?><span class="muted">—</span><?php endif; ?>
            <?php foreach ($cel as $x): ?>
              <?php if ($x['rep_code'] === null): ?><span class="laranja">lacuna</span><br>
              <?php else: ?><span class="pessoa-cel origem-<?php echo h(strtolower($x['origem'])); ?>" title="<?php echo h(($nomes[$x['rep_code']] ?? '') . ' · ' . $x['motivo']); ?>"><?php echo h(nomeCurto($nomes[$x['rep_code']] ?? $x['rep_code'])); ?><?php echo $x['papel'] === 'ASSISTENTE' ? ' #' : ''; ?></span><br><?php endif; ?>
            <?php endforeach; ?>
            </a>
          </td>
        <?php endforeach; ?>
        <td><a class="botao" href="<?php echo h(link_tela('grade', ['plano' => $planoId, 'baixar' => 'csv', 'pagina' => $pg['id']])); ?>">CSV</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$listaPaginas): ?><tr><td colspan="9" class="muted">Nenhuma página neste filtro.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <p class="muted small">Mostrando o bloco <?php echo h($blocoRef); ?> do turno <?php echo h($turnoSel); ?>. Cores: party dona, outra party, Tertius, Buffer, ajuste manual. "#" = assistente. Clique na célula para ver o porquê e ajustar.</p>

  <?php if ($sel): $pgSel = $porId[$sel['page_id']] ?? ['nome' => $paginasPlano[$sel['page_id']] ?? '', 'party' => '']; ?>
  <div class="cartao-csv" id="detalhe">
    <div class="linha-titulo"><strong><?php echo h($pgSel['nome']); ?> · <?php echo h(dataBr($sel['dia'], true)); ?> · turno <?php echo h($turnoSel); ?></strong><a class="botao" href="<?php echo h(link_tela('grade', $params)); ?>">Fechar</a></div>
    <ul class="regras small">
      <?php foreach ($sel['linhas'] as $x): ?>
        <li><?php echo $x['rep_code'] ? h(($nomes[$x['rep_code']] ?? '') . ' (' . $x['rep_code'] . ')') : '<span class="laranja">lacuna</span>'; ?> · <?php echo h(strtolower($x['papel'])); ?> · <?php echo h($x['motivo']); ?><?php echo (int) $x['travada'] ? ' · <strong>travado</strong>' : ''; ?></li>
      <?php endforeach; ?>
      <?php if (!$sel['linhas']): ?><li class="muted">A página não tem necessidade neste turno.</li><?php endif; ?>
    </ul>
    <?php if (escPode('planejar') && $ehRascunho):
        $reg = ''; $ass = [];
        foreach ($sel['linhas'] as $x) { if ($x['rep_code'] && $x['papel'] === 'REGULAR' && $reg === '') { $reg = $x['rep_code']; } elseif ($x['rep_code']) { $ass[] = $x['rep_code']; } }
    ?>
    <form method="post" action="<?php echo h(link_tela('grade', $params)); ?>" class="form-grade">
      <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="editar"><input type="hidden" name="page_id" value="<?php echo (int) $sel['page_id']; ?>"><input type="hidden" name="dia" value="<?php echo h($sel['dia']); ?>"><input type="hidden" name="turno" value="<?php echo h($turnoSel); ?>">
      <label>Regular (código)<input type="text" name="regular" value="<?php echo h($reg); ?>" placeholder="ex.: M443425"></label>
      <label>Assistentes (códigos separados por vírgula)<input type="text" name="assistentes" value="<?php echo h(implode(', ', $ass)); ?>"></label>
      <button type="submit" class="botao primario">Salvar e travar</button>
      <span class="muted small">Vale para os dois blocos do turno. Deixe tudo vazio para marcar como lacuna.</span>
    </form>
    <?php elseif (!$ehRascunho): ?>
      <p class="muted small">Versão <?php echo h(strtolower($plano['status'])); ?>: para ajustar, crie um rascunho a partir dela.</p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

<?php elseif ($aba === 'pessoa'):
    $config = escMotorConfig($seg);
    $estrutura = escEstrutura();
    $porPessoa = [];
    foreach ($aloc as $a) {
        if ($a['rep_code'] === null || $a['bloco'] !== ESC_TURNOS[$a['turno']][0]) {
            continue;
        }
        $porPessoa[$a['rep_code']][substr($a['dia'], 0, 10)][] = $a['pagina'] . ($a['papel'] === 'ASSISTENTE' ? ' #' : '');
    }
    $folgas = [];
    foreach (escQ('SELECT rep_code, dia, tipo FROM ' . escT('esc_plano_folga') . ' WHERE plano_id = ?', [$planoId]) as $f) {
        $folgas[$f['rep_code']][substr($f['dia'], 0, 10)] = $f['tipo'];
    }
    $pessoas = [];
    foreach ($config as $rep => $c) {
        if ($c['turno'] !== $turnoSel) {
            continue;
        }
        $e = $estrutura[$rep] ?? null;
        if ($timeSel !== '' && ($e['team'] ?? '') !== $timeSel) {
            continue;
        }
        $pessoas[] = ['rep' => $rep, 'nome' => $nomes[$rep] ?? $rep, 'papel' => $c['tipo'] === 'BUFFER' ? 'Buffer' : ($e['role'] ?? 'sem estrutura'), 'party' => $e['party'] ?? '', 'modelo' => $c['modelo']];
    }
    usort($pessoas, static fn($a, $b) => [$a['party'], $a['nome']] <=> [$b['party'], $b['nome']]);
?>
  <div class="tabela-rolagem">
  <table class="grade">
    <thead><tr><th>Pessoa</th><?php foreach ($dias as $d): ?><th><?php echo h(dataBr($d, true)); ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($pessoas as $ps): ?>
      <tr>
        <td><strong><?php echo h(nomeCurto($ps['nome'])); ?></strong><br><span class="muted small"><?php echo h($ps['rep'] . ' · ' . $ps['papel'] . ($ps['party'] ? ' · ' . $ps['party'] : '') . ' · ' . $ps['modelo']); ?></span></td>
        <?php foreach ($dias as $d): ?>
          <td class="celula small">
            <?php if (isset($folgas[$ps['rep']][$d])): ?><span class="muted"><?php echo $folgas[$ps['rep']][$d] === 'INDISPONIBILIDADE' ? 'indisponível' : 'folga'; ?></span>
            <?php elseif (!empty($porPessoa[$ps['rep']][$d])): ?><?php echo h(implode(', ', array_unique($porPessoa[$ps['rep']][$d]))); ?>
            <?php else: ?><span class="laranja">sem página</span><?php endif; ?>
          </td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$pessoas): ?><tr><td colspan="8" class="muted">Ninguém neste turno e filtro.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>

<?php else:
    $tipoSel = $_GET['tipo'] ?? '';
    $sqlTipo = $tipoSel !== '' ? ' AND tipo = ?' : '';
    $parDif = $tipoSel !== '' ? [$planoId, $tipoSel] : [$planoId];
    $difs = escQ('SELECT * FROM ' . escT('esc_plano_diferenca') . ' WHERE plano_para_id = ?' . $sqlTipo . ' ORDER BY dia, bloco, pagina LIMIT 1000', $parDif);
    $contagem = escQ('SELECT tipo, COUNT(*) AS n FROM ' . escT('esc_plano_diferenca') . ' WHERE plano_para_id = ? GROUP BY tipo', [$planoId]);
?>
  <?php if (!$plano['plano_anterior_id']): ?>
    <p class="muted">Esta é a primeira versão da semana: não há De→Para.</p>
  <?php else: ?>
  <div class="chips">
    <a class="chip-filtro <?php echo $tipoSel === '' ? 'ativo' : ''; ?>" href="<?php echo h(link_tela('grade', ['plano' => $planoId, 'aba' => 'depara'])); ?>">Todos</a>
    <?php foreach ($contagem as $c): ?><a class="chip-filtro <?php echo $tipoSel === $c['tipo'] ? 'ativo' : ''; ?>" href="<?php echo h(link_tela('grade', ['plano' => $planoId, 'aba' => 'depara', 'tipo' => $c['tipo']])); ?>"><?php echo h(strtolower($c['tipo'])); ?> · <?php echo (int) $c['n']; ?></a><?php endforeach; ?>
  </div>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th>Dia</th><th>Bloco</th><th>Página</th><th>Tipo</th><th>De</th><th>Para</th><th>Motivo</th><th>Aplicado</th></tr></thead>
    <tbody>
    <?php foreach ($difs as $d): ?>
      <tr>
        <td><?php echo h(dataBr($d['dia'], true)); ?></td><td><?php echo h($d['bloco']); ?></td><td><?php echo h($d['pagina']); ?></td>
        <td><span class="tag"><?php echo h(strtolower($d['tipo'])); ?></span></td>
        <td class="small"><?php echo $d['rep_de'] ? h(nomeCurto($nomes[$d['rep_de']] ?? $d['rep_de'])) . ($d['papel_de'] === 'ASSISTENTE' ? ' #' : '') : '—'; ?></td>
        <td class="small"><?php echo $d['rep_para'] ? h(nomeCurto($nomes[$d['rep_para']] ?? $d['rep_para'])) . ($d['papel_para'] === 'ASSISTENTE' ? ' #' : '') : '—'; ?></td>
        <td class="small"><?php echo h($d['motivo']); ?></td>
        <td><?php echo (int) $d['aplicado'] ? 'sim' : '<span class="laranja">não · virou sugestão</span>'; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$difs): ?><tr><td colspan="8" class="muted">Nenhuma diferença.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
<?php endif; ?>
</section>
