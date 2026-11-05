<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/motor_v4.php
// Motor da estrutura de 01/11/2026 (spec v4).
//
// Unidade → turno → 3 núcleos (OS + SRs) na rotação de 21 dias
// (P1 ter/sáb/dom · P2 seg/sex · P3 qua/qui): todo dia 2 núcleos de cada
// unidade e turno trabalham. Cada posto (vem do Capacity, foto em
// esc_posto) tem a vaga do DONO (núcleo dono, nos dias dele) e a de
// COBERTURA (outro núcleo, nos dias de folga do dono).
//
// Preenchimento de cada vaga, por dia, unidade e turno:
//   1. equipe fixa da vaga (esc_vaga_equipe), quem está mais abaixo da
//      própria fatia primeiro — a equipe é PREFERÊNCIA, não trava;
//   2. outra pessoa do mesmo núcleo, primeiro quem já atua nas páginas;
//   3. buffer: pessoa do outro núcleo em serviço no mesmo turno;
//   4. o que sobrar vira lacuna.
// Quem fica sem vaga no dia é o buffer de plantão do núcleo.
//
// A estrutura nova começa no parâmetro v4_virada (dia e hora): às 05:00 no
// turno A, às 21:00 no turno D (T6 do dia + T1 do dia seguinte). O que vem
// antes sai do scheduleday no CSV. Os dias de v4_dia_transicao (separados por
// vírgula) são montados com quem já está escalado no scheduleday, no turno em
// que está.
//
// Saída no mesmo formato do escMotorAlocar (v3), com posto_id e vaga.
// ===============================================

const ESC_V4_ORDEM_TIPO = ['titular' => 0, 'double' => 1, 'apoio' => 2, 'assistente' => 3];

function escV4Param(string $chave, string $padrao): string {
    static $p = null;
    if ($p === null) {
        $p = [];
        foreach (escQ('SELECT chave, valor FROM ' . escT('esc_parametro') . " WHERE grupo IN ('v4', 'merito')") as $l) {
            $p[$l['chave']] = $l['valor'];
        }
    }
    return (string) ($p[$chave] ?? $padrao);
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

// Nome da página para comparar fontes: sem diferença de espaços e maiúsculas ("Haven  Free" = "Haven Free").
function escV4ChavePagina(string $s): string {
    return mb_strtolower((string) preg_replace('~\s+~u', ' ', trim($s)));
}

// Página com cara de OFTV: "OFTV" no nome ou código terminado em t (ex.: "Thalia 147t").
function escV4Oftv(string $pagina): bool {
    return (bool) preg_match('~oftv|\b\d+t\b~i', $pagina);
}

// Configuração de cada página (esc_pagina_config, ES_57): AUTO, NORMAL ou DESCONSIDERAR.
// Sem a tabela (script ainda não rodou), tudo fica AUTO.
function escPaginaConfig(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    try {
        foreach (escQ('SELECT pagina, sla_modo FROM ' . escT('esc_pagina_config')) as $r) {
            $cache[escV4ChavePagina((string) $r['pagina'])] = (string) $r['sla_modo'];
        }
    } catch (Throwable $e) {
        // tabela ainda não criada (ES_57)
    }
    return $cache;
}

// Cor (sensibilidade) da página na tela Páginas (esc_pagina_config.sensibilidade, ES_85):
// VERDE, AMARELA ou VERMELHA; '' quando não tem. Regra P-02 (validada em 03/11).
function escCorDe(string $pagina): string {
    static $mapa = null;
    if ($mapa === null) {
        $mapa = [];
        try {
            foreach (escQ('SELECT pagina, sensibilidade FROM ' . escT('esc_pagina_config') . " WHERE sensibilidade IS NOT NULL AND sensibilidade <> ''") as $r) {
                $mapa[escV4ChavePagina((string) $r['pagina'])] = strtoupper(trim((string) $r['sensibilidade']));
            }
        } catch (Throwable $e) {
            // coluna ainda não criada (ES_85)
        }
    }
    return $mapa[escV4ChavePagina($pagina)] ?? '';
}

// Páginas que vendem em reais (BRL): as das plataformas de v4_plataforma_brl (padrão Privacy), pela
// plataforma da tela Páginas (esc_pagina_config.plataforma, ES_85) ou, sem ela, a do cadastro
// (5c_pages.pages.platform). Devolve [chave da página => true]. Só muda a exibição (R$ no lugar de US$).
function escPaginasBrl(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    $plats = array_values(array_filter(array_map('trim', explode(',', escV4Param('v4_plataforma_brl', 'Privacy')))));
    if (!$plats) {
        return $cache;
    }
    $definida = [];
    try {
        foreach (escQ('SELECT pagina, plataforma FROM ' . escT('esc_pagina_config') . " WHERE plataforma IS NOT NULL AND plataforma <> ''") as $r) {
            $definida[escV4ChavePagina((string) $r['pagina'])] = in_array((string) $r['plataforma'], $plats, true);
        }
    } catch (Throwable $e) {
        // coluna ainda não criada (ES_85)
    }
    try {
        foreach (escQ('SELECT name FROM `5c_pages`.`pages` WHERE platform IN (' . escIn($plats) . ')', $plats) as $r) {
            $definida[escV4ChavePagina((string) $r['name'])] ??= true;
        }
    } catch (Throwable $e) {
        appLog('warning', 'Moeda das páginas: cadastro indisponível', ['erro' => $e->getMessage()]);
    }
    return $cache = array_filter($definida);
}

require_once __DIR__ . '/restricoes_om.php';

// Plataforma de cada página (OnlyFans, Privacy, Fanvue, PlayBoy...): a da tela Páginas
// (esc_pagina_config.plataforma) ou, sem ela, a do cadastro (5c_pages.pages.platform).
// Devolve '' quando não se sabe (ex.: 5C Schedule). Pedido de 04/11: o SR não faz páginas de
// plataformas diferentes no mesmo turno (v4_uma_plataforma_turno, padrão 1).
function escPlataformaDe(string $pagina): string {
    static $mapa = null;
    if ($mapa === null) {
        $mapa = [];
        try {
            foreach (escQ('SELECT name, platform FROM `5c_pages`.`pages` WHERE platform IS NOT NULL AND platform <> \'\'') as $r) {
                $mapa[escV4ChavePagina((string) $r['name'])] = trim((string) $r['platform']);
            }
        } catch (Throwable $e) {
            appLog('warning', 'Plataforma das páginas: cadastro indisponível', ['erro' => $e->getMessage()]);
        }
        try {
            foreach (escQ('SELECT pagina, plataforma FROM ' . escT('esc_pagina_config') . " WHERE plataforma IS NOT NULL AND plataforma <> ''") as $r) {
                $mapa[escV4ChavePagina((string) $r['pagina'])] = trim((string) $r['plataforma']);
            }
        } catch (Throwable $e) {
            // coluna ainda não criada (ES_85)
        }
    }
    return $mapa[escV4ChavePagina($pagina)] ?? '';
}

// Tier da página no cadastro (5c_pages.pages.tier: A a F; '' quando não tem).
function escTierDe(string $pagina): string {
    static $mapa = null;
    if ($mapa === null) {
        $mapa = [];
        try {
            foreach (escQ('SELECT name, tier FROM `5c_pages`.`pages` WHERE tier IS NOT NULL AND tier <> \'\'') as $r) {
                $mapa[escV4ChavePagina((string) $r['name'])] = strtoupper(trim((string) $r['tier']));
            }
        } catch (Throwable $e) {
            appLog('warning', 'Tier das páginas: cadastro indisponível', ['erro' => $e->getMessage()]);
        }
    }
    return $mapa[escV4ChavePagina($pagina)] ?? '';
}

// Plataformas das páginas de um posto do motor ([plataforma => true]; vazio se nenhuma é conhecida).
function escPlataformasPosto(array $p): array {
    $out = [];
    foreach ($p['paginas'] ?? [] as $pg) {
        $pl = escPlataformaDe((string) $pg['pagina']);
        if ($pl !== '') {
            $out[$pl] = true;
        }
    }
    return $out;
}

// Símbolo da moeda da página: R$ (Privacy) ou US$.
function escMoedaPagina(string $pagina): string {
    return isset(escPaginasBrl()[escV4ChavePagina($pagina)]) ? 'R$' : 'US$';
}

// Alocação de cada página (esc_pagina_config.alocacao): EQUIPE (Active team, padrão);
// SOB_DEMANDA (On demand, ES_79: o Gerar não põe ninguém; quando pedem, a alocação é manual, travada
// na Grade); EXCLUSIVA (solo, ES_82: o SR do posto da página não acumula nem cobre outra página, e o
// posto dela não vai para o SR de outro posto). Devolve [chave da página] = alocação, só das que não
// são EQUIPE. Sem a coluna (ES_79 não rodou), nenhuma.
function escPaginaAlocacao(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    try {
        foreach (escQ('SELECT pagina, alocacao FROM ' . escT('esc_pagina_config') . " WHERE alocacao <> 'EQUIPE'") as $r) {
            $cache[escV4ChavePagina((string) $r['pagina'])] = (string) $r['alocacao'];
        }
    } catch (Throwable $e) {
        // coluna ainda não criada (ES_79)
    }
    return $cache;
}

// Páginas sob demanda: [chave da página] = true.
function escPaginaSobDemanda(): array {
    return array_map(static fn() => true, array_filter(escPaginaAlocacao(), static fn($a) => $a === 'SOB_DEMANDA'));
}

// Página desconsiderada (sem SLA a cumprir, carga zero no posto, fim da fila):
//   DESCONSIDERAR → sempre; NORMAL → nunca;
//   AUTO → só a de cara de OFTV que faturou menos que v4_trivial_receita_min nos 30 dias (US$ 8 mil).
// $total30 = faturamento de 30 dias da página (emulado, se ela tem menos dias de venda).
function escV4Desconsiderada(string $pagina, float $total30): bool {
    $modo = escPaginaConfig()[escV4ChavePagina($pagina)] ?? 'AUTO';
    if ($modo !== 'AUTO') {
        return $modo === 'DESCONSIDERAR';
    }
    return escV4Oftv($pagina) && $total30 < (float) escV4Param('v4_trivial_receita_min', '8000');
}

// Faturamento de referência do tier por mês (US$): o meio da faixa do tier (5c_crm.tiers).
function escV4ReceitaTier(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    try {
        foreach (escQ('SELECT letter, min_value, max_value FROM `5c_crm`.`tiers`') as $r) {
            $min = (float) $r['min_value'];
            $cache[strtoupper(trim((string) $r['letter']))] = $r['max_value'] !== null ? ($min + (float) $r['max_value']) / 2 : $min;
        }
    } catch (Throwable $e) {
        appLog('warning', 'Faixas de tier indisponíveis', ['erro' => $e->getMessage()]);
    }
    return $cache;
}

// Faturamento de cada página (US$, statement por bloco) nos últimos $n dias (v4_receita_dias, 30),
// com as vendas fechadas e as pendentes (a pendente leva uns 7 dias para fechar).
//   - $n dias de venda ou mais: a média do período ('base' = HISTORICO);
//   - menos dias (página nova): a média dos dias que ela tem, que emula os $n dias (EMULADO);
//   - nenhuma venda: o meio da faixa do tier da página, dividido igual entre os turnos (TIER).
//     Página com cara de OFTV sem venda fica com zero (SEM).
// $saida[pagina] = ['A' =>, 'C' =>, 'D' => (US$ por dia), 'total' => (US$ em $n dias), 'dias' =>, 'base' =>, 'tier' =>].
function escV4ReceitaPaginas(array $nomes, int $n): array {
    $de = [];
    foreach ($nomes as $nm) {
        $de[escV4ChavePagina((string) $nm)][] = (string) $nm;
    }
    if (!$de) {
        return [];
    }
    $n = max(1, $n);
    $soma = [];
    $ini = [];
    foreach (escQ("SELECT CREATOR_NAME AS pagina, MIN(Date) AS ini,
                          SUM(CASE WHEN Turno IN ('T2','T3') THEN US_AMOUNT ELSE 0 END) AS a,
                          SUM(CASE WHEN Turno IN ('T4','T5') THEN US_AMOUNT ELSE 0 END) AS c,
                          SUM(CASE WHEN Turno IN ('T6','T1') THEN US_AMOUNT ELSE 0 END) AS d
                     FROM `5c_wfm_db_mis`.`tab_statement_interval`
                    WHERE Date >= DATE_SUB(CURDATE(), INTERVAL {$n} DAY) AND Date < CURDATE() AND STATUS IN ('complete', 'pending')
                    GROUP BY CREATOR_NAME") as $r) {
        foreach ($de[escV4ChavePagina((string) $r['pagina'])] ?? [] as $nm) {
            $s = $soma[$nm] ?? ['A' => 0.0, 'C' => 0.0, 'D' => 0.0];
            $soma[$nm] = ['A' => $s['A'] + (float) $r['a'], 'C' => $s['C'] + (float) $r['c'], 'D' => $s['D'] + (float) $r['d']];
            $d = substr((string) $r['ini'], 0, 10);
            $ini[$nm] = isset($ini[$nm]) ? min($ini[$nm], $d) : $d;
        }
    }
    $tierDe = [];
    if (count($soma) < count($nomes)) {
        foreach (escQ('SELECT name, tier FROM `5c_pages`.`pages` WHERE active = 1') as $g) {
            $tierDe[escV4ChavePagina((string) $g['name'])] = strtoupper(trim((string) $g['tier']));
        }
    }
    $hoje = strtotime(date('Y-m-d'));
    $saida = [];
    foreach ($nomes as $nm) {
        $nm = (string) $nm;
        if (isset($soma[$nm])) {
            $dias = max(1, min($n, (int) round(($hoje - strtotime($ini[$nm])) / 86400)));
            $s = $soma[$nm];
            $saida[$nm] = ['A' => $s['A'] / $dias, 'C' => $s['C'] / $dias, 'D' => $s['D'] / $dias,
                'total' => array_sum($s) * $n / $dias, 'dias' => $dias, 'base' => $dias < $n ? 'EMULADO' : 'HISTORICO', 'tier' => null];
            continue;
        }
        $tier = $tierDe[escV4ChavePagina($nm)] ?? '';
        $mes = !escV4Oftv($nm) && $tier !== '' ? (escV4ReceitaTier()[$tier] ?? null) : null;
        if ($mes === null) {
            $saida[$nm] = ['A' => 0.0, 'C' => 0.0, 'D' => 0.0, 'total' => 0.0, 'dias' => 0, 'base' => 'SEM', 'tier' => $tier ?: null];
            continue;
        }
        $dia = $mes / 30;
        $saida[$nm] = ['A' => $dia / 3, 'C' => $dia / 3, 'D' => $dia / 3, 'total' => $dia * $n, 'dias' => 0, 'base' => 'TIER', 'tier' => $tier];
    }
    return $saida;
}

// ---------- leituras ----------

function escV4Carregar(string $dia): array {
    $vig = ' vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)';
    $modelo = escQ1('SELECT ciclo, max_seguidos FROM ' . escT('esc_modelo_escala') . " WHERE nome = '5x2 rotativo 21 dias'");
    if (!$modelo) {
        throw new RuntimeException(t('Falta o modelo "5x2 rotativo 21 dias". Peça ao admin para cadastrá-lo.'));
    }
    $nucleos = [];
    foreach (escQ('SELECT id, unidade, turno, numero, decurio_code, decurio_nome, deslocamento FROM ' . escT('esc_nucleo') . ' WHERE' . $vig, [$dia, $dia]) as $n) {
        $nucleos[(int) $n['id']] = $n;
    }
    $pessoas = [];
    foreach (escQ('SELECT UPPER(rep_code) AS rep, unidade, turno, nucleo_id, nivel, pode_assistente FROM ' . escT('esc_colaborador_config')
        . " WHERE funcao = 'EQUITE' AND nucleo_id IS NOT NULL AND" . $vig . ' ORDER BY vigente_desde', [$dia, $dia]) as $p) {
        $pessoas[$p['rep']] = ['rep' => $p['rep'], 'unidade' => (int) $p['unidade'], 'turno' => $p['turno'],
            'nucleo' => (int) $p['nucleo_id'], 'nivel' => (int) $p['nivel'], 'pode_assistente' => (int) ($p['pode_assistente'] ?? 1) === 1];
    }
    $postos = [];
    foreach (escQ('SELECT id, unidade, carteira, turno, seq, tipo, pagina_principal, carga, capacidade, nucleo_dono_id, nucleo_cobertura_id FROM '
        . escT('esc_posto') . ' WHERE' . $vig, [$dia, $dia]) as $p) {
        $postos[(int) $p['id']] = $p + ['paginas' => []];
    }
    $nomes = [];
    $cargaPagina = []; // [posto][página] = fãs/h
    // Página sob demanda (On demand, ES_79) sai do posto com a carga dela; posto que fica sem
    // página nenhuma não abre vaga. A alocação dela é manual (célula travada na Grade).
    $sobDemanda = escPaginaSobDemanda();
    $tiraDemanda = [];
    if ($postos) {
        foreach (escQ('SELECT posto_id, pagina, page_id, apoio, fracao, fans_h FROM ' . escT('esc_posto_pagina') . ' WHERE posto_id IN (' . escIn(array_keys($postos)) . ')', array_keys($postos)) as $pp) {
            if (isset($sobDemanda[escV4ChavePagina((string) $pp['pagina'])])) {
                $tiraDemanda[(int) $pp['posto_id']] = ($tiraDemanda[(int) $pp['posto_id']] ?? 0) + (float) $pp['fans_h'];
                continue;
            }
            $postos[(int) $pp['posto_id']]['paginas'][] = ['pagina' => $pp['pagina'], 'page_id' => (int) $pp['page_id'], 'apoio' => (int) $pp['apoio'] === 1,
                'fracao' => (float) $pp['fracao'], 'fans_h' => (float) $pp['fans_h']];
            $nomes[$pp['pagina']] = true;
            $cargaPagina[(int) $pp['posto_id']][$pp['pagina']] = ($cargaPagina[(int) $pp['posto_id']][$pp['pagina']] ?? 0) + (float) $pp['fans_h'];
        }
    }
    foreach ($tiraDemanda as $pid => $f) {
        if (!$postos[$pid]['paginas']) {
            unset($postos[$pid]);
            continue;
        }
        $postos[$pid]['carga'] = max(0.0, round((float) $postos[$pid]['carga'] - $f, 1));
    }
    // Posto com página exclusiva (solo, ES_82): o SR dele fica só nele (passos 6 e 7 do Gerar).
    $alocPag = escPaginaAlocacao();
    foreach ($postos as $pid => $p) {
        $postos[$pid]['exclusivo'] = (bool) array_filter($p['paginas'], static fn($pg) => ($alocPag[escV4ChavePagina((string) $pg['pagina'])] ?? '') === 'EXCLUSIVA');
    }
    // Faturamento médio por dia de cada página e turno (US$, statement por bloco): ordem de preenchimento.
    $receita = [];
    if ($nomes) {
        // 30 dias; a página com menos dias de venda é emulada e a sem venda usa o tier.
        $receita = escV4ReceitaPaginas(array_keys($nomes), (int) escV4Param('v4_receita_dias', '30'));
    }
    // Páginas desconsideradas (OFTV que não vende ou marcada no site): sem SLA, carga zero no posto.
    $desconsiderada = [];
    foreach (array_keys($nomes) as $pg) {
        if (escV4Desconsiderada((string) $pg, (float) ($receita[$pg]['total'] ?? 0))) {
            $desconsiderada[$pg] = true;
        }
    }
    foreach ($cargaPagina as $pid => $porPagina) {
        $tira = 0.0;
        foreach ($porPagina as $pg => $f) {
            $tira += isset($desconsiderada[$pg]) ? $f : 0.0;
        }
        if ($tira > 0) {
            $postos[$pid]['carga'] = max(0.0, round((float) $postos[$pid]['carga'] - $tira, 1));
        }
    }
    // Modelo de cada página (Capacity): o over só junta páginas dentro da Política.
    $modeloPag = [];
    if ($nomes) {
        foreach (escQ('SELECT pagina, modelo FROM `5c_planning`.`fc_pagina_carteira` WHERE vigente_ate IS NULL AND pagina IN (' . escIn(array_keys($nomes)) . ')', array_keys($nomes)) as $r) {
            $modeloPag[$r['pagina']] = $r['modelo'];
        }
    }
    // Agência de cada página, a mesma da distribuição dos postos (01/11): a do Infloww
    // (5c_planning.fc_pagina_infloww: 5C Company, Fatbear, Verge); fora do Infloww, o cliente do site
    // (5c_pages.pages.client), com Noah, Independent e Lolly Agency contando como 5C Company. Sem
    // nenhuma, vale a de outra página da mesma modelo; sem essa, a página fica sozinha (a própria modelo).
    // A sobrecarga do passo 7 só junta páginas da mesma agência.
    $agenciaPag = [];
    if ($nomes) {
        $mapaCliente = ['noah' => '5C Company', 'independent' => '5C Company', 'lolly agency' => '5C Company', 'fat bear' => 'Fatbear'];
        $porChave = [];
        foreach (escQ('SELECT name, client FROM `5c_pages`.`pages` ORDER BY active') as $r) {
            $c = trim((string) $r['client']);
            if ($c !== '') {
                $porChave[escV4ChavePagina((string) $r['name'])] = $mapaCliente[mb_strtolower($c)] ?? $c;
            }
        }
        foreach (escQ('SELECT pagina, agencia FROM `5c_planning`.`fc_pagina_infloww` WHERE LENGTH(TRIM(agencia)) > 0') as $r) {
            $porChave[escV4ChavePagina((string) $r['pagina'])] = trim((string) $r['agencia']);
        }
        $agModelo = [];
        foreach (array_keys($nomes) as $pg) {
            if (isset($porChave[escV4ChavePagina((string) $pg)])) {
                $agModelo[$modeloPag[$pg] ?? $pg] ??= $porChave[escV4ChavePagina((string) $pg)];
            }
        }
        foreach (array_keys($nomes) as $pg) {
            $m = $modeloPag[$pg] ?? $pg;
            $agenciaPag[$pg] = $porChave[escV4ChavePagina((string) $pg)] ?? $agModelo[$m] ?? ('modelo:' . $m);
        }
    }
    // Faturamento da modelo por turno: a soma das páginas dela (VIP, Free, OFTV...). As páginas da
    // mesma modelo entram juntas na fila: falhar na Free ou na secundária põe a parceria em risco.
    $receitaModelo = [];
    foreach ($receita as $pg => $r) {
        $m = $modeloPag[$pg] ?? $pg;
        foreach (['A', 'C', 'D', 'total'] as $tu) {
            $receitaModelo[$m][$tu] = ($receitaModelo[$m][$tu] ?? 0) + (float) ($r[$tu] ?? 0);
        }
    }
    // Posto só de página desconsiderada (ex.: a OFTV que ficou com posto próprio numa troca de
    // unidade) não abre vaga: a página entra de apoio no posto da página principal da mesma modelo,
    // na mesma unidade e turno, sem somar carga (o SR desse posto atende as duas). Sem posto da
    // modelo ali, fica como está, no fim da fila.
    foreach ($postos as $pid => $p) {
        if (!$p['paginas'] || array_filter($p['paginas'], static fn($pg) => !isset($desconsiderada[$pg['pagina']]))) {
            continue;
        }
        $modelos = array_flip(array_map(static fn($pg) => $modeloPag[$pg['pagina']] ?? $pg['pagina'], $p['paginas']));
        $alvo = null;
        $kAlvo = null;
        foreach ($postos as $qid => $q) {
            if ($qid === $pid || (int) $q['unidade'] !== (int) $p['unidade'] || $q['turno'] !== $p['turno'] || in_array($q['tipo'], ['apoio', 'assistente'], true)) {
                continue;
            }
            foreach ($q['paginas'] as $pg) {
                if (!$pg['apoio'] && !isset($desconsiderada[$pg['pagina']]) && isset($modelos[$modeloPag[$pg['pagina']] ?? $pg['pagina']])) {
                    $k = [(float) ($receita[$pg['pagina']]['total'] ?? 0), $q['tipo'] === 'titular' ? 1 : 0, -$qid];
                    if ($kAlvo === null || $k > $kAlvo) {
                        $alvo = $qid;
                        $kAlvo = $k;
                    }
                }
            }
        }
        if ($alvo === null) {
            continue;
        }
        foreach ($p['paginas'] as $pg) {
            $postos[$alvo]['paginas'][] = ['pagina' => $pg['pagina'], 'page_id' => $pg['page_id'], 'apoio' => true, 'fracao' => 0.0];
        }
        unset($postos[$pid]);
    }
    $pagOverlap = escQ1('SELECT id, name FROM `5c_pages`.`pages` WHERE name = ? ORDER BY active DESC, id LIMIT 1', [escV4Param('v4_overlap_pagina', '5C Schedule')]);

    // Melhores SRs nas páginas das modelos que mais faturam: modelo com v4_melhores_receita_min
    // (US$ 100 mil) ou mais em 30 dias; página desconsiderada nunca entra. Venda por hora do SR
    // na página (cap_rep_hora, horas só nela, últimos v4_receita_dias dias) e a geral dele.
    $minMelhores = (float) str_replace(',', '.', escV4Param('v4_melhores_receita_min', '100000'));
    $melhores = ['paginas' => [], 'pagina' => [], 'geral' => []];
    foreach (array_keys($nomes) as $pg) {
        $m = $modeloPag[$pg] ?? $pg;
        if (!isset($desconsiderada[$pg]) && (float) ($receitaModelo[$m]['total'] ?? 0) >= $minMelhores) {
            $melhores['paginas'][$pg] = (float) $receitaModelo[$m]['total'];
        }
    }
    if ($melhores['paginas'] && $pessoas) {
        $prio = array_keys($melhores['paginas']);
        $codDe = [];
        foreach (escQ('SELECT UPPER(rep_code) AS rep, code_infloww FROM `5c_wfm_db_mis`.`vw_rep_estrutura_atual` WHERE code_infloww IS NOT NULL AND rep_code IN (' . escIn(array_keys($pessoas)) . ')', array_keys($pessoas)) as $r) {
            $codDe[$r['code_infloww']] = $r['rep'];
        }
        $n = max(1, (int) escV4Param('v4_receita_dias', '30'));
        if ($codDe) {
            foreach (escQ("SELECT SUBSTRING_INDEX(rep, ' ', 1) AS cod, SUM(valor_comprado) / COUNT(*) AS usd_h
                             FROM `5c_capacity`.`cap_rep_hora` WHERE dia >= DATE_SUB(CURDATE(), INTERVAL {$n} DAY) GROUP BY cod") as $r) {
                if (isset($codDe[$r['cod']])) {
                    $melhores['geral'][$codDe[$r['cod']]] = (float) $r['usd_h'];
                }
            }
            if ($prio) {
                foreach (escQ("SELECT SUBSTRING_INDEX(rep, ' ', 1) AS cod, paginas AS pagina, SUM(valor_comprado) / COUNT(*) AS usd_h
                                 FROM `5c_capacity`.`cap_rep_hora`
                                WHERE dia >= DATE_SUB(CURDATE(), INTERVAL {$n} DAY) AND n_paginas = 1 AND paginas IN (" . escIn($prio) . ')
                                GROUP BY cod, paginas HAVING COUNT(*) >= 3', $prio) as $r) {
                    if (isset($codDe[$r['cod']])) {
                        $melhores['pagina'][$r['pagina']][$codDe[$r['cod']]] = (float) $r['usd_h'];
                    }
                }
            }
        }
    }
    // Quem bateu a meta na página: atingimento da meta 5CReps nela nos últimos 30 dias (a parte de cada
    // um, como no PDF da Grade). Com v4_melhores_meta_min (65%) ou mais, passa na frente de quem nunca fez a página.
    $melhores['meta'] = [];
    $melhores['meta_min'] = (float) str_replace(',', '.', escV4Param('v4_melhores_meta_min', '0.65'));
    if ($melhores['paginas'] && $pessoas && function_exists('escMeritoAtingimentoPaginas')) {
        $periodo = [[date('Y-m-d', strtotime('-30 day')), date('Y-m-d', strtotime('-1 day'))]];
        foreach (escMeritoAtingimentoPaginas(array_keys($melhores['paginas']), array_keys($pessoas), $periodo) as $pg => $porRep) {
            foreach ($porRep as $rep => $porPeriodo) {
                [$real, $meta] = ($porPeriodo[0] ?? []) + [0.0, 0.0];
                if ($meta > 0) {
                    $melhores['meta'][$pg][strtoupper((string) $rep)] = $real / $meta;
                }
            }
        }
    }
    // Página prioritária (A-02): modelo com v4_pagina_prioritaria_min (US$ 100 mil) ou mais em 30 dias. O SR
    // Abaixo só fica nela sem outro disponível e não sobe para ela no remanejamento.
    $minPrio = (float) str_replace(',', '.', escV4Param('v4_pagina_prioritaria_min', '100000'));
    $prioritaria = [];
    foreach (array_keys($nomes) as $pg) {
        if (!isset($desconsiderada[$pg]) && (float) ($receitaModelo[$modeloPag[$pg] ?? $pg]['total'] ?? 0) >= $minPrio) {
            $prioritaria[$pg] = true;
        }
    }
    $mer = function_exists('escMeritoCarregar') ? escMeritoMotor(escMeritoCarregar()) : ['nota' => [], 'faixa' => [], 'anos' => []];
    $equipes = [];
    foreach (escQ('SELECT posto_id, vaga, UPPER(rep_code) AS rep, fatia FROM ' . escT('esc_vaga_equipe') . ' WHERE' . $vig, [$dia, $dia]) as $e) {
        $equipes[(int) $e['posto_id']][$e['vaga']][$e['rep']] = (float) $e['fatia'];
    }
    // Equipe fixa da página (esc_pagina_prioridade: Privacy, Molly): quem é da equipe e está no turno
    // fica com a página antes de tudo. esc_pagina_assistente (ES_65): papel ASSISTENTE = o SR entra na
    // página só como assistente, nunca no posto titular dela nem como regular; FORA (ES_67) = não entra nela.
    // Vale por dia: a linha pode cobrir só alguns dias da semana (ex.: quem cobre um SR que saiu).
    $prioridade = [];
    // Papel na equipe (ES_114, pedido do OM da U3): MAIN (titular da página no turno) entra antes, COVER
    // (cobre o titular) depois, EQUIPE (os demais) por último.
    $comPapelEq = (bool) escQ1("SELECT 1 AS ok FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'esc_pagina_prioridade' AND COLUMN_NAME = 'papel'", [ESC_SCHEMA]);
    foreach (escQ('SELECT pagina, rep_codes, vigente_desde, vigente_ate' . ($comPapelEq ? ', papel' : '') . ' FROM ' . escT('esc_pagina_prioridade')
        . ' WHERE vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)', [$dia, escMaisDias($dia, -6)]) as $l) {
        foreach (array_filter(array_map('trim', explode(',', strtoupper((string) $l['rep_codes'])))) as $rep) {
            $prioridade[$l['pagina']][] = ['rep' => $rep, 'de' => substr((string) $l['vigente_desde'], 0, 10),
                'ate' => $l['vigente_ate'] !== null ? substr((string) $l['vigente_ate'], 0, 10) : null,
                'papel' => (string) ($l['papel'] ?? 'EQUIPE') ?: 'EQUIPE'];
        }
    }
    // Divisão do SR entre páginas (esc_sr_divisao, ES_98): [rep] = [['pagina', 'parte', 'de', 'ate'], ...].
    $divisao = [];
    if (escQ1("SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'esc_sr_divisao'", [ESC_SCHEMA])) {
        foreach (escQ('SELECT UPPER(rep_code) AS rep, pagina, parte, vigente_desde, vigente_ate FROM ' . escT('esc_sr_divisao')
            . ' WHERE vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)', [$dia, escMaisDias($dia, -6)]) as $l) {
            $divisao[$l['rep']][] = ['pagina' => (string) $l['pagina'], 'parte' => max(0.0, min(1.0, (float) $l['parte'])),
                'de' => substr((string) $l['vigente_desde'], 0, 10), 'ate' => $l['vigente_ate'] !== null ? substr((string) $l['vigente_ate'], 0, 10) : null];
        }
    }
    $soAssist = [];
    $foraPag = [];
    if (escQ1("SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'esc_pagina_assistente'", [ESC_SCHEMA])) {
        $comPapel = (bool) escQ1("SELECT 1 AS ok FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'esc_pagina_assistente' AND COLUMN_NAME = 'papel'", [ESC_SCHEMA]);
        foreach (escQ('SELECT pagina, UPPER(rep_code) AS rep' . ($comPapel ? ', papel' : '') . ' FROM ' . escT('esc_pagina_assistente') . ' WHERE' . $vig, [$dia, $dia]) as $l) {
            if (($l['papel'] ?? 'ASSISTENTE') === 'FORA') {
                $foraPag[$l['rep']][$l['pagina']] = true;
            } else {
                $soAssist[$l['rep']][$l['pagina']] = true;
            }
        }
    }
    // Plataformas só com SR brasileiro (v4_plataforma_so_brasil, padrão Privacy; pedido de 02/11): quem
    // tem outra nacionalidade no headcount fica FORA das páginas ativas delas (5c_pages.platform).
    $soBrasil = array_values(array_filter(array_map('trim', explode(',', escV4Param('v4_plataforma_so_brasil', 'Privacy')))));
    if ($soBrasil && $pessoas) {
        $pagsBrasil = array_column(escQ('SELECT name FROM `5c_pages`.`pages` WHERE active = 1 AND platform IN (' . escIn($soBrasil) . ')', $soBrasil), 'name');
        if ($pagsBrasil) {
            foreach (escQ('SELECT DISTINCT UPPER(code) AS rep FROM `5c_headcount`.`headcount` WHERE code IN (' . escIn(array_keys($pessoas)) . ") AND nacionality <> '' AND nacionality <> 'Brazil'", array_keys($pessoas)) as $h) {
                foreach ($pagsBrasil as $pg) {
                    $foraPag[$h['rep']][$pg] = true;
                }
            }
        }
    }
    // Quem já conhece cada página: dias escalados nela no 5C Schedule (menos troca de gente).
    $historico = [];
    if ($pessoas && $nomes) {
        $hd = max(7, (int) escV4Param('v4_historico_dias', '56'));
        foreach (escQ('SELECT UPPER(codigo) AS rep, modeloNome AS pagina, COUNT(DISTINCT LEFT(shiftDateAndHour, 10)) AS dias
                         FROM `5c_schedule`.`scheduleday`
                        WHERE codigo IN (' . escIn(array_keys($pessoas)) . ') AND shiftDateAndHour >= ? AND shiftDateAndHour < ?
                        GROUP BY UPPER(codigo), modeloNome', array_merge(array_keys($pessoas), [escMaisDias($dia, -$hd - 7) . 'T00:00:00', $dia . 'T00:00:00'])) as $r) {
            if (isset($nomes[$r['pagina']])) {
                $historico[$r['rep']][$r['pagina']] = (int) $r['dias'];
            }
        }
    }
    // P-02: quem conhece a página = v4_cor_conhece_dias (7) dias ou mais nela nos últimos v4_cor_janela_dias (90).
    $conheceCor = [];
    if ($pessoas && $nomes) {
        $jan = max(7, (int) escV4Param('v4_cor_janela_dias', '90'));
        $minDias = max(1, (int) escV4Param('v4_cor_conhece_dias', '7'));
        foreach (escQ('SELECT UPPER(codigo) AS rep, modeloNome AS pagina, COUNT(DISTINCT LEFT(shiftDateAndHour, 10)) AS dias
                         FROM `5c_schedule`.`scheduleday`
                        WHERE codigo IN (' . escIn(array_keys($pessoas)) . ') AND shiftDateAndHour >= ? AND shiftDateAndHour < ?
                        GROUP BY UPPER(codigo), modeloNome', array_merge(array_keys($pessoas), [escMaisDias($dia, -$jan - 7) . 'T00:00:00', $dia . 'T00:00:00'])) as $r) {
            if (isset($nomes[$r['pagina']]) && (int) $r['dias'] >= $minDias) {
                $conheceCor[$r['rep']][$r['pagina']] = true;
            }
        }
    }
    $overlap = escV4Param('v4_overlap_posicao_ciclo', '');
    return ['modelo' => $modelo, 'conhece_cor' => $conheceCor, 'nucleos' => $nucleos, 'pessoas' => $pessoas, 'postos' => $postos, 'equipes' => $equipes,
        'historico' => $historico, 'merito' => $mer['nota'], 'faixa' => $mer['faixa'], 'anos' => $mer['anos'], 'prioritaria' => $prioritaria,
        'ancora' => escV4Param('v4_rotacao_ancora', '2026-11-02'),
        'overlap_posicoes' => array_map('intval', array_filter(array_map('trim', explode(',', $overlap)), 'strlen')),
        'overlap_pagina' => $pagOverlap ? ['page_id' => (int) $pagOverlap['id'], 'pagina' => $pagOverlap['name']] : null,
        'receita' => $receita, 'receita_modelo' => $receitaModelo, 'desconsiderada' => $desconsiderada, 'modelo_pagina' => $modeloPag, 'agencia_pagina' => $agenciaPag,
        'over_max' => (float) str_replace(',', '.', escV4Param('v4_over_capacity_max', '0.30')),
        'melhores' => $melhores, 'prioridade' => $prioridade, 'divisao' => $divisao, 'so_assist' => $soAssist, 'fora' => $foraPag] + (function_exists('escCasaisMotor') ? escCasaisMotor($dia) : []);
}

// Quem atuou por último em cada posto no plano atual da semana anterior: a semana nova
// começa com a mesma pessoa no posto (menos troca de página de uma semana para a outra).
function escV4UltimoDoPlanoAnterior(string $seg): array {
    $ant = escPlanoPublicado(escMaisDias($seg, -7)) ?? escPlanoAtual(escMaisDias($seg, -7));
    if (!$ant) {
        return [];
    }
    $ultimo = [];
    foreach (escQ("SELECT posto_id, UPPER(rep_code) AS rep FROM " . escT('esc_plano_alocacao') . "
                    WHERE plano_id = ? AND posto_id IS NOT NULL AND rep_code IS NOT NULL
                      AND origem NOT IN ('OVERLAP','BUFFER','SOBRECARGA') ORDER BY dia, bloco", [(int) $ant['id']]) as $r) {
        $ultimo[(int) $r['posto_id']] = $r['rep'];
    }
    return $ultimo;
}

// Capacidade de um SR com as páginas de 2 postos juntas, pela Política do Capacity:
// mesma modelo até 3 páginas (35 × (1 − 10% × (n − 1))); modelos diferentes até 2 (28).
// Página desconsiderada não conta (não tem SLA a cumprir). null = a Política não deixa juntar.
function escV4CapacidadeJunta(array $a, array $b, array $modeloPag, array $desconsiderada = []): ?float {
    $pags = [];
    foreach (array_merge($a['paginas'], $b['paginas']) as $pg) {
        if (!isset($desconsiderada[$pg['pagina']])) {
            $pags[$pg['pagina']] = $modeloPag[$pg['pagina']] ?? ('?' . $pg['pagina']);
        }
    }
    $n = count($pags);
    if ($n === 0) {
        return 35.0;
    }
    if (count(array_unique($pags)) === 1 && $n <= 3) {
        return 35.0 * (1 - 0.10 * ($n - 1));
    }
    return $n <= 2 ? 28.0 : null;
}

// Quem fecha o turno D às 05:00 de $dia (T1): do plano da semana anterior; sem
// ele, o T1 postado ou, se não houver, quem está no T6 postado da véspera.
function escV4FimTurnoD(string $dia): array {
    $segAnt = segundaDaSemana(escMaisDias($dia, -1));
    $ant = escPlanoPublicado($segAnt) ?? escPlanoAtual($segAnt);
    if ($ant) {
        $l = escQ('SELECT DISTINCT UPPER(rep_code) AS code FROM ' . escT('esc_plano_alocacao') . " WHERE plano_id = ? AND dia = ? AND bloco = 'T1' AND rep_code IS NOT NULL", [(int) $ant['id'], $dia]);
        if ($l) {
            return array_column($l, 'code');
        }
    }
    foreach ([$dia . 'T01:00:00', escMaisDias($dia, -1) . 'T21:00:00'] as $slot) {
        $l = escQ('SELECT DISTINCT UPPER(codigo) AS code FROM `5c_schedule`.`scheduleday` WHERE shiftDateAndHour = ? AND modeloId <> 3', [$slot]);
        if ($l) {
            return array_column($l, 'code');
        }
    }
    return [];
}

// Quem já está escalado no dia da virada (scheduleday), por turno: T2/T3 → A, T4/T5 → C, T6 → D.
// Só SRs da estrutura nova entram (quem vai virar OS, OC ou OM fica de fora).
function escV4PresentesTransicao(string $dia): array {
    $linhas = escQ("SELECT UPPER(codigo) AS code, SUBSTRING(shiftDateAndHour, 12, 2) AS hora
                      FROM `5c_schedule`.`scheduleday`
                     WHERE shiftDateAndHour >= ? AND shiftDateAndHour < ? AND modeloId <> 3
                     GROUP BY code, hora", [$dia . 'T05:00:00', escMaisDias($dia, 1) . 'T01:00:00']);
    $mapa = [];
    foreach ($linhas as $l) {
        $t = ['05' => 'A', '09' => 'A', '13' => 'C', '17' => 'C', '21' => 'D'][$l['hora']] ?? null;
        if ($t) {
            $mapa[$l['code']][$t] = true;
        }
    }
    return $mapa;
}

// Dia de Overlap de cada SR (posição do ciclo): os SRs de cada núcleo (por código) se revezam
// entre as posições do parâmetro. Folga juntos (ES_45): num par no mesmo ponto do ciclo, o SR
// fica com o dia de Overlap do outro (o do SR de menor código; com OC, OS ou OA, o da liderança),
// para os dois folgarem nos mesmos dias. $pares = [[rep_a, rep_b]];
// $lider[rep] = ['deslocamento' => n, 'overlap' => posição] de quem do par é da liderança.
function escV4OverlapDe(array $pessoas, array $posicoes, array $nucleos, array $pares = [], array $lider = []): array {
    $de = [];
    if (!$posicoes) {
        return $de;
    }
    $porNucleo = [];
    foreach ($pessoas as $rep => $p) {
        $porNucleo[$p['nucleo']][] = (string) $rep;
    }
    foreach ($porNucleo as $reps) {
        sort($reps);
        foreach ($reps as $i => $rep) {
            $de[$rep] = $posicoes[$i % count($posicoes)];
        }
    }
    $desl = static fn(string $r): ?int => isset($pessoas[$r]) ? (int) ($nucleos[$pessoas[$r]['nucleo']]['deslocamento'] ?? -1)
        : (isset($lider[$r]['deslocamento']) ? (int) $lider[$r]['deslocamento'] : null);
    foreach ($pares as [$a, $b]) {
        $a = strtoupper((string) $a);
        $b = strtoupper((string) $b);
        if ($desl($a) === null || $desl($a) !== $desl($b)) {
            continue;
        }
        if (isset($pessoas[$a], $pessoas[$b])) {
            [$x, $y] = strcmp($a, $b) <= 0 ? [$a, $b] : [$b, $a];
            $de[$y] = $de[$x];
        } elseif (isset($pessoas[$a]) || isset($pessoas[$b])) {
            $sr = isset($pessoas[$a]) ? $a : $b;
            $li = $sr === $a ? $b : $a;
            if (isset($lider[$li]['overlap'])) {
                $de[$sr] = (int) $lider[$li]['overlap'];
            }
        }
    }
    return $de;
}

// ---------- alocação ----------

function escMotorAlocarV4(array $in): array {
    $dias = $in['dias'];
    $est = $in['est'];
    $indisp = $in['indisp'];
    $virada = $in['virada'];
    $turnoVirada = $in['turno_virada'] ?? 'A';
    $diasTransicao = (array) ($in['transicao'] ?? $virada); // [] ou '' = sem dia de transição
    // Páginas no treinamento: [dia][pid] = ['total' => o posto todo é do treinamento, 'paginas' => [pagina => motivo]].
    // Posto todo: ninguém da escala normal vai para ele (a pessoa fica livre e cobre lacuna ou vira buffer).
    // Só algumas páginas do posto: a pessoa fica com as outras e a do treinamento sai como TREINO.
    $treinoCobre = $in['treino_cobre'] ?? [];
    // Na transição também remaneja por faturamento e acumula (pedido de 30/10; 0 desliga).
    $remanejaTransicao = escV4Param('v4_remanejar_transicao', '1') === '1';
    // Mérito no Gerar (1.19.0, regras A-01 a A-04 de contexto/regras_motor_alocacao.md; 0 = regra da 1.18):
    // A-01 passo 0 por meta na página, faixa e nota; A-02 o Abaixo só fica em página prioritária sem outro;
    // A-03 a faixa primeiro no acúmulo e na sobrecarga; A-04 sobe o SR de melhor faixa no remanejamento.
    $faixaPasso0 = escV4Param('v4_merito_faixa_passo0', '1') === '1';
    $abaixoForaTopo = escV4Param('v4_merito_abaixo_fora_topo', '1') === '1';
    $faixaAcumulo = escV4Param('v4_merito_faixa_acumulo', '1') === '1';
    $faixaRemanejar = escV4Param('v4_merito_faixa_remanejar', '1') === '1';
    // SR de nível 3 ou mais não trabalha como assistente: posto de assistente ou de apoio só
    // recebe SR até este nível e com "pode ser assistente" no cadastro (em todas as etapas,
    // inclusive a equipe fixa).
    $nivelMaxAssist = (int) escV4Param('v4_nivel_max_assistente', '2');
    // Presentes por dia de transição; o formato antigo (um dia só) continua valendo.
    $presentesDia = $in['presentes_por_dia'] ?? [(string) ($diasTransicao[0] ?? '') => $in['presentes'] ?? []];
    $ciclo = $est['modelo']['ciclo'];
    $nucleos = $est['nucleos'];
    $pessoas = $est['pessoas'];
    $equipes = $est['equipes'];
    // Dia de treinamento do 5x2 (o 15º dia de trabalho do ciclo): o SR entra como Overlap na
    // página 5C Schedule, fora da conta das vagas, ou cobre lacuna. null = sem Overlap.
    // Cada SR tem o próprio dia de Overlap: os SRs de cada núcleo (por código) se revezam
    // entre as posições de folga do ciclo do parâmetro, para não cair todo mundo no mesmo dia.
    $posicoes = $est['overlap_posicoes'] ?? (isset($est['overlap_idx']) ? [(int) $est['overlap_idx']] : []);
    $overlapDe = escV4OverlapDe($pessoas, $posicoes, $nucleos, $est['pares'] ?? [], $est['pares_lider'] ?? []);

    // Páginas que cada pessoa conhece (em qualquer equipe) — afinidade.
    $conhece = [];
    foreach ($equipes as $pid => $vagas) {
        foreach ($vagas as $reps) {
            foreach ($reps as $rep => $f) {
                foreach ($est['postos'][$pid]['paginas'] ?? [] as $pg) {
                    $conhece[$rep][$pg['pagina']] = true;
                }
            }
        }
    }
    // Prioridade de cada posto no turno dele: o faturamento da modelo (todas as páginas dela
    // somadas); com páginas de modelos diferentes, a de maior faturamento. A página desconsiderada
    // não conta. $receitaPropria (Σ faturamento da página × fração do posto) desempata dentro da modelo.
    $receitaPosto = [];
    $receitaPropria = [];
    foreach ($est['postos'] as $pid => $p) {
        $receitaPosto[$pid] = 0.0;
        $receitaPropria[$pid] = 0.0;
        $soDesc = (bool) $p['paginas'];
        foreach ($p['paginas'] as $pg) {
            $receitaPropria[$pid] += ($est['receita'][$pg['pagina']][$p['turno']] ?? 0) * ($pg['fracao'] ?? (1 / max(1, count($p['paginas']))));
            if (isset($est['desconsiderada'][$pg['pagina']])) {
                continue;
            }
            $soDesc = false;
            $m = $est['modelo_pagina'][$pg['pagina']] ?? $pg['pagina'];
            $receitaPosto[$pid] = max($receitaPosto[$pid], (float) ($est['receita_modelo'][$m][$p['turno']] ?? ($est['receita'][$pg['pagina']][$p['turno']] ?? 0)));
        }
        // Posto só de página desconsiderada: prioridade mínima, abaixo de qualquer outro (também dos que ainda não
        // faturaram): é o primeiro a ceder gente e o último a receber.
        if ($soDesc) {
            $receitaPosto[$pid] = -1.0;
        }
    }
    // Postos por unidade e turno, na ordem de preenchimento: da modelo que mais fatura para a que
    // menos; dentro da modelo, a página que mais fatura; no empate, titular antes de double, apoio
    // e assistente; depois a maior carga.
    $postosUT = [];
    foreach ($est['postos'] as $pid => $p) {
        $postosUT[(int) $p['unidade']][$p['turno']][] = $pid;
    }
    $ordemPosto = static fn($pid) => [-round($receitaPosto[$pid], 2), -round($receitaPropria[$pid], 2),
        ESC_V4_ORDEM_TIPO[$est['postos'][$pid]['tipo']] ?? 9, -(float) $est['postos'][$pid]['carga'], $pid];
    foreach ($postosUT as &$porTurno) {
        foreach ($porTurno as &$lista) {
            usort($lista, static fn($a, $b) => $ordemPosto($a) <=> $ordemPosto($b));
        }
        unset($lista);
    }
    unset($porTurno);
    $nucUT = [];
    foreach ($nucleos as $nid => $n) {
        $nucUT[(int) $n['unidade']][$n['turno']][] = $nid;
    }

    $travadasPorCelula = [];
    foreach ($in['travadas'] ?? [] as $t) {
        $travadasPorCelula[$t['dia'] . '|' . $t['bloco'] . '|' . $t['page_id']][] = $t;
    }

    $alocacoes = [];
    $folgas = [];
    $ociosos = [];
    $resumoOrigem = [];
    $lacunasUT = [];
    $vagasDia = 0;
    $vagasSemNinguem = 0;
    $feito = [];     // [rep][posto|vaga] = dias feitos na semana
    $divFeito = [];  // divisão (ES_98): [rep]['_n'] = dias disponíveis; [rep][página ou ''] = dias feitos
    $diasVaga = [];  // [posto|vaga] = dias em que a vaga abriu
    $ultimo = $in['ultimo'] ?? []; // [posto] = quem atuou no posto da última vez (começa com a semana anterior)
    $trabalhou = []; // [rep][dia] = true (para anomalias; o Overlap não conta)
    $overlaps = [];  // [rep, dia, turno] na página 5C Schedule
    $sobrecargas = 0; // vagas-dia cobertas acima da capacidade (até over_max)

    // Folga de emenda: quem já vem de max_seguidos dias seguidos (realizado antes do plano
    // + dias do plano) folga no dia, para não passar do limite do modelo. Na rotação de 21
    // dias isso não acontece; acontece na virada, com quem vinha sem folga na escala antiga.
    $limite = (int) $est['modelo']['max_seguidos'];
    // Todos os dias (v4_todos_os_dias, códigos; temporário, até ter mais gente): o SR trabalha em
    // todos os dias do turno dele, fora da rotação do núcleo, com uma folga depois de
    // v4_todos_os_dias_seguidos (14) dias seguidos, ou seja, uma folga a cada 15 dias. Fica só no
    // posto dele: não acumula outro posto (passo 6) nem cobre página (passo 7).
    $todoDia = array_flip(array_filter(array_map(static fn($c) => strtoupper(trim($c)), explode(',', escV4Param('v4_todos_os_dias', '')))));
    // Só uma página (v4_sr_uma_pagina, códigos; pedido de 04/11): o SR não funciona com várias páginas.
    // Não fica em posto com mais de uma página (fora as de apoio), não acumula posto (passo 6) nem cobre página (passo 7).
    $umaPag = array_flip(array_filter(array_map(static fn($c) => strtoupper(trim($c)), explode(',', escV4Param('v4_sr_uma_pagina', '')))));
    // Várias páginas ao mesmo tempo (v4_sr_multipagina, "CÓDIGO:página|página;CÓDIGO:..."; pedido de 04/11): o SR
    // fica fixo em todas as páginas da lista no turno dele, acumulando os postos delas, sem assistente e sem limite
    // de capacidade (o manager garante que ele dá conta). Não acumula outro posto nem cobre outra página.
    // Só assistente em qualquer página (v4_sr_so_assistente) e baixo fluxo (v4_sr_baixo_fluxo: só posto de até
    // v4_baixo_fluxo_max fãs/h, ou de assistente), pedidos de 04/11. Nenhum dos dois acumula nem cobre página.
    $soAssistGeral = array_flip(array_filter(array_map(static fn($c) => strtoupper(trim($c)), explode(',', escV4Param('v4_sr_so_assistente', '')))));
    $baixoFluxo = array_flip(array_filter(array_map(static fn($c) => strtoupper(trim($c)), explode(',', escV4Param('v4_sr_baixo_fluxo', '')))));
    $baixoFluxoMax = (float) str_replace(',', '.', escV4Param('v4_baixo_fluxo_max', '12'));
    // Baixo fluxo estrito (v4_sr_baixo_fluxo_estrito): nunca em posto acima do corte, nem de assistente.
    foreach (array_filter(array_map(static fn($c) => strtoupper(trim($c)), explode(',', escV4Param('v4_sr_baixo_fluxo_estrito', '')))) as $c) {
        $baixoFluxo[$c] = 'estrito';
    }
    $multiPag = [];
    $multiPagChave = []; // as mesmas listas, com o nome da página normalizado (escV4ChavePagina) para comparar
    foreach (explode(';', escV4Param('v4_sr_multipagina', '')) as $item) {
        $x = explode(':', $item, 2);
        if (count($x) === 2 && trim($x[0]) !== '') {
            $multiPag[strtoupper(trim($x[0]))] = array_values(array_filter(array_map('trim', explode('|', $x[1])), 'strlen'));
            $multiPagChave[strtoupper(trim($x[0]))] = array_map('escV4ChavePagina', $multiPag[strtoupper(trim($x[0]))]);
        }
    }
    // Uma plataforma por turno (v4_uma_plataforma_turno, padrão 1; pedido de 04/11): quem faz Privacy,
    // Fanvue ou PlayBoy não acumula nem cobre página de outra plataforma no mesmo turno, e vice-versa.
    $umaPlat = escV4Param('v4_uma_plataforma_turno', '1') !== '0';
    $mesmaPlat = static function (array $a, array $b) use ($umaPlat): bool {
        return !$umaPlat || count($a + $b) <= 1;
    };
    // Restrições dos OM (camada à parte, lib/restricoes_om.php): travas e preferências que os OM ditam
    // por pessoa. Valem na equipe fixa e no preenchimento (passos 1 a 7); não mudam as regras do motor
    // (P-01 a P-04): o melhor SR de cima para baixo (passo 0) segue igual.
    ['pref_assist' => $prefAssist, 'tier_medio' => $tierMedio, 'pref_masc' => $prefMasc, 'faixa' => $faixaRec,
     'pode_pags' => $podePags, 'tem_masc' => $temMasc] = escOmRestricoes($est);
    // P-02 Cor da página (regra do motor, validada em 03/11): vermelha só com quem a conhece (7 dias ou mais
    // nos últimos 90) ou é da equipe fixa dela; sem ninguém assim, fica em lacuna. Amarela: no máximo
    // v4_cor_amarela_novos (20%) dos turnos da semana, por página e turno, com quem não a conhece. Verde: livre.
    $corAtiva = escV4Param('v4_cor_ativa', '1') !== '0';
    // A cota da amarela fica desligada por enquanto (decisão de 04/11): liga com v4_cor_amarela_ativa = 1.
    $amarelaCota = escV4Param('v4_cor_amarela_ativa', '0') === '1';
    $conheceCor = $est['conhece_cor'] ?? [];
    $quotaAmarela = max(1, (int) floor((float) str_replace(',', '.', escV4Param('v4_cor_amarela_novos', '0.20')) * count($dias) + 1e-9));
    $novatosAmarela = []; // [página|turno] = turnos da semana com quem não conhece
    $fixoNaPag = static function (string $rep, string $pag, string $dia) use ($est): bool {
        foreach ($est['prioridade'][$pag] ?? [] as $pr) {
            if ($pr['rep'] === $rep && $pr['de'] <= $dia && ($pr['ate'] === null || $pr['ate'] >= $dia)) {
                return true;
            }
        }
        return false;
    };
    $limiteTodoDia = max(1, (int) escV4Param('v4_todos_os_dias_seguidos', '14'));
    $realizado = $in['realizado'] ?? [];
    $inicioPlano = max($dias[0], $virada);
    $seguidosAntes = static function (string $rep, string $dia) use (&$trabalhou, $realizado, $inicioPlano): int {
        $n = 0;
        for ($d = escMaisDias($dia, -1); $n < 30; $d = escMaisDias($d, -1)) {
            if (!(isset($trabalhou[$rep][$d]) || isset($realizado[$rep][$d]))) {
                break;
            }
            $n++;
        }
        return $n;
    };
    $emendas = [];
    $emFolga = []; // [rep][dia] = folga de emenda

    foreach ($dias as $dia) {
        if ($dia < $virada) {
            continue;
        }
        $transicao = in_array($dia, $diasTransicao, true);
        $presentes = $transicao ? ($presentesDia[$dia] ?? []) : [];
        $rotuloTransicao = 'transição ' . date('d/m', strtotime($dia));
        foreach (ESC_TURNOS as $turno => $blocos) {
            if (!escV4TurnoNovo($dia, $turno, $virada, $turnoVirada)) {
                continue; // antes da virada: vale a escala já postada
            }
            $diaBloco = [$blocos[0] => $dia, $blocos[1] => $turno === 'D' ? escMaisDias($dia, 1) : $dia];
            $estadoUT = [];
            $usado = [];
            $overlapUT = [];

            foreach ([1, 2, 3] as $u) {
                $nids = $nucUT[$u][$turno] ?? [];
                $ativos = [];
                foreach ($nids as $nid) {
                    if ($transicao || escV4NucleoTrabalha($nucleos[$nid], $ciclo, $est['ancora'], $dia)) {
                        $ativos[$nid] = true;
                    }
                }
                // Quem está disponível
                $disp = [];
                foreach ($pessoas as $rep => $p) {
                    if ($p['unidade'] !== $u || $p['turno'] !== $turno) {
                        continue;
                    }
                    $sempre = isset($todoDia[$rep]) && !$transicao;
                    $emenda = $sempre ? $seguidosAntes($rep, $dia) >= $limiteTodoDia
                        : $limite > 0 && ($transicao ? !empty($presentes[$rep][$turno]) : isset($ativos[$p['nucleo']]))
                        && $seguidosAntes($rep, $dia) >= $limite;
                    if ($emenda) {
                        $folgas[] = ['rep_code' => $rep, 'dia' => $dia, 'tipo' => 'EMENDA', 'ref_id' => null];
                        $emendas[] = ['rep_code' => $rep, 'dia' => $dia, 'turno' => $turno, 'seguidos' => $seguidosAntes($rep, $dia)];
                        $emFolga[$rep][$dia] = true;
                        continue;
                    }
                    if ($transicao) {
                        // Indisponibilidade aprovada vale também na transição (o "presente" vem da
                        // escala antiga, que pode ter sido montada antes da aprovação).
                        $ind = $indisp[$rep][$dia][$turno] ?? null;
                        if ($ind !== null && $ind['blocos'] === null) {
                            if (!empty($presentes[$rep][$turno])) {
                                $folgas[] = ['rep_code' => $rep, 'dia' => $dia, 'tipo' => 'INDISPONIBILIDADE', 'ref_id' => $ind['id']];
                            }
                            continue;
                        }
                        if (!empty($presentes[$rep][$turno])) {
                            $disp[$rep] = $p;
                        }
                        continue;
                    }
                    if (!$sempre && !isset($ativos[$p['nucleo']])) {
                        $ind = $indisp[$rep][$dia][$turno] ?? null;
                        $diaOverlap = isset($overlapDe[$rep]) && $ind === null
                            && escV4IndiceCiclo($nucleos[$p['nucleo']], $ciclo, $est['ancora'], $dia) === $overlapDe[$rep];
                        if ($diaOverlap && $limite > 0 && $seguidosAntes($rep, $dia) >= $limite) {
                            // Já vem do limite de dias seguidos (ex.: emenda da virada): folga em vez do treinamento.
                            $folgas[] = ['rep_code' => $rep, 'dia' => $dia, 'tipo' => 'EMENDA', 'ref_id' => null];
                            $emendas[] = ['rep_code' => $rep, 'dia' => $dia, 'turno' => $turno, 'seguidos' => $seguidosAntes($rep, $dia)];
                            $emFolga[$rep][$dia] = true;
                        } elseif ($diaOverlap) {
                            $overlapUT[$u][$rep] = $p;
                        } else {
                            $folgas[] = ['rep_code' => $rep, 'dia' => $dia, 'tipo' => 'ROTACAO', 'ref_id' => null];
                        }
                        continue;
                    }
                    $ind = $indisp[$rep][$dia][$turno] ?? null;
                    if ($ind !== null && $ind['blocos'] === null) {
                        $folgas[] = ['rep_code' => $rep, 'dia' => $dia, 'tipo' => 'INDISPONIBILIDADE', 'ref_id' => $ind['id']];
                        continue;
                    }
                    $disp[$rep] = $p;
                }
                // Vagas do dia
                $vagas = [];
                $cobertos = [];
                foreach ($postosUT[$u][$turno] ?? [] as $pid) {
                    if (!empty($treinoCobre[$dia][$pid]['total'])) {
                        $cobertos[$pid] = $treinoCobre[$dia][$pid]['paginas'];
                        continue; // treinamento usa a página: ninguém da escala normal vai para lá
                    }
                    $p = $est['postos'][$pid];
                    $dono = (int) $p['nucleo_dono_id'];
                    $cob = $p['nucleo_cobertura_id'] !== null ? (int) $p['nucleo_cobertura_id'] : null;
                    if ($transicao) {
                        $vaga = 'DONO';
                        $nuc = null;
                        $equipe = ($equipes[$pid]['DONO'] ?? []) + ($equipes[$pid]['COBERTURA'] ?? []);
                    } elseif (isset($ativos[$dono])) {
                        $vaga = 'DONO';
                        $nuc = $dono;
                        $equipe = $equipes[$pid]['DONO'] ?? [];
                    } else {
                        $vaga = 'COBERTURA';
                        $nuc = ($cob !== null && isset($ativos[$cob])) ? $cob : null;
                        $equipe = $equipes[$pid]['COBERTURA'] ?? [];
                    }
                    $chave = $pid . '|' . $vaga;
                    $diasVaga[$chave] = ($diasVaga[$chave] ?? 0) + 1;
                    $vagas[] = ['pid' => $pid, 'vaga' => $vaga, 'nuc' => $nuc, 'equipe' => $equipe, 'chave' => $chave,
                        'rep' => null, 'origem' => null, 'motivo' => null];
                }
                $estadoUT[$u] = ['ativos' => $ativos, 'disp' => $disp, 'vagas' => $vagas, 'cobertos' => $cobertos];
            }

            $afinidade = static function (string $rep, array $p) use ($conhece): int {
                $n = 0;
                foreach ($p['paginas'] as $pg) {
                    $n += isset($conhece[$rep][$pg['pagina']]) ? 1 : 0;
                }
                return $n;
            };
            // Menos troca de gente: quem estava no posto, quem é da equipe de alguma página dele,
            // quem mais trabalhou nas páginas dele (5C Schedule); depois a nota em pontos, o tempo de casa e o nível.
            $historico = $est['historico'] ?? [];
            $merito = $est['merito'] ?? [];
            $faixaRep = $est['faixa'] ?? [];
            $anosRep = $est['anos'] ?? [];
            $soAssist = $est['so_assist'] ?? [];
            $foraPag = $est['fora'] ?? [];
            $corOk = static function (string $rep, array $paginas) use ($corAtiva, $amarelaCota, $conheceCor, &$novatosAmarela, $quotaAmarela, $fixoNaPag, $dia, $turno): bool {
                if (!$corAtiva) {
                    return true;
                }
                foreach ($paginas as $pg) {
                    if (!empty($pg['apoio'])) {
                        continue;
                    }
                    $cor = escCorDe((string) $pg['pagina']);
                    if (($cor !== 'VERMELHA' && ($cor !== 'AMARELA' || !$amarelaCota)) || isset($conheceCor[$rep][$pg['pagina']]) || $fixoNaPag($rep, (string) $pg['pagina'], $dia)) {
                        continue;
                    }
                    if ($cor === 'VERMELHA' || ($novatosAmarela[$pg['pagina'] . '|' . $turno] ?? 0) >= $quotaAmarela) {
                        return false; // P-02: vermelha só com quem conhece; amarela com a cota de gente nova cheia
                    }
                }
                return true;
            };
            $pode = static function (string $rep, array $p) use ($corOk, $pessoas, $nivelMaxAssist, $soAssist, $foraPag, $umaPag, $soAssistGeral, $baixoFluxo, $baixoFluxoMax, $podePags, $prefAssist, $faixaRec): bool {
                foreach ($p['paginas'] as $pg) {
                    if (isset($foraPag[$rep][$pg['pagina']])) {
                        return false; // fora desta página
                    }
                }
                if (!$corOk($rep, $p['paginas'])) {
                    return false; // P-02 (cor da página)
                }
                if (!$podePags($rep, $p['paginas'], in_array($p['tipo'], ['assistente', 'apoio'], true))) {
                    return false; // só certas páginas, ou só tier médio (restrições dos OM)
                }
                if (isset($umaPag[$rep]) && count(array_filter($p['paginas'], static fn($pg) => !$pg['apoio'])) > 1) {
                    return false; // só trabalha com uma página
                }
                $postoAssist = in_array($p['tipo'], ['assistente', 'apoio'], true);
                if (isset($soAssistGeral[$rep]) && !$postoAssist) {
                    return false; // só assistente, em qualquer página
                }
                if (isset($baixoFluxo[$rep]) && ($baixoFluxo[$rep] === 'estrito' || !$postoAssist) && (float) $p['carga'] > $baixoFluxoMax + 1e-9) {
                    return false; // só página de baixo fluxo (no estrito, nem de assistente)
                }
                if ((isset($soAssistGeral[$rep]) || isset($baixoFluxo[$rep]) || isset($prefAssist[$rep]) || isset($faixaRec[$rep])) && $postoAssist) {
                    return true; // de assistente, mesmo acima do nível máximo de assistente
                }
                if (in_array($p['tipo'], ['assistente', 'apoio'], true)) {
                    foreach ($p['paginas'] as $pg) {
                        if (!$pg['apoio'] && isset($soAssist[$rep][$pg['pagina']])) {
                            return true; // o manager marcou de assistente nesta página: vale qualquer nível
                        }
                    }
                    return (int) ($pessoas[$rep]['nivel'] ?? 0) <= $nivelMaxAssist && ($pessoas[$rep]['pode_assistente'] ?? true);
                }
                foreach ($p['paginas'] as $pg) {
                    if (!$pg['apoio'] && isset($soAssist[$rep][$pg['pagina']])) {
                        return false; // só assistente nesta página: não fica com o posto titular dela
                    }
                }
                return true;
            };
            $escolher = static function (array $cands, array $p) use ($afinidade, $pessoas, $ultimo, $historico, $merito, $anosRep, $pode, $prefAssist, $prefMasc, $temMasc): ?string {
                $assistP = in_array($p['tipo'], ['assistente', 'apoio'], true);
                $mascP = $prefMasc ? $temMasc($p) : false;
                $melhor = null;
                $chaveMelhor = null;
                foreach ($cands as $rep) {
                    if (!$pode($rep, $p)) {
                        continue;
                    }
                    $dias = 0;
                    foreach ($p['paginas'] as $pg) {
                        $dias += min(28, $historico[$rep][$pg['pagina']] ?? 0);
                    }
                    // Restrições dos OM: quem serve como assistente vai antes no posto de assistente;
                    // quem tem preferência por página masculina, antes no posto com ela.
                    $pref = ($assistP && isset($prefAssist[$rep])) || ($mascP && isset($prefMasc[$rep])) ? 1 : 0;
                    $k = [$pref, ($ultimo[(int) $p['id']] ?? null) === $rep ? 1 : 0, $afinidade($rep, $p), min(4, intdiv($dias, 7)),
                        round($merito[$rep] ?? 0.0, 2), $anosRep[$rep] ?? 0, $pessoas[$rep]['nivel'] ?? 0, $rep];
                    if ($chaveMelhor === null || $k > $chaveMelhor) {
                        $melhor = $rep;
                        $chaveMelhor = $k;
                    }
                }
                return $melhor;
            };

            // Divisão do SR entre páginas (esc_sr_divisao, ES_98; ex.: 50% Brandi Love e 50% Elly Clutch):
            // a cada dia em que está disponível no turno, a vez vai para a página mais atrasada em relação à
            // parte dela (o que falta para 100% é a escala normal da unidade). A página pode ser de outra
            // unidade: ele entra na vaga dela no mesmo turno. Vem antes da equipe fixa da página.
            foreach ($transicao ? [] : ($est['divisao'] ?? []) as $rep => $partes) {
                $partes = array_values(array_filter($partes, static fn($x) => $x['de'] <= $dia && ($x['ate'] === null || $x['ate'] >= $dia)));
                $uRep = null;
                foreach ($estadoUT as $uu => $stU) {
                    if (isset($stU['disp'][$rep])) {
                        $uRep = $uu;
                        break;
                    }
                }
                if (!$partes || $uRep === null || isset($usado[$rep])) {
                    continue;
                }
                $n = ($divFeito[$rep]['_n'] ?? 0) + 1;
                $opcoes = $partes;
                $soma = array_sum(array_column($partes, 'parte'));
                if ($soma < 0.999) {
                    $opcoes[] = ['pagina' => '', 'parte' => 1 - $soma];
                }
                $vez = null;
                $kVez = null;
                foreach ($opcoes as $i => $o) {
                    $k = [round($o['parte'] * $n - ($divFeito[$rep][$o['pagina']] ?? 0), 6), $o['parte'], -$i];
                    if ($kVez === null || $k > $kVez) {
                        $kVez = $k;
                        $vez = $o;
                    }
                }
                $divFeito[$rep]['_n'] = $n;
                $divFeito[$rep][$vez['pagina']] = ($divFeito[$rep][$vez['pagina']] ?? 0) + 1;
                if ($vez['pagina'] === '') {
                    continue; // a vez é da escala normal da unidade dele
                }
                $achou = false;
                foreach (array_keys($estadoUT) as $uu) {
                    foreach ($estadoUT[$uu]['vagas'] as $iv => $v) {
                        $p = $est['postos'][$v['pid']];
                        if ($v['rep'] !== null || !in_array($vez['pagina'], array_column(array_filter($p['paginas'], static fn($pg) => !$pg['apoio']), 'pagina'), true) || !$pode($rep, $p)) {
                            continue;
                        }
                        $estadoUT[$uu]['vagas'][$iv]['rep'] = $rep;
                        $estadoUT[$uu]['vagas'][$iv]['origem'] = isset($v['equipe'][$rep]) ? ($v['vaga'] === 'DONO' ? 'VAGA_DONO' : 'VAGA_COBERTURA') : 'BUFFER_NUCLEO';
                        $estadoUT[$uu]['vagas'][$iv]['motivo'] = 'divisão do SR: ' . round($vez['parte'] * 100) . '% dos turnos em ' . $vez['pagina'] . ($uu !== $uRep ? ' (página da U' . $uu . ')' : '');
                        $estadoUT[$uu]['vagas'][$iv]['fixa'] = true;
                        $usado[$rep] = true;
                        $achou = true;
                        break 2;
                    }
                }
                if (!$achou) {
                    // A página não tem vaga livre no turno: o dia fica com a escala normal (e conta como tal).
                    $divFeito[$rep][$vez['pagina']]--;
                    $divFeito[$rep][''] = ($divFeito[$rep][''] ?? 0) + 1;
                }
            }

            // Várias páginas ao mesmo tempo (v4_sr_multipagina): o SR pega, no turno dele, todos os postos vazios
            // que só têm páginas da lista (fora as de apoio); do segundo em diante, o posto fica acumulado.
            foreach ($transicao ? [] : $multiPag as $rep => $pagsMulti) {
                $uRep = null;
                foreach ($estadoUT as $uu => $stU) {
                    if (isset($stU['disp'][$rep])) {
                        $uRep = $uu;
                        break;
                    }
                }
                if ($uRep === null || isset($usado[$rep]) || !$pagsMulti) {
                    continue;
                }
                $nPostos = 0;
                foreach ($estadoUT[$uRep]['vagas'] as $iv => $v) {
                    $p = $est['postos'][$v['pid']];
                    $nomesP = array_column(array_filter($p['paginas'], static fn($pg) => !$pg['apoio']), 'pagina');
                    if ($v['rep'] !== null || $p['tipo'] === 'assistente' || !$nomesP || array_diff(array_map('escV4ChavePagina', $nomesP), $multiPagChave[$rep] ?? []) || !$pode($rep, $p)) {
                        continue;
                    }
                    $estadoUT[$uRep]['vagas'][$iv]['rep'] = $rep;
                    $estadoUT[$uRep]['vagas'][$iv]['origem'] = isset($v['equipe'][$rep]) ? ($v['vaga'] === 'DONO' ? 'VAGA_DONO' : 'VAGA_COBERTURA') : 'BUFFER_NUCLEO';
                    $estadoUT[$uRep]['vagas'][$iv]['motivo'] = 'fixo em várias páginas ao mesmo tempo (' . implode(', ', $pagsMulti) . '), pedido do manager';
                    $estadoUT[$uRep]['vagas'][$iv]['fixa'] = true;
                    if ($nPostos > 0) {
                        $estadoUT[$uRep]['vagas'][$iv]['acumulada'] = true;
                    }
                    $nPostos++;
                }
                if ($nPostos > 0) {
                    $usado[$rep] = true;
                }
            }

            // Equipe fixa da página (esc_pagina_prioridade): quem é da equipe e está na unidade e turno fica
            // com a página antes do melhor SR; quem estava no posto e quem mais conhece a página primeiro.
            // Em três rodadas (ES_114): primeiro os titulares (MAIN) de todas as páginas, depois quem cobre
            // (COVER) e por último o resto da equipe. Assim quem é titular de uma página e cover de outra fica
            // na dele quando o titular da outra está no turno.
            $ordemPapel = ['MAIN' => 2, 'COVER' => 1, 'EQUIPE' => 0];
            foreach (empty($est['prioridade']) ? [] : array_keys($estadoUT) as $u) {
                $st = &$estadoUT[$u];
                foreach (['MAIN', 'COVER', 'EQUIPE'] as $rodada)
                foreach ($st['vagas'] as &$v) {
                    if ($v['rep'] !== null) {
                        continue; // já preenchida pela divisão, pelas várias páginas ou na rodada anterior (não sobrescreve)
                    }
                    $p = $est['postos'][$v['pid']];
                    $equipe = [];
                    $papelEq = [];
                    foreach ($p['paginas'] as $pg) {
                        foreach ($pg['apoio'] ? [] : ($est['prioridade'][$pg['pagina']] ?? []) as $pr) {
                            if ($pr['de'] <= $dia && ($pr['ate'] === null || $pr['ate'] >= $dia)) {
                                $pp = $pr['papel'] ?? 'EQUIPE';
                                if (!isset($papelEq[$pr['rep']]) || ($ordemPapel[$pp] ?? 0) > ($ordemPapel[$papelEq[$pr['rep']]] ?? 0)) {
                                    $papelEq[$pr['rep']] = $pp;
                                    $equipe[$pr['rep']] = $pg['pagina'];
                                }
                            }
                        }
                    }
                    $melhor = null;
                    $kMelhor = null;
                    foreach ($equipe as $rep => $pag) {
                        if (($papelEq[$rep] ?? 'EQUIPE') !== $rodada || !isset($st['disp'][$rep]) || isset($usado[$rep]) || !$pode($rep, $p)) {
                            continue;
                        }
                        // No posto de assistente, quem o manager marcou como assistente da página vem antes
                        // (fica sempre o mesmo nome; pedido de 04/11 para a Mikayla Free).
                        $k = [$p['tipo'] === 'assistente' ? (isset($soAssist[$rep][$pag]) ? 2 : (isset($prefAssist[$rep]) ? 1 : 0)) : 0,
                            ($ultimo[(int) $p['id']] ?? null) === $rep ? 1 : 0, isset($v['equipe'][$rep]) ? 1 : 0, $historico[$rep][$pag] ?? 0, $rep];
                        if ($kMelhor === null || $k > $kMelhor) {
                            $kMelhor = $k;
                            $melhor = $rep;
                        }
                    }
                    if ($melhor !== null) {
                        $v['rep'] = $melhor;
                        $v['origem'] = $transicao ? 'TRANSICAO' : (isset($v['equipe'][$melhor]) ? ($v['vaga'] === 'DONO' ? 'VAGA_DONO' : 'VAGA_COBERTURA') : 'BUFFER_NUCLEO');
                        $v['motivo'] = ['MAIN' => 'titular (main) da página ', 'COVER' => 'cover da página '][$rodada] ?? 'equipe fixa da página ';
                        $v['motivo'] .= $equipe[$melhor];
                        $v['fixa'] = true; // não sai da página para remanejar (A-04)
                        $usado[$melhor] = true;
                    }
                }
                unset($v, $st);
            }

            // P-02 Reserva de quem conhece (vermelhas e depois amarelas): antes do melhor SR de cima para baixo,
            // cada vaga vazia de posto com página vermelha (depois amarela), da que mais fatura para a que menos,
            // fica com quem conhece a página (7 dias ou mais em 90, ou equipe fixa). Assim quem conhece a
            // vermelha não é gasto antes numa verde. A escolha entre eles é a de sempre ($escolher).
            if ($corAtiva && !$transicao) {
                $conhecePosto = static function (string $rep, array $p, string $cor) use ($conheceCor, $fixoNaPag, $dia): bool {
                    foreach ($p['paginas'] as $pg) {
                        if (empty($pg['apoio']) && escCorDe((string) $pg['pagina']) === $cor
                            && !isset($conheceCor[$rep][$pg['pagina']]) && !$fixoNaPag($rep, (string) $pg['pagina'], $dia)) {
                            return false;
                        }
                    }
                    return true;
                };
                foreach (['VERMELHA', 'AMARELA'] as $corR) {
                    $listaR = [];
                    foreach ($estadoUT as $uR => $stR) {
                        foreach ($stR['vagas'] as $ivR => $vR) {
                            if ($vR['rep'] !== null) {
                                continue;
                            }
                            $recR = null;
                            foreach ($est['postos'][$vR['pid']]['paginas'] as $pg) {
                                if (empty($pg['apoio']) && escCorDe((string) $pg['pagina']) === $corR) {
                                    $recR = max($recR ?? 0.0, (float) ($est['receita'][$pg['pagina']]['total'] ?? 0));
                                }
                            }
                            if ($recR !== null) {
                                $listaR[] = [$uR, $ivR, $recR];
                            }
                        }
                    }
                    usort($listaR, static fn($a, $b) => $b[2] <=> $a[2]);
                    foreach ($listaR as [$uR, $ivR]) {
                        $vR = $estadoUT[$uR]['vagas'][$ivR];
                        if ($vR['rep'] !== null) {
                            continue;
                        }
                        $pR = $est['postos'][$vR['pid']];
                        $cands = array_values(array_filter(array_keys($estadoUT[$uR]['disp']), static fn($r) => !isset($usado[$r]) && $conhecePosto((string) $r, $pR, $corR)));
                        $rep = $cands ? $escolher($cands, $pR) : null;
                        if ($rep === null) {
                            continue;
                        }
                        $estadoUT[$uR]['vagas'][$ivR]['rep'] = $rep;
                        $estadoUT[$uR]['vagas'][$ivR]['origem'] = isset($vR['equipe'][$rep]) ? ($vR['vaga'] === 'DONO' ? 'VAGA_DONO' : 'VAGA_COBERTURA') : 'BUFFER_NUCLEO';
                        $estadoUT[$uR]['vagas'][$ivR]['motivo'] = 'conhece a página ' . ($corR === 'VERMELHA' ? 'vermelha' : 'amarela') . ' ' . $pR['pagina_principal'] . ' (P-02: 7 dias ou mais em 90)';
                        $estadoUT[$uR]['vagas'][$ivR]['fixa'] = $corR === 'VERMELHA';
                        $usado[$rep] = true;
                    }
                }
            }

            // 0. Páginas das modelos de v4_melhores_receita_min ou mais (0 = todas, P-01): o melhor SR do turno
            //    na página entra antes da equipe da vaga; a equipe fixa da página (acima) vem antes dele.
            //    As vagas vão na ordem da fila: a modelo que mais fatura escolhe primeiro. Desde a 1.19.0
            //    (A-01, v4_merito_faixa_passo0): quem bateu a meta na página, a faixa do mérito, a nota em
            //    pontos e o tempo de casa, com continuidade; antes, a venda/hora.
            $mel = $est['melhores'] ?? null;
            if (!$transicao && $mel && !empty($mel['paginas'])) {
                foreach ($estadoUT as $u => &$st) {
                    foreach ($st['vagas'] as &$v) {
                        if ($v['rep'] !== null) {
                            continue; // já tem a equipe fixa da página
                        }
                        $p = $est['postos'][$v['pid']];
                        $pag = null;
                        foreach ($p['paginas'] as $pg) {
                            if (!$pg['apoio'] && ($pag === null || ($est['receita'][$pg['pagina']][$turno] ?? 0) > ($est['receita'][$pag][$turno] ?? 0))) {
                                $pag = $pg['pagina'];
                            }
                        }
                        $pag = $pag ?? ($p['paginas'][0]['pagina'] ?? null);
                        if ($pag === null || !isset($mel['paginas'][$pag])) {
                            continue;
                        }
                        $melhor = null;
                        $kMelhor = null;
                        $metaMin = $mel['meta_min'] ?? 0.65;
                        foreach ($st['disp'] as $rep => $pp) {
                            if (isset($usado[$rep]) || !$pode($rep, $p)) {
                                continue;
                            }
                            // Primeiro quem já fez a página e bateu a meta nela (v4_melhores_meta_min nos últimos 30 dias).
                            $bateu = ($mel['meta'][$pag][$rep] ?? 0.0) >= $metaMin ? 1 : 0;
                            if ($faixaPasso0) {
                                // A-01 com a P-01: depois quem já fez a página em 30 dias (tem meta nela, 03/11),
                                // a faixa do mérito, a nota em pontos e o tempo de casa.
                                $conhece = isset($mel['meta'][$pag][$rep]) ? 1 : 0;
                                $k = [$bateu, $conhece, $faixaRep[$rep] ?? 1, round($merito[$rep] ?? 0.0, 2), $anosRep[$rep] ?? 0, $rep];
                            } else {
                                // 1.18: a venda/hora (na página, se tem histórico nela; senão a geral).
                                $naPag = $mel['pagina'][$pag][$rep] ?? null;
                                $geral = $mel['geral'][$rep] ?? 0.0;
                                $k = [$bateu, $naPag ?? $geral, $naPag !== null ? 1 : 0, $geral, (int) ($pp['nivel'] ?? 0), $rep];
                            }
                            if ($kMelhor === null || $k > $kMelhor) {
                                $kMelhor = $k;
                                $melhor = $rep;
                            }
                        }
                        if ($melhor === null || (!$faixaPasso0 && $kMelhor[0] === 0 && $kMelhor[1] <= 0)) {
                            continue;
                        }
                        $fica = false;
                        if ($faixaPasso0) {
                            // Continuidade (P-01): quem estava no posto fica, a não ser que o melhor seja de faixa acima
                            // da dele, que ele esteja abaixo da meta mínima na página ou que nunca a tenha feito
                            // e o melhor já tenha.
                            $antes = $ultimo[(int) $p['id']] ?? null;
                            if ($antes !== null && $antes !== $melhor && isset($st['disp'][$antes]) && !isset($usado[$antes]) && $pode($antes, $p)
                                && ($faixaRep[$melhor] ?? 1) <= ($faixaRep[$antes] ?? 1)
                                && (isset($mel['meta'][$pag][$antes]) || !isset($mel['meta'][$pag][$melhor]))
                                && !(isset($mel['meta'][$pag][$antes]) && $mel['meta'][$pag][$antes] < $metaMin)) {
                                $melhor = $antes;
                                $fica = true;
                            }
                        }
                        $v['rep'] = $melhor;
                        $v['origem'] = isset($v['equipe'][$melhor]) ? ($v['vaga'] === 'DONO' ? 'VAGA_DONO' : 'VAGA_COBERTURA') : 'BUFFER_NUCLEO';
                        $metaPag = isset($mel['meta'][$pag][$melhor]) ? round($mel['meta'][$pag][$melhor] * 100) . '% da meta na página em 30 dias' : 'sem meta na página em 30 dias';
                        if ($faixaPasso0) {
                            $fx = [2 => 'Acima', 1 => 'Na média', 0 => 'Abaixo'][$faixaRep[$melhor] ?? 1];
                            $v['motivo'] = ($fica ? 'continua no posto, ' : 'melhor SR do turno, ') . 'para ' . $pag . ' (' . $metaPag . '; mérito ' . $fx . ', '
                                . str_replace('.', ',', (string) round($merito[$melhor] ?? 0.0, 1)) . ' pontos)';
                            // A-02: o SR Abaixo só fica em página prioritária sem outro disponível (ou se bateu a meta nela).
                            if ($abaixoForaTopo && ($faixaRep[$melhor] ?? 1) === 0 && isset($est['prioritaria'][$pag])) {
                                $v['motivo'] .= ' · Abaixo em página prioritária';
                            }
                        } else {
                            $v['motivo'] = 'melhor SR do turno para ' . $pag . ' (modelo com US$ ' . number_format($mel['paginas'][$pag], 0, ',', '.')
                                . ' em 30 dias; SR com US$ ' . number_format($kMelhor[1], 0, ',', '.') . '/h ' . ($kMelhor[2] ? 'na página' : 'no geral')
                                . ($kMelhor[0] ? ', ' . $metaPag : '') . ')';
                        }
                        $usado[$melhor] = true;
                    }
                    unset($v);
                }
                unset($st);
            }

            foreach ($estadoUT as $u => &$st) {
                // 1. equipe fixa da vaga (preferência). Fica quem atuou da última
                //    vez enquanto estiver abaixo da própria fatia (menos troca de
                //    gente na página); senão, quem está mais abaixo da fatia.
                foreach ($st['vagas'] as &$v) {
                    if ($v['rep'] !== null) {
                        continue; // já tem o melhor SR da página (etapa 0)
                    }
                    $melhor = null;
                    $chaveMelhor = null;
                    foreach ($v['equipe'] as $rep => $fatia) {
                        if (!isset($st['disp'][$rep]) || isset($usado[$rep]) || !$pode($rep, $est['postos'][$v['pid']])) {
                            continue;
                        }
                        $share = ($feito[$rep][$v['chave']] ?? 0) / max(1, $diasVaga[$v['chave']]);
                        $continua = ($ultimo[$v['pid']] ?? null) === $rep && $share < $fatia + 0.15 ? 1 : 0;
                        $k = [$continua, round($fatia - $share, 2), $fatia, round($est['merito'][$rep] ?? 0.0, 2), $rep];
                        if ($chaveMelhor === null || $k > $chaveMelhor) {
                            $melhor = $rep;
                            $chaveMelhor = $k;
                        }
                    }
                    if ($melhor !== null) {
                        $v['rep'] = $melhor;
                        $v['origem'] = $transicao ? 'TRANSICAO' : ($v['vaga'] === 'DONO' ? 'VAGA_DONO' : 'VAGA_COBERTURA');
                        $v['motivo'] = ($transicao ? $rotuloTransicao . ': já escalado no turno; ' : '') . 'equipe fixa da vaga ('
                            . strtolower($v['vaga']) . ', fatia ' . str_replace('.', ',', (string) round($v['equipe'][$melhor], 2)) . ')';
                        $usado[$melhor] = true;
                    }
                }
                unset($v);
                // 2. outra pessoa do mesmo núcleo (transição: da unidade e turno), quem conhece as páginas primeiro
                foreach ($st['vagas'] as &$v) {
                    if ($v['rep'] !== null) {
                        continue;
                    }
                    $cands = [];
                    foreach ($st['disp'] as $rep => $p) {
                        if (!isset($usado[$rep]) && ($transicao || $v['nuc'] === null || $p['nucleo'] === $v['nuc'])) {
                            $cands[] = $rep;
                        }
                    }
                    $rep = $escolher($cands, $est['postos'][$v['pid']]);
                    if ($rep !== null) {
                        $v['rep'] = $rep;
                        $v['origem'] = $transicao ? 'TRANSICAO' : 'BUFFER_NUCLEO';
                        $v['motivo'] = $transicao ? $rotuloTransicao . ': já escalado no turno' : 'buffer do próprio núcleo (vaga sem a equipe fixa no dia)';
                        $usado[$rep] = true;
                    }
                }
                unset($v);
                // 3. buffer do outro núcleo em serviço no mesmo turno da unidade
                foreach ($st['vagas'] as &$v) {
                    if ($v['rep'] !== null) {
                        continue;
                    }
                    $cands = array_values(array_filter(array_keys($st['disp']), static fn($r) => !isset($usado[$r])));
                    $rep = $escolher($cands, $est['postos'][$v['pid']]);
                    if ($rep !== null) {
                        $v['rep'] = $rep;
                        $v['origem'] = $transicao ? 'TRANSICAO' : 'BUFFER_NUCLEO';
                        $v['motivo'] = 'buffer de outro núcleo do turno';
                        $usado[$rep] = true;
                    }
                }
                unset($v);
            }
            unset($st);

            // Transição: quem está escalado no turno mas é de outra unidade (ou outro turno na estrutura nova).
            // Quem já trabalhou noutro turno do dia (vaga ou buffer) fica de fora: na escala antiga a pessoa
            // podia estar em dois turnos (ex.: o dela e assistente noutro); turno a mais só por hora extra pedida.
            if ($transicao) {
                $sobra = [];
                foreach ($presentes as $rep => $ts) {
                    $indS = $indisp[$rep][$dia][$turno] ?? null;
                    if (!empty($ts[$turno]) && !isset($usado[$rep]) && !isset($trabalhou[$rep][$dia]) && isset($pessoas[$rep]) && !isset($emFolga[$rep][$dia])
                        && !($indS !== null && $indS['blocos'] === null)
                        && !($limite > 0 && $seguidosAntes($rep, $dia) >= $limite)) {
                        $sobra[] = $rep;
                    }
                }
                foreach ($estadoUT as $u => &$st) {
                    foreach ($st['vagas'] as &$v) {
                        if ($v['rep'] !== null || !$sobra) {
                            continue;
                        }
                        $rep = $escolher($sobra, $est['postos'][$v['pid']]);
                        if ($rep === null) {
                            continue; // ninguém que sobra pode ficar neste posto (assistente: só SR até o nível máximo)
                        }
                        $sobra = array_values(array_diff($sobra, [$rep]));
                        $v['rep'] = $rep;
                        $v['origem'] = 'TRANSICAO';
                        $v['motivo'] = $rotuloTransicao . ': escalado neste turno, de outra unidade ou turno na estrutura nova';
                        $usado[$rep] = true;
                    }
                    unset($v);
                }
                unset($st);
            }

            if (!$transicao || $remanejaTransicao) {
                // Lacunas do turno, da página de maior faturamento para a de menor.
                $listarLacunas = static function () use (&$estadoUT, $receitaPosto, $receitaPropria): array {
                    $l = [];
                    foreach ($estadoUT as $u => $st) {
                        foreach ($st['vagas'] as $i => $v) {
                            if ($v['rep'] === null) {
                                $l[] = [$u, $i, $receitaPosto[$v['pid']], $receitaPropria[$v['pid']]];
                            }
                        }
                    }
                    usort($l, static fn($a, $b) => [$b[2], $b[3]] <=> [$a[2], $a[3]]);
                    return $l;
                };
                $lacunas = $listarLacunas();

                // 4a. Quem está de Overlap e é da equipe fixa da página em lacuna (ou fixo nela pela lista de várias
                //     páginas) cobre primeiro a página dele, da mesma unidade e, em último caso, de outra (pente-fino
                //     de 04/11: no dia de treinamento ia cobrir lacuna de qualquer página, às vezes em outra unidade).
                $multiNoOverlap = [];
                foreach ($lacunas as [$u, $i]) {
                    $v = $estadoUT[$u]['vagas'][$i];
                    if ($v['rep'] !== null) {
                        continue;
                    }
                    $p = $est['postos'][$v['pid']];
                    $nomesP = array_column(array_filter($p['paginas'], static fn($pg) => !$pg['apoio']), 'pagina');
                    $membros = [];
                    foreach ($nomesP as $pgN) {
                        foreach ($est['prioridade'][$pgN] ?? [] as $pr) {
                            if ($pr['de'] <= $dia && ($pr['ate'] === null || $pr['ate'] >= $dia)) {
                                $membros[$pr['rep']] = $pgN;
                            }
                        }
                    }
                    foreach ($multiPag as $rm => $pagsM) {
                        if ($nomesP && !array_diff(array_map('escV4ChavePagina', $nomesP), $multiPagChave[$rm] ?? []) && $p['tipo'] !== 'assistente') {
                            $membros[$rm] = implode(', ', $pagsM);
                        }
                    }
                    if (!$membros) {
                        continue;
                    }
                    $escolhido = null;
                    foreach ([true, false] as $mesmaU) {
                        $kE = null;
                        foreach ($overlapUT as $u2 => $rs) {
                            if (($u2 === $u) !== $mesmaU) {
                                continue;
                            }
                            foreach (array_keys($rs) as $rep) {
                                if (!isset($membros[$rep]) || !$pode($rep, $p)) {
                                    continue;
                                }
                                $k = [isset($multiPag[$rep]) ? 1 : 0, $historico[$rep][$membros[$rep]] ?? 0, $rep];
                                if ($kE === null || $k > $kE) {
                                    $kE = $k;
                                    $escolhido = [$rep, $u2];
                                }
                            }
                        }
                        if ($escolhido !== null) {
                            break;
                        }
                    }
                    if ($escolhido === null) {
                        continue;
                    }
                    [$rep, $u2] = $escolhido;
                    $estadoUT[$u]['vagas'][$i]['rep'] = $rep;
                    $estadoUT[$u]['vagas'][$i]['origem'] = 'BUFFER_NUCLEO';
                    $estadoUT[$u]['vagas'][$i]['motivo'] = 'dia de treinamento (Overlap) na página fixa dele (' . $membros[$rep] . ')' . ($u2 !== $u ? ', de outra unidade (U' . $u2 . '): última opção' : '');
                    $estadoUT[$u]['vagas'][$i]['treino'] = true;
                    $estadoUT[$u]['vagas'][$i]['fixa'] = true;
                    if (isset($multiPag[$rep])) {
                        // Fixo em várias páginas: segue disponível para as outras páginas da lista dele.
                        if (isset($multiNoOverlap[$rep])) {
                            $estadoUT[$u]['vagas'][$i]['acumulada'] = true;
                        }
                        $multiNoOverlap[$rep] = true;
                    } else {
                        foreach (array_keys($overlapUT) as $u3) {
                            unset($overlapUT[$u3][$rep]);
                        }
                    }
                    $usado[$rep] = true;
                }
                foreach (array_keys($multiNoOverlap) as $rep) {
                    foreach (array_keys($overlapUT) as $u3) {
                        unset($overlapUT[$u3][$rep]);
                    }
                }

                // 4. Quem está de Overlap (dia de treinamento) e sobra cobre a lacuna no posto,
                //    dentro da capacidade dele: primeiro da mesma unidade, depois das outras do turno.
                foreach ($lacunas as [$u, $i]) {
                    $cands = array_keys($overlapUT[$u] ?? []);
                    if (!$cands) {
                        foreach ($overlapUT as $u2 => $rs) {
                            $cands = array_merge($cands, array_keys($rs));
                        }
                    }
                    $rep = $cands ? $escolher($cands, $est['postos'][$estadoUT[$u]['vagas'][$i]['pid']]) : null;
                    if ($rep !== null) {
                        $estadoUT[$u]['vagas'][$i]['rep'] = $rep;
                        $estadoUT[$u]['vagas'][$i]['origem'] = 'BUFFER_NUCLEO';
                        $estadoUT[$u]['vagas'][$i]['motivo'] = 'dia de treinamento (Overlap) usado para cobrir a lacuna';
                        $estadoUT[$u]['vagas'][$i]['treino'] = true; // não conta nos dias seguidos, como o Overlap
                        foreach (array_keys($overlapUT) as $u2) {
                            unset($overlapUT[$u2][$rep]);
                        }
                        $usado[$rep] = true;
                    }
                }

                // 5. Página de faturamento maior ainda vazia: o SR do posto de menor faturamento da unidade
                //    (abaixo dela) muda para lá; a lacuna fica na página menor. Não tira o regular de uma
                //    página que tem assistente alocado.
                $temAssistente = static function (array $vagasU, int $j) use ($est): bool {
                    $w = $est['postos'][$vagasU[$j]['pid']];
                    if ($w['tipo'] === 'assistente') {
                        return false;
                    }
                    foreach ($w['paginas'] as $pg) {
                        if ($pg['apoio']) {
                            continue;
                        }
                        $assist = false;
                        $outroRegular = false;
                        foreach ($vagasU as $k => $x) {
                            if ($k === $j || $x['rep'] === null) {
                                continue;
                            }
                            foreach ($est['postos'][$x['pid']]['paginas'] as $pg2) {
                                if ($pg2['pagina'] !== $pg['pagina']) {
                                    continue;
                                }
                                if ($est['postos'][$x['pid']]['tipo'] === 'assistente' || $pg2['apoio']) {
                                    $assist = true;
                                } else {
                                    $outroRegular = true;
                                }
                            }
                        }
                        if ($assist && !$outroRegular) {
                            return true;
                        }
                    }
                    return false;
                };
                foreach ($lacunas as [$u, $i, $rec]) {
                    if ($estadoUT[$u]['vagas'][$i]['rep'] !== null || $rec <= 0) {
                        continue;
                    }
                    $alvo5 = $est['postos'][$estadoUT[$u]['vagas'][$i]['pid']];
                    $alvoPrio = (bool) array_filter($alvo5['paginas'], static fn($pg) => !$pg['apoio'] && isset($est['prioritaria'][$pg['pagina']]));
                    // Hoje sobe o SR do posto que menos fatura. A-04: antes o de melhor faixa; A-02: o Abaixo vai
                    // por último para página prioritária. O SR da equipe fixa não sai da página dele (A-04).
                    $fonte = null;
                    $kFonte = null;
                    foreach ($estadoUT[$u]['vagas'] as $j => $w) {
                        if ($w['rep'] === null || $j === $i || !empty($w['treino']) || $receitaPosto[$w['pid']] >= $rec
                            || ($faixaRemanejar && !empty($w['fixa'])) || !$pode($w['rep'], $alvo5)) {
                            continue;
                        }
                        $fx = $faixaRep[$w['rep']] ?? 1;
                        $k = [$faixaRemanejar ? $fx : (($abaixoForaTopo && $alvoPrio && $fx === 0) ? 0 : 1), -$receitaPosto[$w['pid']]];
                        if (($kFonte === null || $k > $kFonte) && !$temAssistente($estadoUT[$u]['vagas'], $j)) {
                            $fonte = $j;
                            $kFonte = $k;
                        }
                    }
                    if ($fonte !== null) {
                        $w = $estadoUT[$u]['vagas'][$fonte];
                        $estadoUT[$u]['vagas'][$i]['rep'] = $w['rep'];
                        $estadoUT[$u]['vagas'][$i]['origem'] = 'BUFFER_NUCLEO';
                        $estadoUT[$u]['vagas'][$i]['motivo'] = 'remanejado de ' . $est['postos'][$w['pid']]['pagina_principal']
                            . ' (faturamento menor) para a página de faturamento maior'
                            . ($faixaRemanejar ? ' (mérito ' . [2 => 'Acima', 1 => 'Na média', 0 => 'Abaixo'][$faixaRep[$w['rep']] ?? 1] . ')' : '')
                            . ($abaixoForaTopo && $alvoPrio && ($faixaRep[$w['rep']] ?? 1) === 0 ? ' · Abaixo em página prioritária' : '');
                        $estadoUT[$u]['vagas'][$fonte]['rep'] = null;
                        $estadoUT[$u]['vagas'][$fonte]['origem'] = null;
                        $estadoUT[$u]['vagas'][$fonte]['motivo'] = null;
                    }
                }
                $lacunas = $listarLacunas();

                // 6. Ainda vazio: um SR já alocado no turno da unidade acumula o posto inteiro, se a
                //    Política deixa juntar as páginas (mesma modelo até 3; modelos diferentes até 2) e a
                //    carga cabe na capacidade. Posto só de página desconsiderada (carga zero) vai para
                //    quem já faz a mesma modelo, se houver, mesmo sendo de apoio.
                $semAcumular = ['assistente' => true, 'apoio' => true];
                $acumula = [];
                foreach ($lacunas as [$u, $i]) {
                    $v = $estadoUT[$u]['vagas'][$i];
                    $alvo = $est['postos'][$v['pid']];
                    $alvoDesc = $receitaPosto[$v['pid']] < 0;
                    if ($v['rep'] !== null || (isset($semAcumular[$alvo['tipo']]) && !$alvoDesc) || !empty($alvo['exclusivo'])) {
                        continue;
                    }
                    $modelosAlvo = [];
                    foreach ($alvo['paginas'] as $pg) {
                        $modelosAlvo[$est['modelo_pagina'][$pg['pagina']] ?? $pg['pagina']] = true;
                    }
                    $melhor = null;
                    $kMelhor = null;
                    foreach ($estadoUT[$u]['vagas'] as $j => $w) {
                        $rep = $w['rep'];
                        if ($rep === null || $j === $i || isset($acumula[$rep]) || isset($todoDia[$rep]) || isset($umaPag[$rep]) || isset($multiPag[$rep]) || isset($soAssistGeral[$rep]) || isset($baixoFluxo[$rep]) || ($w['acumulada'] ?? false) || !$pode($rep, $alvo)) {
                            continue;
                        }
                        $base = $est['postos'][$w['pid']];
                        if (isset($semAcumular[$base['tipo']]) || !empty($base['exclusivo'])) {
                            continue;
                        }
                        if (!$mesmaPlat(escPlataformasPosto($base), escPlataformasPosto($alvo))) {
                            continue; // plataformas diferentes no mesmo turno (ex.: Privacy com OnlyFans)
                        }
                        $cap = escV4CapacidadeJunta($base, $alvo, $est['modelo_pagina'] ?? [], $est['desconsiderada'] ?? []);
                        $carga = (float) $base['carga'] + (float) $alvo['carga'];
                        if ($cap === null || $carga > $cap + 1e-9) {
                            continue;
                        }
                        $razao = $carga / $cap;
                        $mesmaModelo = 0;
                        foreach ($base['paginas'] as $pg) {
                            if (isset($modelosAlvo[$est['modelo_pagina'][$pg['pagina']] ?? $pg['pagina']])) {
                                $mesmaModelo = 1;
                            }
                        }
                        // A-03: o SR Abaixo vai por último (só acumula se ninguém mais couber); o resto fica igual.
                        $k = [$alvoDesc ? $mesmaModelo : 0, $faixaAcumulo && ($faixaRep[$rep] ?? 1) === 0 ? 0 : 1, -round($razao, 3), $afinidade($rep, $alvo), $rep];
                        if ($kMelhor === null || $k > $kMelhor) {
                            $kMelhor = $k;
                            $melhor = ['rep' => $rep, 'base' => $base, 'carga' => $carga, 'cap' => $cap, 'razao' => $razao];
                        }
                    }
                    if ($melhor !== null) {
                        $estadoUT[$u]['vagas'][$i]['rep'] = $melhor['rep'];
                        $estadoUT[$u]['vagas'][$i]['origem'] = 'BUFFER_NUCLEO';
                        $estadoUT[$u]['vagas'][$i]['acumulada'] = true;
                        $estadoUT[$u]['vagas'][$i]['motivo'] = 'acumula com ' . $melhor['base']['pagina_principal'] . ': '
                            . str_replace('.', ',', (string) round($melhor['carga'], 1)) . ' de ' . str_replace('.', ',', (string) round($melhor['cap'], 1))
                            . ' fãs/h (' . round($melhor['razao'] * 100) . '% da capacidade)';
                        $acumula[$melhor['rep']] = true;
                    }
                }

                // 7. Sobrecarga, página a página: cada página que ainda está em lacuna (do posto da modelo
                //    que mais fatura para o da que menos fatura; dentro do posto, a página que mais fatura)
                //    vai para um SR já alocado no turno da unidade que faz página da MESMA AGÊNCIA
                //    (5c_pages.pages.client), enquanto a carga dele fica até over_max (30%) acima da
                //    capacidade. Ele entra COBRINDO (papel de assistente, não é dono, não conta para a
                //    semana seguinte) e cede a página quando houver gente. Fica sinalizado (SOBRECARGA):
                //    previsão de problema de capacity.
                $maxOver = (float) ($est['over_max'] ?? 0);
                $agPag = $est['agencia_pagina'] ?? [];
                if ($maxOver > 0) {
                    $carregado = []; // [unidade][rep] = carga, capacidade, agências que já faz, páginas puxadas
                    foreach ($estadoUT as $uu => $stU) {
                        foreach ($stU['vagas'] as $w) {
                            if ($w['rep'] === null) {
                                continue;
                            }
                            $base = $est['postos'][$w['pid']];
                            $c = $carregado[$uu][$w['rep']] ?? ['carga' => 0.0, 'cap' => 0.0, 'agencias' => [], 'n' => 0, 'so_apoio' => true];
                            $c['carga'] += (float) $base['carga'];
                            $c['cap'] = max($c['cap'], (float) ($base['capacidade'] ?? 0) > 0 ? (float) $base['capacidade'] : 28.0);
                            $c['so_apoio'] = $c['so_apoio'] && isset($semAcumular[$base['tipo']]);
                            $c['exclusivo'] = ($c['exclusivo'] ?? false) || !empty($base['exclusivo']);
                            $c['plats'] = ($c['plats'] ?? []) + escPlataformasPosto($base);
                            foreach ($base['paginas'] as $pg) {
                                if (!isset($est['desconsiderada'][$pg['pagina']])) {
                                    $c['agencias'][$agPag[$pg['pagina']] ?? ('?' . $pg['pagina'])] = true;
                                }
                            }
                            $carregado[$uu][$w['rep']] = $c;
                        }
                    }
                    foreach ($listarLacunas() as [$u, $i]) {
                        $v = $estadoUT[$u]['vagas'][$i];
                        $alvo = $est['postos'][$v['pid']];
                        if ($v['rep'] !== null || isset($semAcumular[$alvo['tipo']]) || !empty($alvo['exclusivo'])) {
                            continue;
                        }
                        // Por modelo: as páginas da mesma modelo no posto (VIP, Free, OFTV, também a de apoio)
                        // vão juntas para o mesmo SR; a carga é a das páginas que não são de apoio nem desconsideradas.
                        $grupos = [];
                        foreach ($alvo['paginas'] as $pg) {
                            $m = $est['modelo_pagina'][$pg['pagina']] ?? $pg['pagina'];
                            $g = $grupos[$m] ?? ['paginas' => [], 'fans' => 0.0, 'receita' => 0.0, 'principal' => null];
                            $g['paginas'][] = $pg;
                            if (!$pg['apoio'] && !isset($est['desconsiderada'][$pg['pagina']])) {
                                $g['fans'] += (float) ($pg['fans_h'] ?? 0);
                                $r = (float) ($est['receita'][$pg['pagina']]['total'] ?? 0);
                                $g['receita'] += $r;
                                if ($g['principal'] === null || $r > (float) ($est['receita'][$g['principal']]['total'] ?? 0)) {
                                    $g['principal'] = $pg['pagina'];
                                }
                            }
                            $grupos[$m] = $g;
                        }
                        $grupos = array_values(array_filter($grupos, static fn($g) => $g['principal'] !== null));
                        usort($grupos, static fn($a, $b) => $b['receita'] <=> $a['receita']);
                        foreach ($grupos as $g) {
                            $ag = $agPag[$g['principal']] ?? ('?' . $g['principal']);
                            $fans = $g['fans'];
                            $melhor = null;
                            $kMelhor = null;
                            foreach ($carregado[$u] ?? [] as $rep => $c) {
                                if ($c['so_apoio'] || !empty($c['exclusivo']) || isset($todoDia[$rep]) || isset($umaPag[$rep]) || isset($multiPag[$rep]) || isset($soAssistGeral[$rep]) || isset($baixoFluxo[$rep]) || !isset($c['agencias'][$ag]) || array_intersect_key($foraPag[$rep] ?? [], array_column($g['paginas'], null, 'pagina'))) {
                                    continue;
                                }
                                if (!$mesmaPlat($c['plats'] ?? [], escPlataformasPosto(['paginas' => $g['paginas']])) || !$podePags((string) $rep, $g['paginas']) || !$corOk((string) $rep, $g['paginas'])) {
                                    continue; // plataformas diferentes, restrição do OM ou cor da página (P-02)
                                }
                                $razao = ($c['carga'] + $fans) / $c['cap'];
                                if ($razao > 1 + $maxOver + 1e-9) {
                                    continue;
                                }
                                // A-03: o SR Abaixo vai por último (só cobre se ninguém mais couber); o resto fica igual.
                                $k = [$faixaAcumulo && ($faixaRep[$rep] ?? 1) === 0 ? 0 : 1, -$c['n'], -round($razao, 3), $afinidade($rep, $alvo), $rep];
                                if ($kMelhor === null || $k > $kMelhor) {
                                    $kMelhor = $k;
                                    $melhor = ['rep' => $rep, 'razao' => $razao];
                                }
                            }
                            if ($melhor === null) {
                                continue;
                            }
                            $rep = $melhor['rep'];
                            $c = $carregado[$u][$rep];
                            $acima = $melhor['razao'] > 1 + 1e-9;
                            foreach ($g['paginas'] as $pg) {
                                $estadoUT[$u]['vagas'][$i]['cobre'][$pg['pagina']] = [
                                    'rep' => $rep, 'acima' => $acima,
                                    'motivo' => 'cobrindo, sem ser dono (mesma agência: ' . preg_replace('~^modelo:~', '', $ag) . '): '
                                        . str_replace('.', ',', (string) round($c['carga'] + $fans, 1)) . ' de ' . str_replace('.', ',', (string) round($c['cap'], 1))
                                        . ' fãs/h (' . round($melhor['razao'] * 100) . '% da capacidade) · cede quando houver gente'
                                        . ($acima ? ' · previsão de problema de capacity' : ''),
                                ];
                            }
                            $carregado[$u][$rep]['carga'] += $fans;
                            $carregado[$u][$rep]['n']++;
                            $carregado[$u][$rep]['plats'] = ($carregado[$u][$rep]['plats'] ?? []) + escPlataformasPosto(['paginas' => $g['paginas']]);
                            $sobrecargas += $acima ? 1 : 0;
                        }
                    }
                }
            }

            // Última opção (pedido de 04/11): o membro da equipe fixa da página que é de OUTRA unidade só entra
            // aqui, depois de todos os passos, na vaga que ficou vazia (sem ninguém cobrindo), e só se sobrou
            // sem posto no turno da unidade dele. Ex.: Eduardo Gois (U1) na Ivy Vale (U3).
            if (!$transicao && !empty($est['prioridade'])) {
                $sobraDe = [];
                foreach ($estadoUT as $uu => $stU) {
                    foreach (array_keys($stU['disp']) as $rep) {
                        if (!isset($usado[$rep])) {
                            $sobraDe[$rep] = $uu;
                        }
                    }
                }
                foreach (array_keys($sobraDe ? $estadoUT : []) as $uu) {
                    foreach ($estadoUT[$uu]['vagas'] as $iv => $v) {
                        if ($v['rep'] !== null || !empty($v['cobre'])) {
                            continue;
                        }
                        $p = $est['postos'][$v['pid']];
                        $melhor = null;
                        $kMelhor = null;
                        foreach ($p['paginas'] as $pg) {
                            foreach ($pg['apoio'] ? [] : ($est['prioridade'][$pg['pagina']] ?? []) as $pr) {
                                $rep = $pr['rep'];
                                if ($pr['de'] > $dia || ($pr['ate'] !== null && $pr['ate'] < $dia) || !isset($sobraDe[$rep]) || $sobraDe[$rep] === $uu
                                    || isset($usado[$rep]) || !$pode($rep, $p)) {
                                    continue;
                                }
                                $k = [$historico[$rep][$pg['pagina']] ?? 0, $rep];
                                if ($kMelhor === null || $k > $kMelhor) {
                                    $kMelhor = $k;
                                    $melhor = [$rep, $pg['pagina']];
                                }
                            }
                        }
                        if ($melhor !== null) {
                            [$rep, $pag] = $melhor;
                            $estadoUT[$uu]['vagas'][$iv]['rep'] = $rep;
                            $estadoUT[$uu]['vagas'][$iv]['origem'] = 'BUFFER_NUCLEO';
                            $estadoUT[$uu]['vagas'][$iv]['motivo'] = 'equipe fixa da página ' . $pag . ', de outra unidade (U' . $sobraDe[$rep] . '): última opção, sem mais ninguém da U' . $uu;
                            $estadoUT[$uu]['vagas'][$iv]['fixa'] = true;
                            $usado[$rep] = true;
                        }
                    }
                }
            }

            // P-02: turnos do dia em que a página amarela ficou com quem não a conhece (uma vez por página).
            if ($corAtiva) {
                $novoHoje = [];
                foreach ($estadoUT as $stC) {
                    foreach ($stC['vagas'] as $vC) {
                        $pares = [];
                        if ($vC['rep'] !== null) {
                            foreach ($est['postos'][$vC['pid']]['paginas'] as $pgC) {
                                if (empty($pgC['apoio'])) {
                                    $pares[] = [$vC['rep'], (string) $pgC['pagina']];
                                }
                            }
                        }
                        foreach ($vC['cobre'] ?? [] as $pgN => $cb) {
                            $pares[] = [(string) $cb['rep'], (string) $pgN];
                        }
                        foreach ($pares as [$rC, $pgN]) {
                            if (escCorDe($pgN) === 'AMARELA' && !isset($conheceCor[$rC][$pgN]) && !$fixoNaPag($rC, $pgN, $dia)) {
                                $novoHoje[$pgN . '|' . $turno] = true;
                            }
                        }
                    }
                }
                foreach (array_keys($novoHoje) as $kC) {
                    $novatosAmarela[$kC] = ($novatosAmarela[$kC] ?? 0) + 1;
                }
            }

            // Saída por página e bloco
            foreach ($estadoUT as $u => $st) {
                // Postos cobertos pelo treinamento: a célula fica marcada (sem SR da escala normal).
                foreach ($st['cobertos'] ?? [] as $pid => $motivosTreino) {
                    foreach ($est['postos'][$pid]['paginas'] as $pg) {
                        $motivoTreino = $motivosTreino[$pg['pagina']] ?? reset($motivosTreino);
                        foreach ($blocos as $bloco) {
                            $diaB = $diaBloco[$bloco];
                            if (isset($travadasPorCelula[$diaB . '|' . $bloco . '|' . $pg['page_id']])) {
                                continue;
                            }
                            $alocacoes[] = ['dia' => $diaB, 'bloco' => $bloco, 'turno' => $turno, 'page_id' => $pg['page_id'], 'pagina' => $pg['pagina'],
                                'posto_id' => $pid, 'vaga' => null, 'rep_code' => null, 'papel' => 'REGULAR', 'origem' => 'TREINO', 'travada' => 0, 'motivo' => $motivoTreino];
                            $resumoOrigem['TREINO'] = ($resumoOrigem['TREINO'] ?? 0) + 1;
                        }
                    }
                }
                foreach ($st['vagas'] as $v) {
                    $vagasDia++;
                    $p = $est['postos'][$v['pid']];
                    if ($v['rep'] === null) {
                        $vagasSemNinguem++;
                        $lacunasUT[$u . $turno] = ($lacunasUT[$u . $turno] ?? 0) + 1;
                    } else {
                        $feito[$v['rep']][$v['chave']] = ($feito[$v['rep']][$v['chave']] ?? 0) + 1;
                        $trabalhou[$v['rep']][$dia] = true; // inclusive o Overlap usado para cobrir lacuna
                        $ultimo[$v['pid']] = $v['rep'];
                    }
                    foreach ($p['paginas'] as $pg) {
                        $paginaTreino = $treinoCobre[$dia][$v['pid']]['paginas'][$pg['pagina']] ?? null;
                        foreach ($blocos as $bloco) {
                            $diaB = $diaBloco[$bloco];
                            if (isset($travadasPorCelula[$diaB . '|' . $bloco . '|' . $pg['page_id']])) {
                                continue;
                            }
                            if ($paginaTreino !== null) {
                                // Página do posto que está no treinamento: o SR fica só com as outras.
                                $alocacoes[] = ['dia' => $diaB, 'bloco' => $bloco, 'turno' => $turno, 'page_id' => $pg['page_id'], 'pagina' => $pg['pagina'],
                                    'posto_id' => $v['pid'], 'vaga' => $v['vaga'], 'rep_code' => null, 'papel' => 'REGULAR', 'origem' => 'TREINO', 'travada' => 0, 'motivo' => $paginaTreino];
                                $resumoOrigem['TREINO'] = ($resumoOrigem['TREINO'] ?? 0) + 1;
                                continue;
                            }
                            $cobre = $v['cobre'][$pg['pagina']] ?? null; // página puxada por quem só cobre (passo 7)
                            $rep = $cobre['rep'] ?? $v['rep'];
                            if ($rep !== null) {
                                $ind = $indisp[$rep][$dia][$turno] ?? null;
                                if ($ind && is_array($ind['blocos']) && in_array($bloco, $ind['blocos'], true)) {
                                    $rep = null; // indisponível só neste bloco
                                }
                            }
                            $origem = $rep === null ? 'LACUNA' : ($cobre ? ($cobre['acima'] ? 'SOBRECARGA' : 'BUFFER_NUCLEO') : $v['origem']);
                            $alocacoes[] = [
                                'dia' => $diaB, 'bloco' => $bloco, 'turno' => $turno,
                                'page_id' => $pg['page_id'], 'pagina' => $pg['pagina'],
                                'posto_id' => $v['pid'], 'vaga' => $v['vaga'],
                                'rep_code' => $rep,
                                'papel' => 'REGULAR', // definido no fim: o primeiro da página é o regular
                                'origem' => $origem, 'travada' => 0,
                                'motivo' => $rep === null ? 'ninguém disponível na unidade e turno' : ($cobre['motivo'] ?? $v['motivo']),
                                '_ordem' => [$cobre ? 1 : 0, isset($soAssist[(string) $rep][$pg['pagina']]) ? 1 : 0, $pg['apoio'] ? 1 : 0, ESC_V4_ORDEM_TIPO[$p['tipo']] ?? 9, $v['vaga'] === 'DONO' ? 0 : 1, (string) $rep],
                            ];
                            $resumoOrigem[$origem] = ($resumoOrigem[$origem] ?? 0) + 1;
                        }
                    }
                }
                // Quem ficou sem vaga é o buffer de plantão do núcleo: vai para a página 5C Schedule
                // como Buffer (no CSV, código com "-2").
                $pgBuf = $est['overlap_pagina'] ?? null;
                foreach ($st['disp'] as $rep => $p) {
                    if (isset($usado[$rep])) {
                        continue;
                    }
                    $ociosos[] = ['rep_code' => $rep, 'dia' => $dia, 'turno' => $turno, 'tipo' => 'BUFFER_V4', 'motivo' => 'buffer de plantão do núcleo'];
                    $trabalhou[$rep][$dia] = true;
                    if ($pgBuf === null) {
                        continue;
                    }
                    foreach ($blocos as $bloco) {
                        $diaB = $diaBloco[$bloco];
                        $ind = $indisp[$rep][$dia][$turno] ?? null;
                        if (isset($travadasPorCelula[$diaB . '|' . $bloco . '|' . $pgBuf['page_id']])
                            || ($ind && is_array($ind['blocos']) && in_array($bloco, $ind['blocos'], true))) {
                            continue;
                        }
                        $alocacoes[] = [
                            'dia' => $diaB, 'bloco' => $bloco, 'turno' => $turno,
                            'page_id' => $pgBuf['page_id'], 'pagina' => $pgBuf['pagina'], 'posto_id' => null, 'vaga' => null,
                            'rep_code' => $rep, 'papel' => 'REGULAR', 'origem' => 'BUFFER', 'travada' => 0,
                            'motivo' => 'buffer de plantão do núcleo (unidade ' . $u . ')',
                        ];
                        $resumoOrigem['BUFFER'] = ($resumoOrigem['BUFFER'] ?? 0) + 1;
                    }
                }
            }

            // Overlap: no dia de treinamento o SR que não cobriu lacuna vai para a página 5C Schedule
            // (no CSV, código com "-5"). De lá se decide se vira buffer ou treinamento.
            $pgOv = $est['overlap_pagina'] ?? null;
            foreach ($overlapUT as $u => $reps) {
                foreach ($reps as $rep => $p) {
                    if ($pgOv === null) {
                        $folgas[] = ['rep_code' => $rep, 'dia' => $dia, 'tipo' => 'ROTACAO', 'ref_id' => null];
                        continue;
                    }
                    foreach ($blocos as $bloco) {
                        $diaB = $diaBloco[$bloco];
                        if (isset($travadasPorCelula[$diaB . '|' . $bloco . '|' . $pgOv['page_id']])) {
                            continue;
                        }
                        $ind = $indisp[$rep][$dia][$turno] ?? null;
                        if ($ind && is_array($ind['blocos']) && in_array($bloco, $ind['blocos'], true)) {
                            continue;
                        }
                        $alocacoes[] = [
                            'dia' => $diaB, 'bloco' => $bloco, 'turno' => $turno,
                            'page_id' => $pgOv['page_id'], 'pagina' => $pgOv['pagina'],
                            'posto_id' => null, 'vaga' => null,
                            'rep_code' => $rep, 'papel' => 'REGULAR', 'origem' => 'OVERLAP', 'travada' => 0,
                            'motivo' => 'Overlap: dia de treinamento do 5x2 (buffer ou treinamento a decidir)',
                        ];
                        $resumoOrigem['OVERLAP'] = ($resumoOrigem['OVERLAP'] ?? 0) + 1;
                    }
                    $overlaps[] = ['rep_code' => $rep, 'dia' => $dia, 'turno' => $turno];
                    $trabalhou[$rep][$dia] = true; // o dia de treinamento é dia de trabalho (dias seguidos)
                }
            }
        }
    }

    // Travadas entram como estão.
    foreach ($in['travadas'] ?? [] as $t) {
        $alocacoes[] = [
            'dia' => $t['dia'], 'bloco' => $t['bloco'], 'turno' => $t['turno'],
            'page_id' => (int) $t['page_id'], 'pagina' => $t['pagina'], 'posto_id' => null, 'vaga' => null,
            'rep_code' => $t['rep_code'], 'papel' => $t['papel'], 'origem' => $t['origem'],
            'travada' => 1, 'motivo' => $t['motivo'],
        ];
    }

    // Regular e assistente não dizem quem é dono nem quem está cobrindo: em cada página e bloco, o
    // primeiro a entrar é o REGULAR e os próximos, que ajudam no capacity, são ASSISTENTE. Vai primeiro
    // quem é do posto da página (não de apoio), o titular antes do double, o dono antes da cobertura,
    // e por último quem só cobre (passo 7). Linha travada ou já postada com regular fica como está.
    $porCelula = [];
    foreach ($alocacoes as $k => $a) {
        if ($a['rep_code'] !== null) {
            $porCelula[$a['dia'] . '|' . $a['bloco'] . '|' . $a['page_id']][] = $k;
        }
    }
    foreach ($porCelula as $ks) {
        $temRegular = false;
        $livres = [];
        foreach ($ks as $k) {
            if (isset($alocacoes[$k]['_ordem'])) {
                $livres[] = $k;
            } elseif ($alocacoes[$k]['papel'] === 'REGULAR') {
                $temRegular = true;
            }
        }
        usort($livres, static fn($x, $y) => $alocacoes[$x]['_ordem'] <=> $alocacoes[$y]['_ordem']);
        foreach ($livres as $n => $k) {
            $alocacoes[$k]['papel'] = !$temRegular && $n === 0 ? 'REGULAR' : 'ASSISTENTE';
        }
    }
    foreach ($alocacoes as $k => $a) {
        unset($alocacoes[$k]['_ordem']);
    }

    // Anomalias: dias seguidos (realizado + plano) acima do modelo.
    $anomalias = [];
    foreach ($pessoas as $rep => $p) {
        $run = 0;
        for ($d = escMaisDias($inicioPlano, -14); $d < $inicioPlano; $d = escMaisDias($d, 1)) {
            $run = isset($realizado[$rep][$d]) ? $run + 1 : 0;
        }
        foreach ($dias as $dia) {
            if ($dia < $virada) {
                continue;
            }
            $run = isset($trabalhou[$rep][$dia]) ? $run + 1 : 0;
            if ($run > $limite) {
                $anomalias[] = ['rep_code' => $rep, 'dia' => $dia, 'seguidos' => $run, 'limite' => $limite];
            }
        }
    }

    $lacunas = count(array_filter($alocacoes, static fn($a) => $a['rep_code'] === null && $a['origem'] !== 'TREINO'));
    ksort($lacunasUT);
    return [
        'alocacoes' => $alocacoes,
        'folgas' => $folgas,
        'ociosos' => $ociosos,
        'resumo' => [
            'estrutura' => 'V4',
            'blocos' => count($alocacoes),
            'lacunas' => $lacunas,
            'por_origem' => $resumoOrigem,
            'anomalias' => count($anomalias),
            'folgas_emenda' => count($emendas),
            'folgas_emenda_lista' => array_slice($emendas, 0, 100),
            'anomalias_lista' => array_slice($anomalias, 0, 100),
            'buffers_ociosos' => count($ociosos),
            'pessoas' => count($pessoas),
            'vagas_dia' => $vagasDia,
            'vagas_sem_ninguem' => $vagasSemNinguem,
            'vagas_sem_ninguem_por_unidade_turno' => $lacunasUT,
            'paginas_sem_party' => [],
            'overlaps' => count($overlaps),
            'sobrecargas' => $sobrecargas,
        ],
    ];
}
