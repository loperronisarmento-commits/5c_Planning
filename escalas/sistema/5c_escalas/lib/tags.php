<?php
// ===============================================
// ARQUIVO: 5c_escalas/lib/tags.php
// Tags de leitura da escala (Grade, detalhe da célula e PDF das páginas): mostram ao lado de cada
// SR o que ajuda e o que ameaça o resultado, sem precisar abrir o mérito. Tudo vem de leituras
// que já existem (cache do mérito, atingimento na página, dias na página e turmas de New Hires).
//   Na página (SR × página):
//   - BM Bate meta: atingimento da meta 5CReps na página nos últimos 30 dias ≥ tag_meta_bate (100%).
//   - NM Não bate meta: abaixo de tag_meta_nao (80%). Entre os dois, sem tag (perto da meta).
//     Só com tag_exp_min_dias (3) ou mais dias na página em 90 dias; com menos, vale o INX.
//   - EXP Experiente na página: tag_exp_dias (15) ou mais dias escalados nela em 90 dias.
//   - INX Inexperiente na página: menos de v4_conhece_dias (3) dias nela em 90 dias.
//   Do SR (mérito em cache, janela de 28 dias):
//   - TRN Trainee: New Hire disponível há menos de tag_trainee_dias (30) dias (rampa do novato)
//     ou início de contrato no headcount há menos disso.
//   - ABS Absenteísmo alto: tag_faltas_min (4) ou mais dias com falta (M-04: −8 ou pior).
//   - SLA SLA baixo: SLA abaixo de tag_sla_min (60%) (M-05: −4 ou pior).
//   - INF Infração: alguma infração nos últimos 90 dias (M-09).
//   - MA / MB Mérito acima / abaixo: faixa do mérito (M-01).
//   - SEQ Sequência na meta: tag_seq_semanas (3) ou mais semanas seguidas batendo a meta (M-10).
// Vermelho e laranja são risco; verde ajuda. Na grade e nas células do PDF só os riscos; no
// detalhe da célula e na tabela de representantes do PDF, todas.
// ===============================================

// Definição: código => [rótulo curto, nome completo, tom]. A ordem é a de exibição.
function escTagsDef(): array {
    static $def = null;
    return $def ??= [
        'NM' => [t('Meta ✗'), t('Não bate meta na página'), 'vermelho'],
        'ABS' => [t('Faltas'), t('Absenteísmo alto'), 'vermelho'],
        'INF' => [t('Infração'), t('Infração nos últimos 90 dias'), 'vermelho'],
        'MB' => [t('Mérito ↓'), t('Mérito abaixo'), 'vermelho'],
        'INX' => [t('Inexp.'), t('Inexperiente na página'), 'laranja'],
        'TRN' => [t('Trainee'), t('Trainee (New Hire nos primeiros 30 dias)'), 'laranja'],
        'SLA' => [t('SLA ↓'), t('SLA baixo'), 'laranja'],
        'BM' => [t('Meta ✓'), t('Bate meta na página'), 'verde'],
        'EXP' => [t('Exp.'), t('Experiente na página'), 'verde'],
        'MA' => [t('Mérito ↑'), t('Mérito acima'), 'verde'],
        'SEQ' => [t('Seq. meta'), t('Semanas seguidas batendo a meta'), 'verde'],
    ];
}

function escTagsParam(): array {
    static $p = null;
    $f = static fn(string $k, string $d): float => (float) str_replace(',', '.', escV4Param($k, $d));
    return $p ??= [
        'meta_bate' => $f('tag_meta_bate', '1.00'),
        'meta_nao' => $f('tag_meta_nao', '0.80'),
        'exp_dias' => (int) $f('tag_exp_dias', '15'),
        'conhece_dias' => (int) $f('v4_conhece_dias', '3'),
        'trainee_dias' => (int) $f('tag_trainee_dias', '30'),
        'faltas_min' => (int) $f('tag_faltas_min', '4'),
        'sla_min' => $f('tag_sla_min', '0.60'),
        'seq_semanas' => (int) $f('tag_seq_semanas', '3'),
    ];
}

// Leituras das tags, em cache de arquivo por 6 h (as fontes mudam no máximo uma vez por dia):
// ['sr' => [rep => [códigos]], 'meta' => [chave da página][rep] => atingimento, 'dias' => [chave][rep] => dias em 90].
function escTagsDados(): array {
    return escCache('tags_' . date('Ymd'), 6 * 3600, static function (): array {
        $p = escTagsParam();
        $sr = [];
        try {
            foreach (escQ('SELECT UPPER(rep_code) AS rep, faixa, faltas, sla, infracoes, semanas_meta FROM ' . escT('esc_sr_merito')) as $m) {
                $c = [];
                if ((int) $m['faltas'] >= $p['faltas_min']) {
                    $c[] = 'ABS';
                }
                if ($m['sla'] !== null && (float) $m['sla'] < $p['sla_min']) {
                    $c[] = 'SLA';
                }
                if ((int) $m['infracoes'] > 0) {
                    $c[] = 'INF';
                }
                if ($m['faixa'] === 'ACIMA' || $m['faixa'] === 'ABAIXO') {
                    $c[] = $m['faixa'] === 'ACIMA' ? 'MA' : 'MB';
                }
                if ((int) $m['semanas_meta'] >= $p['seq_semanas']) {
                    $c[] = 'SEQ';
                }
                $sr[$m['rep']] = $c;
            }
        } catch (Throwable $e) {
            appLog('warning', 'Tags: mérito indisponível', ['erro' => $e->getMessage()]);
        }
        // Trainee: turma de New Hires disponível há menos de trainee_dias (ou ainda por vir) e,
        // fora das turmas, início de contrato recente no headcount.
        $limite = date('Y-m-d', strtotime('-' . $p['trainee_dias'] . ' day'));
        if (function_exists('escTrnInstalado') && escTrnInstalado()) {
            foreach (escTrnTurmas(['RASCUNHO', 'ATIVA']) as $t) {
                if ((string) $t['disponivel_em'] > $limite) {
                    foreach (array_keys($t['trainees']) as $rep) {
                        $sr[$rep][] = 'TRN';
                    }
                }
            }
        }
        $reps = escMeritoSrsAtivos();
        if ($reps) {
            try {
                foreach (escQ('SELECT UPPER(code) AS rep, startContract FROM `5c_headcount`.`headcount` WHERE code IN (' . escIn($reps) . ')', $reps) as $h) {
                    if (preg_match('~^(\d{2})/(\d{2})/(\d{4})$~', trim((string) $h['startContract']), $d) && "{$d[3]}-{$d[2]}-{$d[1]}" > $limite && !in_array('TRN', $sr[$h['rep']] ?? [], true)) {
                        $sr[$h['rep']][] = 'TRN';
                    }
                }
            } catch (Throwable $e) {
                appLog('warning', 'Tags: headcount indisponível', ['erro' => $e->getMessage()]);
            }
        }
        // Atingimento na página nos últimos 30 dias (como o passo 0 do Gerar) e dias escalados nela em 90.
        $meta = [];
        $dias = [];
        $paginas = array_column(escMotorPaginas(), 'nome');
        if ($paginas && $reps) {
            try {
                $periodo = [[date('Y-m-d', strtotime('-30 day')), date('Y-m-d', strtotime('-1 day'))]];
                foreach (escMeritoAtingimentoPaginas($paginas, $reps, $periodo) as $pg => $porRep) {
                    foreach ($porRep as $rep => $porPeriodo) {
                        [$real, $alvo] = ($porPeriodo[0] ?? []) + [0.0, 0.0];
                        if ($alvo > 0) {
                            $meta[escV4ChavePagina((string) $pg)][strtoupper((string) $rep)] = round($real / $alvo, 4);
                        }
                    }
                }
            } catch (Throwable $e) {
                appLog('warning', 'Tags: atingimento indisponível', ['erro' => $e->getMessage()]);
            }
            try {
                foreach (escQ('SELECT UPPER(codigo) AS rep, modeloNome AS pagina, COUNT(DISTINCT LEFT(shiftDateAndHour, 10)) AS d FROM `5c_schedule`.`scheduleday`
                                WHERE shiftDateAndHour >= ? AND shiftDateAndHour < ? GROUP BY UPPER(codigo), modeloNome',
                    [date('Y-m-d', strtotime('-90 day')) . 'T00:00:00', date('Y-m-d') . 'T00:00:00']) as $l) {
                    $k = escV4ChavePagina((string) $l['pagina']);
                    $dias[$k][$l['rep']] = ($dias[$k][$l['rep']] ?? 0) + (int) $l['d'];
                }
            } catch (Throwable $e) {
                appLog('warning', 'Tags: dias na página indisponíveis', ['erro' => $e->getMessage()]);
            }
        }
        return ['sr' => $sr, 'meta' => $meta, 'dias' => $dias];
    });
}

// Códigos de um SR numa página, na ordem de escTagsDef (riscos primeiro).
function escTagsDe(string $rep, string $pagina): array {
    static $dados = null;
    $dados ??= escTagsDados();
    $p = escTagsParam();
    $rep = strtoupper($rep);
    $k = escV4ChavePagina($pagina);
    $c = $dados['sr'][$rep] ?? [];
    $d = (int) ($dados['dias'][$k][$rep] ?? 0);
    if ($d < $p['conhece_dias']) {
        $c[] = 'INX';
    } else {
        if ($d >= $p['exp_dias']) {
            $c[] = 'EXP';
        }
        $at = $dados['meta'][$k][$rep] ?? null;
        if ($at !== null) {
            $c[] = $at >= $p['meta_bate'] ? 'BM' : ($at < $p['meta_nao'] ? 'NM' : null);
        }
    }
    $ordem = array_flip(array_keys(escTagsDef()));
    $c = array_values(array_unique(array_filter($c)));
    usort($c, static fn($a, $b) => ($ordem[$a] ?? 99) <=> ($ordem[$b] ?? 99));
    return $c;
}

function escTagRisco(string $cod): bool {
    return (escTagsDef()[$cod][2] ?? '') !== 'verde';
}

// HTML das tags: $modo 'riscos' (só vermelho e laranja), 'todas' ou 'nao'; $longo usa o nome completo.
function escTagsHtml(array $codigos, string $modo = 'riscos', bool $longo = false): string {
    if ($modo === 'nao') {
        return '';
    }
    $def = escTagsDef();
    $out = '';
    foreach ($codigos as $c) {
        if (!isset($def[$c]) || ($modo === 'riscos' && !escTagRisco($c))) {
            continue;
        }
        [$curto, $nome, $tom] = $def[$c];
        $out .= '<span class="tag-sr tag-sr-' . $tom . '" title="' . h($nome) . '">' . h($longo ? $nome : $curto) . '</span>';
    }
    return $out === '' ? '' : ' <span class="tags-sr">' . $out . '</span>';
}

// Legenda curta: rótulo = nome, separados por tom.
function escTagsLegenda(): string {
    $p = escTagsParam();
    $partes = [];
    foreach (escTagsDef() as $c => [$curto, $nome, $tom]) {
        $partes[] = '<span class="tag-sr tag-sr-' . $tom . '">' . h($curto) . '</span> ' . h($nome);
    }
    return implode(' · ', $partes) . ' · ' . h(t('Meta na página nos últimos 30 dias (✓ {bate}% ou mais, ✗ abaixo de {nao}%); experiente com {exp} dias ou mais na página em 90 dias, inexperiente com menos de {conhece}; faltas com {faltas} dias ou mais em 28; SLA abaixo de {sla}%; sequência de {seq} semanas ou mais.', [
        'bate' => round($p['meta_bate'] * 100), 'nao' => round($p['meta_nao'] * 100), 'exp' => $p['exp_dias'], 'conhece' => $p['conhece_dias'],
        'faltas' => $p['faltas_min'], 'sla' => round($p['sla_min'] * 100), 'seq' => $p['seq_semanas']]));
}
