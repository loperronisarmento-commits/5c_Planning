<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/saida.php
// Saída de página (uma ou várias na mesma data) aplicada pelo sistema, de uma vez:
//   1. foto do Capacity (antes) das datas de cálculo em uso;
//   2. fecha a vigência da página no Capacity e recalcula (sp_fc_recalcular_postos,
//      que grava a versão no próprio Capacity; sem ele, sp_fc_postos);
//   3. foto do Capacity (depois), para comparar;
//   4. na escala: fecha os postos só da página; os postos com outras páginas
//      são refeitos sem ela, com a mesma equipe (ninguém troca de página);
//   5. quem ficou sem vaga fixa vai para uma vaga sem ninguém do mesmo
//      núcleo, unidade e turno, de preferência numa página que já conhece;
//      quem não couber fica livre para o motor (cobre lacuna ou vira buffer);
//   6. gera de novo (recálculo) as semanas com plano a partir da data.
// Com uma página entrando no lugar (ES_37), quem perdeu a vaga fixa vai para ela
// (mesmos núcleos) a partir da data de entrada, e os dias até lá ficam como buffer
// ou fora da escala (escSaidaPlanoEntrada).
// As únicas gravações fora do 5c_escalas são as do Capacity em escCapacityGravar.
// ===============================================

const ESC_CAP_LIMITE = ['PADRAO' => 35.0, 'SKYLAR' => 40.0, 'SLA80' => 20.0, 'EXCLUSIVA' => 20.0, 'ASSISTENTE_FIXO' => 35.0];

// Gravação no Capacity: só estas duas instruções, com parâmetros.
function escCapacityGravar(string $acao, array $params): void {
    // Modo Treino: nunca grava no Capacity real.
    if (escModoTreino()) {
        throw new RuntimeException(t('No Modo Treino a saída de página não mexe no Capacity. Use a prévia para ver o efeito.'));
    }
    $sql = [
        'fechar_pagina' => 'UPDATE `5c_planning`.`fc_pagina_carteira` SET vigente_ate = GREATEST(vigente_desde, ?), obs = ? WHERE pagina = ? AND vigente_ate IS NULL',
        'recalcular' => 'CALL `5c_planning`.`sp_fc_postos`(?)',
        // Troca de unidade (política de páginas): fecha a página na carteira de origem e abre na de destino.
        'carteira_sair' => 'UPDATE `5c_planning`.`fc_pagina_carteira` SET vigente_ate = GREATEST(vigente_desde, ?), obs = ? WHERE pagina = ? AND carteira = ? AND vigente_ate IS NULL',
        'carteira_entrar' => 'INSERT INTO `5c_planning`.`fc_pagina_carteira` (pagina, vigente_desde, vigente_ate, carteira, modelo, regra, ordem, fas_h_estimado, obs)
                              SELECT pagina, ?, NULL, ?, modelo, regra, ordem, fas_h_estimado, ? FROM `5c_planning`.`fc_pagina_carteira` WHERE pagina = ? ORDER BY vigente_desde DESC LIMIT 1',
        // Página que entra no lugar de uma que saiu (ES_37): só se ainda não estiver na carteira.
        'pagina_nova' => 'INSERT INTO `5c_planning`.`fc_pagina_carteira` (pagina, vigente_desde, vigente_ate, carteira, modelo, regra, ordem, fas_h_estimado, obs)
                          SELECT ?, ?, NULL, ?, ?, \'PADRAO\', 999, ?, ? FROM DUAL
                           WHERE NOT EXISTS (SELECT 1 FROM `5c_planning`.`fc_pagina_carteira` WHERE pagina = ? AND vigente_ate IS NULL)',
        // Página que entrou sozinha antes de gerar (lib/estrutura.php) com vigência antes do início da estrutura.
        'pagina_auto_inicio' => 'UPDATE `5c_planning`.`fc_pagina_carteira` SET vigente_desde = ? WHERE obs LIKE \'Entrou antes de gerar%\' AND vigente_desde < ? AND vigente_ate IS NULL',
        // Capacity com versões (script 19): recalcula e grava a versão com o motivo.
        'recalcular_versao' => 'CALL `5c_planning`.`sp_fc_recalcular_postos`(?, ?)',
    ][$acao] ?? null;
    if ($sql === null) {
        throw new RuntimeException(t('Gravação no Capacity não permitida: {acao}', ['acao' => $acao]));
    }
    // O recálculo usa tabelas de trabalho compartilhadas (fc_wrk_*), que ele limpa no começo.
    // Se outra execução estiver rodando ao mesmo tempo (o Capacity ou outro clique), dá chave
    // duplicada nelas: espera e tenta de novo (o recálculo refaz a data inteira, então repetir é seguro).
    $tentativas = in_array($acao, ['recalcular', 'recalcular_versao'], true) ? 3 : 1;
    for ($t = 1; ; $t++) {
        try {
            $stmt = escDbW()->prepare($sql);
            $stmt->execute($params);
            do {
                try {
                    $stmt->fetchAll();
                } catch (Throwable $e) {
                    // sem resultado neste conjunto
                }
            } while ($stmt->nextRowset());
            $stmt->closeCursor();
            return;
        } catch (PDOException $e) {
            if ($t >= $tentativas || strpos($e->getMessage(), 'fc_wrk_') === false) {
                throw $e;
            }
            sleep(5 * $t);
        }
    }
}

// Guarda a foto do Capacity de uma data de cálculo (necessidade e postos).
function escCapacityFoto(string $dataCalculo, string $momento, ?int $saidaId, string $motivo): int {
    $ut = escQ('SELECT unidade, turno, postos, titulares, buffer, presentes_dia, precisa, tem, saldo FROM `5c_planning`.`fc_necessidade_estrutura` WHERE data_calculo = ?', [$dataCalculo]);
    $postos = escQ("SELECT p.carteira, p.turno, p.posto, p.tipo, p.carga, p.capacidade,
                           GROUP_CONCAT(pp.pagina ORDER BY pp.fans_h DESC SEPARATOR ' + ') AS paginas
                      FROM `5c_planning`.`fc_postos` p
                      LEFT JOIN `5c_planning`.`fc_postos_pagina` pp ON pp.data_calculo = p.data_calculo AND pp.carteira = p.carteira AND pp.turno = p.turno AND pp.posto = p.posto
                     WHERE p.data_calculo = ? GROUP BY p.carteira, p.turno, p.posto, p.tipo, p.carga, p.capacidade", [$dataCalculo]);
    $soma = static fn(string $c) => (int) array_sum(array_map(static fn($l) => (float) ($l[$c] ?? 0), $ut));
    escW('INSERT INTO `' . ESC_SCHEMA . '`.`esc_capacity_versao` (data_calculo, momento, saida_id, motivo, postos, titulares, buffer, precisa, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$dataCalculo, $momento, $saidaId, mb_substr($motivo, 0, 255), count($postos), $soma('titulares'), $soma('buffer'), $soma('precisa'), escUserId() ?: null]);
    $id = escUltimoId();
    escInserirLote('esc_capacity_versao_ut', ['versao_id', 'unidade', 'turno', 'postos', 'titulares', 'buffer', 'presentes_dia', 'precisa', 'tem', 'saldo'],
        array_map(static fn($l) => ['versao_id' => $id] + $l, $ut));
    escInserirLote('esc_capacity_versao_posto', ['versao_id', 'carteira', 'turno', 'posto', 'tipo', 'paginas', 'carga', 'capacidade'],
        array_map(static fn($l) => ['versao_id' => $id, 'carteira' => $l['carteira'], 'turno' => $l['turno'], 'posto' => (int) $l['posto'], 'tipo' => $l['tipo'],
            'paginas' => mb_substr((string) $l['paginas'], 0, 500), 'carga' => $l['carga'], 'capacidade' => $l['capacidade']], $postos));
    return $id;
}

// Capacidade de um posto com estas páginas (Política do Capacity).
function escSaidaCapacidade(array $paginas): float {
    if (!$paginas) {
        return 0.0;
    }
    $info = [];
    foreach (escQ('SELECT pagina, modelo, regra FROM `5c_planning`.`fc_pagina_carteira` WHERE pagina IN (' . escIn($paginas) . ') ORDER BY vigente_desde', $paginas) as $r) {
        $info[$r['pagina']] = $r;
    }
    if (count($paginas) === 1) {
        return ESC_CAP_LIMITE[$info[$paginas[0]]['regra'] ?? 'PADRAO'] ?? 35.0;
    }
    $modelos = array_unique(array_map(static fn($p) => $info[$p]['modelo'] ?? $p, $paginas));
    return count($modelos) === 1 ? round(35 * (1 - 0.1 * (count($paginas) - 1)), 1) : 28.0;
}

// Datas de cálculo do Capacity em uso: as dos planos V4 que vão até a data ou depois, e a mais recente.
function escSaidaDatasCapacity(string $desde): array {
    $datas = [];
    foreach (escQ("SELECT DISTINCT capacity_calculo AS d FROM " . escT('esc_plano') . " WHERE estrutura = 'V4' AND status IN ('RASCUNHO','PUBLICADO') AND semana_fim >= ? AND capacity_calculo IS NOT NULL", [$desde]) as $r) {
        $datas[substr((string) $r['d'], 0, 10)] = true;
    }
    $max = escQ1('SELECT MAX(data_calculo) AS d FROM `5c_planning`.`fc_necessidade_estrutura`');
    if ($max && $max['d']) {
        $datas[substr((string) $max['d'], 0, 10)] = true;
    }
    $datas = array_keys($datas);
    sort($datas);
    return $datas;
}

// $entra (opcional, ES_37): página que entra no lugar, ver escSaidaPlanoEntrada.
function escAplicarSaidaPagina(string $pagina, string $desde, string $motivo, ?int $eventoId = null, ?array $entra = null): array {
    return escAplicarSaidasPaginas([['pagina' => $pagina, 'evento_id' => $eventoId]], $desde, $motivo, $entra);
}

// Várias páginas que saem na mesma data, de uma vez: o Capacity recalcula uma vez só e a
// escala é gerada de novo uma vez. Cada página tem a sua linha em esc_saida_pagina; o
// resultado (comum ao lote) fica na primeira e as outras apontam para ela (lote_de).
// Uma aplicação por vez: com outra rodando (dois cliques), o recálculo do Capacity
// bate na mesma tabela de trabalho, então para com aviso.
function escAplicarSaidasPaginas(array $itens, string $desde, string $motivo, ?array $entra = null): array {
    if (escModoTreino()) {
        throw new RuntimeException(t('No Modo Treino a saída de página não mexe no Capacity. Use a prévia para ver o efeito.'));
    }
    @set_time_limit(900);
    $porPagina = [];
    foreach ($itens as $i) {
        $p = trim((string) ($i['pagina'] ?? ''));
        if ($p !== '' && !isset($porPagina[$p])) {
            $porPagina[$p] = ['pagina' => $p, 'evento_id' => $i['evento_id'] ?? null];
        }
    }
    if (!$porPagina || !preg_match('~^\d{4}-\d{2}-\d{2}$~', $desde)) {
        throw new RuntimeException(t('Informe a página e a data.'));
    }
    if ((int) escDbW()->query("SELECT GET_LOCK('5c_escalas.saida_pagina', 0)")->fetchColumn() !== 1) {
        throw new RuntimeException(t('Já tem uma saída sendo aplicada (Capacity e escala recalculando). Espere terminar e confira em "Saídas aplicadas".'));
    }
    try {
        $val = escSaidaValidar(array_values($porPagina), $desde); // dentro da trava: dois cliques não passam juntos
        return escAplicarSaidasTravado(array_values($porPagina), $desde, $motivo, $val['inativas'], $entra);
    } finally {
        escDbW()->query("SELECT RELEASE_LOCK('5c_escalas.saida_pagina')")->fetchColumn();
    }
}

// $inativas: páginas que o Capacity já desativou (fechadas lá antes): só a escala é ajustada.
function escAplicarSaidasTravado(array $itens, string $desde, string $motivo, array $inativas = [], ?array $entra = null): array {
    $paginas = array_column($itens, 'pagina');
    $fechar = array_values(array_diff($paginas, $inativas));
    $saindo = array_flip($paginas);
    $rotulo = count($paginas) === 1 ? $paginas[0] : count($paginas) . ' páginas (' . implode(', ', $paginas) . ')';
    $ontem = escMaisDias($desde, -1);
    $ids = [];
    foreach ($itens as $i) {
        escW('INSERT INTO `' . ESC_SCHEMA . '`.`esc_saida_pagina` (pagina, vale_desde, evento_id, motivo, status, criado_por) VALUES (?, ?, ?, ?, \'ERRO\', ?)',
            [$i['pagina'], $desde, $i['evento_id'], mb_substr($motivo, 0, 255) ?: null, escUserId() ?: null]);
        $ids[$i['pagina']] = escUltimoId();
    }
    $saidaId = reset($ids);
    // Página que entra no lugar: o plano (postos, equipe, dias sem página) sai antes de mexer em nada.
    $planoEntrada = null;
    if ($entra) {
        if (count($itens) !== 1) {
            throw new RuntimeException(t('A página que entra no lugar vale para uma saída por vez.'));
        }
        $planoEntrada = escSaidaPlanoEntrada($itens[0]['pagina'], $desde, $entra);
        if (escSaidaComEntrada()) {
            escW('UPDATE `' . ESC_SCHEMA . '`.`esc_saida_pagina` SET entra_pagina = ?, entra_desde = ? WHERE id = ?', [$planoEntrada['pagina'], $planoEntrada['desde'], $saidaId]);
        }
    }
    $res = ['capacity' => [], 'postos_fechados' => [], 'postos_refeitos' => [], 'vagas_passadas' => [], 'livres' => [], 'planos' => []]
        + (count($paginas) > 1 ? ['paginas' => $paginas] : []) + ($inativas ? ['ja_inativas_no_capacity' => array_values($inativas)] : []);
    try {
        // 1–3. Capacity: fecha as páginas e recalcula uma vez cada data de cálculo. Se todas já
        //      estavam desativadas no Capacity, ele não é tocado (quem desativou já recalculou).
        $datas = ($fechar || $planoEntrada) ? escSaidaDatasCapacity($desde) : [];
        $antes = $depois = null;
        $fotoAntes = [];
        foreach ($datas as $dc) {
            $fotoAntes[$dc] = escCapacityFoto($dc, 'ANTES', $saidaId, 'saída de ' . $rotulo);
            $antes = $antes ?? $fotoAntes[$dc];
        }
        foreach ($fechar as $pagina) {
            escCapacityGravar('fechar_pagina', [$desde, mb_substr('Saiu em ' . date('d/m/Y', strtotime($desde)) . ($motivo !== '' ? ': ' . $motivo : ''), 0, 255), $pagina]);
        }
        if ($planoEntrada) {
            $pe = $planoEntrada;
            escCapacityGravar('pagina_nova', [$pe['pagina'], $pe['desde'], $pe['carteira'], $pe['modelo'], $pe['fans_media'],
                mb_substr('Entrou no lugar de ' . $paginas[0] . ' em ' . date('d/m/Y', strtotime($pe['desde'])) . ' (5C Escalas)', 0, 255), $pe['pagina']]);
        }
        $comVersao = (bool) escQ1("SELECT 1 AS ok FROM information_schema.routines WHERE routine_schema = '5c_planning' AND routine_name = 'sp_fc_recalcular_postos'");
        foreach ($datas as $dc) {
            if ($comVersao) {
                escCapacityGravar('recalcular_versao', [$dc, mb_substr('Saída de ' . $rotulo . ' a partir de ' . date('d/m/Y', strtotime($desde)) . ' (5C Escalas)', 0, 255)]);
            } else {
                escCapacityGravar('recalcular', [$dc]);
            }
            $d = escCapacityFoto($dc, 'DEPOIS', $saidaId, 'saída de ' . $rotulo);
            $depois = $depois ?? $d;
            $res['capacity'][] = ['data_calculo' => $dc, 'antes' => $fotoAntes[$dc], 'depois' => $d];
        }

        // 4–5. Escala (com página entrando: quem perde a vaga fica reservado para ela)
        escSaidaEscala($paginas, $desde, $res, $planoEntrada !== null);
        if ($planoEntrada) {
            escSaidaAplicarEntrada($planoEntrada, $saidaId, $res);
        }

        // 6. Escala de novo com o mínimo de mudanças sobre a publicada (mantém os ajustes travados).
        foreach (escQ("SELECT DISTINCT semana_inicio AS s FROM " . escT('esc_plano') . " WHERE estrutura = 'V4' AND status IN ('RASCUNHO','PUBLICADO') AND semana_fim >= ? ORDER BY semana_inicio", [$desde]) as $r) {
            $seg = substr((string) $r['s'], 0, 10);
            $res['planos'][] = ['semana' => $seg, 'plano_id' => escMotorGerar($seg, 'RECALCULO', escUserId() ?: null, null, true)];
        }

        foreach ($ids as $id) {
            escW('UPDATE `' . ESC_SCHEMA . '`.`esc_saida_pagina` SET status = \'APLICADA\', capacity_antes = ?, capacity_depois = ?, resultado = ? WHERE id = ?',
                [$antes, $depois, json_encode($id === $saidaId ? $res : ['lote_de' => $saidaId, 'paginas' => $paginas], JSON_UNESCAPED_UNICODE), $id]);
        }
        escLog('saida.aplicar', 'esc_saida_pagina', $saidaId, null, ['paginas' => $paginas, 'desde' => $desde, 'resultado' => $res]);
        return ['id' => $saidaId, 'ids' => $ids] + $res;
    } catch (Throwable $e) {
        foreach ($ids as $id) {
            escW('UPDATE `' . ESC_SCHEMA . '`.`esc_saida_pagina` SET status = \'ERRO\', erro = ?, resultado = ? WHERE id = ?',
                [mb_substr($e->getMessage(), 0, 500), json_encode($id === $saidaId ? $res : ['lote_de' => $saidaId, 'paginas' => $paginas], JSON_UNESCAPED_UNICODE), $id]);
        }
        throw $e;
    }
}

// Passos 4 e 5 da saída, só na escala (também usados na troca de unidade da política):
// fecha os postos só das páginas, refaz os postos com outras páginas sem elas (mesma equipe)
// e passa quem ficou sem vaga fixa para uma vaga sem ninguém do núcleo. Preenche $res.
function escSaidaEscala(array $paginas, string $desde, array &$res, bool $reservar = false): void {
    $saindo = array_flip($paginas);
    $ontem = escMaisDias($desde, -1);
    escTx(static function () use ($paginas, $saindo, $desde, $ontem, &$res, $reservar) {
        $postos = escQW('SELECT DISTINCT p.* FROM `' . ESC_SCHEMA . '`.`esc_posto` p JOIN `' . ESC_SCHEMA . '`.`esc_posto_pagina` pp ON pp.posto_id = p.id
                          WHERE pp.pagina IN (' . escIn($paginas) . ') AND (p.vigente_ate IS NULL OR p.vigente_ate >= ?)', array_merge($paginas, [$desde]));
        $livres = []; // [rep] = ['vaga', 'fatia', 'unidade', 'turno', 'de', 'perdeu']
        foreach ($postos as $p) {
            $pid = (int) $p['id'];
            $pags = escQW('SELECT pagina, page_id, fracao, fans_h, apoio FROM `' . ESC_SCHEMA . '`.`esc_posto_pagina` WHERE posto_id = ?', [$pid]);
            $perdeu = implode(' + ', array_column(array_filter($pags, static fn($g) => isset($saindo[$g['pagina']])), 'pagina'));
            $equipe = escQW('SELECT vaga, rep_code, fatia, ordem, status, motivo, origem, vigente_ate FROM `' . ESC_SCHEMA . '`.`esc_vaga_equipe`
                              WHERE posto_id = ? AND (vigente_ate IS NULL OR vigente_ate >= ?)', [$pid, $desde]);
            // Fecha o posto e a equipe no dia anterior (se começava depois, vigência de um dia só, como no ES_15).
            foreach (['esc_vaga_equipe' => 'posto_id', 'esc_posto' => 'id'] as $tab => $col) {
                escW('UPDATE `' . ESC_SCHEMA . '`.`' . $tab . '` SET vigente_desde = LEAST(vigente_desde, ?), vigente_ate = ?
                       WHERE ' . $col . ' = ? AND (vigente_ate IS NULL OR vigente_ate >= ?)', [$ontem, $ontem, $pid, $desde]);
            }
            $resto = array_values(array_filter($pags, static fn($g) => !isset($saindo[$g['pagina']])));
            if (!$resto) {
                $res['postos_fechados'][] = $pid;
                foreach ($equipe as $e) {
                    $livres[strtoupper($e['rep_code'])] = ['vaga' => $e['vaga'], 'fatia' => (float) $e['fatia'], 'unidade' => (int) $p['unidade'], 'turno' => $p['turno'], 'de' => $pid, 'perdeu' => $perdeu];
                }
                continue;
            }
            // Refaz o posto sem as páginas que saíram, com a mesma equipe.
            usort($resto, static fn($a, $b) => (float) $b['fans_h'] <=> (float) $a['fans_h']);
            $nomes = array_column($resto, 'pagina');
            $tipo = count($resto) === 1 && in_array($p['tipo'], ['double', 'triple'], true) ? 'titular' : $p['tipo'];
            $carga = round(array_sum(array_map(static fn($g) => (float) $g['fans_h'], $resto)), 1);
            // A cópia vale no mesmo período do original (ex.: posto do modelo só de 31/10 não pode virar aberto).
            $desdeNovo = max($desde, substr((string) $p['vigente_desde'], 0, 10));
            $ateNovo = $p['vigente_ate'] !== null ? substr((string) $p['vigente_ate'], 0, 10) : null;
            escW('INSERT INTO `' . ESC_SCHEMA . '`.`esc_posto` (versao, unidade, carteira, turno, seq, tipo, pagina_principal, n_paginas, assinatura, carga, capacidade, nucleo_dono_id, nucleo_cobertura_id, vigente_desde, vigente_ate)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                mb_substr((string) $p['versao'], 0, 16) . '-S', $p['unidade'], $p['carteira'], $p['turno'], $p['seq'], $tipo, $nomes[0], count($resto),
                mb_substr(implode(' + ', $nomes) . ' [' . $tipo . ']', 0, 255), $carga, escSaidaCapacidade($nomes),
                $p['nucleo_dono_id'], $p['nucleo_cobertura_id'], $desdeNovo, $ateNovo]);
            $novo = escUltimoId();
            $fr = round(1 / count($resto), 4);
            escInserirLote('esc_posto_pagina', ['posto_id', 'pagina', 'page_id', 'fracao', 'fans_h', 'apoio'],
                array_map(static fn($g) => ['posto_id' => $novo, 'pagina' => $g['pagina'], 'page_id' => $g['page_id'], 'fracao' => $fr, 'fans_h' => $g['fans_h'], 'apoio' => $g['apoio']], $resto));
            // Equipe também com a vigência dela: a que acabava antes do início da cópia (ex.: só em 31/10) não passa.
            $equipeNova = array_values(array_filter($equipe, static fn($e) => $e['vigente_ate'] === null || substr((string) $e['vigente_ate'], 0, 10) >= $desdeNovo));
            escInserirLote('esc_vaga_equipe', ['posto_id', 'vaga', 'rep_code', 'fatia', 'ordem', 'status', 'motivo', 'vigente_desde', 'vigente_ate', 'origem'],
                array_map(static fn($e) => ['posto_id' => $novo, 'vaga' => $e['vaga'], 'rep_code' => $e['rep_code'], 'fatia' => $e['fatia'], 'ordem' => $e['ordem'],
                    'status' => $e['status'], 'motivo' => mb_substr('posto refeito sem ' . $perdeu . ($e['motivo'] ? '; ' . $e['motivo'] : ''), 0, 255),
                    'vigente_desde' => $desdeNovo, 'vigente_ate' => $e['vigente_ate'] !== null ? substr((string) $e['vigente_ate'], 0, 10) : null, 'origem' => $e['origem']], $equipeNova));
            $res['postos_refeitos'][] = ['de' => $pid, 'para' => $novo, 'paginas' => $nomes];
        }

        // Quem ficou sem vaga fixa: uma vaga sem ninguém do mesmo tipo, núcleo, unidade e turno.
        if ($livres) {
            $reps = array_keys($livres);
            // Se continua em outra vaga fixa, não precisa de outra.
            foreach (escQW('SELECT DISTINCT UPPER(rep_code) AS rep FROM `' . ESC_SCHEMA . '`.`esc_vaga_equipe` WHERE rep_code IN (' . escIn($reps) . ') AND (vigente_ate IS NULL OR vigente_ate >= ?)',
                array_merge($reps, [$desde])) as $r) {
                unset($livres[$r['rep']]);
            }
        }
        if ($livres && $reservar) {
            // Página entrando no lugar: quem perdeu a vaga espera por ela (escSaidaAplicarEntrada).
            $res['reservados'] = $livres;
            return;
        }
        escSaidaPassarVagas($livres, $desde, $res);
    });
}

// Passo 5: quem ficou sem vaga fixa vai para uma vaga sem ninguém do mesmo tipo de vaga, núcleo,
// unidade e turno, de preferência numa página que já conhece; quem não couber fica livre.
// $livres = [rep => ['vaga', 'fatia', 'unidade', 'turno', 'de', 'perdeu']]. Rodar dentro de escTx.
function escSaidaPassarVagas(array $livres, string $desde, array &$res): void {
    if ($livres) {
        $reps = array_keys($livres);
        $nucleoDe = [];
        foreach (escQW('SELECT UPPER(rep_code) AS rep, nucleo_id FROM `' . ESC_SCHEMA . '`.`esc_colaborador_config` WHERE rep_code IN (' . escIn($reps) . ') AND vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?)',
            array_merge($reps, [$desde, $desde])) as $r) {
            $nucleoDe[$r['rep']] = (int) $r['nucleo_id'];
        }
        $vagas = escQW("SELECT p.id, p.unidade, p.turno, p.nucleo_dono_id, p.nucleo_cobertura_id,
                               (SELECT GROUP_CONCAT(pp.pagina SEPARATOR '\n') FROM `" . ESC_SCHEMA . "`.`esc_posto_pagina` pp WHERE pp.posto_id = p.id) AS paginas,
                               (SELECT GROUP_CONCAT(DISTINCT e.vaga) FROM `" . ESC_SCHEMA . "`.`esc_vaga_equipe` e WHERE e.posto_id = p.id AND (e.vigente_ate IS NULL OR e.vigente_ate >= ?)) AS tem
                          FROM `" . ESC_SCHEMA . "`.`esc_posto` p WHERE p.vigente_desde <= ? AND (p.vigente_ate IS NULL OR p.vigente_ate >= ?)", [$desde, $desde, $desde]);
        $hist = [];
        foreach (escQ('SELECT UPPER(codigo) AS rep, modeloNome AS pagina, COUNT(DISTINCT LEFT(shiftDateAndHour, 10)) AS dias FROM `5c_schedule`.`scheduleday`
                        WHERE codigo IN (' . escIn($reps) . ') AND shiftDateAndHour >= ? GROUP BY UPPER(codigo), modeloNome',
            array_merge($reps, [escMaisDias($desde, -56) . 'T00:00:00'])) as $r) {
            $hist[$r['rep']][$r['pagina']] = (int) $r['dias'];
        }
        $ocupada = [];
        foreach ($livres as $rep => $l) {
            $melhor = null;
            $kMelhor = null;
            foreach ($vagas as $v) {
                $nucVaga = $l['vaga'] === 'DONO' ? (int) $v['nucleo_dono_id'] : (int) $v['nucleo_cobertura_id'];
                $tem = explode(',', (string) $v['tem']);
                if ((int) $v['unidade'] !== $l['unidade'] || $v['turno'] !== $l['turno'] || $nucVaga !== ($nucleoDe[$rep] ?? -1)
                    || in_array($l['vaga'], $tem, true) || isset($ocupada[$v['id'] . '|' . $l['vaga']])) {
                    continue;
                }
                $dias = 0;
                foreach (explode("\n", (string) $v['paginas']) as $pg) {
                    $dias += $hist[$rep][$pg] ?? 0;
                }
                $k = [$dias, -(int) $v['id']];
                if ($kMelhor === null || $k > $kMelhor) {
                    $kMelhor = $k;
                    $melhor = $v;
                }
            }
            if ($melhor === null) {
                $res['livres'][] = $rep;
                continue;
            }
            $ocupada[$melhor['id'] . '|' . $l['vaga']] = true;
            escW('INSERT INTO `' . ESC_SCHEMA . '`.`esc_vaga_equipe` (posto_id, vaga, rep_code, fatia, ordem, status, motivo, vigente_desde, origem) VALUES (?, ?, ?, ?, 1, \'PROPOSTA\', ?, ?, \'MONTADOR\')',
                [(int) $melhor['id'], $l['vaga'], $rep, $l['fatia'], mb_substr('saída de ' . $l['perdeu'] . ': vaga sem ninguém do núcleo', 0, 255), $desde]);
            $res['vagas_passadas'][] = ['rep' => $rep, 'vaga' => $l['vaga'], 'de' => $l['de'], 'para' => (int) $melhor['id'], 'dias_nas_paginas' => $kMelhor[0]];
        }
    }
}

// Prévia (só leitura) do que a saída faria: postos que fecham, postos refeitos sem a página,
// SRs que perdem a vaga fixa e as semanas que serão geradas de novo. Não grava nada.
function escSaidaPrevia(array $paginas, string $desde): array {
    $paginas = array_values(array_unique(array_filter(array_map('trim', $paginas), 'strlen')));
    if (!$paginas || !preg_match('~^\d{4}-\d{2}-\d{2}$~', $desde)) {
        throw new RuntimeException(t('Informe a página e a data.'));
    }
    $saindo = array_flip($paginas);
    $res = ['fechados' => [], 'refeitos' => [], 'sem_vaga' => [], 'mantem' => [], 'semanas' => [], 'ja_aplicada' => []];
    foreach (escQ('SELECT pagina, vale_desde, criado_em FROM ' . escT('esc_saida_pagina') . " WHERE status = 'APLICADA' AND pagina IN (" . escIn($paginas) . ')', $paginas) as $j) {
        $res['ja_aplicada'][] = $j;
    }
    $postos = escQ('SELECT DISTINCT p.id, p.unidade, p.turno, p.tipo, p.pagina_principal FROM ' . escT('esc_posto') . ' p JOIN ' . escT('esc_posto_pagina') . ' pp ON pp.posto_id = p.id
                     WHERE pp.pagina IN (' . escIn($paginas) . ') AND (p.vigente_ate IS NULL OR p.vigente_ate >= ?)', array_merge($paginas, [$desde]));
    foreach ($postos as $p) {
        $pid = (int) $p['id'];
        $pags = array_column(escQ('SELECT pagina FROM ' . escT('esc_posto_pagina') . ' WHERE posto_id = ?', [$pid]), 'pagina');
        $equipe = array_map('strtoupper', array_column(escQ('SELECT rep_code FROM ' . escT('esc_vaga_equipe') . ' WHERE posto_id = ? AND (vigente_ate IS NULL OR vigente_ate >= ?)', [$pid, $desde]), 'rep_code'));
        $resto = array_values(array_filter($pags, static fn($g) => !isset($saindo[$g])));
        $rot = 'U' . $p['unidade'] . ' · ' . $p['turno'] . ' · ' . implode(' + ', $pags);
        if (!$resto) {
            $res['fechados'][] = ['posto' => $rot, 'equipe' => $equipe];
            foreach ($equipe as $r) {
                $res['sem_vaga'][$r] = $rot;
            }
        } else {
            $res['refeitos'][] = ['posto' => $rot, 'fica' => implode(' + ', $resto), 'equipe' => $equipe];
            foreach ($equipe as $r) {
                $res['mantem'][$r] = true;
            }
        }
    }
    foreach (escQ("SELECT DISTINCT semana_inicio AS s FROM " . escT('esc_plano') . " WHERE estrutura = 'V4' AND status IN ('RASCUNHO','PUBLICADO') AND semana_fim >= ? ORDER BY semana_inicio", [$desde]) as $r) {
        $res['semanas'][] = substr((string) $r['s'], 0, 10);
    }
    // Quem continua num posto refeito, ou tem outra vaga fixa aberta fora dos postos que fecham, não fica sem vaga.
    $idsFecham = array_map('intval', array_column($postos, 'id')); // todos os postos que têm a página
    foreach (array_keys($res['sem_vaga']) as $r) {
        if (isset($res['mantem'][$r])) {
            unset($res['sem_vaga'][$r]);
            continue;
        }
        $outra = escQ1('SELECT 1 AS ok FROM ' . escT('esc_vaga_equipe') . ' WHERE UPPER(rep_code) = ? AND (vigente_ate IS NULL OR vigente_ate >= ?) AND posto_id NOT IN (' . escIn($idsFecham) . ') LIMIT 1', array_merge([$r, $desde], $idsFecham ?: [0]));
        if ($outra) {
            unset($res['sem_vaga'][$r]);
        }
    }
    return $res;
}

// Antes de aplicar: data a partir de hoje, página conhecida (no Capacity ou na escala) e sem saída
// já aplicada para a mesma página por perto (mesmo evento, ou até 60 dias de diferença). A tela usa
// a mesma regra. Página que o Capacity já desativou não é erro: volta em 'inativas' e a aplicação
// só ajusta a escala. Página que não está nem no Capacity nem na escala (saiu antes da estrutura
// nova) não tem o que aplicar: com $tolerar volta em 'inexistentes' (o lote só confirma); sem, é erro.
const ESC_SAIDA_JANELA_DIAS = 60;
function escSaidaValidar(array $itens, string $desde, bool $tolerar = false): array {
    if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $desde)) {
        throw new RuntimeException(t('Informe a página e a data.'));
    }
    if ($desde < date('Y-m-d')) {
        throw new RuntimeException(t('A saída vale a partir de hoje ou de uma data futura: não dá para aplicar no passado.'));
    }
    $inativas = $inexistentes = [];
    foreach ($itens as $i) {
        $p = (string) $i['pagina'];
        $ja = escQW('SELECT vale_desde, criado_em FROM `' . ESC_SCHEMA . "`.`esc_saida_pagina` WHERE status = 'APLICADA' AND ((evento_id IS NOT NULL AND evento_id = ?) OR (pagina = ? AND ABS(DATEDIFF(vale_desde, ?)) <= " . ESC_SAIDA_JANELA_DIAS . ')) ORDER BY id DESC LIMIT 1',
            [$i['evento_id'] ?? null, $p, $desde]);
        if ($ja) {
            throw new RuntimeException(t('{pagina}: esta saída já foi aplicada em {quando} (sem a página a partir de {desde}). Confira em "Saídas aplicadas".', ['pagina' => $p, 'quando' => escDataHora((string) $ja[0]['criado_em']), 'desde' => dataBr($ja[0]['vale_desde'])]));
        }
        $ativa = escQ1('SELECT 1 AS ok FROM `5c_planning`.`fc_pagina_carteira` WHERE pagina = ? AND (vigente_ate IS NULL OR vigente_ate > ?) LIMIT 1', [$p, $desde]);
        if (!$ativa) {
            $conhecida = escQ1('SELECT 1 AS ok FROM `5c_planning`.`fc_pagina_carteira` WHERE pagina = ? LIMIT 1', [$p])
                ?: escQ1('SELECT 1 AS ok FROM `' . ESC_SCHEMA . '`.`esc_posto_pagina` WHERE pagina = ? LIMIT 1', [$p]);
            if (!$conhecida) {
                if ($tolerar) {
                    $inexistentes[] = $p;
                    continue;
                }
                throw new RuntimeException(t('{pagina} não está no Capacity nem na escala: não há nada a aplicar. Use "Confirmar" para fechar a checagem.', ['pagina' => $p]));
            }
            $inativas[] = $p;
        }
    }
    return ['inativas' => $inativas, 'inexistentes' => $inexistentes];
}

// ---------- Página que entra no lugar de uma que saiu (ES_37) ----------

function escSaidaComEntrada(): bool {
    static $ok = null;
    if ($ok === null) {
        $ok = (bool) escQ1("SELECT 1 AS ok FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'esc_saida_pagina' AND COLUMN_NAME = 'entra_pagina'", [ESC_SCHEMA])
            && (bool) escQ1("SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'esc_saida_sem_pagina'", [ESC_SCHEMA]);
    }
    return $ok;
}

// Páginas que podem entrar no lugar: previsões do Funil e páginas ativas no site da 5C que ainda
// não estão no Capacity (sem OFTV, testes e a página 5C Schedule). Só entra quem já está no site
// (a postagem precisa do page_id). [nome => ['pagina', 'page_id', 'origem', 'fans' => [A, C, D], 'data']]
function escSaidaCandidatasEntrada(): array {
    $site = [];
    foreach (escQ("SELECT g.id, g.name, g.imported_at FROM `5c_pages`.`pages` g WHERE g.active = 1
                     AND NOT EXISTS (SELECT 1 FROM `5c_planning`.`fc_pagina_carteira` c WHERE c.pagina COLLATE utf8mb4_unicode_ci = g.name COLLATE utf8mb4_unicode_ci AND c.vigente_ate IS NULL)
                   ORDER BY g.imported_at DESC, g.name") as $g) {
        $nome = trim((string) $g['name']);
        if ($nome === '' || escTrnOftv($nome) || preg_match('~\b(test|teste)\b|^5C Schedule$~i', $nome)) {
            continue;
        }
        $site[$nome] = ['pagina' => $nome, 'page_id' => (int) $g['id'], 'origem' => 'SITE', 'fans' => null, 'data' => null];
    }
    $out = [];
    try {
        foreach (escQ('SELECT pagina, fans_h_a, fans_h_c, fans_h_d, data_prevista FROM ' . escT('esc_pagina_previsao') . ' ORDER BY data_prevista IS NULL, data_prevista, pagina') as $f) {
            $nome = trim((string) $f['pagina']);
            if ($nome === '' || isset($out[$nome])) {
                continue;
            }
            $out[$nome] = ['pagina' => $nome, 'page_id' => $site[$nome]['page_id'] ?? null, 'origem' => 'FUNIL',
                'fans' => ['A' => (float) $f['fans_h_a'], 'C' => (float) $f['fans_h_c'], 'D' => (float) $f['fans_h_d']], 'data' => $f['data_prevista'] ? substr((string) $f['data_prevista'], 0, 10) : null];
        }
    } catch (Throwable $e) {
        appLog('warning', 'Saída: Funil indisponível para a página que entra', ['erro' => $e->getMessage()]);
    }
    return $out + $site;
}

// Plano (só leitura) da página que entra no lugar de $paginaSai: unidade e carteira da que saiu,
// postos por turno (35 fãs/h por posto; o 1º titular, os outros de assistente) nos mesmos núcleos
// (dono e cobertura) e, para cada SR que perde a vaga fixa, a vaga na página nova e o que fazer nos
// dias entre a saída e a entrada: buffer se falta gente no turno da unidade (saldo do Capacity < 0),
// senão fora da escala nesses dias. $entra = ['pagina', 'desde', 'fans' => [A, C, D] (opcional),
// 'decisoes' => [rep => AUTO|BUFFER|REMOVER]].
function escSaidaPlanoEntrada(string $paginaSai, string $desde, array $entra): array {
    $nome = trim((string) ($entra['pagina'] ?? ''));
    $entrada = (string) ($entra['desde'] ?? '');
    if ($nome === '' || !preg_match('~^\d{4}-\d{2}-\d{2}$~', $entrada)) {
        throw new RuntimeException(t('Escolha a página que entra e a data de entrada.'));
    }
    if ($nome === $paginaSai) {
        throw new RuntimeException(t('A página que entra precisa ser outra.'));
    }
    if ($entrada < $desde) {
        throw new RuntimeException(t('A página nova entra no dia da saída ou depois ({dia}).', ['dia' => dataBr($desde)]));
    }
    if (escTrnOftv($nome)) {
        throw new RuntimeException(t('{pagina} é OFTV: não entra na escala do chat.', ['pagina' => $nome]));
    }
    $site = escQ1('SELECT MAX(id) AS id FROM `5c_pages`.`pages` WHERE name = ? AND active = 1', [$nome]);
    if (!$site || !$site['id']) {
        throw new RuntimeException(t('{pagina} ainda não está ativa no site da 5C: a postagem precisa da página lá. Cadastre no site e tente de novo.', ['pagina' => $nome]));
    }
    if (escQ1('SELECT 1 AS ok FROM ' . escT('esc_posto_pagina') . ' pp JOIN ' . escT('esc_posto') . ' p ON p.id = pp.posto_id WHERE pp.pagina = ? AND (p.vigente_ate IS NULL OR p.vigente_ate >= ?) LIMIT 1', [$nome, $entrada])) {
        throw new RuntimeException(t('{pagina} já tem postos na escala.', ['pagina' => $nome]));
    }
    // Onde a página que sai está: por turno, unidade, carteira, núcleos e fãs/h; quais postos fecham.
    $lugar = [];
    $fecham = [];
    foreach (escQ('SELECT p.id, p.unidade, p.carteira, p.turno, p.nucleo_dono_id AS dono, p.nucleo_cobertura_id AS cob, pp.fans_h,
                          (SELECT COUNT(*) FROM ' . escT('esc_posto_pagina') . ' x WHERE x.posto_id = p.id AND x.pagina <> pp.pagina) AS outras
                     FROM ' . escT('esc_posto') . ' p JOIN ' . escT('esc_posto_pagina') . ' pp ON pp.posto_id = p.id
                    WHERE pp.pagina = ? AND (p.vigente_ate IS NULL OR p.vigente_ate >= ?) ORDER BY outras, p.id', [$paginaSai, $desde]) as $l) {
        $t = $l['turno'];
        $lugar[$t] = $lugar[$t] ?? ['unidade' => (int) $l['unidade'], 'carteira' => $l['carteira'], 'dono' => (int) $l['dono'], 'cob' => $l['cob'] !== null ? (int) $l['cob'] : null, 'fans' => 0.0];
        $lugar[$t]['fans'] += (float) $l['fans_h'];
        if ((int) $l['outras'] === 0) {
            $fecham[] = (int) $l['id'];
        }
    }
    if (!$lugar) {
        throw new RuntimeException(t('{pagina} não tem postos na escala a partir de {dia}: não há lugar para a página nova.', ['pagina' => $paginaSai, 'dia' => dataBr($desde)]));
    }
    $base = reset($lugar);
    $nucleosUT = [];
    foreach (escQ('SELECT id, turno, numero FROM ' . escT('esc_nucleo') . ' WHERE unidade = ? AND vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?) ORDER BY numero', [$base['unidade'], $entrada, $entrada]) as $n) {
        $nucleosUT[$n['turno']][] = (int) $n['id'];
    }
    $turnos = [];
    foreach (['A', 'C', 'D'] as $t) {
        $informado = $entra['fans'][$t] ?? null;
        $fans = $informado !== null && $informado !== '' ? (float) $informado : ($lugar[$t]['fans'] ?? 0.0);
        if ($fans <= 0 && !isset($lugar[$t])) {
            continue;
        }
        $fans = max(1.0, $fans);
        $ns = $nucleosUT[$t] ?? [];
        $turnos[$t] = ['unidade' => $base['unidade'], 'carteira' => $base['carteira'], 'fans' => round($fans, 1),
            'postos' => max(1, (int) ceil($fans / ESC_FUNIL_LIMITE_POSTO)),
            'dono' => $lugar[$t]['dono'] ?? ($ns[0] ?? null), 'cob' => $lugar[$t]['cob'] ?? ($ns[1] ?? null)];
        if (!$turnos[$t]['dono']) {
            throw new RuntimeException(t('A U{u} não tem núcleo no turno {turno} para a página nova.', ['u' => $base['unidade'], 'turno' => $t]));
        }
    }
    // Quem perde a vaga fixa (posto que fecha) e não tem outra vaga aberta.
    $equipe = [];
    if ($fecham) {
        foreach (escQ('SELECT v.posto_id, v.vaga, UPPER(v.rep_code) AS rep, v.fatia, p.turno, p.unidade, c.nivel
                         FROM ' . escT('esc_vaga_equipe') . ' v JOIN ' . escT('esc_posto') . ' p ON p.id = v.posto_id
                         LEFT JOIN ' . escT('esc_colaborador_config') . ' c ON c.rep_code = v.rep_code AND c.vigente_desde <= ? AND (c.vigente_ate IS NULL OR c.vigente_ate >= ?)
                        WHERE v.posto_id IN (' . escIn($fecham) . ') AND (v.vigente_ate IS NULL OR v.vigente_ate >= ?)', array_merge([$desde, $desde], $fecham, [$desde])) as $v) {
            $outra = escQ1('SELECT 1 AS ok FROM ' . escT('esc_vaga_equipe') . ' WHERE UPPER(rep_code) = ? AND (vigente_ate IS NULL OR vigente_ate >= ?) AND posto_id NOT IN (' . escIn($fecham) . ') LIMIT 1',
                array_merge([$v['rep'], $desde], $fecham));
            if ($outra || isset($equipe[$v['rep']])) {
                continue;
            }
            $equipe[$v['rep']] = ['rep' => $v['rep'], 'vaga' => $v['vaga'], 'fatia' => (float) $v['fatia'], 'turno' => $v['turno'], 'unidade' => (int) $v['unidade'], 'nivel' => (int) ($v['nivel'] ?? 0), 'posto_idx' => null];
        }
    }
    // Vaga na página nova: o 1º posto (titular) fica com o de nível mais alto de cada vaga; os postos
    // de assistente com SR até o nível máximo de assistente; quem sobra também entra no titular.
    $nivelMax = (int) escV4Param('v4_nivel_max_assistente', '2');
    foreach ($turnos as $t => $x) {
        foreach (['DONO', 'COBERTURA'] as $vaga) {
            $gente = array_values(array_filter($equipe, static fn($e) => $e['turno'] === $t && $e['vaga'] === $vaga && $e['unidade'] === $x['unidade']));
            usort($gente, static fn($a, $b) => [$b['nivel'], $a['rep']] <=> [$a['nivel'], $b['rep']]);
            $livreAssist = $x['postos'] > 1 ? range(1, $x['postos'] - 1) : [];
            foreach ($gente as $k => $e) {
                $idx = 0;
                if ($k > 0 && $livreAssist && $e['nivel'] <= $nivelMax) {
                    $idx = array_shift($livreAssist);
                }
                $equipe[$e['rep']]['posto_idx'] = $idx;
            }
        }
    }
    // Dias entre a saída e a entrada: decisão por pessoa (automática pelo saldo do Capacity).
    $saldo = [];
    foreach (escQ('SELECT unidade, turno, saldo FROM `5c_planning`.`fc_necessidade_estrutura` WHERE data_calculo = (SELECT MAX(data_calculo) FROM `5c_planning`.`fc_necessidade_estrutura`)') as $s) {
        $saldo[(int) $s['unidade'] . '|' . $s['turno']] = (float) $s['saldo'];
    }
    $semPagina = [];
    if ($entrada > $desde) {
        foreach ($equipe as $rep => $e) {
            $sd = $saldo[$e['unidade'] . '|' . $e['turno']] ?? null;
            $auto = $sd !== null && $sd < 0 ? 'BUFFER' : 'REMOVER';
            $escolha = strtoupper((string) ($entra['decisoes'][$rep] ?? 'AUTO'));
            $semPagina[$rep] = ['de' => $desde, 'ate' => escMaisDias($entrada, -1), 'auto' => $auto, 'saldo' => $sd,
                'decisao' => in_array($escolha, ['BUFFER', 'REMOVER'], true) ? $escolha : $auto];
        }
    }
    $fansPos = array_filter(array_column($turnos, 'fans'));
    return ['pagina' => $nome, 'page_id' => (int) $site['id'], 'desde' => $entrada, 'sai' => $paginaSai, 'sai_desde' => $desde,
        'unidade' => $base['unidade'], 'carteira' => $base['carteira'], 'modelo' => mb_strtolower(preg_replace('~[^a-z0-9]~i', '', $nome)),
        'fans_media' => $fansPos ? round(array_sum($fansPos) / count($fansPos), 1) : 1.0, 'turnos' => $turnos, 'equipe' => $equipe, 'sem_pagina' => $semPagina];
}

// Aplica o plano da página que entra (depois de escSaidaEscala com reserva): cria os postos, põe a
// equipe reservada na página nova a partir da entrada e grava os dias sem página. Quem estava
// reservado e não coube (outra unidade ou turno) segue a regra normal (vaga sem ninguém do núcleo).
function escSaidaAplicarEntrada(array $pe, int $saidaId, array &$res): void {
    escTx(static function () use ($pe, $saidaId, &$res) {
        $reservados = $res['reservados'] ?? [];
        $res['entrada'] = ['pagina' => $pe['pagina'], 'desde' => $pe['desde'], 'postos' => [], 'equipe' => [], 'sem_pagina' => []];
        $postos = [];
        foreach ($pe['turnos'] as $t => $x) {
            for ($i = 0; $i < $x['postos']; $i++) {
                $seq = (int) (escQW('SELECT COALESCE(MAX(seq), 0) + 1 AS s FROM ' . escT('esc_posto') . ' WHERE carteira = ? AND turno = ?', [$x['carteira'], $t])[0]['s'] ?? 1);
                $tipo = $i === 0 ? 'titular' : 'assistente';
                $carga = round($x['fans'] / $x['postos'], 1);
                escW('INSERT INTO ' . escT('esc_posto') . ' (versao, unidade, carteira, turno, seq, tipo, pagina_principal, n_paginas, assinatura, carga, capacidade, nucleo_dono_id, nucleo_cobertura_id, vigente_desde)
                      VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?)',
                    ['ENT' . date('Ymd'), $x['unidade'], $x['carteira'], $t, $seq, $tipo, $pe['pagina'], mb_substr($pe['pagina'] . ' [' . $tipo . ']', 0, 255), $carga, ESC_FUNIL_LIMITE_POSTO,
                     $x['dono'], $x['cob'], $pe['desde']]);
                $novo = escUltimoId();
                escW('INSERT INTO ' . escT('esc_posto_pagina') . ' (posto_id, pagina, page_id, fracao, fans_h, apoio) VALUES (?, ?, ?, 1, ?, 0)', [$novo, $pe['pagina'], $pe['page_id'], $carga]);
                $postos[$t][$i] = $novo;
                $res['entrada']['postos'][] = ['posto' => $novo, 'turno' => $t, 'tipo' => $tipo];
            }
        }
        $motivo = mb_substr('entrou no lugar de ' . $pe['sai'], 0, 255);
        foreach ($pe['equipe'] as $rep => $e) {
            if (!isset($reservados[$rep]) || $e['posto_idx'] === null || !isset($postos[$e['turno']][$e['posto_idx']])) {
                continue;
            }
            escW('INSERT INTO ' . escT('esc_vaga_equipe') . " (posto_id, vaga, rep_code, fatia, ordem, status, motivo, vigente_desde, origem) VALUES (?, ?, ?, ?, 1, 'CONFIRMADA', ?, ?, 'TELA')",
                [$postos[$e['turno']][$e['posto_idx']], $e['vaga'], $rep, $e['fatia'] ?: 1, $motivo, $pe['desde']]);
            $res['entrada']['equipe'][] = ['rep' => $rep, 'turno' => $e['turno'], 'vaga' => $e['vaga'], 'posto' => $postos[$e['turno']][$e['posto_idx']]];
            unset($reservados[$rep]);
            if (isset($pe['sem_pagina'][$rep])) {
                $sp = $pe['sem_pagina'][$rep];
                escW('INSERT INTO ' . escT('esc_saida_sem_pagina') . ' (saida_id, rep_code, unidade, turno, de, ate, decisao, motivo, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$saidaId, $rep, $e['unidade'], $e['turno'], $sp['de'], $sp['ate'], $sp['decisao'],
                     mb_substr('saída de ' . $pe['sai'] . ' até ' . $pe['pagina'] . ' entrar' . ($sp['decisao'] === $sp['auto'] ? ' (automático)' : ''), 0, 255), escUserId() ?: null]);
                $res['entrada']['sem_pagina'][] = ['rep' => $rep, 'turno' => $e['turno'], 'de' => $sp['de'], 'ate' => $sp['ate'], 'decisao' => $sp['decisao']];
            }
        }
        // Quem não coube na página nova: regra normal.
        escSaidaPassarVagas($reservados, $pe['sai_desde'], $res);
    });
}

// Dias em que o SR fica fora da escala por estar sem página até a nova entrar: [rep => [dia => true]].
function escSaidaSemPaginaBloqueios(array $dias): array {
    if (!$dias || !escSaidaComEntrada()) {
        return [];
    }
    $b = [];
    foreach (escQ('SELECT UPPER(rep_code) AS rep, de, ate FROM ' . escT('esc_saida_sem_pagina') . " WHERE decisao = 'REMOVER' AND de <= ? AND ate >= ?", [max($dias), min($dias)]) as $l) {
        foreach ($dias as $d) {
            if ($d >= substr((string) $l['de'], 0, 10) && $d <= substr((string) $l['ate'], 0, 10)) {
                $b[$l['rep']][$d] = true;
            }
        }
    }
    return $b;
}
