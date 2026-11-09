<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/graficos.php
// Gráficos em SVG montados no servidor, sem biblioteca externa (Análise, Capacity,
// Requisição de vagas). Cores nas classes do assets/analise.css (e quadro.css); cada
// marca leva <title>, que o assets/analise.js troca por uma dica que segue o mouse.
//
// Princípios (Storytelling com Dados, C. N. Knaflic, e a skill dataviz):
//   - o título diz a conclusão; a nota diz como ler;
//   - tudo em cinza e uma cor só para o que pede ação (classe "destaque");
//   - rótulo direto no fim da linha em vez de legenda solta; grade leve; um eixo só;
//   - cada gráfico tem a tabela por trás (escGrafTabela / "Ver tabela").
// ===============================================

function escGrafN(float $v): string {
    return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
}

function escGrafFmt(float $v): string {
    return num($v, abs($v - round($v)) > 1e-6 ? 1 : 0);
}

function escGrafCorta(string $s, int $n): string {
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($s, 0, $n, '…', 'UTF-8');
    }
    return strlen($s) > $n ? substr($s, 0, $n - 1) . '…' : $s;
}

// Domínio "redondo" do eixo e as marcas (passo 1, 2, 2,5 ou 5 × 10^k).
function escGrafEscala(float $min, float $max): array {
    if ($max - $min < 1e-9) {
        $max = $min + 1;
    }
    $bruto = ($max - $min) / 4;
    $pot = 10 ** floor(log10($bruto));
    $passo = 10 * $pot;
    foreach ([1, 2, 2.5, 5, 10] as $m) {
        if ($m * $pot >= $bruto - 1e-12) {
            $passo = $m * $pot;
            break;
        }
    }
    $lo = floor($min / $passo + 1e-9) * $passo;
    $hi = ceil($max / $passo - 1e-9) * $passo;
    $marcas = [];
    for ($i = 0, $q = (int) round(($hi - $lo) / $passo); $i <= $q; $i++) {
        $marcas[] = round($lo + $i * $passo, 6);
    }
    return [$lo, $hi, $marcas];
}

// Colunas agrupadas (e empilhadas). $grupos = [['rot' => linha|[linhas], 'barras' => [[
//   'rot' => rótulo sob a barra, 'seg' => [['v' => valor, 'cls' => classe]], 'alvo' => traço de meta,
//   'rotulo' => texto sobre a barra, 'rotCls' => classe do texto, 'tip' => dica]]]]
function escGrafColunas(array $grupos, array $o): string {
    $W = $o['w'] ?? 600;
    $H = $o['h'] ?? 250;
    $fmt = $o['fmt'] ?? 'escGrafFmt';
    $ml = empty($o['eixoY']) ? 44 : 60;
    $mr = 8;
    $mt = 18;
    $min = 0.0;
    $max = 0.0;
    $comRotBarra = false;
    $comRotulo = false;
    $linhasGrupo = 1;
    foreach ($grupos as $g) {
        $linhasGrupo = max($linhasGrupo, count((array) $g['rot']));
        foreach ($g['barras'] as $b) {
            $s = 0.0;
            foreach ($b['seg'] as $sg) {
                $s += (float) $sg['v'];
            }
            $max = max($max, $s, (float) ($b['alvo'] ?? 0));
            $min = min($min, $s);
            $comRotBarra = $comRotBarra || isset($b['rot']);
            $comRotulo = $comRotulo || isset($b['rotulo']);
        }
    }
    if ($comRotulo) {
        $folga = ($max - $min) * 0.08;
        $max += $folga;
        $min -= $min < 0 ? $folga : 0;
    }
    [$lo, $hi, $marcas] = escGrafEscala($min, $max);
    $mb = ($comRotBarra ? 18 : 6) + 14 * $linhasGrupo;
    $ph = $H - $mt - $mb;
    $pw = $W - $ml - $mr;
    $y = static fn(float $v): float => $mt + $ph * ($hi - $v) / ($hi - $lo);
    $out = '<svg class="graf" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . h($o['titulo'] ?? '') . '">';
    foreach ($marcas as $m) {
        $yy = escGrafN($y($m));
        $out .= '<line class="grade' . (abs($m) < 1e-9 ? ' zero' : '') . '" x1="' . $ml . '" x2="' . ($W - $mr) . '" y1="' . $yy . '" y2="' . $yy . '"/>'
            . '<text class="eixo" x="' . ($ml - 6) . '" y="' . $yy . '" dy="4" text-anchor="end">' . h($fmt($m)) . '</text>';
    }
    if (!empty($o['eixoY'])) {
        $cy = escGrafN($mt + $ph / 2);
        $out .= '<text class="eixo-titulo" x="12" y="' . $cy . '" transform="rotate(-90 12 ' . $cy . ')" text-anchor="middle">' . h($o['eixoY']) . '</text>';
    }
    $n = max(1, count($grupos));
    $gw = $pw / $n;
    $i = 0;
    foreach ($grupos as $g) {
        $k = max(1, count($g['barras']));
        $interno = $gw * 0.86;
        $passoB = $interno / $k;
        $bw = max(3.0, min($passoB - 3, (float) ($o['larguraMax'] ?? 40)));
        $gx = $ml + $gw * $i + ($gw - $interno) / 2;
        $j = 0;
        foreach ($g['barras'] as $b) {
            $cx = $gx + $passoB * ($j + 0.5);
            $x = $cx - $bw / 2;
            $out .= '<g class="marca">' . (!empty($b['tip']) ? '<title>' . h($b['tip']) . '</title>' : '')
                . '<rect class="hit" x="' . escGrafN($gx + $passoB * $j) . '" y="' . $mt . '" width="' . escGrafN($passoB) . '" height="' . escGrafN($ph) . '"/>';
            $acum = 0.0;
            foreach ($b['seg'] as $sg) {
                $v = (float) $sg['v'];
                if (abs($v) < 1e-9) {
                    continue;
                }
                $ya = $y($acum);
                $yb = $y($acum + $v);
                $acum += $v;
                $out .= '<rect class="seg ' . h($sg['cls']) . '" x="' . escGrafN($x) . '" y="' . escGrafN(min($ya, $yb)) . '" width="' . escGrafN($bw) . '" height="' . escGrafN(max(0.5, abs($ya - $yb))) . '" rx="2"/>';
            }
            $topo = $y(max(0.0, $acum));
            if (isset($b['alvo'])) {
                $ya = escGrafN($y((float) $b['alvo']));
                $out .= '<line class="alvo" x1="' . escGrafN($x - 3) . '" x2="' . escGrafN($x + $bw + 3) . '" y1="' . $ya . '" y2="' . $ya . '"/>';
                $topo = min($topo, $y((float) $b['alvo']));
            }
            if (isset($b['rotulo'])) {
                $ty = $acum < 0 ? $y($acum) + 14 : $topo - 5;
                $out .= '<text class="valor ' . h($b['rotCls'] ?? '') . '" x="' . escGrafN($cx) . '" y="' . escGrafN($ty) . '" text-anchor="middle">' . h($b['rotulo']) . '</text>';
            }
            $out .= '</g>';
            if (isset($b['rot'])) {
                $out .= '<text class="rot-barra" x="' . escGrafN($cx) . '" y="' . ($mt + $ph + 14) . '" text-anchor="middle">' . h($b['rot']) . '</text>';
            }
            $j++;
        }
        $li = 0;
        foreach ((array) $g['rot'] as $txt) {
            $out .= '<text class="rot-grupo" x="' . escGrafN($ml + $gw * ($i + 0.5)) . '" y="' . ($mt + $ph + ($comRotBarra ? 18 : 6) + 12 + 14 * $li) . '" text-anchor="middle">' . h($txt) . '</text>';
            $li++;
        }
        $i++;
    }
    return $out . '</svg>';
}

// Barras horizontais (empilhadas), uma por linha. $linhas = [['rot' => rótulo, 'seg' => [...],
//   'alvo' => traço de meta, 'valor' => texto à direita, 'valCls' => classe, 'tip' => dica, 'sep' => espaço antes]]
function escGrafBarrasH(array $linhas, array $o): string {
    $W = $o['w'] ?? 600;
    $fmt = $o['fmt'] ?? 'escGrafFmt';
    $esq = $o['esq'] ?? 96;
    $dir = $o['dir'] ?? 92;
    $rh = 26;
    $bh = 14;
    $mt = 6;
    $sepH = 10;
    $max = 0.0;
    $nSep = 0;
    foreach ($linhas as $l) {
        $s = 0.0;
        foreach ($l['seg'] as $sg) {
            $s += (float) $sg['v'];
        }
        $max = max($max, $s, (float) ($l['alvo'] ?? 0));
        $nSep += empty($l['sep']) ? 0 : 1;
    }
    [$lo, $hi, $marcas] = escGrafEscala(0.0, $max);
    $ph = count($linhas) * $rh + $nSep * $sepH;
    $H = $mt + $ph + 22 + (empty($o['eixoX']) ? 0 : 16);
    $pw = $W - $esq - $dir;
    $x = static fn(float $v): float => $esq + $pw * ($v - $lo) / ($hi - $lo);
    $out = '<svg class="graf" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . h($o['titulo'] ?? '') . '">';
    foreach ($marcas as $m) {
        $xx = escGrafN($x($m));
        $out .= '<line class="grade' . (abs($m) < 1e-9 ? ' zero' : '') . '" x1="' . $xx . '" x2="' . $xx . '" y1="' . $mt . '" y2="' . ($mt + $ph) . '"/>'
            . '<text class="eixo" x="' . $xx . '" y="' . ($mt + $ph + 15) . '" text-anchor="middle">' . h($fmt($m)) . '</text>';
    }
    if (!empty($o['eixoX'])) {
        $out .= '<text class="eixo-titulo" x="' . escGrafN($esq + $pw / 2) . '" y="' . ($H - 4) . '" text-anchor="middle">' . h($o['eixoX']) . '</text>';
    }
    $yy = $mt;
    foreach ($linhas as $l) {
        if (!empty($l['sep'])) {
            $yy += $sepH;
        }
        $cy = $yy + $rh / 2;
        $out .= '<g class="marca">' . (!empty($l['tip']) ? '<title>' . h($l['tip']) . '</title>' : '')
            . '<rect class="hit" x="0" y="' . $yy . '" width="' . $W . '" height="' . $rh . '"/>'
            . '<text class="rot-linha ' . h($l['rotCls'] ?? '') . '" x="' . ($esq - 8) . '" y="' . $cy . '" dy="4" text-anchor="end">' . h(escGrafCorta((string) $l['rot'], $o['corte'] ?? 16)) . '</text>';
        $acum = 0.0;
        foreach ($l['seg'] as $sg) {
            $v = (float) $sg['v'];
            if ($v < 1e-9) {
                continue;
            }
            $xa = $x($acum);
            $acum += $v;
            $out .= '<rect class="seg ' . h($sg['cls']) . '" x="' . escGrafN($xa) . '" y="' . ($cy - $bh / 2) . '" width="' . escGrafN(max(0.5, $x($acum) - $xa)) . '" height="' . $bh . '" rx="2"/>';
        }
        if (isset($l['alvo'])) {
            $xa = escGrafN($x((float) $l['alvo']));
            $out .= '<line class="alvo" x1="' . $xa . '" x2="' . $xa . '" y1="' . ($cy - $bh / 2 - 4) . '" y2="' . ($cy + $bh / 2 + 4) . '"/>';
        }
        if (isset($l['valor'])) {
            $out .= '<text class="valor ' . h($l['valCls'] ?? '') . '" x="' . ($esq + $pw + 8) . '" y="' . $cy . '" dy="4">' . h($l['valor']) . '</text>';
        }
        $out .= '</g>';
        $yy += $rh;
    }
    return $out . '</svg>';
}

// Linhas no tempo, um eixo só. $rotulos = rótulos do eixo X (um por ponto, ex.: datas já formatadas);
// $series = [['nome' => 'Precisa', 'cls' => 'destaque'|'cinza'|..., 'v' => [valor|null por ponto],
//   'rotulo' => texto no fim da linha (rótulo direto), 'tracejada' => bool, 'grossa' => bool]];
// $o: 'titulo', 'w', 'h', 'fmt', 'dicas' => [texto por ponto] (coluna invisível com a dica do ponto),
//   'marcos' => [['i' => índice, 'txt' => texto curto]] (linha vertical fina), 'zero' => bool (inclui o 0),
//   'cadaX' => mostrar 1 rótulo do eixo X a cada n pontos (padrão: cabe ~8).
function escGrafLinhas(array $rotulos, array $series, array $o): string {
    $W = $o['w'] ?? 680;
    $H = $o['h'] ?? 260;
    $fmt = $o['fmt'] ?? 'escGrafFmt';
    $n = count($rotulos);
    if ($n === 0) {
        return '';
    }
    $comRotulo = (bool) array_filter($series, static fn($s) => !empty($s['rotulo']));
    $ml = 44;
    $mr = $comRotulo ? ($o['dir'] ?? 120) : 12;
    $mt = 14;
    $mb = !empty($o['marcos']) ? 42 : 26;
    $min = !empty($o['zero']) ? 0.0 : INF;
    $max = !empty($o['zero']) ? 0.0 : -INF;
    foreach ($series as $s) {
        foreach ($s['v'] as $v) {
            if ($v !== null) {
                $min = min($min, (float) $v);
                $max = max($max, (float) $v);
            }
        }
    }
    if (!is_finite($min)) {
        return '';
    }
    [$lo, $hi, $marcas] = escGrafEscala($min, $max);
    $ph = $H - $mt - $mb;
    $pw = $W - $ml - $mr;
    $x = static fn(int $i): float => $ml + ($n === 1 ? $pw / 2 : $pw * $i / ($n - 1));
    $y = static fn(float $v): float => $mt + $ph * ($hi - $v) / ($hi - $lo);
    $out = '<svg class="graf graf-linhas" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . h($o['titulo'] ?? '') . '">';
    foreach ($marcas as $m) {
        $yy = escGrafN($y($m));
        $out .= '<line class="grade' . (abs($m) < 1e-9 ? ' zero' : '') . '" x1="' . $ml . '" x2="' . ($W - $mr) . '" y1="' . $yy . '" y2="' . $yy . '"/>'
            . '<text class="eixo" x="' . ($ml - 6) . '" y="' . $yy . '" dy="4" text-anchor="end">' . h($fmt($m)) . '</text>';
    }
    $cada = $o['cadaX'] ?? max(1, (int) ceil($n / 8));
    for ($i = 0; $i < $n; $i += $cada) {
        $out .= '<text class="eixo" x="' . escGrafN($x($i)) . '" y="' . ($mt + $ph + 16) . '" text-anchor="middle">' . h((string) $rotulos[$i]) . '</text>';
    }
    foreach ($o['marcos'] ?? [] as $mc) {
        $xx = escGrafN($x((int) $mc['i']));
        $out .= '<line class="marco" x1="' . $xx . '" x2="' . $xx . '" y1="' . $mt . '" y2="' . ($mt + $ph) . '"/>'
            . '<text class="marco-txt" x="' . $xx . '" y="' . ($mt + $ph + 32) . '" text-anchor="middle">' . h((string) $mc['txt']) . '</text>';
    }
    // Rótulos diretos no fim de cada linha, afastados para não encostar um no outro.
    $fins = [];
    foreach ($series as $k => $s) {
        $pts = [];
        $ultimo = null;
        foreach ($s['v'] as $i => $v) {
            if ($v === null) {
                continue;
            }
            $pts[] = escGrafN($x($i)) . ',' . escGrafN($y((float) $v));
            $ultimo = [$i, (float) $v];
        }
        if (!$pts) {
            continue;
        }
        $cls = 'serie ' . ($s['cls'] ?? 'cinza') . (!empty($s['tracejada']) ? ' tracejada' : '') . (!empty($s['grossa']) ? ' grossa' : '');
        $out .= '<polyline class="' . h($cls) . '" points="' . implode(' ', $pts) . '"/>';
        $out .= '<circle class="ponta ' . h($s['cls'] ?? 'cinza') . '" cx="' . escGrafN($x($ultimo[0])) . '" cy="' . escGrafN($y($ultimo[1])) . '" r="4"/>';
        if (!empty($s['rotulo'])) {
            $fins[$k] = ['y' => $y($ultimo[1]), 'x' => $x($ultimo[0]), 'txt' => (string) $s['rotulo'], 'cls' => $s['cls'] ?? 'cinza'];
        }
    }
    uasort($fins, static fn($a, $b) => $a['y'] <=> $b['y']);
    $antes = -INF;
    foreach ($fins as $f) {
        $yy = max($f['y'], $antes + 15);
        $antes = $yy;
        $out .= '<text class="rot-serie ' . h($f['cls']) . '" x="' . escGrafN($f['x'] + 8) . '" y="' . escGrafN($yy) . '" dy="4">' . h($f['txt']) . '</text>';
    }
    // Coluna invisível por ponto, com a dica de todas as séries naquele ponto.
    if (!empty($o['dicas'])) {
        $larg = $n === 1 ? $pw : $pw / ($n - 1);
        foreach ($o['dicas'] as $i => $txt) {
            $out .= '<g class="marca"><title>' . h((string) $txt) . '</title><rect class="hit" x="' . escGrafN($x($i) - $larg / 2) . '" y="' . $mt . '" width="' . escGrafN($larg) . '" height="' . escGrafN($ph) . '"/>'
                . '<line class="mira" x1="' . escGrafN($x($i)) . '" x2="' . escGrafN($x($i)) . '" y1="' . $mt . '" y2="' . ($mt + $ph) . '"/></g>';
        }
    }
    return $out . '</svg>';
}

// Legenda em HTML (lê bem em tela estreita). $itens = [classe => texto]; a classe "alvo" vira traço.
function escGrafLegenda(array $itens): string {
    $o = '<div class="legenda-graf">';
    foreach ($itens as $cls => $txt) {
        $o .= '<span><i class="cor ' . h($cls) . '"></i>' . h($txt) . '</span>';
    }
    return $o . '</div>';
}

// $depois: HTML já seguro que fica dentro do cartão, abaixo do gráfico (ex.: escGrafTabela).
function escGrafFigura(string $titulo, string $nota, string $leg, string $svg, string $cls = '', string $depois = ''): string {
    return '<figure class="graf-cartao ' . h($cls) . '"><figcaption><strong>' . h($titulo) . '</strong>'
        . ($nota !== '' ? '<span class="muted small">' . h($nota) . '</span>' : '') . '</figcaption>'
        . $leg . '<div class="graf-rolagem">' . $svg . '</div>' . $depois . '</figure>';
}

// A tabela por trás do gráfico, recolhida. $cab = [texto]; $linhas = [[célula, ...]]; células já em HTML seguro
// quando $html = true (senão passam por h()). Colunas a partir da 2ª alinham à direita.
function escGrafTabela(string $resumo, array $cab, array $linhas, bool $html = false): string {
    $o = '<details class="ver-tabela"><summary class="small">' . h($resumo) . '</summary><div class="tabela-rolagem"><table><thead><tr>';
    foreach ($cab as $i => $c) {
        $o .= '<th' . ($i ? ' class="num"' : '') . '>' . h($c) . '</th>';
    }
    $o .= '</tr></thead><tbody>';
    foreach ($linhas as $l) {
        $o .= '<tr>';
        foreach (array_values($l) as $i => $c) {
            $o .= '<td' . ($i ? ' class="num"' : '') . '>' . ($html ? $c : h((string) $c)) . '</td>';
        }
        $o .= '</tr>';
    }
    return $o . '</tbody></table></div></details>';
}
