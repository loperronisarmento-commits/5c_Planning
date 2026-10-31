<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/modelos.php
// Modelos de escala: o ciclo de trabalho e folga. O limite de anomalia
// (dias seguidos) é digitado, nunca abaixo da maior sequência do próprio ciclo
// (ex.: 5x2 rotativo de 21 dias: o ciclo tem até 4 seguidos, o limite é 5).
// ===============================================

if (escPost() && ($_POST['acao'] ?? '') === 'excluir') {
    // Excluir: sem ninguém configurado no modelo (nem no histórico), apaga; com histórico,
    // desativa (some das escolhas e da lista principal, e as semanas antigas continuam lendo o ciclo).
    escExigir('admin');
    $id = (int) ($_POST['id'] ?? 0);
    $m = escQ1('SELECT id, nome FROM ' . escT('esc_modelo_escala') . ' WHERE id = ?', [$id]);
    if (!$m) {
        throw new RuntimeException(t('Modelo não encontrado.'));
    }
    $uso = escQ1('SELECT COUNT(*) AS total, SUM(vigente_ate IS NULL OR vigente_ate >= CURDATE()) AS vigentes, MAX(vigente_ate) AS ate,
                         SUM(vigente_ate IS NULL) AS sem_fim FROM ' . escT('esc_colaborador_config') . ' WHERE modelo_id = ?', [$id]);
    if ((int) $uso['sem_fim'] > 0) {
        throw new RuntimeException(t('"{nome}" ainda tem {n} pessoa(s) sem data de fim. Passe essas pessoas para outro modelo em Colaboradores antes de excluir.', ['nome' => $m['nome'], 'n' => (int) $uso['sem_fim']]));
    }
    if ((int) $uso['total'] === 0) {
        escW('DELETE FROM ' . escT('esc_modelo_escala') . ' WHERE id = ?', [$id]);
        escLog('modelo.excluir', 'esc_modelo_escala', $id, $m, null);
        escVoltar('modelos', [], t('Modelo "{nome}" excluído.', ['nome' => $m['nome']]));
    }
    escW('UPDATE ' . escT('esc_modelo_escala') . ' SET ativo = 0, atualizado_por = ? WHERE id = ?', [escUserId(), $id]);
    escLog('modelo.desativar', 'esc_modelo_escala', $id, $m, ['ativo' => 0]);
    $varsDesat = ['nome' => $m['nome'], 'n' => (int) $uso['total'], 'dia' => dataBr((string) $uso['ate'])];
    escVoltar('modelos', [], (int) $uso['vigentes']
        ? t('Modelo "{nome}" desativado: tem histórico de {n} configuração(ões), em uso até {dia}. Não aparece mais para escolher; as semanas antigas continuam usando o ciclo.', $varsDesat)
        : t('Modelo "{nome}" desativado: tem histórico de {n} configuração(ões). Não aparece mais para escolher; as semanas antigas continuam usando o ciclo.', $varsDesat));
}

if (escPost()) {
    escExigir('admin');
    $id = (int) ($_POST['id'] ?? 0);
    $nome = trim((string) ($_POST['nome'] ?? ''));
    $tipo = (string) ($_POST['tipo'] ?? 'CONTINUO');
    $ciclo = preg_replace('~[^01]~', '', (string) ($_POST['ciclo'] ?? ''));
    if ($nome === '' || !in_array($tipo, ['CONTINUO', 'SEMANA'], true)) {
        throw new RuntimeException(t('Nome e tipo são obrigatórios.'));
    }
    if ($tipo === 'SEMANA') {
        $ciclo = str_pad(substr($ciclo, 0, 7), 7, '0');
    }
    if (strlen($ciclo) < 2 || strlen($ciclo) > 28 || strpos($ciclo, '0') === false || strpos($ciclo, '1') === false) {
        throw new RuntimeException(t('O ciclo precisa ter de 2 a 28 dias, com trabalho (1) e folga (0). Ex.: 1110.'));
    }
    $dados = [
        'nome' => mb_substr($nome, 0, 80), 'tipo' => $tipo, 'ciclo' => $ciclo,
        'horas_dia' => max(1, min(12, (float) str_replace(',', '.', (string) ($_POST['horas_dia'] ?? 8)))),
        'trilhas' => max(1, min(14, (int) ($_POST['trilhas'] ?? 1))),
        'admin_a_cada' => (int) ($_POST['admin_a_cada'] ?? 0) ?: null,
        'turno_d_conta_inicio' => !empty($_POST['turno_d_conta_inicio']) ? 1 : 0,
        'max_seguidos' => max(escMaxSeguidos($ciclo), min(14, (int) ($_POST['max_seguidos'] ?? 0))),
        'ativo' => !empty($_POST['ativo']) ? 1 : 0,
    ];
    if ($id) {
        $nomeAntes = (string) (escQ1('SELECT nome FROM ' . escT('esc_modelo_escala') . ' WHERE id = ?', [$id])['nome'] ?? '');
        if ($nomeAntes === '5x2 rotativo 21 dias' && $dados['nome'] !== $nomeAntes) {
            throw new RuntimeException(t('O modelo "5x2 rotativo 21 dias" é o da estrutura nova e não pode mudar de nome (o motor e a liderança procuram por ele).'));
        }
    }
    if ($id && !$dados['ativo']) {
        $semFim = (int) escQ1('SELECT COUNT(*) AS n FROM ' . escT('esc_colaborador_config') . ' WHERE modelo_id = ? AND vigente_ate IS NULL', [$id])['n'];
        if ($semFim > 0) {
            throw new RuntimeException(t('Não dá para desativar: {n} pessoa(s) ainda usam este modelo sem data de fim. Passe essas pessoas para outro modelo em Colaboradores antes.', ['n' => $semFim]));
        }
    }
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
    escVoltar('modelos', [], t('Modelo "{nome}" salvo. Anomalia a partir de {n} dias seguidos.', ['nome' => $dados['nome'], 'n' => $dados['max_seguidos'] + 1]));
}

$modelos = escQ('SELECT m.*, (SELECT COUNT(*) FROM ' . escT('esc_colaborador_config') . ' c WHERE c.modelo_id = m.id AND (c.vigente_ate IS NULL OR c.vigente_ate >= CURDATE())) AS pessoas,
                         (SELECT COUNT(*) FROM ' . escT('esc_colaborador_config') . ' c WHERE c.modelo_id = m.id AND c.vigente_ate IS NULL) AS sem_fim,
                         (SELECT MAX(c.vigente_ate) FROM ' . escT('esc_colaborador_config') . ' c WHERE c.modelo_id = m.id) AS ate,
                         (SELECT COUNT(*) FROM ' . escT('esc_colaborador_config') . ' c WHERE c.modelo_id = m.id) AS historico
                    FROM ' . escT('esc_modelo_escala') . ' m ORDER BY m.ativo DESC, m.nome');
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
  <span class="eyebrow"><?php echo h(t('Pessoas')); ?></span>
  <h1><?php echo h(t('Modelos de escala')); ?></h1>
  <p class="muted"><?php echo h(t('Cada modelo é um ciclo de dias de trabalho (1) e folga (0). Um modelo novo (6x1, 4x3, 12x36…) é só um cadastro: a geração lê o ciclo.')); ?></p>
</header>

<section class="panel">
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th><?php echo h(t('Modelo')); ?></th><th><?php echo h(t('Ciclo')); ?></th><th><?php echo h(t('Tipo')); ?></th><th class="num"><?php echo h(t('Pessoas')); ?></th><th><?php echo h(t('Anomalia')); ?></th><th class="num"><?php echo h(t('Horas/dia')); ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($modelos as $m): ?>
      <tr class="<?php echo (int) $m['ativo'] ? '' : 'inativo'; ?>">
        <td><strong><?php echo h($m['nome']); ?></strong><?php echo (int) $m['ativo'] ? '' : ' <span class="tag">' . h(t('inativo')) . '</span>'; ?><?php echo $m['admin_a_cada'] ? '<br><span class="muted small">' . h(t('dia admin a cada {n}', ['n' => (int) $m['admin_a_cada']])) . '</span>' : ''; ?></td>
        <td><?php echo $quadrados($m['ciclo']); ?> <span class="mono small"><?php echo h($m['ciclo']); ?></span></td>
        <td class="small"><?php echo h(escRotulo('tipo_modelo', $m['tipo'])) . ($m['tipo'] === 'SEMANA' ? '' : ' · ' . h(tn((int) $m['trilhas'], '{n} trilha', '{n} trilhas'))); ?></td>
        <td class="num"><?php echo (int) $m['pessoas']; ?><?php echo (int) $m['pessoas'] && !(int) $m['sem_fim'] && $m['ate'] ? '<br><span class="muted small">' . h(t('até {dia}', ['dia' => dataBr($m['ate'])])) . '</span>' : ''; ?></td>
        <td class="small"><?php echo h(t('a partir de {n} seguidos', ['n' => (int) $m['max_seguidos'] + 1])); ?></td>
        <td class="num"><?php echo num($m['horas_dia'], 1); ?></td>
        <td class="nowrap"><?php if (escPode('admin')): ?>
          <a class="botao" href="<?php echo h(link_tela('modelos', ['editar' => $m['id']])); ?>#editar"><?php echo h(t('Editar')); ?></a>
          <?php if ((int) $m['sem_fim'] === 0 && ((int) $m['ativo'] || !(int) $m['historico'])): $apaga = !(int) $m['historico']; ?>
          <form method="post" action="<?php echo h(link_tela('modelos')); ?>" style="display:inline" data-confirmar="<?php echo h($apaga ? t('Excluir o modelo "{nome}"?', ['nome' => $m['nome']]) : t('Desativar o modelo "{nome}"?', ['nome' => $m['nome']]) . "\n\n" . t('Ele tem histórico: some das escolhas, e as semanas antigas continuam usando o ciclo.')); ?>">
            <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" value="<?php echo (int) $m['id']; ?>">
            <button type="submit" class="botao perigo"><?php echo $apaga ? h(t('Excluir')) : h(t('Desativar')); ?></button>
          </form>
          <?php elseif ((int) $m['sem_fim'] > 0): ?><span class="muted small" title="<?php echo h(t('Passe essas pessoas para outro modelo antes')); ?>"><?php echo h(t('em uso')); ?></span><?php endif; ?>
        <?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if (escPode('admin')): ?><a class="botao primario" href="<?php echo h(link_tela('modelos', ['editar' => 0])); ?>#editar"><?php echo h(t('Novo modelo')); ?></a><?php endif; ?>
</section>

<?php if ($edit >= 0 && escPode('admin')): $m = $me ?? ['id' => 0, 'nome' => '', 'tipo' => 'CONTINUO', 'ciclo' => '1111110', 'horas_dia' => 8, 'trilhas' => 7, 'admin_a_cada' => null, 'turno_d_conta_inicio' => 1, 'ativo' => 1]; ?>
<section class="panel" id="editar">
  <h2><?php echo $me ? h(t('Editar {nome}', ['nome' => $me['nome']])) : h(t('Novo modelo')); ?></h2>
  <form method="post" action="<?php echo h(link_tela('modelos', ['editar' => (int) $m['id']])); ?>" class="form-grade" id="form-modelo" data-ciclo="<?php echo h($m['ciclo']); ?>" data-pessoas="<?php echo (int) ($m['sem_fim'] ?? 0); ?>">
    <?php echo campoCsrf(); ?><input type="hidden" name="id" value="<?php echo (int) $m['id']; ?>">
    <label><?php echo h(t('Nome')); ?><input type="text" name="nome" value="<?php echo h($m['nome']); ?>" required></label>
    <label><?php echo h(t('Tipo de ciclo')); ?><select name="tipo"><option value="CONTINUO" <?php echo $m['tipo'] === 'CONTINUO' ? 'selected' : ''; ?>><?php echo h(t('Contínuo (folga avança pelo calendário)')); ?></option><option value="SEMANA" <?php echo $m['tipo'] === 'SEMANA' ? 'selected' : ''; ?>><?php echo h(t('Semana fixa (seg a dom)')); ?></option></select></label>
    <label><?php echo h(t('Ciclo (1 = trabalho, 0 = folga)')); ?><input type="text" name="ciclo" value="<?php echo h($m['ciclo']); ?>" pattern="[01]{2,28}" required></label>
    <label><?php echo h(t('Máximo de dias seguidos (anomalia acima disso)')); ?><input type="number" name="max_seguidos" min="1" max="14" value="<?php echo (int) ($m['max_seguidos'] ?? escMaxSeguidos($m['ciclo'])); ?>"></label>
    <label><?php echo h(t('Horas por dia')); ?><input type="number" name="horas_dia" step="0.5" min="1" max="12" value="<?php echo h($m['horas_dia']); ?>"></label>
    <label><?php echo h(t('Trilhas defasadas')); ?><input type="number" name="trilhas" min="1" max="14" value="<?php echo (int) $m['trilhas']; ?>"></label>
    <label><?php echo h(t('Dia admin a cada (0 = não)')); ?><input type="number" name="admin_a_cada" min="0" max="30" value="<?php echo (int) $m['admin_a_cada']; ?>"></label>
    <label class="small"><input type="checkbox" name="turno_d_conta_inicio" value="1" <?php echo (int) $m['turno_d_conta_inicio'] ? 'checked' : ''; ?>> <?php echo h(t('turno D conta no dia de início')); ?></label>
    <label class="small"><input type="checkbox" name="ativo" value="1" <?php echo (int) $m['ativo'] ? 'checked' : ''; ?><?php echo (int) ($m['sem_fim'] ?? 0) > 0 ? ' aria-describedby="ativo-uso"' : ''; ?>> <?php echo h(t('ativo')); ?></label>
    <?php if ((int) ($m['sem_fim'] ?? 0) > 0): ?><span class="muted small" id="ativo-uso"><?php echo h(tn((int) $m['sem_fim'], 'Em uso por {n} pessoa sem data de fim: para desativar, passe essa pessoa para outro modelo antes.', 'Em uso por {n} pessoas sem data de fim: para desativar, passe essas pessoas para outro modelo antes.')); ?></span><?php endif; ?>
    <div class="acoes"><button type="submit" class="botao primario"><?php echo h(t('Salvar modelo')); ?></button><a class="botao" href="<?php echo h(link_tela('modelos')); ?>"><?php echo h(t('Cancelar')); ?></a></div>
  </form>
  <p class="muted small"><?php echo h(t('Exemplos: 3x1 = 1110 · 6x1 = 1111110 · 5x2 semana fixa = 1111100 · 12x36 (dia sim, dia não) = 10.')); ?></p>
  <script>
  (function () {
    var f = document.getElementById('form-modelo');
    f.addEventListener('submit', function (ev) {
      var n = +f.dataset.pessoas, novo = f.querySelector('input[name="ciclo"]').value.replace(/[^01]/g, '');
      if (!n || novo === f.dataset.ciclo || f.dataset.confirmado === '1') { delete f.dataset.confirmado; return; }
      ev.preventDefault();
      ev.stopImmediatePropagation();
      escConfirmar(<?php echo json_encode(t('Mudar o ciclo de um modelo em uso?')); ?> + '\n\n' + f.dataset.ciclo + ' → ' + novo + '\n\n' + <?php echo json_encode(t('Afeta {n} pessoa(s): todas passam a seguir o ciclo novo no próximo recálculo. Para mudar só algumas, crie um modelo novo e troque essas pessoas em Colaboradores.')); ?>.replace('{n}', n), { botao: <?php echo json_encode(t('Mudar o ciclo')); ?>, perigo: true }).then(function (ok) {
        if (ok) { f.dataset.confirmado = '1'; f.requestSubmit(); }
      });
    });
  })();
  </script>
</section>
<?php endif; ?>
