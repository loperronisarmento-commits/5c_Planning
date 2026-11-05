<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/fixacoes.php
// Restrições dos OM (antes "Fixações"): fixações e restrições feitas à mão (pedidos de 04/11), lidas do banco na hora, para mandar às equipes:
//   ?unidade=N            → as da unidade N (páginas com regra e pessoas com regra);
//   ?pagina=<nome>        → a configuração completa de uma página;
//   &imprimir=1           → abre a impressão (Salvar como PDF), no papel timbrado da 5C.
// Fontes: alocação, cor e plataforma (esc_pagina_config), equipe fixa (esc_pagina_prioridade), só assistente
// e proibido (esc_pagina_assistente), divisão (esc_sr_divisao), parâmetros v4_sr_uma_pagina,
// v4_sr_multipagina, v4_nao_subordinado, v4_todos_os_dias, v4_sr_pref_assistente, v4_sr_so_paginas, v4_sr_tier_medio,
// v4_sr_pref_masculinas, v4_sr_tier_medio_alto, v4_sr_solo, v4_sr_pref_paginas (05/11),
// "não faz assistente" (pode_assistente = 0), inegociáveis (observação do cadastro) e
// indisponibilidades aprovadas longas (sem o motivo).
// ===============================================

$hoje = date('Y-m-d');
$soU = (int) ($_GET['unidade'] ?? 0);
$soPag = trim((string) ($_GET['pagina'] ?? ''));
$nomes = escNomes();
$chave = static fn(string $p): string => mb_strtolower(preg_replace('~\s+~u', ' ', trim($p)), 'UTF-8');
$TURNOS = ['A' => 'A · T2/T3', 'C' => 'C · T4/T5', 'D' => 'D · T6/T1'];

// Páginas da estrutura e a unidade de cada uma (pela carteira)
$paginas = [];
foreach (escQ('SELECT pagina, carteira, modelo FROM `5c_planning`.`fc_pagina_carteira` WHERE vigente_ate IS NULL ORDER BY carteira, pagina') as $l) {
    $paginas[$chave($l['pagina'])] = ['pagina' => $l['pagina'], 'carteira' => $l['carteira'], 'modelo' => $l['modelo'],
        'unidade' => preg_match('~^U(\d)~', (string) $l['carteira'], $m) ? (int) $m[1] : 0, 'aloc' => 'EQUIPE', 'cor' => null, 'plat' => null, 'sla' => 'AUTO'];
}
try {
    foreach (escQ('SELECT pagina, alocacao, sla_modo, sensibilidade, plataforma FROM ' . escT('esc_pagina_config')) as $l) {
        if (isset($paginas[$k = $chave($l['pagina'])])) {
            $paginas[$k] = ['aloc' => $l['alocacao'] ?: 'EQUIPE', 'cor' => $l['sensibilidade'], 'plat' => $l['plataforma'], 'sla' => $l['sla_modo'] ?: 'AUTO'] + $paginas[$k];
        }
    }
} catch (Throwable $e) {
    // colunas ainda não criadas (ES_79, ES_85)
}

// Cadastro de cada pessoa, de hoje em diante (a que vale hoje e as marcadas para depois)
$cad = [];
foreach (escQ('SELECT UPPER(c.rep_code) AS rep, c.unidade, c.turno, c.nivel, c.funcao, c.observacao, c.pode_assistente, c.vigente_desde, c.vigente_ate, n.numero FROM ' . escT('esc_colaborador_config')
    . ' c LEFT JOIN ' . escT('esc_nucleo') . ' n ON n.id = c.nucleo_id WHERE (c.vigente_ate IS NULL OR c.vigente_ate >= ?) ORDER BY c.vigente_desde', [$hoje]) as $l) {
    $cad[$l['rep']][] = $l;
}
$cadEm = static function (string $rep, string $dia) use ($cad): ?array {
    foreach ($cad[$rep] ?? [] as $c) {
        if (substr((string) $c['vigente_desde'], 0, 10) <= $dia && ($c['vigente_ate'] === null || substr((string) $c['vigente_ate'], 0, 10) >= $dia)) {
            return $c;
        }
    }
    return null;
};
$pessoaTxt = static function (string $rep, ?array $c) use ($nomes): string {
    $n = nomeCurto($nomes[$rep] ?? $rep) . ' (' . $rep . ')';
    return $c ? $n . ' · U' . $c['unidade'] . ' ' . $c['turno'] . ($c['numero'] ? ' n' . $c['numero'] : '') . ($c['funcao'] === 'DECURIO' ? ' · OS' : ($c['nivel'] !== null ? ' · SR ' . $c['nivel'] : '')) : $n . ' · ' . t('sem cadastro ativo');
};

// Regras por página e por pessoa
$porPag = [];   // [chave] => ['equipe' => [[rep, de, ate]], 'assist' => [...], 'fora' => [...], 'divisao' => [[rep, parte]], 'multi' => [rep]]
$porRep = [];   // [rep] => [texto, ...]
$add = static function (array &$porRep, string $rep, string $txt): void {
    $porRep[$rep][] = $txt;
};
// Papel na equipe (ES_114): MAIN (titular no turno), COVER (cobre o titular) ou EQUIPE.
$comPapelEq = (bool) escQ1("SELECT 1 AS ok FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'esc_pagina_prioridade' AND COLUMN_NAME = 'papel'", [ESC_SCHEMA]);
$rotPapelEq = ['MAIN' => t('main'), 'COVER' => t('cover'), 'EQUIPE' => ''];
foreach (escQ('SELECT pagina, rep_codes, vigente_desde, vigente_ate' . ($comPapelEq ? ', papel' : '') . ' FROM ' . escT('esc_pagina_prioridade') . ' WHERE vigente_ate IS NULL OR vigente_ate >= ? ORDER BY vigente_desde', [$hoje]) as $l) {
    $papel = (string) ($l['papel'] ?? 'EQUIPE') ?: 'EQUIPE';
    foreach (array_filter(array_map(static fn($r) => strtoupper(trim($r)), explode(',', (string) $l['rep_codes']))) as $rep) {
        $de = substr((string) $l['vigente_desde'], 0, 10);
        $ate = $l['vigente_ate'] !== null ? substr((string) $l['vigente_ate'], 0, 10) : null;
        $porPag[$chave($l['pagina'])]['equipe'][] = [$rep, $de, $ate, $papel];
        $add($porRep, $rep, ($papel === 'EQUIPE' ? t('equipe fixa da {pagina}', ['pagina' => $l['pagina']]) : t('{papel} da {pagina}', ['papel' => $rotPapelEq[$papel], 'pagina' => $l['pagina']]))
            . ($de > $hoje || $ate ? ' (' . trim(($de > $hoje ? t('de {d}', ['d' => dataBr($de)]) : '') . ($ate ? ' ' . t('até {d}', ['d' => dataBr($ate)]) : '')) . ')' : ''));
    }
}
try {
    foreach (escQ('SELECT pagina, UPPER(rep_code) AS rep, papel, vigente_desde, vigente_ate FROM ' . escT('esc_pagina_assistente') . ' WHERE vigente_ate IS NULL OR vigente_ate >= ?', [$hoje]) as $l) {
        $tipo = $l['papel'] === 'FORA' ? 'fora' : 'assist';
        $de = substr((string) $l['vigente_desde'], 0, 10);
        $ate = $l['vigente_ate'] !== null ? substr((string) $l['vigente_ate'], 0, 10) : null;
        $porPag[$chave($l['pagina'])][$tipo][] = $l['rep'];
        $add($porRep, $l['rep'], ($tipo === 'fora' ? t('proibido na {pagina}', ['pagina' => $l['pagina']]) : t('só assistente na {pagina}', ['pagina' => $l['pagina']]))
            . ($de > $hoje || $ate ? ' (' . trim(($de > $hoje ? t('de {d}', ['d' => dataBr($de)]) : '') . ($ate ? ' ' . t('até {d}', ['d' => dataBr($ate)]) : '')) . ')' : ''));
    }
} catch (Throwable $e) {
    // tabela ainda não criada (ES_65)
}
try {
    foreach (escQ('SELECT UPPER(rep_code) AS rep, pagina, parte FROM ' . escT('esc_sr_divisao') . ' WHERE vigente_ate IS NULL OR vigente_ate >= ? ORDER BY rep_code, pagina', [$hoje]) as $l) {
        $porPag[$chave($l['pagina'])]['divisao'][] = [$l['rep'], (float) $l['parte']];
        $add($porRep, $l['rep'], t('{p}% dos turnos na {pagina}', ['p' => round((float) $l['parte'] * 100), 'pagina' => $l['pagina']]));
    }
} catch (Throwable $e) {
    // tabela ainda não criada (ES_98)
}
foreach (explode(';', escV4Param('v4_sr_multipagina', '')) as $item) {
    $x = explode(':', $item, 2);
    if (count($x) === 2 && trim($x[0]) !== '') {
        $rep = strtoupper(trim($x[0]));
        $lista = array_values(array_filter(array_map('trim', explode('|', $x[1])), 'strlen'));
        foreach ($lista as $pg) {
            $porPag[$chave($pg)]['multi'][] = $rep;
        }
        $add($porRep, $rep, t('fixo em várias páginas ao mesmo tempo, sem assistente: {lista}', ['lista' => implode(', ', $lista)]));
    }
}
foreach (array_filter(array_map(static fn($c) => strtoupper(trim($c)), explode(',', escV4Param('v4_sr_uma_pagina', '')))) as $rep) {
    $add($porRep, $rep, t('só trabalha com uma página (não acumula nem cobre)'));
}
foreach (array_filter(array_map(static fn($c) => strtoupper(trim($c)), explode(',', escV4Param('v4_sr_so_assistente', '')))) as $rep) {
    $add($porRep, $rep, t('só entra como assistente, em qualquer página'));
}
foreach (array_filter(array_map(static fn($c) => strtoupper(trim($c)), explode(',', escV4Param('v4_sr_baixo_fluxo', '')))) as $rep) {
    $add($porRep, $rep, t('só página de baixo fluxo (até {n} fãs/h) ou de assistente', ['n' => escV4Param('v4_baixo_fluxo_max', '12')]));
}
foreach (array_filter(array_map(static fn($c) => strtoupper(trim($c)), explode(',', escV4Param('v4_sr_baixo_fluxo_estrito', '')))) as $rep) {
    $add($porRep, $rep, t('nunca em página acima de {n} fãs/h, nem de assistente', ['n' => escV4Param('v4_baixo_fluxo_max', '12')]));
}
foreach (array_filter(array_map(static fn($c) => strtoupper(trim($c)), explode(',', escV4Param('v4_sr_pref_assistente', '')))) as $rep) {
    $add($porRep, $rep, t('serve como assistente (vai antes no posto de assistente)'));
}
foreach (explode(';', escV4Param('v4_sr_so_paginas', '')) as $item) {
    $x = explode(':', $item, 2);
    if (count($x) === 2 && trim($x[0]) !== '') {
        $rep = strtoupper(trim($x[0]));
        $lista = array_values(array_filter(array_map('trim', explode('|', $x[1])), 'strlen'));
        foreach ($lista as $pg) {
            $porPag[$chave($pg)]['so'][] = $rep;
        }
        $add($porRep, $rep, t('só nestas páginas, nenhuma outra: {lista}', ['lista' => implode(', ', $lista)]));
    }
}
foreach (array_filter(array_map(static fn($c) => strtoupper(trim($c)), explode(',', escV4Param('v4_sr_tier_medio', '')))) as $rep) {
    $add($porRep, $rep, t('só páginas de tier médio (tier {t})', ['t' => escV4Param('v4_tier_medio', 'C,D')]));
}
foreach (explode(';', escV4Param('v4_sr_faixa_receita', '')) as $item) {
    $x = explode(':', $item, 2);
    if (count($x) === 2 && trim($x[0]) !== '' && preg_match('~^\s*(\d+)\s*-\s*(\d+)\s*$~', $x[1], $m)) {
        $add($porRep, strtoupper(trim($x[0])), t('só assistente ou página de {de} a {ate} mil em 30 dias', ['de' => round((int) $m[1] / 1000), 'ate' => round((int) $m[2] / 1000)]));
    }
}
foreach (array_filter(array_map(static fn($c) => strtoupper(trim($c)), explode(',', escV4Param('v4_sr_pref_masculinas', '')))) as $rep) {
    $add($porRep, $rep, t('preferência em páginas masculinas ({lista})', ['lista' => implode(', ', array_filter(array_map('trim', explode('|', escV4Param('v4_paginas_masculinas', '')))))]));
}
foreach (array_filter(array_map(static fn($c) => strtoupper(trim($c)), explode(',', escV4Param('v4_sr_tier_medio_alto', '')))) as $rep) {
    $add($porRep, $rep, t('só páginas de tier médio ou alto (tier {t})', ['t' => escV4Param('v4_tier_medio_alto', 'A,B,C,D')]));
}
foreach (array_filter(array_map('trim', explode(',', escV4Param('v4_sr_solo', '')))) as $item) {
    [$rep, $ate] = array_pad(explode(':', $item, 2), 2, '');
    $rep = strtoupper(trim($rep));
    $ate = preg_match('~^\d{4}-\d{2}-\d{2}$~', trim($ate)) ? trim($ate) : '';
    if ($rep !== '' && ($ate === '' || $ate >= $hoje)) {
        $add($porRep, $rep, t('trabalha solo: nunca como assistente nem com assistente') . ($ate !== '' ? ' (' . t('até {d}', ['d' => dataBr($ate)]) . ')' : ''));
    }
}
foreach (explode(';', escV4Param('v4_sr_pref_paginas', '')) as $item) {
    $x = explode(':', $item, 2);
    if (count($x) === 2 && trim($x[0]) !== '') {
        $add($porRep, strtoupper(trim($x[0])), t('preferência nas páginas: {lista}', ['lista' => implode(', ', array_values(array_filter(array_map('trim', explode('|', $x[1])), 'strlen')))]));
    }
}
foreach (array_filter(array_map(static fn($c) => strtoupper(trim($c)), explode(',', escV4Param('v4_todos_os_dias', '')))) as $rep) {
    $add($porRep, $rep, t('trabalha todos os dias do turno, fora da rotação'));
}
foreach (explode(',', escV4Param('v4_nao_subordinado', '')) as $par) {
    $x = array_map(static fn($v) => strtoupper(trim($v)), explode(':', $par, 2));
    if (count($x) === 2 && $x[0] !== '') {
        $add($porRep, $x[0], t('não pode ficar sob {lider}', ['lider' => nomeCurto($nomes[$x[1]] ?? $x[1])]));
    }
}
foreach ($cad as $rep => $cs) {
    $c = $cadEm($rep, $hoje);
    if ($c && (int) ($c['pode_assistente'] ?? 1) === 0) {
        $add($porRep, $rep, t('não faz assistente (faz solo)'));
    }
    if ($c && preg_match('~Inegociável na U(\d)~u', (string) $c['observacao'], $m)) {
        $add($porRep, $rep, t('inegociável na U{u} (não sai da unidade)', ['u' => $m[1]]));
    }
    foreach ($cs as $f) {
        if (substr((string) $f['vigente_desde'], 0, 10) > $hoje) {
            $add($porRep, $rep, t('a partir de {d}: U{u} {t}', ['d' => dataBr(substr((string) $f['vigente_desde'], 0, 10)), 'u' => $f['unidade'], 't' => $f['turno']]) . ($f['numero'] ? ' n' . $f['numero'] : ''));
        }
    }
}
foreach (escQ("SELECT UPPER(i.rep_code) AS rep, MIN(d.dia) AS de, MAX(d.dia) AS ate, COUNT(*) AS n FROM " . escT('esc_indisponibilidade') . ' i JOIN ' . escT('esc_indisponibilidade_data')
    . " d ON d.indisponibilidade_id = i.id AND d.status = 'APROVADO' WHERE d.dia >= ? GROUP BY i.id, i.rep_code HAVING COUNT(*) >= 7", [$hoje]) as $l) {
    $add($porRep, $l['rep'], t('indisponível de {de} a {ate}', ['de' => dataBr(substr((string) $l['de'], 0, 10)), 'ate' => dataBr(substr((string) $l['ate'], 0, 10))]));
}

$rotAloc = ['EQUIPE' => t('Active team'), 'SOB_DEMANDA' => t('On demand'), 'EXCLUSIVA' => t('Exclusiva (solo)')];
$rotCor = ['VERDE' => t('verde'), 'AMARELA' => t('amarela'), 'VERMELHA' => t('vermelha')];
// Equipe da página por turno (na data de hoje ou na de início); quem é de outra unidade é "último caso".
$equipeTurnos = static function (array $pg) use ($porPag, $chave, $cadEm, $pessoaTxt, $hoje, $rotPapelEq): array {
    $out = ['A' => [], 'C' => [], 'D' => [], 'outra' => []];
    $ordem = [];
    foreach ($porPag[$chave($pg['pagina'])]['equipe'] ?? [] as [$rep, $de, $ate, $papel]) {
        $c = $cadEm($rep, max($hoje, $de));
        $txt = ($rotPapelEq[$papel] ?? '' ? mb_strtoupper($rotPapelEq[$papel], 'UTF-8') . ' · ' : '') . $pessoaTxt($rep, $c)
            . ($de > $hoje ? ' · ' . t('de {d}', ['d' => dataBr($de)]) : '') . ($ate ? ' · ' . t('até {d}', ['d' => dataBr($ate)]) : '');
        $onde = $c && (int) $c['unidade'] === $pg['unidade'] && isset($out[$c['turno']]) ? $c['turno'] : 'outra';
        $out[$onde][$rep] = $txt;
        $ordem[$rep] = [$ate !== null && $ate <= $hoje ? 1 : 0, ['MAIN' => 0, 'COVER' => 1][$papel] ?? 2];
    }
    // Primeiro quem vale daqui para a frente; dentro disso, main, cover e o resto da equipe.
    foreach ($out as &$lista) {
        uksort($lista, static fn($a, $b) => $ordem[$a] <=> $ordem[$b]);
    }
    unset($lista);
    return $out;
};
$outrasRegras = static function (array $pg) use ($porPag, $chave, $nomes): array {
    $r = $porPag[$chave($pg['pagina'])] ?? [];
    $l = [];
    foreach ($r['assist'] ?? [] as $rep) {
        $l[] = t('só assistente: {n}', ['n' => nomeCurto($nomes[$rep] ?? $rep)]);
    }
    foreach ($r['fora'] ?? [] as $rep) {
        $l[] = t('proibido: {n}', ['n' => nomeCurto($nomes[$rep] ?? $rep)]);
    }
    foreach ($r['divisao'] ?? [] as [$rep, $parte]) {
        $l[] = t('{n}: {p}% dos turnos', ['n' => nomeCurto($nomes[$rep] ?? $rep), 'p' => round($parte * 100)]);
    }
    foreach (array_unique($r['so'] ?? []) as $rep) {
        $l[] = t('{n}: só esta página (e as outras da lista dele)', ['n' => nomeCurto($nomes[$rep] ?? $rep)]);
    }
    foreach (array_unique($r['multi'] ?? []) as $rep) {
        $l[] = t('{n}: fixo junto com as outras páginas dele, sem assistente', ['n' => nomeCurto($nomes[$rep] ?? $rep)]);
    }
    return $l;
};
$tituloArq = $soPag !== '' ? t('Configuração {pagina} {d}', ['pagina' => $soPag, 'd' => date('d-m-Y')])
    : ($soU ? t('Restrições dos OM U{u} {d}', ['u' => $soU, 'd' => date('d-m-Y')]) : t('Restrições dos OM {d}', ['d' => date('d-m-Y')]));
$regrasGerais = t('Regras que valem para todas: a equipe fixa entra antes de todos na página; quem é de outra unidade só entra em último caso, se sobrou sem posto no turno dele. Página exclusiva (solo): o SR dela não acumula nem cobre outra página. Página On demand não recebe ninguém no Gerar (alocação à mão, com a célula travada). Páginas da Privacy só com SR brasileiro. Uma plataforma por turno: quem faz Privacy, Fanvue ou PlayBoy não faz página de outra plataforma no mesmo turno (nem acumula nem cobre). No dia de treinamento (Overlap), quem é da equipe cobre primeiro a lacuna da própria página. Quem é desligado e página que sai perdem as restrições sozinhos.');
?>
<header class="cabecalho nao-imprimir"><span class="eyebrow"><?php echo h(t('Páginas')); ?></span><h1><?php echo h(t('Restrições dos OM')); ?></h1></header>
<?php
// Histórico (pedido de 04/11): cada versão do documento fica guardada (esc_documento_versao, ES_122),
// para saber que regra valia em cada data. ?versao=N mostra a versão N como ela foi.
$escopoFix = $soPag !== '' ? 'pagina:' . (($paginas[$chave($soPag)] ?? null)['pagina'] ?? 'inexistente') : ($soU >= 1 && $soU <= 3 ? 'U' . $soU : 'todas');
$verVersao = max(0, (int) ($_GET['versao'] ?? 0));
$versoesFix = escDocVersoes('restricoes', $escopoFix);
$linkFix = array_filter(['unidade' => $soU ?: null, 'pagina' => $soPag !== '' ? $soPag : null]);
if ($verVersao > 0):
    $vInfo = null;
    foreach ($versoesFix as $x) {
        if ($x['versao'] === $verVersao) {
            $vInfo = $x;
        }
    }
    $htmlAntigo = escDocConteudo('restricoes', $escopoFix, $verVersao);
    $docFix = ['versao' => $verVersao, 'rotulo' => 'v' . $verVersao, 'desde' => $vInfo['desde'] ?? ''];
?>
<div class="aviso nao-imprimir" role="status">
  <?php if ($htmlAntigo === null): ?>
  <?php echo h(t('A versão v{n} deste documento não está guardada (as versões passaram a ser guardadas em 04/11/2026).', ['n' => $verVersao])); ?>
  <?php else: ?>
  <strong><?php echo h(t('Versão antiga: v{n}', ['n' => $verVersao])); ?></strong> · <?php echo h($vInfo && $vInfo['ate'] !== null
      ? t('valeu de {de} até {ate}', ['de' => escDataHora($vInfo['desde'], true), 'ate' => escDataHora($vInfo['ate'], true)])
      : t('vale desde {de} (é a atual)', ['de' => escDataHora($vInfo['desde'] ?? '', true)])); ?>
  <?php endif; ?>
  · <a href="<?php echo h(link_tela('fixacoes', $linkFix)); ?>"><?php echo h(t('Ver a versão de hoje')); ?></a>
</div>
<?php echo $htmlAntigo ?? ''; ?>
<?php else: ?>
<?php escDocIni(); ?>
<div class="pag-pdf fix-pdf">
  <div class="acoes nao-imprimir">
    <?php foreach ([1, 2, 3] as $u): ?><a class="botao<?php echo $soU === $u && $soPag === '' ? ' primario' : ''; ?>" href="<?php echo h(link_tela('fixacoes', ['unidade' => $u])); ?>"><?php echo h(t('U{u}', ['u' => $u])); ?></a><?php endforeach; ?>
    <a class="botao<?php echo !$soU && $soPag === '' ? ' primario' : ''; ?>" href="<?php echo h(link_tela('fixacoes')); ?>"><?php echo h(t('Todas')); ?></a>
    <button type="button" class="botao primario" id="fix-pdf-baixar"><?php echo h(t('Baixar PDF')); ?></button>
    <span class="muted small"><?php echo h(t('Lido do banco agora. Para uma página só, use o botão PDF na tela Configuração das páginas.')); ?></span>
  </div>

<?php if ($soPag !== ''):
    $pg = $paginas[$chave($soPag)] ?? null; ?>
  <section class="pag-pdf-folha">
  <?php if (!$pg): ?>
    <p class="muted"><?php echo h(t('Página fora da estrutura: {p}.', ['p' => $soPag])); ?></p>
  <?php else: $eq = $equipeTurnos($pg); $outras = $outrasRegras($pg); ?>
    <h1><?php echo h($pg['pagina']); ?> <span class="pag-pdf-unidade">U<?php echo (int) $pg['unidade']; ?></span></h1>
    <p class="pag-pdf-sub"><?php echo h(t('Configuração da página em {d} · carteira {c} · modelo {m}', ['d' => date('d/m/Y H:i'), 'c' => $pg['carteira'], 'm' => $pg['modelo']])); ?> · <?php echo ESC_DOC_TOKEN; ?></p>
    <table class="pag-pdf-tabela fix-tabela">
      <tbody>
        <tr><th><?php echo h(t('Alocação')); ?></th><td><?php echo h($rotAloc[$pg['aloc']] ?? $pg['aloc']); ?></td></tr>
        <tr><th><?php echo h(t('Plataforma')); ?></th><td><?php echo h($pg['plat'] ?? '—'); ?><?php echo $pg['plat'] === 'Privacy' ? ' · ' . h(t('só SR brasileiro · valores em R$')) : ''; ?></td></tr>
        <tr><th><?php echo h(t('Cor (sensibilidade)')); ?></th><td><?php echo h($rotCor[$pg['cor']] ?? '—'); ?></td></tr>
        <tr><th><?php echo h(t('SLA')); ?></th><td><?php echo h(['AUTO' => t('automático'), 'NORMAL' => t('sempre normal'), 'DESCONSIDERAR' => t('desconsiderada')][$pg['sla']] ?? $pg['sla']); ?></td></tr>
        <?php foreach ($TURNOS as $t => $rot): ?>
        <tr><th><?php echo h(t('Equipe fixa · turno {t}', ['t' => $rot])); ?></th><td><?php echo $eq[$t] ? implode('<br>', array_map('h', $eq[$t])) : '<span class="pag-pdf-vazio">—</span>'; ?></td></tr>
        <?php endforeach; ?>
        <?php if ($eq['outra']): ?><tr><th><?php echo h(t('De outra unidade (último caso)')); ?></th><td><?php echo implode('<br>', array_map('h', $eq['outra'])); ?></td></tr><?php endif; ?>
        <tr><th><?php echo h(t('Outras regras')); ?></th><td><?php echo $outras ? implode('<br>', array_map('h', $outras)) : '<span class="pag-pdf-vazio">—</span>'; ?></td></tr>
      </tbody>
    </table>
  <?php endif; ?>
    <p class="pag-pdf-legenda"><?php echo h($regrasGerais); ?></p>
  </section>
<?php else:
    foreach ($soU ? [$soU] : [1, 2, 3] as $u):
        $pagsU = array_filter($paginas, static fn($pg) => $pg['unidade'] === $u && ($pg['aloc'] !== 'EQUIPE' || !empty($porPag[$chave($pg['pagina'])])));
        $repsU = [];
        foreach ($porRep as $rep => $regras) {
            $c = $cadEm($rep, $hoje);
            if ($c && (int) $c['unidade'] === $u) {
                $repsU[$rep] = $c;
            }
        }
        uasort($repsU, static fn($a, $b) => [strpos('ACD', (string) $a['turno']), (int) $a['numero']] <=> [strpos('ACD', (string) $b['turno']), (int) $b['numero']]); ?>
  <section class="pag-pdf-folha">
    <h1><?php echo h(t('Restrições dos OM · Unidade {u}', ['u' => $u])); ?></h1>
    <p class="pag-pdf-sub"><?php echo h(t('Pedidas pelos OM e lançadas pelo Planejamento, valendo em {d} (e as já marcadas para depois) · {p} páginas e {n} pessoas com regra', ['d' => date('d/m/Y H:i'), 'p' => count($pagsU), 'n' => count($repsU)])); ?> · <?php echo ESC_DOC_TOKEN; ?></p>
    <h2 class="fix-h2"><?php echo h(t('Páginas')); ?></h2>
    <table class="pag-pdf-tabela fix-tabela">
      <thead><tr><th class="fix-col-pag"><?php echo h(t('Página')); ?></th><?php foreach ($TURNOS as $rot): ?><th><?php echo h(t('Equipe fixa · {t}', ['t' => $rot])); ?></th><?php endforeach; ?><th><?php echo h(t('Outras regras')); ?></th></tr></thead>
      <tbody>
      <?php foreach ($pagsU as $pg): $eq = $equipeTurnos($pg); $outras = $outrasRegras($pg);
          if ($eq['outra']) {
              $outras[] = t('de outra unidade (último caso): {l}', ['l' => implode('; ', $eq['outra'])]);
          } ?>
        <tr>
          <td><strong><?php echo h($pg['pagina']); ?></strong><br><span class="small"><?php echo h($pg['carteira'] . ' · ' . ($rotAloc[$pg['aloc']] ?? $pg['aloc']) . ($pg['plat'] && $pg['plat'] !== 'OnlyFans' ? ' · ' . $pg['plat'] : '') . ($pg['cor'] ? ' · ' . ($rotCor[$pg['cor']] ?? $pg['cor']) : '')); ?></span></td>
          <?php foreach (array_keys($TURNOS) as $t): ?><td class="small"><?php echo $eq[$t] ? implode('<br>', array_map('h', $eq[$t])) : '<span class="pag-pdf-vazio">—</span>'; ?></td><?php endforeach; ?>
          <td class="small"><?php echo $outras ? implode('<br>', array_map('h', $outras)) : '<span class="pag-pdf-vazio">—</span>'; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$pagsU): ?><tr><td colspan="5" class="muted"><?php echo h(t('Nenhuma página com regra.')); ?></td></tr><?php endif; ?>
      </tbody>
    </table>
    <h2 class="fix-h2"><?php echo h(t('Pessoas')); ?></h2>
    <table class="pag-pdf-tabela fix-tabela">
      <thead><tr><th class="fix-col-pag"><?php echo h(t('Pessoa')); ?></th><th><?php echo h(t('Regras')); ?></th></tr></thead>
      <tbody>
      <?php foreach ($repsU as $rep => $c): ?>
        <tr><td class="small"><?php echo h($pessoaTxt($rep, $c)); ?></td><td class="small"><?php echo implode('<br>', array_map('h', array_values(array_unique($porRep[$rep])))); ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$repsU): ?><tr><td colspan="2" class="muted"><?php echo h(t('Ninguém com regra.')); ?></td></tr><?php endif; ?>
      </tbody>
    </table>
    <p class="pag-pdf-legenda"><?php echo h($regrasGerais); ?></p>
  </section>
<?php endforeach; endif; ?>
</div>
<?php $docFix = escDocFim('restricoes', $escopoFix, true); $versoesFix = escDocVersoes('restricoes', $escopoFix); ?>
<?php endif; ?>
<section class="panel nao-imprimir">
  <h2><?php echo h(t('Versões deste documento')); ?></h2>
  <p class="muted small"><?php echo h(t('Cada mudança nas restrições vira uma versão nova, guardada como estava. Uma versão vale da data dela até a data da seguinte. A versão de hoje é registrada também antes de cada Gerar.')); ?></p>
  <?php if (!$versoesFix): ?>
  <p class="muted"><?php echo h(t('Nenhuma versão registrada ainda.')); ?></p>
  <?php else: ?>
  <table class="tabela">
    <thead><tr><th><?php echo h(t('Versão')); ?></th><th><?php echo h(t('Valeu de')); ?></th><th><?php echo h(t('Até')); ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($versoesFix as $x): ?>
      <tr<?php echo $x['versao'] === (int) ($docFix['versao'] ?? 0) ? ' class="ativo"' : ''; ?>>
        <td><strong>v<?php echo (int) $x['versao']; ?></strong></td>
        <td><?php echo h(escDataHora($x['desde'], true)); ?></td>
        <td><?php echo $x['ate'] !== null ? h(escDataHora($x['ate'], true)) : '<span class="tag verde">' . h(t('vale hoje')) . '</span>'; ?></td>
        <td><?php if (!$x['guardado']): ?><span class="muted small"><?php echo h(t('não guardada')); ?></span><?php elseif ($x['versao'] !== (int) ($docFix['versao'] ?? 0)): ?><a class="botao small" href="<?php echo h(link_tela('fixacoes', $linkFix + ['versao' => $x['versao']])); ?>"><?php echo h(t('Abrir')); ?></a><?php else: ?><span class="muted small"><?php echo h(t('aberta')); ?></span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
<script>
(function () {
  var titulo = document.title, nome = <?php echo json_encode($tituloArq . escDocSufixo($docFix), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE); ?>;
  function imprimir() { document.title = nome; window.print(); }
  var b = document.getElementById('fix-pdf-baixar'); if (b) { b.addEventListener('click', imprimir); }
  window.addEventListener('afterprint', function () { document.title = titulo; });
  <?php if (!empty($_GET['imprimir'])): ?>window.addEventListener('load', function () { setTimeout(imprimir, 300); });<?php endif; ?>
})();
</script>
