<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/casais.php
// Folga juntos (casais, pedido de 31/10; tabela esc_folga_par, ES_45). Duas pessoas folgam nos
// mesmos dias quando estão no mesmo ponto do ciclo de 21 dias: mesmo deslocamento (SR e OS pelo
// núcleo — núcleo 1 = 0, 2 = 14, 3 = 7 em todas as unidades —, OC e OA pelo deslocamento do
// Roster). OA com semana fixa só folga junto de quem tem as mesmas folgas na semana.
//   - A tela mostra, para cada par, as folgas dos próximos 21 dias e se coincidem.
//   - Par desalinhado: propõe o ajuste, de um lado ou do outro (o planejador escolhe a data):
//     SR muda para o núcleo do turno com o deslocamento do par; OC/OA troca o deslocamento com
//     o colega do turno que tem o do par; OS troca de núcleo com o OS do núcleo do par.
//   - O motor dá aos SRs do par o mesmo dia de Overlap (escV4OverlapDe), para nenhum dos dois
//     trabalhar no treinamento enquanto o outro folga.
// ===============================================

function escCasaisInstalado(): bool {
    static $ok = null;
    if ($ok === null) {
        $ok = (bool) escQ1("SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'esc_folga_par'", [ESC_SCHEMA]);
    }
    return $ok;
}

// Pares vigentes no dia (ou a partir dele, com $futuros).
function escCasaisPares(string $dia, bool $futuros = false): array {
    if (!escCasaisInstalado()) {
        return [];
    }
    return escQ('SELECT id, UPPER(rep_a) AS rep_a, UPPER(rep_b) AS rep_b, observacao, vigente_desde, vigente_ate FROM ' . escT('esc_folga_par')
        . ' WHERE ' . ($futuros ? '' : 'vigente_desde <= ? AND ') . '(vigente_ate IS NULL OR vigente_ate >= ?) ORDER BY id', $futuros ? [$dia] : [$dia, $dia]);
}

// Lê o código de "Nome (X123456)" ou de "X123456".
function escCasalCodigo(string $texto): ?string {
    $texto = trim($texto);
    if (preg_match('~\(([A-Za-z]\d{6})\)\s*$~', $texto, $m) || preg_match('~^([A-Za-z]\d{6})$~', $texto, $m)) {
        return strtoupper($m[1]);
    }
    return null;
}

// Quem está na estrutura no dia, com o ponto do ciclo: [rep] = ['rep', 'cargo' SR|OS|OC|OA|OM, 'nivel',
// 'unidade', 'turno', 'nucleo_id', 'numero', 'deslocamento' (null = sem ciclo), 'padrao' (semana
// fixa do OA), 'tabela' e 'linha' (o cadastro que um ajuste muda)].
function escCasalPessoas(string $dia): array {
    $vig = ' vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)';
    $nucleos = [];
    foreach (escQ('SELECT id, unidade, turno, numero, deslocamento, decurio_code FROM ' . escT('esc_nucleo') . ' WHERE' . $vig, [$dia, $dia]) as $n) {
        $nucleos[(int) $n['id']] = $n;
    }
    $pessoas = [];
    foreach (escQ('SELECT * FROM ' . escT('esc_colaborador_config') . " WHERE funcao IN ('EQUITE', 'DECURIO') AND nucleo_id IS NOT NULL AND" . $vig . ' ORDER BY vigente_desde', [$dia, $dia]) as $c) {
        $n = $nucleos[(int) $c['nucleo_id']] ?? null;
        if (!$n) {
            continue;
        }
        $rep = strtoupper((string) $c['rep_code']);
        $pessoas[$rep] = ['rep' => $rep, 'cargo' => $c['funcao'] === 'DECURIO' ? 'OS' : 'SR', 'nivel' => (int) $c['nivel'], 'unidade' => (int) $n['unidade'],
            'turno' => $n['turno'], 'nucleo_id' => (int) $n['id'], 'numero' => (int) $n['numero'], 'deslocamento' => (int) $n['deslocamento'], 'padrao' => null,
            'tabela' => 'esc_colaborador_config', 'linha' => $c];
    }
    // OS sem cadastro de OS (vale o decurio gravado no núcleo): aparece, mas o ajuste é pelo Roster.
    foreach ($nucleos as $n) {
        $rep = strtoupper((string) $n['decurio_code']);
        if ($rep !== '' && !isset($pessoas[$rep])) {
            $pessoas[$rep] = ['rep' => $rep, 'cargo' => 'OS', 'nivel' => null, 'unidade' => (int) $n['unidade'], 'turno' => $n['turno'], 'nucleo_id' => (int) $n['id'],
                'numero' => (int) $n['numero'], 'deslocamento' => (int) $n['deslocamento'], 'padrao' => null, 'tabela' => null, 'linha' => null];
        }
    }
    // Liderança do Roster: sem deslocamento gravado vale o padrão da escala da liderança
    // (OC: o do núcleo de mesmo número da unidade; OA: 0, 14 e 7 pela ordem no turno).
    $deslNumero = [];
    foreach ($nucleos as $n) {
        $deslNumero[$n['turno']][(int) $n['numero']] = (int) $n['deslocamento'];
    }
    $ordemOA = [];
    foreach (escQ('SELECT * FROM ' . escT('esc_lideranca') . ' WHERE rep_code IS NOT NULL AND' . $vig . ' ORDER BY nivel, unidade, turno, nome', [$dia, $dia]) as $l) {
        $rep = strtoupper((string) $l['rep_code']);
        $semana = strtoupper((string) $l['padrao_semana']);
        $p = ['rep' => $rep, 'cargo' => $l['nivel'], 'nivel' => null, 'unidade' => $l['unidade'] !== null ? (int) $l['unidade'] : null, 'turno' => $l['turno'],
            'nucleo_id' => null, 'numero' => null, 'deslocamento' => null, 'padrao' => null, 'tabela' => 'esc_lideranca', 'linha' => $l];
        if ($l['nivel'] === 'OA' && strlen($semana) === 7) {
            $p['padrao'] = $semana;
        } elseif (in_array($l['nivel'], ['OC', 'OA'], true) && $l['turno']) {
            $padrao = $l['nivel'] === 'OC' ? ($deslNumero[$l['turno']][(int) $l['unidade']] ?? 0) : [0, 14, 7][($ordemOA[$l['turno']] = ($ordemOA[$l['turno']] ?? -1) + 1) % 3];
            $p['deslocamento'] = $l['deslocamento'] !== null ? (int) $l['deslocamento'] : $padrao;
        }
        $pessoas[$rep] = $p;
    }
    return ['pessoas' => $pessoas, 'nucleos' => $nucleos];
}

// Parâmetros do ciclo usados nas folgas.
function escCasalCiclo(): array {
    $modelo = escQ1('SELECT ciclo FROM ' . escT('esc_modelo_escala') . " WHERE nome = '5x2 rotativo 21 dias'");
    $lista = static fn(string $v): array => array_map('intval', array_filter(array_map('trim', explode(',', $v)), 'strlen'));
    return ['ciclo' => (string) ($modelo['ciclo'] ?? ''), 'ancora' => escV4Param('v4_rotacao_ancora', '2026-11-02'),
        'overlap_sr' => $lista(escV4Param('v4_overlap_posicao_ciclo', '')), 'overlap_lider' => $lista(escV4Param('v4_lideranca_overlap_posicao', '5'))];
}

// O que o motor precisa: os pares e, de quem do par é da liderança no ciclo, deslocamento e Overlap.
function escCasaisMotor(string $dia): array {
    try {
        $pares = escCasaisPares($dia);
        if (!$pares) {
            return [];
        }
        $e = escCasalPessoas($dia);
        $pos = escCasalCiclo()['overlap_lider'];
        $lider = [];
        foreach ($pares as $par) {
            foreach ([$par['rep_a'], $par['rep_b']] as $r) {
                $p = $e['pessoas'][$r] ?? null;
                if ($p && $p['cargo'] !== 'SR' && $p['deslocamento'] !== null) {
                    $lider[$r] = ['deslocamento' => $p['deslocamento'], 'overlap' => $pos[0] ?? null];
                }
            }
        }
        return ['pares' => array_map(static fn($p) => [$p['rep_a'], $p['rep_b']], $pares), 'pares_lider' => $lider];
    } catch (Throwable $ex) {
        appLog('warning', 'Folga juntos: pares fora do motor', ['erro' => $ex->getMessage()]);
        return [];
    }
}

// Dias de folga da pessoa em $n dias a partir de $de. $overlap = posições do ciclo em que ela
// trabalha no treinamento (Overlap), mesmo sendo dia de folga do ciclo.
function escCasalFolgas(array $p, string $de, int $n, array $c, array $overlap): array {
    $dias = [];
    for ($i = 0; $i < $n; $i++) {
        $d = escMaisDias($de, $i);
        if ($p['padrao'] !== null) {
            if ($p['padrao'][(int) date('N', strtotime($d)) - 1] === '.') {
                $dias[] = $d;
            }
        } elseif ($p['deslocamento'] !== null && $c['ciclo'] !== '') {
            $idx = escV4IndiceCiclo(['deslocamento' => $p['deslocamento']], $c['ciclo'], $c['ancora'], $d);
            if ($c['ciclo'][$idx] !== '1' && !in_array($idx, $overlap, true)) {
                $dias[] = $d;
            }
        }
    }
    return $dias;
}

// Ajustes possíveis para $x passar a folgar com $y (função pura sobre escCasalPessoas).
function escCasalOpcoes(array $x, array $y, array $pessoas, array $nucleos): array {
    $alvo = $y['deslocamento'];
    if ($alvo === null || $x['padrao'] !== null || $y['padrao'] !== null || $x['deslocamento'] === $alvo) {
        return [];
    }
    $ops = [];
    if ($x['cargo'] === 'SR' || $x['cargo'] === 'OS') {
        foreach ($nucleos as $n) {
            if ((int) $n['unidade'] !== $x['unidade'] || $n['turno'] !== $x['turno'] || (int) $n['deslocamento'] !== $alvo) {
                continue;
            }
            if ($x['cargo'] === 'SR') {
                $ops[] = ['tipo' => 'SR', 'rep' => $x['rep'], 'nucleo_id' => (int) $n['id'], 'numero' => (int) $n['numero'], 'com' => null];
                continue;
            }
            $outro = null;
            foreach ($pessoas as $p) {
                if ($p['cargo'] === 'OS' && $p['nucleo_id'] === (int) $n['id']) {
                    $outro = $p;
                }
            }
            if ($outro && $x['tabela'] && $outro['tabela']) {
                $ops[] = ['tipo' => 'OS', 'rep' => $x['rep'], 'nucleo_id' => (int) $n['id'], 'numero' => (int) $n['numero'], 'com' => $outro['rep']];
            }
        }
    } elseif (in_array($x['cargo'], ['OC', 'OA'], true) && $x['tabela']) {
        $colegas = array_filter($pessoas, static fn($p) => $p['cargo'] === $x['cargo'] && $p['turno'] === $x['turno'] && $p['rep'] !== $x['rep'] && $p['padrao'] === null);
        $com = array_values(array_filter($colegas, static fn($p) => $p['deslocamento'] === $alvo));
        if ($com) {
            $ops[] = ['tipo' => 'LIDER', 'rep' => $x['rep'], 'deslocamento' => $alvo, 'com' => $com[0]['rep']];
        } else {
            $ops[] = ['tipo' => 'LIDER', 'rep' => $x['rep'], 'deslocamento' => $alvo, 'com' => null];
        }
    }
    return $ops;
}

// Situação de cada par a partir de $de (21 dias): pessoas, folgas, dias juntos e ajustes.
function escCasaisSituacao(string $de, bool $futuros = true): array {
    $pares = escCasaisPares($de, $futuros);
    if (!$pares) {
        return [];
    }
    $e = escCasalPessoas($de);
    $c = escCasalCiclo();
    // Overlap dos SRs como o motor calcula, com os pares.
    $srs = [];
    foreach ($e['pessoas'] as $rep => $p) {
        if ($p['cargo'] === 'SR') {
            $srs[$rep] = ['nucleo' => $p['nucleo_id']];
        }
    }
    $lider = escCasaisMotor($de)['pares_lider'] ?? [];
    $overlapDe = escV4OverlapDe($srs, $c['overlap_sr'], $e['nucleos'], array_map(static fn($p) => [$p['rep_a'], $p['rep_b']], $pares), $lider);
    $overlap = static fn(?array $p): array => !$p ? [] : ($p['cargo'] === 'SR' ? (isset($overlapDe[$p['rep']]) ? [$overlapDe[$p['rep']]] : []) : ($p['cargo'] === 'OM' ? [] : $c['overlap_lider']));
    $saida = [];
    foreach ($pares as $par) {
        $a = $e['pessoas'][$par['rep_a']] ?? null;
        $b = $e['pessoas'][$par['rep_b']] ?? null;
        $fa = $a ? escCasalFolgas($a, $de, 21, $c, $overlap($a)) : [];
        $fb = $b ? escCasalFolgas($b, $de, 21, $c, $overlap($b)) : [];
        $semCiclo = !$a || !$b || ($a['deslocamento'] === null && $a['padrao'] === null) || ($b['deslocamento'] === null && $b['padrao'] === null);
        $saida[] = $par + ['a' => $a, 'b' => $b, 'folgas_a' => $fa, 'folgas_b' => $fb, 'juntos' => array_values(array_intersect($fa, $fb)),
            'alinhado' => !$semCiclo && $fa === $fb, 'sem_ciclo' => $semCiclo,
            'opcoes' => $a && $b ? array_merge(escCasalOpcoes($a, $b, $e['pessoas'], $e['nucleos']), escCasalOpcoes($b, $a, $e['pessoas'], $e['nucleos'])) : []];
    }
    return $saida;
}

// Aviso para a tela Gerar: pares que não folgam juntos na semana.
function escCasaisAviso(string $seg): string {
    try {
        $fora = array_filter(escCasaisSituacao(max($seg, escV4DiaEstrutura()), false), static fn($s) => !$s['alinhado']);
    } catch (Throwable $e) {
        return '';
    }
    return $fora ? ' ' . tn(count($fora), '{n} par de Folga juntos não folga junto: ajuste em Pessoas › Folga juntos.', '{n} pares de Folga juntos não folgam juntos: ajuste em Pessoas › Folga juntos.') : '';
}

// Cadastro com vigência: muda $novo a partir de $desde (linha que começa em $desde ou depois é
// alterada; a de antes fecha na véspera e ganha uma cópia). $colunas = as copiadas (fixas aqui).
function escCasalVersionar(string $tabela, array $linha, array $novo, string $desde, array $colunas): void {
    $tab = escT($tabela);
    $sets = implode(', ', array_map(static fn($k) => $k . ' = ?', array_keys($novo)));
    if (substr((string) $linha['vigente_desde'], 0, 10) >= $desde) {
        escW('UPDATE ' . $tab . ' SET ' . $sets . ' WHERE id = ?', array_merge(array_values($novo), [(int) $linha['id']]));
        return;
    }
    escW('UPDATE ' . $tab . ' SET vigente_ate = ? WHERE id = ?', [escMaisDias($desde, -1), (int) $linha['id']]);
    $vals = [];
    foreach ($colunas as $col) {
        $vals[] = array_key_exists($col, $novo) ? $novo[$col] : $linha[$col];
    }
    $colunas[] = 'vigente_desde';
    $vals[] = $desde;
    $colunas[] = 'vigente_ate';
    $vals[] = $linha['vigente_ate'];
    $colunas[] = 'criado_por';
    $vals[] = escUserId() ?: null;
    escW('INSERT INTO ' . $tab . ' (' . implode(', ', $colunas) . ') VALUES (' . implode(', ', array_fill(0, count($vals), '?')) . ')', $vals);
}

const ESC_CASAL_COLS_CONFIG = ['rep_code', 'modelo_id', 'tipo', 'turno', 'unidade', 'nucleo_id', 'nivel', 'funcao', 'fatia_regular', 'trilha', 'ancora', 'folgas_fixas', 'pode_assistente', 'observacao', 'origem'];
const ESC_CASAL_COLS_LIDER = ['nivel', 'unidade', 'turno', 'rep_code', 'nome', 'team_id', 'deslocamento', 'padrao_semana', 'origem'];

// Aplica um ajuste de escCasalOpcoes a partir de $desde. Rodar fora de outra transação.
function escCasalAplicar(int $parId, int $indice, string $desde): array {
    if (escModoTreino()) {
        throw new RuntimeException(t('No Modo Treino o ajuste da Folga juntos não é aplicado.'));
    }
    if ($desde < date('Y-m-d')) {
        throw new RuntimeException(t('O ajuste vale a partir de hoje ou de uma data futura.'));
    }
    $sit = array_values(array_filter(escCasaisSituacao($desde), static fn($s) => (int) $s['id'] === $parId))[0] ?? null;
    $op = $sit['opcoes'][$indice] ?? null;
    if (!$op) {
        throw new RuntimeException(t('Esse ajuste não vale mais (a estrutura mudou). Recarregue a tela.'));
    }
    $e = escCasalPessoas($desde);
    $x = $e['pessoas'][$op['rep']];
    $com = $op['com'] ? $e['pessoas'][$op['com']] : null;
    $obs = 'Folga juntos a partir de ' . date('d/m/Y', strtotime($desde)); // gravado no banco: em português
    escTx(static function () use ($op, $x, $com, $desde, $obs) {
        if ($op['tipo'] === 'SR') {
            escCasalVersionar('esc_colaborador_config', $x['linha'], ['nucleo_id' => $op['nucleo_id'], 'observacao' => $obs, 'origem' => 'TELA'], $desde, ESC_CASAL_COLS_CONFIG);
            // Equipe do núcleo antigo sai; a geração seguinte completa as vagas (Owners e backups).
            escW('DELETE FROM ' . escT('esc_vaga_equipe') . ' WHERE UPPER(rep_code) = ? AND vigente_desde >= ?', [$x['rep'], $desde]);
            escW('UPDATE ' . escT('esc_vaga_equipe') . ' SET vigente_ate = ? WHERE UPPER(rep_code) = ? AND (vigente_ate IS NULL OR vigente_ate >= ?)', [escMaisDias($desde, -1), $x['rep'], $desde]);
        } elseif ($op['tipo'] === 'OS') {
            escCasalVersionar('esc_colaborador_config', $x['linha'], ['nucleo_id' => $op['nucleo_id'], 'observacao' => $obs, 'origem' => 'TELA'], $desde, ESC_CASAL_COLS_CONFIG);
            escCasalVersionar('esc_colaborador_config', $com['linha'], ['nucleo_id' => $x['nucleo_id'], 'observacao' => $obs, 'origem' => 'TELA'], $desde, ESC_CASAL_COLS_CONFIG);
            unset($GLOBALS['ESC_OS_DO_DIA']);
        } else {
            escCasalVersionar('esc_lideranca', $x['linha'], ['deslocamento' => $op['deslocamento'], 'origem' => 'TELA'], $desde, ESC_CASAL_COLS_LIDER);
            if ($com) {
                escCasalVersionar('esc_lideranca', $com['linha'], ['deslocamento' => $x['deslocamento'], 'origem' => 'TELA'], $desde, ESC_CASAL_COLS_LIDER);
            }
        }
    });
    escLog('casal.ajuste', 'esc_folga_par', $parId, null, $op + ['desde' => $desde]);
    return $op;
}

// Texto do ajuste para a tela.
function escCasalTextoOpcao(array $op, array $nomes): string {
    $nome = static fn(?string $r): string => $r ? nomeCurto($nomes[$r] ?? $r) : '';
    if ($op['tipo'] === 'SR') {
        return t('{quem} passa para o núcleo {n} (mesma unidade e turno)', ['quem' => $nome($op['rep']), 'n' => $op['numero']]);
    }
    if ($op['tipo'] === 'OS') {
        return t('{quem} troca de núcleo com {com} (vai para o núcleo {n})', ['quem' => $nome($op['rep']), 'com' => $nome($op['com']), 'n' => $op['numero']]);
    }
    return $op['com'] ? t('{quem} troca o ciclo com {com} (vai para o ciclo {d})', ['quem' => $nome($op['rep']), 'com' => $nome($op['com']), 'd' => $op['deslocamento']])
        : t('{quem} passa para o ciclo {d}', ['quem' => $nome($op['rep']), 'd' => $op['deslocamento']]);
}
