<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/treinamento.php
// Treinamento de New Hires (lib/treinamento.php, ES_36). Três subseções (aba), um ciclo por vez:
//  - E-mail e resposta: colar o e-mail do Treinamento (e a lista de contratados), o resumo do
//    ciclo e a resposta, que só libera quando todas as turmas têm as páginas confirmadas;
//  - Páginas: uma turma por vez, com as escolhidas no topo, filtro (recomendadas por padrão) e
//    busca; confirmar leva à próxima turma sem página;
//  - Destino dos novatos (ES_137): unidade e núcleo depois do treinamento e o cadastro.
// Turma confirmada entra no plano na página 5C Schedule com -4; trainee fica fora da escala
// normal até o dia em que fica disponível.
// ===============================================

if (!escTrnInstalado()) {
    ?>
<header class="cabecalho"><span class="eyebrow"><?php echo h(t('Quadro e contratação')); ?></span><h1><?php echo h(t('Turmas de treinamento')); ?></h1></header>
<section class="panel"><p class="aviso erro" role="alert"><?php echo h(t('A tela de treinamento ainda não foi instalada no banco (script ES_36). Peça ao admin.')); ?></p></section>
    <?php
    return;
}

// Turma ainda sem páginas confirmadas (rascunho ou confirmada sem página).
$trnPendente = static fn(array $t): bool => $t['status'] !== 'ATIVA' || !$t['paginas'];
// Chave do ciclo (o "ciclo" do e-mail; sem ele, o início da turma).
$trnCiclo = static fn(array $t): string => (string) ($t['ciclo'] ?: $t['inicio']);

if (escPost()) {
    escExigir('planejar');
    $acao = $_POST['acao'] ?? '';
    if ($acao === 'ler') {
        $email = trim((string) ($_POST['email'] ?? ''));
        if (mb_strlen($email) < 40) {
            throw new RuntimeException(t('Cole o e-mail do Treinamento inteiro (com "Turma:", horário e datas).'));
        }
        $lido = escTrnLerEmail($email, (string) ($_POST['contratados'] ?? ''));
        if (!$lido['turmas']) {
            throw new RuntimeException(t('Não achei nenhuma turma ("Turma: ...") no texto.'));
        }
        $ids = escTrnSalvarLeitura($lido, $email);
        $novas = $ids ? array_intersect_key(escTrnTurmas(['RASCUNHO', 'ATIVA']), array_flip($ids)) : [];
        $cicloNovo = $novas ? $trnCiclo(reset($novas)) : null;
        escVoltar('treinamento', ['aba' => 'email', 'ciclo' => $cicloNovo], t('{n} turma(s) lida(s). Confira os dados e os alertas, escolha as páginas e confirme cada turma.', ['n' => count($ids)]));
    }
    $id = (int) ($_POST['turma_id'] ?? 0);
    if ($acao === 'confirmar') {
        escTrnConfirmar($id, (array) ($_POST['paginas'] ?? []), !empty($_POST['mesmo_assim']));
        $todas = escTrnTurmas(['RASCUNHO', 'ATIVA']);
        $turmaOk = $todas[$id] ?? null;
        $repetidas = $turmaOk ? array_intersect_key($turmaOk['paginas'], escTrnOcupadas($turmaOk)) : [];
        $msg = t('Turma confirmada. Recalcule as semanas do treinamento para os trainees entrarem na 5C Schedule com -4.')
            . ($repetidas ? ' ' . t('Atenção: {paginas} também está em treinamento em outro turno.', ['paginas' => implode(', ', array_keys($repetidas))]) : '');
        // Próxima turma do mesmo ciclo ainda sem páginas; sem nenhuma, a resposta ao e-mail está liberada.
        $ciclo = $turmaOk ? $trnCiclo($turmaOk) : null;
        $proxima = null;
        foreach ($todas as $tid => $tt) {
            if ($tid !== $id && $trnCiclo($tt) === $ciclo && $trnPendente($tt)) {
                $proxima = $tid;
                break;
            }
        }
        if ($proxima) {
            escVoltar('treinamento', ['aba' => 'paginas', 'ciclo' => $ciclo, 'turma' => $proxima], $msg . ' ' . t('Agora a turma {turma}.', ['turma' => $todas[$proxima]['codigo']]));
        }
        escVoltar('treinamento', ['aba' => 'email', 'ciclo' => $ciclo], $msg . ' ' . t('Todas as turmas do ciclo têm páginas: a resposta ao e-mail está liberada.'), false, 'resposta');
    }
    if ($acao === 'destino') {
        if (!escDstInstalado()) {
            throw new RuntimeException(t('O destino dos novatos ainda não foi instalado no banco (script ES_137). Peça ao admin.'));
        }
        $turma = escTrnTurmas(['RASCUNHO', 'ATIVA'])[$id] ?? null;
        if (!$turma) {
            throw new RuntimeException(t('Turma não encontrada.'));
        }
        $itens = [];
        foreach ((array) ($_POST['dst'] ?? []) as $rep => $x) {
            $rep = strtoupper((string) $rep);
            if (!isset($turma['trainees'][$rep]) || !is_array($x)) {
                continue;
            }
            $nuc = (int) ($x['nucleo_id'] ?? 0);
            $u = (int) ($x['unidade'] ?? 0);
            // O núcleo tem de ser da unidade e do turno da turma.
            if ($nuc && !escQ1('SELECT 1 AS ok FROM ' . escT('esc_nucleo') . ' WHERE id = ? AND unidade = ? AND turno = ?', [$nuc, $u, $turma['turno']])) {
                throw new RuntimeException(t('O núcleo de {rep} não é da unidade e do turno escolhidos.', ['rep' => $rep]));
            }
            $sug = (string) ($x['sugestao'] ?? '');
            $motivo = $sug !== '' && $sug === $u . '|' . $nuc ? (string) ($x['motivo'] ?? '') : t('escolhido por {quem}', ['quem' => (string) ($GLOBALS['ESC_USUARIO']['nome'] ?? '')]);
            $itens[$rep] = ['situacao' => ($x['situacao'] ?? '') === 'DESISTIU' ? 'DESISTIU' : 'TREINANDO', 'unidade' => $u ?: null, 'nucleo_id' => $nuc ?: null, 'motivo' => $motivo];
        }
        $n = escDstSalvar($id, $itens);
        escVoltar('treinamento', ['aba' => 'destino', 'ciclo' => $trnCiclo($turma)], tn($n, 'Destino de {n} trainee salvo. Na data disponível, cadastre em Colaboradores (botão em cada linha).', 'Destino de {n} trainees salvo. Na data disponível, cadastre em Colaboradores (botão em cada linha).'), false, 'turma-' . $id);
    }
    if ($acao === 'incluir') {
        $ativas = escTrnTurmas(['RASCUNHO', 'ATIVA']);
        if (!isset($ativas[$id])) {
            throw new RuntimeException(t('Turma não encontrada.'));
        }
        $rep = strtoupper(trim((string) ($_POST['rep_code'] ?? '')));
        escDstIncluir($id, $rep, mb_substr(trim((string) ($_POST['nome'] ?? '')), 0, 150));
        escVoltar('treinamento', ['aba' => 'destino', 'ciclo' => $trnCiclo($ativas[$id])], t('{rep} incluído na turma.', ['rep' => $rep]), false, 'turma-' . $id);
    }
    if ($acao === 'cancelar') {
        $turma = escTrnTurmas(['RASCUNHO', 'ATIVA'])[$id] ?? null;
        escTrnCancelar($id);
        escVoltar('treinamento', ['aba' => 'email', 'ciclo' => $turma ? $trnCiclo($turma) : null], t('Turma cancelada: sai do plano no próximo recálculo.'));
    }
    if ($acao === 'ajustar') {
        $turma = escTrnTurmas(['RASCUNHO', 'ATIVA'])[$id] ?? null;
        $datas = [];
        foreach (['inicio', 'fim', 'disponivel_em'] as $c) {
            $datas[$c] = preg_match('~^\d{4}-\d{2}-\d{2}$~', (string) ($_POST[$c] ?? '')) ? $_POST[$c] : null;
        }
        $turno = in_array($_POST['turno'] ?? '', ['A', 'C', 'D'], true) ? $_POST['turno'] : null;
        $n = max(1, min(9, (int) ($_POST['paginas_necessarias'] ?? 1)));
        if (!$turma || !$turno || in_array(null, $datas, true)) {
            throw new RuntimeException(t('Preencha turno e as três datas.'));
        }
        if ($datas['fim'] < $datas['inicio'] || $datas['disponivel_em'] <= $datas['fim']) {
            throw new RuntimeException(t('O fim vem depois do início, e o "disponível" depois do fim.'));
        }
        escTx(function () use ($turno, $datas, $n, $id, $turma) {
            escW('UPDATE ' . escT('esc_treinamento_turma') . ' SET turno = ?, inicio = ?, fim = ?, disponivel_em = ?, paginas_necessarias = ? WHERE id = ?',
                [$turno, $datas['inicio'], $datas['fim'], $datas['disponivel_em'], $n, $id]);
            if ($turno !== $turma['turno']) {
                escW('DELETE FROM ' . escT('esc_treinamento_pagina') . ' WHERE turma_id = ?', [$id]);
                escW('UPDATE ' . escT('esc_treinamento_turma') . " SET status = 'RASCUNHO' WHERE id = ?", [$id]);
            }
            escLog('treinamento.ajustar', 'treinamento', $id, null, $datas + ['turno' => $turno, 'paginas' => $n]);
        });
        escVoltar('treinamento', ['aba' => 'paginas', 'ciclo' => $trnCiclo($turma), 'turma' => $id], t('Turma ajustada.'));
    }
    throw new RuntimeException(t('Não deu para entender o pedido. Recarregue a tela e tente de novo.'));
}

$podePlanejar = escPode('planejar');
$verAntigas = !empty($_GET['antigas']);
$turmas = escTrnTurmas(['RASCUNHO', 'ATIVA'], $verAntigas ? '2000-01-01' : date('Y-m-d'), '2999-12-31');
$nomes = escNomes();
$en = escEmIngles();
$bl = ['A' => 'T2/T3', 'C' => 'T4/T5', 'D' => 'T6/T1'];
$rotStatus = ['RASCUNHO' => [t('rascunho'), 'amarelo'], 'ATIVA' => [t('confirmada'), 'verde'], 'CANCELADA' => [t('cancelada'), 'cinza']];
$comDestino = escDstInstalado();
$podeCadastrar = escPode('cadastrar');

// Ciclos: o pedido; senão o primeiro com turma sem página; senão o mais recente.
$ciclos = [];
foreach ($turmas as $id => $t) {
    $ciclos[$trnCiclo($t)][$id] = $t;
}
$cicloSel = is_string($_GET['ciclo'] ?? null) ? $_GET['ciclo'] : '';
if (!isset($ciclos[$cicloSel])) {
    $cicloSel = '';
    foreach ($ciclos as $c => $doC) {
        if (array_filter($doC, $trnPendente)) {
            $cicloSel = (string) $c;
            break;
        }
    }
    if ($cicloSel === '' && $ciclos) {
        $cicloSel = (string) array_key_last($ciclos);
    }
}
$doCiclo = $ciclos[$cicloSel] ?? [];
$aba = in_array($_GET['aba'] ?? '', ['email', 'paginas', 'destino'], true) ? $_GET['aba'] : 'email';
$ctx = ['ciclo' => $doCiclo ? $cicloSel : null, 'antigas' => $verAntigas ? 1 : null];
$link = static fn(array $p = []) => link_tela('treinamento', array_merge($ctx, $p));

// Pendências do ciclo (contadores das subseções).
$semPagina = array_filter($doCiclo, $trnPendente);
$dstConfirmados = static function (array $t): array {
    $ativos = array_filter($t['trainees'], static fn($tr) => ($tr['situacao'] ?? 'TREINANDO') !== 'DESISTIU');
    $ok = array_filter($ativos, static fn($tr) => !empty($tr['destino_em']) && !empty($tr['unidade_destino']));
    return [count($ok), count($ativos)];
};
$dstFalta = 0;
if ($comDestino) {
    foreach ($doCiclo as $t) {
        [$ok, $tot] = $dstConfirmados($t);
        $dstFalta += $tot - $ok;
    }
}
$semanas = [];
if ($doCiclo) {
    $ini = min(array_column($doCiclo, 'inicio'));
    $fim = max(array_column($doCiclo, 'fim'));
    for ($s = segundaDaSemana($ini); $s <= $fim; $s = escMaisDias($s, 7)) {
        $semanas[] = $s;
    }
}
$flash = escFlashLer();
$repost = is_array($flash['post'] ?? null) ? $flash['post'] : [];
?>
<header class="cabecalho">
  <span class="eyebrow"><?php echo h(t('Quadro e contratação')); ?></span>
  <h1><?php echo h(t('Turmas de treinamento')); ?></h1>
  <p class="muted"><?php echo h(t('Cole o e-mail do Treinamento: o sistema lê as turmas, recomenda as páginas para emprestar, gera a resposta e põe os trainees na página 5C Schedule com -4.')); ?> <?php echo h(t('Depois, sugere onde cada novato entra (unidade e núcleo) para preencher as lacunas.')); ?></p>
</header>

<section class="panel treino-nav">
  <div class="linha-titulo">
    <nav class="chips" aria-label="<?php echo h(t('Subseções')); ?>">
      <?php foreach (['email' => [t('E-mail e resposta'), ''], 'paginas' => [t('Páginas'), $semPagina ? tn(count($semPagina), '{n} pendente', '{n} pendentes') : ''], 'destino' => [t('Destino dos novatos'), $dstFalta ? tn($dstFalta, '{n} a confirmar', '{n} a confirmar') : '']] as $k => [$rot, $pend]): ?>
        <a class="chip-filtro <?php echo $aba === $k ? 'ativo' : ''; ?>" href="<?php echo h($link(['aba' => $k])); ?>" <?php echo $aba === $k ? 'aria-current="page"' : ''; ?>><?php echo h($rot); ?><?php if ($pend !== ''): ?> · <span class="laranja"><?php echo h($pend); ?></span><?php endif; ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="chips" aria-label="<?php echo h(t('Ciclo')); ?>">
      <?php if (count($ciclos) > 1): foreach ($ciclos as $c => $doC): ?>
        <a class="chip-filtro <?php echo (string) $c === $cicloSel ? 'ativo' : ''; ?>" href="<?php echo h(link_tela('treinamento', ['aba' => $aba, 'ciclo' => (string) $c, 'antigas' => $verAntigas ? 1 : null])); ?>"><?php echo h(t('Ciclo {ciclo}', ['ciclo' => $c])); ?><?php if (array_filter($doC, $trnPendente)): ?> <span class="laranja" aria-label="<?php echo h(t('com pendência')); ?>">●</span><?php endif; ?></a>
      <?php endforeach; elseif ($doCiclo): ?>
        <span class="chip-filtro ativo"><?php echo h(t('Ciclo {ciclo}', ['ciclo' => $cicloSel])); ?></span>
      <?php endif; ?>
      <a class="chip-filtro" href="<?php echo h(link_tela('treinamento', ['aba' => $aba] + ($verAntigas ? [] : ['antigas' => 1]))); ?>"><?php echo h($verAntigas ? t('Só as turmas atuais') : t('Ver turmas antigas')); ?></a>
    </div>
  </div>
</section>

<?php if ($aba === 'email'): ?>
<?php if ($podePlanejar): ?>
<section class="panel">
  <h2><?php echo h(t('Colar o e-mail')); ?></h2>
  <form method="post" action="<?php echo h($link(['aba' => 'email'])); ?>" class="treino-email">
    <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="ler">
    <label><?php echo h(t('E-mail do Treinamento (turmas, horário, datas, quadro e lista do WFM)')); ?>
      <textarea name="email" rows="9" required placeholder="Turma: NH45A2026&#10;HC: 04&#10;Instrutor: …&#10;Horário: 05h - 13h …"><?php echo h((string) ($repost['email'] ?? '')); ?></textarea></label>
    <label><?php echo h(t('Lista de contratados do RH (opcional: nome, nome social e código)')); ?>
      <textarea name="contratados" rows="9" placeholder="NAME	SOCIAL NAME	CODE (site)"><?php echo h((string) ($repost['contratados'] ?? '')); ?></textarea></label>
    <div class="acoes">
      <button type="submit" class="botao primario" data-longo="<?php echo h(t('Lendo o e-mail…')); ?>"><?php echo h(t('Ler e-mail')); ?></button>
      <?php if ($turmas): ?><span class="muted small"><?php echo h(t('Ler de novo uma turma em rascunho troca os dados e os trainees dela (os destinos já confirmados se perdem). Turma confirmada não muda: cancele antes.')); ?></span><?php endif; ?>
    </div>
  </form>
</section>
<?php endif; ?>

<?php if (!$doCiclo): ?>
<section class="panel"><p class="muted"><?php echo h(t('Nenhuma turma em andamento.')); ?> <?php if ($podePlanejar): ?><?php echo h(t('Cole o e-mail acima para começar.')); ?><?php endif; ?></p></section>
<?php else:
    // Alertas do ciclo: os gerais (lista de contratados, cadastro, histórico) e os de cada turma ("NH45A2026: ...").
    $alertas = [];
    foreach ($doCiclo as $t) {
        foreach ($t['alertas'] as $a) {
            $alertas[$a] = true;
        }
    }
?>
<section class="panel">
  <h2><?php echo h(t('Ciclo {ciclo}', ['ciclo' => $cicloSel])); ?> <span class="muted small"><?php echo h(dataBr(min(array_column($doCiclo, 'inicio'))) . ' – ' . dataBr(max(array_column($doCiclo, 'fim')))); ?></span></h2>
  <?php if ($alertas): ?>
  <ul class="aviso alerta small" role="status"><?php foreach (array_keys($alertas) as $a): ?><li><?php echo h($a); ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
  <div class="tabela-rolagem"><table class="treino-resumo">
    <thead><tr><th><?php echo h(t('Turma')); ?></th><th><?php echo h(t('Situação')); ?></th><th><?php echo h(t('Horário e instrutor(a)')); ?></th><th><?php echo h(t('Treinamento')); ?></th><th><?php echo h(t('Disponível')); ?></th><th class="num"><?php echo h(t('Trainees')); ?></th><th><?php echo h(t('Páginas')); ?></th><?php if ($comDestino): ?><th><?php echo h(t('Destinos')); ?></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($doCiclo as $id => $t): [$stRot, $stCls] = $rotStatus[$t['status']]; ?>
      <tr>
        <td><a href="<?php echo h($link(['aba' => 'paginas', 'turma' => $id])); ?>"><strong><?php echo h($t['codigo']); ?></strong></a> <?php echo chipTurno($t['turno']); ?></td>
        <td><span class="tag <?php echo $stCls; ?>"><?php echo h($stRot); ?></span></td>
        <td class="small"><?php echo h(($t['horario'] ?: '—') . ' (' . $bl[$t['turno']] . ')'); ?><br><span class="muted"><?php echo h($t['instrutor'] ?: '—'); ?></span></td>
        <td class="small"><?php echo h(dataBr($t['inicio']) . ' – ' . dataBr($t['fim'])); ?><?php if ($t['nesting_de']): ?><br><span class="muted"><?php echo h(t('nesting {de} a {ate}', ['de' => dataBr($t['nesting_de']), 'ate' => dataBr($t['nesting_ate'] ?? $t['nesting_de'])])); ?></span><?php endif; ?></td>
        <td class="small"><?php echo h(dataBr($t['disponivel_em'], true)); ?></td>
        <td class="num"><?php echo count($t['trainees']); ?> <span class="muted small">(HC <?php echo h((string) ($t['hc'] ?? '—')); ?>)</span></td>
        <td class="small"><?php if ($trnPendente($t)): ?><a class="tag laranja" href="<?php echo h($link(['aba' => 'paginas', 'turma' => $id])); ?>"><?php echo h(t('escolher páginas')); ?></a>
          <?php else: foreach (array_keys($t['paginas']) as $p): ?><span class="tag verde"><?php echo h($p); ?></span> <?php endforeach; endif; ?></td>
        <?php if ($comDestino): [$ok, $tot] = $dstConfirmados($t); ?>
        <td class="small"><a href="<?php echo h($link(['aba' => 'destino'])); ?>#turma-<?php echo $id; ?>" class="<?php echo $ok < $tot ? 'laranja' : ''; ?>"><?php echo h(t('{ok} de {n} confirmados', ['ok' => $ok, 'n' => $tot])); ?></a></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>

<section class="panel" id="resposta">
  <h2><?php echo h(t('Resposta ao e-mail')); ?></h2>
  <?php if ($semPagina): ?>
  <div class="aviso alerta" role="status">
    <p><strong><?php echo h(t('A resposta libera quando todas as turmas do ciclo tiverem as páginas confirmadas.')); ?></strong></p>
    <ul class="small"><?php foreach ($semPagina as $id => $t): ?><li><?php echo h(t('{turma}: falta confirmar as páginas', ['turma' => $t['codigo']])); ?> · <a href="<?php echo h($link(['aba' => 'paginas', 'turma' => $id])); ?>"><?php echo h(t('escolher páginas')); ?></a></li><?php endforeach; ?></ul>
  </div>
  <?php else: $idResp = 'resposta-' . md5($cicloSel); ?>
  <textarea class="treino-resposta" rows="16" readonly id="<?php echo h($idResp); ?>" aria-label="<?php echo h(t('Resposta ao e-mail')); ?>"><?php echo h(escTrnResposta($doCiclo, $en)); ?></textarea>
  <div class="acoes"><button type="button" class="botao primario" data-copiar="#<?php echo h($idResp); ?>"><?php echo h(t('Copiar resposta')); ?></button>
    <span class="muted small"><?php echo h(t('Depois de confirmar as turmas, recalcule as semanas {semanas} na Escala para os trainees entrarem na 5C Schedule com -4.', ['semanas' => implode(', ', array_map(static fn($s) => dataBr($s), $semanas))])); ?></span></div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php elseif ($aba === 'paginas'): ?>
<?php if (!$doCiclo): ?>
<section class="panel"><p class="muted"><?php echo h(t('Nenhuma turma em andamento.')); ?> <a href="<?php echo h($link(['aba' => 'email'])); ?>"><?php echo h(t('Colar o e-mail')); ?></a></p></section>
<?php else:
    $turmaSel = (int) ($_GET['turma'] ?? 0);
    if (!isset($doCiclo[$turmaSel])) {
        $turmaSel = (int) (array_key_first($semPagina) ?? array_key_first($doCiclo));
    }
    $t = $doCiclo[$turmaSel];
    $id = $turmaSel;
    [$stRot, $stCls] = $rotStatus[$t['status']];
    $sug = escTrnSugerir($turmas);
    $s = $sug[$id] ?? ['candidatas' => [], 'escolha' => []];
    $min = pct((float) str_replace(',', '.', escV4Param('treinamento_ating_min', '0.85')), 0);
    // Recomendada: pode, sem aviso e lacuna ou emprestada (o mesmo critério da sugestão), só as
    // primeiras na ordem de prioridade; as demais ficam em "Todas que podem".
    $maxRec = max(12, 4 * (int) $t['paginas_necessarias']);
    $grupos = [];
    $nGrupo = ['rec' => 0, 'podem' => 0, 'nao' => 0];
    foreach ($s['candidatas'] as $nome => $c) {
        $rec = $c['ok'] && $c['aviso'] === null && $c['modo'] !== 'OUTRA' && $nGrupo['rec'] < $maxRec;
        $grupos[$nome] = !$c['ok'] ? 'nao' : ($rec ? 'rec podem' : 'podem');
        foreach (explode(' ', $grupos[$nome]) as $g) {
            $nGrupo[$g]++;
        }
    }
    $grupo = static fn(array $c): string => $grupos[$c['pagina']];
?>
<section class="panel">
  <nav class="chips" aria-label="<?php echo h(t('Turmas do ciclo')); ?>">
    <?php foreach ($doCiclo as $tid => $tt): ?>
      <a class="chip-filtro <?php echo $tid === $id ? 'ativo' : ''; ?>" href="<?php echo h($link(['aba' => 'paginas', 'turma' => $tid])); ?>" <?php echo $tid === $id ? 'aria-current="page"' : ''; ?>><?php echo h($tt['codigo']); ?> <?php echo chipTurno($tt['turno']); ?> <?php echo $trnPendente($tt) ? '<span class="laranja">' . h(t('sem página')) . '</span>' : '<span class="verde" aria-label="' . h(t('confirmada')) . '">✓</span>'; ?></a>
    <?php endforeach; ?>
  </nav>
</section>

<section class="panel treino-turma-painel" id="turma-<?php echo $id; ?>">
  <header class="linha-titulo">
    <div>
      <h2 class="treino-turma-titulo"><?php echo h($t['codigo']); ?> <?php echo chipTurno($t['turno']); ?> <span class="tag <?php echo $stCls; ?>"><?php echo h($stRot); ?></span></h2>
      <p class="small"><?php echo h(t('{horario} ({blocos}) · instrutor(a) {instrutor} · {n} trainees (HC {hc})', ['horario' => $t['horario'] ?: '—', 'blocos' => $bl[$t['turno']], 'instrutor' => $t['instrutor'] ?: '—', 'n' => count($t['trainees']), 'hc' => $t['hc'] ?? '—'])); ?><br>
        <?php echo h(t('Treinamento {ini} a {fim} (seg a sex){nesting} · disponível para a operação em {disp}', ['ini' => dataBr($t['inicio'], true), 'fim' => dataBr($t['fim'], true),
            'nesting' => $t['nesting_de'] ? ' · ' . t('nesting {de} a {ate}', ['de' => dataBr($t['nesting_de']), 'ate' => dataBr($t['nesting_ate'] ?? $t['nesting_de'])]) : '', 'disp' => dataBr($t['disponivel_em'], true)])); ?></p>
    </div>
    <?php if ($podePlanejar): ?>
    <div class="acoes">
      <form method="post" action="<?php echo h($link(['aba' => 'paginas', 'turma' => $id])); ?>">
        <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="cancelar"><input type="hidden" name="turma_id" value="<?php echo $id; ?>">
        <button type="submit" class="botao perigo" data-confirmar="<?php echo h(t('Cancelar a turma {turma}? Os trainees saem da 5C Schedule no próximo recálculo.', ['turma' => $t['codigo']])); ?>"><?php echo h(t('Cancelar turma')); ?></button>
      </form>
    </div>
    <?php endif; ?>
  </header>
  <?php if ($podePlanejar): ?>
  <details class="treino-ajustar"><summary class="botao"><?php echo h(t('Ajustar datas e turno')); ?></summary>
    <form method="post" action="<?php echo h($link(['aba' => 'paginas', 'turma' => $id])); ?>" class="form-grade" data-turno-atual="<?php echo h($t['turno']); ?>" data-aviso-turno="<?php echo h(t('Trocar o turno apaga as páginas escolhidas da turma {turma} e ela volta para rascunho. Continuar?', ['turma' => $t['codigo']])); ?>">
      <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="ajustar"><input type="hidden" name="turma_id" value="<?php echo $id; ?>">
      <label><?php echo h(t('Turno')); ?><select name="turno"><?php foreach ($bl as $tt => $b): ?><option value="<?php echo $tt; ?>" <?php echo $tt === $t['turno'] ? 'selected' : ''; ?>><?php echo $tt . ' · ' . $b; ?></option><?php endforeach; ?></select></label>
      <label><?php echo h(t('Início')); ?><input type="date" name="inicio" value="<?php echo h($t['inicio']); ?>" required></label>
      <label><?php echo h(t('Último dia (sexta)')); ?><input type="date" name="fim" value="<?php echo h($t['fim']); ?>" required></label>
      <label><?php echo h(t('Disponível para a operação')); ?><input type="date" name="disponivel_em" value="<?php echo h($t['disponivel_em']); ?>" required></label>
      <label><?php echo h(t('Páginas')); ?><input type="number" name="paginas_necessarias" min="1" max="9" value="<?php echo (int) $t['paginas_necessarias']; ?>"></label>
      <div class="acoes"><button type="submit" class="botao"><?php echo h(t('Salvar')); ?></button></div>
    </form>
  </details>
  <?php endif; ?>
  <?php $alertasT = array_values(array_filter($t['alertas'], static fn($a) => strncmp($a, $t['codigo'] . ':', strlen($t['codigo'] . ':')) === 0)); if ($alertasT): ?>
  <ul class="aviso alerta small" role="status"><?php foreach ($alertasT as $a): ?><li><?php echo h($a); ?></li><?php endforeach; ?></ul>
  <?php endif; ?>

  <?php if (!$podePlanejar): ?>
  <h3><?php echo h(t('Páginas escolhidas')); ?></h3>
  <p><?php if ($t['paginas']): foreach (array_keys($t['paginas']) as $p): ?><span class="tag verde"><?php echo h($p); ?></span> <?php endforeach; else: ?><span class="laranja"><?php echo h(t('sem página')); ?></span><?php endif; ?></p>
  <?php else: ?>
  <form method="post" action="<?php echo h($link(['aba' => 'paginas', 'turma' => $id])); ?>" class="treino-paginas-form" data-pedidas="<?php echo (int) $t['paginas_necessarias']; ?>" data-cor="<?php echo $t['paginas'] ? 'verde' : 'amarelo'; ?>">
    <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="confirmar"><input type="hidden" name="turma_id" value="<?php echo $id; ?>">
    <div class="treino-escolhidas" aria-live="polite">
      <strong><?php echo h(t('Escolhidas')); ?></strong>
      <span class="treino-escolhidas-lista"><?php foreach ($s['escolha'] as $p): ?><span class="tag <?php echo $t['paginas'] ? 'verde' : 'amarelo'; ?>"><?php echo h($p); ?></span> <?php endforeach; ?></span>
      <span class="small muted treino-contador"><?php echo h(t('{n} marcada(s) · {p} pedida(s)', ['n' => count($s['escolha']), 'p' => (int) $t['paginas_necessarias']])); ?></span>
      <?php if (!$t['paginas'] && $s['escolha']): ?><span class="small muted"><?php echo h(t('Amarelo = sugestão do sistema, ainda não confirmada.')); ?></span><?php endif; ?>
    </div>
    <p class="muted small"><?php echo h(t('Primeiro as páginas com lacuna no turno nos dias da turma: melhor treinar do que deixar vazia. Depois as menores de posto com 2 ou mais páginas e, por fim, todas as outras do turno.')); ?> <?php echo h(t('Na lacuna, o gerador põe a escala normal em outras páginas.')); ?></p>
    <div class="treino-filtros" role="group" aria-label="<?php echo h(t('Filtrar as páginas')); ?>">
      <?php foreach (['rec' => t('Recomendadas'), 'podem' => t('Todas que podem'), 'nao' => t('Não podem'), 'todas' => t('Todas')] as $k => $rot): ?>
        <button type="button" class="chip-filtro <?php echo $k === 'rec' ? 'ativo' : ''; ?>" data-filtro-pag="<?php echo $k; ?>" aria-pressed="<?php echo $k === 'rec' ? 'true' : 'false'; ?>"><?php echo h($rot); ?> · <?php echo $k === 'todas' ? count($s['candidatas']) : $nGrupo[$k]; ?></button>
      <?php endforeach; ?>
      <input type="search" data-busca-pag placeholder="<?php echo h(t('buscar página, dono ou unidade')); ?>" aria-label="<?php echo h(t('Buscar página, dono ou unidade')); ?>" autocomplete="off">
    </div>
    <div class="tabela-rolagem"><table class="treino-paginas">
      <thead><tr><th aria-label="<?php echo h(t('Marcar')); ?>"></th><th><?php echo h(t('Página')); ?></th><th><?php echo h(t('Tipo')); ?></th><th class="num"><?php echo h(t('Fãs/h')); ?></th><th><?php echo h(t('Dono do turno')); ?></th><th class="num"><?php echo h(t('Mês · turno')); ?></th><th><?php echo h(t('Situação')); ?></th></tr></thead>
      <tbody>
      <?php foreach ($s['candidatas'] as $nome => $c): $marcada = in_array($nome, $s['escolha'], true); $dono = $c['owner'] ? nomeCurto($nomes[$c['owner']] ?? $c['owner']) : ''; ?>
        <tr class="<?php echo $c['ok'] ? '' : 'muted'; ?>" data-grupo="<?php echo $grupo($c); ?>" data-busca="<?php echo h(mb_strtolower($nome . ' ' . $dono . ' ' . ($c['owner'] ?? '') . ' u' . (int) $c['unidade'])); ?>" <?php echo $c['aviso'] !== null ? 'data-aviso="1"' : ''; ?>>
          <td><input type="checkbox" name="paginas[]" value="<?php echo h($nome); ?>" id="pg-<?php echo $id; ?>-<?php echo h(md5($nome)); ?>" <?php echo $marcada ? 'checked' : ''; ?> <?php echo escTrnOftv($nome) ? 'disabled' : ''; ?>></td>
          <td><label for="pg-<?php echo $id; ?>-<?php echo h(md5($nome)); ?>"><strong><?php echo h($nome); ?></strong></label><br><span class="small muted">U<?php echo (int) $c['unidade']; ?></span></td>
          <td class="small"><?php if ($c['modo'] === 'LACUNA'): ?><span class="tag laranja"><?php echo h(t('lacuna')); ?></span><br><span class="muted"><?php echo h(tn((int) $c['lacunas'], '{n} bloco vazio', '{n} blocos vazios') . ' · ' . implode(', ', $c['semanas'])); ?></span><?php elseif ($c['modo'] === 'EMPRESTADA'): ?><span class="tag"><?php echo h(t('emprestada')); ?></span><br><span class="muted"><?php echo h(t('dono fica com {p}', ['p' => $c['fica_com']])); ?></span><?php else: ?><span class="tag"><?php echo h(t('outra')); ?></span><br><span class="muted"><?php echo h($c['fica_com'] ? t('maior página do posto (o SR fica com {p})', ['p' => $c['fica_com']]) : t('posto de uma página: o SR vai para uma página com lacuna no mesmo horário')); ?></span><?php endif; ?></td>
          <td class="num"><?php echo num($c['fans_h'], 1); ?></td>
          <td class="small"><?php echo $c['owner'] ? h($dono) . ' <span class="mono muted">' . h($c['owner']) . '</span>' : '—'; ?></td>
          <td class="num"><?php echo $c['ating'] !== null ? pct($c['ating'], 0) : '—'; ?> · <?php echo $c['ating_turno'] !== null ? pct($c['ating_turno'], 0) : '—'; ?></td>
          <td class="small"><?php echo $c['ok'] ? ($c['aviso'] ? '<span class="tag amarelo">⚠ ' . h($c['aviso']) . '</span>' : ($c['sem_dados'] ? '<span class="tag">' . h(t('sem dados de meta')) . '</span>' : '<span class="tag verde">' . h(t('pode')) . '</span>')) : '<span class="tag laranja">' . h((string) $c['motivo']) . '</span>'; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$s['candidatas']): ?><tr><td colspan="7" class="muted"><?php echo h(t('Nenhuma lacuna nem posto com 2 ou mais páginas no turno {t} na data de início.', ['t' => $bl[$t['turno']]])); ?></td></tr><?php endif; ?>
      <tr class="treino-vazio" hidden><td colspan="7" class="muted"><?php echo h(t('Nenhuma página neste filtro. Troque o filtro ou limpe a busca.')); ?></td></tr>
      </tbody>
    </table></div>
    <div class="acoes treino-rodape">
      <label class="small" data-mesmo-assim <?php echo array_filter($s['candidatas'], static fn($c) => $c['aviso'] !== null && in_array($c['pagina'], $s['escolha'], true)) ? '' : 'hidden'; ?>><input type="checkbox" name="mesmo_assim" value="1"> <?php echo h(t('usar mesmo assim página que já está em outro turno (fica em treinamento em mais de um turno)')); ?></label>
      <button type="submit" class="botao primario"><?php echo h($t['status'] === 'ATIVA' ? t('Salvar páginas') : t('Confirmar turma')); ?></button>
      <span class="small muted treino-contador"><?php echo h(t('{n} marcada(s) · {p} pedida(s)', ['n' => count($s['escolha']), 'p' => (int) $t['paginas_necessarias']])); ?></span>
    </div>
  </form>
  <?php endif; ?>
</section>

<section class="panel">
  <details>
    <summary><strong><?php echo h(t('Como o sistema trata os New Hires')); ?></strong></summary>
    <ul class="small">
      <li><?php echo h(t('O motor só escala quem tem cadastro de SR com núcleo em Colaboradores. New Hire não tem cadastro: não entra na escala normal.')); ?></li>
      <li><?php echo h(t('Trava a mais: quem está numa turma (rascunho ou confirmada) fica fora da escala normal até o dia "disponível para operação", mesmo que alguém cadastre a pessoa num núcleo antes.')); ?></li>
      <li><?php echo h(t('Com a turma confirmada, os trainees vão para a página 5C Schedule com -4, de segunda a sexta, no horário da turma (clock-in/out), como o Overlap (-5).')); ?></li>
      <li><?php echo h(t('Depois do treinamento (em geral 14 dias), cadastre cada um em Colaboradores com início no dia disponível, no núcleo onde vai ficar. A partir daí segue a escala normal.')); ?></li>
      <li><?php echo h(t('Página confirmada fica com o treinamento nos dias e no horário da turma: o gerador não escala ninguém da escala normal nela. Se o posto só tinha essa página, o SR vai para outra página com lacuna no mesmo horário (ou fica de buffer); se o posto tem outras páginas, o SR fica com elas. Por isso, confirme o treinamento antes de gerar a escala.')); ?></li>
      <li><?php echo h(t('Página emprestada: a menor de um posto com 2 ou mais páginas, sem OFTV (final OFTV ou número com t, ex.: 141t), sem mês abaixo de {min} e sem repetir entre turnos ao mesmo tempo. O dono do turno continua dono e com a comissão; nas pausas ele assume.', ['min' => $min])); ?></li>
      <li><?php echo h(t('As mesmas páginas valem o ciclo inteiro.')); ?></li>
    </ul>
  </details>
</section>
<?php endif; ?>

<?php else: // destino ?>
<?php if (!$doCiclo): ?>
<section class="panel"><p class="muted"><?php echo h(t('Nenhuma turma em andamento.')); ?></p></section>
<?php else:
    $modeloPadrao = 0;
    if ($comDestino) {
        $modeloPadrao = (int) (escQ1('SELECT modelo_id, COUNT(*) AS n FROM ' . escT('esc_colaborador_config') . " WHERE funcao = 'EQUITE' AND vigente_ate IS NULL GROUP BY modelo_id ORDER BY n DESC LIMIT 1")['modelo_id'] ?? 0);
    }
?>
<?php if ($comDestino): ?>
<section class="panel">
  <p class="muted small"><?php echo h(t('Sugestão do sistema: a unidade com mais lacunas no turno da turma na escala da semana em que ela fica disponível; no empate, a com mais falta no Capacity. Dentro da unidade, o núcleo com menos SRs para a parte dele. Confirme ou troque; o cadastro em Colaboradores (a partir do dia disponível) é o que põe a pessoa na escala.')); ?></p>
</section>
<?php endif; ?>
<?php foreach ($doCiclo as $id => $t):
    [$stRot, $stCls] = $rotStatus[$t['status']]; ?>
<section class="panel treino-destino" id="turma-<?php echo $id; ?>">
  <h2 class="treino-turma-titulo"><?php echo h($t['codigo']); ?> <?php echo chipTurno($t['turno']); ?> <span class="tag <?php echo $stCls; ?>"><?php echo h($stRot); ?></span>
    <span class="muted small"><?php echo h(t('disponível em {disp}', ['disp' => dataBr($t['disponivel_em'], true)])); ?><?php if ($comDestino && $t['trainees']): [$ok, $tot] = $dstConfirmados($t); ?> · <?php echo h(t('{ok} de {n} confirmados', ['ok' => $ok, 'n' => $tot])); ?><?php endif; ?></span></h2>
  <?php if (!$t['trainees']): ?>
  <p class="muted small"><?php echo h(t('A turma {turma} está sem trainees.', ['turma' => $t['codigo']])); ?></p>
  <?php elseif (!$comDestino): ?>
  <p class="muted small"><?php echo h(t('O destino dos novatos ainda não foi instalado no banco (script ES_137). Peça ao admin.')); ?></p>
  <ul class="treino-trainees small"><?php foreach ($t['trainees'] as $rep => $tr): ?><li><strong><?php echo h($tr['nome_social'] ?: ($tr['nome'] ?: $rep)); ?></strong> <span class="mono muted"><?php echo h($rep); ?></span></li><?php endforeach; ?></ul>
  <?php else:
      $sugT = escDstSugerir($t, escDstDesignados($turmas, $id));
      $nucT = escDstNucleos((string) $t['disponivel_em']);
      $aplT = escDstAplicados(array_keys($t['trainees']), (string) $t['disponivel_em']);
      $podeDst = $podePlanejar; ?>
    <?php if ($podeDst): ?><form method="post" action="<?php echo h($link(['aba' => 'destino'])); ?>#turma-<?php echo $id; ?>"><?php echo campoCsrf(); ?><input type="hidden" name="acao" value="destino"><input type="hidden" name="turma_id" value="<?php echo $id; ?>"><?php endif; ?>
    <div class="tabela-rolagem"><table class="treino-destino-tab">
      <thead><tr><th><?php echo h(t('Trainee')); ?></th><th><?php echo h(t('Situação')); ?></th><th><?php echo h(t('Unidade')); ?></th><th><?php echo h(t('Núcleo')); ?></th><th><?php echo h(t('Por quê')); ?></th><th><?php echo h(t('Colaboradores')); ?></th></tr></thead>
      <tbody>
      <?php foreach ($t['trainees'] as $rep => $tr):
          $sit = (string) ($tr['situacao'] ?? 'TREINANDO');
          $conf = !empty($tr['destino_em']) && !empty($tr['unidade_destino']);
          $sg = $sugT[$rep] ?? null;
          $uSel = $conf ? (int) $tr['unidade_destino'] : (int) ($sg['unidade'] ?? 0);
          $nSel = $conf ? (int) $tr['nucleo_destino_id'] : (int) ($sg['nucleo_id'] ?? 0);
          $motivo = $conf ? (string) $tr['destino_motivo'] : (string) ($sg['motivo'] ?? '');
          $apl = $aplT[$rep] ?? null;
          $campo = 'dst[' . $rep . ']'; ?>
        <tr class="<?php echo $sit === 'DESISTIU' ? 'muted' : ''; ?>">
          <td><strong><?php echo h($tr['nome_social'] ?: ($tr['nome'] ?: $rep)); ?></strong><br><span class="mono muted small"><?php echo h($rep); ?></span><?php if ($tr['nome_social'] && $tr['nome']): ?><br><span class="muted small"><?php echo h(t('nome de registro: {n}', ['n' => $tr['nome']])); ?></span><?php endif; ?></td>
          <td><?php if ($podeDst): ?><select name="<?php echo h($campo); ?>[situacao]" aria-label="<?php echo h(t('Situação de {rep}', ['rep' => $rep])); ?>">
              <option value="TREINANDO" <?php echo $sit === 'TREINANDO' ? 'selected' : ''; ?>><?php echo h(t('Treinando')); ?></option>
              <option value="DESISTIU" <?php echo $sit === 'DESISTIU' ? 'selected' : ''; ?>><?php echo h(t('Desistiu')); ?></option></select>
            <?php else: ?><?php echo h($sit === 'DESISTIU' ? t('Desistiu') : t('Treinando')); ?><?php endif; ?></td>
          <td><?php if ($podeDst): ?><select name="<?php echo h($campo); ?>[unidade]" aria-label="<?php echo h(t('Unidade de {rep}', ['rep' => $rep])); ?>">
              <option value="">—</option>
              <?php foreach ([1, 2, 3] as $u): ?><option value="<?php echo $u; ?>" <?php echo $uSel === $u ? 'selected' : ''; ?>>U<?php echo $u; ?></option><?php endforeach; ?></select>
            <?php else: ?><?php echo $uSel ? 'U' . $uSel : '—'; ?><?php endif; ?>
            <?php if (!$conf && $sg): ?><br><span class="tag amarelo small"><?php echo h(t('sugestão')); ?></span><?php elseif ($conf): ?><br><span class="tag verde small"><?php echo h(t('confirmado')); ?></span><?php endif; ?></td>
          <td><?php if ($podeDst): ?><select name="<?php echo h($campo); ?>[nucleo_id]" aria-label="<?php echo h(t('Núcleo de {rep}', ['rep' => $rep])); ?>">
              <option value="">—</option>
              <?php foreach ([1, 2, 3] as $u): foreach ($nucT[$u][$t['turno']] ?? [] as $nid => $n): ?><option value="<?php echo $nid; ?>" <?php echo $nSel === $nid ? 'selected' : ''; ?>><?php echo h(t('U{u} · núcleo {n} · {os} ({srs} SRs)', ['u' => $u, 'n' => $n['numero'], 'os' => nomeCurto($n['os']) ?: '—', 'srs' => $n['srs']])); ?></option><?php endforeach; endforeach; ?></select>
              <input type="hidden" name="<?php echo h($campo); ?>[sugestao]" value="<?php echo h($sg ? $sg['unidade'] . '|' . (int) $sg['nucleo_id'] : ($conf ? $uSel . '|' . $nSel : '')); ?>">
              <input type="hidden" name="<?php echo h($campo); ?>[motivo]" value="<?php echo h($motivo); ?>">
            <?php else: ?><?php $nn = $nucT[$uSel][$t['turno']][$nSel] ?? null; echo $nn ? h(t('núcleo {n} · {os}', ['n' => $nn['numero'], 'os' => nomeCurto($nn['os']) ?: '—'])) : '—'; ?><?php endif; ?></td>
          <td class="small muted"><?php echo h($motivo ?: '—'); ?></td>
          <td class="small"><?php if ($apl): ?><span class="tag verde"><?php echo h(t('cadastrado: U{u}', ['u' => $apl['unidade']])); ?></span>
            <?php elseif ($conf && $sit === 'TREINANDO' && $podeCadastrar && $modeloPadrao && $nSel): ?>
              <button type="submit" class="botao" form="aplicar-<?php echo h($rep); ?>" data-confirmar="<?php echo h(t('Cadastrar {rep} em Colaboradores a partir de {dia}, na U{u}, nível 0?', ['rep' => $rep, 'dia' => dataBr($t['disponivel_em'], true), 'u' => $uSel])); ?>"><?php echo h(t('Cadastrar a partir de {dia}', ['dia' => dataBr($t['disponivel_em'])])); ?></button>
            <?php else: ?><span class="muted"><?php echo h($conf ? t('falta o núcleo') : t('confirme o destino')); ?></span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if ($podeDst): ?><div class="acoes"><button type="submit" class="botao primario"><?php echo h(t('Confirmar destinos')); ?></button></div></form><?php endif; ?>
    <?php // Formulários de cadastro em Colaboradores (fora do formulário de destinos: HTML não aceita form dentro de form).
    if ($podeCadastrar && $modeloPadrao): foreach ($t['trainees'] as $rep => $tr):
        if (empty($tr['destino_em']) || empty($tr['nucleo_destino_id']) || isset($aplT[$rep]) || ($tr['situacao'] ?? '') === 'DESISTIU') { continue; } ?>
      <form method="post" action="<?php echo h(link_tela('colaboradores')); ?>" id="aplicar-<?php echo h($rep); ?>" hidden>
        <?php echo campoCsrf(); ?><input type="hidden" name="rep_code" value="<?php echo h($rep); ?>"><input type="hidden" name="modelo_id" value="<?php echo (int) $modeloPadrao; ?>">
        <input type="hidden" name="vigente_desde" value="<?php echo h($t['disponivel_em']); ?>"><input type="hidden" name="nucleo_id" value="<?php echo (int) $tr['nucleo_destino_id']; ?>">
        <input type="hidden" name="nivel" value="0"><input type="hidden" name="pode_assistente" value="1"><input type="hidden" name="observacao" value="<?php echo h(t('New Hire {turma}', ['turma' => $t['codigo']])); ?>">
      </form>
    <?php endforeach; endif; ?>
  <?php endif; ?>
  <?php if ($comDestino && $podePlanejar): ?>
  <details class="treino-incluir"><summary class="small"><?php echo h(t('Incluir trainee à mão')); ?></summary>
    <form method="post" action="<?php echo h($link(['aba' => 'destino'])); ?>#turma-<?php echo $id; ?>" class="form-grade">
      <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="incluir"><input type="hidden" name="turma_id" value="<?php echo $id; ?>">
      <label><?php echo h(t('Código')); ?><input type="text" name="rep_code" required pattern="[A-Za-z][0-9]{6}" maxlength="7" placeholder="A000000"></label>
      <label><?php echo h(t('Nome')); ?><input type="text" name="nome" maxlength="150"></label>
      <div class="acoes"><button type="submit" class="botao"><?php echo h(t('Incluir')); ?></button></div>
    </form>
  </details>
  <?php endif; ?>
</section>
<?php endforeach; ?>
<?php endif; ?>
<?php endif; ?>
<script>
(function () {
  var txt = <?php echo json_encode(['copiado' => t('Copiado'), 'contador' => t('{n} marcada(s) · {p} pedida(s)')], JSON_UNESCAPED_UNICODE); ?>;
  document.querySelectorAll('[data-copiar]').forEach(function (b) {
    b.addEventListener('click', function () {
      var el = document.querySelector(b.getAttribute('data-copiar'));
      if (!el) { return; }
      el.select();
      var ok = function () { var t = b.textContent; b.textContent = txt.copiado; setTimeout(function () { b.textContent = t; }, 1500); };
      if (navigator.clipboard) { navigator.clipboard.writeText(el.value).then(ok, function () { document.execCommand('copy'); ok(); }); } else { document.execCommand('copy'); ok(); }
    });
  });

  // Páginas: filtro (recomendadas por padrão) e busca no navegador; as marcadas sempre aparecem.
  var form = document.querySelector('.treino-paginas-form');
  if (form) {
    var linhas = Array.prototype.slice.call(form.querySelectorAll('tr[data-grupo]'));
    var vazio = form.querySelector('.treino-vazio');
    var busca = form.querySelector('[data-busca-pag]');
    var filtro = 'rec';
    var aplicar = function () {
      var q = (busca.value || '').trim().toLowerCase();
      var vistas = 0;
      linhas.forEach(function (tr) {
        var marcada = tr.querySelector('input[type="checkbox"]').checked;
        var noFiltro = filtro === 'todas' || (' ' + tr.getAttribute('data-grupo') + ' ').indexOf(' ' + filtro + ' ') >= 0;
        var naBusca = !q || tr.getAttribute('data-busca').indexOf(q) >= 0;
        var ver = (noFiltro && naBusca) || (marcada && !q);
        tr.hidden = !ver;
        if (ver) { vistas++; }
      });
      if (vazio) { vazio.hidden = vistas > 0 || !linhas.length; }
    };
    var resumo = function () {
      var marcadas = linhas.filter(function (tr) { return tr.querySelector('input[type="checkbox"]').checked; });
      var lista = form.querySelector('.treino-escolhidas-lista');
      lista.textContent = '';
      marcadas.forEach(function (tr) {
        var s = document.createElement('span');
        s.className = 'tag ' + (form.getAttribute('data-cor') || 'verde');
        s.textContent = tr.querySelector('input[type="checkbox"]').value;
        lista.appendChild(s);
        lista.appendChild(document.createTextNode(' '));
      });
      var n = marcadas.length, p = parseInt(form.getAttribute('data-pedidas'), 10) || 0;
      form.querySelectorAll('.treino-contador').forEach(function (c) {
        c.textContent = txt.contador.replace('{n}', n).replace('{p}', p);
        c.classList.toggle('laranja', n !== p);
        c.classList.toggle('muted', n === p);
      });
      var mesmo = form.querySelector('[data-mesmo-assim]');
      if (mesmo) { mesmo.hidden = !marcadas.some(function (tr) { return tr.hasAttribute('data-aviso'); }); }
    };
    form.querySelectorAll('[data-filtro-pag]').forEach(function (b) {
      b.addEventListener('click', function () {
        filtro = b.getAttribute('data-filtro-pag');
        form.querySelectorAll('[data-filtro-pag]').forEach(function (o) {
          o.classList.toggle('ativo', o === b);
          o.setAttribute('aria-pressed', o === b ? 'true' : 'false');
        });
        aplicar();
      });
    });
    busca.addEventListener('input', aplicar);
    busca.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); } });
    form.addEventListener('change', function (ev) { if (ev.target.name === 'paginas[]') { resumo(); } });
    aplicar();
    resumo();
  }

  // Ajustar: trocar o turno apaga as páginas; pede confirmação só quando o turno muda.
  document.querySelectorAll('form[data-turno-atual]').forEach(function (f) {
    var sel = f.querySelector('select[name="turno"]');
    var botao = f.querySelector('button[type="submit"]');
    sel.addEventListener('change', function () {
      if (sel.value !== f.getAttribute('data-turno-atual')) { botao.setAttribute('data-confirmar', f.getAttribute('data-aviso-turno')); } else { botao.removeAttribute('data-confirmar'); }
    });
  });
})();
</script>
