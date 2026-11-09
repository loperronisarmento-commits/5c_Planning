<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/xlsx.php
// Planilha .xlsx simples, sem biblioteca externa (só ZipArchive, o mesmo do .zip dos CSVs).
// Uma aba: linhas de título, cabeçalho com filtro e congelado, e as linhas de dados.
//
// $colunas: [['titulo' => texto, 'largura' => número, 'estilo' => texto|inteiro|pct|dinheiro|pontos|nota]]
// $linhas:  valores na ordem das colunas (null = vazio) ou ['v' => valor, 'cor' => verde|laranja].
// Texto vai como inlineStr: nunca é lido como fórmula.
// ===============================================

const ESC_XLSX_ESTILOS = ['titulo' => 1, 'info' => 2, 'cabecalho' => 3, 'texto' => 4, 'inteiro' => 5, 'pct' => 6, 'dinheiro' => 7, 'pontos' => 8, 'nota' => 9, 'verde' => 10, 'laranja' => 11];

function escXlsxColuna(int $n): string {
    $s = '';
    for ($n++; $n > 0; $n = (int) (($n - 1) / 26)) {
        $s = chr(65 + ($n - 1) % 26) . $s;
    }
    return $s;
}

function escXlsxTexto($v): string {
    $limpo = preg_replace('~[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}]~u', '', (string) $v);
    return htmlspecialchars((string) $limpo, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function escXlsxCelula(string $ref, $v, int $estilo): string {
    if ($v === null || $v === '') {
        return '<c r="' . $ref . '" s="' . $estilo . '"/>';
    }
    if (is_int($v) || is_float($v)) {
        return '<c r="' . $ref . '" s="' . $estilo . '"><v>' . $v . '</v></c>';
    }
    return '<c r="' . $ref . '" s="' . $estilo . '" t="inlineStr"><is><t xml:space="preserve">' . escXlsxTexto($v) . '</t></is></c>';
}

function escXlsxEstilosXml(): string {
    $xf = static fn(int $fmt, int $font, int $fill, int $borda, string $alin = '') => '<xf numFmtId="' . $fmt . '" fontId="' . $font . '" fillId="' . $fill . '" borderId="' . $borda . '" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"' . ($alin !== '' ? ' applyAlignment="1"><alignment ' . $alin . '/></xf>' : '/>');
    $xfs = [
        $xf(0, 0, 0, 0), $xf(0, 1, 0, 0), $xf(0, 2, 0, 0),
        $xf(0, 3, 2, 0, 'horizontal="center" vertical="center" wrapText="1"'),
        $xf(0, 0, 0, 1), $xf(1, 0, 0, 1), $xf(9, 0, 0, 1), $xf(3, 0, 0, 1), $xf(164, 0, 0, 1), $xf(164, 4, 0, 1),
        $xf(0, 4, 3, 1, 'horizontal="center"'), $xf(0, 4, 4, 1, 'horizontal="center"'),
    ];
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="+0;\-0;0"/></numFmts>'
        . '<fonts count="5"><font><sz val="10"/><name val="Calibri"/></font><font><b/><sz val="15"/><color rgb="FF650637"/><name val="Calibri"/></font><font><sz val="10"/><color rgb="FF67636D"/><name val="Calibri"/></font><font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><b/><sz val="10"/><name val="Calibri"/></font></fonts>'
        . '<fills count="5"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF970250"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFD9F2E3"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFFCE8D5"/></patternFill></fill></fills>'
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left/><right/><top/><bottom style="thin"><color rgb="FFE6DCE1"/></bottom><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="' . count($xfs) . '">' . implode('', $xfs) . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
}

// Grava o .xlsx em $destino. $topo: linhas acima do cabeçalho (a primeira é o título). $fixas: colunas congeladas.
function escXlsxGravar(string $destino, string $aba, array $topo, array $colunas, array $linhas, int $fixas = 0): void {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException(t('O PHP do servidor não tem ZipArchive. Não deu para gerar o .xlsx.'));
    }
    $aba = mb_substr(preg_replace('~[\[\]:*?/\\\\\']~', ' ', $aba), 0, 31);
    $linhaCab = count($topo) + 1;
    $ultimaCol = escXlsxColuna(max(0, count($colunas) - 1));
    $linhaFim = $linhaCab + count($linhas);
    $faixa = 'A' . $linhaCab . ':' . $ultimaCol . $linhaFim;

    $dados = '';
    foreach ($topo as $i => $txt) {
        $n = $i + 1;
        $dados .= '<row r="' . $n . '">' . escXlsxCelula('A' . $n, $txt, $i === 0 ? ESC_XLSX_ESTILOS['titulo'] : ESC_XLSX_ESTILOS['info']) . '</row>';
    }
    $dados .= '<row r="' . $linhaCab . '" ht="42" customHeight="1">';
    foreach ($colunas as $c => $col) {
        $dados .= escXlsxCelula(escXlsxColuna($c) . $linhaCab, $col['titulo'], ESC_XLSX_ESTILOS['cabecalho']);
    }
    $dados .= '</row>';
    foreach ($linhas as $i => $linha) {
        $n = $linhaCab + 1 + $i;
        $dados .= '<row r="' . $n . '">';
        foreach ($colunas as $c => $col) {
            $v = $linha[$c] ?? null;
            $estilo = ESC_XLSX_ESTILOS[$col['estilo'] ?? 'texto'];
            if (is_array($v)) {
                $estilo = ESC_XLSX_ESTILOS[$v['cor'] ?? ''] ?? $estilo;
                $v = $v['v'] ?? null;
            }
            $dados .= escXlsxCelula(escXlsxColuna($c) . $n, $v, $estilo);
        }
        $dados .= '</row>';
    }

    $cols = '';
    foreach ($colunas as $c => $col) {
        $cols .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . (float) ($col['largura'] ?? 10) . '" customWidth="1"/>';
    }
    $pane = $fixas > 0
        ? '<pane xSplit="' . $fixas . '" ySplit="' . $linhaCab . '" topLeftCell="' . escXlsxColuna($fixas) . ($linhaCab + 1) . '" activePane="bottomRight" state="frozen"/>'
        : '<pane ySplit="' . $linhaCab . '" topLeftCell="A' . ($linhaCab + 1) . '" activePane="bottomLeft" state="frozen"/>';
    $folha = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><dimension ref="A1:' . $ultimaCol . $linhaFim . '"/>'
        . '<sheetViews><sheetView showGridLines="0" workbookViewId="0">' . $pane . '</sheetView></sheetViews><sheetFormatPr defaultRowHeight="15"/>'
        . '<cols>' . $cols . '</cols><sheetData>' . $dados . '</sheetData><autoFilter ref="' . $faixa . '"/>'
        . '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/><pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>';
    $refFiltro = "'" . escXlsxTexto($aba) . "'!\$A\$" . $linhaCab . ':$' . $ultimaCol . '$' . $linhaFim;
    $livro = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . escXlsxTexto($aba) . '" sheetId="1" r:id="rId1"/></sheets>'
        . '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">' . $refFiltro . '</definedName></definedNames></workbook>';

    $zip = new ZipArchive();
    if ($zip->open($destino, ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException(t('Não deu para gerar o .xlsx. Tente de novo.'));
    }
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', $livro);
    $zip->addFromString('xl/styles.xml', escXlsxEstilosXml());
    $zip->addFromString('xl/worksheets/sheet1.xml', $folha);
    $zip->close();
}

// Monta e envia para o navegador (o arquivo temporário sempre é apagado).
function escXlsxBaixar(string $arquivo, string $aba, array $topo, array $colunas, array $linhas, int $fixas = 0): void {
    $tmp = tempnam(sys_get_temp_dir(), 'esx');
    try {
        escXlsxGravar($tmp, $aba, $topo, $colunas, $linhas, $fixas);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . preg_replace('~[^A-Za-z0-9._-]+~', '_', $arquivo) . '"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
    } finally {
        @unlink($tmp);
    }
}
