<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/restricoes_om.php
// Restrições dos OM (pedidos de 04/11): travas e preferências por pessoa que os líderes de unidade ditam.
// É uma camada à parte do motor: as regras do motor (P-01 a P-04, contexto/regras_prioridade_paginas.md)
// não mudam. O motor (lib/motor_v4.php) só consulta o que sai daqui na equipe fixa e no preenchimento.
// Fontes (esc_parametro, grupo v4; a tela Restrições dos OM mostra todas):
//   v4_sr_pref_assistente  códigos: "serve como assistente": vai antes no posto de assistente, em qualquer nível.
//   v4_sr_so_paginas       "CÓDIGO:página|página;...": só essas páginas, nenhuma outra (nem acúmulo nem cobertura).
//   v4_sr_tier_medio       códigos: só páginas de tier médio (v4_tier_medio, padrão C,D, do cadastro 5c_pages).
//   v4_sr_faixa_receita    "CÓDIGO:mín-máx;...": só assistente ou posto cuja página principal fatura na faixa em 30 dias.
//   v4_sr_pref_masculinas  códigos: preferência nos postos com página masculina (v4_paginas_masculinas, "a|b").
// A página da divisão do SR (esc_sr_divisao) vale sempre para ele, mesmo fora da lista, do tier ou da faixa.
// As outras restrições dos OM já existentes seguem onde estavam: equipe fixa com main e cover
// (esc_pagina_prioridade), só assistente e proibido (esc_pagina_assistente), divisão (esc_sr_divisao),
// v4_sr_uma_pagina, v4_sr_multipagina, v4_sr_so_assistente, v4_sr_baixo_fluxo e "não faz assistente"
// (esc_colaborador_config.pode_assistente).
// ===============================================

// Regra de 04/11: quando uma página sai ou um representante é desligado, as restrições dele são
// desativadas. Desligado = sem cadastro em aberto (esc_colaborador_config: o último fecha em "fim").
// Página fora = sem linha em aberto em fc_pagina_carteira (vigente_ate exclusivo: o último dia é o
// anterior). Fecha no último dia: equipe fixa (tira a pessoa da linha; a linha só fecha se esvaziar),
// só assistente/proibido, divisão; e tira dos parâmetros de restrição quem já passou do último dia.
// Roda no fim da saída de página e antes de cada Gerar. $gravar = false só lista o que faria.
function escOmEncerrarOrfas(string $hoje, bool $gravar = true): array {
    $feito = [];
    $chave = static fn(string $p): string => mb_strtolower(trim((string) preg_replace('~\s+~u', ' ', $p)), 'UTF-8');
    $fimRep = [];
    foreach (escQ('SELECT UPPER(rep_code) AS rep, MAX(vigente_ate) AS fim, SUM(vigente_ate IS NULL) AS abertas FROM ' . escT('esc_colaborador_config') . ' GROUP BY UPPER(rep_code)') as $r) {
        if ((int) $r['abertas'] === 0 && $r['fim'] !== null) {
            $fimRep[$r['rep']] = substr((string) $r['fim'], 0, 10);
        }
    }
    // Liderança (OS nos núcleos, OM/OC/OA em esc_lideranca) não tem cadastro de SR em aberto e não é desligada.
    foreach (escQ('SELECT UPPER(decurio_code) AS rep FROM ' . escT('esc_nucleo') . " WHERE decurio_code IS NOT NULL AND decurio_code <> '' AND (vigente_ate IS NULL OR vigente_ate >= ?)", [$hoje]) as $r) {
        unset($fimRep[$r['rep']]);
    }
    if (escQ1('SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = \'esc_lideranca\'', [ESC_SCHEMA])) {
        foreach (escQ('SELECT UPPER(rep_code) AS rep FROM ' . escT('esc_lideranca') . " WHERE rep_code IS NOT NULL AND rep_code <> '' AND (vigente_ate IS NULL OR vigente_ate >= ?)", [$hoje]) as $r) {
            unset($fimRep[$r['rep']]);
        }
    }
    $fimPag = [];
    foreach (escQ('SELECT pagina, MAX(vigente_ate) AS fim, SUM(vigente_ate IS NULL) AS abertas FROM `5c_planning`.`fc_pagina_carteira` GROUP BY pagina') as $r) {
        if ((int) $r['abertas'] === 0 && $r['fim'] !== null) {
            $fimPag[$chave((string) $r['pagina'])] = escMaisDias(substr((string) $r['fim'], 0, 10), -1);
        }
    }
    if (!$fimRep && !$fimPag) {
        return [];
    }
    $nomes = function_exists('escNomes') ? escNomes() : [];
    $quem = static fn(string $rep): string => (function_exists('nomeCurto') ? nomeCurto($nomes[$rep] ?? $rep) : $rep) . ' (' . $rep . ')';
    // Fecha a linha no último dia; se a linha começa depois dele, ela nunca vale (fim = início − 1).
    $fechaEm = static fn(string $de, string $fim): string => $fim < $de ? escMaisDias($de, -1) : $fim;
    $grava = static function (string $sql, array $p) use ($gravar): void {
        if ($gravar) {
            escW($sql, $p);
        }
    };

    // 1. Equipe fixa
    $comPapel = (bool) escQ1("SELECT 1 AS ok FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'esc_pagina_prioridade' AND COLUMN_NAME = 'papel'", [ESC_SCHEMA]);
    foreach (escQ('SELECT id, pagina, rep_codes, ' . ($comPapel ? 'papel, ' : '') . 'motivo, vigente_desde, vigente_ate FROM ' . escT('esc_pagina_prioridade') . ' WHERE vigente_ate IS NULL OR vigente_ate >= ?', [$hoje]) as $l) {
        $de = substr((string) $l['vigente_desde'], 0, 10);
        $ate = $l['vigente_ate'] !== null ? substr((string) $l['vigente_ate'], 0, 10) : null;
        $fp = $fimPag[$chave((string) $l['pagina'])] ?? null;
        if ($fp !== null && ($ate === null || $ate > $fp)) {
            $grava('UPDATE ' . escT('esc_pagina_prioridade') . ' SET vigente_ate = ?, motivo = LEFT(CONCAT(\'Página saiu (regra de 04/11); \', IFNULL(motivo, \'\')), 255) WHERE id = ?', [$fechaEm($de, $fp), (int) $l['id']]);
            $feito[] = t('equipe da {pagina} encerrada (a página saiu)', ['pagina' => $l['pagina']]);
            continue;
        }
        $reps = array_values(array_filter(array_map(static fn($x) => strtoupper(trim($x)), explode(',', (string) $l['rep_codes']))));
        $sai = array_values(array_filter($reps, static fn($r) => isset($fimRep[$r]) && ($ate === null || $ate > $fimRep[$r])));
        if (!$sai) {
            continue;
        }
        $fim = min(array_map(static fn($r) => $fimRep[$r], $sai));
        $fica = array_values(array_diff($reps, $sai));
        $grava('UPDATE ' . escT('esc_pagina_prioridade') . ' SET vigente_ate = ?, motivo = LEFT(CONCAT(\'Sem quem foi desligado (regra de 04/11); \', IFNULL(motivo, \'\')), 255) WHERE id = ?', [$fechaEm($de, $fim), (int) $l['id']]);
        if ($fica) {
            $grava('INSERT INTO ' . escT('esc_pagina_prioridade') . ' (pagina, rep_codes, ' . ($comPapel ? 'papel, ' : '') . 'motivo, vigente_desde, vigente_ate) VALUES (?, ?, ' . ($comPapel ? '?, ' : '') . '?, ?, ?)',
                array_merge([$l['pagina'], implode(',', $fica)], $comPapel ? [$l['papel']] : [], [mb_substr((string) $l['motivo'], 0, 255), max($de, escMaisDias($fim, 1)), $ate]));
        }
        foreach ($sai as $r) {
            $feito[] = t('{quem} sai da equipe da {pagina} (desligado)', ['quem' => $quem($r), 'pagina' => $l['pagina']]);
        }
    }

    // 2. Só assistente / proibido e 3. divisão
    foreach ([['esc_pagina_assistente', 'só assistente/proibido'], ['esc_sr_divisao', 'divisão']] as [$tab, $rot]) {
        if (!escQ1('SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [ESC_SCHEMA, $tab])) {
            continue;
        }
        foreach (escQ('SELECT id, pagina, UPPER(rep_code) AS rep, vigente_desde, vigente_ate FROM ' . escT($tab) . ' WHERE vigente_ate IS NULL OR vigente_ate >= ?', [$hoje]) as $l) {
            $de = substr((string) $l['vigente_desde'], 0, 10);
            $ate = $l['vigente_ate'] !== null ? substr((string) $l['vigente_ate'], 0, 10) : null;
            $fins = array_filter([$fimRep[$l['rep']] ?? null, $fimPag[$chave((string) $l['pagina'])] ?? null]);
            if (!$fins || ($ate !== null && $ate <= min($fins))) {
                continue;
            }
            $grava('UPDATE ' . escT($tab) . ' SET vigente_ate = ?, motivo = LEFT(CONCAT(\'Desligado ou página saiu (regra de 04/11); \', IFNULL(motivo, \'\')), 255) WHERE id = ?', [$fechaEm($de, min($fins)), (int) $l['id']]);
            $feito[] = t('{rot} de {quem} na {pagina} encerrada', ['rot' => $rot, 'quem' => $quem($l['rep']), 'pagina' => $l['pagina']]);
        }
    }

    // 4. Parâmetros (não têm data): sai quem já passou do último dia e a página que já saiu.
    $passou = array_filter($fimRep, static fn($f) => $f < $hoje);
    $pagPassou = array_filter($fimPag, static fn($f) => $f < $hoje);
    $params = [];
    foreach (escQ('SELECT chave, valor FROM ' . escT('esc_parametro') . " WHERE chave IN ('v4_sr_uma_pagina','v4_sr_so_assistente','v4_sr_baixo_fluxo','v4_sr_baixo_fluxo_estrito','v4_sr_pref_assistente','v4_sr_tier_medio','v4_sr_pref_masculinas','v4_todos_os_dias','v4_sr_so_paginas','v4_sr_multipagina','v4_sr_faixa_receita','v4_paginas_masculinas','v4_nao_subordinado')") as $r) {
        $params[$r['chave']] = (string) $r['valor'];
    }
    foreach ($params as $ch => $valor) {
        $novo = $valor;
        if (in_array($ch, ['v4_sr_so_paginas', 'v4_sr_multipagina', 'v4_sr_faixa_receita'], true)) {
            $itens = [];
            foreach (array_filter(array_map('trim', explode(';', $valor))) as $item) {
                [$rep, $resto] = array_pad(explode(':', $item, 2), 2, '');
                if (isset($passou[strtoupper(trim($rep))])) {
                    continue;
                }
                if ($ch !== 'v4_sr_faixa_receita') {
                    $pags = array_values(array_filter(array_map('trim', explode('|', $resto)), static fn($p) => $p !== '' && !isset($pagPassou[$chave($p)])));
                    if (!$pags) {
                        continue;
                    }
                    $resto = implode('|', $pags);
                }
                $itens[] = trim($rep) . ':' . $resto;
            }
            $novo = implode(';', $itens);
        } elseif ($ch === 'v4_paginas_masculinas') {
            $novo = implode('|', array_values(array_filter(array_map('trim', explode('|', $valor)), static fn($p) => $p !== '' && !isset($pagPassou[$chave($p)]))));
        } elseif ($ch === 'v4_nao_subordinado') {
            $novo = implode(',', array_values(array_filter(array_map('trim', explode(',', $valor)), static function ($par) use ($passou) {
                $x = array_map(static fn($v) => strtoupper(trim($v)), explode(':', $par, 2));
                return count($x) === 2 && !isset($passou[$x[0]]) && !isset($passou[$x[1]]);
            })));
        } else {
            $novo = implode(',', array_values(array_filter(array_map(static fn($c) => strtoupper(trim($c)), explode(',', $valor)), static fn($c) => $c !== '' && !isset($passou[$c]))));
        }
        if ($novo !== $valor && $novo !== trim($valor)) {
            $grava('UPDATE ' . escT('esc_parametro') . ' SET valor = ? WHERE chave = ?', [$novo, $ch]);
            $feito[] = t('{chave}: tirado quem foi desligado ou a página que saiu', ['chave' => $ch]);
        }
    }
    if ($feito && $gravar && function_exists('escLog')) {
        escLog('restricao.encerrar', 'esc_parametro', null, null, ['regra' => 'desligado ou página fora: restrições desativadas (04/11)', 'feito' => $feito], 'SISTEMA');
    }
    return $feito;
}

// $est: o que escV4Carregar devolve (usa 'receita' e 'divisao'). Devolve as listas e duas funções:
// pode_pags(rep, páginas, posto de assistente?) e tem_masc(posto).
function escOmRestricoes(array $est): array {
    $listaCod = static fn(string $ch): array => array_flip(array_filter(array_map(static fn($c) => strtoupper(trim($c)), explode(',', escV4Param($ch, '')))));
    $porRep = static function (string $ch, callable $valor): array {
        $out = [];
        foreach (explode(';', escV4Param($ch, '')) as $item) {
            $x = explode(':', $item, 2);
            if (count($x) === 2 && trim($x[0]) !== '' && ($v = $valor($x[1])) !== null) {
                $out[strtoupper(trim($x[0]))] = $v;
            }
        }
        return $out;
    };
    $prefAssist = $listaCod('v4_sr_pref_assistente');
    $tierMedio = $listaCod('v4_sr_tier_medio');
    $prefMasc = $listaCod('v4_sr_pref_masculinas');
    $tiersMedio = array_flip(array_filter(array_map(static fn($c) => strtoupper(trim($c)), explode(',', escV4Param('v4_tier_medio', 'C,D')))));
    $pagsMasc = array_flip(array_filter(array_map(static fn($c) => escV4ChavePagina($c), explode('|', escV4Param('v4_paginas_masculinas', '')))));
    $soPags = $porRep('v4_sr_so_paginas', static fn(string $v) => array_flip(array_filter(array_map(static fn($c) => escV4ChavePagina($c), explode('|', $v)), 'strlen')));
    $faixaRec = $porRep('v4_sr_faixa_receita', static fn(string $v) => preg_match('~^\s*(\d+)\s*-\s*(\d+)\s*$~', $v, $m) ? [(float) $m[1], (float) $m[2]] : null);
    $receitaPag = $est['receita'] ?? [];
    $divPags = [];
    foreach ($est['divisao'] ?? [] as $rd => $partes) {
        foreach ($partes as $x) {
            $divPags[$rd][escV4ChavePagina((string) $x['pagina'])] = true;
        }
    }
    $podePags = static function (string $rep, array $paginas, bool $assist = false) use ($soPags, $tierMedio, $tiersMedio, $faixaRec, $receitaPag, $divPags): bool {
        if (isset($faixaRec[$rep]) && !$assist) {
            $princ = 0.0;
            foreach ($paginas as $pg) {
                if (empty($pg['apoio'])) {
                    $princ = max($princ, (float) ($receitaPag[$pg['pagina']]['total'] ?? 0));
                }
            }
            $naDiv = (bool) array_filter($paginas, static fn($pg) => empty($pg['apoio']) && isset($divPags[$rep][escV4ChavePagina((string) $pg['pagina'])]));
            if (!$naDiv && ($princ < $faixaRec[$rep][0] || $princ > $faixaRec[$rep][1])) {
                return false;
            }
        }
        foreach ($paginas as $pg) {
            if (!empty($pg['apoio']) || isset($divPags[$rep][escV4ChavePagina((string) $pg['pagina'])])) {
                continue;
            }
            if (isset($soPags[$rep]) && !isset($soPags[$rep][escV4ChavePagina((string) $pg['pagina'])])) {
                return false;
            }
            if (isset($tierMedio[$rep]) && ($t = escTierDe((string) $pg['pagina'])) !== '' && !isset($tiersMedio[$t])) {
                return false;
            }
        }
        return true;
    };
    $temMasc = static function (array $p) use ($pagsMasc): bool {
        foreach ($p['paginas'] as $pg) {
            if (!$pg['apoio'] && isset($pagsMasc[escV4ChavePagina((string) $pg['pagina'])])) {
                return true;
            }
        }
        return false;
    };
    return ['pref_assist' => $prefAssist, 'tier_medio' => $tierMedio, 'pref_masc' => $prefMasc, 'faixa' => $faixaRec,
        'so_paginas' => $soPags, 'pode_pags' => $podePags, 'tem_masc' => $temMasc];
}
