<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/i18n.php
// Idioma da interface: português (pt-BR, o texto-fonte) e inglês (en).
//
// Estilo gettext: o texto em português é a chave. Na tela, t('Texto em
// português') devolve o texto do idioma escolhido; a tradução para inglês fica
// nos catálogos lang/en/*.php (um por tela, cada um devolve ['pt' => 'en']).
// Texto com variável usa marcadores: t('Semana de {dia}', ['dia' => dataBr($d)]).
// O que vem do banco (nomes de página e de pessoa, motivos digitados) não é
// traduzido; os valores fixos do banco (status, tipos) passam por escRotulo().
//
// Escolha do idioma: cookie esc_idioma (seletor no menu); sem cookie, o idioma
// do navegador (Accept-Language): português → pt-BR, qualquer outro → en.
// ===============================================

const ESC_IDIOMAS = ['pt-BR' => 'Português', 'en' => 'English'];

function escIdioma(): string {
    static $idioma = null;
    if ($idioma === null) {
        $c = (string) ($_COOKIE['esc_idioma'] ?? '');
        if (isset(ESC_IDIOMAS[$c])) {
            $idioma = $c;
        } else {
            $nav = strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
            $idioma = ($nav === '' || strpos($nav, 'pt') === 0) ? 'pt-BR' : 'en';
        }
    }
    return $idioma;
}

function escEmIngles(): bool {
    return escIdioma() === 'en';
}

// Catálogo inglês: junta lang/en/*.php (cada arquivo devolve um array pt => en).
function escCatalogo(): array {
    static $cat = null;
    if ($cat === null) {
        $cat = [];
        foreach (glob(__DIR__ . '/../lang/en/*.php') ?: [] as $arq) {
            $parte = include $arq;
            if (is_array($parte)) {
                $cat += $parte;
            }
        }
    }
    return $cat;
}

// Traduz o texto (português é a chave) e troca os marcadores {nome}.
function t(string $pt, array $vars = []): string {
    $txt = $pt;
    if (escEmIngles()) {
        $cat = escCatalogo();
        if (isset($cat[$pt]) && $cat[$pt] !== '') {
            $txt = $cat[$pt];
        } else {
            $GLOBALS['ESC_SEM_TRADUCAO'][$pt] = true;
        }
    }
    if ($vars) {
        $troca = [];
        foreach ($vars as $k => $v) {
            $troca['{' . $k . '}'] = (string) $v;
        }
        $txt = strtr($txt, $troca);
    }
    return $txt;
}

// Plural simples: tn(n, 'singular', 'plural', vars) — {n} vira o número.
function tn(int $n, string $um, string $varios, array $vars = []): string {
    return t($n === 1 ? $um : $varios, ['n' => $n] + $vars);
}

// Rótulos dos valores fixos do banco (enums). Sem rótulo conhecido, mostra o valor com a
// primeira letra maiúscula. O texto do rótulo passa por t().
const ESC_ROTULOS = [
    'status_plano' => ['RASCUNHO' => 'Rascunho', 'PUBLICADO' => 'Publicada', 'SUBSTITUIDO' => 'Substituída', 'DESCARTADO' => 'Descartada'],
    'status_pedido' => ['PENDENTE' => 'Pendente', 'APROVADO' => 'Aprovado', 'RECUSADO' => 'Recusado', 'PARCIAL' => 'Parcial', 'CANCELADO' => 'Cancelado'],
    'canal' => ['NORA' => 'Nora', 'SITE' => 'Site', 'WFM' => 'WFM'],
    'tipo_pedido' => ['UM_DIA' => 'Um dia', 'VARIOS_DIAS' => 'Vários dias', 'RECORRENTE' => 'Recorrente'],
    'motivo' => ['SAUDE' => 'Saúde', 'ESTUDO' => 'Estudo', 'PESSOAL' => 'Pessoal', 'OUTRO' => 'Outro'],
    'papel' => ['REGULAR' => 'Regular', 'ASSISTENTE' => 'Assistente'],
    'decisao_saida' => ['PENDENTE' => 'A conferir', 'CONFIRMADA' => 'Saída confirmada', 'NAO_SAIU' => 'Não saiu'],
    'status_evento' => ['DETECTADO' => 'Detectado', 'AGUARDANDO_CHECAGEM' => 'Aguardando checagem', 'RECALCULADO' => 'Recalculado', 'SEM_EFEITO' => 'Sem efeito', 'ERRO' => 'Erro'],
    'gatilho' => ['CAPACITY' => 'Capacity', 'ESTRUTURA' => 'Estrutura', 'MEMBROS' => 'Membros', 'PAGINA_ENTRADA' => 'Entrada de página', 'PAGINA_SAIDA' => 'Saída de página', 'TROCA_PAGINA' => 'Troca de página', 'INDISPONIBILIDADE' => 'Indisponibilidade', 'FALTA' => 'Falta', 'COLABORADOR' => 'Colaborador', 'MODELO' => 'Modelo de escala', 'PARAMETRO' => 'Parâmetro', 'MANUAL' => 'Manual'],
    'tipo_diferenca' => ['ENTRA' => 'Entra', 'SAI' => 'Sai', 'TROCA' => 'Troca', 'PAPEL' => 'Papel', 'LACUNA_ABERTA' => 'Lacuna aberta', 'LACUNA_FECHADA' => 'Lacuna fechada'],
    'status_sugestao' => ['ABERTA' => 'Aberta', 'APLICADA' => 'Aplicada', 'DESCARTADA' => 'Descartada', 'EXPIRADA' => 'Expirada'],
    'tipo_sugestao' => ['WORKLOAD' => 'Carga', 'COBERTURA' => 'Cobertura', 'MERITO' => 'Mérito', 'FOLGA' => 'Folga', 'ESTRUTURA' => 'Estrutura'],
    'situacao_violacao' => ['ABERTA' => 'Aberta', 'EM_CORRECAO' => 'Em correção', 'JUSTIFICADA' => 'Justificada', 'RESOLVIDA' => 'Resolvida'],
    'tipo_pendencia' => ['ADICIONAR' => 'Adicionar', 'MOVER' => 'Mover', 'REMOVER' => 'Remover', 'PASSAR_TERTIUS' => 'Passar a rodízio', 'PROMOVER' => 'Promover'],
    'tipo_he' => ['POSTO' => 'Posto', 'BUFFER' => 'Buffer'],
    'tipo_modelo' => ['CONTINUO' => 'Contínuo', 'SEMANA' => 'Semana fixa'],
    'base_previsao' => ['ARQUETIPO' => 'Arquétipo', 'REFERENCIA' => 'Página de referência', 'INFORMADO' => 'Informado'],
    'origem_pedido' => ['AUTO' => 'Detecção automática'],
    'status_he' => ['INTERESSADO' => 'Interessado', 'ALOCADO' => 'Alocado', 'RECUSADO' => 'Recusado', 'CANCELADO' => 'Cancelado'],
    'status_pendencia' => ['ABERTA' => 'Aberta', 'ATRASADA' => 'Atrasada', 'DIVERGENTE' => 'Divergente', 'APLICADA' => 'Aplicada', 'CANCELADA' => 'Cancelada'],
    'perfil' => ['ADMIN' => 'Admin', 'PLANEJAMENTO' => 'Planejamento', 'WFM' => 'WFM', 'GESTOR_TIME' => 'Gestor de time', 'LEITURA' => 'Leitura'],
    'tipo_folga' => ['CICLO' => 'Ciclo', 'FOLGA_FIXA' => 'Folga fixa', 'INDISPONIBILIDADE' => 'Indisponibilidade', 'ADMIN' => 'Dia admin', 'ROTACAO' => 'Rotação', 'EMENDA' => 'Folga de emenda'],
];

function escRotulo(string $grupo, ?string $valor): string {
    if ($valor === null || $valor === '') {
        return '—';
    }
    $pt = ESC_ROTULOS[$grupo][$valor] ?? ucfirst(strtolower(str_replace('_', ' ', $valor)));
    return t($pt);
}

// ---------- datas e números no formato do idioma ----------

function escDiaSemanaCurto(int $w): string {
    return escEmIngles() ? ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][$w] : ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'][$w];
}

// Data curta: pt-BR 02/11 (dd/mm) · en 11/02 (mm/dd). Com ano: 02/11/2026 · 11/02/2026.
function escData(?string $iso, bool $comDiaSemana = false, bool $comAno = false): string {
    if (!$iso) {
        return '—';
    }
    $t = strtotime(substr($iso, 0, 10));
    if ($t === false) {
        return (string) $iso;
    }
    $fmt = escEmIngles() ? ($comAno ? 'm/d/Y' : 'm/d') : ($comAno ? 'd/m/Y' : 'd/m');
    return ($comDiaSemana ? escDiaSemanaCurto((int) date('w', $t)) . ' ' : '') . date($fmt, $t);
}

// Data e hora curtas (aceita 'Y-m-d H:i:s' ou timestamp): 29/10 23:00 · 10/29 23:00.
function escDataHora($quando, bool $comAno = false): string {
    $t = is_int($quando) ? $quando : strtotime((string) $quando);
    if (!$t) {
        return '—';
    }
    $fmt = escEmIngles() ? ($comAno ? 'm/d/Y H:i' : 'm/d H:i') : ($comAno ? 'd/m/Y H:i' : 'd/m H:i');
    return date($fmt, $t);
}

function escSeparadores(): array {
    return escEmIngles() ? ['.', ','] : [',', '.'];
}
