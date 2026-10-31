<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/aprovar.php
// Pedidos de indisponibilidade: fila do WFM (aprovar/recusar, por data),
// histórico de cada pessoa e lançamento em nome de alguém.
// Pedidos que vierem da Nora caem nesta mesma fila (canal NORA).
// ===============================================

if (escPost()) {
    $acao = $_POST['acao'] ?? '';
    if ($acao === 'lancar') {
        escExigir('cadastrar');
        $rep = strtoupper(trim((string) ($_POST['rep_code'] ?? '')));
        $tipo = (string) ($_POST['tipo'] ?? 'UM_DIA');
        $ini = (string) ($_POST['inicio'] ?? '');
        $fim = (string) ($_POST['fim'] ?? $ini);
        $motivo = (string) ($_POST['motivo_categoria'] ?? 'OUTRO');
        if (!preg_match('~^[A-Z]\d{6}$~', $rep) || !preg_match('~^\d{4}-\d{2}-\d{2}$~', $ini) || !in_array($tipo, ['UM_DIA', 'VARIOS_DIAS', 'RECORRENTE'], true) || !in_array($motivo, ['SAUDE', 'ESTUDO', 'PESSOAL', 'OUTRO'], true)) {
            throw new RuntimeException(t('Preencha código, tipo, data e motivo.'));
        }
        if ($tipo === 'UM_DIA' || !preg_match('~^\d{4}-\d{2}-\d{2}$~', $fim) || $fim < $ini) {
            $fim = $ini;
        }
        $maxMeses = (int) (escQ1('SELECT valor FROM ' . escT('esc_parametro') . " WHERE chave = 'indisp_recorrente_max_meses'")['valor'] ?? 3);
        if ($fim > date('Y-m-d', strtotime($ini . ' +' . $maxMeses . ' month'))) {
            throw new RuntimeException(t('Período maior que {n} meses.', ['n' => $maxMeses]));
        }
        $diasSemana = array_map('intval', (array) ($_POST['dias_semana'] ?? []));
        $blocos = array_values(array_intersect((array) ($_POST['blocos'] ?? []), ['T1', 'T2', 'T3', 'T4', 'T5', 'T6']));
        $cfg = escMotorConfig($ini)[$rep] ?? null;
        if (!$cfg) {
            throw new RuntimeException(t('{rep} não tem configuração vigente (Colaboradores).', ['rep' => $rep]));
        }
        $datas = [];
        for ($d = $ini; $d <= $fim; $d = escMaisDias($d, 1)) {
            if ($tipo === 'RECORRENTE' && !in_array((int) date('N', strtotime($d)), $diasSemana, true)) {
                continue;
            }
            $datas[] = $d;
        }
        if (!$datas) {
            throw new RuntimeException(t('Nenhuma data no período (confira os dias da semana).'));
        }
        $antecedencia = (int) (escQ1('SELECT valor FROM ' . escT('esc_parametro') . " WHERE chave = 'indisp_antecedencia_h'")['valor'] ?? 72);
        $urgente = strtotime($datas[0]) < time() + $antecedencia * 3600 ? 1 : 0;
        escTx(function () use ($rep, $tipo, $motivo, $datas, $diasSemana, $fim, $urgente, $cfg, $blocos) {
            escW('INSERT INTO ' . escT('esc_indisponibilidade') . ' (rep_code, tipo, canal, motivo_categoria, motivo_texto, recorrencia, urgente, solicitado_por) VALUES (?, ?, \'WFM\', ?, ?, ?, ?, ?)', [
                $rep, $tipo, $motivo, mb_substr(trim((string) ($_POST['motivo_texto'] ?? '')), 0, 500) ?: null,
                $tipo === 'RECORRENTE' ? json_encode(['dias_semana' => $diasSemana, 'ate' => $fim]) : null, $urgente, (string) escUserId(),
            ]);
            $id = escUltimoId();
            $linhas = array_map(static fn($d) => ['indisponibilidade_id' => $id, 'dia' => $d, 'turno' => $cfg['turno'], 'blocos' => $blocos ? implode(',', $blocos) : null, 'status' => 'PENDENTE'], $datas);
            escInserirLote('esc_indisponibilidade_data', ['indisponibilidade_id', 'dia', 'turno', 'blocos', 'status'], $linhas);
            escLog('indisp.lancar', 'esc_indisponibilidade', $id, null, ['rep' => $rep, 'datas' => $datas]);
        });
        escVoltar('aprovar', [], tn(count($datas), 'Pedido lançado: {n} data para {rep}.', 'Pedido lançado: {n} datas para {rep}.', ['rep' => $rep]));
    }
    if ($acao === 'decidir') {
        escExigir('aprovar');
        $id = (int) ($_POST['id'] ?? 0);
        $ped = escQ1('SELECT * FROM ' . escT('esc_indisponibilidade') . ' WHERE id = ?', [$id]);
        if (!$ped) {
            throw new RuntimeException(t('Pedido não encontrado.'));
        }
        if ($ped['status'] !== 'PENDENTE') {
            throw new RuntimeException(t('Este pedido já foi decidido ou cancelado. Recarregue a tela.'));
        }
        $comentario = trim((string) ($_POST['comentario'] ?? ''));
        $datas = escQ('SELECT id, status FROM ' . escT('esc_indisponibilidade_data') . ' WHERE indisponibilidade_id = ?', [$id]);
        $pendentes = array_filter($datas, static fn($d) => $d['status'] === 'PENDENTE');
        // Decisão explícita: aprovar todas, só as marcadas, ou recusar todas (nada vem marcado).
        $modo = (string) ($_POST['modo'] ?? '');
        if ($modo === 'todas') {
            $aprovadas = array_map(static fn($d) => (int) $d['id'], array_values($pendentes));
        } elseif ($modo === 'nenhuma') {
            $aprovadas = [];
        } elseif ($modo === 'marcadas') {
            $aprovadas = array_map('intval', (array) ($_POST['aprovar'] ?? []));
            if (!$aprovadas) {
                throw new RuntimeException(t('Nenhuma data marcada. Marque as datas que aprova, ou use "Recusar todas".'));
            }
        } else {
            throw new RuntimeException(t('Escolha: aprovar todas, aprovar só as marcadas ou recusar todas.'));
        }
        $recusadas = array_filter($pendentes, static fn($d) => !in_array((int) $d['id'], $aprovadas, true));
        if ($recusadas && $comentario === '') {
            throw new RuntimeException(t('Recusar pede comentário (ele vai para o colaborador). Escreva o comentário e registre de novo.'));
        }
        escTx(function () use ($id, $pendentes, $aprovadas, $comentario, $datas) {
            foreach ($pendentes as $d) {
                $ok = in_array((int) $d['id'], $aprovadas, true);
                escW('UPDATE ' . escT('esc_indisponibilidade_data') . ' SET status = ?, decidido_em = NOW(), decidido_por = ?, comentario = ? WHERE id = ?', [$ok ? 'APROVADO' : 'RECUSADO', escUserId(), $comentario ?: null, (int) $d['id']]);
            }
            $final = escQW('SELECT SUM(status = \'APROVADO\') AS ap, SUM(status = \'RECUSADO\') AS re, SUM(status = \'CANCELADO\') AS ca, COUNT(*) AS n FROM ' . escT('esc_indisponibilidade_data') . ' WHERE indisponibilidade_id = ?', [$id])[0];
            $validas = (int) $final['n'] - (int) ($final['ca'] ?? 0);
            $status = (int) $final['ap'] === $validas ? 'APROVADO' : ((int) $final['re'] === $validas ? 'RECUSADO' : 'PARCIAL');
            escW('UPDATE ' . escT('esc_indisponibilidade') . " SET status = ?, decidido_em = NOW(), decidido_por = ?, comentario_decisao = ? WHERE id = ? AND status = 'PENDENTE'", [$status, escUserId(), $comentario ?: null, $id]);
            escLog('indisp.decidir', 'esc_indisponibilidade', $id, null, ['status' => $status, 'aprovadas' => $aprovadas, 'comentario' => $comentario]);
        });
        escVoltar('aprovar', [], t('Decisão registrada. As datas aprovadas entram no próximo recálculo.'));
    }
    if ($acao === 'cancelar') {
        escExigir('aprovar');
        $id = (int) ($_POST['id'] ?? 0);
        escTx(function () use ($id) {
            escW('UPDATE ' . escT('esc_indisponibilidade_data') . " SET status = 'CANCELADO' WHERE indisponibilidade_id = ? AND status = 'PENDENTE'", [$id]);
            escW('UPDATE ' . escT('esc_indisponibilidade') . " SET status = 'CANCELADO' WHERE id = ? AND status = 'PENDENTE'", [$id]);
            escLog('indisp.cancelar', 'esc_indisponibilidade', $id);
        });
        escVoltar('aprovar', [], t('Pedido cancelado.'));
    }
    throw new RuntimeException(t('Ação desconhecida.'));
}

$status = $_GET['status'] ?? 'PENDENTE';
if (!in_array($status, ['PENDENTE', 'APROVADO', 'RECUSADO', 'PARCIAL', 'CANCELADO', 'TODOS'], true)) {
    $status = 'PENDENTE';
}
$pedidos = escQ('SELECT * FROM ' . escT('esc_indisponibilidade') . ($status === 'TODOS' ? '' : ' WHERE status = ?') . ' ORDER BY urgente DESC, solicitado_em ' . ($status === 'PENDENTE' ? 'ASC' : 'DESC') . ' LIMIT 200', $status === 'TODOS' ? [] : [$status]);
$datas = [];
$historico = [];
if ($pedidos) {
    $ids = array_map(static fn($p) => (int) $p['id'], $pedidos);
    foreach (escQ('SELECT * FROM ' . escT('esc_indisponibilidade_data') . ' WHERE indisponibilidade_id IN (' . escIn($ids) . ') ORDER BY dia', $ids) as $d) {
        $datas[(int) $d['indisponibilidade_id']][] = $d;
    }
    $reps = array_values(array_unique(array_map(static fn($p) => $p['rep_code'], $pedidos)));
    foreach (escQ('SELECT rep_code, SUM(solicitado_em >= NOW() - INTERVAL 90 DAY) AS d90, COUNT(*) AS total, SUM(tipo = \'RECORRENTE\') AS rec FROM ' . escT('esc_indisponibilidade') . ' WHERE rep_code IN (' . escIn($reps) . ') GROUP BY rep_code', $reps) as $h) {
        $historico[$h['rep_code']] = $h;
    }
}
$contagem = [];
foreach (escQ('SELECT status, COUNT(*) AS n FROM ' . escT('esc_indisponibilidade') . ' GROUP BY status') as $c) {
    $contagem[$c['status']] = (int) $c['n'];
}
$nomes = escNomes();
$estrutura = escEstrutura();
// Estrutura nova (a partir da virada): unidade · turno · núcleo e função do cadastro.
$ondeV4 = [];
$dv = max(date('Y-m-d'), escV4DiaEstrutura());
foreach (escQ('SELECT UPPER(c.rep_code) AS rep, c.funcao, c.nivel, n.unidade, n.turno, n.numero FROM ' . escT('esc_colaborador_config') . ' c JOIN ' . escT('esc_nucleo') . ' n ON n.id = c.nucleo_id WHERE c.vigente_desde <= ? AND (c.vigente_ate IS NULL OR c.vigente_ate >= ?)', [$dv, $dv]) as $x) {
    $ondeV4[$x['rep']] = t('U{u} · {turno} · núcleo {n}', ['u' => $x['unidade'], 'turno' => $x['turno'], 'n' => $x['numero']]) . ' · ' . ($x['funcao'] === 'DECURIO' ? 'OS' : 'SR ' . ($x['nivel'] ?? '?'));
}
?>
<header class="cabecalho">
  <span class="eyebrow"><?php echo h(t('Pessoas')); ?></span>
  <h1><?php echo h(t('Indisponibilidade')); ?></h1>
  <p class="muted"><?php echo h(t('Só pedido aprovado vira folga, e entra na escala no próximo recálculo. Veja quantas vezes a pessoa já pediu. Recusar pede comentário. Mais urgentes primeiro.')); ?></p>
</header>

<section class="panel">
  <div class="chips">
    <?php foreach (['PENDENTE' => t('Pendentes'), 'APROVADO' => t('Aprovados'), 'PARCIAL' => t('Parciais'), 'RECUSADO' => t('Recusados'), 'CANCELADO' => t('Cancelados'), 'TODOS' => t('Todos')] as $k => $rot): ?>
      <a class="chip-filtro <?php echo $status === $k ? 'ativo' : ''; ?>" href="<?php echo h(link_tela('aprovar', ['status' => $k])); ?>"><?php echo h($rot); ?><?php echo $k !== 'TODOS' ? ' · ' . (int) ($contagem[$k] ?? 0) : ''; ?></a>
    <?php endforeach; ?>
  </div>
  <?php foreach ($pedidos as $p): $e = $estrutura[$p['rep_code']] ?? null; $hst = $historico[$p['rep_code']] ?? null; ?>
  <div class="cartao-csv">
    <div class="linha-titulo">
      <strong><?php echo h(($nomes[$p['rep_code']] ?? $p['rep_code']) . ' · ' . $p['rep_code']); ?></strong>
      <span><?php echo (int) $p['urgente'] ? '<span class="tag vermelho">' . h(t('urgente')) . '</span> ' : ''; ?><span class="tag"><?php echo h(escRotulo('canal', $p['canal'])); ?></span> <span class="tag <?php echo ['APROVADO' => 'verde', 'PENDENTE' => 'laranja', 'RECUSADO' => 'vermelho', 'PARCIAL' => 'amarelo', 'CANCELADO' => 'cinza'][$p['status']] ?? ''; ?>"><?php echo h(escRotulo('status_pedido', $p['status'])); ?></span></span>
    </div>
    <span class="muted small"><?php echo h(isset($ondeV4[$p['rep_code']]) ? $ondeV4[$p['rep_code']] : (($e['team'] ?? t('fora da estrutura')) . ($e ? ' · ' . ($e['party'] ?? '') : ''))); ?> · <?php echo h(escRotulo('tipo_pedido', $p['tipo'])); ?> · <?php echo h(t('motivo: {motivo}', ['motivo' => escRotulo('motivo', $p['motivo_categoria'])])); ?><?php echo $p['motivo_texto'] ? ' — ' . h($p['motivo_texto']) : ''; ?> · <?php echo h(t('pedido em {quando}', ['quando' => escDataHora($p['solicitado_em'])])); ?></span>
    <span class="small"><?php echo t('Pedidos desta pessoa: {d90} em 90 dias · {total} no total · {rec} recorrente(s)', ['d90' => '<strong>' . (int) ($hst['d90'] ?? 0) . '</strong>', 'total' => '<strong>' . (int) ($hst['total'] ?? 0) . '</strong>', 'rec' => (int) ($hst['rec'] ?? 0)]); ?></span>
    <?php if ($p['status'] === 'PENDENTE' && escPode('aprovar')): ?>
    <?php $pendDatas = array_values(array_filter($datas[(int) $p['id']] ?? [], static fn($d) => $d['status'] === 'PENDENTE')); ?>
    <form method="post" action="<?php echo h(link_tela('aprovar', ['status' => $status])); ?>" class="form-grade form-decisao">
      <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="decidir"><input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>">
      <fieldset class="dias-folga"><legend><?php echo h(t('Datas pedidas (marque só se for aprovar parte delas)')); ?></legend>
        <?php foreach ($datas[(int) $p['id']] ?? [] as $d): ?>
          <label class="small"><input type="checkbox" name="aprovar[]" value="<?php echo (int) $d['id']; ?>" <?php echo $d['status'] === 'PENDENTE' ? '' : 'disabled'; ?>> <?php echo h(dataBr($d['dia'], true)); ?> · <?php echo h($d['turno']); ?><?php echo $d['blocos'] ? ' (' . h($d['blocos']) . ')' : ''; ?></label>
        <?php endforeach; ?>
      </fieldset>
      <label><?php echo h(t('Comentário (obrigatório se recusar alguma data; vai para o colaborador)')); ?><input type="text" name="comentario"></label>
      <div class="acoes">
        <button type="submit" name="modo" value="todas" class="botao primario" data-confirmar="<?php echo h(tn(count($pendDatas), 'Aprovar a {n} data de {nome}?', 'Aprovar as {n} datas de {nome}?', ['nome' => nomeCurto($nomes[$p['rep_code']] ?? $p['rep_code'])])); ?>"><?php echo h(t('Aprovar todas ({n})', ['n' => count($pendDatas)])); ?></button>
        <button type="submit" name="modo" value="marcadas" class="botao"><?php echo h(t('Aprovar só as marcadas')); ?></button>
        <button type="submit" name="modo" value="nenhuma" class="botao perigo" data-confirmar="<?php echo h(tn(count($pendDatas), 'Recusar a {n} data de {nome}? O comentário vai para o colaborador.', 'Recusar as {n} datas de {nome}? O comentário vai para o colaborador.', ['nome' => nomeCurto($nomes[$p['rep_code']] ?? $p['rep_code'])])); ?>"><?php echo h(t('Recusar todas')); ?></button>
        <span class="muted small resumo-decisao" aria-live="polite"></span>
      </div>
    </form>
    <form method="post" action="<?php echo h(link_tela('aprovar', ['status' => $status])); ?>" class="acoes cancelar-pedido"><?php echo campoCsrf(); ?><input type="hidden" name="acao" value="cancelar"><input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>"><span class="muted small"><?php echo h(t('Pedido lançado por engano ou retirado pelo colaborador?')); ?></span><button type="submit" class="botao perigo" data-confirmar="<?php echo h(t('Cancelar o pedido inteiro? Ele sai da lista de pendentes e não vira folga. Não dá para desfazer.')); ?>"><?php echo h(t('Cancelar pedido')); ?></button></form>
    <?php else: ?>
      <span class="small"><?php foreach ($datas[(int) $p['id']] ?? [] as $d): ?><span class="tag <?php echo $d['status'] === 'APROVADO' ? 'verde' : ($d['status'] === 'RECUSADO' ? 'vermelho' : ''); ?>"><?php echo h(dataBr($d['dia']) . ' ' . $d['turno'] . ' · ' . escRotulo('status_pedido', $d['status'])); ?></span> <?php endforeach; ?></span>
      <?php if ($p['comentario_decisao']): ?><span class="muted small"><?php echo h(t('Comentário: {texto}', ['texto' => $p['comentario_decisao']])); ?></span><?php endif; ?>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php if (!$pedidos): ?><p class="muted"><?php echo h(t('Nenhum pedido neste filtro.')); ?></p><?php endif; ?>
</section>

<script>
document.addEventListener('change', function (ev) {
  var f = ev.target.closest('.form-decisao');
  if (!f || ev.target.name !== 'aprovar[]') { return; }
  var todas = f.querySelectorAll('input[name="aprovar[]"]:not(:disabled)'), marcadas = f.querySelectorAll('input[name="aprovar[]"]:checked').length;
  f.querySelector('.resumo-decisao').textContent = marcadas ? <?php echo json_encode(t('"Aprovar só as marcadas": {ap} aprovada(s) · {re} recusada(s)')); ?>.replace('{ap}', marcadas).replace('{re}', todas.length - marcadas) : '';
});
</script>

<?php if (escPode('cadastrar')): ?>
<section class="panel">
  <h2><?php echo h(t('Lançar pedido em nome de alguém')); ?></h2>
  <form method="post" action="<?php echo h(link_tela('aprovar')); ?>" class="form-grade">
    <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="lancar">
    <label><?php echo h(t('Colaborador')); ?><input type="search" name="rep_code" list="lista-colaboradores" autocomplete="off" placeholder="<?php echo h(t('buscar nome ou código')); ?>" required></label>
    <label><?php echo h(t('Tipo')); ?><select name="tipo"><option value="UM_DIA"><?php echo h(t('Um dia')); ?></option><option value="VARIOS_DIAS"><?php echo h(t('Vários dias')); ?></option><option value="RECORRENTE"><?php echo h(t('Recorrente')); ?></option></select></label>
    <label><?php echo h(t('De')); ?><input type="date" name="inicio" required></label>
    <label><?php echo h(t('Até (vários dias / recorrente)')); ?><input type="date" name="fim"></label>
    <fieldset class="dias-folga"><legend><?php echo h(t('Dias da semana (recorrente)')); ?></legend>
      <?php foreach ([1, 2, 3, 4, 5, 6, 0] as $i => $w): ?><label class="small"><input type="checkbox" name="dias_semana[]" value="<?php echo $i + 1; ?>"> <?php echo h(escDiaSemanaCurto($w)); ?></label><?php endforeach; ?>
    </fieldset>
    <fieldset class="dias-folga"><legend><?php echo h(t('Só alguns blocos (vazio = turno todo)')); ?></legend>
      <?php foreach (['T1', 'T2', 'T3', 'T4', 'T5', 'T6'] as $b): ?><label class="small"><input type="checkbox" name="blocos[]" value="<?php echo $b; ?>"> <?php echo $b; ?></label><?php endforeach; ?>
    </fieldset>
    <label><?php echo h(t('Motivo')); ?><select name="motivo_categoria"><option value="SAUDE"><?php echo h(t('Saúde')); ?></option><option value="ESTUDO"><?php echo h(t('Estudo / prova')); ?></option><option value="PESSOAL"><?php echo h(t('Pessoal')); ?></option><option value="OUTRO"><?php echo h(t('Outro')); ?></option></select></label>
    <label><?php echo h(t('Detalhe')); ?><input type="text" name="motivo_texto"></label>
    <div class="acoes"><button type="submit" class="botao primario"><?php echo h(t('Lançar pedido')); ?></button></div>
  </form>
  <p class="muted small"><?php echo h(t('O turno vem da configuração da pessoa. Com menos de 72 h, o pedido entra como urgente. Recorrente: até 3 meses.')); ?></p>
</section>
<?php endif; ?>
