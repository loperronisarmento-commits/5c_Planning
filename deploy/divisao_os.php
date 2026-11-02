<?php
// ===============================================
// ARQUIVO: deploy/divisao_os.php
// Roda no servidor (CLI) a mesma divisão dos OS do botão "Aplicar divisão" da tela Equilíbrio,
// para as 9 unidades × turnos de uma vez. Sem "aplicar" só mostra a prévia (nada é gravado).
//   php divisao_os.php <pasta do módulo> <AAAA-MM-DD> [aplicar]
// ===============================================

[$modulo, $desde, $modo] = array_pad(array_slice($argv, 1), 3, '');
if (!is_dir($modulo) || !preg_match('~^\d{4}-\d{2}-\d{2}$~', $desde)) {
    fwrite(STDERR, "uso: php divisao_os.php <pasta do módulo> <AAAA-MM-DD> [aplicar]\n");
    exit(1);
}
chdir($modulo);
define('APP_RESPONSE_FORMAT', 'json');
foreach (['config/logger.php', 'config/config.php'] as $f) {
    require_once $modulo . '/' . $f;
}
require_once SHARED_ROOT . 'helpers/auth.php';
foreach (['db', 'i18n', 'helpers', 'acesso', 'dados', 'motor', 'motor_v4', 'merito', 'saida', 'equipes', 'estrutura', 'minimo', 'lideranca', 'versao', 'versao_en', 'treino',
          'ajuda', 'solicitacao', 'roster', 'funil_sugestao', 'politica', 'equilibrio', 'treinamento', 'casais'] as $f) {
    require_once $modulo . '/lib/' . $f . '.php';
}

$eq = escEqDados(date('Y-m-d'), $desde);
foreach ($eq['postos'] as $u => $porT) {
    foreach (['A', 'C', 'D'] as $t) {
        if (empty($porT[$t])) {
            continue;
        }
        $div = escEqDivisao($porT[$t], $eq['pessoas'][$u][$t] ?? []);
        $p = count(array_filter($div['postos'], static fn($x) => $x['muda']));
        $s = count(array_filter($div['srs'], static fn($x) => $x['novo'] !== $x['num']));
        $parte = implode(' / ', array_map(static fn($k) => 'OS' . $k . ' ' . round(($div['parte_nova'][$k] ?? 0) * 100) . '%', [1, 2, 3]));
        echo "U$u $t: $p postos e $s SRs mudam · $parte";
        if ($modo === 'aplicar') {
            $r = escEqAplicarDivisao((int) $u, $t, $desde);
            echo " · aplicado: {$r['postos']} postos, {$r['srs']} SRs, {$r['equipe_fora']} vagas fechadas";
        }
        echo "\n";
    }
}
