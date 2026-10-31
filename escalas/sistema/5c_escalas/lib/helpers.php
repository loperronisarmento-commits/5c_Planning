<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/helpers.php
// Funções pequenas de apresentação usadas pelas páginas.
// ===============================================

function h($v): string {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

// Números, % e datas no formato do idioma da tela (lib/i18n.php).
function num($v, int $casas = 1): string {
    if ($v === null || $v === '') {
        return '—';
    }
    [$dec, $mil] = escSeparadores();
    return number_format((float) $v, $casas, $dec, $mil);
}

function pct($v, int $casas = 1): string {
    [$dec, $mil] = escSeparadores();
    return $v === null || $v === '' ? '—' : number_format((float) $v * 100, $casas, $dec, $mil) . '%';
}

// Nome histórico: devolve a data curta no formato do idioma (pt-BR dd/mm · en mm/dd).
function dataBr(?string $iso, bool $comDiaSemana = false): string {
    return escData($iso, $comDiaSemana);
}

// Link para outra tela do módulo, mantendo só os parâmetros pedidos.
function link_tela(string $p, array $params = []): string {
    return MODULE_URL . '/index.php?' . http_build_query(array_merge(['p' => $p], $params));
}

// Hora de início do bloco (scheduleday) -> T1..T6 e turno A/C/D.
const ESC_BLOCOS = ['01' => 'T1', '05' => 'T2', '09' => 'T3', '13' => 'T4', '17' => 'T5', '21' => 'T6'];

function blocoDaHora(string $hora): string {
    return ESC_BLOCOS[$hora] ?? '?';
}

function turnoDoBloco(string $bloco): string {
    return ['T2' => 'A', 'T3' => 'A', 'T4' => 'C', 'T5' => 'C', 'T6' => 'D', 'T1' => 'D'][$bloco] ?? '?';
}

function chipTurno(string $turno): string {
    $cls = ['A' => 'chip-a', 'C' => 'chip-c', 'D' => 'chip-d'][$turno] ?? '';
    return '<span class="chip ' . $cls . '">' . h($turno) . '</span>';
}

// Segunda-feira (Y-m-d) da semana ISO de uma data.
function segundaDaSemana(string $data): string {
    $t = strtotime($data);
    $n = (int) date('N', $t);
    return date('Y-m-d', strtotime('-' . ($n - 1) . ' days', $t));
}

function ehPrimaris(?string $papel): bool {
    return $papel !== null && stripos($papel, 'Primaris') !== false;
}

// Status da versão do plano: texto + cor (nunca só cor).
function escTagStatusPlano(string $status): string {
    $cls = ['RASCUNHO' => 'amarelo', 'PUBLICADO' => 'verde', 'SUBSTITUIDO' => 'cinza'][$status] ?? '';
    return '<span class="tag ' . $cls . '">' . h(escRotulo('status_plano', $status)) . '</span>';
}
