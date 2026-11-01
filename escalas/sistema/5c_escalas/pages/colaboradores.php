<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/colaboradores.php
// Configuração de cada pessoa na data escolhida (?dia=). Da virada em diante
// (estrutura nova): unidade, núcleo, turno, nível de SR e modelo 5x2; antes
// dela, o cadastro antigo (turno fixo, modelo 3x1, trilha, folgas fixas).
// Mudança grava uma nova vigência (histórico). Trocar de núcleo fecha as
// vagas fixas da pessoa no núcleo antigo; o motor cobre essas vagas. Configuração
// já marcada para depois da data é cancelada: vale a que foi salva agora.
// ===============================================

$diaEstrutura = escV4DiaEstrutura();

if (escPost()) {
    escExigir('cadastrar');
    $rep = strtoupper(trim((string) ($_POST['rep_code'] ?? '')));
    // A busca manda "Nome (CÓDIGO)"; também vale o código puro.
    if (preg_match('~\(([A-Z]\d{6})\)\s*$~', $rep, $m)) {
        $rep = $m[1];
    }
    if (!preg_match('~^[A-Z]\d{6}$~', $rep)) {
        throw new RuntimeException(t('Código inválido (letra + 6 números).'));
    }
    $modelo = (int) ($_POST['modelo_id'] ?? 0);
    $desde = (string) ($_POST['vigente_desde'] ?? '') ?: date('Y-m-d');
    if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $desde)) {
        throw new RuntimeException(t('Data inválida.'));
    }
    $m = escQ1('SELECT id, tipo FROM ' . escT('esc_modelo_escala') . ' WHERE id = ? AND ativo = 1', [$modelo]);
    if (!$m) {
        throw new RuntimeException(t('Modelo inválido.'));
    }
    $nucleo = null;
    if (isset($_POST['nucleo_id'])) {
        // Estrutura nova: o núcleo define unidade e turno.
        if ($desde < $diaEstrutura) {
            throw new RuntimeException(t('Na estrutura nova a vigência começa em {dia} ou depois.', ['dia' => dataBr($diaEstrutura)]));
        }
        $nucleo = escQ1('SELECT id, unidade, turno, numero FROM ' . escT('esc_nucleo') . ' WHERE id = ? AND vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)', [(int) $_POST['nucleo_id'], $desde, $desde]);
        if (!$nucleo) {
            throw new RuntimeException(t('Núcleo inválido na data.'));
        }
        $nivel = (string) ($_POST['nivel'] ?? '');
        $novo = [
            'modelo_id' => $modelo, 'tipo' => 'NUCLEO', 'turno' => $nucleo['turno'], 'trilha' => null, 'folgas_fixas' => null,
            'unidade' => (int) $nucleo['unidade'], 'nucleo_id' => (int) $nucleo['id'], 'nivel' => $nivel === '' ? null : max(0, min(5, (int) $nivel)),
        ];
    } else {
        if ($desde >= $diaEstrutura) {
            throw new RuntimeException(t('A partir de {dia} vale a estrutura nova: escolha uma data a partir dela para editar unidade, turno e núcleo.', ['dia' => dataBr($diaEstrutura)]));
        }
        $turno = (string) ($_POST['turno'] ?? '');
        $tipo = (string) ($_POST['tipo'] ?? 'TIME');
        $trilha = trim((string) ($_POST['trilha'] ?? ''));
        $folgas = '';
        for ($i = 0; $i < 7; $i++) {
            $folgas .= !empty($_POST['folga'][$i]) ? '1' : '0';
        }
        if (!isset(ESC_TURNOS[$turno]) || !in_array($tipo, ['TIME', 'BUFFER', 'NUCLEO'], true)) {
            throw new RuntimeException(t('Turno ou tipo inválidos.'));
        }
        $novo = [
            'modelo_id' => $modelo, 'tipo' => $tipo, 'turno' => $turno,
            'trilha' => $trilha === '' ? null : max(0, min(13, (int) $trilha)),
            'folgas_fixas' => $m['tipo'] === 'SEMANA' && strpos($folgas, '1') !== false ? $folgas : null,
        ];
    }
    $novo += [
        'pode_assistente' => !empty($_POST['pode_assistente']) ? 1 : 0,
        'observacao' => mb_substr(trim((string) ($_POST['observacao'] ?? '')), 0, 255) ?: null,
    ];
    $vagasFechadas = 0;
    $canceladas = [];
    escTx(function () use ($rep, $novo, $desde, $nucleo, &$vagasFechadas, &$canceladas) {
        // Configuração marcada para depois da data (ex.: remanejamento a partir de 09/11): a
        // mudança de agora vale daí em diante, então a futura é cancelada (nunca chegou a valer),
        // e as vagas fixas que começariam junto com ela (ou depois) vão para a data neutra.
        $neutra = escMaisDias(escV4DiaVirada(), -2);
        foreach (escQW('SELECT * FROM ' . escT('esc_colaborador_config') . ' WHERE rep_code = ? AND vigente_desde > ? ORDER BY vigente_desde FOR UPDATE', [$rep, $desde]) as $f) {
            $canceladas[] = $f;
            escW('DELETE FROM ' . escT('esc_colaborador_config') . ' WHERE id = ?', [(int) $f['id']]);
        }
        if ($canceladas) {
            foreach (escQW('SELECT id FROM ' . escT('esc_vaga_equipe') . ' WHERE rep_code = ? AND vigente_desde >= ?', [$rep, substr((string) $canceladas[0]['vigente_desde'], 0, 10)]) as $v) {
                escVagaNeutra((int) $v['id'], $neutra);
            }
        }
        // A configuração que vale NA data informada (não a mais nova).
        $atual = escQW('SELECT * FROM ' . escT('esc_colaborador_config') . ' WHERE rep_code = ? AND vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?) ORDER BY vigente_desde DESC LIMIT 1', [$rep, $desde, $desde]);
        $atual = $atual[0] ?? null;
        // Sem núcleo no formulário, a nova vigência leva junto a estrutura que já tinha.
        $est = [
            'unidade' => $novo['unidade'] ?? $atual['unidade'] ?? null,
            'nucleo_id' => $novo['nucleo_id'] ?? $atual['nucleo_id'] ?? null,
            'nivel' => array_key_exists('nivel', $novo) ? $novo['nivel'] : ($atual['nivel'] ?? null),
            'funcao' => $atual['funcao'] ?? ($nucleo ? 'EQUITE' : null),
        ];
        // SR 3 ou mais não trabalha como assistente (v4_nivel_max_assistente): grava sempre "não".
        if ($est['nivel'] !== null && (int) $est['nivel'] > (int) escV4Param('v4_nivel_max_assistente', '2')) {
            $novo['pode_assistente'] = 0;
        }
        if ($atual && substr($atual['vigente_desde'], 0, 10) === $desde) {
            // Com a futura cancelada, esta passa a valer sem fim (antes fechava na véspera dela).
            escW('UPDATE ' . escT('esc_colaborador_config') . ' SET modelo_id = ?, tipo = ?, turno = ?, trilha = ?, folgas_fixas = ?, pode_assistente = ?, unidade = ?, nucleo_id = ?, nivel = ?, funcao = ?, observacao = ?, origem = \'TELA\', atualizado_por = ?' . ($canceladas ? ', vigente_ate = NULL' : '') . ' WHERE id = ?',
                [$novo['modelo_id'], $novo['tipo'], $novo['turno'], $novo['trilha'], $novo['folgas_fixas'], $novo['pode_assistente'], $est['unidade'], $est['nucleo_id'], $est['nivel'], $est['funcao'], $novo['observacao'], escUserId(), (int) $atual['id']]);
        } else {
            if ($atual) {
                escW('UPDATE ' . escT('esc_colaborador_config') . ' SET vigente_ate = ?, atualizado_por = ? WHERE id = ?', [escMaisDias($desde, -1), escUserId(), (int) $atual['id']]);
            }
            escW('INSERT INTO ' . escT('esc_colaborador_config') . ' (rep_code, modelo_id, tipo, turno, trilha, ancora, folgas_fixas, pode_assistente, unidade, nucleo_id, nivel, funcao, fatia_regular, vigente_desde, observacao, origem, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'TELA\', ?)',
                [$rep, $novo['modelo_id'], $novo['tipo'], $novo['turno'], $novo['trilha'], $atual['ancora'] ?? null, $novo['folgas_fixas'], $novo['pode_assistente'],
                 $est['unidade'], $est['nucleo_id'], $est['nivel'], $est['funcao'], $atual['fatia_regular'] ?? null,
                 $desde, $novo['observacao'], escUserId()]);
        }
        // Trocou de núcleo: as vagas fixas do núcleo antigo fecham na véspera (o motor cobre).
        // A vaga que só começava na data ou depois nunca valeu: vai para a data neutra.
        if ($nucleo && $atual && (int) $atual['nucleo_id'] !== (int) $nucleo['id']) {
            foreach (escQW('SELECT id, vigente_desde FROM ' . escT('esc_vaga_equipe') . ' WHERE rep_code = ? AND (vigente_ate IS NULL OR vigente_ate >= ?)', [$rep, $desde]) as $v) {
                if (substr((string) $v['vigente_desde'], 0, 10) >= $desde) {
                    escVagaNeutra((int) $v['id'], $neutra);
                } else {
                    escW('UPDATE ' . escT('esc_vaga_equipe') . ' SET vigente_ate = ?, motivo = LEFT(CONCAT(\'trocou de núcleo; \', COALESCE(motivo, \'\')), 255) WHERE id = ?', [escMaisDias($desde, -1), (int) $v['id']]);
                }
                $vagasFechadas++;
            }
        }
        escLog('colaborador.salvar', 'esc_colaborador_config', $rep, $atual, $novo + ['vigente_desde' => $desde, 'vagas_fechadas' => $vagasFechadas, 'canceladas' => $canceladas]);
    });
    $nucleosTxt = static function (array $c): string {
        $n = $c['nucleo_id'] ? escQ1('SELECT unidade, numero FROM ' . escT('esc_nucleo') . ' WHERE id = ?', [(int) $c['nucleo_id']]) : null;
        return dataBr(substr((string) $c['vigente_desde'], 0, 10)) . ($n ? ' (U' . (int) $n['unidade'] . ' · ' . $c['turno'] . ' · ' . t('núcleo {n}', ['n' => (int) $n['numero']]) . ')' : ' (' . $c['turno'] . ')');
    };
    escVoltar('colaboradores', ['q' => $rep, 'dia' => $desde], t('Configuração de {rep} salva a partir de {dia}.', ['rep' => $rep, 'dia' => dataBr($desde)])
        . ($canceladas ? ' ' . t('A configuração que estava marcada para depois foi cancelada: {lista}.', ['lista' => implode(', ', array_map($nucleosTxt, $canceladas))]) : '')
        . ($vagasFechadas ? ' ' . tn($vagasFechadas, '{n} vaga fixa no núcleo antigo fechada; o motor cobre.', '{n} vagas fixas no núcleo antigo fechadas; o motor cobre.') : '') . ' ' . t('O próximo recálculo usa a nova configuração.'));
}

$dia = preg_match('~^\d{4}-\d{2}-\d{2}$~', (string) ($_GET['dia'] ?? '')) ? $_GET['dia'] : max(date('Y-m-d'), $diaEstrutura);
$ehV4 = $dia >= $diaEstrutura;
$vig = ' vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)';
$config = escMotorConfig($dia);
$modelos = escQ('SELECT id, nome, tipo FROM ' . escT('esc_modelo_escala') . ' WHERE ativo = 1 ORDER BY nome');
$nomes = escNomes();
$estado = [];
foreach (escQ('SELECT e.rep_code, e.trilha_calculada, e.dias_seguidos, e.divergente_config FROM ' . escT('esc_ciclo_estado') . ' e JOIN (SELECT rep_code, MAX(ate_dia) AS d FROM ' . escT('esc_ciclo_estado') . ' GROUP BY rep_code) u ON u.rep_code = e.rep_code AND u.d = e.ate_dia') as $e) {
    $estado[$e['rep_code']] = $e;
}
$busca = trim((string) ($_GET['q'] ?? ''));
$turnoF = (string) ($_GET['turno'] ?? '');
$tipoF = (string) ($_GET['tipo'] ?? '');
$unidadeF = (string) ($_GET['unidade'] ?? '');
$edit = isset($_GET['editar']) ? strtoupper((string) $_GET['editar']) : '';
$ce = $edit !== '' ? ($config[$edit] ?? null) : null;
$dsem = array_map('escDiaSemanaCurto', [1, 2, 3, 4, 5, 6, 0]);
$TURNO_ROT = ['A' => 'A · T2/T3 · 05–13', 'C' => 'C · T4/T5 · 13–21', 'D' => 'D · T6/T1 · 21–05'];

$lista = [];
$nucleos = [];
if ($ehV4) {
    foreach (escQ('SELECT id, unidade, turno, numero, decurio_code, decurio_nome FROM ' . escT('esc_nucleo') . ' WHERE' . $vig . " ORDER BY unidade, FIELD(turno, 'A', 'C', 'D'), numero", [$dia, $dia]) as $n) {
        $nucleos[(int) $n['id']] = $n;
    }
    $extra = [];
    foreach (escQ('SELECT UPPER(rep_code) AS rep, unidade, nucleo_id, nivel, funcao, observacao FROM ' . escT('esc_colaborador_config') . ' WHERE' . $vig, [$dia, $dia]) as $x) {
        $extra[$x['rep']] = $x;
    }
    foreach ($config as $rep => $c) {
        $x = $extra[$rep] ?? [];
        $n = $nucleos[(int) ($x['nucleo_id'] ?? 0)] ?? null;
        $nome = $nomes[$rep] ?? '';
        $ehOs = ($x['funcao'] ?? '') === 'DECURIO';
        $funcao = $ehOs ? 'OS' : (($x['funcao'] ?? '') === 'EQUITE' ? 'SR' : 'fora da estrutura');
        if ($busca !== '' && stripos($rep . ' ' . $nome . ' ' . ($n['decurio_nome'] ?? ''), $busca) === false) {
            continue;
        }
        if (($turnoF !== '' && $c['turno'] !== $turnoF) || ($unidadeF !== '' && (string) ($n['unidade'] ?? $x['unidade'] ?? '') !== $unidadeF) || ($tipoF !== '' && $funcao !== $tipoF)) {
            continue;
        }
        $lista[] = ['rep' => $rep, 'nome' => $nome, 'c' => $c, 'n' => $n, 'x' => $x, 'funcao' => $funcao, 'est' => $estado[$rep] ?? null];
    }
    usort($lista, static fn($a, $b) => [$a['n']['unidade'] ?? 9, strpos('ACD', $a['c']['turno']), $a['n']['numero'] ?? 9, $a['funcao'] === 'OS' ? 0 : 1, -($a['x']['nivel'] ?? -1), $a['nome']]
        <=> [$b['n']['unidade'] ?? 9, strpos('ACD', $b['c']['turno']), $b['n']['numero'] ?? 9, $b['funcao'] === 'OS' ? 0 : 1, -($b['x']['nivel'] ?? -1), $b['nome']]);
    $foraEstrutura = count(array_filter($lista, static fn($l) => $l['funcao'] === 'fora da estrutura'));
} else {
    $estrutura = escEstrutura();
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
}
$rotNucleo = static fn(?array $n): string => $n ? t('U{u} · {turno} · núcleo {n}', ['u' => $n['unidade'], 'turno' => $n['turno'], 'n' => $n['numero']]) . ($n['decurio_nome'] ? ' · OS ' . nomeCurto($n['decurio_nome']) : '') : '—';
$linkDia = static fn(array $p = []) => link_tela('colaboradores', $p + ['dia' => $dia]);
?>
<header class="cabecalho">
  <span class="eyebrow"><?php echo h(t('Pessoas')); ?></span>
  <h1><?php echo h(t('Colaboradores')); ?></h1>
  <?php if ($ehV4): ?>
  <p class="muted"><?php echo t('Estrutura nova (vigente em {dia}): cada SR e OS pertence a um núcleo, que define unidade e turno, e segue o modelo 5x2 rotativo de 21 dias. A posição no ciclo vem do núcleo. OM, OC e OA ficam na {link}.', ['dia' => h(dataBr($dia)), 'link' => '<a href="' . h(link_tela('lideranca')) . '">' . h(t('Escala da liderança')) . '</a>']); ?></p>
  <?php else: ?>
  <p class="muted"><?php echo h(t('Cadastro antigo (vigente em {dia}, antes da virada de {virada}): turno fixo e modelo de cada pessoa. No 3x1 o turno não roda: só a folga avança. A posição no ciclo vem da releitura do realizado; preencha "trilha" só para forçar outra.', ['dia' => dataBr($dia), 'virada' => dataBr($diaEstrutura)])); ?></p>
  <?php endif; ?>
</header>

<?php if ($edit !== '' && escPode('cadastrar')): ?>
<!-- Edição numa janela por cima da lista (mesma janela da Grade: fundo inerte, Esc fecha). -->
<div class="modal-fundo" role="dialog" aria-modal="true" aria-labelledby="editar-titulo">
<div class="modal modal-largo" id="editar">
  <div class="linha-titulo"><h2 id="editar-titulo"><?php echo $ce ? h(t('Editar {rep} · {nome}', ['rep' => $edit, 'nome' => $nomes[$edit] ?? ''])) : h(t('Novo colaborador')); ?></h2><a class="botao" href="<?php echo h($linkDia()); ?>" data-fechar-modal aria-label="<?php echo h(t('Fechar')); ?>">×</a></div>
  <?php
  $nucleoAtual = $ehV4 && $ce ? ($extra[$edit]['nucleo_id'] ?? null) : null;
  $vagasAbertas = $nucleoAtual ? (int) escQ1('SELECT COUNT(*) AS n FROM ' . escT('esc_vaga_equipe') . ' WHERE rep_code = ? AND (vigente_ate IS NULL OR vigente_ate >= ?)', [$edit, $dia])['n'] : 0;
  $futuras = $ce ? escQ('SELECT c.vigente_desde, c.turno, n.unidade, n.numero FROM ' . escT('esc_colaborador_config') . ' c LEFT JOIN ' . escT('esc_nucleo') . ' n ON n.id = c.nucleo_id WHERE c.rep_code = ? AND c.vigente_desde > ? ORDER BY c.vigente_desde', [$edit, $dia]) : [];
  ?>
  <?php if ($futuras): ?>
  <p class="aviso small"><?php echo h(t('Já tem configuração marcada para depois: {lista}. Salvar a partir de uma data antes dela cancela essa configuração; vale a que você salvar agora.', ['lista' => implode(', ', array_map(static fn($f) => dataBr(substr((string) $f['vigente_desde'], 0, 10)) . ($f['unidade'] ? ' (U' . (int) $f['unidade'] . ' · ' . $f['turno'] . ' · ' . t('núcleo {n}', ['n' => (int) $f['numero']]) . ')' : ' (' . $f['turno'] . ')'), $futuras))])); ?></p>
  <?php endif; ?>
  <form method="post" action="<?php echo h(link_tela('colaboradores', ['editar' => $edit, 'dia' => $dia])); ?>" class="form-grade" id="form-colaborador"<?php if ($nucleoAtual): ?> data-nucleo-atual="<?php echo (int) $nucleoAtual; ?>" data-vagas="<?php echo $vagasAbertas; ?>"<?php endif; ?>>
    <?php echo campoCsrf(); ?>
    <label><?php echo h(t('Colaborador')); ?><input type="search" name="rep_code" value="<?php echo h($edit === 'NOVO' ? '' : $edit); ?>" <?php echo $ce ? 'readonly' : 'list="lista-colaboradores" autocomplete="off" placeholder="' . h(t('buscar nome ou código')) . '"'; ?> required></label>
    <?php if ($ehV4):
        $x = $extra[$edit] ?? ['nucleo_id' => null, 'nivel' => null, 'funcao' => 'EQUITE'];
        $c = $ce ?? ['modelo_id' => (int) (escQ1('SELECT id FROM ' . escT('esc_modelo_escala') . " WHERE nome = '5x2 rotativo 21 dias'")['id'] ?? 0), 'pode_assistente' => 1, 'observacao' => null];
        $ehOs = ($x['funcao'] ?? '') === 'DECURIO'; ?>
    <label><?php echo h(t('Núcleo (unidade e turno)')); ?><select name="nucleo_id" required><option value=""<?php echo empty($x['nucleo_id']) ? ' selected' : ''; ?>><?php echo h(t('Escolha o núcleo…')); ?></option><?php foreach ($nucleos as $id => $n): ?><option value="<?php echo (int) $id; ?>" <?php echo (int) $id === (int) $x['nucleo_id'] ? 'selected' : ''; ?>><?php echo h(t('U{u} · {turno} · núcleo {n}', ['u' => $n['unidade'], 'turno' => $TURNO_ROT[$n['turno']], 'n' => $n['numero']]) . ' · OS ' . nomeCurto($n['decurio_nome'] ?? '—')); ?></option><?php endforeach; ?></select></label>
    <?php if ($ehOs): ?>
    <label><?php echo h(t('Função')); ?><input type="text" value="Operational Supervisor (OS)" readonly></label><input type="hidden" name="nivel" value="">
    <?php else: ?>
    <label><?php echo h(t('Nível de SR')); ?><select name="nivel"><option value=""><?php echo h(t('sem nível')); ?></option><?php for ($k = 5; $k >= 0; $k--): ?><option value="<?php echo $k; ?>" <?php echo $x['nivel'] !== null && (int) $x['nivel'] === $k ? 'selected' : ''; ?>>SR <?php echo $k; ?></option><?php endfor; ?></select></label>
    <?php endif; ?>
    <label><?php echo h(t('Modelo')); ?><select name="modelo_id"><?php foreach ($modelos as $m): ?><option value="<?php echo (int) $m['id']; ?>" <?php echo (int) $m['id'] === (int) $c['modelo_id'] ? 'selected' : ''; ?>><?php echo h($m['nome']); ?></option><?php endforeach; ?></select></label>
    <label><?php echo h(t('Vale a partir de')); ?><input type="date" name="vigente_desde" required min="<?php echo h($diaEstrutura); ?>" value="<?php echo h($dia); ?>"></label>
    <?php else: $c = $ce ?? ['modelo_id' => 0, 'tipo' => 'TIME', 'turno' => 'A', 'trilha' => null, 'folgas_fixas' => null, 'pode_assistente' => 1, 'observacao' => null]; ?>
    <label><?php echo h(t('Modelo')); ?><select name="modelo_id"><?php foreach ($modelos as $m): ?><option value="<?php echo (int) $m['id']; ?>" <?php echo (int) $m['id'] === (int) $c['modelo_id'] ? 'selected' : ''; ?>><?php echo h($m['nome']); ?></option><?php endforeach; ?></select></label>
    <label><?php echo h(t('Turno fixo')); ?><select name="turno"><?php foreach (['A' => 'A · 05–13', 'C' => 'C · 13–21', 'D' => 'D · 21–05'] as $t => $r): ?><option value="<?php echo $t; ?>" <?php echo $c['turno'] === $t ? 'selected' : ''; ?>><?php echo h($r); ?></option><?php endforeach; ?></select></label>
    <label><?php echo h(t('Tipo')); ?><select name="tipo"><option value="TIME" <?php echo $c['tipo'] === 'TIME' ? 'selected' : ''; ?>><?php echo h(t('Time')); ?></option><option value="BUFFER" <?php echo $c['tipo'] === 'BUFFER' ? 'selected' : ''; ?>><?php echo h(t('Buffer')); ?></option><option value="NUCLEO" <?php echo $c['tipo'] === 'NUCLEO' ? 'selected' : ''; ?>><?php echo h(t('Núcleo (estrutura nova)')); ?></option></select></label>
    <label><?php echo h(t('Trilha (vazio = calcular)')); ?><input type="number" name="trilha" min="0" max="13" value="<?php echo h($c['trilha'] ?? ''); ?>"></label>
    <label><?php echo h(t('Vale a partir de')); ?><input type="date" name="vigente_desde" required max="<?php echo h(escMaisDias($diaEstrutura, -1)); ?>" value="<?php echo h(min($dia, escMaisDias($diaEstrutura, -1))); ?>"></label>
    <fieldset class="dias-folga"><legend><?php echo h(t('Folgas fixas (só modelo semana fixa)')); ?></legend>
      <?php for ($i = 0; $i < 7; $i++): ?><label class="small"><input type="checkbox" name="folga[<?php echo $i; ?>]" value="1" <?php echo !empty($c['folgas_fixas']) && $c['folgas_fixas'][$i] === '1' ? 'checked' : ''; ?>> <?php echo h($dsem[$i]); ?></label><?php endfor; ?>
    </fieldset>
    <?php endif; ?>
    <?php $nivelMaxAssist = (int) escV4Param('v4_nivel_max_assistente', '2'); $nivelForm = $ehV4 && ($x['nivel'] ?? null) !== null ? (int) $x['nivel'] : null; $semAssist = $nivelForm !== null && $nivelForm > $nivelMaxAssist; ?>
    <label class="small" data-pode-assist data-nivel-max="<?php echo $nivelMaxAssist; ?>"><input type="checkbox" name="pode_assistente" value="1" <?php echo (int) $c['pode_assistente'] && !$semAssist ? 'checked' : ''; ?> <?php echo $semAssist ? 'disabled' : ''; ?>> <?php echo h(t('pode ser assistente')); ?>
      <span class="muted" data-pode-assist-aviso <?php echo $semAssist ? '' : 'hidden'; ?>><?php echo h(t('(SR {n} ou mais não trabalha como assistente)', ['n' => $nivelMaxAssist + 1])); ?></span></label>
    <label><?php echo h(t('Observação')); ?><input type="text" name="observacao" value="<?php echo h($c['observacao'] ?? ''); ?>"></label>
    <div class="acoes"><button type="submit" class="botao primario"><?php echo h(t('Salvar')); ?></button><a class="botao" href="<?php echo h($linkDia()); ?>"><?php echo h(t('Cancelar')); ?></a></div>
    <?php if ($ehV4): ?><span class="muted small"><?php echo h(t('Trocar de núcleo fecha as vagas fixas da pessoa no núcleo antigo na véspera; o motor cobre essas vagas. Quem é OS de cada núcleo muda pelo quadro da liderança, não por aqui.')); ?></span><?php endif; ?>
  </form>
  <script>
  (function () {
    // Nível de SR acima do máximo de assistente: a caixa "pode ser assistente" fica desmarcada e bloqueada.
    var rot = document.querySelector('[data-pode-assist]');
    var nivel = document.querySelector('#form-colaborador select[name="nivel"]');
    if (!rot || !nivel) { return; }
    var caixa = rot.querySelector('input'), aviso = rot.querySelector('[data-pode-assist-aviso]'), max = +rot.dataset.nivelMax;
    nivel.addEventListener('change', function () {
      var alto = nivel.value !== '' && +nivel.value > max;
      if (alto) { caixa.checked = false; }
      caixa.disabled = alto;
      aviso.hidden = !alto;
    });
  })();
  </script>
  <?php if ($nucleoAtual): ?>
  <script>
  (function () {
    var f = document.getElementById('form-colaborador');
    f.addEventListener('submit', function (ev) {
      var sel = f.querySelector('select[name="nucleo_id"]');
      if (!sel || sel.value === f.dataset.nucleoAtual || f.dataset.confirmado === '1') { delete f.dataset.confirmado; return; }
      ev.preventDefault();
      ev.stopImmediatePropagation();
      var de = sel.querySelector('option[value="' + f.dataset.nucleoAtual + '"]');
      var p = f.querySelector('input[name="vigente_desde"]').value.split('-');
      var desde = <?php echo escEmIngles() ? "p[1] + '/' + p[2] + '/' + p[0]" : "p[2] + '/' + p[1] + '/' + p[0]"; ?>;
      var n = +f.dataset.vagas;
      escConfirmar(<?php echo json_encode(t('Trocar de núcleo?')); ?> + '\n\n' + (de ? de.textContent : <?php echo json_encode(t('núcleo atual')); ?>) + '\n→ ' + sel.options[sel.selectedIndex].textContent
        + '\n\n' + <?php echo json_encode(t('Vale a partir de {dia}.')); ?>.replace('{dia}', desde) + ' ' + (n ? <?php echo json_encode(t('Fecha {n} vaga(s) fixa(s) no núcleo antigo na véspera; o motor cobre essas vagas.')); ?>.replace('{n}', n) : <?php echo json_encode(t('A pessoa não tem vaga fixa aberta no núcleo antigo.')); ?>),
        { botao: <?php echo json_encode(t('Trocar de núcleo')); ?> }).then(function (ok) {
        if (ok) { f.dataset.confirmado = '1'; f.requestSubmit(); }
      });
    });
  })();
  </script>
  <?php endif; ?>
</div>
</div>
<?php endif; ?>

<section class="panel">
  <form method="get" action="<?php echo h(MODULE_URL); ?>/index.php" class="acoes">
    <input type="hidden" name="p" value="colaboradores">
    <input type="search" name="q" value="<?php echo h($busca); ?>" placeholder="<?php echo $ehV4 ? h(t('Nome, código ou OS')) : h(t('Nome, código ou party')); ?>" aria-label="<?php echo h(t('Buscar')); ?>">
    <select name="turno" onchange="this.form.submit()" aria-label="<?php echo h(t('Turno')); ?>"><option value=""><?php echo h(t('Todos os turnos')); ?></option><?php foreach (['A', 'C', 'D'] as $t): ?><option <?php echo $turnoF === $t ? 'selected' : ''; ?>><?php echo $t; ?></option><?php endforeach; ?></select>
    <?php if ($ehV4): ?>
    <select name="unidade" onchange="this.form.submit()" aria-label="<?php echo h(t('Unidade')); ?>"><option value=""><?php echo h(t('Todas as unidades')); ?></option><?php foreach ([1, 2, 3] as $u): ?><option value="<?php echo $u; ?>" <?php echo $unidadeF === (string) $u ? 'selected' : ''; ?>><?php echo h(t('Unidade {u}', ['u' => $u])); ?></option><?php endforeach; ?></select>
    <select name="tipo" onchange="this.form.submit()" aria-label="<?php echo h(t('Função')); ?>"><option value=""><?php echo h(t('SR e OS')); ?></option><option value="SR" <?php echo $tipoF === 'SR' ? 'selected' : ''; ?>>SR</option><option value="OS" <?php echo $tipoF === 'OS' ? 'selected' : ''; ?>>OS</option><option value="fora da estrutura" <?php echo $tipoF === 'fora da estrutura' ? 'selected' : ''; ?>><?php echo h(t('Fora da estrutura')); ?></option></select>
    <?php else: ?>
    <select name="tipo" onchange="this.form.submit()" aria-label="<?php echo h(t('Tipo')); ?>"><option value=""><?php echo h(t('Time e Buffer')); ?></option><option value="TIME" <?php echo $tipoF === 'TIME' ? 'selected' : ''; ?>><?php echo h(t('Time')); ?></option><option value="BUFFER" <?php echo $tipoF === 'BUFFER' ? 'selected' : ''; ?>><?php echo h(t('Buffer')); ?></option></select>
    <?php endif; ?>
    <label class="small muted"><?php echo h(t('Data')); ?> <input type="date" name="dia" value="<?php echo h($dia); ?>" onchange="this.form.submit()"></label>
    <button type="submit" class="botao"><?php echo h(t('Filtrar')); ?></button>
    <?php if (escPode('cadastrar')): ?><a class="botao primario" href="<?php echo h($linkDia(['editar' => 'NOVO'])); ?>"><?php echo h(t('Novo')); ?></a><?php endif; ?>
  </form>
  <?php if ($ehV4 && $foraEstrutura): ?><p class="laranja small"><?php echo h(tn($foraEstrutura, '{n} pessoa com configuração aberta sem núcleo: não entra na escala nova. Coloque num núcleo ou feche a configuração.', '{n} pessoas com configuração aberta sem núcleo: não entram na escala nova. Coloque num núcleo ou feche a configuração.')); ?></p><?php endif; ?>
  <div class="tabela-rolagem">
  <table>
    <?php if ($ehV4): ?>
    <thead><tr><th><?php echo h(t('Pessoa')); ?></th><th><?php echo h(t('Unidade · turno · núcleo')); ?></th><th><?php echo h(t('Função')); ?></th><th><?php echo h(t('Turno')); ?></th><th><?php echo h(t('Modelo')); ?></th><th class="num"><?php echo h(t('Dias seguidos')); ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($lista as $l): $c = $l['c']; ?>
      <tr>
        <td><strong><?php echo h(nomeCurto($l['nome'])); ?></strong><br><span class="muted small mono"><?php echo h($l['rep']); ?></span></td>
        <td class="small"><?php echo h($rotNucleo($l['n'])); ?></td>
        <td class="small"><?php echo $l['funcao'] === 'OS' ? '<strong>OS</strong>' : ($l['funcao'] === 'SR' ? 'SR ' . ($l['x']['nivel'] ?? '?') : '<span class="laranja">' . h(t('fora da estrutura')) . '</span>'); ?></td>
        <td><?php echo chipTurno($c['turno']); ?></td>
        <td class="small"><?php echo h($c['modelo']); ?></td>
        <td class="num"><?php echo $l['est'] ? (int) $l['est']['dias_seguidos'] : '—'; ?></td>
        <td><?php if (escPode('cadastrar')): ?><a class="botao" href="<?php echo h($linkDia(['editar' => $l['rep']])); ?>"><?php echo h(t('Editar')); ?></a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php else: ?>
    <thead><tr><th><?php echo h(t('Pessoa')); ?></th><th><?php echo h(t('Time · party · papel')); ?></th><th><?php echo h(t('Turno')); ?></th><th><?php echo h(t('Modelo')); ?></th><th class="num"><?php echo h(t('Trilha')); ?></th><th class="num"><?php echo h(t('Dias seguidos')); ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach (array_slice($lista, 0, 400) as $l): $c = $l['c']; ?>
      <tr>
        <td><strong><?php echo h(nomeCurto($l['nome'])); ?></strong><br><span class="muted small mono"><?php echo h($l['rep']); ?></span></td>
        <td class="small"><?php echo $c['tipo'] === 'BUFFER' ? h(t('Buffer')) : h(($l['e']['team'] ?? t('sem estrutura')) . ' · ' . ($l['e']['party'] ?? '') . ' · ' . ($l['e']['role'] ?? '')); ?></td>
        <td><?php echo chipTurno($c['turno']); ?></td>
        <td class="small"><?php echo h($c['modelo']); ?><?php echo $c['folgas_fixas'] ? ' · ' . h(t('folgas {dias}', ['dias' => $c['folgas_fixas']])) : ''; ?></td>
        <td class="num"><?php echo $c['trilha'] !== null ? h(t('{n} (fixa)', ['n' => (int) $c['trilha']])) : ($l['est'] && $l['est']['trilha_calculada'] !== null ? (int) $l['est']['trilha_calculada'] : '—'); ?><?php echo $l['est'] && (int) $l['est']['divergente_config'] ? ' <span class="tag laranja">' . h(t('difere do realizado')) . '</span>' : ''; ?></td>
        <td class="num"><?php echo $l['est'] ? (int) $l['est']['dias_seguidos'] : '—'; ?></td>
        <td><?php if (escPode('cadastrar')): ?><a class="botao" href="<?php echo h($linkDia(['editar' => $l['rep']])); ?>"><?php echo h(t('Editar')); ?></a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php endif; ?>
    <?php if (!$lista): ?><tr><td colspan="7" class="muted"><?php echo h(t('Ninguém neste filtro.')); ?></td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <p class="muted small"><?php echo h(tn(count($lista), '{n} pessoa em {dia}.', '{n} pessoas em {dia}.', ['dia' => dataBr($dia)])); ?> <?php echo $ehV4 ? h(t('Para ver o cadastro antigo, escolha uma data antes de {dia}.', ['dia' => dataBr($diaEstrutura)])) : h(t('Trilha e dias seguidos aparecem depois da primeira geração (releitura do realizado).')); ?></p>
</section>
