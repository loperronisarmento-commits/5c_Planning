<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/estrutura.php
// Estrutura atualizada antes de gerar (escMotorGerar, estrutura nova), para a escala não
// sair com o Capacity defasado:
//   1. Página ativa no site e com movimento (escala no 5C Schedule ou venda nos últimos
//      v4_estrutura_movimento_dias, 14), ou cadastrada no site há até v4_estrutura_nova_dias (7)
//      — página que o Comercial acabou de incluir —, que não está no Capacity entra sozinha, com vigência a
//      partir do início da estrutura do Capacity (a menor data da carteira: o Capacity usa essa
//      data como referência; uma data anterior tiraria do cálculo todas as outras páginas): na carteira da mesma modelo, se houver;
//      senão no destino mais indicado do Funil. Enquanto a página não tem chat próprio, o
//      volume vem do arquétipo de uma página parecida (mesmo tier e perfil: Main, VIP, Free
//      ou OFTV), como no Funil.
//   2. O Capacity é recalculado para hoje (sp_fc_recalcular_postos, que grava a versão).
//   3. Os postos da escala seguem os do Capacity a partir de amanhã (nunca antes da estrutura
//      nova): posto igual (mesma carteira, turno e páginas) continua com a equipe; posto que
//      mudou fecha, e a equipe dele vai para o posto novo que ficou com a maior parte das
//      páginas dele. Página que o Capacity deixou sem posto num turno (pouco chat) entra como
//      apoio no posto da mesma modelo, ou no de menor carga: nenhuma página fica sem equipe.
//   4. Vaga que ficou sem ninguém é completada em seguida (lib/equipes.php).
// Roda no máximo uma vez a cada v4_estrutura_minutos (30), fora do Modo Treino e fora de
// outra transação (a saída de página já recalcula o Capacity do jeito dela).
// ===============================================

// Perfil da página pelo nome (o mesmo critério dos arquétipos do Funil).
function escPaginaPerfil(string $nome): string {
    if (preg_match('~oftv|\b\d+t\b~i', $nome)) {
        return 'OFTV';
    }
    if (stripos($nome, 'vip') !== false) {
        return 'VIP';
    }
    return stripos($nome, 'free') !== false ? 'FREE' : 'MAIN';
}

// Chave da modelo a partir do nome da página: sem perfil (VIP, Free, OFTV) e sem código (147t).
// Ex.: "zOFTV Diana Rider" → "dianarider"; "Thalia 147t" → "thalia"; "Haven  Free" → "haven".
function escPaginaChaveModelo(string $nome): string {
    $partes = array_filter(preg_split('~\s+~', trim($nome)), static fn($w) => !preg_match('~^(z?oftv|vip|free|paid|\d+[a-z]?)$~i', $w));
    return escFunilChave(implode('', $partes) ?: $nome);
}

// ---------- Experiência e meta pela modelo (pedido de 11/11) ----------
// Conhecer uma das páginas da modelo basta (ex.: Mikayla Free ou Mikayla Paid): os dias e a meta de quem
// atende a modelo contam para todas as páginas dela. A venda por hora continua por página.
// Modelo de cada página: a do Capacity (fc_pagina_carteira.modelo, a linha mais nova de cada página);
// sem ela, pelo nome (escPaginaChaveModelo). Chave da página: escV4ChavePagina.
function escModeloMapa(): array {
    static $mapa = null;
    if ($mapa === null) {
        $mapa = [];
        try {
            foreach (escQ('SELECT pagina, modelo FROM `5c_planning`.`fc_pagina_carteira` WHERE modelo IS NOT NULL AND modelo <> \'\' ORDER BY vigente_desde') as $r) {
                $mapa[escV4ChavePagina((string) $r['pagina'])] = (string) $r['modelo'];
            }
        } catch (Throwable $e) {
            appLog('warning', 'Modelo das páginas indisponível (Capacity)', ['erro' => $e->getMessage()]);
        }
    }
    return $mapa;
}

function escModeloDaPagina(string $pagina): string {
    return escModeloMapa()[escV4ChavePagina($pagina)] ?? escPaginaChaveModelo($pagina);
}

// Páginas abertas no Capacity de cada modelo: [modelo] = [páginas].
function escModeloPaginasAbertas(): array {
    static $porModelo = null;
    if ($porModelo === null) {
        $porModelo = [];
        try {
            foreach (escQ('SELECT DISTINCT pagina FROM `5c_planning`.`fc_pagina_carteira` WHERE vigente_ate IS NULL OR vigente_ate > CURDATE()') as $r) {
                $porModelo[escModeloDaPagina((string) $r['pagina'])][] = (string) $r['pagina'];
            }
        } catch (Throwable $e) {
            appLog('warning', 'Páginas por modelo indisponíveis (Capacity)', ['erro' => $e->getMessage()]);
        }
    }
    return $porModelo;
}

// Dias distintos de cada SR na modelo: $linhas com rep, pagina e dia (um por rep × página × dia, do
// 5C Schedule). Volta [rep][modelo][dia] = true; o mesmo dia em duas páginas da modelo conta uma vez.
function escModeloDiasSet(iterable $linhas): array {
    $set = [];
    foreach ($linhas as $l) {
        $set[strtoupper((string) $l['rep'])][escModeloDaPagina((string) $l['pagina'])][substr((string) $l['dia'], 0, 10)] = true;
    }
    return $set;
}

// Dias de $rep na modelo de $pagina, a partir de $desde (AAAA-MM-DD; '' = todos do conjunto).
function escModeloDias(array $set, string $rep, string $pagina, string $desde = ''): int {
    $dias = $set[strtoupper($rep)][escModeloDaPagina($pagina)] ?? [];
    if ($desde === '') {
        return count($dias);
    }
    return count(array_filter(array_keys($dias), static fn($d) => $d >= $desde));
}

// Primeiro nome da página, sem perfil nem código ("zOFTV Diana Rider" → "diana").
function escPaginaPrimeiroNome(string $nome): string {
    foreach (preg_split('~\s+~', trim($nome)) as $w) {
        if (!preg_match('~^(z?oftv|vip|free|paid|\d+[a-z]?)$~i', $w)) {
            return escFunilChave($w);
        }
    }
    return '';
}

// Lista "sem escala" do Capacity (fc_pagina_sem_escala: nome exato ou padrão LIKE com % e _).
// Devolve fn(pagina): bool. Sem a tabela (antes do script 23 do Capacity), nenhuma página.
function escEstruturaSemEscala(): callable {
    $lista = [];
    try {
        $existe = escQ1("SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = '5c_planning' AND TABLE_NAME = 'fc_pagina_sem_escala'");
        foreach ($existe ? escQ('SELECT padrao FROM `5c_planning`.`fc_pagina_sem_escala`') : [] as $l) {
            $lista[] = '~^' . strtr(preg_quote(trim((string) $l['padrao']), '~'), ['%' => '.*', '_' => '.']) . '$~iu';
        }
    } catch (Throwable $e) {
        appLog('warning', 'Estrutura: lista sem escala do Capacity ilegível', ['erro' => $e->getMessage()]);
    }
    return static function (string $pagina) use ($lista): bool {
        foreach ($lista as $r) {
            if (preg_match($r, trim($pagina))) {
                return true;
            }
        }
        return false;
    };
}

// Páginas ativas no site, com movimento, fora do Capacity em $hoje, já com carteira, modelo e volume.
function escEstruturaPaginasFora(string $hoje): array {
    $de = escMaisDias($hoje, -max(1, (int) escV4Param('v4_estrutura_movimento_dias', '14')));
    $novaDe = escMaisDias($hoje, -max(0, (int) escV4Param('v4_estrutura_nova_dias', '7')));
    // Escala somada de uma vez e juntada aqui (uma subconsulta por página lia milhões de linhas).
    $chaveNome = static fn($p) => mb_strtolower(trim((string) $p));
    $escalaDe = [];
    foreach (escQ('SELECT modeloNome AS p, COUNT(*) AS n FROM `5c_schedule`.`scheduleday` WHERE shiftDateAndHour >= ? GROUP BY modeloNome', [$de . 'T00:00:00']) as $l) {
        $escalaDe[$chaveNome($l['p'])] = ($escalaDe[$chaveNome($l['p'])] ?? 0) + (int) $l['n'];
    }
    $lista = [];
    foreach (escQ('SELECT g.id, g.name AS pagina, g.tier, g.imported_at,
                          (SELECT SUM(i.`5CReps`) FROM `5c_wfm_db_mis`.`tab_intraday_base` i WHERE i.Page = g.name AND i.`Date` >= ?) AS venda
                     FROM `5c_pages`.`pages` g
                    WHERE g.active = 1 AND g.name <> ?', [$de, escV4Param('v4_overlap_pagina', '5C Schedule')]) as $l) {
        $lista[] = $l + ['escala' => $escalaDe[$chaveNome($l['pagina'])] ?? 0];
    }
    // Já com carteira (comparando sem espaços e sem caixa): no PHP, porque a trava de leitura (escQ)
    // bloqueia a palavra REPLACE no SQL.
    $semEspaco = static fn($p) => mb_strtolower(str_replace(' ', '', trim((string) $p)));
    $comCarteira = array_flip(array_map($semEspaco, array_column(escQ('SELECT pagina FROM `5c_planning`.`fc_pagina_carteira`
                     WHERE vigente_ate IS NULL OR vigente_ate > ?', [$hoje]), 'pagina')));
    // Página que saiu pela tela Saídas (esc_saida_pagina APLICADA) nos últimos v4_saida_bloqueio_dias (60) não
    // volta sozinha, mesmo ativa no site (ex.: Andressa Urach em 04/11: saía e entrava de novo como modelo nova,
    // e a segunda entrada no mesmo dia dava chave duplicada). Para voltar antes, a entrada é pelo Funil.
    $saiu = [];
    try {
        foreach (escQ('SELECT pagina, MAX(criado_em) AS quando FROM ' . escT('esc_saida_pagina') . " WHERE status = 'APLICADA' AND vale_desde >= ? GROUP BY pagina",
            [escMaisDias($hoje, -max(1, (int) escV4Param('v4_saida_bloqueio_dias', '60')))]) as $s) {
            $saiu[$semEspaco($s['pagina'])] = (string) $s['quando'];
        }
        // Destino novo no Funil depois da saída: a página volta normalmente.
        foreach ($saiu ? escQ('SELECT pagina, GREATEST(criado_em, COALESCE(atualizado_em, criado_em)) AS quando FROM ' . escT('esc_pagina_previsao')) : [] as $pv) {
            $k = $semEspaco($pv['pagina']);
            if (isset($saiu[$k]) && (string) $pv['quando'] > $saiu[$k]) {
                unset($saiu[$k]);
            }
        }
    } catch (Throwable $e) {
        // tabela ainda não criada
    }
    // Página marcada como "Ignorar" no Funil de entrada (não é página de escala) também não entra sozinha,
    // a não ser que depois tenha uma entrada ("Pôr na escala"). Ex.: MYM Nicole voltava a cada Gerar.
    try {
        foreach (escQ('SELECT pagina, decisao FROM ' . escT('esc_pagina_entrada_checagem') . ' ORDER BY decidido_em, id') as $d) {
            if ($d['decisao'] === 'IGNORAR') {
                $saiu[$semEspaco($d['pagina'])] = 'ignorar';
            } else {
                unset($saiu[$semEspaco($d['pagina'])]);
            }
        }
    } catch (Throwable $e) {
        // tabela ainda não criada (ES_138)
    }
    $lista = array_values(array_filter($lista, static fn($l) => !isset($comCarteira[$semEspaco($l['pagina'])]) && !isset($saiu[$semEspaco($l['pagina'])])
        && ((int) $l['escala'] > 0 || (float) $l['venda'] > 0
            || ($l['imported_at'] !== null && substr((string) $l['imported_at'], 0, 10) >= $novaDe))));
    if (!$lista) {
        return [];
    }
    $ctx = escFunilContexto($hoje);
    $arquetipos = escFunilArquetipos();
    $previsao = [];
    foreach (escQ('SELECT pagina, perfil, fans_h_a, fans_h_c, fans_h_d, destino_unidade FROM ' . escT('esc_pagina_previsao') . ' WHERE pagina IN (' . escIn(array_column($lista, 'pagina')) . ')', array_column($lista, 'pagina')) as $pv) {
        $previsao[$pv['pagina']] = $pv;
    }
    // Carteira com menos páginas em cada unidade (para modelo nova).
    $carteiraDe = [];
    foreach (escQ('SELECT c.carteira, c.unidade, (SELECT COUNT(*) FROM `5c_planning`.`fc_pagina_carteira` p WHERE p.carteira = c.carteira AND (p.vigente_ate IS NULL OR p.vigente_ate > ?)) AS n
                     FROM `5c_planning`.`fc_carteira` c WHERE c.ativo = 1 ORDER BY n, c.carteira', [$hoje]) as $c) {
        $carteiraDe[(int) $c['unidade']] = $carteiraDe[(int) $c['unidade']] ?? $c['carteira'];
    }
    // Página que já teve carteira numa unidade da 5C volta para a última delas (25/11: "se uma página saiu e
    // depois voltou, volta sempre pra onde ela estava"); o núcleo vem do último posto (escEstruturaHerdarNucleo).
    $antes = [];
    foreach (escQ('SELECT p.pagina, p.carteira, p.modelo, p.fas_h_estimado FROM `5c_planning`.`fc_pagina_carteira` p
                     JOIN `5c_planning`.`fc_carteira` c ON c.carteira = p.carteira AND c.ativo = 1
                    ORDER BY p.vigente_desde, p.vigente_ate IS NULL, p.vigente_ate') as $c) {
        $antes[$semEspaco($c['pagina'])] = $c;
    }
    $saida = [];
    $novaModelo = []; // [chave] = carteira da modelo nova já decidida nesta rodada
    foreach ($lista as $l) {
        $perfil = escPaginaPerfil($l['pagina']);
        $chave = escPaginaChaveModelo($l['pagina']);
        $tier = $l['tier'] !== null && $l['tier'] !== '' ? (string) $l['tier'] : null;
        $ult = $antes[$semEspaco($l['pagina'])] ?? null;
        if ($ult) {
            $fasAntes = $ult['fas_h_estimado'] !== null ? (float) $ult['fas_h_estimado'] : null;
            $deOnde = $fasAntes !== null ? 'volume de antes' : 'volume do arquétipo';
            if ($fasAntes === null) {
                [$v0] = escFunilVolume($previsao[$l['pagina']] ?? ['perfil' => $perfil, 'fans_h_a' => null, 'fans_h_c' => null, 'fans_h_d' => null], $tier, $arquetipos);
                $fasAntes = ($v0['A'] + $v0['C'] + $v0['D']) / 3;
            }
            $fas = round(max(0.1, $fasAntes), 2);
            $saida[] = ['pagina' => $l['pagina'], 'tier' => $tier, 'perfil' => $perfil, 'carteira' => (string) $ult['carteira'],
                'modelo' => trim((string) $ult['modelo']) !== '' ? (string) $ult['modelo'] : $chave, 'fas_h' => $fas,
                'motivo' => mb_substr('Voltou antes de gerar (ativa no site): carteira de antes (' . $ult['carteira'] . '); ' . $deOnde . ' (' . str_replace('.', ',', (string) $fas) . ' fãs/h)', 0, 255)];
            continue;
        }
        // Mesma modelo já no Capacity: a mesma chave, ou o mesmo primeiro nome (4 letras ou mais)
        // numa página dela (ex.: "Nicole OFTV" e "Nicole Doshi"; "Aria" não casa com "Ariana Marie").
        $irma = null;
        $nome1 = escPaginaPrimeiroNome($l['pagina']);
        foreach ($ctx['modelos'] as $m => $porUnidade) {
            $info = reset($porUnidade);
            $casa = $m === $chave ? 2 : (strlen($nome1) >= 4 && $nome1 === escPaginaPrimeiroNome((string) $info['pagina']) ? 1 : 0);
            if ($casa && ($irma === null || $casa > $irma['casa'])) {
                $irma = ['modelo' => $m, 'carteira' => $info['carteira'], 'pagina' => $info['pagina'], 'casa' => $casa];
            }
        }
        $pv = $previsao[$l['pagina']] ?? null;
        [$vol, $origem] = escFunilVolume($pv ?: ['perfil' => $perfil, 'fans_h_a' => null, 'fans_h_c' => null, 'fans_h_d' => null], $tier, $arquetipos);
        if ($irma) {
            $carteira = $irma['carteira'];
            $modelo = $irma['modelo'];
            $onde = 'mesma modelo de ' . $irma['pagina'];
        } elseif (isset($novaModelo[$chave])) {
            // Outra página da mesma modelo nova nesta rodada (ex.: Silvia Saige VIP e Free): mesma carteira.
            $carteira = $novaModelo[$chave];
            $modelo = $chave;
            $onde = 'modelo nova, junto da outra página dela';
        } else {
            $u = $pv && $pv['destino_unidade'] ? (int) $pv['destino_unidade'] : null;
            if ($u === null) {
                $sug = escFunilSugerir($vol, $tier, [$l['pagina']], $ctx);
                $u = $sug['top'][0]['nucleo']['unidade'] ?? null;
            }
            $carteira = $u !== null ? ($carteiraDe[$u] ?? null) : (reset($carteiraDe) ?: null);
            $modelo = $chave;
            $onde = 'modelo nova, destino do Funil';
            if ($carteira !== null) {
                $novaModelo[$chave] = $carteira;
            }
        }
        if ($carteira === null) {
            continue;
        }
        $fas = round(max(0.1, ($vol['A'] + $vol['C'] + $vol['D']) / 3), 2);
        $saida[] = ['pagina' => $l['pagina'], 'tier' => $tier, 'perfil' => $perfil, 'carteira' => $carteira, 'modelo' => $modelo, 'fas_h' => $fas,
            'motivo' => mb_substr('Entrou antes de gerar (ativa no site): ' . $onde . '; volume do ' . ($origem === 'previsao' ? 'Funil' : 'arquétipo ' . ($tier ?? '-') . ' ' . $perfil) . ' (' . str_replace('.', ',', (string) $fas) . ' fãs/h)', 0, 255)];
    }
    return $saida;
}

// Postos da escala = postos do Capacity de $dc, a partir de $desde. Rodar fora de outra transação.
function escEstruturaSincronizar(string $dc, string $desde): array {
    $res = ['mantidos' => 0, 'novos' => 0, 'fechados' => 0, 'equipe_movida' => 0, 'apoios' => []];
    // 1. Postos do Capacity
    $cap = [];
    foreach (escQ('SELECT carteira, turno, posto, unidade, tipo, pagina_principal, carga, capacidade FROM `5c_planning`.`fc_postos` WHERE data_calculo = ?', [$dc]) as $p) {
        $cap[$p['carteira'] . '|' . $p['turno'] . '|' . $p['posto']] = $p + ['paginas' => []];
    }
    if (!$cap) {
        return $res;
    }
    $modeloDe = [];
    foreach (escQ('SELECT DISTINCT pagina, modelo FROM `5c_planning`.`fc_pagina_carga` WHERE data_calculo = ?', [$dc]) as $m) {
        $modeloDe[$m['pagina']] = (string) $m['modelo'];
    }
    // Só páginas que continuam depois de $desde (a saída fecha a página com fim em $desde ou antes).
    $vale = array_flip(array_column(escQ('SELECT DISTINCT pagina FROM `5c_planning`.`fc_pagina_carteira` WHERE vigente_ate IS NULL OR vigente_ate > ?', [$desde]), 'pagina'));
    // Carteira da página em $desde. Troca de unidade que já vale em $desde, mas depois da
    // referência do Capacity (ele ainda põe a página na carteira antiga): a página não fica no
    // posto da carteira antiga (os postos do destino vêm de escEstruturaTrocasUnidade).
    $carteiraEm = array_column(escQ('SELECT pagina, carteira FROM `5c_planning`.`fc_pagina_carteira` WHERE vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate > ?)', [$desde, $desde]), 'carteira', 'pagina');
    $outraCarteira = static fn(string $pg, string $cart): bool => isset($carteiraEm[$pg]) && $carteiraEm[$pg] !== $cart;
    foreach (escQ('SELECT carteira, turno, posto, pagina, fans_h, apoio, fracao FROM `5c_planning`.`fc_postos_pagina` WHERE data_calculo = ?', [$dc]) as $g) {
        $k = $g['carteira'] . '|' . $g['turno'] . '|' . $g['posto'];
        if (isset($cap[$k]) && isset($vale[$g['pagina']]) && !$outraCarteira($g['pagina'], $g['carteira'])) {
            $cap[$k]['paginas'][$g['pagina']] = ['fans_h' => (float) $g['fans_h'], 'apoio' => (int) $g['apoio'], 'fracao' => (float) $g['fracao']];
        }
    }
    $cap = array_filter($cap, static fn($p) => (bool) $p['paginas']);
    // Página sem posto no turno (pouco chat): apoio no posto da mesma modelo; senão no de menor carga.
    // Página da lista "sem escala" do Capacity (fc_pagina_sem_escala, script 23, ex.: zOFTV%) fica na
    // carga mas não ocupa posto: não vira apoio.
    $semEscala = escEstruturaSemEscala();
    foreach (escQ('SELECT c.pagina, c.turno, c.carteira, c.fans_h FROM `5c_planning`.`fc_pagina_carga` c WHERE c.data_calculo = ?
                     AND NOT EXISTS (SELECT 1 FROM `5c_planning`.`fc_postos_pagina` pp WHERE pp.data_calculo = c.data_calculo AND pp.carteira = c.carteira AND pp.turno = c.turno AND pp.pagina = c.pagina)', [$dc]) as $o) {
        if (!isset($vale[$o['pagina']]) || $semEscala($o['pagina']) || $outraCarteira($o['pagina'], $o['carteira'])) {
            continue;
        }
        $doTurno = array_filter($cap, static fn($p) => $p['carteira'] === $o['carteira'] && $p['turno'] === $o['turno']);
        if (!$doTurno) {
            continue;
        }
        $alvo = null;
        foreach ($doTurno as $k => $p) {
            $mesma = $o['pagina'] !== '' && array_filter(array_keys($p['paginas']), static fn($pg) => ($modeloDe[$pg] ?? '') !== '' && ($modeloDe[$pg] ?? '') === ($modeloDe[$o['pagina']] ?? '-')) ? 1 : 0;
            $chave = [$mesma, -(float) $p['carga']];
            if ($alvo === null || $chave > $alvo[1]) {
                $alvo = [$k, $chave];
            }
        }
        $cap[$alvo[0]]['paginas'][$o['pagina']] = ['fans_h' => (float) $o['fans_h'], 'apoio' => 1, 'fracao' => 0.0];
        $res['apoios'][] = $o['pagina'] . ' (' . $o['turno'] . ')';
    }
    $assin = static fn(string $cart, string $turno, array $pags): string => $cart . '|' . $turno . '|' . implode('+', (static function ($p) { sort($p); return $p; })($pags));

    // 2. Postos da escala que valem em $desde ou depois (inclui os que começam mais tarde, como
    //    o de uma página nova: senão ficariam junto com os do Capacity).
    $vig = ' vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)';
    $esc = [];
    foreach (escQ('SELECT id, unidade, carteira, turno, tipo, carga, capacidade, nucleo_dono_id, nucleo_cobertura_id, vigente_desde FROM ' . escT('esc_posto') . ' WHERE vigente_ate IS NULL OR vigente_ate >= ?', [$desde]) as $p) {
        $esc[(int) $p['id']] = $p + ['paginas' => []];
    }
    foreach ($esc ? escQ('SELECT posto_id, pagina, fans_h FROM ' . escT('esc_posto_pagina') . ' WHERE posto_id IN (' . escIn(array_keys($esc)) . ')', array_keys($esc)) : [] as $g) {
        $esc[(int) $g['posto_id']]['paginas'][$g['pagina']] = (float) $g['fans_h'];
    }
    $porAssin = [];
    foreach ($esc as $id => $p) {
        $porAssin[$assin($p['carteira'], $p['turno'], array_keys($p['paginas']))][] = $id;
    }
    $mantidos = [];
    $novos = [];
    foreach ($cap as $k => $p) {
        $a = $assin($p['carteira'], $p['turno'], array_keys($p['paginas']));
        if (!empty($porAssin[$a])) {
            $mantidos[array_shift($porAssin[$a])] = $k;
        } else {
            $novos[$k] = $p;
        }
    }
    $fechar = array_diff_key($esc, $mantidos);
    // Página que só começa depois da referência do cálculo (ex.: Andressa a partir de 02/11, com o
    // Capacity de hoje olhando 01/11) não está no Capacity ainda: o posto dela fica como está.
    // O mesmo vale para a troca de unidade com data depois da referência (ex.: Mikayla para a U3
    // em 02/11): o posto do destino, na carteira nova, fica como está.
    $capPags = [];
    foreach ($cap as $p) {
        foreach (array_keys($p['paginas']) as $pg) {
            $capPags[$pg][$p['carteira']] = true;
        }
    }
    $ref = max($dc, (string) (escQ1('SELECT MIN(vigente_desde) AS d FROM `5c_planning`.`fc_pagina_carteira`')['d'] ?? $dc));
    $futuras = [];
    foreach (escQ('SELECT DISTINCT pagina, carteira FROM `5c_planning`.`fc_pagina_carteira` WHERE vigente_desde > ? AND (vigente_ate IS NULL OR vigente_ate > ?)', [substr($ref, 0, 10), $desde]) as $l) {
        $futuras[$l['pagina']][$l['carteira']] = true;
    }
    $espera = static fn(string $pg, string $cart): bool => (!isset($capPags[$pg]) && isset($futuras[$pg])) || (isset($futuras[$pg][$cart]) && !isset($capPags[$pg][$cart]));
    // E posto que só começa depois de $desde foi posto por uma saída ou troca com data marcada
    // (o refeito sem a página que sai, o do destino): o Capacity de hoje ainda não vê essa data.
    foreach ($fechar as $id => $f) {
        $pags = array_keys($f['paginas']);
        if (substr((string) $f['vigente_desde'], 0, 10) > $desde
            || ($pags && !array_filter($pags, static fn($pg) => !$espera((string) $pg, (string) $f['carteira'])))) {
            unset($fechar[$id]);
        }
    }
    $res['mantidos'] = count($esc) - count($fechar);
    // Trava: Capacity com bem menos páginas que a escala é cálculo com problema, não estrutura nova.
    $pagsCap = count(array_unique(array_merge([], ...array_map(static fn($p) => array_keys($p['paginas']), array_values($cap)))));
    $pagsEsc = count(array_filter(array_keys(array_diff_key(array_flip(array_merge([], ...array_map(static fn($p) => array_keys($p['paginas']), array_values($esc)))), $futuras)),
        static fn($pg) => !$semEscala((string) $pg)));
    $minimo = (float) str_replace(',', '.', escV4Param('v4_estrutura_minimo_paginas', '0.9'));
    if ($pagsEsc > 0 && $pagsCap < $minimo * $pagsEsc) {
        throw new RuntimeException(t('o Capacity de hoje veio com {n} páginas e a escala tem {m}; os postos não foram trocados. Confira o cálculo do Capacity', ['n' => $pagsCap, 'm' => $pagsEsc]));
    }
    if (!$novos && !$fechar) {
        return $res;
    }

    // 3. Núcleos dos postos novos: os dos postos que fecham com as mesmas páginas (pelo volume).
    $nucleos = [];
    foreach (escQ('SELECT id, unidade, turno FROM ' . escT('esc_nucleo') . ' WHERE' . $vig . ' ORDER BY numero', [$desde, $desde]) as $n) {
        $nucleos[$n['unidade'] . '|' . $n['turno']][] = (int) $n['id'];
    }
    $contaDono = [];
    $contaCob = [];
    foreach ($mantidos as $id => $k) {
        $contaDono[(int) $esc[$id]['nucleo_dono_id']] = ($contaDono[(int) $esc[$id]['nucleo_dono_id']] ?? 0) + 1;
        $contaCob[(int) $esc[$id]['nucleo_cobertura_id']] = ($contaCob[(int) $esc[$id]['nucleo_cobertura_id']] ?? 0) + 1;
    }
    $partilha = static function (array $novo, array $velho): float {
        $s = 0.0;
        foreach ($novo['paginas'] as $pg => $g) {
            if (isset($velho['paginas'][$pg])) {
                $s += max(0.1, $g['fans_h']);
            }
        }
        return $s;
    };
    foreach ($novos as $k => &$n) {
        $ut = $n['unidade'] . '|' . $n['turno'];
        $pesoDono = [];
        $pesoCob = [];
        foreach ($fechar as $f) {
            if ((int) $f['unidade'] !== (int) $n['unidade'] || $f['turno'] !== $n['turno']) {
                continue;
            }
            $s = $partilha($n, $f);
            if ($s > 0) {
                $pesoDono[(int) $f['nucleo_dono_id']] = ($pesoDono[(int) $f['nucleo_dono_id']] ?? 0) + $s;
                if ($f['nucleo_cobertura_id'] !== null) {
                    $pesoCob[(int) $f['nucleo_cobertura_id']] = ($pesoCob[(int) $f['nucleo_cobertura_id']] ?? 0) + $s;
                }
            }
        }
        $doTurno = $nucleos[$ut] ?? [];
        $escolher = static function (array $peso, array $conta, ?int $fora) use ($doTurno): ?int {
            $melhor = null;
            foreach ($doTurno as $id) {
                if ($id === $fora) {
                    continue;
                }
                $chave = [$peso[$id] ?? 0, -($conta[$id] ?? 0)];
                if ($melhor === null || $chave > $melhor[1]) {
                    $melhor = [$id, $chave];
                }
            }
            return $melhor[0] ?? null;
        };
        $n['dono'] = $escolher($pesoDono, $contaDono, null);
        $n['cob'] = $escolher($pesoCob, $contaCob, $n['dono']);
        if ($n['dono'] !== null) {
            $contaDono[$n['dono']] = ($contaDono[$n['dono']] ?? 0) + 1;
        }
        if ($n['cob'] !== null) {
            $contaCob[$n['cob']] = ($contaCob[$n['cob']] ?? 0) + 1;
        }
    }
    unset($n);
    if (escV4Param('v4_os_equilibrio', '1') === '1') {
        escEstruturaDividirOS($novos, $mantidos, $esc, $cap, $nucleos, $modeloDe);
    }
    // A página não troca de núcleo (25/11): o posto refeito ou de página que volta fica no núcleo do último
    // posto dela na mesma unidade e turno, por cima da escolha automática e da divisão dos OS.
    if (escV4Param('v4_posto_herda_nucleo', '1') === '1') {
        escEstruturaHerdarNucleo($novos, $nucleos);
    }
    // Núcleo escolhido na entrada da página (Funil de entrada, 1.25.1) vale por cima da escolha automática.
    escEntradaFixarNucleos($novos, $nucleos, $desde);

    $ontem = escMaisDias($desde, -1);
    // Posto que nunca chegou a valer (começava em $desde ou depois) vai para antes da virada,
    // onde o motor não lê postos da estrutura nova (mesma convenção da saída de página).
    $neutra = escMaisDias(escV4DiaVirada(), -2);
    $versao = 'CAP' . str_replace('-', '', $dc) . '_' . date('His');
    // Código da página no site pelo nome, sem diferença de maiúsculas e espaços (o Capacity
    // escreve "Mandi CAT" e o site "Mandi Cat"); a ativa de id maior ganha.
    $chaveNome = static fn(string $n): string => mb_strtolower(preg_replace('~\s+~', ' ', trim($n)));
    $paginaId = [];
    $todas = array_values(array_unique(array_merge(...array_map(static fn($p) => array_keys($p['paginas']), array_values($novos)) ?: [[]])));
    foreach ($todas ? escQ('SELECT name, id FROM `5c_pages`.`pages` WHERE name IN (' . escIn($todas) . ') ORDER BY active, id', $todas) : [] as $g) {
        $paginaId[$chaveNome($g['name'])] = (int) $g['id'];
    }

    $nucleoDe = array_map('intval', array_column(escQ('SELECT UPPER(rep_code) AS rep, nucleo_id FROM ' . escT('esc_colaborador_config')
        . " WHERE funcao = 'EQUITE' AND nucleo_id IS NOT NULL AND" . $vig, [$desde, $desde]), 'nucleo_id', 'rep'));
    escTx(static function () use ($cap, $mantidos, $novos, $fechar, $esc, $desde, $ontem, $neutra, $versao, $paginaId, $chaveNome, $dc, $partilha, &$res, $vig, $nucleoDe) {
        // Mantidos: só os números do Capacity de hoje.
        foreach ($mantidos as $id => $k) {
            $c = $cap[$k];
            if ((float) $esc[$id]['carga'] !== (float) $c['carga'] || (float) $esc[$id]['capacidade'] !== (float) $c['capacidade'] || $esc[$id]['tipo'] !== $c['tipo']) {
                escW('UPDATE ' . escT('esc_posto') . ' SET tipo = ?, carga = ?, capacidade = ? WHERE id = ?', [$c['tipo'], $c['carga'], $c['capacidade'], $id]);
            }
        }
        // Novos
        $idNovo = [];
        foreach ($novos as $k => $n) {
            $pags = $n['paginas'];
            uasort($pags, static fn($a, $b) => [$a['apoio'], -$a['fans_h']] <=> [$b['apoio'], -$b['fans_h']]);
            $nomes = array_keys($pags);
            $ord = $nomes;
            sort($ord);
            escW('INSERT INTO ' . escT('esc_posto') . ' (versao, unidade, carteira, turno, seq, tipo, pagina_principal, n_paginas, assinatura, carga, capacidade, nucleo_dono_id, nucleo_cobertura_id, vigente_desde)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$versao, (int) $n['unidade'], $n['carteira'], $n['turno'], (int) $n['posto'], $n['tipo'],
                $n['pagina_principal'] ?: $nomes[0], count($nomes), mb_substr(implode(' + ', $ord) . ' [' . $n['tipo'] . ']', 0, 400), $n['carga'], $n['capacidade'], $n['dono'], $n['cob'], $desde]);
            $idNovo[$k] = escUltimoId();
            foreach ($pags as $pg => $g) {
                escW('INSERT INTO ' . escT('esc_posto_pagina') . ' (posto_id, pagina, page_id, fracao, fans_h, apoio) VALUES (?, ?, ?, ?, ?, ?)',
                    [$idNovo[$k], $pg, $paginaId[$chaveNome($pg)] ?? null, $g['fracao'], $g['fans_h'], $g['apoio']]);
            }
        }
        $res['novos'] = count($idNovo);
        // Fechados: a equipe vai para o posto novo com a maior parte das páginas; depois fecha.
        foreach ($fechar as $id => $f) {
            $destino = null;
            foreach ($novos as $k => $n) {
                if ((int) $n['unidade'] === (int) $f['unidade'] && $n['turno'] === $f['turno']) {
                    $s = $partilha($n, $f);
                    if ($s > 0 && ($destino === null || $s > $destino[1])) {
                        $destino = [$k, $s];
                    }
                }
            }
            $equipe = escQ('SELECT id, vaga, rep_code, fatia, ordem, status, motivo, vigente_desde FROM ' . escT('esc_vaga_equipe') . ' WHERE posto_id = ? AND (vigente_ate IS NULL OR vigente_ate >= ?)', [$id, $desde]);
            if ($destino) {
                $para = $idNovo[$destino[0]];
                $nucleosPara = [(int) $novos[$destino[0]]['dono'] => true, (int) $novos[$destino[0]]['cob'] => true];
                foreach ($equipe as $e) {
                    // Só vai junto quem é do núcleo dono ou de cobertura do posto novo (a divisão por OS
                    // pode ter trocado o núcleo); a vaga que sobrar é completada com gente do núcleo.
                    if (isset($nucleoDe[strtoupper((string) $e['rep_code'])]) && !isset($nucleosPara[$nucleoDe[strtoupper((string) $e['rep_code'])]])) {
                        continue;
                    }
                    if (escQ1('SELECT 1 AS ok FROM ' . escT('esc_vaga_equipe') . ' WHERE posto_id = ? AND vaga = ? AND rep_code = ? AND vigente_ate IS NULL', [$para, $e['vaga'], $e['rep_code']])) {
                        continue;
                    }
                    escW('INSERT INTO ' . escT('esc_vaga_equipe') . " (posto_id, vaga, rep_code, fatia, ordem, status, motivo, vigente_desde, origem) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'MONTADOR') AS n ON DUPLICATE KEY UPDATE vigente_ate = NULL, fatia = n.fatia, ordem = n.ordem, status = n.status, motivo = n.motivo",
                        [$para, $e['vaga'], $e['rep_code'], $e['fatia'], (int) $e['ordem'], $e['status'], mb_substr('Capacity de ' . $dc . ': veio do posto ' . $id . ($e['motivo'] ? '; ' . $e['motivo'] : ''), 0, 255), $desde]);
                    $res['equipe_movida']++;
                }
            }
            foreach ($equipe as $e) {
                $ini = substr((string) $e['vigente_desde'], 0, 10);
                if ($ini < $desde) {
                    escW('UPDATE ' . escT('esc_vaga_equipe') . ' SET vigente_ate = ? WHERE id = ?', [$ontem, (int) $e['id']]);
                } else {
                    escVagaNeutra((int) $e['id'], $neutra);
                }
            }
            $ini = substr((string) $f['vigente_desde'], 0, 10);
            escW('UPDATE ' . escT('esc_posto') . ' SET vigente_desde = ?, vigente_ate = ? WHERE id = ?', $ini < $desde ? [$ini, $ontem, $id] : [$neutra, $neutra, $id]);
        }
        $res['fechados'] = count($fechar);
    });
    return $res;
}

// Página segue o SR (26/11, pedido do CEO: "OS com o grupo de SR que ele atende, sempre os mesmos; os SRs
// sobem de página e isso melhora ou piora as páginas dos OS; quem treinar os melhores SRs terá as melhores
// páginas"). Ao publicar uma versão da escala, cada posto passa para o núcleo do SR que mais faz a página
// como titular naquela semana (células REGULAR de página, de SRs da mesma unidade e turno do posto); a
// cobertura vai para o segundo núcleo que mais a faz. Empate com o núcleo de hoje: fica. Vale a partir da
// segunda da semana (ou de amanhã, se a semana já começou); o posto que já valia fecha na véspera e abre a
// cópia com o núcleo novo, e a equipe da vaga que deixou de bater com o núcleo sai (o próximo Gerar completa),
// como na divisão dos OS. $aplicar = false só calcula (prévia). v4_pagina_segue_sr = 0 desliga.
function escPostoNucleoPeloPlano(int $planoId, bool $aplicar = true): array {
    $res = ['desde' => null, 'postos' => 0, 'equipe_fora' => 0, 'mudancas' => []];
    if (escV4Param('v4_pagina_segue_sr', '1') !== '1' || ($aplicar && escModoTreino())) {
        return $res;
    }
    $pl = escQ1('SELECT id, semana_inicio FROM ' . escT('esc_plano') . " WHERE id = ? AND time_nome = ''", [$planoId]);
    if (!$pl) {
        return $res;
    }
    $seg = substr((string) $pl['semana_inicio'], 0, 10);
    $desde = max($seg, date('Y-m-d', strtotime('+1 day')));
    $res['desde'] = $desde;
    $vig = ' vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)';
    // Núcleo de cada SR em cada dia da semana (o cadastro pode mudar no meio).
    $cfg = [];
    foreach (escQ('SELECT UPPER(rep_code) AS rep, unidade, turno, nucleo_id, vigente_desde, vigente_ate FROM ' . escT('esc_colaborador_config')
        . " WHERE funcao = 'EQUITE' AND nucleo_id IS NOT NULL AND vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)", [escMaisDias($seg, 6), $seg]) as $c) {
        $cfg[$c['rep']][] = $c;
    }
    $nucleoDe = static function (string $rep, string $dia) use ($cfg): ?array {
        foreach ($cfg[$rep] ?? [] as $c) {
            if (substr((string) $c['vigente_desde'], 0, 10) <= $dia && ($c['vigente_ate'] === null || substr((string) $c['vigente_ate'], 0, 10) >= $dia)) {
                return $c;
            }
        }
        return null;
    };
    $postos = [];
    foreach (escQ('SELECT id, unidade, turno, nucleo_dono_id, nucleo_cobertura_id, vigente_desde FROM ' . escT('esc_posto') . ' WHERE vigente_ate IS NULL OR vigente_ate >= ?', [$desde]) as $p) {
        $postos[(int) $p['id']] = $p;
    }
    $doTurno = [];
    foreach (escQ('SELECT id, unidade, turno FROM ' . escT('esc_nucleo') . ' WHERE' . $vig . ' ORDER BY numero', [$desde, $desde]) as $n) {
        $doTurno[$n['unidade'] . '|' . $n['turno']][] = (int) $n['id'];
    }
    $conta = [];
    foreach (escQ("SELECT posto_id, DATE_FORMAT(dia, '%Y-%m-%d') AS dia, bloco, UPPER(rep_code) AS rep FROM " . escT('esc_plano_alocacao')
        . " WHERE plano_id = ? AND papel = 'REGULAR' AND posto_id IS NOT NULL AND rep_code IS NOT NULL AND origem NOT IN ('OVERLAP', 'TREINO', 'LIDER_OS', 'LIDER_OC', 'LIDER_OA')", [$planoId]) as $a) {
        $p = $postos[(int) $a['posto_id']] ?? null;
        if (!$p) {
            continue;
        }
        // Dia do turno: o T1 do D é da noite anterior.
        $diaT = $a['bloco'] === 'T1' ? escMaisDias($a['dia'], -1) : $a['dia'];
        $c = $nucleoDe($a['rep'], $diaT);
        if (!$c || (int) $c['unidade'] !== (int) $p['unidade'] || $c['turno'] !== $p['turno']) {
            continue;
        }
        $conta[(int) $a['posto_id']][(int) $c['nucleo_id']] = ($conta[(int) $a['posto_id']][(int) $c['nucleo_id']] ?? 0) + 1;
    }
    $muda = [];
    foreach ($conta as $pid => $porNuc) {
        $p = $postos[$pid];
        $ids = $doTurno[$p['unidade'] . '|' . $p['turno']] ?? [];
        $porNuc = array_intersect_key($porNuc, array_flip($ids));
        if (!$porNuc || count($ids) < 2) {
            continue;
        }
        $atualDono = (int) $p['nucleo_dono_id'];
        $atualCob = $p['nucleo_cobertura_id'] !== null ? (int) $p['nucleo_cobertura_id'] : null;
        // Mais células primeiro; empate: o núcleo de hoje (dono, depois cobertura), depois a ordem do núcleo.
        $ordem = $ids;
        usort($ordem, static fn($a, $b) => [-($porNuc[$a] ?? 0), $a === $atualDono ? 0 : ($a === $atualCob ? 1 : 2), array_search($a, $ids, true)]
            <=> [-($porNuc[$b] ?? 0), $b === $atualDono ? 0 : ($b === $atualCob ? 1 : 2), array_search($b, $ids, true)]);
        // O dono só muda com evidência: o núcleo novo faz mais que o de hoje e ao menos v4_pagina_segue_sr_min
        // (40%) das células do posto. A cobertura só muda junto com o dono (para o dono de antes, se ainda faz a
        // página; senão o segundo que mais faz; senão a de hoje).
        $minParte = (float) str_replace(',', '.', escV4Param('v4_pagina_segue_sr_min', '0.4'));
        $total = array_sum($porNuc);
        $top = $ordem[0];
        if ($top === $atualDono || ($porNuc[$top] ?? 0) <= ($porNuc[$atualDono] ?? 0) || ($porNuc[$top] ?? 0) < $minParte * $total) {
            continue;
        }
        $dono = $top;
        $outros = array_values(array_filter($ordem, static fn($n) => $n !== $dono));
        if (($porNuc[$atualDono] ?? 0) > 0) {
            $cob = $atualDono;
        } elseif (($porNuc[$outros[0]] ?? 0) > 0) {
            $cob = $outros[0];
        } else {
            $cob = $atualCob !== null && $atualCob !== $dono ? $atualCob : $outros[0];
        }
        $muda[$pid] = ['dono' => $dono, 'cob' => $cob, 'antes' => [$atualDono, $atualCob], 'celulas' => $porNuc];
    }
    $res['mudancas'] = $muda;
    $res['postos'] = count($muda);
    if (!$aplicar || !$muda) {
        return $res;
    }
    $ontem = escMaisDias($desde, -1);
    $neutra = escMaisDias(escV4DiaVirada(), -2);
    escTx(static function () use ($muda, $postos, $desde, $ontem, $neutra, $cfg, $nucleoDe, &$res) {
        $versao = 'SR' . date('Ymd_His');
        $novoId = [];
        foreach ($muda as $id => $m) {
            if (substr((string) $postos[$id]['vigente_desde'], 0, 10) >= $desde) {
                escW('UPDATE ' . escT('esc_posto') . ' SET nucleo_dono_id = ?, nucleo_cobertura_id = ? WHERE id = ?', [$m['dono'], $m['cob'], $id]);
                $novoId[$id] = $id;
                continue;
            }
            // Cópia a partir da data, com o mesmo número no grupo (ou o próximo livre: uk_posto).
            $orig = escQW('SELECT carteira, turno, seq FROM ' . escT('esc_posto') . ' WHERE id = ?', [$id])[0];
            $seq = (int) $orig['seq'];
            while (escQW('SELECT 1 AS ok FROM ' . escT('esc_posto') . ' WHERE versao = ? AND carteira = ? AND turno = ? AND seq = ?', [$versao, $orig['carteira'], $orig['turno'], $seq])) {
                $seq++;
            }
            escW('INSERT INTO ' . escT('esc_posto') . ' (versao, unidade, carteira, turno, seq, tipo, pagina_principal, n_paginas, assinatura, carga, capacidade, nucleo_dono_id, nucleo_cobertura_id, vigente_desde, vigente_ate)
                  SELECT ?, unidade, carteira, turno, ?, tipo, pagina_principal, n_paginas, assinatura, carga, capacidade, ?, ?, ?, vigente_ate FROM ' . escT('esc_posto') . ' WHERE id = ?',
                [$versao, $seq, $m['dono'], $m['cob'], $desde, $id]);
            $novo = escUltimoId();
            $novoId[$id] = $novo;
            escW('INSERT INTO ' . escT('esc_posto_pagina') . ' (posto_id, pagina, page_id, fracao, fans_h, apoio) SELECT ?, pagina, page_id, fracao, fans_h, apoio FROM ' . escT('esc_posto_pagina') . ' WHERE posto_id = ?', [$novo, $id]);
            escW('INSERT IGNORE INTO ' . escT('esc_vaga_equipe') . ' (posto_id, vaga, rep_code, fatia, ordem, status, motivo, vigente_desde, vigente_ate, origem, criado_por)
                  SELECT ?, vaga, rep_code, fatia, ordem, status, motivo, ?, vigente_ate, origem, criado_por FROM ' . escT('esc_vaga_equipe') . ' WHERE posto_id = ? AND vigente_desde < ? AND (vigente_ate IS NULL OR vigente_ate >= ?)',
                [$novo, $desde, $id, $desde, $desde]);
            escW('UPDATE ' . escT('esc_vaga_equipe') . ' SET vigente_ate = ? WHERE posto_id = ? AND vigente_desde < ? AND (vigente_ate IS NULL OR vigente_ate >= ?)', [$ontem, $id, $desde, $desde]);
            foreach (escQW('SELECT id, vaga, rep_code, vigente_desde FROM ' . escT('esc_vaga_equipe') . ' WHERE posto_id = ? AND vigente_desde >= ?', [$id, $desde]) as $vg) {
                if (escQW('SELECT 1 AS ok FROM ' . escT('esc_vaga_equipe') . ' WHERE posto_id = ? AND vaga = ? AND rep_code = ? AND vigente_desde = ?', [$novo, $vg['vaga'], $vg['rep_code'], $vg['vigente_desde']])) {
                    escW('DELETE FROM ' . escT('esc_vaga_equipe') . ' WHERE id = ?', [(int) $vg['id']]);
                } else {
                    escW('UPDATE ' . escT('esc_vaga_equipe') . ' SET posto_id = ? WHERE id = ?', [$novo, (int) $vg['id']]);
                }
            }
            escW('UPDATE ' . escT('esc_posto') . ' SET vigente_ate = ? WHERE id = ?', [$ontem, $id]);
        }
        // Equipe da vaga que não bate mais com o núcleo (dono ou cobertura) sai a partir da data.
        foreach ($muda as $id => $m) {
            foreach (escQW('SELECT id, vaga, UPPER(rep_code) AS rep, vigente_desde FROM ' . escT('esc_vaga_equipe') . ' WHERE posto_id = ? AND (vigente_ate IS NULL OR vigente_ate >= ?)', [$novoId[$id], $desde]) as $v) {
                $c = $nucleoDe((string) $v['rep'], $desde);
                $nucVaga = $v['vaga'] === 'DONO' ? $m['dono'] : $m['cob'];
                if ($c && (int) $c['nucleo_id'] === $nucVaga) {
                    continue;
                }
                if (substr((string) $v['vigente_desde'], 0, 10) >= $desde) {
                    escVagaNeutra((int) $v['id'], $neutra);
                } else {
                    escW('UPDATE ' . escT('esc_vaga_equipe') . ' SET vigente_ate = ? WHERE id = ?', [$ontem, (int) $v['id']]);
                }
                $res['equipe_fora']++;
            }
        }
    });
    escLog('posto.nucleo_pelo_sr', 'esc_plano', (string) $planoId, null, ['desde' => $desde, 'postos' => $res['postos'], 'equipe_fora' => $res['equipe_fora'],
        'mudancas' => array_map(static fn($m) => [$m['antes'], [$m['dono'], $m['cob']]], $muda)]);
    return $res;
}

// Núcleo de antes (25/11, decisão do usuário: "as páginas não vão mais trocar de núcleo; se uma página
// saiu e depois voltou, volta sempre pra onde ela estava"). Para cada posto novo, o núcleo dono é o do
// último posto da estrutura nova (desde a virada) em que a página principal dele (a de mais volume, fora
// as de apoio) esteve, na mesma unidade e turno; a cobertura também, se ainda for outro núcleo do turno.
// Página sem posto anterior ali (nova, ou que voltou em outra unidade) fica com a escolha automática.
// Mudar de núcleo por controle (ex.: divisão dos OS aplicada, script) muda o posto de hoje, e é dele que
// o próximo posto herda.
// $semIds: postos que não contam como "antes" (só para conferir uma sincronização já feita).
function escEstruturaHerdarNucleo(array &$novos, array $nucleos, array $semIds = []): void {
    if (!$novos) {
        return;
    }
    try {
        $pags = [];
        foreach ($novos as $n) {
            foreach ($n['paginas'] as $pg => $g) {
                if (empty($g['apoio'])) {
                    $pags[(string) $pg] = true;
                }
            }
        }
        if (!$pags) {
            return;
        }
        $nomes = array_keys($pags);
        $ultimo = [];
        foreach (escQ('SELECT pp.pagina, p.unidade, p.turno, p.nucleo_dono_id, p.nucleo_cobertura_id FROM ' . escT('esc_posto_pagina') . ' pp JOIN ' . escT('esc_posto') . ' p ON p.id = pp.posto_id
                        WHERE pp.apoio = 0 AND pp.pagina IN (' . escIn($nomes) . ') AND p.vigente_desde >= ? AND (p.vigente_ate IS NULL OR p.vigente_ate >= p.vigente_desde)
                          AND p.nucleo_dono_id IS NOT NULL' . ($semIds ? ' AND p.id NOT IN (' . escIn($semIds) . ')' : '') . '
                        ORDER BY p.vigente_desde DESC, p.id DESC', array_merge($nomes, [escV4DiaVirada()], array_map('intval', $semIds))) as $r) {
            $ultimo[$r['pagina'] . '|' . $r['unidade'] . '|' . $r['turno']] ??= ['dono' => (int) $r['nucleo_dono_id'], 'cob' => $r['nucleo_cobertura_id'] !== null ? (int) $r['nucleo_cobertura_id'] : null];
        }
        foreach ($novos as &$n) {
            $ut = $n['unidade'] . '|' . $n['turno'];
            $doTurno = $nucleos[$ut] ?? [];
            $pg = null;
            foreach ($n['paginas'] as $nome => $g) {
                if (empty($g['apoio']) && isset($ultimo[$nome . '|' . $ut]) && ($pg === null || (float) $g['fans_h'] > (float) $n['paginas'][$pg]['fans_h'])) {
                    $pg = (string) $nome;
                }
            }
            $antes = $pg !== null ? $ultimo[$pg . '|' . $ut] : null;
            if (!$antes || !in_array($antes['dono'], $doTurno, true)) {
                continue;
            }
            $n['dono'] = $antes['dono'];
            if ($antes['cob'] !== null && $antes['cob'] !== $n['dono'] && in_array($antes['cob'], $doTurno, true)) {
                $n['cob'] = $antes['cob'];
            } elseif (($n['cob'] ?? null) === $n['dono'] || ($n['cob'] ?? null) === null) {
                $n['cob'] = array_values(array_diff($doTurno, [$n['dono']]))[0] ?? null;
            }
        }
        unset($n);
    } catch (Throwable $e) {
        appLog('warning', 'Estrutura: núcleo de antes não aplicado', ['erro' => $e->getMessage()]);
    }
}

// Páginas por OS (núcleo dono dos postos novos), em cada unidade e turno: o OS ganha comissão sobre
// o faturamento das páginas dele, então a divisão vai pelo valor. Todos os postos de uma página vão
// juntos; da página que mais fatura no turno (30 dias, parte de cada posto pela fração) para a que
// menos, cada uma vai para o OS com menos faturamento até ali. Páginas da mesma modelo (ex.: Mikayla
// Free e Paid) ficam em OSs diferentes quando dá. OS novato na liderança (v4_os_novato, ids de núcleo)
// só recebe páginas até a mediana do turno e conta o dobro (fica com cerca de metade do valor). O
// núcleo de cobertura é o outro do turno com menos valor coberto. Postos mantidos ficam como estão.
function escEstruturaDividirOS(array &$novos, array $mantidos, array $esc, array $cap, array $nucleos, array $modeloDe): void {
    if (!$novos) {
        return;
    }
    $novatos = array_flip(array_map('intval', array_filter(array_map('trim', explode(',', escV4Param('v4_os_novato', ''))))));
    $todas = [];
    foreach ($cap as $p) {
        $todas += $p['paginas'];
    }
    $receita = escV4ReceitaPaginas(array_keys($todas), (int) escV4Param('v4_receita_dias', '30'), false); // Capacity: sem o corte de dados
    $valor = static function (array $p) use ($receita): float {
        $v = 0.0;
        foreach ($p['paginas'] as $pg => $g) {
            if (!$g['apoio']) {
                $v += (float) ($receita[$pg][$p['turno']] ?? 0) * ((float) $g['fracao'] > 0 ? (float) $g['fracao'] : 1.0);
            }
        }
        return $v;
    };
    $modelo = static fn(string $pg): string => ($modeloDe[$pg] ?? '') !== '' ? $modeloDe[$pg] : escPaginaChaveModelo($pg);
    $porUT = [];
    foreach ($novos as $k => $n) {
        $porUT[$n['unidade'] . '|' . $n['turno']][] = $k;
    }
    foreach ($porUT as $ut => $ks) {
        $doTurno = $nucleos[$ut] ?? [];
        if (count($doTurno) < 2) {
            continue;
        }
        $carga = array_fill_keys($doTurno, 0.0);
        $cobre = array_fill_keys($doTurno, 0.0);
        $modelosDe = array_fill_keys($doTurno, []);
        foreach ($mantidos as $id => $kc) {
            $e = $esc[$id];
            if ($e['unidade'] . '|' . $e['turno'] !== $ut) {
                continue;
            }
            $v = $valor($cap[$kc]);
            $d = (int) $e['nucleo_dono_id'];
            if (isset($carga[$d])) {
                $carga[$d] += $v;
                foreach (array_keys($cap[$kc]['paginas']) as $pg) {
                    $modelosDe[$d][$modelo($pg)] = true;
                }
            }
            if ($e['nucleo_cobertura_id'] !== null && isset($cobre[(int) $e['nucleo_cobertura_id']])) {
                $cobre[(int) $e['nucleo_cobertura_id']] += $v;
            }
        }
        // Grupos: postos que dividem página (titular, double e assistente da mesma página).
        $grupo = [];
        $raiz = static function (string $k) use (&$grupo, &$raiz): string {
            return ($grupo[$k] ?? $k) === $k ? $k : ($grupo[$k] = $raiz($grupo[$k]));
        };
        $dono = [];
        foreach ($ks as $k) {
            $grupo[$k] = $k;
            foreach (array_keys($novos[$k]['paginas']) as $pg) {
                if (!$novos[$k]['paginas'][$pg]['apoio']) {
                    if (isset($dono[$pg])) {
                        $grupo[$raiz($k)] = $raiz($dono[$pg]);
                    } else {
                        $dono[$pg] = $k;
                    }
                }
            }
        }
        $grupos = [];
        foreach ($ks as $k) {
            $r = $raiz($k);
            $g = $grupos[$r] ?? ['ks' => [], 'valor' => 0.0, 'modelos' => []];
            $g['ks'][] = $k;
            $g['valor'] += $valor($novos[$k]);
            foreach ($novos[$k]['paginas'] as $pg => $x) {
                if (!$x['apoio']) {
                    $g['modelos'][$modelo($pg)] = true;
                }
            }
            $grupos[$r] = $g;
        }
        usort($grupos, static fn($a, $b) => $b['valor'] <=> $a['valor']);
        $valores = array_column($grupos, 'valor');
        sort($valores);
        $mediana = $valores ? $valores[intdiv(count($valores), 2)] : 0.0;
        $peso = static fn(int $id): float => isset($novatos[$id]) ? 2.0 : 1.0;
        foreach ($grupos as $g) {
            $melhor = null;
            foreach ($doTurno as $id) {
                if (isset($novatos[$id]) && $g['valor'] > $mediana && count($doTurno) > count(array_intersect_key($novatos, array_flip($doTurno)))) {
                    continue;
                }
                $chave = [array_intersect_key($g['modelos'], $modelosDe[$id]) ? 1 : 0, $carga[$id] * $peso($id), $id];
                if ($melhor === null || $chave < $melhor[1]) {
                    $melhor = [$id, $chave];
                }
            }
            $d = $melhor[0];
            $c = null;
            foreach ($doTurno as $id) {
                if ($id !== $d && ($c === null || [$cobre[$id], $id] < [$cobre[$c], $c])) {
                    $c = $id;
                }
            }
            $carga[$d] += $g['valor'];
            $cobre[$c] += $g['valor'];
            $modelosDe[$d] += $g['modelos'];
            foreach ($g['ks'] as $k) {
                $novos[$k]['dono'] = $d;
                $novos[$k]['cob'] = $c;
            }
        }
    }
}

// Trocas de unidade aplicadas (esc_politica_troca) que valem em $desde ou depois e cujos postos
// ainda estão fora do destino: a troca entrou só no Capacity (ex.: ES_51) ou a sincronização
// a desfez antes desta versão. Só a troca mais recente de cada página vale: uma troca antiga
// desfeita por outra depois (ex.: ES_51 levou a Mikayla para a U3; a ES_61 trouxe de volta)
// não pode ser reaplicada.
function escEstruturaTrocasPendentes(string $desde): array {
    return escQ("SELECT t.pagina, CAST(t.para_ref AS UNSIGNED) AS para, DATE(t.vale_desde) AS desde
                   FROM " . escT('esc_politica_troca') . " t
                  WHERE t.tipo = 'UNIDADE' AND t.status = 'APLICADA' AND t.vale_desde >= ?
                    AND t.id = (SELECT MAX(t2.id) FROM " . escT('esc_politica_troca') . " t2
                                 WHERE t2.tipo = 'UNIDADE' AND t2.status = 'APLICADA' AND t2.pagina = t.pagina)
                    AND EXISTS (SELECT 1 FROM " . escT('esc_posto') . ' p JOIN ' . escT('esc_posto_pagina') . ' pp ON pp.posto_id = p.id
                                 WHERE pp.pagina = t.pagina AND p.unidade <> CAST(t.para_ref AS UNSIGNED)
                                   AND p.vigente_desde <= t.vale_desde AND (p.vigente_ate IS NULL OR p.vigente_ate >= t.vale_desde))
                  ORDER BY t.vale_desde, t.id', [$desde]);
}

// Aplica a parte da escala dessas trocas (escPolUnidadeEscala), na carteira que o Capacity
// tem para a página na data. Com outra saída ou troca rodando, fica para a próxima vez.
function escEstruturaTrocasUnidade(string $desde): array {
    $feitas = [];
    $pendentes = escEstruturaTrocasPendentes($desde);
    if (!$pendentes || (int) escDbW()->query("SELECT GET_LOCK('5c_escalas.saida_pagina', 0)")->fetchColumn() !== 1) {
        return $feitas;
    }
    try {
        $porData = [];
        foreach ($pendentes as $t) {
            $porData[substr((string) $t['desde'], 0, 10)][$t['pagina']] = (int) $t['para'];
        }
        foreach ($porData as $d => $paginas) {
            // Plano de cada página antes da saída (que muda os postos da origem).
            $todas = escPolPaginas($d);
            $itens = [];
            foreach ($paginas as $pagina => $para) {
                $pg = $todas[$pagina] ?? null;
                if (!$pg || isset($pg['unidades'][$para])) {
                    continue;
                }
                $plano = escPolPlanoDestino($pg, $para, $d);
                $cart = escQ1('SELECT carteira FROM `5c_planning`.`fc_pagina_carteira` WHERE pagina = ? AND vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate > ?)', [$pagina, $d, $d]);
                if ($cart && strncmp((string) $cart['carteira'], 'U' . $para, strlen('U' . $para)) === 0) {
                    $plano['carteira'] = $cart['carteira'];
                }
                $itens[$pagina] = [$pg, $plano, $para];
            }
            if (!$itens) {
                continue;
            }
            // Saída da origem e entrada no destino juntas: com erro no meio, nada fica gravado
            // (a página não fica sem posto nas duas unidades), como na saída de página.
            escTx(static function () use ($itens, $d, &$feitas) {
                $r = ['postos_fechados' => [], 'postos_refeitos' => [], 'vagas_passadas' => [], 'livres' => [], 'postos_novos' => []];
                escSaidaEscala(array_keys($itens), $d, $r);
                foreach ($itens as $pagina => [$pg, $plano, $para]) {
                    $antes = count($r['postos_novos']);
                    escPolUnidadeEscala($pg, $plano, $para, $d, $r, false);
                    $feitas[] = ['pagina' => $pagina, 'de' => $pg['unidade'], 'para' => $para, 'desde' => $d, 'carteira' => $plano['carteira'], 'postos_novos' => array_slice($r['postos_novos'], $antes)];
                }
                escLog('politica.unidade_escala', 'esc_politica_troca', implode(', ', array_keys($itens)), null, ['desde' => $d, 'trocas' => array_slice($feitas, -count($itens))] + array_diff_key($r, ['postos_novos' => 1]));
            });
        }
    } finally {
        escDbW()->query("SELECT RELEASE_LOCK('5c_escalas.saida_pagina')")->fetchColumn();
    }
    return $feitas;
}

// Passos 1 a 3, com a trava de tempo. Devolve o resumo, ou null se não rodou.
// $forcar (Gerar em etapas, 05/11): roda sempre, sem a trava de v4_estrutura_minutos.
function escEstruturaAtualizar(bool $forcar = false): ?array {
    if (escModoTreino() || escDbW()->inTransaction() || escV4Param('v4_estrutura_auto', '1') !== '1') {
        return null;
    }
    $hoje = date('Y-m-d');
    $desde = max(escV4DiaEstrutura(), escMaisDias($hoje, 1));
    $fora = escEstruturaPaginasFora($hoje);
    $min = max(0, (int) escV4Param('v4_estrutura_minutos', '30'));
    $ult = escQ1('SELECT MAX(quando) AS q FROM ' . escT('esc_log') . " WHERE acao = 'estrutura_atualizada'");
    if (!$forcar && !$fora && $ult && $ult['q'] !== null && strtotime((string) $ult['q']) > time() - $min * 60 && !escEstruturaTrocasPendentes($desde)) {
        return null;
    }
    $res = ['paginas' => [], 'erro' => null];
    // Vigência da página nova: nunca antes do início da estrutura nova (ver passo 1). E página que
    // entrou sozinha com data anterior (versão 1.14.0) volta para o início: senão o Capacity tira
    // todas as outras do cálculo.
    $inicioCap = max($hoje, escV4DiaEstrutura());
    try {
        escCapacityGravar('pagina_auto_inicio', [escV4DiaEstrutura(), escV4DiaEstrutura()]);
        foreach ($fora as $p) {
            escCapacityGravar('pagina_nova', [$p['pagina'], $inicioCap, $p['carteira'], $p['modelo'], $p['fas_h'], $p['motivo'], $p['pagina']]);
            $res['paginas'][] = $p;
        }
        escCapacityGravar('recalcular_versao', [$hoje, 'Escalas: antes de gerar a escala']);
        $res += escEstruturaSincronizar($hoje, $desde);
        $res['trocas_unidade'] = escEstruturaTrocasUnidade($desde);
    } catch (Throwable $e) {
        // Onde caiu (arquivo:linha e o começo do trace), para achar a causa de erros soltos como o 1160.
        appLog('error', 'Estrutura: falha ao atualizar antes de gerar', ['erro' => $e->getMessage(), 'onde' => basename($e->getFile()) . ':' . $e->getLine(),
            'trace' => mb_substr($e->getTraceAsString(), 0, 1200)]);
        $res['erro'] = $e->getMessage();
        $res['conexao'] = escErroConexao($e);
    }
    // Com erro, registra à parte: a trava de 30 minutos só conta atualização que deu certo.
    escLog($res['erro'] ? 'estrutura_erro' : 'estrutura_atualizada', 'esc_posto', null, null, ['desde' => $desde, 'paginas' => array_column($res['paginas'], 'pagina')] + array_diff_key($res, ['paginas' => 1]));
    return $res;
}

// Texto curto para a mensagem depois de gerar.
function escEstruturaAviso(): string {
    $r = $GLOBALS['ESC_ESTRUTURA_AUTO'] ?? null;
    if (!$r || !empty($r['ja_feito'])) {
        return ''; // nada rodou, ou rodou na etapa do Capacity (o aviso já foi junto)
    }
    if ($r['erro']) {
        return ' ' . t('Não deu para atualizar o Capacity antes de gerar ({erro}): a escala usou a estrutura anterior.', ['erro' => mb_substr((string) $r['erro'], 0, 120)]);
    }
    $txt = ' ' . t('Capacity recalculado antes de gerar: {m} postos iguais, {n} novos, {f} fechados.', ['m' => $r['mantidos'] ?? 0, 'n' => $r['novos'] ?? 0, 'f' => $r['fechados'] ?? 0]);
    if ($r['paginas']) {
        $txt .= ' ' . t('Entraram no Capacity: {lista}.', ['lista' => implode(', ', array_column($r['paginas'], 'pagina'))]);
    }
    if (!empty($r['trocas_unidade'])) {
        $txt .= ' ' . t('Postos levados para a unidade nova: {lista}.', ['lista' => implode(', ', array_map(static fn($x) => $x['pagina'] . ' (U' . $x['de'] . ' → U' . $x['para'] . ', ' . dataBr($x['desde']) . ')', $r['trocas_unidade']))]);
    }
    return $txt;
}

// ---------- Capacity automático (1.38.0, item D da revisão de 24/11) ----------
// Toda gravação que muda pessoas, páginas ou postos (as ações do esc_log em ESC_CAPACITY_GATILHOS) grava
// um pedido em esc_capacity_fila, na mesma transação da mudança (se ela volta atrás, o pedido também volta).
// O evento ev_esc_capacity_fila (ES_222) roda a cada 2 minutos e chama sp_fc_recalcular_postos de hoje uma vez
// só, com os motivos juntos, na trava do Gerar. O usuário não espera o recálculo. Entrada e saída de página e
// troca de unidade já recalculam no próprio fluxo; o Gerar e o diário seguem recalculando. O Modo Treino não pede.
const ESC_CAPACITY_GATILHOS = [
    'colaborador.salvar',                                   // Colaboradores, Fora das unidades, Turmas › Cadastrar todos
    'roster.trocar', 'roster.incluir_oa', 'roster.tirar_oa', // SR que vira OS ou OA
    'solicitacao.aplicar',                                  // mudança de unidade, turno ou núcleo pedida pelo SR
    'casal.incluir', 'casal.ajuste', 'casal.encerrar',     // Folga juntos (pode mudar o núcleo)
    'treinamento.destino', 'treinamento.confirmar', 'treinamento.cancelar',
    'pagina.config',                                        // alocação da página (Equipe, On demand, Exclusiva)
    'equilibrio.divisao',                                   // divisão dos OS (dono dos postos)
    'requisicao.turma_nova', 'requisicao.turma_salvar', 'requisicao.plano_salvar_todas', 'requisicao.turma_apagar',
    'requisicao.abrir', 'requisicao.contratar', 'requisicao.cancelar', // plano de turmas (fc_contratacoes)
];

function escCapacityAgendar(string $motivo): void {
    if (escModoTreino() || !empty($GLOBALS['ESC_CAPACITY_PEDIDO'][$motivo])) {
        return;
    }
    $GLOBALS['ESC_CAPACITY_PEDIDO'][$motivo] = true;
    try {
        escW('INSERT INTO ' . escT('esc_capacity_fila') . ' (motivo, criado_por) VALUES (?, ?)', [mb_substr($motivo, 0, 60), escUserId() ?: null]);
    } catch (Throwable $e) {
        // ES_222 ainda não rodou: o Gerar e o diário recalculam
        appLog('warning', 'Capacity automático: fila indisponível', ['motivo' => $motivo, 'erro' => $e->getMessage()]);
    }
}

// Situação da fila: pedidos pendentes, o mais antigo, o último processado e o último erro (24 h).
function escCapacityFila(): array {
    try {
        $r = escQ1('SELECT (SELECT COUNT(*) FROM ' . escT('esc_capacity_fila') . ' WHERE processado_em IS NULL) AS pendentes,
                           (SELECT MIN(criado_em) FROM ' . escT('esc_capacity_fila') . ' WHERE processado_em IS NULL) AS desde,
                           (SELECT MAX(processado_em) FROM ' . escT('esc_capacity_fila') . ' WHERE erro IS NULL) AS ultimo,
                           (SELECT erro FROM ' . escT('esc_capacity_fila') . ' WHERE erro IS NOT NULL AND processado_em >= NOW() - INTERVAL 1 DAY ORDER BY id DESC LIMIT 1) AS erro');
        return ['pendentes' => (int) ($r['pendentes'] ?? 0), 'desde' => $r['desde'] ?? null, 'ultimo' => $r['ultimo'] ?? null, 'erro' => $r['erro'] ?? null];
    } catch (Throwable $e) {
        return ['pendentes' => 0, 'desde' => null, 'ultimo' => null, 'erro' => null];
    }
}
