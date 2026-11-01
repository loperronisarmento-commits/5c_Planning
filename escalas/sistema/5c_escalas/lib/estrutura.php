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
    $lista = escQ('SELECT g.id, g.name AS pagina, g.tier, g.imported_at,
                          (SELECT COUNT(*) FROM `5c_schedule`.`scheduleday` s WHERE s.modeloNome = g.name AND s.shiftDateAndHour >= ?) AS escala,
                          (SELECT SUM(i.`5CReps`) FROM `5c_wfm_db_mis`.`tab_intraday_base` i WHERE i.Page = g.name AND i.`Date` >= ?) AS venda
                     FROM `5c_pages`.`pages` g
                    WHERE g.active = 1 AND g.name <> ?
                      AND NOT EXISTS (SELECT 1 FROM `5c_planning`.`fc_pagina_carteira` c WHERE c.pagina = g.name AND (c.vigente_ate IS NULL OR c.vigente_ate > ?))',
        [$de . 'T00:00:00', $de, escV4Param('v4_overlap_pagina', '5C Schedule'), $hoje]);
    $lista = array_values(array_filter($lista, static fn($l) => (int) $l['escala'] > 0 || (float) $l['venda'] > 0
        || ($l['imported_at'] !== null && substr((string) $l['imported_at'], 0, 10) >= $novaDe)));
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
    $saida = [];
    $novaModelo = []; // [chave] = carteira da modelo nova já decidida nesta rodada
    foreach ($lista as $l) {
        $perfil = escPaginaPerfil($l['pagina']);
        $chave = escPaginaChaveModelo($l['pagina']);
        $tier = $l['tier'] !== null && $l['tier'] !== '' ? (string) $l['tier'] : null;
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
                    escW('INSERT INTO ' . escT('esc_vaga_equipe') . " (posto_id, vaga, rep_code, fatia, ordem, status, motivo, vigente_desde, origem) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'MONTADOR')",
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
    $receita = escV4ReceitaPaginas(array_keys($todas), (int) escV4Param('v4_receita_dias', '30'));
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
function escEstruturaAtualizar(): ?array {
    if (escModoTreino() || escDbW()->inTransaction() || escV4Param('v4_estrutura_auto', '1') !== '1') {
        return null;
    }
    $hoje = date('Y-m-d');
    $desde = max(escV4DiaEstrutura(), escMaisDias($hoje, 1));
    $fora = escEstruturaPaginasFora($hoje);
    $min = max(0, (int) escV4Param('v4_estrutura_minutos', '30'));
    $ult = escQ1('SELECT MAX(quando) AS q FROM ' . escT('esc_log') . " WHERE acao = 'estrutura_atualizada'");
    if (!$fora && $ult && $ult['q'] !== null && strtotime((string) $ult['q']) > time() - $min * 60 && !escEstruturaTrocasPendentes($desde)) {
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
        appLog('error', 'Estrutura: falha ao atualizar antes de gerar', ['erro' => $e->getMessage()]);
        $res['erro'] = $e->getMessage();
    }
    // Com erro, registra à parte: a trava de 30 minutos só conta atualização que deu certo.
    escLog($res['erro'] ? 'estrutura_erro' : 'estrutura_atualizada', 'esc_posto', null, null, ['desde' => $desde, 'paginas' => array_column($res['paginas'], 'pagina')] + array_diff_key($res, ['paginas' => 1]));
    return $res;
}

// Texto curto para a mensagem depois de gerar.
function escEstruturaAviso(): string {
    $r = $GLOBALS['ESC_ESTRUTURA_AUTO'] ?? null;
    if (!$r) {
        return '';
    }
    if ($r['erro']) {
        return ' ' . t('Não deu para atualizar o Capacity antes de gerar ({erro}): a escala usou a estrutura anterior.', ['erro' => mb_substr((string) $r['erro'], 0, 120)]);
    }
    $txt = ' ' . t('Capacity recalculado antes de gerar: {m} postos iguais, {n} novos, {f} fechados.', ['m' => $r['mantidos'] ?? 0, 'n' => $r['novos'] ?? 0, 'f' => $r['fechados'] ?? 0]);
    if ($r['paginas']) {
        $txt .= ' ' . t('Entraram no Capacity: {lista}.', ['lista' => implode(', ', array_column($r['paginas'], 'pagina'))]);
    }
    if (!empty($r['trocas_unidade'])) {
        $txt .= ' ' . t('Postos levados para a unidade nova: {lista}.', ['lista' => implode(', ', array_map(static fn($x) => $x['pagina'] . ' (U' . $x['de'] . ' → U' . $x['para'] . ', ' . dataBr($x['desde']) . ')', $r['trocas_unidade']))]);    }
    return $txt;
}
