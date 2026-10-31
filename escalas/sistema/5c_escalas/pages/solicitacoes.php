<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/solicitacoes.php
// Solicitações do SR: o líder registra o pedido (trabalhar como assistente, em dupla,
// mudar de turno, núcleo ou unidade, ou outro); o Planejamento aprova ou recusa e,
// aprovado, escolhe o par e aplica a troca 1 por 1 a partir de uma data
// (lib/solicitacao.php). Tabela esc_solicitacao (ES_30).
// ===============================================

if (!escSolInstalada()) {
    ?>
<header class="cabecalho"><span class="eyebrow"><?php echo h(t('Pessoas')); ?></span><h1><?php echo h(t('Solicitações')); ?></h1></header>
<section class="panel"><p class="aviso erro" role="alert"><?php echo h(t('A tela de solicitações ainda não foi instalada no banco (script ES_30). Peça ao admin.')); ?></p></section>
    <?php
    return;
}

$tab = escT('esc_solicitacao');
$primeiroDia = escSolPrimeiroDia();

if (escPost()) {
    $acao = $_POST['acao'] ?? '';
    if ($acao === 'registrar') {
        escExigir('corrigir');
        $rep = strtoupper(trim((string) ($_POST['rep_code'] ?? '')));
        $tipo = (string) ($_POST['tipo'] ?? '');
        $aPartir = preg_match('~^\d{4}-\d{2}-\d{2}$~', (string) ($_POST['a_partir'] ?? '')) ? $_POST['a_partir'] : null;
        $texto = mb_substr(trim((string) ($_POST['texto'] ?? '')), 0, 500);
        $dUnidade = in_array((int) ($_POST['destino_unidade'] ?? 0), [1, 2, 3], true) ? (int) $_POST['destino_unidade'] : null;
        $dTurno = in_array($_POST['destino_turno'] ?? '', ['A', 'C', 'D'], true) ? $_POST['destino_turno'] : null;
        $dNucleo = (int) ($_POST['destino_nucleo_id'] ?? 0) ?: null;
        $dRep = strtoupper(trim((string) ($_POST['rep'] ?? ''))) ?: null; // pessoa: titular que vai assistir ou par da dupla
        if (!preg_match('~^[A-Z]\d{6}$~', $rep) || !in_array($tipo, ESC_SOL_TIPOS, true)) {
            throw new RuntimeException(t('Escolha o colaborador e o tipo de solicitação.'));
        }
        if ($texto === '') {
            throw new RuntimeException(t('Escreva o pedido (do jeito que a pessoa pediu).'));
        }
        $diaRef = max($aPartir ?? '', $primeiroDia);
        $lugar = escSolLugares($diaRef)[$rep] ?? null;
        if (!$lugar) {
            throw new RuntimeException(t('{rep} não está em nenhum núcleo em {dia} (veja em Colaboradores).', ['rep' => $rep, 'dia' => dataBr($diaRef)]));
        }
        $nivelMaxAssist = (int) escV4Param('v4_nivel_max_assistente', '2');
        if ($tipo === 'ASSISTENTE' && (int) ($lugar['nivel'] ?? 0) > $nivelMaxAssist) {
            throw new RuntimeException(t('{rep} é SR {nivel}: só SR 0 a {max} trabalha como assistente.', ['rep' => $rep, 'nivel' => (int) $lugar['nivel'], 'max' => $nivelMaxAssist]));
        }
        // Só guarda o destino que o tipo usa, e confere que é diferente de onde a pessoa está.
        if ($tipo === 'TURNO') {
            $dNucleo = null;
            if (!$dTurno || ($dTurno === $lugar['turno'] && (!$dUnidade || $dUnidade === $lugar['unidade']))) {
                throw new RuntimeException(t('Escolha um turno diferente do atual ({turno}).', ['turno' => ESC_SOL_TURNO_BLOCOS[$lugar['turno']]]));
            }
        } elseif ($tipo === 'UNIDADE') {
            $dNucleo = null;
            if (!$dUnidade || $dUnidade === $lugar['unidade']) {
                throw new RuntimeException(t('Escolha uma unidade diferente da atual (U{u}).', ['u' => $lugar['unidade']]));
            }
        } elseif ($tipo === 'NUCLEO') {
            $dUnidade = $dTurno = null;
            $ok = $dNucleo ? escQ1('SELECT id FROM ' . escT('esc_nucleo') . ' WHERE id = ? AND vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)', [$dNucleo, $diaRef, $diaRef]) : null;
            if (!$ok || $dNucleo === $lugar['nucleo_id']) {
                throw new RuntimeException(t('Escolha um núcleo diferente do atual.'));
            }
        } elseif ($tipo === 'ASSISTENTE' || $tipo === 'DUPLA') {
            // Com quem ou onde fica fixo: o núcleo (unidade, turno e OS) e/ou a pessoa; ao menos um.
            $dUnidade = $dTurno = null;
            if ($dRep !== null) {
                $lugarAlvo = escSolLugares($diaRef)[$dRep] ?? null;
                if (!preg_match('~^[A-Z]\d{6}$~', $dRep) || !$lugarAlvo) {
                    throw new RuntimeException(t('{rep} não está em nenhum núcleo em {dia}: escolha outra pessoa ou só o núcleo.', ['rep' => $dRep, 'dia' => dataBr($diaRef)]));
                }
                if ($dRep === $rep) {
                    throw new RuntimeException(t('Escolha outra pessoa (não a própria).'));
                }
                if ($dNucleo !== null && $dNucleo !== $lugarAlvo['nucleo_id']) {
                    throw new RuntimeException(t('{pessoa} está em {lugar}: escolha esse núcleo ou deixe o núcleo em branco.', ['pessoa' => nomeCurto(escNomes()[$dRep] ?? $dRep), 'lugar' => escSolLugarTexto($lugarAlvo, false)]));
                }
                $dNucleo = $lugarAlvo['nucleo_id'];
            }
            if ($dNucleo === null) {
                throw new RuntimeException(t('Diga com quem ou onde a pessoa vai ficar: escolha o núcleo (unidade, turno e OS) ou a pessoa.'));
            }
        } else {
            $dUnidade = $dTurno = $dNucleo = $dRep = null;
        }
        if ($tipo !== 'ASSISTENTE' && $tipo !== 'DUPLA') {
            $dRep = null;
        }
        $repetida = escQ1('SELECT id FROM ' . $tab . " WHERE rep_code = ? AND tipo = ? AND status IN ('PENDENTE', 'APROVADO')", [$rep, $tipo]);
        if ($repetida) {
            throw new RuntimeException(t('{rep} já tem uma solicitação deste tipo em aberto (nº {id}).', ['rep' => $rep, 'id' => (int) $repetida['id']]));
        }
        $temRep = (bool) escQ1("SELECT 1 AS ok FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'esc_solicitacao' AND COLUMN_NAME = 'destino_rep'", [ESC_SCHEMA]);
        if ($dRep !== null && !$temRep) {
            throw new RuntimeException(t('Para guardar a pessoa da dupla ou da assistência falta rodar o script ES_34. Por enquanto, escolha só o núcleo.'));
        }
        escTx(function () use ($tab, $rep, $tipo, $dUnidade, $dTurno, $dNucleo, $dRep, $temRep, $aPartir, $texto) {
            escW('INSERT INTO ' . $tab . ' (rep_code, tipo, destino_unidade, destino_turno, destino_nucleo_id, ' . ($temRep ? 'destino_rep, ' : '') . 'a_partir, texto, canal, solicitado_por, solicitado_por_nome) VALUES (?, ?, ?, ?, ?, ' . ($temRep ? '?, ' : '') . '?, ?, ?, ?, ?)',
                array_merge([$rep, $tipo, $dUnidade, $dTurno, $dNucleo], $temRep ? [$dRep] : [], [$aPartir, $texto, escPode('decidir') ? 'PLANEJAMENTO' : 'LIDER', escUserId() ?: null, mb_substr((string) ($GLOBALS['ESC_USUARIO']['nome'] ?? ''), 0, 120) ?: null]));
            escLog('solicitacao.registrar', 'esc_solicitacao', escUltimoId(), null, ['rep' => $rep, 'tipo' => $tipo]);
        });
        escVoltar('solicitacoes', [], t('Solicitação registrada para {rep}. O Planejamento decide em Pessoas › Solicitações.', ['rep' => $rep]));
    }
    if ($acao === 'decidir') {
        escExigir('decidir');
        $id = (int) ($_POST['id'] ?? 0);
        $modo = (string) ($_POST['modo'] ?? '');
        $comentario = mb_substr(trim((string) ($_POST['comentario'] ?? '')), 0, 500);
        if (!in_array($modo, ['aprovar', 'recusar'], true)) {
            throw new RuntimeException(t('Escolha aprovar ou recusar.'));
        }
        if ($modo === 'recusar' && $comentario === '') {
            throw new RuntimeException(t('Recusar pede comentário (ele volta para o líder). Escreva o comentário e registre de novo.'));
        }
        $n = escTx(function () use ($tab, $id, $modo, $comentario) {
            $n = escW('UPDATE ' . $tab . " SET status = ?, decidido_em = NOW(), decidido_por = ?, comentario_decisao = ? WHERE id = ? AND status = 'PENDENTE'",
                [$modo === 'aprovar' ? 'APROVADO' : 'RECUSADO', escUserId(), $comentario ?: null, $id]);
            if ($n) {
                escLog('solicitacao.decidir', 'esc_solicitacao', $id, null, ['modo' => $modo, 'comentario' => $comentario]);
            }
            return $n;
        });
        if (!$n) {
            throw new RuntimeException(t('Esta solicitação já foi decidida ou cancelada. Recarregue a tela.'));
        }
        escVoltar('solicitacoes', ['status' => $modo === 'aprovar' ? 'APROVADO' : 'PENDENTE'], $modo === 'aprovar'
            ? t('Solicitação aprovada. Escolha o par e aplique a troca (em Aprovadas), quando houver alguém para trocar.')
            : t('Solicitação recusada.'));
    }
    if ($acao === 'aplicar') {
        escExigir('decidir');
        $id = (int) ($_POST['id'] ?? 0);
        $par = strtoupper(trim((string) ($_POST['par'] ?? '')));
        $desde = (string) ($_POST['desde'] ?? '');
        if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $desde) || $desde < $primeiroDia) {
            throw new RuntimeException(t('A troca vale a partir de {dia} ou depois (não dá para mudar o que já passou).', ['dia' => dataBr($primeiroDia)]));
        }
        $s = escQ1('SELECT * FROM ' . $tab . ' WHERE id = ?', [$id]);
        if (!$s || $s['status'] !== 'APROVADO' || $s['tipo'] === 'OUTRO') {
            throw new RuntimeException(t('Só dá para aplicar troca de uma solicitação aprovada. Recarregue a tela.'));
        }
        $candidatos = escSolCandidatos($s, $desde);
        $escolhido = null;
        foreach ($candidatos as $c) {
            if ($c['rep'] === $par) {
                $escolhido = $c;
            }
        }
        if (!$escolhido) {
            throw new RuntimeException(t('{par} não serve de par para esta troca em {dia}. Escolha alguém da lista.', ['par' => $par ?: '—', 'dia' => dataBr($desde)]));
        }
        $rep = strtoupper($s['rep_code']);
        $resumo = escTx(function () use ($tab, $s, $rep, $par, $desde, $escolhido) {
            if (!escW('UPDATE ' . $tab . " SET status = 'APLICADO', troca_com = ?, troca_desde = ?, aplicado_em = NOW(), aplicado_por = ? WHERE id = ? AND status = 'APROVADO'", [$par, $desde, escUserId(), (int) $s['id']])) {
                throw new RuntimeException(t('Esta solicitação já foi aplicada ou cancelada. Recarregue a tela.'));
            }
            $resumo = escSolTrocar($rep, $par, $desde, t('troca 1 por 1 da solicitação nº {id} ({rep} com {par})', ['id' => (int) $s['id'], 'rep' => $rep, 'par' => $par]));
            // Quem pediu o contrário também foi atendido.
            if ($escolhido['contrario']) {
                escW('UPDATE ' . $tab . " SET status = 'APLICADO', troca_com = ?, troca_desde = ?, aplicado_em = NOW(), aplicado_por = ?,
                        decidido_em = COALESCE(decidido_em, NOW()), decidido_por = COALESCE(decidido_por, ?) WHERE id = ? AND status IN ('PENDENTE', 'APROVADO')",
                    [$rep, $desde, escUserId(), escUserId(), (int) $escolhido['contrario']]);
            }
            escLog('solicitacao.aplicar', 'esc_solicitacao', (int) $s['id'], null, $resumo + ['par' => $par, 'contrario' => $escolhido['contrario']]);
            return $resumo;
        });
        $nomes = escNomes();
        escVoltar('solicitacoes', ['status' => 'APLICADO'], t('Troca aplicada a partir de {dia}: {a} e {b} trocaram de lugar e de vagas fixas ({n} vagas). Recalcule as semanas a partir dessa data.', [
            'dia' => dataBr($desde), 'a' => nomeCurto($nomes[$rep] ?? $rep), 'b' => nomeCurto($nomes[$par] ?? $par), 'n' => array_sum(array_map('count', $resumo['vagas'])),
        ]));
    }
    if ($acao === 'cancelar') {
        $id = (int) ($_POST['id'] ?? 0);
        if (!escPode('decidir') && !escPode('corrigir')) {
            escExigir('decidir');
        }
        $n = escTx(function () use ($tab, $id) {
            $n = escW('UPDATE ' . $tab . " SET status = 'CANCELADO' WHERE id = ? AND status IN ('PENDENTE', 'APROVADO')", [$id]);
            if ($n) {
                escLog('solicitacao.cancelar', 'esc_solicitacao', $id);
            }
            return $n;
        });
        if (!$n) {
            throw new RuntimeException(t('Esta solicitação já foi aplicada, recusada ou cancelada. Recarregue a tela.'));
        }
        escVoltar('solicitacoes', [], t('Solicitação cancelada.'));
    }
    if ($acao === 'legado_aplicar') {
        // Times legados juntos (só no início): as trocas marcadas na prévia, todas ou nenhuma.
        escExigir('planejar');
        $desde = preg_match('~^\d{4}-\d{2}-\d{2}$~', (string) ($_POST['desde'] ?? '')) ? max($_POST['desde'], $primeiroDia) : $primeiroDia;
        $srs = escSolLegadoDados($desde)['srs'];
        $pares = [];
        foreach ((array) ($_POST['pares'] ?? []) as $par) {
            [$vem, $sai] = array_pad(explode('|', strtoupper((string) $par), 2), 2, '');
            if (!isset($srs[$vem], $srs[$sai]) || $srs[$vem]['ut'] !== $srs[$sai]['ut'] || $srs[$vem]['nucleo'] === $srs[$sai]['nucleo']) {
                throw new RuntimeException(t('A troca {a} ⇄ {b} não vale mais (alguém mudou de lugar). Abra a prévia de novo.', ['a' => $vem, 'b' => $sai]));
            }
            $pares[] = [$vem, $sai, (string) $srs[$vem]['time']];
        }
        if (!$pares) {
            throw new RuntimeException(t('Marque pelo menos uma troca.'));
        }
        escTx(static function () use ($pares, $desde) {
            foreach ($pares as [$vem, $sai, $time]) {
                escSolTrocar($vem, $sai, $desde, mb_substr('Times legados juntos (início): ' . $time, 0, 120));
            }
        });
        escLog('solicitacao.legado', 'esc_colaborador_config', null, null, ['desde' => $desde, 'pares' => $pares]);
        escVoltar('solicitacoes', [], t('{n} trocas aplicadas a partir de {dia}: os times legados ficaram juntos. Recalcule as semanas a partir dessa data.', ['n' => count($pares), 'dia' => dataBr($desde)]));
    }
    throw new RuntimeException(t('Ação desconhecida.'));
}

$STATUS = ['PENDENTE' => t('Pendentes'), 'APROVADO' => t('Aprovadas · esperando troca'), 'APLICADO' => t('Aplicadas'), 'RECUSADO' => t('Recusadas'), 'CANCELADO' => t('Canceladas'), 'TODOS' => t('Todas')];
$status = isset($STATUS[$_GET['status'] ?? '']) ? $_GET['status'] : 'PENDENTE';
$lista = escQ('SELECT * FROM ' . $tab . ($status === 'TODOS' ? '' : ' WHERE status = ?') . ' ORDER BY ' . (in_array($status, ['PENDENTE', 'APROVADO'], true) ? 'solicitado_em ASC' : 'COALESCE(aplicado_em, decidido_em, solicitado_em) DESC') . ' LIMIT 200', $status === 'TODOS' ? [] : [$status]);
$contagem = [];
foreach (escQ('SELECT status, COUNT(*) AS n FROM ' . $tab . ' GROUP BY status') as $c) {
    $contagem[$c['status']] = (int) $c['n'];
}
$nomes = escNomes();
$diaVer = $primeiroDia;
$lugares = escSolLugares($diaVer);
$abertas = escQ('SELECT * FROM ' . $tab . " WHERE status IN ('PENDENTE', 'APROVADO')");
$nucleos = [];
foreach (escQ('SELECT id, unidade, turno, numero, decurio_nome FROM ' . escT('esc_nucleo') . " WHERE vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?) ORDER BY unidade, FIELD(turno, 'D', 'A', 'C'), numero", [$diaVer, $diaVer]) as $n) {
    $nucleos[(int) $n['id']] = $n;
}
$TIPOS = ['ASSISTENTE' => t('Trabalhar como assistente'), 'DUPLA' => t('Trabalhar em dupla'), 'TURNO' => t('Mudar de turno'), 'NUCLEO' => t('Mudar de núcleo'), 'UNIDADE' => t('Mudar de unidade'), 'OUTRO' => t('Outro')];
$COR = ['PENDENTE' => 'laranja', 'APROVADO' => 'amarelo', 'APLICADO' => 'verde', 'RECUSADO' => 'vermelho', 'CANCELADO' => 'cinza'];
$ROT_STATUS = ['PENDENTE' => t('Pendente'), 'APROVADO' => t('Aprovada'), 'APLICADO' => t('Aplicada'), 'RECUSADO' => t('Recusada'), 'CANCELADO' => t('Cancelada')];
// Data sugerida para a troca: a segunda-feira seguinte (a semana em aberto já tem escala).
$segSeguinte = segundaDaSemana(escMaisDias(date('Y-m-d'), 7));
$nome = static fn(string $r) => $nomes[strtoupper($r)] ?? strtoupper($r);
?>
<header class="cabecalho">
  <span class="eyebrow"><?php echo h(t('Pessoas')); ?></span>
  <h1><?php echo h(t('Solicitações')); ?></h1>
  <p class="muted"><?php echo h(t('Pedidos dos SRs para trabalhar como assistente ou em dupla, ou mudar de turno, núcleo ou unidade. O líder registra; o Planejamento aprova e aplica a troca com outro SR: cada um vai para o lugar do outro e assume as vagas fixas dele, sem deixar posto sem dono.')); ?></p>
</header>

<section class="panel">
  <div class="chips">
    <?php foreach ($STATUS as $k => $rot): ?>
      <a class="chip-filtro <?php echo $status === $k ? 'ativo' : ''; ?>" href="<?php echo h(link_tela('solicitacoes', ['status' => $k])); ?>"<?php echo $status === $k ? ' aria-current="page"' : ''; ?>><?php echo h($rot); ?><?php echo $k !== 'TODOS' ? ' · ' . (int) ($contagem[$k] ?? 0) : ''; ?></a>
    <?php endforeach; ?>
  </div>
  <?php foreach ($lista as $s): $rep = strtoupper($s['rep_code']); $l = $lugares[$rep] ?? null; ?>
  <article class="cartao-csv sol-cartao" id="sol-<?php echo (int) $s['id']; ?>">
    <div class="linha-titulo">
      <strong><?php echo h($nome($rep) . ' · ' . $rep); ?></strong>
      <span><span class="tag"><?php echo h($TIPOS[$s['tipo']] ?? $s['tipo']); ?></span> <span class="tag <?php echo $COR[$s['status']] ?? ''; ?>"><?php echo h($ROT_STATUS[$s['status']] ?? $s['status']); ?></span></span>
    </div>
    <span class="small"><?php echo h(t('Hoje: {lugar} · vagas fixas: {vagas}', ['lugar' => escSolLugarTexto($l), 'vagas' => $l ? escSolVagasTexto($l['vagas']) : '—'])); ?><?php echo $l ? ' · OS ' . h(nomeCurto($l['os'])) : ''; ?></span>
    <span class="small"><?php echo h(t('Quer: {destino}', ['destino' => escSolDestinoTexto($s, $nucleos)])); ?><?php echo $s['a_partir'] ? ' · ' . h(t('a partir de {dia}', ['dia' => dataBr($s['a_partir'])])) : ''; ?></span>
    <blockquote class="sol-texto"><?php echo h((string) $s['texto']); ?></blockquote>
    <span class="muted small"><?php echo h(t('Registrada por {quem} em {quando}', ['quem' => $s['solicitado_por_nome'] ?: t('alguém'), 'quando' => escDataHora($s['solicitado_em'])])); ?> · <?php echo h(t('nº {id}', ['id' => (int) $s['id']])); ?></span>
    <?php if ($s['comentario_decisao']): ?><span class="muted small"><?php echo h(t('Comentário do Planejamento: {texto}', ['texto' => $s['comentario_decisao']])); ?></span><?php endif; ?>
    <?php if ($s['status'] === 'APLICADO' && $s['troca_com']): ?>
      <span class="small"><?php echo h(t('Trocou com {par} a partir de {dia}.', ['par' => $nome($s['troca_com']) . ' (' . strtoupper($s['troca_com']) . ')', 'dia' => dataBr($s['troca_desde'])])); ?></span>
    <?php endif; ?>

    <?php if ($s['status'] === 'PENDENTE' && escPode('decidir')): ?>
    <form method="post" action="<?php echo h(link_tela('solicitacoes', ['status' => $status])); ?>" class="form-grade">
      <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="decidir"><input type="hidden" name="id" value="<?php echo (int) $s['id']; ?>">
      <label><?php echo h(t('Comentário (obrigatório para recusar; volta para o líder)')); ?><input type="text" name="comentario" maxlength="500"></label>
      <div class="acoes">
        <button type="submit" name="modo" value="aprovar" class="botao primario"><?php echo h(t('Aprovar')); ?></button>
        <button type="submit" name="modo" value="recusar" class="botao perigo" data-confirmar="<?php echo h(t('Recusar a solicitação de {nome}? O comentário volta para o líder.', ['nome' => nomeCurto($nome($rep))])); ?>"><?php echo h(t('Recusar')); ?></button>
      </div>
    </form>
    <?php endif; ?>

    <?php if ($s['status'] === 'APROVADO' && $s['tipo'] !== 'OUTRO' && escPode('decidir')): $cands = array_slice(escSolCandidatos($s, $diaVer, $lugares, $abertas), 0, 15); ?>
    <form method="post" action="<?php echo h(link_tela('solicitacoes', ['status' => $status])); ?>" class="sol-troca">
      <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="aplicar"><input type="hidden" name="id" value="<?php echo (int) $s['id']; ?>">
      <?php if ($cands): ?>
      <fieldset>
        <legend><?php echo h(t('Trocar com quem? ({n} possíveis; primeiro quem pediu o contrário, depois o nível mais perto)', ['n' => count($cands)])); ?></legend>
        <div class="tabela-rolagem"><table class="sol-candidatos" data-sem-ordem>
          <thead><tr><th scope="col"><span class="so-leitor"><?php echo h(t('Escolher')); ?></span></th><th scope="col"><?php echo h(t('SR')); ?></th><th scope="col"><?php echo h(t('Onde está')); ?></th><th scope="col"><?php echo h(t('Vagas fixas (a pessoa assume estas)')); ?></th><th scope="col"><?php echo h(t('Observação')); ?></th></tr></thead>
          <tbody>
          <?php foreach ($cands as $i => $c): $idCampo = 'par-' . (int) $s['id'] . '-' . $i; ?>
            <tr>
              <td data-rotulo="<?php echo h(t('Escolher')); ?>"><input type="radio" name="par" id="<?php echo h($idCampo); ?>" value="<?php echo h($c['rep']); ?>" required></td>
              <td data-rotulo="<?php echo h(t('SR')); ?>"><label for="<?php echo h($idCampo); ?>"><strong><?php echo h($nome($c['rep'])); ?></strong> <span class="mono muted"><?php echo h($c['rep']); ?></span></label></td>
              <td data-rotulo="<?php echo h(t('Onde está')); ?>"><?php echo h(escSolLugarTexto($c)); ?></td>
              <td data-rotulo="<?php echo h(t('Vagas fixas (a pessoa assume estas)')); ?>"><?php echo h(escSolVagasTexto($c['vagas'])); ?></td>
              <td data-rotulo="<?php echo h(t('Observação')); ?>"><?php echo $c['contrario'] ? '<span class="tag verde">' . h(t('pediu o contrário (nº {id})', ['id' => $c['contrario']])) . '</span>' : '<span class="muted small">' . h(t('não pediu; combine com o líder dele')) . '</span>'; ?><?php if (!empty($c['com_pessoa'])): ?> <span class="tag azul"><?php echo h(t('já trabalha com {pessoa}', ['pessoa' => nomeCurto($nomes[strtoupper((string) $s['destino_rep'])] ?? (string) $s['destino_rep'])])); ?></span><?php elseif (!empty($c['no_nucleo'])): ?> <span class="tag azul"><?php echo h(t('no núcleo pedido')); ?></span><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      </fieldset>
      <div class="acoes">
        <label class="small"><?php echo h(t('Vale a partir de')); ?> <input type="date" name="desde" required min="<?php echo h($primeiroDia); ?>" value="<?php echo h(max($primeiroDia, $s['a_partir'] ?? '', $segSeguinte)); ?>"></label>
        <button type="submit" class="botao primario" data-longo data-confirmar="<?php echo h(t('Aplicar a troca? {nome} vai para o lugar do par escolhido e assume as vagas fixas dele, e o par vai para o lugar de {nome}. Para desfazer, é preciso uma troca de volta.', ['nome' => nomeCurto($nome($rep))])); ?>"><?php echo h(t('Aplicar troca')); ?></button>
        <span class="muted small"><?php echo h(t('Depois, recalcule as semanas a partir dessa data em Gerar escala.')); ?></span>
      </div>
      <?php else: ?>
      <p class="muted small"><?php echo h(t('Ninguém no lugar pedido serve de par agora. A solicitação fica aprovada esperando: quando alguém pedir o contrário ou houver vaga, ela aparece aqui.')); ?></p>
      <?php endif; ?>
    </form>
    <?php endif; ?>

    <?php if (in_array($s['status'], ['PENDENTE', 'APROVADO'], true) && (escPode('decidir') || escPode('corrigir'))): ?>
    <form method="post" action="<?php echo h(link_tela('solicitacoes', ['status' => $status])); ?>" class="acoes cancelar-pedido"><?php echo campoCsrf(); ?><input type="hidden" name="acao" value="cancelar"><input type="hidden" name="id" value="<?php echo (int) $s['id']; ?>"><span class="muted small"><?php echo h(t('A pessoa desistiu ou foi registrada por engano?')); ?></span><button type="submit" class="botao perigo" data-confirmar="<?php echo h(t('Cancelar esta solicitação? Ela sai da fila. Não dá para desfazer.')); ?>"><?php echo h(t('Cancelar solicitação')); ?></button></form>
    <?php endif; ?>
  </article>
  <?php endforeach; ?>
  <?php if (!$lista): ?><p class="muted"><?php echo h($status === 'PENDENTE' ? t('Nenhuma solicitação esperando decisão.') : t('Nenhuma solicitação neste filtro.')); ?></p><?php endif; ?>
</section>

<?php if (escPode('corrigir')): ?>
<section class="panel" id="registrar">
  <h2><?php echo h(t('Registrar solicitação')); ?></h2>
  <form method="post" action="<?php echo h(link_tela('solicitacoes')); ?>" class="form-grade sol-form">
    <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="registrar">
    <label><?php echo h(t('Colaborador')); ?><input type="search" name="rep_code" list="lista-colaboradores" autocomplete="off" placeholder="<?php echo h(t('buscar nome ou código')); ?>" required></label>
    <label><?php echo h(t('O que a pessoa pede')); ?><select name="tipo" required><?php foreach ($TIPOS as $k => $rot): ?><option value="<?php echo $k; ?>"><?php echo h($rot); ?></option><?php endforeach; ?></select></label>
    <label data-sol-campo="TURNO UNIDADE"><?php echo h(t('Turno desejado')); ?><select name="destino_turno"><option value=""><?php echo h(t('o mesmo')); ?></option><?php foreach (ESC_SOL_TURNO_BLOCOS as $k => $rot): ?><option value="<?php echo $k; ?>"><?php echo h($rot); ?></option><?php endforeach; ?></select></label>
    <label data-sol-campo="TURNO UNIDADE"><?php echo h(t('Unidade desejada')); ?><select name="destino_unidade"><option value=""><?php echo h(t('a mesma')); ?></option><?php foreach ([1, 2, 3] as $u): ?><option value="<?php echo $u; ?>">U<?php echo $u; ?></option><?php endforeach; ?></select></label>
    <label data-sol-campo="NUCLEO ASSISTENTE DUPLA"><?php echo h(t('Núcleo (unidade, turno e OS)')); ?><select name="destino_nucleo_id"><option value=""><?php echo h(t('escolha')); ?></option><?php foreach ($nucleos as $n): ?><option value="<?php echo (int) $n['id']; ?>"><?php echo h('U' . $n['unidade'] . ' · ' . ESC_SOL_TURNO_BLOCOS[$n['turno']] . ' · ' . t('núcleo {n}', ['n' => $n['numero']]) . ' · OS ' . nomeCurto($n['decurio_nome'])); ?></option><?php endforeach; ?></select></label>
    <label data-sol-campo="ASSISTENTE DUPLA"><?php echo h(t('Com quem (titular ou par)')); ?><input type="search" name="rep" list="lista-colaboradores" autocomplete="off" placeholder="<?php echo h(t('nome ou código (opcional)')); ?>"></label>
    <label><?php echo h(t('A partir de (opcional)')); ?><input type="date" name="a_partir" min="<?php echo h($primeiroDia); ?>"></label>
    <label class="sol-texto-campo"><?php echo h(t('Pedido (nas palavras da pessoa, com o motivo)')); ?><textarea name="texto" rows="3" maxlength="500" required></textarea></label>
    <div class="acoes"><button type="submit" class="botao primario"><?php echo h(t('Registrar solicitação')); ?></button></div>
  </form>
  <p class="muted small"><?php echo h(t('Assistente e dupla: diga onde a pessoa fica fixa (núcleo: unidade, turno e OS) e, se já souber, com quem (o titular que vai assistir ou o par da dupla). Assistente fica em posto de assistente ou de apoio; dupla também vale página de dupla. A troca só acontece quando o Planejamento aprova e escolhe o par.')); ?></p>
</section>
<script>
(function () {
  var f = document.querySelector('.sol-form');
  if (!f) { return; }
  var tipo = f.querySelector('[name="tipo"]');
  function mostrar() {
    f.querySelectorAll('[data-sol-campo]').forEach(function (el) {
      var ok = el.dataset.solCampo.split(' ').indexOf(tipo.value) !== -1;
      el.hidden = !ok;
      el.querySelectorAll('select').forEach(function (s) { s.disabled = !ok; });
    });
    f.querySelector('[name="destino_nucleo_id"]').required = tipo.value === 'NUCLEO';
  }
  tipo.addEventListener('change', mostrar);
  mostrar();
})();
</script>
<?php endif; ?>

<?php if (escPode('planejar')): ?>
<section class="panel" id="legado">
  <h2><?php echo h(t('Início: times legados juntos')); ?></h2>
  <p class="muted small"><?php echo h(t('Só para o começo da estrutura nova: junta 2 ou 3 pessoas de cada time legado no mesmo núcleo, com trocas 1 por 1 dentro da mesma unidade e turno (cada um vai para o lugar do outro, com as vagas fixas). Não mexe em quem tem prioridade fixa ou pedido aberto, nem desfaz um par que já está junto. Times "SOLO" não contam. Depois vale a regra normal.')); ?></p>
  <?php if (empty($_GET['legado'])): ?>
  <div class="acoes"><a class="botao" href="<?php echo h(link_tela('solicitacoes', ['legado' => 1])); ?>#legado"><?php echo h(t('Ver prévia das trocas')); ?></a></div>
  <?php else:
      $dadosLeg = escSolLegadoDados($primeiroDia);
      $planoLeg = escSolLegadoPlano($dadosLeg['srs'], $dadosLeg['travados']);
      $nucleoTxt = static fn(int $id) => isset($nucleos[$id]) ? 'U' . $nucleos[$id]['unidade'] . ' · ' . ESC_SOL_TURNO_BLOCOS[$nucleos[$id]['turno']] . ' · ' . t('núcleo {n}', ['n' => $nucleos[$id]['numero']]) . ($nucleos[$id]['decurio_nome'] ? ' (' . nomeCurto($nucleos[$id]['decurio_nome']) . ')' : '') : '#' . $id;
      $contaTimes = static function (array $srs): array {
          $g = [];
          foreach ($srs as $x) {
              if ($x['time'] && stripos((string) $x['time'], 'SOLO') !== 0) {
                  $g[$x['ut'] . '|' . $x['time']][$x['nucleo']] = ($g[$x['ut'] . '|' . $x['time']][$x['nucleo']] ?? 0) + 1;
              }
          }
          $g = array_filter($g, static fn($ns) => array_sum($ns) >= 2);
          return [count(array_filter($g, static fn($ns) => max($ns) >= 2)), count($g)];
      };
      $depois = $dadosLeg['srs'];
      foreach ($planoLeg as $x) {
          $depois[$x['vem']]['nucleo'] = $x['para'];
          $depois[$x['sai']]['nucleo'] = $x['de'];
      }
      [$okA, $totA] = $contaTimes($dadosLeg['srs']);
      [$okD] = $contaTimes($depois);
  ?>
  <p><?php echo h(t('Hoje {a} de {t} times (com 2 ou mais pessoas no mesmo turno) têm pelo menos 2 juntos no mesmo núcleo; com as trocas abaixo, {d}.', ['a' => $okA, 't' => $totA, 'd' => $okD])); ?></p>
  <?php if (!$planoLeg): ?><p class="muted"><?php echo h(t('Nada a trocar: os times já estão juntos.')); ?></p><?php else: ?>
  <form method="post" action="<?php echo h(link_tela('solicitacoes')); ?>">
    <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="legado_aplicar">
    <div class="tabela-rolagem"><table>
      <thead><tr><th><input type="checkbox" checked data-marcar-todos aria-label="<?php echo h(t('Marcar todas')); ?>"></th><th><?php echo h(t('Time')); ?></th><th><?php echo h(t('Vai para o núcleo do time')); ?></th><th><?php echo h(t('Troca com (vai para o núcleo de origem)')); ?></th><th><?php echo h(t('Observação')); ?></th></tr></thead>
      <tbody>
      <?php foreach ($planoLeg as $x): ?>
        <tr>
          <td><input type="checkbox" name="pares[]" value="<?php echo h($x['vem'] . '|' . $x['sai']); ?>" checked aria-label="<?php echo h($x['vem'] . ' ⇄ ' . $x['sai']); ?>"></td>
          <td><strong><?php echo h((string) $x['time']); ?></strong></td>
          <td><?php echo h(nomeCurto($nome($x['vem']))); ?> <span class="mono muted small"><?php echo h($x['vem']); ?></span><br><span class="small muted"><?php echo h($nucleoTxt($x['de']) . ' → ' . $nucleoTxt($x['para'])); ?></span></td>
          <td><?php echo h(nomeCurto($nome($x['sai']))); ?> <span class="mono muted small"><?php echo h($x['sai']); ?></span><br><span class="small muted"><?php echo h(($x['time_sai'] ?: t('sem time')) . ' · ' . $nucleoTxt($x['para']) . ' → ' . $nucleoTxt($x['de'])); ?></span></td>
          <td class="small"><?php echo $x['junta_outro'] ? '<span class="tag verde">' . h(t('junta o time do outro também')) . '</span> ' : ''; ?><?php echo $x['mesmo_nivel'] ? '' : '<span class="tag amarelo">' . h(t('níveis diferentes')) . '</span>'; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="acoes">
      <label><?php echo h(t('A partir de')); ?> <input type="date" name="desde" value="<?php echo h($primeiroDia); ?>" min="<?php echo h($primeiroDia); ?>"></label>
      <button type="submit" class="botao primario" data-confirmar="<?php echo h(t('Aplicar as trocas marcadas? Cada par troca de núcleo e de vagas fixas a partir da data. Depois, recalcule as semanas.')); ?>"><?php echo h(t('Aplicar trocas marcadas')); ?></button>
    </div>
  </form>
  <?php endif; ?>
  <?php endif; ?>
</section>
<script>
document.querySelectorAll('[data-marcar-todos]').forEach(function (c) {
  c.addEventListener('change', function () { c.closest('table').querySelectorAll('tbody input[type=checkbox]').forEach(function (x) { x.checked = c.checked; }); });
});
</script>
<?php endif; ?>
