<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/treinamento.php
// Treinamento de New Hires (lib/treinamento.php, ES_36): colar o e-mail do Treinamento (e a
// lista de contratados), conferir o que foi lido, escolher as páginas emprestadas (o sistema
// recomenda), confirmar a turma e copiar a resposta. Turma confirmada entra no plano na página
// 5C Schedule com -4; trainee fica fora da escala normal até o dia em que fica disponível.
// ===============================================

if (!escTrnInstalado()) {
    ?>
<header class="cabecalho"><span class="eyebrow"><?php echo h(t('Quadro e contratação')); ?></span><h1><?php echo h(t('Turmas de treinamento')); ?></h1></header>
<section class="panel"><p class="aviso erro" role="alert"><?php echo h(t('A tela de treinamento ainda não foi instalada no banco (script ES_36). Peça ao admin.')); ?></p></section>
    <?php
    return;
}

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
        escVoltar('treinamento', [], t('{n} turma(s) lida(s). Confira os dados e os alertas, escolha as páginas e confirme cada turma.', ['n' => count($ids)]));
    }
    $id = (int) ($_POST['turma_id'] ?? 0);
    if ($acao === 'confirmar') {
        escTrnConfirmar($id, (array) ($_POST['paginas'] ?? []), !empty($_POST['mesmo_assim']));
        $turmaOk = escTrnTurmas(['ATIVA'])[$id] ?? null;
        $repetidas = $turmaOk ? array_intersect_key($turmaOk['paginas'], escTrnOcupadas($turmaOk)) : [];
        escVoltar('treinamento', [], t('Turma confirmada. Recalcule as semanas do treinamento para os trainees entrarem na 5C Schedule com -4.')
            . ($repetidas ? ' ' . t('Atenção: {paginas} também está em treinamento em outro turno.', ['paginas' => implode(', ', array_keys($repetidas))]) : ''));
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
        escVoltar('treinamento', [], tn($n, 'Destino de {n} trainee salvo. Na data disponível, cadastre em Colaboradores (botão em cada linha).', 'Destino de {n} trainees salvo. Na data disponível, cadastre em Colaboradores (botão em cada linha).'), false, 'turma-' . $id);
    }
    if ($acao === 'incluir') {
        $ativas = escTrnTurmas(['RASCUNHO', 'ATIVA']);
        if (!isset($ativas[$id])) {
            throw new RuntimeException(t('Turma não encontrada.'));
        }
        $rep = strtoupper(trim((string) ($_POST['rep_code'] ?? '')));
        escDstIncluir($id, $rep, mb_substr(trim((string) ($_POST['nome'] ?? '')), 0, 150));
        escVoltar('treinamento', [], t('{rep} incluído na turma.', ['rep' => $rep]), false, 'turma-' . $id);
    }
    if ($acao === 'cancelar') {
        escTrnCancelar($id);
        escVoltar('treinamento', [], t('Turma cancelada: sai do plano no próximo recálculo.'));
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
        escVoltar('treinamento', [], t('Turma ajustada.'));
    }
    throw new RuntimeException(t('Não deu para entender o pedido. Recarregue a tela e tente de novo.'));
}

$podePlanejar = escPode('planejar');
$verAntigas = !empty($_GET['antigas']);
$turmas = escTrnTurmas(['RASCUNHO', 'ATIVA'], $verAntigas ? '2000-01-01' : date('Y-m-d'), '2999-12-31');
$sug = $turmas ? escTrnSugerir($turmas) : [];
$nomes = escNomes();
$en = escEmIngles();
$bl = ['A' => 'T2/T3', 'C' => 'T4/T5', 'D' => 'T6/T1'];
$ciclos = [];
foreach ($turmas as $id => $t) {
    $ciclos[$t['ciclo'] ?: $t['inicio']][$id] = $t;
}
$rotStatus = ['RASCUNHO' => [t('rascunho'), 'amarelo'], 'ATIVA' => [t('confirmada'), 'verde'], 'CANCELADA' => [t('cancelada'), 'cinza']];
// Destino dos novatos (ES_137): sugestão por turma, núcleos do turno e quem já está em Colaboradores.
$comDestino = escDstInstalado();
$podeCadastrar = escPode('cadastrar');
$dstSug = [];
$dstNucleos = [];
$dstAplic = [];
if ($comDestino && $turmas) {
    $modeloPadrao = (int) (escQ1('SELECT modelo_id, COUNT(*) AS n FROM ' . escT('esc_colaborador_config') . " WHERE funcao = 'EQUITE' AND vigente_ate IS NULL GROUP BY modelo_id ORDER BY n DESC LIMIT 1")['modelo_id'] ?? 0);
    foreach ($turmas as $id => $tm) {
        $dstSug[$id] = escDstSugerir($tm, escDstDesignados($turmas, $id));
        $dstNucleos[$id] = escDstNucleos((string) $tm['disponivel_em']);
        $dstAplic[$id] = escDstAplicados(array_keys($tm['trainees']), (string) $tm['disponivel_em']);
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

<section class="panel">
  <details>
    <summary><strong><?php echo h(t('Como o sistema trata os New Hires')); ?></strong></summary>
    <ul class="small">
      <li><?php echo h(t('O motor só escala quem tem cadastro de SR com núcleo em Colaboradores. New Hire não tem cadastro: não entra na escala normal.')); ?></li>
      <li><?php echo h(t('Trava a mais: quem está numa turma (rascunho ou confirmada) fica fora da escala normal até o dia "disponível para operação", mesmo que alguém cadastre a pessoa num núcleo antes.')); ?></li>
      <li><?php echo h(t('Com a turma confirmada, os trainees vão para a página 5C Schedule com -4, de segunda a sexta, no horário da turma (clock-in/out), como o Overlap (-5).')); ?></li>
      <li><?php echo h(t('Depois do treinamento (em geral 14 dias), cadastre cada um em Colaboradores com início no dia disponível, no núcleo onde vai ficar. A partir daí segue a escala normal.')); ?></li>
      <li><?php echo h(t('Primeiro as páginas com lacuna no turno nos dias da turma: melhor treinar do que deixar vazia. Depois as menores de posto com 2 ou mais páginas e, por fim, todas as outras do turno.')); ?></li>
      <li><?php echo h(t('Página confirmada fica com o treinamento nos dias e no horário da turma: o gerador não escala ninguém da escala normal nela. Se o posto só tinha essa página, o SR vai para outra página com lacuna no mesmo horário (ou fica de buffer); se o posto tem outras páginas, o SR fica com elas. Por isso, confirme o treinamento antes de gerar a escala.')); ?></li>
      <li><?php echo h(t('Página emprestada: a menor de um posto com 2 ou mais páginas, sem OFTV (final OFTV ou número com t, ex.: 141t), sem mês abaixo de {min} e sem repetir entre turnos ao mesmo tempo. O dono do turno continua dono e com a comissão; nas pausas ele assume.', ['min' => pct((float) str_replace(',', '.', escV4Param('treinamento_ating_min', '0.85')), 0)])); ?></li>
    </ul>
  </details>
</section>

<?php if ($podePlanejar): ?>
<section class="panel">
  <h2><?php echo h(t('Colar o e-mail')); ?></h2>
  <form method="post" action="<?php echo h(link_tela('treinamento')); ?>" class="treino-email">
    <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="ler">
    <label class="largo"><?php echo h(t('E-mail do Treinamento (turmas, horário, datas, quadro e lista do WFM)')); ?>
      <textarea name="email" rows="10" required placeholder="Turma: NH45A2026&#10;HC: 04&#10;Instrutor: …&#10;Horário: 05h - 13h …"><?php echo h((string) ($repost['email'] ?? '')); ?></textarea></label>
    <label class="largo"><?php echo h(t('Lista de contratados do RH (opcional: nome, nome social e código)')); ?>
      <textarea name="contratados" rows="4" placeholder="NAME	SOCIAL NAME	CODE (site)"><?php echo h((string) ($repost['contratados'] ?? '')); ?></textarea></label>
    <div class="acoes"><button type="submit" class="botao primario"><?php echo h(t('Ler e-mail')); ?></button></div>
  </form>
</section>
<?php endif; ?>

<?php if (!$turmas): ?>
<section class="panel"><p class="muted"><?php echo h(t('Nenhuma turma em andamento.')); ?> <?php if (!$verAntigas): ?><a href="<?php echo h(link_tela('treinamento', ['antigas' => 1])); ?>"><?php echo h(t('Ver turmas antigas')); ?></a><?php endif; ?></p></section>
<?php endif; ?>

<?php foreach ($ciclos as $ciclo => $doCiclo):
    $ini = min(array_column($doCiclo, 'inicio'));
    $fim = max(array_column($doCiclo, 'fim'));
    $semanas = [];
    for ($s = segundaDaSemana($ini); $s <= $fim; $s = escMaisDias($s, 7)) {
        $semanas[] = $s;
    }
    $resposta = escTrnResposta($doCiclo, $en);
    // Alertas da turma ("NH45A2026: ...") ficam na turma; os gerais (lista de contratados,
    // cadastro, histórico) aparecem uma vez no ciclo.
    $gerais = [];
    foreach ($doCiclo as $t) {
        foreach ($t['alertas'] as $a) {
            if (strncmp($a, $t['codigo'] . ':', strlen($t['codigo'] . ':')) !== 0) {
                $gerais[$a] = true;
            }
        }
    }
?>
<section class="panel treino-ciclo">
  <h2><?php echo h(t('Ciclo {ciclo}', ['ciclo' => $ciclo])); ?> <span class="muted small"><?php echo h(dataBr($ini) . ' – ' . dataBr($fim)); ?></span></h2>

  <?php if ($gerais): ?>
  <ul class="aviso alerta small" role="status"><?php foreach (array_keys($gerais) as $a): ?><li><?php echo h($a); ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
  <h3><?php echo h(t('Páginas por semana')); ?></h3>
  <div class="tabela-rolagem"><table>
    <thead><tr><th><?php echo h(t('Semana')); ?></th><?php foreach ($doCiclo as $t): ?><th><?php echo h($t['codigo']); ?> <?php echo chipTurno($t['turno']); ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($semanas as $i => $s): ?>
      <tr><td><strong><?php echo h(t('Período {n}', ['n' => ['I', 'II', 'III', 'IV'][$i] ?? (string) ($i + 1)])); ?></strong><br><span class="small muted"><?php echo h(dataBr(max($s, $ini)) . ' – ' . dataBr(min(escMaisDias($s, 4), $fim))); ?></span></td>
      <?php foreach ($doCiclo as $id => $t): $esc = $sug[$id]['escolha'] ?? []; $nest = $t['nesting_de'] && $t['nesting_de'] <= escMaisDias($s, 4) && ($t['nesting_ate'] ?? $t['nesting_de']) >= $s; ?>
        <td class="small"><?php if (!$esc): ?><span class="laranja"><?php echo h(t('sem página')); ?></span><?php endif; ?>
          <?php foreach ($esc as $p): $c = $sug[$id]['candidatas'][$p] ?? null; ?><span class="tag <?php echo $t['paginas'] ? 'verde' : 'amarelo'; ?>"><?php echo h($p); ?></span><?php if ($c && !$c['ok']): ?> <span class="vermelho" title="<?php echo h((string) $c['motivo']); ?>">⚠</span><?php endif; ?><br><?php endforeach; ?>
          <?php if ($nest): ?><span class="muted"><?php echo h(t('com nesting')); ?></span><?php endif; ?></td>
      <?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="muted small"><?php echo h(t('As mesmas páginas valem o ciclo inteiro. Amarelo = sugestão ainda não confirmada; ⚠ = a página deixou de atender a regra (confira antes da próxima semana).')); ?></p>

  <?php foreach ($doCiclo as $id => $t): [$stRot, $stCls] = $rotStatus[$t['status']]; $s = $sug[$id] ?? ['candidatas' => [], 'escolha' => []];
      $t['alertas'] = array_values(array_filter($t['alertas'], static fn($a) => strncmp($a, $t['codigo'] . ':', strlen($t['codigo'] . ':')) === 0)); ?>
  <article class="treino-turma" id="turma-<?php echo $id; ?>">
    <header class="treino-turma-cab">
      <h3><?php echo h($t['codigo']); ?> <?php echo chipTurno($t['turno']); ?> <span class="tag <?php echo $stCls; ?>"><?php echo h($stRot); ?></span></h3>
      <p class="small"><?php echo h(t('{horario} ({blocos}) · instrutor(a) {instrutor} · {n} trainees (HC {hc})', ['horario' => $t['horario'] ?: '—', 'blocos' => $bl[$t['turno']], 'instrutor' => $t['instrutor'] ?: '—', 'n' => count($t['trainees']), 'hc' => $t['hc'] ?? '—'])); ?><br>
        <?php echo h(t('Treinamento {ini} a {fim} (seg a sex){nesting} · disponível para a operação em {disp}', ['ini' => dataBr($t['inicio'], true), 'fim' => dataBr($t['fim'], true),
            'nesting' => $t['nesting_de'] ? ' · ' . t('nesting {de} a {ate}', ['de' => dataBr($t['nesting_de']), 'ate' => dataBr($t['nesting_ate'] ?? $t['nesting_de'])]) : '', 'disp' => dataBr($t['disponivel_em'], true)])); ?></p>
    </header>
    <?php if ($t['alertas']): ?>
    <ul class="aviso alerta small" role="status"><?php foreach ($t['alertas'] as $a): ?><li><?php echo h($a); ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <details>
      <summary><?php echo h(t('Trainees ({n})', ['n' => count($t['trainees'])])); ?></summary>
      <ul class="treino-trainees small"><?php foreach ($t['trainees'] as $rep => $tr): ?><li><strong><?php echo h($tr['nome_social'] ?: ($tr['nome'] ?: $rep)); ?></strong> <span class="mono muted"><?php echo h($rep); ?></span><?php if ($tr['nome_social'] && $tr['nome']): ?> <span class="muted">(<?php echo h(t('nome de registro: {n}', ['n' => $tr['nome']])); ?>)</span><?php endif; ?></li><?php endforeach; ?></ul>
    </details>

    <?php if ($comDestino && $t['trainees']): $sugT = $dstSug[$id] ?? []; $nucT = $dstNucleos[$id] ?? []; $aplT = $dstAplic[$id] ?? []; $podeDst = $podePlanejar; ?>
    <div class="treino-destino">
      <h4><?php echo h(t('Destino depois do treinamento')); ?></h4>
      <p class="muted small"><?php echo h(t('Sugestão do sistema: a unidade com mais lacunas no turno {t} na escala da semana em que a turma fica disponível ({disp}); no empate, a com mais falta no Capacity. Dentro da unidade, o núcleo com menos SRs para a parte dele. Confirme ou troque; o cadastro em Colaboradores (a partir de {disp}) é o que põe a pessoa na escala.', ['t' => $t['turno'], 'disp' => dataBr($t['disponivel_em'], true)])); ?></p>
      <?php if ($podeDst): ?><form method="post" action="<?php echo h(link_tela('treinamento')); ?>#turma-<?php echo $id; ?>"><?php echo campoCsrf(); ?><input type="hidden" name="acao" value="destino"><input type="hidden" name="turma_id" value="<?php echo $id; ?>"><?php endif; ?>
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
            <td><strong><?php echo h($tr['nome_social'] ?: ($tr['nome'] ?: $rep)); ?></strong><br><span class="mono muted small"><?php echo h($rep); ?></span></td>
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
      if ($podeCadastrar && !empty($modeloPadrao)): foreach ($t['trainees'] as $rep => $tr):
          if (empty($tr['destino_em']) || empty($tr['nucleo_destino_id']) || isset($aplT[$rep]) || ($tr['situacao'] ?? '') === 'DESISTIU') { continue; } ?>
        <form method="post" action="<?php echo h(link_tela('colaboradores')); ?>" id="aplicar-<?php echo h($rep); ?>" hidden>
          <?php echo campoCsrf(); ?><input type="hidden" name="rep_code" value="<?php echo h($rep); ?>"><input type="hidden" name="modelo_id" value="<?php echo (int) $modeloPadrao; ?>">
          <input type="hidden" name="vigente_desde" value="<?php echo h($t['disponivel_em']); ?>"><input type="hidden" name="nucleo_id" value="<?php echo (int) $tr['nucleo_destino_id']; ?>">
          <input type="hidden" name="nivel" value="0"><input type="hidden" name="pode_assistente" value="1"><input type="hidden" name="observacao" value="<?php echo h(t('New Hire {turma}', ['turma' => $t['codigo']])); ?>">
        </form>
      <?php endforeach; endif; ?>
      <?php if ($podeDst): ?>
      <details class="treino-incluir"><summary class="small"><?php echo h(t('Incluir trainee à mão')); ?></summary>
        <form method="post" action="<?php echo h(link_tela('treinamento')); ?>#turma-<?php echo $id; ?>" class="form-grade">
          <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="incluir"><input type="hidden" name="turma_id" value="<?php echo $id; ?>">
          <label><?php echo h(t('Código')); ?><input type="text" name="rep_code" required pattern="[A-Za-z][0-9]{6}" maxlength="7" placeholder="A000000"></label>
          <label><?php echo h(t('Nome')); ?><input type="text" name="nome" maxlength="150"></label>
          <div class="acoes"><button type="submit" class="botao"><?php echo h(t('Incluir')); ?></button></div>
        </form>
      </details>
      <?php endif; ?>
    </div>
    <?php elseif (!$comDestino && $t['trainees']): ?>
    <p class="muted small"><?php echo h(t('O destino dos novatos ainda não foi instalado no banco (script ES_137). Peça ao admin.')); ?></p>
    <?php endif; ?>

    <?php if ($podePlanejar): ?>
    <form method="post" action="<?php echo h(link_tela('treinamento')); ?>#turma-<?php echo $id; ?>">
      <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="confirmar"><input type="hidden" name="turma_id" value="<?php echo $id; ?>">
      <p class="small"><?php echo h(t('Páginas para o treinamento: {n} pedida(s). Marque e confirme. Confirme antes de gerar ou recalcular a semana: a página de lacuna sai do preenchimento da escala nos dias da turma.', ['n' => (int) $t['paginas_necessarias']])); ?></p>
      <?php $nLac = count(array_filter($s['candidatas'], static fn($c) => $c['modo'] === 'LACUNA')); ?>
      <p class="muted small"><?php echo h($nLac ? tn($nLac, '{n} página com lacuna no turno nos dias da turma (vem primeiro: melhor treinar do que deixar vazia).', '{n} páginas com lacuna no turno nos dias da turma (vêm primeiro: melhor treinar do que deixar vazia).') : t('Nenhuma lacuna no turno nos dias da turma na versão atual das semanas (gere ou recalcule a semana para ver as lacunas).')); ?> <?php echo h(t('Todas as possibilidades aparecem; em cinza, as que não podem e o motivo.')); ?></p>
      <div class="tabela-rolagem"><table class="treino-paginas">
        <thead><tr><th aria-label="<?php echo h(t('Ações')); ?>"></th><th><?php echo h(t('Página')); ?></th><th><?php echo h(t('Tipo')); ?></th><th class="num"><?php echo h(t('Fãs/h')); ?></th><th><?php echo h(t('Dono do turno')); ?></th><th class="num"><?php echo h(t('Mês')); ?></th><th class="num"><?php echo h(t('Turno')); ?></th><th><?php echo h(t('Situação')); ?></th></tr></thead>
        <tbody>
        <?php foreach ($s['candidatas'] as $nome => $c): $marcada = in_array($nome, $s['escolha'], true); ?>
          <tr class="<?php echo $c['ok'] ? '' : 'muted'; ?>">
            <td><input type="checkbox" name="paginas[]" value="<?php echo h($nome); ?>" <?php echo $marcada ? 'checked' : ''; ?> <?php echo escTrnOftv($nome) ? 'disabled' : ''; ?> aria-label="<?php echo h($nome); ?>"></td>
            <td><strong><?php echo h($nome); ?></strong><br><span class="small muted">U<?php echo (int) $c['unidade']; ?></span></td>
            <td class="small"><?php if ($c['modo'] === 'LACUNA'): ?><span class="tag laranja"><?php echo h(t('lacuna')); ?></span><br><span class="muted"><?php echo h(tn((int) $c['lacunas'], '{n} bloco vazio', '{n} blocos vazios') . ' · ' . implode(', ', $c['semanas'])); ?></span><?php elseif ($c['modo'] === 'EMPRESTADA'): ?><span class="tag"><?php echo h(t('emprestada')); ?></span><br><span class="muted"><?php echo h(t('dono fica com {p}', ['p' => $c['fica_com']])); ?></span><?php else: ?><span class="tag"><?php echo h(t('outra')); ?></span><br><span class="muted"><?php echo h($c['fica_com'] ? t('maior página do posto (o SR fica com {p})', ['p' => $c['fica_com']]) : t('posto de uma página: o SR vai para uma página com lacuna no mesmo horário')); ?></span><?php endif; ?></td>
            <td class="num"><?php echo num($c['fans_h'], 1); ?></td>
            <td class="small"><?php echo $c['owner'] ? h(nomeCurto($nomes[$c['owner']] ?? $c['owner'])) . ' <span class="mono muted">' . h($c['owner']) . '</span>' : '—'; ?><?php if ($c['modo'] === 'LACUNA'): ?><br><span class="muted"><?php echo h(t('o gerador põe a escala normal em outras páginas')); ?></span><?php endif; ?></td>
            <td class="num"><?php echo $c['ating'] !== null ? pct($c['ating'], 0) : '—'; ?></td>
            <td class="num"><?php echo $c['ating_turno'] !== null ? pct($c['ating_turno'], 0) : '—'; ?></td>
            <td class="small"><?php echo $c['ok'] ? ($c['aviso'] ? '<span class="tag amarelo">⚠ ' . h($c['aviso']) . '</span>' : ($c['sem_dados'] ? '<span class="tag">' . h(t('sem dados de meta')) . '</span>' : '<span class="tag verde">' . h(t('pode')) . '</span>')) : '<span class="tag laranja">' . h((string) $c['motivo']) . '</span>'; ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$s['candidatas']): ?><tr><td colspan="8" class="muted"><?php echo h(t('Nenhuma lacuna nem posto com 2 ou mais páginas no turno {t} na data de início.', ['t' => $bl[$t['turno']]])); ?></td></tr><?php endif; ?>
        </tbody>
      </table></div>
      <div class="acoes">
        <?php if (array_filter($s['candidatas'], static fn($c) => $c['aviso'] !== null)): ?>
        <label class="small"><input type="checkbox" name="mesmo_assim" value="1"> <?php echo h(t('usar mesmo assim página que já está em outro turno (fica em treinamento em mais de um turno)')); ?></label>
        <?php endif; ?>
        <button type="submit" class="botao primario"><?php echo h($t['status'] === 'ATIVA' ? t('Salvar páginas') : t('Confirmar turma')); ?></button>
      </div>
    </form>
    <div class="acoes">
      <details class="treino-ajustar"><summary class="botao"><?php echo h(t('Ajustar datas e turno')); ?></summary>
        <form method="post" action="<?php echo h(link_tela('treinamento')); ?>#turma-<?php echo $id; ?>" class="form-grade">
          <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="ajustar"><input type="hidden" name="turma_id" value="<?php echo $id; ?>">
          <label><?php echo h(t('Turno')); ?><select name="turno"><?php foreach ($bl as $tt => $b): ?><option value="<?php echo $tt; ?>" <?php echo $tt === $t['turno'] ? 'selected' : ''; ?>><?php echo $tt . ' · ' . $b; ?></option><?php endforeach; ?></select></label>
          <label><?php echo h(t('Início')); ?><input type="date" name="inicio" value="<?php echo h($t['inicio']); ?>"></label>
          <label><?php echo h(t('Último dia (sexta)')); ?><input type="date" name="fim" value="<?php echo h($t['fim']); ?>"></label>
          <label><?php echo h(t('Disponível para a operação')); ?><input type="date" name="disponivel_em" value="<?php echo h($t['disponivel_em']); ?>"></label>
          <label><?php echo h(t('Páginas')); ?><input type="number" name="paginas_necessarias" min="1" max="9" value="<?php echo (int) $t['paginas_necessarias']; ?>"></label>
          <div class="acoes"><button type="submit" class="botao"><?php echo h(t('Salvar')); ?></button></div>
        </form>
      </details>
      <form method="post" action="<?php echo h(link_tela('treinamento')); ?>">
        <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="cancelar"><input type="hidden" name="turma_id" value="<?php echo $id; ?>">
        <button type="submit" class="botao perigo" data-confirmar="<?php echo h(t('Cancelar a turma {turma}? Os trainees saem da 5C Schedule no próximo recálculo.', ['turma' => $t['codigo']])); ?>"><?php echo h(t('Cancelar turma')); ?></button>
      </form>
    </div>
    <?php endif; ?>
  </article>
  <?php endforeach; ?>

  <h3><?php echo h(t('Resposta ao e-mail')); ?></h3>
  <textarea class="treino-resposta" rows="16" readonly id="resposta-<?php echo h(md5((string) $ciclo)); ?>"><?php echo h($resposta); ?></textarea>
  <div class="acoes"><button type="button" class="botao" data-copiar="#resposta-<?php echo h(md5((string) $ciclo)); ?>"><?php echo h(t('Copiar resposta')); ?></button>
    <span class="muted small"><?php echo h(t('Depois de confirmar as turmas, recalcule as semanas {semanas} na Escala para os trainees entrarem na 5C Schedule com -4.', ['semanas' => implode(', ', array_map(static fn($s) => dataBr($s), $semanas))])); ?></span></div>
</section>
<?php endforeach; ?>
<script>
document.querySelectorAll('[data-copiar]').forEach(function (b) {
  b.addEventListener('click', function () {
    var el = document.querySelector(b.getAttribute('data-copiar'));
    if (!el) { return; }
    el.select();
    var ok = function () { var t = b.textContent; b.textContent = <?php echo json_encode(t('Copiado'), JSON_UNESCAPED_UNICODE); ?>; setTimeout(function () { b.textContent = t; }, 1500); };
    if (navigator.clipboard) { navigator.clipboard.writeText(el.value).then(ok, function () { document.execCommand('copy'); ok(); }); } else { document.execCommand('copy'); ok(); }
  });
});
</script>
