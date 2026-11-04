<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/fixacoes.php
// Fixações e restrições feitas à mão (pedido de 04/11), lidas do banco na hora, para mandar às equipes:
//   ?unidade=N            → as da unidade N (páginas com regra e pessoas com regra);
//   ?pagina=<nome>        → a configuração completa de uma página;
//   &imprimir=1           → abre a impressão (Salvar como PDF), no papel timbrado da 5C.
// Fontes: alocação, cor e plataforma (esc_pagina_config), equipe fixa (esc_pagina_prioridade), só assistente
// e proibido (esc_pagina_assistente), divisão (esc_sr_divisao), parâmetros v4_sr_uma_pagina,
// v4_sr_multipagina, v4_nao_subordinado e v4_todos_os_dias, inegociáveis (observação do cadastro) e
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
foreach (escQ('SELECT UPPER(c.rep_code) AS rep, c.unidade, c.turno, c.nivel, c.funcao, c.observacao, c.vigente_desde, c.vigente_ate, n.numero FROM ' . escT('esc_colaborador_config')
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
foreach (escQ('SELECT pagina, rep_codes, vigente_desde, vigente_ate FROM ' . escT('esc_pagina_prioridade') . ' WHERE vigente_ate IS NULL OR vigente_ate >= ? ORDER BY vigente_desde', [$hoje]) as $l) {
    foreach (array_filter(array_map(static fn($r) => strtoupper(trim($r)), explode(',', (string) $l['rep_codes']))) as $rep) {
        $de = substr((string) $l['vigente_desde'], 0, 10);
        $ate = $l['vigente_ate'] !== null ? substr((string) $l['vigente_ate'], 0, 10) : null;
        $porPag[$chave($l['pagina'])]['equipe'][] = [$rep, $de, $ate];
        $add($porRep, $rep, t('equipe fixa da {pagina}', ['pagina' => $l['pagina']]) . ($de > $hoje || $ate ? ' (' . ($de > $hoje ? t('de {d}', ['d' => dataBr($de)]) : '') . ($ate ? ' ' . t('até {d}', ['d' => dataBr($ate)]) : '') . ')' : ''));
    }
}
try {
    foreach (escQ('SELECT pagina, UPPER(rep_code) AS rep, papel FROM ' . escT('esc_pagina_assistente') . ' WHERE vigente_ate IS NULL OR vigente_ate >= ?', [$hoje]) as $l) {
        $tipo = $l['papel'] === 'FORA' ? 'fora' : 'assist';
        $porPag[$chave($l['pagina'])][$tipo][] = $l['rep'];
        $add($porRep, $l['rep'], $tipo === 'fora' ? t('proibido na {pagina}', ['pagina' => $l['pagina']]) : t('só assistente na {pagina}', ['pagina' => $l['pagina']]));
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
$equipeTurnos = static function (array $pg) use ($porPag, $chave, $cadEm, $pessoaTxt, $hoje): array {
    $out = ['A' => [], 'C' => [], 'D' => [], 'outra' => []];
    foreach ($porPag[$chave($pg['pagina'])]['equipe'] ?? [] as [$rep, $de, $ate]) {
        $c = $cadEm($rep, max($hoje, $de));
        $txt = $pessoaTxt($rep, $c) . ($de > $hoje ? ' · ' . t('de {d}', ['d' => dataBr($de)]) : '') . ($ate ? ' · ' . t('até {d}', ['d' => dataBr($ate)]) : '');
        if ($c && (int) $c['unidade'] === $pg['unidade'] && isset($out[$c['turno']])) {
            $out[$c['turno']][$rep] = $txt;
        } else {
            $out['outra'][$rep] = $txt;
        }
    }
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
    foreach (array_unique($r['multi'] ?? []) as $rep) {
        $l[] = t('{n}: fixo junto com as outras páginas dele, sem assistente', ['n' => nomeCurto($nomes[$rep] ?? $rep)]);
    }
    return $l;
};
$tituloArq = $soPag !== '' ? t('Configuração {pagina} {d}', ['pagina' => $soPag, 'd' => date('d-m-Y')])
    : ($soU ? t('Fixações U{u} {d}', ['u' => $soU, 'd' => date('d-m-Y')]) : t('Fixações {d}', ['d' => date('d-m-Y')]));
$regrasGerais = t('Regras que valem para todas: a equipe fixa entra antes de todos na página; quem é de outra unidade só entra em último caso, se sobrou sem posto no turno dele. Página exclusiva (solo): o SR dela não acumula nem cobre outra página. Página On demand não recebe ninguém no Gerar (alocação à mão, com a célula travada). Páginas da Privacy só com SR brasileiro. No dia de treinamento (Overlap), quem é da equipe cobre primeiro a lacuna da própria página.');
?>
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
    <p class="pag-pdf-sub"><?php echo h(t('Configuração da página em {d} · carteira {c} · modelo {m}', ['d' => date('d/m/Y H:i'), 'c' => $pg['carteira'], 'm' => $pg['modelo']])); ?></p>
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
    <h1><?php echo h(t('Fixações e restrições · Unidade {u}', ['u' => $u])); ?></h1>
    <p class="pag-pdf-sub"><?php echo h(t('Feitas à mão pelo Planejamento, valendo em {d} (e as já marcadas para depois) · {p} páginas e {n} pessoas com regra', ['d' => date('d/m/Y H:i'), 'p' => count($pagsU), 'n' => count($repsU)])); ?></p>
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
<script>
(function () {
  var titulo = document.title, nome = <?php echo json_encode($tituloArq); ?>;
  function imprimir() { document.title = nome; window.print(); }
  document.getElementById('fix-pdf-baixar').addEventListener('click', imprimir);
  window.addEventListener('afterprint', function () { document.title = titulo; });
  <?php if (!empty($_GET['imprimir'])): ?>window.addEventListener('load', function () { setTimeout(imprimir, 300); });<?php endif; ?>
})();
</script>
