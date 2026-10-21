<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/gerar.php
// Gera o rascunho da escala de uma semana (nova versão) e mostra as
// versões, os recálculos e o De→Para de cada um.
// ===============================================

$semanas = [];
for ($i = 0; $i < 4; $i++) {
    $semanas[] = date('Y-m-d', strtotime(segundaDaSemana(date('Y-m-d')) . " +{$i} week"));
}
$seg = (string) ($_GET['semana'] ?? $semanas[1]);
if (!in_array($seg, $semanas, true) && !preg_match('~^\d{4}-\d{2}-\d{2}$~', $seg)) {
    $seg = $semanas[1];
}
$seg = segundaDaSemana($seg);

if (escPost()) {
    escExigir('planejar');
    $acao = $_POST['acao'] ?? '';
    $segPost = segundaDaSemana((string) ($_POST['semana'] ?? $seg));
    if ($acao === 'gerar') {
        $id = escMotorGerar($segPost, 'NOVA', escUserId());
        escVoltar('grade', ['plano' => $id], 'Rascunho gerado para a semana de ' . date('d/m', strtotime($segPost)) . '.');
    }
    if ($acao === 'recalcular') {
        if (!escPlanoAtual($segPost)) {
            throw new RuntimeException('Não há versão desta semana para recalcular. Gere primeiro.');
        }
        escW('INSERT INTO ' . escT('esc_recalculo_evento') . " (gatilho, descricao, semana_inicio, status) VALUES ('MANUAL', 'Recálculo pedido na tela', ?, 'DETECTADO')", [$segPost]);
        $ev = escUltimoId();
        $id = escMotorGerar($segPost, 'RECALCULO', escUserId(), $ev);
        escVoltar('grade', ['plano' => $id], 'Recalculado. Veja o De→Para no fim da tela Gerar escala.');
    }
    if ($acao === 'verificar') {
        $r = escMotorVerificar(escUserId(), true);
        $n = count($r['recalculados']);
        escVoltar('gerar', ['semana' => $segPost], $n ? $n . ' semana(s) recalculada(s): ' . implode(', ', array_map(static fn($x) => implode('/', array_map('escNomeFonte', $x['fontes'])), $r['recalculados'])) : 'Nada mudou nas entradas desde a última versão.');
    }
    throw new RuntimeException('Ação desconhecida.');
}

$parties = escFormacaoParties();
$bloqueantes = array_values(array_filter($parties, static function ($l) {
    foreach ($l['regras'] as $r) {
        if ($r[2]) {
            return true;
        }
    }
    return false;
}));
$config = escQ1('SELECT COUNT(*) AS n, SUM(tipo = \'BUFFER\') AS buffers, SUM(trilha IS NULL) AS sem_trilha FROM ' . escT('esc_colaborador_config') . ' WHERE vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)', [$seg, $seg]);
$capacity = escQ1('SELECT MAX(data_calculo) AS dc FROM `5c_planning`.`fc_escala_pagina`');
$versoes = escQ('SELECT p.*, e.gatilho, e.descricao AS gatilho_txt FROM ' . escT('esc_plano') . ' p LEFT JOIN ' . escT('esc_recalculo_evento') . ' e ON e.id = p.recalculo_id WHERE p.semana_inicio = ? ORDER BY p.versao DESC, p.subversao DESC', [$seg]);
$atual = escPlanoAtual($seg);
$difs = [];
if ($atual && $atual['plano_anterior_id']) {
    $difs = escQ('SELECT tipo, COUNT(*) AS n, SUM(aplicado = 0) AS nao_aplicados FROM ' . escT('esc_plano_diferenca') . ' WHERE plano_para_id = ? GROUP BY tipo', [(int) $atual['id']]);
}
$eventos = escQ('SELECT * FROM ' . escT('esc_recalculo_evento') . ' WHERE semana_inicio = ? OR semana_inicio IS NULL ORDER BY id DESC LIMIT 10', [$seg]);
$mudancasEntrada = escQ('SELECT d.fonte, COUNT(*) AS n, MAX(f.lido_em) AS quando FROM ' . escT('esc_entrada_diferenca') . ' d JOIN ' . escT('esc_entrada_foto') . ' f ON f.id = d.foto_para_id WHERE f.lido_em >= NOW() - INTERVAL 7 DAY GROUP BY d.fonte ORDER BY quando DESC');
?>
<header class="cabecalho">
  <span class="eyebrow">Planejamento</span>
  <h1>Gerar escala</h1>
  <p class="muted">Gera o rascunho da semana a partir do Capacity, da estrutura do Planner, da configuração de cada pessoa e dos pedidos aprovados. A escala oficial continua no site da 5C: daqui sai o CSV para postar.</p>
</header>

<section class="panel">
  <form method="get" action="<?php echo h(MODULE_URL); ?>/index.php" class="linha-titulo">
    <input type="hidden" name="p" value="gerar">
    <label class="filtros">Semana
      <select name="semana" onchange="this.form.submit()">
        <?php foreach ($semanas as $s): ?><option value="<?php echo h($s); ?>" <?php echo $s === $seg ? 'selected' : ''; ?>><?php echo h(dataBr($s) . ' a ' . dataBr(escMaisDias($s, 6))); ?></option><?php endforeach; ?>
      </select>
    </label>
    <span class="muted small">Versão atual: <strong><?php echo $atual ? h(escPlanoRotulo($atual) . ' · ' . strtolower($atual['status'])) : 'nenhuma'; ?></strong></span>
  </form>

  <div class="kpis">
    <div class="kpi"><span class="muted">Pessoas configuradas</span><strong><?php echo (int) ($config['n'] ?? 0); ?></strong><span class="muted small"><?php echo (int) ($config['buffers'] ?? 0); ?> Buffers · posição no ciclo pela releitura do realizado</span></div>
    <div class="kpi"><span class="muted">Capacity</span><strong><?php echo h(dataBr($capacity['dc'] ?? null)); ?></strong><span class="muted small">dias fora do horizonte usam o mesmo dia da semana</span></div>
    <div class="kpi"><span class="muted">Parties que bloqueiam</span><strong class="<?php echo $bloqueantes ? 'laranja' : ''; ?>"><?php echo count($bloqueantes); ?></strong><span class="muted small">sem Primaris ou sem página: as páginas delas vão para outras parties e Buffers</span></div>
  </div>

  <?php if (escPode('planejar')): ?>
  <div class="acoes">
    <form method="post" action="<?php echo h(link_tela('gerar', ['semana' => $seg])); ?>" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Gerando…'">
      <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="gerar"><input type="hidden" name="semana" value="<?php echo h($seg); ?>">
      <button type="submit" class="botao primario">Gerar nova versão</button>
    </form>
    <?php if ($atual): ?>
    <form method="post" action="<?php echo h(link_tela('gerar', ['semana' => $seg])); ?>">
      <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="recalcular"><input type="hidden" name="semana" value="<?php echo h($seg); ?>">
      <button type="submit" class="botao">Recalcular (mantém ajustes travados)</button>
    </form>
    <?php endif; ?>
    <form method="post" action="<?php echo h(link_tela('gerar', ['semana' => $seg])); ?>">
      <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="verificar"><input type="hidden" name="semana" value="<?php echo h($seg); ?>">
      <button type="submit" class="botao">Verificar mudanças agora</button>
    </form>
  </div>
  <?php else: ?>
  <p class="muted small">Seu perfil só visualiza. Gerar e recalcular: Planejamento ou Admin.</p>
  <?php endif; ?>
  <ol class="roteiro muted small">
    <li><strong>Releitura:</strong> lê as últimas 4 semanas do scheduleday e descobre onde cada pessoa está no ciclo e quantos dias seguidos já trabalhou.</li>
    <li><strong>Ordem de preenchimento:</strong> party dona → outras parties do time → Tertius regular → Tertius assistente → Buffers → lacuna.</li>
    <li><strong>Recálculo:</strong> qualquer mudança no Capacity, estrutura, páginas, colaboradores, pedidos aprovados ou modelos gera uma nova subversão (v3.1). Quem já está na escala publicada não sai sozinho: vira sugestão.</li>
  </ol>
</section>

<section class="panel">
  <h2>Versões da semana</h2>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th>Versão</th><th>Status</th><th>Gerada em</th><th>Gatilho</th><th class="num">Blocos</th><th class="num">Lacunas</th><th class="num">Anomalias</th><th class="num">Buffers sem página</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($versoes as $v): $r = json_decode((string) $v['resumo'], true) ?: []; ?>
      <tr>
        <td><strong><?php echo h(escPlanoRotulo($v)); ?></strong></td>
        <td><span class="tag <?php echo $v['status'] === 'PUBLICADO' ? 'verde' : ($v['status'] === 'RASCUNHO' ? 'amarelo' : ''); ?>"><?php echo h(strtolower($v['status'])); ?></span></td>
        <td class="small"><?php echo h(date('d/m H:i', strtotime($v['gerado_em']))); ?></td>
        <td class="small"><?php echo $v['gatilho'] ? h($v['gatilho_txt']) : 'geração manual'; ?></td>
        <td class="num"><?php echo (int) ($r['blocos'] ?? 0); ?></td>
        <td class="num <?php echo !empty($r['lacunas']) ? 'laranja' : ''; ?>"><?php echo (int) ($r['lacunas'] ?? 0); ?></td>
        <td class="num <?php echo !empty($r['anomalias']) ? 'vermelho' : ''; ?>"><?php echo (int) ($r['anomalias'] ?? 0); ?></td>
        <td class="num"><?php echo (int) ($r['buffers_ociosos'] ?? 0); ?></td>
        <td><a class="botao" href="<?php echo h(link_tela('grade', ['plano' => $v['id']])); ?>">Abrir</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$versoes): ?><tr><td colspan="9" class="muted">Nenhuma versão gerada para esta semana.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</section>

<?php if ($difs): ?>
<section class="panel">
  <h2>De→Para da última versão (<?php echo h(escPlanoRotulo($atual)); ?>)</h2>
  <div class="chips">
    <?php foreach ($difs as $d): ?><span class="tag"><?php echo h(strtolower($d['tipo'])); ?>: <?php echo (int) $d['n']; ?><?php echo (int) $d['nao_aplicados'] ? ' (' . (int) $d['nao_aplicados'] . ' mantidos por já estarem publicados)' : ''; ?></span><?php endforeach; ?>
  </div>
  <a class="botao" href="<?php echo h(link_tela('grade', ['plano' => $atual['id'], 'aba' => 'depara'])); ?>">Ver célula a célula</a>
</section>
<?php endif; ?>

<section class="panel">
  <h2>Mudanças nas entradas (últimos 7 dias)</h2>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th>Fonte</th><th class="num">Itens que mudaram</th><th>Quando</th></tr></thead>
    <tbody>
    <?php foreach ($mudancasEntrada as $m): ?>
      <tr><td><?php echo h(escNomeFonte($m['fonte'])); ?></td><td class="num"><?php echo (int) $m['n']; ?></td><td class="small"><?php echo h(date('d/m H:i', strtotime($m['quando']))); ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$mudancasEntrada): ?><tr><td colspan="3" class="muted">Nenhuma mudança registrada. A primeira verificação só tira a foto inicial.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <h3 class="small muted">Eventos de recálculo</h3>
  <ul class="regras small">
    <?php foreach ($eventos as $e): ?>
      <li><?php echo h(date('d/m H:i', strtotime($e['detectado_em']))); ?> · <?php echo h($e['descricao']); ?> · <span class="tag <?php echo $e['status'] === 'ERRO' ? 'vermelho' : ''; ?>"><?php echo h(strtolower($e['status'])); ?></span><?php echo $e['erro'] ? ' ' . h($e['erro']) : ''; ?></li>
    <?php endforeach; ?>
    <?php if (!$eventos): ?><li class="muted">Nenhum ainda.</li><?php endif; ?>
  </ul>
</section>
