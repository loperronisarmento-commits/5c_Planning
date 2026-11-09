<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/paginas_info.php
// Informações das páginas: chave do nome, OFTV, configuração (SLA, alocação), cor, plataforma, tier, moeda e faturamento.
// Separado de lib/motor_v4.php em 04/11 (1.19.18), só movendo as funções, sem mudar nada.
// Carregado por lib/motor_v4.php (require_once no topo).
// ===============================================

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
function escV4ReceitaPaginas(array $nomes, int $n, bool $corte = true): array {
    $de = [];
    foreach ($nomes as $nm) {
        $de[escV4ChavePagina((string) $nm)][] = (string) $nm;
    }
    if (!$de) {
        return [];
    }
    $n = $corte ? escDiasCorte(max(1, $n)) : max(1, $n);
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
