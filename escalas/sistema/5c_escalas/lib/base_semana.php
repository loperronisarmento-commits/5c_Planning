<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/base_semana.php
// Base da semana anterior, limite 80/20 e Anjo do OS (1.37.0, ES_213; regras em contexto/regras_base_semana_8020_anjo.md).
//   1. Base: as páginas de cada SR no plano da semana anterior (publicado; senão o atual) valem como verdade.
//   2. O motor roda uma vez livre (proposta). Cada SR da base que sai das páginas dele na proposta é uma movimentação:
//      - FORCADA (fora da conta): saiu da escala, a página saiu ou mudou de unidade/turno, equipe fixa nova na página,
//        ou o SR só faz cobertura (KPI abaixo de 25%);
//      - NUCLEO: mudou de unidade, turno ou núcleo; sempre muda e conta nos 20%;
//      - OPCIONAL: troca do motor. Impacto = faturamento da página no turno × (score de quem entra − score de quem sai).
//      No máximo v4_base_mov_max (20%) das NUCLEO + OPCIONAL acontecem: primeiro as NUCLEO, depois as OPCIONAL de
//      maior impacto (só impacto positivo). As outras ficam (FICA).
//   3. Anjo: cada OS (núcleo) escolhe 1 SR que ia mudar (OPCIONAL) para ficar mais uma semana (esc_anjo).
//   4. O motor roda de novo com a base: quem não muda entra antes do melhor SR nas páginas da base (lib/motor_v4.php).
// ===============================================

function escBaseInstalado(): bool {
    static $ok = null;
    if ($ok === null) {
        try {
            escQ('SELECT 1 FROM ' . escT('esc_base_movimento') . ' LIMIT 0');
            escQ('SELECT 1 FROM ' . escT('esc_anjo') . ' LIMIT 0');
            $ok = true;
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

function escBaseAtiva(): bool {
    return escV4Param('v4_base_semana_anterior', '0') === '1' && escBaseInstalado();
}

// Origens que não são página da base: Overlap, buffer, sobrecarga, treino e liderança.
function escBaseOrigemConta(string $origem, string $motivo): bool {
    if (in_array($origem, ['OVERLAP', 'BUFFER', 'SOBRECARGA', 'TREINO'], true) || strncmp($origem, 'LIDER', 5) === 0) {
        return false;
    }
    // Dia de Overlap usado para cobrir lacuna também não é a página do SR.
    return stripos($motivo, '(Overlap)') === false;
}

// Páginas de cada SR na semana anterior: ['plano' => plano|null, 'sr' => [rep => ['turno', 'blocos', 'paginas' => [page_id => blocos],
// 'nomes' => [page_id => nome], 'unidade', 'nucleo']]].
function escBaseCarregar(string $seg, ?int $overlapPageId = null): array {
    $segAnt = escMaisDias($seg, -7);
    $ant = escPlanoPublicado($segAnt) ?? escPlanoAtual($segAnt);
    if (!$ant) {
        return ['plano' => null, 'sr' => []];
    }
    $fatiaMin = (float) str_replace(',', '.', escV4Param('v4_base_min_fatia', '0.25'));
    $sr = [];
    foreach (escQ('SELECT UPPER(rep_code) AS rep, turno, page_id, pagina, origem, motivo FROM ' . escT('esc_plano_alocacao')
        . ' WHERE plano_id = ? AND rep_code IS NOT NULL AND page_id IS NOT NULL', [(int) $ant['id']]) as $l) {
        if (($overlapPageId !== null && (int) $l['page_id'] === $overlapPageId) || !escBaseOrigemConta((string) $l['origem'], (string) $l['motivo'])) {
            continue;
        }
        $r = $l['rep'];
        $pid = (int) $l['page_id'];
        $sr[$r]['blocos'] = ($sr[$r]['blocos'] ?? 0) + 1;
        $sr[$r]['paginas'][$pid] = ($sr[$r]['paginas'][$pid] ?? 0) + 1;
        $sr[$r]['nomes'][$pid] = (string) $l['pagina'];
        $sr[$r]['turnos'][$l['turno']] = ($sr[$r]['turnos'][$l['turno']] ?? 0) + 1;
    }
    // Cadastro do último dia da semana anterior (unidade, turno e núcleo de antes).
    $cad = [];
    foreach (escQ('SELECT UPPER(rep_code) AS rep, unidade, turno, nucleo_id FROM ' . escT('esc_colaborador_config')
        . " WHERE funcao = 'EQUITE' AND vigente_desde <= ? AND (vigente_ate IS NULL OR vigente_ate >= ?) ORDER BY vigente_desde", [escMaisDias($seg, -1), escMaisDias($seg, -1)]) as $c) {
        $cad[$c['rep']] = $c;
    }
    foreach ($sr as $r => &$x) {
        arsort($x['turnos']);
        $x['turno'] = (string) array_key_first($x['turnos']);
        arsort($x['paginas']);
        $x['paginas'] = array_filter($x['paginas'], static fn($n) => $n >= max(2, $fatiaMin * $x['blocos']));
        $x['unidade'] = isset($cad[$r]) ? (int) $cad[$r]['unidade'] : null;
        $x['nucleo'] = isset($cad[$r]) ? (int) $cad[$r]['nucleo_id'] : null;
        $x['turno_cad'] = $cad[$r]['turno'] ?? null;
    }
    unset($x);
    $sr = array_filter($sr, static fn($x) => !empty($x['paginas']));
    return ['plano' => $ant, 'sr' => $sr];
}

// Anjos da semana: [nucleo_id => rep].
function escBaseAnjos(string $seg): array {
    if (!escBaseInstalado()) {
        return [];
    }
    $a = [];
    foreach (escQ('SELECT nucleo_id, UPPER(rep_code) AS rep FROM ' . escT('esc_anjo') . ' WHERE semana = ?', [$seg]) as $l) {
        $a[(int) $l['nucleo_id']] = $l['rep'];
    }
    return $a;
}

// Movimentações: compara a base com a proposta livre do motor ($alocA) e escolhe quais acontecem.
// Devolve ['lista' => [...], 'resumo' => [...], 'fica' => [rep => true]] (fica = quem o motor mantém nas páginas da base).
function escBaseMovimentos(string $seg, array $base, array $est, array $alocA): array {
    $pessoas = $est['pessoas'];
    $overlapId = (int) ($est['overlap_pagina']['page_id'] ?? 0);
    $score = $est['desempenho'] ?? [];
    $receitaDe = static function (int $pageId, string $turno, array $nomes) use ($est): float {
        $nm = $nomes[$pageId] ?? null;
        return $nm !== null ? (float) ($est['receita'][$nm][$turno] ?? 0) : 0.0;
    };
    // Nome e unidade/turno de cada página nos postos de agora (sem as de apoio).
    $pagPosto = [];
    $nomePag = [];
    foreach ($est['postos'] as $p) {
        foreach ($p['paginas'] as $pg) {
            if (empty($pg['apoio'])) {
                $pagPosto[(int) $pg['page_id']][(int) $p['unidade'] . '|' . $p['turno']] = true;
                $nomePag[(int) $pg['page_id']] = (string) $pg['pagina'];
            }
        }
    }
    // Proposta: blocos de cada SR por página, e quem ocupa cada página por turno.
    $propSr = [];
    $ocupa = [];
    $temCelula = [];
    $fixoMotivo = [];
    $fixoSr = []; // [rep] = página da equipe fixa (MAIN, COVER ou EQUIPE) em que ele está na proposta
    $slotSr = []; // [rep][dia|turno][page_id] = true (combo de várias páginas conta uma vez por turno)
    $travSr = []; // [rep][page_id] = página de célula travada à mão
    foreach ($alocA as $a) {
        if ($a['rep_code'] === null) {
            continue;
        }
        $r = strtoupper((string) $a['rep_code']);
        $temCelula[$r] = true;
        $pid = (int) $a['page_id'];
        if ($pid === $overlapId || !escBaseOrigemConta((string) $a['origem'], (string) ($a['motivo'] ?? ''))) {
            continue;
        }
        $propSr[$r][$pid] = ($propSr[$r][$pid] ?? 0) + 1;
        $slotSr[$r][$a['dia'] . '|' . $a['turno']][$pid] = true;
        if (!empty($a['travada'])) {
            $travSr[$r][$pid] = (string) $a['pagina'];
        }
        $nomePag[$pid] = $nomePag[$pid] ?? (string) $a['pagina'];
        $ocupa[$pid . '|' . $a['turno']][$r] = ($ocupa[$pid . '|' . $a['turno']][$r] ?? 0) + 1;
        if (preg_match('~^(titular \(main\)|cover|equipe fixa) da página~', (string) ($a['motivo'] ?? ''))) {
            $fixoMotivo[$pid . '|' . $a['turno']][$r] = true;
            $fixoSr[$r] = (string) $a['pagina'];
        }
    }
    // Ocupante de cada página por turno na base (para medir o ganho de quem vai para uma página sem ninguém saindo dela).
    $ocupaBase = [];
    foreach ($base['sr'] as $r => $x) {
        foreach ($x['paginas'] as $pid => $n) {
            if (($ocupaBase[$pid . '|' . $x['turno']][1] ?? 0) < $n) {
                $ocupaBase[$pid . '|' . $x['turno']] = [$r, $n];
            }
        }
    }
    $lista = [];
    foreach ($base['sr'] as $r => $x) {
        $nomes = $x['nomes'] + $nomePag;
        $basePags = array_keys($x['paginas']);
        $pagsTxt = implode(', ', array_map(static fn($pid) => $nomes[$pid] ?? (string) $pid, $basePags));
        $mov = ['rep_code' => $r, 'unidade' => $pessoas[$r]['unidade'] ?? $x['unidade'], 'turno' => $pessoas[$r]['turno'] ?? $x['turno'],
            'nucleo_id' => $pessoas[$r]['nucleo'] ?? $x['nucleo'], 'paginas_base' => mb_substr($pagsTxt, 0, 500), 'pagina_para' => null, 'entra_rep' => null,
            'score_sai' => isset($score[$r]) ? round((float) $score[$r], 1) : null, 'score_entra' => null, 'receita' => null, 'impacto' => null];
        if (!isset($pessoas[$r])) {
            $lista[] = $mov + ['tipo' => 'FORCADA', 'situacao' => 'FORCADA', 'motivo' => t('saiu da escala (desligado, treinamento ou bloqueado)')];
            continue;
        }
        if (!isset($temCelula[$r])) {
            continue; // não trabalha na semana (indisponível): não é movimentação
        }
        $p = $pessoas[$r];
        // Página principal na proposta.
        $prop = $propSr[$r] ?? [];
        arsort($prop);
        $principal = $prop ? (int) array_key_first($prop) : null;
        $mov['pagina_para'] = $principal !== null ? ($nomePag[$principal] ?? null) : null;
        // Fica quando a metade ou mais dos turnos dele na proposta têm uma página da base.
        $slots = $slotSr[$r] ?? [];
        $naBase = count(array_filter($slots, static fn($pgs) => (bool) array_intersect_key($pgs, $x['paginas'])));
        $mudouUT = $x['unidade'] !== null && ($x['unidade'] !== $p['unidade'] || $x['turno_cad'] !== $p['turno']);
        if (!$mudouUT && $slots && $naBase * 2 >= count($slots)) {
            continue; // fica nas páginas da base
        }
        // Mudou de unidade, turno ou núcleo no cadastro: muda e conta nos 20%.
        if ($mudouUT || ($x['nucleo'] !== null && $x['nucleo'] !== $p['nucleo'])) {
            $lista[] = $mov + ['tipo' => 'NUCLEO', 'situacao' => 'MUDA', 'motivo' => $mudouUT ? t('mudou de unidade ou turno no cadastro') : t('mudou de núcleo no cadastro')];
            continue;
        }
        $trav = array_diff_key($travSr[$r] ?? [], $x['paginas']);
        if ($trav) {
            $lista[] = $mov + ['tipo' => 'FORCADA', 'situacao' => 'FORCADA', 'motivo' => t('travado à mão em outra página ({pagina})', ['pagina' => reset($trav)])];
            continue;
        }
        $existe = false;
        foreach ($basePags as $pid) {
            $existe = $existe || isset($pagPosto[$pid][$p['unidade'] . '|' . $p['turno']]);
        }
        if (!$existe) {
            $lista[] = $mov + ['tipo' => 'FORCADA', 'situacao' => 'FORCADA', 'motivo' => t('a página saiu, ficou sob demanda ou mudou de unidade ou turno')];
            continue;
        }
        if (isset($fixoSr[$r]) && !in_array($fixoSr[$r], array_map(static fn($pid) => $nomes[$pid] ?? '', $basePags), true)) {
            $lista[] = $mov + ['tipo' => 'FORCADA', 'situacao' => 'FORCADA', 'motivo' => t('está na equipe fixa de outra página ({pagina})', ['pagina' => $fixoSr[$r]])];
            continue;
        }
        if (isset(($est['so_cobertura'] ?? [])[$r])) {
            $lista[] = $mov + ['tipo' => 'FORCADA', 'situacao' => 'FORCADA', 'motivo' => t('KPI abaixo de 25%: só faz cobertura')];
            continue;
        }
        // Quem entra na página principal da base (no turno do SR).
        $pidBase = (int) $basePags[0];
        $ch = $pidBase . '|' . $p['turno'];
        $ent = $ocupa[$ch] ?? [];
        unset($ent[$r]);
        arsort($ent);
        $entra = $ent ? (string) array_key_first($ent) : null;
        if ($entra !== null && isset($fixoMotivo[$ch][$entra])) {
            $lista[] = $mov + ['entra_rep' => $entra, 'tipo' => 'FORCADA', 'situacao' => 'FORCADA', 'motivo' => t('equipe fixa da página entrou no lugar')];
            continue;
        }
        if ($entra !== null) {
            $rec = $receitaDe($pidBase, $p['turno'], $nomes);
            $sEnt = (float) ($score[$entra] ?? 75.0);
            $imp = $rec * ($sEnt - (float) ($score[$r] ?? 75.0)) / 100;
            $mov['entra_rep'] = $entra;
            $mov['score_entra'] = round($sEnt, 1);
            $motivo = t('o motor põe outra pessoa na página');
        } else {
            // Ninguém entra: ele vai para uma página maior (remanejamento) ou fica de buffer.
            $rec = $principal !== null ? $receitaDe($principal, $p['turno'], $nomePag) : 0.0;
            $dono = $principal !== null ? ($ocupaBase[$principal . '|' . $p['turno']][0] ?? null) : null;
            $imp = $rec * ((float) ($score[$r] ?? 75.0) - (float) ($dono !== null ? ($score[$dono] ?? 75.0) : 75.0)) / 100;
            // 1.39.0: a página da base fica sem ninguém (ex.: vermelha que só ele conhece), então o impacto é o ganho
            // na página nova menos o que a da base perde (Iasmim: Jadeteen 106m × Natasha Nice VIP, prévia de 30/11).
            $imp -= $receitaDe($pidBase, $p['turno'], $nomes) * (float) ($score[$r] ?? 75.0) / 100;
            $motivo = $principal !== null ? t('o motor o leva para outra página') : t('o motor o deixa sem página (buffer)');
        }
        $lista[] = array_merge($mov, ['receita' => round($rec, 2), 'impacto' => round($imp, 2), 'tipo' => 'OPCIONAL', 'situacao' => 'FICA', 'motivo' => $motivo]);
    }

    // 80/20: no máximo v4_base_mov_max das trocas (NUCLEO + OPCIONAL); as NUCLEO primeiro, depois as de maior impacto.
    $max = (float) str_replace(',', '.', escV4Param('v4_base_mov_max', '0.20'));
    $contaveis = array_filter($lista, static fn($m) => $m['tipo'] !== 'FORCADA');
    $limite = $contaveis ? (int) ceil(count($contaveis) * $max) : 0;
    $nNucleo = count(array_filter($lista, static fn($m) => $m['tipo'] === 'NUCLEO'));
    $resta = max(0, $limite - $nNucleo);
    $ordem = array_keys(array_filter($lista, static fn($m) => $m['tipo'] === 'OPCIONAL'));
    usort($ordem, static fn($a, $b) => [$lista[$b]['impacto'], $lista[$a]['rep_code']] <=> [$lista[$a]['impacto'], $lista[$b]['rep_code']]);
    $anjos = array_flip(escBaseAnjos($seg)); // rep => núcleo
    $impTotal = 0.0;
    foreach ($ordem as $k) {
        $impTotal += max(0.0, (float) $lista[$k]['impacto']);
    }
    $impFeito = 0.0;
    foreach ($ordem as $k) {
        if ($resta <= 0 || (float) $lista[$k]['impacto'] <= 0) {
            break;
        }
        $resta--;
        $r = $lista[$k]['rep_code'];
        if (isset($anjos[$r]) && (int) $anjos[$r] === (int) $lista[$k]['nucleo_id']) {
            $lista[$k]['situacao'] = 'ANJO';
            $lista[$k]['motivo'] .= ' · ' . t('Anjo do OS: fica mais uma semana');
            continue;
        }
        $lista[$k]['situacao'] = 'MUDA';
        $impFeito += (float) $lista[$k]['impacto'];
    }
    $fica = [];
    $muda = [];
    foreach ($lista as $m) {
        if ($m['situacao'] === 'MUDA' || $m['situacao'] === 'FORCADA') {
            $muda[$m['rep_code']] = true;
        }
    }
    foreach ($base['sr'] as $r => $x) {
        if (!isset($muda[$r]) && isset($pessoas[$r])) {
            $fica[$r] = true;
        }
    }
    $porSit = [];
    foreach ($lista as $m) {
        $porSit[$m['tipo'] . '|' . $m['situacao']] = ($porSit[$m['tipo'] . '|' . $m['situacao']] ?? 0) + 1;
    }
    return ['lista' => $lista, 'fica' => $fica, 'resumo' => [
        'plano_base' => $base['plano'] ? (int) $base['plano']['id'] : null, 'plano_base_rotulo' => $base['plano'] ? escPlanoRotulo($base['plano']) : null,
        'srs_base' => count($base['sr']), 'propostas' => count($contaveis), 'limite' => $limite, 'nucleo' => $nNucleo,
        'muda' => count(array_filter($lista, static fn($m) => $m['situacao'] === 'MUDA')),
        'fica' => count(array_filter($lista, static fn($m) => $m['situacao'] === 'FICA')),
        'anjo' => count(array_filter($lista, static fn($m) => $m['situacao'] === 'ANJO')),
        'forcada' => count(array_filter($lista, static fn($m) => $m['tipo'] === 'FORCADA')),
        'impacto_total' => round($impTotal, 2), 'impacto_feito' => round($impFeito, 2),
        'impacto_pct' => $impTotal > 0 ? round(100 * $impFeito / $impTotal, 1) : null, 'por_situacao' => $porSit,
    ]];
}

// Quem fez a página na semana anterior conhece a página (P-02, cor), fique ou mude: [rep => [página => true]].
function escBaseConhece(array $base): array {
    $c = [];
    foreach ($base['sr'] as $r => $x) {
        foreach ($x['paginas'] as $pid => $n) {
            $c[$r][$x['nomes'][$pid]] = true;
        }
    }
    return $c;
}

// Entrada do motor: [page_id => [rep => blocos na semana anterior]] só de quem fica, mais os Anjos.
function escBaseParaMotor(array $base, array $mov): array {
    $porPag = [];
    foreach ($base['sr'] as $r => $x) {
        if (!isset($mov['fica'][$r])) {
            continue;
        }
        foreach ($x['paginas'] as $pid => $n) {
            $porPag[(int) $pid][$r] = $n;
        }
    }
    $anjo = [];
    foreach ($mov['lista'] as $m) {
        if ($m['situacao'] === 'ANJO') {
            $anjo[$m['rep_code']] = true;
        }
    }
    return ['paginas' => $porPag, 'anjo' => $anjo, 'conhece' => escBaseConhece($base)];
}

// Grava as movimentações do plano (dentro da transação do Gerar).
function escBaseGravar(int $planoId, string $seg, ?array $mov): void {
    if (!$mov || !escBaseInstalado()) {
        return;
    }
    $linhas = [];
    foreach ($mov['lista'] as $m) {
        $linhas[] = ['plano_id' => $planoId, 'semana' => $seg, 'plano_base_id' => $mov['resumo']['plano_base'], 'rep_code' => $m['rep_code'],
            'unidade' => $m['unidade'], 'turno' => $m['turno'], 'nucleo_id' => $m['nucleo_id'], 'tipo' => $m['tipo'], 'situacao' => $m['situacao'],
            'paginas_base' => $m['paginas_base'], 'pagina_para' => $m['pagina_para'] !== null ? mb_substr((string) $m['pagina_para'], 0, 255) : null,
            'entra_rep' => $m['entra_rep'], 'score_sai' => $m['score_sai'], 'score_entra' => $m['score_entra'], 'receita' => $m['receita'],
            'impacto' => $m['impacto'], 'motivo' => mb_substr((string) $m['motivo'], 0, 255)];
    }
    escInserirLote('esc_base_movimento', ['plano_id', 'semana', 'plano_base_id', 'rep_code', 'unidade', 'turno', 'nucleo_id', 'tipo', 'situacao',
        'paginas_base', 'pagina_para', 'entra_rep', 'score_sai', 'score_entra', 'receita', 'impacto', 'motivo'], $linhas);
}
