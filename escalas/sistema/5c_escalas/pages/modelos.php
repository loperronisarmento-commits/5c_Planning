<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/modelos.php
// Modelos de escala: o ciclo de trabalho e folga. O limite de anomalia
// (dias seguidos) sai do ciclo, não é digitado.
// ===============================================

if (escPost()) {
    escExigir('admin');
    $id = (int) ($_POST['id'] ?? 0);
    $nome = trim((string) ($_POST['nome'] ?? ''));
    $tipo = (string) ($_POST['tipo'] ?? 'CONTINUO');
    $ciclo = preg_replace('~[^01]~', '', (string) ($_POST['ciclo'] ?? ''));
    if ($nome === '' || !in_array($tipo, ['CONTINUO', 'SEMANA'], true)) {
        throw new RuntimeException('Nome e tipo são obrigatórios.');
    }
    if ($tipo === 'SEMANA') {
        $ciclo = str_pad(substr($ciclo, 0, 7), 7, '0');
    }
    if (strlen($ciclo) < 2 || strlen($ciclo) > 14 || strpos($ciclo, '0') === false || strpos($ciclo, '1') === false) {
        throw new RuntimeException('O ciclo precisa ter de 2 a 14 dias, com trabalho (1) e folga (0). Ex.: 1110.');
    }
    $dados = [
        'nome' => mb_substr($nome, 0, 80), 'tipo' => $tipo, 'ciclo' => $ciclo,
        'horas_dia' => max(1, min(12, (float) str_replace(',', '.', (string) ($_POST['horas_dia'] ?? 8)))),
        'trilhas' => max(1, min(14, (int) ($_POST['trilhas'] ?? 1))),
        'admin_a_cada' => (int) ($_POST['admin_a_cada'] ?? 0) ?: null,
        'turno_d_conta_inicio' => !empty($_POST['turno_d_conta_inicio']) ? 1 : 0,
        'max_seguidos' => escMaxSeguidos($ciclo),
        'ativo' => !empty($_POST['ativo']) ? 1 : 0,
    ];
    escTx(function () use ($id, $dados) {
        if ($id) {
            $antes = escQW('SELECT * FROM ' . escT('esc_modelo_escala') . ' WHERE id = ?', [$id]);
            escW('UPDATE ' . escT('esc_modelo_escala') . ' SET nome = ?, tipo = ?, ciclo = ?, horas_dia = ?, trilhas = ?, admin_a_cada = ?, turno_d_conta_inicio = ?, max_seguidos = ?, ativo = ?, atualizado_por = ? WHERE id = ?',
                [$dados['nome'], $dados['tipo'], $dados['ciclo'], $dados['horas_dia'], $dados['trilhas'], $dados['admin_a_cada'], $dados['turno_d_conta_inicio'], $dados['max_seguidos'], $dados['ativo'], escUserId(), $id]);
            escLog('modelo.salvar', 'esc_modelo_escala', $id, $antes[0] ?? null, $dados);
        } else {
            escW('INSERT INTO ' . escT('esc_modelo_escala') . ' (nome, tipo, ciclo, horas_dia, trilhas, admin_a_cada, turno_d_conta_inicio, max_seguidos, ativo, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$dados['nome'], $dados['tipo'], $dados['ciclo'], $dados['horas_dia'], $dados['trilhas'], $dados['admin_a_cada'], $dados['turno_d_conta_inicio'], $dados['max_seguidos'], $dados['ativo'], escUserId()]);
            escLog('modelo.criar', 'esc_modelo_escala', escUltimoId(), null, $dados);
        }
    });
    escVoltar('modelos', [], 'Modelo "' . $dados['nome'] . '" salvo. Anomalia a partir de ' . ($dados['max_seguidos'] + 1) . ' dias seguidos.');
}

$modelos = escQ('SELECT m.*, (SELECT COUNT(*) FROM ' . escT('esc_colaborador_config') . ' c WHERE c.modelo_id = m.id AND (c.vigente_ate IS NULL OR c.vigente_ate >= CURDATE())) AS pessoas FROM ' . escT('esc_modelo_escala') . ' m ORDER BY m.ativo DESC, m.nome');
$edit = isset($_GET['editar']) ? (int) $_GET['editar'] : -1;
$me = null;
foreach ($modelos as $m) {
    if ((int) $m['id'] === $edit) {
        $me = $m;
    }
}
$quadrados = static function (string $ciclo): string {
    $html = '';
    foreach (str_split($ciclo) as $c) {
        $html .= '<span class="quadrado ' . ($c === '1' ? 'trabalho' : 'folga') . '"></span>';
    }
    return $html;
};
?>
<header class="cabecalho">
  <span class="eyebrow">Cadastros</span>
  <h1>Modelos de escala</h1>
  <p class="muted">Cada modelo é um ciclo de dias de trabalho (1) e folga (0). Um modelo novo (6x1, 4x3, 12x36…) é só um cadastro: a geração lê o ciclo.</p>
</header>

<section class="panel">
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th>Modelo</th><th>Ciclo</th><th>Tipo</th><th class="num">Pessoas</th><th>EXCESSO_CONSECUTIVO</th><th class="num">Horas/dia</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($modelos as $m): ?>
      <tr class="<?php echo (int) $m['ativo'] ? '' : 'inativo'; ?>">
        <td><strong><?php echo h($m['nome']); ?></strong><?php echo (int) $m['ativo'] ? '' : ' <span class="tag">inativo</span>'; ?><?php echo $m['admin_a_cada'] ? '<br><span class="muted small">dia admin a cada ' . (int) $m['admin_a_cada'] . '</span>' : ''; ?></td>
        <td><?php echo $quadrados($m['ciclo']); ?> <span class="mono small"><?php echo h($m['ciclo']); ?></span></td>
        <td class="small"><?php echo $m['tipo'] === 'SEMANA' ? 'semana fixa' : 'contínuo · ' . (int) $m['trilhas'] . ' trilhas'; ?></td>
        <td class="num"><?php echo (int) $m['pessoas']; ?></td>
        <td class="small">a partir de <?php echo (int) $m['max_seguidos'] + 1; ?> seguidos</td>
        <td class="num"><?php echo num($m['horas_dia'], 1); ?></td>
        <td><?php if (escPode('admin')): ?><a class="botao" href="<?php echo h(link_tela('modelos', ['editar' => $m['id']])); ?>#editar">Editar</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if (escPode('admin')): ?><a class="botao primario" href="<?php echo h(link_tela('modelos', ['editar' => 0])); ?>#editar">Novo modelo</a><?php endif; ?>
</section>

<?php if ($edit >= 0 && escPode('admin')): $m = $me ?? ['id' => 0, 'nome' => '', 'tipo' => 'CONTINUO', 'ciclo' => '1111110', 'horas_dia' => 8, 'trilhas' => 7, 'admin_a_cada' => null, 'turno_d_conta_inicio' => 1, 'ativo' => 1]; ?>
<section class="panel" id="editar">
  <h2><?php echo $me ? 'Editar ' . h($me['nome']) : 'Novo modelo'; ?></h2>
  <form method="post" action="<?php echo h(link_tela('modelos')); ?>" class="form-grade">
    <?php echo campoCsrf(); ?><input type="hidden" name="id" value="<?php echo (int) $m['id']; ?>">
    <label>Nome<input type="text" name="nome" value="<?php echo h($m['nome']); ?>" required></label>
    <label>Tipo de ciclo<select name="tipo"><option value="CONTINUO" <?php echo $m['tipo'] === 'CONTINUO' ? 'selected' : ''; ?>>Contínuo (folga avança pelo calendário)</option><option value="SEMANA" <?php echo $m['tipo'] === 'SEMANA' ? 'selected' : ''; ?>>Semana fixa (seg a dom)</option></select></label>
    <label>Ciclo (1 = trabalho, 0 = folga)<input type="text" name="ciclo" value="<?php echo h($m['ciclo']); ?>" pattern="[01]{2,14}" required></label>
    <label>Horas por dia<input type="number" name="horas_dia" step="0.5" min="1" max="12" value="<?php echo h($m['horas_dia']); ?>"></label>
    <label>Trilhas defasadas<input type="number" name="trilhas" min="1" max="14" value="<?php echo (int) $m['trilhas']; ?>"></label>
    <label>Dia admin a cada (0 = não)<input type="number" name="admin_a_cada" min="0" max="30" value="<?php echo (int) $m['admin_a_cada']; ?>"></label>
    <label class="small"><input type="checkbox" name="turno_d_conta_inicio" value="1" <?php echo (int) $m['turno_d_conta_inicio'] ? 'checked' : ''; ?>> turno D conta no dia de início</label>
    <label class="small"><input type="checkbox" name="ativo" value="1" <?php echo (int) $m['ativo'] ? 'checked' : ''; ?>> ativo</label>
    <div class="acoes"><button type="submit" class="botao primario">Salvar modelo</button><a class="botao" href="<?php echo h(link_tela('modelos')); ?>">Cancelar</a></div>
  </form>
  <p class="muted small">Exemplos: 3x1 = 1110 · 6x1 = 1111110 · 5x2 semana fixa = 1111100 · 12x36 (dia sim, dia não) = 10.</p>
</section>
<?php endif; ?>
