<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/equilibrio.php
// Equilíbrio das unidades (lib/equilibrio.php): faturamento total de 30 dias de cada unidade e
// coordenador, sugestões de troca de unidade (só páginas com 28 dias na unidade) e a divisão
// dos OS 40/35/25 em cada unidade e turno. O planejador aplica item a item.
// ===============================================

$validaData = static fn($d) => preg_match('~^\d{4}-\d{2}-\d{2}$~', (string) $d) ? max((string) $d, date('Y-m-d')) : null;

if (escPost() && ($_POST['acao'] ?? '') === 'unidade') {
    escExigir('decidir');
    // As páginas da mesma modelo trocam juntas (uma depois da outra, cada uma com a trava da troca).
    $lista = array_values(array_filter(array_map('trim', is_array($_POST['paginas'] ?? null) ? $_POST['paginas'] : [])));
    $destino = (int) ($_POST['destino'] ?? 0);
    $desde = $validaData($_POST['desde'] ?? '');
    if (!$lista || !$desde || !in_array($destino, [1, 2, 3], true)) {
        throw new RuntimeException(t('Informe a página e a data.'));
    }
    $dadosEq = escEqDados(date('Y-m-d'))['paginas'];
    $naEscala = escPolPaginas($desde);
    $antesMaturacao = false;
    foreach ($lista as $pagina) {
        // Antes dos 28 dias na unidade a troca é decisão do planejador (ex.: primeira geração):
        // vale, com aviso na confirmação e registro no motivo.
        $madura = $dadosEq[$pagina]['madura_em'] ?? null;
        $antesMaturacao = $antesMaturacao || ($madura && $desde < $madura);
        if (!isset($naEscala[$pagina])) {
            throw new RuntimeException(t('{pagina} não está na escala em {dia}.', ['pagina' => $pagina, 'dia' => dataBr($desde)]));
        }
    }
    $motivo = mb_substr((trim((string) ($_POST['motivo'] ?? '')) ?: 'Equilíbrio das unidades (faturamento total)') . ($antesMaturacao ? ' (antes dos 28 dias na unidade)' : ''), 0, 255);
    $novos = 0;
    $planos = [];
    foreach ($lista as $pagina) {
        $r = escPolAplicarUnidade(escPolPaginas($desde)[$pagina], $destino, $desde, $motivo);
        $novos += count($r['postos_novos']);
        foreach ($r['planos'] as $pl) {
            $planos[$pl['semana']] = true;
        }
    }
    escVoltar('equilibrio', [], t('{pagina}: passa da U{de} para a U{para} a partir de {dia}. {novos} posto(s) novo(s); {planos} escala(s) gerada(s) de novo com o mínimo de mudanças.', [
        'pagina' => implode(' + ', $lista), 'de' => $r['de'], 'para' => $r['para'], 'dia' => dataBr($desde), 'novos' => $novos, 'planos' => count($planos)]));
}

if (escPost() && ($_POST['acao'] ?? '') === 'divisao') {
    escExigir('decidir');
    $u = (int) ($_POST['unidade'] ?? 0);
    $t = (string) ($_POST['turno'] ?? '');
    $desde = $validaData($_POST['desde'] ?? '');
    if (!$desde || !in_array($u, [1, 2, 3], true) || !in_array($t, ['A', 'C', 'D'], true)) {
        throw new RuntimeException(t('Informe a unidade, o turno e a data.'));
    }
    $r = escEqAplicarDivisao($u, $t, $desde);
    escVoltar('equilibrio', [], t('Divisão dos OS da U{u} {turno} a partir de {dia}: {postos} posto(s) com núcleo novo, {srs} SR(s) mudaram de núcleo, {fora} vaga(s) de equipe liberadas (a próxima geração completa).', [
        'u' => $u, 'turno' => $t, 'dia' => dataBr($desde), 'postos' => $r['postos'], 'srs' => $r['srs'], 'fora' => $r['equipe_fora']]), false, 'os');
}

$erroDados = null;
// Estrutura vista: a da próxima semana a gerar (segunda que vem), com as trocas já agendadas;
// ?dia= mostra outro dia.
$diaEq = preg_match('~^\d{4}-\d{2}-\d{2}$~', (string) ($_GET['dia'] ?? '')) ? $_GET['dia'] : max(date('Y-m-d', strtotime('next monday')), escV4DiaEstrutura());
try {
    $eq = escEqDados(date('Y-m-d'), $diaEq);
} catch (Throwable $e) {
    appLog('error', 'Equilíbrio: falha ao ler', ['erro' => $e->getMessage()]);
    $erroDados = $e->getMessage();
    $eq = ['dia' => date('Y-m-d'), 'paginas' => [], 'srs' => [], 'pessoas' => [], 'fat' => [], 'postos' => [], 'nucleo_id' => []];
}
// Meta: o mesmo faturamento total em cada unidade e coordenador (peso 1); o por SR é acompanhamento.
$pesoTotal = escEqPesoTotal($eq['fat'], $eq['srs']);
$ds = escEqDesvios($eq['fat'], $pesoTotal);
$dsSr = escEqDesvios($eq['fat'], $eq['srs']);
$conheceMin = max(0, (int) escV4Param('v4_conhece_min', '2'));
$sugestoes = escEqSugerirTrocas($eq['paginas'], $pesoTotal, 60, 0.005, $conheceMin);
// Páginas com menos de v4_conhece_min SRs que as conhecem na própria unidade, da que mais fatura.
$semConhecedor = array_filter($eq['paginas'], static fn($p) => ($p['conhece'][$p['unidade']] ?? 0) < $conheceMin);
uasort($semConhecedor, static fn($a, $b) => array_sum($b['fat']) <=> array_sum($a['fat']));
$fatDepois = $sugestoes ? end($sugestoes)['fat_depois'] : $eq['fat'];
$depois = escEqDesvios($fatDepois, $pesoTotal)['desvio'];
$depoisSr = escEqDesvios($fatDepois, $eq['srs'])['desvio'];
$maduras = count(array_filter($sugestoes, static fn($s) => ($s['madura_em'] ?? '') <= date('Y-m-d')));
// Total de cada unidade (os 3 turnos) contra a média das unidades, hoje e com todas as trocas.
$totalU = static function (array $fat): array {
    $tot = array_map('array_sum', $fat);
    ksort($tot);
    $m = $tot ? array_sum($tot) / count($tot) : 0;
    return array_map(static fn($v) => ['total' => $v, 'desvio' => $m > 0 ? $v / $m - 1 : null], $tot);
};
$unidHoje = $totalU($eq['fat']);
$unidDepois = $totalU($fatDepois);
$podeDecidir = escPode('decidir');
$treino = escModoTreino();
$nomes = escNomes();
// Nomes do OC de cada unidade e turno e do OS de cada núcleo (escala da liderança do dia).
$osNome = [];
$ocNome = [];
try {
    foreach (escLiderancaCarregar($eq['dia'])['grupos'] as $t => $g) {
        foreach ($g as $chave => $pessoas) {
            foreach ($pessoas as $l) {
                $nome = $l['nome'] !== '' ? nomeCurto($l['nome']) : ($l['rep'] ?? '—');
                if ($chave === 'OC') {
                    $ocNome[$l['unidade']][$t][] = $nome;
                } elseif ($l['cargo'] === 'OS') {
                    $osNome[$l['unidade']][$t][$l['nucleo']] = $nome;
                }
            }
        }
    }
} catch (Throwable $e) {
    appLog('warning', 'Equilíbrio: sem nomes da liderança', ['erro' => $e->getMessage()]);
}
$amanha = date('Y-m-d', strtotime('+1 day'));
$classeDesvio = static fn($d) => $d === null ? 'muted' : (abs($d) <= 0.05 ? 'verde' : (abs($d) <= 0.15 ? 'laranja' : 'vermelho'));
$fmtDesvio = static fn($d) => $d === null ? '—' : ($d > 0 ? '+' : '') . pct($d, 0);
?>
<header class="cabecalho">
  <span class="eyebrow"><?php echo h(t('Páginas')); ?></span>
  <h1><?php echo h(t('Equilíbrio das unidades')); ?></h1>
  <p class="muted"><?php echo h(t('Faturamento dos últimos 30 dias de cada unidade e de cada coordenador (unidade × turno). A meta é o mesmo faturamento total em todos; o faturamento por SR fica para acompanhar (com páginas maiores, o Capacity pede mais SRs). Refeito a cada leitura, com a estrutura e o Capacity do dia.')); ?></p>
  <form method="get" class="acoes small">
    <input type="hidden" name="p" value="equilibrio">
    <label><?php echo h(t('Estrutura de')); ?> <input type="date" name="dia" value="<?php echo h($diaEq); ?>"></label>
    <button type="submit" class="botao"><?php echo h(t('Ver')); ?></button>
  </form>
</header>

<?php if ($erroDados): ?><div class="aviso erro" role="alert"><?php echo h(t('Não deu para ler os dados do equilíbrio: {erro}', ['erro' => $erroDados])); ?></div><?php endif; ?>
<?php if ($treino): ?><div class="aviso" role="status"><?php echo h(t('Modo Treino: só a prévia. Trocas de unidade e divisão dos OS não são aplicadas.')); ?></div><?php endif; ?>

<section class="kpis">
  <?php foreach ($unidHoje as $u => $x): $y = $unidDepois[$u] ?? null; ?>
  <div class="kpi"><span class="muted">U<?php echo (int) $u; ?> · <?php echo h(t('30 dias')); ?></span><strong class="<?php echo $classeDesvio($x['desvio']); ?>">US$ <?php echo num($x['total'] / 1e6, 2); ?> mi <span class="small"><?php echo h($fmtDesvio($x['desvio'])); ?></span></strong><span class="muted small"><?php echo h(t('com as trocas: {v} ({d})', ['v' => 'US$ ' . num(($y['total'] ?? 0) / 1e6, 2) . ' mi', 'd' => $fmtDesvio($y['desvio'] ?? null)])); ?></span></div>
  <?php endforeach; ?>
  <div class="kpi"><span class="muted"><?php echo h(t('Trocas sugeridas')); ?></span><strong><?php echo count($sugestoes); ?></strong><span class="muted small"><?php echo h(tn($maduras, '{n} já pode ser aplicada', '{n} já podem ser aplicadas')); ?></span></div>
</section>

<section class="panel">
  <h2><?php echo h(t('Faturamento por coordenador')); ?></h2>
  <div class="tabela-rolagem">
  <table>
    <thead>
      <tr><th rowspan="2"><?php echo h(t('Unidade · turno')); ?></th><th rowspan="2"><?php echo h(t('Coordenador')); ?></th><th colspan="3" class="num"><?php echo h(t('Faturamento total (meta)')); ?></th><th colspan="3" class="num"><?php echo h(t('Por SR (acompanhamento)')); ?></th></tr>
      <tr><th class="num"><?php echo h(t('30 dias')); ?></th><th class="num"><?php echo h(t('Desvio hoje')); ?></th><th class="num"><?php echo h(t('Com todas as trocas')); ?></th><th class="num">SRs</th><th class="num"><?php echo h(t('Desvio hoje')); ?></th><th class="num"><?php echo h(t('Com todas as trocas')); ?></th></tr>
    </thead>
    <tbody>
    <?php foreach ($pesoTotal as $u => $porT): foreach (['A', 'C', 'D'] as $t): $nSr = (int) ($eq['srs'][$u][$t] ?? 0); ?>
      <tr>
        <td>U<?php echo (int) $u; ?> <?php echo chipTurno($t); ?></td>
        <td class="small"><?php echo !empty($ocNome[$u][$t]) ? h(implode(', ', $ocNome[$u][$t])) : '<span class="muted">—</span>'; ?></td>
        <td class="num">US$ <?php echo num($eq['fat'][$u][$t] ?? 0, 0); ?></td>
        <?php foreach ([$ds['desvio'][$u][$t] ?? null, $depois[$u][$t] ?? null] as $d): ?><td class="num <?php echo $classeDesvio($d); ?>"><?php echo h($fmtDesvio($d)); ?></td><?php endforeach; ?>
        <td class="num"><?php echo $nSr; ?></td>
        <?php foreach ([$dsSr['desvio'][$u][$t] ?? null, $depoisSr[$u][$t] ?? null] as $d): ?><td class="num muted"><?php echo h($fmtDesvio($d)); ?></td><?php endforeach; ?>
      </tr>
    <?php endforeach; endforeach; ?>
    <?php if (!$pesoTotal): ?><tr><td colspan="8" class="muted"><?php echo h(t('Sem faturamento nem SRs na estrutura nova para o dia.')); ?></td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <p class="muted small"><?php echo h(t('Faturamento: complete + pending − reverse, todas as classificações, pelo horário da venda (A 05h–13h, C 13h–21h, D 21h–05h). Desvio = faturamento do coordenador ÷ média do turno − 1 (por SR: o mesmo, dividido pelos SRs). Até ±5% está equilibrado.')); ?></p>
  <?php $capU = escEqCapacityUnidades(); if ($capU): ?>
  <p class="small"><strong><?php echo h(t('Conferência com o Capacity (versão {v}, bruto de 30 dias por unidade):', ['v' => reset($capU)['versao']])); ?></strong>
    <?php foreach ($capU as $u => $c): ?>
    · U<?php echo (int) $u; ?> US$ <?php echo num($c['bruto'], 0); ?> <span class="muted">(<?php echo h(t('aqui {v}', ['v' => 'US$ ' . num(array_sum($eq['fat'][$u] ?? []), 0)])); ?>)</span><?php if ($c['alerta']): ?> <span class="tag laranja" title="<?php echo h(t('A unidade foge mais de 30% da média das unidades no Capacity.')); ?>"><?php echo h(($c['desvio'] > 0 ? '+' : '') . pct($c['desvio'], 0)); ?></span><?php endif; ?>
    <?php endforeach; ?>
  </p>
  <?php endif; ?>
</section>

<section class="panel" id="trocas">
  <h2><?php echo h(t('Trocas de unidade sugeridas')); ?></h2>
  <p class="muted"><?php echo h(t('Da que mais aproxima as unidades para a que menos, cada uma contando com as de cima ("Melhora" é quanto do desequilíbrio de hoje some até aquela linha). A página troca com todos os turnos, junto com as outras páginas da mesma modelo. Só pode trocar depois de 28 dias na unidade; o gap que ficar, o Funil cobre dando prioridade às unidades abaixo da média.')); ?></p>
  <div class="tabela-rolagem largo">
  <table>
    <thead><tr><th>#</th><th><?php echo h(t('Página')); ?></th><th><?php echo h(t('De → para')); ?></th><th class="num">A</th><th class="num">C</th><th class="num">D</th><th class="num"><?php echo h(t('Melhora')); ?></th><th><?php echo h(t('Pode a partir de')); ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($sugestoes as $i => $s): $pg = $eq['paginas'][$s['paginas'][0]]; $pode = ($s['madura_em'] ?? '') <= date('Y-m-d'); $sugerida = max($amanha, (string) $s['madura_em']); ?>
      <tr>
        <td class="num"><?php echo $i + 1; ?></td>
        <td><strong><?php echo h($s['pagina']); ?></strong><br><span class="muted small"><?php echo h(t('na U{u} desde {dia}', ['u' => $s['de'], 'dia' => dataBr($pg['desde'])])); ?></span></td>
        <td>U<?php echo (int) $s['de']; ?> → <strong>U<?php echo (int) $s['para']; ?></strong><br><span class="muted small"><?php echo h(tn((int) $s['conhece_destino'], '{n} SR conhece no destino', '{n} SRs conhecem no destino')); ?></span></td>
        <?php foreach (['A', 'C', 'D'] as $t): ?><td class="num"><?php echo num($s['fat'][$t] ?? 0, 0); ?></td><?php endforeach; ?>
        <td class="num"><?php echo pct($s['melhora'], 0); ?></td>
        <td><?php echo $pode ? '<span class="tag verde">' . h(t('já pode')) . '</span>' : '<span class="tag laranja">' . h(dataBr($s['madura_em'])) . '</span>'; ?></td>
        <td class="nowrap">
          <?php if ($podeDecidir && !$treino): ?>
          <form method="post" action="<?php echo h(link_tela('equilibrio')); ?>" class="acoes" data-confirmar="<?php echo h(t('Passar {pagina} da U{de} para a U{para}?', ['pagina' => $s['pagina'], 'de' => $s['de'], 'para' => $s['para']]) . "\n\n" . t('Recalcula o Capacity, cria os postos no destino e gera as escalas de novo com o mínimo de mudanças. Não dá para desfazer pelo sistema.')
                . ($pode ? '' : "\n\n" . t('Atenção: a página só completa 28 dias na unidade em {dia}. Se a data escolhida for antes disso, a troca vale assim mesmo.', ['dia' => dataBr($s['madura_em'])]))); ?>" data-longo="<?php echo h(t('Aplicando a troca… recalcula o Capacity e a escala, pode levar alguns minutos.')); ?>">
            <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="unidade"><?php foreach ($s['paginas'] as $nomePg): ?><input type="hidden" name="paginas[]" value="<?php echo h($nomePg); ?>"><?php endforeach; ?><input type="hidden" name="destino" value="<?php echo (int) $s['para']; ?>">
            <input type="date" name="desde" required min="<?php echo h($amanha); ?>" value="<?php echo h($sugerida); ?>" aria-label="<?php echo h(t('A partir de')); ?>">
            <button type="submit" class="botao"><?php echo h(t('Aplicar…')); ?></button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$sugestoes): ?><tr><td colspan="9" class="muted"><?php echo h(t('Nenhuma troca melhora o equilíbrio.')); ?></td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</section>

<section class="panel" id="conhece">
  <h2><?php echo h(t('Páginas sem quem as conheça na unidade')); ?></h2>
  <p class="muted"><?php echo h(t('Conhece a página quem tem {d} dias ou mais nela nas últimas 8 semanas (5C Schedule). Aqui ficam as páginas com menos de {n} SRs assim na própria unidade, com quem as conhece em outra. O Gerar só escolhe entre os SRs da unidade e do turno: para a página ganhar quem a conhece, a pessoa muda de unidade no mesmo turno e nível, com uma compensação no sentido contrário para a cobertura não mudar. As trocas de unidade acima só vão para onde a modelo tem pelo menos {n} SRs que a conhecem.', ['d' => max(1, (int) escV4Param('v4_conhece_dias', '3')), 'n' => $conheceMin])); ?></p>
  <div class="tabela-rolagem largo">
  <table>
    <thead><tr><th><?php echo h(t('Página')); ?></th><th><?php echo h(t('Unidade')); ?></th><th class="num"><?php echo h(t('Faturamento 30 dias')); ?></th><th class="num"><?php echo h(t('Conhecem na unidade')); ?></th><th><?php echo h(t('Quem conhece em outra unidade')); ?></th></tr></thead>
    <tbody>
    <?php foreach ($semConhecedor as $nomePg => $p): $fora = array_slice(array_values(array_filter($p['conhecedores'], static fn($c) => $c['unidade'] !== $p['unidade'])), 0, 4); ?>
      <tr>
        <td><strong><?php echo h($nomePg); ?></strong></td>
        <td>U<?php echo (int) $p['unidade']; ?></td>
        <td class="num">US$ <?php echo num(array_sum($p['fat']), 0); ?></td>
        <td class="num"><?php echo (int) ($p['conhece'][$p['unidade']] ?? 0); ?></td>
        <td class="small"><?php if ($fora): foreach ($fora as $i => $c): ?><?php echo $i ? ' · ' : ''; ?><?php echo h(nomeCurto($nomes[$c['rep']] ?? $c['rep'])); ?> <span class="muted">U<?php echo (int) $c['unidade']; ?> <?php echo h($c['turno']); ?>, <?php echo h(tn((int) $c['dias'], '{n} dia', '{n} dias')); ?></span><?php endforeach; else: ?><span class="muted"><?php echo h(t('ninguém conhece')); ?></span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$semConhecedor): ?><tr><td colspan="5" class="muted"><?php echo h(t('Toda página tem quem a conheça na própria unidade.')); ?></td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</section>

<section class="panel" id="os">
  <h2><?php echo h(t('Divisão dos OS')); ?></h2>
  <p class="muted"><?php echo h(t('Em cada unidade e turno, pelo faturamento de 30 dias dos postos, do maior para o menor: na metade de cima, OS1, OS1, OS2 (repetindo); na metade de baixo, OS2, OS3, OS3. O OS1 fica com as maiores páginas, o OS3 com as menores e o OS2 com um misto, cerca de 1/3 dos postos cada. Cobertura: o OS2 cobre os postos do OS1 e do OS3; nos postos do OS2, os grandes ficam com o OS1 e os pequenos com o OS3. Os melhores SRs (nível, depois mérito) vão para o OS1; no OS2, quem conhece páginas grandes e pequenas; o tamanho de cada núcleo segue os postos dele.')); ?></p>
  <?php foreach ($eq['postos'] as $u => $porT): foreach (['A', 'C', 'D'] as $t): if (empty($porT[$t])) { continue; }
      $div = escEqDivisao($porT[$t], $eq['pessoas'][$u][$t] ?? []);
      $mudaP = array_filter($div['postos'], static fn($p) => $p['muda']);
      $mudaS = array_filter($div['srs'], static fn($s) => $s['novo'] !== $s['num']);
      $tem3 = count($eq['nucleo_id'][$u][$t] ?? []) >= 3; ?>
  <details class="eq-os">
    <summary><strong>U<?php echo (int) $u; ?></strong> <?php echo chipTurno($t); ?>
      <span class="small"><?php foreach ([1, 2, 3] as $k): ?> · OS<?php echo $k; ?> <?php echo h(pct($div['parte_hoje'][$k] ?? 0, 0)); ?> → <strong><?php echo h(pct($div['parte_nova'][$k] ?? 0, 0)); ?></strong><?php endforeach; ?></span>
      <span class="muted small"> · <?php echo h(t('{p} posto(s) e {s} SR(s) mudam', ['p' => count($mudaP), 's' => count($mudaS)])); ?></span></summary>
    <div class="tabela-rolagem">
    <table>
      <thead><tr><th>OS</th><th><?php echo h(t('Nome')); ?></th><th class="num"><?php echo h(t('Faturamento hoje')); ?></th><th class="num"><?php echo h(t('Proposta')); ?></th><th class="num"><?php echo h(t('Postos')); ?></th><th class="num">SRs</th></tr></thead>
      <tbody>
      <?php foreach ([1, 2, 3] as $k): ?>
        <tr><td>OS<?php echo $k; ?></td><td class="small"><?php echo h($osNome[$u][$t][$k] ?? '—'); ?></td><td class="num"><?php echo pct($div['parte_hoje'][$k] ?? 0, 0); ?></td><td class="num"><strong><?php echo pct($div['parte_nova'][$k] ?? 0, 0); ?></strong></td><td class="num"><?php echo (int) ($div['postos_por_os'][$k] ?? 0); ?></td><td class="num"><?php echo (int) ($div['tamanho'][$k] ?? 0); ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php if ($mudaP): ?>
    <h3 class="small"><?php echo h(t('Postos que mudam de núcleo')); ?></h3>
    <ul class="regras small">
      <?php foreach ($mudaP as $p): ?><li><?php echo h(implode(' + ', array_unique($p['paginas']))); ?> · US$ <?php echo num($p['fat'], 0); ?> · <?php echo h(t('dono OS{a} → OS{b}, cobertura OS{c} → OS{d}', ['a' => $p['dono'] ?: '?', 'b' => $p['os'], 'c' => $p['cob'] ?: '?', 'd' => $p['os_cob']])); ?></li><?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if ($mudaS): ?>
    <h3 class="small"><?php echo h(t('SRs que mudam de núcleo')); ?></h3>
    <ul class="regras small">
      <?php foreach ($mudaS as $rep => $s): ?><li><strong><?php echo h(nomeCurto($nomes[$rep] ?? $rep)); ?></strong> <span class="mono muted"><?php echo h($rep); ?></span> · SR <?php echo (int) $s['nivel']; ?><?php echo $s['nota'] !== null ? ' · ' . h(t('mérito {n}', ['n' => num($s['nota'], 2)])) : ''; ?> · OS<?php echo (int) $s['num']; ?> → <strong>OS<?php echo (int) $s['novo']; ?></strong></li><?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if (!$tem3): ?><p class="aviso small"><?php echo h(t('A U{u} não tem os 3 núcleos no turno {turno}.', ['u' => $u, 'turno' => $t])); ?></p><?php endif; ?>
    <?php if ($podeDecidir && !$treino && $tem3 && ($mudaP || $mudaS)): ?>
    <form method="post" action="<?php echo h(link_tela('equilibrio')); ?>" class="acoes" data-confirmar="<?php echo h(t('Aplicar a divisão dos OS da U{u} {turno}?', ['u' => $u, 'turno' => $t]) . "\n\n" . t('{p} posto(s) mudam de núcleo e {s} SR(s) mudam de OS a partir da data. As vagas de equipe que deixarem de bater com o núcleo são completadas na próxima geração.', ['p' => count($mudaP), 's' => count($mudaS)])); ?>">
      <?php echo campoCsrf(); ?><input type="hidden" name="acao" value="divisao"><input type="hidden" name="unidade" value="<?php echo (int) $u; ?>"><input type="hidden" name="turno" value="<?php echo h($t); ?>">
      <label><?php echo h(t('Aplicar a partir de')); ?><input type="date" name="desde" required min="<?php echo h($amanha); ?>" value="<?php echo h(escMaisDias(segundaDaSemana($amanha), 7)); ?>"></label>
      <button type="submit" class="botao primario"><?php echo h(t('Aplicar divisão')); ?></button>
    </form>
    <?php endif; ?>
  </details>
  <?php endforeach; endforeach; ?>
  <?php if (!$eq['postos']): ?><p class="muted"><?php echo h(t('Sem postos na estrutura nova para o dia.')); ?></p><?php endif; ?>
  <p class="muted small"><?php echo h(t('Depois de aplicar, gere a semana (Gerar escala › Recalcular com o mínimo de mudanças) para as equipes novas entrarem na escala.')); ?></p>
</section>
