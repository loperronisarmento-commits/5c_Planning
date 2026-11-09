<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/restricoes_om_gestao.php
// Gestão das Restrições dos OM (pedido de 07/11): editar, apagar, ativar e desativar, uma por uma ou em lote,
// na tela Páginas › Restrições dos OM.
//   Desativar: tira a restrição da fonte (o Gerar e o documento deixam de ver) e guarda o que foi tirado em
//              esc_restricao_om_inativa (ES_129). Ativar devolve para a fonte, como estava.
//   Apagar:    tira da fonte (ou da lista das desativadas) sem guardar; o log guarda o que era (escLog).
//   Editar:    papel e datas da equipe fixa e do só assistente/proibido, partes da divisão, páginas ou
//              faixa dos parâmetros por pessoa e o "até" do solo.
// O motor não muda: ele continua lendo as mesmas fontes (lib/restricoes_om.php, lib/motor_v4_carga.php).
// Cada restrição tem uma chave:
//   prio|<id>|<REP>        equipe fixa (esc_pagina_prioridade; uma linha pode ter várias pessoas)
//   assist|<id>            só assistente ou proibido (esc_pagina_assistente)
//   div|<REP>              divisão entre páginas (esc_sr_divisao, todas as linhas da pessoa)
//   param|<chave>|<REP>    parâmetro por pessoa (v4_sr_*, v4_todos_os_dias; v4_nao_subordinado usa <REP>:<LÍDER>)
//   podeassist|<REP>       não faz assistente (esc_colaborador_config.pode_assistente = 0)
//   ineg|<REP>             inegociável na unidade (observação do cadastro)
// ===============================================

// Parâmetros com lista de códigos (separados por vírgula; o solo aceita "CÓDIGO:AAAA-MM-DD").
const ESC_OM_LISTA = ['v4_sr_uma_pagina', 'v4_sr_so_assistente', 'v4_sr_baixo_fluxo', 'v4_sr_baixo_fluxo_estrito', 'v4_sr_pref_assistente',
    'v4_sr_tier_medio', 'v4_sr_pref_masculinas', 'v4_sr_tier_medio_alto', 'v4_sr_solo', 'v4_todos_os_dias'];
// Parâmetros "CÓDIGO:valor" separados por ponto e vírgula.
const ESC_OM_MAPA = ['v4_sr_so_paginas', 'v4_sr_multipagina', 'v4_sr_faixa_receita', 'v4_sr_pref_paginas'];

function escOmGestaoInstalada(): bool {
    static $ok = null;
    if ($ok === null) {
        $ok = (bool) escQ1('SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = \'esc_restricao_om_inativa\'', [ESC_SCHEMA]);
    }
    return $ok;
}

function escOmRotulo(string $ch): string {
    $r = [
        'v4_sr_uma_pagina' => t('só uma página (não acumula nem cobre)'),
        'v4_sr_so_assistente' => t('só entra como assistente'),
        'v4_sr_baixo_fluxo' => t('só página de baixo fluxo ou de assistente'),
        'v4_sr_baixo_fluxo_estrito' => t('nunca acima do baixo fluxo, nem de assistente'),
        'v4_sr_pref_assistente' => t('serve como assistente'),
        'v4_sr_tier_medio' => t('só tier médio'),
        'v4_sr_pref_masculinas' => t('preferência em páginas masculinas'),
        'v4_sr_tier_medio_alto' => t('só tier médio ou alto'),
        'v4_sr_solo' => t('trabalha solo'),
        'v4_todos_os_dias' => t('todos os dias, fora da rotação'),
        'v4_sr_so_paginas' => t('só nestas páginas'),
        'v4_sr_multipagina' => t('várias páginas ao mesmo tempo'),
        'v4_sr_faixa_receita' => t('faixa de faturamento'),
        'v4_sr_pref_paginas' => t('preferência nas páginas'),
        'v4_nao_subordinado' => t('não pode ficar sob o líder'),
        'prio' => t('equipe fixa'), 'MAIN' => t('equipe fixa · main'), 'COVER' => t('equipe fixa · cover'),
        'ASSISTENTE' => t('só assistente na página'), 'FORA' => t('proibido na página'),
        'div' => t('divisão entre páginas'), 'podeassist' => t('não faz assistente'), 'ineg' => t('inegociável na unidade'),
    ];
    return $r[$ch] ?? $ch;
}

// Unidade de cada pessoa (cadastro de hoje; sem ele, o próximo) e de cada página (carteira).
function escOmUnidades(string $hoje): array {
    $rep = [];
    foreach (escQ('SELECT UPPER(rep_code) AS rep, unidade, vigente_desde FROM ' . escT('esc_colaborador_config') . ' WHERE vigente_ate IS NULL OR vigente_ate >= ? ORDER BY vigente_desde', [$hoje]) as $l) {
        if (!isset($rep[$l['rep']]) || substr((string) $l['vigente_desde'], 0, 10) <= $hoje) {
            $rep[$l['rep']] = (int) $l['unidade'];
        }
    }
    $pag = [];
    foreach (escQ('SELECT pagina, carteira FROM `5c_planning`.`fc_pagina_carteira` WHERE vigente_ate IS NULL') as $l) {
        $pag[escOmChavePag((string) $l['pagina'])] = preg_match('~^U(\d)~', (string) $l['carteira'], $m) ? (int) $m[1] : 0;
    }
    return [$rep, $pag];
}

function escOmChavePag(string $p): string {
    return mb_strtolower(trim((string) preg_replace('~\s+~u', ' ', $p)), 'UTF-8');
}

function escOmPeriodo(string $de, ?string $ate, string $hoje): string {
    $x = [];
    if ($de > $hoje) {
        $x[] = t('de {d}', ['d' => dataBr($de)]);
    }
    if ($ate) {
        $x[] = t('até {d}', ['d' => dataBr($ate)]);
    }
    return $x ? ' (' . implode(' ', $x) . ')' : '';
}

// Todas as restrições ativas, uma por linha: chave, fonte, tipo, rep, pagina, texto, unidade e o que dá para editar.
function escOmItens(string $hoje): array {
    [$uRep, $uPag] = escOmUnidades($hoje);
    $nomes = escNomes();
    $nm = static fn(string $r): string => nomeCurto($nomes[$r] ?? $r);
    $unid = static fn(string $rep, string $pagina = '') => ($pagina !== '' ? ($uPag[escOmChavePag($pagina)] ?? 0) : 0) ?: ($uRep[$rep] ?? 0);
    $itens = [];
    foreach (escQ('SELECT id, pagina, rep_codes, papel, vigente_desde, vigente_ate FROM ' . escT('esc_pagina_prioridade') . ' WHERE vigente_ate IS NULL OR vigente_ate >= ? ORDER BY pagina, vigente_desde', [$hoje]) as $l) {
        $de = substr((string) $l['vigente_desde'], 0, 10);
        $ate = $l['vigente_ate'] !== null ? substr((string) $l['vigente_ate'], 0, 10) : null;
        foreach (array_filter(array_map(static fn($r) => strtoupper(trim($r)), explode(',', (string) $l['rep_codes']))) as $rep) {
            $papel = (string) ($l['papel'] ?: 'EQUIPE');
            $itens[] = ['chave' => 'prio|' . $l['id'] . '|' . $rep, 'fonte' => 'prio', 'rep' => $rep, 'pagina' => $l['pagina'], 'unidade' => $unid($rep, $l['pagina']),
                'tipo' => escOmRotulo($papel === 'EQUIPE' ? 'prio' : $papel), 'texto' => $l['pagina'] . escOmPeriodo($de, $ate, $hoje),
                'editar' => ['papel' => $papel, 'de' => $de, 'ate' => $ate ?? '']];
        }
    }
    foreach (escQ('SELECT id, pagina, UPPER(rep_code) AS rep, papel, vigente_desde, vigente_ate FROM ' . escT('esc_pagina_assistente') . ' WHERE vigente_ate IS NULL OR vigente_ate >= ? ORDER BY pagina', [$hoje]) as $l) {
        $de = substr((string) $l['vigente_desde'], 0, 10);
        $ate = $l['vigente_ate'] !== null ? substr((string) $l['vigente_ate'], 0, 10) : null;
        $itens[] = ['chave' => 'assist|' . $l['id'], 'fonte' => 'assist', 'rep' => $l['rep'], 'pagina' => $l['pagina'], 'unidade' => $unid($l['rep'], $l['pagina']),
            'tipo' => escOmRotulo((string) $l['papel']), 'texto' => $l['pagina'] . escOmPeriodo($de, $ate, $hoje),
            'editar' => ['papel_assist' => $l['papel'], 'de' => $de, 'ate' => $ate ?? '']];
    }
    $div = [];
    foreach (escQ('SELECT id, UPPER(rep_code) AS rep, pagina, parte FROM ' . escT('esc_sr_divisao') . ' WHERE vigente_ate IS NULL OR vigente_ate >= ? ORDER BY rep_code, pagina', [$hoje]) as $l) {
        $div[$l['rep']][] = $l;
    }
    foreach ($div as $rep => $ls) {
        $itens[] = ['chave' => 'div|' . $rep, 'fonte' => 'div', 'rep' => $rep, 'pagina' => implode(', ', array_column($ls, 'pagina')), 'unidade' => $unid($rep, (string) $ls[0]['pagina']),
            'tipo' => escOmRotulo('div'), 'texto' => implode(', ', array_map(static fn($l) => round((float) $l['parte'] * 100) . '% ' . $l['pagina'], $ls)),
            'editar' => ['partes' => array_combine(array_column($ls, 'id'), array_map(static fn($l) => [$l['pagina'], round((float) $l['parte'] * 100)], $ls))]];
    }
    $par = [];
    foreach (escQ('SELECT chave, valor FROM ' . escT('esc_parametro') . ' WHERE chave IN (' . implode(',', array_fill(0, count(ESC_OM_LISTA) + count(ESC_OM_MAPA) + 1, '?')) . ')',
        array_merge(ESC_OM_LISTA, ESC_OM_MAPA, ['v4_nao_subordinado'])) as $l) {
        $par[$l['chave']] = (string) $l['valor'];
    }
    foreach (ESC_OM_LISTA as $ch) {
        foreach (array_filter(array_map('trim', explode(',', $par[$ch] ?? ''))) as $item) {
            [$rep, $ate] = array_pad(explode(':', $item, 2), 2, '');
            $rep = strtoupper(trim($rep));
            $ate = preg_match('~^\d{4}-\d{2}-\d{2}$~', trim($ate)) ? trim($ate) : '';
            $itens[] = ['chave' => 'param|' . $ch . '|' . $rep, 'fonte' => 'param', 'rep' => $rep, 'pagina' => '', 'unidade' => $unid($rep),
                'tipo' => escOmRotulo($ch), 'texto' => $ate !== '' ? t('até {d}', ['d' => dataBr($ate)]) . ($ate < $hoje ? ' · ' . t('já venceu') : '') : '',
                'editar' => $ch === 'v4_sr_solo' ? ['ate_solo' => $ate] : []];
        }
    }
    foreach (ESC_OM_MAPA as $ch) {
        foreach (array_filter(array_map('trim', explode(';', $par[$ch] ?? ''))) as $item) {
            $x = explode(':', $item, 2);
            if (count($x) !== 2 || trim($x[0]) === '') {
                continue;
            }
            $rep = strtoupper(trim($x[0]));
            $val = trim($x[1]);
            $pags = $ch === 'v4_sr_faixa_receita' ? '' : implode(', ', array_filter(array_map('trim', explode('|', $val)), 'strlen'));
            $itens[] = ['chave' => 'param|' . $ch . '|' . $rep, 'fonte' => 'param', 'rep' => $rep, 'pagina' => $pags, 'unidade' => $unid($rep),
                'tipo' => escOmRotulo($ch), 'texto' => $ch === 'v4_sr_faixa_receita' ? str_replace('-', ' – ', $val) : $pags,
                'editar' => [$ch === 'v4_sr_faixa_receita' ? 'faixa' : 'paginas' => $ch === 'v4_sr_faixa_receita' ? $val : $pags]];
        }
    }
    foreach (array_filter(array_map('trim', explode(',', $par['v4_nao_subordinado'] ?? ''))) as $item) {
        $x = array_map(static fn($v) => strtoupper(trim($v)), explode(':', $item, 2));
        if (count($x) === 2 && $x[0] !== '') {
            $itens[] = ['chave' => 'param|v4_nao_subordinado|' . $x[0] . ':' . $x[1], 'fonte' => 'param', 'rep' => $x[0], 'pagina' => '', 'unidade' => $unid($x[0]),
                'tipo' => escOmRotulo('v4_nao_subordinado'), 'texto' => $nm($x[1]) . ' (' . $x[1] . ')', 'editar' => []];
        }
    }
    $vistos = [];
    foreach (escQ('SELECT UPPER(rep_code) AS rep, pode_assistente, observacao FROM ' . escT('esc_colaborador_config') . ' WHERE (vigente_ate IS NULL OR vigente_ate >= ?) AND vigente_desde <= ?', [$hoje, $hoje]) as $l) {
        if ((int) ($l['pode_assistente'] ?? 1) === 0 && !isset($vistos['podeassist' . $l['rep']])) {
            $vistos['podeassist' . $l['rep']] = true;
            $itens[] = ['chave' => 'podeassist|' . $l['rep'], 'fonte' => 'podeassist', 'rep' => $l['rep'], 'pagina' => '', 'unidade' => $unid($l['rep']),
                'tipo' => escOmRotulo('podeassist'), 'texto' => '', 'editar' => []];
        }
        if (preg_match('~Inegociável na U(\d)~u', (string) $l['observacao'], $m) && !isset($vistos['ineg' . $l['rep']])) {
            $vistos['ineg' . $l['rep']] = true;
            $itens[] = ['chave' => 'ineg|' . $l['rep'], 'fonte' => 'ineg', 'rep' => $l['rep'], 'pagina' => '', 'unidade' => $unid($l['rep']),
                'tipo' => escOmRotulo('ineg'), 'texto' => 'U' . $m[1], 'editar' => []];
        }
    }
    foreach ($itens as &$i) {
        $i['nome'] = $nm($i['rep']);
    }
    unset($i);
    usort($itens, static fn($a, $b) => [$a['unidade'] ?: 9, $a['nome'], $a['tipo'], $a['texto']] <=> [$b['unidade'] ?: 9, $b['nome'], $b['tipo'], $b['texto']]);
    return $itens;
}

function escOmInativas(): array {
    if (!escOmGestaoInstalada()) {
        return [];
    }
    $nomes = escNomes();
    $out = escQ('SELECT id, chave, fonte, rep_code, pagina, unidade, tipo, texto, desativada_em, desativada_por FROM ' . escT('esc_restricao_om_inativa') . ' ORDER BY unidade, rep_code, tipo');
    foreach ($out as &$l) {
        $l['nome'] = nomeCurto($nomes[(string) $l['rep_code']] ?? (string) $l['rep_code']);
    }
    unset($l);
    return $out;
}

// ---------- tirar e devolver ----------

function escOmLinhasPar(string $valor, string $sep): array {
    return array_values(array_filter(array_map('trim', explode($sep, $valor)), 'strlen'));
}

function escOmParGravar(string $ch, string $valor): void {
    if (escQW('SELECT chave FROM ' . escT('esc_parametro') . ' WHERE chave = ?', [$ch])) {
        escW('UPDATE ' . escT('esc_parametro') . ' SET valor = ?, atualizado_por = ? WHERE chave = ?', [$valor, escUserId() ?: null, $ch]);
    } else {
        escW('INSERT INTO ' . escT('esc_parametro') . ' (chave, valor, tipo, grupo, descricao, atualizado_por) VALUES (?, ?, \'TEXTO\', \'v4\', ?, ?)',
            [$ch, $valor, 'Restrição dos OM (criado pela tela, 07/11)', escUserId() ?: null]);
    }
}

// Tira a restrição da fonte e devolve o que foi tirado (para guardar ou para o log).
function escOmTirar(string $chave, string $hoje): array {
    $x = explode('|', $chave, 3);
    $fonte = $x[0];
    $sumiu = static fn() => new RuntimeException(t('A restrição já não existe (a tela estava desatualizada). Abra de novo e confira.'));
    if ($fonte === 'param') {
        [, $ch, $cod] = array_pad($x, 3, '');
        if (!in_array($ch, array_merge(ESC_OM_LISTA, ESC_OM_MAPA, ['v4_nao_subordinado']), true)) {
            throw new RuntimeException(t('Restrição inválida.'));
        }
        $valor = (string) (escQW('SELECT valor FROM ' . escT('esc_parametro') . ' WHERE chave = ? FOR UPDATE', [$ch])[0]['valor'] ?? '');
        $sep = in_array($ch, ESC_OM_MAPA, true) ? ';' : ',';
        $fica = [];
        $sai = [];
        foreach (escOmLinhasPar($valor, $sep) as $item) {
            $k = $ch === 'v4_nao_subordinado' ? strtoupper(str_replace(' ', '', $item)) : strtoupper(trim(explode(':', $item, 2)[0]));
            if ($k === $cod) {
                $sai[] = $item;
            } else {
                $fica[] = $item;
            }
        }
        if (!$sai) {
            throw $sumiu();
        }
        escOmParGravar($ch, implode($sep, $fica));
        return ['fonte' => 'param', 'chave' => $ch, 'itens' => $sai];
    }
    if ($fonte === 'prio') {
        [, $id, $rep] = array_pad($x, 3, '');
        $l = escQW('SELECT id, pagina, rep_codes, papel, motivo, vigente_desde, vigente_ate FROM ' . escT('esc_pagina_prioridade') . ' WHERE id = ? FOR UPDATE', [(int) $id])[0] ?? null;
        $reps = $l ? array_values(array_filter(array_map(static fn($r) => strtoupper(trim($r)), explode(',', (string) $l['rep_codes'])))) : [];
        if (!in_array($rep, $reps, true)) {
            throw $sumiu();
        }
        $fica = array_values(array_diff($reps, [$rep]));
        if ($fica) {
            escW('UPDATE ' . escT('esc_pagina_prioridade') . ' SET rep_codes = ? WHERE id = ?', [implode(',', $fica), (int) $id]);
        } else {
            escW('DELETE FROM ' . escT('esc_pagina_prioridade') . ' WHERE id = ?', [(int) $id]);
        }
        unset($l['id']);
        $l['rep_codes'] = $rep;
        return ['fonte' => 'prio', 'linhas' => [$l]];
    }
    if ($fonte === 'assist') {
        $l = escQW('SELECT id, pagina, rep_code, papel, motivo, vigente_desde, vigente_ate FROM ' . escT('esc_pagina_assistente') . ' WHERE id = ?', [(int) ($x[1] ?? 0)])[0] ?? null;
        if (!$l) {
            throw $sumiu();
        }
        escW('DELETE FROM ' . escT('esc_pagina_assistente') . ' WHERE id = ?', [(int) $l['id']]);
        unset($l['id']);
        return ['fonte' => 'assist', 'linhas' => [$l]];
    }
    if ($fonte === 'div') {
        $ls = escQW('SELECT id, rep_code, pagina, parte, vigente_desde, vigente_ate, motivo FROM ' . escT('esc_sr_divisao') . ' WHERE UPPER(rep_code) = ? AND (vigente_ate IS NULL OR vigente_ate >= ?)', [$x[1] ?? '', $hoje]);
        if (!$ls) {
            throw $sumiu();
        }
        foreach ($ls as &$l) {
            escW('DELETE FROM ' . escT('esc_sr_divisao') . ' WHERE id = ?', [(int) $l['id']]);
            unset($l['id']);
        }
        unset($l);
        return ['fonte' => 'div', 'linhas' => $ls];
    }
    if ($fonte === 'podeassist' || $fonte === 'ineg') {
        $rep = $x[1] ?? '';
        $ls = escQW('SELECT id, pode_assistente, observacao FROM ' . escT('esc_colaborador_config') . ' WHERE UPPER(rep_code) = ? AND (vigente_ate IS NULL OR vigente_ate >= ?)', [$rep, $hoje]);
        $mud = [];
        foreach ($ls as $l) {
            if ($fonte === 'podeassist' && (int) $l['pode_assistente'] === 0) {
                escW('UPDATE ' . escT('esc_colaborador_config') . ' SET pode_assistente = 1 WHERE id = ?', [(int) $l['id']]);
                $mud[(int) $l['id']] = 0;
            }
            if ($fonte === 'ineg' && preg_match('~Inegociável na U\d~u', (string) $l['observacao'])) {
                $nova = trim((string) preg_replace('~Inegociável na U\d[^;]*;?\s*~u', '', (string) $l['observacao']));
                escW('UPDATE ' . escT('esc_colaborador_config') . ' SET observacao = ? WHERE id = ?', [$nova === '' ? null : $nova, (int) $l['id']]);
                $mud[(int) $l['id']] = (string) $l['observacao'];
            }
        }
        if (!$mud) {
            throw $sumiu();
        }
        return ['fonte' => $fonte, 'ids' => $mud];
    }
    throw new RuntimeException(t('Restrição inválida.'));
}

const ESC_OM_TABELAS = ['prio' => ['esc_pagina_prioridade', ['pagina', 'rep_codes', 'papel', 'motivo', 'vigente_desde', 'vigente_ate']],
    'assist' => ['esc_pagina_assistente', ['pagina', 'rep_code', 'papel', 'motivo', 'vigente_desde', 'vigente_ate']],
    'div' => ['esc_sr_divisao', ['rep_code', 'pagina', 'parte', 'vigente_desde', 'vigente_ate', 'motivo']]];

function escOmDevolver(array $g): void {
    if ($g['fonte'] === 'param') {
        $ch = (string) $g['chave'];
        $sep = in_array($ch, ESC_OM_MAPA, true) ? ';' : ',';
        $valor = (string) (escQW('SELECT valor FROM ' . escT('esc_parametro') . ' WHERE chave = ? FOR UPDATE', [$ch])[0]['valor'] ?? '');
        $itens = escOmLinhasPar($valor, $sep);
        foreach ($g['itens'] as $item) {
            if (!in_array($item, $itens, true)) {
                $itens[] = $item;
            }
        }
        escOmParGravar($ch, implode($sep, $itens));
        return;
    }
    if (isset(ESC_OM_TABELAS[$g['fonte']])) {
        [$tab, $cols] = ESC_OM_TABELAS[$g['fonte']];
        foreach ($g['linhas'] as $l) {
            escW('INSERT INTO ' . escT($tab) . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')',
                array_map(static fn($c) => $l[$c] ?? null, $cols));
        }
        return;
    }
    foreach ($g['ids'] as $id => $antes) {
        if ($g['fonte'] === 'podeassist') {
            escW('UPDATE ' . escT('esc_colaborador_config') . ' SET pode_assistente = 0 WHERE id = ?', [(int) $id]);
        } else {
            escW('UPDATE ' . escT('esc_colaborador_config') . ' SET observacao = ? WHERE id = ?', [$antes, (int) $id]);
        }
    }
}

// ---------- ações da tela ----------

function escOmPorChave(array $chaves, string $hoje): array {
    $todos = [];
    foreach (escOmItens($hoje) as $i) {
        $todos[$i['chave']] = $i;
    }
    $out = [];
    foreach ($chaves as $c) {
        if (!isset($todos[$c])) {
            throw new RuntimeException(t('A restrição já não existe (a tela estava desatualizada). Abra de novo e confira.'));
        }
        $out[] = $todos[$c];
    }
    return $out;
}

function escOmDesativar(array $chaves, string $hoje): int {
    if (!escOmGestaoInstalada()) {
        throw new RuntimeException(t('Falta instalar a lista das restrições desativadas (script ES_129).'));
    }
    $itens = escOmPorChave($chaves, $hoje);
    return escTx(static function () use ($itens, $hoje) {
        foreach ($itens as $i) {
            $g = escOmTirar($i['chave'], $hoje);
            escW('INSERT INTO ' . escT('esc_restricao_om_inativa') . ' (chave, fonte, rep_code, pagina, unidade, tipo, texto, payload, desativada_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$i['chave'], $i['fonte'], $i['rep'], mb_substr((string) $i['pagina'], 0, 255), $i['unidade'] ?: null, mb_substr($i['tipo'], 0, 120), mb_substr((string) $i['texto'], 0, 500),
                    json_encode($g, JSON_UNESCAPED_UNICODE), escUserId() ?: null]);
            escLog('restricao.desativar', 'esc_restricao_om_inativa', $i['chave'], $g, null);
        }
        return count($itens);
    });
}

function escOmAtivar(array $ids): int {
    if (!escOmGestaoInstalada()) {
        throw new RuntimeException(t('Falta instalar a lista das restrições desativadas (script ES_129).'));
    }
    return escTx(static function () use ($ids) {
        $n = 0;
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $l = escQW('SELECT id, chave, payload FROM ' . escT('esc_restricao_om_inativa') . ' WHERE id = ?', [$id])[0] ?? null;
            if (!$l) {
                continue;
            }
            $g = json_decode((string) $l['payload'], true);
            if (!is_array($g) || empty($g['fonte'])) {
                throw new RuntimeException(t('Não deu para ler a restrição guardada {c}.', ['c' => $l['chave']]));
            }
            escOmDevolver($g);
            escW('DELETE FROM ' . escT('esc_restricao_om_inativa') . ' WHERE id = ?', [$id]);
            escLog('restricao.ativar', 'esc_restricao_om_inativa', $l['chave'], null, $g);
            $n++;
        }
        return $n;
    });
}

function escOmApagar(array $chaves, array $ids, string $hoje): int {
    $itens = escOmPorChave($chaves, $hoje);
    return escTx(static function () use ($itens, $ids, $hoje) {
        $n = 0;
        foreach ($itens as $i) {
            $g = escOmTirar($i['chave'], $hoje);
            escLog('restricao.apagar', 'restricao_om', $i['chave'], $g, null);
            $n++;
        }
        if ($ids && escOmGestaoInstalada()) {
            foreach (array_unique(array_map('intval', $ids)) as $id) {
                $l = escQW('SELECT chave, payload FROM ' . escT('esc_restricao_om_inativa') . ' WHERE id = ?', [$id])[0] ?? null;
                if ($l) {
                    escW('DELETE FROM ' . escT('esc_restricao_om_inativa') . ' WHERE id = ?', [$id]);
                    escLog('restricao.apagar', 'esc_restricao_om_inativa', $l['chave'], json_decode((string) $l['payload'], true), null);
                    $n++;
                }
            }
        }
        return $n;
    });
}

function escOmData(string $v, bool $obrigatoria): ?string {
    $v = trim($v);
    if ($v === '' && !$obrigatoria) {
        return null;
    }
    if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $v)) {
        throw new RuntimeException(t('Data inválida: {d}.', ['d' => $v === '' ? '—' : $v]));
    }
    return $v;
}

// $c: os campos do formulário de edição daquela restrição.
function escOmEditar(string $chave, array $c, string $hoje): string {
    $i = escOmPorChave([$chave], $hoje)[0];
    return escTx(static function () use ($i, $c, $hoje) {
        $x = explode('|', $i['chave'], 3);
        if ($i['fonte'] === 'prio') {
            $papel = (string) ($c['papel'] ?? 'EQUIPE');
            if (!in_array($papel, ['EQUIPE', 'MAIN', 'COVER'], true)) {
                throw new RuntimeException(t('Papel inválido.'));
            }
            $de = escOmData((string) ($c['de'] ?? ''), true);
            $ate = escOmData((string) ($c['ate'] ?? ''), false);
            if ($ate !== null && $ate < $de) {
                throw new RuntimeException(t('O fim não pode ser antes do início.'));
            }
            $l = escQW('SELECT rep_codes, motivo FROM ' . escT('esc_pagina_prioridade') . ' WHERE id = ? FOR UPDATE', [(int) $x[1]])[0] ?? null;
            if (!$l) {
                throw new RuntimeException(t('Essa restrição não existe mais. Recarregue a tela.'));
            }
            $reps = array_values(array_filter(array_map(static fn($r) => strtoupper(trim($r)), explode(',', (string) $l['rep_codes']))));
            if (count($reps) === 1) {
                escW('UPDATE ' . escT('esc_pagina_prioridade') . ' SET papel = ?, vigente_desde = ?, vigente_ate = ? WHERE id = ?', [$papel, $de, $ate, (int) $x[1]]);
            } else {
                // A linha tem outras pessoas: esta sai dela e ganha uma linha só dela, com o que foi editado.
                escW('UPDATE ' . escT('esc_pagina_prioridade') . ' SET rep_codes = ? WHERE id = ?', [implode(',', array_diff($reps, [$i['rep']])), (int) $x[1]]);
                escW('INSERT INTO ' . escT('esc_pagina_prioridade') . ' (pagina, rep_codes, papel, motivo, vigente_desde, vigente_ate) VALUES (?, ?, ?, ?, ?, ?)',
                    [$i['pagina'], $i['rep'], $papel, $l['motivo'], $de, $ate]);
            }
            $depois = ['papel' => $papel, 'de' => $de, 'ate' => $ate];
        } elseif ($i['fonte'] === 'assist') {
            $papel = (string) ($c['papel_assist'] ?? '');
            if (!in_array($papel, ['ASSISTENTE', 'FORA'], true)) {
                throw new RuntimeException(t('Papel inválido.'));
            }
            $de = escOmData((string) ($c['de'] ?? ''), true);
            $ate = escOmData((string) ($c['ate'] ?? ''), false);
            if ($ate !== null && $ate < $de) {
                throw new RuntimeException(t('O fim não pode ser antes do início.'));
            }
            escW('UPDATE ' . escT('esc_pagina_assistente') . ' SET papel = ?, vigente_desde = ?, vigente_ate = ? WHERE id = ?', [$papel, $de, $ate, (int) $x[1]]);
            $depois = ['papel' => $papel, 'de' => $de, 'ate' => $ate];
        } elseif ($i['fonte'] === 'div') {
            $depois = [];
            foreach ($i['editar']['partes'] as $id => [$pg, $p]) {
                $novo = (float) str_replace(',', '.', (string) ($c['parte'][$id] ?? $p));
                if ($novo <= 0 || $novo > 100) {
                    throw new RuntimeException(t('A parte de cada página vai de 1 a 100%.'));
                }
                escW('UPDATE ' . escT('esc_sr_divisao') . ' SET parte = ? WHERE id = ?', [round($novo / 100, 3), (int) $id]);
                $depois[$pg] = $novo;
            }
        } elseif ($i['fonte'] === 'param' && $x[1] === 'v4_sr_solo') {
            $ate = escOmData((string) ($c['ate_solo'] ?? ''), false);
            $valor = (string) (escQW('SELECT valor FROM ' . escT('esc_parametro') . ' WHERE chave = ? FOR UPDATE', ['v4_sr_solo'])[0]['valor'] ?? '');
            $novo = array_map(static fn($it) => strtoupper(trim(explode(':', $it, 2)[0])) === $i['rep'] ? $i['rep'] . ($ate ? ':' . $ate : '') : $it, escOmLinhasPar($valor, ','));
            escOmParGravar('v4_sr_solo', implode(',', $novo));
            $depois = ['ate' => $ate];
        } elseif ($i['fonte'] === 'param' && in_array($x[1], ESC_OM_MAPA, true)) {
            if ($x[1] === 'v4_sr_faixa_receita') {
                $v = str_replace([' ', '.'], '', (string) ($c['faixa'] ?? ''));
                if (!preg_match('~^(\d+)-(\d+)$~', $v, $m) || (int) $m[1] > (int) $m[2]) {
                    throw new RuntimeException(t('Faixa inválida: use mínimo-máximo em US$, como 40000-60000.'));
                }
            } else {
                $pags = array_values(array_filter(array_map('trim', preg_split('~[,|;]~', (string) ($c['paginas'] ?? ''))), 'strlen'));
                if (!$pags) {
                    throw new RuntimeException(t('Informe ao menos uma página.'));
                }
                $v = implode('|', $pags);
            }
            $valor = (string) (escQW('SELECT valor FROM ' . escT('esc_parametro') . ' WHERE chave = ? FOR UPDATE', [$x[1]])[0]['valor'] ?? '');
            $novo = array_map(static fn($it) => strtoupper(trim(explode(':', $it, 2)[0])) === $i['rep'] ? $i['rep'] . ':' . $v : $it, escOmLinhasPar($valor, ';'));
            escOmParGravar($x[1], implode(';', $novo));
            $depois = ['valor' => $v];
        } else {
            throw new RuntimeException(t('Esta restrição não tem o que editar: dá para desativar ou apagar.'));
        }
        escLog('restricao.editar', 'restricao_om', $i['chave'], $i['editar'], $depois);
        return $i['nome'] . ' · ' . $i['tipo'];
    });
}
