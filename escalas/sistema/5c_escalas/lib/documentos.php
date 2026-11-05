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

// ['versao' => n, 'rotulo' => 'vN', 'desde' => 'Y-m-d H:i:s']; sem a tabela (ES_115), rótulo vazio.
function escDocVersao(string $tipo, string $escopo, string $conteudo): array {
    static $cache = [];
    $escopo = mb_substr($escopo, 0, 160);
    $ass = escDocAssinatura($conteudo);
    $k = $tipo . '|' . $escopo . '|' . $ass;
    if (isset($cache[$k])) {
        return $cache[$k];
    }
    try {
        $busca = static fn() => escQ1('SELECT versao, criado_em FROM ' . escT('esc_documento_versao') . ' WHERE tipo = ? AND escopo = ? AND assinatura = ? ORDER BY versao LIMIT 1', [$tipo, $escopo, $ass]);
        $ja = $busca();
        // Duas pessoas abrindo o mesmo documento novo ao mesmo tempo: quem perder a corrida (chave
        // duplicada) lê de novo e usa o número que o outro gravou.
        for ($tent = 0; !$ja && $tent < 3; $tent++) {
            $max = (int) (escQ1('SELECT MAX(versao) AS m FROM ' . escT('esc_documento_versao') . ' WHERE tipo = ? AND escopo = ?', [$tipo, $escopo])['m'] ?? 0);
            try {
                escW('INSERT INTO ' . escT('esc_documento_versao') . ' (tipo, escopo, versao, assinatura, criado_por) VALUES (?, ?, ?, ?, ?)',
                    [$tipo, $escopo, $max + 1, $ass, function_exists('escUserId') ? escUserId() : null]);
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
        return $cache[$k] = ['versao' => (int) $ja['versao'], 'rotulo' => 'v' . (int) $ja['versao'], 'desde' => (string) $ja['criado_em']];
    } catch (Throwable $e) {
        appLog('warning', 'Versão do documento indisponível', ['tipo' => $tipo, 'erro' => $e->getMessage()]);
        return $cache[$k] = ['versao' => 0, 'rotulo' => '', 'desde' => ''];
    }
}

function escDocIni(): void {
    ob_start();
}

// Fecha o documento aberto com escDocIni, troca ESC_DOC_TOKEN pela versão e imprime. Devolve a versão.
function escDocFim(string $tipo, string $escopo = ''): array {
    $html = (string) ob_get_clean();
    $v = escDocVersao($tipo, $escopo, $html);
    echo str_replace(ESC_DOC_TOKEN, $v['rotulo'] !== '' ? h(t('versão {v}', ['v' => $v['rotulo']])) : '', $html);
    return $v;
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
