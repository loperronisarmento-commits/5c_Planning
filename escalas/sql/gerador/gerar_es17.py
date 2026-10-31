# Gera o ES_17_equipes_por_historico.sql: refaz as equipes fixas (esc_vaga_equipe)
# dos postos vigentes pelo histórico real do 5C Schedule (dias que cada SR
# trabalhou em cada página nas últimas 8 semanas), para mexer o mínimo nas
# pessoas na virada para a estrutura nova.
#
# Os postos (páginas, carga, capacidade) não mudam. Com --dono, o núcleo dono e
# o de cobertura de cada posto também são escolhidos de novo (o núcleo cujos SRs
# mais trabalham nas páginas do posto), dentro da mesma unidade e turno e com a
# mesma divisão de postos por núcleo do ES_10.
#
# Mesmas regras do gerar_es10.py (spec v4, seção 2, item 3): nível 3–5 fixo numa
# vaga cheia; níveis 0–2 na fatia do nível, até 2 vagas; cobertura em par.
# Linha de equipe que não muda (mesmo posto, vaga e pessoa) fica como está.
#
# Entrada: JSON (consulta só leitura, ver o fim do ES_17 gerado).
# Uso: python gerar_es17.py <entrada.json> <saida.sql> [--dono]
import collections, io, json, math, os, sys

ENTRADA, SAIDA = sys.argv[1], sys.argv[2]
TROCA_DONO = '--dono' in sys.argv[3:]
d = json.load(io.open(ENTRADA, encoding='utf-8'))

VIGENCIA = '2026-11-01'
FECHA = '2026-10-31'
FATIA = {0: 0.40, 1: 0.60, 2: 0.80}
MAX_VAGAS_BAIXO = 2
NS = [1, 2, 3]
TETO_DIAS = 28          # mais que 4 semanas numa página não conta mais
BONUS_ATUAL = 2         # empate: fica na vaga da proposta atual (dias equivalentes)

def q(v):
    if v is None:
        return 'NULL'
    if isinstance(v, (int, float)):
        return repr(v)
    return "'" + str(v).replace('\\', '\\\\').replace("'", "''") + "'"

pessoas = {p['c']: p for p in d['pessoas']}
modelo = d['modelo'] or {}

def fatia_de(r):
    if r.get('fr') is not None:
        return float(r['fr'])
    return 1.0 if r['lv'] >= 3 else FATIA[r['lv']]

dias = collections.defaultdict(dict)               # [rep][pagina] = dias (com teto)
for h in d['hist']:
    if h['c'] in pessoas:
        dias[h['c']][h['p']] = min(TETO_DIAS, int(h['d']))

pags = collections.defaultdict(list)
for g in d['paginas']:
    pags[g['id']].append(g)

atual = collections.defaultdict(dict)              # [rep][(posto, vaga)] = fatia
for e in d['equipes']:
    atual[e['c']][(e['id'], e['v'])] = float(e['f'])

por_ut = collections.defaultdict(list)
for p in d['postos']:
    por_ut[(p['u'], p['t'])].append(p)

novas, mudou_posto, resumo = [], [], []
for (u, t) in sorted(por_ut):
    P = []
    for p in sorted(por_ut[(u, t)], key=lambda p: (p['c'], p['s'])):
        itens = pags[p['id']]
        P.append({'id': p['id'], 'cart': p['c'], 'seq': p['s'], 'carga': float(p['carga'] or 0),
                  'pags': [g['p'] for g in itens if not g['a']] or [g['p'] for g in itens],
                  'dono0': p['dono'], 'cob0': p['cob'], 'dono': p['dono'], 'cob': p['cob']})
    membros = {n: [r for r in pessoas.values() if r['u'] == u and r['t'] == t and r['n'] == n] for n in NS}

    def sabe(code, ps):
        return sum(dias[code].get(g, 0) for p in ps for g in p['pags'])

    if TROCA_DONO:
        # Dono: o núcleo cujos SRs mais trabalharam nas páginas do posto, sem passar da parte
        # dele (proporcional ao que os SRs do núcleo rendem), como no ES_10.
        rende = {n: sum(fatia_de(r) for r in membros[n]) for n in NS}
        alvo = {n: math.ceil(len(P) * rende[n] / max(sum(rende.values()), 1)) + 1 for n in NS}
        cont = collections.Counter()
        for p in sorted(P, key=lambda p: -p['carga']):
            score = {n: sum(sabe(r['c'], [p]) for r in membros[n]) for n in NS}
            livres = [n for n in NS if cont[n] < alvo[n]] or NS
            p['dono'] = max(livres, key=lambda n: (score[n], n == p['dono0'], -cont[n] / max(rende[n], 0.1)))
            cont[p['dono']] += 1
        n_por = collections.Counter(p['dono'] for p in P)
        # Cobertura em par: o núcleo j cobre um posto de i e um de k.
        pend = {n: [p for p in P if p['dono'] == n] for n in NS}
        for p in P:
            p['cob'] = None
        b = {j: max(0, (sum(n_por[x] for x in NS if x != j) - n_por[j]) // 2) for j in NS}
        for j in sorted(NS, key=lambda x: -b[x]):
            i, k = [x for x in NS if x != j]
            for _ in range(b[j]):
                if not pend[i] or not pend[k]:
                    break
                cobre = lambda ac: sum(sabe(r['c'], ac) for r in membros[j])
                par = max(((a, c) for a in pend[i] for c in pend[k]),
                          key=lambda ac: (bool({modelo.get(g) for g in ac[0]['pags']} & {modelo.get(g) for g in ac[1]['pags']} - {None}),
                                          ac[0]['cart'] == ac[1]['cart'], cobre(ac), ac[0]['carga'] + ac[1]['carga']))
                for x in par:
                    x['cob'] = j
                    x['par'] = par
                    pend[x['dono']].remove(x)
        for n in NS:
            for p in list(pend[n]):
                outros = [x for x in NS if x != n]
                p['cob'] = max(outros, key=lambda x: (sum(sabe(r['c'], [p]) for r in membros[x]), -sum(1 for y in P if y['cob'] == x)))
                p['par'] = None
                pend[n].remove(p)
    else:
        # Mantém dono e cobertura; refaz os pares de cobertura como estão hoje (mesma pessoa nos 2 postos).
        par_de = {}
        cob_reps = collections.defaultdict(set)
        for e in d['equipes']:
            if e['v'] == 'COBERTURA':
                cob_reps[e['id']].add(e['c'])
        ids = {p['id']: p for p in P}
        for p in P:
            p['par'] = None
        for p in P:
            if p['par'] is None and p['cob']:
                outro = [x for x in P if x is not p and x['par'] is None and x['cob'] == p['cob'] and cob_reps[x['id']] & cob_reps[p['id']]]
                if outro:
                    p['par'] = x = (p, outro[0])
                    outro[0]['par'] = x

    pares = []
    vistos = set()
    for p in P:
        if p.get('par') and id(p['par']) not in vistos:
            vistos.add(id(p['par']))
            pares.append(p['par'])
    sem_par = [p for p in P if not p.get('par') and p['cob']]
    vagas = [{'ps': [p], 'vaga': 'DONO', 'nuc': p['dono'], 'eq': [], 'precisa': 1.0} for p in P]
    vagas += [{'ps': list(x), 'vaga': 'COBERTURA', 'nuc': x[0]['cob'], 'eq': [], 'precisa': 1.0} for x in pares]
    vagas += [{'ps': [p], 'vaga': 'COBERTURA', 'nuc': p['cob'], 'eq': [], 'precisa': 0.5} for p in sem_par]
    resto = {r['c']: fatia_de(r) for n in NS for r in membros[n]}
    nvagas = collections.Counter()

    def falta(v):
        return v['precisa'] - sum(f for _, f, _ in v['eq'])

    def por(v, code, f, motivo):
        v['eq'].append((code, round(f, 3), motivo))
        resto[code] -= f
        nvagas[code] += 1

    def conhece(code, v):
        return sabe(code, v['ps'])

    def na_proposta(code, v):
        return any((p['id'], v['vaga']) in atual[code] for p in v['ps'])

    def nota(code, v):
        return conhece(code, v) + (BONUS_ATUAL if na_proposta(code, v) else 0)

    def motivo(r, v, extra=''):
        k = conhece(r['c'], v)
        return ('nível ' + str(r['lv']) + extra + (f', {min(k, 99)} dias nas páginas em 8 semanas' if k else ', sem histórico nas páginas'))[:255]

    # 1. nível 3–5: vaga cheia do próprio núcleo; os pares (pessoa, vaga) de mais dias primeiro.
    cands = [(nota(r['c'], v), r['lv'], r['c'], i) for n in NS for r in membros[n] if r['lv'] >= 3 and resto[r['c']] >= 0.999
             for i, v in enumerate(vagas) if v['nuc'] == n and v['precisa'] >= 0.999]
    for sc, lv, code, i in sorted(cands, reverse=True):
        v = vagas[i]
        if resto[code] >= 0.999 and not v['eq']:
            por(v, code, 1.0, motivo(pessoas[code], v, ', fixo na vaga'))

    # 2. níveis 0–2 na fatia do nível, até 2 vagas (as vagas das páginas que a pessoa conhece primeiro)
    def encher(v, limite_extra):
        n = v['nuc']
        while falta(v) > 0.001:
            cs = [r for r in membros[n] if r['lv'] < 3 and resto[r['c']] + limite_extra.get(r['c'], 0) > 0.001
                  and nvagas[r['c']] < MAX_VAGAS_BAIXO and r['c'] not in [e[0] for e in v['eq']]]
            if not cs:
                return
            r = max(cs, key=lambda r: (nota(r['c'], v), r['lv'], resto[r['c']]))
            disp = resto[r['c']] + limite_extra.get(r['c'], 0)
            f = min(falta(v), disp)
            acima = f > resto[r['c']] + 0.001
            if acima:
                limite_extra[r['c']] -= f - max(resto[r['c']], 0)
            por(v, r['c'], f, motivo(r, v, ', acima da fatia do nível por falta de gente' if acima else ''))

    # Vagas em que alguém do núcleo conhece as páginas primeiro (senão a 1ª vaga leva quem conhece a 2ª).
    def ordem_vaga(v):
        melhor = max([conhece(r['c'], v) for r in membros[v['nuc']] if r['lv'] < 3 and resto[r['c']] > 0.001] or [0])
        return (-v['precisa'], -melhor, -sum(p['carga'] for p in v['ps']))
    pend_v = [v for v in vagas if falta(v) > 0.001]
    while pend_v:
        pend_v.sort(key=ordem_vaga)
        v = pend_v.pop(0)
        encher(v, {})
    # 3. nível 3–5 que sobrou completa vaga parcial
    for v in vagas:
        if falta(v) > 0.001:
            for r in sorted(membros[v['nuc']], key=lambda r: -conhece(r['c'], v)):
                if r['lv'] >= 3 and resto[r['c']] > 0.001 and nvagas[r['c']] == 0:
                    por(v, r['c'], min(falta(v), resto[r['c']]), motivo(r, v, ', completa a vaga'))
                    break
    # 4. ainda falta: níveis 0–2 passam da fatia (usam os dias de buffer) antes de virar lacuna
    extra = {r['c']: 1 - fatia_de(r) for n in NS for r in membros[n] if r['lv'] < 3}
    for v in sorted(vagas, key=lambda v: (-v['precisa'], -sum(p['carga'] for p in v['ps']))):
        if falta(v) > 0.001:
            encher(v, extra)

    for p in P:
        if p['dono'] != p['dono0'] or p['cob'] != p['cob0']:
            mudou_posto.append((p['id'], u, t, p['dono'], p['cob']))
    for v in vagas:
        for o, (code, f, mot) in enumerate(v['eq'], start=1):
            for p in v['ps']:
                novas.append((p['id'], v['vaga'], code, round(min(1.0, f / v['precisa']), 3), o, mot, conhece(code, {'ps': [p]}) > 0))
    resumo.append((u, t, len(P), len(mudou_posto), round(sum(max(0, falta(v)) for v in vagas), 2), sum(1 for v in vagas if not v['eq'])))

# ---------------------------------------------------------------- medidas
def medir(linhas):
    # linhas: (posto, vaga, rep, fatia)
    srs, srs_ok, peso, peso_ok = set(), set(), 0.0, 0.0
    for pid, vaga, code, f in linhas:
        k = sum(dias[code].get(g, 0) for g in [x['p'] for x in pags[pid]])
        srs.add(code)
        peso += f
        if k > 0:
            srs_ok.add(code)
            peso_ok += f
    return len(srs_ok), len(srs), round(100 * peso_ok / max(peso, 1e-9), 1)

def buracos(linhas):
    # Por posto e vaga (o par de cobertura conta nos 2 postos): quanto falta para 1 e quantas vazias.
    soma = collections.defaultdict(float)
    for pid, vaga, code, f in linhas:
        soma[(pid, vaga)] += f
    falta, vazias = 0.0, 0
    for p in d['postos']:
        for vaga in ('DONO', 'COBERTURA'):
            f = soma.get((p['id'], vaga), 0.0)
            if vaga == 'COBERTURA' and f == 0 and not p['cob']:
                continue
            falta += max(0.0, 1 - f)
            vazias += f < 0.001
    return round(falta, 1), vazias

antes = medir([(e['id'], e['v'], e['c'], float(e['f'])) for e in d['equipes']])
buracos_antes = buracos([(e['id'], e['v'], e['c'], float(e['f'])) for e in d['equipes']])
buracos_depois = buracos([(a, b, c, f) for a, b, c, f, *_ in novas])
depois = medir([(a, b, c, f) for a, b, c, f, *_ in novas])
iguais = len({(e['id'], e['v'], e['c']) for e in d['equipes']} & {(a, b, c) for a, b, c, *_ in novas})
fora = [c for c in pessoas if c not in {x[2] for x in novas}]

# ---------------------------------------------------------------- SQL
L = []
w = L.append
w('-- =====================================================================')
w(f'-- {os.path.basename(SAIDA)}  (gerado por escalas/sql/gerador/gerar_es17.py' + (' --dono' if TROCA_DONO else '') + ')')
w('-- Refaz as equipes fixas (esc_vaga_equipe) dos postos vigentes pelo histórico real')
w('-- do 5C Schedule: cada SR vai, dentro do próprio núcleo, para a vaga das páginas')
w('-- em que mais trabalhou nas últimas 8 semanas (mexer o mínimo nas pessoas).')
if TROCA_DONO:
    w('-- O núcleo dono e o de cobertura de cada posto também são escolhidos de novo:')
    w('-- o núcleo cujos SRs mais trabalham nas páginas do posto (mesma unidade e turno,')
    w('-- mesma divisão de postos por núcleo).')
w('-- Os postos (páginas, carga, capacidade) não mudam. Linha de equipe igual fica como está;')
w(f'-- as que saem ficam só com o dia {FECHA}, antes da virada (o histórico continua na tabela).')
w(f'-- SRs numa equipe com página que já conhecem: antes {antes[0]} de {antes[1]}; depois {depois[0]} de {depois[1]}.')
w(f'-- Fatia das equipes em páginas conhecidas: antes {antes[2]}%; depois {depois[2]}%.')
w('-- Depois: gerar de novo as semanas de 26/10 e 02/11, nessa ordem.')
w('-- Pode rodar de novo (não duplica).')
w('-- =====================================================================')
w('')
w('SET NAMES utf8mb4;')
w('USE `5c_escalas`;')
w('')
w('START TRANSACTION;')
w('')
if mudou_posto:
    w(f'-- 1. Núcleo dono e de cobertura ({len(mudou_posto)} postos mudam)')
    w('DROP TEMPORARY TABLE IF EXISTS tmp_es17_posto;')
    w('CREATE TEMPORARY TABLE tmp_es17_posto (posto_id INT UNSIGNED PRIMARY KEY, dono TINYINT UNSIGNED, cobertura TINYINT UNSIGNED) ENGINE=InnoDB;')
    w('INSERT INTO tmp_es17_posto VALUES\n  ' + ',\n  '.join(f"({pid}, {dn}, {cb if cb else 'NULL'})" for pid, u, t, dn, cb in mudou_posto) + ';')
    w('UPDATE esc_posto p')
    w('  JOIN tmp_es17_posto t ON t.posto_id = p.id')
    w(f"  JOIN esc_nucleo nd ON nd.unidade = p.unidade AND nd.turno = p.turno AND nd.numero = t.dono AND nd.vigente_desde = {q(VIGENCIA)}")
    w(f"  LEFT JOIN esc_nucleo nc ON nc.unidade = p.unidade AND nc.turno = p.turno AND nc.numero = t.cobertura AND nc.vigente_desde = {q(VIGENCIA)}")
    w('   SET p.nucleo_dono_id = nd.id, p.nucleo_cobertura_id = nc.id')
    w(' WHERE p.vigente_ate IS NULL;')
    w('')
w('-- 2. Equipes novas')
w('DROP TEMPORARY TABLE IF EXISTS tmp_es17_vaga;')
w('CREATE TEMPORARY TABLE tmp_es17_vaga (posto_id INT UNSIGNED, vaga VARCHAR(10), rep_code VARCHAR(30), fatia DECIMAL(4,3), ordem TINYINT UNSIGNED, '
  'motivo VARCHAR(255), PRIMARY KEY (posto_id, vaga, rep_code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;')
w('INSERT INTO tmp_es17_vaga VALUES\n  ' + ',\n  '.join(f"({pid}, {q(vg)}, {q(c)}, {f}, {o}, {q(m)})" for pid, vg, c, f, o, m, _ in novas) + ';')
w('')
w('-- 2a. Linha que continua: só atualiza fatia, ordem e motivo')
w('UPDATE esc_vaga_equipe e')
w('  JOIN esc_posto p ON p.id = e.posto_id AND p.vigente_ate IS NULL')
w('  JOIN tmp_es17_vaga t ON t.posto_id = e.posto_id AND t.vaga = e.vaga AND t.rep_code = e.rep_code')
w('   SET e.fatia = t.fatia, e.ordem = t.ordem, e.motivo = t.motivo')
w(' WHERE e.vigente_ate IS NULL;')
w('-- 2b. Linha que sai: fica só com o dia ' + FECHA)
w('UPDATE esc_vaga_equipe e')
w('  JOIN esc_posto p ON p.id = e.posto_id AND p.vigente_ate IS NULL')
w('  LEFT JOIN tmp_es17_vaga t ON t.posto_id = e.posto_id AND t.vaga = e.vaga AND t.rep_code = e.rep_code')
w(f"   SET e.vigente_desde = LEAST(e.vigente_desde, {q(FECHA)}), e.vigente_ate = {q(FECHA)}")
w(' WHERE e.vigente_ate IS NULL AND t.rep_code IS NULL;')
w('-- 2c. Linha nova')
w('INSERT INTO esc_vaga_equipe (posto_id, vaga, rep_code, fatia, ordem, status, motivo, vigente_desde, origem)')
w(f"SELECT t.posto_id, t.vaga, t.rep_code, t.fatia, t.ordem, 'PROPOSTA', t.motivo, {q(VIGENCIA)}, 'MONTADOR'")
w('  FROM tmp_es17_vaga t')
w('  JOIN esc_posto p ON p.id = t.posto_id AND p.vigente_ate IS NULL')
w(' WHERE NOT EXISTS (SELECT 1 FROM esc_vaga_equipe x WHERE x.posto_id = t.posto_id AND x.vaga = t.vaga AND x.rep_code = t.rep_code AND x.vigente_ate IS NULL);')
w('')
w('COMMIT;')
w('')
w('-- =====================================================================')
w('-- Conferência')
w('-- =====================================================================')
w(f"SELECT 'linhas de equipe vigentes' AS item, COUNT(*) AS n, {q(str(len(novas)))} AS esperado FROM esc_vaga_equipe e JOIN esc_posto p ON p.id = e.posto_id WHERE p.vigente_ate IS NULL AND e.vigente_ate IS NULL")
w(f"UNION ALL SELECT 'linhas que não mudaram', COUNT(*), {q(str(iguais))} FROM esc_vaga_equipe e JOIN tmp_es17_vaga t ON t.posto_id = e.posto_id AND t.vaga = e.vaga AND t.rep_code = e.rep_code WHERE e.vigente_ate IS NULL AND e.criado_em < NOW() - INTERVAL 1 MINUTE")
w(f"UNION ALL SELECT 'SRs em equipe', COUNT(DISTINCT e.rep_code), {q(str(depois[1]))} FROM esc_vaga_equipe e JOIN esc_posto p ON p.id = e.posto_id WHERE p.vigente_ate IS NULL AND e.vigente_ate IS NULL")
w("UNION ALL SELECT 'postos sem núcleo dono', COUNT(*), '0' FROM esc_posto WHERE vigente_ate IS NULL AND nucleo_dono_id IS NULL;")
w('')
w('-- SRs numa equipe com página em que trabalharam nas últimas 8 semanas (5C Schedule)')
w("SELECT COUNT(DISTINCT e.rep_code) AS srs_em_equipe,")
w("       COUNT(DISTINCT IF(s.codigo IS NULL, NULL, e.rep_code)) AS srs_que_conhecem_a_pagina, " + q(str(depois[0])) + " AS esperado")
w("  FROM esc_vaga_equipe e")
w("  JOIN esc_posto p ON p.id = e.posto_id AND p.vigente_ate IS NULL")
w("  JOIN esc_posto_pagina pp ON pp.posto_id = e.posto_id")
w("  LEFT JOIN `5c_schedule`.scheduleday s ON s.codigo COLLATE utf8mb4_unicode_ci = e.rep_code AND s.modeloNome COLLATE utf8mb4_unicode_ci = pp.pagina")
w("        AND s.shiftDateAndHour >= CONCAT(DATE_SUB(CURDATE(), INTERVAL 57 DAY), 'T00:00:00')")
w(" WHERE e.vigente_ate IS NULL;")
w('')
w('-- Entrada do gerador (só leitura): ver escalas/sql/gerador/gerar_es17.py')
io.open(SAIDA, 'w', encoding='utf-8', newline='\n').write('\n'.join(L) + '\n')

# ---------------------------------------------------------------- resumo
print('modo:', 'troca dono e cobertura' if TROCA_DONO else 'mantém dono e cobertura')
print('U T | postos | postos com núcleo trocado (acum.) | falta (pessoas) | vagas vazias')
for x in resumo:
    print(' | '.join(str(v) for v in x))
print(f'SRs em equipe com página conhecida: antes {antes[0]}/{antes[1]}  depois {depois[0]}/{depois[1]}')
print(f'fatia em páginas conhecidas: antes {antes[2]}%  depois {depois[2]}%')
print(f'falta nas vagas (posto a posto): antes {buracos_antes[0]} ({buracos_antes[1]} vazias)  depois {buracos_depois[0]} ({buracos_depois[1]} vazias)')
print(f'linhas: {len(novas)} (antes {len(d["equipes"])}); iguais: {iguais}; SRs fora de equipe: {len(fora)}')
