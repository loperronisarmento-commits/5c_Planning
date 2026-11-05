<?php
// Lista os t('...') / tn('...') literais do módulo que não estão no catálogo inglês.
$mod = 'D:/5C Planning/5c_Planning/escalas/sistema/5c_escalas';
$cat = [];
foreach (glob($mod . '/lang/en/*.php') as $a) {
    $p = include $a;
    if (is_array($p)) {
        $cat += $p;
    }
}
$falta = [];
$arqs = array_merge(glob($mod . '/pages/*.php'), glob($mod . '/lib/*.php'), [$mod . '/index.php']);
foreach ($arqs as $a) {
    if (preg_match('~/lib/versao(_en)?\.php$~', $a)) {
        continue;
    }
    $src = file_get_contents($a);
    $tokens = token_get_all($src);
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $tk = $tokens[$i];
        if (is_array($tk) && $tk[0] === T_STRING && in_array($tk[1], ['t', 'tn'], true)) {
            $j = $i + 1;
            while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { $j++; }
            if (($tokens[$j] ?? null) !== '(') { continue; }
            $j++;
            while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { $j++; }
            if (is_array($tokens[$j] ?? null) && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
                $s = eval('return ' . $tokens[$j][1] . ';');
                if (!isset($cat[$s])) {
                    $falta[basename($a)][$s] = $tokens[$j][2];
                }
                if ($tk[1] === 'tn') {
                    // segundo literal (plural)
                    $k = $j + 1;
                    while ($k < $n && $tokens[$k] !== ',') { $k++; }
                    $k++;
                    while ($k < $n && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) { $k++; }
                    if (is_array($tokens[$k] ?? null) && $tokens[$k][0] === T_CONSTANT_ENCAPSED_STRING) {
                        $s2 = eval('return ' . $tokens[$k][1] . ';');
                        if (!isset($cat[$s2])) { $falta[basename($a)][$s2] = $tokens[$k][2]; }
                    }
                }
            }
        }
    }
}
$tot = 0;
foreach ($falta as $a => $l) {
    $tot += count($l);
    echo "== $a (" . count($l) . ")\n";
    foreach ($l as $s => $linha) {
        echo "  $linha: $s\n";
    }
}
echo "TOTAL $tot\n";
