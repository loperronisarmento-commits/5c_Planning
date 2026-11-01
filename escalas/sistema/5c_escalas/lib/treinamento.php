<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/treinamento.php
// Treinamento de New Hires (ES_36). Não confundir com o Modo Treino (lib/treino.php).
//   - Lê o e-mail do Treinamento (turmas, horário, datas, nesting, páginas necessárias e
//     trainees) e, se colada, a lista de contratados do RH; o que não bate vira alerta.
//   - Recomenda as páginas emprestadas: a menor página de um posto com 2 ou mais páginas
//     (o SR dono fica com a maior e continua dono da emprestada, com a comissão), nunca
//     OFTV ("... OFTV" ou número + t, ex.: "141t"), sem desempenho do mês prejudicado e
//     sem repetir página entre turnos ao mesmo tempo. As mesmas páginas valem o ciclo todo.
//   - Gera o texto de resposta ao e-mail.
//   - Põe os trainees no plano na página 5C Schedule com -4 (origem TREINO), seg a sex,
//     no turno da turma, e tira da escala normal até o dia em que ficam disponíveis.
// ===============================================

const ESC_TRN_SUFIXO = '-4';
const ESC_TRN_STATUS = ['RASCUNHO', 'ATIVA', 'CANCELADA'];

function escTrnInstalado(): bool {
    static $ok = null;
    if ($ok === null) {
        $ok = (bool) escQ1("SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'esc_treinamento_turma'", [ESC_SCHEMA]);
    }
    return $ok;
}

// Páginas pela quantidade de trainees (regra do Treinamento: até 4 em 1 página, de 5 a 8 em 2,
// 9 ou mais em 3). Parâmetro treinamento_faixas = limites de cada página a mais ("4,8").
function escTrnPaginasPara(int $trainees): int {
    $n = 1;
    foreach (array_filter(array_map('intval', explode(',', escV4Param('treinamento_faixas', '4,8')))) as $limite) {
        $n += $trainees > $limite ? 1 : 0;
    }
    return $n;
}

// Página OFTV: nunca vai para treinamento.
function escTrnOftv(string $pagina): bool {
    return (bool) preg_match('~(\bOFTV|\d+\s*t)\s*$~i', trim($pagina));
}

// Turno pelo horário de início (05h = A, 13h = C, 21h = D).
function escTrnTurnoDaHora(int $h): string {
    return $h >= 3 && $h < 11 ? 'A' : ($h >= 11 && $h < 19 ? 'C' : 'D');
}

function escTrnTurnoDosBlocos(string $tt): ?string {
    $tt = strtoupper(preg_replace('~\s+~', '', $tt));
    return ['T2T3' => 'A', 'T4T5' => 'C', 'T6T1' => 'D', 'T1T6' => 'D'][$tt] ?? null;
}

function escTrnData(string $dmY): ?string {
    if (!preg_match('~(\d{1,2})/(\d{1,2})/(\d{4})~', $dmY, $m) || !checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
}

// Dias de treinamento: seg a sex entre o início e o fim.
function escTrnDias(array $turma): array {
    $dias = [];
    for ($d = substr((string) $turma['inicio'], 0, 10); $d <= substr((string) $turma['fim'], 0, 10); $d = escMaisDias($d, 1)) {
        if ((int) date('N', strtotime($d)) <= 5) {
            $dias[] = $d;
        }
    }
    return $dias;
}

// ---------- leitura do e-mail ----------

// Devolve ['ciclo', 'turmas' => [codigo => turma], 'alertas' => [texto]]. Cada turma tem
// codigo, turno, hc, instrutor, horario, inicio, fim, disponivel_em, nesting_de/ate,
// paginas_necessarias, trainees [code => [nome, nome_social]] e alertas.
function escTrnLerEmail(string $email, string $contratados = ''): array {
    $txt = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $email);
    $alertas = [];
    $turmas = [];

    // 1) Cabeçalho de cada turma ("Turma: NH45A2026" ... até a próxima ou o quadro).
    preg_match_all('~Turma:\s*([A-Z0-9][A-Z0-9-]{3,})~iu', $txt, $ms, PREG_OFFSET_CAPTURE);
    $fimCab = mb_strlen($txt);
    foreach (['New Hire Onboarding', 'GROUP ID', 'NESTING'] as $marca) {
        $pos = stripos($txt, $marca);
        if ($pos !== false) {
            $fimCab = min($fimCab, $pos);
        }
    }
    foreach ($ms[1] as $i => [$codigo, $off]) {
        if ($off > $fimCab) {
            break;
        }
        $ate = isset($ms[1][$i + 1]) ? min($ms[1][$i + 1][1], $fimCab) : $fimCab;
        $bloco = substr($txt, $off, max(0, $ate - $off));
        $codigo = strtoupper($codigo);
        $t = ['codigo' => $codigo, 'turno' => null, 'hc' => null, 'instrutor' => null, 'horario' => null, 'inicio' => null, 'fim' => null,
              'disponivel_em' => null, 'nesting_de' => null, 'nesting_ate' => null, 'paginas_necessarias' => null, 'trainees' => [], 'alertas' => []];
        if (preg_match('~HC:\s*0*(\d+)~iu', $bloco, $m)) {
            $t['hc'] = (int) $m[1];
        }
        if (preg_match('~Instrutor(?:a)?:\s*([^\n]+)~iu', $bloco, $m)) {
            $t['instrutor'] = trim($m[1]);
        }
        $turnoHora = null;
        if (preg_match('~Hor[áa]rio:\s*(\d{1,2})\s*h?(?::\d{2})?\s*[-–a]+\s*(\d{1,2})\s*h?~iu', $bloco, $m)) {
            $t['horario'] = sprintf('%02dh–%02dh', $m[1], $m[2]);
            $turnoHora = escTrnTurnoDaHora((int) $m[1]);
        }
        $turnoTexto = null;
        if (preg_match('~Turno:\s*([ACD])\b(?:\s*[-–]\s*(T\d\s*T\d))?~iu', $bloco, $m)) {
            $turnoTexto = strtoupper($m[1]);
        }
        $t['turno'] = $turnoHora ?? $turnoTexto;
        if ($turnoHora && $turnoTexto && $turnoHora !== $turnoTexto) {
            $t['alertas'][] = t('{turma}: o e-mail diz turno {texto}, mas o horário {horario} é do turno {hora}. Usei o {hora}.', ['turma' => $codigo, 'texto' => $turnoTexto, 'horario' => $t['horario'], 'hora' => $turnoHora]);
        }
        foreach (['inicio' => 'Data de In[íi]cio', 'fim' => 'Data de Conclus[ãa]o', 'disponivel_em' => 'Dispon[íi]vel para Opera[çc][ãa]o'] as $campo => $rotulo) {
            if (preg_match('~' . $rotulo . ':\s*(\d{1,2}/\d{1,2}/\d{4})~iu', $bloco, $m)) {
                $t[$campo] = escTrnData($m[1]);
            }
        }
        $turmas[$codigo] = $t;
    }

    // 2) Quadro (New Hire Onboarding Planning Chart): nome e código por turma, turno do quadro.
    $atual = null;
    $noQuadro = false;
    $wfm = null;            // turma corrente na parte do WFM ("Turma X" ou "Turno: T6T1")
    $soWfm = [];
    foreach (explode("\n", $txt) as $linha) {
        $l = trim($linha);
        if ($l === '') {
            continue;
        }
        if (stripos($l, 'GROUP ID') !== false || stripos($l, 'Onboarding Planning') !== false) {
            $noQuadro = true;
            continue;
        }
        if (preg_match('~^NESTING|^P[áa]ginas necess|^A[ÇC][ÕO]ES~iu', $l)) {
            $noQuadro = false;
        }
        if ($noQuadro && preg_match('~^([A-Z]{2}\d{2}[A-Z0-9-]*\d{4})$~iu', $l, $m) && isset($turmas[strtoupper($m[1])])) {
            $atual = strtoupper($m[1]);
            continue;
        }
        if ($noQuadro && $atual && preg_match('~^(T\d\s*T\d)$~iu', $l, $m)) {
            $tq = escTrnTurnoDosBlocos($m[1]);
            if ($tq && $turmas[$atual]['turno'] && $tq !== $turmas[$atual]['turno']) {
                $turmas[$atual]['alertas'][] = t('{turma}: o quadro diz {quadro}, o cabeçalho diz {turno}.', ['turma' => $atual, 'quadro' => strtoupper($m[1]), 'turno' => $turmas[$atual]['turno']]);
            }
            continue;
        }
        if ($noQuadro && $atual && preg_match('~^(.+?)\t+\s*([A-Z]\d{6})\b~u', $linha, $m)) {
            $turmas[$atual]['trainees'][strtoupper($m[2])] = ['nome' => trim($m[1]), 'nome_social' => null];
            continue;
        }
        // Parte do WFM: "Turma NH45A2026", "Turno: T6T1" e linhas "A017276 Nome".
        if (!$noQuadro && preg_match('~^Turma\s+([A-Z0-9-]{5,})$~iu', $l, $m) && isset($turmas[strtoupper($m[1])])) {
            $wfm = strtoupper($m[1]);
            continue;
        }
        if (!$noQuadro && preg_match('~^Turno:\s*(T\d\s*T\d)~iu', $l, $m)) {
            $tw = escTrnTurnoDosBlocos($m[1]);
            if ($tw && (!$wfm || $turmas[$wfm]['turno'] !== $tw)) {
                foreach ($turmas as $c => $tu) {
                    if ($tu['turno'] === $tw) {
                        $wfm = $c;
                        break;
                    }
                }
            }
            continue;
        }
        if (!$noQuadro && $wfm && preg_match('~^([A-Z]\d{6})\s+(.+)$~u', $l, $m)) {
            $code = strtoupper($m[1]);
            $ja = false;
            foreach ($turmas as $tu) {
                $ja = $ja || isset($tu['trainees'][$code]);
            }
            if (!$ja) {
                $turmas[$wfm]['trainees'][$code] = ['nome' => trim($m[2]), 'nome_social' => null];
                $soWfm[] = $code;
            }
        }
    }
    $doQuadro = array_sum(array_map(static fn($tu) => count($tu['trainees']), $turmas)) - count($soWfm);
    if ($soWfm && $doQuadro > 0) {
        $alertas[] =t('Só na lista do WFM (não no quadro): {codigos}.', ['codigos' => implode(', ', $soWfm)]);
    }

    // 3) Nesting e páginas necessárias (valem para o ciclo).
    if (preg_match('~Nesting.{0,120}?(\d{1,2})(?:/(\d{1,2}))?(?:/\d{4})?\s*(?:e|a|até|-|–)\s*(\d{1,2})/(\d{1,2})(?:/(\d{4}))?~isu', $txt, $m)) {
        $ano = $m[5] ?? '';
        $ano = $ano !== '' ? $ano : date('Y');
        $mesDe = ($m[2] ?? '') !== '' ? $m[2] : $m[4];
        $de = escTrnData($m[1] . '/' . $mesDe . '/' . $ano);
        $ate = escTrnData($m[3] . '/' . $m[4] . '/' . $ano);
        foreach ($turmas as &$tu) {
            $tu['nesting_de'] = $de;
            $tu['nesting_ate'] = $ate;
        }
        unset($tu);
    }
    preg_match_all('~Turno\s*([ACD])\s*[-–]\s*(T\d\s*T\d)\s*:\s*0*(\d+)\s*p[áa]gina~iu', $txt, $mp, PREG_SET_ORDER);
    $porTurno = [];
    foreach ($mp as $m) {
        $porTurno[escTrnTurnoDosBlocos($m[2]) ?? strtoupper($m[1])] = (int) $m[3];
    }
    $cicloM = preg_match('~Training Group:\s*([A-Z0-9-]+)~iu', $txt, $mc) ? strtoupper($mc[1]) : null;

    // 4) Conferências por turma.
    $codigosTodos = [];
    foreach ($turmas as $c => &$tu) {
        $codigosTodos += array_fill_keys(array_keys($tu['trainees']), $c);
        $n = $porTurno[$tu['turno']] ?? null;
        $regra = escTrnPaginasPara(count($tu['trainees']));
        $tu['paginas_necessarias'] = $n ?? $regra;
        if ($n === null) {
            $tu['alertas'][] = t('{turma}: o e-mail não diz quantas páginas; pela regra ({n} trainees) são {p}.', ['turma' => $c, 'n' => count($tu['trainees']), 'p' => $regra]);
        }
        if (!$tu['inicio'] || !$tu['fim']) {
            $tu['alertas'][] = t('{turma}: faltou a data de início ou de conclusão.', ['turma' => $c]);
            continue;
        }
        // Fim no fim de semana: o turno D de sexta termina às 05h de sábado.
        $wFim = (int) date('N', strtotime($tu['fim']));
        if ($wFim >= 6) {
            $sexta = escMaisDias($tu['fim'], 5 - $wFim);
            $tu['alertas'][] = $tu['turno'] === 'D'
                ? t('{turma}: conclusão {fim} é o fim do turno de sexta (21h–05h). Treinamento de seg a sex até {sexta}.', ['turma' => $c, 'fim' => dataBr($tu['fim']), 'sexta' => dataBr($sexta)])
                : t('{turma}: conclusão {fim} cai no fim de semana; considerei até {sexta}.', ['turma' => $c, 'fim' => dataBr($tu['fim']), 'sexta' => dataBr($sexta)]);
            $tu['fim'] = $sexta;
        }
        $proxSeg = escMaisDias($tu['fim'], 8 - (int) date('N', strtotime($tu['fim'])));
        if (!$tu['disponivel_em']) {
            $tu['disponivel_em'] = $proxSeg;
            $tu['alertas'][] = t('{turma}: sem data de disponível para a operação; usei {dia}.', ['turma' => $c, 'dia' => dataBr($proxSeg)]);
        } elseif ($tu['disponivel_em'] <= $tu['fim']) {
            $tu['alertas'][] = t('{turma}: "disponível para operação" {dia} é antes do fim do treinamento (erro de digitação?). Usei {novo}.', ['turma' => $c, 'dia' => dataBr($tu['disponivel_em']), 'novo' => dataBr($proxSeg)]);
            $tu['disponivel_em'] = $proxSeg;
        }
        if ($tu['hc'] !== null && $tu['hc'] !== count($tu['trainees'])) {
            $tu['alertas'][] = t('{turma}: HC {hc}, mas vieram {n} trainees.', ['turma' => $c, 'hc' => $tu['hc'], 'n' => count($tu['trainees'])]);
        }
    }
    unset($tu);
    // Mais de uma turma no mesmo turno: o pedido de páginas do turno é dividido.
    $contaTurno = array_count_values(array_column($turmas, 'turno'));
    foreach ($turmas as $c => &$tu) {
        if (($contaTurno[$tu['turno']] ?? 1) > 1 && isset($porTurno[$tu['turno']])) {
            $tu['paginas_necessarias'] = max(1, intdiv($porTurno[$tu['turno']], $contaTurno[$tu['turno']]));
        }
        $regra = escTrnPaginasPara(count($tu['trainees']));
        if (isset($porTurno[$tu['turno']]) && $tu['trainees'] && $tu['paginas_necessarias'] !== $regra) {
            $tu['alertas'][] = t('{turma}: o e-mail pede {p} página(s); pela regra ({n} trainees) seriam {r}. Ficou o pedido do e-mail.', ['turma' => $c, 'p' => $tu['paginas_necessarias'], 'n' => count($tu['trainees']), 'r' => $regra]);
        }
    }
    unset($tu);

    // 5) Lista de contratados (opcional): nome social e quem não está em turma nenhuma.
    if (trim($contratados) !== '') {
        $lista = [];
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $contratados)) as $linha) {
            if (!preg_match('~\b([A-Z]\d{6})\b~u', $linha, $m)) {
                continue;
            }
            $partes = array_values(array_filter(array_map('trim', explode("\t", $linha)), static fn($x) => $x !== '' && !preg_match('~^[A-Z]\d{6}$~u', $x)));
            $lista[strtoupper($m[1])] = ['nome' => $partes[0] ?? '', 'nome_social' => $partes[1] ?? null];
        }
        $fora = array_diff_key($lista, $codigosTodos);
        if ($fora) {
            $alertas[] = t('Na lista de contratados, mas em nenhuma turma: {lista}.', ['lista' => implode('; ', array_map(static fn($c, $p) => $c . ' ' . $p['nome'], array_keys($fora), $fora))]);
        }
        $semLista = array_diff_key($codigosTodos, $lista);
        if ($lista && $semLista) {
            $alertas[] = t('Em turma, mas fora da lista de contratados: {codigos}.', ['codigos' => implode(', ', array_keys($semLista))]);
        }
        foreach ($turmas as &$tu) {
            foreach ($tu['trainees'] as $code => &$tr) {
                if (!empty($lista[$code]['nome_social'])) {
                    $tr['nome_social'] = $lista[$code]['nome_social'];
                }
            }
            unset($tr);
        }
        unset($tu);
    }

    // 6) Quem já tem cadastro de SR ou histórico no 5C Schedule.
    if ($codigosTodos) {
        $codes = array_keys($codigosTodos);
        foreach (escQ('SELECT UPPER(rep_code) AS r, MIN(vigente_desde) AS desde FROM ' . escT('esc_colaborador_config') . ' WHERE UPPER(rep_code) IN (' . escIn($codes) . ') AND (vigente_ate IS NULL OR vigente_ate >= CURDATE()) GROUP BY UPPER(rep_code)', $codes) as $r) {
            $alertas[] = t('{rep} já tem cadastro de SR desde {dia}: o sistema deixa fora da escala normal até ficar disponível.', ['rep' => $r['r'], 'dia' => dataBr(substr((string) $r['desde'], 0, 10))]);
        }
        try {
            foreach (escQ('SELECT UPPER(codigo) AS r, COUNT(*) AS n, MAX(LEFT(shiftDateAndHour, 10)) AS ult FROM `5c_schedule`.`scheduleday` WHERE codigo IN (' . escIn($codes) . ') GROUP BY UPPER(codigo)', $codes) as $r) {
                $alertas[] = t('{rep} já aparece no 5C Schedule ({n} blocos, último em {dia}): recontratação ou código repetido?', ['rep' => $r['r'], 'n' => (int) $r['n'], 'dia' => dataBr((string) $r['ult'])]);
            }
        } catch (Throwable $e) {
            appLog('warning', 'Treinamento: 5C Schedule indisponível na leitura', ['erro' => $e->getMessage()]);
        }
    }
    if (!$turmas) {
        $alertas[] = t('Não achei nenhuma turma ("Turma: ...") no texto.');
    }
    return ['ciclo' => $cicloM, 'turmas' => $turmas, 'alertas' => $alertas];
}

// Grava as turmas lidas como RASCUNHO (ou atualiza a turma de mesmo código que ainda é rascunho).
function escTrnSalvarLeitura(array $lido, string $email): array {
    $ids = [];
    escTx(static function () use ($lido, $email, &$ids) {
        foreach ($lido['turmas'] as $c => $t) {
            if (!$t['turno'] || !$t['inicio'] || !$t['fim'] || !$t['disponivel_em']) {
                continue;
            }
            $ja = escQ1('SELECT id, status FROM ' . escT('esc_treinamento_turma') . ' WHERE codigo = ?', [$c]);
            if ($ja && $ja['status'] === 'ATIVA') {
                throw new RuntimeException(t('A turma {turma} já está confirmada. Para trocar os dados, cancele a turma antes.', ['turma' => $c]));
            }
            $alertas = json_encode(array_values(array_merge($t['alertas'], $lido['alertas'])), JSON_UNESCAPED_UNICODE);
            $campos = [$lido['ciclo'], $t['turno'], $t['hc'], $t['instrutor'], $t['horario'], $t['inicio'], $t['fim'], $t['disponivel_em'], $t['nesting_de'], $t['nesting_ate'],
                       $t['paginas_necessarias'], $alertas, mb_substr($email, 0, 60000)];
            if ($ja) {
                escW('UPDATE ' . escT('esc_treinamento_turma') . " SET ciclo = ?, turno = ?, hc = ?, instrutor = ?, horario = ?, inicio = ?, fim = ?, disponivel_em = ?, nesting_de = ?, nesting_ate = ?,
                        paginas_necessarias = ?, alertas = ?, email_texto = ?, status = 'RASCUNHO' WHERE id = ?", array_merge($campos, [(int) $ja['id']]));
                $id = (int) $ja['id'];
                escW('DELETE FROM ' . escT('esc_treinamento_trainee') . ' WHERE turma_id = ?', [$id]);
            } else {
                escW('INSERT INTO ' . escT('esc_treinamento_turma') . ' (ciclo, turno, hc, instrutor, horario, inicio, fim, disponivel_em, nesting_de, nesting_ate, paginas_necessarias, alertas, email_texto, codigo, criado_por)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', array_merge($campos, [$c, escUserId() ?: null]));
                $id = escUltimoId();
            }
            foreach ($t['trainees'] as $code => $tr) {
                escW('INSERT INTO ' . escT('esc_treinamento_trainee') . ' (turma_id, rep_code, nome, nome_social) VALUES (?, ?, ?, ?)',
                    [$id, $code, mb_substr((string) $tr['nome'], 0, 150), $tr['nome_social'] !== null ? mb_substr((string) $tr['nome_social'], 0, 150) : null]);
            }
            $ids[] = $id;
        }
    });
    escLog('treinamento.ler_email', 'treinamento', null, null, ['turmas' => $ids]);
    return $ids;
}

// Turmas (com trainees e páginas) por status ou por período.
function escTrnTurmas(array $status = ['RASCUNHO', 'ATIVA'], ?string $de = null, ?string $ate = null): array {
    $sql = 'SELECT * FROM ' . escT('esc_treinamento_turma') . ' WHERE status IN (' . escIn($status) . ')';
    $par = $status;
    if ($de && $ate) {
        // Período que cobre o treinamento e o bloqueio até ficar disponível.
        $sql .= ' AND inicio <= ? AND disponivel_em > ?';
        array_push($par, $ate, $de);
    }
    $turmas = [];
    foreach (escQ($sql . ' ORDER BY inicio, FIELD(turno, \'D\', \'A\', \'C\'), codigo', $par) as $t) {
        foreach (['inicio', 'fim', 'disponivel_em', 'nesting_de', 'nesting_ate'] as $c) {
            $t[$c] = $t[$c] !== null ? substr((string) $t[$c], 0, 10) : null;
        }
        $t['alertas'] = json_decode((string) ($t['alertas'] ?? '[]'), true) ?: [];
        $t['trainees'] = [];
        $t['paginas'] = [];
        $turmas[(int) $t['id']] = $t;
    }
    if ($turmas) {
        $ids = array_keys($turmas);
        foreach (escQ('SELECT turma_id, UPPER(rep_code) AS rep_code, nome, nome_social FROM ' . escT('esc_treinamento_trainee') . ' WHERE turma_id IN (' . escIn($ids) . ') ORDER BY nome', $ids) as $r) {
            $turmas[(int) $r['turma_id']]['trainees'][$r['rep_code']] = $r;
        }
        foreach (escQ('SELECT * FROM ' . escT('esc_treinamento_pagina') . ' WHERE turma_id IN (' . escIn($ids) . ') ORDER BY fans_h', $ids) as $r) {
            $turmas[(int) $r['turma_id']]['paginas'][$r['pagina']] = $r;
        }
    }
    return $turmas;
}

// ---------- páginas para o treinamento ----------

// Atingimento (5CReps ÷ meta) por página, total e por turno, nos últimos $n dias.
function escTrnAtingimento(array $paginas, string $hoje, int $n): array {
    if (!$paginas) {
        return [];
    }
    $out = [];
    foreach (escQ('SELECT Page AS pagina, Turn AS t, SUM(`5CReps`) AS r, SUM(`Goal 5CReps`) AS g FROM `5c_wfm_db_mis`.`tab_intraday_base`
                    WHERE `Date` >= ? AND `Date` < ? AND Page IN (' . escIn($paginas) . ') GROUP BY Page, Turn', array_merge([escMaisDias($hoje, -$n), $hoje], $paginas)) as $l) {
        $t = ESC_POL_TURNO[$l['t']] ?? null;
        $out[$l['pagina']]['*'][0] = ($out[$l['pagina']]['*'][0] ?? 0) + (float) $l['r'];
        $out[$l['pagina']]['*'][1] = ($out[$l['pagina']]['*'][1] ?? 0) + (float) $l['g'];
        if ($t) {
            $out[$l['pagina']][$t][0] = ($out[$l['pagina']][$t][0] ?? 0) + (float) $l['r'];
            $out[$l['pagina']][$t][1] = ($out[$l['pagina']][$t][1] ?? 0) + (float) $l['g'];
        }
    }
    $r = [];
    foreach ($out as $p => $porT) {
        foreach ($porT as $t => [$re, $g]) {
            $r[$p][$t] = $g > 0 ? $re / $g : null;
        }
    }
    return $r;
}

// Coluna modo/lacunas (ES_38) instalada?
function escTrnTemModo(): bool {
    static $ok = null;
    if ($ok === null) {
        $ok = (bool) escQ1("SELECT 1 AS ok FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'esc_treinamento_pagina' AND COLUMN_NAME = 'modo'", [ESC_SCHEMA]);
    }
    return $ok;
}

// Lacunas da turma: blocos sem ninguém no turno, nos dias de treinamento, na versão atual de cada
// semana (a que vale hoje para a escala). [pagina => ['blocos', 'posto_id', 'semanas' => [rótulos]]]
function escTrnLacunas(array $turma): array {
    $dias = array_flip(escTrnDias($turma));
    if (!$dias) {
        return [];
    }
    $out = [];
    for ($s = segundaDaSemana(min(array_keys($dias))); $s <= max(array_keys($dias)); $s = escMaisDias($s, 7)) {
        $plano = escPlanoAtual($s);
        if (!$plano) {
            continue;
        }
        foreach (escQ("SELECT pagina, posto_id, dia, bloco FROM " . escT('esc_plano_alocacao') . " WHERE plano_id = ? AND origem = 'LACUNA' AND turno = ? AND rep_code IS NULL",
            [(int) $plano['id'], $turma['turno']]) as $l) {
            $d = substr((string) $l['dia'], 0, 10);
            $diaTurno = $turma['turno'] === 'D' && $l['bloco'] === 'T1' ? escMaisDias($d, -1) : $d;
            if (!isset($dias[$diaTurno])) {
                continue;
            }
            $p = $l['pagina'];
            $out[$p]['blocos'] = ($out[$p]['blocos'] ?? 0) + 1;
            $out[$p]['posto_id'] = $out[$p]['posto_id'] ?? (int) $l['posto_id'];
            $out[$p]['semanas'][$s] = escPlanoRotulo($plano);
        }
    }
    return $out;
}

// Páginas que podem ir para o treinamento da turma. Duas origens:
//   - LACUNA: página sem ninguém no turno nos dias da turma (versão atual da semana). Vem
//     primeiro: é melhor usar para treinar do que deixar vazia; o gerador não escala ninguém
//     nela nesses dias (escTrnCobertura). O desempenho do mês aparece, mas não impede.
//   - EMPRESTADA: a menor página de um posto com 2 ou mais páginas (o dono fica com a maior e
//     continua dono da emprestada, com a comissão). Desempenho do mês abaixo do mínimo impede.
//   - OUTRA: qualquer outra página do turno (a maior de um posto com várias, ou posto de uma
//     página só, que o dono divide com o treinamento). Mesmas travas; vem por último.
// Nunca OFTV, prioridade fixa ou página de outra turma no mesmo turno e período. Página de outra
// turma em outro turno pode (o Planejamento decide), mas vem marcada com aviso.
// $ocupadas = [pagina => [['codigo', 'turno'], ...]] (escTrnOcupadas).
function escTrnCandidatas(array $turma, array $ocupadas = []): array {
    $dia = max($turma['inicio'], escV4DiaEstrutura());
    $t = $turma['turno'];
    $postos = [];
    foreach (escQ('SELECT p.id, p.unidade, p.nucleo_dono_id, pp.pagina, pp.page_id, pp.fans_h, pp.apoio FROM ' . escT('esc_posto') . ' p
                     JOIN ' . escT('esc_posto_pagina') . ' pp ON pp.posto_id = p.id
                    WHERE p.turno = ? AND p.vigente_desde <= ? AND (p.vigente_ate IS NULL OR p.vigente_ate >= ?)', [$t, $dia, $dia]) as $l) {
        $postos[(int) $l['id']]['unidade'] = (int) $l['unidade'];
        $postos[(int) $l['id']]['paginas'][$l['pagina']] = ['page_id' => (int) $l['page_id'], 'fans_h' => (float) $l['fans_h']];
    }
    $cand = [];
    // Lacunas primeiro.
    foreach (escTrnLacunas($turma) as $nome => $lac) {
        $po = $postos[$lac['posto_id']] ?? null;
        $pg = $po['paginas'][$nome] ?? null;
        if (!$po || !$pg) {
            continue; // posto da versão antiga, que já não vale na data
        }
        $cand[$nome] = ['pagina' => $nome, 'page_id' => $pg['page_id'], 'posto_id' => $lac['posto_id'], 'unidade' => $po['unidade'], 'fans_h' => $pg['fans_h'],
            'fica_com' => null, 'modo' => 'LACUNA', 'lacunas' => $lac['blocos'], 'semanas' => array_values($lac['semanas'])];
    }
    // Emprestadas: só posto com 2 ou mais páginas; o dono fica com a maior e empresta as outras.
    foreach ($postos as $id => $po) {
        if (count($po['paginas']) < 2) {
            continue;
        }
        $pgs = $po['paginas'];
        uasort($pgs, static fn($a, $b) => $a['fans_h'] <=> $b['fans_h']);
        $maior = array_key_last($pgs);
        foreach ($pgs as $nome => $pg) {
            if ($nome === $maior || isset($cand[$nome])) {
                continue;
            }
            $cand[$nome] = ['pagina' => $nome, 'page_id' => $pg['page_id'], 'posto_id' => $id, 'unidade' => $po['unidade'], 'fans_h' => $pg['fans_h'],
                'fica_com' => $maior, 'modo' => 'EMPRESTADA', 'lacunas' => 0, 'semanas' => []];
        }
    }
    // Todas as outras páginas do turno (a maior de cada posto e os postos de uma página só).
    foreach ($postos as $id => $po) {
        $pgs = $po['paginas'];
        uasort($pgs, static fn($a, $b) => $b['fans_h'] <=> $a['fans_h']);
        foreach ($pgs as $nome => $pg) {
            if (isset($cand[$nome])) {
                continue;
            }
            $outrasDoPosto = array_keys(array_diff_key($pgs, [$nome => true]));
            $cand[$nome] = ['pagina' => $nome, 'page_id' => $pg['page_id'], 'posto_id' => $id, 'unidade' => $po['unidade'], 'fans_h' => $pg['fans_h'],
                'fica_com' => $outrasDoPosto ? implode(' + ', $outrasDoPosto) : null, 'modo' => 'OUTRA', 'lacunas' => 0, 'semanas' => []];
        }
    }
    if (!$cand) {
        return [];
    }
    $donos = [];
    $postoIds = array_values(array_unique(array_column($cand, 'posto_id')));
    foreach (escQ('SELECT posto_id, UPPER(rep_code) AS rep FROM ' . escT('esc_vaga_equipe') . " WHERE vaga = 'DONO' AND posto_id IN (" . escIn($postoIds) . ') AND vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?) ORDER BY ordem, id',
        array_merge($postoIds, [$dia, $dia])) as $v) {
        $donos[(int) $v['posto_id']] ??= $v['rep'];
    }
    // Página com prioridade fixa (ex.: Privacy do time legado) não é emprestada.
    $prioridade = [];
    if (function_exists('escPolInstalada') && escPolInstalada()) {
        foreach (escQ('SELECT pagina FROM ' . escT('esc_pagina_prioridade') . ' WHERE vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)', [$dia, $dia]) as $l) {
            $prioridade[$l['pagina']] = true;
        }
    }
    $min = (float) str_replace(',', '.', escV4Param('treinamento_ating_min', '0.85'));
    $janela = max(7, (int) escV4Param('treinamento_janela_dias', '28'));
    $ating = escTrnAtingimento(array_keys($cand), date('Y-m-d'), $janela);
    foreach ($cand as $nome => &$c) {
        $c['owner'] = $donos[$c['posto_id']] ?? null;
        $c['ating'] = $ating[$nome]['*'] ?? null;
        $c['ating_turno'] = $ating[$nome][$t] ?? null;
        $c['motivo'] = null;
        if (escTrnOftv($nome)) {
            $c['motivo'] = t('OFTV');
        } elseif (isset($prioridade[$nome])) {
            $c['motivo'] = t('prioridade fixa');
        } elseif ($mesmo = array_values(array_filter($ocupadas[$nome] ?? [], static fn($o) => $o['turno'] === $t))) {
            $c['motivo'] = t('já está no treinamento {turma} no mesmo turno', ['turma' => $mesmo[0]['codigo']]);
        } elseif ($c['modo'] !== 'LACUNA' && $c['ating'] !== null && $c['ating'] < $min) {
            $c['motivo'] = t('mês abaixo de {min} ({a})', ['min' => pct($min, 0), 'a' => pct($c['ating'], 0)]);
        } elseif ($c['modo'] !== 'LACUNA' && $c['ating_turno'] !== null && $c['ating_turno'] < $min) {
            $c['motivo'] = t('turno abaixo de {min} ({a})', ['min' => pct($min, 0), 'a' => pct($c['ating_turno'], 0)]);
        } elseif ($c['modo'] !== 'LACUNA' && !$c['owner']) {
            $c['motivo'] = t('posto sem dono');
        }
        $c['ok'] = $c['motivo'] === null;
        $c['sem_dados'] = $c['ating'] === null;
        // Outra turma, outro turno: pode, com aviso (a página fica em treinamento em mais de um turno).
        $c['outras'] = array_values(array_filter($ocupadas[$nome] ?? [], static fn($o) => $o['turno'] !== $t));
        $c['aviso'] = $c['outras'] ? t('também no treinamento {turmas}', ['turmas' => implode(', ', array_map(static fn($o) => $o['codigo'] . ' (' . ESC_SOL_TURNO_BLOCOS[$o['turno']] . ')', $c['outras']))]) : null;
    }
    unset($c);
    // Pode primeiro; lacuna antes de emprestada (mais blocos vazios primeiro); com dados antes de sem dados; menor primeiro.
    $ordemModo = ['LACUNA' => 0, 'EMPRESTADA' => 1, 'OUTRA' => 2];
    uasort($cand, static fn($a, $b) => [!$a['ok'], $a['aviso'] !== null, $ordemModo[$a['modo']], -$a['lacunas'], $a['sem_dados'], $a['fans_h']]
        <=> [!$b['ok'], $b['aviso'] !== null, $ordemModo[$b['modo']], -$b['lacunas'], $b['sem_dados'], $b['fans_h']]);
    return $cand;
}


// Páginas já usadas por outras turmas que se cruzam no tempo: [pagina => [['codigo', 'turno'], ...]].
function escTrnOcupadas(array $turma): array {
    $o = [];
    foreach (escQ('SELECT tp.pagina, t.codigo, t.turno FROM ' . escT('esc_treinamento_pagina') . ' tp JOIN ' . escT('esc_treinamento_turma') . " t ON t.id = tp.turma_id
                    WHERE t.status IN ('RASCUNHO', 'ATIVA') AND t.id <> ? AND t.inicio <= ? AND t.fim >= ?", [(int) $turma['id'], $turma['fim'], $turma['inicio']]) as $l) {
        $o[$l['pagina']][] = ['codigo' => $l['codigo'], 'turno' => $l['turno']];
    }
    return $o;
}

// Sugestão para as turmas sem página: as menores que podem, sem repetir entre turmas (repetir em
// outro turno é escolha do Planejamento, com aviso).
function escTrnSugerir(array $turmas): array {
    $sug = [];
    $usadas = [];
    foreach ($turmas as $t) {
        foreach ($t['paginas'] as $p => $x) {
            $usadas[$p][] = ['codigo' => $t['codigo'], 'turno' => $t['turno']];
        }
    }
    foreach ($turmas as $id => $t) {
        $ocup = escTrnOcupadas($t);
        foreach ($usadas as $p => $lista) {
            foreach ($lista as $u) {
                if ($u['codigo'] !== $t['codigo'] && !in_array($u, $ocup[$p] ?? [], true)) {
                    $ocup[$p][] = $u;
                }
            }
        }
        $cand = escTrnCandidatas($t, $ocup);
        $sug[$id] = ['candidatas' => $cand, 'escolha' => []];
        if ($t['paginas']) {
            $sug[$id]['escolha'] = array_keys($t['paginas']);
            continue;
        }
        foreach ($cand as $nome => $c) {
            if (count($sug[$id]['escolha']) >= (int) $t['paginas_necessarias']) {
                break;
            }
            if ($c['ok'] && $c['aviso'] === null && $c['modo'] !== 'OUTRA' && !isset($usadas[$nome])) { // "outra" (página maior) só à mão
                $sug[$id]['escolha'][] = $nome;
                $usadas[$nome][] = ['codigo' => $t['codigo'], 'turno' => $t['turno']];
            }
        }
    }
    return $sug;
}

// Confirma a turma: grava as páginas escolhidas e passa para ATIVA (entra no plano). Página que
// já está em outra turma de outro turno no mesmo período só passa com $ciente (aviso na tela).
function escTrnConfirmar(int $turmaId, array $paginas, bool $ciente = false): void {
    $turma = escTrnTurmas(['RASCUNHO', 'ATIVA'])[$turmaId] ?? null;
    if (!$turma) {
        throw new RuntimeException(t('Turma não encontrada.'));
    }
    if (!$turma['trainees']) {
        throw new RuntimeException(t('A turma {turma} está sem trainees.', ['turma' => $turma['codigo']]));
    }
    $paginas = array_values(array_unique(array_filter(array_map('strval', $paginas))));
    if (!$paginas) {
        throw new RuntimeException(t('Escolha pelo menos uma página para a turma {turma}.', ['turma' => $turma['codigo']]));
    }
    $cand = escTrnCandidatas($turma, escTrnOcupadas($turma));
    foreach ($paginas as $p) {
        if (!isset($cand[$p])) {
            throw new RuntimeException(t('{pagina} não está entre as páginas possíveis no turno {turno} (lacuna ou posto com 2 ou mais páginas).', ['pagina' => $p, 'turno' => $turma['turno']]));
        }
        if (escTrnOftv($p)) {
            throw new RuntimeException(t('{pagina} é OFTV e não pode ir para treinamento.', ['pagina' => $p]));
        }
        if (array_filter(escTrnOcupadas($turma)[$p] ?? [], static fn($o) => $o['turno'] === $turma['turno'])) {
            throw new RuntimeException(t('{pagina} já está em outro treinamento no mesmo turno e período: duas turmas não usam a mesma página ao mesmo tempo.', ['pagina' => $p]));
        }
    }
    $compartilhadas = array_values(array_filter($paginas, static fn($p) => $cand[$p]['aviso'] !== null));
    if ($compartilhadas && !$ciente) {
        throw new RuntimeException(t('Atenção: {lista}. A página fica em treinamento em mais de um turno no mesmo período. Para confirmar assim, marque "usar mesmo assim" e confirme de novo.', [
            'lista' => implode(' · ', array_map(static fn($p) => $p . ' ' . $cand[$p]['aviso'], $compartilhadas))]));
    }
    escTx(static function () use ($turmaId, $paginas, $cand) {
        escW('DELETE FROM ' . escT('esc_treinamento_pagina') . ' WHERE turma_id = ?', [$turmaId]);
        foreach ($paginas as $p) {
            $c = $cand[$p];
            if (escTrnTemModo()) {
                escW('INSERT INTO ' . escT('esc_treinamento_pagina') . ' (turma_id, pagina, modo, page_id, posto_id, owner_rep, fans_h, ating, lacunas, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$turmaId, $p, $c['modo'] === 'LACUNA' ? 'LACUNA' : 'EMPRESTADA', $c['page_id'] ?: null, $c['posto_id'], $c['modo'] === 'LACUNA' ? null : $c['owner'], $c['fans_h'], $c['ating'], $c['lacunas'] ?: null, escUserId() ?: null]);
            } else {
                escW('INSERT INTO ' . escT('esc_treinamento_pagina') . ' (turma_id, pagina, page_id, posto_id, owner_rep, fans_h, ating, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$turmaId, $p, $c['page_id'] ?: null, $c['posto_id'], $c['owner'], $c['fans_h'], $c['ating'], escUserId() ?: null]);
            }
        }
        escW('UPDATE ' . escT('esc_treinamento_turma') . " SET status = 'ATIVA' WHERE id = ?", [$turmaId]);
    });
    escLog('treinamento.confirmar', 'treinamento', $turmaId, null, ['paginas' => $paginas]);
}

function escTrnCancelar(int $turmaId): void {
    escW('UPDATE ' . escT('esc_treinamento_turma') . " SET status = 'CANCELADA' WHERE id = ?", [$turmaId]);
    escLog('treinamento.cancelar', 'treinamento', $turmaId, null, []);
}

// ---------- escala ----------

// Páginas no treinamento (todas as confirmadas, lacuna ou não), nos dias de treinamento e no turno
// da turma. Se todas as páginas do posto (fora as de apoio) estão no treinamento, o posto todo é do
// treinamento: o gerador não escala ninguém nele e a pessoa vai para outra página com lacuna no
// mesmo horário (ou vira buffer). Se só algumas, a pessoa fica com as outras páginas do posto.
// [dia => [pid => ['total' => bool, 'paginas' => [pagina => motivo]]]]
function escTrnCobertura(array $dias): array {
    if (!$dias || !escTrnInstalado()) {
        return [];
    }
    $porDia = []; // [dia][turno][pagina] = codigo
    foreach (escTrnTurmas(['ATIVA'], min($dias), max($dias)) as $t) {
        foreach (escTrnDias($t) as $d) {
            if (!in_array($d, $dias, true)) {
                continue;
            }
            foreach ($t['paginas'] as $p => $x) {
                $porDia[$d][$t['turno']][$p] = $t['codigo'];
            }
        }
    }
    $out = [];
    foreach ($porDia as $d => $porTurno) {
        foreach ($porTurno as $turno => $pags) {
            $postos = [];
            foreach (escQ('SELECT p.id, pp.pagina, pp.apoio FROM ' . escT('esc_posto') . ' p JOIN ' . escT('esc_posto_pagina') . ' pp ON pp.posto_id = p.id
                            WHERE p.turno = ? AND p.vigente_desde <= ? AND (p.vigente_ate IS NULL OR p.vigente_ate >= ?)
                              AND p.id IN (SELECT x.posto_id FROM ' . escT('esc_posto_pagina') . ' x WHERE x.pagina IN (' . escIn(array_keys($pags)) . '))',
                array_merge([$turno, $d, $d], array_keys($pags))) as $l) {
                $postos[(int) $l['id']][] = $l;
            }
            foreach ($postos as $pid => $linhas) {
                $principais = array_filter($linhas, static fn($l) => !(int) $l['apoio']);
                $doTreino = array_filter($linhas, static fn($l) => isset($pags[$l['pagina']]));
                if (!$doTreino) {
                    continue;
                }
                $motivos = [];
                foreach ($doTreino as $l) {
                    $motivos[$l['pagina']] = 'página no treinamento ' . $pags[$l['pagina']] . ' (trainees -4 na 5C Schedule)';
                }
                $out[$d][$pid] = ['total' => $principais && !array_filter($principais, static fn($l) => !isset($pags[$l['pagina']])), 'paginas' => $motivos];
            }
        }
    }
    return $out;
}

// Trainees fora da escala normal: [rep => [dia => true]] nos dias antes de ficarem disponíveis.
function escTrnBloqueios(array $dias): array {
    if (!$dias || !escTrnInstalado()) {
        return [];
    }
    $b = [];
    foreach (escTrnTurmas(['RASCUNHO', 'ATIVA'], min($dias), max($dias)) as $t) {
        foreach ($t['trainees'] as $rep => $x) {
            foreach ($dias as $d) {
                if ($d < $t['disponivel_em']) {
                    $b[$rep][$d] = true;
                }
            }
        }
    }
    return $b;
}

// Alocações dos trainees na semana: página 5C Schedule, seg a sex, blocos do turno da turma
// (D = T6 do dia + T1 do dia seguinte), origem TREINO (-4 no CSV). Célula travada fica como está.
function escTrnAlocacoes(string $seg, array $travadas, ?array $pagina): array {
    if (!$pagina || !escTrnInstalado()) {
        return [];
    }
    $trav = [];
    foreach ($travadas as $t) {
        $trav[substr((string) $t['dia'], 0, 10) . '|' . $t['bloco'] . '|' . (int) $t['page_id']] = true;
    }
    $dom = escMaisDias($seg, 6);
    $aloc = [];
    foreach (escTrnTurmas(['ATIVA'], $seg, $dom) as $t) {
        $onde = $t['codigo'] . ($t['paginas'] ? ' · ' . implode(', ', array_keys($t['paginas'])) : '');
        foreach (escTrnDias($t) as $d) {
            if ($d < $seg || $d > $dom) {
                continue;
            }
            foreach (ESC_TURNOS[$t['turno']] as $bloco) {
                $diaB = $t['turno'] === 'D' && $bloco === 'T1' ? escMaisDias($d, 1) : $d;
                if (isset($trav[$diaB . '|' . $bloco . '|' . (int) $pagina['page_id']])) {
                    continue;
                }
                $nesting = $t['nesting_de'] && $d >= $t['nesting_de'] && $d <= ($t['nesting_ate'] ?? $t['nesting_de']);
                foreach ($t['trainees'] as $rep => $x) {
                    $aloc[] = ['dia' => $diaB, 'bloco' => $bloco, 'turno' => $t['turno'], 'page_id' => $pagina['page_id'], 'pagina' => $pagina['pagina'],
                        'posto_id' => null, 'vaga' => null, 'rep_code' => $rep, 'papel' => 'REGULAR', 'origem' => 'TREINO', 'travada' => 0,
                        'motivo' => mb_substr('Treinamento · ' . $onde . ($nesting ? ' · Nesting' : ''), 0, 255)];
                }
            }
        }
    }
    return $aloc;
}

// ---------- resposta ao e-mail ----------

function escTrnResposta(array $turmas, bool $en = false): string {
    if (!$turmas) {
        return '';
    }
    $nomes = escNomes();
    $nomeDe = static fn(?string $r) => $r ? (nomeCurto($nomes[$r] ?? $r) . ' (' . $r . ')') : '—';
    $bl = ['A' => 'T2/T3', 'C' => 'T4/T5', 'D' => 'T6/T1'];
    $primeira = reset($turmas);
    $ini = min(array_column($turmas, 'inicio'));
    $fim = max(array_column($turmas, 'fim'));
    $l = [];
    if ($en) {
        $l[] = 'Hi everyone,';
        $l[] = '';
        $l[] = 'Planning here. Pages for the ' . ($primeira['ciclo'] ?: 'New Hire') . ' training (' . dataBr($ini) . ' to ' . dataBr($fim) . '), the same pages for the whole cycle:';
    } else {
        $l[] = 'Olá, pessoal!';
        $l[] = '';
        $l[] = 'Aqui é o Planejamento. Seguem as páginas para o treinamento ' . ($primeira['ciclo'] ?: 'de New Hires') . ' (' . dataBr($ini) . ' a ' . dataBr($fim) . '), as mesmas durante todo o ciclo:';
    }
    $l[] = '';
    foreach ($turmas as $t) {
        $l[] = $t['codigo'] . ' · ' . ($en ? 'Shift ' : 'Turno ') . $t['turno'] . ' - ' . $bl[$t['turno']] . ' (' . ($t['horario'] ?: '') . ')' . ($t['instrutor'] ? ' · ' . ($en ? 'Trainer: ' : 'Instrutor(a): ') . $t['instrutor'] : '') . ' · ' . count($t['trainees']) . ' trainees';
        if (!$t['paginas']) {
            $l[] = '  - ' . ($en ? 'pages still being defined' : 'páginas ainda em definição');
        }
        foreach ($t['paginas'] as $p => $x) {
            $l[] = '  - ' . $p . ' · ' . (($x['modo'] ?? 'EMPRESTADA') === 'LACUNA'
                ? ($en ? 'page with no one on this shift: the training covers it' : 'página sem ninguém no turno: o treinamento cobre')
                : ($en ? 'owner of the shift: ' : 'dono do turno: ') . $nomeDe($x['owner_rep']));
        }
    }
    $l[] = '';
    if ($en) {
        $l[] = 'How it works:';
        $l[] = '- The owner of the shift stays the page owner, and all commission generated goes to them. The training team only uses the page.';
        $l[] = '- Trainer and owner talk during the shift: when the training pauses, the owner takes over.';
        $l[] = '- Trainees are on the 5C Schedule page with the -4 suffix, Monday to Friday, in their class hours, for clock-in/out.';
        $l[] = '- They only enter the regular schedule from the day they are available for operation (' . implode(', ', array_unique(array_map(static fn($t) => dataBr($t['disponivel_em']), $turmas))) . ').';
    } else {
        $l[] = 'Como funciona:';
        $l[] = '- O dono do turno continua dono da página e toda comissão gerada fica com ele. A equipe de treinamento só usa a página.';
        $l[] = '- Instrutor e dono se falam no turno: nas pausas do treinamento, o dono assume.';
        $l[] = '- Os trainees estão na página 5C Schedule com final -4, de segunda a sexta, no horário da turma, para o registro de clock-in/out.';
        $l[] = '- Eles só entram na escala normal a partir do dia em que ficam disponíveis para a operação (' . implode(', ', array_unique(array_map(static fn($t) => dataBr($t['disponivel_em']), $turmas))) . ').';
    }
    $obs = [];
    foreach ($turmas as $t) {
        foreach ($t['alertas'] as $a) {
            if (strncmp($a, $t['codigo'] . ':', strlen($t['codigo'] . ':')) === 0) {
                $obs[$a] = true;
            }
        }
    }
    if ($obs) {
        $l[] = '';
        $l[] = $en ? 'Please check (in the e-mail):' : 'Por favor, confiram (no e-mail):';
        foreach (array_keys($obs) as $a) {
            $l[] = '- ' . $a;
        }
    }
    $l[] = '';
    $l[] = $en ? 'Best regards,' : 'Atenciosamente,';
    $l[] = $en ? 'Planning · 5C Company' : 'Planejamento · 5C Company';
    return implode("\n", $l);
}
