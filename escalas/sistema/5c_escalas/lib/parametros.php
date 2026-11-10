<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/parametros.php
// Parâmetros do motor (esc_parametro, grupo v4 e outros), lidos uma vez por requisição.
// Separado de lib/motor_v4.php em 04/11 (1.19.18), só movendo as funções, sem mudar nada.
// Carregado por lib/motor_v4.php (require_once no topo).
// ===============================================

// Parâmetros que nenhum código lê mais (estrutura antiga de times e parties, mérito antes da nota em
// pontos, 3x1, fatias por nível). Saem da tela Parâmetros e ficam só para consulta em Legado ›
// Parâmetros legados, sem edição. Conferido por busca no código em 10/11/2026.
const ESC_PARAMETROS_LEGADO = [
    'primaris_por_party', 'secundus_por_party', 'sem_pagina_bloqueia', 'sem_primaris_bloqueia',
    'limite_pagina_compartilhada', 'presenca', 'workload_aviso_h',
    'merito_abs_ativo', 'merito_abs_critico', 'merito_abs_piso', 'merito_abs_teto_critico', 'merito_abs_tolerancia',
    'merito_alvo_atencao', 'merito_alvo_destaque', 'merito_alvo_entrega_falta', 'merito_alvo_novato', 'merito_alvo_presente_abaixo',
    'merito_infracao_janela_dias', 'merito_infracao_meia_vida_dias', 'merito_infracao_pesos', 'merito_min_respostas',
    'v4_tiers_melhores_srs', 'v4_max_vagas_nivel_baixo', 'v4_fatia_nivel_0', 'v4_fatia_nivel_1', 'v4_fatia_nivel_2', 'v4_nucleos_ativos_dia',
];

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
