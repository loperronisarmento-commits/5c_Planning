<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/calendario.php
// Calendário da escala: dias da semana, turnos, ciclo do 5x2, virada da estrutura, horário do corte.
// Separado de lib/motor.php e lib/motor_v4.php em 04/11 (1.19.18), só movendo as funções, sem mudar nada.
// Carregado por lib/motor.php e lib/motor_v4.php (require_once no topo).
// ===============================================

const ESC_ANCORA = '2026-10-05';

const ESC_TURNOS = ['A' => ['T2', 'T3'], 'C' => ['T4', 'T5'], 'D' => ['T6', 'T1']];

function escDiasDaSemana(string $seg): array {
    $dias = [];
    for ($i = 0; $i < 7; $i++) {
        $dias[] = date('Y-m-d', strtotime("{$seg} +{$i} day"));
    }
    return $dias;
}

function escMaisDias(string $dia, int $n): string {
    return date('Y-m-d', strtotime("{$dia} {$n} day"));
}

function escMaxSeguidos(string $ciclo): int {
    if (strpos($ciclo, '0') === false) {
        return strlen($ciclo);
    }
    $maior = 0;
    $atual = 0;
    foreach (str_split($ciclo . $ciclo) as $c) {
        $atual = $c === '1' ? $atual + 1 : 0;
        $maior = max($maior, $atual);
    }
    return min($maior, strlen($ciclo));
}

// Trabalha no dia pelo ciclo? (folgas fixas valem para modelo SEMANA)
function escTrabalhaNoDia(array $cfg, int $trilha, string $dia): bool {
    $ciclo = $cfg['ciclo'];
    $L = strlen($ciclo);
    if ($cfg['mtipo'] === 'SEMANA') {
        $dow = (int) date('N', strtotime($dia)) - 1;
        if (!empty($cfg['folgas_fixas'])) {
            return $cfg['folgas_fixas'][$dow] !== '1';
        }
        return $ciclo[(($dow + $trilha) % 7) % $L] === '1';
    }
    $ancora = $cfg['ancora'] ?: ESC_ANCORA;
    $dias = (int) round((strtotime($dia) - strtotime($ancora)) / 86400);
    $idx = ((($dias + $trilha + $L - 1) % $L) + $L) % $L;
    return $ciclo[$idx] === '1';
}

// Corte dos dados históricos (pedido de 09/11): os dados anteriores estão comprometidos, então as janelas
// de desempenho (mérito, metas, faltas, SLA, vendas, quem conhece a página, política, tags) nunca começam
// antes desta data. O Capacity fica de fora e segue com as janelas dele.
function escDadosDesde(): string {
    return substr(escV4Param('dados_desde', '2026-11-01'), 0, 10);
}

// Início de uma janela: nunca antes do corte. Aceita 'AAAA-MM-DD' e 'AAAA-MM-DDTHH:MM:SS'.
function escCorteDados(string $inicio): string {
    $corte = escDadosDesde();
    return substr($inicio, 0, 10) < $corte ? $corte . substr($inicio, 10) : $inicio;
}

// Janela em dias para trás a partir de hoje, sem passar do corte.
function escDiasCorte(int $n): int {
    return max(1, min($n, (int) round((strtotime(date('Y-m-d')) - strtotime(escDadosDesde())) / 86400)));
}

function escV4DiaVirada(): string {
    return substr(escV4Param('v4_virada', '2026-11-01 05:00'), 0, 10);
}

// Postagem da semana da virada: o que vem antes da virada continua a escala já postada no site.
// Devolve o primeiro bloco da estrutura nova ('Y-m-dTHH:00:00') se a virada cai depois do início
// da semana $seg (segunda T1), senão ''. A Grade apaga e posta só daí em diante.
function escPostagemCorte(string $seg): string {
    $virada = escV4Param('v4_virada', '2026-11-01 05:00');
    $corte = substr($virada, 0, 10) . 'T' . substr($virada, 11, 2) . ':00:00';
    return $corte > $seg . 'T01:00:00' && $corte < escMaisDias($seg, 7) . 'T01:00:00' ? $corte : '';
}

// Início de cada turno no dia (slot do CSV): A às 05:00, C às 13:00, D às 21:00.
const ESC_TURNO_HORA = ['A' => '05', 'C' => '13', 'D' => '21'];

// "A partir de" (dia e turno) como slot da semana ('Y-m-dTHH:00:00', no início de um turno);
// '' quando não é o começo de um turno desta semana.
function escSlotDaSemana(string $seg, string $slot): string {
    if (!preg_match('~^(\d{4}-\d{2}-\d{2})T(05|13|21):00:00$~', $slot, $m)) {
        return '';
    }
    return $m[1] >= $seg && $m[1] <= escMaisDias($seg, 6) ? $slot : '';
}

// Turnos da semana para escolher o "a partir de": [slot => rótulo].
function escSlotsDaSemana(string $seg): array {
    $slots = [];
    foreach (escDiasDaSemana($seg) as $d) {
        foreach (ESC_TURNO_HORA as $turno => $hora) {
            $slots[$d . 'T' . $hora . ':00:00'] = t('{dia} · turno {turno} ({blocos})', ['dia' => dataBr($d, true), 'turno' => $turno, 'blocos' => implode('/', ESC_TURNOS[$turno])]);
        }
    }
    return $slots;
}

// Corte do CSV da Grade: os blocos antes dele ficam em branco e o site continua com o que já tem
// (Date Schedule = dia do corte). Nunca antes da virada. Vale o escolhido na tela ('semana' = a
// semana toda); sem escolha, o "a partir de" com que a versão foi gerada.
function escCsvCorte(string $seg, ?array $plano, ?string $escolhido = null): string {
    $par = $plano ? (json_decode((string) ($plano['parametros'] ?? ''), true) ?: []) : [];
    $desde = escSlotDaSemana($seg, $escolhido ?? (string) ($par['desde'] ?? ''));
    return max(escPostagemCorte($seg), $desde);
}

// Primeiro turno da estrutura nova no dia da virada: 05:00 → A, 13:00 → C, 21:00 → D
// (a virada às 21:00 começa no turno D: T6 do dia + T1 do dia seguinte).
function escV4TurnoVirada(): string {
    $h = (int) substr(escV4Param('v4_virada', '2026-11-01 05:00'), 11, 2);
    return $h >= 21 ? 'D' : ($h >= 13 ? 'C' : 'A');
}

// O turno já é da estrutura nova nesse dia?
function escV4TurnoNovo(string $dia, string $turno, string $virada, string $turnoVirada): bool {
    $ordem = ['A' => 0, 'C' => 1, 'D' => 2];
    return $dia > $virada || ($dia === $virada && ($ordem[$turno] ?? 0) >= ($ordem[$turnoVirada] ?? 0));
}

// Dia em que a estrutura nova (postos, núcleos, equipes) já vale: com a virada no turno C ou D,
// o dia seguinte (o turno D da virada usa a estrutura do dia seguinte).
function escV4DiaEstrutura(): string {
    return escV4TurnoVirada() === 'A' ? escV4DiaVirada() : escMaisDias(escV4DiaVirada(), 1);
}

// Dias de transição (parâmetro v4_dia_transicao, separados por vírgula; vazio = sem transição).
function escV4DiasTransicao(): array {
    $v = escV4Param('v4_dia_transicao', escV4DiaVirada());
    return array_values(array_filter(array_map(static fn($x) => substr(trim($x), 0, 10), explode(',', $v)), 'strlen'));
}

// A estrutura antiga (times e parties) ainda vale agora? Depois da virada as telas dela ficam
// só para consulta (grupo Legado do menu).
function escLegadoAtivo(): bool {
    return time() < strtotime(escV4Param('v4_virada', '2026-11-01 05:00'));
}

// A semana tem algum dia na estrutura nova?
function escV4Ativo(string $seg): bool {
    return escMaisDias($seg, 6) >= escV4DiaVirada();
}

// Posição do núcleo no ciclo de 21 dias nesse dia.
function escV4IndiceCiclo(array $nucleo, string $ciclo, string $ancora, string $dia): int {
    $L = strlen($ciclo);
    $dias = (int) round((strtotime($dia) - strtotime($ancora)) / 86400);
    return ((($dias + (int) $nucleo['deslocamento']) % $L) + $L) % $L;
}

function escV4NucleoTrabalha(array $nucleo, string $ciclo, string $ancora, string $dia): bool {
    return $ciclo[escV4IndiceCiclo($nucleo, $ciclo, $ancora, $dia)] === '1';
}
