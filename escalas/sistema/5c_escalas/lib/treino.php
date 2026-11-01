<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/treino.php
// Modo Treino: o sistema lê e grava no schema 5c_escalas_treino (ES_28) em vez
// do 5c_escalas. Liga por cookie (esc_treino=1), pela Ajuda › Tutorial. As
// outras bases (Capacity, Planner, headcount, escala postada) continuam só
// sendo lidas; no treino a saída de página não mexe no Capacity.
//
// "Recomeçar o treino" apaga tudo do treino e copia de novo os cadastros do
// 5c_escalas (lista abaixo) e alguns pedidos fictícios. Escalas, pedidos reais,
// saídas e logs não são copiados.
// ===============================================

const ESC_TREINO_COPIAR = [
    'esc_unidade', 'esc_modelo_escala', 'esc_nucleo', 'esc_colaborador_config', 'esc_lideranca',
    'esc_posto', 'esc_posto_pagina', 'esc_vaga_equipe', 'esc_parametro', 'esc_acesso',
    'esc_ciclo_estado', 'esc_folga_par', 'esc_pagina_config',
];
// O mérito (esc_sr_merito, esc_sr_infraction) não é copiado: é dado individual restrito.

function escTreinoExiste(): bool {
    static $existe = null;
    if ($existe === null) {
        $existe = (bool) escQ1('SELECT 1 AS ok FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [ESC_SCHEMA_TREINO]);
    }
    return $existe;
}

// O treino ainda está vazio (primeiro acesso)?
function escTreinoVazio(): bool {
    return (int) (escQ1('SELECT COUNT(*) AS n FROM `' . ESC_SCHEMA_TREINO . '`.`esc_modelo_escala`')['n'] ?? 0) === 0;
}

function escTreinoLigar(bool $ligar): void {
    setcookie('esc_treino', $ligar ? '1' : '', ['expires' => $ligar ? time() + 30 * 86400 : time() - 3600, 'path' => '/', 'samesite' => 'Lax']);
}

// Apaga o treino e copia de novo os cadastros do 5c_escalas. Só roda com o Modo Treino ligado
// (a conexão de gravação aponta para o treino). As instruções são montadas só com nomes da
// lista acima e das tabelas que existem no treino; nenhum texto vem do usuário.
function escTreinoResetar(): array {
    if (!escModoTreino()) {
        throw new RuntimeException(t('O Modo Treino não está ligado.'));
    }
    $tabelas = array_column(escQ("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'", [ESC_SCHEMA_TREINO]), 't');
    $colunas = static function (string $schema, string $tabela): array {
        return array_column(escQ('SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$schema, $tabela]), 'c');
    };
    $pdo = escDbW();
    $copiadas = [];
    if ((int) $pdo->query("SELECT GET_LOCK('5c_escalas.treino_reset', 0)")->fetchColumn() !== 1) {
        throw new RuntimeException(t('O treino já está sendo recomeçado por outra pessoa. Espere um instante e recarregue.'));
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    try {
        foreach ($tabelas as $tab) {
            if (preg_match('~^esc_[a-z_]+$~', $tab)) {
                escW('DELETE FROM `' . ESC_SCHEMA_TREINO . '`.`' . $tab . '`');
            }
        }
        foreach (ESC_TREINO_COPIAR as $tab) {
            if (!in_array($tab, $tabelas, true)) {
                continue;
            }
            // Só as colunas que existem nos dois (se um script novo ainda não rodou no treino).
            $cols = array_values(array_intersect($colunas(ESC_SCHEMA_TREINO, $tab), $colunas(ESC_SCHEMA_REAL, $tab)));
            if (!$cols) {
                continue;
            }
            $lista = implode(', ', array_map(static fn($c) => '`' . str_replace('`', '', $c) . '`', $cols));
            $copiadas[$tab] = $pdo->exec('INSERT INTO `' . ESC_SCHEMA_TREINO . '`.`' . $tab . '` (' . $lista . ') SELECT ' . $lista . ' FROM `' . ESC_SCHEMA_REAL . '`.`' . $tab . '`');
        }
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $pdo->query("SELECT RELEASE_LOCK('5c_escalas.treino_reset')")->fetchColumn();
    }
    // Pedidos fictícios para praticar a aprovação: 3 SRs, um dia cada na semana que vem.
    $seg = segundaDaSemana(date('Y-m-d', strtotime('+7 day')));
    $srs = escQW("SELECT UPPER(rep_code) AS rep, turno FROM `" . ESC_SCHEMA_TREINO . "`.`esc_colaborador_config` WHERE funcao = 'EQUITE' AND vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?) ORDER BY rep_code LIMIT 3", [$seg, $seg]);
    $motivos = [['ESTUDO', 'Treino · prova na faculdade'], ['SAUDE', 'Treino · consulta médica'], ['PESSOAL', 'Treino · mudança de casa']];
    foreach ($srs as $i => $s) {
        escW('INSERT INTO `' . ESC_SCHEMA_TREINO . "`.`esc_indisponibilidade` (rep_code, tipo, canal, motivo_categoria, motivo_texto, status, solicitado_por) VALUES (?, 'UM_DIA', 'SITE', ?, ?, 'PENDENTE', ?)",
            [$s['rep'], $motivos[$i][0], $motivos[$i][1], escUserId() ?: null]);
        $id = escUltimoId();
        escW('INSERT INTO `' . ESC_SCHEMA_TREINO . "`.`esc_indisponibilidade_data` (indisponibilidade_id, dia, turno, status) VALUES (?, ?, ?, 'PENDENTE')",
            [$id, escMaisDias($seg, 2 + $i), $s['turno'] ?: 'A']);
    }
    escLog('treino.recomecar', 'treino', null, null, ['copiadas' => $copiadas]);
    return $copiadas;
}
