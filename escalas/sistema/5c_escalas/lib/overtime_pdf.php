<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/overtime_pdf.php
// PDF de anúncio do Overtime (pedido de 05/11), no padrão visual 5C (pedido de 08/11): papel timbrado claro,
// faixa marsala, águia e logo da 5C, Open Sans, como o PDF da Grade; por dia do calendário, bloco de 4 h e unidade, com os buffers em destaque no fim de cada
// unidade. Vai do turno escolhido ("a partir de": dia e turno) até o fim da semana dessa data (o T1 da
// segunda é o fim do turno D do domingo). A paginação é feita aqui (linhas de altura fixa), para
// o "CONTINUED" e o número da folha saírem certos na impressão do navegador (Salvar como PDF).
// Chamado por pages/horas.php com ?baixar=overtime&de=AAAA-MM-DD&turno=A|C|D (documento inteiro, sem o menu).
// ===============================================

const ESC_OT_BLOCO_HORA = ['T1' => ['01', '01h–05h'], 'T2' => ['05', '05h–09h'], 'T3' => ['09', '09h–13h'], 'T4' => ['13', '13h–17h'], 'T5' => ['17', '17h–21h'], 'T6' => ['21', '21h–01h']];
const ESC_OT_TURNO_HORA = ['A' => '05', 'C' => '13', 'D' => '21'];

// Itens do anúncio a partir de $desde (AAAA-MM-DDTHH:00:00): [semana => [dia => [linhas]]].
function escOvertimeItens(callable $vaziosDaSemana, callable $diaDoBloco, string $desde): array {
    $de = substr($desde, 0, 10);
    $seg1 = segundaDaSemana($de);
    $out = [];
    $planos = [];
    foreach ([$seg1] as $seg) { // só a semana da data escolhida (pedido de 05/11)
        [$plano, $postos, $buffers, $receita] = $vaziosDaSemana($seg);
        if (!$plano) {
            continue;
        }
        $planos[$seg] = $plano;
        $itens = [];
        foreach ($postos as $l) {
            $dia = $diaDoBloco($l['dia'], $l['turno'], $l['bloco']);
            if ($dia . 'T' . ESC_OT_BLOCO_HORA[$l['bloco']][0] . ':00:00' < $desde) {
                continue;
            }
            $itens[] = ['dia' => $dia, 'bloco' => $l['bloco'], 'u' => (int) $l['unidade'], 'buf' => 0,
                'rec' => (float) ($receita[$l['pagina_principal']][$l['turno']] ?? 0), 'txt' => (string) $l['pagina_principal']];
        }
        foreach ($buffers as $l) {
            $dia = $diaDoBloco($l['dia'], $l['turno'], $l['bloco']);
            if ($dia . 'T' . ESC_OT_BLOCO_HORA[$l['bloco']][0] . ':00:00' < $desde) {
                continue;
            }
            $itens[] = ['dia' => $dia, 'bloco' => $l['bloco'], 'u' => (int) $l['unidade'], 'buf' => 1, 'rec' => 0.0,
                'txt' => 'Buffer (' . (int) $l['falta'] . ' slot' . ((int) $l['falta'] === 1 ? '' : 's') . ')'];
        }
        $ordem = array_flip(array_keys(ESC_OT_BLOCO_HORA));
        usort($itens, static fn($a, $b) => [$a['dia'], $ordem[$a['bloco']], $a['u'], $a['buf'], -$a['rec'], $a['txt']]
            <=> [$b['dia'], $ordem[$b['bloco']], $b['u'], $b['buf'], -$b['rec'], $b['txt']]);
        foreach ($itens as $i) {
            $out[$seg][$i['dia']][] = $i;
        }
    }
    return [$out, $planos];
}

// Folhas: cada uma com os blocos que cabem (semana, dia com as linhas). Unidades de altura: linha = 1.
function escOvertimeFolhas(array $porSemana): array {
    $orcamento = 54.0;
    $custoSemana = ['primeira' => 4.3, 'cont' => 3.2];
    $custoDia = 3.6;
    $folhas = [];
    $folha = null;
    $usado = 0.0;
    $nova = static function (string $seg, bool $cont) use (&$folhas, &$folha, &$usado, $custoSemana): void {
        if ($folha !== null) {
            $folhas[] = $folha;
        }
        $folha = ['semana' => $seg, 'cont' => $cont, 'dias' => []];
        $usado = $cont ? $custoSemana['cont'] : $custoSemana['primeira'];
    };
    foreach ($porSemana as $seg => $dias) {
        $nova($seg, false);
        foreach ($dias as $dia => $linhas) {
            $contDia = false;
            $i = 0;
            while ($i < count($linhas)) {
                if ($usado + $custoDia + 1 > $orcamento) {
                    $nova($seg, true);
                }
                $usado += $custoDia;
                $cabe = max(1, (int) floor($orcamento - $usado));
                $pedaco = array_slice($linhas, $i, $cabe);
                $folha['dias'][] = ['dia' => $dia, 'cont' => $contDia, 'linhas' => $pedaco];
                $usado += count($pedaco);
                $i += count($pedaco);
                $contDia = true;
            }
        }
    }
    if ($folha !== null) {
        $folhas[] = $folha;
    }
    return $folhas;
}

function escOvertimePdf(callable $vaziosDaSemana, callable $diaDoBloco): void {
    $de = escDataValida($_GET['de'] ?? null) ?? date('Y-m-d');
    $turno = isset(ESC_OT_TURNO_HORA[$_GET['turno'] ?? '']) ? (string) $_GET['turno'] : 'A';
    $desde = $de . 'T' . ESC_OT_TURNO_HORA[$turno] . ':00:00';
    [$porSemana, $planos] = escOvertimeItens($vaziosDaSemana, $diaDoBloco, $desde);
    $folhas = escOvertimeFolhas($porSemana);
    $todosDias = [];
    foreach ($porSemana as $dias) {
        $todosDias = array_merge($todosDias, array_keys($dias));
    }
    $ini = $todosDias ? min($todosDias) : $de;
    $fim = $todosDias ? max($todosDias) : $de;
    $up = static fn(string $s): string => mb_strtoupper($s, 'UTF-8');
    $periodo = $up(date('d M', strtotime($ini))) . ' - ' . $up(date('d M Y', strtotime($fim)));
    $versoesEscala = implode(', ', array_map('escPlanoRotulo', $planos));
    $nomeArq = '5C_Overtime_Opportunities_' . date('d-m', strtotime($ini)) . '_a_' . date('d-m', strtotime($fim));
    $logo = '<img class="ot-logo" src="assets/marca/logo_5c_marsala.png" alt="5C">';
    $total = max(1, count($folhas));
    $rodape = static fn(int $n): string => '<footer class="ot-rodape"><span class="ot-num">' . $n . ' / ' . $total . '</span><span class="ot-regra">4-hour blocks &nbsp;•&nbsp; Up to 12 consecutive hours including the regular schedule</span><span class="ot-site">www.<strong>5ccompany</strong>.com</span></footer>';
    $timbre = '<span class="ot-faixa"></span><img class="ot-aguia" src="assets/marca/aguia_timbrado.png" alt="">';

    header('Content-Type: text/html; charset=utf-8');
    ?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>5C Overtime Opportunities</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700;800&amp;display=swap">
<style>
@page { size: A4 portrait; margin: 0; }
:root { --ot-marsala: #650637; --ot-marsala-2: #970250; --ot-claro: #f8f3f5; --ot-linha: #e6dce1; --ot-texto: #1a171d; --ot-suave: #67636d; --ot-z: #fbf8fa; }
* { box-sizing: border-box; }
html, body { margin: 0; background: #d9d4dc; }
body { font-family: "Open Sans", Arial, sans-serif; color: var(--ot-texto); -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.ot-folha { position: relative; width: 210mm; height: 297mm; margin: 6mm auto; background: #fff; overflow: hidden; padding: 12mm 14mm 0 24mm; break-after: page; }
.ot-folha:last-child { break-after: auto; }
.ot-faixa { position: absolute; left: 0; top: 0; bottom: 0; width: 9mm; background: var(--ot-marsala); }
.ot-aguia { position: absolute; right: -12mm; top: -6mm; width: 70mm; opacity: .55; pointer-events: none; }
.ot-topo { position: relative; display: flex; gap: 7mm; align-items: center; }
.ot-logo { width: 30mm; height: auto; display: block; }
.ot-topo h1 { margin: 0; font-size: 7mm; font-weight: 800; color: var(--ot-marsala); letter-spacing: .1mm; }
.ot-topo p { margin: 1.2mm 0 0; font-size: 3mm; color: var(--ot-suave); letter-spacing: .1mm; }
.ot-semana { position: relative; margin-top: 7mm; background: var(--ot-claro); border-left: 1.4mm solid var(--ot-marsala); border-radius: 0 2mm 2mm 0; padding: 3.6mm 5mm; }
.ot-semana h2 { margin: 0; font-size: 4.4mm; font-weight: 800; color: var(--ot-marsala); }
.ot-semana p { margin: 1.2mm 0 0; font-size: 3mm; color: var(--ot-suave); }
.ot-dia { position: relative; margin-top: 3.5mm; }
.ot-dia-barra { background: var(--ot-marsala); color: #fff; border-radius: 1.6mm 1.6mm 0 0; padding: 2.2mm 4mm; font-weight: 700; font-size: 3.3mm; letter-spacing: .15mm; }
.ot-cols, .ot-linha { display: grid; grid-template-columns: 52mm 24mm 1fr; padding: 0 4mm; }
.ot-cols { font-size: 2.8mm; font-weight: 700; color: var(--ot-marsala); background: var(--ot-claro); padding-top: 1.8mm; padding-bottom: 1.6mm; border: .2mm solid var(--ot-linha); border-top: 0; }
.ot-linhas { border: .2mm solid var(--ot-linha); border-top: 0; }
.ot-linha { height: 4.2mm; line-height: 4.2mm; font-size: 3.05mm; border-bottom: .2mm solid var(--ot-linha); }
.ot-linha:last-child { border-bottom: 0; }
.ot-linha:nth-child(even) { background: var(--ot-z); }
.ot-linha:nth-child(odd) { background: #fff; }
.ot-bloco { color: var(--ot-suave); }
.ot-u { font-weight: 700; }
.ot-u span { display: inline-block; line-height: 3.2mm; padding: 0 1.4mm; border-radius: .8mm; background: var(--ot-marsala); color: #fff; font-size: 2.6mm; }
.ot-pag { padding-left: 3mm; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ot-linha.buf .ot-pag { color: var(--ot-marsala-2); font-weight: 700; position: relative; }
.ot-linha.buf .ot-pag::before { content: ''; position: absolute; left: 0; top: 1.1mm; width: .9mm; height: 2mm; border-radius: .4mm; background: var(--ot-marsala-2); }
.ot-rodape { position: absolute; left: 24mm; right: 14mm; bottom: 8mm; display: flex; justify-content: space-between; align-items: baseline; gap: 6mm; font-size: 2.6mm; color: var(--ot-suave); }
.ot-regra { flex: 1; text-align: center; }
.ot-site { color: var(--ot-marsala); font-weight: 600; font-size: 2.8mm; }
.ot-vazio { position: relative; margin-top: 10mm; font-size: 4mm; color: var(--ot-suave); }
@media print { html, body { background: #fff; } .ot-folha { margin: 0; } }
</style></head><body>
<?php ob_start(); ?>
<?php if (!$folhas): ?>
<section class="ot-folha"><?php echo $timbre; ?>
  <div class="ot-topo"><?php echo $logo; ?><div><h1>OVERTIME OPPORTUNITIES</h1><p>SCHEDULE &nbsp;|&nbsp; <?php echo h($periodo); ?><?php echo ESC_DOC_TOKEN; ?></p></div></div>
  <p class="ot-vazio"><?php echo h('No open blocks from ' . date('D d/m/Y', strtotime($de)) . ' ' . ESC_OT_TURNO_HORA[$turno] . 'h' . ($planos ? '' : ': no schedule generated for this period yet') . '.'); ?></p>
  <?php echo $rodape(1); ?>
</section>
<?php endif; ?>
<?php foreach ($folhas as $n => $f): ?>
<section class="ot-folha"><?php echo $timbre; ?>
  <div class="ot-topo"><?php echo $logo; ?><div><h1>OVERTIME OPPORTUNITIES</h1>
    <p>SCHEDULE &nbsp;|&nbsp; <?php echo h($periodo); ?> &nbsp;|&nbsp; <?php echo h('PLAN ' . $versoesEscala); ?><?php echo ESC_DOC_TOKEN; ?></p></div></div>
  <div class="ot-semana">
    <h2>WEEK OF <?php echo h(date('m/d', strtotime($f['semana']))); ?><?php echo $f['cont'] ? ' &nbsp;/&nbsp; CONTINUED' : ''; ?></h2>
    <?php if (!$f['cont']): ?><p>Availability by date, 4-hour block and unit &nbsp;•&nbsp; Buffer slots listed separately</p><?php endif; ?>
  </div>
  <?php foreach ($f['dias'] as $d): ?>
  <div class="ot-dia">
    <div class="ot-dia-barra"><?php echo h($up(date('D', strtotime($d['dia']))) . ' · ' . $up(date('d M Y', strtotime($d['dia'])))); ?><?php echo $d['cont'] ? ' &nbsp;/&nbsp; CONTINUED' : ''; ?></div>
    <div class="ot-cols"><span>BLOCK</span><span>UNIT</span><span>PAGE / COVERAGE</span></div>
    <div class="ot-linhas">
    <?php foreach ($d['linhas'] as $l): ?>
      <div class="ot-linha<?php echo $l['buf'] ? ' buf' : ''; ?>"><span class="ot-bloco"><?php echo h($l['bloco'] . ' ' . ESC_OT_BLOCO_HORA[$l['bloco']][1]); ?></span><span class="ot-u u<?php echo (int) $l['u']; ?>"><span>U<?php echo (int) $l['u']; ?></span></span><span class="ot-pag"><?php echo h($l['txt']); ?></span></div>
    <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <?php echo $rodape($n + 1); ?>
</section>
<?php endforeach; ?>
<?php
    // Versão do documento (lib/documentos.php), em inglês como o resto do anúncio.
    $html = (string) ob_get_clean();
    $docOt = escDocVersao('overtime', 'de:' . $desde, $html);
    echo str_replace(ESC_DOC_TOKEN, $docOt['rotulo'] !== '' ? ' &nbsp;|&nbsp; VERSION ' . h($docOt['rotulo']) : '', $html);
?>
<?php echo escDocTituloJs($nomeArq . escDocSufixo($docOt)); ?>
<?php if (!empty($_GET['imprimir'])): ?><script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });</script><?php endif; ?>
</body></html>
<?php
}
