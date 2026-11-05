<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/documentos.php
// Versão dos documentos gerados (pedido de 04/11): organograma, escala da liderança, mérito, regras,
// owners e restrições dos OM. A escala das páginas usa a versão do plano (escPlanoRotulo: v32.1).
// Cada documento tem um tipo e um escopo (ex.: restricoes / U1). A versão só sobe quando o conteúdo
// muda: o conteúdo (sem data e hora de geração) vira uma assinatura; a mesma assinatura já vista
// reusa o número dela, e uma nova ganha o próximo (esc_documento_versao, ES_115).
// Uso numa tela: escDocIni(); ...HTML do documento, com ESC_DOC_TOKEN onde vai a versão...;
// $doc = escDocFim('organograma', 'lideranca'); depois $doc['rotulo'] (ex.: "v3") no nome do arquivo.
// ===============================================

const ESC_DOC_TOKEN = '%%DOC_VERSAO%%';

// Assinatura do conteúdo: só o texto que sai impresso (sem o que é nao-imprimir, scripts e campos de
// formulário, que mudam por usuário ou por acesso), sem a marca da versão, sem data e hora de geração.
function escDocAssinatura(string $conteudo): string {
    $c = str_replace(ESC_DOC_TOKEN, '', $conteudo);
    if (class_exists('DOMDocument')) {
        $dom = new DOMDocument();
        $antes = libxml_use_internal_errors(true);
        if ($dom->loadHTML('<?xml encoding="utf-8"?><div>' . $c . '</div>')) {
            $xp = new DOMXPath($dom);
            foreach (iterator_to_array($xp->query("//script|//style|//form|//input|//select|//textarea|//*[contains(concat(' ', normalize-space(@class), ' '), ' nao-imprimir ')]")) as $n) {
                if ($n->parentNode) {
                    $n->parentNode->removeChild($n);
                }
            }
            $c = (string) $dom->textContent;
        }
        libxml_clear_errors();
        libxml_use_internal_errors($antes);
    }
    $c = (string) preg_replace('~\d{2}/\d{2}/\d{4},? \d{2}:\d{2}(:\d{2})?~', '', $c);
    $c = (string) preg_replace('~\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?~', '', $c);
    $c = (string) preg_replace('~\s+~u', ' ', $c);
    return sha1($c);
}

// A tabela guarda o documento de cada versão (ES_122)?
function escDocComConteudo(): bool {
    static $tem = null;
    if ($tem === null) {
        try {
            $tem = (bool) escQ1("SELECT 1 AS ok FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'esc_documento_versao' AND COLUMN_NAME = 'conteudo'", [ESC_SCHEMA]);
        } catch (Throwable $e) {
            $tem = false;
        }
    }
    return $tem;
}

// ['versao' => n, 'rotulo' => 'vN', 'desde' => 'Y-m-d H:i:s']; sem a tabela (ES_115), rótulo vazio.
// $linhaDoTempo (Restrições dos OM): a versão sobe sempre que o conteúdo muda em relação à última,
// mesmo que volte a ser igual a uma antiga, e cada versão guarda o documento inteiro (ES_122). Sem
// ela (organograma de outra data, filtros), o mesmo conteúdo reusa o número que já tinha.
function escDocVersao(string $tipo, string $escopo, string $conteudo, bool $linhaDoTempo = false): array {
    static $cache = [];
    $escopo = mb_substr($escopo, 0, 160);
    $ass = escDocAssinatura($conteudo);
    $k = $tipo . '|' . $escopo . '|' . $ass . '|' . (int) $linhaDoTempo;
    if (isset($cache[$k])) {
        return $cache[$k];
    }
    try {
        $tab = escT('esc_documento_versao');
        $comConteudo = escDocComConteudo();
        $busca = $linhaDoTempo
            ? static function () use ($tab, $tipo, $escopo, $ass): ?array {
                $ult = escQ1('SELECT versao, criado_em, assinatura FROM ' . $tab . ' WHERE tipo = ? AND escopo = ? ORDER BY versao DESC LIMIT 1', [$tipo, $escopo]);
                return $ult && $ult['assinatura'] === $ass ? $ult : null;
            }
            : static fn() => escQ1('SELECT versao, criado_em FROM ' . $tab . ' WHERE tipo = ? AND escopo = ? AND assinatura = ? ORDER BY versao LIMIT 1', [$tipo, $escopo, $ass]);
        $ja = $busca();
        // Duas pessoas abrindo o mesmo documento novo ao mesmo tempo: quem perder a corrida (chave
        // duplicada) lê de novo e usa o número que o outro gravou.
        for ($tent = 0; !$ja && $tent < 3; $tent++) {
            $max = (int) (escQ1('SELECT MAX(versao) AS m FROM ' . $tab . ' WHERE tipo = ? AND escopo = ?', [$tipo, $escopo])['m'] ?? 0);
            try {
                if ($comConteudo) {
                    escW('INSERT INTO ' . $tab . ' (tipo, escopo, versao, assinatura, conteudo, bytes, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [$tipo, $escopo, $max + 1, $ass, base64_encode(gzcompress($conteudo, 6)), strlen($conteudo), function_exists('escUserId') ? escUserId() : null]);
                } else {
                    escW('INSERT INTO ' . $tab . ' (tipo, escopo, versao, assinatura, criado_por) VALUES (?, ?, ?, ?, ?)',
                        [$tipo, $escopo, $max + 1, $ass, function_exists('escUserId') ? escUserId() : null]);
                }
                $ja = ['versao' => $max + 1, 'criado_em' => date('Y-m-d H:i:s')];
            } catch (Throwable $e) {
                if (strpos($e->getMessage(), '1062') === false && stripos($e->getMessage(), 'Duplicate') === false) {
                    throw $e;
                }
                $ja = $busca();
            }
        }
        if (!$ja) {
            throw new RuntimeException('versão do documento não gravada');
        }
        // Versão antiga, de antes do ES_122, sem o documento guardado: guarda agora.
        if ($comConteudo) {
            escW('UPDATE ' . $tab . ' SET conteudo = ?, bytes = ? WHERE tipo = ? AND escopo = ? AND versao = ? AND conteudo IS NULL',
                [base64_encode(gzcompress($conteudo, 6)), strlen($conteudo), $tipo, $escopo, (int) $ja['versao']]);
        }
        return $cache[$k] = ['versao' => (int) $ja['versao'], 'rotulo' => 'v' . (int) $ja['versao'], 'desde' => (string) $ja['criado_em']];
    } catch (Throwable $e) {
        appLog('warning', 'Versão do documento indisponível', ['tipo' => $tipo, 'erro' => $e->getMessage()]);
        return $cache[$k] = ['versao' => 0, 'rotulo' => '', 'desde' => ''];
    }
}

// Todas as versões de um documento, da mais nova para a mais antiga: versao, desde (início) e ate
// (início da seguinte; null = vale hoje) e se o documento está guardado.
function escDocVersoes(string $tipo, string $escopo): array {
    try {
        $l = escQ('SELECT versao, criado_em, ' . (escDocComConteudo() ? 'conteudo IS NOT NULL' : '0') . ' AS guardado FROM ' . escT('esc_documento_versao')
            . ' WHERE tipo = ? AND escopo = ? ORDER BY versao', [$tipo, mb_substr($escopo, 0, 160)]);
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($l as $i => $r) {
        $out[] = ['versao' => (int) $r['versao'], 'desde' => (string) $r['criado_em'], 'ate' => isset($l[$i + 1]) ? (string) $l[$i + 1]['criado_em'] : null, 'guardado' => (bool) $r['guardado']];
    }
    return array_reverse($out);
}

// O documento guardado de uma versão (HTML, com ESC_DOC_TOKEN já trocado pela versão), ou null.
// Guardado como base64 do gzip: só texto, para não passar binário pela conexão utf8mb4.
function escDocConteudo(string $tipo, string $escopo, int $versao): ?string {
    if (!escDocComConteudo()) {
        return null;
    }
    $r = escQ1('SELECT conteudo FROM ' . escT('esc_documento_versao') . ' WHERE tipo = ? AND escopo = ? AND versao = ?', [$tipo, mb_substr($escopo, 0, 160), $versao]);
    if (!$r || $r['conteudo'] === null) {
        return null;
    }
    $html = @gzuncompress((string) base64_decode((string) $r['conteudo'], true));
    return $html === false ? null : str_replace(ESC_DOC_TOKEN, h(t('versão {v}', ['v' => 'v' . $versao])), $html);
}

function escDocIni(): void {
    ob_start();
}

// Fecha o documento aberto com escDocIni, troca ESC_DOC_TOKEN pela versão e imprime. Devolve a versão.
function escDocFim(string $tipo, string $escopo = '', bool $linhaDoTempo = false): array {
    $html = (string) ob_get_clean();
    $v = escDocVersao($tipo, $escopo, $html, $linhaDoTempo);
    echo str_replace(ESC_DOC_TOKEN, $v['rotulo'] !== '' ? h(t('versão {v}', ['v' => $v['rotulo']])) : '', $html);
    return $v;
}

// Registra a versão de hoje das Restrições dos OM (U1, U2, U3 e todas) sem ninguém abrir a tela:
// monta a tela (pages/fixacoes.php) e descarta a saída; a versão nova, se o conteúdo mudou, fica
// guardada com a data de agora. Roda antes de cada Gerar e no fim da saída de página.
function escRestricoesRegistrarVersoes(): void {
    if (!escDocComConteudo() || (function_exists('escModoTreino') && escModoTreino())) {
        return;
    }
    $get = $_GET;
    try {
        foreach ([1, 2, 3, 0] as $u) {
            $_GET = $u ? ['p' => 'fixacoes', 'unidade' => (string) $u] : ['p' => 'fixacoes'];
            ob_start();
            try {
                (static function () {
                    include __DIR__ . '/../pages/fixacoes.php';
                })();
            } finally {
                ob_end_clean();
            }
        }
    } catch (Throwable $e) {
        appLog('warning', 'Restrições dos OM: versão não registrada antes de gerar', ['erro' => $e->getMessage()]);
    } finally {
        $_GET = $get;
    }
}

// Script que põe o nome do arquivo (com a versão) no título só durante a impressão (Salvar como PDF).
function escDocTituloJs(string $nome): string {
    return '<script>(function () { var t0 = document.title, n = ' . json_encode($nome, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE) . ';'
        . " window.addEventListener('beforeprint', function () { if (document.title === t0) { document.title = n; } });"
        . " window.addEventListener('afterprint', function () { document.title = t0; }); })();</script>";
}

// Sufixo para o nome do arquivo: " v3" (ou nada sem versão).
function escDocSufixo(array $v): string {
    return $v['rotulo'] !== '' ? ' ' . $v['rotulo'] : '';
}
