<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/quadro.php
// Seção "Quadro e contratação" (1.24.0): leituras comuns às telas Capacity,
// Requisição de vagas e Turmas de treinamento (destino dos novatos).
//   - Capacity: versões do 5c_planning (fc_capacity_versao*, só leitura). O Capacity é a
//     exceção ao corte de 01/11 (escCorteDados): todas as versões contam.
//   - Ocupação: pessoas escaladas nas páginas de cada carteira (plano atual da semana) ÷
//     postos do Capacity × dias; lacunas = blocos sem ninguém (origem LACUNA).
//   - Requisições: esc_requisicao_vaga (ES_136) sobre o plano de turmas (fc_contratacoes), que a tela
//     também grava: adicionar, editar e apagar turmas; a requisição substitui o plano da turma.
// ===============================================

const ESC_QD_TURNOS = ['A' => '05–13', 'C' => '13–21', 'D' => '21–05'];
// Quem está escalado mas não ocupa posto: buffer, overlap, treinamento e liderança.
const ESC_QD_FORA_DO_POSTO = ['BUFFER', 'OVERLAP', 'TREINO', 'LIDER_OS', 'LIDER_OC', 'LIDER_OA'];

function escQdTabelaExiste(string $tabela): bool {
    static $cache = [];
    if (!isset($cache[$tabela])) {
        $cache[$tabela] = (bool) escQ1('SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [ESC_SCHEMA, $tabela]);
    }
    return $cache[$tabela];
}

function escQdColunaExiste(string $tabela, string $coluna): bool {
    static $cache = [];
    $k = $tabela . '.' . $coluna;
    if (!isset($cache[$k])) {
        $cache[$k] = (bool) escQ1('SELECT 1 AS ok FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', [ESC_SCHEMA, $tabela, $coluna]);
    }
    return $cache[$k];
}

// Última versão do Capacity (a mais nova de todas).
function escQdUltimaVersao(): ?array {
    return escQ1('SELECT versao, data_calculo, gerado_em, origem, obs, motivo, postos, titulares, buffer, precisa, tem, saldo, fecha_oficial, fecha_sugerido, contratar_oficial, contratar_sugerido
                    FROM `5c_planning`.`fc_capacity_versao` ORDER BY versao DESC LIMIT 1');
}

// A versão que valia num dia (a última calculada até ele); sem nenhuma antes, a mais antiga.
function escQdVersaoDoDia(string $dia): ?int {
    $v = escQ1('SELECT MAX(versao) AS v FROM `5c_planning`.`fc_capacity_versao` WHERE data_calculo <= ?', [$dia])['v'] ?? null;
    if ($v === null) {
        $v = escQ1('SELECT MIN(versao) AS v FROM `5c_planning`.`fc_capacity_versao`')['v'] ?? null;
    }
    return $v === null ? null : (int) $v;
}

// Uma linha por dia (a última versão do dia), somando as unidades e os turnos do filtro.
function escQdEvolucao(string $desde, int $unidade = 0, string $turno = ''): array {
    $f = '';
    $p = [$desde];
    if ($unidade) {
        $f .= ' AND u.unidade = ?';
        $p[] = $unidade;
    }
    if ($turno !== '') {
        $f .= ' AND u.turno = ?';
        $p[] = $turno;
    }
    return escQ('SELECT v.versao, v.data_calculo, SUM(u.postos) AS postos, SUM(u.titulares) AS titulares, SUM(u.buffer) AS buffer,
                        SUM(u.precisa) AS precisa, SUM(u.tem) AS tem
                   FROM `5c_planning`.`fc_capacity_versao` v
                   JOIN `5c_planning`.`fc_capacity_versao_unidade` u ON u.versao = v.versao
                  WHERE v.versao IN (SELECT MAX(versao) FROM `5c_planning`.`fc_capacity_versao` WHERE data_calculo >= ? GROUP BY data_calculo)' . $f . '
                  GROUP BY v.versao, v.data_calculo ORDER BY v.data_calculo', $p);
}

// [unidade][turno] => postos, titulares, buffer, precisa, tem, saldo, faltas_dia, pico_dia de uma versão.
function escQdUnidadeTurno(int $versao): array {
    $r = [];
    foreach (escQ('SELECT unidade, turno, postos, titulares, buffer, precisa, tem, saldo, faltas_dia, pico_dia FROM `5c_planning`.`fc_capacity_versao_unidade` WHERE versao = ?', [$versao]) as $l) {
        $r[(int) $l['unidade']][$l['turno']] = $l;
    }
    return $r;
}

// As versões do período, da mais nova para a mais antiga, com o que mudou.
function escQdVersoes(string $desde, int $limite = 15): array {
    return escQ('SELECT versao, data_calculo, gerado_em, origem, obs, motivo, postos, precisa, tem, saldo FROM `5c_planning`.`fc_capacity_versao`
                  WHERE data_calculo >= ? ORDER BY versao DESC LIMIT ' . max(1, $limite), [$desde]);
}

// Ocupação dos postos na semana, por carteira e turno: [carteira][turno] => postos (Capacity),
// pessoa_dias (SRs distintos por dia nas páginas da carteira), dias (com escala), lacunas (blocos vazios).
function escQdOcupacao(?array $plano, int $versao): array {
    $r = [];
    foreach (escQ('SELECT carteira, turno, COUNT(DISTINCT posto) AS postos FROM `5c_planning`.`fc_capacity_versao_posto` WHERE versao = ? GROUP BY carteira, turno', [$versao]) as $l) {
        $r[$l['carteira']][$l['turno']] = ['postos' => (int) $l['postos'], 'pessoa_dias' => 0, 'lacunas' => 0, 'dias' => 0];
    }
    if (!$plano) {
        return $r;
    }
    $fora = escIn(ESC_QD_FORA_DO_POSTO);
    $linhas = escQ("SELECT pc.carteira, a.turno,
                           COUNT(DISTINCT CASE WHEN a.rep_code IS NOT NULL AND a.origem NOT IN ($fora) THEN CONCAT(a.rep_code, '|', IF(a.bloco = 'T1', DATE_SUB(a.dia, INTERVAL 1 DAY), a.dia)) END) AS pessoa_dias,
                           SUM(a.origem = 'LACUNA') AS lacunas,
                           COUNT(DISTINCT IF(a.bloco = 'T1', DATE_SUB(a.dia, INTERVAL 1 DAY), a.dia)) AS dias
                      FROM " . escT('esc_plano_alocacao') . " a
                      JOIN `5c_planning`.`fc_pagina_carteira` pc ON pc.pagina COLLATE utf8mb4_unicode_ci = a.pagina COLLATE utf8mb4_unicode_ci
                           AND pc.vigente_desde <= a.dia AND (pc.vigente_ate IS NULL OR pc.vigente_ate > a.dia)
                     WHERE a.plano_id = ? AND a.papel = 'REGULAR'
                     GROUP BY pc.carteira, a.turno", array_merge(ESC_QD_FORA_DO_POSTO, [(int) $plano['id']]));
    foreach ($linhas as $l) {
        $c = &$r[$l['carteira']][$l['turno']];
        $c = ($c ?? ['postos' => 0]) + ['pessoa_dias' => 0, 'lacunas' => 0, 'dias' => 0];
        $c['pessoa_dias'] = (int) $l['pessoa_dias'];
        $c['lacunas'] = (int) $l['lacunas'];
        $c['dias'] = min(7, (int) $l['dias']);
        unset($c);
    }
    ksort($r);
    return $r;
}

// Blocos sem ninguém por unidade e turno no plano atual de uma semana (lacunas da escala gerada).
function escQdLacunasUT(string $seg): array {
    $plano = escPlanoAtual($seg);
    $r = [];
    if (!$plano) {
        return $r;
    }
    foreach (escQ("SELECT CAST(SUBSTRING(pc.carteira, 2, 1) AS UNSIGNED) AS unidade, a.turno, COUNT(*) AS n
                     FROM " . escT('esc_plano_alocacao') . " a
                     JOIN `5c_planning`.`fc_pagina_carteira` pc ON pc.pagina COLLATE utf8mb4_unicode_ci = a.pagina COLLATE utf8mb4_unicode_ci
                          AND pc.vigente_desde <= a.dia AND (pc.vigente_ate IS NULL OR pc.vigente_ate > a.dia)
                    WHERE a.plano_id = ? AND a.origem = 'LACUNA'
                    GROUP BY 1, 2", [(int) $plano['id']]) as $l) {
        $r[(int) $l['unidade']][$l['turno']] = (int) $l['n'];
    }
    return $r;
}

// Faturamento de 30 dias por carteira gravado na versão (dado restrito: só escPodeVerRestrito).
function escQdFaturamento(int $versao): array {
    $r = [];
    foreach (escQ('SELECT carteira, bruto_30d, paginas FROM `5c_planning`.`fc_capacity_versao_faturamento` WHERE versao = ?', [$versao]) as $l) {
        $r[$l['carteira']] = ['bruto' => (float) $l['bruto_30d'], 'paginas' => (int) $l['paginas']];
    }
    return $r;
}

// SRs com cadastro vigente no 5C Escalas num dia, por unidade e turno. O "tem" do Capacity vem da foto
// provisória de 27/10 (fc_estrutura_rep); este é o quadro de hoje no módulo.
function escQdSrsAtivos(string $dia): array {
    $r = [];
    foreach (escQ('SELECT unidade, turno, COUNT(DISTINCT rep_code) AS n FROM ' . escT('esc_colaborador_config') . "
                   WHERE funcao = 'EQUITE' AND unidade IS NOT NULL AND vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)
                   GROUP BY unidade, turno", [$dia, $dia]) as $l) {
        $r[(int) $l['unidade']][$l['turno']] = (int) $l['n'];
    }
    return $r;
}

// A versão mais nova que calculou quando a falta fecha (o cálculo diário nem sempre refaz o plano).
function escQdUltimoFechamento(): ?array {
    return escQ1('SELECT versao, data_calculo, fecha_oficial, fecha_sugerido, contratar_oficial, contratar_sugerido FROM `5c_planning`.`fc_capacity_versao`
                   WHERE fecha_oficial IS NOT NULL ORDER BY versao DESC LIMIT 1');
}

// Rótulo curto "U1 · A".
function escQdRotUT(int $u, string $t): string {
    return 'U' . $u . ' · ' . $t;
}

// ---------------------------------------------------------------------------------------------
// Requisição de vagas (o Simulações 5C dentro do módulo)
// ---------------------------------------------------------------------------------------------

const ESC_REQ_ETAPAS = ['ABERTA', 'CONTRATADA', 'CANCELADA'];

function escReqInstalado(): bool {
    return escQdTabelaExiste('esc_requisicao_vaga');
}

// Bases da simulação: as últimas versões do Capacity (uma por dia), com precisa, titulares e "tem" por turno.
function escReqBases(int $quantas = 10): array {
    $r = [];
    foreach (escQ('SELECT v.versao, v.data_calculo, u.turno, SUM(u.precisa) AS precisa, SUM(u.titulares) AS titulares, SUM(u.tem) AS tem, SUM(u.postos) AS postos
                     FROM `5c_planning`.`fc_capacity_versao` v JOIN `5c_planning`.`fc_capacity_versao_unidade` u ON u.versao = v.versao
                    WHERE v.versao IN (SELECT MAX(versao) FROM `5c_planning`.`fc_capacity_versao` GROUP BY data_calculo)
                    GROUP BY v.versao, v.data_calculo, u.turno ORDER BY v.versao DESC') as $l) {
        $v = (int) $l['versao'];
        if (!isset($r[$v]) && count($r) >= $quantas) {
            continue;
        }
        $r[$v] ??= ['versao' => $v, 'data' => substr((string) $l['data_calculo'], 0, 10), 'precisa' => [], 'titulares' => [], 'tem' => [], 'postos' => 0];
        $r[$v]['precisa'][$l['turno']] = (int) $l['precisa'];
        $r[$v]['titulares'][$l['turno']] = (int) $l['titulares'];
        $r[$v]['tem'][$l['turno']] = (int) $l['tem'];
        $r[$v]['postos'] += (int) $l['postos'];
    }
    return array_values($r);
}

// Parâmetros do plano de turmas no 5c_planning (fc_parametros), com os padrões do Simulações 5C.
function escReqPremissas(): array {
    $p = [];
    foreach (escQ("SELECT chave, valor FROM `5c_planning`.`fc_parametros` WHERE chave IN ('teto_turma_turno','teto_turma','rampa_novato_rend','rampa_novato_dias','novato_sem_1_4','novato_sem_5_13','falta_fechada')") as $l) {
        $p[$l['chave']] = (float) $l['valor'];
    }
    return [
        'tetoTurno' => (int) ($p['teto_turma_turno'] ?? 10),
        'tetoTurma' => (int) ($p['teto_turma'] ?? 30),
        'rend' => (int) round(100 * ($p['rampa_novato_rend'] ?? 0.5)),
        'rampa' => (int) ($p['rampa_novato_dias'] ?? 30),
        // Saída do novato: semanal × 4 semanas (30 dias) e × 9 semanas (do dia 30 ao 90), em %.
        'n30' => round(100 * 4 * ($p['novato_sem_1_4'] ?? 0.03), 1),
        'n90' => round(100 * 9 * ($p['novato_sem_5_13'] ?? 0.0122), 1),
        'fechada' => (int) ($p['falta_fechada'] ?? 5),
        'trein' => 14,
        'recr' => 14,
        // Saída semanal do quadro por turno (12 semanas até 26/10, medida no banco; a mesma do Simulações 5C).
        'txA' => 0.89, 'txC' => 1.79, 'txD' => 1.86,
    ];
}

// Turmas do plano oficial (fc_contratacoes, sem as canceladas): [data_inicio_trn => turma].
function escReqTurmasPlano(): array {
    $r = [];
    foreach (escQ("SELECT data_inicio_trn, data_inicio_operacao, turno, qtd, status, fixa, obs FROM `5c_planning`.`fc_contratacoes`
                   WHERE status <> 'CANCELADA' ORDER BY data_inicio_trn, turno") as $l) {
        $d = substr((string) $l['data_inicio_trn'], 0, 10);
        $r[$d] ??= ['ini' => $d, 'opera' => substr((string) $l['data_inicio_operacao'], 0, 10), 'status' => $l['status'], 'fixa' => 0, 'q' => ['A' => 0, 'C' => 0, 'D' => 0], 'obs' => (string) $l['obs']];
        $r[$d]['q'][$l['turno']] += (int) $l['qtd'];
        $r[$d]['fixa'] = max($r[$d]['fixa'], (int) $l['fixa']);
    }
    return $r;
}

// Requisições registradas: [turma_inicio => [turno => linha]].
function escReqRequisicoes(): array {
    $r = [];
    if (!escReqInstalado()) {
        return $r;
    }
    foreach (escQ('SELECT id, turma_inicio, turno, qtd_pedida, qtd_contratada, etapa, aberta_em, referencia, obs, atualizado_em, criado_em FROM ' . escT('esc_requisicao_vaga') . ' ORDER BY turma_inicio, turno') as $l) {
        $r[substr((string) $l['turma_inicio'], 0, 10)][$l['turno']] = $l;
    }
    return $r;
}

// Turmas de treinamento (esc_treinamento_turma) pela segunda-feira do início: [segunda => [turno => ['codigo', 'hc', 'ativos', 'disponivel_em']]].
// Trainee que desistiu (ES_137) não conta em "ativos".
function escReqTurmasTreino(): array {
    $r = [];
    if (!escTrnInstalado()) {
        return $r;
    }
    $comSituacao = escQdColunaExiste('esc_treinamento_trainee', 'situacao');
    foreach (escQ('SELECT t.id, t.codigo, t.turno, t.hc, t.inicio, t.disponivel_em, t.status,
                          COUNT(r.id) AS trainees, ' . ($comSituacao ? "SUM(r.situacao = 'TREINANDO')" : 'COUNT(r.id)') . ' AS ativos
                     FROM ' . escT('esc_treinamento_turma') . ' t LEFT JOIN ' . escT('esc_treinamento_trainee') . " r ON r.turma_id = t.id
                    WHERE t.status IN ('RASCUNHO','ATIVA') GROUP BY t.id") as $l) {
        $seg = segundaDaSemana(substr((string) $l['inicio'], 0, 10));
        $n = (int) $l['trainees'] > 0 ? (int) $l['ativos'] : (int) $l['hc'];
        $x = $r[$seg][$l['turno']] ?? ['codigo' => '', 'hc' => 0, 'ativos' => 0, 'disponivel_em' => ''];
        $r[$seg][$l['turno']] = ['codigo' => trim($x['codigo'] . ' ' . $l['codigo']), 'hc' => $x['hc'] + (int) $l['hc'], 'ativos' => $x['ativos'] + $n,
            'disponivel_em' => max($x['disponivel_em'], substr((string) $l['disponivel_em'], 0, 10))];
    }
    return $r;
}

// SRs com cadastro hoje por turno (quadro de partida da simulação).
function escReqQuadroHoje(): array {
    $r = ['A' => 0, 'C' => 0, 'D' => 0];
    foreach (escQdSrsAtivos(date('Y-m-d')) as $porT) {
        foreach ($porT as $t => $n) {
            $r[$t] = ($r[$t] ?? 0) + $n;
        }
    }
    return $r;
}

// Junta plano, requisições e treinamento numa lista de turmas com a etapa de cada uma:
// PLANEJADA (só no plano) → ABERTA (requisição enviada) → CONTRATADA → TREINANDO → OPERANDO.
// 'q' = quantas contam na simulação (as pessoas reais quando já existem).
function escReqTurmas(string $hoje): array {
    $plano = escReqTurmasPlano();
    $req = escReqRequisicoes();
    $trn = escReqTurmasTreino();
    $datas = array_unique(array_merge(array_keys($plano), array_keys($req)));
    sort($datas);
    $r = [];
    foreach ($datas as $d) {
        $p = $plano[$d] ?? null;
        $rq = $req[$d] ?? [];
        $vivas = array_filter($rq, static fn($x) => $x['etapa'] !== 'CANCELADA');
        $tr = $trn[segundaDaSemana($d)] ?? [];
        $t = ['ini' => $d, 'opera' => $p['opera'] ?? escMaisDias($d, 14), 'plano' => $p ? $p['q'] : null, 'plano_status' => $p['status'] ?? null, 'fixa' => (int) ($p['fixa'] ?? 0),
              'req' => $rq, 'treino' => $tr, 'q' => ['A' => 0, 'C' => 0, 'D' => 0], 'pedida' => ['A' => 0, 'C' => 0, 'D' => 0], 'contratada' => null,
              'referencia' => '', 'aberta_em' => null];
        foreach (['A', 'C', 'D'] as $tt) {
            $x = $vivas[$tt] ?? null;
            $t['pedida'][$tt] = $x ? (int) $x['qtd_pedida'] : 0;
            if ($x && $x['etapa'] === 'CONTRATADA') {
                $t['contratada'][$tt] = (int) $x['qtd_contratada'];
            }
            if ($x) {
                $t['referencia'] = $t['referencia'] ?: (string) $x['referencia'];
                $t['aberta_em'] = $t['aberta_em'] ?: ($x['aberta_em'] ? substr((string) $x['aberta_em'], 0, 10) : null);
            }
            if (isset($tr[$tt])) {
                $t['q'][$tt] = (int) $tr[$tt]['ativos'];
            } elseif ($x) {
                $t['q'][$tt] = $x['etapa'] === 'CONTRATADA' ? (int) $x['qtd_contratada'] : (int) $x['qtd_pedida'];
            } elseif ($p && !$vivas && !$rq) {
                $t['q'][$tt] = (int) $p['q'][$tt];
            }
        }
        if ($tr) {
            $disp = max(array_column($tr, 'disponivel_em'));
            $t['etapa'] = $hoje >= $disp ? 'OPERANDO' : 'TREINANDO';
            $t['opera'] = $disp;
        } elseif ($rq && !$vivas) {
            $t['etapa'] = 'CANCELADA';
        } elseif ($vivas) {
            $t['etapa'] = count(array_filter($vivas, static fn($x) => $x['etapa'] === 'CONTRATADA')) === count($vivas) ? 'CONTRATADA' : 'ABERTA';
        } else {
            $t['etapa'] = 'PLANEJADA';
        }
        $r[] = $t;
    }
    return $r;
}

// Abre (ou refaz) a requisição de uma turma: uma linha por turno com quantidade > 0.
function escReqAbrir(string $ini, array $q, ?string $abertaEm, string $referencia, string $obs): int {
    $n = 0;
    escTx(function () use ($ini, $q, $abertaEm, $referencia, $obs, &$n) {
        foreach (['A', 'C', 'D'] as $t) {
            $qt = max(0, min(99, (int) ($q[$t] ?? 0)));
            $atual = escQW('SELECT id, qtd_pedida, etapa FROM ' . escT('esc_requisicao_vaga') . ' WHERE turma_inicio = ? AND turno = ?', [$ini, $t])[0] ?? null;
            if ($qt === 0) {
                if ($atual && $atual['etapa'] === 'ABERTA') {
                    escW('UPDATE ' . escT('esc_requisicao_vaga') . " SET etapa = 'CANCELADA', atualizado_por = ? WHERE id = ?", [escUserId(), (int) $atual['id']]);
                }
                continue;
            }
            if ($atual) {
                escW('UPDATE ' . escT('esc_requisicao_vaga') . " SET qtd_pedida = ?, etapa = IF(etapa = 'CANCELADA', 'ABERTA', etapa), aberta_em = COALESCE(?, aberta_em),
                             referencia = COALESCE(NULLIF(?, ''), referencia), obs = COALESCE(NULLIF(?, ''), obs), atualizado_por = ? WHERE id = ?",
                    [$qt, $abertaEm, $referencia, $obs, escUserId(), (int) $atual['id']]);
            } else {
                escW('INSERT INTO ' . escT('esc_requisicao_vaga') . " (turma_inicio, turno, qtd_pedida, etapa, aberta_em, referencia, obs, criado_por) VALUES (?, ?, ?, 'ABERTA', ?, NULLIF(?, ''), NULLIF(?, ''), ?)",
                    [$ini, $t, $qt, $abertaEm, $referencia, $obs, escUserId()]);
            }
            $n += $qt;
        }
        // A requisição substitui o plano: o plano da turma passa a ter os números pedidos (CONFIRMADA).
        // No Modo Treino o plano (5c_planning) não é tocado.
        if (!escModoTreino()) {
            escReqPlanoGravarQ($ini, $q, 'CONFIRMADA', true);
        }
        escLog('requisicao.abrir', 'esc_requisicao_vaga', $ini, null, ['q' => $q, 'aberta_em' => $abertaEm, 'referencia' => $referencia]);
    });
    return $n;
}

// ---------------------------------------------------------------------------------------------
// Plano de turmas (5c_planning.fc_contratacoes): adicionar, editar e apagar pela tela.
// O plano alimenta o Capacity (plano oficial, sp_fc_plano_sugerido, sp_fc_prev_to).
// Turma em treinamento não muda aqui: os números vêm das Turmas de treinamento.
// ---------------------------------------------------------------------------------------------

const ESC_REQ_DIAS_TREINO = 14;

function escReqPlanoExigirGravavel(): void {
    if (escModoTreino()) {
        throw new RuntimeException(t('No Modo Treino o plano de turmas não é gravado (ele fica no Capacity). Use a simulação para testar.'));
    }
}

// Etapa da turma que começa em $ini (null = não existe).
function escReqEtapaDe(string $ini): ?string {
    foreach (escReqTurmas(date('Y-m-d')) as $tm) {
        if ($tm['ini'] === $ini) {
            return $tm['etapa'];
        }
    }
    return null;
}

function escReqExigirForaDoTreino(string $ini): void {
    if (in_array(escReqEtapaDe($ini), ['TREINANDO', 'OPERANDO'], true)) {
        throw new RuntimeException(t('A turma de {dia} já está em treinamento: os números vêm das Turmas de treinamento.', ['dia' => escData($ini, false, true)]));
    }
}

// Grava as quantidades de uma turma no plano, turno a turno: linha que existe recebe a quantidade
// (0 = CANCELADA); turno sem linha e com gente ganha uma linha nova com $status.
// $mudarStatus: as linhas que já existem também passam a $status (a requisição confirma o plano).
function escReqPlanoGravarQ(string $ini, array $q, string $status, bool $mudarStatus = false): void {
    $opera = escMaisDias($ini, ESC_REQ_DIAS_TREINO);
    foreach (['A', 'C', 'D'] as $t) {
        $qt = max(0, min(99, (int) ($q[$t] ?? 0)));
        $ids = array_map(static fn($l) => (int) $l['id'], escQW("SELECT id FROM `5c_planning`.`fc_contratacoes` WHERE data_inicio_trn = ? AND turno = ? AND status <> 'CANCELADA' ORDER BY id", [$ini, $t]));
        if ($ids) {
            $id = array_shift($ids);
            if ($qt === 0) {
                escW("UPDATE `5c_planning`.`fc_contratacoes` SET status = 'CANCELADA' WHERE id = ?", [$id]);
            } else {
                escW('UPDATE `5c_planning`.`fc_contratacoes` SET qtd = ?' . ($mudarStatus ? ', status = ?' : '') . ' WHERE id = ?', $mudarStatus ? [$qt, $status, $id] : [$qt, $id]);
            }
            // Linhas repetidas do mesmo turno (não deveria haver): saem, para a soma bater.
            foreach ($ids as $extra) {
                escW("UPDATE `5c_planning`.`fc_contratacoes` SET status = 'CANCELADA' WHERE id = ?", [$extra]);
            }
        } elseif ($qt > 0) {
            escW('INSERT INTO `5c_planning`.`fc_contratacoes` (turma, data_inicio_trn, data_inicio_operacao, turno, qtd, status, obs, fixa) VALUES (?, ?, ?, ?, ?, ?, ?, 0)',
                [$ini, $ini, $opera, $t, $qt, $status, mb_substr('Incluída no 5C Escalas por ' . ((string) ($GLOBALS['ESC_USUARIO']['nome'] ?? '') ?: 'usuário ' . escUserId()) . ' em ' . date('d/m/Y'), 0, 255)]);
        }
    }
}

// Já existe turma (no plano ou com requisição viva) que começa em $ini?
function escReqTurmaExiste(string $ini): bool {
    if (escQW("SELECT 1 AS ok FROM `5c_planning`.`fc_contratacoes` WHERE data_inicio_trn = ? AND status <> 'CANCELADA' LIMIT 1", [$ini])) {
        return true;
    }
    return escReqInstalado() && (bool) escQW('SELECT 1 AS ok FROM ' . escT('esc_requisicao_vaga') . " WHERE turma_inicio = ? AND etapa <> 'CANCELADA' LIMIT 1", [$ini]);
}

// Salva uma turma: muda a data (de $de para $para) e, com $q, as quantidades do plano.
// Mudar a data leva junto as requisições da turma. $q = null: só a data.
function escReqPlanoSalvar(string $de, string $para, ?array $q): void {
    escReqPlanoExigirGravavel();
    escReqExigirForaDoTreino($de);
    escTx(function () use ($de, $para, $q) {
        if ($para !== $de) {
            if (escReqTurmaExiste($para)) {
                throw new RuntimeException(t('Já existe uma turma em {dia}. Edite essa turma ou escolha outra data.', ['dia' => escData($para, false, true)]));
            }
            escW("UPDATE `5c_planning`.`fc_contratacoes` SET turma = ?, data_inicio_trn = ?, data_inicio_operacao = ? WHERE data_inicio_trn = ? AND status <> 'CANCELADA'",
                [$para, $para, escMaisDias($para, ESC_REQ_DIAS_TREINO), $de]);
            if (escReqInstalado()) {
                // Requisições canceladas na data de destino saem, para não bater na chave (turma, turno).
                escW('DELETE FROM ' . escT('esc_requisicao_vaga') . " WHERE turma_inicio = ? AND etapa = 'CANCELADA'", [$para]);
                escW('UPDATE ' . escT('esc_requisicao_vaga') . ' SET turma_inicio = ?, atualizado_por = ? WHERE turma_inicio = ?', [$para, escUserId(), $de]);
            }
        }
        if ($q !== null) {
            escReqPlanoGravarQ($para, $q, 'PLANEJADA');
        }
        escLog('requisicao.turma_salvar', 'fc_contratacoes', $de, ['ini' => $de], ['ini' => $para, 'q' => $q]);
    });
}

// Turma nova (também entre as do plano). $abrir = já com a requisição aberta.
function escReqPlanoNova(string $ini, array $q, bool $abrir): void {
    escReqPlanoExigirGravavel();
    if (escReqTurmaExiste($ini)) {
        throw new RuntimeException(t('Já existe uma turma em {dia}. Edite essa turma ou escolha outra data.', ['dia' => escData($ini, false, true)]));
    }
    escTx(function () use ($ini, $q, $abrir) {
        if ($abrir) {
            escReqAbrir($ini, $q, null, '', '');
        } else {
            escReqPlanoGravarQ($ini, $q, 'PLANEJADA');
        }
        escLog('requisicao.turma_nova', 'fc_contratacoes', $ini, null, ['q' => $q, 'abrir' => $abrir]);
    });
}

// Apaga a turma: o plano fica CANCELADA (o histórico continua no banco) e a requisição é cancelada.
function escReqPlanoApagar(string $ini): void {
    escReqPlanoExigirGravavel();
    escReqExigirForaDoTreino($ini);
    escTx(function () use ($ini) {
        escW("UPDATE `5c_planning`.`fc_contratacoes` SET status = 'CANCELADA' WHERE data_inicio_trn = ? AND status <> 'CANCELADA'", [$ini]);
        if (escReqInstalado()) {
            escReqCancelar($ini);
        }
        escLog('requisicao.turma_apagar', 'fc_contratacoes', $ini);
    });
}

// Grava de uma vez as turmas planejadas (depois de "Sugerir tamanhos"): [ini => [A, C, D]].
// Só turmas ainda planejadas: as que têm requisição ou treinamento são puladas. Devolve quantas gravou.
function escReqPlanoSalvarTodas(array $turmas): int {
    escReqPlanoExigirGravavel();
    $etapas = [];
    foreach (escReqTurmas(date('Y-m-d')) as $tm) {
        $etapas[$tm['ini']] = $tm['etapa'];
    }
    $n = 0;
    escTx(function () use ($turmas, $etapas, &$n) {
        foreach ($turmas as $ini => $q) {
            if (($etapas[$ini] ?? null) !== 'PLANEJADA' || !is_array($q)) {
                continue;
            }
            escReqPlanoGravarQ($ini, $q, 'PLANEJADA');
            $n++;
        }
        escLog('requisicao.plano_salvar_todas', 'fc_contratacoes', null, null, ['turmas' => $turmas]);
    });
    return $n;
}

// O recrutamento fechou: quantos foram contratados em cada turno.
function escReqContratar(string $ini, array $q): void {
    escTx(function () use ($ini, $q) {
        foreach (escQW('SELECT id, turno FROM ' . escT('esc_requisicao_vaga') . " WHERE turma_inicio = ? AND etapa <> 'CANCELADA'", [$ini]) as $l) {
            escW('UPDATE ' . escT('esc_requisicao_vaga') . " SET qtd_contratada = ?, etapa = 'CONTRATADA', atualizado_por = ? WHERE id = ?",
                [max(0, min(99, (int) ($q[$l['turno']] ?? 0))), escUserId(), (int) $l['id']]);
        }
        escLog('requisicao.contratar', 'esc_requisicao_vaga', $ini, null, ['q' => $q]);
    });
}

function escReqCancelar(string $ini): void {
    escTx(function () use ($ini) {
        escW('UPDATE ' . escT('esc_requisicao_vaga') . " SET etapa = 'CANCELADA', atualizado_por = ? WHERE turma_inicio = ?", [escUserId(), $ini]);
        escLog('requisicao.cancelar', 'esc_requisicao_vaga', $ini);
    });
}

// ---------------------------------------------------------------------------------------------
// Destino dos novatos (Turmas de treinamento, ES_137)
// ---------------------------------------------------------------------------------------------

// Blocos de 4 h que um SR cobre por semana (5 dias × 2 blocos): quanto uma pessoa "fecha" de lacuna.
const ESC_DST_BLOCOS_SR = 10;

function escDstInstalado(): bool {
    return escQdColunaExiste('esc_treinamento_trainee', 'unidade_destino');
}

// Núcleos vigentes num dia, por unidade e turno: [unidade][turno][id] => ['numero', 'os', 'srs'].
function escDstNucleos(string $dia): array {
    $r = [];
    foreach (escQ('SELECT id, unidade, turno, numero, decurio_nome FROM ' . escT('esc_nucleo') . ' WHERE vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?) ORDER BY unidade, turno, numero', [$dia, $dia]) as $l) {
        $r[(int) $l['unidade']][$l['turno']][(int) $l['id']] = ['numero' => (int) $l['numero'], 'os' => (string) $l['decurio_nome'], 'srs' => 0];
    }
    foreach (escQ('SELECT unidade, turno, nucleo_id, COUNT(DISTINCT rep_code) AS n FROM ' . escT('esc_colaborador_config') . "
                   WHERE funcao = 'EQUITE' AND nucleo_id IS NOT NULL AND vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)
                   GROUP BY unidade, turno, nucleo_id", [$dia, $dia]) as $l) {
        if (isset($r[(int) $l['unidade']][$l['turno']][(int) $l['nucleo_id']])) {
            $r[(int) $l['unidade']][$l['turno']][(int) $l['nucleo_id']]['srs'] = (int) $l['n'];
        }
    }
    return $r;
}

// Trainees com cadastro em Colaboradores vigente no dia em que ficam disponíveis (o destino já foi aplicado).
function escDstAplicados(array $reps, string $dia): array {
    if (!$reps) {
        return [];
    }
    $r = [];
    foreach (escQ('SELECT UPPER(rep_code) AS rep, unidade, nucleo_id FROM ' . escT('esc_colaborador_config') . ' WHERE rep_code IN (' . escIn($reps) . ') AND (vigente_ate IS NULL OR vigente_ate >= ?)',
        array_merge($reps, [$dia])) as $l) {
        $r[$l['rep']] = ['unidade' => (int) $l['unidade'], 'nucleo_id' => (int) $l['nucleo_id']];
    }
    return $r;
}

// Sugestão de unidade e núcleo para os trainees de uma turma, no turno dela.
//   1º a unidade com mais lacunas (blocos sem ninguém) na escala da semana em que a turma fica disponível
//      (sem escala gerada para essa semana, a da semana atual); no empate, a com mais falta no Capacity
//      (precisa − SRs com cadastro hoje − novatos já designados para lá);
//   2º no núcleo da unidade com mais falta em relação à parte dele (postos ÷ 2 por núcleo, fator 1,5 ÷ 3 núcleos).
// Cada pessoa designada desconta {ESC_DST_BLOCOS_SR} blocos de lacuna e 1 da falta antes da próxima.
// Quem já tem destino confirmado entra na conta e não muda. Devolve [rep => ['unidade', 'nucleo_id', 'motivo']].
function escDstSugerir(array $turma, array $outrasDesignadas = []): array {
    $t = (string) $turma['turno'];
    $disp = (string) $turma['disponivel_em'];
    $segDisp = segundaDaSemana($disp);
    $lac = escQdLacunasUT($segDisp);
    $semanaLac = $segDisp;
    if (!$lac && !escPlanoAtual($segDisp)) {
        $semanaLac = segundaDaSemana(date('Y-m-d'));
        $lac = escQdLacunasUT($semanaLac);
    }
    $ult = escQdUltimaVersao();
    $ut = $ult ? escQdUnidadeTurno((int) $ult['versao']) : [];
    $srs = escQdSrsAtivos(date('Y-m-d'));
    $nucleos = escDstNucleos($disp);
    $estado = [];
    foreach ([1, 2, 3] as $u) {
        $estado[$u] = ['lac' => (int) ($lac[$u][$t] ?? 0), 'falta' => (int) ($ut[$u][$t]['precisa'] ?? 0) - (int) ($srs[$u][$t] ?? 0), 'postos' => (int) ($ut[$u][$t]['postos'] ?? 0)];
    }
    $designa = static function (int $u, ?int $nuc) use (&$estado, &$nucleos, $t): void {
        $estado[$u]['lac'] -= ESC_DST_BLOCOS_SR;
        $estado[$u]['falta']--;
        if ($nuc !== null && isset($nucleos[$u][$t][$nuc])) {
            $nucleos[$u][$t][$nuc]['srs']++;
        }
    };
    // Novatos já designados (desta e de outras turmas do mesmo turno) entram na conta antes.
    foreach ($outrasDesignadas as $d) {
        if (($d['turno'] ?? '') === $t && isset($estado[(int) $d['unidade']])) {
            $designa((int) $d['unidade'], $d['nucleo_id'] !== null ? (int) $d['nucleo_id'] : null);
        }
    }
    foreach ($turma['trainees'] as $tr) {
        if (($tr['situacao'] ?? 'TREINANDO') === 'TREINANDO' && !empty($tr['destino_em']) && !empty($tr['unidade_destino'])) {
            $designa((int) $tr['unidade_destino'], $tr['nucleo_destino_id'] !== null ? (int) $tr['nucleo_destino_id'] : null);
        }
    }
    $r = [];
    foreach ($turma['trainees'] as $rep => $tr) {
        if (($tr['situacao'] ?? 'TREINANDO') !== 'TREINANDO' || !empty($tr['destino_em'])) {
            continue;
        }
        $ordem = [1, 2, 3];
        usort($ordem, static fn($a, $b) => [$estado[$b]['lac'], $estado[$b]['falta']] <=> [$estado[$a]['lac'], $estado[$a]['falta']]);
        $u = $ordem[0];
        $lacAntes = max(0, $estado[$u]['lac']);
        $faltaAntes = $estado[$u]['falta'];
        $alvoNuc = $estado[$u]['postos'] / 2;
        $nuc = null;
        $melhor = null;
        foreach ($nucleos[$u][$t] ?? [] as $id => $n) {
            $def = $alvoNuc - $n['srs'];
            if ($melhor === null || $def > $melhor) {
                $melhor = $def;
                $nuc = $id;
            }
        }
        $nInfo = $nuc !== null ? $nucleos[$u][$t][$nuc] : null;
        $motivo = ($lacAntes > 0
                ? t('U{u} tem {lac} blocos sem ninguém no turno {t} na escala de {sem} e faltam {falta} no Capacity', ['u' => $u, 'lac' => $lacAntes, 't' => $t, 'sem' => escData($semanaLac), 'falta' => $faltaAntes])
                : t('U{u} é a que mais falta no turno {t} pelo Capacity ({falta})', ['u' => $u, 't' => $t, 'falta' => $faltaAntes]))
            . ($nInfo ? '; ' . t('núcleo {n} ({os}) tem {srs} SRs para {alvo}', ['n' => $nInfo['numero'], 'os' => nomeCurto($nInfo['os']) ?: '—', 'srs' => $nInfo['srs'], 'alvo' => num($alvoNuc, 0)]) : '');
        $r[$rep] = ['unidade' => $u, 'nucleo_id' => $nuc, 'motivo' => $motivo];
        $designa($u, $nuc);
    }
    return $r;
}

// Destinos confirmados e ainda não aplicados em Colaboradores, de todas as turmas em andamento: [['turno', 'unidade', 'nucleo_id']].
function escDstDesignados(array $turmas, ?int $exceto = null): array {
    $r = [];
    foreach ($turmas as $id => $tm) {
        if ($id === $exceto) {
            continue;
        }
        foreach ($tm['trainees'] as $tr) {
            if (($tr['situacao'] ?? 'TREINANDO') === 'TREINANDO' && !empty($tr['destino_em']) && !empty($tr['unidade_destino'])) {
                $r[] = ['turno' => $tm['turno'], 'unidade' => (int) $tr['unidade_destino'], 'nucleo_id' => $tr['nucleo_destino_id'] !== null ? (int) $tr['nucleo_destino_id'] : null];
            }
        }
    }
    return $r;
}

// Grava o destino (e a situação) de cada trainee da turma. $itens = [rep => ['situacao', 'unidade', 'nucleo_id', 'motivo']].
function escDstSalvar(int $turmaId, array $itens): int {
    $n = 0;
    escTx(function () use ($turmaId, $itens, &$n) {
        foreach ($itens as $rep => $x) {
            $sit = ($x['situacao'] ?? 'TREINANDO') === 'DESISTIU' ? 'DESISTIU' : 'TREINANDO';
            $u = in_array((int) ($x['unidade'] ?? 0), [1, 2, 3], true) ? (int) $x['unidade'] : null;
            $nuc = (int) ($x['nucleo_id'] ?? 0) ?: null;
            $n += escW('UPDATE ' . escT('esc_treinamento_trainee') . ' SET situacao = ?, unidade_destino = ?, nucleo_destino_id = ?, destino_motivo = ?,
                               destino_por = IF(? IS NULL, NULL, ?), destino_em = IF(? IS NULL, NULL, NOW())
                         WHERE turma_id = ? AND UPPER(rep_code) = ?',
                [$sit, $u, $nuc, $u ? mb_substr((string) ($x['motivo'] ?? ''), 0, 255) : null, $u, escUserId(), $u, $turmaId, strtoupper((string) $rep)]);
        }
        escLog('treinamento.destino', 'treinamento', $turmaId, null, $itens);
    });
    return $n;
}

// Inclui um trainee à mão na turma (quem ainda não veio no e-mail do Treinamento).
function escDstIncluir(int $turmaId, string $rep, string $nome): void {
    if (!preg_match('~^[A-Z]\d{6}$~', $rep)) {
        throw new RuntimeException(t('Código inválido (letra + 6 números).'));
    }
    if (escQ1('SELECT 1 AS ok FROM ' . escT('esc_treinamento_trainee') . ' WHERE turma_id = ? AND UPPER(rep_code) = ?', [$turmaId, $rep])) {
        throw new RuntimeException(t('{rep} já está nesta turma.', ['rep' => $rep]));
    }
    escTx(function () use ($turmaId, $rep, $nome) {
        escW('INSERT INTO ' . escT('esc_treinamento_trainee') . ' (turma_id, rep_code, nome) VALUES (?, ?, ?)', [$turmaId, $rep, $nome !== '' ? $nome : null]);
        escLog('treinamento.incluir', 'treinamento', $turmaId, null, ['rep' => $rep, 'nome' => $nome]);
    });
}
