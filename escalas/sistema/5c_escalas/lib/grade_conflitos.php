<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/grade_conflitos.php
// Alertas do ajuste manual na Grade (escGradeConflitos).
// Separado de lib/motor.php em 04/11 (1.19.18), só movendo as funções, sem mudar nada.
// Carregado por lib/motor.php (require_once no topo).
// ===============================================

// Conflitos de quem vai entrar numa célula da grade (ajuste manual). Devolve textos de alerta.
function escGradeConflitos(int $planoId, int $pageId, string $dia, string $turno, array $celulas, array $pessoas): array {
    $alertas = [];
    $in = escIn($pessoas);
    // Folga programada no plano.
    foreach (escQ('SELECT UPPER(rep_code) AS r, tipo FROM ' . escT('esc_plano_folga') . ' WHERE plano_id = ? AND dia = ? AND UPPER(rep_code) IN (' . $in . ')', array_merge([$planoId, $dia], $pessoas)) as $f) {
        $alertas[] = t('{rep} está de folga neste dia ({tipo})', ['rep' => $f['r'], 'tipo' => mb_strtolower(escRotulo('tipo_folga', (string) $f['tipo']))]);
    }
    // Indisponibilidade aprovada.
    $indisp = escMotorIndisp([$dia]);
    foreach ($pessoas as $r) {
        if (array_key_exists($turno, $indisp[$r][$dia] ?? [])) {
            $alertas[] = t('{rep} tem indisponibilidade aprovada neste turno', ['rep' => $r]);
        }
    }
    // Turno do cadastro.
    foreach (escQ('SELECT UPPER(rep_code) AS r, turno FROM ' . escT('esc_colaborador_config') . ' WHERE UPPER(rep_code) IN (' . $in . ') AND vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)', array_merge($pessoas, [$dia, $dia])) as $c) {
        if ($c['turno'] && $c['turno'] !== $turno) {
            $alertas[] = t('{rep} é do turno {turno}', ['rep' => $c['r'], 'turno' => $c['turno']]);
        }
    }
    // Já está em outra página no mesmo bloco.
    $params = [$planoId, $pageId, $celulas[0][0], $celulas[0][1], $celulas[1][0], $celulas[1][1]];
    foreach (escQ('SELECT UPPER(rep_code) AS r, GROUP_CONCAT(DISTINCT pagina ORDER BY pagina SEPARATOR \', \') AS paginas FROM ' . escT('esc_plano_alocacao') . ' WHERE plano_id = ? AND page_id <> ? AND ((dia = ? AND bloco = ?) OR (dia = ? AND bloco = ?)) AND UPPER(rep_code) IN (' . $in . ') GROUP BY UPPER(rep_code)', array_merge($params, $pessoas)) as $o) {
        $alertas[] = t('{rep} já está em {paginas} neste turno', ['rep' => $o['r'], 'paginas' => $o['paginas']]);
    }
    // 12 h seguidas e 52 h na semana (turnos de 8 h; o T1 conta no dia do turno D).
    $vizinhos = ['A' => [[escMaisDias($dia, -1), 'D'], [$dia, 'C']], 'C' => [[$dia, 'A'], [$dia, 'D']], 'D' => [[$dia, 'C'], [escMaisDias($dia, 1), 'A']]][$turno] ?? [];
    $horasMax = (int) escV4Param('v4_horas_semana_max', '52');
    $ja = [];
    foreach (escQ("SELECT UPPER(rep_code) AS r, turno, IF(bloco = 'T1', DATE_SUB(dia, INTERVAL 1 DAY), dia) AS dt FROM " . escT('esc_plano_alocacao') . ' WHERE plano_id = ? AND UPPER(rep_code) IN (' . $in . ') AND NOT (page_id = ? AND turno = ? AND IF(bloco = \'T1\', DATE_SUB(dia, INTERVAL 1 DAY), dia) = ?) GROUP BY UPPER(rep_code), turno, dt', array_merge([$planoId], $pessoas, [$pageId, $turno, $dia])) as $l) {
        $ja[$l['r']][substr((string) $l['dt'], 0, 10) . '|' . $l['turno']] = true;
    }
    // Vizinho em outra semana: lê a versão publicada (ou a atual) daquela semana.
    $plano = escQ1('SELECT semana_inicio, semana_fim FROM ' . escT('esc_plano') . ' WHERE id = ?', [$planoId]);
    foreach ($vizinhos as [$d2, $t2]) {
        if ($plano && ($d2 < substr((string) $plano['semana_inicio'], 0, 10) || $d2 > substr((string) $plano['semana_fim'], 0, 10))) {
            $segV = segundaDaSemana($d2);
            $outro = escPlanoPublicado($segV) ?? escPlanoAtual($segV);
            if ($outro) {
                foreach (escQ("SELECT DISTINCT UPPER(rep_code) AS r FROM " . escT('esc_plano_alocacao') . " WHERE plano_id = ? AND turno = ? AND IF(bloco = 'T1', DATE_SUB(dia, INTERVAL 1 DAY), dia) = ? AND UPPER(rep_code) IN (" . $in . ')', array_merge([(int) $outro['id'], $t2, $d2], $pessoas)) as $o) {
                    $ja[$o['r']][$d2 . '|' . $t2] = true;
                }
            }
        }
    }
    foreach ($pessoas as $r) {
        foreach ($vizinhos as [$d2, $t2]) {
            if (isset($ja[$r][$d2 . '|' . $t2])) {
                $alertas[] = t('{rep} está no turno {turno} de {dia}: seriam 16 h seguidas (máximo 12 h)', ['rep' => $r, 'turno' => $t2, 'dia' => dataBr($d2)]);
            }
        }
        $turnosSemana = count($ja[$r] ?? []) + (isset($ja[$r][$dia . '|' . $turno]) ? 0 : 1);
        if ($turnosSemana * 8 > $horasMax) {
            $alertas[] = t('{rep} passaria de {max} h na semana ({horas} h)', ['rep' => $r, 'max' => $horasMax, 'horas' => $turnosSemana * 8]);
        }
    }
    return array_values(array_unique($alertas));
}
