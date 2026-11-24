<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/capacity.php
// Capacity (1.24.0, seção Quadro e contratação). Só leitura, todos os perfis.
//   - quanto falta hoje (precisa − tem da última versão) e quando o plano de turmas fecha a falta;
//   - evolução das versões (uma por dia): precisa × tem;
//   - onde falta: unidade × turno;
//   - ocupação da semana: SRs escalados nas páginas de cada carteira ÷ postos do Capacity, e as lacunas;
//   - as versões e o que mudou em cada uma.
// O Capacity não entra no corte de 01/11 (escCorteDados): todas as versões contam.
// Faturamento por carteira só para WFM/Planejamento e Admin (escPodeVerRestrito).
// Recalcular: desde a 1.38.0 é automático (sem botão); ver escCapacityAgendar em lib/estrutura.php.
// ===============================================

// Capacity automático (1.38.0, item D): sem botão de recálculo. Toda mudança em pessoas, páginas ou postos
// recalcula sozinha no fim da requisição (lib/estrutura.php, escCapacityAgendar), além do Gerar e do diário.
$ultAuto = escQ1('SELECT quando, depois FROM ' . escT('esc_log') . " WHERE acao = 'capacity.auto' ORDER BY id DESC LIMIT 1");

$unSel =(int) (is_string($_GET['unidade'] ?? null) ? $_GET['unidade'] : 0);
$unSel = in_array($unSel, [1, 2, 3], true) ? $unSel : 0;
$tSel = in_array($_GET['turno'] ?? '', ['A', 'C', 'D'], true) ? $_GET['turno'] : '';
$periodos = [14 => t('14 dias'), 30 => t('30 dias'), 60 => t('60 dias'), 90 => t('90 dias')];
$periodo = (int) ($_GET['periodo'] ?? 30);
$periodo = isset($periodos[$periodo]) ? $periodo : 30;
$desde = date('Y-m-d', strtotime("-{$periodo} days"));
$semanas = [];
for ($i = -1; $i < 3; $i++) {
    $semanas[] = date('Y-m-d', strtotime(segundaDaSemana(date('Y-m-d')) . " +{$i} week"));
}
$seg = segundaDaSemana(escDataValida($_GET['semana'] ?? '') ?? $semanas[1]);
$params = array_filter(['unidade' => $unSel ?: null, 'turno' => $tSel ?: null, 'periodo' => $periodo !== 30 ? $periodo : null, 'semana' => $seg !== $semanas[1] ? $seg : null]);
$int = static fn($v): string => num((float) $v, 0);
$sinal = static fn(int $v): string => ($v > 0 ? '+' : ($v < 0 ? '−' : '')) . num(abs($v), 0);
$noFiltro = static fn(int $u, string $t): bool => (!$unSel || $u === $unSel) && ($tSel === '' || $t === $tSel);
$versao = static fn(string $arq): string => (string) (@filemtime(__DIR__ . '/../assets/' . $arq) ?: time());

$ult = escQdUltimaVersao();
$evol = $ult ? escQdEvolucao($desde, $unSel, $tSel) : [];
// "Tem" de cada dia: o registro diário do quadro (fc_quadro_dia, script 35) quando existe; antes dele,
// o "tem" da versão (até 08/11, a foto de 27/10).
$quadroDia = escFuQuadroDia($desde, $unSel, $tSel);
foreach ($evol as &$e) {
    $dE = substr((string) $e['data_calculo'], 0, 10);
    $e['tem_versao'] = (int) $e['tem'];
    if (isset($quadroDia[$dE])) {
        $e['tem'] = $quadroDia[$dE];
    }
}
unset($e);
$ut = $ult ? escQdUnidadeTurno((int) $ult['versao']) : [];
$plano = escPlanoAtual($seg);
$versaoSemana = $plano && !empty($plano['capacity_calculo']) ? escQdVersaoDoDia((string) $plano['capacity_calculo']) : ($ult ? (int) $ult['versao'] : null);
$ocup = $versaoSemana ? escQdOcupacao($plano, $versaoSemana) : [];
$versoes = $ult ? escQdVersoes($desde) : [];
$verFat = escPodeVerRestrito();
$srsHoje = escQdSrsAtivos(date('Y-m-d'));
$fechamento = escQdUltimoFechamento();
$faltaFechada = (int) round((float) (escQ1("SELECT valor FROM `5c_planning`.`fc_parametros` WHERE chave = 'falta_fechada'")['valor'] ?? 5));
$fat = $verFat && $ult ? escQdFaturamento((int) $ult['versao']) : [];
// Buffer por turno no último cálculo: o que a falta e os picos pedem × o mínimo (por turno desde o script 37; fc_buffer_turno).
$bufT = ['global' => 0, 'minimo' => 0, 'buffer' => 0, 'n' => 0];
if ($ult && !$unSel) {
    foreach (escQ('SELECT turno, buffer_global, buffer_minimo, buffer FROM `5c_planning`.`fc_buffer_turno` WHERE data_calculo = ?', [substr((string) $ult['data_calculo'], 0, 10)]) as $b) {
        if ($tSel === '' || $b['turno'] === $tSel) {
            $bufT['global'] += (int) $b['buffer_global'];
            $bufT['minimo'] += (int) $b['buffer_minimo'];
            $bufT['buffer'] += (int) $b['buffer'];
            $bufT['n']++;
        }
    }
}
$parBuf = [];
foreach (escQ("SELECT chave, valor FROM `5c_planning`.`fc_parametros` WHERE chave IN ('abs_desde', 'abs_d_media_ac', 'buffer_minimo_nucleo', 'buffer_minimo_turno', 'percentil_pico')") as $l) {
    $parBuf[$l['chave']] = (float) $l['valor'];
}

// Totais do filtro na última versão.
$tot = ['postos' => 0, 'titulares' => 0, 'buffer' => 0, 'precisa' => 0, 'tem' => 0];
$srsFiltro = 0;
foreach ($srsHoje as $u => $porT) {
    foreach ($porT as $t => $n) {
        $srsFiltro += $noFiltro($u, $t) ? $n : 0;
    }
}
foreach ($ut as $u => $porT) {
    foreach ($porT as $t => $l) {
        if ($noFiltro($u, $t)) {
            foreach ($tot as $k => $v) {
                $tot[$k] += (int) $l[$k];
            }
        }
    }
}
$falta = $tot['precisa'] - $tot['tem'];
$filtroRot = trim(($unSel ? t('Unidade {n}', ['n' => $unSel]) : '') . ($tSel !== '' ? ' · ' . t('turno {t}', ['t' => $tSel]) : ''), ' ·');
?>
<link rel="stylesheet" href="<?php echo h(MODULE_URL); ?>/assets/analise.css?v=<?php echo h($versao('analise.css')); ?>">
<div class="analise quadro">
<header class="cabecalho">
  <span class="eyebrow"><?php echo h(t('Quadro e contratação')); ?></span>
  <h1><?php echo h(t('Capacity')); ?></h1>
  <p class="muted"><?php echo h(t('Quantas pessoas o Capacity pede, quantas existem e onde falta; e quanto dos postos a escala da semana ocupa. O Capacity é a exceção ao corte de 01/11: todas as versões contam.')); ?></p>
</header>

<section class="panel">
  <form method="get" action="<?php echo h(MODULE_URL); ?>/index.php" class="acoes">
    <input type="hidden" name="p" value="capacity">
    <select name="unidade" data-envio-auto aria-label="<?php echo h(t('Unidade')); ?>">
      <option value=""><?php echo h(t('Todas as unidades')); ?></option>
      <?php foreach ([1, 2, 3] as $u): ?><option value="<?php echo $u; ?>" <?php echo $unSel === $u ? 'selected' : ''; ?>><?php echo h(t('Unidade {n}', ['n' => $u])); ?></option><?php endforeach; ?>
    </select>
    <select name="turno" data-envio-auto aria-label="<?php echo h(t('Turno')); ?>">
      <option value=""><?php echo h(t('Todos os turnos')); ?></option>
      <?php foreach (ESC_QD_TURNOS as $t => $faixa): ?><option value="<?php echo $t; ?>" <?php echo $tSel === $t ? 'selected' : ''; ?>><?php echo h($t . ' · ' . $faixa); ?></option><?php endforeach; ?>
    </select>
    <select name="periodo" data-envio-auto aria-label="<?php echo h(t('Período')); ?>">
      <?php foreach ($periodos as $d => $rot): ?><option value="<?php echo $d; ?>" <?php echo $periodo === $d ? 'selected' : ''; ?>><?php echo h(t('Últimos {p}', ['p' => $rot])); ?></option><?php endforeach; ?>
    </select>
    <select name="semana" data-envio-auto aria-label="<?php echo h(t('Semana da escala')); ?>" data-sem-busca>
      <?php foreach ($semanas as $s): ?><option value="<?php echo h($s); ?>" <?php echo $s === $seg ? 'selected' : ''; ?>><?php echo h(t('Escala de {ini} a {fim}', ['ini' => dataBr($s), 'fim' => dataBr(escMaisDias($s, 6))])); ?></option><?php endforeach; ?>
    </select>
  </form>
  <?php if ($ult): ?>
  <span class="muted small"><?php echo h(t('Versão {v} do Capacity, calculada em {dia} ({origem}).', ['v' => (int) $ult['versao'], 'dia' => escDataHora($ult['gerado_em']), 'origem' => strtolower((string) $ult['origem'])])); ?></span>
  <?php endif; ?>
  <p class="muted small"><?php echo h(t('Recalcula sozinho a cada mudança em pessoas, páginas ou postos, a cada Gerar e todo dia (diário).')); ?><?php if ($ultAuto): $dAuto = json_decode((string) $ultAuto['depois'], true) ?: []; ?> <?php echo h(t('Último recálculo automático: {quando} ({motivo}).', ['quando' => escDataHora((string) $ultAuto['quando']), 'motivo' => implode(', ', $dAuto['motivos'] ?? [])])); ?><?php endif; ?></p>
</section>

<?php if (!$ult): ?>
<section class="panel"><p class="muted"><?php echo h(t('Nenhuma versão do Capacity no 5c_planning.')); ?></p></section>
</div>
<?php return; endif; ?>

<section class="kpis">
  <div class="kpi"><span class="muted"><?php echo h($filtroRot !== '' ? t('Faltam hoje ({filtro})', ['filtro' => $filtroRot]) : t('Faltam hoje')); ?></span>
    <strong class="numero-heroi <?php echo $falta > 0 ? 'laranja' : 'verde'; ?>"><?php echo $falta > 0 ? $int($falta) : '0'; ?></strong>
    <span class="muted small"><?php echo h(t('o Capacity pede {precisa} pessoas; há {tem}', ['precisa' => $int($tot['precisa']), 'tem' => $int($tot['tem'])])); ?></span>
    <span class="muted small"><?php echo h(t('O "tem" do Capacity é o cadastro do 5C Escalas desde 09/11 (antes, a foto de 27/10); hoje há {n} SRs com cadastro.', ['n' => $int($srsFiltro)])); ?></span></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Postos')); ?></span><strong><?php echo $int($tot['postos']); ?></strong>
    <span class="muted small"><?php echo h(t('{tit} titulares (postos × 1,5) + {buf} de buffer', ['tit' => $int($tot['titulares']), 'buf' => $int($tot['buffer'])])); ?></span>
    <?php if ($bufT['n'] && $bufT['minimo'] > $bufT['global']): ?>
    <span class="muted small"><?php echo h(t('Pela falta e pelos picos, o buffer seria {g}; o mínimo de {t} por turno ({m} por núcleo) leva a {b}. Por isso mudar o absenteísmo não muda o buffer enquanto ele ficar abaixo do mínimo.', ['g' => $bufT['global'], 't' => num($parBuf['buffer_minimo_turno'] ?? 0, 0), 'm' => num($parBuf['buffer_minimo_nucleo'] ?? 2, 0), 'b' => $bufT['buffer']])); ?></span>
    <?php elseif ($bufT['n']): ?>
    <span class="muted small"><?php echo h(t('Buffer pela falta e pelos picos: {g} (mínimo {m}).', ['g' => $bufT['global'], 'm' => $bufT['minimo']])); ?></span>
    <?php endif; ?></div>
  <div class="kpi"><span class="muted"><?php echo h(t('A falta fecha (plano oficial)')); ?></span><strong><?php echo $fechamento ? h(escData((string) $fechamento['fecha_oficial'], false, true)) : '—'; ?></strong>
    <span class="muted small"><?php echo h($fechamento ? t('semana em que faltam até {n} pessoas com as turmas do plano (calculado na versão {v}, de {dia}); simule em Requisição de vagas', ['n' => $faltaFechada, 'v' => (int) $fechamento['versao'], 'dia' => escData((string) $fechamento['data_calculo'])]) : t('o plano de turmas ainda não foi calculado; simule em Requisição de vagas')); ?></span></div>
</section>

<div class="graficos">
<?php
// ---------- Evolução: precisa × tem ----------
if (count($evol) >= 1) {
    $rot = [];
    $vPre = [];
    $vTem = [];
    $dicas = [];
    foreach ($evol as $e) {
        $rot[] = escData((string) $e['data_calculo']);
        $vPre[] = (int) $e['precisa'];
        $vTem[] = (int) $e['tem'];
        $dicas[] = t('{dia} · versão {v}', ['dia' => escData((string) $e['data_calculo'], true), 'v' => (int) $e['versao']]) . "\n"
            . t('Precisa {p} · tem {t} · faltam {f}', ['p' => (int) $e['precisa'], 't' => (int) $e['tem'], 'f' => (int) $e['precisa'] - (int) $e['tem']]) . "\n"
            . t('{postos} postos · {tit} titulares · {buf} de buffer', ['postos' => (int) $e['postos'], 'tit' => (int) $e['titulares'], 'buf' => (int) $e['buffer']]);
    }
    $p0 = $evol[0];
    $pN = $evol[count($evol) - 1];
    $dPre = (int) $pN['precisa'] - (int) $p0['precisa'];
    $dTem = (int) $pN['tem'] - (int) $p0['tem'];
    $fN = (int) $pN['precisa'] - (int) $pN['tem'];
    if (count($evol) === 1) {
        $titulo = t('Faltam {f} pessoas: o Capacity pede {p} e há {t}', ['f' => $fN, 'p' => (int) $pN['precisa'], 't' => (int) $pN['tem']]);
    } elseif (abs($dPre) >= abs($dTem)) {
        $titulo = t('A falta foi de {f0} para {f}: a necessidade {mud} desde {dia}, e o quadro {quadro}', ['f0' => (int) $p0['precisa'] - (int) $p0['tem'], 'f' => $fN,
            'mud' => $dPre === 0 ? t('ficou igual') : ($dPre > 0 ? t('subiu {n}', ['n' => $dPre]) : t('caiu {n}', ['n' => -$dPre])), 'dia' => escData((string) $p0['data_calculo']),
            'quadro' => $dTem === 0 ? t('ficou igual') : ($dTem > 0 ? t('subiu {n}', ['n' => $dTem]) : t('caiu {n}', ['n' => -$dTem]))]);
    } else {
        $titulo = t('A falta foi de {f0} para {f}: o quadro {quadro} desde {dia}', ['f0' => (int) $p0['precisa'] - (int) $p0['tem'], 'f' => $fN, 'dia' => escData((string) $p0['data_calculo']),
            'quadro' => $dTem > 0 ? t('subiu {n}', ['n' => $dTem]) : t('caiu {n}', ['n' => -$dTem])]);
    }
    $svg = escGrafLinhas($rot, [
        ['nome' => t('Precisa'), 'cls' => 'cinza', 'tracejada' => true, 'v' => $vPre, 'rotulo' => t('Precisa {n}', ['n' => end($vPre)])],
        ['nome' => t('Tem'), 'cls' => 'destaque', 'grossa' => true, 'v' => $vTem, 'rotulo' => t('Tem {n}', ['n' => end($vTem)])],
    ], ['titulo' => $titulo, 'dicas' => $dicas, 'zero' => false, 'w' => 760]);
    echo escGrafFigura($titulo, t('Uma versão por dia (a última do dia). A distância entre as linhas é a falta. Passe o mouse para ver postos, titulares e buffer.')
        . ($quadroDia ? ' ' . t('"Tem" = SRs com cadastro no 5C Escalas em cada dia (registro diário do quadro, desde 01/11); antes disso, o "tem" da versão.') : ''),
        escGrafLegenda(['tracejada' => t('Precisa (titulares + buffer)'), 'destaque' => t('Tem (SRs no quadro)')]), $svg, 'largo', escGrafTabela(t('Ver tabela'), [t('Dia'), t('Versão'), t('Postos'), t('Titulares'), t('Buffer'), t('Precisa'), t('Tem'), t('Faltam')],
        array_map(static fn($e) => [escData((string) $e['data_calculo'], true), (int) $e['versao'], (int) $e['postos'], (int) $e['titulares'], (int) $e['buffer'], (int) $e['precisa'], (int) $e['tem'], (int) $e['precisa'] - (int) $e['tem']], array_reverse($evol))));
}

// ---------- Onde falta: unidade × turno ----------
$linhasF = [];
$maxF = 0;
foreach ($ut as $u => $porT) {
    foreach ($porT as $t => $l) {
        if ($noFiltro($u, $t)) {
            $maxF = max($maxF, (int) $l['precisa'] - (int) $l['tem']);
        }
    }
}
$piores = [];
$primeiro = true;
foreach ([1, 2, 3] as $u) {
    $sep = !$primeiro;
    foreach (array_keys(ESC_QD_TURNOS) as $t) {
        $l = $ut[$u][$t] ?? null;
        if (!$l || !$noFiltro($u, $t)) {
            continue;
        }
        $f = (int) $l['precisa'] - (int) $l['tem'];
        $pior = $f > 0 && $f === $maxF;
        if ($pior) {
            $piores[] = escQdRotUT($u, $t);
        }
        $linhasF[] = ['rot' => escQdRotUT($u, $t), 'seg' => [['v' => max(0, $f), 'cls' => $pior ? 'destaque' : 'cinza']], 'sep' => $sep,
            'valor' => $f > 0 ? t('faltam {n}', ['n' => $f]) : t('sobram {n}', ['n' => -$f]), 'valCls' => $pior ? 'laranja' : '',
            'tip' => escQdRotUT($u, $t) . "\n" . t('Precisa {p} ({tit} titulares + {buf} de buffer) · tem {t}', ['p' => (int) $l['precisa'], 'tit' => (int) $l['titulares'], 'buf' => (int) $l['buffer'], 't' => (int) $l['tem']])
                . "\n" . t('{postos} postos · faltas/dia {fd} · pico/dia {pd}', ['postos' => (int) $l['postos'], 'fd' => num((float) $l['faltas_dia'], 1), 'pd' => num((float) $l['pico_dia'], 1)])
                . "\n" . t('SRs com cadastro hoje no 5C Escalas: {n}', ['n' => (int) ($srsHoje[$u][$t] ?? 0)])];
        $sep = false;
        $primeiro = false;
    }
}
if ($linhasF) {
    $titulo = $maxF > 0
        ? (count($piores) === 1 ? t('{lista} é onde mais falta: {qtd} pessoas', ['lista' => $piores[0], 'qtd' => $maxF]) : t('{lista} são onde mais falta: {qtd} pessoas em cada', ['lista' => implode(t(' e '), $piores), 'qtd' => $maxF]))
        : t('Nenhuma unidade e turno com falta');
    echo escGrafFigura($titulo, t('Pessoas que faltam (precisa − tem) na última versão. Em destaque, a maior falta.'), '',
        escGrafBarrasH($linhasF, ['titulo' => $titulo, 'esq' => 70, 'dir' => 90, 'w' => 900]), 'largo');
}

// ---------- Ocupação da semana por carteira e turno ----------
$linhasO = [];
$tabO = [];
$menor = null;
$sepC = '';
foreach ($ocup as $cart => $porT) {
    $u = (int) substr((string) $cart, 1, 1);
    foreach (array_keys(ESC_QD_TURNOS) as $t) {
        $o = $porT[$t] ?? null;
        if (!$o || !$noFiltro($u, $t) || !$o['postos']) {
            continue;
        }
        $dias = $o['dias'] ?: 7;
        $taxa = $o['pessoa_dias'] / ($o['postos'] * $dias);
        $rot = $cart . ' · ' . $t;
        if ($plano && ($menor === null || $taxa < $menor[1])) {
            $menor = [$rot, $taxa, $o['lacunas']];
        }
        $linhasO[] = ['rot' => $rot, 'taxa' => $taxa, 'o' => $o, 'sep' => $sepC !== '' && $sepC !== $cart];
        $sepC = $cart;
        $tabO[] = [$cart, $t, $o['postos'], num($o['pessoa_dias'] / $dias, 1), pct($taxa, 0), $o['lacunas']];
    }
}
if ($plano && $linhasO) {
    $abaixo = 0.9;
    // Destaque só nas 3 menores ocupações (o resto fica cinza; abaixo de 90% o número fica laranja).
    $taxas = array_column($linhasO, 'taxa');
    sort($taxas);
    $corte = $taxas[min(2, count($taxas) - 1)];
    $barras = [];
    foreach ($linhasO as $l) {
        $baixo = $l['taxa'] < $abaixo;
        $barras[] = ['rot' => $l['rot'], 'seg' => [['v' => $l['taxa'] * 100, 'cls' => $baixo && $l['taxa'] <= $corte ? 'destaque' : 'cinza']], 'alvo' => 100, 'sep' => $l['sep'],
            'valor' => pct($l['taxa'], 0) . ($l['o']['lacunas'] ? ' · ' . tn($l['o']['lacunas'], '{n} vazio', '{n} vazios') : ''), 'valCls' => $baixo ? 'laranja' : '',
            'tip' => $l['rot'] . "\n" . t('{postos} postos no Capacity · {sr} SRs por dia nas páginas da carteira', ['postos' => $l['o']['postos'], 'sr' => num($l['o']['pessoa_dias'] / ($l['o']['dias'] ?: 7), 1)])
                . "\n" . tn($l['o']['lacunas'], '{n} bloco de 4 h sem ninguém na semana', '{n} blocos de 4 h sem ninguém na semana')];
    }
    $nBaixo = count(array_filter($linhasO, static fn($l) => $l['taxa'] < $abaixo));
    $totLac = array_sum(array_map(static fn($l) => $l['o']['lacunas'], $linhasO));
    $titulo = $nBaixo
        ? t('{pior} é a carteira e turno com menos SRs por posto ({pct}); {n} de {total} ficam abaixo de 90%', ['n' => $nBaixo, 'total' => count($linhasO), 'pior' => $menor[0], 'pct' => pct($menor[1], 0)])
        : t('Todas as carteiras e turnos ocupam 90% dos postos ou mais');
    echo escGrafFigura($titulo, t('SRs escalados por dia nas páginas da carteira ÷ postos do Capacity ({escala}). O traço é 100%. Acima de 100% = mais SRs que postos (ex.: escala montada pelo OM). {lac} na semana.',
            ['escala' => escPlanoRotulo($plano) . ' · ' . strtolower(escRotulo('status_plano', $plano['status'])), 'lac' => tn($totLac, '{n} bloco sem ninguém', '{n} blocos sem ninguém')]), '',
        escGrafBarrasH($barras, ['titulo' => $titulo, 'esq' => 80, 'dir' => 120, 'w' => 900, 'fmt' => static fn(float $v): string => num($v, 0) . '%']), 'largo',
        escGrafTabela(t('Ver tabela'), [t('Carteira'), t('Turno'), t('Postos'), t('SRs por dia'), t('Ocupação'), t('Blocos vazios')], $tabO));
} elseif (!$plano) {
    echo '<figure class="graf-cartao largo"><figcaption><strong>' . h(t('Ocupação dos postos')) . '</strong><span class="muted small">' . h(t('Nenhuma escala gerada para a semana de {dia}.', ['dia' => dataBr($seg)])) . '</span></figcaption></figure>';
}
?>
</div>

<?php if ($verFat && $fat): ?>
<section class="panel">
  <h2><?php echo h(t('Faturamento de 30 dias por carteira')); ?></h2>
  <p class="muted small"><?php echo h(t('Gravado na versão {v} do Capacity. Só WFM/Planejamento e Admin veem.', ['v' => (int) $ult['versao']])); ?></p>
  <div class="tabela-rolagem"><table>
    <thead><tr><th><?php echo h(t('Carteira')); ?></th><th class="num"><?php echo h(t('Páginas')); ?></th><th class="num"><?php echo h(t('Bruto 30 dias')); ?></th><th class="num"><?php echo h(t('Postos (soma dos turnos)')); ?></th></tr></thead>
    <tbody><?php foreach ($fat as $c => $f): if ($unSel && (int) substr($c, 1, 1) !== $unSel) { continue; } ?>
      <tr><td><?php echo h($c); ?></td><td class="num"><?php echo (int) $f['paginas']; ?></td><td class="num"><?php echo h(num($f['bruto'], 0)); ?></td><td class="num"><?php echo array_sum(array_map(static fn($o) => (int) $o['postos'], $ocup[$c] ?? [])); ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</section>
<?php endif; ?>

<section class="panel">
  <h2><?php echo h(t('Versões do Capacity e o que mudou')); ?></h2>
  <div class="tabela-rolagem"><table>
    <thead><tr><th><?php echo h(t('Versão')); ?></th><th><?php echo h(t('Calculada em')); ?></th><th class="num"><?php echo h(t('Postos')); ?></th><th class="num"><?php echo h(t('Precisa')); ?></th><th class="num"><?php echo h(t('Tem')); ?></th><th><?php echo h(t('O que mudou')); ?></th></tr></thead>
    <tbody>
    <?php foreach ($versoes as $i => $v): $ant = $versoes[$i + 1] ?? null; $dp = $ant ? (int) $v['precisa'] - (int) $ant['precisa'] : 0; ?>
      <tr><td>v<?php echo (int) $v['versao']; ?> <span class="muted small"><?php echo h(strtolower((string) $v['origem'])); ?></span></td>
        <td class="small"><?php echo h(escDataHora($v['gerado_em'])); ?></td>
        <td class="num"><?php echo (int) $v['postos']; ?></td>
        <td class="num"><?php echo (int) $v['precisa']; ?><?php if ($dp): ?> <span class="small <?php echo $dp > 0 ? 'laranja' : 'verde'; ?>">(<?php echo h($sinal($dp)); ?>)</span><?php endif; ?></td>
        <td class="num"><?php echo (int) $v['tem']; ?></td>
        <td class="small"><?php echo h(escGrafCorta(trim((string) ($v['obs'] ? $v['obs'] . ' · ' : '') . (string) $v['motivo']), 220) ?: '—'); ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="muted small"><?php echo h(t('O Capacity é recalculado sozinho todo dia, a cada Gerar e a cada mudança em pessoas, páginas ou postos (entradas e saídas de página, cadastro, turmas, alocação das páginas).')); ?>
    <?php echo h(t('Parâmetros do buffer (5c_planning.fc_parametros): absenteísmo desde {desde}{dmed}, mínimo de {tur} por turno e {min} por núcleo, pico coberto no percentil {pct}.', [
        'tur' => num($parBuf['buffer_minimo_turno'] ?? 0, 0),
        'desde' => !empty($parBuf['abs_desde']) ? dataBr(substr((string) (int) $parBuf['abs_desde'], 0, 4) . '-' . substr((string) (int) $parBuf['abs_desde'], 4, 2) . '-' . substr((string) (int) $parBuf['abs_desde'], 6, 2)) : t('a janela toda'),
        'dmed' => !empty($parBuf['abs_d_media_ac']) ? t(' (turno D na média de A e C)') : '',
        'min' => num($parBuf['buffer_minimo_nucleo'] ?? 2, 0), 'pct' => num($parBuf['percentil_pico'] ?? 0.5, 2)])); ?></p>
</section>
</div>
<script src="<?php echo h(MODULE_URL); ?>/assets/analise.js?v=<?php echo h($versao('analise.js')); ?>" defer></script>
