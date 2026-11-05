<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/parametros.php
// Parâmetros do motor (esc_parametro, grupo v4 e outros), lidos uma vez por requisição.
// Separado de lib/motor_v4.php em 04/11 (1.19.18), só movendo as funções, sem mudar nada.
// Carregado por lib/motor_v4.php (require_once no topo).
// ===============================================

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
