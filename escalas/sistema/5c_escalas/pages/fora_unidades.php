<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/fora_unidades.php
// Fora das unidades (seção Quadro e contratação, pedido de 10/11): SRs do RH que não estão em
// nenhuma unidade no 5C Escalas, com a sugestão do sistema para as lacunas de cada unidade
// (lib/fora_unidades.php). "Cadastrar" grava em Colaboradores (o mesmo POST das Turmas de
// treinamento); esta tela só lê. Todos os perfis veem; cadastrar pede o perfil de cadastro.
// ===============================================

$hoje = date('Y-m-d');
$diaEstrutura = escV4DiaEstrutura();
$fu = escFuPessoas($hoje);
$estado = escFuEstado($hoje);
$sug = escFuSugerir($fu['candidatos'], $estado, $hoje);
$podeCadastrar = escPode('cadastrar');
$modeloPadrao = escFuModeloPadrao();
$desdePadrao = max($hoje, $diaEstrutura);
$nParados = count(array_filter($fu['candidatos'], static fn($c) => escFuParado($c, $hoje)));
// Na lista, quem está ativo vem antes de afastados e parados (a mesma ordem da conta das sugestões).
uasort($fu['candidatos'], static fn($a, $b) => [escFuParado($a, $hoje), $a['turno'] === null, (string) $a['turno'], $a['nome']] <=> [escFuParado($b, $hoje), $b['turno'] === null, (string) $b['turno'], $b['nome']]);
$versao = static fn(string $arq): string => (string) (@filemtime(__DIR__ . '/../assets/' . $arq) ?: time());
$ultV = escQdUltimaVersao();
$totFalta = 0;
$totDepois = 0;
foreach ([1, 2, 3] as $u) {
    foreach (array_keys(ESC_QD_TURNOS) as $t) {
        $totFalta += max(0, $estado[$u][$t]['falta']);
        $totDepois += max(0, $sug['depois'][$u][$t]['falta']);
    }
}
$situacao = static function (array $c) use ($hoje): string {
    $u = $c['cadastro'];
    if ($c['vigente_sem_unidade']) {
        return t('cadastro sem unidade desde {dia}', ['dia' => escData(substr((string) $u['vigente_desde'], 0, 10))]);
    }
    if ($c['ultima_ut']) {
        return t('esteve na U{u} · {t} até {dia}', ['u' => $c['ultima_ut']['unidade'], 't' => $c['ultima_ut']['turno'], 'dia' => escData(substr((string) $u['vigente_ate'], 0, 10))]);
    }
    if ($u) {
        return t('cadastro antigo fechado em {dia}', ['dia' => escData(substr((string) $u['vigente_ate'], 0, 10))]);
    }
    return t('nunca teve cadastro no 5C Escalas');
};
?>
<link rel="stylesheet" href="<?php echo h(MODULE_URL); ?>/assets/analise.css?v=<?php echo h($versao('analise.css')); ?>">
<div class="analise quadro fora">
<header class="cabecalho">
  <span class="eyebrow"><?php echo h(t('Quadro e contratação')); ?></span>
  <h1><?php echo h(t('Fora das unidades')); ?></h1>
  <p class="muted"><?php echo h(t('SRs que estão no RH e não estão em nenhuma unidade no 5C Escalas. O sistema sugere a unidade, o turno e o núcleo pelas lacunas da escala da semana e pela falta no Capacity; confirme ou troque e cadastre. O cadastro em Colaboradores é o que põe a pessoa na escala.')); ?></p>
</header>

<section class="kpis">
  <div class="kpi"><span class="muted"><?php echo h(t('Para destinar')); ?></span><strong class="<?php echo $fu['candidatos'] ? 'laranja' : 'verde'; ?>"><?php echo count($fu['candidatos']); ?></strong><span class="muted small"><?php echo h(tn($nParados, '{n} afastado ou sem escala há mais de 30 dias', '{n} afastados ou sem escala há mais de 30 dias')); ?></span></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Falta nas unidades')); ?></span><strong><?php echo $totFalta; ?></strong><span class="muted small"><?php echo h(t('{n} depois das sugestões', ['n' => $totDepois])); ?></span></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Saída informada, ainda no RH')); ?></span><strong><?php echo count($fu['saidas']); ?></strong><span class="muted small"><?php echo h(t('avisar o RH')); ?></span></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Trainees')); ?></span><strong><?php echo (int) $fu['trainees']; ?></strong><span class="muted small"><a href="<?php echo h(link_tela('treinamento')); ?>"><?php echo h(t('destino em Turmas de treinamento')); ?></a></span></div>
</section>

<section class="panel">
  <h2><?php echo h(t('Falta por unidade e turno')); ?></h2>
  <p class="muted small"><?php echo h(t('Falta = precisa do Capacity (versão {v}, {dia}) − SRs com cadastro hoje − novatos já designados. Lacunas = blocos sem ninguém na escala desta semana. A seta mostra como fica se todas as sugestões abaixo forem cadastradas.', ['v' => $ultV ? (int) $ultV['versao'] : '—', 'dia' => $ultV ? escData((string) $ultV['data_calculo']) : '—'])); ?></p>
  <div class="tabela-rolagem"><table>
    <thead><tr><th><?php echo h(t('Unidade')); ?></th><?php foreach (ESC_QD_TURNOS as $t => $hr): ?><th class="num"><?php echo h($t); ?> <span class="muted small"><?php echo h($hr); ?></span></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ([1, 2, 3] as $u): ?>
      <tr><td><strong>U<?php echo $u; ?></strong></td>
      <?php foreach (array_keys(ESC_QD_TURNOS) as $t): $a = $estado[$u][$t]; $d = $sug['depois'][$u][$t]; ?>
        <td class="num"><span class="<?php echo $a['falta'] > 0 ? 'laranja' : ''; ?>"><?php echo (int) $a['falta']; ?></span><?php if ($d['falta'] !== $a['falta']): ?> → <strong><?php echo (int) $d['falta']; ?></strong><?php endif; ?>
          <br><span class="muted small"><?php echo h(tn($a['lac'], '{n} lacuna', '{n} lacunas')); ?></span></td>
      <?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>

<section class="panel" id="destinar">
  <h2><?php echo h(t('Quem destinar')); ?></h2>
  <?php if (!$fu['candidatos']): ?>
  <p class="muted"><?php echo h(t('Ninguém fora das unidades.')); ?></p>
  <?php else: ?>
  <p class="muted small"><?php echo h(t('Quem tem turno conhecido (último cadastro no 5C Escalas; senão o turno do RH) fica no turno dele. Afastados e quem está sem escala no site há mais de 30 dias entram por último na conta: confirme se a pessoa volta antes de cadastrar.')); ?></p>
  <div class="tabela-rolagem"><table class="fora-tab">
    <thead><tr><th><?php echo h(t('Pessoa')); ?></th><th><?php echo h(t('Situação')); ?></th><th><?php echo h(t('Turno')); ?></th><th><?php echo h(t('Sugestão')); ?></th><?php if ($podeCadastrar): ?><th><?php echo h(t('Cadastrar em Colaboradores')); ?></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($fu['candidatos'] as $rep => $c):
        $sg = $sug['sugestoes'][$rep] ?? null;
        $parado = escFuParado($c, $hoje);
        $modelo = (int) ($c['cadastro']['modelo_id'] ?? 0) ?: $modeloPadrao;
        $nivel = $c['nivel'] !== null ? (int) $c['nivel'] : 1;
        $ultSite = $c['site']['plan'] ?? null; ?>
      <tr id="fu-<?php echo h($rep); ?>" class="<?php echo $parado ? 'muted' : ''; ?>">
        <td><strong><?php echo h($c['nome'] ?: $rep); ?></strong><br><span class="mono muted small"><?php echo h($rep); ?></span>
          <?php if ($c['admissao'] !== ''): ?><br><span class="muted small"><?php echo h(t('admissão: {d}', ['d' => $c['admissao']])); ?></span><?php endif; ?></td>
        <td class="small"><?php echo h($situacao($c)); ?>
          <?php if (!$c['no_rh']): ?><br><span class="tag laranja"><?php echo h(t('fora do RH')); ?></span><?php endif; ?>
          <?php if ($c['afastado']): ?><br><span class="tag amarelo"><?php echo h($c['afastado']['de'] <= $hoje ? t('afastado até {dia}', ['dia' => escData($c['afastado']['ate'])]) : t('indisponível de {de} a {ate}', ['de' => escData($c['afastado']['de']), 'ate' => escData($c['afastado']['ate'])])); ?></span><?php endif; ?>
          <br><span class="muted"><?php echo h($ultSite ? t('última escala no site: {dia}', ['dia' => escData($ultSite)]) : t('sem escala no site')); ?></span></td>
        <td><?php echo $c['turno'] ? h($c['turno']) . '<br><span class="muted small">' . h($c['turno_origem'] === 'RH' ? t('do RH') : t('do 5C Escalas')) . '</span>' : '<span class="muted">—</span>'; ?></td>
        <td class="small"><?php if ($sg): ?><strong>U<?php echo (int) $sg['unidade']; ?> · <?php echo h($sg['turno']); ?></strong><?php $nn = $sg['nucleo_id'] !== null ? ($sug['nucleos'][$sg['unidade']][$sg['turno']][$sg['nucleo_id']] ?? null) : null; if ($nn): ?> · <?php echo h(t('núcleo {n} · {os}', ['n' => $nn['numero'], 'os' => nomeCurto($nn['os']) ?: '—'])); ?><?php endif; ?>
          <br><span class="muted"><?php echo h($sg['motivo']); ?></span><?php else: ?>—<?php endif; ?></td>
        <?php if ($podeCadastrar): ?>
        <td>
          <?php if ($modelo): ?>
          <form method="post" action="<?php echo h(link_tela('colaboradores')); ?>" class="form-grade fora-form">
            <?php echo campoCsrf(); ?><input type="hidden" name="rep_code" value="<?php echo h($rep); ?>"><input type="hidden" name="modelo_id" value="<?php echo $modelo; ?>">
            <input type="hidden" name="pode_assistente" value="<?php echo (int) ($c['cadastro']['pode_assistente'] ?? 1); ?>">
            <input type="hidden" name="observacao" value="<?php echo h(mb_substr(t('Destinado em Fora das unidades: {m}', ['m' => $sg['motivo'] ?? '']), 0, 255)); ?>">
            <label><?php echo h(t('Núcleo')); ?><select name="nucleo_id" required aria-label="<?php echo h(t('Núcleo de {rep}', ['rep' => $rep])); ?>">
              <?php foreach ($sug['nucleos'] as $u => $porT): foreach ($porT as $t => $ns): ?><optgroup label="U<?php echo $u; ?> · <?php echo h($t); ?>">
                <?php foreach ($ns as $nid => $n): ?><option value="<?php echo $nid; ?>" <?php echo $sg && (int) $sg['nucleo_id'] === $nid ? 'selected' : ''; ?>><?php echo h(t('U{u} · {t} · núcleo {n} · {os} ({srs} SRs)', ['u' => $u, 't' => $t, 'n' => $n['numero'], 'os' => nomeCurto($n['os']) ?: '—', 'srs' => $n['srs']])); ?></option><?php endforeach; ?>
              </optgroup><?php endforeach; endforeach; ?>
            </select></label>
            <label><?php echo h(t('Nível')); ?><select name="nivel"><?php foreach (ESC_ROSTER_NIVEIS_SR as $nv): ?><option value="<?php echo $nv; ?>" <?php echo $nv === $nivel ? 'selected' : ''; ?>>SR <?php echo $nv; ?></option><?php endforeach; ?></select></label>
            <label><?php echo h(t('A partir de')); ?><input type="date" name="vigente_desde" min="<?php echo h($diaEstrutura); ?>" value="<?php echo h($desdePadrao); ?>" required></label>
            <div class="acoes"><button type="submit" class="botao primario" data-confirmar="<?php echo h(t('Cadastrar {rep} no núcleo escolhido?', ['rep' => $rep])); ?>"><?php echo h(t('Cadastrar')); ?></button></div>
          </form>
          <?php else: ?><span class="muted small"><?php echo h(t('Nenhum modelo de escala ativo: cadastre em Colaboradores.')); ?></span><?php endif; ?>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>

<?php if ($fu['saidas']): ?>
<section class="panel">
  <details>
    <summary><strong><?php echo h(t('Saída informada no 5C Escalas, ainda no RH ({n})', ['n' => count($fu['saidas'])])); ?></strong></summary>
    <p class="muted small"><?php echo h(t('O cadastro foi fechado por desligamento ou movimentação, mas a pessoa ainda aparece como SR no headcount do RH. Não entram na falta nem nas sugestões; avise o RH.')); ?></p>
    <div class="tabela-rolagem"><table>
      <thead><tr><th><?php echo h(t('Pessoa')); ?></th><th><?php echo h(t('Cadastro fechado em')); ?></th><th><?php echo h(t('Motivo no cadastro')); ?></th></tr></thead>
      <tbody>
      <?php foreach ($fu['saidas'] as $rep => $c): ?>
        <tr><td><strong><?php echo h($c['nome'] ?: $rep); ?></strong> <span class="mono muted small"><?php echo h($rep); ?></span></td>
          <td><?php echo h(escData(substr((string) $c['cadastro']['vigente_ate'], 0, 10))); ?></td>
          <td class="small muted"><?php echo h(mb_substr((string) $c['cadastro']['observacao'], 0, 120)); ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </details>
</section>
<?php endif; ?>
</div>
