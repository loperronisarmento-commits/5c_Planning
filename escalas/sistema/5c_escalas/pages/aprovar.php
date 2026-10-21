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
            throw new RuntimeException('Preencha código, tipo, data e motivo.');
        }
        if ($tipo === 'UM_DIA' || !preg_match('~^\d{4}-\d{2}-\d{2}$~', $fim) || $fim < $ini) {
            $fim = $ini;
        }
        $maxMeses = (int) (escQ1('SELECT valor FROM ' . escT('esc_parametro') . " WHERE chave = 'indisp_recorrente_max_meses'")['valor'] ?? 3);
        if ($fim > date('Y-m-d', strtotime($ini . ' +' . $maxMeses . ' month'))) {
            throw new RuntimeException('Período maior que ' . $maxMeses . ' meses.');
        }
        $diasSemana = array_map('intval', (array) ($_POST['dias_semana'] ?? []));
        $blocos = array_values(array_intersect((array) ($_POST['blocos'] ?? []), ['T1', 'T2', 'T3', 'T4', 'T5', 'T6']));
        $cfg = escMotorConfig($ini)[$rep] ?? null;
        if (!$cfg) {
            throw new RuntimeException($rep . ' não tem configuração vigente (Colaboradores).');
        }
        $datas = [];
        for ($d = $ini; $d <= $fim; $d = escMaisDias($d, 1)) {
            if ($tipo === 'RECORRENTE' && !in_array((int) date('N', strtotime($d)), $diasSemana, true)) {
                continue;
            }
            $datas[] = $d;
        }
        if (!$datas) {
            throw new RuntimeException('Nenhuma data no período (confira os dias da semana).');
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
        escVoltar('aprovar', [], 'Pedido lançado: ' . count($datas) . ' data(s) para ' . $rep . '.');
    }
    if ($acao === 'decidir') {
        escExigir('aprovar');
        $id = (int) ($_POST['id'] ?? 0);
        $ped = escQ1('SELECT * FROM ' . escT('esc_indisponibilidade') . ' WHERE id = ?', [$id]);
        if (!$ped) {
            throw new RuntimeException('Pedido não encontrado.');
        }
        $aprovadas = array_map('intval', (array) ($_POST['aprovar'] ?? []));
        $comentario = trim((string) ($_POST['comentario'] ?? ''));
        $datas = escQ('SELECT id, status FROM ' . escT('esc_indisponibilidade_data') . ' WHERE indisponibilidade_id = ?', [$id]);
        $pendentes = array_filter($datas, static fn($d) => $d['status'] === 'PENDENTE');
        $recusadas = array_filter($pendentes, static fn($d) => !in_array((int) $d['id'], $aprovadas, true));
        if ($recusadas && $comentario === '') {
            throw new RuntimeException('Recusar pede comentário (ele vai para o colaborador).');
        }
        escTx(function () use ($id, $pendentes, $aprovadas, $comentario, $datas) {
            foreach ($pendentes as $d) {
                $ok = in_array((int) $d['id'], $aprovadas, true);
                escW('UPDATE ' . escT('esc_indisponibilidade_data') . ' SET status = ?, decidido_em = NOW(), decidido_por = ?, comentario = ? WHERE id = ?', [$ok ? 'APROVADO' : 'RECUSADO', escUserId(), $comentario ?: null, (int) $d['id']]);
            }
            $final = escQW('SELECT SUM(status = \'APROVADO\') AS ap, SUM(status = \'RECUSADO\') AS re, COUNT(*) AS n FROM ' . escT('esc_indisponibilidade_data') . ' WHERE indisponibilidade_id = ?', [$id])[0];
            $status = (int) $final['ap'] === (int) $final['n'] ? 'APROVADO' : ((int) $final['re'] === (int) $final['n'] ? 'RECUSADO' : 'PARCIAL');
            escW('UPDATE ' . escT('esc_indisponibilidade') . ' SET status = ?, decidido_em = NOW(), decidido_por = ?, comentario_decisao = ? WHERE id = ?', [$status, escUserId(), $comentario ?: null, $id]);
            escLog('indisp.decidir', 'esc_indisponibilidade', $id, null, ['status' => $status, 'aprovadas' => $aprovadas, 'comentario' => $comentario]);
        });
        escVoltar('aprovar', [], 'Decisão registrada. As datas aprovadas entram no próximo recálculo.');
    }
    if ($acao === 'cancelar') {
        escExigir('aprovar');
        $id = (int) ($_POST['id'] ?? 0);
        escTx(function () use ($id) {
            escW('UPDATE ' . escT('esc_indisponibilidade_data') . " SET status = 'CANCELADO' WHERE indisponibilidade_id = ? AND status = 'PENDENTE'", [$id]);
            escW('UPDATE ' . escT('esc_indisponibilidade') . " SET status = 'CANCELADO' WHERE id = ? AND status = 'PENDENTE'", [$id]);
            escLog('indisp.cancelar', 'esc_indisponibilidade', $id);
        });
        escVoltar('aprovar', [], 'Pedido cancelado.');
    }
    throw new RuntimeException('Ação desconhecida.');
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
?>
<header class="cabecalho">
  <span class="eyebrow">Indisponibilidade · equipe de WFM</span>
  <h1>Pedidos e aprovação</h1>
  <p class="muted">Só pedido aprovado vira folga, e entra na escala no próximo recálculo. Veja quantas vezes a pessoa já pediu. Recusar pede comentário. Mais urgentes primeiro.</p>
</header>

<section class="panel">
  <div class="chips">
    <?php foreach (['PENDENTE' => 'Pendentes', 'APROVADO' => 'Aprovados', 'PARCIAL' => 'Parciais', 'RECUSADO' => 'Recusados', 'CANCELADO' => 'Cancelados', 'TODOS' => 'Todos'] as $k => $rot): ?>
      <a class="chip-filtro <?php echo $status === $k ? 'ativo' : ''; ?>" href="<?php echo h(link_tela('aprovar', ['status' => $k])); ?>"><?php echo h($rot); ?><?php echo $k !== 'TODOS' ? ' · ' . (int) ($contagem[$k] ?? 0) : ''; ?></a>
    <?php endforeach; ?>
  </div>
  <?php foreach ($pedidos as $p): $e = $estrutura[$p['rep_code']] ?? null; $hst = $historico[$p['rep_code']] ?? null; ?>
  <div class="cartao-csv">
    <div class="linha-titulo">
      <strong><?php echo h(($nomes[$p['rep_code']] ?? $p['rep_code']) . ' · ' . $p['rep_code']); ?></strong>
      <span><?php echo (int) $p['urgente'] ? '<span class="tag vermelho">urgente</span> ' : ''; ?><span class="tag"><?php echo h(strtolower($p['canal'])); ?></span> <span class="tag <?php echo $p['status'] === 'APROVADO' ? 'verde' : ($p['status'] === 'PENDENTE' ? 'amarelo' : ''); ?>"><?php echo h(strtolower($p['status'])); ?></span></span>
    </div>
    <span class="muted small"><?php echo h(($e['team'] ?? 'sem estrutura') . ' · ' . ($e['party'] ?? '') . ' · ' . ($e['role'] ?? '')); ?> · <?php echo h(strtolower(str_replace('_', ' ', $p['tipo']))); ?> · motivo: <?php echo h(strtolower($p['motivo_categoria'])); ?><?php echo $p['motivo_texto'] ? ' — ' . h($p['motivo_texto']) : ''; ?> · pedido em <?php echo h(date('d/m H:i', strtotime($p['solicitado_em']))); ?></span>
    <span class="small">Pedidos desta pessoa: <strong><?php echo (int) ($hst['d90'] ?? 0); ?></strong> em 90 dias · <strong><?php echo (int) ($hst['total'] ?? 0); ?></strong> no total · <?php echo (int) ($hst['rec'] ?? 0); ?> recorrente(s)</span>
    <?php if ($p['status'] === 'PENDENTE' && escPode('aprovar')): ?>
    <form method="post" action="<?php echo h(link_tela('aprovar', ['status' => $status])); ?>" class="form-grade">
      <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="decidir"><input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>">
      <fieldset class="dias-folga"><legend>Marque as datas que aprova</legend>
        <?php foreach ($datas[(int) $p['id']] ?? [] as $d): ?>
          <label class="small"><input type="checkbox" name="aprovar[]" value="<?php echo (int) $d['id']; ?>" <?php echo $d['status'] === 'PENDENTE' ? 'checked' : 'disabled'; ?>> <?php echo h(dataBr($d['dia'], true)); ?> · <?php echo h($d['turno']); ?><?php echo $d['blocos'] ? ' (' . h($d['blocos']) . ')' : ''; ?></label>
        <?php endforeach; ?>
      </fieldset>
      <label>Comentário (obrigatório se recusar alguma data)<input type="text" name="comentario"></label>
      <div class="acoes"><button type="submit" class="botao primario">Registrar decisão</button></div>
    </form>
    <form method="post" action="<?php echo h(link_tela('aprovar', ['status' => $status])); ?>"><?php echo campoCsrf(); ?><input type="hidden" name="acao" value="cancelar"><input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>"><button type="submit" class="botao">Cancelar pedido</button></form>
    <?php else: ?>
      <span class="small"><?php foreach ($datas[(int) $p['id']] ?? [] as $d): ?><span class="tag <?php echo $d['status'] === 'APROVADO' ? 'verde' : ($d['status'] === 'RECUSADO' ? 'vermelho' : ''); ?>"><?php echo h(dataBr($d['dia']) . ' ' . $d['turno'] . ' · ' . strtolower($d['status'])); ?></span> <?php endforeach; ?></span>
      <?php if ($p['comentario_decisao']): ?><span class="muted small">Comentário: <?php echo h($p['comentario_decisao']); ?></span><?php endif; ?>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php if (!$pedidos): ?><p class="muted">Nenhum pedido neste filtro.</p><?php endif; ?>
</section>

<?php if (escPode('cadastrar')): ?>
<section class="panel">
  <h2>Lançar pedido em nome de alguém</h2>
  <form method="post" action="<?php echo h(link_tela('aprovar')); ?>" class="form-grade">
    <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="lancar">
    <label>Código do colaborador<input type="text" name="rep_code" placeholder="ex.: M443425" required></label>
    <label>Tipo<select name="tipo"><option value="UM_DIA">Um dia</option><option value="VARIOS_DIAS">Vários dias</option><option value="RECORRENTE">Recorrente</option></select></label>
    <label>De<input type="date" name="inicio" required></label>
    <label>Até (vários dias / recorrente)<input type="date" name="fim"></label>
    <fieldset class="dias-folga"><legend>Dias da semana (recorrente)</legend>
      <?php foreach (['seg', 'ter', 'qua', 'qui', 'sex', 'sáb', 'dom'] as $i => $d): ?><label class="small"><input type="checkbox" name="dias_semana[]" value="<?php echo $i + 1; ?>"> <?php echo $d; ?></label><?php endforeach; ?>
    </fieldset>
    <fieldset class="dias-folga"><legend>Só alguns blocos (vazio = turno todo)</legend>
      <?php foreach (['T1', 'T2', 'T3', 'T4', 'T5', 'T6'] as $b): ?><label class="small"><input type="checkbox" name="blocos[]" value="<?php echo $b; ?>"> <?php echo $b; ?></label><?php endforeach; ?>
    </fieldset>
    <label>Motivo<select name="motivo_categoria"><option value="SAUDE">Saúde</option><option value="ESTUDO">Estudo / prova</option><option value="PESSOAL">Pessoal</option><option value="OUTRO">Outro</option></select></label>
    <label>Detalhe<input type="text" name="motivo_texto"></label>
    <div class="acoes"><button type="submit" class="botao primario">Lançar pedido</button></div>
  </form>
  <p class="muted small">O turno vem da configuração da pessoa. Com menos de 72 h, o pedido entra como urgente. Recorrente: até 3 meses.</p>
</section>
<?php endif; ?>
