# Gera o ES_05_carga_estrutura_27out.sql a partir do modelo de 27/10
# (estrutura_unidades/data_real_final.json do pacote 5C_contexto_27out).
#
# Monta a proposta inicial de equipes por vaga, com as regras da spec v4
# (seção 2, item 3), e imprime um resumo por unidade e turno.
#
# Uso: python gerar_es05.py <data_real_final.json> <saida.sql>
import collections, io, json, sys

ENTRADA, SAIDA = sys.argv[1], sys.argv[2]
d = json.load(io.open(ENTRADA, encoding='utf-8'))

VIGENCIA = '2026-11-01'
VERSAO = 'MODELO_27OUT'
DESLOC = {1: 0, 2: 14, 3: 7}  # núcleo 1 em P1, núcleo 2 em P3, núcleo 3 em P2 na semana de 02/11
FATIA = {0: 0.40, 1: 0.60, 2: 0.80}  # níveis 3–5 = 1
MAX_VAGAS_BAIXO = 2

# Códigos dos líderes (headcount de 27/10). Nomes do modelo → código.
COD_LIDER = {
    'Gabriel Henrique Keller': 'G105318', 'Hebert Lopes Ruiz': 'H093754', 'João Lucas dos Santos Mayresse': 'J085570',
    'Alex Emanuel Machado de Souza': 'A142232', 'Natasha Tem Tem': 'N069703', 'Eduardo Ehlke': 'E009869',
    'Victor Renner Magno de Aguiar': 'V056646', 'Lucas Fernandes Silva': 'L098436', 'Gabriel da Silva': 'G463421',
    'Rafaela de Souza Gaspar': 'R107827', 'Nathan da Silva Peres Santos': 'N163267', 'Nicoli Peretti Klein': 'N092070',
    'Betania Caroline Francisco Ferreira': 'B114969', 'João Paulo Gonçalves Martins': 'J070909',
    'Daniel Fratane Amado': 'D154823', 'Caio Afonso da Silva Limongi': 'C019777', 'Leiziane Lopes Ruiz': 'L092466',
    'Carolina Xavier Broggio': 'C099427', 'Tiago Benjamin Vidigal': 'T051480', 'Fernando Ehlke': 'F009869',
    'Julia Andrade Vieira': 'J418568', 'Thiago de Meneses Arantes': 'T143032', 'Ricardo Cunico': 'R077585',
    'Joao Paulo Caial Pires': 'J110041', 'Vinicius Kodama Yoshinaga': 'V098223', 'Isabel da Silva Marinho': None,
    'Victoria Aires Cunico': 'V034940', 'Pedro Ribeiro da Silva Neto': 'P094137', 'Yuri de Souza Oliveira Bonfim': 'Y135044',
    'Rafael Zaganini Paschoal': 'R063529', 'Nátali Bianchini Vieira': 'N108054', 'Henrique Dubles Bisneto': 'H122585',
    'Nathan Vinícius Sahdo': 'N401103', 'Lucas Dos Santos Caetano': 'L124965', 'José Otávio de Melo': 'J104720',
    'Rafael Gauze Pereira dos Reis': 'R096272', 'Guilherme de Lucca Vicente': 'G115750',
    'Matheus Felipe Seluzniak': 'M112585', 'Jan Roger Maia da Silva': 'J019571',
}

def q(v):
    if v is None:
        return 'NULL'
    if isinstance(v, (int, float)):
        return repr(v)
    return "'" + str(v).replace('\\', '\\\\').replace("'", "''") + "'"

def cart_cod(k):  # 'U1 · C1' (com caractere trocado no arquivo) → 'U1C1'
    return k[:2] + k[-2:]

# ---------------------------------------------------------------- líderes e núcleos
lid = d['lead']
oum = [l for l in lid if l['nivel'] == 'OUM']
om = [l for l in lid if l['nivel'] == 'OM']
os_ = [l for l in lid if l['nivel'] == 'OS']
nucleos = {}  # (u, t) -> [nome do OS na ordem do organograma]
for l in os_:
    nucleos.setdefault((l['u'], l['tn']), []).append(l)
assert all(len(v) == 3 for v in nucleos.values()) and len(nucleos) == 9, {k: len(v) for k, v in nucleos.items()}
nuc_de_os = {}
for (u, t), ls in nucleos.items():
    for i, l in enumerate(ls, start=1):
        nuc_de_os[l['nome']] = (u, t, i)

# Fora da carga: quem não tem cadastro no headcount (aparece no modelo como "(sem Cadastro)").
FORA = {'B109639'}
d['reps'] = [r for r in d['reps'] if r['c'] not in FORA]
reps = {r['n']: r for r in d['reps']}
for r in d['reps']:
    assert r['sup'] in nuc_de_os, r['sup']
    u, t, _ = nuc_de_os[r['sup']]
    assert (u, t) == (r['u'], r['t']), (r['n'], r['sup'])

def fatia_de(lv):
    return 1.0 if lv >= 3 else FATIA[lv]

# ---------------------------------------------------------------- montador por unidade e turno
postos_saida = []   # dicts com chave natural
equipes = []        # (chave_posto, vaga, rep_code, fatia, ordem, motivo)
resumo = []
pessoas_pag = collections.defaultdict(set)

por_ut = collections.defaultdict(list)
for p in d['postos']:
    por_ut[(p['u'], p['t'])].append(p)

for (u, t) in sorted(por_ut):
    ps = por_ut[(u, t)]
    ns = [1, 2, 3]
    seq_cart = collections.Counter()
    P = []
    for p in ps:
        c = cart_cod(p['k'])
        seq_cart[c] += 1
        team_real = [m for m in p['team'] if m['n'] in reps and not m.get('f')]
        tit = next((m['n'] for m in team_real if m['reg'] >= 0.99), None)
        P.append({'u': u, 't': t, 'cart': c, 'seq': seq_cart[c], 'tipo': {'double': 'double'}.get(p['kind'], p['kind']),
                  'pags': [it['p'] for it in p['items']], 'itens': p['items'], 'carga': p['load'], 'cap': p['cap'],
                  'time': [m['n'] for m in team_real], 'tit': tit,
                  'dono': nuc_de_os[reps[tit]['sup']][2] if tit else None, 'cob': None})
    # dono dos postos sem titular: maioria do time; senão o núcleo com menos postos
    for p in P:
        if p['dono'] is None and p['time']:
            cnt = collections.Counter(nuc_de_os[reps[n]['sup']][2] for n in p['time'])
            p['dono'] = cnt.most_common(1)[0][0]
    for p in P:
        if p['dono'] is None:
            n_por = collections.Counter(x['dono'] for x in P if x['dono'])
            p['dono'] = min(ns, key=lambda n: n_por[n])
    n_por = collections.Counter(p['dono'] for p in P)
    # cobertura: o núcleo j cobre um posto de i e um de k (par); b_j = (n_i + n_k − n_j) / 2
    pend = {n: [p for p in P if p['dono'] == n] for n in ns}
    pares, sem_par = [], []
    b = {j: max(0, (sum(n_por[x] for x in ns if x != j) - n_por[j]) // 2) for j in ns}
    for j in sorted(ns, key=lambda x: -b[x]):
        i, k = [x for x in ns if x != j]
        for _ in range(b[j]):
            if not pend[i] or not pend[k]:
                break
            # 1º mesma página, 2º mesma carteira, 3º maior carga (página mais importante)
            par = max(((a, c) for a in pend[i] for c in pend[k]),
                      key=lambda ac: (bool(set(ac[0]['pags']) & set(ac[1]['pags'])), ac[0]['cart'] == ac[1]['cart'],
                                      ac[0]['carga'] + ac[1]['carga']))
            for x in par:
                x['cob'] = j
                pend[x['dono']].remove(x)
            pares.append(par)
    # sobra: cobre o núcleo com menos cobertura (diferente do dono), sem par
    for n in ns:
        for p in list(pend[n]):
            outros = [x for x in ns if x != n]
            p['cob'] = min(outros, key=lambda x: sum(1 for y in P if y['cob'] == x))
            pend[n].remove(p)
            sem_par.append(p)
    # vagas: DONO = 1 por posto (precisa de 1 pessoa nos dias do núcleo dono).
    # COBERTURA = 1 por par de postos (a mesma pessoa cobre os 2: cada um só nos dias de folga do
    # seu dono, que nunca coincidem). Posto de cobertura sem par = meia vaga (o resto dos dias da
    # pessoa vira buffer).
    vagas = []
    for p in P:
        vagas.append({'p': p, 'ps': [p], 'vaga': 'DONO', 'nuc': p['dono'], 'eq': [], 'precisa': 1.0})
    for x in pares:
        vagas.append({'p': x[0], 'ps': list(x), 'vaga': 'COBERTURA', 'nuc': x[0]['cob'], 'eq': [], 'precisa': 1.0})
    for p in sem_par:
        vagas.append({'p': p, 'ps': [p], 'vaga': 'COBERTURA', 'nuc': p['cob'], 'eq': [], 'precisa': 0.5})
    membros = {n: [r for r in d['reps'] if r['u'] == u and r['t'] == t and nuc_de_os[r['sup']][2] == n] for n in ns}
    resto = {r['n']: fatia_de(r['lv']) for n in ns for r in membros[n]}
    nvagas = collections.Counter()

    # Pessoa = fração dos próprios dias de trabalho (nível 3–5: 1; nível 0–2: a fatia do nível).
    # Vaga cheia precisa de 1; meia vaga de 0,5.
    def falta(v):
        return v['precisa'] - sum(f for _, f, _ in v['eq'])

    def por(v, nome, f, motivo):
        v['eq'].append((nome, round(f, 3), motivo))
        resto[nome] -= f
        nvagas[nome] += 1

    def pags(v):
        return set(g for p in v['ps'] for g in p['pags'])

    def no_time(r, v):
        return any(r['n'] in p['time'] for p in v['ps'])

    def afinidade(r, v):
        return (no_time(r, v), bool(pags(v) & set(x['p'] for x in r.get('pp', []))),
                v['p']['cart'] == cart_cod(r['cart']), v['precisa'], v['p']['carga'])

    # 1. titular do modelo na vaga do dono
    for v in vagas:
        p = v['p']
        if v['vaga'] == 'DONO' and p['tit'] and resto[p['tit']] >= 0.999 and reps[p['tit']]['lv'] >= 3:
            por(v, p['tit'], 1.0, 'titular do posto no modelo de 27/10')
    # 2. nível 3–5 sem vaga: vaga cheia vazia do próprio núcleo, onde já atua ou nas mesmas páginas
    for n in ns:
        for r in sorted(membros[n], key=lambda r: -r['lv']):
            if r['lv'] < 3 or resto[r['n']] < 0.999:
                continue
            livres = [v for v in vagas if v['nuc'] == n and not v['eq'] and v['precisa'] >= 0.999]
            if livres:
                v = max(livres, key=lambda v: afinidade(r, v))
                por(v, r['n'], 1.0, 'nível ' + str(r['lv']) + ', fixo na vaga' + (' (já no time do posto)' if no_time(r, v) else ''))
    # 3. níveis 0–2 na fatia do nível, até 2 vagas por pessoa (as maiores páginas primeiro)
    def encher(v, limite_extra):
        n = v['nuc']
        while falta(v) > 0.001:
            cands = [r for r in membros[n] if r['lv'] < 3 and resto[r['n']] + limite_extra.get(r['n'], 0) > 0.001
                     and nvagas[r['n']] < MAX_VAGAS_BAIXO and r['n'] not in [e[0] for e in v['eq']]]
            if not cands:
                return
            r = max(cands, key=lambda r: (afinidade(r, v), r['lv'], resto[r['n']]))
            disp = resto[r['n']] + limite_extra.get(r['n'], 0)
            f = min(falta(v), disp)
            acima = f > resto[r['n']] + 0.001
            if acima:
                limite_extra[r['n']] -= f - max(resto[r['n']], 0)
            por(v, r['n'], f, 'nível ' + str(r['lv']) + (', acima da fatia do nível por falta de gente' if acima else '')
                + (' (já no time do posto)' if no_time(r, v) else ''))
    for v in sorted(vagas, key=lambda v: (-v['precisa'], -v['p']['carga'])):
        encher(v, {})
    # 4. nível 3–5 que sobrou completa vaga parcial
    for v in vagas:
        if falta(v) > 0.001:
            for r in membros[v['nuc']]:
                if r['lv'] >= 3 and resto[r['n']] > 0.001 and nvagas[r['n']] == 0:
                    por(v, r['n'], min(falta(v), resto[r['n']]), 'nível ' + str(r['lv']) + ', completa a vaga')
                    break
    # 5. ainda falta: níveis 0–2 passam da fatia (usam os dias de buffer) antes de virar lacuna
    extra = {r['n']: 1 - fatia_de(r['lv']) for n in ns for r in membros[n] if r['lv'] < 3}
    for v in sorted(vagas, key=lambda v: (-v['precisa'], -v['p']['carga'])):
        if falta(v) > 0.001:
            encher(v, extra)

    # saída: a fatia gravada é a parte dos dias DA VAGA (soma 1 numa vaga completa)
    for p in P:
        postos_saida.append(p)
    for v in vagas:
        for o, (nome, f, mot) in enumerate(v['eq'], start=1):
            for p in v['ps']:
                equipes.append(((p['cart'], t, p['seq']), v['vaga'], reps[nome]['c'], round(min(1.0, f / v['precisa']), 3), o, mot))
                for g in p['pags']:
                    pessoas_pag[(g, t)].add(nome)
    falta_tot = sum(max(0, falta(v)) for v in vagas)
    buffer_tot = sum(max(0, x) for x in resto.values())
    vazias = sum(1 for v in vagas if not v['eq'])
    acima_fatia = sum(1 for n in extra if extra[n] < 1 - fatia_de(reps[n]['lv']) - 0.001)
    resumo.append((u, t, len(P), dict(sorted(n_por.items())), len(pares), len(sem_par), round(falta_tot, 2), vazias,
                   round(buffer_tot, 2), acima_fatia))

# ---------------------------------------------------------------- SQL
L = []
w = L.append
w('-- =====================================================================')
w('-- ES_05_carga_estrutura_27out.sql  (gerado por escalas/sql/gerador/gerar_es05.py)')
w('-- Carga da estrutura de 01/11/2026 a partir do modelo de 27/10:')
w('--   unidades, OUM, OM, os 27 núcleos (Decurio), as 247 pessoas (220 Equites + 27 Decurios)')
w('--   com núcleo, nível e o modelo "5x2 rotativo 21 dias", os 162 postos com as páginas e')
w('--   a PROPOSTA de equipe de cada vaga (status PROPOSTA: o Planejamento confirma na tela).')
w('-- Rodar depois do ES_04. Pode rodar de novo (não duplica).')
w('-- A configuração antiga de cada pessoa da carga é fechada em 31/10 (vigente_ate) e a nova')
w('-- vale de 01/11. Quem não está no modelo de 27/10 não é mexido (aparece na conferência).')
w('-- =====================================================================')
w('')
w('SET NAMES utf8mb4;')
w('USE `5c_escalas`;')
w('')
w('START TRANSACTION;')
w('')
w('-- 1. Unidades')
w('INSERT IGNORE INTO esc_unidade (id, nome) VALUES ' + ', '.join(f'({i}, {q("Unidade " + str(i))})' for i in (1, 2, 3)) + ';')
w('')
w('-- 2. OUM e OM')
for l in oum:  # turno NULL: o UNIQUE não segura a repetição, então só insere se não existir
    w(f"INSERT INTO esc_lideranca (nivel, unidade, turno, rep_code, nome, vigente_desde, origem) SELECT 'OUM', {l['u']}, NULL, "
      f"{q(COD_LIDER[l['full']])}, {q(l['full'])}, {q(VIGENCIA)}, 'CARGA_27OUT' FROM DUAL WHERE NOT EXISTS "
      f"(SELECT 1 FROM esc_lideranca WHERE nivel = 'OUM' AND unidade = {l['u']} AND vigente_desde = {q(VIGENCIA)});")
linhas = []
for l in om:
    linhas.append(f"('OM', {l['u']}, {q(l['tn'])}, {q(COD_LIDER[l['full']])}, {q(l['full'])}, {q(VIGENCIA)}, 'CARGA_27OUT')")
w('INSERT IGNORE INTO esc_lideranca (nivel, unidade, turno, rep_code, nome, vigente_desde, origem) VALUES\n  ' + ',\n  '.join(linhas) + ';')
w('')
w('-- 3. Núcleos (Decurio). Ordem do organograma: núcleo 1 em P1, 2 em P3, 3 em P2 na semana de 02/11.')
linhas = []
for (u, t), ls in sorted(nucleos.items()):
    for i, l in enumerate(ls, start=1):
        linhas.append(f"({u}, {q(t)}, {i}, {q(COD_LIDER[l['full']])}, {q(l['full'])}, {DESLOC[i]}, {q(VIGENCIA)}, 'CARGA_27OUT')")
w('INSERT IGNORE INTO esc_nucleo (unidade, turno, numero, decurio_code, decurio_nome, deslocamento, vigente_desde, origem) VALUES\n  '
  + ',\n  '.join(linhas) + ';')
w('')
w('-- 4. Pessoas: tabela de trabalho com a carga')
w('DROP TEMPORARY TABLE IF EXISTS tmp_carga_pessoa;')
w('CREATE TEMPORARY TABLE tmp_carga_pessoa (rep_code VARCHAR(30) NOT NULL PRIMARY KEY, nome VARCHAR(150), unidade TINYINT UNSIGNED, '
  'turno CHAR(1), nucleo TINYINT UNSIGNED, nivel TINYINT UNSIGNED, funcao VARCHAR(10)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;')
linhas = []
for r in sorted(d['reps'], key=lambda r: r['c']):
    u, t, i = nuc_de_os[r['sup']]
    linhas.append(f"({q(r['c'])}, {q(r['n'])}, {u}, {q(t)}, {i}, {r['lv']}, 'EQUITE')")
cod_reps = {r['c'] for r in d['reps']}
for (u, t), ls in sorted(nucleos.items()):
    for i, l in enumerate(ls, start=1):
        assert COD_LIDER[l['full']] not in cod_reps, ('Decurio também está na lista de reps', l['full'])
        if COD_LIDER[l['full']]:
            linhas.append(f"({q(COD_LIDER[l['full']])}, {q(l['full'])}, {u}, {q(t)}, {i}, NULL, 'DECURIO')")
w('INSERT INTO tmp_carga_pessoa (rep_code, nome, unidade, turno, nucleo, nivel, funcao) VALUES\n  ' + ',\n  '.join(linhas) + ';')
w('')
w("-- 4a. Fecha a configuração anterior em 31/10")
w("UPDATE esc_colaborador_config c JOIN tmp_carga_pessoa t ON t.rep_code = c.rep_code")
w("   SET c.vigente_ate = '2026-10-31'")
w(f" WHERE c.vigente_ate IS NULL AND c.vigente_desde < {q(VIGENCIA)};")
w("-- 4b. Abre a configuração da estrutura nova (não duplica se já existir)")
w("INSERT INTO esc_colaborador_config (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, vigente_desde, origem, observacao)")
w("SELECT t.rep_code, m.id, 'NUCLEO', t.turno, t.unidade, n.id, t.nivel, t.funcao, " + q(VIGENCIA) + ", 'CARGA_ESTRUTURA', 'Estrutura de 01/11 (modelo de 27/10)'")
w("  FROM tmp_carga_pessoa t")
w("  JOIN esc_modelo_escala m ON m.nome = '5x2 rotativo 21 dias'")
w("  JOIN esc_nucleo n ON n.unidade = t.unidade AND n.turno = t.turno AND n.numero = t.nucleo AND n.vigente_desde = " + q(VIGENCIA))
w(" WHERE NOT EXISTS (SELECT 1 FROM esc_colaborador_config x WHERE x.rep_code = t.rep_code AND x.vigente_desde = " + q(VIGENCIA) + ");")
w('')
w('-- 5. Postos do modelo de 27/10 e páginas')
w('DROP TEMPORARY TABLE IF EXISTS tmp_carga_posto;')
w('CREATE TEMPORARY TABLE tmp_carga_posto (carteira VARCHAR(8), turno CHAR(1), seq SMALLINT UNSIGNED, unidade TINYINT UNSIGNED, tipo VARCHAR(12), '
  'pagina_principal VARCHAR(150), n_paginas TINYINT UNSIGNED, assinatura VARCHAR(400), carga DECIMAL(6,1), capacidade DECIMAL(5,1), '
  'dono TINYINT UNSIGNED, cobertura TINYINT UNSIGNED, PRIMARY KEY (carteira, turno, seq)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;')
linhas = []
for p in postos_saida:
    assin = ' + '.join(sorted(p['pags'])) + ' [' + p['tipo'] + ']'
    linhas.append(f"({q(p['cart'])}, {q(p['t'])}, {p['seq']}, {p['u']}, {q(p['tipo'])}, {q(p['pags'][0])}, {len(p['pags'])}, {q(assin)}, "
                  f"{round(p['carga'], 1)}, {round(p['cap'], 1)}, {p['dono']}, {p['cob'] if p['cob'] else 'NULL'})")
w('INSERT INTO tmp_carga_posto VALUES\n  ' + ',\n  '.join(linhas) + ';')
w("INSERT IGNORE INTO esc_posto (versao, unidade, carteira, turno, seq, tipo, pagina_principal, n_paginas, assinatura, carga, capacidade,")
w("                              nucleo_dono_id, nucleo_cobertura_id, vigente_desde)")
w(f"SELECT {q(VERSAO)}, t.unidade, t.carteira, t.turno, t.seq, t.tipo, t.pagina_principal, t.n_paginas, t.assinatura, t.carga, t.capacidade,")
w("       nd.id, nc.id, " + q(VIGENCIA))
w("  FROM tmp_carga_posto t")
w("  JOIN esc_nucleo nd ON nd.unidade = t.unidade AND nd.turno = t.turno AND nd.numero = t.dono AND nd.vigente_desde = " + q(VIGENCIA))
w("  LEFT JOIN esc_nucleo nc ON nc.unidade = t.unidade AND nc.turno = t.turno AND nc.numero = t.cobertura AND nc.vigente_desde = " + q(VIGENCIA) + ";")
w('')
w('DROP TEMPORARY TABLE IF EXISTS tmp_carga_posto_pagina;')
w('CREATE TEMPORARY TABLE tmp_carga_posto_pagina (carteira VARCHAR(8), turno CHAR(1), seq SMALLINT UNSIGNED, pagina VARCHAR(150), fracao DECIMAL(6,4), '
  'fans_h DECIMAL(6,1), apoio TINYINT, PRIMARY KEY (carteira, turno, seq, pagina)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;')
linhas = []
for p in postos_saida:
    for it in p['itens']:
        linhas.append(f"({q(p['cart'])}, {q(p['t'])}, {p['seq']}, {q(it['p'])}, {round(1 / len(p['itens']), 4)}, {round(it['f'], 1)}, {1 if it.get('a') else 0})")
w('INSERT INTO tmp_carga_posto_pagina VALUES\n  ' + ',\n  '.join(linhas) + ';')
w("INSERT IGNORE INTO esc_posto_pagina (posto_id, pagina, page_id, fracao, fans_h, apoio)")
w("SELECT p.id, t.pagina,")
w("       (SELECT pg.id FROM `5c_pages`.pages pg WHERE pg.name = t.pagina ORDER BY pg.active DESC, pg.id DESC LIMIT 1),")
w("       t.fracao, t.fans_h, t.apoio")
w("  FROM tmp_carga_posto_pagina t")
w(f"  JOIN esc_posto p ON p.versao = {q(VERSAO)} AND p.carteira = t.carteira AND p.turno = t.turno AND p.seq = t.seq;")
w('')
w('-- 6. Proposta de equipe de cada vaga (status PROPOSTA)')
w('DROP TEMPORARY TABLE IF EXISTS tmp_carga_vaga;')
w('CREATE TEMPORARY TABLE tmp_carga_vaga (carteira VARCHAR(8), turno CHAR(1), seq SMALLINT UNSIGNED, vaga VARCHAR(10), rep_code VARCHAR(30), '
  'fatia DECIMAL(4,3), ordem TINYINT UNSIGNED, motivo VARCHAR(255)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;')
linhas = []
for (c, t, s), vaga, code, f, o, mot in equipes:
    linhas.append(f"({q(c)}, {q(t)}, {s}, {q(vaga)}, {q(code)}, {f}, {o}, {q(mot)})")
w('INSERT INTO tmp_carga_vaga VALUES\n  ' + ',\n  '.join(linhas) + ';')
w("INSERT IGNORE INTO esc_vaga_equipe (posto_id, vaga, rep_code, fatia, ordem, status, motivo, vigente_desde, origem)")
w("SELECT p.id, t.vaga, t.rep_code, t.fatia, t.ordem, 'PROPOSTA', t.motivo, " + q(VIGENCIA) + ", 'CARGA_27OUT'")
w("  FROM tmp_carga_vaga t")
w(f"  JOIN esc_posto p ON p.versao = {q(VERSAO)} AND p.carteira = t.carteira AND p.turno = t.turno AND p.seq = t.seq;")
w('')
w('COMMIT;')
w('')
w('-- =====================================================================')
w('-- Conferência')
w('-- =====================================================================')
w("SELECT 'núcleos' AS item, COUNT(*) AS n, '27' AS esperado FROM esc_nucleo WHERE vigente_desde = " + q(VIGENCIA))
w("UNION ALL SELECT 'núcleos sem código do Decurio', COUNT(*), '1 (Isabel da Silva Marinho, U2 A)' FROM esc_nucleo WHERE vigente_desde = " + q(VIGENCIA) + " AND decurio_code IS NULL")
w("UNION ALL SELECT 'pessoas na estrutura nova', COUNT(*), " + q(str(len(d['reps']) + sum(1 for l in os_ if COD_LIDER[l['full']]))) + " FROM esc_colaborador_config WHERE vigente_desde = " + q(VIGENCIA))
w("UNION ALL SELECT 'postos', COUNT(*), '162' FROM esc_posto WHERE versao = " + q(VERSAO))
w("UNION ALL SELECT 'páginas de postos', COUNT(*), " + q(str(sum(len(p['itens']) for p in postos_saida))) + " FROM esc_posto_pagina pp JOIN esc_posto p ON p.id = pp.posto_id WHERE p.versao = " + q(VERSAO))
w("UNION ALL SELECT 'páginas sem page_id (conferir nome)', COUNT(*), '0' FROM esc_posto_pagina pp JOIN esc_posto p ON p.id = pp.posto_id WHERE p.versao = " + q(VERSAO) + " AND pp.page_id IS NULL")
w("UNION ALL SELECT CONCAT('página inativa hoje no cadastro: ', GROUP_CONCAT(DISTINCT pp.pagina)), COUNT(DISTINCT pp.pagina), '0 (27/10: Daniswings 157m)' FROM esc_posto_pagina pp JOIN esc_posto p ON p.id = pp.posto_id JOIN `5c_pages`.pages pg ON pg.id = pp.page_id WHERE p.versao = " + q(VERSAO) + " AND pg.active = 0")
w("UNION ALL SELECT 'linhas de equipe (proposta)', COUNT(*), " + q(str(len(equipes))) + " FROM esc_vaga_equipe WHERE origem = 'CARGA_27OUT'")
w("UNION ALL SELECT 'pessoas da carga fora do headcount ativo', COUNT(*), '0' FROM esc_colaborador_config c WHERE c.vigente_desde = " + q(VIGENCIA)
  + " AND NOT EXISTS (SELECT 1 FROM `5c_headcount`.headcount h WHERE h.code = c.rep_code COLLATE utf8mb4_0900_ai_ci AND h.active = 1)")
w("UNION ALL SELECT 'configurações v3 que seguem abertas (fora do modelo)', COUNT(*), '—' FROM esc_colaborador_config WHERE vigente_ate IS NULL AND nucleo_id IS NULL;")
w('')
w('-- Vagas por unidade e turno: preenchidas, parciais e vazias')
w("SELECT p.unidade, p.turno, COUNT(*) AS vagas,")
w("       SUM(e.soma >= 0.999) AS completas, SUM(e.soma > 0 AND e.soma < 0.999) AS parciais, SUM(e.soma IS NULL) AS vazias")
w("  FROM (SELECT id, unidade, turno FROM esc_posto WHERE versao = " + q(VERSAO) + ") p")
w("  CROSS JOIN (SELECT 'DONO' AS vaga UNION ALL SELECT 'COBERTURA') v")
w("  LEFT JOIN (SELECT posto_id, vaga, SUM(fatia) AS soma FROM esc_vaga_equipe WHERE origem = 'CARGA_27OUT' GROUP BY posto_id, vaga) e")
w("         ON e.posto_id = p.id AND e.vaga = v.vaga")
w(" GROUP BY p.unidade, p.turno ORDER BY p.unidade, p.turno;")
io.open(SAIDA, 'w', encoding='utf-8', newline='\n').write('\n'.join(L) + '\n')

# ---------------------------------------------------------------- resumo
print('U T | postos | postos por núcleo dono | pares de cobertura | coberturas sem par | falta (pessoas) | vagas vazias | sobra p/ buffer (pessoas) | níveis 0–2 acima da fatia')
for x in resumo:
    print(' | '.join(str(v) for v in x))
dist = collections.Counter(len(v) for v in pessoas_pag.values())
print('pessoas diferentes por página-turno no ciclo:', dict(sorted(dist.items())),
      'média', round(sum(len(v) for v in pessoas_pag.values()) / len(pessoas_pag), 2))
print('linhas de equipe:', len(equipes), '| postos:', len(postos_saida))
