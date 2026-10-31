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
        escVoltar('grade', ['plano' => $id], t('Rascunho gerado para a semana de {dia}.', ['dia' => escData($segPost)]) . escEstruturaAviso() . escEquipesAviso() . escCasaisAviso($segPost));
    }
    if ($acao === 'recalcular') {
        if (!escPlanoAtual($segPost)) {
            throw new RuntimeException(t('Não há versão desta semana para recalcular. Gere primeiro.'));
        }
        escW('INSERT INTO ' . escT('esc_recalculo_evento') . " (gatilho, descricao, semana_inicio, status) VALUES ('MANUAL', 'Recálculo pedido na tela', ?, 'DETECTADO')", [$segPost]);
        $ev = escUltimoId();
        $id = escMotorGerar($segPost, 'RECALCULO', escUserId(), $ev);
        escVoltar('grade', ['plano' => $id], t('Recalculado. Veja o De→Para no fim da tela Gerar escala.') . escEstruturaAviso() . escEquipesAviso() . escCasaisAviso($segPost));
    }
    if ($acao === 'recalcular_minimo') {
        if (!escPlanoAtual($segPost)) {
            throw new RuntimeException(t('Não há versão desta semana para recalcular. Gere primeiro.'));
        }
        escW('INSERT INTO ' . escT('esc_recalculo_evento') . " (gatilho, descricao, semana_inicio, status) VALUES ('MANUAL', 'Recálculo com o mínimo de mudanças', ?, 'DETECTADO')", [$segPost]);
        $ev = escUltimoId();
        $id = escMotorGerar($segPost, 'RECALCULO', escUserId(), $ev, true);
        escVoltar('grade', ['plano' => $id], t('Recalculado com o mínimo de mudanças.') . escMinimoAviso() . escEstruturaAviso() . escEquipesAviso() . escCasaisAviso($segPost));
    }
    if ($acao === 'verificar') {
        $r = escMotorVerificar(escUserId(), true);
        $n = count($r['recalculados']);
        escVoltar('gerar', ['semana' => $segPost], $n ? tn($n, '{n} semana recalculada: {fontes}', '{n} semanas recalculadas: {fontes}', ['fontes' => implode(', ', array_map(static fn($x) => implode('/', array_map('escNomeFonte', $x['fontes'])), $r['recalculados']))]) : t('Nada mudou nas entradas desde a última versão.'));
    }
    if ($acao === 'excluir') {
        // Versões substituídas ou rascunho: apaga a versão e o que é só dela. A publicada não sai;
        // versão já postada no site também não (é a base do "sem mudança" da postagem).
        $ids = array_values(array_unique(array_filter(array_map('intval', is_array($_POST['planos'] ?? null) ? $_POST['planos'] : []))));
        if (!$ids) {
            throw new RuntimeException(t('Selecione ao menos uma versão.'));
        }
        $planos = escQ('SELECT id, semana_inicio, versao, subversao, status, plano_anterior_id FROM ' . escT('esc_plano') . ' WHERE id IN (' . escIn($ids) . ')', $ids);
        $postados = [];
        try {
            foreach (escQ('SELECT DISTINCT plano_id FROM ' . escT('esc_plano_postagem') . ' WHERE postado_em IS NOT NULL AND plano_id IN (' . escIn($ids) . ')', $ids) as $r) {
                $postados[(int) $r['plano_id']] = true;
            }
        } catch (Throwable $e) {
            // ES_23 ainda não rodou: nada postado pelo controle
        }
        $apagar = [];
        $pulados = [];
        $comDecisao = [];
        foreach (escQ('SELECT DISTINCT plano_id FROM ' . escT('esc_sugestao') . " WHERE status = 'APLICADA' AND plano_id IN (" . escIn($ids) . ')', $ids) as $r) {
            $comDecisao[(int) $r['plano_id']] = t('sugestão aplicada');
        }
        try {
            foreach (escQ('SELECT DISTINCT plano_id FROM ' . escT('esc_hora_extra') . " WHERE status = 'ALOCADO' AND plano_id IN (" . escIn($ids) . ')', $ids) as $r) {
                $comDecisao[(int) $r['plano_id']] = t('hora extra alocada');
            }
        } catch (Throwable $e) {
            // tabela ainda não criada (ES_14)
        }
        foreach ($planos as $pl) {
            if (isset($comDecisao[(int) $pl['id']])) {
                $pulados[] = escPlanoRotulo($pl) . ' (' . $comDecisao[(int) $pl['id']] . ')';
                continue;
            }
            if ($pl['status'] === 'PUBLICADO' || isset($postados[(int) $pl['id']])) {
                $pulados[] = $pl['status'] === 'PUBLICADO' ? t('{versao} (publicada)', ['versao' => escPlanoRotulo($pl)]) : t('{versao} (já postada no site)', ['versao' => escPlanoRotulo($pl)]);
                continue;
            }
            $apagar[(int) $pl['id']] = $pl;
        }
        // Mais nova primeiro: a corrente de "versão anterior" fica sempre válida.
        uasort($apagar, static fn($a, $b) => [(int) $b['versao'], (int) $b['subversao']] <=> [(int) $a['versao'], (int) $a['subversao']]);
        escTx(function () use ($apagar) {
            foreach ($apagar as $id => $pl) {
                // Quem apontava para esta versão passa a apontar para a anterior dela.
                escW('UPDATE ' . escT('esc_plano') . ' SET plano_anterior_id = ? WHERE plano_anterior_id = ?', [$pl['plano_anterior_id'], $id]);
                escW('UPDATE ' . escT('esc_recalculo_evento') . ' SET plano_de_id = NULL WHERE plano_de_id = ?', [$id]);
                escW('UPDATE ' . escT('esc_recalculo_evento') . ' SET plano_para_id = NULL WHERE plano_para_id = ?', [$id]);
                escW('DELETE FROM ' . escT('esc_plano_diferenca') . ' WHERE plano_de_id = ? OR plano_para_id = ?', [$id, $id]);
                escW('DELETE FROM ' . escT('esc_sugestao') . ' WHERE plano_id = ?', [$id]);
                escW('UPDATE ' . escT('esc_exportacao') . ' SET plano_id = NULL WHERE plano_id = ?', [$id]);
                try {
                    escW('UPDATE ' . escT('esc_hora_extra') . ' SET plano_id = NULL WHERE plano_id = ?', [$id]);
                } catch (Throwable $e) {
                    // tabela ainda não criada (ES_14)
                }
                try {
                    escW('DELETE FROM ' . escT('esc_plano_postagem') . ' WHERE plano_id = ?', [$id]);
                } catch (Throwable $e) {
                    // tabela ainda não criada (ES_23)
                }
                // Alocações e folgas saem junto (ON DELETE CASCADE). Publicada no meio do caminho não sai.
                if (escW('DELETE FROM ' . escT('esc_plano') . " WHERE id = ? AND status <> 'PUBLICADO'", [$id]) === 0) {
                    throw new RuntimeException(t('A versão {versao} foi publicada enquanto você excluía. Nada foi apagado.', ['versao' => escPlanoRotulo($pl)]));
                }
                escLog('plano.excluir', 'esc_plano', $id, ['versao' => escPlanoRotulo($pl), 'status' => $pl['status'], 'semana' => substr((string) $pl['semana_inicio'], 0, 10)], null);
            }
        });
        escVoltar('gerar', ['semana' => $segPost], ($apagar ? tn(count($apagar), '{n} versão excluída: {lista}.', '{n} versões excluídas: {lista}.', ['lista' => implode(', ', array_map('escPlanoRotulo', $apagar))]) : t('Nenhuma versão excluída.'))
            . ($pulados ? ' ' . t('Ficaram: {lista}.', ['lista' => implode(', ', $pulados)]) : ''));
    }
    throw new RuntimeException(t('Ação desconhecida.'));
}

// Semana na estrutura de 01/11: o motor v4 não usa times nem parties.
$v4 = null;
if (escV4Ativo($seg)) {
    $dv = max($seg, escV4DiaEstrutura());
    $vig = ' vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)';
    $v4 = [
        'srs' => (int) escQ1('SELECT COUNT(*) AS n FROM ' . escT('esc_colaborador_config') . " WHERE funcao = 'EQUITE' AND nucleo_id IS NOT NULL AND" . $vig, [$dv, $dv])['n'],
        'nucleos' => (int) escQ1('SELECT COUNT(*) AS n FROM ' . escT('esc_nucleo') . ' WHERE' . $vig, [$dv, $dv])['n'],
        'unidades' => (int) escQ1('SELECT COUNT(DISTINCT unidade) AS n FROM ' . escT('esc_nucleo') . ' WHERE' . $vig, [$dv, $dv])['n'],
        'postos' => (int) escQ1('SELECT COUNT(*) AS n FROM ' . escT('esc_posto') . ' WHERE' . $vig, [$dv, $dv])['n'],
    ];
    $eq = escQ1('SELECT COUNT(*) AS n, SUM(status = \'PROPOSTA\') AS propostas FROM ' . escT('esc_vaga_equipe') . ' WHERE' . $vig, [$dv, $dv]);
    $v4['equipes'] = (int) $eq['n'];
    $v4['propostas'] = (int) $eq['propostas'];
    $v4['sem_equipe'] = escEquipesVazias($dv);
}
$parties = $v4 ? [] : escFormacaoParties();
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
$travadasAtual = $atual ? (int) escQ1('SELECT COUNT(*) AS n FROM ' . escT('esc_plano_alocacao') . ' WHERE plano_id = ? AND travada = 1', [(int) $atual['id']])['n'] : 0;
$difs = [];
if ($atual && $atual['plano_anterior_id']) {
    $difs = escQ('SELECT tipo, COUNT(*) AS n, SUM(aplicado = 0) AS nao_aplicados FROM ' . escT('esc_plano_diferenca') . ' WHERE plano_para_id = ? GROUP BY tipo', [(int) $atual['id']]);
}
$eventos = escQ('SELECT * FROM ' . escT('esc_recalculo_evento') . ' WHERE semana_inicio = ? OR semana_inicio IS NULL ORDER BY id DESC LIMIT 10', [$seg]);
$mudancasEntrada = escQ('SELECT d.fonte, COUNT(*) AS n, MAX(f.lido_em) AS quando FROM ' . escT('esc_entrada_diferenca') . ' d JOIN ' . escT('esc_entrada_foto') . ' f ON f.id = d.foto_para_id WHERE f.lido_em >= NOW() - INTERVAL 7 DAY GROUP BY d.fonte ORDER BY quando DESC');
?>
<header class="cabecalho">
  <span class="eyebrow"><?php echo h(t('Escala')); ?></span>
  <h1><?php echo h(t('Gerar escala')); ?></h1>
  <p class="muted"><?php echo h(t('Gera o rascunho da semana a partir do Capacity, da estrutura do Planner, da configuração de cada pessoa e dos pedidos aprovados. A escala oficial continua no site da 5C: daqui sai o CSV para postar.')); ?></p>
</header>

<section class="panel">
  <form method="get" action="<?php echo h(MODULE_URL); ?>/index.php" class="linha-titulo">
    <input type="hidden" name="p" value="gerar">
    <label class="filtros"><?php echo h(t('Semana')); ?>
      <select name="semana" onchange="this.form.submit()">
        <?php foreach ($semanas as $s): ?><option value="<?php echo h($s); ?>" <?php echo $s === $seg ? 'selected' : ''; ?>><?php echo h(t('{de} a {ate}', ['de' => dataBr($s), 'ate' => dataBr(escMaisDias($s, 6))])); ?></option><?php endforeach; ?>
      </select>
    </label>
    <span class="muted small"><?php echo h(t('Versão atual:')); ?> <strong><?php echo $atual ? h(escPlanoRotulo($atual) . ' · ' . escRotulo('status_plano', $atual['status'])) : h(t('nenhuma')); ?></strong></span>
  </form>

  <?php if ($v4): ?>
  <div class="kpis">
    <div class="kpi"><span class="muted"><?php echo h(t('Estrutura nova')); ?></span><strong><?php echo (int) $v4['srs']; ?></strong><span class="muted small"><?php echo h(t('SRs em {nucleos} núcleos de {unidades} unidades · rotação de 21 dias', ['nucleos' => (int) $v4['nucleos'], 'unidades' => (int) $v4['unidades']])); ?></span></div>
    <div class="kpi"><span class="muted"><?php echo h(t('Postos')); ?></span><strong><?php echo (int) $v4['postos']; ?></strong><span class="muted small"><?php echo h(t('foto do Capacity, cada um com vaga do dono e de cobertura')); ?></span></div>
    <div class="kpi"><span class="muted"><?php echo h(t('Equipes das vagas')); ?></span><strong><?php echo (int) $v4['equipes']; ?></strong><span class="muted small"><?php echo h(t('{n} ainda como proposta · a equipe é preferência, não trava', ['n' => (int) $v4['propostas']])); ?><?php if ($v4['sem_equipe']): ?><br><span class="laranja"><?php echo h(tn($v4['sem_equipe'], '{n} posto sem equipe: será completado ao gerar', '{n} postos sem equipe: serão completados ao gerar')); ?></span><?php endif; ?></span></div>
  </div>
  <?php else: ?>
  <div class="kpis">
    <div class="kpi"><span class="muted"><?php echo h(t('Pessoas configuradas')); ?></span><strong><?php echo (int) ($config['n'] ?? 0); ?></strong><span class="muted small"><?php echo h(t('{n} Buffers · posição no ciclo pela releitura do realizado', ['n' => (int) ($config['buffers'] ?? 0)])); ?></span></div>
    <div class="kpi"><span class="muted">Capacity</span><strong><?php echo h(dataBr($capacity['dc'] ?? null)); ?></strong><span class="muted small"><?php echo h(t('dias fora do horizonte usam o mesmo dia da semana')); ?></span></div>
    <div class="kpi"><span class="muted"><?php echo h(t('Parties que bloqueiam')); ?></span><strong class="<?php echo $bloqueantes ? 'laranja' : ''; ?>"><?php echo count($bloqueantes); ?></strong><span class="muted small"><?php echo h(t('sem líder ou sem página: as páginas delas vão para outras parties e Buffers')); ?></span></div>
  </div>
  <?php endif; ?>

  <?php if (escPode('planejar')): ?>
  <div class="acoes">
    <?php if ($atual): ?>
    <form method="post" action="<?php echo h(link_tela('gerar', ['semana' => $seg])); ?>" data-longo="<?php echo h(t('Recalculando… pode levar até 3 min.')); ?>">
      <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="recalcular_minimo"><input type="hidden" name="semana" value="<?php echo h($seg); ?>">
      <button type="submit" class="botao primario" title="<?php echo h(t('Parte da versão publicada (a postada) e muda só o necessário: página nova, quem saiu ou ficou indisponível. Quase todos os CSVs continuam iguais.')); ?>"><?php echo h(t('Recalcular com o mínimo de mudanças')); ?></button>
    </form>
    <form method="post" action="<?php echo h(link_tela('gerar', ['semana' => $seg])); ?>" data-longo="<?php echo h(t('Recalculando… pode levar até 3 min.')); ?>" data-confirmar="<?php echo h(t('Recalcular tudo refaz a semana inteira com a estrutura de hoje: muitas páginas podem mudar e precisar ser postadas de novo. Os ajustes travados ficam. Recalcular tudo?')); ?>">
      <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="recalcular"><input type="hidden" name="semana" value="<?php echo h($seg); ?>">
      <button type="submit" class="botao"><?php echo h(tn($travadasAtual, 'Recalcular tudo (mantém {n} ajuste travado)', 'Recalcular tudo (mantém {n} ajustes travados)')); ?></button>
    </form>
    <?php endif; ?>
    <form method="post" action="<?php echo h(link_tela('gerar', ['semana' => $seg])); ?>" data-longo="<?php echo h(t('Gerando… pode levar até 3 min.')); ?>"<?php if ($atual && $travadasAtual): ?> data-confirmar="<?php echo h(tn($travadasAtual, 'Gerar do zero descarta o {n} ajuste manual travado da {versao}. Para manter, use Recalcular. Gerar do zero mesmo assim?', 'Gerar do zero descarta os {n} ajustes manuais travados da {versao}. Para manter, use Recalcular. Gerar do zero mesmo assim?', ['versao' => escPlanoRotulo($atual)])); ?>"<?php endif; ?>>
      <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="gerar"><input type="hidden" name="semana" value="<?php echo h($seg); ?>">
      <button type="submit" class="botao <?php echo $atual ? '' : 'primario'; ?>"><?php echo $atual ? h(t('Gerar do zero (nova versão)')) : h(t('Gerar rascunho')); ?></button>
    </form>
    <form method="post" action="<?php echo h(link_tela('gerar', ['semana' => $seg])); ?>" data-longo="<?php echo h(t('Verificando… pode levar até 3 min.')); ?>">
      <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="verificar"><input type="hidden" name="semana" value="<?php echo h($seg); ?>">
      <button type="submit" class="botao"><?php echo h(t('Verificar mudanças')); ?></button>
    </form>
  </div>
  <?php if ($atual): ?><p class="muted small"><?php echo h(t('Recalcular com o mínimo de mudanças parte da versão publicada e só mexe no necessário (use depois de postar). Recalcular tudo refaz a semana com a estrutura de hoje e mantém o que foi ajustado à mão. Gerar do zero cria uma versão nova sem os ajustes.')); ?></p><?php endif; ?>
  <?php else: ?>
  <p class="muted small"><?php echo h(t('Seu perfil só visualiza. Gerar e recalcular: Planejamento ou Admin.')); ?></p>
  <?php endif; ?>
  <?php if ($v4): ?>
  <ol class="roteiro muted small">
    <li><strong><?php echo h(t('Estrutura nova')); ?></strong> <?php echo h(t('(a partir de {dia} às {hora}): unidade → turno → 3 núcleos; todo dia 2 núcleos trabalham. Os times e parties atuais não entram.', ['dia' => dataBr(escV4DiaVirada()), 'hora' => substr(escV4Param('v4_virada', '2026-11-01 05:00'), 11, 5)])); ?></li>
    <li><strong><?php echo h(t('Ordem de preenchimento de cada vaga:')); ?></strong> <?php echo t('melhor SR nas páginas SS/A/B → equipe fixa da vaga → outra pessoa do núcleo → buffer do outro núcleo do turno → Overlap → remanejamento → acúmulo (até 15% acima da capacidade, com ⚠) → lacuna. Quem fica sem vaga é buffer de plantão (página 5C Schedule, {codigo} no CSV).', ['codigo' => '<code>-2</code>']); ?></li>
    <li><strong><?php echo h(t('Menos troca de gente:')); ?></strong> <?php echo h(t('para escolher quem cobre, vem primeiro quem estava no posto (inclusive na semana anterior), depois quem é da equipe de alguma página do posto, depois quem mais trabalhou nas páginas dele nas últimas 8 semanas. No empate, a nota de mérito do 5C George decide (tela Equilíbrio dos SRs).')); ?></li>
    <li><strong><?php echo h(t('Recálculo:')); ?></strong> <?php echo h(t('qualquer mudança nas entradas gera uma nova subversão (v1.1). Ajuste feito na grade fica travado.')); ?></li>
  </ol>
  <?php else: ?>
  <ol class="roteiro muted small">
    <li><strong><?php echo h(t('Releitura:')); ?></strong> <?php echo h(t('lê as últimas 4 semanas do scheduleday e descobre onde cada pessoa está no ciclo e quantos dias seguidos já trabalhou.')); ?></li>
    <li><strong><?php echo h(t('Ordem de preenchimento:')); ?></strong> <?php echo h(t('party dona → outras parties do time → SR regular → SR assistente → Buffers → lacuna.')); ?></li>
    <li><strong><?php echo h(t('Recálculo:')); ?></strong> <?php echo h(t('qualquer mudança no Capacity, estrutura, páginas, colaboradores, pedidos aprovados ou modelos gera uma nova subversão (v3.1). Quem já está na escala publicada não sai sozinho: vira sugestão.')); ?></li>
  </ol>
  <?php endif; ?>
</section>

<section class="panel">
  <h2><?php echo h(t('Versões da semana')); ?></h2>
  <?php $podeExcluir = escPode('planejar') && array_filter($versoes, static fn($v) => $v['status'] !== 'PUBLICADO'); ?>
  <?php if ($podeExcluir): ?>
  <form method="post" action="<?php echo h(link_tela('gerar', ['semana' => $seg])); ?>" id="versoes-excluir" class="acoes lote-barra">
    <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="excluir"><input type="hidden" name="semana" value="<?php echo h($seg); ?>">
    <span class="small"><?php echo t('{n} selecionada(s)', ['n' => '<strong id="versoes-n">0</strong>']); ?></span>
    <button type="button" class="botao" id="versoes-substituidas"><?php echo h(t('Selecionar as substituídas')); ?></button>
    <button type="submit" class="botao" id="versoes-botao" disabled><?php echo h(t('Excluir selecionadas')); ?></button>
    <span class="muted small"><?php echo h(t('A versão publicada e as já postadas no site não saem.')); ?></span>
  </form>
  <?php endif; ?>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><?php if ($podeExcluir): ?><th data-sem-ordem class="col-sel"></th><?php endif; ?><th><?php echo h(t('Versão')); ?></th><th><?php echo h(t('Status')); ?></th><th><?php echo h(t('Gerada em')); ?></th><th><?php echo h(t('Gatilho')); ?></th><th class="num"><?php echo h(t('Blocos')); ?></th><th class="num"><?php echo h(t('Lacunas')); ?></th><th class="num"><?php echo h(t('Anomalias')); ?></th><th class="num"><?php echo h(t('Buffers sem página')); ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($versoes as $v): $r = json_decode((string) $v['resumo'], true) ?: []; ?>
      <tr>
        <?php if ($podeExcluir): ?><td class="col-sel"><?php if ($v['status'] !== 'PUBLICADO'): ?><input type="checkbox" name="planos[]" value="<?php echo (int) $v['id']; ?>" form="versoes-excluir" class="versao-sel" data-status="<?php echo h($v['status']); ?>" data-rotulo="<?php echo h(escPlanoRotulo($v)); ?>" aria-label="<?php echo h(t('Selecionar {versao}', ['versao' => escPlanoRotulo($v)])); ?>"><?php endif; ?></td><?php endif; ?>
        <td><strong><?php echo h(escPlanoRotulo($v)); ?></strong></td>
        <td><?php echo escTagStatusPlano($v['status']); ?></td>
        <td class="small"><?php echo h(escDataHora($v['gerado_em'])); ?></td>
        <td class="small"><?php echo $v['gatilho'] ? h($v['gatilho_txt']) : h(t('geração manual')); ?></td>
        <td class="num"><?php echo (int) ($r['blocos'] ?? 0); ?></td>
        <td class="num <?php echo !empty($r['lacunas']) ? 'laranja' : ''; ?>"><?php echo (int) ($r['lacunas'] ?? 0); ?></td>
        <td class="num <?php echo !empty($r['anomalias']) ? 'vermelho' : ''; ?>"><?php echo (int) ($r['anomalias'] ?? 0); ?></td>
        <td class="num"><?php echo (int) ($r['buffers_ociosos'] ?? 0); ?></td>
        <td><a class="botao" href="<?php echo h(link_tela('grade', ['plano' => $v['id']])); ?>"><?php echo h(t('Abrir')); ?></a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$versoes): ?><tr><td colspan="9" class="muted"><?php echo h(t('Nenhuma versão gerada para esta semana.')); ?></td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <?php if ($podeExcluir): ?>
  <script>
  (function () {
    var form = document.getElementById('versoes-excluir'), n = document.getElementById('versoes-n'), botao = document.getElementById('versoes-botao');
    var sel = function () { return Array.prototype.slice.call(document.querySelectorAll('.versao-sel')); };
    function contar() { var k = sel().filter(function (c) { return c.checked; }).length; n.textContent = k; botao.disabled = k === 0; }
    document.addEventListener('change', function (ev) { if (ev.target.classList.contains('versao-sel')) { contar(); } });
    document.getElementById('versoes-substituidas').addEventListener('click', function () { sel().forEach(function (c) { c.checked = c.dataset.status === 'SUBSTITUIDO'; }); contar(); });
    form.addEventListener('submit', function (ev) {
      var r = sel().filter(function (c) { return c.checked; }).map(function (c) { return c.dataset.rotulo; });
      if (form.dataset.confirmado === '1') { delete form.dataset.confirmado; return; }
      ev.preventDefault();
      var msg = <?php echo json_encode(t('Excluir {n} versão(ões)?')); ?>.replace('{n}', r.length) + '\n\n' + r.join(', ') + '\n\n' + <?php echo json_encode(t('Apaga as alocações, folgas e o De→Para delas. Não dá para desfazer.')); ?>;
      escConfirmar(msg, { botao: <?php echo json_encode(t('Excluir')); ?>, perigo: true, origem: botao }).then(function (ok) {
        if (ok) { form.dataset.confirmado = '1'; form.requestSubmit(); }
      });
    });
  })();
  </script>
  <?php endif; ?>
</section>

<?php if ($difs): ?>
<section class="panel">
  <h2><?php echo h(t('De→Para da última versão ({versao})', ['versao' => escPlanoRotulo($atual)])); ?></h2>
  <div class="chips">
    <?php foreach ($difs as $d): ?><span class="tag"><?php echo h(escRotulo('tipo_diferenca', $d['tipo'])); ?>: <?php echo (int) $d['n']; ?><?php echo (int) $d['nao_aplicados'] ? ' ' . h(t('({n} mantidos por já estarem publicados)', ['n' => (int) $d['nao_aplicados']])) : ''; ?></span><?php endforeach; ?>
  </div>
  <a class="botao" href="<?php echo h(link_tela('grade', ['plano' => $atual['id'], 'aba' => 'depara'])); ?>"><?php echo h(t('Ver célula a célula')); ?></a>
</section>
<?php endif; ?>

<section class="panel">
  <h2><?php echo h(t('Mudanças nas entradas (últimos 7 dias)')); ?></h2>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th><?php echo h(t('Fonte')); ?></th><th class="num"><?php echo h(t('Itens que mudaram')); ?></th><th><?php echo h(t('Quando')); ?></th></tr></thead>
    <tbody>
    <?php foreach ($mudancasEntrada as $m): ?>
      <tr><td><?php echo h(escNomeFonte($m['fonte'])); ?></td><td class="num"><?php echo (int) $m['n']; ?></td><td class="small"><?php echo h(escDataHora($m['quando'])); ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$mudancasEntrada): ?><tr><td colspan="3" class="muted"><?php echo h(t('Nenhuma mudança registrada. A primeira verificação só tira a foto inicial.')); ?></td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <h3 class="small muted"><?php echo h(t('Eventos de recálculo')); ?></h3>
  <ul class="regras small">
    <?php foreach ($eventos as $e): ?>
      <li><?php echo h(escDataHora($e['detectado_em'])); ?> · <?php echo h($e['descricao']); ?> · <span class="tag <?php echo $e['status'] === 'ERRO' ? 'vermelho' : ''; ?>"><?php echo h(escRotulo('status_evento', $e['status'])); ?></span><?php echo $e['erro'] ? ' ' . h($e['erro']) : ''; ?></li>
    <?php endforeach; ?>
    <?php if (!$eventos): ?><li class="muted"><?php echo h(t('Nenhum ainda.')); ?></li><?php endif; ?>
  </ul>
</section>
