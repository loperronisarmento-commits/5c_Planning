<?php
// ===============================================
// ARQUIVO: deploy/saida_concluir.php
// Roda no servidor (CLI) o fim de uma saída de página que parou com erro DEPOIS do Capacity e dos
// postos (ex.: queda da conexão com o MySQL, erro 1160, ao gerar as escalas de novo). Faz só o que
// faltou: o passo 6 de escAplicarSaidasTravado (recálculo com o mínimo de mudanças das semanas
// pedidas) e marca as linhas de esc_saida_pagina como APLICADA, com o resultado completo.
// Não mexe no Capacity nem nos postos (já feitos). Semana travada pelos OM: não passar aqui.
//   phpdbg -qrr saida_concluir.php <pasta do módulo> <ids das saídas, com vírgula> <segundas, com vírgula>
// Primeiro criado em 10/11 (lote Livinia Roberts OFTV, Aria, Janet Free e Janet VIP).
// ===============================================

[$modulo, $idsTxt, $semanasTxt] = array_pad(array_slice($argv, 1), 3, '');
$ids = array_values(array_filter(array_map('intval', explode(',', $idsTxt))));
$semanas = array_values(array_filter(array_map('trim', explode(',', $semanasTxt)), static fn($s) => (bool) preg_match('~^\d{4}-\d{2}-\d{2}$~', $s)));
if (!is_dir($modulo) || !$ids || !$semanas) {
    fwrite(STDERR, "uso: php saida_concluir.php <pasta do módulo> <id,id,...> <AAAA-MM-DD,...>\n");
    exit(1);
}
chdir($modulo);
define('APP_RESPONSE_FORMAT', 'json');
foreach (['config/logger.php', 'config/config.php'] as $f) {
    require_once $modulo . '/' . $f;
}
require_once SHARED_ROOT . 'helpers/auth.php';
foreach (['db', 'i18n', 'helpers', 'acesso', 'dados', 'motor', 'motor_v4', 'merito', 'tags', 'saida', 'equipes', 'estrutura', 'minimo', 'lideranca', 'versao', 'versao_en',
          'documentos', 'treino', 'ajuda', 'solicitacao', 'roster', 'funil_sugestao', 'funil_entrada', 'politica', 'equilibrio', 'treinamento', 'casais', 'gerar_etapas',
          'restricoes_om_gestao', 'graficos', 'quadro', 'fora_unidades'] as $f) {
    require_once $modulo . '/lib/' . $f . '.php';
}
if (session_status() === PHP_SESSION_NONE) {
    $_SESSION = [];
}
@set_time_limit(0);

$linhas = escQW('SELECT id, pagina, vale_desde, status, criado_por, resultado FROM `' . ESC_SCHEMA . '`.`esc_saida_pagina` WHERE id IN (' . escIn($ids) . ') ORDER BY id', $ids);
if (count($linhas) !== count($ids)) {
    fwrite(STDERR, "saídas não encontradas\n");
    exit(1);
}
$primeira = $linhas[0];
$res = json_decode((string) $primeira['resultado'], true) ?: [];
foreach ($linhas as $l) {
    if ($l['status'] !== 'ERRO') {
        fwrite(STDERR, "a saída {$l['id']} ({$l['pagina']}) não está com erro: {$l['status']}\n");
        exit(1);
    }
}
if (empty($res['capacity']) || (empty($res['postos_fechados']) && empty($res['postos_refeitos']))) {
    fwrite(STDERR, "a primeira saída do lote não chegou ao Capacity e aos postos: reaplicar pela tela\n");
    exit(1);
}
$paginas = array_column($linhas, 'pagina');
$GLOBALS['ESC_USUARIO'] = ['user_id' => (int) $primeira['criado_por'], 'nome' => 'servidor (saida_concluir)', 'perfil' => 'ADMIN', 'times' => null, 'ativo' => 1];
if (!escGerarTravar()) {
    fwrite(STDERR, "outra pessoa está gerando ou recalculando uma escala agora; tente de novo em 3 min\n");
    exit(1);
}
// O Capacity já foi recalculado na saída: o motor não repete (nem traz de volta as páginas que saíram).
$GLOBALS['ESC_ESTRUTURA_AUTO'] = ['erro' => null, 'paginas' => [], 'ja_feito' => true];
$GLOBALS['ESC_OM_ORFAS'] = $res['restricoes_encerradas'] ?? [];
$res['planos'] = $res['planos'] ?? [];
foreach ($semanas as $seg) {
    $id = escMotorGerar($seg, 'RECALCULO', (int) $primeira['criado_por'] ?: null, null, true);
    $res['planos'][] = ['semana' => $seg, 'plano_id' => $id];
    $pl = escQ1('SELECT id, versao, subversao, status FROM `' . ESC_SCHEMA . '`.`esc_plano` WHERE id = ?', [$id]);
    echo "semana $seg: plano $id v{$pl['versao']}.{$pl['subversao']} {$pl['status']}\n";
}
$res['concluida'] = 'Concluída no servidor em ' . date('d/m H:i') . ' (deploy/saida_concluir.php) depois do erro 1160; semanas recalculadas: ' . implode(', ', $semanas) . '.';
$antes = $res['capacity'][0]['antes'] ?? null;
$depois = $res['capacity'][0]['depois'] ?? null;
foreach ($linhas as $l) {
    escW('UPDATE `' . ESC_SCHEMA . '`.`esc_saida_pagina` SET status = \'APLICADA\', erro = NULL, capacity_antes = ?, capacity_depois = ?, resultado = ? WHERE id = ?',
        [$antes, $depois, json_encode((int) $l['id'] === (int) $primeira['id'] ? $res : ['lote_de' => (int) $primeira['id'], 'paginas' => $paginas], JSON_UNESCAPED_UNICODE), (int) $l['id']]);
}
escLog('saida.concluir', 'esc_saida_pagina', (int) $primeira['id'], null, ['paginas' => $paginas, 'semanas' => $semanas, 'resultado' => $res]);
echo 'APLICADA: ' . implode(', ', $paginas) . "\n";
