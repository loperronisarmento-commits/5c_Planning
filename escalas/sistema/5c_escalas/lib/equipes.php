<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/equipes.php
// Nenhum posto aberto fica sem equipe fixa. Antes de cada geração (escMotorGerar), a vaga
// de dono ou de cobertura sem ninguém recebe SRs do mesmo turno e unidade, nesta ordem:
//   1. do núcleo da vaga, dentro da fatia do nível (quem tem folga);
//   2. de outro núcleo da unidade e turno, dentro da fatia;
//   3. níveis 0–2 acima da fatia, até 1 (usam os dias de buffer), do núcleo e depois dos outros;
//   4. se ainda ninguém: quem do núcleo (ou do turno) estiver menos carregado, mesmo acima de 1.
// Dentro de cada passo vem quem conhece as páginas do posto (dias no 5C Schedule em 8
// semanas), depois a nota de mérito. A equipe é preferência: se faltar gente no dia, o
// gerador continua priorizando as páginas pela mecânica normal (a página pode ficar vazia
// num dia), mas o posto sempre tem a quem chamar. Mesmas regras do montador (gerar_es17.py):
// dono precisa de 1 pessoa inteira; cobertura de um posto só, de meia (a fatia gravada é
// a parte do posto: uso de 0,5 grava 1,0).
// ===============================================

// Vaga de equipe que nunca chegou a valer vai para a data neutra (antes da virada, onde o motor
// não lê). Se a mesma pessoa já tem essa linha neutra na mesma vaga, a repetida é apagada
// (a chave única é posto + vaga + pessoa + início).
function escVagaNeutra(int $id, string $neutra): void {
    // Pela conexão de gravação: dentro de uma transação, vê as vagas que ela acabou de criar ou mudar.
    $v = escQW('SELECT posto_id, vaga, rep_code FROM ' . escT('esc_vaga_equipe') . ' WHERE id = ?', [$id])[0] ?? null;
    if (!$v) {
        return;
    }
    $ja = escQW('SELECT 1 AS ok FROM ' . escT('esc_vaga_equipe') . ' WHERE posto_id = ? AND vaga = ? AND rep_code = ? AND vigente_desde = ? AND id <> ?',
        [(int) $v['posto_id'], $v['vaga'], $v['rep_code'], $neutra, $id]);
    if ($ja) {
        escW('DELETE FROM ' . escT('esc_vaga_equipe') . ' WHERE id = ?', [$id]);
        return;
    }
    escW('UPDATE ' . escT('esc_vaga_equipe') . ' SET vigente_desde = ?, vigente_ate = ? WHERE id = ?', [$neutra, $neutra, $id]);
}

// Configuração marcada para depois de $desde (ex.: remanejamento a partir de 09/11): a mudança
// de agora vale daí em diante, então a futura é cancelada (nunca chegou a valer), a que vale em
// $desde volta a valer sem fim e as vagas fixas que começariam junto com a futura (ou depois)
// vão para a data neutra. Usada por Colaboradores, Roster e Solicitações; rodar dentro de escTx.
// As canceladas ficam em $GLOBALS['ESC_CONFIG_CANCELADAS'] para a mensagem (escConfigCanceladasAviso).
function escConfigCancelarFuturas(string $rep, string $desde): array {
    $rep = strtoupper($rep);
    $tab = escT('esc_colaborador_config');
    $futuras = escQW('SELECT * FROM ' . $tab . ' WHERE UPPER(rep_code) = ? AND vigente_desde > ? ORDER BY vigente_desde FOR UPDATE', [$rep, $desde]);
    if (!$futuras) {
        return [];
    }
    unset($GLOBALS['ESC_OS_DO_DIA']);
    foreach ($futuras as $f) {
        escW('DELETE FROM ' . $tab . ' WHERE id = ?', [(int) $f['id']]);
    }
    escW('UPDATE ' . $tab . ' SET vigente_ate = NULL, atualizado_por = ? WHERE UPPER(rep_code) = ? AND vigente_desde <= ? AND vigente_ate >= ?', [escUserId(), $rep, $desde, $desde]);
    $neutra = escMaisDias(escV4DiaVirada(), -2);
    foreach (escQW('SELECT id FROM ' . escT('esc_vaga_equipe') . ' WHERE UPPER(rep_code) = ? AND vigente_desde >= ?', [$rep, substr((string) $futuras[0]['vigente_desde'], 0, 10)]) as $v) {
        escVagaNeutra((int) $v['id'], $neutra);
    }
    foreach ($futuras as $f) {
        $GLOBALS['ESC_CONFIG_CANCELADAS'][] = $f;
    }
    return $futuras;
}

// Grava a configuração de uma pessoa a partir de $desde (o POST de Colaboradores e o "Cadastrar todos"
// das Turmas de treinamento). $novo: modelo_id, tipo, turno, trilha, folgas_fixas, pode_assistente,
// observacao e, na estrutura nova, unidade, nucleo_id e nivel. $nucleo: a linha de esc_nucleo ou null.
// Cancela a configuração marcada para depois, fecha (ou atualiza, se começa no mesmo dia) a que vale
// na data e, se trocou de núcleo, fecha as vagas fixas do antigo. Devolve quantas vagas fecharam.
function escColaboradorGravar(string $rep, array $novo, string $desde, ?array $nucleo): int {
    $vagasFechadas = 0;
    escTx(function () use ($rep, $novo, $desde, $nucleo, &$vagasFechadas) {
        // Configuração marcada para depois da data: é cancelada e vale a de agora.
        $neutra = escMaisDias(escV4DiaVirada(), -2);
        $canceladas = escConfigCancelarFuturas($rep, $desde);
        // A configuração que vale NA data informada (não a mais nova).
        $atual = escQW('SELECT * FROM ' . escT('esc_colaborador_config') . ' WHERE rep_code = ? AND vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?) ORDER BY vigente_desde DESC LIMIT 1', [$rep, $desde, $desde]);
        $atual = $atual[0] ?? null;
        // Sem núcleo no formulário, a nova vigência leva junto a estrutura que já tinha.
        $est = [
            'unidade' => $novo['unidade'] ?? $atual['unidade'] ?? null,
            'nucleo_id' => $novo['nucleo_id'] ?? $atual['nucleo_id'] ?? null,
            'nivel' => array_key_exists('nivel', $novo) ? $novo['nivel'] : ($atual['nivel'] ?? null),
            'funcao' => $atual['funcao'] ?? ($nucleo ? 'EQUITE' : null),
        ];
        // SR 3 ou mais não trabalha como assistente (v4_nivel_max_assistente): grava sempre "não".
        if ($est['nivel'] !== null && (int) $est['nivel'] > (int) escV4Param('v4_nivel_max_assistente', '2')) {
            $novo['pode_assistente'] = 0;
        }
        if ($atual && substr($atual['vigente_desde'], 0, 10) === $desde) {
            escW('UPDATE ' . escT('esc_colaborador_config') . ' SET modelo_id = ?, tipo = ?, turno = ?, trilha = ?, folgas_fixas = ?, pode_assistente = ?, unidade = ?, nucleo_id = ?, nivel = ?, funcao = ?, observacao = ?, origem = \'TELA\', atualizado_por = ? WHERE id = ?',
                [$novo['modelo_id'], $novo['tipo'], $novo['turno'], $novo['trilha'], $novo['folgas_fixas'], $novo['pode_assistente'], $est['unidade'], $est['nucleo_id'], $est['nivel'], $est['funcao'], $novo['observacao'], escUserId(), (int) $atual['id']]);
        } else {
            if ($atual) {
                escW('UPDATE ' . escT('esc_colaborador_config') . ' SET vigente_ate = ?, atualizado_por = ? WHERE id = ?', [escMaisDias($desde, -1), escUserId(), (int) $atual['id']]);
            }
            escW('INSERT INTO ' . escT('esc_colaborador_config') . ' (rep_code, modelo_id, tipo, turno, trilha, ancora, folgas_fixas, pode_assistente, unidade, nucleo_id, nivel, funcao, fatia_regular, vigente_desde, observacao, origem, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'TELA\', ?)',
                [$rep, $novo['modelo_id'], $novo['tipo'], $novo['turno'], $novo['trilha'], $atual['ancora'] ?? null, $novo['folgas_fixas'], $novo['pode_assistente'],
                 $est['unidade'], $est['nucleo_id'], $est['nivel'], $est['funcao'], $atual['fatia_regular'] ?? null,
                 $desde, $novo['observacao'], escUserId()]);
        }
        // Trocou de núcleo: as vagas fixas do núcleo antigo fecham na véspera (o motor cobre).
        // A vaga que só começava na data ou depois nunca valeu: vai para a data neutra.
        if ($nucleo && $atual && (int) $atual['nucleo_id'] !== (int) $nucleo['id']) {
            foreach (escQW('SELECT id, vigente_desde FROM ' . escT('esc_vaga_equipe') . ' WHERE rep_code = ? AND (vigente_ate IS NULL OR vigente_ate >= ?)', [$rep, $desde]) as $v) {
                if (substr((string) $v['vigente_desde'], 0, 10) >= $desde) {
                    escVagaNeutra((int) $v['id'], $neutra);
                } else {
                    escW('UPDATE ' . escT('esc_vaga_equipe') . ' SET vigente_ate = ?, motivo = LEFT(CONCAT(\'trocou de núcleo; \', COALESCE(motivo, \'\')), 255) WHERE id = ?', [escMaisDias($desde, -1), (int) $v['id']]);
                }
                $vagasFechadas++;
            }
        }
        escLog('colaborador.salvar', 'esc_colaborador_config', $rep, $atual, $novo + ['vigente_desde' => $desde, 'vagas_fechadas' => $vagasFechadas, 'canceladas' => $canceladas]);
    });
    return $vagasFechadas;
}

// " A configuração que estava marcada para depois foi cancelada: 09/11 (U1 · D · núcleo 2)." ou ''.
function escConfigCanceladasAviso(): string {
    $lista = [];
    foreach ($GLOBALS['ESC_CONFIG_CANCELADAS'] ?? [] as $c) {
        $n = $c['nucleo_id'] ? escQ1('SELECT unidade, numero FROM ' . escT('esc_nucleo') . ' WHERE id = ?', [(int) $c['nucleo_id']]) : null;
        $lista[] = strtoupper((string) $c['rep_code']) . ' ' . dataBr(substr((string) $c['vigente_desde'], 0, 10))
            . ($n ? ' (U' . (int) $n['unidade'] . ' · ' . $c['turno'] . ' · ' . t('núcleo {n}', ['n' => (int) $n['numero']]) . ')' : ' (' . $c['turno'] . ')');
    }
    return $lista ? ' ' . t('A configuração que estava marcada para depois foi cancelada: {lista}.', ['lista' => implode(', ', $lista)]) : '';
}

// Fatia do nível: 0 = 0,4; 1 = 0,6; 2 = 0,8; 3 a 5 = 1 (ou a fatia regular do cadastro).
function escEquipeFatiaNivel(?float $regular, int $nivel): float {
    if ($regular !== null && $regular > 0) {
        return min(1.0, $regular);
    }
    return $nivel >= 3 ? 1.0 : 0.4 + 0.2 * max(0, $nivel);
}

// Escolhe quem entra na vaga. $cands = [rep => ['nucleo', 'nivel', 'cap', 'uso']], $vaga = ['nucleo',
// 'precisa', 'paginas'], $hist = [rep][pagina] = dias, $notas = [rep] = nota. Devolve
// [[rep, uso que assume, passo]] (vazio só se não houver nenhum SR no turno). Função pura.
// Desde a 1.39.0, $vaga['bloq'] = [rep => true] não entra (nível 0 do KPI ou new hire em página grande) e,
// com $vaga['por_score'] (posto de página prioritária), o score do KPI vem antes de quem conhece as páginas.
function escEquipeEscolher(array $cands, array $vaga, array $hist, array $notas): array {
    $bloq = $vaga['bloq'] ?? [];
    $cands = array_diff_key($cands, $bloq);
    $porScore = !empty($vaga['por_score']);
    $conhece = static function (string $rep) use ($vaga, $hist): int {
        $d = 0;
        foreach ($vaga['paginas'] as $pg) {
            $d += min(28, $hist[$rep][$pg] ?? 0);
        }
        return $d;
    };
    $falta = $vaga['precisa'];
    // Quem conhece as páginas primeiro; vaga inteira vai para nível 3–5 (fixo numa vaga cheia) e o
    // resto de uma vaga já começada, para níveis 0–2 (que dividem a fatia em até 2 vagas).
    $ordem = static function (array $reps, callable $disp) use ($conhece, $notas, $cands, &$falta, $porScore): array {
        $k = $porScore
            ? static fn($r) => [$notas[$r] ?? -1, $conhece($r), ($cands[$r]['nivel'] >= 3) === ($falta >= 0.999) ? 1 : 0, $disp($r)]
            : static fn($r) => [$conhece($r), ($cands[$r]['nivel'] >= 3) === ($falta >= 0.999) ? 1 : 0, $notas[$r] ?? -1, $disp($r)];
        usort($reps, static fn($a, $b) => $k($b) <=> $k($a));
        return $reps;
    };
    $escolha = [];
    $passos = [
        1 => [static fn($c) => $c['nucleo'] === $vaga['nucleo'], static fn($c) => $c['cap'] - $c['uso']],
        2 => [static fn($c) => $c['nucleo'] !== $vaga['nucleo'], static fn($c) => $c['cap'] - $c['uso']],
        3 => [static fn($c) => $c['nivel'] < 3, static fn($c) => 1.0 - max($c['uso'], $c['cap'])],
    ];
    $uso = array_map(static fn($c) => $c['uso'], $cands);
    foreach ($passos as $passo => [$filtro, $folga]) {
        $disp = static fn(string $r) => $folga(['uso' => $uso[$r]] + $cands[$r]);
        $reps = array_keys(array_filter($cands, static fn($c, $r) => $filtro($c) && $disp((string) $r) >= 0.2 - 1e-9 && !isset($escolha[$r]), ARRAY_FILTER_USE_BOTH));
        // Um por vez: a ordem muda quando a vaga deixa de ser inteira. No passo 3, o núcleo da vaga primeiro.
        while ($reps) {
            if ($falta <= 1e-6 || count($escolha) >= 3) {
                break 2;
            }
            $mesmo = $passo === 3 ? array_values(array_filter($reps, static fn($r) => $cands[$r]['nucleo'] === $vaga['nucleo'])) : [];
            $r = (string) $ordem($mesmo ?: $reps, $disp)[0];
            $reps = array_values(array_diff($reps, [$r]));
            $f = round(min($falta, $disp($r)), 3);
            $escolha[$r] = [(string) $r, $f, $passo];
            $uso[$r] += $f;
            $falta -= $f;
        }
    }
    if (!$escolha && $cands) {
        // Passo 4: ninguém com folga. Quem estiver menos carregado, do núcleo da vaga se houver.
        $mesmo = array_keys(array_filter($cands, static fn($c) => $c['nucleo'] === $vaga['nucleo']));
        $reps = $mesmo ?: array_keys($cands);
        usort($reps, static fn($a, $b) => [$cands[$a]['uso'] - $cands[$a]['cap'], -$conhece($a)] <=> [$cands[$b]['uso'] - $cands[$b]['cap'], -$conhece($b)]);
        $escolha[$reps[0]] = [(string) $reps[0], $vaga['precisa'], 4];
    }
    return array_values($escolha);
}

// Postos de página grande (1.39.0): [prioritária [posto_id => true], maior faturamento de página [posto_id => US$]].
// Prioritária: a modelo de alguma página do posto fatura v4_pagina_prioritaria_min (US$ 100 mil) ou mais em 30 dias
// (como no motor); o maior faturamento de 30 dias entre as páginas do posto vai contra o teto da rampa do new hire.
// $paginas = [posto][] = nome.
function escEquipesPostosGrandes(array $paginas): array {
    $nomes = [];
    foreach ($paginas as $lista) {
        foreach ($lista as $pg) {
            $nomes[$pg] = true;
        }
    }
    if (!$nomes || !function_exists('escV4ReceitaPaginas')) {
        return [[], []];
    }
    $receita = escV4ReceitaPaginas(array_keys($nomes), (int) escV4Param('v4_receita_dias', '30'));
    $modeloPag = [];
    foreach (escQ('SELECT pagina, modelo FROM `5c_planning`.`fc_pagina_carteira` WHERE vigente_ate IS NULL AND pagina IN (' . escIn(array_keys($nomes)) . ')', array_keys($nomes)) as $r) {
        $modeloPag[$r['pagina']] = $r['modelo'];
    }
    $porModelo = [];
    foreach ($receita as $pg => $r) {
        $m = $modeloPag[$pg] ?? $pg;
        $porModelo[$m] = ($porModelo[$m] ?? 0) + (float) ($r['total'] ?? 0);
    }
    $minPrio = (float) str_replace(',', '.', escV4Param('v4_pagina_prioritaria_min', '100000'));
    $prio = [];
    $maior = [];
    foreach ($paginas as $pid => $lista) {
        foreach ($lista as $pg) {
            if (($porModelo[$modeloPag[$pg] ?? $pg] ?? 0) >= $minPrio) {
                $prio[$pid] = true;
            }
            $maior[$pid] = max($maior[$pid] ?? 0.0, (float) ($receita[$pg]['total'] ?? 0));
        }
    }
    return [$prio, $maior];
}

// Completa as vagas sem ninguém dos postos vigentes em $ref, a partir de $desde (nunca antes do
// início do posto). Rodar fora de outra transação. Devolve a lista do que foi feito.
function escEquipesCompletar(string $desde, string $ref): array {
    $vig = ' vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)';
    $postos = escQ('SELECT id, unidade, turno, carga, nucleo_dono_id, nucleo_cobertura_id, vigente_desde, vigente_ate FROM ' . escT('esc_posto') . ' WHERE' . $vig . ' ORDER BY carga DESC, id', [$ref, $ref]);
    if (!$postos) {
        return [];
    }
    $idsVig = array_flip(array_map(static fn($p) => (int) $p['id'], $postos));
    // Páginas dos postos vigentes, a página grande (prioritária: modelo com v4_pagina_prioritaria_min ou mais
    // em 30 dias) e a acima do Tier E (página com mais de v4_novato_receita_max), 1.39.0.
    $paginas = [];
    foreach (escQ('SELECT posto_id, pagina FROM ' . escT('esc_posto_pagina') . ' WHERE posto_id IN (' . escIn(array_keys($idsVig)) . ')', array_keys($idsVig)) as $g) {
        $paginas[(int) $g['posto_id']][] = $g['pagina'];
    }
    [$postoPrio, $postoMaior] = escEquipesPostosGrandes($paginas);
    $nivelKpi = [];
    try {
        $nivelKpi = escDesempenhoMotor()['faixa'] ?? [];
    } catch (Throwable $e) {
        // sem KPI: só o new hire é barrado
    }
    $cargo = []; // [rep] = nível do cargo vigente em $ref
    foreach (escQ('SELECT UPPER(rep_code) AS rep, nivel FROM ' . escT('esc_colaborador_config') . " WHERE funcao = 'EQUITE' AND" . $vig . ' ORDER BY vigente_desde', [$ref, $ref]) as $c) {
        $cargo[$c['rep']] = $c['nivel'] !== null ? (int) $c['nivel'] : null;
    }
    // Rampa do new hire (semana na operação em $desde): 1ª semana fora das equipes (assistente ou buffer);
    // depois, só posto com todas as páginas até o teto da semana (Tier F na 2ª, Tier E da 3ª em diante).
    $novIni = escNovatoInicio(array_keys(array_filter($cargo, static fn($n) => $n === 0)));
    $novSem = static fn(string $rep): ?int => isset($novIni[$rep]) ? max(1, intdiv((int) ((strtotime($desde) - strtotime($novIni[$rep])) / 86400), 7) + 1) : null;
    // Quem não pode ficar na equipe do posto: na página prioritária, o nível 0 do KPI e o new hire; o new hire fora da rampa.
    // Restrições dos OM (24/11): quem é só buffer de plantão ou completa a escala não fica em equipe de vaga.
    $foraEquipeOm = array_flip(array_filter(array_map(static fn($c) => strtoupper(trim($c)), explode(',', escV4Param('v4_sr_so_buffer', '') . ',' . escV4Param('v4_sr_completa', '')))));
    $barrado = static function (int $posto, string $rep) use ($postoPrio, $postoMaior, $nivelKpi, $novSem, $foraEquipeOm): ?string {
        if (isset($foraEquipeOm[$rep])) {
            return 'ajuste da escalação';
        }
        if (isset($postoPrio[$posto]) && isset($nivelKpi[$rep]) && (int) $nivelKpi[$rep] === 0) {
            return 'KPI nível 0 em página prioritária';
        }
        $sem = $novSem($rep);
        if ($sem === null) {
            return null;
        }
        if ($sem === 1) {
            return 'new hire na 1ª semana: assistente ou buffer';
        }
        $teto = escNovatoTeto($sem);
        if (isset($postoPrio[$posto]) || ($teto > 0 && ($postoMaior[$posto] ?? 0) > $teto)) {
            return 'new hire acima do Tier ' . ($sem <= 2 ? 'F' : 'E') . ' (' . $sem . 'ª semana)';
        }
        return null;
    };
    // Refaz as vagas que o próprio montador completou e que ferem a regra acima: fecham na véspera de $desde
    // (ou vão para a data neutra, se só começariam depois) e a vaga é completada de novo logo abaixo.
    $refazer = [];
    foreach (escQ('SELECT id, posto_id, vaga, UPPER(rep_code) AS rep, vigente_desde FROM ' . escT('esc_vaga_equipe') . " WHERE origem = 'MONTADOR' AND" . $vig, [$ref, $ref]) as $e) {
        if (isset($idsVig[(int) $e['posto_id']]) && ($por = $barrado((int) $e['posto_id'], $e['rep'])) !== null) {
            $refazer[] = $e + ['por' => $por];
        }
    }
    if ($refazer) {
        $fechar = static function () use ($refazer, $desde) {
            $neutra = escMaisDias(escV4DiaVirada(), -2);
            foreach ($refazer as $e) {
                if (substr((string) $e['vigente_desde'], 0, 10) >= $desde) {
                    escVagaNeutra((int) $e['id'], $neutra);
                } else {
                    escW('UPDATE ' . escT('esc_vaga_equipe') . ' SET vigente_ate = ?, motivo = LEFT(CONCAT(?, COALESCE(motivo, \'\')), 255) WHERE id = ?',
                        [escMaisDias($desde, -1), 'saiu (1.39.0: ' . $e['por'] . '); ', (int) $e['id']]);
                }
            }
        };
        escDbW()->inTransaction() ? $fechar() : escTx($fechar);
        escLog('equipe_refeita', 'esc_vaga_equipe', null, null, $refazer);
    }
    $tem = [];   // [posto][vaga] = true
    $uso = [];   // [rep] = uso (dono conta a fatia; cobertura, metade)
    foreach (escQ('SELECT posto_id, vaga, UPPER(rep_code) AS rep, fatia FROM ' . escT('esc_vaga_equipe') . ' WHERE' . $vig, [$ref, $ref]) as $e) {
        if (!isset($idsVig[(int) $e['posto_id']])) {
            continue;
        }
        $tem[(int) $e['posto_id']][$e['vaga']] = true;
        $uso[$e['rep']] = ($uso[$e['rep']] ?? 0) + (float) $e['fatia'] * ($e['vaga'] === 'DONO' ? 1 : 0.5);
    }
    // Vaga que já tem gente a partir de uma data depois de $ref (ex.: o Gerar da semana seguinte rodou
    // antes): a vaga só fica vazia até a véspera disso. Sem este limite, o Gerar de uma semana anterior
    // abria uma segunda linha sem fim para o mesmo posto e vaga (B2 da auditoria de 24/11).
    $futuro = []; // [posto][vaga] = primeiro vigente_desde depois de $ref
    foreach (escQ('SELECT posto_id, vaga, MIN(vigente_desde) AS d FROM ' . escT('esc_vaga_equipe') . ' WHERE vigente_desde > ? GROUP BY posto_id, vaga', [$ref]) as $e) {
        $futuro[(int) $e['posto_id']][$e['vaga']] = substr((string) $e['d'], 0, 10);
    }
    $vazias = [];
    foreach ($postos as $p) {
        foreach (['DONO' => 'nucleo_dono_id', 'COBERTURA' => 'nucleo_cobertura_id'] as $vaga => $col) {
            if (empty($tem[(int) $p['id']][$vaga]) && $p[$col] !== null) {
                $vazias[] = ['posto' => $p, 'vaga' => $vaga, 'nucleo' => (int) $p[$col], 'precisa' => $vaga === 'DONO' ? 1.0 : 0.5];
            }
        }
    }
    if (!$vazias) {
        return [];
    }
    $ids = array_values(array_unique(array_map(static fn($v) => (int) $v['posto']['id'], $vazias)));
    $paginas = [];
    foreach (escQ('SELECT posto_id, pagina FROM ' . escT('esc_posto_pagina') . ' WHERE posto_id IN (' . escIn($ids) . ')', $ids) as $g) {
        $paginas[(int) $g['posto_id']][] = $g['pagina'];
    }
    $pessoas = []; // [unidade|turno][rep] = [...]
    foreach (escQ('SELECT UPPER(rep_code) AS rep, unidade, turno, nucleo_id, nivel, fatia_regular FROM ' . escT('esc_colaborador_config')
        . " WHERE funcao = 'EQUITE' AND nucleo_id IS NOT NULL AND" . $vig . ' ORDER BY vigente_desde', [$ref, $ref]) as $c) {
        $pessoas[$c['unidade'] . '|' . $c['turno']][$c['rep']] = ['nucleo' => (int) $c['nucleo_id'], 'nivel' => (int) $c['nivel'],
            'cap' => escEquipeFatiaNivel($c['fatia_regular'] !== null ? (float) $c['fatia_regular'] : null, (int) $c['nivel']), 'uso' => 0.0];
    }
    $todos = [];
    foreach ($pessoas as $ut => $lista) {
        foreach (array_keys($lista) as $rep) {
            $pessoas[$ut][$rep]['uso'] = $uso[$rep] ?? 0.0;
            $todos[] = $rep;
        }
    }
    $hist = [];
    if ($todos) {
        foreach (escQ('SELECT UPPER(codigo) AS rep, modeloNome AS pagina, COUNT(DISTINCT LEFT(shiftDateAndHour, 10)) AS dias FROM `5c_schedule`.`scheduleday`
                        WHERE codigo IN (' . escIn($todos) . ') AND shiftDateAndHour >= ? GROUP BY UPPER(codigo), modeloNome',
            array_merge($todos, [escCorteDados(escMaisDias($ref, -56)) . 'T00:00:00'])) as $h) {
            $hist[$h['rep']][$h['pagina']] = (int) $h['dias'];
        }
    }
    $notas = [];
    try {
        $notas = escDesempenhoNotas(); // KPI (score em %) ou mérito
    } catch (Throwable $e) {
        // sem desempenho: só o histórico decide
    }
    // Ordem das vagas (como no montador): dono antes de cobertura; depois a vaga em que alguém do
    // núcleo conhece as páginas (senão quem conhece uma página é gasto noutra); depois a carga.
    $conhecido = static function (array $v) use ($pessoas, $paginas, $hist): int {
        $melhor = 0;
        foreach ($pessoas[$v['posto']['unidade'] . '|' . $v['posto']['turno']] ?? [] as $rep => $c) {
            if ($c['nucleo'] === $v['nucleo']) {
                $d = 0;
                foreach ($paginas[(int) $v['posto']['id']] ?? [] as $pg) {
                    $d += $hist[$rep][$pg] ?? 0;
                }
                $melhor = max($melhor, $d);
            }
        }
        return $melhor;
    };
    foreach ($vazias as &$v) {
        $v['ordem'] = [$v['vaga'] === 'DONO' ? 0 : 1, -$conhecido($v), -(float) $v['posto']['carga'], (int) $v['posto']['id']];
    }
    unset($v);
    usort($vazias, static fn($a, $b) => $a['ordem'] <=> $b['ordem']);

    $feito = [];
    $gravar = static function () use ($vazias, $paginas, &$pessoas, $hist, $notas, $desde, $futuro, &$feito, $barrado, $postoPrio) {
        foreach ($vazias as $v) {
            $p = $v['posto'];
            $ut = $p['unidade'] . '|' . $p['turno'];
            $limite = isset($futuro[(int) $p['id']][$v['vaga']]) ? escMaisDias($futuro[(int) $p['id']][$v['vaga']], -1) : null;
            if ($limite !== null && $limite < max($desde, substr((string) $p['vigente_desde'], 0, 10))) {
                continue; // já preenchida a partir de $desde
            }
            $bloq = [];
            foreach (array_keys($pessoas[$ut] ?? []) as $rep) {
                if ($barrado((int) $p['id'], (string) $rep) !== null) {
                    $bloq[$rep] = true;
                }
            }
            $escolha = escEquipeEscolher($pessoas[$ut] ?? [], ['nucleo' => $v['nucleo'], 'precisa' => $v['precisa'], 'paginas' => $paginas[(int) $p['id']] ?? [],
                'bloq' => $bloq, 'por_score' => isset($postoPrio[(int) $p['id']])], $hist, $notas);
            if (!$escolha) {
                $feito[] = ['posto' => (int) $p['id'], 'vaga' => $v['vaga'], 'reps' => [], 'paginas' => $paginas[(int) $p['id']] ?? []];
                continue;
            }
            $de = max($desde, substr((string) $p['vigente_desde'], 0, 10));
            $ate = $p['vigente_ate'] !== null ? substr((string) $p['vigente_ate'], 0, 10) : null;
            if ($limite !== null && ($ate === null || $limite < $ate)) {
                $ate = $limite;
            }
            $reps = [];
            foreach ($escolha as $ordem => [$rep, $f, $passo]) {
                $pessoas[$ut][$rep]['uso'] += $f;
                $motivo = ['', 'do núcleo, dentro da fatia', 'de outro núcleo do turno, dentro da fatia', 'nível 0–2 acima da fatia, por falta de gente', 'ninguém com folga no turno: o menos carregado'][$passo];
                $dias = 0;
                foreach ($paginas[(int) $p['id']] ?? [] as $pg) {
                    $dias += $hist[$rep][$pg] ?? 0;
                }
                escW('INSERT INTO ' . escT('esc_vaga_equipe') . " (posto_id, vaga, rep_code, fatia, ordem, status, motivo, vigente_desde, vigente_ate, origem) VALUES (?, ?, ?, ?, ?, 'PROPOSTA', ?, ?, ?, 'MONTADOR') AS n ON DUPLICATE KEY UPDATE vigente_ate = n.vigente_ate, fatia = n.fatia, ordem = n.ordem, status = n.status, motivo = n.motivo",
                    [(int) $p['id'], $v['vaga'], $rep, min(1.0, round($f / $v['precisa'], 3)), $ordem + 1,
                     mb_substr('vaga sem equipe, completada antes de gerar: ' . $motivo . ($dias ? ', ' . min($dias, 99) . ' dias nas páginas em 8 semanas' : ', sem histórico nas páginas'), 0, 255), $de, $ate]);
                $reps[] = $rep;
            }
            $feito[] = ['posto' => (int) $p['id'], 'vaga' => $v['vaga'], 'reps' => $reps, 'paginas' => $paginas[(int) $p['id']] ?? []];
        }
    };
    escDbW()->inTransaction() ? $gravar() : escTx($gravar); // dentro da saída de página já há transação
    $ok = array_values(array_filter($feito, static fn($f) => $f['reps']));
    if ($ok) {
        escLog('equipe_completada', 'esc_vaga_equipe', null, null, $ok);
    }
    return $feito;
}

// Texto curto para a mensagem depois de gerar: quantas vagas vazias foram completadas.
function escEquipesAviso(): string {
    $feito = $GLOBALS['ESC_EQUIPES_AUTO'] ?? [];
    $ok = count(array_filter($feito, static fn($f) => $f['reps']));
    $sem = count($feito) - $ok;
    $txt = $ok ? ' ' . tn($ok, '{n} vaga sem equipe foi completada (Páginas › Owners e backups).', '{n} vagas sem equipe foram completadas (Páginas › Owners e backups).') : '';
    return $txt . ($sem ? ' ' . tn($sem, '{n} vaga ficou sem equipe: não há SR no turno da unidade.', '{n} vagas ficaram sem equipe: não há SR no turno da unidade.') : '');
}

// Postos vigentes em $dia sem ninguém na vaga de dono ou de cobertura (para a tela Gerar).
function escEquipesVazias(string $dia): int {
    $vig = static fn(string $a): string => " {$a}.vigente_desde <= ? AND ({$a}.vigente_ate IS NULL OR {$a}.vigente_ate >= ?)";
    $sem = static fn(string $vaga): string => 'NOT EXISTS (SELECT 1 FROM ' . escT('esc_vaga_equipe') . " e WHERE e.posto_id = p.id AND e.vaga = '{$vaga}' AND" . $vig('e') . ')';
    $r = escQ1('SELECT COUNT(*) AS n FROM ' . escT('esc_posto') . ' p WHERE' . $vig('p') . ' AND (' . $sem('DONO') . ' OR (p.nucleo_cobertura_id IS NOT NULL AND ' . $sem('COBERTURA') . '))',
        [$dia, $dia, $dia, $dia, $dia, $dia]);
    return (int) ($r['n'] ?? 0);
}
