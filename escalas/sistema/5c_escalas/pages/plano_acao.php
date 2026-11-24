<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/plano_acao.php
// Plano de Ação (1.38.0, item C da revisão de 24/11; era "Sugestões de troca"): todas as ações
// sugeridas ou pendentes do sistema numa lista só (lib/plano_acao.php, esc_acao): trocas de pessoas
// e de páginas, cobertura de lacunas, carga, Recalcular Tier, entradas e saídas de página, pedidos
// pendentes, alertas e dados a conferir. Cada ação tem tipo, o que ou quem envolve, origem, data e
// status (pendente, feita, descartada), com filtro por tipo, unidade e status. A ação fecha sozinha
// quando a origem deixa de apontar o problema.
// ===============================================

if (escPost()) {
    escExigir('decidir');
    $volta = array_intersect_key($_GET, array_flip(['status', 'tipo', 'u']));
    $acao = (string) ($_POST['acao'] ?? '');
    if ($acao === 'atualizar') {
        escAcoesSincronizar(true);
        escVoltar('plano_acao', $volta, t('Plano de Ação atualizado.'));
    }
    $id = (int) ($_POST['id'] ?? 0);
    $a = escQ1('SELECT * FROM ' . escT('esc_acao') . ' WHERE id = ?', [$id]);
    if (!$a) {
        throw new RuntimeException(t('Ação não encontrada.'));
    }
    $motivo = mb_substr(trim((string) ($_POST['motivo'] ?? '')), 0, 255);
    if ($acao === 'descartar') {
        if ($motivo === '') {
            throw new RuntimeException(t('Diga o motivo para descartar.'));
        }
        escW('UPDATE ' . escT('esc_acao') . " SET status = 'DESCARTADA', motivo = ?, decidido_em = NOW(), decidido_por = ? WHERE id = ?", [$motivo, escUserId(), $id]);
        // A carga sugerida pelo Gerar também sai da lista de sugestões do plano.
        $d = json_decode((string) $a['dados'], true) ?: [];
        if (!empty($d['sugestao_id'])) {
            escW('UPDATE ' . escT('esc_sugestao') . " SET status = 'DESCARTADA', motivo_descarte = ?, decidido_em = NOW(), decidido_por = ? WHERE id = ? AND status = 'ABERTA'", [$motivo, escUserId(), (int) $d['sugestao_id']]);
        }
        escLog('acao.descartar', 'esc_acao', $id, ['status' => $a['status']], ['status' => 'DESCARTADA', 'motivo' => $motivo]);
        escVoltar('plano_acao', $volta, t('Ação descartada.'));
    }
    if ($acao === 'feita') {
        escW('UPDATE ' . escT('esc_acao') . " SET status = 'FEITA', motivo = NULLIF(?, ''), decidido_em = NOW(), decidido_por = ? WHERE id = ?", [$motivo, escUserId(), $id]);
        escLog('acao.feita', 'esc_acao', $id, ['status' => $a['status']], ['status' => 'FEITA']);
        escVoltar('plano_acao', $volta, t('Ação marcada como feita.'));
    }
    if ($acao === 'reabrir') {
        escW('UPDATE ' . escT('esc_acao') . " SET status = 'PENDENTE', motivo = NULL, decidido_em = NULL, decidido_por = NULL WHERE id = ?", [$id]);
        escLog('acao.reabrir', 'esc_acao', $id, ['status' => $a['status']], ['status' => 'PENDENTE']);
        escVoltar('plano_acao', $volta, t('Ação reaberta.'));
    }
    if ($acao === 'aplicar') {
        // Carga (WORKLOAD) sugerida pelo Gerar: tira o turno da pessoa no rascunho, como na antiga tela de sugestões.
        $d = json_decode((string) $a['dados'], true) ?: [];
        $s = !empty($d['sugestao_id']) ? escQ1('SELECT * FROM ' . escT('esc_sugestao') . ' WHERE id = ?', [(int) $d['sugestao_id']]) : null;
        if (!$s || $s['status'] !== 'ABERTA' || $s['tipo'] !== 'WORKLOAD') {
            throw new RuntimeException(t('Esta ação não tem o que aplicar aqui: use o link da origem.'));
        }
        $ds = json_decode((string) $s['dados'], true) ?: [];
        $plano = escQ1('SELECT * FROM ' . escT('esc_plano') . ' WHERE id = ?', [(int) $s['plano_id']]);
        if (!$plano || $plano['status'] !== 'RASCUNHO') {
            throw new RuntimeException(t('Esta versão não é rascunho. Crie um rascunho a partir dela e aplique lá.'));
        }
        escTx(function () use ($s, $ds, $plano, $id) {
            foreach ($ds['celulas'] ?? [] as $c) {
                escW('DELETE FROM ' . escT('esc_plano_alocacao') . ' WHERE plano_id = ? AND dia = ? AND bloco = ? AND page_id = ? AND rep_code = ?', [(int) $plano['id'], $c['dia'], $c['bloco'], (int) $c['page_id'], $ds['rep_code']]);
            }
            escW('UPDATE ' . escT('esc_sugestao') . " SET status = 'APLICADA', decidido_em = NOW(), decidido_por = ? WHERE id = ? AND status = 'ABERTA'", [escUserId(), (int) $s['id']]);
            escW('UPDATE ' . escT('esc_acao') . " SET status = 'FEITA', motivo = ?, decidido_em = NOW(), decidido_por = ? WHERE id = ?", [t('aplicada no rascunho'), escUserId(), $id]);
            escLog('acao.aplicar', 'esc_acao', $id, ['status' => 'PENDENTE'], ['status' => 'FEITA', 'sugestao' => (int) $s['id']]);
        });
        escVoltar('plano_acao', $volta, t('Ação aplicada no rascunho.'));
    }
    throw new RuntimeException(t('Não deu para entender o pedido. Recarregue a tela e tente de novo.'));
}

if (!escAcaoInstalada()) {
    echo '<header class="cabecalho"><span class="eyebrow">' . h(t('Escala')) . '</span><h1>' . h(t('Plano de Ação')) . '</h1></header><section class="panel"><p class="muted">' . h(t('Rode o ES_220 para criar o Plano de Ação.')) . '</p></section>';
    return;
}
$sync = escAcoesSincronizar();

$statusSel = in_array($_GET['status'] ?? '', ['PENDENTE', 'FEITA', 'DESCARTADA', 'todas'], true) ? (string) $_GET['status'] : 'PENDENTE';
$tipoSel = in_array($_GET['tipo'] ?? '', ESC_ACAO_TIPOS, true) ? (string) $_GET['tipo'] : '';
$uSel = in_array((string) ($_GET['u'] ?? ''), ['1', '2', '3', '0'], true) ? (string) $_GET['u'] : '';
$restrito = !escPodeVerRestrito(); // a política de páginas (Owners) é só de WFM/Planejamento e Admin
$where = ['1 = 1'];
$par = [];
if ($restrito) {
    $where[] = "origem <> 'owners'";
}
if ($uSel !== '') {
    $where[] = $uSel === '0' ? 'unidade IS NULL' : 'unidade = ?';
    if ($uSel !== '0') {
        $par[] = (int) $uSel;
    }
}
$contaTipo = [];
foreach (escQ('SELECT tipo, COUNT(*) AS n FROM ' . escT('esc_acao') . ' WHERE ' . implode(' AND ', $where) . ($statusSel !== 'todas' ? ' AND status = ?' : '') . ' GROUP BY tipo',
    array_merge($par, $statusSel !== 'todas' ? [$statusSel] : [])) as $c) {
    $contaTipo[$c['tipo']] = (int) $c['n'];
}
$contaStatus = [];
foreach (escQ('SELECT status, COUNT(*) AS n FROM ' . escT('esc_acao') . ' WHERE ' . implode(' AND ', $where) . ($tipoSel !== '' ? ' AND tipo = ?' : '') . ' GROUP BY status',
    array_merge($par, $tipoSel !== '' ? [$tipoSel] : [])) as $c) {
    $contaStatus[$c['status']] = (int) $c['n'];
}
if ($statusSel !== 'todas') {
    $where[] = 'status = ?';
    $par[] = $statusSel;
}
if ($tipoSel !== '') {
    $where[] = 'tipo = ?';
    $par[] = $tipoSel;
}
$acoes = escQ('SELECT * FROM ' . escT('esc_acao') . ' WHERE ' . implode(' AND ', $where)
    . " ORDER BY FIELD(status, 'PENDENTE', 'FEITA', 'DESCARTADA'), FIELD(tipo, 'ALERTA', 'SAIDA_PAGINA', 'ENTRADA_PAGINA', 'COBERTURA', 'TROCA_PESSOA', 'TROCA_PAGINA', 'RECALCULAR_TIER', 'CARGA', 'PEDIDO', 'DADOS'),
       COALESCE(dia, DATE(criado_em)), id LIMIT 500", $par);
$nomes = escNomes();
$filtro = static fn(array $mais) => array_filter(['status' => $statusSel !== 'PENDENTE' ? $statusSel : null, 'tipo' => $tipoSel ?: null, 'u' => $uSel !== '' ? $uSel : null] + $mais, static fn($v) => $v !== null && $v !== '');
$rotStatus = ['PENDENTE' => [t('Pendente'), 'amarelo'], 'FEITA' => [t('Feita'), 'verde'], 'DESCARTADA' => [t('Descartada'), '']];
$podeDecidir = escPode('decidir');
?>
<header class="cabecalho">
  <span class="eyebrow"><?php echo h(t('Escala')); ?></span>
  <h1><?php echo h(t('Plano de Ação')); ?></h1>
  <p class="muted"><?php echo h(t('Todas as ações sugeridas ou pendentes do sistema: trocas de pessoas e de páginas, lacunas, carga, Recalcular Tier, entradas e saídas de página, pedidos e alertas. A ação fecha sozinha quando a origem deixa de apontar o problema; descartada não volta.')); ?></p>
</header>

<section class="panel">
  <div class="linha-titulo">
    <div class="chips">
      <?php foreach (['PENDENTE' => t('Pendentes'), 'FEITA' => t('Feitas'), 'DESCARTADA' => t('Descartadas'), 'todas' => t('Todas')] as $k => $rot): ?>
      <a class="chip-filtro <?php echo $statusSel === $k ? 'ativo' : ''; ?>" href="<?php echo h(link_tela('plano_acao', array_filter(['status' => $k !== 'PENDENTE' ? $k : null, 'tipo' => $tipoSel ?: null, 'u' => $uSel !== '' ? $uSel : null]))); ?>"><?php echo h($rot); ?><?php echo $k !== 'todas' ? ' · ' . (int) ($contaStatus[$k] ?? 0) : ''; ?></a>
      <?php endforeach; ?>
    </div>
    <?php if ($podeDecidir): ?>
    <form method="post" action="<?php echo h(link_tela('plano_acao', $filtro([]))); ?>" data-longo="<?php echo h(t('Lendo as origens…')); ?>">
      <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="atualizar"><input type="hidden" name="id" value="0">
      <button type="submit" class="botao"><?php echo h(t('Ler as origens agora')); ?></button>
    </form>
    <?php endif; ?>
  </div>
  <div class="chips">
    <span class="muted small"><?php echo h(t('Tipo')); ?></span>
    <a class="chip-filtro <?php echo $tipoSel === '' ? 'ativo' : ''; ?>" href="<?php echo h(link_tela('plano_acao', $filtro(['tipo' => null]))); ?>"><?php echo h(t('Todos')); ?> · <?php echo array_sum($contaTipo); ?></a>
    <?php foreach (ESC_ACAO_TIPOS as $tp): if (empty($contaTipo[$tp]) && $tipoSel !== $tp) { continue; } ?>
    <a class="chip-filtro <?php echo $tipoSel === $tp ? 'ativo' : ''; ?>" href="<?php echo h(link_tela('plano_acao', $filtro(['tipo' => $tp]))); ?>"><?php echo h(escAcaoRotuloTipo($tp)); ?> · <?php echo (int) ($contaTipo[$tp] ?? 0); ?></a>
    <?php endforeach; ?>
  </div>
  <div class="chips">
    <span class="muted small"><?php echo h(t('Unidade')); ?></span>
    <?php foreach (['' => t('Todas'), '1' => 'U1', '2' => 'U2', '3' => 'U3', '0' => t('Sem unidade')] as $k => $rot): ?>
    <a class="chip-filtro <?php echo $uSel === (string) $k ? 'ativo' : ''; ?>" href="<?php echo h(link_tela('plano_acao', $filtro(['u' => (string) $k !== '' ? (string) $k : null]))); ?>"><?php echo h($rot); ?></a>
    <?php endforeach; ?>
  </div>
  <?php $falhas = array_filter($sync, static fn($v) => is_string($v)); if ($falhas): ?>
  <p class="aviso laranja small" role="status"><?php echo h(t('Não deu para ler agora: {lista}. As ações dessas origens ficam como estavam.', ['lista' => implode(', ', array_map('escAcaoRotuloOrigem', array_keys($falhas)))])); ?></p>
  <?php endif; ?>

  <div class="tabela-rolagem">
  <table class="plano-acao">
    <thead><tr><th><?php echo h(t('Tipo')); ?></th><th><?php echo h(t('Ação')); ?></th><th><?php echo h(t('Envolve')); ?></th><th><?php echo h(t('Origem')); ?></th><th><?php echo h(t('Data')); ?></th><th><?php echo h(t('Status')); ?></th><?php if ($podeDecidir): ?><th aria-label="<?php echo h(t('Ações')); ?>"></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($acoes as $a): $d = json_decode((string) $a['dados'], true) ?: []; $lp = json_decode((string) $a['link_params'], true) ?: []; [$stRot, $stCls] = $rotStatus[$a['status']] ?? [$a['status'], '']; ?>
      <tr>
        <td><span class="tag"><?php echo h(escAcaoRotuloTipo((string) $a['tipo'])); ?></span><?php if ($a['unidade'] !== null): ?> <span class="muted small">U<?php echo (int) $a['unidade']; ?></span><?php endif; ?><?php if ($a['turno']): ?> <?php echo chipTurno((string) $a['turno']); ?><?php endif; ?></td>
        <td><strong><?php echo h((string) $a['titulo']); ?></strong><?php if ($a['detalhe']): ?><br><span class="muted small"><?php echo h((string) $a['detalhe']); ?></span><?php endif; ?><?php if ($a['motivo']): ?><br><span class="small"><?php echo h(t('Obs.: {m}', ['m' => $a['motivo']])); ?></span><?php endif; ?></td>
        <td class="small"><?php $env = (string) $a['envolve']; echo h(preg_match('~^[A-Z]\d{6}$~', $env) ? nomeCurto($nomes[$env] ?? $env) . ' · ' . $env : $env); ?></td>
        <td class="small"><?php if ($a['link_tela']): ?><a href="<?php echo h(link_tela((string) $a['link_tela'], $lp)); ?>"><?php echo h(escAcaoRotuloOrigem((string) $a['origem'])); ?> ›</a><?php else: ?><?php echo h(escAcaoRotuloOrigem((string) $a['origem'])); ?><?php endif; ?></td>
        <td class="small"><?php echo h($a['dia'] ? dataBr((string) $a['dia'], true) : dataBr(substr((string) $a['criado_em'], 0, 10))); ?></td>
        <td><span class="tag <?php echo h($stCls); ?>"><?php echo h($stRot); ?></span><?php if ($a['decidido_em']): ?><br><span class="muted small"><?php echo h(escDataHora((string) $a['decidido_em'])); ?></span><?php endif; ?></td>
        <?php if ($podeDecidir): ?>
        <td class="acoes-acao">
          <?php if ($a['status'] === 'PENDENTE'): ?>
          <?php if ($a['tipo'] === 'CARGA' && !empty($d['sugestao_id'])): ?>
          <form method="post" action="<?php echo h(link_tela('plano_acao', $filtro([]))); ?>" class="acoes"><?php echo campoCsrf(); ?><input type="hidden" name="id" value="<?php echo (int) $a['id']; ?>"><input type="hidden" name="acao" value="aplicar"><button type="submit" class="botao primario"><?php echo h(t('Aplicar')); ?></button></form>
          <?php endif; ?>
          <form method="post" action="<?php echo h(link_tela('plano_acao', $filtro([]))); ?>" class="acoes"><?php echo campoCsrf(); ?><input type="hidden" name="id" value="<?php echo (int) $a['id']; ?>"><input type="hidden" name="acao" value="feita"><button type="submit" class="botao"><?php echo h(t('Feita')); ?></button></form>
          <form method="post" action="<?php echo h(link_tela('plano_acao', $filtro([]))); ?>" class="acoes"><?php echo campoCsrf(); ?><input type="hidden" name="id" value="<?php echo (int) $a['id']; ?>"><input type="hidden" name="acao" value="descartar"><input type="text" name="motivo" placeholder="<?php echo h(t('motivo do descarte')); ?>" aria-label="<?php echo h(t('Motivo do descarte')); ?>" required size="14"><button type="submit" class="botao"><?php echo h(t('Descartar')); ?></button></form>
          <?php else: ?>
          <form method="post" action="<?php echo h(link_tela('plano_acao', $filtro([]))); ?>" class="acoes"><?php echo campoCsrf(); ?><input type="hidden" name="id" value="<?php echo (int) $a['id']; ?>"><input type="hidden" name="acao" value="reabrir"><button type="submit" class="botao"><?php echo h(t('Reabrir')); ?></button></form>
          <?php endif; ?>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$acoes): ?><tr><td colspan="7" class="muted"><?php echo h(t('Nenhuma ação neste filtro.')); ?></td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <?php if (count($acoes) === 500): ?><p class="muted small"><?php echo h(t('Mostrando as 500 primeiras. Use os filtros para ver o resto.')); ?></p><?php endif; ?>
</section>
