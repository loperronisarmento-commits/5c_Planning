<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/entradas.php
// Fotos e hashes das entradas da escala (o que mudou desde a última geração) e a verificação periódica.
// Separado de lib/motor.php em 04/11 (1.19.18), só movendo as funções, sem mudar nada.
// Carregado por lib/motor.php (require_once no topo).
// ===============================================

function escMotorFotosAtuais(): array {
    $fotos = [];
    $hoje = date('Y-m-d');

    $cap = escMotorCapacity(escDiasDaSemana(segundaDaSemana($hoje)));
    $m = [];
    foreach ($cap['nec'] as $nome => $porDia) {
        foreach ($porDia as $dia => $blocos) {
            foreach ($blocos as $bloco => $need) {
                $m[$nome . '|' . date('N', strtotime($dia)) . '|' . $bloco] = round($need, 2);
            }
        }
    }
    $fotos['CAPACITY'] = [$m, (string) $cap['dc']];

    $m = [];
    foreach (escEstrutura() as $rep => $e) {
        $m[$rep] = $e['team'] . '|' . $e['party'] . '|' . $e['role'];
    }
    $fotos['ESTRUTURA'] = [$m, null];

    $m = [];
    foreach (escMotorPaginas() as $p) {
        $m[(string) $p['page_id']] = $p['nome'] . '|' . $p['party'];
    }
    $fotos['PAGINAS'] = [$m, null];

    $m = [];
    foreach (escMotorConfig($hoje) as $rep => $c) {
        $m[$rep] = $c['turno'] . '|' . $c['tipo'] . '|' . $c['modelo_id'] . '|' . ($c['trilha'] ?? '') . '|' . ($c['folgas_fixas'] ?? '');
    }
    $fotos['COLABORADORES'] = [$m, null];

    $m = [];
    foreach (escQ('SELECT d.id, UPPER(i.rep_code) AS rep, d.dia, d.turno, d.blocos FROM ' . escT('esc_indisponibilidade_data') . ' d JOIN ' . escT('esc_indisponibilidade') . ' i ON i.id = d.indisponibilidade_id WHERE d.status = \'APROVADO\' AND d.dia >= CURDATE() - INTERVAL 7 DAY') as $l) {
        $m[(string) $l['id']] = $l['rep'] . '|' . substr($l['dia'], 0, 10) . '|' . $l['turno'] . '|' . ($l['blocos'] ?? '');
    }
    $fotos['INDISPONIBILIDADE'] = [$m, null];

    $m = [];
    foreach (escQ('SELECT id, ciclo, tipo, trilhas, ativo FROM ' . escT('esc_modelo_escala')) as $l) {
        $m[(string) $l['id']] = $l['ciclo'] . '|' . $l['tipo'] . '|' . $l['trilhas'] . '|' . $l['ativo'];
    }
    $fotos['MODELOS'] = [$m, null];

    $m = [];
    foreach (escQ("SELECT pageId, MAX(partyName) AS party, MAX(`timestamp`) AS ts FROM `5c_historic_planner`.`historic_page_planner` WHERE changeTypeId = 40 AND isApplied = 0 AND `timestamp` >= DATE_FORMAT(NOW() - INTERVAL 1 DAY, '%Y-%m-%dT%H:%i:%s') GROUP BY pageId") as $l) {
        $m[(string) $l['pageId']] = $l['party'] . '|' . $l['ts'];
    }
    $fotos['TROCAS_PAGINA'] = [$m, null];

    $saida = [];
    foreach ($fotos as $fonte => [$mapa, $ref]) {
        ksort($mapa);
        $saida[$fonte] = ['conteudo' => $mapa, 'hash' => hash('sha256', json_encode($mapa, JSON_UNESCAPED_UNICODE)), 'referencia' => $ref, 'linhas' => count($mapa)];
    }
    return $saida;
}

// Grava as fotos que mudaram e o De→Para delas. Devolve [fonte => foto_id] e as fontes que mudaram.
function escMotorRegistrarFotos(array $fotos): array {
    $ids = [];
    $mudaram = [];
    foreach ($fotos as $fonte => $f) {
        $ultima = escQ1('SELECT id, hash, conteudo FROM ' . escT('esc_entrada_foto') . ' WHERE fonte = ? ORDER BY id DESC LIMIT 1', [$fonte]);
        if ($ultima && $ultima['hash'] === $f['hash']) {
            $ids[$fonte] = (int) $ultima['id'];
            continue;
        }
        escW('INSERT INTO ' . escT('esc_entrada_foto') . ' (fonte, referencia, hash, linhas, conteudo) VALUES (?, ?, ?, ?, ?)',
            [$fonte, $f['referencia'], $f['hash'], $f['linhas'], json_encode($f['conteudo'], JSON_UNESCAPED_UNICODE)]);
        $novo = escUltimoId();
        $ids[$fonte] = $novo;
        if (!$ultima) {
            continue; // primeira foto: nada a comparar
        }
        $mudaram[$fonte] = $novo;
        $antes = json_decode((string) $ultima['conteudo'], true) ?: [];
        $linhas = [];
        foreach (array_unique(array_merge(array_keys($antes), array_keys($f['conteudo']))) as $chave) {
            $de = $antes[$chave] ?? null;
            $para = $f['conteudo'][$chave] ?? null;
            if ((string) $de !== (string) $para) {
                $linhas[] = ['foto_de_id' => (int) $ultima['id'], 'foto_para_id' => $novo, 'fonte' => $fonte,
                    'chave' => mb_substr((string) $chave, 0, 150), 'campo' => escCampoDaFonte($fonte),
                    'valor_de' => $de === null ? null : mb_substr((string) $de, 0, 255),
                    'valor_para' => $para === null ? null : mb_substr((string) $para, 0, 255)];
            }
            if (count($linhas) >= 2000) {
                break;
            }
        }
        escInserirLote('esc_entrada_diferenca', ['foto_de_id', 'foto_para_id', 'fonte', 'chave', 'campo', 'valor_de', 'valor_para'], $linhas);
    }
    return [$ids, $mudaram];
}

function escCampoDaFonte(string $fonte): string {
    return [
        'CAPACITY' => 'reps_necessarios', 'ESTRUTURA' => 'time|party|papel', 'PAGINAS' => 'pagina|party',
        'COLABORADORES' => 'turno|tipo|modelo|trilha|folgas', 'INDISPONIBILIDADE' => 'rep|dia|turno|blocos',
        'MODELOS' => 'ciclo|tipo|trilhas|ativo', 'TROCAS_PAGINA' => 'party_para|corte',
    ][$fonte] ?? 'valor';
}

function escHashEntradas(array $ids): string {
    ksort($ids);
    return hash('sha256', json_encode($ids));
}

// Compara as entradas atuais com as usadas no plano de cada semana em
// aberto. Se mudou, registra o evento e recalcula (nova subversão).
// Blocos e lacunas como estão gravados agora: o resumo é da geração e não acompanha ajuste manual nem hora extra.
function escResumosVivos(array $planoIds): array {
    $out = [];
    if ($planoIds) {
        foreach (escQ('SELECT plano_id, SUM(rep_code IS NOT NULL) AS blocos, SUM(rep_code IS NULL AND origem <> \'TREINO\') AS lacunas FROM ' . escT('esc_plano_alocacao')
            . ' WHERE plano_id IN (' . escIn($planoIds) . ') GROUP BY plano_id', array_values($planoIds)) as $l) {
            $out[(int) $l['plano_id']] = ['blocos' => (int) $l['blocos'], 'lacunas' => (int) $l['lacunas']];
        }
    }
    return $out;
}

// Semanas que o Verificar não conseguiu recalcular (texto para a tela; vazio se deu tudo certo).
function escVerificarFalhas(array $r): string {
    $falhas = $r['falhas'] ?? [];
    if (!$falhas) {
        return '';
    }
    return ' ' . t('Não deu para recalcular: {lista}.', ['lista' => implode('; ', array_map(static fn($f) => dataBr($f['semana']) . ' (' . $f['erro'] . ')', $falhas))]);
}

// Recálculo que falhou: o evento não fica "detectado" para sempre.
function escEventoErro(int $eventoId, Throwable $e): void {
    try {
        escW('UPDATE ' . escT('esc_recalculo_evento') . " SET status = 'ERRO', erro = ?, processado_em = NOW() WHERE id = ?", [mb_substr($e->getMessage(), 0, 500), $eventoId]);
    } catch (Throwable $e2) {
        appLog('error', 'Evento de recálculo sem registro do erro', ['evento' => $eventoId, 'erro' => $e2->getMessage()]);
    }
}

function escMotorVerificar(?int $userId, bool $forcar = false): array {
    $ultima = escQ1("SELECT valor FROM " . escT('esc_parametro') . " WHERE chave = 'motor_ultima_verificacao'");
    if (!$forcar && $ultima && strtotime($ultima['valor']) > time() - 15 * 60) {
        return ['verificado' => false, 'recalculados' => []];
    }
    $fotos = escMotorFotosAtuais();
    [$ids, $mudaram] = escMotorRegistrarFotos($fotos);
    escW('INSERT INTO ' . escT('esc_parametro') . " (chave, valor, tipo, grupo, descricao) VALUES ('motor_ultima_verificacao', ?, 'TEXTO', 'motor', 'Última verificação de mudanças nas entradas') AS n ON DUPLICATE KEY UPDATE valor = n.valor", [date('Y-m-d H:i:s')]);

    $planos = escQ("SELECT p.* FROM " . escT('esc_plano') . " p
                     WHERE p.time_nome = '' AND p.status IN ('RASCUNHO','PUBLICADO') AND p.semana_fim >= CURDATE()
                       AND NOT EXISTS (SELECT 1 FROM " . escT('esc_plano') . " q WHERE q.semana_inicio = p.semana_inicio AND q.time_nome = '' AND q.status IN ('RASCUNHO','PUBLICADO') AND (q.versao > p.versao OR (q.versao = p.versao AND q.subversao > p.subversao)))");
    $recalculados = [];
    $falhas = [];
    foreach ($planos as $p) {
        $usadas = json_decode((string) $p['parametros'], true)['fotos'] ?? [];
        $diferentes = [];
        foreach ($ids as $fonte => $id) {
            if (($usadas[$fonte] ?? null) !== $id) {
                $diferentes[] = $fonte;
            }
        }
        if (!$diferentes) {
            continue;
        }
        $gatilho = escGatilhoDaFonte($diferentes[0]);
        escW('INSERT INTO ' . escT('esc_recalculo_evento') . ' (gatilho, descricao, foto_id, semana_inicio, plano_de_id, status) VALUES (?, ?, ?, ?, ?, \'DETECTADO\')', [
            $gatilho, 'Mudou: ' . implode(', ', array_map(static fn($f) => escNomeFonte($f, false), $diferentes)), $ids[$diferentes[0]], substr($p['semana_inicio'], 0, 10), (int) $p['id'],
        ]);
        $eventoId = escUltimoId();
        try {
            $novo = escMotorGerar(substr($p['semana_inicio'], 0, 10), 'RECALCULO', $userId, $eventoId);
            $recalculados[] = ['semana' => substr($p['semana_inicio'], 0, 10), 'plano' => $novo, 'fontes' => $diferentes];
        } catch (Throwable $e) {
            escEventoErro($eventoId, $e);
            appLog('error', 'Recálculo automático falhou', ['erro' => $e->getMessage()]);
            $falhas[] = ['semana' => substr($p['semana_inicio'], 0, 10), 'erro' => mb_substr($e->getMessage(), 0, 160)];
        }
    }
    return ['verificado' => true, 'recalculados' => $recalculados, 'falhas' => $falhas, 'mudaram' => array_keys($mudaram)];
}

function escGatilhoDaFonte(string $fonte): string {
    return ['CAPACITY' => 'CAPACITY', 'ESTRUTURA' => 'ESTRUTURA', 'PAGINAS' => 'PAGINA_ENTRADA', 'COLABORADORES' => 'COLABORADOR',
        'INDISPONIBILIDADE' => 'INDISPONIBILIDADE', 'MODELOS' => 'MODELO', 'TROCAS_PAGINA' => 'TROCA_PAGINA'][$fonte] ?? 'MANUAL';
}

// Nome da fonte para a tela (traduzido); $traduzir = false para o que é gravado no banco.
function escNomeFonte(string $fonte, bool $traduzir = true): string {
    $nome = ['CAPACITY' => 'Capacity', 'ESTRUTURA' => 'estrutura/membros', 'PAGINAS' => 'páginas', 'COLABORADORES' => 'colaboradores',
        'INDISPONIBILIDADE' => 'indisponibilidade', 'MODELOS' => 'modelos', 'TROCAS_PAGINA' => 'trocas de página'][$fonte] ?? null;
    if ($nome === null) {
        return strtolower($fonte);
    }
    if (!$traduzir) {
        return $nome;
    }
    return ['Capacity' => t('Capacity'), 'estrutura/membros' => t('estrutura/membros'), 'páginas' => t('páginas'), 'colaboradores' => t('colaboradores'),
        'indisponibilidade' => t('indisponibilidade'), 'modelos' => t('modelos'), 'trocas de página' => t('trocas de página')][$nome];
}
