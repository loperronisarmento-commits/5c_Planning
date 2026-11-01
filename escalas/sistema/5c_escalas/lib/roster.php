<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/roster.php
// Roster da liderança (pages/roster.php): troca de OM, OC e OS e entrada/saída de OA
// a partir de uma data. OM, OC e OA ficam em esc_lideranca (fecha a linha na véspera
// e abre outra); o OS é o decurio do núcleo em esc_nucleo (troca no lugar, porque o
// núcleo é referência de configurações e postos) mais a configuração da pessoa
// (funcao DECURIO). Quem sai pode ficar com o lugar de quem entrou (núcleo e vagas
// fixas), virar SR no mesmo núcleo ou ficar sem núcleo (acerta depois em Colaboradores).
// Tudo roda dentro de escTx.
// ===============================================

const ESC_ROSTER_NIVEIS_SR = [0, 1, 2, 3, 4, 5];

// Cargo de liderança da pessoa no dia ("OC U1 · T2/T3", "OS U2 · T6/T1 · núcleo 3"...), ou null.
function escRosterCargo(string $rep, string $dia): ?string {
    $rep = strtoupper($rep);
    $vig = ' vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)';
    $l = escQW('SELECT nivel, unidade, turno FROM ' . escT('esc_lideranca') . ' WHERE UPPER(rep_code) = ? AND' . $vig . ' LIMIT 1', [$rep, $dia, $dia]);
    if ($l) {
        return $l[0]['nivel'] . ($l[0]['unidade'] ? ' U' . $l[0]['unidade'] : '') . ($l[0]['turno'] ? ' · ' . (ESC_SOL_TURNO_BLOCOS[$l[0]['turno']] ?? $l[0]['turno']) : '');
    }
    $n = escQW('SELECT unidade, turno, numero FROM ' . escT('esc_nucleo') . ' WHERE UPPER(decurio_code) = ? AND' . $vig . ' LIMIT 1', [$rep, $dia, $dia]);
    return $n ? 'OS U' . $n[0]['unidade'] . ' · ' . (ESC_SOL_TURNO_BLOCOS[$n[0]['turno']] ?? $n[0]['turno']) . ' · ' . t('núcleo {n}', ['n' => $n[0]['numero']]) : null;
}

// Configuração que vale em $desde (trava a linha). Configuração começando depois de $desde é
// cancelada: vale a mudança de agora (escConfigCancelarFuturas, lib/equipes.php).
function escRosterConfig(string $rep, string $desde): ?array {
    $tab = escT('esc_colaborador_config');
    escConfigCancelarFuturas($rep, $desde);
    $c = escQW('SELECT * FROM ' . $tab . ' WHERE UPPER(rep_code) = ? AND vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?) ORDER BY vigente_desde DESC LIMIT 1 FOR UPDATE', [strtoupper($rep), $desde, $desde]);
    return $c[0] ?? null;
}

// Encerra a configuração na véspera de $desde (a que começa em $desde é apagada: nunca valeu).
function escRosterFecharConfig(array $c, string $desde): void {
    unset($GLOBALS['ESC_OS_DO_DIA']);
    if (substr((string) $c['vigente_desde'], 0, 10) >= $desde) {
        escW('DELETE FROM ' . escT('esc_colaborador_config') . ' WHERE id = ?', [(int) $c['id']]);
    } else {
        escW('UPDATE ' . escT('esc_colaborador_config') . ' SET vigente_ate = ?, atualizado_por = ? WHERE id = ?', [escMaisDias($desde, -1), escUserId(), (int) $c['id']]);
    }
}

// Põe a pessoa num núcleo a partir de $desde: fecha a configuração que ela tem e abre outra
// copiando $modelo (modelo de escala, tipo...) com o núcleo, a função e o nível dados.
function escRosterNovaConfig(string $rep, array $modelo, int $nucleoId, string $funcao, ?int $nivel, string $desde, string $obs): void {
    unset($GLOBALS['ESC_OS_DO_DIA']);
    $atual = escRosterConfig($rep, $desde);
    if ($atual) {
        escRosterFecharConfig($atual, $desde);
    }
    $n = escQW('SELECT id, unidade, turno FROM ' . escT('esc_nucleo') . ' WHERE id = ?', [$nucleoId])[0];
    $fora = ['id', 'rep_code', 'vigente_desde', 'vigente_ate', 'criado_em', 'criado_por', 'atualizado_em', 'atualizado_por', 'origem', 'nucleo_id', 'unidade', 'turno', 'funcao', 'nivel', 'tipo', 'observacao', 'fatia_regular'];
    $cols = array_values(array_diff(array_keys($modelo), $fora));
    $valores = array_map(static fn($k) => $modelo[$k], $cols);
    $cols = array_merge(['rep_code'], $cols, ['nucleo_id', 'unidade', 'turno', 'funcao', 'nivel', 'tipo', 'observacao', 'vigente_desde', 'origem', 'criado_por']);
    $valores = array_merge([strtoupper($rep)], $valores, [$nucleoId, (int) $n['unidade'], $n['turno'], $funcao, $nivel, 'NUCLEO', mb_substr($obs, 0, 255), $desde, 'TELA', escUserId()]);
    escW('INSERT INTO ' . escT('esc_colaborador_config') . ' (' . implode(', ', array_map(static fn($k) => '`' . str_replace('`', '', $k) . '`', $cols)) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')', $valores);
}

// Tira a pessoa do núcleo a partir de $desde (vira liderança fora do núcleo, ou sai).
function escRosterTirarDoNucleo(string $rep, string $desde): ?array {
    $c = escRosterConfig($rep, $desde);
    if ($c && $c['nucleo_id']) {
        escRosterFecharConfig($c, $desde);
    }
    return $c;
}

// Vagas fixas de $de a partir de $desde: passam para $para, ou fecham (o motor cobre).
// Devolve quantas. Grava com "#" e tira no fim, para não colidir na chave única.
function escRosterPassarVagas(string $de, ?string $para, string $desde, string $motivo): int {
    $tab = escT('esc_vaga_equipe');
    $vagas = escQW('SELECT * FROM ' . $tab . ' WHERE UPPER(rep_code) = ? AND (vigente_ate IS NULL OR vigente_ate >= ?) FOR UPDATE', [strtoupper($de), $desde]);
    foreach ($vagas as $v) {
        $mot = mb_substr($motivo . '; ' . (string) $v['motivo'], 0, 255);
        $comeca = substr((string) $v['vigente_desde'], 0, 10) >= $desde;
        if ($para === null) {
            if ($comeca) {
                escW('DELETE FROM ' . $tab . ' WHERE id = ?', [(int) $v['id']]);
            } else {
                escW('UPDATE ' . $tab . ' SET vigente_ate = ?, motivo = ? WHERE id = ?', [escMaisDias($desde, -1), $mot, (int) $v['id']]);
            }
        } elseif ($comeca) {
            escW('UPDATE ' . $tab . ' SET rep_code = ?, motivo = ? WHERE id = ?', ['#' . strtoupper($para), $mot, (int) $v['id']]);
        } else {
            escW('UPDATE ' . $tab . ' SET vigente_ate = ? WHERE id = ?', [escMaisDias($desde, -1), (int) $v['id']]);
            escW('INSERT INTO ' . $tab . ' (posto_id, vaga, rep_code, fatia, ordem, status, motivo, vigente_desde, vigente_ate, origem, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'TELA\', ?)',
                [(int) $v['posto_id'], $v['vaga'], '#' . strtoupper($para), $v['fatia'], (int) $v['ordem'], $v['status'], $mot, $desde, $v['vigente_ate'], escUserId()]);
        }
    }
    if ($para !== null) {
        escW('UPDATE ' . $tab . ' SET rep_code = SUBSTRING(rep_code, 2) WHERE rep_code = ?', ['#' . strtoupper($para)]);
    }
    return count($vagas);
}

// Troca a pessoa de uma linha de esc_lideranca a partir de $desde (mesma unidade, turno,
// deslocamento e semana). $id é a linha que vale em $desde.
function escRosterTrocarLider(int $id, string $rep, string $nome, string $desde): void {
    $tab = escT('esc_lideranca');
    $l = escQW('SELECT * FROM ' . $tab . ' WHERE id = ? FOR UPDATE', [$id])[0] ?? null;
    if (!$l) {
        throw new RuntimeException(t('Cargo não encontrado. Recarregue a tela.'));
    }
    if (substr((string) $l['vigente_desde'], 0, 10) >= $desde) {
        escW('UPDATE ' . $tab . " SET rep_code = ?, nome = ?, origem = 'TELA', criado_por = ? WHERE id = ?", [$rep, $nome, escUserId(), $id]);
        return;
    }
    escW('UPDATE ' . $tab . ' SET vigente_ate = ? WHERE id = ?', [escMaisDias($desde, -1), $id]);
    escW('INSERT INTO ' . $tab . " (nivel, unidade, turno, rep_code, nome, team_id, deslocamento, padrao_semana, vigente_desde, vigente_ate, origem, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'TELA', ?)",
        [$l['nivel'], $l['unidade'], $l['turno'], $rep, $nome, $l['team_id'], $l['deslocamento'], $l['padrao_semana'], $desde, $l['vigente_ate'], escUserId()]);
}

// Encerra uma linha de esc_lideranca na véspera de $desde (a que começa em $desde é apagada).
function escRosterFecharLider(int $id, string $desde): array {
    $tab = escT('esc_lideranca');
    $l = escQW('SELECT * FROM ' . $tab . ' WHERE id = ? FOR UPDATE', [$id])[0] ?? null;
    if (!$l) {
        throw new RuntimeException(t('Cargo não encontrado. Recarregue a tela.'));
    }
    if (substr((string) $l['vigente_desde'], 0, 10) >= $desde) {
        escW('DELETE FROM ' . $tab . ' WHERE id = ?', [$id]);
    } else {
        escW('UPDATE ' . $tab . ' SET vigente_ate = ? WHERE id = ?', [escMaisDias($desde, -1), $id]);
    }
    return $l;
}

// Para onde vai quem sai do cargo: 'lugar' (núcleo e vagas fixas de quem entrou, como SR
// do nível dado), 'mesmo' (SR no mesmo núcleo, só para OS) ou 'sem' (sem núcleo).
// $cfgEntra é a configuração que quem entrou tinha (o "lugar"); $cfgSai, a de quem sai.
function escRosterDestinoDeQuemSai(string $sai, string $nomeSai, string $entra, string $destino, ?int $nivel, ?array $cfgEntra, ?array $cfgSai, ?int $nucleoMesmo, string $desde, string $motivo): string {
    if ($destino === 'lugar') {
        if (!$cfgEntra || !$cfgEntra['nucleo_id'] || $cfgEntra['funcao'] !== 'EQUITE') {
            throw new RuntimeException(t('{rep} não tinha lugar de SR num núcleo para passar. Escolha outra opção para quem sai.', ['rep' => $entra]));
        }
        escRosterNovaConfig($sai, $cfgEntra, (int) $cfgEntra['nucleo_id'], 'EQUITE', $nivel ?? ($cfgEntra['nivel'] !== null ? (int) $cfgEntra['nivel'] : null), $desde, $motivo);
        $n = escRosterPassarVagas($entra, $sai, $desde, $motivo);
        return tn($n, '{nome} fica com o núcleo e {n} vaga fixa de quem entrou.', '{nome} fica com o núcleo e as {n} vagas fixas de quem entrou.', ['nome' => $nomeSai]);
    }
    escRosterPassarVagas($entra, null, $desde, $motivo);
    if ($destino === 'mesmo') {
        if (!$nucleoMesmo || !$cfgSai) {
            throw new RuntimeException(t('Não há configuração de quem sai para mantê-lo no núcleo. Escolha outra opção.'));
        }
        escRosterNovaConfig($sai, $cfgSai, $nucleoMesmo, 'EQUITE', $nivel ?? 5, $desde, $motivo);
        return t('{nome} fica como SR no mesmo núcleo.', ['nome' => $nomeSai]);
    }
    $c = escRosterConfig($sai, $desde);
    if ($c && $c['nucleo_id']) {
        escRosterFecharConfig($c, $desde);
    }
    escRosterPassarVagas($sai, null, $desde, $motivo);
    return t('{nome} fica sem núcleo: ajuste em Colaboradores se continuar na operação.', ['nome' => $nomeSai]);
}
