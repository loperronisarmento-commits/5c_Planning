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
