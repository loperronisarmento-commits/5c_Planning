<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/roster.php
// Roster da liderança: troca o OM de cada unidade, o OC de cada unidade e turno e o
// OS de cada núcleo, e inclui, tira ou muda a semana dos OA, sempre a partir de uma
// data (lib/roster.php). Quem entra sai do núcleo de SR; quem sai pode ficar com o
// lugar de quem entrou, virar SR no mesmo núcleo (OS) ou ficar sem núcleo.
// ===============================================

$primeiroDia = escSolPrimeiroDia();
$lerDesde = static function () use ($primeiroDia): string {
    $d = (string) ($_POST['desde'] ?? '');
    if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $d) || $d < $primeiroDia) {
        throw new RuntimeException(t('A mudança vale a partir de {dia} ou depois (não dá para mudar o que já passou).', ['dia' => dataBr($primeiroDia)]));
    }
    return $d;
};
$lerNovo = static function (): array {
    $rep = strtoupper(trim((string) ($_POST['rep_code'] ?? '')));
    $nome = escNomes()[$rep] ?? null;
    if (!preg_match('~^[A-Z]\d{6}$~', $rep) || !$nome) {
        throw new RuntimeException(t('Escolha quem entra na lista (nome ou código do headcount).'));
    }
    return [$rep, $nome];
};
$lerSemana = static function (): string {
    $sem = '';
    for ($i = 0; $i < 7; $i++) {
        $v = (string) ($_POST['semana'][$i] ?? '');
        $sem .= in_array($v, ['A', 'C', 'D'], true) ? $v : '.';
    }
    if ($sem === '.......') {
        throw new RuntimeException(t('Marque ao menos um dia de trabalho na semana do OA.'));
    }
    return $sem;
};
$linhaLider = static function (int $id, string $desde, string $nivel): array {
    $l = escQW('SELECT * FROM ' . escT('esc_lideranca') . ' WHERE id = ? AND nivel = ? AND vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)', [$id, $nivel, $desde, $desde])[0] ?? null;
    if (!$l) {
        throw new RuntimeException(t('Este cargo não vale na data escolhida. Recarregue a tela.'));
    }
    return $l;
};

if (escPost()) {
    escExigir('decidir');
    $acao = $_POST['acao'] ?? '';
    $nomes = escNomes();
    if ($acao === 'trocar') {
        $desde = $lerDesde();
        [$novo, $nomeNovo] = $lerNovo();
        [$tipo, $ref] = array_pad(explode(':', (string) ($_POST['cargo'] ?? ''), 2), 2, '');
        $destino = (string) ($_POST['destino'] ?? 'sem');
        $nivel = ($_POST['nivel'] ?? '') !== '' && in_array((int) $_POST['nivel'], ESC_ROSTER_NIVEIS_SR, true) ? (int) $_POST['nivel'] : null;
        if (!in_array($tipo, ['OM', 'OC', 'OS', 'OMN', 'OCN'], true) || !in_array($destino, ['lugar', 'mesmo', 'sem'], true) || ($destino === 'mesmo' && $tipo !== 'OS')) {
            throw new RuntimeException(t('Escolha o cargo e o que acontece com quem sai.'));
        }
        $msg = escTx(function () use ($tipo, $ref, $novo, $nomeNovo, $destino, $nivel, $desde, $nomes, $linhaLider) {
            if ($tipo === 'OS') {
                $n = escQW('SELECT * FROM ' . escT('esc_nucleo') . ' WHERE id = ? AND vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?) FOR UPDATE', [(int) $ref, $desde, $desde])[0] ?? null;
                if (!$n) {
                    throw new RuntimeException(t('Este núcleo não vale na data escolhida. Recarregue a tela.'));
                }
                $n = escNucleoComOsDoDia($n, $desde);
                $sai =$n['decurio_code'] ? strtoupper($n['decurio_code']) : null;
                $cargoTxt = 'OS U' . $n['unidade'] . ' · ' . ESC_SOL_TURNO_BLOCOS[$n['turno']] . ' · ' . t('núcleo {n}', ['n' => $n['numero']]);
            } elseif ($tipo === 'OMN' || $tipo === 'OCN') {
                // Cargo vago: não há linha que valha na data; cria uma.
                [$u, $t] = array_pad(explode(':', $ref, 2), 2, null);
                $u = (int) $u;
                $t = $tipo === 'OCN' && in_array($t, ['A', 'C', 'D'], true) ? $t : null;
                if (!in_array($u, [1, 2, 3], true) || ($tipo === 'OCN' && !$t)) {
                    throw new RuntimeException(t('Escolha o cargo e o que acontece com quem sai.'));
                }
                $nivelCargo = $tipo === 'OMN' ? 'OM' : 'OC';
                $existe = escQW('SELECT id FROM ' . escT('esc_lideranca') . ' WHERE nivel = ? AND unidade = ? AND ' . ($t ? 'turno = ?' : 'turno IS NULL') . ' AND vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)',
                    $t ? [$nivelCargo, $u, $t, $desde, $desde] : [$nivelCargo, $u, $desde, $desde]);
                if ($existe) {
                    throw new RuntimeException(t('Este cargo já tem alguém na data escolhida. Recarregue a tela.'));
                }
                $sai = null;
                $cargoTxt = $nivelCargo . ' U' . $u . ($t ? ' · ' . ESC_SOL_TURNO_BLOCOS[$t] : '');
            } else {
                $l = $linhaLider((int) $ref, $desde, $tipo);
                $sai = $l['rep_code'] ? strtoupper($l['rep_code']) : null;
                $cargoTxt = $tipo . ' U' . $l['unidade'] . ($l['turno'] ? ' · ' . ESC_SOL_TURNO_BLOCOS[$l['turno']] : '');
            }
            if ($sai === $novo) {
                throw new RuntimeException(t('{nome} já está neste cargo.', ['nome' => $nomeNovo]));
            }
            $outro = escRosterCargo($novo, $desde);
            if ($outro) {
                throw new RuntimeException(t('{nome} já é {cargo} em {dia}. Troque esse cargo primeiro.', ['nome' => $nomeNovo, 'cargo' => $outro, 'dia' => dataBr($desde)]));
            }
            $cfgEntra = escRosterConfig($novo, $desde);
            $cfgSai = $sai ? escRosterConfig($sai, $desde) : null;
            $nomeSai = $sai ? ($nomes[$sai] ?? $sai) : '';
            $motivo = t('roster: {novo} entra como {cargo}', ['novo' => $novo, 'cargo' => $cargoTxt]) . ($sai ? ' ' . t('no lugar de {sai}', ['sai' => $sai]) : '');
            if ($tipo === 'OS') {
                $modelo = $cfgEntra ?: $cfgSai;
                if (!$modelo) {
                    throw new RuntimeException(t('{nome} não tem configuração (modelo de escala). Cadastre em Colaboradores antes.', ['nome' => $nomeNovo]));
                }
                escRosterNovaConfig($novo, $modelo, (int) $n['id'], 'DECURIO', null, $desde, $motivo);
                escW('UPDATE ' . escT('esc_nucleo') . ' SET decurio_code = ?, decurio_nome = ? WHERE id = ?', [$novo, $nomeNovo, (int) $n['id']]);
            } elseif ($tipo === 'OMN' || $tipo === 'OCN') {
                escRosterTirarDoNucleo($novo, $desde);
                escW('INSERT INTO ' . escT('esc_lideranca') . " (nivel, unidade, turno, rep_code, nome, vigente_desde, origem, criado_por) VALUES (?, ?, ?, ?, ?, ?, 'TELA', ?)", [$nivelCargo, $u, $t, $novo, $nomeNovo, $desde, escUserId()]);
            } else {
                escRosterTirarDoNucleo($novo, $desde);
                escRosterTrocarLider((int) $ref, $novo, $nomeNovo, $desde);
            }
            if ($sai) {
                $resto = escRosterDestinoDeQuemSai($sai, nomeCurto($nomeSai), $novo, $destino, $nivel, $cfgEntra, $cfgSai, $tipo === 'OS' ? (int) $n['id'] : null, $desde, $motivo);
            } else {
                $resto = tn(escRosterPassarVagas($novo, null, $desde, $motivo), 'Cargo estava vago; {n} vaga fixa de quem entrou fecha (o motor cobre).', 'Cargo estava vago; {n} vagas fixas de quem entrou fecham (o motor cobre).');
            }
            escLog('roster.trocar', $tipo === 'OS' ? 'esc_nucleo' : 'esc_lideranca', (string) $ref, ['rep' => $sai], ['rep' => $novo, 'desde' => $desde, 'destino' => $destino, 'nivel' => $nivel]);
            return t('{nome} é {cargo} a partir de {dia}.', ['nome' => nomeCurto($nomeNovo), 'cargo' => $cargoTxt, 'dia' => dataBr($desde)]) . ' ' . $resto;
        });
        escVoltar('roster', [], $msg . ' ' . t('Recalcule as semanas a partir dessa data em Gerar escala.'));
    }
    if ($acao === 'incluir_oa') {
        $desde = $lerDesde();
        [$novo, $nomeNovo] = $lerNovo();
        $semana = $lerSemana();
        escTx(function () use ($novo, $nomeNovo, $semana, $desde) {
            $outro = escRosterCargo($novo, $desde);
            if ($outro) {
                throw new RuntimeException(t('{nome} já é {cargo} em {dia}. Troque esse cargo primeiro.', ['nome' => $nomeNovo, 'cargo' => $outro, 'dia' => dataBr($desde)]));
            }
            escRosterTirarDoNucleo($novo, $desde);
            escRosterPassarVagas($novo, null, $desde, t('roster: virou OA'));
            escW('INSERT INTO ' . escT('esc_lideranca') . " (nivel, unidade, turno, rep_code, nome, padrao_semana, vigente_desde, origem, criado_por) VALUES ('OA', NULL, NULL, ?, ?, ?, ?, 'TELA', ?)", [$novo, $nomeNovo, $semana, $desde, escUserId()]);
            escLog('roster.incluir_oa', 'esc_lideranca', escUltimoId(), null, ['rep' => $novo, 'semana' => $semana, 'desde' => $desde]);
        });
        escVoltar('roster', [], t('{nome} é OA a partir de {dia}.', ['nome' => nomeCurto($nomeNovo), 'dia' => dataBr($desde)]) . ' ' . t('Recalcule as semanas a partir dessa data em Gerar escala.'));
    }
    if ($acao === 'semana_oa') {
        $desde = $lerDesde();
        $semana = $lerSemana();
        $id = (int) ($_POST['id'] ?? 0);
        escTx(function () use ($id, $semana, $desde, $linhaLider) {
            $l = $linhaLider($id, $desde, 'OA');
            if (substr((string) $l['vigente_desde'], 0, 10) >= $desde) {
                escW('UPDATE ' . escT('esc_lideranca') . " SET padrao_semana = ?, origem = 'TELA' WHERE id = ?", [$semana, $id]);
            } else {
                escRosterFecharLider($id, $desde);
                escW('INSERT INTO ' . escT('esc_lideranca') . " (nivel, unidade, turno, rep_code, nome, team_id, deslocamento, padrao_semana, vigente_desde, vigente_ate, origem, criado_por) VALUES ('OA', NULL, NULL, ?, ?, ?, ?, ?, ?, ?, 'TELA', ?)",
                    [$l['rep_code'], $l['nome'], $l['team_id'], $l['deslocamento'], $semana, $desde, $l['vigente_ate'], escUserId()]);
            }
            escLog('roster.semana_oa', 'esc_lideranca', $id, ['semana' => $l['padrao_semana']], ['semana' => $semana, 'desde' => $desde]);
        });
        escVoltar('roster', [], t('Semana do OA salva a partir de {dia}.', ['dia' => dataBr($desde)]) . ' ' . t('Recalcule as semanas a partir dessa data em Gerar escala.'));
    }
    if ($acao === 'tirar_oa') {
        $desde = $lerDesde();
        $id = (int) ($_POST['id'] ?? 0);
        $volta = (int) ($_POST['volta_nucleo_id'] ?? 0);
        $nivel = ($_POST['nivel'] ?? '') !== '' && in_array((int) $_POST['nivel'], ESC_ROSTER_NIVEIS_SR, true) ? (int) $_POST['nivel'] : null;
        $msg = escTx(function () use ($id, $volta, $nivel, $desde, $linhaLider) {
            $l = $linhaLider($id, $desde, 'OA');
            escRosterFecharLider($id, $desde);
            $rep = strtoupper((string) $l['rep_code']);
            $nome = nomeCurto($l['nome']);
            $resto = t('{nome} fica sem núcleo: ajuste em Colaboradores se continuar na operação.', ['nome' => $nome]);
            if ($volta && $rep !== '') {
                $modelo = escQW('SELECT * FROM ' . escT('esc_colaborador_config') . ' WHERE UPPER(rep_code) = ? ORDER BY vigente_desde DESC LIMIT 1', [$rep])[0] ?? null;
                if (!$modelo) {
                    throw new RuntimeException(t('{nome} não tem configuração (modelo de escala). Tire o OA sem núcleo e cadastre em Colaboradores.', ['nome' => $nome]));
                }
                escRosterNovaConfig($rep, $modelo, $volta, 'EQUITE', $nivel ?? 5, $desde, t('roster: deixou de ser OA'));
                $resto = t('{nome} volta como SR no núcleo escolhido.', ['nome' => $nome]);
            }
            escLog('roster.tirar_oa', 'esc_lideranca', $id, ['rep' => $rep], ['desde' => $desde, 'volta_nucleo_id' => $volta ?: null]);
            return t('{nome} deixa de ser OA a partir de {dia}.', ['nome' => $nome, 'dia' => dataBr($desde)]) . ' ' . $resto;
        });
        escVoltar('roster', [], $msg . ' ' . t('Recalcule as semanas a partir dessa data em Gerar escala.'));
    }
    throw new RuntimeException(t('Ação desconhecida.'));
}

$dia = preg_match('~^\d{4}-\d{2}-\d{2}$~', (string) ($_GET['dia'] ?? '')) ? $_GET['dia'] : max(date('Y-m-d'), escV4DiaEstrutura());
$vig = ' vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)';
$nomes = escNomes();
$lideres = escQ('SELECT * FROM ' . escT('esc_lideranca') . ' WHERE' . $vig . " ORDER BY nivel, unidade, FIELD(turno, 'D', 'A', 'C'), nome", [$dia, $dia]);
$nucleos = escQ('SELECT * FROM ' . escT('esc_nucleo') . ' WHERE' . $vig . " ORDER BY unidade, FIELD(turno, 'D', 'A', 'C'), numero", [$dia, $dia]);
$om = $oc = $oa = [];
foreach ($lideres as $l) {
    if ($l['nivel'] === 'OM') {
        $om[(int) $l['unidade']] = $l;
    } elseif ($l['nivel'] === 'OC') {
        $oc[(int) $l['unidade']][$l['turno']] = $l;
    } elseif ($l['nivel'] === 'OA') {
        $oa[] = $l;
    }
}
$nucleos = array_map(static fn($n) => escNucleoComOsDoDia($n, $dia), $nucleos);
$porUnidade = [];
foreach ($nucleos as $n) {
    $porUnidade[(int) $n['unidade']][$n['turno']][] = $n;
}
$segSeguinte = segundaDaSemana(escMaisDias(date('Y-m-d'), 7));
$desdePadrao = max($primeiroDia, $segSeguinte);
$podeMudar = escPode('decidir');
$diasSem = array_map('escDiaSemanaCurto', [1, 2, 3, 4, 5, 6, 0]);
$TURNO_OA = ['' => '—', 'A' => 'T2/T3', 'C' => 'T4/T5', 'D' => 'T6/T1'];
$campoData = static fn() => '<label class="small">' . h(t('A partir de')) . ' <input type="date" name="desde" required min="' . h($primeiroDia) . '" value="' . h($desdePadrao) . '"></label>';
$campoNivel = static function (string $rotulo) {
    $h = '<label class="small">' . h($rotulo) . ' <select name="nivel"><option value="">' . h(t('automático')) . '</option>';
    foreach (ESC_ROSTER_NIVEIS_SR as $nv) {
        $h .= '<option value="' . $nv . '">SR ' . $nv . '</option>';
    }
    return $h . '</select></label>';
};
$campoSemana = static function (string $padrao) use ($diasSem, $TURNO_OA) {
    $h = '<fieldset class="roster-semana"><legend>' . h(t('Semana (turno de cada dia; — é folga)')) . '</legend>';
    for ($i = 0; $i < 7; $i++) {
        $atual = $padrao[$i] ?? '.';
        $h .= '<label class="small">' . h($diasSem[$i]) . '<select name="semana[' . $i . ']">';
        foreach ($TURNO_OA as $k => $rot) {
            $h .= '<option value="' . $k . '"' . (($k === '' ? '.' : $k) === $atual ? ' selected' : '') . '>' . h($rot) . '</option>';
        }
        $h .= '</select></label>';
    }
    return $h . '</fieldset>';
};
// Formulário "Trocar" de um cargo. $sai: quem está hoje (ou null = vago).
$formTroca = static function (string $cargo, string $titulo, ?string $sai, bool $ehOs) use ($nomes, $campoData, $campoNivel) {
    $nomeSai = $sai ? nomeCurto($nomes[strtoupper($sai)] ?? $sai) : '';
    $h = '<details class="roster-acao"><summary class="botao">' . h($sai ? t('Trocar') : t('Preencher')) . '</summary>'
        . '<form method="post" action="' . h(link_tela('roster')) . '" class="form-grade roster-form">' . campoCsrf()
        . '<input type="hidden" name="acao" value="trocar"><input type="hidden" name="cargo" value="' . h($cargo) . '">'
        . '<label>' . h(t('Quem entra como {cargo}', ['cargo' => $titulo])) . '<input type="search" name="rep_code" list="lista-colaboradores" autocomplete="off" placeholder="' . h(t('buscar nome ou código')) . '" required></label>';
    if ($sai) {
        $h .= '<fieldset class="roster-destino"><legend>' . h(t('E {nome}, que sai?', ['nome' => $nomeSai])) . '</legend>'
            . '<label class="small"><input type="radio" name="destino" value="lugar" checked> ' . h(t('Fica com o lugar de quem entra (núcleo e vagas fixas, como SR)')) . '</label>'
            . ($ehOs ? '<label class="small"><input type="radio" name="destino" value="mesmo"> ' . h(t('Fica como SR neste mesmo núcleo')) . '</label>' : '')
            . '<label class="small"><input type="radio" name="destino" value="sem"> ' . h(t('Fica sem núcleo (ajusto depois em Colaboradores)')) . '</label>'
            . '</fieldset>' . $campoNivel(t('Nível de SR de quem sai'));
    } else {
        $h .= '<input type="hidden" name="destino" value="sem"><p class="muted small">' . h(t('Cargo vago hoje.')) . '</p>';
    }
    return $h . '<div class="acoes">' . $campoData() . '<button type="submit" class="botao primario" data-longo data-confirmar="' . h(t('Trocar {cargo} a partir da data escolhida? Quem entra sai do núcleo de SR. Para desfazer, é preciso outra troca.', ['cargo' => $titulo])) . '">' . h(t('Trocar')) . '</button></div></form></details>';
};
$pessoa = static function (?string $rep, ?string $nome) use ($nomes) {
    if (!$rep && !$nome) {
        return '<span class="laranja">' . h(t('vago')) . '</span>';
    }
    return '<strong>' . h($nome ?: ($nomes[strtoupper((string) $rep)] ?? (string) $rep)) . '</strong> <span class="mono muted small">' . h(strtoupper((string) $rep)) . '</span>';
};
$opcoesNucleo = '';
foreach ($nucleos as $n) {
    $opcoesNucleo .= '<option value="' . (int) $n['id'] . '">' . h('U' . $n['unidade'] . ' · ' . ESC_SOL_TURNO_BLOCOS[$n['turno']] . ' · ' . t('núcleo {n}', ['n' => $n['numero']]) . ' · OS ' . nomeCurto($n['decurio_nome'])) . '</option>';
}
?>
<header class="cabecalho">
  <span class="eyebrow"><?php echo h(t('Pessoas')); ?></span>
  <h1><?php echo h(t('Roster da liderança')); ?></h1>
  <p class="muted"><?php echo h(t('Quem é OM, OC, OS e OA em {dia}. Cada troca vale a partir de uma data: quem entra sai do núcleo de SR (as vagas fixas dele passam para quem sai ou fecham), e quem sai fica com o lugar de quem entrou, vira SR no mesmo núcleo (OS) ou fica sem núcleo. Depois, recalcule as semanas a partir da data.', ['dia' => dataBr($dia)])); ?></p>
</header>

<section class="panel org-barra">
  <form method="get" class="acoes" style="margin:0"><input type="hidden" name="p" value="roster"><label class="small muted"><?php echo h(t('Ver em')); ?> <input type="date" name="dia" value="<?php echo h($dia); ?>" onchange="this.form.submit()"></label></form>
  <a class="botao" href="<?php echo h(link_tela('organograma', ['dia' => $dia])); ?>"><?php echo h(t('Ver no Organograma')); ?></a>
  <?php if (!$podeMudar): ?><span class="muted small"><?php echo h(t('Só Planejamento, WFM e Admin mudam o roster.')); ?></span><?php endif; ?>
</section>

<?php foreach ($porUnidade as $u => $porTurno): ?>
<section class="panel roster-unidade">
  <h2><?php echo h(t('Unidade {u}', ['u' => $u])); ?></h2>
  <div class="tabela-rolagem"><table data-sem-ordem class="roster-tabela">
    <thead><tr><th scope="col"><?php echo h(t('Cargo')); ?></th><th scope="col"><?php echo h(t('Pessoa')); ?></th><th scope="col" aria-label="<?php echo h(t('Ação')); ?>"></th></tr></thead>
    <tbody>
      <?php $l = $om[$u] ?? null; ?>
      <tr class="roster-om"><td data-rotulo="<?php echo h(t('Cargo')); ?>"><strong>OM</strong></td><td data-rotulo="<?php echo h(t('Pessoa')); ?>"><?php echo $pessoa($l['rep_code'] ?? null, $l['nome'] ?? null); ?></td>
        <td class="cel-larga"><?php echo $podeMudar ? ($l ? $formTroca('OM:' . (int) $l['id'], 'OM U' . $u, $l['rep_code'], false) : $formTroca('OMN:' . $u, 'OM U' . $u, null, false)) : ''; ?></td></tr>
      <?php foreach (['D', 'A', 'C'] as $t): if (empty($porTurno[$t])) { continue; } $l = $oc[$u][$t] ?? null; $bl = ESC_SOL_TURNO_BLOCOS[$t]; ?>
      <tr class="roster-turno"><td colspan="3"><?php echo h($bl); ?></td></tr>
      <tr class="roster-oc"><td data-rotulo="<?php echo h(t('Cargo')); ?>">OC · <?php echo h($bl); ?></td><td data-rotulo="<?php echo h(t('Pessoa')); ?>"><?php echo $pessoa($l['rep_code'] ?? null, $l['nome'] ?? null); ?></td>
        <td class="cel-larga"><?php echo $podeMudar ? ($l ? $formTroca('OC:' . (int) $l['id'], 'OC U' . $u . ' · ' . $bl, $l['rep_code'], false) : $formTroca('OCN:' . $u . ':' . $t, 'OC U' . $u . ' · ' . $bl, null, false)) : ''; ?></td></tr>
      <?php foreach ($porTurno[$t] as $n): ?>
      <tr><td data-rotulo="<?php echo h(t('Cargo')); ?>">OS <?php echo (int) $n['numero']; ?> · <?php echo h($bl); ?></td><td data-rotulo="<?php echo h(t('Pessoa')); ?>"><?php echo $pessoa($n['decurio_code'], $n['decurio_nome']); ?></td>
        <td class="cel-larga"><?php echo $podeMudar ? $formTroca('OS:' . (int) $n['id'], 'OS ' . $n['numero'] . ' U' . $u . ' · ' . $bl, $n['decurio_code'], true) : ''; ?></td></tr>
      <?php endforeach; ?>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php endforeach; ?>

<section class="panel">
  <h2><?php echo h(t('Operational Assistants')); ?></h2>
  <p class="muted small"><?php echo h(t('Os OA não têm núcleo nem unidade: cada um tem uma semana fixa, com o turno de cada dia.')); ?></p>
  <div class="tabela-rolagem"><table data-sem-ordem class="roster-tabela">
    <thead><tr><th scope="col"><?php echo h(t('Pessoa')); ?></th><?php foreach ($diasSem as $d): ?><th scope="col"><?php echo h($d); ?></th><?php endforeach; ?><th scope="col" aria-label="<?php echo h(t('Ação')); ?>"></th></tr></thead>
    <tbody>
    <?php foreach ($oa as $l): $sem = str_pad((string) $l['padrao_semana'], 7, '.'); ?>
      <tr>
        <td data-rotulo="<?php echo h(t('Pessoa')); ?>"><?php echo $pessoa($l['rep_code'], $l['nome']); ?></td>
        <?php for ($i = 0; $i < 7; $i++): ?><td data-rotulo="<?php echo h($diasSem[$i]); ?>" class="<?php echo $sem[$i] === '.' ? 'muted' : ''; ?>"><?php echo h($TURNO_OA[$sem[$i] === '.' ? '' : $sem[$i]] ?? $sem[$i]); ?></td><?php endfor; ?>
        <td class="cel-larga">
          <?php if ($podeMudar): ?>
          <details class="roster-acao"><summary class="botao"><?php echo h(t('Mudar semana')); ?></summary>
            <form method="post" action="<?php echo h(link_tela('roster')); ?>" class="form-grade roster-form"><?php echo campoCsrf(); ?><input type="hidden" name="acao" value="semana_oa"><input type="hidden" name="id" value="<?php echo (int) $l['id']; ?>">
              <?php echo $campoSemana($sem); ?>
              <div class="acoes"><?php echo $campoData(); ?><button type="submit" class="botao primario"><?php echo h(t('Salvar semana')); ?></button></div>
            </form>
          </details>
          <details class="roster-acao"><summary class="botao perigo"><?php echo h(t('Tirar')); ?></summary>
            <form method="post" action="<?php echo h(link_tela('roster')); ?>" class="form-grade roster-form"><?php echo campoCsrf(); ?><input type="hidden" name="acao" value="tirar_oa"><input type="hidden" name="id" value="<?php echo (int) $l['id']; ?>">
              <label><?php echo h(t('Volta como SR no núcleo (opcional)')); ?><select name="volta_nucleo_id"><option value=""><?php echo h(t('sem núcleo (ajusto em Colaboradores)')); ?></option><?php echo $opcoesNucleo; ?></select></label>
              <?php echo $campoNivel(t('Nível de SR')); ?>
              <div class="acoes"><?php echo $campoData(); ?><button type="submit" class="botao perigo" data-confirmar="<?php echo h(t('Tirar {nome} dos OA a partir da data escolhida?', ['nome' => nomeCurto($l['nome'])])); ?>"><?php echo h(t('Tirar dos OA')); ?></button></div>
            </form>
          </details>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$oa): ?><tr><td colspan="9" class="muted"><?php echo h(t('Nenhum OA nesta data.')); ?></td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <?php if ($podeMudar): ?>
  <details class="roster-acao roster-incluir"><summary class="botao"><?php echo h(t('Incluir OA')); ?></summary>
    <form method="post" action="<?php echo h(link_tela('roster')); ?>" class="form-grade roster-form"><?php echo campoCsrf(); ?><input type="hidden" name="acao" value="incluir_oa">
      <label><?php echo h(t('Quem entra como OA')); ?><input type="search" name="rep_code" list="lista-colaboradores" autocomplete="off" placeholder="<?php echo h(t('buscar nome ou código')); ?>" required></label>
      <?php echo $campoSemana('.......'); ?>
      <div class="acoes"><?php echo $campoData(); ?><button type="submit" class="botao primario" data-confirmar="<?php echo h(t('Incluir como OA a partir da data escolhida? A pessoa sai do núcleo de SR e as vagas fixas dela fecham (o motor cobre).')); ?>"><?php echo h(t('Incluir OA')); ?></button></div>
    </form>
  </details>
  <?php endif; ?>
</section>
