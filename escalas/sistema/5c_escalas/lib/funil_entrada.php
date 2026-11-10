<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/funil_entrada.php
// Entradas de página detectadas pela DB (pedido de 10/11, ES_138), no espelho de Saídas de página:
// páginas ativas no site da 5C (5c_pages.pages.active = 1) sem carteira aberta no Capacity
// (fc_pagina_carteira), como entrada NOVA ou RETORNO. O dia em que ficou ativa vem da foto
// diária do Capacity (fc_pagina_status_diario).
// Caso que motivou: Alexis Clark e Daniswings 157m saíram pela tela Saídas e voltaram a ficar
// ativas em 09/11, mas a entrada automática antes do Gerar (escEstruturaPaginasFora) não traz
// de volta, por v4_saida_bloqueio_dias (60), quem saiu pela tela Saídas; e o Funil só tinha
// cards do CRM. Ações (esc_pagina_entrada_checagem):
//   ENTROU  grava a carteira no Capacity (retorno: a última linha dela, com modelo, regra e volume;
//           nova: volume do arquétipo) e recalcula o Capacity. A escala não muda agora: os postos
//           entram no próximo Gerar, que recalcula e sincroniza a estrutura.
//   IGNORAR não é página de escala (PH, X, MYM, teste...). Se a página sair e voltar, reaparece.
// 1.27.1 (Liv Loxey, 10/11): a página renomeada no site ("Liv" → "Liv Loxey") é ligada ao nome antigo
// pelo page_id (fotos e eventos do Capacity) e entra como RETORNO, com a carteira de antes; a que ativou
// depois da última foto (às 3h) e ainda não tem movimento fica como RECENTE, no topo, e não mais
// recolhida entre as paradas com a data de importação antiga.
// ===============================================

function escEntradaTemTabela(): bool {
    static $ok = null;
    if ($ok === null) {
        $ok = (bool) escQ1("SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'esc_pagina_entrada_checagem'", [ESC_SCHEMA]);
    }
    return $ok;
}

// Comparação de nome de página sem espaços e sem caixa (como a entrada automática: "Haven  Free").
function escEntradaChave(string $p): string {
    return mb_strtolower(str_replace(' ', '', trim($p)));
}

// Páginas ativas no site e fora do Capacity. $movimento: soma os dias na escala do site e a venda
// desde que ficou ativa (mais pesado; a verificação do Gerar não precisa).
// Cada item: page_id, pagina, tier, ativa_desde, tipo (NOVA/RETORNO), ultima (última linha da
// carteira ou null), saida (última saída aplicada pela tela Saídas ou null), presa (bool),
// decisao (linha de esc_pagina_entrada_checagem ou null), dias_escala, venda e situacao:
//   ENTROU, IGNORADA, PRESA (não volta sozinha), AUTO (entra sozinha no próximo Gerar) ou PARADA.
function escEntradasDetectadas(bool $movimento = true): array {
    $hoje = date('Y-m-d');
    $semEscala = escEstruturaSemEscala();
    $overlap = escV4Param('v4_overlap_pagina', '5C Schedule');
    $paginas = [];
    foreach (escQ('SELECT id, name, tier, imported_at, updated_at FROM `5c_pages`.`pages` WHERE active = 1 AND name <> ? ORDER BY name', [$overlap]) as $g) {
        if (!$semEscala((string) $g['name'])) {
            $paginas[(int) $g['id']] = $g;
        }
    }
    if (!$paginas) {
        return [];
    }
    // Carteira: aberta (fica fora da lista) e a última linha de cada página (retorno).
    $aberta = $ultima = $modeloAberta = [];
    foreach (escQ('SELECT pagina, carteira, modelo, vigente_desde, vigente_ate, obs FROM `5c_planning`.`fc_pagina_carteira` ORDER BY vigente_desde') as $c) {
        $k = escEntradaChave((string) $c['pagina']);
        $ultima[$k] = $c;
        if ($c['vigente_ate'] === null || (string) $c['vigente_ate'] > $hoje) {
            $aberta[$k] = true;
            $modeloAberta[escPaginaChaveModelo((string) $c['pagina'])] = $modeloAberta[escPaginaChaveModelo((string) $c['pagina'])] ?? $c;
        }
    }
    $paginas = array_filter($paginas, static fn($g) => !isset($aberta[escEntradaChave((string) $g['name'])]));
    if (!$paginas) {
        return [];
    }
    $ids = array_keys($paginas);
    // Dia em que ficou ativa: o primeiro dia ativo depois da última foto inativa (sem foto inativa, a primeira foto).
    $ativaDesde = [];
    foreach (escQ('SELECT s.page_id, MIN(s.dia) AS desde
                     FROM `5c_planning`.`fc_pagina_status_diario` s
                     LEFT JOIN (SELECT page_id, MAX(dia) AS ult FROM `5c_planning`.`fc_pagina_status_diario` WHERE ativo = 0 AND page_id IN (' . escIn($ids) . ') GROUP BY page_id) i ON i.page_id = s.page_id
                    WHERE s.ativo = 1 AND s.page_id IN (' . escIn($ids) . ') AND (i.ult IS NULL OR s.dia > i.ult)
                    GROUP BY s.page_id', array_merge($ids, $ids)) as $s) {
        $ativaDesde[(int) $s['page_id']] = substr((string) $s['desde'], 0, 10);
    }
    // Ativa já na primeira foto: a data real pode ser anterior ("19/10 ou antes").
    $primeiraFoto = substr((string) (escQ1('SELECT MIN(dia) AS d FROM `5c_planning`.`fc_pagina_status_diario`')['d'] ?? ''), 0, 10);
    // Ativou depois da última foto (a foto é diária, às 3h): na última foto estava inativa, ou ainda não tem foto.
    $ultimaFoto = [];
    foreach (escQ('SELECT s.page_id, s.ativo FROM `5c_planning`.`fc_pagina_status_diario` s
                     JOIN (SELECT page_id, MAX(dia) AS d FROM `5c_planning`.`fc_pagina_status_diario` WHERE page_id IN (' . escIn($ids) . ') GROUP BY page_id) u
                       ON u.page_id = s.page_id AND u.d = s.dia', $ids) as $s) {
        $ultimaFoto[(int) $s['page_id']] = (int) $s['ativo'];
    }
    // Nome antigo da mesma página (o site renomeia: "Liv" virou "Liv Loxey"), pelas fotos e pelos eventos do Capacity.
    $nomesAntes = [];
    foreach (escQ('SELECT page_id, pagina, MAX(dia) AS ult FROM `5c_planning`.`fc_pagina_status_diario` WHERE page_id IN (' . escIn($ids) . ') GROUP BY page_id, pagina
                   UNION ALL SELECT page_id, pagina, MAX(data_evento) AS ult FROM `5c_planning`.`fc_pagina_eventos` WHERE page_id IN (' . escIn($ids) . ') GROUP BY page_id, pagina
                   ORDER BY ult DESC', array_merge($ids, $ids)) as $r) {
        $id = (int) $r['page_id'];
        $antigo = (string) $r['pagina'];
        if (isset($paginas[$id]) && escEntradaChave($antigo) !== escEntradaChave((string) $paginas[$id]['name']) && !in_array($antigo, $nomesAntes[$id] ?? [], true)) {
            $nomesAntes[$id][] = $antigo;
        }
    }
    // Com nome antigo que tem carteira aberta, a página já está no Capacity (só o nome mudou): fica fora da lista.
    foreach ($nomesAntes as $id => $lista) {
        foreach ($lista as $antigo) {
            if (isset($aberta[escEntradaChave($antigo)])) {
                unset($paginas[$id]);
                break;
            }
        }
    }
    if (!$paginas) {
        return [];
    }
    $nomes = array_values(array_map(static fn($g) => (string) $g['name'], $paginas));
    foreach ($paginas as $id => $g) {
        foreach ($nomesAntes[$id] ?? [] as $antigo) {
            $nomes[] = $antigo;
        }
    }
    $nomes = array_values(array_unique($nomes));
    // Na escala antes (postos de qualquer época) = retorno, mesmo sem carteira.
    $naEscala = [];
    foreach (escQ('SELECT DISTINCT pagina FROM ' . escT('esc_posto_pagina') . ' WHERE pagina IN (' . escIn($nomes) . ')', $nomes) as $r) {
        $naEscala[escEntradaChave((string) $r['pagina'])] = true;
    }
    // Última saída aplicada pela tela Saídas; presa = dentro de v4_saida_bloqueio_dias e sem destino novo no Funil depois.
    $bloqDe = escMaisDias($hoje, -max(1, (int) escV4Param('v4_saida_bloqueio_dias', '60')));
    $saida = [];
    foreach (escQ('SELECT pagina, MAX(vale_desde) AS vale_desde, MAX(criado_em) AS quando FROM ' . escT('esc_saida_pagina') . " WHERE status = 'APLICADA' AND pagina IN (" . escIn($nomes) . ') GROUP BY pagina', $nomes) as $s) {
        $saida[escEntradaChave((string) $s['pagina'])] = $s;
    }
    $previsaoDepois = [];
    foreach ($saida ? escQ('SELECT pagina, GREATEST(criado_em, COALESCE(atualizado_em, criado_em)) AS quando FROM ' . escT('esc_pagina_previsao') . ' WHERE pagina IN (' . escIn($nomes) . ')', $nomes) : [] as $pv) {
        $k = escEntradaChave((string) $pv['pagina']);
        if (isset($saida[$k]) && (string) $pv['quando'] > (string) $saida[$k]['quando']) {
            $previsaoDepois[$k] = true;
        }
    }
    $decisoes = [];
    if (escEntradaTemTabela()) {
        foreach (escQ('SELECT id, pagina, ativa_desde, tipo, decisao, carteira, vale_desde, observacao, decidido_em, decidido_por FROM ' . escT('esc_pagina_entrada_checagem') . ' WHERE pagina IN (' . escIn($nomes) . ')', $nomes) as $d) {
            $decisoes[escEntradaChave((string) $d['pagina']) . '|' . substr((string) $d['ativa_desde'], 0, 10)] = $d;
        }
    }
    // Movimento desde que ficou ativa, no máximo v4_estrutura_movimento_dias (14) para trás.
    $diasEscala = $venda = [];
    if ($movimento) {
        $de = escMaisDias($hoje, -max(1, (int) escV4Param('v4_estrutura_movimento_dias', '14')));
        foreach (escQ('SELECT modeloNome AS p, LEFT(shiftDateAndHour, 10) AS d FROM `5c_schedule`.`scheduleday` WHERE shiftDateAndHour >= ? AND modeloNome IN (' . escIn($nomes) . ') GROUP BY modeloNome, LEFT(shiftDateAndHour, 10)',
            array_merge([$de . 'T00:00:00'], $nomes)) as $r) {
            $diasEscala[escEntradaChave((string) $r['p'])][] = (string) $r['d'];
        }
        foreach (escQ('SELECT Page AS p, `Date` AS d, SUM(`5CReps`) AS v FROM `5c_wfm_db_mis`.`tab_intraday_base` WHERE `Date` >= ? AND Page IN (' . escIn($nomes) . ') GROUP BY Page, `Date`',
            array_merge([$de], $nomes)) as $r) {
            $venda[escEntradaChave((string) $r['p'])][substr((string) $r['d'], 0, 10)] = (float) $r['v'];
        }
    }
    $novaDe = escMaisDias($hoje, -max(0, (int) escV4Param('v4_estrutura_nova_dias', '7')));
    $out = [];
    foreach ($paginas as $id => $g) {
        $nome = (string) $g['name'];
        $k = escEntradaChave($nome);
        // Ativou depois da última foto: o dia vem do cadastro do site (updated_at), não da importação antiga.
        $recente = ($ultimaFoto[$id] ?? 0) === 0;
        $desde = $recente ? min($hoje, substr((string) ($g['updated_at'] ?? $hoje), 0, 10))
            : ($ativaDesde[$id] ?? ($g['imported_at'] ? substr((string) $g['imported_at'], 0, 10) : $hoje));
        // Página renomeada no site: a carteira, a saída e os postos de antes estão no nome antigo.
        $antes = null;
        $ult = $ultima[$k] ?? null;
        $sai = $saida[$k] ?? null;
        $naEscalaAntes = isset($naEscala[$k]);
        foreach ($nomesAntes[$id] ?? [] as $antigo) {
            $ka = escEntradaChave($antigo);
            if (!$ult && isset($ultima[$ka])) { $ult = $ultima[$ka]; $antes = $antigo; }
            if (!$sai && isset($saida[$ka])) { $sai = $saida[$ka]; $antes = $antes ?? $antigo; }
            if (isset($naEscala[$ka])) { $naEscalaAntes = true; $antes = $antes ?? $antigo; }
        }
        $presa = $sai && substr((string) $sai['vale_desde'], 0, 10) >= $bloqDe && !isset($previsaoDepois[$k]);
        $dias = count(array_filter($diasEscala[$k] ?? [], static fn($d) => $d >= $desde));
        $v = array_sum(array_filter($venda[$k] ?? [], static fn($d) => $d >= $desde, ARRAY_FILTER_USE_KEY));
        $dec = $decisoes[$k . '|' . $desde] ?? null;
        $temMovimento = $dias > 0 || $v > 0 || ($g['imported_at'] !== null && substr((string) $g['imported_at'], 0, 10) >= $novaDe);
        // RECENTE: ativou depois da última foto e ainda sem movimento. Não entra sozinha no Gerar (ele pede escala ou venda): decida aqui.
        $situacao = $dec ? ($dec['decisao'] === 'ENTROU' ? 'ENTROU' : 'IGNORADA') : ($presa ? 'PRESA' : ($movimento && !$temMovimento ? ($recente ? 'RECENTE' : 'PARADA') : 'AUTO'));
        $irma = $ult ? null : ($modeloAberta[escPaginaChaveModelo($nome)] ?? null);
        $out[] = ['page_id' => $id, 'pagina' => $nome, 'tier' => $g['tier'], 'ativa_desde' => $desde, 'desde_antes' => !$recente && $desde === $primeiraFoto,
            'recente' => $recente, 'nome_antes' => $antes,
            'tipo' => ($ult || $naEscalaAntes || $sai) ? 'RETORNO' : 'NOVA', 'ultima' => $ult, 'irma' => $irma, 'saida' => $sai, 'presa' => $presa,
            'decisao' => $dec, 'dias_escala' => $dias, 'venda' => $v, 'situacao' => $situacao,
            'carteira_sugerida' => $ult['carteira'] ?? ($irma['carteira'] ?? null)];
    }
    // Primeiro o que precisa de decisão (presas), depois as que entram sozinhas, paradas e decididas.
    $ordem = ['PRESA' => 0, 'RECENTE' => 1, 'AUTO' => 2, 'PARADA' => 3, 'ENTROU' => 4, 'IGNORADA' => 5];
    usort($out, static fn($a, $b) => [$ordem[$a['situacao']], $b['ativa_desde'], $a['pagina']] <=> [$ordem[$b['situacao']], $a['ativa_desde'], $b['pagina']]);
    return $out;
}

// Carteiras ativas do Capacity, para escolher onde a página entra.
function escEntradaCarteiras(): array {
    return escQ('SELECT carteira, unidade, nome FROM `5c_planning`.`fc_carteira` WHERE ativo = 1 ORDER BY unidade, carteira');
}

// Pôr na escala: grava a carteira no Capacity e recalcula; a escala recebe os postos no próximo Gerar.
// $nucleo: núcleo (OS) 1 a 3 da unidade da carteira para os postos novos da página (ES_139); null = automático.
function escEntradaAplicar(string $pagina, string $carteira, string $desde, string $obs, ?int $nucleo = null): array {
    if ($nucleo !== null && ($nucleo < 1 || $nucleo > 3)) {
        throw new RuntimeException(t('Núcleo inválido.'));
    }
    if (escModoTreino()) {
        throw new RuntimeException(t('No Modo Treino a entrada de página não mexe no Capacity.'));
    }
    if (!escEntradaTemTabela()) {
        throw new RuntimeException(t('Falta rodar o script ES_138 (entradas de página).'));
    }
    $minimo = max(date('Y-m-d'), escV4DiaEstrutura());
    if ($desde < $minimo) {
        throw new RuntimeException(t('A data de entrada não pode ser antes de {dia}.', ['dia' => dataBr($minimo)]));
    }
    $item = null;
    foreach (escEntradasDetectadas(false) as $e) {
        if ($e['pagina'] === $pagina) {
            $item = $e;
        }
    }
    if (!$item) {
        throw new RuntimeException(t('{pagina} não está ativa no site ou já está no Capacity.', ['pagina' => $pagina]));
    }
    $cart = escQ1('SELECT carteira, unidade FROM `5c_planning`.`fc_carteira` WHERE carteira = ? AND ativo = 1', [$carteira]);
    if (!$cart) {
        throw new RuntimeException(t('Carteira não encontrada: {carteira}.', ['carteira' => $carteira]));
    }
    $motivo = mb_substr(($item['tipo'] === 'RETORNO' ? 'Voltou' : 'Entrou') . ' em ' . date('d/m/Y', strtotime($desde)) . ' (Funil de entrada, ativa no site desde '
        . date('d/m/Y', strtotime($item['ativa_desde'])) . ')' . ($obs !== '' ? ': ' . $obs : ''), 0, 255);
    $lock = (int) escDbW()->query("SELECT GET_LOCK('5c_escalas.entrada_pagina', 30)")->fetchColumn();
    if ($lock !== 1) {
        throw new RuntimeException(t('Outra entrada de página está sendo gravada agora. Tente de novo em instantes.'));
    }
    try {
        $origem = 'ultima';
        if ($item['ultima'] && escQ1('SELECT 1 AS ok FROM `5c_planning`.`fc_pagina_carteira` WHERE pagina = ? LIMIT 1', [$pagina])) {
            escCapacityGravar('carteira_entrar', [$desde, $carteira, $motivo, $pagina]);
        } elseif ($item['ultima'] && ($item['nome_antes'] ?? null) !== null && (string) $item['ultima']['pagina'] !== $pagina) {
            // Renomeada no site: a carteira nova copia modelo, regra e volume da última linha do nome antigo.
            $origem = 'nome_antes';
            escCapacityGravar('carteira_renomeada', [$pagina, $desde, $carteira, mb_substr($motivo . ' · antes ' . $item['ultima']['pagina'], 0, 255), (string) $item['ultima']['pagina'], $pagina]);
        } else {
            // Página nova (ou com outro nome na carteira antiga): volume do arquétipo do tier e perfil.
            $perfil = escPaginaPerfil($pagina);
            $tier = $item['tier'] !== null && $item['tier'] !== '' ? (string) $item['tier'] : null;
            [$vol, $origem] = escFunilVolume(['perfil' => $perfil, 'fans_h_a' => null, 'fans_h_c' => null, 'fans_h_d' => null], $tier, escFunilArquetipos());
            $fas = round(max(0.1, ($vol['A'] + $vol['C'] + $vol['D']) / 3), 2);
            $modelo = $item['irma']['modelo'] ?? escPaginaChaveModelo($pagina);
            escCapacityGravar('pagina_nova', [$pagina, $desde, $carteira, $modelo, $fas, $motivo, $pagina]);
        }
        if (!escQ1('SELECT 1 AS ok FROM `5c_planning`.`fc_pagina_carteira` WHERE pagina = ? AND vigente_ate IS NULL', [$pagina])) {
            throw new RuntimeException(t('{pagina}: o Capacity não aceitou a carteira. Nada foi gravado na escala.', ['pagina' => $pagina]));
        }
        // A decisão fica gravada logo depois da carteira: se o recálculo falhar, o próximo Gerar recalcula.
        escW('INSERT INTO `' . ESC_SCHEMA . '`.`esc_pagina_entrada_checagem` (page_id, pagina, ativa_desde, tipo, decisao, carteira, vale_desde, observacao, decidido_por)
              VALUES (?, ?, ?, ?, \'ENTROU\', ?, ?, ?, ?) AS n
              ON DUPLICATE KEY UPDATE decisao = n.decisao, carteira = n.carteira, vale_desde = n.vale_desde, observacao = n.observacao, decidido_em = NOW(), decidido_por = n.decidido_por',
            [$item['page_id'], $pagina, $item['ativa_desde'], $item['tipo'], $carteira, $desde, mb_substr($obs, 0, 500) ?: null, escUserId() ?: null]);
        if ($nucleo !== null && escEntradaTemNucleo()) {
            escW('UPDATE `' . ESC_SCHEMA . '`.`esc_pagina_entrada_checagem` SET nucleo_numero = ? WHERE pagina = ? AND ativa_desde = ?', [$nucleo, $pagina, $item['ativa_desde']]);
        } elseif (escEntradaTemNucleo()) {
            escW('UPDATE `' . ESC_SCHEMA . '`.`esc_pagina_entrada_checagem` SET nucleo_numero = NULL WHERE pagina = ? AND ativa_desde = ?', [$pagina, $item['ativa_desde']]);
        }
        $res = ['pagina' => $pagina, 'tipo' => $item['tipo'], 'carteira' => $carteira, 'desde' => $desde, 'nucleo' => $nucleo, 'volume' => $origem, 'capacity' => [], 'erro_recalculo' => null];
        try {
            $comVersao = (bool) escQ1("SELECT 1 AS ok FROM information_schema.routines WHERE routine_schema = '5c_planning' AND routine_name = 'sp_fc_recalcular_postos'");
            foreach (escSaidaDatasCapacity($desde) as $dc) {
                escCapacityGravar($comVersao ? 'recalcular_versao' : 'recalcular', $comVersao
                    ? [$dc, mb_substr('Entrada de ' . $pagina . ' em ' . $carteira . ' a partir de ' . date('d/m/Y', strtotime($desde)) . ' (5C Escalas)', 0, 255)] : [$dc]);
                $res['capacity'][] = $dc;
            }
        } catch (Throwable $e) {
            appLog('error', 'Entrada de página: carteira gravada, recálculo do Capacity falhou', ['pagina' => $pagina, 'erro' => $e->getMessage()]);
            $res['erro_recalculo'] = $e->getMessage();
        }
        escLog('entrada.aplicar', 'esc_pagina_entrada_checagem', null, null, $res);
        return $res;
    } finally {
        escDbW()->query("SELECT RELEASE_LOCK('5c_escalas.entrada_pagina')")->fetchColumn();
    }
}

function escEntradaTemNucleo(): bool {
    static $ok = null;
    if ($ok === null) {
        $ok = escEntradaTemTabela() && (bool) escQ1("SELECT 1 AS ok FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'esc_pagina_entrada_checagem' AND COLUMN_NAME = 'nucleo_numero'", [ESC_SCHEMA]);
    }
    return $ok;
}

// Sincronização da estrutura (lib/estrutura.php): posto novo de página que entrou pelo Funil com núcleo
// escolhido (ES_139) fica com esse núcleo como dono, no turno do posto, se a unidade é a da carteira e
// todas as páginas do posto (fora as de apoio) têm esse núcleo. A cobertura passa para outro núcleo do
// turno quando coincidia. Só mexe em postos novos: depois de criado, o posto mantém o núcleo.
function escEntradaFixarNucleos(array &$novos, array $nucleos, string $desde): void {
    if (!$novos) {
        return;
    }
    try {
        if (!escEntradaTemNucleo()) {
            return;
        }
        $fixo = [];
        foreach (escQ("SELECT e.pagina, e.nucleo_numero, c.unidade FROM " . escT('esc_pagina_entrada_checagem') . " e
                         JOIN `5c_planning`.`fc_carteira` c ON c.carteira COLLATE utf8mb4_unicode_ci = e.carteira COLLATE utf8mb4_unicode_ci
                        WHERE e.decisao = 'ENTROU' AND e.nucleo_numero IS NOT NULL") as $f) {
            $fixo[escEntradaChave((string) $f['pagina'])] = ['unidade' => (int) $f['unidade'], 'numero' => (int) $f['nucleo_numero']];
        }
        if (!$fixo) {
            return;
        }
        $idDe = [];
        foreach (escQ('SELECT id, unidade, turno, numero FROM ' . escT('esc_nucleo') . ' WHERE vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)', [$desde, $desde]) as $n) {
            $idDe[$n['unidade'] . '|' . $n['turno'] . '|' . $n['numero']] = (int) $n['id'];
        }
        // Posto dividido com página sem núcleo escolhido (ex.: Alexis Clark + Bianca, 10/11): o núcleo não vale,
        // a página fica no núcleo do posto (decisão do usuário: a outra página não muda de núcleo).
        foreach ($novos as &$n) {
            $numero = null;
            foreach ($n['paginas'] as $pg => $g) {
                if (!empty($g['apoio'])) {
                    continue;
                }
                $f = $fixo[escEntradaChave((string) $pg)] ?? null;
                if (!$f || $f['unidade'] !== (int) $n['unidade'] || ($numero !== null && $numero !== $f['numero'])) {
                    $numero = null;
                    break;
                }
                $numero = $f['numero'];
            }
            $dono = $numero !== null ? ($idDe[$n['unidade'] . '|' . $n['turno'] . '|' . $numero] ?? null) : null;
            if ($dono === null) {
                continue;
            }
            $n['dono'] = $dono;
            if (($n['cob'] ?? null) === $dono || ($n['cob'] ?? null) === null) {
                $outros = array_values(array_diff($nucleos[$n['unidade'] . '|' . $n['turno']] ?? [], [$dono]));
                $n['cob'] = $outros[0] ?? null;
            }
        }
        unset($n);
    } catch (Throwable $e) {
        appLog('warning', 'Estrutura: núcleo escolhido na entrada de página não aplicado', ['erro' => $e->getMessage()]);
    }
}

// Ignorar (não é página de escala) ou desfazer o "ignorar".
function escEntradaIgnorar(string $pagina, string $ativaDesde, bool $ignorar, string $obs): void {
    if (!escEntradaTemTabela()) {
        throw new RuntimeException(t('Falta rodar o script ES_138 (entradas de página).'));
    }
    if (!$ignorar) {
        escW('DELETE FROM `' . ESC_SCHEMA . "`.`esc_pagina_entrada_checagem` WHERE pagina = ? AND ativa_desde = ? AND decisao = 'IGNORAR'", [$pagina, $ativaDesde]);
        escLog('entrada.desfazer_ignorar', 'esc_pagina_entrada_checagem', null, null, ['pagina' => $pagina, 'ativa_desde' => $ativaDesde]);
        return;
    }
    $item = null;
    foreach (escEntradasDetectadas(false) as $e) {
        if ($e['pagina'] === $pagina && $e['ativa_desde'] === $ativaDesde) {
            $item = $e;
        }
    }
    if (!$item) {
        throw new RuntimeException(t('{pagina} não está ativa no site ou já está no Capacity.', ['pagina' => $pagina]));
    }
    escW('INSERT INTO `' . ESC_SCHEMA . '`.`esc_pagina_entrada_checagem` (page_id, pagina, ativa_desde, tipo, decisao, observacao, decidido_por)
          VALUES (?, ?, ?, ?, \'IGNORAR\', ?, ?) AS n
          ON DUPLICATE KEY UPDATE decisao = n.decisao, carteira = NULL, vale_desde = NULL, observacao = n.observacao, decidido_em = NOW(), decidido_por = n.decidido_por',
        [$item['page_id'], $pagina, $ativaDesde, $item['tipo'], mb_substr($obs, 0, 500) ?: null, escUserId() ?: null]);
    escLog('entrada.ignorar', 'esc_pagina_entrada_checagem', null, null, ['pagina' => $pagina, 'ativa_desde' => $ativaDesde]);
}
