<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/requisicao.php
// Requisição de vagas (1.24.0, seção Quadro e contratação): o Simulações 5C dentro do módulo.
//   - Simulação (assets/requisicao.js, nada é gravado): falta de pessoas por dia com as turmas do
//     plano, a partir do Capacity (precisa por turno) e do quadro de hoje; "Sugerir tamanhos"
//     refaz as turmas ainda só planejadas, com o teto por turno.
//   - Acompanhamento (grava em esc_requisicao_vaga, ES_136): planejada → requisição aberta →
//     contratada → em treinamento → operando. Em treinamento e operando vêm das Turmas de treinamento.
//   - Plano de turmas (5c_planning.fc_contratacoes, o plano oficial do Capacity): a tabela Turmas
//     adiciona (também entre as do plano), muda a data, grava as quantidades e apaga turmas. A
//     requisição substitui o plano da turma (CONFIRMADA com os números pedidos).
// Todos os perfis veem; gravar pede o perfil de planejar. No Modo Treino o plano não é gravado.
// ===============================================

$hoje = date('Y-m-d');
if (escPost()) {
    escExigir('planejar');
    if (!escReqInstalado()) {
        throw new RuntimeException(t('A requisição de vagas ainda não foi instalada no banco (script ES_136). Peça ao admin.'));
    }
    $acao = (string) ($_POST['acao'] ?? '');
    $q = [];
    foreach (['A', 'C', 'D'] as $t) {
        $q[$t] = max(0, min(99, (int) ($_POST['q'][$t] ?? 0)));
    }
    // Plano de turmas: grava todas as planejadas de uma vez (depois de "Sugerir tamanhos").
    if ($acao === 'plano_salvar_todas') {
        $lista = json_decode((string) ($_POST['turmas'] ?? ''), true);
        $turmasPost = [];
        foreach (is_array($lista) ? $lista : [] as $x) {
            $d = escDataValida($x['ini'] ?? '');
            if ($d) {
                $turmasPost[$d] = ['A' => (int) ($x['q']['A'] ?? 0), 'C' => (int) ($x['q']['C'] ?? 0), 'D' => (int) ($x['q']['D'] ?? 0)];
            }
        }
        if (!$turmasPost) {
            throw new RuntimeException(t('Nenhuma turma planejada para gravar.'));
        }
        $n = escReqPlanoSalvarTodas($turmasPost);
        escVoltar('requisicao', [], t('{n} turma(s) planejada(s) gravada(s) no plano.', ['n' => $n]), false, 'turmas');
    }
    $ini = escDataValida($_POST['turma'] ?? '');
    if (!$ini) {
        throw new RuntimeException(t('Turma inválida.'));
    }
    if ($acao === 'turma_nova') {
        if (array_sum($q) === 0) {
            throw new RuntimeException(t('Informe quantas pessoas em pelo menos um turno.'));
        }
        $abrir = ($_POST['etapa'] ?? '') === 'ABERTA';
        escReqPlanoNova($ini, $q, $abrir);
        escVoltar('requisicao', [], t('Turma de {dia} incluída: {n} pessoas (A {a} · C {c} · D {d}).', ['dia' => dataBr($ini, true), 'n' => array_sum($q), 'a' => $q['A'], 'c' => $q['C'], 'd' => $q['D']]), false, 'turmas');
    }
    if ($acao === 'turma_salvar') {
        $para = escDataValida($_POST['nova'] ?? '') ?? $ini;
        escReqPlanoSalvar($ini, $para, ($_POST['com_q'] ?? '') === '1' ? $q : null);
        escVoltar('requisicao', [], t('Turma de {dia} salva.', ['dia' => dataBr($para, true)]), false, 'turmas');
    }
    if ($acao === 'turma_apagar') {
        escReqPlanoApagar($ini);
        escVoltar('requisicao', [], t('Turma de {dia} apagada do plano.', ['dia' => dataBr($ini, true)]), false, 'turmas');
    }
    if ($acao === 'abrir') {
        $aberta = escDataValida($_POST['aberta_em'] ?? '') ?? $hoje;
        if (array_sum($q) === 0) {
            throw new RuntimeException(t('Informe quantas pessoas em pelo menos um turno.'));
        }
        $n = escReqAbrir($ini, $q, $aberta, mb_substr(trim((string) ($_POST['referencia'] ?? '')), 0, 60), mb_substr(trim((string) ($_POST['obs'] ?? '')), 0, 255));
        escVoltar('requisicao', [], t('Requisição da turma de {dia} registrada: {n} pessoas (A {a} · C {c} · D {d}).', ['dia' => dataBr($ini, true), 'n' => $n, 'a' => $q['A'], 'c' => $q['C'], 'd' => $q['D']]), false, 'acompanhamento');
    }
    if ($acao === 'contratar') {
        escReqContratar($ini, $q);
        escVoltar('requisicao', [], t('Contratados da turma de {dia} registrados. Quando o e-mail do Treinamento chegar, cole em Turmas de treinamento.', ['dia' => dataBr($ini, true)]), false, 'acompanhamento');
    }
    if ($acao === 'cancelar') {
        escReqCancelar($ini);
        escVoltar('requisicao', [], t('Requisição da turma de {dia} cancelada.', ['dia' => dataBr($ini, true)]), false, 'acompanhamento');
    }
    throw new RuntimeException(t('Não deu para entender o pedido. Recarregue a tela e tente de novo.'));
}

$instalado = escReqInstalado();
$podePlanejar = escPode('planejar') && $instalado;
$gravaPlano = $podePlanejar && !escModoTreino();
$bases = escReqBases();
$prem = escReqPremissas();
$turmas = escReqTurmas($hoje);
$quadroHoje = escReqQuadroHoje();
$en = escEmIngles();
$versao = static fn(string $arq): string => (string) (@filemtime(__DIR__ . '/../assets/' . $arq) ?: time());
$etapas = [
    'PLANEJADA' => [t('Planejada'), ''], 'ABERTA' => [t('Requisição aberta'), 'amarelo'], 'CONTRATADA' => [t('Contratada'), 'azul'],
    'TREINANDO' => [t('Em treinamento'), 'verde'], 'OPERANDO' => [t('Operando'), 'cinza'], 'CANCELADA' => [t('Cancelada'), 'cinza'],
];
// Turmas da simulação: as que ainda vão operar (as que já operam estão no quadro de hoje).
$sim = [];
foreach ($turmas as $tm) {
    if (in_array($tm['etapa'], ['OPERANDO', 'CANCELADA'], true)) {
        continue;
    }
    $sim[] = ['ini' => $tm['ini'], 'opera' => $tm['etapa'] === 'PLANEJADA' ? null : $tm['opera'], 'etapa' => $tm['etapa'], 'fixa' => $tm['fixa'], 'q' => $tm['q'], 'plano' => $tm['plano']];
}
$dados = [
    'hoje' => $hoje, 'en' => $en, 'bases' => $bases, 'quadroHoje' => $quadroHoje, 'prem' => $prem, 'turmas' => $sim,
    // Gravar o plano pela tabela Turmas: formulários montados no JS com o csrf e a URL da ação.
    'grava' => $gravaPlano, 'csrf' => $gravaPlano ? escCsrf() : '', 'acao' => link_tela('requisicao'),
    'etapas' => array_map(static fn($e) => $e[0], $etapas),
    'txt' => [
        'titFecha' => t('Com estas turmas, a falta fica em até {n} pessoas a partir de {dia}'),
        'titNaoFecha' => t('Com estas turmas, a falta não fecha até {dia}: ainda faltam {n} pessoas'),
        'notaGraf' => t('Pessoas que faltam por dia (precisa − quadro que rende; novato na rampa conta {rend}%). A linha grossa é o total; A, C e D em cinza. Abaixo de zero sobra gente. Linhas finas verticais: dia em que cada turma começa a operar. Pior ponto: {pior} em {dia}.'),
        'total' => t('Total'), 'dica' => t('{dia}: faltam {tot} (A {a} · C {c} · D {d})'),
        'kContr' => t('Contratações no plano'), 'kContrDet' => t('{n} turmas que ainda vão operar'),
        'kPior' => t('Pior ponto'), 'kPiorDet' => t('em {dia}'),
        'kFecha' => t('A falta fecha'), 'kFechaDet' => t('fica em até {n} pessoas'), 'kNaoFecha' => t('não fecha'), 'kNaoFechaDet' => t('até {dia}'),
        'kVenc' => t('Requisições a abrir'), 'kVencDet' => t('{n} vencida(s) · próxima até {dia}'), 'kVencNada' => t('nenhuma turma planejada'),
        'acimaTeto' => t('acima do teto de {n} por turno'),
        'vencida' => t('vencida há {n} dia(s)'), 'vence' => t('faltam {n} dia(s)'), 'hoje' => t('vence hoje'),
        'copiado' => t('Requisições copiadas.'), 'selecione' => t('Texto selecionado: use Ctrl+C para copiar.'),
        'sugerido' => t('Turmas planejadas redimensionadas para a base escolhida.'), 'oficial' => t('Turmas do plano oficial de volta.'),
        'cabCopia' => t("Turma começa\tAbrir requisição até\tOpera em\tA\tC\tD\tTotal"),
        'baseRot' => t('Capacity de {dia} (versão {v}) · precisa {n}'), 'baseTit' => t('só titulares · {n}'),
        'semReq' => t('Nenhuma turma planejada com gente: nada a pedir.'),
        'salvar' => t('Salvar'), 'apagar' => t('Apagar'), 'editarReq' => t('Editar no Acompanhamento'),
        'dataTurma' => t('Início da turma de {dia}'), 'emTreino' => t('vem das Turmas de treinamento'),
        'confApagar' => t('Apagar a turma de {dia} do plano? A requisição dela, se houver, é cancelada.'),
        'confTodas' => t('Gravar no plano as {n} turmas planejadas com os números da tabela?'),
        'semPlanejadas' => t('Nenhuma turma planejada para gravar.'),
        'editada' => t('mudou: falta Salvar'),
        'meses' => $en ? ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'] : ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'],
    ],
];
?>
<link rel="stylesheet" href="<?php echo h(MODULE_URL); ?>/assets/analise.css?v=<?php echo h($versao('analise.css')); ?>">
<div class="analise quadro req">
<header class="cabecalho">
  <span class="eyebrow"><?php echo h(t('Quadro e contratação')); ?></span>
  <h1><?php echo h(t('Requisição de vagas')); ?></h1>
  <p class="muted"><?php echo h(t('O ciclo começa com a requisição de vagas ao recrutamento. Aqui fica o plano de turmas, a simulação de quando a falta fecha e o acompanhamento de cada requisição até a turma operar. A simulação não grava nada.')); ?></p>
</header>

<?php if (!$instalado): ?>
<section class="panel"><p class="aviso alerta" role="status"><?php echo h(t('O acompanhamento das requisições ainda não foi instalado no banco (script ES_136). A simulação funciona; registrar requisição, não.')); ?></p></section>
<?php endif; ?>
<?php if (!$bases): ?>
<section class="panel"><p class="muted"><?php echo h(t('Nenhuma versão do Capacity no 5c_planning.')); ?></p></section>
</div>
<?php return; endif; ?>

<section class="kpis" id="req-kpis" aria-live="polite"></section>

<section class="panel">
  <div class="acoes req-base">
    <label class="small"><?php echo h(t('Necessidade')); ?>
      <select id="req-base"><?php foreach ($bases as $i => $b): ?><option value="<?php echo $i; ?>"><?php echo h(t('Capacity de {dia} (versão {v}) · precisa {n}', ['dia' => escData($b['data']), 'v' => $b['versao'], 'n' => array_sum($b['precisa'])])); ?></option><?php endforeach; ?></select></label>
    <label class="small"><?php echo h(t('Contar')); ?>
      <select id="req-alvo"><option value="precisa"><?php echo h(t('titulares + buffer')); ?></option><option value="titulares"><?php echo h(t('só titulares')); ?></option></select></label>
    <label class="small"><?php echo h(t('Quadro de partida')); ?>
      <select id="req-partida">
        <option value="hoje"><?php echo h(t('SRs com cadastro hoje no 5C Escalas ({n})', ['n' => array_sum($quadroHoje)])); ?></option>
        <option value="capacity"><?php echo h(t('"Tem" do Capacity no dia do cálculo')); ?></option>
      </select></label>
    <button type="button" class="botao primario" id="req-sugerir"><?php echo h(t('Sugerir tamanhos')); ?></button>
    <button type="button" class="botao" id="req-oficial"><?php echo h(t('Voltar ao plano oficial')); ?></button>
    <span class="muted small" id="req-msg" role="status"></span>
  </div>
</section>

<div class="graficos">
  <figure class="graf-cartao largo" id="req-graf">
    <figcaption><strong id="req-graf-titulo"></strong><span class="muted small" id="req-graf-nota"></span></figcaption>
    <div class="legenda-graf"><span><i class="cor destaque"></i><?php echo h(t('Total')); ?></span><span><i class="cor cinza-2"></i><?php echo h(t('Turnos A, C e D (rótulo no fim da linha)')); ?></span></div>
    <div class="graf-rolagem" id="req-svg"></div>
    <details class="ver-tabela"><summary class="small"><?php echo h(t('Ver tabela: falta no fim de cada mês')); ?></summary>
      <div class="tabela-rolagem"><table><thead><tr><th><?php echo h(t('Dia')); ?></th><th class="num">A</th><th class="num">C</th><th class="num">D</th><th class="num"><?php echo h(t('Total')); ?></th></tr></thead><tbody id="req-proj"></tbody></table></div>
    </details>
  </figure>
</div>

<section class="panel" id="turmas">
  <div class="linha-titulo"><h2><?php echo h(t('Turmas · pessoas por turno')); ?></h2>
  <?php if ($gravaPlano): ?><button type="button" class="botao" id="req-gravar-todas"><?php echo h(t('Gravar turmas planejadas')); ?></button><?php endif; ?></div>
  <p class="muted small"><?php echo h(t('Mudar os números de uma turma planejada só muda a simulação; Salvar grava a turma no plano (o plano oficial do Capacity). As turmas com requisição ou contratados usam as pessoas reais: os números mudam no Acompanhamento. Turma em treinamento vem das Turmas de treinamento. Requisição até = início da turma menos o prazo de recrutamento.')); ?></p>
  <div class="tabela-rolagem"><table class="req-turmas">
    <thead><tr><th><?php echo h(t('Turma')); ?></th><th><?php echo h(t('Etapa')); ?></th><th><?php echo h(t('Requisição até')); ?></th><th><?php echo h(t('Opera em')); ?></th><th class="num">A</th><th class="num">C</th><th class="num">D</th><th class="num"><?php echo h(t('Total')); ?></th><?php if ($gravaPlano): ?><th><?php echo h(t('Ações')); ?></th><?php endif; ?></tr></thead>
    <tbody id="req-turmas"></tbody>
  </table></div>
  <?php if ($gravaPlano): ?>
  <details class="req-form req-nova"><summary class="botao primario"><?php echo h(t('Adicionar turma')); ?></summary>
    <form method="post" action="<?php echo h(link_tela('requisicao')); ?>" class="form-grade">
      <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="turma_nova">
      <label><?php echo h(t('Início da turma')); ?><input type="date" name="turma" required min="<?php echo h($hoje); ?>"></label>
      <?php foreach (['A', 'C', 'D'] as $tt): ?><label><?php echo $tt; ?><input type="number" name="q[<?php echo $tt; ?>]" min="0" max="99" value="0"></label><?php endforeach; ?>
      <label><?php echo h(t('Etapa')); ?><select name="etapa"><option value="PLANEJADA"><?php echo h(t('Planejada')); ?></option><option value="ABERTA"><?php echo h(t('Requisição aberta')); ?></option></select></label>
      <div class="acoes"><button type="submit" class="botao primario"><?php echo h(t('Incluir no plano')); ?></button></div>
    </form>
    <p class="muted small"><?php echo h(t('Pode ser entre as turmas do plano (turma intermediária). Opera 14 dias depois do início. Com requisição aberta, a turma já entra no Acompanhamento.')); ?></p>
  </details>
  <form method="post" action="<?php echo h(link_tela('requisicao')); ?>" id="req-form-todas" hidden>
    <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="plano_salvar_todas"><input type="hidden" name="turmas" id="req-todas-json">
  </form>
  <?php elseif ($podePlanejar && escModoTreino()): ?>
  <p class="muted small"><?php echo h(t('No Modo Treino o plano de turmas não é gravado (ele fica no Capacity). Use a simulação para testar.')); ?></p>
  <?php endif; ?>
</section>

<section class="panel" id="copiar">
  <div class="linha-titulo"><h2><?php echo h(t('Requisições para o recrutamento')); ?></h2><button type="button" class="botao" id="req-copiar"><?php echo h(t('Copiar requisições')); ?></button></div>
  <div class="tabela-rolagem"><table>
    <thead><tr><th><?php echo h(t('Turma começa')); ?></th><th><?php echo h(t('Abrir requisição até')); ?></th><th><?php echo h(t('Opera em')); ?></th><th class="num">A</th><th class="num">C</th><th class="num">D</th><th class="num"><?php echo h(t('Total')); ?></th></tr></thead>
    <tbody id="req-lista"></tbody>
  </table></div>
  <pre id="req-texto" hidden></pre>
  <p class="muted small"><?php echo h(t('Só as turmas planejadas, com os números da simulação. A cópia vai em colunas separadas por tabulação: cola direto numa planilha ou num e-mail.')); ?></p>
</section>

<section class="panel" id="acompanhamento">
  <h2><?php echo h(t('Acompanhamento das requisições')); ?></h2>
  <p class="muted small"><?php echo h(t('Planejada → requisição aberta → contratada → em treinamento → operando. Em treinamento e operando vêm das Turmas de treinamento (e-mail do Treinamento).')); ?></p>
  <div class="tabela-rolagem"><table>
    <thead><tr><th><?php echo h(t('Turma')); ?></th><th><?php echo h(t('Etapa')); ?></th><th class="num"><?php echo h(t('Plano')); ?></th><th class="num"><?php echo h(t('Pedida')); ?></th><th class="num"><?php echo h(t('Contratada')); ?></th><th class="num"><?php echo h(t('Em treinamento')); ?></th><th><?php echo h(t('Requisição')); ?></th><?php if ($podePlanejar): ?><th><?php echo h(t('Ações')); ?></th><?php endif; ?></tr></thead>
    <tbody>
    <?php $mostradas = 0; foreach ($turmas as $tm):
        if ($tm['etapa'] === 'PLANEJADA' && $tm['ini'] > escMaisDias($hoje, 70)) {
            continue;
        }
        $mostradas++;
        [$rotE, $clsE] = $etapas[$tm['etapa']];
        $fmtQ = static fn(?array $q): string => $q === null ? '—' : (array_sum($q) . ' <span class="muted small">(' . (int) $q['A'] . '/' . (int) $q['C'] . '/' . (int) $q['D'] . ')</span>');
        $trein = $tm['treino'] ? ['A' => (int) ($tm['treino']['A']['ativos'] ?? 0), 'C' => (int) ($tm['treino']['C']['ativos'] ?? 0), 'D' => (int) ($tm['treino']['D']['ativos'] ?? 0)] : null;
        $limite = escMaisDias($tm['ini'], -(int) $prem['recr']);
        $atrasada = $tm['etapa'] === 'PLANEJADA' && array_sum((array) $tm['plano']) > 0 && $limite < $hoje; ?>
      <tr id="req-<?php echo h($tm['ini']); ?>">
        <td><strong><?php echo h(escData($tm['ini'], false, true)); ?></strong><br><span class="muted small"><?php echo h(t('opera em {dia}', ['dia' => escData($tm['opera'])])); ?></span></td>
        <td><span class="tag <?php echo $clsE; ?>"><?php echo h($rotE); ?></span><?php if ($atrasada): ?><br><span class="small laranja"><?php echo h(t('abrir até {dia}: vencida', ['dia' => escData($limite)])); ?></span><?php elseif ($tm['etapa'] === 'PLANEJADA'): ?><br><span class="small muted"><?php echo h(t('abrir até {dia}', ['dia' => escData($limite)])); ?></span><?php endif; ?></td>
        <td class="num"><?php echo $fmtQ($tm['plano']); ?></td>
        <td class="num"><?php echo array_sum($tm['pedida']) ? $fmtQ($tm['pedida']) : '—'; ?></td>
        <td class="num"><?php echo $fmtQ($tm['contratada']); ?></td>
        <td class="num"><?php echo $fmtQ($trein); ?></td>
        <td class="small"><?php echo h(trim(($tm['aberta_em'] ? t('aberta em {dia}', ['dia' => escData($tm['aberta_em'])]) : '') . ($tm['referencia'] !== '' ? ' · ' . $tm['referencia'] : ''), ' ·') ?: '—'); ?></td>
        <?php if ($podePlanejar): ?>
        <td class="small">
          <?php if (in_array($tm['etapa'], ['PLANEJADA', 'ABERTA', 'CANCELADA'], true)): $qIni = array_sum($tm['pedida']) ? $tm['pedida'] : (array) $tm['plano']; ?>
          <details class="req-form"><summary class="botao"><?php echo h($tm['etapa'] === 'ABERTA' ? t('Editar requisição') : t('Registrar requisição')); ?></summary>
            <form method="post" action="<?php echo h(link_tela('requisicao')); ?>" class="form-grade">
              <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="abrir"><input type="hidden" name="turma" value="<?php echo h($tm['ini']); ?>">
              <?php foreach (['A', 'C', 'D'] as $tt): ?><label><?php echo $tt; ?><input type="number" name="q[<?php echo $tt; ?>]" min="0" max="99" value="<?php echo (int) ($qIni[$tt] ?? 0); ?>" data-req-q="<?php echo h($tm['ini'] . '|' . $tt); ?>"></label><?php endforeach; ?>
              <label><?php echo h(t('Aberta em')); ?><input type="date" name="aberta_em" value="<?php echo h($tm['aberta_em'] ?? $hoje); ?>"></label>
              <label><?php echo h(t('Nº no RH (opcional)')); ?><input type="text" name="referencia" maxlength="60" value="<?php echo h($tm['referencia']); ?>"></label>
              <div class="acoes"><button type="submit" class="botao primario"><?php echo h(t('Salvar')); ?></button></div>
            </form>
          </details>
          <?php endif; ?>
          <?php if ($tm['etapa'] === 'ABERTA'): ?>
          <details class="req-form"><summary class="botao"><?php echo h(t('Registrar contratados')); ?></summary>
            <form method="post" action="<?php echo h(link_tela('requisicao')); ?>" class="form-grade">
              <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="contratar"><input type="hidden" name="turma" value="<?php echo h($tm['ini']); ?>">
              <?php foreach (['A', 'C', 'D'] as $tt): ?><label><?php echo $tt; ?><input type="number" name="q[<?php echo $tt; ?>]" min="0" max="99" value="<?php echo (int) $tm['pedida'][$tt]; ?>"></label><?php endforeach; ?>
              <div class="acoes"><button type="submit" class="botao primario"><?php echo h(t('Salvar')); ?></button></div>
            </form>
          </details>
          <?php endif; ?>
          <?php if (in_array($tm['etapa'], ['ABERTA', 'CONTRATADA'], true)): ?>
          <form method="post" action="<?php echo h(link_tela('requisicao')); ?>" class="inline">
            <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="cancelar"><input type="hidden" name="turma" value="<?php echo h($tm['ini']); ?>">
            <button type="submit" class="botao perigo" data-confirmar="<?php echo h(t('Cancelar a requisição da turma de {dia}?', ['dia' => escData($tm['ini'], false, true)])); ?>"><?php echo h(t('Cancelar')); ?></button>
          </form>
          <?php endif; ?>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$mostradas): ?><tr><td colspan="8" class="muted"><?php echo h(t('Nenhuma turma no plano.')); ?></td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <p class="muted small"><?php echo h(t('Aparecem as turmas com requisição, contratados ou treinamento e as planejadas dos próximos 70 dias. Plano = fc_contratacoes (Capacity). Números entre parênteses: A/C/D.')); ?></p>
</section>

<section class="panel">
  <details>
    <summary><strong><?php echo h(t('Premissas da simulação')); ?></strong> <span class="muted small"><?php echo h(t('(do 5c_planning; mude para testar, nada é gravado)')); ?></span></summary>
    <div class="form-grade req-prem">
      <label><?php echo h(t('Saída semanal no turno A (%)')); ?><input type="number" id="p-txA" step="0.01" min="0" value="<?php echo h((string) $prem['txA']); ?>"></label>
      <label><?php echo h(t('Saída semanal no turno C (%)')); ?><input type="number" id="p-txC" step="0.01" min="0" value="<?php echo h((string) $prem['txC']); ?>"></label>
      <label><?php echo h(t('Saída semanal no turno D (%)')); ?><input type="number" id="p-txD" step="0.01" min="0" value="<?php echo h((string) $prem['txD']); ?>"></label>
      <label><?php echo h(t('Novatos que saem em 30 dias (%)')); ?><input type="number" id="p-n30" step="0.5" min="0" value="<?php echo h((string) $prem['n30']); ?>"></label>
      <label><?php echo h(t('Mais até 90 dias (%)')); ?><input type="number" id="p-n90" step="0.5" min="0" value="<?php echo h((string) $prem['n90']); ?>"></label>
      <label><?php echo h(t('Novato rende no começo (% de um SR)')); ?><input type="number" id="p-rend" step="5" min="0" max="100" value="<?php echo (int) $prem['rend']; ?>"></label>
      <label><?php echo h(t('Dias até render 100%')); ?><input type="number" id="p-rampa" step="1" min="0" value="<?php echo (int) $prem['rampa']; ?>"></label>
      <label><?php echo h(t('Treinamento (dias)')); ?><input type="number" id="p-trein" step="1" min="0" value="<?php echo (int) $prem['trein']; ?>"></label>
      <label><?php echo h(t('Recrutamento (dias)')); ?><input type="number" id="p-recr" step="1" min="0" value="<?php echo (int) $prem['recr']; ?>"></label>
      <label><?php echo h(t('Teto por turno em cada turma')); ?><input type="number" id="p-teto" step="1" min="1" value="<?php echo (int) $prem['tetoTurno']; ?>"></label>
      <label><?php echo h(t('Aproveitamento da turma')); ?><input type="number" id="p-aprov" step="0.01" min="0.5" max="1" value="0.94"></label>
      <label><?php echo h(t('Falta que conta como fechada')); ?><input type="number" id="p-fechada" step="1" min="0" value="<?php echo (int) $prem['fechada']; ?>"></label>
    </div>
    <p class="muted small"><?php echo h(t('Saída do quadro: taxa semanal de cada turno (12 semanas até 26/10). Os 12% + 11% do novato já incluem o turnover do quadro (só a parte acima da taxa do turno entra como saída extra), como no banco. Novato na rampa rende a fração informada nos primeiros dias de operação.')); ?></p>
  </details>
</section>
</div>
<script type="application/json" id="req-dados"><?php echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<script src="<?php echo h(MODULE_URL); ?>/assets/requisicao.js?v=<?php echo h($versao('requisicao.js')); ?>" defer></script>
<script src="<?php echo h(MODULE_URL); ?>/assets/analise.js?v=<?php echo h($versao('analise.js')); ?>" defer></script>
