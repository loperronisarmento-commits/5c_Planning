<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/times.php
// Configuração dos times: violações da regra 1 Primaris + 2 Secundus,
// parties sem página e a formação de cada time (código e Telegram).
// Grava no 5c_escalas: ação de correção de cada violação e as pendências
// para aplicar no Planner (sem API de escrita). A pendência fecha sozinha
// quando a estrutura do Planner bate com o pedido.
// ===============================================

if (escPost()) {
    $acao = $_POST['acao'] ?? '';
    if ($acao === 'acao_violacao') {
        escExigir('corrigir');
        $regra = strtoupper((string) ($_POST['regra'] ?? ''));
        $situacao = (string) ($_POST['situacao'] ?? 'ABERTA');
        if (!in_array($regra, ['SEM_PRIMARIS', 'DOIS_PRIMARIS', 'SECUNDUS', 'SEM_PAGINA'], true) || !in_array($situacao, ['ABERTA', 'EM_CORRECAO', 'JUSTIFICADA', 'RESOLVIDA'], true) || trim((string) ($_POST['texto'] ?? '')) === '') {
            throw new RuntimeException('Preencha a ação e a situação.');
        }
        $prazo = preg_match('~^\d{4}-\d{2}-\d{2}$~', (string) ($_POST['prazo'] ?? '')) ? $_POST['prazo'] : null;
        $ate = preg_match('~^\d{4}-\d{2}-\d{2}$~', (string) ($_POST['justificada_ate'] ?? '')) ? $_POST['justificada_ate'] : null;
        escW('INSERT INTO ' . escT('esc_violacao_acao') . ' (time_nome, party, regra, acao, responsavel, prazo, situacao, justificativa, justificada_ate, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            mb_substr((string) $_POST['time'], 0, 100), mb_substr((string) $_POST['party'], 0, 100), $regra, mb_substr(trim((string) $_POST['texto']), 0, 500),
            mb_substr(trim((string) ($_POST['responsavel'] ?? '')), 0, 120) ?: null, $prazo, $situacao,
            mb_substr(trim((string) ($_POST['justificativa'] ?? '')), 0, 500) ?: null, $ate, escUserId(),
        ]);
        escLog('violacao.acao', 'esc_violacao_acao', escUltimoId(), null, ['party' => $_POST['party'], 'regra' => $regra, 'situacao' => $situacao]);
        escVoltar('times', ['regra' => $_GET['regra'] ?? 'todas'], 'Ação registrada para ' . $_POST['party'] . '.');
    }
    if ($acao === 'pendencia') {
        escExigir('cadastrar');
        $tipo = (string) ($_POST['tipo'] ?? '');
        $rep = strtoupper(trim((string) ($_POST['rep_code'] ?? '')));
        $vale = (string) ($_POST['vale_a_partir'] ?? '');
        if (!in_array($tipo, ['ADICIONAR', 'MOVER', 'REMOVER', 'PASSAR_TERTIUS', 'PROMOVER'], true) || !preg_match('~^[A-Z]\d{6}$~', $rep) || !preg_match('~^\d{4}-\d{2}-\d{2}$~', $vale)) {
            throw new RuntimeException('Preencha tipo, código e data.');
        }
        $e = escEstrutura()[$rep] ?? null;
        $partyPara = trim((string) ($_POST['party_para'] ?? ''));
        $timePara = trim((string) ($_POST['time_para'] ?? ''));
        $papel = trim((string) ($_POST['papel_para'] ?? '')) ?: ($tipo === 'PASSAR_TERTIUS' ? 'Tertius' : null);
        $turno = in_array($_POST['turno_para'] ?? '', ['A', 'C', 'D'], true) ? $_POST['turno_para'] : null;
        if ($tipo !== 'REMOVER' && $partyPara === '' && $tipo !== 'PASSAR_TERTIUS' && $tipo !== 'PROMOVER') {
            throw new RuntimeException('Diga a party de destino.');
        }
        $nome = escNomes()[$rep] ?? $rep;
        $verbo = ['ADICIONAR' => 'incluir', 'MOVER' => 'mover', 'REMOVER' => 'tirar', 'PASSAR_TERTIUS' => 'passar a Tertius', 'PROMOVER' => 'promover'][$tipo];
        $instrucao = 'Planner › ' . ($timePara ?: ($e['team'] ?? '?')) . ' › ' . ($partyPara ?: ($e['party'] ?? '?')) . ': ' . $verbo . ' ' . $nome . ' (' . $rep . ')'
            . ($e ? ' (hoje em ' . $e['party'] . ' como ' . $e['role'] . ')' : '') . ($papel ? ' como ' . $papel : '') . ($turno ? ', turno ' . $turno : '') . ', a partir de ' . date('d/m', strtotime($vale)) . '.';
        escW('INSERT INTO ' . escT('esc_estrutura_pendencia') . ' (tipo, rep_code, time_de, party_de, time_para, party_para, papel_para, turno_para, vale_a_partir, motivo, instrucao, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $tipo, $rep, $e['team'] ?? null, $e['party'] ?? null, $timePara ?: ($e['team'] ?? null), $partyPara ?: ($e['party'] ?? null), $papel, $turno, $vale,
            mb_substr(trim((string) ($_POST['motivo'] ?? '')), 0, 255) ?: null, mb_substr($instrucao, 0, 500), escUserId(),
        ]);
        escLog('pendencia.criar', 'esc_estrutura_pendencia', escUltimoId(), null, ['instrucao' => $instrucao]);
        escVoltar('times', ['aba' => 'pend'], 'Pendência criada. Aplique no Planner; ela fecha sozinha quando o Planner bater.');
    }
    if ($acao === 'pend_cancelar') {
        escExigir('cadastrar');
        escW('UPDATE ' . escT('esc_estrutura_pendencia') . " SET status = 'CANCELADA', atualizado_por = ? WHERE id = ? AND status <> 'APLICADA'", [escUserId(), (int) ($_POST['id'] ?? 0)]);
        escLog('pendencia.cancelar', 'esc_estrutura_pendencia', (int) ($_POST['id'] ?? 0));
        escVoltar('times', ['aba' => 'pend'], 'Pendência cancelada.');
    }
    throw new RuntimeException('Ação desconhecida.');
}

$parties = escFormacaoParties();
$filtro = $_GET['regra'] ?? 'todas';
$busca = trim((string) ($_GET['q'] ?? ''));
$timeSel = (string) ($_GET['time'] ?? '');

$contagem = ['todas' => 0, 'sem_primaris' => 0, 'dois_primaris' => 0, 'secundus' => 0, 'sem_pagina' => 0];
foreach ($parties as $l) {
    if ($l['regras']) {
        $contagem['todas']++;
    }
    foreach (array_unique(array_column($l['regras'], 0)) as $r) {
        $contagem[$r]++;
    }
}
$rotulos = ['todas' => 'Todas', 'sem_primaris' => 'Sem Primaris', 'dois_primaris' => '2+ Primaris', 'secundus' => 'Secundus ≠ 2', 'sem_pagina' => 'Sem página'];
if (!isset($rotulos[$filtro])) {
    $filtro = 'todas';
}

$lista = array_filter($parties, static function ($l) use ($filtro, $busca) {
    if (!$l['regras']) {
        return false;
    }
    if ($filtro !== 'todas' && !in_array($filtro, array_column($l['regras'], 0), true)) {
        return false;
    }
    return $busca === '' || stripos($l['team'] . ' ' . $l['party'], $busca) !== false;
});

$times = array_values(array_unique(array_column($parties, 'team')));
sort($times);
if ($timeSel === '' || !in_array($timeSel, $times, true)) {
    $timeSel = $times[0] ?? '';
}

// Formação do time escolhido.
$membros = [];
if ($timeSel !== '') {
    foreach (escEstrutura() as $code => $e) {
        if ($e['team'] === $timeSel) {
            $membros[$code] = $e;
        }
    }
}
$telegram = [];
if ($membros) {
    $codes = array_keys($membros);
    foreach (escQ('SELECT UPPER(code) AS code, telegram FROM `5c_bot`.`vw_rep` WHERE code IN (' . escIn($codes) . ')', $codes) as $r) {
        $telegram[$r['code']] = $r['telegram'];
    }
}
$turnos = escTurnosVigentes();
$ordemPapel = static function (string $papel): int {
    return ehPrimaris($papel) ? 0 : ($papel === 'Secundus' ? 1 : 2);
};
uasort($membros, static function ($a, $b) use ($ordemPapel) {
    return [$a['party'], $ordemPapel($a['role']), $a['rep_name']] <=> [$b['party'], $ordemPapel($b['role']), $b['rep_name']];
});
$porParty = [];
foreach ($membros as $code => $e) {
    $porParty[$e['party']][$code] = $e;
}
$formacaoTime = array_values(array_filter($parties, static fn($l) => $l['team'] === $timeSel));

// Última ação registrada por party e regra.
$acoes = [];
foreach (escQ('SELECT * FROM ' . escT('esc_violacao_acao') . ' ORDER BY id') as $a) {
    $acoes[$a['party'] . '|' . $a['regra']] = $a;
}

// Pendências do Planner, com conferência automática contra a estrutura atual.
$atraso = (int) (escQ1('SELECT valor FROM ' . escT('esc_parametro') . " WHERE chave = 'pendencia_planner_atraso_d'")['valor'] ?? 2);
$estruturaAtual = escEstrutura();
$pendencias = escQ('SELECT * FROM ' . escT('esc_estrutura_pendencia') . " WHERE status IN ('ABERTA','ATRASADA','DIVERGENTE') OR atualizado_em >= NOW() - INTERVAL 14 DAY OR criado_em >= NOW() - INTERVAL 14 DAY ORDER BY FIELD(status, 'ATRASADA', 'DIVERGENTE', 'ABERTA', 'APLICADA', 'CANCELADA'), vale_a_partir");
foreach ($pendencias as &$pd) {
    if (!in_array($pd['status'], ['ABERTA', 'ATRASADA', 'DIVERGENTE'], true)) {
        continue;
    }
    $e = $estruturaAtual[strtoupper((string) $pd['rep_code'])] ?? null;
    $bateu = $pd['tipo'] === 'REMOVER'
        ? ($e === null || ($pd['party_de'] && $e['party'] !== $pd['party_de']))
        : ($e !== null && ($pd['party_para'] === null || $e['party'] === $pd['party_para']) && ($pd['papel_para'] === null || stripos($e['role'], $pd['papel_para']) !== false));
    $novo = $bateu ? 'APLICADA' : (date('Y-m-d') > escMaisDias(substr($pd['vale_a_partir'], 0, 10), $atraso) ? 'ATRASADA' : 'ABERTA');
    if (!$bateu && $e && $pd['party_para'] && $e['party'] !== $pd['party_para'] && $e['party'] !== $pd['party_de']) {
        $novo = 'DIVERGENTE';
        $pd['divergencia'] = 'Planner tem ' . $e['party'] . ' (' . $e['role'] . ')';
    }
    if ($novo !== $pd['status']) {
        escW('UPDATE ' . escT('esc_estrutura_pendencia') . ' SET status = ?, conferida_em = ' . ($novo === 'APLICADA' ? 'NOW()' : 'conferida_em') . ', divergencia = ? WHERE id = ?', [$novo, $novo === 'DIVERGENTE' ? $pd['divergencia'] : null, (int) $pd['id']]);
        $pd['status'] = $novo;
    }
}
unset($pd);
$abertasPend = count(array_filter($pendencias, static fn($p) => in_array($p['status'], ['ABERTA', 'ATRASADA', 'DIVERGENTE'], true)));
$partiesNomes = array_column($parties, 'party');
?>
<header class="cabecalho">
  <span class="eyebrow">Cadastros</span>
  <h1>Configuração dos times</h1>
  <p class="muted">Regra: cada party com 1 Primaris e 2 Secundus, e com página ativa. Tertius atendem o time inteiro. Dados do Planner (estrutura atual) e do cadastro de páginas.</p>
</header>

<section class="panel">
  <div class="linha-titulo">
    <h2>Violações · <?php echo (int) $contagem['todas']; ?> de <?php echo count($parties); ?> parties</h2>
    <form class="busca" method="get" action="<?php echo h(MODULE_URL); ?>/index.php">
      <input type="hidden" name="p" value="times"><input type="hidden" name="regra" value="<?php echo h($filtro); ?>">
      <input type="search" name="q" value="<?php echo h($busca); ?>" placeholder="Buscar time ou party" aria-label="Buscar time ou party">
    </form>
  </div>
  <div class="chips" role="group" aria-label="Filtrar por regra">
    <?php foreach ($rotulos as $k => $rot): ?>
      <a class="chip-filtro <?php echo $filtro === $k ? 'ativo' : ''; ?>" href="<?php echo h(link_tela('times', ['regra' => $k, 'q' => $busca, 'time' => $timeSel])); ?>"><?php echo h($rot); ?> · <?php echo (int) $contagem[$k]; ?></a>
    <?php endforeach; ?>
  </div>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th>Time / Party</th><th>Formação</th><th>Páginas</th><th>Regra</th><th>Ação sugerida</th><th>Ação registrada</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($lista as $l): ?>
      <tr>
        <td><strong><?php echo h($l['team']); ?></strong><br><span class="muted"><?php echo h($l['party']); ?></span></td>
        <td class="mono"><?php echo (int) $l['p']; ?>P · <?php echo (int) $l['s']; ?>S · <?php echo (int) $l['t']; ?>T</td>
        <td class="small"><?php echo $l['paginas'] ? h($l['nomes']) : '<span class="muted">nenhuma</span>'; ?></td>
        <td><?php foreach ($l['regras'] as $r): ?><span class="tag <?php echo $r[2] ? 'vermelho' : 'amarelo'; ?>"><?php echo h($r[1]); ?><?php echo $r[2] ? ' · bloqueia' : ''; ?></span> <?php endforeach; ?></td>
        <td class="small"><?php echo h(implode(' ', array_column($l['regras'], 3))); ?></td>
        <td class="small">
          <?php foreach ($l['regras'] as $r): $ac = $acoes[$l['party'] . '|' . strtoupper($r[0])] ?? null; if ($ac): ?>
            <span class="tag <?php echo $ac['situacao'] === 'RESOLVIDA' ? 'verde' : ($ac['situacao'] === 'JUSTIFICADA' ? 'amarelo' : ''); ?>"><?php echo h(strtolower(str_replace('_', ' ', $ac['situacao']))); ?></span> <?php echo h($ac['acao']); ?><?php echo $ac['responsavel'] ? ' · ' . h($ac['responsavel']) : ''; ?><?php echo $ac['prazo'] ? ' · até ' . h(dataBr($ac['prazo'])) : ''; ?><br>
          <?php endif; endforeach; ?>
          <?php if (escPode('corrigir')): ?>
          <details><summary class="botao">Registrar ação</summary>
            <form method="post" action="<?php echo h(link_tela('times', ['regra' => $filtro])); ?>" class="form-grade">
              <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="acao_violacao"><input type="hidden" name="time" value="<?php echo h($l['team']); ?>"><input type="hidden" name="party" value="<?php echo h($l['party']); ?>">
              <label>Regra<select name="regra"><?php foreach ($l['regras'] as $r): ?><option value="<?php echo h(strtoupper($r[0])); ?>"><?php echo h($r[1]); ?></option><?php endforeach; ?></select></label>
              <label>O que será feito<input type="text" name="texto" required></label>
              <label>Responsável<input type="text" name="responsavel"></label>
              <label>Prazo<input type="date" name="prazo"></label>
              <label>Situação<select name="situacao"><option value="ABERTA">Aberta</option><option value="EM_CORRECAO">Em correção</option><option value="JUSTIFICADA">Justificada</option><option value="RESOLVIDA">Resolvida</option></select></label>
              <label>Justificativa (se justificada)<input type="text" name="justificativa"></label>
              <label>Justificada até<input type="date" name="justificada_ate"></label>
              <button type="submit" class="botao primario">Salvar ação</button>
            </form>
          </details>
          <?php endif; ?>
        </td>
        <td><a class="botao" href="<?php echo h(link_tela('times', ['regra' => $filtro, 'q' => $busca, 'time' => $l['team']])); ?>#formacao">Ver formação</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$lista): ?><tr><td colspan="7" class="muted">Nenhuma party nesse filtro.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <p class="muted small">"Bloqueia" = a party fica fora da geração até ser corrigida. Mudanças de formação são feitas no Planner (não há API de escrita).</p>
</section>

<section class="panel" id="formacao">
  <div class="linha-titulo">
    <h2>Formação do time</h2>
    <form method="get" action="<?php echo h(MODULE_URL); ?>/index.php">
      <input type="hidden" name="p" value="times"><input type="hidden" name="regra" value="<?php echo h($filtro); ?>"><input type="hidden" name="q" value="<?php echo h($busca); ?>">
      <select name="time" onchange="this.form.submit()" aria-label="Time">
        <?php foreach ($times as $t): ?><option <?php echo $t === $timeSel ? 'selected' : ''; ?>><?php echo h($t); ?></option><?php endforeach; ?>
      </select>
    </form>
  </div>
  <div class="grade-parties">
    <?php foreach ($formacaoTime as $l): ?>
    <div class="cartao-party <?php echo $l['regras'] ? 'com-violacao' : ''; ?>">
      <div class="linha-titulo"><strong><?php echo h($l['party']); ?></strong><span class="mono small"><?php echo (int) $l['p']; ?>P · <?php echo (int) $l['s']; ?>S · <?php echo (int) $l['t']; ?>T</span></div>
      <span class="muted small"><?php echo $l['paginas'] ? h($l['nomes']) : 'sem página ativa'; ?></span>
      <ul class="membros">
        <?php foreach ($porParty[$l['party']] ?? [] as $code => $e):
            $tg = $telegram[$code] ?? '';
            $turno = turnoLetra($turnos[$code]['turno'] ?? null);
        ?>
        <li title="<?php echo h($e['rep_name'] . ' · ' . $code . ($tg ? ' · Telegram ' . $tg : '')); ?>">
          <span><?php echo h($e['rep_name']); ?> <span class="muted small"><?php echo h($e['role']); ?></span></span>
          <span class="small"><?php echo $turno ? chipTurno($turno) : ''; ?> <span class="mono"><?php echo h($code); ?></span><?php if ($tg): ?> · <a href="https://t.me/<?php echo h(ltrim($tg, '@')); ?>" target="_blank" rel="noopener"><?php echo h('@' . ltrim($tg, '@')); ?></a><?php endif; ?></span>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endforeach; ?>
  </div>
  <p class="muted small">Turno pelo headcount vigente. Passe o mouse no nome para ver código e Telegram.</p>
</section>

<section class="panel" id="pendencias">
  <h2>Pendências no Planner · <?php echo (int) $abertasPend; ?> abertas</h2>
  <p class="muted small">O Planner não tem API de escrita. Cada mudança vira uma instrução para aplicar lá; ela fecha sozinha quando a estrutura do Planner bate com o pedido. Passou <?php echo (int) $atraso; ?> dias da data sem aparecer: "atrasada". O Planner mudou diferente do pedido: "divergente".</p>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th>Mudança</th><th>O que fazer no Planner</th><th>Vale a partir de</th><th>Situação</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($pendencias as $pd): ?>
      <tr>
        <td><strong><?php echo h(strtolower(str_replace('_', ' ', $pd['tipo']))); ?></strong><br><span class="muted small mono"><?php echo h($pd['rep_code']); ?></span></td>
        <td class="small" id="pend-<?php echo (int) $pd['id']; ?>"><?php echo h($pd['instrucao']); ?></td>
        <td><?php echo h(dataBr($pd['vale_a_partir'], true)); ?></td>
        <td><span class="tag <?php echo $pd['status'] === 'APLICADA' ? 'verde' : (in_array($pd['status'], ['ATRASADA', 'DIVERGENTE'], true) ? 'vermelho' : 'amarelo'); ?>"><?php echo h(strtolower($pd['status'])); ?></span><?php echo $pd['divergencia'] ? '<br><span class="muted small">' . h($pd['divergencia']) . '</span>' : ''; ?><?php echo $pd['conferida_em'] ? '<br><span class="muted small">conferida ' . h(date('d/m H:i', strtotime($pd['conferida_em']))) . '</span>' : ''; ?></td>
        <td class="nowrap">
          <button type="button" class="botao" data-copiar="pend-<?php echo (int) $pd['id']; ?>">Copiar instrução</button>
          <?php if (escPode('cadastrar') && !in_array($pd['status'], ['APLICADA', 'CANCELADA'], true)): ?>
          <form method="post" action="<?php echo h(link_tela('times')); ?>" style="display:inline"><?php echo campoCsrf(); ?><input type="hidden" name="acao" value="pend_cancelar"><input type="hidden" name="id" value="<?php echo (int) $pd['id']; ?>"><button type="submit" class="botao">Cancelar</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$pendencias): ?><tr><td colspan="5" class="muted">Nenhuma pendência.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>

  <?php if (escPode('cadastrar')): ?>
  <h3>Nova pendência</h3>
  <form method="post" action="<?php echo h(link_tela('times')); ?>" class="form-grade">
    <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="pendencia">
    <label>Tipo<select name="tipo"><option value="MOVER">Mover de party</option><option value="ADICIONAR">Adicionar ao time</option><option value="REMOVER">Remover do time</option><option value="PASSAR_TERTIUS">Passar a Tertius</option><option value="PROMOVER">Promover</option></select></label>
    <label>Colaborador<input type="search" name="rep_code" list="lista-colaboradores" autocomplete="off" placeholder="buscar nome ou código" required></label>
    <label>Time de destino<select name="time_para"><option value="">(o mesmo)</option><?php foreach ($times as $t): ?><option><?php echo h($t); ?></option><?php endforeach; ?></select></label>
    <label>Party de destino<select name="party_para"><option value="">(a mesma / não se aplica)</option><?php foreach ($partiesNomes as $pn): ?><option><?php echo h($pn); ?></option><?php endforeach; ?></select></label>
    <label>Papel<select name="papel_para"><option value="">(o mesmo)</option><option>Primaris</option><option>Secundus</option><option>Tertius</option></select></label>
    <label>Turno<select name="turno_para"><option value="">(o mesmo)</option><option>A</option><option>C</option><option>D</option></select></label>
    <label>Vale a partir de<input type="date" name="vale_a_partir" value="<?php echo h(date('Y-m-d', strtotime('+1 day'))); ?>" required></label>
    <label>Motivo<input type="text" name="motivo"></label>
    <div class="acoes"><button type="submit" class="botao primario">Criar pendência</button></div>
  </form>
  <?php endif; ?>
</section>
