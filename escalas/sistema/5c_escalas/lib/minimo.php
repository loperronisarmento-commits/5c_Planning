<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/minimo.php
// "Recalcular com o mínimo de mudanças" (escMotorGerar com $minimo = true): parte da versão de
// referência (a publicada, que é a postada no site) e muda só o necessário, para quase todos os
// CSVs continuarem iguais ao que já está no site.
//   1. Liderança (-6/-7/-8), trainees (-4) e células travadas vêm do cálculo novo.
//   2. Cada pessoa da referência fica no mesmo bloco e página se a página ainda tem aquela célula
//      na estrutura de hoje e a pessoa continua no turno dela, disponível e sem outro turno no dia.
//   3. O que faltar (página nova, quem saiu) é preenchido, nesta ordem: pela pessoa que o cálculo
//      novo pôs ali, se estiver livre no bloco; por um buffer de plantão da unidade e turno; como
//      página a mais de quem já está no bloco com pouca carga (até $limite fãs/h, de preferência
//      quem já está na mesma modelo). Sem ninguém, fica lacuna.
//   4. Buffers e Overlap da referência ficam se a pessoa continua disponível e livre no bloco;
//      quem o cálculo novo põe para trabalhar e não tem nada no dia entra como no cálculo novo.
// Fora do alcance do cálculo novo (antes da virada) a referência vale como está.
// ===============================================

// $info[page_id|turno] = ['fans', 'unidade', 'modelo']. Devolve [alocações, resumo].
function escMotorMinimo(array $ref, array $novas, array $pessoas, array $indisp, array $bloqueios, array $info, float $limite, array $folgaNova = []): array {
    $dia = static fn(array $r): string => substr((string) $r['dia'], 0, 10);
    $bloc = static fn(array $r): string => $dia($r) . '|' . $r['bloco'];
    $cel = static fn(array $r): string => $bloc($r) . '|' . (int) $r['page_id'];
    $diaTurno = static fn(array $r): string => $r['bloco'] === 'T1' ? escMaisDias($dia($r), -1) : $dia($r);
    $especial = static fn(array $r): bool => strpos((string) $r['origem'], 'LIDER') === 0 || $r['origem'] === 'TREINO';
    $plantao = static fn(array $r): bool => in_array($r['origem'], ['BUFFER', 'OVERLAP'], true);
    $pode = static function (string $rep, array $r) use ($pessoas, $indisp, $bloqueios, $diaTurno, $folgaNova): bool {
        $p = $pessoas[$rep] ?? null;
        if (!$p || $p['turno'] !== $r['turno']) {
            return false; // saiu da escala ou mudou de turno
        }
        $dt = $diaTurno($r);
        // Folga do cálculo novo (emenda de dias seguidos, rotação do núcleo novo): a célula antiga não fica.
        if (isset($bloqueios[$rep][$dt]) || isset($folgaNova[$rep . '|' . $dt])) {
            return false;
        }
        $ind = $indisp[$rep][$dt][$r['turno']] ?? null;
        return !($ind && ($ind['blocos'] === null || in_array($r['bloco'], (array) $ind['blocos'], true)));
    };

    $escopo = [];
    foreach ($novas as $n) {
        $escopo[$bloc($n)] = true;
    }
    $out = [];
    $ocup = [];      // [rep][dia|bloco] = true
    $turnoDia = [];  // [rep][diaTurno] = turno
    $carga = [];     // [rep][dia|bloco] = fãs/h
    $marcar = static function (array $r) use (&$ocup, &$turnoDia, &$carga, $bloc, $diaTurno, $info): void {
        if ($r['rep_code'] === null) {
            return;
        }
        $ocup[$r['rep_code']][$bloc($r)] = true;
        $turnoDia[$r['rep_code']][$diaTurno($r)] = $r['turno'];
        $carga[$r['rep_code']][$bloc($r)] = ($carga[$r['rep_code']][$bloc($r)] ?? 0) + ($info[(int) $r['page_id'] . '|' . $r['turno']]['fans'] ?? 0);
    };
    // 1. Do cálculo novo: liderança, trainees e travadas; o resto vira "o que cada célula precisa".
    $precisa = [];    // [célula] = linhas do cálculo novo (uma por vaga)
    $travCel = [];
    $plantaoNovo = [];
    foreach ($novas as $n) {
        if ($especial($n) || !empty($n['travada'])) {
            if (!empty($n['travada']) && !$especial($n)) {
                $travCel[$cel($n)] = true;
            }
            $out[] = $n;
            $marcar($n);
        } elseif ($plantao($n)) {
            $plantaoNovo[] = $n;
        } else {
            $precisa[$cel($n)][] = $n;
        }
    }
    foreach ($ref as $r) {
        if (!isset($escopo[$bloc($r)])) {
            $out[] = $r; // antes da virada: vale a escala já postada
            $marcar($r);
        }
    }
    // 2. Quem fica onde estava
    $preenchida = [];
    $mantidas = 0;
    foreach ($ref as $r) {
        $c = $cel($r);
        if (!isset($escopo[$bloc($r)]) || $r['rep_code'] === null || $especial($r) || $plantao($r) || isset($travCel[$c]) || !isset($precisa[$c])) {
            continue;
        }
        $i = count($preenchida[$c] ?? []);
        $rep = $r['rep_code'];
        $td = $turnoDia[$rep][$diaTurno($r)] ?? null;
        if ($i >= count($precisa[$c]) || in_array($rep, $preenchida[$c] ?? [], true) || !$pode($rep, $r) || ($td !== null && $td !== $r['turno'])) {
            continue;
        }
        $linha = ['rep_code' => $rep, 'papel' => $r['papel'], 'origem' => $r['origem'], 'travada' => 0, 'motivo' => 'mantido da versão publicada (mínimo de mudanças)'] + $precisa[$c][$i];
        $out[] = $linha;
        $marcar($linha);
        $preenchida[$c][] = $rep;
        $mantidas++;
    }
    // Buffers e Overlap da referência que continuam valendo (podem cobrir página no passo 3).
    $buffers = []; // [dia|bloco][rep] = linha
    foreach ($ref as $r) {
        if (isset($escopo[$bloc($r)]) && $r['rep_code'] !== null && $plantao($r) && $pode($r['rep_code'], $r)
            && !isset($ocup[$r['rep_code']][$bloc($r)]) && (($turnoDia[$r['rep_code']][$diaTurno($r)] ?? $r['turno']) === $r['turno'])) {
            $buffers[$bloc($r)][$r['rep_code']] = $r;
        }
    }
    // 3. O que falta. Só se cobre o que o cálculo novo cobre (vaga com gente nele): o que ele deixa
    //    sem ninguém continua sem ninguém, a não ser que a pessoa da referência tenha ficado ali.
    $escolha = []; // [page_id|diaTurno|turno] = rep (o mesmo nos dois blocos do turno)
    $novos = 0;
    $paginaMais = 0;
    $lacunas = 0;
    foreach ($precisa as $c => $linhas) {
        $ficou = $preenchida[$c] ?? [];
        $comRep = array_values(array_filter($linhas, static fn($n) => $n['rep_code'] !== null));
        usort($comRep, static fn($x, $y) => in_array($x['rep_code'], $ficou, true) <=> in_array($y['rep_code'], $ficou, true));
        $cobrir = array_slice($comRep, 0, max(0, count($comRep) - count($ficou)));
        $vazias = max(0, count($linhas) - count($ficou) - count($cobrir));
        foreach (array_slice(array_values(array_filter($linhas, static fn($n) => $n['rep_code'] === null)) ?: $linhas, 0, $vazias) as $n) {
            $out[] = ['rep_code' => null, 'origem' => 'LACUNA', 'travada' => 0] + $n;
            $lacunas++;
        }
        foreach ($cobrir as $n) {
            $b = $bloc($n);
            $pg = (int) $n['page_id'];
            $inf = $info[$pg . '|' . $n['turno']] ?? ['fans' => 0, 'unidade' => null, 'modelo' => null];
            $ja = $preenchida[$c] ?? [];
            $livre = static fn(string $rep): bool => !isset($ocup[$rep][$b]) && (($turnoDia[$rep][$diaTurno($n)] ?? $n['turno']) === $n['turno']) && !in_array($rep, $ja, true);
            $cabe = static fn(string $rep): bool => isset($ocup[$rep][$b]) && ($carga[$rep][$b] ?? 0) + $inf['fans'] <= $limite && !in_array($rep, $ja, true);
            $chaveTurno = $pg . '|' . $diaTurno($n) . '|' . $n['turno'];
            $rep = null;
            $como = null;
            $ant = $escolha[$chaveTurno] ?? null;
            if ($ant !== null && $pode($ant, $n) && ($livre($ant) || $cabe($ant))) {
                [$rep, $como] = [$ant, isset($ocup[$ant][$b]) ? 'mais' : 'novo'];
            } elseif ($n['rep_code'] !== null && $pode($n['rep_code'], $n) && $livre($n['rep_code'])) {
                [$rep, $como] = [$n['rep_code'], 'novo'];
            } else {
                foreach ($buffers[$b] ?? [] as $br => $bl) {
                    if (($pessoas[$br]['unidade'] ?? null) === $inf['unidade'] && $livre((string) $br)) {
                        [$rep, $como] = [(string) $br, 'buffer'];
                        unset($buffers[$b][$br]);
                        break;
                    }
                }
                if ($rep === null) {
                    $melhor = null;
                    foreach ($ocup as $cand => $blocos) {
                        $cand = (string) $cand;
                        if (!isset($blocos[$b]) || ($pessoas[$cand]['unidade'] ?? null) !== $inf['unidade'] || !$pode($cand, $n) || !$cabe($cand)) {
                            continue;
                        }
                        $mesmaModelo = 0;
                        foreach ($out as $o) {
                            if ($o['rep_code'] === $cand && $bloc($o) === $b && $inf['modelo'] !== null && ($info[(int) $o['page_id'] . '|' . $o['turno']]['modelo'] ?? null) === $inf['modelo']) {
                                $mesmaModelo = 1;
                                break;
                            }
                        }
                        $k = [$mesmaModelo, -($carga[$cand][$b] ?? 0)];
                        if ($melhor === null || $k > $melhor[1]) {
                            $melhor = [$cand, $k];
                        }
                    }
                    if ($melhor) {
                        [$rep, $como] = [$melhor[0], 'mais'];
                    }
                }
            }
            if ($rep === null) {
                $linha = ['rep_code' => null, 'origem' => 'LACUNA', 'travada' => 0, 'motivo' => 'mínimo de mudanças: ninguém livre nem com folga de carga na unidade e turno'] + $n;
                $lacunas++;
            } else {
                $motivo = ['novo' => 'mínimo de mudanças: entrou no lugar vago', 'buffer' => 'mínimo de mudanças: buffer de plantão cobre a página', 'mais' => 'mínimo de mudanças: página a mais (carga baixa no bloco)'][$como];
                $linha = ['rep_code' => $rep, 'origem' => $como === 'mais' ? 'SOBRECARGA' : ($n['rep_code'] === $rep ? $n['origem'] : 'BUFFER_NUCLEO'), 'travada' => 0, 'motivo' => $motivo] + $n;
                $escolha[$chaveTurno] = $rep;
                $preenchida[$c][] = $rep;
                $como === 'mais' ? $paginaMais++ : $novos++;
            }
            $out[] = $linha;
            $marcar($linha);
        }
    }
    // 4. Plantão: buffers da referência que sobraram; quem o cálculo novo põe e não tem nada no dia.
    foreach ($buffers as $b => $porRep) {
        foreach ($porRep as $r) {
            if (!isset($ocup[$r['rep_code']][$b])) {
                $out[] = $r;
                $marcar($r);
            }
        }
    }
    $semNada = [];
    foreach ($plantaoNovo as $n) {
        if ($n['rep_code'] !== null && !isset($turnoDia[$n['rep_code']][$diaTurno($n)])) {
            $semNada[$n['rep_code'] . '|' . $diaTurno($n)] = true;
        }
    }
    foreach ($plantaoNovo as $n) {
        if ($n['rep_code'] !== null && isset($semNada[$n['rep_code'] . '|' . $diaTurno($n)]) && !isset($ocup[$n['rep_code']][$bloc($n)])) {
            $out[] = $n;
            $marcar($n);
        }
    }
    // Páginas cujas células mudaram em relação à referência (para a mensagem).
    $assin = static function (array $linhas) use ($cel): array {
        $m = [];
        foreach ($linhas as $l) {
            $m[$cel($l)][] = (string) $l['rep_code'];
        }
        foreach ($m as &$v) {
            sort($v);
            $v = implode(',', $v);
        }
        return $m;
    };
    $antes = $assin($ref);
    $depois = $assin($out);
    $paginas = [];
    foreach (array_keys($antes + $depois) as $c) {
        if (($antes[$c] ?? null) !== ($depois[$c] ?? null)) {
            $paginas[(int) explode('|', $c)[2]] = true;
        }
    }
    return [$out, ['mantidas' => $mantidas, 'novas' => $novos, 'pagina_a_mais' => $paginaMais, 'lacunas' => $lacunas, 'paginas_mudadas' => count($paginas)]];
}

// Texto curto para a mensagem depois de recalcular com o mínimo de mudanças.
function escMinimoAviso(): string {
    $r = $GLOBALS['ESC_MINIMO'] ?? null;
    if (!$r) {
        return '';
    }
    return ' ' . t('Mínimo de mudanças sobre a {base}: {mantidas} células mantidas; {paginas} páginas mudaram ({novas} vagas preenchidas, {mais} como página a mais, {lacunas} sem ninguém).',
        ['base' => $r['base'], 'mantidas' => $r['mantidas'], 'paginas' => $r['paginas_mudadas'], 'novas' => $r['novas'], 'mais' => $r['pagina_a_mais'], 'lacunas' => $r['lacunas']]);
}
