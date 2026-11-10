<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/fora_unidades.php
// Fora das unidades (seção Quadro e contratação, pedido de 10/11): quem é SR no RH e não está
// em nenhuma unidade no 5C Escalas, com a sugestão de unidade, turno e núcleo para as lacunas.
//   - Entram: SR no headcount do RH (position 'Sales Representative') sem cadastro de SR ou OS
//     vigente com unidade, que não é liderança (OM, OC, OA, OS) nem trainee de turma em
//     andamento; e quem tem cadastro vigente no Escalas sem unidade.
//   - Ficam à parte (sem destino): quem teve a saída informada no Escalas (cadastro fechado por
//     desligamento ou movimentação) e ainda aparece no RH.
//   - Sugestão: a mesma regra do destino dos novatos (lib/quadro.php, escDstSugerir), por unidade e
//     turno: 1º lacunas da escala da semana, 2º falta no Capacity (precisa − SRs hoje − novatos já
//     designados). Quem tem turno conhecido (último cadastro no Escalas; senão o turno do RH) fica
//     no turno dele; sem turno, vai para a unidade e turno com mais falta. Cada pessoa desconta
//     ESC_DST_BLOCOS_SR blocos e 1 da falta antes da próxima.
//   - Destinar = cadastro em Colaboradores (o mesmo POST das Turmas de treinamento).
// Só leitura aqui; a gravação é a de pages/colaboradores.php.
// ===============================================

// Códigos de teste do RH que nunca são pessoas.
const ESC_FU_IGNORAR = ['L000000', 'T000001'];
// Texto do cadastro fechado que indica saída já informada no Escalas.
const ESC_FU_SAIDA_RE = '~deslig|movimenta|pediu|saiu|sa[ií]da~iu';

// Pessoas fora das unidades hoje: ['candidatos' => [rep => info], 'saidas' => [rep => info], 'trainees' => n].
function escFuPessoas(string $hoje): array {
    $vig = ' vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)';
    // No RH (só as colunas necessárias: nada de dados pessoais além do nome).
    $rh = [];
    foreach (escQ("SELECT UPPER(code) AS rep, name, socialName, startContract FROM `5c_headcount`.`headcount`
                    WHERE position = 'Sales Representative' AND code IS NOT NULL AND code <> ''") as $l) {
        if (!in_array($l['rep'], ESC_FU_IGNORAR, true)) {
            $rh[$l['rep']] = $l;
        }
    }
    // Com unidade no Escalas hoje (SR ou OS) e sem unidade.
    $comUnidade = [];
    $semUnidade = [];
    foreach (escQ('SELECT UPPER(rep_code) AS rep, unidade, turno, nivel, funcao, tipo, vigente_desde FROM ' . escT('esc_colaborador_config') . ' WHERE' . $vig, [$hoje, $hoje]) as $l) {
        if ($l['unidade'] !== null) {
            $comUnidade[$l['rep']] = true;
        } elseif ($l['funcao'] === null || $l['funcao'] === 'EQUITE') {
            $semUnidade[$l['rep']] = $l;
        }
    }
    // Liderança (OM, OC, OA) e OS dos núcleos.
    $lider = [];
    foreach (escQ('SELECT UPPER(rep_code) AS rep FROM ' . escT('esc_lideranca') . ' WHERE rep_code IS NOT NULL AND' . $vig, [$hoje, $hoje]) as $l) {
        $lider[$l['rep']] = true;
    }
    foreach (escQ('SELECT UPPER(decurio_code) AS rep FROM ' . escT('esc_nucleo') . ' WHERE decurio_code IS NOT NULL AND' . $vig, [$hoje, $hoje]) as $l) {
        $lider[$l['rep']] = true;
    }
    // Trainees de turmas em andamento (o destino deles fica nas Turmas de treinamento).
    $trainee = [];
    if (escQdTabelaExiste('esc_treinamento_trainee')) {
        foreach (escQ('SELECT UPPER(r.rep_code) AS rep FROM ' . escT('esc_treinamento_trainee') . ' r JOIN ' . escT('esc_treinamento_turma') . " t ON t.id = r.turma_id
                        WHERE t.status IN ('RASCUNHO','ATIVA') AND r.rep_code IS NOT NULL") as $l) {
            $trainee[$l['rep']] = true;
        }
    }
    $reps = [];
    foreach ($rh as $rep => $x) {
        if (!isset($comUnidade[$rep]) && !isset($lider[$rep]) && !isset($trainee[$rep])) {
            $reps[$rep] = true;
        }
    }
    foreach ($semUnidade as $rep => $x) {
        if (!isset($lider[$rep]) && !isset($trainee[$rep])) {
            $reps[$rep] = true;
        }
    }
    $lista = array_keys($reps);
    if (!$lista) {
        return ['candidatos' => [], 'saidas' => [], 'trainees' => count($trainee)];
    }
    // Último cadastro no Escalas (o vigente sem unidade, ou o último que fechou).
    $ult = [];
    foreach (escQ('SELECT UPPER(rep_code) AS rep, unidade, turno, nivel, funcao, tipo, vigente_desde, vigente_ate, observacao, modelo_id, pode_assistente
                     FROM ' . escT('esc_colaborador_config') . ' WHERE UPPER(rep_code) IN (' . escIn($lista) . ') ORDER BY vigente_desde, id', $lista) as $l) {
        $ult[$l['rep']] = $l;
    }
    // Último cadastro COM unidade e turno (para saber o turno de quem já esteve numa unidade).
    $ultUT = [];
    foreach (escQ('SELECT UPPER(rep_code) AS rep, unidade, turno, nivel FROM ' . escT('esc_colaborador_config') . ' WHERE unidade IS NOT NULL AND UPPER(rep_code) IN (' . escIn($lista) . ') ORDER BY vigente_desde, id', $lista) as $l) {
        $ultUT[$l['rep']] = $l;
    }
    // Turno no RH.
    $turnoRh = [];
    foreach (escQ('SELECT UPPER(code) AS rep, tipo_turno FROM `5c_headcount`.`headcount_turno` WHERE vigente_ate IS NULL AND UPPER(code) IN (' . escIn($lista) . ')', $lista) as $l) {
        $turnoRh[$l['rep']] = (string) $l['tipo_turno'];
    }
    // Última escala no site (tabela de trabalho do Capacity; pode estar vazia durante o recálculo).
    $site = [];
    try {
        foreach (escQ('SELECT UPPER(codigo) AS rep, ultima, ultima_plan FROM `5c_planning`.`fc_wrk_rep` WHERE UPPER(codigo) IN (' . escIn($lista) . ')', $lista) as $l) {
            $site[$l['rep']] = ['ultima' => $l['ultima'] ? substr((string) $l['ultima'], 0, 10) : null, 'plan' => $l['ultima_plan'] ? substr((string) $l['ultima_plan'], 0, 10) : null];
        }
    } catch (Throwable $e) {
        $site = [];
    }
    // Indisponibilidade aprovada hoje ou adiante (afastamento).
    $ind = [];
    foreach (escQ('SELECT UPPER(i.rep_code) AS rep, MIN(d.dia) AS de, MAX(d.dia) AS ate FROM ' . escT('esc_indisponibilidade') . ' i JOIN ' . escT('esc_indisponibilidade_data') . " d ON d.indisponibilidade_id = i.id
                    WHERE d.status = 'APROVADO' AND d.dia >= ? AND UPPER(i.rep_code) IN (" . escIn($lista) . ') GROUP BY UPPER(i.rep_code)', array_merge([$hoje], $lista)) as $l) {
        $ind[$l['rep']] = ['de' => substr((string) $l['de'], 0, 10), 'ate' => substr((string) $l['ate'], 0, 10)];
    }
    $r = ['candidatos' => [], 'saidas' => [], 'trainees' => count($trainee)];
    foreach ($lista as $rep) {
        $h = $rh[$rep] ?? null;
        $u = $ult[$rep] ?? null;
        $ut = $ultUT[$rep] ?? null;
        $info = [
            'rep' => $rep,
            'nome' => $h ? (trim((string) $h['socialName']) ?: (string) $h['name']) : '',
            'no_rh' => $h !== null,
            'admissao' => $h ? trim((string) $h['startContract']) : '',
            'cadastro' => $u,
            'vigente_sem_unidade' => isset($semUnidade[$rep]),
            'ultima_ut' => $ut,
            'turno' => $semUnidade[$rep]['turno'] ?? ($ut['turno'] ?? ($turnoRh[$rep] ?? null)),
            'turno_origem' => isset($semUnidade[$rep]['turno']) || isset($ut['turno']) ? 'ESCALAS' : (isset($turnoRh[$rep]) ? 'RH' : null),
            'nivel' => $ut['nivel'] ?? ($u['nivel'] ?? null),
            'site' => $site[$rep] ?? null,
            'afastado' => $ind[$rep] ?? null,
        ];
        $fechou = $u && $u['vigente_ate'] !== null && substr((string) $u['vigente_ate'], 0, 10) < $hoje;
        if ($fechou && preg_match(ESC_FU_SAIDA_RE, (string) $u['observacao'])) {
            $r['saidas'][$rep] = $info;
        } else {
            $r['candidatos'][$rep] = $info;
        }
    }
    uasort($r['candidatos'], static fn($a, $b) => [$a['turno'] === null, $a['turno'], $a['nome']] <=> [$b['turno'] === null, $b['turno'], $b['nome']]);
    return $r;
}

// Falta e lacunas por unidade e turno, antes das sugestões: [u][t] => ['precisa', 'srs', 'novatos', 'falta', 'lac', 'postos'].
// Lacunas: escala da semana atual (sem escala gerada, zero).
function escFuEstado(string $hoje): array {
    $seg = segundaDaSemana($hoje);
    $lac = escQdLacunasUT($seg);
    $ult = escQdUltimaVersao();
    $ut = $ult ? escQdUnidadeTurno((int) $ult['versao']) : [];
    $srs = escQdSrsAtivos($hoje);
    // Novatos com destino confirmado e ainda sem cadastro: já contam na unidade.
    $nov = [];
    if (escDstInstalado()) {
        foreach (escQ('SELECT r.unidade_destino AS u, t.turno, COUNT(*) AS n FROM ' . escT('esc_treinamento_trainee') . ' r JOIN ' . escT('esc_treinamento_turma') . " t ON t.id = r.turma_id
                        WHERE t.status IN ('RASCUNHO','ATIVA') AND r.situacao = 'TREINANDO' AND r.unidade_destino IS NOT NULL AND r.destino_em IS NOT NULL
                          AND NOT EXISTS (SELECT 1 FROM " . escT('esc_colaborador_config') . " c WHERE UPPER(c.rep_code) = UPPER(r.rep_code) AND c.unidade IS NOT NULL AND (c.vigente_ate IS NULL OR c.vigente_ate >= ?))
                        GROUP BY r.unidade_destino, t.turno", [$hoje]) as $l) {
            $nov[(int) $l['u']][$l['turno']] = (int) $l['n'];
        }
    }
    $r = [];
    foreach ([1, 2, 3] as $u) {
        foreach (array_keys(ESC_QD_TURNOS) as $t) {
            $precisa = (int) ($ut[$u][$t]['precisa'] ?? 0);
            $s = (int) ($srs[$u][$t] ?? 0);
            $n = (int) ($nov[$u][$t] ?? 0);
            $r[$u][$t] = ['precisa' => $precisa, 'srs' => $s, 'novatos' => $n, 'falta' => $precisa - $s - $n, 'lac' => (int) ($lac[$u][$t] ?? 0), 'postos' => (int) ($ut[$u][$t]['postos'] ?? 0)];
        }
    }
    return $r;
}

// Sugestão para cada candidato: [rep => ['unidade', 'turno', 'nucleo_id', 'motivo']] e o estado depois ('depois').
// Afastados e quem está sem escala há mais de 30 dias vão por último (provável afastamento: confirmar antes).
function escFuSugerir(array $candidatos, array $estado, string $hoje): array {
    $nucleos = escDstNucleos($hoje);
    $seg = segundaDaSemana($hoje);
    $ordem = $candidatos;
    uasort($ordem, static function ($a, $b) use ($hoje) {
        $pa = escFuParado($a, $hoje);
        $pb = escFuParado($b, $hoje);
        return [$pa, $a['turno'] === null] <=> [$pb, $b['turno'] === null];
    });
    $r = [];
    foreach ($ordem as $rep => $c) {
        $turnos = $c['turno'] !== null && isset(ESC_QD_TURNOS[$c['turno']]) ? [$c['turno']] : array_keys(ESC_QD_TURNOS);
        $melhor = null;
        foreach ([1, 2, 3] as $u) {
            foreach ($turnos as $t) {
                $k = [$estado[$u][$t]['lac'], $estado[$u][$t]['falta']];
                if ($melhor === null || $k > $melhor['k']) {
                    $melhor = ['u' => $u, 't' => $t, 'k' => $k];
                }
            }
        }
        $u = $melhor['u'];
        $t = $melhor['t'];
        $e = $estado[$u][$t];
        $alvoNuc = $e['postos'] / 2;
        $nuc = null;
        $def = null;
        foreach ($nucleos[$u][$t] ?? [] as $id => $n) {
            $d = $alvoNuc - $n['srs'];
            if ($def === null || $d > $def) {
                $def = $d;
                $nuc = $id;
            }
        }
        $nInfo = $nuc !== null ? $nucleos[$u][$t][$nuc] : null;
        $motivo = ($e['lac'] > 0
                ? t('U{u} tem {lac} blocos sem ninguém no turno {t} na escala de {sem} e faltam {falta} no Capacity', ['u' => $u, 'lac' => $e['lac'], 't' => $t, 'sem' => escData($seg), 'falta' => $e['falta']])
                : t('U{u} é a que mais falta no turno {t} pelo Capacity ({falta})', ['u' => $u, 't' => $t, 'falta' => $e['falta']]))
            . (count($turnos) > 1 ? ' · ' . t('sem turno conhecido: o turno com mais falta') : '')
            . ($nInfo ? '; ' . t('núcleo {n} ({os}) tem {srs} SRs para {alvo}', ['n' => $nInfo['numero'], 'os' => nomeCurto($nInfo['os']) ?: '—', 'srs' => $nInfo['srs'], 'alvo' => num($alvoNuc, 0)]) : '');
        $r[$rep] = ['unidade' => $u, 'turno' => $t, 'nucleo_id' => $nuc, 'motivo' => $motivo];
        $estado[$u][$t]['lac'] -= ESC_DST_BLOCOS_SR;
        $estado[$u][$t]['falta']--;
        if ($nuc !== null) {
            $nucleos[$u][$t][$nuc]['srs']++;
        }
    }
    return ['sugestoes' => $r, 'depois' => $estado, 'nucleos' => escDstNucleos($hoje)];
}

// Afastado (indisponibilidade aprovada hoje) ou sem escala no site há mais de 30 dias.
function escFuParado(array $c, string $hoje): bool {
    if ($c['afastado'] && $c['afastado']['de'] <= $hoje) {
        return true;
    }
    $ult = $c['site']['plan'] ?? null;
    return $ult === null ? $c['no_rh'] && $c['cadastro'] !== null : $ult < escMaisDias($hoje, -30);
}

// Registro diário do quadro (5c_planning.fc_quadro_dia, script 35): SRs com unidade por dia, no filtro
// de unidade e turno da tela Capacity. [data => srs]; vazio se o script ainda não rodou.
function escFuQuadroDia(string $desde, int $unidade = 0, string $turno = ''): array {
    static $existe = null;
    $existe ??= (bool) escQ1("SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = '5c_planning' AND TABLE_NAME = 'fc_quadro_dia'");
    if (!$existe) {
        return [];
    }
    $f = '';
    $p = [$desde];
    if ($unidade) {
        $f .= ' AND unidade = ?';
        $p[] = $unidade;
    }
    if ($turno !== '') {
        $f .= ' AND turno = ?';
        $p[] = $turno;
    }
    $r = [];
    foreach (escQ('SELECT data, SUM(srs) AS srs FROM `5c_planning`.`fc_quadro_dia` WHERE data >= ? AND unidade > 0' . $f . ' GROUP BY data', $p) as $l) {
        $r[substr((string) $l['data'], 0, 10)] = (int) $l['srs'];
    }
    return $r;
}

// Modelo de escala mais usado pelos SRs (o mesmo que as Turmas de treinamento usam no cadastro).
function escFuModeloPadrao(): int {
    return (int) (escQ1('SELECT modelo_id, COUNT(*) AS n FROM ' . escT('esc_colaborador_config') . " WHERE funcao = 'EQUITE' AND vigente_ate IS NULL GROUP BY modelo_id ORDER BY n DESC LIMIT 1")['modelo_id'] ?? 0);
}
