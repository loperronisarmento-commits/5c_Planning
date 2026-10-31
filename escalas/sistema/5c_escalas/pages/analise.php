<?php
// ===============================================
// ARQUIVO: 5c_escalas/pages/analise.php
// Painel de análise para o WFM: a escala da semana (horas necessárias ×
// previstas, buffers, escalados, absenteísmo) e a demanda (fluxo previsto
// das páginas, funil do CRM), com o cruzamento das duas por unidade e turno.
// Só lê.
// ===============================================

$semanas = [];
for ($i = -1; $i < 4; $i++) {
    $semanas[] = date('Y-m-d', strtotime(segundaDaSemana(date('Y-m-d')) . " +{$i} week"));
}
$seg = segundaDaSemana(is_string($_GET['semana'] ?? null) && preg_match('~^\d{4}-\d{2}-\d{2}$~', $_GET['semana']) ? $_GET['semana'] : $semanas[2]);
$aba = in_array($_GET['aba'] ?? '', ['escala', 'demanda', 'cruzamento'], true) ? $_GET['aba'] : 'escala';
$unSel = (int) (is_string($_GET['unidade'] ?? null) ? $_GET['unidade'] : 0);
$unSel = in_array($unSel, [1, 2, 3], true) ? $unSel : 0;
$pagina5c = escV4Param('v4_overlap_pagina', '5C Schedule');
$turnos = ['A' => '05–13', 'C' => '13–21', 'D' => '21–05'];
$pct = static fn(float $v): string => pct($v);
$int = static fn(float $v): string => num($v, 0);
$unidadeDaCarteira = static fn(string $c): int => (int) substr($c, 1, 1);

// ---------- Capacity: necessidade de buffer e absenteísmo por unidade e turno ----------
$cap = [];
foreach (escQ('SELECT unidade, turno, postos, titulares, taxa_abs, taxa_af, presentes_dia, buffer, precisa, tem, saldo FROM `5c_planning`.`fc_necessidade_estrutura`
                WHERE data_calculo = (SELECT MAX(data_calculo) FROM `5c_planning`.`fc_necessidade_estrutura`)') as $l) {
    $cap[(int) $l['unidade']][$l['turno']] = $l;
}

// ---------- Escala da semana (plano atual) ----------
$plano = escPlanoAtual($seg);
$porDT = [];     // [dia][turno][unidade] => métricas
$repUnidade = [];
if ($plano) {
    $fim = escMaisDias($seg, 6);
    foreach (escQ('SELECT UPPER(rep_code) AS rep, unidade FROM ' . escT('esc_colaborador_config') . " WHERE funcao = 'EQUITE' AND unidade IS NOT NULL AND vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)", [$fim, $fim]) as $l) {
        $repUnidade[$l['rep']] = (int) $l['unidade'];
    }
    $linhas = escQ("SELECT a.turno, IF(a.bloco = 'T1', DATE_SUB(a.dia, INTERVAL 1 DAY), a.dia) AS dt, a.posto_id, UPPER(a.rep_code) AS rep, a.origem, a.pagina,
                           (a.motivo LIKE 'hora extra%') AS he, p.unidade
                      FROM " . escT('esc_plano_alocacao') . ' a LEFT JOIN ' . escT('esc_posto') . ' p ON p.id = a.posto_id
                     WHERE a.plano_id = ?', [(int) $plano['id']]);
    $vistos = [];
    foreach ($linhas as $l) {
        $d = substr($l['dt'], 0, 10);
        $t = $l['turno'];
        $u = $l['unidade'] !== null ? (int) $l['unidade'] : ($repUnidade[$l['rep']] ?? 0);
        if ($u === 0) {
            continue;
        }
        $m = &$porDT[$d][$t][$u];
        $m = ($m ?? []) + ['postos' => [], 'vazios' => [], 'escalados' => [], 'buffers' => [], 'overlap' => [], 'he' => []];
        if ($l['posto_id'] !== null) {
            $m['postos'][$l['posto_id']] = true;
            if ($l['rep'] === null || $l['rep'] === '') {
                $m['vazios'][$l['posto_id']] = true;
            } else {
                $m['escalados'][$l['rep']] = true;
            }
        } elseif ($l['pagina'] === $pagina5c && $l['rep']) {
            if ($l['origem'] === 'BUFFER') {
                $m['buffers'][$l['rep']] = true;
            } elseif ($l['origem'] === 'OVERLAP') {
                $m['overlap'][$l['rep']] = true;
            }
        }
        if ($l['he'] && $l['rep']) {
            $m['he'][$l['rep']] = true;
        }
        unset($m);
    }
}
// Absenteísmo realizado (28 dias, até ontem) por turno, sem a falha administrativa (escAbsRealizado).
$absReal = array_map(static fn($v) => (float) $v, array_filter(escAbsRealizado('turno', date('Y-m-d', strtotime('-28 days')), date('Y-m-d', strtotime('-1 day')))['taxa'], static fn($v) => $v !== null));

// Linhas da aba Escala: dia × turno (somando as unidades do filtro).
$linhasEscala = [];
$tot = ['postos' => 0, 'vazios' => 0, 'escalados' => 0, 'buffers' => 0, 'buf_precisa' => 0, 'overlap' => 0, 'he' => 0, 'faltas' => 0.0];
foreach ($plano ? escDiasDaSemana($seg) : [] as $d) {
    foreach (array_keys($turnos) as $t) {
        $r = ['dia' => $d, 'turno' => $t, 'postos' => 0, 'vazios' => 0, 'escalados' => 0, 'buffers' => 0, 'buf_precisa' => 0, 'overlap' => 0, 'he' => 0, 'faltas' => 0.0];
        foreach ([1, 2, 3] as $u) {
            if ($unSel && $u !== $unSel) {
                continue;
            }
            $m = $porDT[$d][$t][$u] ?? null;
            if (!$m) {
                continue;
            }
            $taxa = (float) ($cap[$u][$t]['taxa_abs'] ?? 0) + (float) ($cap[$u][$t]['taxa_af'] ?? 0);
            $r['postos'] += count($m['postos']);
            $r['vazios'] += count($m['vazios']);
            $r['escalados'] += count($m['escalados']);
            $r['buffers'] += count($m['buffers']);
            $r['buf_precisa'] += (int) ceil((float) ($cap[$u][$t]['presentes_dia'] ?? 0) - 1e-9);
            $r['overlap'] += count($m['overlap']);
            $r['he'] += count($m['he']);
            $r['faltas'] += (count($m['escalados']) + count($m['buffers'])) * $taxa;
        }
        if ($r['postos'] === 0 && $r['escalados'] === 0) {
            continue;
        }
        foreach ($tot as $k => $v) {
            $tot[$k] += $r[$k];
        }
        $linhasEscala[] = $r;
    }
}
$hNec = ($tot['postos'] + $tot['buf_precisa']) * 8;
$hPrev = ($tot['escalados'] + $tot['buffers']) * 8;

// ---------- Demanda ----------
$carga = [];
$dcCarga = escQ1('SELECT MAX(data_calculo) AS dc FROM `5c_planning`.`fc_pagina_carga`')['dc'] ?? null;
if ($dcCarga) {
    foreach (escQ('SELECT pagina, turno, carteira, modelo, regra, fans_h FROM `5c_planning`.`fc_pagina_carga` WHERE data_calculo = ?', [$dcCarga]) as $l) {
        if ($unSel && $unidadeDaCarteira((string) $l['carteira']) !== $unSel) {
            continue;
        }
        $carga[$l['pagina']]['carteira'] = $l['carteira'];
        $carga[$l['pagina']]['regra'] = $l['regra'];
        $carga[$l['pagina']][$l['turno']] = (float) $l['fans_h'];
    }
}
$receita = [];
if ($carga && in_array($aba, ['demanda', 'cruzamento'], true)) {
    $n = max(1, (int) escV4Param('v4_receita_dias', '28'));
    foreach (escQ("SELECT CREATOR_NAME AS pagina, SUM(CASE WHEN Turno IN ('T2','T3') THEN US_AMOUNT ELSE 0 END) AS a,
                          SUM(CASE WHEN Turno IN ('T4','T5') THEN US_AMOUNT ELSE 0 END) AS c, SUM(CASE WHEN Turno IN ('T6','T1') THEN US_AMOUNT ELSE 0 END) AS d
                     FROM `5c_wfm_db_mis`.`tab_statement_interval`
                    WHERE Date >= DATE_SUB(CURDATE(), INTERVAL {$n} DAY) AND Date < CURDATE() AND STATUS = 'complete' AND CREATOR_NAME IN (" . escIn(array_keys($carga)) . ')
                    GROUP BY CREATOR_NAME', array_keys($carga)) as $r) {
        $receita[$r['pagina']] = ['A' => (float) $r['a'] / $n, 'C' => (float) $r['c'] / $n, 'D' => (float) $r['d'] / $n];
    }
}
// SRs escalados por página e turno na semana (média por dia), para cruzar com o fluxo.
$srPagina = [];
if ($plano && $carga) {
    foreach (escQ("SELECT a.pagina, a.turno, COUNT(DISTINCT CONCAT(a.rep_code, '|', IF(a.bloco = 'T1', DATE_SUB(a.dia, INTERVAL 1 DAY), a.dia))) AS pessoa_dias
                     FROM " . escT('esc_plano_alocacao') . " a WHERE a.plano_id = ? AND a.rep_code IS NOT NULL AND a.posto_id IS NOT NULL AND a.papel = 'REGULAR'
                    GROUP BY a.pagina, a.turno", [(int) $plano['id']]) as $l) {
        $srPagina[$l['pagina']][$l['turno']] = (int) $l['pessoa_dias'] / 7;
    }
}
// Previsão diária do Capacity (SRs por turno) e o funil do CRM.
$previsao = escQ('SELECT dia, a, c, d, total, paginas FROM `5c_planning`.`fc_escala_diaria` WHERE data_calculo = (SELECT MAX(data_calculo) FROM `5c_planning`.`fc_escala_diaria`) AND dia >= CURDATE() ORDER BY dia');
$funil = $aba === 'demanda' ? escQ("SELECT pc.id, st.name AS etapa, st.slug, c.name AS cliente, t.letter AS tier, ct.start_date, ct.value, ct.currency
                     FROM `5c_crm`.`pipeline_cards` pc
                     JOIN `5c_crm`.`pipeline_stages` st ON st.id = pc.stage_id
                     LEFT JOIN `5c_crm`.`clients` c ON c.id = pc.client_id
                     LEFT JOIN `5c_crm`.`tiers` t ON t.id = c.tier_id
                     LEFT JOIN `5c_crm`.`contracts` ct ON ct.id = pc.contract_id
                    WHERE st.slug IN ('pipeline', 'onboarding') OR (st.slug = 'active' AND ct.start_date >= CURDATE() - INTERVAL 7 DAY)
                    ORDER BY st.position, ct.start_date") : [];
$prevFunil = [];
if ($funil) {
    foreach (escQ('SELECT crm_card_id, pagina, fans_h_a, fans_h_c, fans_h_d, probabilidade, data_prevista, destino_time FROM ' . escT('esc_pagina_previsao')) as $pv) {
        $prevFunil[(int) $pv['crm_card_id']] = $pv;
    }
}

// ---------- Cruzamento: unidade × turno ----------
$cruz = [];
foreach ([1, 2, 3] as $u) {
    if ($unSel && $u !== $unSel) {
        continue;
    }
    foreach (array_keys($turnos) as $t) {
        $fans = 0.0;
        $fat = 0.0;
        foreach ($carga as $pg => $c) {
            if ($unidadeDaCarteira((string) $c['carteira']) === $u) {
                $fans += $c[$t] ?? 0;
                $fat += $receita[$pg][$t] ?? 0;
            }
        }
        $dias = 0;
        $esc = 0;
        $buf = 0;
        $vaz = 0;
        $pst = 0;
        foreach ($porDT as $d => $pt) {
            if (!isset($pt[$t][$u])) {
                continue;
            }
            $dias++;
            $esc += count($pt[$t][$u]['escalados']);
            $buf += count($pt[$t][$u]['buffers']);
            $vaz += count($pt[$t][$u]['vazios']);
            $pst += count($pt[$t][$u]['postos']);
        }
        $taxa = (float) ($cap[$u][$t]['taxa_abs'] ?? 0) + (float) ($cap[$u][$t]['taxa_af'] ?? 0);
        $escDia = $dias ? $esc / $dias : 0;
        $presentes = $escDia * (1 - $taxa);
        $cruz[] = ['u' => $u, 't' => $t, 'fans' => $fans, 'fat' => $fat, 'postos' => $dias ? $pst / $dias : 0, 'esc' => $escDia, 'buf' => $dias ? $buf / $dias : 0,
            'vazios' => $dias ? $vaz / $dias : 0, 'taxa' => $taxa, 'presentes' => $presentes, 'fans_sr' => $presentes > 0 ? $fans / $presentes : null,
            'tem' => (int) ($cap[$u][$t]['tem'] ?? 0), 'precisa' => (int) ($cap[$u][$t]['precisa'] ?? 0)];
    }
}

// ---------- Gráficos: SVG montado aqui, cores nas classes do assets/analise.css ----------
// Sem biblioteca externa. Cada marca leva <title> (dica nativa); o analise.js troca por uma dica mais rápida.
$svgN = static fn(float $v): string => rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
$fmtEixo = static fn(float $v): string => num($v, abs($v - round($v)) > 1e-6 ? 1 : 0);
$corta = static function (string $s, int $n): string {
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($s, 0, $n, '…', 'UTF-8');
    }
    return strlen($s) > $n ? substr($s, 0, $n - 1) . '…' : $s;
};
// Domínio "redondo" do eixo e as marcas (passo 1, 2, 2,5 ou 5 × 10^k).
$escalaEixo = static function (float $min, float $max): array {
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
};
// Colunas agrupadas (e empilhadas). $grupos = [['rot' => linha|[linhas], 'barras' => [[
//   'rot' => rótulo sob a barra, 'seg' => [['v' => valor, 'cls' => classe]], 'alvo' => traço de meta,
//   'rotulo' => texto sobre a barra, 'rotCls' => classe do texto, 'tip' => dica]]]]
$grafColunas = static function (array $grupos, array $o) use ($svgN, $fmtEixo, $escalaEixo): string {
    $W = $o['w'] ?? 600;
    $H = $o['h'] ?? 250;
    $fmt = $o['fmt'] ?? $fmtEixo;
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
    [$lo, $hi, $marcas] = $escalaEixo($min, $max);
    $mb = ($comRotBarra ? 18 : 6) + 14 * $linhasGrupo;
    $ph = $H - $mt - $mb;
    $pw = $W - $ml - $mr;
    $y = static fn(float $v): float => $mt + $ph * ($hi - $v) / ($hi - $lo);
    $out = '<svg class="graf" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . h($o['titulo'] ?? '') . '">';
    foreach ($marcas as $m) {
        $yy = $svgN($y($m));
        $out .= '<line class="grade' . (abs($m) < 1e-9 ? ' zero' : '') . '" x1="' . $ml . '" x2="' . ($W - $mr) . '" y1="' . $yy . '" y2="' . $yy . '"/>'
            . '<text class="eixo" x="' . ($ml - 6) . '" y="' . $yy . '" dy="4" text-anchor="end">' . h($fmt($m)) . '</text>';
    }
    if (!empty($o['eixoY'])) {
        $cy = $svgN($mt + $ph / 2);
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
                . '<rect class="hit" x="' . $svgN($gx + $passoB * $j) . '" y="' . $mt . '" width="' . $svgN($passoB) . '" height="' . $svgN($ph) . '"/>';
            $acum = 0.0;
            foreach ($b['seg'] as $sg) {
                $v = (float) $sg['v'];
                if (abs($v) < 1e-9) {
                    continue;
                }
                $ya = $y($acum);
                $yb = $y($acum + $v);
                $acum += $v;
                $out .= '<rect class="seg ' . h($sg['cls']) . '" x="' . $svgN($x) . '" y="' . $svgN(min($ya, $yb)) . '" width="' . $svgN($bw) . '" height="' . $svgN(max(0.5, abs($ya - $yb))) . '" rx="2"/>';
            }
            $topo = $y(max(0.0, $acum));
            if (isset($b['alvo'])) {
                $ya = $svgN($y((float) $b['alvo']));
                $out .= '<line class="alvo" x1="' . $svgN($x - 3) . '" x2="' . $svgN($x + $bw + 3) . '" y1="' . $ya . '" y2="' . $ya . '"/>';
                $topo = min($topo, $y((float) $b['alvo']));
            }
            if (isset($b['rotulo'])) {
                $ty = $acum < 0 ? $y($acum) + 14 : $topo - 5;
                $out .= '<text class="valor ' . h($b['rotCls'] ?? '') . '" x="' . $svgN($cx) . '" y="' . $svgN($ty) . '" text-anchor="middle">' . h($b['rotulo']) . '</text>';
            }
            $out .= '</g>';
            if (isset($b['rot'])) {
                $out .= '<text class="rot-barra" x="' . $svgN($cx) . '" y="' . ($mt + $ph + 14) . '" text-anchor="middle">' . h($b['rot']) . '</text>';
            }
            $j++;
        }
        $li = 0;
        foreach ((array) $g['rot'] as $txt) {
            $out .= '<text class="rot-grupo" x="' . $svgN($ml + $gw * ($i + 0.5)) . '" y="' . ($mt + $ph + ($comRotBarra ? 18 : 6) + 12 + 14 * $li) . '" text-anchor="middle">' . h($txt) . '</text>';
            $li++;
        }
        $i++;
    }
    return $out . '</svg>';
};
// Barras horizontais (empilhadas), uma por linha. $linhas = [['rot' => rótulo, 'seg' => [...],
//   'alvo' => traço de meta, 'valor' => texto à direita, 'valCls' => classe, 'tip' => dica, 'sep' => espaço antes]]
$grafBarrasH = static function (array $linhas, array $o) use ($svgN, $fmtEixo, $escalaEixo, $corta): string {
    $W = $o['w'] ?? 600;
    $fmt = $o['fmt'] ?? $fmtEixo;
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
    [$lo, $hi, $marcas] = $escalaEixo(0.0, $max);
    $ph = count($linhas) * $rh + $nSep * $sepH;
    $H = $mt + $ph + 22 + (empty($o['eixoX']) ? 0 : 16);
    $pw = $W - $esq - $dir;
    $x = static fn(float $v): float => $esq + $pw * ($v - $lo) / ($hi - $lo);
    $out = '<svg class="graf" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . h($o['titulo'] ?? '') . '">';
    foreach ($marcas as $m) {
        $xx = $svgN($x($m));
        $out .= '<line class="grade' . (abs($m) < 1e-9 ? ' zero' : '') . '" x1="' . $xx . '" x2="' . $xx . '" y1="' . $mt . '" y2="' . ($mt + $ph) . '"/>'
            . '<text class="eixo" x="' . $xx . '" y="' . ($mt + $ph + 15) . '" text-anchor="middle">' . h($fmt($m)) . '</text>';
    }
    if (!empty($o['eixoX'])) {
        $out .= '<text class="eixo-titulo" x="' . $svgN($esq + $pw / 2) . '" y="' . ($H - 4) . '" text-anchor="middle">' . h($o['eixoX']) . '</text>';
    }
    $yy = $mt;
    foreach ($linhas as $l) {
        if (!empty($l['sep'])) {
            $yy += $sepH;
        }
        $cy = $yy + $rh / 2;
        $out .= '<g class="marca">' . (!empty($l['tip']) ? '<title>' . h($l['tip']) . '</title>' : '')
            . '<rect class="hit" x="0" y="' . $yy . '" width="' . $W . '" height="' . $rh . '"/>'
            . '<text class="rot-linha ' . h($l['rotCls'] ?? '') . '" x="' . ($esq - 8) . '" y="' . $cy . '" dy="4" text-anchor="end">' . h($corta((string) $l['rot'], $o['corte'] ?? 16)) . '</text>';
        $acum = 0.0;
        foreach ($l['seg'] as $sg) {
            $v = (float) $sg['v'];
            if ($v < 1e-9) {
                continue;
            }
            $xa = $x($acum);
            $acum += $v;
            $out .= '<rect class="seg ' . h($sg['cls']) . '" x="' . $svgN($xa) . '" y="' . ($cy - $bh / 2) . '" width="' . $svgN(max(0.5, $x($acum) - $xa)) . '" height="' . $bh . '" rx="2"/>';
        }
        if (isset($l['alvo'])) {
            $xa = $svgN($x((float) $l['alvo']));
            $out .= '<line class="alvo" x1="' . $xa . '" x2="' . $xa . '" y1="' . ($cy - $bh / 2 - 4) . '" y2="' . ($cy + $bh / 2 + 4) . '"/>';
        }
        if (isset($l['valor'])) {
            $out .= '<text class="valor ' . h($l['valCls'] ?? '') . '" x="' . ($esq + $pw + 8) . '" y="' . $cy . '" dy="4">' . h($l['valor']) . '</text>';
        }
        $out .= '</g>';
        $yy += $rh;
    }
    return $out . '</svg>';
};
// Legenda em HTML (lê bem em tela estreita). $itens = [classe => texto]; a classe "alvo" vira traço.
$legenda = static function (array $itens): string {
    $o = '<div class="legenda-graf">';
    foreach ($itens as $cls => $txt) {
        $o .= '<span><i class="cor ' . h($cls) . '"></i>' . h($txt) . '</span>';
    }
    return $o . '</div>';
};
$figura = static function (string $titulo, string $nota, string $leg, string $svg, string $cls = ''): string {
    return '<figure class="graf-cartao ' . h($cls) . '"><figcaption><strong>' . h($titulo) . '</strong>'
        . ($nota !== '' ? '<span class="muted small">' . h($nota) . '</span>' : '') . '</figcaption>'
        . $leg . '<div class="graf-rolagem">' . $svg . '</div></figure>';
};
$legTurnos = [];
foreach ($turnos as $t => $faixa) {
    $legTurnos['t-' . strtolower($t)] = $t . ' ' . $faixa;
}
$diaSemana = static fn(string $d): string => escDiaSemanaCurto((int) date('w', strtotime($d)));
$rotDia = static fn(string $d): string => escData($d, true);
$rotGrupo = static fn(string $d): array => [escDiaSemanaCurto((int) date('w', strtotime($d))), escData($d)];

// ---------- dados dos gráficos (só rearranjo do que já foi lido acima) ----------
$gHoras = $gPostos = $gBuffers = $gSaldo = [];
$porDia = [];
foreach ($linhasEscala as $r) {
    $porDia[$r['dia']][$r['turno']] = $r;
}
foreach ($porDia as $d => $pt) {
    $necPosto = 0;
    $necBuf = 0;
    $prevH = 0;
    $bPostos = $bBuf = $bSaldo = [];
    foreach (array_keys($turnos) as $t) {
        if (!isset($pt[$t])) {
            continue;
        }
        $r = $pt[$t];
        $necPosto += $r['postos'] * 8;
        $necBuf += $r['buf_precisa'] * 8;
        $prevH += ($r['escalados'] + $r['buffers']) * 8;
        $saldo = $r['escalados'] + $r['buffers'] - $r['faltas'] - $r['postos'];
        $cls = 't-' . strtolower($t);
        $rd = t('{dia} · turno {turno}', ['dia' => $rotDia($d), 'turno' => $t]);
        $bPostos[] = ['rot' => $t, 'seg' => [['v' => $r['postos'] - $r['vazios'], 'cls' => $cls], ['v' => $r['vazios'], 'cls' => 'falta']],
            'rotulo' => $r['vazios'] ? (string) $r['vazios'] : null, 'rotCls' => 'laranja',
            'tip' => $rd . "\n" . t('Postos: {n}', ['n' => $r['postos']]) . "\n" . t('Sem ninguém: {n}', ['n' => $r['vazios']]) . "\n" . t('SRs em posto: {n}', ['n' => $r['escalados']])];
        $bBuf[] = ['rot' => $t, 'seg' => [['v' => $r['buffers'], 'cls' => $cls]], 'alvo' => $r['buf_precisa'],
            'rotulo' => $r['buffers'] < $r['buf_precisa'] ? '−' . ($r['buf_precisa'] - $r['buffers']) : null, 'rotCls' => 'laranja',
            'tip' => $rd . "\n" . t('Buffers escalados: {n}', ['n' => $r['buffers']]) . "\n" . t('Capacity pede: {n}', ['n' => $r['buf_precisa']]) . "\n" . t('Overlap: {o} · hora extra: {he}', ['o' => $r['overlap'], 'he' => $r['he']])];
        $bSaldo[] = ['rot' => $t, 'seg' => [['v' => $saldo, 'cls' => $saldo < 0 ? 'neg' : 'pos']],
            'rotulo' => $saldo < 0 ? num($saldo, 1) : null, 'rotCls' => 'vermelho',
            'tip' => $rd . "\n" . t('Escalados {e} + buffers {b} − faltas esp. {f} − postos {p}', ['e' => $r['escalados'], 'b' => $r['buffers'], 'f' => num($r['faltas'], 1), 'p' => $r['postos']]) . "\n" . t('Saldo esperado: {n}', ['n' => num($saldo, 1)])];
    }
    $nec = $necPosto + $necBuf;
    $tipH = $rotDia($d) . "\n" . t('Necessárias: {nec} h ({posto} de posto + {buffer} de buffer)', ['nec' => $int($nec), 'posto' => $int($necPosto), 'buffer' => $int($necBuf)])
        . "\n" . ($nec ? t('Previstas: {n} h ({pct})', ['n' => $int($prevH), 'pct' => $pct($prevH / $nec)]) : t('Previstas: {n} h', ['n' => $int($prevH)]));
    $gHoras[] = ['rot' => $rotGrupo($d), 'barras' => [
        ['seg' => [['v' => $nec, 'cls' => 'neutro']], 'tip' => $tipH],
        ['seg' => [['v' => $prevH, 'cls' => $prevH < $nec ? 'falta' : 'ok']], 'tip' => $tipH,
            'rotulo' => $nec ? pct($prevH / $nec, 0) : null, 'rotCls' => $prevH < $nec ? 'laranja' : 'verde'],
    ]];
    $gPostos[] = ['rot' => $rotGrupo($d), 'barras' => $bPostos];
    $gBuffers[] = ['rot' => $rotGrupo($d), 'barras' => $bBuf];
    $gSaldo[] = ['rot' => $rotGrupo($d), 'barras' => $bSaldo];
}
// Taxas do Capacity por turno (média das unidades do filtro, ponderada pelos titulares).
$capTurno = [];
foreach (array_keys($turnos) as $t) {
    $peso = $sAbs = $sAf = $mAbs = $mAf = 0.0;
    $qtd = 0;
    foreach ($cap as $u => $pt) {
        if (($unSel && $u !== $unSel) || !isset($pt[$t])) {
            continue;
        }
        $w = (float) $pt[$t]['titulares'];
        $peso += $w;
        $sAbs += $w * (float) $pt[$t]['taxa_abs'];
        $sAf += $w * (float) $pt[$t]['taxa_af'];
        $mAbs += (float) $pt[$t]['taxa_abs'];
        $mAf += (float) $pt[$t]['taxa_af'];
        $qtd++;
    }
    if ($qtd) {
        $capTurno[$t] = $peso > 0 ? ['abs' => $sAbs / $peso, 'af' => $sAf / $peso] : ['abs' => $mAbs / $qtd, 'af' => $mAf / $qtd];
    }
}
$lAbs = [];
foreach (array_keys($turnos) as $t) {
    $cls = 't-' . strtolower($t);
    $c = $capTurno[$t] ?? null;
    $sep = (bool) $lAbs;
    if (isset($absReal[$t])) {
        $acima = $c && $absReal[$t] > $c['abs'];
        $lAbs[] = ['rot' => t('{turno} realizado', ['turno' => $t]), 'seg' => [['v' => $absReal[$t] * 100, 'cls' => $cls]], 'sep' => $sep,
            'valor' => $pct($absReal[$t]), 'valCls' => $acima ? 'laranja' : '',
            'tip' => t('Turno {turno} · realizado (28 dias, todas as unidades)', ['turno' => $t]) . "\n" . t('Faltas ÷ blocos escalados: {p}', ['p' => $pct($absReal[$t])]) . ($c ? "\n" . t('Capacity (absenteísmo): {p}', ['p' => $pct($c['abs'])]) : '')];
        $sep = false;
    }
    if ($c) {
        $lAbs[] = ['rot' => $t . ' Capacity', 'seg' => [['v' => $c['abs'] * 100, 'cls' => 'neutro'], ['v' => $c['af'] * 100, 'cls' => 'neutro-2']], 'sep' => $sep,
            'valor' => $pct($c['abs'] + $c['af']),
            'tip' => t('Turno {turno} · Capacity', ['turno' => $t]) . "\n" . t('Absenteísmo {abs} + falha adm. {af} = {total}', ['abs' => $pct($c['abs']), 'af' => $pct($c['af']), 'total' => $pct($c['abs'] + $c['af'])])];
    }
}
$lQuadro = [];
foreach ([1, 2, 3] as $u) {
    if (($unSel && $u !== $unSel) || !isset($cap[$u])) {
        continue;
    }
    $primeiro = true;
    foreach (array_keys($turnos) as $t) {
        if (!isset($cap[$u][$t])) {
            continue;
        }
        $c = $cap[$u][$t];
        $s = (int) $c['saldo'];
        $lQuadro[] = ['rot' => 'U' . $u . ' · ' . $t, 'seg' => [['v' => (int) $c['tem'], 'cls' => 't-' . strtolower($t)]], 'alvo' => (int) $c['precisa'],
            'sep' => $primeiro && (bool) $lQuadro, 'valor' => (int) $c['tem'] . ' / ' . (int) $c['precisa'] . ' (' . ($s > 0 ? '+' : '') . $s . ')', 'valCls' => $s < 0 ? 'vermelho' : 'verde',
            'tip' => t('U{u} · turno {turno}', ['u' => $u, 'turno' => $t]) . "\n" . t('Tem {tem} · precisa {precisa} · saldo {saldo}', ['tem' => (int) $c['tem'], 'precisa' => (int) $c['precisa'], 'saldo' => $s])
                . "\n" . t('Postos {postos} · titulares {titulares} · buffer {buffer}', ['postos' => (int) $c['postos'], 'titulares' => (int) $c['titulares'], 'buffer' => (int) $c['buffer']])
                . "\n" . t('Absenteísmo {abs} · falha adm. {af} · buffers presentes/dia {pres}', ['abs' => $pct((float) $c['taxa_abs']), 'af' => $pct((float) $c['taxa_af']), 'pres' => num((float) $c['presentes_dia'], 1)])];
        $primeiro = false;
    }
}

// Demanda: previsão dos próximos 21 dias e as páginas com mais fluxo / faturamento.
$prev21 = array_slice($previsao, 0, 21);
$gPrev = [];
if ($prev21) {
    $totais = array_map(static fn($l) => (int) $l['total'], $prev21);
    $marcar = [0 => true, count($prev21) - 1 => true, array_search(max($totais), $totais, true) => true, array_search(min($totais), $totais, true) => true];
    foreach ($prev21 as $i => $l) {
        $gPrev[] = ['rot' => $rotGrupo($l['dia']), 'barras' => [[
            'seg' => [['v' => (int) $l['a'], 'cls' => 't-a'], ['v' => (int) $l['c'], 'cls' => 't-c'], ['v' => (int) $l['d'], 'cls' => 't-d']],
            'rotulo' => isset($marcar[$i]) ? (string) (int) $l['total'] : null,
            'tip' => dataBr($l['dia'], true) . "\nA " . (int) $l['a'] . ' · C ' . (int) $l['c'] . ' · D ' . (int) $l['d'] . "\n" . tn((int) $l['paginas'], 'Total {total} SRs · {n} página', 'Total {total} SRs · {n} páginas', ['total' => (int) $l['total']]),
        ]]];
    }
}
uasort($carga, static fn($a, $b) => (($b['A'] ?? 0) + ($b['C'] ?? 0) + ($b['D'] ?? 0)) <=> (($a['A'] ?? 0) + ($a['C'] ?? 0) + ($a['D'] ?? 0)));
$topN = 15;
$tipPagina = static function (string $pg, array $c) use ($turnos, $receita, $srPagina, $int): string {
    $tip = t('{pagina} · carteira {carteira}', ['pagina' => $pg, 'carteira' => $c['carteira'] ?? '—']);
    foreach (array_keys($turnos) as $t) {
        $tip .= "\n" . t('{turno}: {fans} fãs/h · US$ {us}/dia · {srs} SRs/dia', ['turno' => $t, 'fans' => num($c[$t] ?? 0, 1), 'us' => isset($receita[$pg]) ? $int($receita[$pg][$t]) : '—', 'srs' => num($srPagina[$pg][$t] ?? 0, 1)]);
    }
    return $tip;
};
$lFluxo = [];
foreach (array_slice($carga, 0, $topN, true) as $pg => $c) {
    $semSr = [];
    foreach (array_keys($turnos) as $t) {
        if ($plano && ($c[$t] ?? 0) > 0 && !isset($srPagina[$pg][$t])) {
            $semSr[] = $t;
        }
    }
    $lFluxo[] = ['rot' => (string) $pg, 'rotCls' => $semSr ? 'laranja' : '',
        'seg' => [['v' => $c['A'] ?? 0, 'cls' => 't-a'], ['v' => $c['C'] ?? 0, 'cls' => 't-c'], ['v' => $c['D'] ?? 0, 'cls' => 't-d']],
        'valor' => $semSr ? t('{v} · sem SR {turnos}', ['v' => num(($c['A'] ?? 0) + ($c['C'] ?? 0) + ($c['D'] ?? 0), 1), 'turnos' => implode('/', $semSr)]) : num(($c['A'] ?? 0) + ($c['C'] ?? 0) + ($c['D'] ?? 0), 1), 'valCls' => $semSr ? 'laranja' : '',
        'tip' => $tipPagina((string) $pg, $c) . ($semSr ? "\n" . t('Sem SR regular escalado em {turnos}', ['turnos' => implode(', ', $semSr)]) : '')];
}
$lReceita = [];
if ($receita) {
    $rec = $receita;
    uasort($rec, static fn($a, $b) => ($b['A'] + $b['C'] + $b['D']) <=> ($a['A'] + $a['C'] + $a['D']));
    foreach (array_slice($rec, 0, $topN, true) as $pg => $r) {
        $lReceita[] = ['rot' => (string) $pg, 'seg' => [['v' => $r['A'], 'cls' => 't-a'], ['v' => $r['C'], 'cls' => 't-c'], ['v' => $r['D'], 'cls' => 't-d']],
            'valor' => 'US$ ' . $int($r['A'] + $r['C'] + $r['D']), 'tip' => $tipPagina((string) $pg, $carga[$pg] ?? [])];
    }
}
// Funil em cartões, por etapa (mesmos números da tabela).
$funilEtapas = [];
foreach ($funil as $c) {
    $pv = $prevFunil[(int) $c['id']] ?? null;
    $fans = $pv ? [(float) $pv['fans_h_a'], (float) $pv['fans_h_c'], (float) $pv['fans_h_d']] : null;
    $srs = $fans ? array_map(static fn($v) => ceil($v / 28) * 1.5, $fans) : null;
    if (!isset($funilEtapas[$c['etapa']])) {
        $funilEtapas[$c['etapa']] = ['cards' => [], 'srs' => [0.0, 0.0, 0.0]];
    }
    $funilEtapas[$c['etapa']]['cards'][] = ['c' => $c, 'pv' => $pv, 'fans' => $fans, 'srs' => $srs];
    foreach ($srs ?: [] as $k => $v) {
        $funilEtapas[$c['etapa']]['srs'][$k] += $v;
    }
}

// Cruzamento: mapa de calor (fãs/h por presente) e presentes × SRs para 28 fãs/h.
$nivelMapa = static function (?float $v): string {
    if ($v === null) {
        return 'm-sem';
    }
    $x = $v / 28;
    return $x <= 0.5 ? 'm-1' : ($x <= 0.75 ? 'm-2' : ($x <= 1 ? 'm-3' : ($x <= 1.25 ? 'm-4' : 'm-5')));
};
$mapa = [];
$lPresentes = [];
$uAnt = null;
foreach ($cruz as $r) {
    $mapa[$r['u']][$r['t']] = $r;
    $precisaSr = $r['fans'] / 28;
    $lPresentes[] = ['rot' => 'U' . $r['u'] . ' · ' . $r['t'], 'seg' => [['v' => $r['presentes'], 'cls' => 't-' . strtolower($r['t'])]], 'alvo' => $precisaSr,
        'sep' => $uAnt !== null && $uAnt !== $r['u'], 'valor' => num($r['presentes'], 1) . ' / ' . num($precisaSr, 1),
        'valCls' => $r['fans'] > 0 && $r['presentes'] < $precisaSr ? 'vermelho' : '',
        'tip' => t('U{u} · turno {turno}', ['u' => $r['u'], 'turno' => $r['t']]) . "\n" . t('Presentes esperados/dia: {n}', ['n' => num($r['presentes'], 1)]) . "\n" . t('SRs para 28 fãs/h: {n} ({fans} fãs/h ÷ 28)', ['n' => num($precisaSr, 1), 'fans' => num($r['fans'], 0)])
            . "\n" . t('Postos/dia {postos} · SRs em posto/dia {esc} · sem ninguém/dia {vazios}', ['postos' => num($r['postos'], 1), 'esc' => num($r['esc'], 1), 'vazios' => num($r['vazios'], 1)])
            . "\n" . t('Buffers/dia {buf} · faltas {taxa}', ['buf' => num($r['buf'], 1), 'taxa' => $pct($r['taxa'])])];
    $uAnt = $r['u'];
}
$params = ['semana' => $seg, 'unidade' => $unSel ?: null];
$params = array_filter($params, static fn($v) => $v !== null);
$versao = static fn(string $arq): string => (string) (@filemtime(__DIR__ . '/../assets/' . $arq) ?: time());
$rotRegra = ['EXCLUSIVA' => t('exclusiva'), 'ASSISTENTE_FIXO' => t('assistente_fixo')];
?>
<link rel="stylesheet" href="<?php echo h(MODULE_URL); ?>/assets/analise.css?v=<?php echo h($versao('analise.css')); ?>">
<div class="analise">
<header class="cabecalho">
  <span class="eyebrow"><?php echo h(t('Análise')); ?></span>
  <h1><?php echo h(t('Análise')); ?></h1>
  <p class="muted"><?php echo h(t('Horas necessárias × previstas, buffers, escalados e absenteísmo da escala da semana; fluxo previsto das páginas e o funil do CRM; e o cruzamento das duas por unidade e turno. Só leitura. Passe o mouse nas barras para ver os números; cada bloco tem a tabela completa em "Ver tabela".')); ?></p>
</header>

<section class="panel">
  <div class="linha-titulo">
    <div class="chips">
      <?php foreach (['escala' => t('Escala'), 'demanda' => t('Demanda'), 'cruzamento' => t('Escala × demanda')] as $k => $rot): ?>
        <a class="chip-filtro <?php echo $aba === $k ? 'ativo' : ''; ?>" href="<?php echo h(link_tela('analise', $params + ['aba' => $k])); ?>"><?php echo h($rot); ?></a>
      <?php endforeach; ?>
    </div>
    <form method="get" action="<?php echo h(MODULE_URL); ?>/index.php" class="acoes">
      <input type="hidden" name="p" value="analise"><input type="hidden" name="aba" value="<?php echo h($aba); ?>">
      <select name="semana" onchange="this.form.submit()" aria-label="<?php echo h(t('Semana')); ?>" data-sem-busca>
        <?php foreach ($semanas as $s): ?><option value="<?php echo h($s); ?>" <?php echo $s === $seg ? 'selected' : ''; ?>><?php echo h(t('{ini} a {fim}', ['ini' => dataBr($s), 'fim' => dataBr(escMaisDias($s, 6))])); ?></option><?php endforeach; ?>
      </select>
      <select name="unidade" onchange="this.form.submit()" aria-label="<?php echo h(t('Unidade')); ?>">
        <option value=""><?php echo h(t('Todas as unidades')); ?></option>
        <?php foreach ([1, 2, 3] as $u): ?><option value="<?php echo $u; ?>" <?php echo $unSel === $u ? 'selected' : ''; ?>><?php echo h(t('Unidade {n}', ['n' => $u])); ?></option><?php endforeach; ?>
      </select>
    </form>
  </div>
  <span class="muted small"><?php echo t('Escala: {escala} · Capacity de {dia}', ['escala' => '<strong>' . h($plano ? escPlanoRotulo($plano) . ' · ' . strtolower(escRotulo('status_plano', $plano['status'])) : t('nenhuma gerada para esta semana')) . '</strong>', 'dia' => h(dataBr($dcCarga))]); ?></span>
</section>

<?php if ($aba === 'escala'): ?>
<section class="kpis">
  <div class="kpi"><span class="muted"><?php echo h(t('Horas necessárias')); ?></span><strong><?php echo $int($hNec); ?> h</strong><span class="muted small"><?php echo h(t('{posto} h de posto + {buffer} h de buffer (Capacity)', ['posto' => $int($tot['postos'] * 8), 'buffer' => $int($tot['buf_precisa'] * 8)])); ?></span></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Horas previstas')); ?></span><strong class="<?php echo $hPrev < $hNec ? 'laranja' : 'verde'; ?>"><?php echo $int($hPrev); ?> h</strong><span class="muted small"><?php echo h(t('{pct} do necessário · {buffer} h de buffer', ['pct' => $hNec ? $pct($hPrev / $hNec) : '—', 'buffer' => $int($tot['buffers'] * 8)])); ?></span></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Postos sem ninguém')); ?></span><strong class="<?php echo $tot['vazios'] ? 'laranja' : 'verde'; ?>"><?php echo (int) $tot['vazios']; ?></strong><span class="muted small"><?php echo h(t('de {n} posto-dias · {pct}', ['n' => (int) $tot['postos'], 'pct' => $tot['postos'] ? $pct($tot['vazios'] / $tot['postos']) : '—'])); ?></span></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Buffers')); ?></span><strong><?php echo h(t('{tem} de {precisa}', ['tem' => (int) $tot['buffers'], 'precisa' => (int) $tot['buf_precisa']])); ?></strong><span class="muted small"><?php echo h(t('pessoa-turnos escalados × o que o Capacity pede')); ?></span></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Faltas esperadas')); ?></span><strong><?php echo num($tot['faltas'], 1); ?></strong><span class="muted small"><?php echo h(t('pessoa-turnos na semana (absenteísmo + falha adm. do Capacity)')); ?></span></div>
  <div class="kpi"><span class="muted"><?php echo h(t('Absenteísmo realizado (28 dias)')); ?></span><strong><?php echo $absReal ? h(implode(' · ', array_map(static fn($t) => $t . ' ' . $pct($absReal[$t] ?? 0), array_keys($turnos)))) : '—'; ?></strong><span class="muted small"><?php echo h(t('faltas ÷ blocos escalados, sem falha administrativa')); ?></span></div>
</section>
<section class="panel">
  <h2><?php echo h(t('Por dia e turno')); ?></h2>
  <p class="muted small"><?php echo h(t('Horas = pessoa-turnos × 8 h. Previstas = SRs em posto + buffers de plantão (Overlap e hora extra à parte). Saldo esperado = escalados e buffers menos as faltas esperadas, menos os postos.')); ?></p>
  <?php if (!$linhasEscala): ?><p class="muted"><?php echo h(t('Gere a escala desta semana para ver a análise.')); ?></p><?php else: ?>
  <div class="graficos">
    <?php echo $figura(t('Horas necessárias × previstas'), t('Por dia, somando os turnos. O número é o % do necessário que a escala cobre.'),
        $legenda(['neutro' => t('Necessárias (posto + buffer)'), 'ok' => t('Previstas, cobre'), 'falta' => t('Previstas, abaixo')]),
        $grafColunas($gHoras, ['titulo' => t('Horas necessárias e previstas por dia'), 'eixoY' => t('horas'), 'larguraMax' => 30])); ?>
    <?php echo $figura(t('Postos e postos sem ninguém'), t('Altura = postos do turno; laranja = posto sem SR. O número é quantos ficaram vazios.'),
        $legenda($legTurnos + ['falta' => t('Sem ninguém')]),
        $grafColunas($gPostos, ['titulo' => t('Postos por dia e turno, com os sem ninguém'), 'eixoY' => t('postos')])); ?>
    <?php echo $figura(t('Buffers: escalados × Capacity pede'), t('Barra = buffers escalados; traço = o que o Capacity pede. O número é o que falta.'),
        $legenda($legTurnos + ['alvo' => t('Capacity pede')]),
        $grafColunas($gBuffers, ['titulo' => t('Buffers escalados e pedidos por dia e turno'), 'eixoY' => t('pessoas')])); ?>
    <?php echo $figura(t('Saldo esperado'), t('Escalados + buffers − faltas esperadas − postos. Abaixo de zero falta gente no turno.'),
        $legenda(['pos' => t('Sobra'), 'neg' => t('Falta gente')]),
        $grafColunas($gSaldo, ['titulo' => t('Saldo esperado por dia e turno'), 'eixoY' => t('pessoas')])); ?>
  </div>
  <details class="ver-tabela"><summary class="botao"><?php echo h(t('Ver tabela')); ?></summary>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th><?php echo h(t('Dia')); ?></th><th><?php echo h(t('Turno')); ?></th><th class="num"><?php echo h(t('Postos')); ?></th><th class="num"><?php echo h(t('Sem ninguém')); ?></th><th class="num"><?php echo h(t('SRs em posto')); ?></th><th class="num"><?php echo h(t('Buffers (precisa)')); ?></th><th class="num"><?php echo h(t('Overlap')); ?></th><th class="num"><?php echo h(t('Hora extra')); ?></th><th class="num"><?php echo h(t('Horas nec.')); ?></th><th class="num"><?php echo h(t('Horas prev.')); ?></th><th class="num"><?php echo h(t('Faltas esp.')); ?></th><th class="num"><?php echo h(t('Saldo esp.')); ?></th></tr></thead>
    <tbody>
    <?php foreach ($linhasEscala as $r): $saldo = $r['escalados'] + $r['buffers'] - $r['faltas'] - $r['postos']; ?>
      <tr>
        <td class="nowrap"><?php echo h($rotDia($r['dia'])); ?></td>
        <td><span class="chip chip-<?php echo strtolower($r['turno']); ?>"><?php echo h($r['turno']); ?></span></td>
        <td class="num"><?php echo (int) $r['postos']; ?></td>
        <td class="num <?php echo $r['vazios'] ? 'laranja' : ''; ?>"><?php echo (int) $r['vazios']; ?></td>
        <td class="num"><?php echo (int) $r['escalados']; ?></td>
        <td class="num <?php echo $r['buffers'] < $r['buf_precisa'] ? 'laranja' : ''; ?>"><?php echo (int) $r['buffers']; ?> <span class="muted">(<?php echo (int) $r['buf_precisa']; ?>)</span></td>
        <td class="num"><?php echo (int) $r['overlap']; ?></td>
        <td class="num"><?php echo (int) $r['he']; ?></td>
        <td class="num"><?php echo $int(($r['postos'] + $r['buf_precisa']) * 8); ?></td>
        <td class="num"><?php echo $int(($r['escalados'] + $r['buffers']) * 8); ?></td>
        <td class="num"><?php echo num($r['faltas'], 1); ?></td>
        <td class="num <?php echo $saldo < 0 ? 'vermelho' : 'verde'; ?>"><?php echo num($saldo, 1); ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  </details>
  <?php endif; ?>
</section>
<section class="panel">
  <h2><?php echo h(t('Absenteísmo e quadro × necessidade (Capacity)')); ?></h2>
  <div class="graficos">
    <?php echo $lAbs ? $figura(t('Absenteísmo: realizado × Capacity'), t('Realizado = faltas ÷ blocos escalados nos últimos 28 dias, todas as unidades, sem a falha administrativa (escala dobrada ou fora do horário). Laranja = acima do absenteísmo do Capacity.'),
        $legenda($legTurnos + ['neutro' => t('Capacity: absenteísmo'), 'neutro-2' => t('Capacity: falha adm.')]),
        $grafBarrasH($lAbs, ['titulo' => t('Absenteísmo realizado e taxa do Capacity por turno'), 'fmt' => static fn(float $v): string => num($v, 0) . '%', 'eixoX' => t('% de faltas'), 'esq' => 100]))
        : '<p class="muted">' . h(t('Sem absenteísmo realizado nem taxas do Capacity.')) . '</p>'; ?>
    <?php echo $lQuadro ? $figura(t('Quadro × necessidade'), t('Barra = quem tem; traço = quanto precisa. Entre parênteses, o saldo.'),
        $legenda($legTurnos + ['alvo' => t('Precisa')]),
        $grafBarrasH($lQuadro, ['titulo' => t('Quadro que tem e que precisa por unidade e turno'), 'eixoX' => t('pessoas'), 'dir' => 100]))
        : '<p class="muted">' . h(t('Sem cálculo de necessidade do Capacity.')) . '</p>'; ?>
  </div>
  <details class="ver-tabela"><summary class="botao"><?php echo h(t('Ver tabela')); ?></summary>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th><?php echo h(t('Unidade')); ?></th><th><?php echo h(t('Turno')); ?></th><th class="num"><?php echo h(t('Postos')); ?></th><th class="num"><?php echo h(t('Titulares')); ?></th><th class="num"><?php echo h(t('Buffer')); ?></th><th class="num"><?php echo h(t('Precisa')); ?></th><th class="num"><?php echo h(t('Tem')); ?></th><th class="num"><?php echo h(t('Saldo')); ?></th><th class="num"><?php echo h(t('Absenteísmo')); ?></th><th class="num"><?php echo h(t('Falha adm.')); ?></th><th class="num"><?php echo h(t('Buffers presentes/dia')); ?></th></tr></thead>
    <tbody>
    <?php foreach ($cap as $u => $pt): if ($unSel && $u !== $unSel) { continue; } foreach ($pt as $t => $c): ?>
      <tr><td>U<?php echo (int) $u; ?></td><td><span class="chip chip-<?php echo strtolower($t); ?>"><?php echo h($t); ?></span></td>
        <td class="num"><?php echo (int) $c['postos']; ?></td><td class="num"><?php echo (int) $c['titulares']; ?></td><td class="num"><?php echo (int) $c['buffer']; ?></td>
        <td class="num"><?php echo (int) $c['precisa']; ?></td><td class="num"><?php echo (int) $c['tem']; ?></td>
        <td class="num <?php echo (int) $c['saldo'] < 0 ? 'vermelho' : 'verde'; ?>"><?php echo (int) $c['saldo']; ?></td>
        <td class="num"><?php echo $pct((float) $c['taxa_abs']); ?></td><td class="num"><?php echo $pct((float) $c['taxa_af']); ?></td><td class="num"><?php echo num((float) $c['presentes_dia'], 1); ?></td></tr>
    <?php endforeach; endforeach; ?>
    </tbody>
  </table>
  </div>
  </details>
</section>

<?php elseif ($aba === 'demanda'): ?>
<section class="panel">
  <h2><?php echo h(t('Previsão de SRs por dia (Capacity)')); ?></h2>
  <p class="muted small"><?php echo h(t('Quantos SRs o fluxo previsto pede por turno, dia a dia, nos próximos 21 dias. Os números marcam o primeiro e o último dia, o maior e o menor total.')); ?></p>
  <?php if (!$gPrev): ?><p class="muted"><?php echo h(t('Sem previsão do Capacity.')); ?></p><?php else: ?>
  <?php echo $figura(t('SRs pedidos por dia'), '', $legenda($legTurnos),
      $grafColunas($gPrev, ['titulo' => t('SRs pedidos pelo Capacity por dia e turno, próximos 21 dias'), 'eixoY' => 'SRs', 'w' => 1000, 'h' => 270, 'larguraMax' => 30]), 'largo'); ?>
  <?php endif; ?>
  <details class="ver-tabela"><summary class="botao"><?php echo h(t('Ver tabela')); ?></summary>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th><?php echo h(t('Dia')); ?></th><th class="num">A</th><th class="num">C</th><th class="num">D</th><th class="num"><?php echo h(t('Total')); ?></th><th class="num"><?php echo h(t('Páginas')); ?></th></tr></thead>
    <tbody>
    <?php foreach ($prev21 as $l): ?>
      <tr><td class="nowrap"><?php echo h(dataBr($l['dia'], true)); ?></td><td class="num"><?php echo (int) $l['a']; ?></td><td class="num"><?php echo (int) $l['c']; ?></td><td class="num"><?php echo (int) $l['d']; ?></td><td class="num"><strong><?php echo (int) $l['total']; ?></strong></td><td class="num"><?php echo (int) $l['paginas']; ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$previsao): ?><tr><td colspan="6" class="muted"><?php echo h(t('Sem previsão do Capacity.')); ?></td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  </details>
</section>
<section class="panel">
  <h2><?php echo h(t('Fluxo por página e turno')); ?></h2>
  <p class="muted small"><?php echo h(t('Fãs/h previstos (Capacity) × faturamento (US$/dia) × SRs regulares escalados por dia na semana. Fãs/h por SR alto = página apertada. Os gráficos mostram as {top} maiores de {total} páginas; a tabela tem todas.', ['top' => (int) $topN, 'total' => count($carga)])); ?></p>
  <?php if (!$carga): ?><p class="muted"><?php echo h(t('Sem carga do Capacity.')); ?></p><?php else: ?>
  <div class="graficos">
    <?php echo $figura(t('Páginas com mais fluxo'), t('Fãs/h previstos somando os turnos. Laranja = turno com fluxo e nenhum SR regular escalado.'),
        $legenda($legTurnos),
        $grafBarrasH($lFluxo, ['titulo' => t('Fãs por hora previstos por página e turno'), 'eixoX' => t('fãs/h'), 'esq' => 150, 'dir' => 120, 'corte' => 22])); ?>
    <?php echo $lReceita ? $figura(t('Páginas que mais faturam'), t('US$ por dia, média dos últimos dias de extrato, por turno.'),
        $legenda($legTurnos),
        $grafBarrasH($lReceita, ['titulo' => t('Faturamento em dólares por dia, por página e turno'), 'eixoX' => t('US$/dia'), 'esq' => 150, 'dir' => 100, 'corte' => 22]))
        : '<p class="muted">' . h(t('Sem faturamento das páginas no período.')) . '</p>'; ?>
  </div>
  <?php endif; ?>
  <details class="ver-tabela"><summary class="botao"><?php echo h(t('Ver tabela')); ?></summary>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th><?php echo h(t('Página')); ?></th><th><?php echo h(t('Carteira')); ?></th>
      <?php foreach (array_keys($turnos) as $t): ?><th class="num"><?php echo h(t('{turno} fãs/h', ['turno' => $t])); ?></th><th class="num"><?php echo h(t('{turno} US$/dia', ['turno' => $t])); ?></th><th class="num"><?php echo h(t('{turno} SRs/dia', ['turno' => $t])); ?></th><?php endforeach; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($carga as $pg => $c): ?>
      <tr><td><strong><?php echo h($pg); ?></strong><?php echo ($c['regra'] ?? 'PADRAO') !== 'PADRAO' ? ' <span class="tag">' . h($rotRegra[$c['regra']] ?? strtolower((string) $c['regra'])) . '</span>' : ''; ?></td><td><?php echo h($c['carteira']); ?></td>
        <?php foreach (array_keys($turnos) as $t): $sr = $srPagina[$pg][$t] ?? 0; $f = $c[$t] ?? 0; ?>
          <td class="num"><?php echo $f ? num($f, 1) : '—'; ?></td>
          <td class="num"><?php echo isset($receita[$pg]) ? $int($receita[$pg][$t]) : '—'; ?></td>
          <td class="num <?php echo $f > 0 && $sr === 0 ? 'laranja' : ''; ?>"><?php echo $sr ? num($sr, 1) : '0'; ?></td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  </details>
</section>
<section class="panel">
  <h2><?php echo h(t('Funil do CRM (páginas chegando)')); ?></h2>
  <p class="muted small"><?php echo h(t('Cards em pipeline e onboarding, e os que começaram nos últimos 7 dias. A previsão de fãs/h e o destino vêm da tela Funil de entrada. SRs estimados por turno = ↑(fãs/h ÷ 28 por posto) × 1,5 (rotação de 21 dias).')); ?></p>
  <?php if (!$funil): ?><p class="muted"><?php echo h(t('Nenhum card no funil.')); ?></p><?php else: ?>
  <div class="colunas-funil">
    <?php foreach ($funilEtapas as $etapa => $e): ?>
    <div class="coluna">
      <div class="cab-etapa"><strong><?php echo h($etapa); ?></strong> <span class="muted small"><?php echo h(tn(count($e['cards']), '{n} card · SRs estimados A · C · D: {srs}', '{n} cards · SRs estimados A · C · D: {srs}', ['srs' => implode(' · ', array_map(static fn($v) => num($v, 1), $e['srs']))])); ?></span></div>
      <?php foreach ($e['cards'] as $k): $c = $k['c']; $pv = $k['pv']; ?>
      <div class="cartao">
        <div class="linha-titulo"><strong><?php echo h($c['cliente'] ?? '—'); ?></strong><?php if ($c['tier'] ?? null): ?><span class="tag"><?php echo h(t('Tier {tier}', ['tier' => $c['tier']])); ?></span><?php endif; ?></div>
        <span class="muted small"><?php echo h(t('Início {dia}', ['dia' => dataBr($pv['data_prevista'] ?? $c['start_date'])])); ?> · <?php echo $c['value'] !== null ? h(num((float) $c['value'], 0) . ' ' . $c['currency']) : h(t('sem valor')); ?><?php echo $pv && $pv['probabilidade'] !== null ? ' · ' . h(t('{pct} de chance', ['pct' => $pct((float) $pv['probabilidade'])])) : ''; ?></span>
        <?php if ($k['fans']): ?>
        <span class="small"><?php echo h(t('Fãs/h')); ?> <?php echo chipTurno('A'); ?> <?php echo h(num($k['fans'][0], 1)); ?> <?php echo chipTurno('C'); ?> <?php echo h(num($k['fans'][1], 1)); ?> <?php echo chipTurno('D'); ?> <?php echo h(num($k['fans'][2], 1)); ?></span>
        <span class="small"><?php echo h(t('SRs estimados {srs}', ['srs' => implode(' · ', array_map(static fn($v) => num($v, 1), $k['srs']))])); ?><?php echo !empty($pv['destino_time']) ? ' · ' . h(t('destino {time}', ['time' => $pv['destino_time']])) : ''; ?></span>
        <?php else: ?><span class="small laranja"><?php echo h(t('Sem previsão de fãs/h')); ?></span><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <details class="ver-tabela"><summary class="botao"><?php echo h(t('Ver tabela')); ?></summary>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th><?php echo h(t('Cliente')); ?></th><th><?php echo h(t('Etapa')); ?></th><th><?php echo h(t('Tier')); ?></th><th><?php echo h(t('Início')); ?></th><th class="num"><?php echo h(t('Valor')); ?></th><th class="num"><?php echo h(t('Fãs/h A · C · D')); ?></th><th class="num"><?php echo h(t('Probab.')); ?></th><th><?php echo h(t('SRs estimados')); ?></th><th><?php echo h(t('Destino')); ?></th></tr></thead>
    <tbody>
    <?php foreach ($funil as $c): $pv = $prevFunil[(int) $c['id']] ?? null; $fans = $pv ? [(float) $pv['fans_h_a'], (float) $pv['fans_h_c'], (float) $pv['fans_h_d']] : null; ?>
      <tr><td><strong><?php echo h($c['cliente'] ?? '—'); ?></strong></td><td><?php echo h($c['etapa']); ?></td><td><?php echo h($c['tier'] ?? '—'); ?></td>
        <td class="nowrap"><?php echo h(dataBr($pv['data_prevista'] ?? $c['start_date'])); ?></td>
        <td class="num"><?php echo $c['value'] !== null ? h(num((float) $c['value'], 0) . ' ' . $c['currency']) : '—'; ?></td>
        <td class="num"><?php echo $fans ? h(implode(' · ', array_map(static fn($v) => num($v, 1), $fans))) : '<span class="muted">' . h(t('sem previsão')) . '</span>'; ?></td>
        <td class="num"><?php echo $pv && $pv['probabilidade'] !== null ? $pct((float) $pv['probabilidade']) : '—'; ?></td>
        <td><?php echo $fans ? h(implode(' · ', array_map(static fn($v) => num(ceil($v / 28) * 1.5, 1), $fans))) : '—'; ?></td>
        <td><?php echo h($pv['destino_time'] ?? '—'); ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$funil): ?><tr><td colspan="9" class="muted"><?php echo h(t('Nenhum card no funil.')); ?></td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  </details>
  <p class="muted small"><a href="<?php echo h(link_tela('funil')); ?>"><?php echo h(t('Ajustar a previsão no Funil de entrada')); ?></a>.</p>
</section>

<?php else: ?>
<section class="panel">
  <h2><?php echo h(t('Escala × demanda por unidade e turno')); ?></h2>
  <p class="muted small"><?php echo h(t('Médias por dia da semana selecionada. Presentes esperados = SRs em posto × (1 − absenteísmo − falha adm.). Fãs/h por presente acima de 28 indica sobrecarga prevista.')); ?></p>
  <?php if (!$cruz): ?><p class="muted"><?php echo h(t('Sem dados.')); ?></p><?php else: ?>
  <div class="graficos">
    <figure class="graf-cartao">
      <figcaption><strong><?php echo h(t('Fãs/h por presente')); ?></strong><span class="muted small"><?php echo h(t('Quanto mais escuro, mais perto de 28. Vermelho = acima de 28, sobrecarga prevista.')); ?></span></figcaption>
      <div class="legenda-graf"><span><i class="cor m-1"></i><?php echo h(t('até {n}', ['n' => 14])); ?></span><span><i class="cor m-2"></i>14–21</span><span><i class="cor m-3"></i>21–28</span><span><i class="cor m-4"></i>28–35</span><span><i class="cor m-5"></i><?php echo h(t('acima de {n}', ['n' => 35])); ?></span></div>
      <div class="mapa" role="img" aria-label="<?php echo h(t('Mapa de fãs por hora por presente, por unidade e turno')); ?>">
        <span></span>
        <?php foreach ($turnos as $t => $faixa): ?><span class="mapa-cab"><?php echo chipTurno($t); ?> <span class="muted small"><?php echo h($faixa); ?></span></span><?php endforeach; ?>
        <?php foreach ($mapa as $u => $pt): ?>
          <span class="mapa-cab mapa-un">U<?php echo (int) $u; ?></span>
          <?php foreach (array_keys($turnos) as $t): $r = $pt[$t] ?? null; $v = $r ? $r['fans_sr'] : null; ?>
          <div class="celula-mapa <?php echo h($nivelMapa($v)); ?>" title="<?php echo h(t('U{u} · turno {turno}', ['u' => $u, 'turno' => $t]) . "\n" . t('Fãs/h previstos: {n}', ['n' => num($r['fans'] ?? 0, 0)]) . "\n" . t('Presentes esperados/dia: {n}', ['n' => num($r['presentes'] ?? 0, 1)]) . "\n" . t('Fãs/h por presente: {n}', ['n' => $v !== null ? num($v, 1) : '—']) . "\n" . t('US$/dia: {n}', ['n' => $int($r['fat'] ?? 0)])); ?>">
            <strong><?php echo $v !== null ? h(num($v, 1)) : '—'; ?></strong>
            <span class="small"><?php echo h(t('{fans} fãs/h · {pres} pres.', ['fans' => num($r['fans'] ?? 0, 0), 'pres' => num($r['presentes'] ?? 0, 1)])); ?></span>
          </div>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </div>
    </figure>
    <?php echo $figura(t('Presentes esperados × SRs necessários'), t('Barra = presentes esperados por dia; traço = SRs para atender 28 fãs/h por pessoa (fãs/h ÷ 28). Vermelho = faltam presentes.'),
        $legenda($legTurnos + ['alvo' => t('SRs para 28 fãs/h')]),
        $grafBarrasH($lPresentes, ['titulo' => t('Presentes esperados e SRs necessários por unidade e turno'), 'eixoX' => t('SRs por dia'), 'dir' => 100])); ?>
  </div>
  <?php endif; ?>
  <details class="ver-tabela"><summary class="botao"><?php echo h(t('Ver tabela')); ?></summary>
  <div class="tabela-rolagem">
  <table>
    <thead><tr><th><?php echo h(t('Unidade')); ?></th><th><?php echo h(t('Turno')); ?></th><th class="num"><?php echo h(t('Fãs/h previstos')); ?></th><th class="num"><?php echo h(t('US$/dia')); ?></th><th class="num"><?php echo h(t('Postos/dia')); ?></th><th class="num"><?php echo h(t('SRs em posto/dia')); ?></th><th class="num"><?php echo h(t('Sem ninguém/dia')); ?></th><th class="num"><?php echo h(t('Buffers/dia')); ?></th><th class="num"><?php echo h(t('Faltas')); ?></th><th class="num"><?php echo h(t('Presentes esp.')); ?></th><th class="num"><?php echo h(t('Fãs/h por presente')); ?></th><th class="num"><?php echo h(t('Quadro (tem/precisa)')); ?></th></tr></thead>
    <tbody>
    <?php foreach ($cruz as $r): ?>
      <tr><td>U<?php echo (int) $r['u']; ?></td><td><span class="chip chip-<?php echo strtolower($r['t']); ?>"><?php echo h($r['t']); ?></span></td>
        <td class="num"><?php echo num($r['fans'], 0); ?></td><td class="num"><?php echo $int($r['fat']); ?></td>
        <td class="num"><?php echo num($r['postos'], 1); ?></td><td class="num"><?php echo num($r['esc'], 1); ?></td>
        <td class="num <?php echo $r['vazios'] > 0 ? 'laranja' : ''; ?>"><?php echo num($r['vazios'], 1); ?></td><td class="num"><?php echo num($r['buf'], 1); ?></td>
        <td class="num"><?php echo $pct($r['taxa']); ?></td><td class="num"><?php echo num($r['presentes'], 1); ?></td>
        <td class="num <?php echo $r['fans_sr'] !== null && $r['fans_sr'] > 28 ? 'vermelho' : ''; ?>"><?php echo $r['fans_sr'] !== null ? num($r['fans_sr'], 1) : '—'; ?></td>
        <td class="num <?php echo $r['tem'] < $r['precisa'] ? 'vermelho' : ''; ?>"><?php echo (int) $r['tem']; ?> / <?php echo (int) $r['precisa']; ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$cruz): ?><tr><td colspan="12" class="muted"><?php echo h(t('Sem dados.')); ?></td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  </details>
</section>
<?php endif; ?>
</div>
<script src="<?php echo h(MODULE_URL); ?>/assets/analise.js?v=<?php echo h($versao('analise.js')); ?>" defer></script>
