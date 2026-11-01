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

// Marcar apagado / postado fica mais abaixo: precisa do CSV montado.
if (escPost() && ($_POST['acao'] ?? '') !== 'postagem') {
    escExigir('planejar');
    if (!$plano) {
        throw new RuntimeException(t('Plano não encontrado.'));
    }
    $acao = $_POST['acao'] ?? '';
    if ($acao === 'editar') {
        if ($plano['status'] !== 'RASCUNHO') {
            throw new RuntimeException($plano['status'] === 'PUBLICADO'
                ? t('Só dá para ajustar um rascunho. Para mexer na versão publicada, use "Criar rascunho a partir desta".')
                : t('Esta versão foi substituída. Abra a versão atual da semana (aviso no topo da grade) para ajustar.'));
        }
        $pid = (int) $_POST['page_id'];
        $dia = (string) $_POST['dia'];
        $turno = (string) $_POST['turno'];
        if (!isset(ESC_TURNOS[$turno]) || !preg_match('~^\d{4}-\d{2}-\d{2}$~', $dia)) {
            throw new RuntimeException(t('Célula inválida.'));
        }
        $pagina = escQ1('SELECT pagina FROM ' . escT('esc_plano_alocacao') . ' WHERE plano_id = ? AND page_id = ? LIMIT 1', [$planoId, $pid]);
        // A busca manda "Nome (CÓDIGO)"; também vale o código puro.
        $codigo = static function (string $v): string {
            $v = strtoupper(trim($v));
            return preg_match('~\(([A-Z]\d{6})\)\s*$~', $v, $m) ? $m[1] : $v;
        };
        $regular = $codigo((string) ($_POST['regular'] ?? ''));
        $brutos = is_array($_POST['assistentes'] ?? null) ? $_POST['assistentes'] : explode(',', (string) ($_POST['assistentes'] ?? ''));
        $assist = array_values(array_unique(array_filter(array_map(static fn($x) => $codigo((string) $x), $brutos))));
        foreach (array_merge([$regular], $assist) as $c) {
            if ($c !== '' && !preg_match('~^[A-Z]\d{6}$~', $c)) {
                throw new RuntimeException(t('Código inválido: {codigo} (formato letra + 6 números).', ['codigo' => $c]));
            }
        }
        if (($plano['estrutura'] ?? '') === 'V4' && !escV4TurnoNovo($dia, $turno, escV4DiaVirada(), escV4TurnoVirada())) {
            throw new RuntimeException(t('Este turno é de antes da virada: vale a escala já postada no site da 5C, e a célula não muda por aqui.'));
        }
        if ($dia < substr($plano['semana_inicio'], 0, 10) || $dia > substr($plano['semana_fim'], 0, 10)) {
            throw new RuntimeException(t('Célula inválida.'));
        }
        [$b0, $b1] = ESC_TURNOS[$turno];
        $celulas = [[$dia, $b0], [$turno === 'D' ? escMaisDias($dia, 1) : $dia, $b1]];
        // Conflitos (as mesmas regras da tela Horas extras): folga, indisponibilidade aprovada,
        // outro turno, a mesma pessoa em outra página no bloco, mais de 12 h seguidas e 52 h na
        // semana. Avisa e só grava com "salvar mesmo assim".
        $pessoasCel = array_values(array_unique(array_filter(array_merge([$regular], $assist))));
        if ($pessoasCel && empty($_POST['mesmo_assim'])) {
            $alertas = escGradeConflitos($planoId, $pid, $dia, $turno, $celulas, $pessoasCel);
            if ($alertas) {
                throw new RuntimeException(t('Confira antes de salvar: {alertas}. Para salvar assim, marque "salvar mesmo assim".', ['alertas' => implode(' · ', $alertas)]));
            }
        }
        // Liderança vigente no dia: OS (cadastro) e OC/OA (esc_lideranca), para a marca certa no CSV.
        $lideres = [];
        foreach (escQ("SELECT UPPER(rep_code) AS r FROM " . escT('esc_colaborador_config') . " WHERE funcao = 'DECURIO' AND vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)", [$dia, $dia]) as $l) {
            $lideres[$l['r']] = 'LIDER_OS';
        }
        foreach (escQ("SELECT UPPER(rep_code) AS r, nivel FROM " . escT('esc_lideranca') . " WHERE nivel IN ('OC', 'OA') AND rep_code IS NOT NULL AND vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)", [$dia, $dia]) as $l) {
            $lideres[$l['r']] = 'LIDER_' . $l['nivel'];
        }
        escTx(function () use ($planoId, $pid, $turno, $celulas, $regular, $assist, $pagina, $lideres) {
            $antes = [];
            foreach ($celulas as [$d, $b]) {
                $antes[$d . ' ' . $b] = escQW('SELECT rep_code, papel, origem FROM ' . escT('esc_plano_alocacao') . ' WHERE plano_id = ? AND page_id = ? AND dia = ? AND bloco = ?', [$planoId, $pid, $d, $b]);
                $origemAntes = array_column(array_filter($antes[$d . ' ' . $b], static fn($x) => $x['rep_code'] !== null), 'origem', 'rep_code');
                escW('DELETE FROM ' . escT('esc_plano_alocacao') . ' WHERE plano_id = ? AND page_id = ? AND dia = ? AND bloco = ?', [$planoId, $pid, $d, $b]);
                $linhas = [];
                if ($regular === '' && !$assist) {
                    $linhas[] = ['rep' => null, 'papel' => 'REGULAR', 'origem' => 'LACUNA'];
                }
                // Na página 5C Schedule cada um continua como estava (Overlap -5 ou Buffer -2);
                // quem entra novo entra como Buffer (-2). Lá não há assistente.
                $na5c = ($pagina['pagina'] ?? '') === escV4Param('v4_overlap_pagina', '5C Schedule');
                $origemDe = static fn(string $r): string => $na5c ? ($origemAntes[$r] ?? ($lideres[$r] ?? 'BUFFER')) : 'MANUAL';
                if ($regular !== '') {
                    $linhas[] = ['rep' => $regular, 'papel' => 'REGULAR', 'origem' => $origemDe($regular)];
                }
                foreach ($assist as $a) {
                    $linhas[] = ['rep' => $a, 'papel' => $na5c ? 'REGULAR' : 'ASSISTENTE', 'origem' => $origemDe($a)];
                }
                foreach ($linhas as $l) {
                    escW('INSERT INTO ' . escT('esc_plano_alocacao') . ' (plano_id, dia, bloco, turno, page_id, pagina, rep_code, papel, origem, travada, motivo, alterado_em, alterado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), ?)',
                        [$planoId, $d, $b, $turno, $pid, $pagina['pagina'] ?? '', $l['rep'], $l['papel'], $l['origem'], 'ajuste manual na grade (travado)', escUserId()]);
                }
            }
            escLog('grade.ajustar', 'esc_plano_alocacao', $planoId . ':' . $pid . ':' . $celulas[0][0] . ':' . $turno, $antes, ['regular' => $regular, 'assistentes' => array_values($assist)]);
        });
        escVoltar('grade', ['plano' => $planoId, 'turno' => $turno, 'time' => $_GET['time'] ?? ''], t('Célula ajustada e travada (recálculos mantêm).'));
    }
    if ($acao === 'publicar') {
        if ($plano['status'] !== 'RASCUNHO') {
            throw new RuntimeException(t('Esta versão está {status}: só dá para publicar o rascunho atual. Abra a versão mais recente em Gerar escala.', ['status' => mb_strtolower(escRotulo('status_plano', $plano['status']))]));
        }
        $resumo = json_decode((string) $plano['resumo'], true) ?: [];
        if (!empty($resumo['anomalias']) && empty($_POST['ciente'])) {
            throw new RuntimeException(tn((int) $resumo['anomalias'], 'Há {n} anomalia programada. Marque "estou ciente" para publicar assim.', 'Há {n} anomalias programadas. Marque "estou ciente" para publicar assim.'));
        }
        escTx(function () use ($plano, $planoId) {
            escW('UPDATE ' . escT('esc_plano') . " SET status = 'SUBSTITUIDO' WHERE semana_inicio = ? AND time_nome = '' AND status = 'PUBLICADO' AND id <> ?", [substr($plano['semana_inicio'], 0, 10), $planoId]);
            escW('UPDATE ' . escT('esc_plano') . " SET status = 'PUBLICADO', publicado_em = NOW(), publicado_por = ? WHERE id = ?", [escUserId(), $planoId]);
            escLog('plano.publicar', 'esc_plano', $planoId, ['status' => $plano['status']], ['status' => 'PUBLICADO']);
        });
        escVoltar('grade', ['plano' => $planoId], t('Versão {versao} publicada. Baixe os CSVs e poste no site da 5C.', ['versao' => escPlanoRotulo($plano)]));
    }
    if ($acao === 'rascunho') {
        $atualSem = escPlanoAtual(substr($plano['semana_inicio'], 0, 10));
        if ($plano['status'] !== 'PUBLICADO' || ($atualSem && (int) $atualSem['id'] !== $planoId)) {
            throw new RuntimeException(t('Já existe uma versão mais nova desta semana ({versao}). Abra a versão atual para ajustar.', ['versao' => $atualSem ? escPlanoRotulo($atualSem) : '—']));
        }
        escW('INSERT INTO ' . escT('esc_recalculo_evento') . " (gatilho, descricao, semana_inicio, status) VALUES ('MANUAL', 'Rascunho a partir da versão publicada', ?, 'DETECTADO')", [substr($plano['semana_inicio'], 0, 10)]);
        $novo = escMotorGerar(substr($plano['semana_inicio'], 0, 10), 'RECALCULO', escUserId(), escUltimoId(), true);
        escVoltar('grade', ['plano' => $novo], t('Rascunho criado a partir da versão publicada, com o mínimo de mudanças.') . escMinimoAviso());
    }
    throw new RuntimeException(t('Ação desconhecida.'));
}

if (!$plano) {
    echo '<header class="cabecalho"><span class="eyebrow">' . h(t('Escala')) . '</span><h1>' . h(t('Grade da escala')) . '</h1></header>';
    echo '<section class="panel"><p class="muted">' . h(t('Nenhum rascunho gerado ainda.')) . '</p><a class="botao primario" href="' . h(link_tela('gerar')) . '">' . h(t('Gerar escala')) . '</a></section>';
    return;
}

$seg = substr($plano['semana_inicio'], 0, 10);
$dias = escDiasDaSemana($seg);
$aloc = escQ('SELECT dia, bloco, turno, page_id, pagina, rep_code, papel, origem, travada, motivo FROM ' . escT('esc_plano_alocacao') . ' WHERE plano_id = ?', [$planoId]);

// ---------- CSVs para postar (download e menu de copiar) ----------
// $alocCsv = alocações do plano + o que completa o CSV (T1 de segunda e, na virada, o já postado).
// O T1 de segunda é o fim do turno D do domingo anterior: vem do plano da semana anterior.
$anterior = escPlanoPublicado(escMaisDias($seg, -7)) ?? escPlanoAtual(escMaisDias($seg, -7));
$t1Anterior = $anterior ? escQ('SELECT dia, bloco, turno, page_id, pagina, rep_code, papel, origem, travada, motivo FROM ' . escT('esc_plano_alocacao') . " WHERE plano_id = ? AND dia = ? AND bloco = 'T1'", [(int) $anterior['id'], $seg]) : [];
if (!$t1Anterior) {
    // Sem plano da semana anterior (virada): o T1 já postado ou, se não houver,
    // quem está no T6 de domingo no scheduleday continua o turno D.
    $nomesPag = array_column($aloc, 'pagina', 'page_id');
    $postado = [];
    foreach (escQ("SELECT modeloId, SUBSTRING(shiftDateAndHour, 12, 2) AS hora, UPPER(codigo) AS code, assitente
                     FROM `5c_schedule`.`scheduleday`
                    WHERE shiftDateAndHour IN (?, ?) AND modeloId <> 3",
                  [escMaisDias($seg, -1) . 'T21:00:00', $seg . 'T01:00:00']) as $r) {
        $postado[(int) $r['modeloId']][$r['hora']][$r['code']] = (int) $r['assitente'] === 1;
    }
    foreach ($postado as $pid => $horas) {
        if (!isset($nomesPag[$pid])) {
            continue;
        }
        foreach ($horas['01'] ?? $horas['21'] as $code => $assist) {
            $t1Anterior[] = ['dia' => $seg, 'bloco' => 'T1', 'turno' => 'D', 'page_id' => $pid, 'pagina' => $nomesPag[$pid],
                'rep_code' => $code, 'papel' => $assist ? 'ASSISTENTE' : 'REGULAR', 'origem' => 'MANUAL', 'travada' => 0, 'motivo' => 'continua o turno D postado'];
        }
    }
}
$alocCsv = array_merge($aloc, $t1Anterior);
// Semana da virada: antes dela o CSV repete o que já está postado, para não apagar nada no site.
$viradaSlot = str_replace(' ', 'T', escV4Param('v4_virada', '2026-11-01 05:00')) . ':00';
if (escV4Ativo($seg) && $viradaSlot > $seg . 'T01:00:00') {
    $nomesPag = array_column($alocCsv, 'pagina', 'page_id');
    if ($nomesPag) {
        foreach (escQ('SELECT modeloId, shiftDateAndHour AS slot, UPPER(codigo) AS code, assitente
                         FROM `5c_schedule`.`scheduleday`
                        WHERE shiftDateAndHour >= ? AND shiftDateAndHour < ? AND modeloId IN (' . escIn(array_keys($nomesPag)) . ')',
                      array_merge([$seg . 'T01:00:00', $viradaSlot], array_keys($nomesPag))) as $r) {
            $alocCsv[] = ['dia' => substr($r['slot'], 0, 10), 'bloco' => blocoDaHora(substr($r['slot'], 11, 2)), 'turno' => '',
                'page_id' => (int) $r['modeloId'], 'pagina' => $nomesPag[(int) $r['modeloId']], 'rep_code' => $r['code'],
                'papel' => (int) $r['assitente'] === 1 ? 'ASSISTENTE' : 'REGULAR', 'origem' => 'MANUAL', 'travada' => 0, 'motivo' => 'já postado'];
        }
    }
}
$porPagina = [];
foreach ($alocCsv as $a) {
    if ($a['rep_code'] === null) {
        continue;
    }
    $slot = substr($a['dia'], 0, 10) . 'T' . escHoraDoBloco($a['bloco']) . ':00:00';
    $porPagina[(int) $a['page_id']]['nome'] = $a['pagina'];
    // Overlap (dia de treinamento) sai com "-5", o motivo que o CSV do site lê; assistente com "#".
    $porPagina[(int) $a['page_id']]['cel'][$slot][$a['rep_code']] = escMarcaCsv($a);
    if ($a['origem'] === 'SOBRECARGA') {
        $porPagina[(int) $a['page_id']]['acima'][$slot][$a['rep_code']] = true; // só para o PDF (⚠)
    }
}
$arquivoDe = static fn(string $nome) => (escModoTreino() ? 'TREINO_' : '') . preg_replace('~[^A-Za-z0-9_-]+~', '_', $nome) . '_' . $seg . '.csv';
if (isset($_GET['baixar'])) {
    if ($_GET['baixar'] === 'csv') {
        $pid = (int) ($_GET['pagina'] ?? 0);
        if (!isset($porPagina[$pid])) {
            http_response_code(404);
            echo t('Página sem alocação neste plano.');
            return;
        }
        $csv = escCsvT1T6($porPagina[$pid]['cel'], $seg, escPostagemCorte($seg));
        escW('INSERT INTO ' . escT('esc_exportacao') . " (plano_id, formato, page_id, pagina, semana, date_schedule, date_end, csv_hash, gerado_por) VALUES (?, 'T1T6', ?, ?, ?, ?, ?, ?, ?)",
            [$planoId, $pid, $porPagina[$pid]['nome'], $seg, escPostagemCorte($seg) !== '' ? substr(escPostagemCorte($seg), 0, 10) : $seg, escMaisDias($seg, 6), hash('sha256', $csv), escUserId()]);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $arquivoDe($porPagina[$pid]['nome']) . '"');
        echo $csv;
        return;
    }
    if ($_GET['baixar'] === 'zip') {
        if (!class_exists('ZipArchive')) {
            http_response_code(500);
            echo t('O PHP do servidor não tem ZipArchive. Baixe página a página.');
            return;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'esc');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        foreach ($porPagina as $pid => $pg) {
            $csv = escCsvT1T6($pg['cel'], $seg, escPostagemCorte($seg));
            $zip->addFromString($arquivoDe($pg['nome']), $csv);
        }
        $zip->close();
        escW('INSERT INTO ' . escT('esc_exportacao') . " (plano_id, formato, semana, date_schedule, date_end, csv_hash, gerado_por) VALUES (?, 'T1T6', ?, ?, ?, ?, ?)",
            [$planoId, $seg, $seg, escMaisDias($seg, 6), hash_file('sha256', $tmp), escUserId()]);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . (escModoTreino() ? 'TREINO_' : '') . 'escala_' . $seg . '_' . escPlanoRotulo($plano) . '.zip"');
        readfile($tmp);
        @unlink($tmp);
        return;
    }
    http_response_code(400);
    echo t('Download desconhecido.');
    return;
}

// ---------- PDF de metas (?ver=metas[&pagina=id]): a mesma folha do PDF da escala, uma página
// por folha, com cada pessoa escalada na semana e o atingimento dela na página (5CReps ÷ meta,
// pela fatia das mensagens, como no mérito) em cada uma das 4 semanas anteriores. ----------
if (($_GET['ver'] ?? '') === 'metas') {
    $nomesPdf = escNomes();
    $soPid = (int) ($_GET['pagina'] ?? 0);
    $overlapPdf = escV4Param('v4_overlap_pagina', '5C Schedule');
    $folhas = array_filter($porPagina, static fn($pg, $pid) => $soPid ? $pid === $soPid : $pg['nome'] !== $overlapPdf, ARRAY_FILTER_USE_BOTH);
    uasort($folhas, static fn($a, $b) => strnatcasecmp($a['nome'], $b['nome']));
    $semanasMeta = [];
    for ($i = 4; $i >= 1; $i--) {
        $semanasMeta[] = [escMaisDias($seg, -7 * $i), escMaisDias($seg, -7 * $i + 6)];
    }
    // Na semana: blocos, turnos (o T1 é o fim do turno D) e marcas de cada pessoa na página.
    $turnoDaHora = ['05' => 'A', '09' => 'A', '13' => 'C', '17' => 'C', '21' => 'D', '01' => 'D'];
    $membros = [];
    foreach ($folhas as $pid => $pg) {
        foreach ($pg['cel'] as $slot => $quem) {
            foreach ($quem as $rep => $marca) {
                $m = $membros[$pid][$rep] ?? ['rep' => $rep, 'nome' => $nomesPdf[$rep] ?? $rep, 'blocos' => 0, 'turnos' => [], 'marcas' => []];
                $m['blocos']++;
                $m['turnos'][$turnoDaHora[substr($slot, 11, 2)] ?? '?'] = true;
                if ($marca !== false) {
                    $m['marcas'][is_string($marca) ? $marca : '#'] = true;
                }
                $membros[$pid][$rep] = $m;
            }
        }
        uasort($membros[$pid], static fn($a, $b) => [-$a['blocos'], $a['nome']] <=> [-$b['blocos'], $b['nome']]);
    }
    $repsMeta = array_keys(array_merge(...array_values($membros ?: [[]])));
    $ating = escMeritoAtingimentoPaginas(array_column($folhas, 'nome'), $repsMeta, $semanasMeta);
    $tetoMeta = (float) str_replace(',', '.', escV4Param('merito_desempenho_teto', '2'));
    // [realizado, meta] → "104%" em verde (meta batida) ou vermelho; sem meta, "—".
    $pctMeta = static function (?array $rm) use ($tetoMeta): string {
        if (!$rm || $rm[1] <= 0) {
            return '<span class="pag-pdf-vazio">—</span>';
        }
        $r = $rm[0] / $rm[1];
        $txt = $r >= $tetoMeta ? '≥' . round($tetoMeta * 100) . '%' : round($r * 100) . '%';
        return '<span class="' . ($r >= 1 ? 'verde' : 'vermelho') . '">' . h($txt) . '</span>';
    };
    $somaMeta = static fn(array $porSemana): ?array => $porSemana ? [array_sum(array_column($porSemana, 0)), array_sum(array_column($porSemana, 1))] : null;
    $dePdf = date('d/m/Y', strtotime($seg));
    $atePdf = date('d/m/Y', strtotime(escMaisDias($seg, 6)));
    $versaoPdf = escPlanoRotulo($plano) . ' · ' . ($plano['status'] === 'PUBLICADO' ? t('publicada') : t('rascunho'));
    $arquivoPdf = count($folhas) === 1 ? t('Metas {pagina} {de} a {ate}', ['pagina' => reset($folhas)['nome'], 'de' => date('d-m-Y', strtotime($seg)), 'ate' => date('d-m-Y', strtotime(escMaisDias($seg, 6)))])
        : t('Metas das páginas {de} a {ate}', ['de' => date('d-m-Y', strtotime($seg)), 'ate' => date('d-m-Y', strtotime(escMaisDias($seg, 6)))]);
    ?>
<div class="pag-pdf">
  <div class="acoes nao-imprimir">
    <a class="botao" href="<?php echo h(link_tela('grade', ['plano' => $planoId])); ?>">← <?php echo h(t('Voltar para a grade')); ?></a>
    <button type="button" class="botao primario" id="pag-pdf-baixar"><?php echo h(t('Baixar PDF')); ?></button>
    <span class="muted small"><?php echo h(tn(count($folhas), '{n} página, uma por folha (A4 paisagem). Na impressão, escolha "Salvar como PDF".', '{n} páginas, uma por folha (A4 paisagem). Na impressão, escolha "Salvar como PDF".')); ?></span>
  </div>
  <?php foreach ($folhas as $pid => $pg): $daPagina = $ating[$pg['nome']] ?? []; ?>
  <section class="pag-pdf-folha">
    <h1><?php echo h($pg['nome']); ?></h1>
    <p class="pag-pdf-sub"><?php echo h(t('Histórico de meta · escalados na semana de {de} a {ate} · histórico de {hde} a {hate} · versão {versao} · gerado em {gerado}', ['de' => $dePdf, 'ate' => $atePdf,
        'hde' => date('d/m/Y', strtotime($semanasMeta[0][0])), 'hate' => date('d/m/Y', strtotime(end($semanasMeta)[1])), 'versao' => $versaoPdf, 'gerado' => date('d/m/Y H:i')])); ?></p>
    <table class="pag-pdf-tabela pag-pdf-metas">
      <thead><tr>
        <th class="pag-pdf-membro"><?php echo h(t('Membro')); ?></th>
        <th class="pag-pdf-semana"><?php echo h(t('Na semana')); ?></th>
        <?php foreach ($semanasMeta as [$a, $b]): ?><th class="pag-pdf-num"><?php echo h(date('d/m', strtotime($a)) . '–' . date('d/m', strtotime($b))); ?></th><?php endforeach; ?>
        <th class="pag-pdf-num"><?php echo h(t('4 semanas')); ?></th>
      </tr></thead>
      <tbody>
      <?php foreach ($membros[$pid] ?? [] as $rep => $m): $hist = $daPagina[strtoupper($rep)] ?? []; ?>
        <tr>
          <td><div class="pag-pdf-pessoa"><strong><?php echo h(nomeCurto($m['nome'])); ?></strong> <span class="mono"><?php echo h($rep); ?></span></div></td>
          <td><?php echo h(implode(' · ', array_keys($m['turnos'])) . ' · ' . tn($m['blocos'], '{n} bloco', '{n} blocos')); ?><?php foreach (array_keys($m['marcas']) as $mc): ?> <span class="pag-pdf-marca"><?php echo h($mc); ?></span><?php endforeach; ?></td>
          <?php foreach (array_keys($semanasMeta) as $i): ?><td class="pag-pdf-num"><?php echo $pctMeta($hist[$i] ?? null); ?></td><?php endforeach; ?>
          <td class="pag-pdf-num"><strong><?php echo $pctMeta($somaMeta($hist)); ?></strong></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="pag-pdf-legenda"><?php echo h(t('Atingimento = realizado ÷ meta 5CReps da página, na parte de cada pessoa (pela fatia das mensagens no dia e no bloco, a mesma regra do mérito). Verde: meta batida (100% ou mais). — : sem mensagens na página na semana. # assistente · -2 buffer de plantão · -4 trainee · -5 Overlap · -6 OA · -7 OC · -8 OS.')); ?></p>
  </section>
  <?php endforeach; ?>
  <?php if (!$folhas): ?><p class="muted"><?php echo h(t('Página sem alocação neste plano.')); ?></p><?php endif; ?>
</div>
<script>
(function () {
  // PDF pela impressão do navegador; o título vira o nome do arquivo sugerido ao salvar.
  var titulo = document.title, nome = <?php echo json_encode($arquivoPdf); ?>;
  function imprimir() { document.title = nome; window.print(); }
  document.getElementById('pag-pdf-baixar').addEventListener('click', imprimir);
  window.addEventListener('afterprint', function () { document.title = titulo; });
  <?php if (!empty($_GET['imprimir'])): ?>window.addEventListener('load', function () { setTimeout(imprimir, 300); });<?php endif; ?>
})();
</script>
    <?php
    return;
}

// ---------- PDF da escala de cada página (?ver=pdf[&pagina=id]): A4 paisagem com o papel timbrado,
// uma página por folha, blocos T1 a T6 × os 7 dias, como sai no site (inclui o que já estava postado
// antes da virada). Sem &pagina sai uma folha por página do plano, menos a 5C Schedule. ----------
if (($_GET['ver'] ?? '') === 'pdf') {
    $nomesPdf = escNomes();
    $soPid = (int) ($_GET['pagina'] ?? 0);
    $overlapPdf = escV4Param('v4_overlap_pagina', '5C Schedule');
    $folhas = array_filter($porPagina, static fn($pg, $pid) => $soPid ? $pid === $soPid : $pg['nome'] !== $overlapPdf, ARRAY_FILTER_USE_BOTH);
    // Ordem das folhas: da modelo que mais fatura para a que menos (30 dias, fechado + pendente),
    // e dentro da modelo a página que mais fatura (a mesma fila do motor).
    $recPdf = escV4ReceitaPaginas(array_column($folhas, 'nome'), (int) escV4Param('v4_receita_dias', '30'));
    $modeloPdf = [];
    if ($folhas) {
        foreach (escQ('SELECT pagina, modelo FROM `5c_planning`.`fc_pagina_carteira` WHERE vigente_ate IS NULL AND pagina IN (' . escIn(array_column($folhas, 'nome')) . ')', array_column($folhas, 'nome')) as $l) {
            $modeloPdf[$l['pagina']] = $l['modelo'];
        }
    }
    $recModeloPdf = [];
    foreach ($folhas as $pg) {
        $m = $modeloPdf[$pg['nome']] ?? $pg['nome'];
        $recModeloPdf[$m] = ($recModeloPdf[$m] ?? 0) + (float) ($recPdf[$pg['nome']]['total'] ?? 0);
    }
    $ordemPdf = static fn($pg) => [-round($recModeloPdf[$modeloPdf[$pg['nome']] ?? $pg['nome']] ?? 0), -round((float) ($recPdf[$pg['nome']]['total'] ?? 0)), $pg['nome']];
    uasort($folhas, static fn($a, $b) => $ordemPdf($a) <=> $ordemPdf($b));
    $usdPdf = static fn(float $v): string => 'US$ ' . number_format($v, 0, ',', '.');
    // Venda por hora de cada SR nos últimos v4_receita_dias (US$ vendido ÷ horas no chat, cap_rep_hora):
    // o número que o motor usa para pôr os melhores SRs nas páginas grandes. Faixa contra a mediana.
    $diasPdf = max(1, (int) escV4Param('v4_receita_dias', '30'));
    $codDePdf = [];
    foreach (escQ('SELECT UPPER(rep_code) AS rep, code_infloww FROM `5c_wfm_db_mis`.`vw_rep_estrutura_atual` WHERE code_infloww IS NOT NULL') as $l) {
        $codDePdf[(string) $l['code_infloww']] = $l['rep'];
    }
    $vendaPdf = [];
    foreach (escQ("SELECT SUBSTRING_INDEX(rep, ' ', 1) AS cod, SUM(valor_comprado) / COUNT(*) AS usd_h FROM `5c_capacity`.`cap_rep_hora` WHERE dia >= DATE_SUB(CURDATE(), INTERVAL {$diasPdf} DAY) GROUP BY cod") as $l) {
        if (isset($codDePdf[$l['cod']])) {
            $vendaPdf[$codDePdf[$l['cod']]] = (float) $l['usd_h'];
        }
    }
    $vMed = array_values($vendaPdf);
    sort($vMed);
    $medPdf = $vMed ? $vMed[intdiv(count($vMed), 2)] : null;
    $vendaTxt = static function (string $rep) use ($vendaPdf, $medPdf): string {
        if (!isset($vendaPdf[$rep])) {
            return '';
        }
        $n = $vendaPdf[$rep];
        $faixa = $medPdf ? ($n >= 1.15 * $medPdf ? 'acima' : ($n < 0.85 * $medPdf ? 'abaixo' : 'media')) : 'media';
        return ' <span class="pag-pdf-venda pag-pdf-venda-' . $faixa . '">US$ ' . h(number_format($n, 0, ',', '.')) . '/h</span>';
    };
    $cartPdf = [];
    foreach (escQ('SELECT DISTINCT pp.page_id, p.carteira FROM ' . escT('esc_posto_pagina') . ' pp JOIN ' . escT('esc_posto') . ' p ON p.id = pp.posto_id
                    WHERE p.vigente_desde <= ? AND (p.vigente_ate IS NULL OR p.vigente_ate >= ?) ORDER BY p.carteira', [escMaisDias($seg, 6), escMaisDias($seg, 6)]) as $l) {
        $cartPdf[(int) $l['page_id']][$l['carteira']] = true;
    }
    // Blocos por unidade (U1, U2, U3): dentro de cada uma, a ordem de faturamento. A posição
    // geral é a da fila de toda a operação.
    $posGeral = array_flip(array_keys($folhas));
    $unidadePdf = static function (int $pid) use ($cartPdf): int {
        $c = array_key_first($cartPdf[$pid] ?? []);
        return $c !== null && preg_match('~^U(\d)~', (string) $c, $m) ? (int) $m[1] : 9;
    };
    $chaves = array_keys($folhas);
    usort($chaves, static fn($a, $b) => [$unidadePdf($a), $posGeral[$a]] <=> [$unidadePdf($b), $posGeral[$b]]);
    $folhas = array_replace(array_flip($chaves), $folhas);
    $porUnidadePdf = [];
    foreach (array_keys($folhas) as $pid) {
        $porUnidadePdf[$unidadePdf($pid)][] = $pid;
    }
    // Um arquivo por unidade (?unidade=1, 2 ou 3), para cada liderança ver só a sua. O arquivo com
    // todas as unidades é só de Planejamento; a folha de uma página (?pagina=) continua livre.
    $soUnidade = (int) ($_GET['unidade'] ?? 0);
    if (!$soPid && !$soUnidade) {
        escExigir('planejar');
    }
    if ($soUnidade) {
        $folhas = array_intersect_key($folhas, array_flip($porUnidadePdf[$soUnidade] ?? []));
        $porUnidadePdf = array_intersect_key($porUnidadePdf, [$soUnidade => 1]);
    }
    // Representantes de cada página (embaixo da escala): tempo de casa (a data mais antiga entre o
    // cadastro e o início do contrato no headcount), dias escalados na página no 5C Schedule e
    // atingimento da meta 5CReps na página (na parte de cada um, como no PDF de metas), nos 30,
    // 60 e 90 dias antes da semana.
    $repsPag = [];
    foreach ($folhas as $pid => $pg) {
        foreach ($pg['cel'] as $quem) {
            foreach (array_keys($quem) as $rep) {
                $repsPag[$pid][strtoupper((string) $rep)] = ($repsPag[$pid][strtoupper((string) $rep)] ?? 0) + 1;
            }
        }
        arsort($repsPag[$pid]);
    }
    $todosReps = $repsPag ? array_keys(array_merge(...array_values($repsPag))) : [];
    $janelasPdf = [30, 60, 90];
    $casaPdf = [];
    $diasPag = [];
    $metaPag = [];
    if ($todosReps) {
        $dataBr = static function (?string $v): ?string {
            return preg_match('~^(\d{2})/(\d{2})/(\d{4})$~', trim((string) $v), $m) ? $m[3] . '-' . $m[2] . '-' . $m[1] : null;
        };
        foreach (escQ('SELECT UPPER(code) AS code, registerDate, startContract FROM `5c_headcount`.`headcount` WHERE code IN (' . escIn($todosReps) . ')', $todosReps) as $l) {
            $ds = array_filter([$dataBr($l['registerDate']), $dataBr($l['startContract'])]);
            if ($ds) {
                $ini = min($ds);
                $casaPdf[$l['code']] = isset($casaPdf[$l['code']]) ? min($casaPdf[$l['code']], $ini) : $ini;
            }
        }
        $pidDe = [];
        foreach ($folhas as $pid => $pg) {
            $pidDe[escV4ChavePagina($pg['nome'])] = $pid;
        }
        $sqlDias = [];
        foreach ($janelasPdf as $j) {
            $sqlDias[] = "COUNT(DISTINCT IF(shiftDateAndHour >= '" . escMaisDias($seg, -$j) . "T00:00:00', LEFT(shiftDateAndHour, 10), NULL)) AS d{$j}";
        }
        foreach (escQ('SELECT UPPER(codigo) AS rep, modeloNome AS pagina, ' . implode(', ', $sqlDias) . ' FROM `5c_schedule`.`scheduleday`
                        WHERE codigo IN (' . escIn($todosReps) . ') AND shiftDateAndHour >= ? AND shiftDateAndHour < ?
                        GROUP BY UPPER(codigo), modeloNome', array_merge($todosReps, [escMaisDias($seg, -max($janelasPdf)) . 'T00:00:00', $seg . 'T00:00:00'])) as $l) {
            $pid = $pidDe[escV4ChavePagina((string) $l['pagina'])] ?? null;
            if ($pid !== null) {
                foreach ($janelasPdf as $j) {
                    $diasPag[$pid][$l['rep']][$j] = ($diasPag[$pid][$l['rep']][$j] ?? 0) + (int) $l['d' . $j];
                }
            }
        }
        // Períodos sem sobreposição (a função põe cada dia num período só); 60 e 90 são somados.
        $periodosPdf = [];
        $ant = 0;
        foreach ($janelasPdf as $j) {
            $periodosPdf[] = [escMaisDias($seg, -$j), escMaisDias($seg, -$ant - 1)];
            $ant = $j;
        }
        $atPdf = escMeritoAtingimentoPaginas(array_column($folhas, 'nome'), $todosReps, $periodosPdf);
        foreach ($folhas as $pid => $pg) {
            foreach ($atPdf[$pg['nome']] ?? [] as $rep => $porPeriodo) {
                $acc = [0.0, 0.0];
                foreach ($janelasPdf as $i => $j) {
                    $acc = [$acc[0] + (float) ($porPeriodo[$i][0] ?? 0), $acc[1] + (float) ($porPeriodo[$i][1] ?? 0)];
                    $metaPag[$pid][strtoupper((string) $rep)][$j] = $acc;
                }
            }
        }
    }
    $casaTxt = static function (?string $ini) use ($seg): string {
        if ($ini === null) {
            return '—';
        }
        $m = max(0, (int) floor((strtotime($seg) - strtotime($ini)) / (86400 * 30.44)));
        return $m >= 12 ? intdiv($m, 12) . 'a ' . ($m % 12) . 'm' : $m . 'm';
    };
    $metaTxt = static function (?array $rm): string {
        if (!$rm || $rm[1] <= 0) {
            return '<span class="pag-pdf-vazio">—</span>';
        }
        $r = $rm[0] / $rm[1];
        return '<span class="' . ($r >= 1 ? 'verde' : 'vermelho') . '">' . h(round($r * 100) . '%') . '</span>';
    };
    $blocosPdf = ['01' => ['T1', '01:00–05:00'], '05' => ['T2', '05:00–09:00'], '09' => ['T3', '09:00–13:00'], '13' => ['T4', '13:00–17:00'], '17' => ['T5', '17:00–21:00'], '21' => ['T6', '21:00–01:00']];
    $dePdf = date('d/m/Y', strtotime($seg));
    $atePdf = date('d/m/Y', strtotime(escMaisDias($seg, 6)));
    $versaoPdf = escPlanoRotulo($plano) . ' · ' . ($plano['status'] === 'PUBLICADO' ? t('publicada') : t('rascunho'));
    $arquivoPdf = $soPid && count($folhas) === 1 ? t('Escala {pagina} {de} a {ate}', ['pagina' => reset($folhas)['nome'], 'de' => date('d-m-Y', strtotime($seg)), 'ate' => date('d-m-Y', strtotime(escMaisDias($seg, 6)))])
        : ($soUnidade ? t('Escala U{u} {de} a {ate}', ['u' => $soUnidade, 'de' => date('d-m-Y', strtotime($seg)), 'ate' => date('d-m-Y', strtotime(escMaisDias($seg, 6)))])
        : t('Escala das páginas {de} a {ate}', ['de' => date('d-m-Y', strtotime($seg)), 'ate' => date('d-m-Y', strtotime(escMaisDias($seg, 6)))]));
    ?>
<div class="pag-pdf">
  <div class="acoes nao-imprimir">
    <a class="botao" href="<?php echo h(link_tela('grade', ['plano' => $planoId])); ?>">← <?php echo h(t('Voltar para a grade')); ?></a>
    <button type="button" class="botao primario" id="pag-pdf-baixar"><?php echo h(t('Baixar PDF')); ?></button>
    <span class="muted small"><?php echo h(tn(count($folhas), '{n} página, uma por folha (A4 paisagem). Na impressão, escolha "Salvar como PDF".', '{n} páginas, uma por folha (A4 paisagem). Na impressão, escolha "Salvar como PDF".')); ?></span>
  </div>
  <?php $uAtual = null; foreach ($folhas as $pid => $pg): $u = $unidadePdf($pid); ?>
  <?php if ($u !== $uAtual): $uAtual = $u; $listaU = $porUnidadePdf[$u]; $somaU = 0.0; $modelosU = []; foreach ($listaU as $x) { $somaU += (float) ($recPdf[$folhas[$x]['nome']]['total'] ?? 0); $modelosU[$modeloPdf[$folhas[$x]['nome']] ?? $folhas[$x]['nome']] = true; } ?>
  <section class="pag-pdf-folha pag-pdf-capa">
    <h1><?php echo h($u === 9 ? t('Sem unidade') : t('Unidade {u}', ['u' => $u])); ?></h1>
    <p class="pag-pdf-sub"><?php echo h(t('Semana de {de} a {ate} · {n} páginas de {m} modelos · faturamento {d} dias {usd} · as folhas seguem esta ordem (da modelo que mais fatura para a que menos)', ['de' => $dePdf, 'ate' => $atePdf, 'n' => count($listaU), 'm' => count($modelosU), 'd' => $diasPdf, 'usd' => $usdPdf($somaU)])); ?></p>
    <table class="pag-pdf-tabela pag-pdf-indice">
      <thead><tr><th class="pag-pdf-num">#</th><th class="pag-pdf-num"><?php echo h(t('Fila geral')); ?></th><th><?php echo h(t('Página')); ?></th><th><?php echo h(t('Carteira')); ?></th><th><?php echo h(t('Modelo')); ?></th><th class="pag-pdf-num"><?php echo h(t('Página {d}d', ['d' => $diasPdf])); ?></th><th class="pag-pdf-num"><?php echo h(t('Modelo {d}d', ['d' => $diasPdf])); ?></th></tr></thead>
      <tbody>
      <?php foreach ($listaU as $i => $x): $nx = $folhas[$x]['nome']; $mx = $modeloPdf[$nx] ?? $nx; ?>
        <tr><td class="pag-pdf-num"><?php echo $i + 1; ?></td><td class="pag-pdf-num"><?php echo $posGeral[$x] + 1; ?></td><td><strong><?php echo h($nx); ?></strong></td><td><?php echo h(implode(' · ', array_keys($cartPdf[$x] ?? [])) ?: '—'); ?></td><td><?php echo h($mx); ?></td><td class="pag-pdf-num"><?php echo h($usdPdf((float) ($recPdf[$nx]['total'] ?? 0))); ?></td><td class="pag-pdf-num"><?php echo h($usdPdf((float) ($recModeloPdf[$mx] ?? 0))); ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
  <?php endif; ?>
  <section class="pag-pdf-folha">
    <h1><?php echo h($pg['nome']); ?> <span class="pag-pdf-unidade">U<?php echo $u; ?></span></h1>
    <p class="pag-pdf-sub"><?php echo h(t('Schedule · semana de {de} a {ate} · {carteira} · versão {versao} · gerado em {gerado}', ['de' => $dePdf, 'ate' => $atePdf,
        'carteira' => implode(' · ', array_keys($cartPdf[$pid] ?? [])) ?: '—', 'versao' => $versaoPdf, 'gerado' => date('d/m/Y H:i')])); ?></p>
    <p class="pag-pdf-sub"><?php $mPdf = $modeloPdf[$pg['nome']] ?? $pg['nome']; echo h(t('Faturamento {d} dias: {pag} · modelo {modelo}: {mod} · {pu}ª da U{u} · {pg}ª da fila geral', ['d' => $diasPdf, 'pag' => $usdPdf((float) ($recPdf[$pg['nome']]['total'] ?? 0)), 'modelo' => $mPdf,
        'mod' => $usdPdf((float) ($recModeloPdf[$mPdf] ?? 0)), 'pu' => array_search($pid, $porUnidadePdf[$u], true) + 1, 'u' => $u, 'pg' => $posGeral[$pid] + 1])); ?></p>
    <table class="pag-pdf-tabela">
      <thead><tr><th class="pag-pdf-bloco"><?php echo h(t('Bloco')); ?></th><?php foreach ($dias as $d): ?><th><?php echo h(dataBr($d, true)); ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($blocosPdf as $hora => [$bl, $faixa]): ?>
        <tr>
          <th class="pag-pdf-bloco"><?php echo h($bl); ?><span><?php echo h($faixa); ?></span></th>
          <?php foreach ($dias as $d): $quem = $pg['cel'][$d . 'T' . $hora . ':00:00'] ?? []; ?>
          <td><?php if (!$quem): ?><span class="pag-pdf-vazio">—</span><?php endif; ?>
            <?php foreach ($quem as $rep => $marca): ?>
            <div class="pag-pdf-pessoa"><strong><?php echo h(nomeCurto($nomesPdf[$rep] ?? $rep)); ?></strong> <span class="mono"><?php echo h($rep); ?></span><?php echo $vendaTxt((string) $rep); ?><?php echo is_string($marca) ? ' <span class="pag-pdf-marca">' . h($marca) . '</span>' : ($marca ? ' <span class="pag-pdf-marca">#</span>' : ''); ?><?php echo !empty($pg['acima'][$d . 'T' . $hora . ':00:00'][$rep]) ? ' <span class="pag-pdf-marca">⚠</span>' : ''; ?></div>
            <?php endforeach; ?>
          </td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!empty($repsPag[$pid])): ?>
    <table class="pag-pdf-tabela pag-pdf-reps">
      <thead><tr>
        <th class="pag-pdf-membro"><?php echo h(t('Representante')); ?></th>
        <th class="pag-pdf-num"><?php echo h(t('Tempo de casa')); ?></th>
        <?php foreach ($janelasPdf as $j): ?><th class="pag-pdf-num"><?php echo h(t('Dias na página {d}d', ['d' => $j])); ?></th><th class="pag-pdf-num"><?php echo h(t('Meta {d}d', ['d' => $j])); ?></th><?php endforeach; ?>
      </tr></thead>
      <tbody>
      <?php foreach (array_keys($repsPag[$pid]) as $rep): ?>
        <tr>
          <td><div class="pag-pdf-pessoa"><strong><?php echo h(nomeCurto($nomesPdf[$rep] ?? $rep)); ?></strong> <span class="mono"><?php echo h($rep); ?></span></div></td>
          <td class="pag-pdf-num"><?php echo h($casaTxt($casaPdf[$rep] ?? null)); ?></td>
          <?php foreach ($janelasPdf as $j): ?><td class="pag-pdf-num"><?php echo (int) ($diasPag[$pid][$rep][$j] ?? 0); ?></td><td class="pag-pdf-num"><?php echo $metaTxt($metaPag[$pid][$rep][$j] ?? null); ?></td><?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
    <p class="pag-pdf-legenda"><?php echo h(t('# assistente (o primeiro da página no bloco entra sem #; os demais, que ajudam no capacity, com #) · ⚠ cobrindo acima da capacidade (até 30%, só páginas da mesma agência; cede quando houver gente) · -2 buffer de plantão · -4 trainee · -5 Overlap · -6 OA · -7 OC · -8 OS. Turno A = T2 e T3 · C = T4 e T5 · D = T6 e o T1 do dia seguinte.') . ' ' . t('Ao lado do código: venda por hora do SR nos últimos {d} dias (US$ vendido ÷ horas no chat), o número que o motor usa para pôr os melhores SRs nas páginas grandes; verde = 115% da mediana ou mais, laranja = menos de 85%, sem cor = na média.', ['d' => $diasPdf])
        . ($medPdf ? ' ' . t('Mediana: US$ {v}/h.', ['v' => number_format($medPdf, 0, ',', '.')]) : '')
        . ' ' . t('Representantes: tempo de casa pela data mais antiga do cadastro (headcount); dias escalados na página no 5C Schedule e atingimento da meta 5CReps na página (na parte de cada um, pela fatia das mensagens) nos 30, 60 e 90 dias antes da semana. Verde: meta batida. — : sem mensagens na página no período.')); ?></p>
  </section>
  <?php endforeach; ?>
  <?php if (!$folhas): ?><p class="muted"><?php echo h(t('Página sem alocação neste plano.')); ?></p><?php endif; ?>
</div>
<script>
(function () {
  // PDF pela impressão do navegador; o título vira o nome do arquivo sugerido ao salvar.
  var titulo = document.title, nome = <?php echo json_encode($arquivoPdf); ?>;
  function imprimir() { document.title = nome; window.print(); }
  document.getElementById('pag-pdf-baixar').addEventListener('click', imprimir);
  window.addEventListener('afterprint', function () { document.title = titulo; });
  <?php if (!empty($_GET['imprimir'])): ?>window.addEventListener('load', function () { setTimeout(imprimir, 300); });<?php endif; ?>
})();
</script>
    <?php
    return;
}

// ---------- marcar apagado / postado (5c_escalas.esc_plano_postagem, ES_23) ----------
if (escPost()) {
    escExigir('decidir');
    $pid = (int) ($_POST['pagina'] ?? 0);
    $passo = (string) ($_POST['passo'] ?? '');
    if (!isset($porPagina[$pid]) || !in_array($passo, ['apagado', 'postado', 'desfazer'], true)) {
        throw new RuntimeException(t('Página fora deste plano.'));
    }
    if ($plano['status'] !== 'PUBLICADO') {
        throw new RuntimeException(t('Publique esta versão antes de marcar a postagem.'));
    }
    $csv = escCsvT1T6($porPagina[$pid]['cel'], $seg, escPostagemCorte($seg));
    escW('INSERT INTO ' . escT('esc_plano_postagem') . ' (plano_id, page_id, pagina, semana, csv, csv_hash) VALUES (?, ?, ?, ?, ?, ?) AS n
          ON DUPLICATE KEY UPDATE csv = n.csv, csv_hash = n.csv_hash', [$planoId, $pid, $porPagina[$pid]['nome'], $seg, $csv, hash('sha256', $csv)]);
    if ($passo === 'apagado') {
        escW('UPDATE ' . escT('esc_plano_postagem') . ' SET apagado_em = NOW(), apagado_por = ? WHERE plano_id = ? AND page_id = ?', [escUserId(), $planoId, $pid]);
    } elseif ($passo === 'postado') {
        escW('UPDATE ' . escT('esc_plano_postagem') . ' SET postado_em = NOW(), postado_por = ? WHERE plano_id = ? AND page_id = ?', [escUserId(), $planoId, $pid]);
    } else {
        escW('UPDATE ' . escT('esc_plano_postagem') . ' SET apagado_em = NULL, apagado_por = NULL, postado_em = NULL, postado_por = NULL WHERE plano_id = ? AND page_id = ?', [$planoId, $pid]);
    }
    escLog('grade.' . $passo, 'esc_plano_postagem', $planoId . ':' . $pid);
    $vp = ['pagina' => $porPagina[$pid]['nome']];
    escVoltar('grade', ['plano' => $planoId, 'turno' => $_GET['turno'] ?? ''], $passo === 'desfazer' ? t('{pagina}: marcação desfeita.', $vp)
        : ($passo === 'apagado' ? t('{pagina}: marcado como apagado.', $vp) : t('{pagina}: marcado como postado.', $vp)), false, 'postar-' . $pid);
}

$aba = is_string($_GET['aba'] ?? null) ? $_GET['aba'] : 'pagina';
$turnoSel = is_string($_GET['turno'] ?? null) ? $_GET['turno'] : 'C';
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
// Nome curto que se repete entre os SRs do plano (ex.: dois "Gustavo Pereira") sai com o código.
$curtos = [];
foreach (array_unique(array_filter(array_column($aloc, 'rep_code'))) as $c) {
    $curtos[nomeCurto($nomes[$c] ?? $c)][$c] = true;
}
$nomeCelula = static function (string $c) use ($nomes, $curtos): string {
    $curto = nomeCurto($nomes[$c] ?? $c);
    return count($curtos[$curto] ?? []) > 1 ? $curto . ' · ' . $c : $curto;
};

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
// Estrutura nova: a linha mostra a carteira da página (dos postos da semana), não a party.
$ehV4 = ($plano['estrutura'] ?? 'V3') === 'V4';
$carteiraDe = [];
if ($ehV4) {
    $fimSemana = escMaisDias($seg, 6);
    foreach (escQ('SELECT DISTINCT pp.page_id, p.carteira FROM ' . escT('esc_posto_pagina') . ' pp JOIN ' . escT('esc_posto') . ' p ON p.id = pp.posto_id
                    WHERE p.vigente_desde <= ? AND (p.vigente_ate IS NULL OR p.vigente_ate >= ?) ORDER BY p.carteira', [$fimSemana, $fimSemana]) as $l) {
        $carteiraDe[(int) $l['page_id']][] = $l['carteira'];
    }
}
$pagOverlap = $ehV4 ? escV4Param('v4_overlap_pagina', '5C Schedule') : '';
$listaPaginas = [];
foreach ($paginasPlano as $pid => $nome) {
    $time = $porId[$pid]['time'] ?? '';
    if (!$ehV4 && $timeSel !== '' && $time !== $timeSel) {
        continue;
    }
    $rotulo = $ehV4
        ? ($nome === $pagOverlap ? t('buffer de plantão (-2) · Overlap (-5)') : (implode(' · ', array_unique($carteiraDe[$pid] ?? [])) ?: t('fora dos postos')))
        : ($porId[$pid]['party'] ?? '');
    $listaPaginas[] = ['id' => $pid, 'nome' => $nome, 'time' => $ehV4 ? '' : $time, 'party' => $rotulo];
}
usort($listaPaginas, static fn($a, $b) => [$a['time'], $a['party'], $a['nome']] <=> [$b['time'], $b['party'], $b['nome']]);

$sel = null;
if (isset($_GET['pg'], $_GET['dia'])) {
    $sel = ['page_id' => (int) $_GET['pg'], 'dia' => (string) $_GET['dia'], 'linhas' => $grade[(int) $_GET['pg']][(string) $_GET['dia']] ?? []];
}
$params = ['plano' => $planoId, 'turno' => $turnoSel, 'time' => $timeSel];
$ehRascunho = $plano['status'] === 'RASCUNHO';
// Seletor de semana: as semanas com escala (da mais nova para a mais antiga), com a versão atual
// e, se for outra, a publicada.
$semanasGrade = [];
foreach (escQ("SELECT semana_inicio AS s, id, versao, subversao, status FROM " . escT('esc_plano') . " WHERE time_nome = '' AND status IN ('RASCUNHO','PUBLICADO')
                ORDER BY semana_inicio DESC, versao DESC, subversao DESC") as $l) {
    $s = substr((string) $l['s'], 0, 10);
    if (!isset($semanasGrade[$s])) {
        $semanasGrade[$s] = ['atual' => $l, 'publicado' => null];
    }
    if ($l['status'] === 'PUBLICADO' && $semanasGrade[$s]['publicado'] === null) {
        $semanasGrade[$s]['publicado'] = $l;
    }
}
$semanasLista = array_keys($semanasGrade);
$posSemana = array_search($seg, $semanasLista, true);
$semanaAnt = $posSemana !== false && isset($semanasLista[$posSemana + 1]) ? $semanasGrade[$semanasLista[$posSemana + 1]]['atual'] : null;
$semanaProx = $posSemana !== false && $posSemana > 0 ? $semanasGrade[$semanasLista[$posSemana - 1]]['atual'] : null;
?>
<header class="cabecalho">
  <span class="eyebrow"><?php echo h(t('Escala')); ?></span>
  <h1><?php echo h(t('Grade da escala')); ?> · <?php echo h(escPlanoRotulo($plano)); ?></h1>
  <p class="muted"><?php echo h(t('Semana de {de} a {ate}', ['de' => dataBr($seg), 'ate' => dataBr(escMaisDias($seg, 6))])); ?> · <?php echo escTagStatusPlano($plano['status']); ?> · <?php echo h(t('Capacity de {dia}', ['dia' => dataBr($plano['capacity_calculo'])])); ?></p>
  <form method="get" action="<?php echo h(MODULE_URL); ?>/index.php" class="acoes grade-semana nao-imprimir">
    <input type="hidden" name="p" value="grade">
    <?php if ($semanaAnt): ?><a class="botao" href="<?php echo h(link_tela('grade', ['plano' => (int) $semanaAnt['id']])); ?>" aria-label="<?php echo h(t('Semana anterior')); ?>">‹</a><?php endif; ?>
    <select name="plano" onchange="this.form.submit()" aria-label="<?php echo h(t('Semana')); ?>" data-sem-busca>
      <?php foreach ($semanasGrade as $s => $x): ?>
        <option value="<?php echo (int) $x['atual']['id']; ?>" <?php echo (int) $x['atual']['id'] === $planoId ? 'selected' : ''; ?>><?php echo h(t('Semana de {de} a {ate}', ['de' => dataBr($s), 'ate' => dataBr(escMaisDias($s, 6))]) . ' · ' . escPlanoRotulo($x['atual']) . ' ' . ($x['atual']['status'] === 'PUBLICADO' ? t('publicada') : t('rascunho'))); ?></option>
        <?php if ($x['publicado'] && (int) $x['publicado']['id'] !== (int) $x['atual']['id']): ?>
        <option value="<?php echo (int) $x['publicado']['id']; ?>" <?php echo (int) $x['publicado']['id'] === $planoId ? 'selected' : ''; ?>>&nbsp;&nbsp;↳ <?php echo h(escPlanoRotulo($x['publicado']) . ' ' . t('publicada')); ?></option>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if ($posSemana === false || !in_array($planoId, array_map(static fn($x) => (int) $x['atual']['id'], $semanasGrade), true) && !in_array($planoId, array_map(static fn($x) => (int) ($x['publicado']['id'] ?? 0), $semanasGrade), true)): ?>
        <option value="<?php echo $planoId; ?>" selected><?php echo h(t('Semana de {de} a {ate}', ['de' => dataBr($seg), 'ate' => dataBr(escMaisDias($seg, 6))]) . ' · ' . escPlanoRotulo($plano) . ' (' . t('versão antiga') . ')'); ?></option>
      <?php endif; ?>
    </select>
    <?php if ($semanaProx): ?><a class="botao" href="<?php echo h(link_tela('grade', ['plano' => (int) $semanaProx['id']])); ?>" aria-label="<?php echo h(t('Próxima semana')); ?>">›</a><?php endif; ?>
    <noscript><button type="submit" class="botao"><?php echo h(t('Abrir')); ?></button></noscript>
  </form>
</header>
<?php if ($plano['status'] === 'SUBSTITUIDO'): $atualSemana = escPlanoAtual($seg); ?>
<div class="faixa-legado" role="status"><?php echo h(t('Esta versão foi substituída e fica só para consulta.')); ?><?php if ($atualSemana && (int) $atualSemana['id'] !== $planoId): ?> <?php echo t('A versão atual da semana é a {versao} · {link}.', ['versao' => '<strong>' . h(escPlanoRotulo($atualSemana)) . '</strong>', 'link' => '<a href="' . h(link_tela('grade', ['plano' => (int) $atualSemana['id']])) . '">' . h(t('abrir a versão atual')) . '</a>']); ?><?php endif; ?></div>
<?php endif; ?>

<section class="kpis">
  <div class="kpi"><span class="muted"><?php echo h(t('Blocos alocados')); ?></span><strong><?php echo (int) ($resumo['blocos'] ?? 0); ?></strong><span class="muted small"><?php echo h(t('{n} pessoas configuradas', ['n' => (int) ($resumo['pessoas'] ?? 0)])); ?></span></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Lacunas')); ?></span><strong class="<?php echo !empty($resumo['lacunas']) ? 'laranja' : ''; ?>"><?php echo (int) ($resumo['lacunas'] ?? 0); ?></strong><span class="muted small"><?php echo h(t('blocos sem ninguém')); ?></span></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Anomalias programadas')); ?></span><strong class="<?php echo !empty($resumo['anomalias']) ? 'vermelho' : ''; ?>"><?php echo (int) ($resumo['anomalias'] ?? 0); ?></strong><span class="muted small"><?php echo h(t('dias seguidos acima do modelo')); ?></span></div>
  <?php if (($resumo['estrutura'] ?? '') === 'V4'): ?>
  <div class="kpi"><span class="muted"><?php echo h(t('Buffers de plantão')); ?></span><strong><?php echo (int) ($resumo['buffers_ociosos'] ?? 0); ?></strong><span class="muted small"><?php echo h(t('pessoa-turnos sem posto (cobrem faltas)')); ?></span></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Acima da capacidade')); ?></span><strong class="<?php echo !empty($resumo['sobrecargas']) ? 'laranja' : ''; ?>"><?php echo (int) ($resumo['sobrecargas'] ?? 0); ?></strong><span class="muted small"><?php echo h(t('página-dias cobertos por um SR até {pct}% acima, mesma agência (⚠ na grade)', ['pct' => (int) round((float) escV4Param('v4_over_capacity_max', '0.30') * 100)])); ?></span></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Postos sem ninguém')); ?></span><strong class="<?php echo !empty($resumo['vagas_sem_ninguem']) ? 'laranja' : ''; ?>"><?php echo (int) ($resumo['vagas_sem_ninguem'] ?? 0); ?></strong><span class="muted small"><?php echo h(t('de {n} posto-dias', ['n' => (int) ($resumo['vagas_dia'] ?? 0)])); ?><?php
    $porUT = [];
    foreach ($resumo['vagas_sem_ninguem_por_unidade_turno'] ?? [] as $ut => $n) {
        $porUT[] = 'U' . $ut[0] . ' ' . $ut[1] . ': ' . (int) $n;
    }
    echo $porUT ? ' · ' . h(implode(' · ', $porUT)) : ''; ?></span></div>
  <?php else: ?>
  <div class="kpi"><span class="muted"><?php echo h(t('Buffers sem página')); ?></span><strong><?php echo (int) ($resumo['buffers_ociosos'] ?? 0); ?></strong><span class="muted small"><?php echo h(t('turnos sem necessidade')); ?></span></div>
  <?php endif; ?>
</section>

<section class="panel">
  <div class="linha-titulo">
    <div class="chips">
      <?php foreach (['pagina' => t('Por página'), 'pessoa' => t('Por pessoa'), 'depara' => t('De→Para')] as $k => $rot): ?>
        <a class="chip-filtro <?php echo $aba === $k ? 'ativo' : ''; ?>" href="<?php echo h(link_tela('grade', $params + ['aba' => $k])); ?>"><?php echo h($rot); ?></a>
      <?php endforeach; ?>
    </div>
    <form method="get" action="<?php echo h(MODULE_URL); ?>/index.php" class="acoes">
      <input type="hidden" name="p" value="grade"><input type="hidden" name="plano" value="<?php echo $planoId; ?>"><input type="hidden" name="aba" value="<?php echo h($aba); ?>">
      <select name="turno" onchange="this.form.submit()" aria-label="<?php echo h(t('Turno')); ?>">
        <?php foreach (['A' => t('Turno {turno} ({horas})', ['turno' => 'A', 'horas' => '05–13']), 'C' => t('Turno {turno} ({horas})', ['turno' => 'C', 'horas' => '13–21']), 'D' => t('Turno {turno} ({horas})', ['turno' => 'D', 'horas' => '21–05'])] as $t => $rot): ?><option value="<?php echo $t; ?>" <?php echo $t === $turnoSel ? 'selected' : ''; ?>><?php echo h($rot); ?></option><?php endforeach; ?>
      </select>
      <?php if (!$ehV4): ?>
      <select name="time" onchange="this.form.submit()" aria-label="<?php echo h(t('Time')); ?>">
        <option value=""><?php echo h(t('Todos os times')); ?></option>
        <?php foreach ($times as $t): ?><option <?php echo $t === $timeSel ? 'selected' : ''; ?>><?php echo h($t); ?></option><?php endforeach; ?>
      </select>
      <?php endif; ?>
    </form>
  </div>

  <div class="acoes">
    <a class="botao" href="<?php echo h(link_tela('grade', ['plano' => $planoId, 'baixar' => 'zip'])); ?>"><?php echo h(t('Baixar CSVs (.zip)')); ?></a>
    <?php foreach ([1, 2, 3] as $uPdf): ?>
    <a class="botao" href="<?php echo h(link_tela('grade', ['plano' => $planoId, 'ver' => 'pdf', 'unidade' => $uPdf, 'imprimir' => 1])); ?>" target="_blank" rel="noopener" title="<?php echo h(t('Só as páginas da U{u}, em ordem de faturamento: uma folha de resumo e uma folha por página (A4 paisagem, papel timbrado), com quem está em cada bloco T1 a T6 dos 7 dias e a venda por hora de cada SR.', ['u' => $uPdf])); ?>"><?php echo h(t('PDF U{u}', ['u' => $uPdf])); ?></a>
    <?php endforeach; ?>
    <a class="botao" href="<?php echo h(link_tela('grade', ['plano' => $planoId, 'ver' => 'metas', 'imprimir' => 1])); ?>" target="_blank" rel="noopener" title="<?php echo h(t('Uma folha por página (A4 paisagem, papel timbrado), com cada pessoa escalada na semana e o atingimento da meta dela na página nas 4 semanas anteriores.')); ?>"><?php echo h(t('Baixar PDF das metas')); ?></a>
    <?php if (escPode('planejar') && $ehRascunho): ?>
    <?php
    $resumoPublicar = t('Publicar a {versao} (semana de {dia})?', ['versao' => escPlanoRotulo($plano), 'dia' => dataBr($seg)])
        . "

" . tn((int) ($resumo['lacunas'] ?? 0), '{n} lacuna', '{n} lacunas') . ' · ' . tn((int) ($resumo['anomalias'] ?? 0), '{n} anomalia', '{n} anomalias')
        . (($resumo['estrutura'] ?? '') === 'V4' ? ' · ' . tn((int) ($resumo['vagas_sem_ninguem'] ?? 0), '{n} posto-dia sem ninguém', '{n} posto-dias sem ninguém') . ' · ' . t('{n} acima da capacidade', ['n' => (int) ($resumo['sobrecargas'] ?? 0)]) : '')
        . "

" . t('A versão publicada antes passa a substituída. Depois, poste os CSVs no site da 5C.');
    ?>
    <form method="post" action="<?php echo h(link_tela('grade', $params)); ?>" class="acoes" data-confirmar="<?php echo h($resumoPublicar); ?>" data-longo="<?php echo h(t('Publicando…')); ?>">
      <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="publicar">
      <?php if (!empty($resumo['anomalias'])): ?><label class="small"><input type="checkbox" name="ciente" value="1" required> <?php echo h(tn((int) $resumo['anomalias'], 'estou ciente da {n} anomalia', 'estou ciente das {n} anomalias')); ?></label><?php endif; ?>
      <button type="submit" class="botao primario"><?php echo h(t('Publicar esta versão')); ?></button>
    </form>
    <?php elseif (escPode('planejar') && $plano['status'] === 'PUBLICADO'): ?>
    <form method="post" action="<?php echo h(link_tela('grade', $params)); ?>" data-longo="<?php echo h(t('Criando rascunho… pode levar até 3 min.')); ?>">
      <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="rascunho">
      <button type="submit" class="botao"><?php echo h(t('Criar rascunho a partir desta')); ?></button>
    </form>
    <?php endif; ?>
  </div>

<?php if ($aba === 'pagina'): ?>
  <div class="tabela-rolagem grade-rolagem">
  <table class="grade">
    <thead><tr><th class="col-pagina"><?php echo h(t('Página')); ?></th><?php foreach ($dias as $d): ?><th><?php echo h(dataBr($d, true)); ?></th><?php endforeach; ?><th></th></tr></thead>
    <tbody>
    <?php foreach ($listaPaginas as $pg): ?>
      <tr>
        <td class="col-pagina"><strong><?php echo h($pg['nome']); ?></strong><br><span class="muted small"><?php echo h($pg['party'] ?: t('sem party')); ?></span></td>
        <?php foreach ($dias as $d): $cel = $grade[$pg['id']][$d] ?? []; $lac = $cel && !array_filter($cel, static fn($x) => $x['rep_code'] !== null || $x['origem'] === 'TREINO'); ?>
          <td class="celula <?php echo $lac ? 'lacuna' : ''; ?> <?php echo array_filter($cel, static fn($x) => (int) $x['travada'] === 1) ? 'travada' : ''; ?>">
            <a href="<?php echo h(link_tela('grade', $params + ['pg' => $pg['id'], 'dia' => $d])); ?>" data-manter-rolagem>
            <?php if (!$cel): ?><span class="muted">—</span><?php endif; ?>
            <?php foreach ($cel as $x): ?>
              <?php if ($x['rep_code'] === null && $x['origem'] === 'TREINO'): ?><span class="origem-treino" title="<?php echo h($x['motivo']); ?>"><?php echo h(t('treinamento')); ?></span><br>
              <?php elseif ($x['rep_code'] === null): ?><span class="laranja"><?php echo h(t('lacuna')); ?></span><br>
              <?php else: ?><span class="pessoa-cel origem-<?php echo h(strtolower($x['origem'])); ?>" title="<?php echo h(($nomes[$x['rep_code']] ?? '') . ' · ' . $x['motivo']); ?>"><?php echo h($nomeCelula($x['rep_code'])); ?><?php $mc = escMarcaCsv($x); echo is_string($mc) ? ' ' . $mc : ($mc ? ' #' : ''); ?><?php echo $x['origem'] === 'SOBRECARGA' ? ' ⚠' : ''; ?></span><br><?php endif; ?>
            <?php endforeach; ?>
            </a>
          </td>
        <?php endforeach; ?>
        <td class="grade-baixar"><a class="botao" href="<?php echo h(link_tela('grade', ['plano' => $planoId, 'baixar' => 'csv', 'pagina' => $pg['id']])); ?>">CSV</a> <a class="botao" href="<?php echo h(link_tela('grade', ['plano' => $planoId, 'ver' => 'pdf', 'pagina' => $pg['id'], 'imprimir' => 1])); ?>" target="_blank" rel="noopener" aria-label="<?php echo h(t('PDF da escala de {pagina}', ['pagina' => $pg['nome']])); ?>">PDF</a> <a class="botao" href="<?php echo h(link_tela('grade', ['plano' => $planoId, 'ver' => 'metas', 'pagina' => $pg['id'], 'imprimir' => 1])); ?>" target="_blank" rel="noopener" aria-label="<?php echo h(t('PDF de metas de {pagina}', ['pagina' => $pg['nome']])); ?>"><?php echo h(t('Metas')); ?></a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$listaPaginas): ?><tr><td colspan="9" class="muted"><?php echo h(t('Nenhuma página neste filtro.')); ?></td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <div class="legenda small">
    <span class="muted"><?php echo h(t('Bloco {bloco} do turno {turno}', ['bloco' => $blocoRef, 'turno' => $turnoSel])); ?> ·</span>
    <?php foreach ($ehV4
        ? ['vaga_dono' => t('vaga do dono'), 'vaga_cobertura' => t('cobertura'), 'buffer_nucleo' => t('buffer do núcleo'), 'transicao' => t('transição'), 'buffer' => t('buffer de plantão (-2)'), 'overlap' => 'Overlap (-5)', 'lider_os' => t('liderança (OA -6, OC -7, OS -8)'), 'treino' => t('trainee New Hire (-4)'), 'sobrecarga' => t('acima da capacidade ⚠'), 'manual' => t('ajuste manual')]
        : ['party_dona' => t('party dona'), 'outra_party' => t('outra party'), 'tertius' => t('rodízio'), 'buffer' => t('Buffer'), 'manual' => t('ajuste manual')] as $cls => $rot): ?>
      <span class="pessoa-cel origem-<?php echo $cls; ?> amostra"><?php echo h($rot); ?></span>
    <?php endforeach; ?>
    <span class="muted">· <?php echo h(t('"#" = assistente · clique na célula para ver o porquê e ajustar')); ?></span>
  </div>

  <?php if ($sel): $pgSel = $porId[$sel['page_id']] ?? ['nome' => $paginasPlano[$sel['page_id']] ?? '', 'party' => '']; ?>
  <div class="modal-fundo" role="dialog" aria-modal="true" aria-label="<?php echo h(t('Ajustar célula')); ?>">
  <div class="modal">
    <div class="linha-titulo"><strong><?php echo h($pgSel['nome']); ?> · <?php echo h(dataBr($sel['dia'], true)); ?> · <?php echo h(t('turno {turno}', ['turno' => $turnoSel])); ?></strong><a class="botao" href="<?php echo h(link_tela('grade', $params)); ?>" data-manter-rolagem data-fechar-modal><?php echo h(t('Fechar')); ?></a></div>
    <ul class="regras small">
      <?php foreach ($sel['linhas'] as $x): ?>
        <li><?php echo $x['rep_code'] ? h(($nomes[$x['rep_code']] ?? '') . ' (' . $x['rep_code'] . ')') : ($x['origem'] === 'TREINO' ? '<span class="origem-treino">' . h(t('treinamento')) . '</span>' : '<span class="laranja">' . h(t('lacuna')) . '</span>'); ?> · <?php echo h(escRotulo('papel', $x['papel'])); ?> · <?php echo h($x['motivo']); ?><?php echo (int) $x['travada'] ? ' · <strong>' . h(t('travado')) . '</strong>' : ''; ?></li>
      <?php endforeach; ?>
      <?php if (!$sel['linhas']): ?><li class="muted"><?php echo h(t('A página não tem necessidade neste turno.')); ?></li><?php endif; ?>
    </ul>
    <?php if (escPode('planejar') && $ehRascunho):
        $reg = ''; $ass = [];
        foreach ($sel['linhas'] as $x) { if ($x['rep_code'] && $x['papel'] === 'REGULAR' && $reg === '') { $reg = $x['rep_code']; } elseif ($x['rep_code']) { $ass[] = $x['rep_code']; } }
        $rotulo = static fn(string $c): string => $c === '' ? '' : (($nomes[$c] ?? $c) . ' (' . $c . ')');
        // Busca: SRs do turno primeiro (estrutura nova), depois todo mundo do headcount.
        $srsTurno = [];
        foreach (escQ('SELECT UPPER(rep_code) AS c, unidade, nivel FROM ' . escT('esc_colaborador_config') . " WHERE funcao = 'EQUITE' AND turno = ? AND vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)", [$turnoSel, $sel['dia'], $sel['dia']]) as $l) {
            $srsTurno[$l['c']] = 'U' . $l['unidade'] . ' · SR ' . $l['nivel'];
        }
    ?>
    <datalist id="lista-srs">
      <?php foreach ($srsTurno as $c => $info): ?><option value="<?php echo h($rotulo($c)); ?>"><?php echo h(t('{info} · turno {turno}', ['info' => $info, 'turno' => $turnoSel])); ?></option><?php endforeach; ?>
      <?php foreach ($nomes as $c => $n): if (isset($srsTurno[$c]) || !preg_match('~^[A-Z]\d{6}$~', (string) $c)) { continue; } ?><option value="<?php echo h($n . ' (' . $c . ')'); ?>"></option><?php endforeach; ?>
    </datalist>
    <form method="post" action="<?php echo h(link_tela('grade', $params + ['pg' => (int) $sel['page_id'], 'dia' => $sel['dia']])); ?>" class="form-grade" id="form-celula" data-manter-rolagem>
      <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="editar"><input type="hidden" name="page_id" value="<?php echo (int) $sel['page_id']; ?>"><input type="hidden" name="dia" value="<?php echo h($sel['dia']); ?>"><input type="hidden" name="turno" value="<?php echo h($turnoSel); ?>">
      <label><?php echo h(t('Regular')); ?><input type="search" name="regular" list="lista-srs" value="<?php echo h($rotulo($reg)); ?>" placeholder="<?php echo h(t('buscar nome ou código')); ?>" autocomplete="off" autofocus></label>
      <?php for ($k = 0; $k < max(4, count($ass) + 1); $k++): ?>
      <label><?php echo h(t('Assistente {n}', ['n' => $k + 1])); ?><input type="search" name="assistentes[]" list="lista-srs" value="<?php echo h($rotulo($ass[$k] ?? '')); ?>" placeholder="<?php echo h(t('buscar nome ou código')); ?>" autocomplete="off"></label>
      <?php endfor; ?>
      <label class="small"><input type="checkbox" name="mesmo_assim" value="1"> <?php echo h(t('salvar mesmo assim (ignora os alertas de folga, indisponibilidade, outro turno, 12 h e 52 h)')); ?></label>
      <button type="submit" class="botao primario"><?php echo h(t('Salvar e travar')); ?></button>
      <span class="muted small"><?php echo h(t('Vale para os dois blocos do turno. Deixe tudo vazio para marcar como lacuna. Esc fecha.')); ?></span>
    </form>
    <?php elseif (!$ehRascunho): ?>
      <p class="muted small"><?php echo h($plano['status'] === 'PUBLICADO' ? t('Versão publicada: para ajustar, use "Criar rascunho a partir desta".') : t('Versão substituída: só consulta. Abra a versão atual da semana para ajustar.')); ?></p>
    <?php endif; ?>
  </div>
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
        $mc = escMarcaCsv($a);
        $porPessoa[$a['rep_code']][substr($a['dia'], 0, 10)][] = $a['pagina'] . (is_string($mc) ? ' ' . $mc : ($mc ? ' #' : ''));
    }
    $folgas = [];
    foreach (escQ('SELECT rep_code, dia, tipo FROM ' . escT('esc_plano_folga') . ' WHERE plano_id = ?', [$planoId]) as $f) {
        $folgas[$f['rep_code']][substr($f['dia'], 0, 10)] = $f['tipo'];
    }
    $pessoas = [];
    // Estrutura nova: função (OS / SR n) e núcleo vêm do cadastro, não do Planner.
    $extraV4 = [];
    if ($ehV4) {
        $dv = max($seg, escV4DiaEstrutura());
        foreach (escQ('SELECT UPPER(c.rep_code) AS rep, c.funcao, c.nivel, n.unidade, n.turno, n.numero FROM ' . escT('esc_colaborador_config') . ' c LEFT JOIN ' . escT('esc_nucleo') . ' n ON n.id = c.nucleo_id WHERE c.vigente_desde <= ? AND (c.vigente_ate IS NULL OR c.vigente_ate >= ?)', [$dv, $dv]) as $x) {
            $extraV4[$x['rep']] = $x;
        }
    }
    foreach ($config as $rep => $c) {
        if ($c['turno'] !== $turnoSel) {
            continue;
        }
        if ($ehV4) {
            $x = $extraV4[$rep] ?? [];
            $papel = ($x['funcao'] ?? '') === 'DECURIO' ? 'OS' : (($x['funcao'] ?? '') === 'EQUITE' ? 'SR ' . ($x['nivel'] ?? '?') : t('fora da estrutura'));
            $onde = !empty($x['unidade']) ? t('U{unidade} · {turno} · núcleo {nucleo}', ['unidade' => $x['unidade'], 'turno' => $x['turno'], 'nucleo' => $x['numero']]) : '';
            $pessoas[] = ['rep' => $rep, 'nome' => $nomes[$rep] ?? $rep, 'papel' => $papel, 'party' => $onde, 'modelo' => $c['modelo']];
            continue;
        }
        $e = $estrutura[$rep] ?? null;
        if ($timeSel !== '' && ($e['team'] ?? '') !== $timeSel) {
            continue;
        }
        $pessoas[] = ['rep' => $rep, 'nome' => $nomes[$rep] ?? $rep, 'papel' => $c['tipo'] === 'BUFFER' ? t('Buffer') : ($e['role'] ?? t('fora da estrutura')), 'party' => $e['party'] ?? '', 'modelo' => $c['modelo']];
    }
    usort($pessoas, static fn($a, $b) => [$a['party'], $a['nome']] <=> [$b['party'], $b['nome']]);
?>
  <div class="tabela-rolagem">
  <table class="grade">
    <thead><tr><th><?php echo h(t('Pessoa')); ?></th><?php foreach ($dias as $d): ?><th><?php echo h(dataBr($d, true)); ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($pessoas as $ps): ?>
      <tr>
        <td><strong><?php echo h(nomeCurto($ps['nome'])); ?></strong><br><span class="muted small"><?php echo h($ps['rep'] . ' · ' . $ps['papel'] . ($ps['party'] ? ' · ' . $ps['party'] : '') . ' · ' . $ps['modelo']); ?></span></td>
        <?php foreach ($dias as $d): ?>
          <td class="celula small">
            <?php if (isset($folgas[$ps['rep']][$d])): ?><span class="muted"><?php echo h($folgas[$ps['rep']][$d] === 'INDISPONIBILIDADE' ? t('indisponível') : t('folga')); ?></span>
            <?php elseif (!empty($porPessoa[$ps['rep']][$d])): ?><?php echo h(implode(', ', array_unique($porPessoa[$ps['rep']][$d]))); ?>
            <?php else: ?><span class="laranja"><?php echo h(t('sem página')); ?></span><?php endif; ?>
          </td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$pessoas): ?><tr><td colspan="8" class="muted"><?php echo h(t('Ninguém neste turno e filtro.')); ?></td></tr><?php endif; ?>
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
    <p class="muted"><?php echo h(t('Esta é a primeira versão da semana: não há De→Para.')); ?></p>
  <?php else: ?>
  <div class="chips">
    <a class="chip-filtro <?php echo $tipoSel === '' ? 'ativo' : ''; ?>" href="<?php echo h(link_tela('grade', ['plano' => $planoId, 'aba' => 'depara'])); ?>"><?php echo h(t('Todos')); ?></a>
    <?php foreach ($contagem as $c): ?><a class="chip-filtro <?php echo $tipoSel === $c['tipo'] ? 'ativo' : ''; ?>" href="<?php echo h(link_tela('grade', ['plano' => $planoId, 'aba' => 'depara', 'tipo' => $c['tipo']])); ?>"><?php echo h(escRotulo('tipo_diferenca', $c['tipo'])); ?> · <?php echo (int) $c['n']; ?></a><?php endforeach; ?>
  </div>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th><?php echo h(t('Dia')); ?></th><th><?php echo h(t('Bloco')); ?></th><th><?php echo h(t('Página')); ?></th><th><?php echo h(t('Tipo')); ?></th><th><?php echo h(t('De')); ?></th><th><?php echo h(t('Para')); ?></th><th><?php echo h(t('Motivo')); ?></th><th><?php echo h(t('Aplicado')); ?></th></tr></thead>
    <tbody>
    <?php foreach ($difs as $d): ?>
      <tr>
        <td><?php echo h(dataBr($d['dia'], true)); ?></td><td><?php echo h($d['bloco']); ?></td><td><?php echo h($d['pagina']); ?></td>
        <td><span class="tag"><?php echo h(escRotulo('tipo_diferenca', $d['tipo'])); ?></span></td>
        <td class="small"><?php echo $d['rep_de'] ? h(nomeCurto($nomes[$d['rep_de']] ?? $d['rep_de'])) . ($d['papel_de'] === 'ASSISTENTE' ? ' #' : '') : '—'; ?></td>
        <td class="small"><?php echo $d['rep_para'] ? h(nomeCurto($nomes[$d['rep_para']] ?? $d['rep_para'])) . ($d['papel_para'] === 'ASSISTENTE' ? ' #' : '') : '—'; ?></td>
        <td class="small"><?php echo h($d['motivo']); ?></td>
        <td><?php echo (int) $d['aplicado'] ? h(t('sim')) : '<span class="laranja">' . h(t('não · virou sugestão')) . '</span>'; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$difs): ?><tr><td colspan="8" class="muted"><?php echo h(t('Nenhuma diferença.')); ?></td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
<?php endif; ?>
</section>

<?php if ($aba === 'pagina'):
    // Postagem: um cartão por página com o CSV da semana (todos os turnos), como na troca de páginas.
    $fimSem = escMaisDias($seg, 6);
    $postagens = [];
    $postadoAntes = [];
    $temControle = true;
    try {
        foreach (escQ('SELECT page_id, apagado_em, postado_em FROM ' . escT('esc_plano_postagem') . ' WHERE plano_id = ?', [$planoId]) as $pp) {
            $postagens[(int) $pp['page_id']] = $pp;
        }
        // Última versão postada desta semana: diz se o CSV mudou ou se a página pode ficar como está.
        foreach (escQ('SELECT pp.page_id, pp.csv_hash, pp.postado_em, pl.versao, pl.subversao FROM ' . escT('esc_plano_postagem') . ' pp JOIN ' . escT('esc_plano') . ' pl ON pl.id = pp.plano_id
                        WHERE pp.semana = ? AND pp.plano_id <> ? AND pp.postado_em IS NOT NULL ORDER BY pp.postado_em', [$seg, $planoId]) as $pp) {
            $postadoAntes[(int) $pp['page_id']] = $pp;
        }
    } catch (Throwable $e) {
        $temControle = false;
        appLog('warning', 'Controle de postagem da grade indisponível (rodar ES_23)', ['erro' => $e->getMessage()]);
    }
    // Semana da virada: antes dela vale a escala já postada (não apaga nem posta).
    $corte = escPostagemCorte($seg);
    $diaCorte = $corte !== '' ? substr($corte, 0, 10) : $seg;
    $blocosMao = [];
    if ($corte !== '') {
        $passou = false;
        foreach (ESC_BLOCOS as $hora => $bloco) {
            $passou = $passou || $hora === substr($corte, 11, 2);
            if ($passou && $hora !== '01') {
                $blocosMao[] = $bloco; // blocos do próprio dia, a partir da virada (o T1 é do dia seguinte)
            }
        }
    }
    // O que já está no site nesta semana (onde precisa do Delete Range), a partir da virada.
    $noSite = [];
    foreach (escQ('SELECT modeloId, MIN(SUBSTRING(shiftDateAndHour, 1, 10)) AS de, MAX(SUBSTRING(shiftDateAndHour, 1, 10)) AS ate
                     FROM `5c_schedule`.`scheduleday` WHERE shiftDateAndHour >= ? AND shiftDateAndHour < ? GROUP BY modeloId',
                  [$corte !== '' ? $corte : $seg . 'T01:00:00', escMaisDias($seg, 7) . 'T01:00:00']) as $r) {
        $noSite[(int) $r['modeloId']] = $r;
    }
    $rotuloVersao = static fn(array $p): string => 'v' . (int) $p['versao'] . ((int) $p['subversao'] > 0 ? '.' . (int) $p['subversao'] : '');
    $cartoes = [];
    foreach ($porPagina as $pid => $pg) {
        $csv = escCsvT1T6($pg['cel'], $seg, $corte);
        $pp = $postagens[$pid] ?? null;
        $ant = $postadoAntes[$pid] ?? null;
        $estado = $pp && $pp['postado_em'] ? 'postado' : ($ant && hash_equals((string) $ant['csv_hash'], hash('sha256', $csv)) ? 'igual' : 'afazer');
        $cartoes[] = ['id' => $pid, 'nome' => $pg['nome'], 'csv' => $csv, 'pp' => $pp, 'ant' => $ant, 'estado' => $estado, 'site' => $noSite[$pid] ?? null];
    }
    usort($cartoes, static fn($a, $b) => strnatcasecmp($a['nome'], $b['nome']));
    $conta = array_count_values(array_column($cartoes, 'estado')) + ['afazer' => 0, 'igual' => 0, 'postado' => 0];
    $soApagar = array_diff_key($noSite, $porPagina);
    $comSite = count(array_filter($cartoes, static fn($c) => $c['site'] !== null));
    $publicado = $plano['status'] === 'PUBLICADO';
    $podeMarcar = $temControle && $publicado && escPode('decidir');
    $dataSite = static fn(string $d): string => date('d/m/Y', strtotime($d));
?>
<section class="panel" id="postar">
  <div class="linha-titulo">
    <h2><?php echo h(t('Postar no site da 5C (Schedule Ajustment)')); ?></h2>
    <span class="muted small"><?php echo t('{n} de {total} páginas prontas', ['n' => '<strong>' . ($conta['postado'] + $conta['igual']) . '</strong>', 'total' => count($cartoes)]); ?></span>
  </div>
  <?php if (!$publicado): ?><p class="laranja small"><?php echo h($plano['status'] === 'RASCUNHO' ? t('Rascunho: os CSVs abaixo servem para conferir. Publique antes de postar.') : t('Versão substituída: os CSVs abaixo servem para conferir. Publique antes de postar.')); ?></p><?php endif; ?>
  <?php if (!$temControle): ?><p class="laranja small"><?php echo h(t('Falta rodar o ES_23 para marcar "Apaguei" e "Postei".')); ?></p><?php endif; ?>
  <ol class="roteiro">
    <?php if ($comSite || $soApagar): ?>
    <?php if ($corte !== ''): ?>
    <li><?php echo t('Semana da virada ({virada}): antes disso vale a escala já postada no site; não apague nem poste nada antes da virada.', ['virada' => '<strong>' . h(dataBr($diaCorte) . ' ' . substr($corte, 11, 2) . ':00') . '</strong>']); ?></li>
    <li><?php echo $diaCorte < $fimSem
        ? t('{acao} de {de} a {ate} e, {aMao}, em {dia} só os blocos {blocos}, nas {n} páginas que já têm escala depois da virada, antes de postar qualquer uma.', ['acao' => '<strong>Delete Range</strong>', 'de' => h(dataBr(escMaisDias($diaCorte, 1))), 'ate' => h(dataBr($fimSem)), 'aMao' => '<strong>' . h(t('à mão')) . '</strong>', 'dia' => h(dataBr($diaCorte, true)), 'blocos' => h(implode(', ', $blocosMao)), 'n' => $comSite + count($soApagar)])
        : t('Apague {aMao}, em {dia}, só os blocos {blocos}, nas {n} páginas que já têm escala depois da virada, antes de postar qualquer uma.', ['aMao' => '<strong>' . h(t('à mão')) . '</strong>', 'dia' => h(dataBr($diaCorte, true)), 'blocos' => h(implode(', ', $blocosMao)), 'n' => $comSite + count($soApagar)]); ?><?php if ($soApagar): ?> <?php echo h(tn(count($soApagar), 'Inclui {n} página fora deste plano: só apagar.', 'Inclui {n} páginas fora deste plano: só apagar.')); ?><?php endif; ?></li>
    <?php else: ?>
    <li><?php echo t('{acao} de {de} a {ate} nas {n} páginas que já têm escala na semana (marcadas "no site"), antes de postar qualquer uma.', ['acao' => '<strong>Delete Range</strong>', 'de' => h(dataBr($seg)), 'ate' => h(dataBr($fimSem)), 'n' => $comSite + count($soApagar)]); ?><?php if ($soApagar): ?> <?php echo h(tn(count($soApagar), 'Inclui {n} página fora deste plano: só apagar.', 'Inclui {n} páginas fora deste plano: só apagar.')); ?><?php endif; ?></li>
    <?php endif; ?>
    <?php else: ?>
    <li><?php echo h(t('A semana ainda não tem escala no site: não precisa apagar nada.')); ?></li>
    <?php endif; ?>
    <?php if ($corte !== ''): ?>
    <li><?php echo t('{acao} em cada página: Model = a página, Date Schedule {inicio}, Date End {fim}. No dia {inicio} só vão os blocos a partir da virada; antes disso o CSV fica em branco (continua o que já está no site).', ['acao' => '<strong>Save Schedule CSV</strong>', 'inicio' => '<strong>' . h($dataSite($diaCorte)) . '</strong>', 'fim' => '<strong>' . h($dataSite($fimSem)) . '</strong>']); ?></li>
    <?php else: ?>
    <li><?php echo t('{acao} em cada página: Model = a página, Date Schedule {inicio}, Date End {fim}. O CSV traz os blocos T1 a T6 dos 7 dias, todos os turnos juntos.', ['acao' => '<strong>Save Schedule CSV</strong>', 'inicio' => '<strong>' . h($dataSite($seg)) . '</strong>', 'fim' => '<strong>' . h($dataSite($fimSem)) . '</strong>']); ?></li>
    <?php endif; ?>
    <li><?php echo t('Na {pagina} vão a liderança (OS -8, OC -7, OA -6), os buffers de plantão (-2) e o Overlap (-5).', ['pagina' => '<strong>' . h(escV4Param('v4_overlap_pagina', '5C Schedule')) . '</strong>']); ?></li>
    <li><?php echo h(t('"Sem mudança": o CSV é igual ao da versão já postada; a página pode ficar como está.')); ?></li>
  </ol>
  <?php if ($soApagar): ?>
  <p class="small"><span class="muted"><?php echo h(t('Só apagar (sem CSV neste plano):')); ?></span> <?php echo h(implode(' · ', array_map(static fn($pid) => $porId[$pid]['nome'] ?? t('modelo {id}', ['id' => $pid]), array_keys($soApagar)))); ?></p>
  <?php endif; ?>

  <div class="linha-titulo postar-filtros">
    <div class="acoes">
      <input type="search" id="postar-busca" placeholder="<?php echo h(t('Buscar página…')); ?>" aria-label="<?php echo h(t('Buscar página')); ?>">
      <div class="chips">
        <?php foreach (['' => t('Todas') . ' · ' . count($cartoes), 'afazer' => t('A fazer') . ' · ' . $conta['afazer'], 'igual' => t('Sem mudança') . ' · ' . $conta['igual'], 'postado' => t('Postadas') . ' · ' . $conta['postado']] as $k => $rot): ?>
        <button type="button" class="chip-filtro <?php echo $k === '' ? 'ativo' : ''; ?>" aria-pressed="<?php echo $k === '' ? 'true' : 'false'; ?>" data-postar-estado="<?php echo h($k); ?>"><?php echo h($rot); ?></button>
        <?php endforeach; ?>
      </div>
    </div>
    <a class="botao" href="<?php echo h(link_tela('grade', ['plano' => $planoId, 'baixar' => 'zip'])); ?>"><?php echo h(t('Baixar todos (.zip)')); ?></a>
  </div>

  <div class="postar-lista">
  <?php foreach ($cartoes as $c): $pp = $c['pp']; $ant = $c['ant']; $idPre = 'csv-pg-' . $c['id']; ?>
    <div class="cartao-csv" id="postar-<?php echo (int) $c['id']; ?>" data-estado="<?php echo h($c['estado']); ?>" data-nome="<?php echo h($c['nome']); ?>">
      <div class="linha-titulo">
        <div><strong><?php echo h($c['nome']); ?></strong><?php if ($c['site']): ?> <span class="tag amarelo" title="<?php echo h(t('Já tem escala no site de {de} a {ate}', ['de' => dataBr($c['site']['de']), 'ate' => dataBr($c['site']['ate'])])); ?>"><?php echo h(t('no site')); ?></span><?php endif; ?></div>
        <span class="muted small">Model <strong><?php echo h($c['nome']); ?></strong> · Date Schedule <strong><?php echo h($dataSite($diaCorte)); ?></strong> · Date End <strong><?php echo h($dataSite($fimSem)); ?></strong></span>
      </div>
      <pre id="<?php echo h($idPre); ?>" class="csv"><?php echo h($c['csv']); ?></pre>
      <div class="acoes">
        <button type="button" class="botao primario" data-copiar="<?php echo h($idPre); ?>"><?php echo h(t('Copiar CSV')); ?></button>
        <a class="botao" href="<?php echo h(link_tela('grade', ['plano' => $planoId, 'baixar' => 'csv', 'pagina' => $c['id']])); ?>"><?php echo h(t('Baixar .csv')); ?></a>
        <?php if ($pp && $pp['postado_em']): ?><span class="tag verde"><?php echo h(t('postado {quando}', ['quando' => escDataHora($pp['postado_em'])])); ?></span>
        <?php elseif ($c['estado'] === 'igual'): ?><span class="tag verde"><?php echo h(t('sem mudança desde a {versao}', ['versao' => $rotuloVersao($ant)])); ?></span>
        <?php elseif ($pp && $pp['apagado_em']): ?><span class="tag amarelo"><?php echo h(t('intervalo apagado')); ?></span>
        <?php elseif ($ant): ?><span class="tag laranja"><?php echo h(t('mudou desde a {versao}', ['versao' => $rotuloVersao($ant)])); ?></span>
        <?php else: ?><span class="tag laranja"><?php echo h(t('a fazer')); ?></span><?php endif; ?>
        <?php if ($podeMarcar): ?>
        <form method="post" action="<?php echo h(link_tela('grade', ['plano' => $planoId, 'turno' => $turnoSel])); ?>" class="acoes">
          <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="postagem"><input type="hidden" name="pagina" value="<?php echo (int) $c['id']; ?>">
          <?php if ($c['site'] && (!$pp || !$pp['apagado_em'])): ?><button type="submit" name="passo" value="apagado" class="botao">1 · <?php echo h(t('Apaguei')); ?></button><?php endif; ?>
          <?php if (!$pp || !$pp['postado_em']): ?><button type="submit" name="passo" value="postado" class="botao"><?php echo $c['site'] ? '2 · ' : ''; ?><?php echo h(t('Postei')); ?></button><?php endif; ?>
          <?php if ($pp && ($pp['apagado_em'] || $pp['postado_em'])): ?><button type="submit" name="passo" value="desfazer" class="botao"><?php echo h(t('Desfazer')); ?></button><?php endif; ?>
        </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$cartoes): ?><p class="muted"><?php echo h(t('Nenhuma página com alocação neste plano.')); ?></p><?php endif; ?>
  <p class="muted small" id="postar-vazio" hidden><?php echo h(t('Nenhuma página neste filtro.')); ?></p>
  </div>
</section>

<script>
(function () {
  var busca = document.getElementById('postar-busca'), vazio = document.getElementById('postar-vazio'), estado = '';
  var cartoes = document.querySelectorAll('.postar-lista .cartao-csv');
  function tirar(t) { return t.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); }
  function aplicar() {
    var q = tirar(busca.value.trim()), n = 0;
    cartoes.forEach(function (c) {
      var ok = (estado === '' || c.dataset.estado === estado) && (q === '' || tirar(c.dataset.nome).indexOf(q) !== -1);
      c.hidden = !ok;
      if (ok) { n++; }
    });
    vazio.hidden = n > 0 || !cartoes.length;
  }
  busca.addEventListener('input', aplicar);
  document.querySelectorAll('[data-postar-estado]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-postar-estado]').forEach(function (x) { x.classList.toggle('ativo', x === b); });
      estado = b.dataset.postarEstado;
      aplicar();
    });
  });
})();
</script>
<?php endif; ?>
