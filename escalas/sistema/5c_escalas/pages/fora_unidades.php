<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/fora_unidades.php
// Fora das unidades (seção Quadro e contratação, pedido de 10/11): SRs do RH que não estão em
// nenhuma unidade no 5C Escalas, com a sugestão do sistema para as lacunas de cada unidade
// (lib/fora_unidades.php). "Cadastrar" grava em Colaboradores (o mesmo POST das Turmas de
// treinamento); esta tela só lê. Todos os perfis veem; cadastrar pede o perfil de cadastro.
// Desde a 1.27.0: lista compacta com filtro (ativos, afastados ou parados) e busca, e a lista
// "Para o RH desativar no site" com o download .xlsx (?baixar=rh, só WFM/Planejamento e Admin).
// ===============================================

$hoje = date('Y-m-d');
$fu = escFuPessoas($hoje);
$paraRh = escFuListaRh($fu, $hoje);
$dataBr4 = static fn(?string $iso): string => $iso ? date('d/m/Y', strtotime($iso)) : '—';

if (($_GET['baixar'] ?? '') === 'rh') {
    escExigirRestrito();
    require_once __DIR__ . '/../lib/xlsx.php';
    $exportar = array_map(static fn($l) => [$l['rep'], $l['nome_completo'], $l['admissao'] ?: '—', $dataBr4($l['ultima_site']), $l['observacao']], $paraRh);
    $doc = escDocVersao('fora_unidades_rh', 'rh', (string) json_encode($exportar, JSON_UNESCAPED_UNICODE));
    $nSaidas = count(array_filter($paraRh, static fn($l) => $l['grupo'] === 'SAIDA'));
    $col = static fn(string $titulo, float $largura) => ['titulo' => $titulo, 'largura' => $largura, 'estilo' => 'texto'];
    escXlsxBaixar('SRs para desativar no site ' . date('d-m-Y') . escDocSufixo($doc) . '.xlsx', t('Para o RH'),
        [t('SRs para desativar no site'), t('Gerado em {quando}', ['quando' => date('d/m/Y H:i')]) . ($doc['rotulo'] !== '' ? ' · ' . t('versão {v}', ['v' => $doc['rotulo']]) : '')
            . ' · ' . t('{n} pessoas: {s} com saída informada no 5C Escalas e {p} afastadas ou sem escala no site há mais de 30 dias', ['n' => count($paraRh), 's' => $nSaidas, 'p' => count($paraRh) - $nSaidas])],
        [$col(t('Código do SR'), 12), $col(t('Nome completo'), 38), $col(t('Data de admissão'), 14), $col(t('Data da última escala no site'), 16), $col(t('Observação'), 90)],
        $exportar, 2);
    return;
}
if (isset($_GET['baixar'])) {
    http_response_code(400);
    echo t('Download desconhecido.');
    return;
}

$diaEstrutura = escV4DiaEstrutura();
$estado = escFuEstado($hoje);
$sug = escFuSugerir($fu['candidatos'], $estado, $hoje);
$podeCadastrar = escPode('cadastrar');
$podeBaixar = escPodeVerRestrito();
$modeloPadrao = escFuModeloPadrao();
$desdePadrao = max($hoje, $diaEstrutura);
$nParados = count(array_filter($fu['candidatos'], static fn($c) => escFuParado($c, $hoje)));
$nAtivos = count($fu['candidatos']) - $nParados;
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
$nSaidasRh = count(array_filter($paraRh, static fn($l) => $l['grupo'] === 'SAIDA'));
?>
<link rel="stylesheet" href="<?php echo h(MODULE_URL); ?>/assets/analise.css?v=<?php echo h($versao('analise.css')); ?>">
<div class="analise quadro fora">
<header class="cabecalho">
  <span class="eyebrow"><?php echo h(t('Quadro e contratação')); ?></span>
  <h1><?php echo h(t('Fora das unidades')); ?></h1>
  <p class="muted"><?php echo h(t('SRs que estão no RH e não estão em nenhuma unidade no 5C Escalas. O sistema sugere a unidade, o turno e o núcleo pelas lacunas da escala da semana e pela falta no Capacity; confirme ou troque e cadastre. O cadastro em Colaboradores é o que põe a pessoa na escala.')); ?></p>
</header>

<section class="kpis">
  <div class="kpi"><span class="muted"><?php echo h(t('Para destinar')); ?></span><strong class="<?php echo $nAtivos ? 'laranja' : 'verde'; ?>"><?php echo $nAtivos; ?></strong><span class="muted small"><a href="#destinar"><?php echo h(t('ativos, com escala no site')); ?></a></span></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Para o RH desativar no site')); ?></span><strong class="<?php echo $paraRh ? 'laranja' : 'verde'; ?>"><?php echo count($paraRh); ?></strong><span class="muted small"><a href="#rh"><?php echo h(t('{s} saídas · {p} afastados ou parados', ['s' => $nSaidasRh, 'p' => count($paraRh) - $nSaidasRh])); ?></a></span></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Falta nas unidades')); ?></span><strong><?php echo $totFalta; ?></strong><span class="muted small"><a href="#falta"><?php echo h(t('{n} depois das sugestões', ['n' => $totDepois])); ?></a></span></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Trainees')); ?></span><strong><?php echo (int) $fu['trainees']; ?></strong><span class="muted small"><a href="<?php echo h(link_tela('treinamento', ['aba' => 'destino'])); ?>"><?php echo h(t('destino em Turmas de treinamento')); ?></a></span></div>
</section>

<section class="panel" id="destinar">
  <div class="linha-titulo">
    <h2><?php echo h(t('Quem destinar')); ?></h2>
    <?php if ($fu['candidatos']): ?>
    <div class="fora-filtros" role="group" aria-label="<?php echo h(t('Filtrar a lista')); ?>">
      <?php foreach (['ativos' => [t('Ativos'), $nAtivos], 'parados' => [t('Afastados ou parados'), $nParados], 'todos' => [t('Todos'), count($fu['candidatos'])]] as $k => [$rot, $n]): ?>
        <button type="button" class="chip-filtro <?php echo $k === 'ativos' ? 'ativo' : ''; ?>" data-filtro-fu="<?php echo $k; ?>" aria-pressed="<?php echo $k === 'ativos' ? 'true' : 'false'; ?>"><?php echo h($rot); ?> · <?php echo $n; ?></button>
      <?php endforeach; ?>
      <input type="search" data-busca-fu placeholder="<?php echo h(t('buscar nome ou código')); ?>" aria-label="<?php echo h(t('Buscar nome ou código')); ?>" autocomplete="off">
    </div>
    <?php endif; ?>
  </div>
  <?php if (!$fu['candidatos']): ?>
  <p class="muted"><?php echo h(t('Ninguém fora das unidades.')); ?></p>
  <?php else: ?>
  <p class="muted small"><?php echo h(t('Quem tem turno conhecido (último cadastro no 5C Escalas; senão o turno do RH) fica no turno dele. Afastados e quem está sem escala no site há mais de 30 dias entram por último na conta: confirme se a pessoa volta antes de cadastrar.')); ?></p>
  <div class="tabela-rolagem"><table class="fora-tab">
    <thead><tr><th><?php echo h(t('Pessoa')); ?></th><th><?php echo h(t('Situação')); ?></th><th><?php echo h(t('Sugestão')); ?></th><?php if ($podeCadastrar): ?><th><?php echo h(t('Cadastrar em Colaboradores')); ?></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($fu['candidatos'] as $rep => $c):
        $sg = $sug['sugestoes'][$rep] ?? null;
        $parado = escFuParado($c, $hoje);
        $modelo = (int) ($c['cadastro']['modelo_id'] ?? 0) ?: $modeloPadrao;
        $nivel = $c['nivel'] !== null ? (int) $c['nivel'] : 1;
        $ultSite = $c['site']['plan'] ?? null;
        $nn = $sg && $sg['nucleo_id'] !== null ? ($sug['nucleos'][$sg['unidade']][$sg['turno']][$sg['nucleo_id']] ?? null) : null; ?>
      <tr id="fu-<?php echo h($rep); ?>" class="<?php echo $parado ? 'muted' : ''; ?>" data-grupo="<?php echo $parado ? 'parados' : 'ativos'; ?>" data-busca="<?php echo h(mb_strtolower($c['nome'] . ' ' . $c['nome_completo'] . ' ' . $rep)); ?>">
        <td><strong><?php echo h($c['nome'] ?: $rep); ?></strong><br><span class="mono muted small"><?php echo h($rep); ?></span>
          <?php if ($c['turno']): ?> <?php echo chipTurno((string) $c['turno']); ?> <span class="muted small"><?php echo h($c['turno_origem'] === 'RH' ? t('do RH') : t('do 5C Escalas')); ?></span><?php endif; ?>
          <?php if ($c['admissao'] !== ''): ?><br><span class="muted small"><?php echo h(t('admissão: {d}', ['d' => $c['admissao']])); ?></span><?php endif; ?></td>
        <td class="small"><?php echo h($situacao($c)); ?>
          <br><span class="muted"><?php echo h($ultSite ? t('última escala no site: {dia}', ['dia' => escData($ultSite)]) : t('sem escala no site')); ?></span>
          <?php if (!$c['no_rh']): ?><br><span class="tag laranja"><?php echo h(t('fora do RH')); ?></span><?php endif; ?>
          <?php if ($c['afastado']): ?><br><span class="tag amarelo"><?php echo h($c['afastado']['de'] <= $hoje ? t('afastado até {dia}', ['dia' => escData($c['afastado']['ate'])]) : t('indisponível de {de} a {ate}', ['de' => escData($c['afastado']['de']), 'ate' => escData($c['afastado']['ate'])])); ?></span><?php endif; ?></td>
        <td class="small fora-sugestao"><?php if ($sg): ?><strong>U<?php echo (int) $sg['unidade']; ?> · <?php echo h($sg['turno']); ?><?php if ($nn): ?> · <?php echo h(t('núcleo {n} · {os}', ['n' => $nn['numero'], 'os' => nomeCurto($nn['os']) ?: '—'])); ?><?php endif; ?></strong>
          <details><summary class="muted"><?php echo h(t('por quê')); ?></summary><span class="muted"><?php echo h($sg['motivo']); ?></span></details><?php else: ?>—<?php endif; ?></td>
        <?php if ($podeCadastrar): ?>
        <td>
          <?php if ($modelo): ?>
          <form method="post" action="<?php echo h(link_tela('colaboradores')); ?>" class="fora-form">
            <?php echo campoCsrf(); ?><input type="hidden" name="rep_code" value="<?php echo h($rep); ?>"><input type="hidden" name="modelo_id" value="<?php echo $modelo; ?>">
            <input type="hidden" name="pode_assistente" value="<?php echo (int) ($c['cadastro']['pode_assistente'] ?? 1); ?>">
            <input type="hidden" name="observacao" value="<?php echo h(mb_substr(t('Destinado em Fora das unidades: {m}', ['m' => $sg['motivo'] ?? '']), 0, 255)); ?>">
            <select name="nucleo_id" required data-sem-busca aria-label="<?php echo h(t('Núcleo de {rep}', ['rep' => $rep])); ?>" class="fora-nucleo">
              <?php foreach ($sug['nucleos'] as $u => $porT): foreach ($porT as $t => $ns): ?><optgroup label="U<?php echo $u; ?> · <?php echo h($t); ?>">
                <?php foreach ($ns as $nid => $n): ?><option value="<?php echo $nid; ?>" <?php echo $sg && (int) $sg['nucleo_id'] === $nid ? 'selected' : ''; ?>><?php echo h(t('U{u} · {t} · núcleo {n} · {os} ({srs} SRs)', ['u' => $u, 't' => $t, 'n' => $n['numero'], 'os' => nomeCurto($n['os']) ?: '—', 'srs' => $n['srs']])); ?></option><?php endforeach; ?>
              </optgroup><?php endforeach; endforeach; ?>
            </select>
            <select name="nivel" aria-label="<?php echo h(t('Nível de {rep}', ['rep' => $rep])); ?>"><?php foreach (ESC_ROSTER_NIVEIS_SR as $nv): ?><option value="<?php echo $nv; ?>" <?php echo $nv === $nivel ? 'selected' : ''; ?>>SR <?php echo $nv; ?></option><?php endforeach; ?></select>
            <input type="date" name="vigente_desde" min="<?php echo h($diaEstrutura); ?>" value="<?php echo h($desdePadrao); ?>" required aria-label="<?php echo h(t('A partir de')); ?>" title="<?php echo h(t('A partir de')); ?>">
            <button type="submit" class="botao primario" data-confirmar="<?php echo h(t('Cadastrar {rep} no núcleo escolhido?', ['rep' => $rep])); ?>"><?php echo h(t('Cadastrar')); ?></button>
          </form>
          <?php else: ?><span class="muted small"><?php echo h(t('Nenhum modelo de escala ativo: cadastre em Colaboradores.')); ?></span><?php endif; ?>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <tr class="fora-vazio" hidden><td colspan="<?php echo $podeCadastrar ? 4 : 3; ?>" class="muted"><?php echo h(t('Ninguém neste filtro. Troque o filtro ou limpe a busca.')); ?></td></tr>
    </tbody>
  </table></div>
  <?php if ($podeCadastrar): ?><p class="muted small"><?php echo h(t('Cadastrar grava em Colaboradores e abre aquela tela (ou o Gerar, se a semana já tem escala). Para o próximo, volte aqui.')); ?></p><?php endif; ?>
  <?php endif; ?>
</section>

<section class="panel" id="rh">
  <div class="linha-titulo">
    <h2><?php echo h(t('Para o RH desativar no site ({n})', ['n' => count($paraRh)])); ?></h2>
    <?php if ($paraRh && $podeBaixar): ?><a class="botao primario" href="<?php echo h(link_tela('fora_unidades', ['baixar' => 'rh'])); ?>"><?php echo h(t('Baixar para o RH (.xlsx)')); ?></a><?php endif; ?>
  </div>
  <p class="muted small"><?php echo h(t('Quem o RH ainda lista como SR, está fora das unidades e não está trabalhando: saída informada no 5C Escalas (cadastro fechado por desligamento ou movimentação) ou afastado / sem escala no site há mais de 30 dias. A observação diz se a pessoa está de licença ou com indisponibilidade programada: confira antes de pedir a desativação.')); ?><?php if (!$podeBaixar): ?> <?php echo h(t('O download é só para WFM, Planejamento e Admin.')); ?><?php endif; ?></p>
  <?php if (!$paraRh): ?>
  <p class="muted"><?php echo h(t('Ninguém para avisar o RH.')); ?></p>
  <?php else: ?>
  <div class="tabela-rolagem"><table>
    <thead><tr><th><?php echo h(t('Código do SR')); ?></th><th><?php echo h(t('Nome completo')); ?></th><th><?php echo h(t('Data de admissão')); ?></th><th><?php echo h(t('Data da última escala no site')); ?></th><th><?php echo h(t('Observação')); ?></th></tr></thead>
    <tbody>
    <?php foreach ($paraRh as $l): ?>
      <tr><td class="mono"><?php echo h($l['rep']); ?></td>
        <td><strong><?php echo h($l['nome_completo'] ?: '—'); ?></strong><?php if ($l['grupo'] === 'SAIDA'): ?> <span class="tag laranja"><?php echo h(t('saída informada')); ?></span><?php endif; ?></td>
        <td class="small"><?php echo h($l['admissao'] ?: '—'); ?></td>
        <td class="small"><?php echo h($dataBr4($l['ultima_site'])); ?></td>
        <td class="small muted"><?php echo h($l['observacao']); ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>

<section class="panel" id="falta">
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
</div>
<script>
(function () {
  var tab = document.querySelector('.fora-tab');
  if (!tab) { return; }
  var linhas = Array.prototype.slice.call(tab.querySelectorAll('tr[data-grupo]'));
  var vazio = tab.querySelector('.fora-vazio');
  var busca = document.querySelector('[data-busca-fu]');
  var filtro = 'ativos';
  var aplicar = function () {
    var q = (busca.value || '').trim().toLowerCase();
    var vistas = 0;
    linhas.forEach(function (tr) {
      var ver = (filtro === 'todos' || tr.getAttribute('data-grupo') === filtro) && (!q || tr.getAttribute('data-busca').indexOf(q) >= 0);
      tr.hidden = !ver;
      if (ver) { vistas++; }
    });
    vazio.hidden = vistas > 0;
  };
  document.querySelectorAll('[data-filtro-fu]').forEach(function (b) {
    b.addEventListener('click', function () {
      filtro = b.getAttribute('data-filtro-fu');
      document.querySelectorAll('[data-filtro-fu]').forEach(function (o) {
        o.classList.toggle('ativo', o === b);
        o.setAttribute('aria-pressed', o === b ? 'true' : 'false');
      });
      aplicar();
    });
  });
  busca.addEventListener('input', aplicar);
  // Link para uma pessoa (#fu-CODIGO) mostra a linha mesmo fora do filtro.
  var alvo = location.hash && document.querySelector(location.hash);
  // Sem ninguém ativo, abre em Todos.
  if ((alvo && alvo.getAttribute('data-grupo') === 'parados') || !tab.querySelector('tr[data-grupo="ativos"]')) { document.querySelector('[data-filtro-fu="todos"]').click(); return; }
  aplicar();
})();
</script>
