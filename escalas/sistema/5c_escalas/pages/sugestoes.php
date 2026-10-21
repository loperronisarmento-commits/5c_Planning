<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/sugestoes.php
// Sugestões do rascunho: workload (remover turno), cobertura (lacunas),
// Buffers sem página. Nada é aplicado sozinho: aqui se aplica ou descarta.
// ===============================================

$planoId = (int) ($_GET['plano'] ?? 0);
if (!$planoId) {
    $p = escPlanoAtual(date('Y-m-d', strtotime(segundaDaSemana(date('Y-m-d')) . ' +1 week'))) ?? escPlanoAtual(segundaDaSemana(date('Y-m-d')));
    $planoId = $p ? (int) $p['id'] : 0;
}
$plano = $planoId ? escQ1('SELECT * FROM ' . escT('esc_plano') . ' WHERE id = ?', [$planoId]) : null;

if (escPost()) {
    escExigir('decidir');
    $id = (int) ($_POST['id'] ?? 0);
    $s = escQ1('SELECT * FROM ' . escT('esc_sugestao') . ' WHERE id = ?', [$id]);
    if (!$s || $s['status'] !== 'ABERTA') {
        throw new RuntimeException('Sugestão não encontrada ou já decidida.');
    }
    $acao = $_POST['acao'] ?? '';
    if ($acao === 'descartar') {
        $motivo = trim((string) ($_POST['motivo'] ?? ''));
        if ($motivo === '') {
            throw new RuntimeException('Diga o motivo para descartar.');
        }
        escW('UPDATE ' . escT('esc_sugestao') . " SET status = 'DESCARTADA', motivo_descarte = ?, decidido_em = NOW(), decidido_por = ? WHERE id = ?", [mb_substr($motivo, 0, 500), escUserId(), $id]);
        escLog('sugestao.descartar', 'esc_sugestao', $id, ['status' => 'ABERTA'], ['status' => 'DESCARTADA', 'motivo' => $motivo]);
        escVoltar('sugestoes', ['plano' => $s['plano_id']], 'Sugestão descartada.');
    }
    if ($acao === 'aplicar') {
        $d = json_decode((string) $s['dados'], true) ?: [];
        $plano = escQ1('SELECT * FROM ' . escT('esc_plano') . ' WHERE id = ?', [(int) $s['plano_id']]);
        escTx(function () use ($s, $d, $id, $plano) {
            if ($s['tipo'] === 'WORKLOAD' && !empty($d['celulas']) && $plano && $plano['status'] === 'RASCUNHO') {
                foreach ($d['celulas'] as $c) {
                    escW('DELETE FROM ' . escT('esc_plano_alocacao') . ' WHERE plano_id = ? AND dia = ? AND bloco = ? AND page_id = ? AND rep_code = ?', [(int) $plano['id'], $c['dia'], $c['bloco'], (int) $c['page_id'], $d['rep_code']]);
                }
            } elseif ($s['tipo'] === 'COBERTURA') {
                throw new RuntimeException('Lacuna se cobre na grade (ajuste manual da célula). Aqui dá para descartar se for aceitar a lacuna.');
            } elseif ($s['tipo'] === 'WORKLOAD' && !empty($d['celulas'])) {
                throw new RuntimeException('Esta versão não é rascunho. Crie um rascunho a partir dela e aplique lá.');
            }
            escW('UPDATE ' . escT('esc_sugestao') . " SET status = 'APLICADA', decidido_em = NOW(), decidido_por = ? WHERE id = ?", [escUserId(), $id]);
            escLog('sugestao.aplicar', 'esc_sugestao', $id, ['status' => 'ABERTA'], ['status' => 'APLICADA', 'dados' => $d]);
        });
        escVoltar('sugestoes', ['plano' => $s['plano_id']], 'Sugestão aplicada.');
    }
    throw new RuntimeException('Ação desconhecida.');
}

if (!$plano) {
    echo '<header class="cabecalho"><span class="eyebrow">Planejamento</span><h1>Sugestões de troca</h1></header><section class="panel"><p class="muted">Gere um rascunho para ver sugestões.</p></section>';
    return;
}

$status = $_GET['status'] ?? 'ABERTA';
if (!in_array($status, ['ABERTA', 'APLICADA', 'DESCARTADA'], true)) {
    $status = 'ABERTA';
}
$tipo = $_GET['tipo'] ?? '';
$where = 'plano_id = ? AND status = ?';
$par = [$planoId, $status];
if (in_array($tipo, ['WORKLOAD', 'COBERTURA', 'MERITO', 'FOLGA', 'ESTRUTURA'], true)) {
    $where .= ' AND tipo = ?';
    $par[] = $tipo;
}
$lista = escQ('SELECT * FROM ' . escT('esc_sugestao') . ' WHERE ' . $where . ' ORDER BY tipo, id LIMIT 300', $par);
$contagem = [];
foreach (escQ('SELECT status, tipo, COUNT(*) AS n FROM ' . escT('esc_sugestao') . ' WHERE plano_id = ? GROUP BY status, tipo', [$planoId]) as $c) {
    $contagem[$c['status']][$c['tipo']] = (int) $c['n'];
}
$nomes = escNomes();
$params = ['plano' => $planoId, 'status' => $status];
?>
<header class="cabecalho">
  <span class="eyebrow">Planejamento</span>
  <h1>Sugestões de troca · <?php echo h(escPlanoRotulo($plano)); ?></h1>
  <p class="muted">Semana de <?php echo h(dataBr($plano['semana_inicio'])); ?>. O sistema sugere, ninguém é tirado ou trocado sozinho. Descartar pede o motivo.</p>
</header>

<section class="panel">
  <div class="chips">
    <?php foreach (['ABERTA' => 'Abertas', 'APLICADA' => 'Aplicadas', 'DESCARTADA' => 'Descartadas'] as $k => $rot): ?>
      <a class="chip-filtro <?php echo $status === $k ? 'ativo' : ''; ?>" href="<?php echo h(link_tela('sugestoes', ['plano' => $planoId, 'status' => $k])); ?>"><?php echo h($rot); ?> · <?php echo array_sum($contagem[$k] ?? []); ?></a>
    <?php endforeach; ?>
  </div>
  <div class="chips">
    <a class="chip-filtro <?php echo $tipo === '' ? 'ativo' : ''; ?>" href="<?php echo h(link_tela('sugestoes', $params)); ?>">Todos os tipos</a>
    <?php foreach (['WORKLOAD' => 'Workload', 'COBERTURA' => 'Cobertura'] as $k => $rot): ?>
      <a class="chip-filtro <?php echo $tipo === $k ? 'ativo' : ''; ?>" href="<?php echo h(link_tela('sugestoes', $params + ['tipo' => $k])); ?>"><?php echo h($rot); ?> · <?php echo (int) ($contagem[$status][$k] ?? 0); ?></a>
    <?php endforeach; ?>
  </div>

  <?php foreach ($lista as $s): $d = json_decode((string) $s['dados'], true) ?: []; ?>
  <div class="cartao-csv">
    <div class="linha-titulo"><strong><?php echo h($s['titulo']); ?></strong><span class="tag <?php echo $s['tipo'] === 'COBERTURA' ? 'laranja' : 'amarelo'; ?>"><?php echo h(strtolower($s['tipo'])); ?></span></div>
    <span class="small"><?php echo h($s['descricao']); ?><?php echo isset($d['rep_code']) ? ' · ' . h($nomes[$d['rep_code']] ?? '') : ''; ?></span>
    <span class="muted small"><?php echo h($s['efeito']); ?></span>
    <?php if ($s['status'] === 'ABERTA' && escPode('decidir')): ?>
    <div class="acoes">
      <?php if ($s['tipo'] === 'WORKLOAD'): ?>
      <form method="post" action="<?php echo h(link_tela('sugestoes', $params)); ?>"><?php echo campoCsrf(); ?><input type="hidden" name="acao" value="aplicar"><input type="hidden" name="id" value="<?php echo (int) $s['id']; ?>"><button type="submit" class="botao primario">Aplicar</button></form>
      <?php else: ?>
      <a class="botao" href="<?php echo h(link_tela('grade', ['plano' => $planoId, 'turno' => $d['turno'] ?? 'C'])); ?>">Cobrir na grade</a>
      <?php endif; ?>
      <form method="post" action="<?php echo h(link_tela('sugestoes', $params)); ?>" class="acoes"><?php echo campoCsrf(); ?><input type="hidden" name="acao" value="descartar"><input type="hidden" name="id" value="<?php echo (int) $s['id']; ?>">
        <input type="text" name="motivo" placeholder="motivo do descarte" required>
        <button type="submit" class="botao">Descartar</button></form>
    </div>
    <?php elseif ($s['status'] !== 'ABERTA'): ?>
    <span class="muted small"><?php echo h(strtolower($s['status'])); ?> em <?php echo h(date('d/m H:i', strtotime((string) $s['decidido_em']))); ?><?php echo $s['motivo_descarte'] ? ' · motivo: ' . h($s['motivo_descarte']) : ''; ?></span>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php if (!$lista): ?><p class="muted">Nenhuma sugestão neste filtro.</p><?php endif; ?>
</section>
