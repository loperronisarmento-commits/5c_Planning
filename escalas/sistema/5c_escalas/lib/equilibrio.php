<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/equilibrio.php
// Equilíbrio das unidades (pedido de 31/10), refeito a cada cálculo do Capacity:
//   - Faturamento de 30 dias por página e turno: 5c_wfm_db.agg_statements_classification
//     (complete + pending − reverse, todas as classificações), pelo horário da venda.
//   - Meta (decisão de 31/10): o mesmo faturamento total em cada unidade e em cada coordenador
//     (unidade × turno). Desvio = faturamento do coordenador ÷ média do turno − 1. O faturamento
//     por SR fica na tela para acompanhar: com as páginas maiores, o Capacity pede mais SRs onde
//     o total subiu, e o por SR se acerta com o tempo.
//   - Troca de unidade: só página com 28 dias na unidade (maturação, pol_prazo_acomp). Sugere
//     quantas forem precisas, da que mais aproxima as unidades para a que menos; o planejador
//     decide uma a uma (tela Equilíbrio). Gap que sobra: o Funil prioriza a unidade abaixo.
//   - Conhecimento da página (pedido de 02/11): conhece quem tem v4_conhece_dias (3) dias ou mais
//     nela nas últimas 8 semanas. A troca de unidade só é sugerida se o destino tiver pelo menos
//     v4_conhece_min (2) SRs que conhecem a modelo; a tela lista as páginas abaixo desse mínimo na
//     própria unidade, com quem as conhece em outra (mudar a pessoa com compensação, como no ES_73).
//   - Divisão dos OS em cada unidade e turno, pelo faturamento de 30 dias dos postos (regra de
//     31/10): OS1 com as maiores páginas, OS3 com as menores e OS2 misto, cobrindo os dois. Ver
//     escEqDivisao. Os melhores SRs vão para o OS1; no OS2, quem conhece páginas grandes e pequenas.
// ===============================================

// Nome da página sem diferença de maiúscula e espaço (o banco compara assim: "Haven  Free").
function escEqChave(string $pagina): string {
    return mb_strtolower(preg_replace('~\s+~u', ' ', trim($pagina)));
}

// Faturamento de 30 dias por página (escEqChave) e turno: [pagina][A|C|D] = US$ (cache de 15 min).
function escEqFaturamento(string $hoje): array {
    return escCache('equilibrio_fat_' . $hoje, 900, static function () use ($hoje): array {
        $f = [];
        foreach (escQ("SELECT CREATOR_NAME AS pagina, CASE WHEN HOUR(`DATE`) BETWEEN 5 AND 12 THEN 'A' WHEN HOUR(`DATE`) BETWEEN 13 AND 20 THEN 'C' ELSE 'D' END AS turno,
                              SUM(CASE WHEN STATUS = 'reverse' THEN -ABS(AMOUNT) ELSE AMOUNT END) AS v
                         FROM `5c_wfm_db`.`agg_statements_classification` WHERE `DATE` >= ? AND `DATE` < ? GROUP BY CREATOR_NAME, turno",
            [escMaisDias($hoje, -30) . ' 00:00:00', $hoje . ' 00:00:00']) as $r) {
            $k = escEqChave((string) $r['pagina']);
            $f[$k][$r['turno']] = round(($f[$k][$r['turno']] ?? 0) + (float) $r['v'], 2);
        }
        return $f;
    });
}

// Peso 1 em cada unidade × turno: com ele, desvios, custo e trocas olham o faturamento total
// (a meta); com os SRs de cada coordenador, olham o faturamento por SR.
function escEqPesoTotal(array $fat, array $srs = []): array {
    $um = [];
    foreach (array_unique(array_merge(array_keys($fat), array_keys($srs))) as $u) {
        $um[$u] = ['A' => 1, 'C' => 1, 'D' => 1];
    }
    ksort($um);
    return $um;
}

// Desvio de cada coordenador: [u][t] = faturamento por peso (SR, ou 1 para o total) ÷ média do
// turno − 1 (null sem peso).
function escEqDesvios(array $fat, array $srs): array {
    $media = [];
    foreach (['A', 'C', 'D'] as $t) {
        $f = $s = 0.0;
        foreach ($srs as $u => $porT) {
            $f += $fat[$u][$t] ?? 0;
            $s += $porT[$t] ?? 0;
        }
        $media[$t] = $s > 0 ? $f / $s : 0.0;
    }
    $d = [];
    foreach ($srs as $u => $porT) {
        foreach (['A', 'C', 'D'] as $t) {
            $n = $porT[$t] ?? 0;
            $d[$u][$t] = $n > 0 && $media[$t] > 0 ? (($fat[$u][$t] ?? 0) / $n) / $media[$t] - 1 : null;
        }
    }
    return ['media' => $media, 'desvio' => $d];
}

// Custo do desequilíbrio: Σ SRs × (faturamento por SR − média do turno)².
function escEqCusto(array $fat, array $srs, array $media): float {
    $c = 0.0;
    foreach ($srs as $u => $porT) {
        foreach ($porT as $t => $n) {
            if ($n > 0) {
                $c += $n * ((($fat[$u][$t] ?? 0) / $n) - ($media[$t] ?? 0)) ** 2;
            }
        }
    }
    return $c;
}

// Sugestões de troca de unidade (função pura). $paginas[nome] = ['unidade', 'fat' => [A, C, D],
// 'madura_em' => data, 'grupo' => modelo]; $srs[u][t] = SRs. As páginas da mesma modelo na mesma
// unidade trocam juntas (o Capacity junta a modelo no mesmo posto). Cada grupo troca no máximo uma
// vez; para quando nenhuma troca melhora o equilíbrio em pelo menos 0,5% (troca de US$ 50 não vale
// a mexida). 'melhora' é acumulada: quanto do desequilíbrio de hoje some até aquela linha.
// $conheceMin > 0: só vai para a unidade com pelo menos esse número de SRs que conhecem a modelo
// ($paginas[nome]['conhece'][u], a página da modelo mais conhecida).
function escEqSugerirTrocas(array $paginas, array $srs, int $max = 60, float $ganhoMin = 0.005, int $conheceMin = 0): array {
    $fat = [];
    $grupos = [];
    foreach ($paginas as $nome => $p) {
        foreach ($p['fat'] as $t => $v) {
            $fat[$p['unidade']][$t] = ($fat[$p['unidade']][$t] ?? 0) + $v;
        }
        $g = $p['unidade'] . '|' . ($p['grupo'] ?? $nome);
        $grupos[$g] = $grupos[$g] ?? ['unidade' => $p['unidade'], 'paginas' => [], 'fat' => [], 'madura_em' => null, 'conhece' => []];
        $grupos[$g]['paginas'][] = $nome;
        foreach ($p['conhece'] ?? [] as $u => $n) {
            $grupos[$g]['conhece'][$u] = max($grupos[$g]['conhece'][$u] ?? 0, (int) $n);
        }
        foreach ($p['fat'] as $t => $v) {
            $grupos[$g]['fat'][$t] = ($grupos[$g]['fat'][$t] ?? 0) + $v;
        }
        $grupos[$g]['madura_em'] = max((string) $grupos[$g]['madura_em'], (string) ($p['madura_em'] ?? '')) ?: null;
    }
    $media = escEqDesvios($fat, $srs)['media'];
    $custo = escEqCusto($fat, $srs, $media);
    $custo0 = $custo;
    $unidades = array_keys($srs);
    $sug = [];
    $movida = [];
    while (count($sug) < $max) {
        $melhor = null;
        foreach ($grupos as $nome => $p) {
            if (isset($movida[$nome]) || array_sum($p['fat']) <= 0) {
                continue;
            }
            foreach ($unidades as $b) {
                if ($b === $p['unidade'] || ($conheceMin > 0 && ($p['conhece'][$b] ?? 0) < $conheceMin)) {
                    continue;
                }
                $f2 = $fat;
                foreach ($p['fat'] as $t => $v) {
                    $f2[$p['unidade']][$t] = ($f2[$p['unidade']][$t] ?? 0) - $v;
                    $f2[$b][$t] = ($f2[$b][$t] ?? 0) + $v;
                }
                $c2 = escEqCusto($f2, $srs, $media);
                if ($c2 < $custo - max(1e-6, $ganhoMin * $custo0) && ($melhor === null || $c2 < $melhor['custo'])) {
                    $melhor = ['grupo' => $nome, 'de' => $p['unidade'], 'para' => $b, 'custo' => $c2, 'fat' => $f2];
                }
            }
        }
        if ($melhor === null) {
            break;
        }
        $p = $grupos[$melhor['grupo']];
        $sug[] = ['pagina' => implode(' + ', $p['paginas']), 'paginas' => $p['paginas'], 'de' => $melhor['de'], 'para' => $melhor['para'],
            'fat' => $p['fat'], 'faturamento' => round(array_sum($p['fat'])), 'madura_em' => $p['madura_em'], 'conhece_destino' => $p['conhece'][$melhor['para']] ?? 0,
            'melhora' => $custo0 > 0 ? round(1 - $melhor['custo'] / $custo0, 4) : 0.0, 'desvio_depois' => escEqDesvios($melhor['fat'], $srs)['desvio'], 'fat_depois' => $melhor['fat']];
        $fat = $melhor['fat'];
        $custo = $melhor['custo'];
        $movida[$melhor['grupo']] = true;
    }
    return $sug;
}

// Divisão dos OS numa unidade e turno (função pura), regra de 31/10: postos pelo faturamento de 30
// dias, do maior para o menor. Metade de cima no padrão OS1, OS1, OS2; metade de baixo no padrão
// OS2, OS3, OS3. O OS1 fica com as maiores, o OS3 com as menores e o OS2 com um misto, cerca de
// 1/3 dos postos cada. Cobertura: o OS2 cobre os postos do OS1 e do OS3; nos postos do OS2, os
// grandes ficam com o OS1 e os pequenos com o OS3.
// $postos[id] = ['fat', 'paginas', 'dono' => número do núcleo dono hoje, 'cob' => número da
// cobertura]; $srs[rep] = ['num' => núcleo hoje, 'nivel', 'nota', 'conhece' => [escEqChave(pagina)
// => dias escalado]]. SRs: os melhores (nível, depois mérito) no OS1; no OS2, quem conhece páginas
// grandes e pequenas; o resto no OS3. O tamanho de cada núcleo segue os postos dele.
function escEqDivisao(array $postos, array $srs): array {
    uasort($postos, static fn($a, $b) => [$b['fat'], $a['id'] ?? 0] <=> [$a['fat'], $b['id'] ?? 0]);
    $total = array_sum(array_column($postos, 'fat'));
    $metade = (int) ceil(count($postos) / 2);
    $parteHoje = [1 => 0.0, 2 => 0.0, 3 => 0.0];
    $parteNova = [1 => 0.0, 2 => 0.0, 3 => 0.0];
    $qtdPostos = [1 => 0, 2 => 0, 3 => 0];
    $grandes = [];
    $pequenas = [];
    $saida = [];
    $i = 0;
    foreach ($postos as $id => $p) {
        $cima = $i < $metade;
        $os = $cima ? [1, 1, 2][$i % 3] : [2, 3, 3][($i - $metade) % 3];
        $cob = $os === 2 ? ($cima ? 1 : 3) : 2;
        foreach ($p['paginas'] ?? [] as $pg) {
            if ($cima) {
                $grandes[escEqChave((string) $pg)] = true;
            } else {
                $pequenas[escEqChave((string) $pg)] = true;
            }
        }
        $parteHoje[$p['dono']] = ($parteHoje[$p['dono']] ?? 0) + $p['fat'];
        $parteNova[$os] += $p['fat'];
        $qtdPostos[$os]++;
        $saida[$id] = $p + ['os' => $os, 'os_cob' => $cob, 'grande' => $cima, 'muda' => $p['dono'] !== $os || $p['cob'] !== $cob];
        $i++;
    }
    // Tamanho de cada núcleo pelos postos dele (o que sobra do arredondamento vai para o que tem mais postos).
    $n = count($srs);
    $tam = [];
    $somaP = max(1, array_sum($qtdPostos));
    $resto = $n;
    foreach ($qtdPostos as $k => $q) {
        $tam[$k] = (int) floor($n * $q / $somaP);
        $resto -= $tam[$k];
    }
    $ordemResto = $qtdPostos;
    arsort($ordemResto);
    foreach (array_keys($ordemResto) as $k) {
        if ($resto <= 0) {
            break;
        }
        $tam[$k]++;
        $resto--;
    }
    // OS1: os melhores. OS2: dos que sobram, quem conhece as duas pontas (menor dos dias em páginas
    // grandes e em pequenas), depois nível e mérito. OS3: os demais.
    $melhor = static fn($a, $b) => [$b['nivel'], $b['nota'] ?? -1] <=> [$a['nivel'], $a['nota'] ?? -1];
    $dias = static fn(array $s, array $set) => array_sum(array_intersect_key($s['conhece'] ?? [], $set));
    uasort($srs, $melhor);
    $srsOut = [];
    foreach (array_slice($srs, 0, $tam[1], true) as $rep => $s) {
        $srsOut[$rep] = $s + ['novo' => 1];
    }
    $resto = array_diff_key($srs, $srsOut);
    foreach ($resto as $rep => &$s) {
        $s['mistura'] = min($dias($s, $grandes), $dias($s, $pequenas));
    }
    unset($s);
    uasort($resto, static fn($a, $b) => $b['mistura'] <=> $a['mistura'] ?: $melhor($a, $b));
    $k = 0;
    foreach ($resto as $rep => $s) {
        $srsOut[$rep] = $s + ['novo' => $k < $tam[2] ? 2 : 3];
        $k++;
    }
    $porcento = static fn(array $v) => array_map(static fn($x) => $total > 0 ? round($x / $total, 4) : 0.0, $v);
    return ['postos' => $saida, 'parte_hoje' => $porcento($parteHoje), 'parte_nova' => $porcento($parteNova), 'postos_por_os' => $qtdPostos,
        'tamanho' => $tam, 'srs' => $srsOut];
}

// Dados do banco para a tela: faturamento por coordenador, SRs, páginas com maturação e postos.
// $dia: a estrutura (postos, núcleos, SRs) desse dia; o faturamento é sempre o dos 30 dias até $hoje.
function escEqDados(string $hoje, ?string $dia = null): array {
    $dia = max($dia ?? $hoje, escV4DiaEstrutura());
    $vig = ' vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)';
    $fatPag = escEqFaturamento($hoje);
    $unidadeDe = [];
    foreach (escQ('SELECT carteira, unidade FROM `5c_planning`.`fc_carteira`') as $c) {
        $unidadeDe[$c['carteira']] = (int) $c['unidade'];
    }
    // Página → unidade hoje e desde quando está nela (linhas seguidas da carteira na mesma unidade).
    $linhas = [];
    foreach (escQ('SELECT pagina, carteira, modelo, vigente_desde, vigente_ate FROM `5c_planning`.`fc_pagina_carteira` ORDER BY pagina, vigente_desde') as $l) {
        $linhas[$l['pagina']][] = $l;
    }
    $maturacao = (int) (escPolParam()['pol_prazo_acomp'] ?? 28);
    $paginas = [];
    foreach ($linhas as $nome => $ls) {
        $atual = null;
        foreach ($ls as $l) {
            if (substr((string) $l['vigente_desde'], 0, 10) <= $dia && ($l['vigente_ate'] === null || substr((string) $l['vigente_ate'], 0, 10) > $dia)) {
                $atual = $l;
            }
        }
        if (!$atual || !isset($unidadeDe[$atual['carteira']])) {
            continue;
        }
        $u = $unidadeDe[$atual['carteira']];
        $desde = substr((string) $atual['vigente_desde'], 0, 10);
        foreach (array_reverse($ls) as $l) {
            if (substr((string) $l['vigente_desde'], 0, 10) < $desde && ($unidadeDe[$l['carteira']] ?? null) === $u
                && $l['vigente_ate'] !== null && substr((string) $l['vigente_ate'], 0, 10) >= escMaisDias($desde, -1)) {
                $desde = substr((string) $l['vigente_desde'], 0, 10);
            }
        }
        $f = $fatPag[escEqChave((string) $nome)] ?? [];
        // Modelo: a do Capacity; sem ela, o nome da página sem perfil e código (Mikayla Free + Paid).
        $grupo = trim((string) $atual['modelo']) !== '' ? escFunilChave((string) $atual['modelo']) : escPaginaChaveModelo((string) $nome);
        $paginas[$nome] = ['unidade' => $u, 'carteira' => $atual['carteira'], 'grupo' => $grupo, 'desde' => $desde, 'madura_em' => escMaisDias($desde, $maturacao),
            'fat' => ['A' => (float) ($f['A'] ?? 0), 'C' => (float) ($f['C'] ?? 0), 'D' => (float) ($f['D'] ?? 0)]];
    }
    $srs = [];
    $pessoas = [];
    foreach (escQ('SELECT UPPER(c.rep_code) AS rep, c.unidade, c.turno, c.nivel, n.numero FROM ' . escT('esc_colaborador_config') . ' c JOIN ' . escT('esc_nucleo') . ' n ON n.id = c.nucleo_id
                    WHERE c.funcao = \'EQUITE\' AND c.vigente_desde <= ? AND (c.vigente_ate IS NULL OR c.vigente_ate >= ?)', [$dia, $dia]) as $c) {
        $srs[(int) $c['unidade']][$c['turno']] = ($srs[(int) $c['unidade']][$c['turno']] ?? 0) + 1;
        $pessoas[(int) $c['unidade']][$c['turno']][$c['rep']] = ['num' => (int) $c['numero'], 'nivel' => (int) $c['nivel'], 'nota' => null];
    }
    $notas = array_change_key_case(escMeritoNotasMotor(escMeritoCarregar()), CASE_UPPER);   // sem mérito: só o nível ordena
    // Páginas que cada SR conhece: dias escalado nelas nas últimas 8 semanas (5C Schedule).
    $conhece = [];
    $reps = [];
    foreach ($pessoas as $porT) {
        foreach ($porT as $ps) {
            $reps += array_fill_keys(array_keys($ps), true);
        }
    }
    foreach (array_chunk(array_keys($reps), 400) as $lote) {
        try {
            foreach (escQ('SELECT UPPER(codigo) AS rep, modeloNome AS pagina, COUNT(DISTINCT LEFT(shiftDateAndHour, 10)) AS dias FROM `5c_schedule`.`scheduleday`
                            WHERE codigo IN (' . escIn($lote) . ') AND shiftDateAndHour >= ? GROUP BY UPPER(codigo), modeloNome',
                array_merge($lote, [escMaisDias($dia, -56) . 'T00:00:00'])) as $h) {
                $k = escEqChave((string) $h['pagina']);
                $conhece[$h['rep']][$k] = ($conhece[$h['rep']][$k] ?? 0) + (int) $h['dias'];
            }
        } catch (Throwable $e) {
            appLog('warning', 'Equilíbrio: sem histórico do 5C Schedule', ['erro' => $e->getMessage()]);
        }
    }
    foreach ($pessoas as &$porT) {
        foreach ($porT as &$ps) {
            foreach ($ps as $rep => &$p) {
                $p['nota'] = $notas[$rep] ?? null;
                $p['conhece'] = $conhece[$rep] ?? [];
            }
            unset($p);
        }
        unset($ps);
    }
    unset($porT);
    // Quem conhece cada página, por unidade (v4_conhece_dias ou mais dias nela em 8 semanas).
    $cDias = max(1, (int) escV4Param('v4_conhece_dias', '3'));
    $porChave = [];
    foreach ($pessoas as $u => $porT) {
        foreach ($porT as $t => $ps) {
            foreach ($ps as $rep => $p) {
                foreach ($p['conhece'] as $k => $d) {
                    if ($d >= $cDias) {
                        $porChave[$k][] = ['rep' => $rep, 'unidade' => $u, 'turno' => $t, 'dias' => $d];
                    }
                }
            }
        }
    }
    foreach ($paginas as $nome => &$pg) {
        $quem = $porChave[escEqChave((string) $nome)] ?? [];
        usort($quem, static fn($a, $b) => $b['dias'] <=> $a['dias']);
        $pg['conhecedores'] = $quem;
        $pg['conhece'] = array_count_values(array_column($quem, 'unidade'));
    }
    unset($pg);
    ksort($srs);
    $fatUT = [];
    foreach ($paginas as $p) {
        foreach ($p['fat'] as $t => $v) {
            $fatUT[$p['unidade']][$t] = ($fatUT[$p['unidade']][$t] ?? 0) + $v;
        }
    }
    // Postos de cada unidade e turno com o faturamento do turno (página × fração no posto).
    $nucleoNum = [];
    $nucleoId = [];
    foreach (escQ('SELECT id, unidade, turno, numero FROM ' . escT('esc_nucleo') . ' WHERE' . $vig, [$dia, $dia]) as $n) {
        $nucleoNum[(int) $n['id']] = (int) $n['numero'];
        $nucleoId[(int) $n['unidade']][$n['turno']][(int) $n['numero']] = (int) $n['id'];
    }
    $postos = [];
    foreach (escQ('SELECT p.id, p.unidade, p.turno, p.nucleo_dono_id, p.nucleo_cobertura_id, pp.pagina, pp.fracao FROM ' . escT('esc_posto') . ' p JOIN ' . escT('esc_posto_pagina') . ' pp ON pp.posto_id = p.id
                    WHERE p.' . ltrim($vig), [$dia, $dia]) as $l) {
        $u = (int) $l['unidade'];
        $t = $l['turno'];
        $id = (int) $l['id'];
        $postos[$u][$t][$id] = $postos[$u][$t][$id] ?? ['id' => $id, 'fat' => 0.0, 'paginas' => [], 'dono' => $nucleoNum[(int) $l['nucleo_dono_id']] ?? 0, 'cob' => $nucleoNum[(int) $l['nucleo_cobertura_id']] ?? 0];
        $postos[$u][$t][$id]['fat'] += (float) ($fatPag[escEqChave((string) $l['pagina'])][$t] ?? 0) * (float) ($l['fracao'] ?: 1);
        $postos[$u][$t][$id]['paginas'][] = $l['pagina'];
    }
    ksort($postos);
    // Postos com páginas juntadas pelo Capacity (fc_postos.forcado, regra dos 32k/16k): a divisão dos
    // OS move o posto inteiro, sem separar as páginas. Na troca de unidade só a modelo vai junta; as
    // juntadas se encadeiam pela unidade toda e o Capacity refaz as combinações no recálculo da troca.
    return ['dia' => $dia, 'paginas' => $paginas, 'srs' => $srs, 'pessoas' => $pessoas, 'fat' => $fatUT, 'postos' => $postos, 'nucleo_id' => $nucleoId];
}

// Conferência com o Capacity (vw_fc_19_faturamento_unidades, script 23 do Capacity): faturamento
// bruto de 30 dias por unidade na última versão. Alerta quando a unidade foge mais de 30% da média.
// Vazio se a visão ainda não existe.
function escEqCapacityUnidades(): array {
    try {
        $out = [];
        foreach (escQ("SELECT `Unidade` AS u, `Bruto 30 dias` AS bruto, `Versão` AS versao FROM `5c_planning`.`vw_fc_19_faturamento_unidades` WHERE `Carteira` = 'Unidade'") as $l) {
            $out[(int) substr((string) $l['u'], 1)] = ['bruto' => (float) $l['bruto'], 'versao' => $l['versao']];
        }
        $media = $out ? array_sum(array_column($out, 'bruto')) / count($out) : 0;
        foreach ($out as &$x) {
            $x['desvio'] = $media > 0 ? $x['bruto'] / $media - 1 : null;
            $x['alerta'] = $x['desvio'] !== null && abs($x['desvio']) > 0.30;
        }
        unset($x);
        ksort($out);
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

// Faturamento total de cada coordenador contra a média, para o Funil priorizar a unidade abaixo.
function escEqDesvioUT(string $hoje): array {
    try {
        $e = escEqDados($hoje);
        return escEqDesvios($e['fat'], escEqPesoTotal($e['fat'], $e['srs']))['desvio'];
    } catch (Throwable $e) {
        appLog('warning', 'Equilíbrio: sem dados para o Funil', ['erro' => $e->getMessage()]);
        return [];
    }
}

// Aplica a divisão dos OS numa unidade e turno a partir de $desde: núcleo dono e de cobertura de
// cada posto, os SRs nos núcleos propostos e as equipes que deixaram de bater com o núcleo saem
// (a geração seguinte completa as vagas pela regra de equipes). Rodar fora de outra transação.
function escEqAplicarDivisao(int $u, string $t, string $desde): array {
    if (escModoTreino()) {
        throw new RuntimeException(t('No Modo Treino a divisão dos OS não é aplicada. Use a prévia para ver o efeito.'));
    }
    $e = escEqDados(date('Y-m-d'), $desde);
    $ids = $e['nucleo_id'][$u][$t] ?? [];
    if (count($ids) < 3) {
        throw new RuntimeException(t('A U{u} não tem os 3 núcleos no turno {turno}.', ['u' => $u, 'turno' => $t]));
    }
    $div = escEqDivisao($e['postos'][$u][$t] ?? [], $e['pessoas'][$u][$t] ?? []);
    $ontem = escMaisDias($desde, -1);
    $neutra = escMaisDias(escV4DiaVirada(), -2);
    $res = ['postos' => 0, 'srs' => 0, 'equipe_fora' => 0];
    escTx(static function () use ($div, $ids, $desde, $ontem, $neutra, $u, $t, &$res) {
        // 1. Postos: novo dono e cobertura (posto que já valia antes: fecha e abre a cópia a partir da data).
        foreach ($div['postos'] as $id => $p) {
            if (!$p['muda']) {
                continue;
            }
            $orig = escQ1('SELECT * FROM ' . escT('esc_posto') . ' WHERE id = ?', [$id]);
            if (substr((string) $orig['vigente_desde'], 0, 10) >= $desde) {
                escW('UPDATE ' . escT('esc_posto') . ' SET nucleo_dono_id = ?, nucleo_cobertura_id = ? WHERE id = ?', [$ids[$p['os']], $ids[$p['os_cob']], $id]);
            } else {
                escW('INSERT INTO ' . escT('esc_posto') . ' (versao, unidade, carteira, turno, seq, tipo, pagina_principal, n_paginas, assinatura, carga, capacidade, nucleo_dono_id, nucleo_cobertura_id, vigente_desde, vigente_ate)
                      SELECT ?, unidade, carteira, turno, seq, tipo, pagina_principal, n_paginas, assinatura, carga, capacidade, ?, ?, ?, vigente_ate FROM ' . escT('esc_posto') . ' WHERE id = ?',
                    ['OS' . date('Ymd_His'), $ids[$p['os']], $ids[$p['os_cob']], $desde, $id]);
                $novo = escUltimoId();
                escW('INSERT INTO ' . escT('esc_posto_pagina') . ' (posto_id, pagina, page_id, fracao, fans_h, apoio) SELECT ?, pagina, page_id, fracao, fans_h, apoio FROM ' . escT('esc_posto_pagina') . ' WHERE posto_id = ?', [$novo, $id]);
                // A equipe segue no posto novo; quem não bater com o núcleo sai no passo 3.
                escW('INSERT INTO ' . escT('esc_vaga_equipe') . ' (posto_id, vaga, rep_code, fatia, ordem, status, motivo, vigente_desde, vigente_ate, origem, criado_por)
                      SELECT ?, vaga, rep_code, fatia, ordem, status, motivo, ?, vigente_ate, origem, criado_por FROM ' . escT('esc_vaga_equipe') . ' WHERE posto_id = ? AND vigente_desde < ? AND (vigente_ate IS NULL OR vigente_ate >= ?)',
                    [$novo, $desde, $id, $desde, $desde]);
                escW('UPDATE ' . escT('esc_vaga_equipe') . ' SET vigente_ate = ? WHERE posto_id = ? AND vigente_desde < ? AND (vigente_ate IS NULL OR vigente_ate >= ?)', [$ontem, $id, $desde, $desde]);
                escW('UPDATE ' . escT('esc_vaga_equipe') . ' SET posto_id = ? WHERE posto_id = ? AND vigente_desde >= ?', [$novo, $id, $desde]);
                escW('UPDATE ' . escT('esc_posto') . ' SET vigente_ate = ? WHERE id = ?', [$ontem, $id]);
            }
            $res['postos']++;
        }
        // 2. SRs no núcleo proposto (cadastro novo a partir da data; o de antes fecha na véspera).
        foreach ($div['srs'] as $rep => $s) {
            if ($s['novo'] === $s['num']) {
                continue;
            }
            $c = escQ1('SELECT * FROM ' . escT('esc_colaborador_config') . " WHERE UPPER(rep_code) = ? AND funcao = 'EQUITE' AND vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?) ORDER BY vigente_desde DESC LIMIT 1", [$rep, $desde, $desde]);
            if (!$c) {
                continue;
            }
            $obs = mb_substr('Equilíbrio dos OS: OS' . $s['num'] . ' → OS' . $s['novo'] . ' a partir de ' . date('d/m/Y', strtotime($desde)), 0, 255);
            if (substr((string) $c['vigente_desde'], 0, 10) >= $desde) {
                escW('UPDATE ' . escT('esc_colaborador_config') . ' SET nucleo_id = ?, observacao = ? WHERE id = ?', [$ids[$s['novo']], $obs, (int) $c['id']]);
            } else {
                escW('UPDATE ' . escT('esc_colaborador_config') . ' SET vigente_ate = ? WHERE id = ?', [$ontem, (int) $c['id']]);
                escW('INSERT INTO ' . escT('esc_colaborador_config') . ' (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, vigente_desde, vigente_ate, observacao, origem, criado_por)
                      SELECT rep_code, modelo_id, tipo, turno, unidade, ?, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, ?, ?, ?, \'TELA\', ? FROM ' . escT('esc_colaborador_config') . ' WHERE id = ?',
                    [$ids[$s['novo']], $desde, $c['vigente_ate'], $obs, escUserId() ?: null, (int) $c['id']]);
            }
            $res['srs']++;
        }
        // 3. Equipe que não bate mais com o núcleo da vaga sai a partir da data.
        $numDe = array_flip($ids);
        $novoDe = array_map(static fn($s) => $s['novo'], $div['srs']);
        foreach (escQ('SELECT v.id, v.vaga, UPPER(v.rep_code) AS rep, v.vigente_desde, p.nucleo_dono_id, p.nucleo_cobertura_id FROM ' . escT('esc_vaga_equipe') . ' v JOIN ' . escT('esc_posto') . ' p ON p.id = v.posto_id
                        WHERE p.unidade = ? AND p.turno = ? AND (p.vigente_ate IS NULL OR p.vigente_ate >= ?) AND (v.vigente_ate IS NULL OR v.vigente_ate >= ?)', [$u, $t, $desde, $desde]) as $v) {
            $nucVaga = $numDe[(int) ($v['vaga'] === 'DONO' ? $v['nucleo_dono_id'] : $v['nucleo_cobertura_id'])] ?? null;
            if ($nucVaga === null || !isset($novoDe[$v['rep']]) || $novoDe[$v['rep']] === $nucVaga) {
                continue;
            }
            if (substr((string) $v['vigente_desde'], 0, 10) >= $desde) {
                escVagaNeutra((int) $v['id'], $neutra);
            } else {
                escW('UPDATE ' . escT('esc_vaga_equipe') . ' SET vigente_ate = ? WHERE id = ?', [$ontem, (int) $v['id']]);
            }
            $res['equipe_fora']++;
        }
    });
    escLog('equilibrio.divisao', 'esc_posto', 'U' . $u . $t, null, $res + ['desde' => $desde, 'parte' => $div['parte_nova']]);
    return $res;
}
