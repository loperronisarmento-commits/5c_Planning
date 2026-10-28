# Gera o ES_10_postos_capacity_<data>.sql: troca os postos da escala (carga de
# 27/10) pelos postos do Capacity (5c_planning.fc_postos / fc_postos_pagina de
# um data_calculo) e remonta a proposta de equipe de cada vaga.
#
# Entrada: JSON com o que o banco tem hoje (uma consulta só leitura, ver o fim
# do ES_10 gerado): postos e páginas do Capacity, modelo de cada página,
# núcleos, SRs (código, unidade, turno, núcleo, nível) e as equipes atuais com
# as páginas de cada posto.
#
# Mesmas regras do gerar_es05.py (spec v4, seção 2, item 3), com a menor troca
# de gente: o dono de cada posto novo é o núcleo que mais atuava nas páginas
# dele, e cada SR vai para a vaga com as páginas que já fazia no turno.
#
# Uso: python gerar_es10.py <entrada.json> <data_calculo AAAA-MM-DD> <hora do cálculo HHMM> <saida.sql>
# (a hora entra na versão: o Capacity pode recalcular o mesmo dia)
import collections, io, json, math, os, sys

ENTRADA, DC, HORA, SAIDA = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4]
d = json.load(io.open(ENTRADA, encoding='utf-8'))

VIGENCIA = '2026-11-01'
FECHA = '2026-10-31'            # postos e equipes antigos ficam sem vigência a partir de 01/11
VERSAO = 'CAP' + DC.replace('-', '') + '_' + HORA  # varchar(20)
FATIA = {0: 0.40, 1: 0.60, 2: 0.80}  # níveis 3–5 = 1
MAX_VAGAS_BAIXO = 2
NS = [1, 2, 3]

def q(v):
    if v is None:
        return 'NULL'
    if isinstance(v, (int, float)):
        return repr(v)
    return "'" + str(v).replace('\\', '\\\\').replace("'", "''") + "'"

def fatia_de(lv):
    return 1.0 if lv >= 3 else FATIA[lv]

modelo = d['modelo']
pessoas = {p['c']: p for p in d['pessoas']}

# Quem já atua em cada página, por turno: peso = fatia (DONO vale o dobro da cobertura).
sabe = collections.defaultdict(lambda: collections.defaultdict(float))   # [rep][(pagina, turno)]
dono_pag = collections.defaultdict(lambda: collections.Counter())         # [(pagina, turno)][núcleo]
for e in d['equipes']:
    if e['c'] not in pessoas:
        continue
    peso = float(e['f']) * (2 if e['v'] == 'DONO' else 1)
    k = (e['p'], e['t'])
    sabe[e['c']][k] = max(sabe[e['c']][k], peso)
    if e['v'] == 'DONO':
        dono_pag[k][pessoas[e['c']]['n']] += float(e['f']) * max(float(e['fans'] or 0), 1)

pags_posto = collections.defaultdict(list)
for g in d['paginas']:
    pags_posto[(g['c'], g['t'], g['s'])].append(g)

postos_saida, equipes, resumo = [], [], []
pessoas_pag = collections.defaultdict(set)
por_ut = collections.defaultdict(list)
for p in d['postos']:
    por_ut[(p['u'], p['t'])].append(p)

for (u, t) in sorted(por_ut):
    P = []
    for p in sorted(por_ut[(u, t)], key=lambda p: (p['c'], p['s'])):
        itens = sorted(pags_posto[(p['c'], t, p['s'])], key=lambda g: (g['a'], -float(g['f'])))
        P.append({'u': u, 't': t, 'cart': p['c'], 'seq': p['s'], 'tipo': p['tipo'], 'itens': itens,
                  'pags': [g['p'] for g in itens], 'carga': float(p['carga']), 'cap': float(p['cap']),
                  'principal': p['pp'], 'dono': None, 'cob': None})
    membros = {n: [r for r in pessoas.values() if r['u'] == u and r['t'] == t and r['n'] == n] for n in NS}

    # Dono: o núcleo que mais atuava como dono nas páginas do posto, sem passar da parte
    # dele (proporcional ao que os SRs do núcleo rendem: nível 3–5 = 1, 0–2 = fatia).
    rende = {n: sum(fatia_de(r['lv']) for r in membros[n]) for n in NS}
    alvo = {n: math.ceil(len(P) * rende[n] / max(sum(rende.values()), 1)) + 1 for n in NS}
    cont = collections.Counter()
    for p in sorted(P, key=lambda p: -p['carga']):
        score = collections.Counter()
        for g in p['pags']:
            score.update(dono_pag[(g, t)])
        livres = [n for n in NS if cont[n] < alvo[n]] or NS
        p['dono'] = max(livres, key=lambda n: (score[n], -cont[n] / max(rende[n], 0.1)))
        cont[p['dono']] += 1
    n_por = collections.Counter(p['dono'] for p in P)

    # Cobertura: o núcleo j cobre um posto de i e um de k (par); b_j = (n_i + n_k − n_j) / 2
    pend = {n: [p for p in P if p['dono'] == n] for n in NS}
    pares, sem_par = [], []
    b = {j: max(0, (sum(n_por[x] for x in NS if x != j) - n_por[j]) // 2) for j in NS}
    for j in sorted(NS, key=lambda x: -b[x]):
        i, k = [x for x in NS if x != j]
        for _ in range(b[j]):
            if not pend[i] or not pend[k]:
                break
            # 1º mesma modelo, 2º mesma carteira, 3º maior carga
            par = max(((a, c) for a in pend[i] for c in pend[k]),
                      key=lambda ac: (bool({modelo.get(g) for g in ac[0]['pags']} & {modelo.get(g) for g in ac[1]['pags']} - {None}),
                                      ac[0]['cart'] == ac[1]['cart'], ac[0]['carga'] + ac[1]['carga']))
            for x in par:
                x['cob'] = j
                pend[x['dono']].remove(x)
            pares.append(par)
    for n in NS:
        for p in list(pend[n]):
            outros = [x for x in NS if x != n]
            p['cob'] = min(outros, key=lambda x: sum(1 for y in P if y['cob'] == x))
            pend[n].remove(p)
            sem_par.append(p)

    vagas = [{'p': p, 'ps': [p], 'vaga': 'DONO', 'nuc': p['dono'], 'eq': [], 'precisa': 1.0} for p in P]
    vagas += [{'p': x[0], 'ps': list(x), 'vaga': 'COBERTURA', 'nuc': x[0]['cob'], 'eq': [], 'precisa': 1.0} for x in pares]
    vagas += [{'p': p, 'ps': [p], 'vaga': 'COBERTURA', 'nuc': p['cob'], 'eq': [], 'precisa': 0.5} for p in sem_par]
    resto = {r['c']: fatia_de(r['lv']) for n in NS for r in membros[n]}
    nvagas = collections.Counter()

    def falta(v):
        return v['precisa'] - sum(f for _, f, _ in v['eq'])

    def por(v, code, f, motivo):
        v['eq'].append((code, round(f, 3), motivo))
        resto[code] -= f
        nvagas[code] += 1

    def conhece(r, v, so_dono=False):
        return sum(sabe[r['c']].get((g, t), 0) for p in v['ps'] for g in p['pags']
                   if not so_dono or sabe[r['c']].get((g, t), 0) >= 1.99)

    def afinidade(r, v):
        return (conhece(r, v) > 0, conhece(r, v), v['precisa'], v['p']['carga'])

    # 1. vaga do dono: o SR de nível 3–5 do núcleo que era dono dessas páginas (maior carga primeiro)
    for v in sorted([v for v in vagas if v['vaga'] == 'DONO'], key=lambda v: -v['p']['carga']):
        cands = [r for r in membros[v['nuc']] if r['lv'] >= 3 and resto[r['c']] >= 0.999 and conhece(r, v, True) > 0]
        if cands:
            r = max(cands, key=lambda r: (conhece(r, v, True), r['lv']))
            por(v, r['c'], 1.0, 'nível ' + str(r['lv']) + ', já era dono destas páginas')
    # 2. nível 3–5 sem vaga: vaga cheia vazia do próprio núcleo, onde já atua
    for n in NS:
        for r in sorted(membros[n], key=lambda r: -r['lv']):
            if r['lv'] < 3 or resto[r['c']] < 0.999:
                continue
            livres = [v for v in vagas if v['nuc'] == n and not v['eq'] and v['precisa'] >= 0.999]
            if livres:
                v = max(livres, key=lambda v: afinidade(r, v))
                por(v, r['c'], 1.0, 'nível ' + str(r['lv']) + ', fixo na vaga' + (' (já atuava nas páginas)' if conhece(r, v) else ''))

    # 3. níveis 0–2 na fatia do nível, até 2 vagas por pessoa (maiores páginas primeiro)
    def encher(v, limite_extra):
        n = v['nuc']
        while falta(v) > 0.001:
            cands = [r for r in membros[n] if r['lv'] < 3 and resto[r['c']] + limite_extra.get(r['c'], 0) > 0.001
                     and nvagas[r['c']] < MAX_VAGAS_BAIXO and r['c'] not in [e[0] for e in v['eq']]]
            if not cands:
                return
            r = max(cands, key=lambda r: (afinidade(r, v), r['lv'], resto[r['c']]))
            disp = resto[r['c']] + limite_extra.get(r['c'], 0)
            f = min(falta(v), disp)
            acima = f > resto[r['c']] + 0.001
            if acima:
                limite_extra[r['c']] -= f - max(resto[r['c']], 0)
            por(v, r['c'], f, 'nível ' + str(r['lv']) + (', acima da fatia do nível por falta de gente' if acima else '')
                + (' (já atuava nas páginas)' if conhece(r, v) else ''))
    for v in sorted(vagas, key=lambda v: (-v['precisa'], -v['p']['carga'])):
        encher(v, {})
    # 4. nível 3–5 que sobrou completa vaga parcial
    for v in vagas:
        if falta(v) > 0.001:
            for r in membros[v['nuc']]:
                if r['lv'] >= 3 and resto[r['c']] > 0.001 and nvagas[r['c']] == 0:
                    por(v, r['c'], min(falta(v), resto[r['c']]), 'nível ' + str(r['lv']) + ', completa a vaga')
                    break
    # 5. ainda falta: níveis 0–2 passam da fatia (usam os dias de buffer) antes de virar lacuna
    extra = {r['c']: 1 - fatia_de(r['lv']) for n in NS for r in membros[n] if r['lv'] < 3}
    for v in sorted(vagas, key=lambda v: (-v['precisa'], -v['p']['carga'])):
        if falta(v) > 0.001:
            encher(v, extra)

    postos_saida += P
    mantidos = 0
    for v in vagas:
        for o, (code, f, mot) in enumerate(v['eq'], start=1):
            if conhece(pessoas[code], v) > 0:
                mantidos += 1
            for p in v['ps']:
                equipes.append(((p['cart'], t, p['seq']), v['vaga'], code, round(min(1.0, f / v['precisa']), 3), o, mot))
                for g in p['pags']:
                    pessoas_pag[(g, t)].add(code)
    linhas_eq = sum(len(v['eq']) for v in vagas)
    resumo.append((u, t, len(P), dict(sorted(n_por.items())), len(pares), len(sem_par),
                   round(sum(max(0, falta(v)) for v in vagas), 2), sum(1 for v in vagas if not v['eq']),
                   round(sum(max(0, x) for x in resto.values()), 2), f'{mantidos}/{linhas_eq}'))

# Agrupamento por modelo nos postos novos (conferência)
grupos = collections.defaultdict(set)
for p in postos_saida:
    for g in p['itens']:
        if not g['a'] and modelo.get(g['p']):
            grupos[(p['cart'], p['t'], modelo[g['p']])].add(p['seq'])
mod_pags = collections.Counter()
for p in postos_saida:
    for g in p['itens']:
        if not g['a'] and modelo.get(g['p']):
            mod_pags[(p['cart'], p['t'], modelo[g['p']])] += 1
multi = [k for k, n in mod_pags.items() if n > 1]
separadas = [k for k in multi if len(grupos[k]) > 1]

# ---------------------------------------------------------------- SQL
L = []
w = L.append
w('-- =====================================================================')
w(f'-- {os.path.basename(SAIDA)}  (gerado por escalas/sql/gerador/gerar_es10.py)')
w(f'-- Troca os postos da escala pelos do Capacity (fc_postos de {DC}, calculado às {HORA[:2]}:{HORA[2:]} UTC), com as')
w('-- páginas da mesma modelo juntas quando o Capacity agrupa assim, e remonta a')
w('-- PROPOSTA de equipe de cada vaga (o mínimo de troca: cada SR fica nas')
w('-- páginas que já fazia no turno).')
w(f'-- Os postos e as equipes de antes ficam só com o dia {FECHA}, antes da virada.')
w('-- Depois: gerar de novo as semanas de 26/10 e 02/11, nessa ordem.')
w('-- Pode rodar de novo (não duplica).')
w('-- =====================================================================')
w('')
w('SET NAMES utf8mb4;')
w('USE `5c_escalas`;')
w('')
w('START TRANSACTION;')
w('')
w('-- 1. Fecha os postos e as equipes de antes. Eles começavam em 01/11 e a vigência não pode')
w('--    terminar antes de começar (ck_vaga_vig): ficam só com o dia 31/10, antes da virada,')
w('--    e o motor nunca os lê. O histórico continua na tabela.')
w(f"UPDATE esc_vaga_equipe e JOIN esc_posto p ON p.id = e.posto_id SET e.vigente_desde = LEAST(e.vigente_desde, {q(FECHA)}), e.vigente_ate = {q(FECHA)}")
w(f" WHERE e.vigente_ate IS NULL AND p.versao <> {q(VERSAO)};")
w(f"UPDATE esc_posto SET vigente_desde = LEAST(vigente_desde, {q(FECHA)}), vigente_ate = {q(FECHA)} WHERE vigente_ate IS NULL AND versao <> {q(VERSAO)};")
w('')
w(f'-- 2. Postos do Capacity ({DC}) e páginas')
w('DROP TEMPORARY TABLE IF EXISTS tmp_es10_posto;')
w('CREATE TEMPORARY TABLE tmp_es10_posto (carteira VARCHAR(8), turno CHAR(1), seq SMALLINT UNSIGNED, unidade TINYINT UNSIGNED, tipo VARCHAR(12), '
  'pagina_principal VARCHAR(150), n_paginas TINYINT UNSIGNED, assinatura VARCHAR(400), carga DECIMAL(6,1), capacidade DECIMAL(5,1), '
  'dono TINYINT UNSIGNED, cobertura TINYINT UNSIGNED, PRIMARY KEY (carteira, turno, seq)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;')
linhas = []
for p in postos_saida:
    assin = (' + '.join(sorted(p['pags'])) + ' [' + p['tipo'] + ']')[:400]
    linhas.append(f"({q(p['cart'])}, {q(p['t'])}, {p['seq']}, {p['u']}, {q(p['tipo'])}, {q(p['principal'] or p['pags'][0])}, {len(p['pags'])}, {q(assin)}, "
                  f"{round(p['carga'], 1)}, {round(p['cap'], 1)}, {p['dono']}, {p['cob'] if p['cob'] else 'NULL'})")
w('INSERT INTO tmp_es10_posto VALUES\n  ' + ',\n  '.join(linhas) + ';')
w("INSERT IGNORE INTO esc_posto (versao, unidade, carteira, turno, seq, tipo, pagina_principal, n_paginas, assinatura, carga, capacidade,")
w("                              nucleo_dono_id, nucleo_cobertura_id, vigente_desde)")
w(f"SELECT {q(VERSAO)}, t.unidade, t.carteira, t.turno, t.seq, t.tipo, t.pagina_principal, t.n_paginas, t.assinatura, t.carga, t.capacidade,")
w("       nd.id, nc.id, " + q(VIGENCIA))
w("  FROM tmp_es10_posto t")
w("  JOIN esc_nucleo nd ON nd.unidade = t.unidade AND nd.turno = t.turno AND nd.numero = t.dono AND nd.vigente_desde = " + q(VIGENCIA))
w("  LEFT JOIN esc_nucleo nc ON nc.unidade = t.unidade AND nc.turno = t.turno AND nc.numero = t.cobertura AND nc.vigente_desde = " + q(VIGENCIA) + ";")
w('')
w('DROP TEMPORARY TABLE IF EXISTS tmp_es10_pagina;')
w('CREATE TEMPORARY TABLE tmp_es10_pagina (carteira VARCHAR(8), turno CHAR(1), seq SMALLINT UNSIGNED, pagina VARCHAR(150), fracao DECIMAL(6,4), '
  'fans_h DECIMAL(6,1), apoio TINYINT, PRIMARY KEY (carteira, turno, seq, pagina)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;')
linhas = []
for p in postos_saida:
    for g in p['itens']:
        linhas.append(f"({q(p['cart'])}, {q(p['t'])}, {p['seq']}, {q(g['p'])}, {round(float(g['fr']), 4)}, {round(float(g['f']), 1)}, {1 if g['a'] else 0})")
w('INSERT INTO tmp_es10_pagina VALUES\n  ' + ',\n  '.join(linhas) + ';')
w("INSERT IGNORE INTO esc_posto_pagina (posto_id, pagina, page_id, fracao, fans_h, apoio)")
w("SELECT p.id, t.pagina,")
w("       (SELECT pg.id FROM `5c_pages`.pages pg WHERE pg.name = t.pagina ORDER BY pg.active DESC, pg.id DESC LIMIT 1),")
w("       t.fracao, t.fans_h, t.apoio")
w("  FROM tmp_es10_pagina t")
w(f"  JOIN esc_posto p ON p.versao = {q(VERSAO)} AND p.carteira = t.carteira AND p.turno = t.turno AND p.seq = t.seq;")
w('')
w('-- 3. Proposta de equipe de cada vaga (status PROPOSTA)')
w('DROP TEMPORARY TABLE IF EXISTS tmp_es10_vaga;')
w('CREATE TEMPORARY TABLE tmp_es10_vaga (carteira VARCHAR(8), turno CHAR(1), seq SMALLINT UNSIGNED, vaga VARCHAR(10), rep_code VARCHAR(30), '
  'fatia DECIMAL(4,3), ordem TINYINT UNSIGNED, motivo VARCHAR(255)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;')
linhas = [f"({q(c)}, {q(t)}, {s}, {q(vaga)}, {q(code)}, {f}, {o}, {q(mot)})" for (c, t, s), vaga, code, f, o, mot in equipes]
w('INSERT INTO tmp_es10_vaga VALUES\n  ' + ',\n  '.join(linhas) + ';')
w("INSERT INTO esc_vaga_equipe (posto_id, vaga, rep_code, fatia, ordem, status, motivo, vigente_desde, origem)")
w("SELECT p.id, t.vaga, t.rep_code, t.fatia, t.ordem, 'PROPOSTA', t.motivo, " + q(VIGENCIA) + ", 'MONTADOR'")
w("  FROM tmp_es10_vaga t")
w(f"  JOIN esc_posto p ON p.versao = {q(VERSAO)} AND p.carteira = t.carteira AND p.turno = t.turno AND p.seq = t.seq")
w(" WHERE NOT EXISTS (SELECT 1 FROM esc_vaga_equipe x WHERE x.posto_id = p.id AND x.vaga = t.vaga AND x.rep_code = t.rep_code AND x.vigente_ate IS NULL);")
w('')
w('COMMIT;')
w('')
w('-- =====================================================================')
w('-- Conferência')
w('-- =====================================================================')
w(f"SELECT 'postos vigentes' AS item, COUNT(*) AS n, {q(str(len(postos_saida)))} AS esperado FROM esc_posto WHERE vigente_ate IS NULL")
w(f"UNION ALL SELECT 'postos de outra versão ainda vigentes', COUNT(*), '0' FROM esc_posto WHERE vigente_ate IS NULL AND versao <> {q(VERSAO)}")
w(f"UNION ALL SELECT 'páginas dos postos', COUNT(*), {q(str(sum(len(p['itens']) for p in postos_saida)))} FROM esc_posto_pagina pp JOIN esc_posto p ON p.id = pp.posto_id WHERE p.versao = {q(VERSAO)}")
w(f"UNION ALL SELECT 'páginas sem page_id (conferir nome)', COUNT(*), '0' FROM esc_posto_pagina pp JOIN esc_posto p ON p.id = pp.posto_id WHERE p.versao = {q(VERSAO)} AND pp.page_id IS NULL")
w(f"UNION ALL SELECT 'linhas de equipe vigentes', COUNT(*), {q(str(len(equipes)))} FROM esc_vaga_equipe e JOIN esc_posto p ON p.id = e.posto_id WHERE p.versao = {q(VERSAO)} AND e.vigente_ate IS NULL")
w("UNION ALL SELECT 'equipes de postos antigos ainda vigentes', COUNT(*), '0' FROM esc_vaga_equipe e JOIN esc_posto p ON p.id = e.posto_id WHERE e.vigente_ate IS NULL AND p.vigente_ate IS NOT NULL;")
io.open(SAIDA, 'w', encoding='utf-8', newline='\n').write('\n'.join(L) + '\n')

# ---------------------------------------------------------------- resumo
print('U T | postos | postos por núcleo dono | pares de cobertura | coberturas sem par | falta (pessoas) | vagas vazias | sobra p/ buffer | equipe que já atuava nas páginas')
for x in resumo:
    print(' | '.join(str(v) for v in x))
dist = collections.Counter(len(v) for v in pessoas_pag.values())
print('pessoas diferentes por página-turno no ciclo:', dict(sorted(dist.items())),
      'média', round(sum(len(v) for v in pessoas_pag.values()) / max(len(pessoas_pag), 1), 2))
print('linhas de equipe:', len(equipes), '| postos:', len(postos_saida))
print(f'modelo com 2+ páginas no mesmo turno e carteira: {len(multi)}; em postos diferentes: {len(separadas)}')
