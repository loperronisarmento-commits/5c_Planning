<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/lideranca.php
// Escala da liderança (OC, OS e OA), separada da dos SRs: 5x2 no ciclo de 21
// dias, cada pessoa num turno só, com o dia de Overlap em que os 3 do grupo
// estão no turno. O OM não segue escala. Sai do rodízio (lib/lideranca.php); não grava nada.
// Download: ?baixar=csv&semana=Y-m-d (rodízio para conferência) e "Baixar PDF" (impressão em
// A4 paisagem, papel timbrado, um turno por página; sem as marcas de conferência).
// ===============================================

$seg = segundaDaSemana(preg_match('~^\d{4}-\d{2}-\d{2}$~', (string) ($_GET['semana'] ?? '')) ? $_GET['semana'] : date('Y-m-d', strtotime('+7 day')));
$escala = escLiderancaSemana($seg);

if (($_GET['baixar'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="escala_lideranca_' . $seg . '.csv"');
    echo escLiderancaCsv($escala);
    return;
}

$dias = $escala['dias'];
// O que vai para o site é o plano da semana (página 5C Schedule na Grade, com os ajustes feitos
// lá). Aqui o rodízio é comparado com o plano: célula diferente ganha a marca "≠ grade".
$planoSemana = escPlanoAtual($seg);
$noPlano = [];
if ($planoSemana) {
    foreach (escQ("SELECT DISTINCT UPPER(rep_code) AS r, turno, IF(bloco = 'T1', DATE_SUB(dia, INTERVAL 1 DAY), dia) AS dt FROM " . escT('esc_plano_alocacao') . " WHERE plano_id = ? AND origem IN ('LIDER_OS', 'LIDER_OC', 'LIDER_OA') AND rep_code IS NOT NULL", [(int) $planoSemana['id']]) as $l) {
        $noPlano[$l['r'] . '|' . substr((string) $l['dt'], 0, 10) . '|' . $l['turno']] = true;
    }
}
$diferencas = 0;
$rotuloGrupo = static fn($g) => isset(ESC_LID_CARGO[$g]) ? ESC_LID_CARGO[$g] : 'Operational Supervisor · U' . $g;
$minimo = static fn($g) => ESC_LID_MINIMO[$g] ?? 2;
$celula = [
    'TRABALHO' => '<span class="verde">' . h(t('trabalha')) . '</span>',
    'OVERLAP' => '<strong class="azul">Overlap</strong>',
    'FOLGA' => '<span class="muted">' . h(t('folga')) . '</span>',
    'AUSENTE' => '<span class="laranja">' . h(t('ausente')) . '</span>',
    'ANTES' => '<span class="muted">—</span>',
    'OUTRO' => '<span class="muted">' . h(t('outro turno')) . '</span>',
];
$semCodigo = [];
$total = ['OC' => 0, 'OS' => 0, 'OA' => 0];
foreach ($escala['grupos'] as $grupos) {
    foreach ($grupos as $pessoas) {
        foreach ($pessoas as $p) {
            $total[$p['cargo']]++;
            if (!$p['rep']) {
                $semCodigo[] = $p['nome'] . ' (' . ($p['cargo'] === 'OS' ? 'OS U' . $p['unidade'] . $p['turno'] . $p['nucleo'] : $p['cargo'] . ($p['unidade'] ? ' U' . $p['unidade'] : '') . ' ' . $p['turno']) . ')';
            }
        }
    }
}
$poucos = 0;
foreach ($escala['presentes'] as $grupos) {
    foreach ($grupos as $g => $porDia) {
        foreach ($porDia as $n) {
            $poucos += $n < $minimo($g) ? 1 : 0;
        }
    }
}
?>
<div class="lid">
<header class="cabecalho">
  <span class="eyebrow"><?php echo h(t('Escala')); ?></span>
  <h1><?php echo h(t('Escala da liderança · semana de {dia}', ['dia' => dataBr($seg)])); ?></h1>
  <p class="lid-so-pdf"><?php echo h(t('Leadership schedule · {de} a {ate} · versão de {gerado}', ['de' => date('d/m/Y', strtotime($dias[0])), 'ate' => date('d/m/Y', strtotime(end($dias))), 'gerado' => date('d/m/Y H:i')])); ?></p>
  <p class="muted nao-imprimir"><?php echo h(t('Operational Coordinator (3 por turno) e Operational Supervisor (3 por unidade e turno) em 5x2, no mesmo ciclo de 21 dias dos SRs, cada um num turno só; o OS segue o núcleo dele. Operational Assistant: 1 por turno todo dia (24/7), numa semana fixa em que quem faz dois turnos sempre muda para o mais tarde. Não há dia de treinamento separado: no dia de Overlap os 3 do grupo estão no turno; nos outros dias, 2 de 3 (OA: ao menos 1). O Operational Manager não segue escala.')); ?></p>
</header>

<section class="panel nao-imprimir">
  <div class="acoes">
    <a class="botao" href="<?php echo h(link_tela('lideranca', ['semana' => escMaisDias($seg, -7)])); ?>">← <?php echo h(dataBr(escMaisDias($seg, -7))); ?></a>
    <a class="botao" href="<?php echo h(link_tela('lideranca', ['semana' => escMaisDias($seg, 7)])); ?>"><?php echo h(dataBr(escMaisDias($seg, 7))); ?> →</a>
    <?php if ($planoSemana): ?><a class="botao primario" href="<?php echo h(link_tela('grade', ['plano' => (int) $planoSemana['id'], 'aba' => 'pagina'])); ?>#postar"><?php echo h(t('Postar pela Grade ({versao})', ['versao' => escPlanoRotulo($planoSemana)])); ?></a><?php endif; ?>
    <a class="botao" href="<?php echo h(link_tela('lideranca', ['semana' => $seg, 'baixar' => 'csv'])); ?>"><?php echo h(t('Baixar CSV do rodízio (conferência)')); ?></a>
    <button type="button" class="botao primario" id="lid-pdf" title="<?php echo h(t('Abre a impressão: escolha "Salvar como PDF". A4 paisagem, um turno por página.')); ?>"><?php echo h(t('Baixar PDF')); ?></button>
  </div>
  <p class="muted small"><?php echo h(t('Para postar, use a Grade: a liderança sai no CSV da página 5C Schedule (-8 OS, -7 OC, -6 OA), já com os ajustes feitos lá. Este CSV mostra só o rodízio, sem os ajustes.')); ?><?php echo $planoSemana ? '' : ' ' . h(t('Esta semana ainda não tem plano gerado.')); ?></p>
  <div class="kpis">
    <div class="kpi"><span class="muted">Operational Coordinator</span><strong><?php echo $total['OC']; ?></strong><span class="muted small"><?php echo h(t('3 por turno')); ?></span></div>
    <div class="kpi"><span class="muted">Operational Supervisor</span><strong><?php echo $total['OS']; ?></strong><span class="muted small"><?php echo h(t('3 por unidade e turno')); ?></span></div>
    <div class="kpi"><span class="muted">Operational Assistant</span><strong><?php echo count(array_unique(array_column(array_merge(...array_map(static fn($g) => $g['OA'] ?? [], array_values($escala['grupos']))), 'nome'))); ?></strong><span class="muted small"><?php echo h(t('1 por turno, todo dia (24/7)')); ?></span></div>
    <div class="kpi"><span class="muted"><?php echo h(t('Grupos abaixo do mínimo')); ?></span><strong class="<?php echo $poucos ? 'laranja' : ''; ?>"><?php echo $poucos; ?></strong><span class="muted small"><?php echo h(t('grupo × dia: OC e OS com menos de 2, OA sem ninguém')); ?></span></div>
    <div class="kpi"><span class="muted"><?php echo h(t('Sem código')); ?></span><strong class="<?php echo $semCodigo ? 'laranja' : ''; ?>"><?php echo count($semCodigo); ?></strong><span class="muted small"><?php echo h(implode('; ', $semCodigo) ?: t('todos com código')); ?></span></div>
  </div>
</section>

<p class="lid-so-pdf lid-legenda"><?php echo h(t('Trabalha · Overlap (os 3 do grupo no turno) · folga · ausente (indisponibilidade aprovada) · — antes da virada. "no turno" = quantos do grupo estão no dia.')); ?></p>
<?php foreach ($escala['grupos'] as $turno => $grupos): ?>
<section class="panel lid-turno">
  <h2><?php echo chipTurno($turno); ?> <?php echo h(t('Turno {turno} · {horario}', ['turno' => $turno, 'horario' => ESC_LID_HORARIO[$turno]])); ?></h2>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th><?php echo h(t('Pessoa')); ?></th><th><?php echo h(t('Cargo')); ?></th><?php foreach ($dias as $d): ?><th><?php echo h(dataBr($d, true)); ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($grupos as $g => $pessoas): ?>
      <tr><th colspan="<?php echo 2 + count($dias); ?>" class="small"><?php echo h($rotuloGrupo($g)); ?></th></tr>
      <?php foreach ($pessoas as $i => $p): ?>
      <tr>
        <td><strong><?php echo h(nomeCurto($p['nome'])); ?></strong><br><span class="muted small mono"><?php echo h($p['rep'] ?? t('sem código')); ?></span></td>
        <td class="small"><?php echo h($p['cargo'] === 'OS' ? t('OS · núcleo {nucleo}', ['nucleo' => 'U' . $p['unidade'] . $turno . $p['nucleo']]) : $p['cargo'] . ($p['unidade'] ? ' · U' . $p['unidade'] : '')); ?></td>
        <?php foreach ($dias as $d):
            $sit = $escala['sit'][$turno . '|' . $g . '|' . $i][$d];
            $marca = '';
            if ($planoSemana && $p['rep'] && in_array($sit, ['TRABALHO', 'OVERLAP', 'FOLGA'], true)) {
                $naGrade = isset($noPlano[strtoupper($p['rep']) . '|' . $d . '|' . $turno]);
                if ($naGrade !== ($sit !== 'FOLGA')) {
                    $diferencas++;
                    $marca = ' <span class="tag laranja nao-imprimir" title="' . h($naGrade ? t('Na Grade ({versao}) a pessoa está escalada neste dia', ['versao' => escPlanoRotulo($planoSemana)]) : t('Na Grade ({versao}) a pessoa não está escalada neste dia', ['versao' => escPlanoRotulo($planoSemana)])) . '">' . h(t('≠ grade')) . '</span>';
                }
            } ?><td class="small"><?php echo $celula[$sit] . $marca; ?></td><?php endforeach; ?>
      </tr>
      <?php endforeach; ?>
      <tr><td colspan="2" class="muted small"><?php echo h(t('no turno')); ?></td>
      <?php foreach ($dias as $d): $n = $escala['presentes'][$turno][$g][$d] ?? null; ?>
        <td class="small <?php echo $n !== null && $n < $minimo($g) ? 'laranja' : ''; ?>"><?php echo $n === null ? '—' : h(t('{n} de {total}', ['n' => $n, 'total' => count($pessoas)])); ?></td>
      <?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
<?php endforeach; ?>

<?php if ($planoSemana): ?>
<div class="<?php echo $diferencas ? 'aviso' : 'aviso ok'; ?> nao-imprimir" role="status"><?php echo h($diferencas ? tn($diferencas, '{n} dia em que a Grade ({versao}) difere do rodízio (marca "≠ grade"). O que vale para postar é a Grade.', '{n} dias em que a Grade ({versao}) difere do rodízio (marca "≠ grade"). O que vale para postar é a Grade.', ['versao' => escPlanoRotulo($planoSemana)]) : t('A Grade ({versao}) segue o rodízio da liderança em todos os dias.', ['versao' => escPlanoRotulo($planoSemana)])); ?></div>
<?php endif; ?>

<section class="panel lid-om">
  <h2><?php echo h(t('Operational Manager · não segue escala')); ?></h2>
  <table>
    <thead><tr><th><?php echo h(t('Unidade')); ?></th><th><?php echo h(t('Pessoa')); ?></th><th><?php echo h(t('Código')); ?></th></tr></thead>
    <tbody>
    <?php foreach ($escala['om'] as $p): ?>
      <tr><td>U<?php echo (int) $p['unidade']; ?></td><td><?php echo h($p['nome']); ?></td><td class="mono small"><?php echo h($p['rep'] ?? t('sem código')); ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="muted small"><?php echo h(t('Ausência aprovada em Indisponibilidade aparece como "ausente". Antes da virada ({dia} às {hora}) vale a escala postada.', ['dia' => dataBr(escV4DiaVirada()), 'hora' => substr(escV4Param('v4_virada', '2026-11-01 05:00'), 11, 5)])); ?></p>
</section>
</div>
<script>
(function () {
  // PDF pela impressão do navegador; o título vira o nome do arquivo sugerido ao salvar.
  var titulo = document.title;
  document.getElementById('lid-pdf').addEventListener('click', function () {
    document.title = <?php echo json_encode(t('Escala da liderança {de} a {ate}', ['de' => date('d-m-Y', strtotime($dias[0])), 'ate' => date('d-m-Y', strtotime(end($dias)))])); ?>;
    window.print();
  });
  window.addEventListener('afterprint', function () { document.title = titulo; });
})();
</script>
