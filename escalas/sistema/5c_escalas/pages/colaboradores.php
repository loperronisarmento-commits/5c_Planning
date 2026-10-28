<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/colaboradores.php
// Configuração de cada pessoa: turno fixo, modelo, tipo, posição no
// ciclo, folgas fixas. Mudança grava uma nova vigência (histórico).
// ===============================================

if (escPost()) {
    escExigir('cadastrar');
    $rep = strtoupper(trim((string) ($_POST['rep_code'] ?? '')));
    if (!preg_match('~^[A-Z]\d{6}$~', $rep)) {
        throw new RuntimeException('Código inválido (letra + 6 números).');
    }
    $modelo = (int) ($_POST['modelo_id'] ?? 0);
    $turno = (string) ($_POST['turno'] ?? '');
    $tipo = (string) ($_POST['tipo'] ?? 'TIME');
    $trilha = trim((string) ($_POST['trilha'] ?? ''));
    $folgas = '';
    for ($i = 0; $i < 7; $i++) {
        $folgas .= !empty($_POST['folga'][$i]) ? '1' : '0';
    }
    $desde = (string) ($_POST['vigente_desde'] ?? date('Y-m-d'));
    if (!isset(ESC_TURNOS[$turno]) || !in_array($tipo, ['TIME', 'BUFFER', 'NUCLEO'], true) || !preg_match('~^\d{4}-\d{2}-\d{2}$~', $desde)) {
        throw new RuntimeException('Turno, tipo ou data inválidos.');
    }
    $m = escQ1('SELECT id, tipo FROM ' . escT('esc_modelo_escala') . ' WHERE id = ? AND ativo = 1', [$modelo]);
    if (!$m) {
        throw new RuntimeException('Modelo inválido.');
    }
    $novo = [
        'modelo_id' => $modelo, 'tipo' => $tipo, 'turno' => $turno,
        'trilha' => $trilha === '' ? null : max(0, min(13, (int) $trilha)),
        'folgas_fixas' => $m['tipo'] === 'SEMANA' && strpos($folgas, '1') !== false ? $folgas : null,
        'pode_assistente' => !empty($_POST['pode_assistente']) ? 1 : 0,
        'observacao' => mb_substr(trim((string) ($_POST['observacao'] ?? '')), 0, 255) ?: null,
    ];
    escTx(function () use ($rep, $novo, $desde) {
        $atual = escQW('SELECT * FROM ' . escT('esc_colaborador_config') . ' WHERE rep_code = ? AND (vigente_ate IS NULL OR vigente_ate >= ?) ORDER BY vigente_desde DESC LIMIT 1', [$rep, $desde]);
        $atual = $atual[0] ?? null;
        if ($atual && substr($atual['vigente_desde'], 0, 10) >= $desde) {
            escW('UPDATE ' . escT('esc_colaborador_config') . ' SET modelo_id = ?, tipo = ?, turno = ?, trilha = ?, folgas_fixas = ?, pode_assistente = ?, observacao = ?, origem = \'TELA\', atualizado_por = ? WHERE id = ?',
                [$novo['modelo_id'], $novo['tipo'], $novo['turno'], $novo['trilha'], $novo['folgas_fixas'], $novo['pode_assistente'], $novo['observacao'], escUserId(), (int) $atual['id']]);
        } else {
            if ($atual) {
                escW('UPDATE ' . escT('esc_colaborador_config') . ' SET vigente_ate = ?, atualizado_por = ? WHERE id = ?', [escMaisDias($desde, -1), escUserId(), (int) $atual['id']]);
            }
            // A nova vigência leva junto a estrutura de 01/11 (unidade, núcleo, nível, função, fatia, âncora).
            escW('INSERT INTO ' . escT('esc_colaborador_config') . ' (rep_code, modelo_id, tipo, turno, trilha, ancora, folgas_fixas, pode_assistente, unidade, nucleo_id, nivel, funcao, fatia_regular, vigente_desde, observacao, origem, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'TELA\', ?)',
                [$rep, $novo['modelo_id'], $novo['tipo'], $novo['turno'], $novo['trilha'], $atual['ancora'] ?? null, $novo['folgas_fixas'], $novo['pode_assistente'],
                 $atual['unidade'] ?? null, $atual['nucleo_id'] ?? null, $atual['nivel'] ?? null, $atual['funcao'] ?? null, $atual['fatia_regular'] ?? null,
                 $desde, $novo['observacao'], escUserId()]);
        }
        escLog('colaborador.salvar', 'esc_colaborador_config', $rep, $atual, $novo + ['vigente_desde' => $desde]);
    });
    escVoltar('colaboradores', ['q' => $rep], 'Configuração de ' . $rep . ' salva. O próximo recálculo usa a nova configuração.');
}

$hoje = date('Y-m-d');
$config = escMotorConfig($hoje);
$modelos = escQ('SELECT id, nome, tipo FROM ' . escT('esc_modelo_escala') . ' WHERE ativo = 1 ORDER BY nome');
$estrutura = escEstrutura();
$nomes = escNomes();
$estado = [];
foreach (escQ('SELECT e.rep_code, e.trilha_calculada, e.dias_seguidos, e.divergente_config FROM ' . escT('esc_ciclo_estado') . ' e JOIN (SELECT rep_code, MAX(ate_dia) AS d FROM ' . escT('esc_ciclo_estado') . ' GROUP BY rep_code) u ON u.rep_code = e.rep_code AND u.d = e.ate_dia') as $e) {
    $estado[$e['rep_code']] = $e;
}

$busca = trim((string) ($_GET['q'] ?? ''));
$turnoF = (string) ($_GET['turno'] ?? '');
$tipoF = (string) ($_GET['tipo'] ?? '');
$lista = [];
foreach ($config as $rep => $c) {
    $e = $estrutura[$rep] ?? null;
    $nome = $nomes[$rep] ?? '';
    if ($busca !== '' && stripos($rep . ' ' . $nome . ' ' . ($e['party'] ?? ''), $busca) === false) {
        continue;
    }
    if (($turnoF !== '' && $c['turno'] !== $turnoF) || ($tipoF !== '' && $c['tipo'] !== $tipoF)) {
        continue;
    }
    $lista[] = ['rep' => $rep, 'nome' => $nome, 'c' => $c, 'e' => $e, 'est' => $estado[$rep] ?? null];
}
usort($lista, static fn($a, $b) => [$a['e']['team'] ?? 'zz', $a['e']['party'] ?? '', $a['nome']] <=> [$b['e']['team'] ?? 'zz', $b['e']['party'] ?? '', $b['nome']]);
$edit = isset($_GET['editar']) ? strtoupper((string) $_GET['editar']) : '';
$ce = $edit !== '' ? ($config[$edit] ?? null) : null;
$dsem = ['seg', 'ter', 'qua', 'qui', 'sex', 'sáb', 'dom'];
?>
<header class="cabecalho">
  <span class="eyebrow">Cadastros</span>
  <h1>Colaboradores</h1>
  <p class="muted">Turno fixo e modelo de cada pessoa. No 3x1 o turno não roda: só a folga avança. A posição no ciclo vem da releitura do realizado; preencha "trilha" só para forçar outra.</p>
</header>

<?php if ($edit !== '' && escPode('cadastrar')): $c = $ce ?? ['modelo_id' => 0, 'tipo' => 'TIME', 'turno' => 'A', 'trilha' => null, 'folgas_fixas' => null, 'pode_assistente' => 1]; ?>
<section class="panel" id="editar">
  <h2><?php echo $ce ? 'Editar ' . h($edit) . ' · ' . h($nomes[$edit] ?? '') : 'Novo colaborador'; ?></h2>
  <form method="post" action="<?php echo h(link_tela('colaboradores')); ?>" class="form-grade">
    <?php echo campoCsrf(); ?>
    <label>Colaborador<input type="search" name="rep_code" value="<?php echo h($edit === 'NOVO' ? '' : $edit); ?>" <?php echo $ce ? 'readonly' : 'list="lista-colaboradores" autocomplete="off" placeholder="buscar nome ou código"'; ?> required></label>
    <label>Modelo<select name="modelo_id"><?php foreach ($modelos as $m): ?><option value="<?php echo (int) $m['id']; ?>" <?php echo (int) $m['id'] === (int) $c['modelo_id'] ? 'selected' : ''; ?>><?php echo h($m['nome']); ?></option><?php endforeach; ?></select></label>
    <label>Turno fixo<select name="turno"><?php foreach (['A' => 'A · 05–13', 'C' => 'C · 13–21', 'D' => 'D · 21–05'] as $t => $r): ?><option value="<?php echo $t; ?>" <?php echo $c['turno'] === $t ? 'selected' : ''; ?>><?php echo h($r); ?></option><?php endforeach; ?></select></label>
    <label>Tipo<select name="tipo"><option value="TIME" <?php echo $c['tipo'] === 'TIME' ? 'selected' : ''; ?>>Time</option><option value="BUFFER" <?php echo $c['tipo'] === 'BUFFER' ? 'selected' : ''; ?>>Buffer</option><option value="NUCLEO" <?php echo $c['tipo'] === 'NUCLEO' ? 'selected' : ''; ?>>Núcleo (estrutura nova)</option></select></label>
    <label>Trilha (vazio = calcular)<input type="number" name="trilha" min="0" max="13" value="<?php echo h($c['trilha'] ?? ''); ?>"></label>
    <label>Vale a partir de<input type="date" name="vigente_desde" value="<?php echo h(date('Y-m-d')); ?>"></label>
    <fieldset class="dias-folga"><legend>Folgas fixas (só modelo semana fixa)</legend>
      <?php for ($i = 0; $i < 7; $i++): ?><label class="small"><input type="checkbox" name="folga[<?php echo $i; ?>]" value="1" <?php echo !empty($c['folgas_fixas']) && $c['folgas_fixas'][$i] === '1' ? 'checked' : ''; ?>> <?php echo $dsem[$i]; ?></label><?php endfor; ?>
    </fieldset>
    <label class="small"><input type="checkbox" name="pode_assistente" value="1" <?php echo (int) $c['pode_assistente'] ? 'checked' : ''; ?>> pode ser assistente</label>
    <label>Observação<input type="text" name="observacao" value="<?php echo h($c['observacao'] ?? ''); ?>"></label>
    <div class="acoes"><button type="submit" class="botao primario">Salvar</button><a class="botao" href="<?php echo h(link_tela('colaboradores')); ?>">Cancelar</a></div>
  </form>
</section>
<?php endif; ?>

<section class="panel">
  <form method="get" action="<?php echo h(MODULE_URL); ?>/index.php" class="acoes">
    <input type="hidden" name="p" value="colaboradores">
    <input type="search" name="q" value="<?php echo h($busca); ?>" placeholder="Nome, código ou party" aria-label="Buscar">
    <select name="turno" onchange="this.form.submit()" aria-label="Turno"><option value="">Todos os turnos</option><?php foreach (['A', 'C', 'D'] as $t): ?><option <?php echo $turnoF === $t ? 'selected' : ''; ?>><?php echo $t; ?></option><?php endforeach; ?></select>
    <select name="tipo" onchange="this.form.submit()" aria-label="Tipo"><option value="">Time e Buffer</option><option value="TIME" <?php echo $tipoF === 'TIME' ? 'selected' : ''; ?>>Time</option><option value="BUFFER" <?php echo $tipoF === 'BUFFER' ? 'selected' : ''; ?>>Buffer</option></select>
    <button type="submit" class="botao">Filtrar</button>
    <?php if (escPode('cadastrar')): ?><a class="botao primario" href="<?php echo h(link_tela('colaboradores', ['editar' => 'NOVO'])); ?>#editar">Novo</a><?php endif; ?>
  </form>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th>Pessoa</th><th>Time · party · papel</th><th>Turno</th><th>Modelo</th><th class="num">Trilha</th><th class="num">Dias seguidos</th><th></th></tr></thead>
    <tbody>
    <?php foreach (array_slice($lista, 0, 400) as $l): $c = $l['c']; ?>
      <tr>
        <td><strong><?php echo h(nomeCurto($l['nome'])); ?></strong><br><span class="muted small mono"><?php echo h($l['rep']); ?></span></td>
        <td class="small"><?php echo $c['tipo'] === 'BUFFER' ? 'Buffer' : h(($l['e']['team'] ?? 'sem estrutura') . ' · ' . ($l['e']['party'] ?? '') . ' · ' . ($l['e']['role'] ?? '')); ?></td>
        <td><?php echo chipTurno($c['turno']); ?></td>
        <td class="small"><?php echo h($c['modelo']); ?><?php echo $c['folgas_fixas'] ? ' · folgas ' . h($c['folgas_fixas']) : ''; ?></td>
        <td class="num"><?php echo $c['trilha'] !== null ? (int) $c['trilha'] . ' (fixa)' : ($l['est'] && $l['est']['trilha_calculada'] !== null ? (int) $l['est']['trilha_calculada'] : '—'); ?><?php echo $l['est'] && (int) $l['est']['divergente_config'] ? ' <span class="tag laranja">difere do realizado</span>' : ''; ?></td>
        <td class="num"><?php echo $l['est'] ? (int) $l['est']['dias_seguidos'] : '—'; ?></td>
        <td><?php if (escPode('cadastrar')): ?><a class="botao" href="<?php echo h(link_tela('colaboradores', ['editar' => $l['rep']])); ?>#editar">Editar</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$lista): ?><tr><td colspan="7" class="muted">Ninguém neste filtro.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <p class="muted small"><?php echo count($lista); ?> pessoa(s). Trilha e dias seguidos aparecem depois da primeira geração (releitura do realizado).</p>
</section>
