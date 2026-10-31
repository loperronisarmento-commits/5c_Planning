# Espelho do sp_fc_plano_sugerido com a rampa do novato (script 26), com os dados da versão 17 (31/10).
# Rampa: nas semanas em que a turma tem 7 a rampa_novato_dias dias de operação (semanas 1 a 4), cada novato rende
# rampa_novato_rend de um rep. O quadro e as saídas não mudam; muda a falta (precisa − quadro que rende).
# Uso: python -I -X utf8 plano_rampa_espelho.py [sem_rampa]
import math, sys
from datetime import date, timedelta as td
T = 'ACD'
SEM_RAMPA = len(sys.argv) > 1 and sys.argv[1] == 'sem_rampa'
TX = {'A': 0.0089, 'C': 0.0170, 'D': 0.0186}; N14, N513 = 0.03, 0.0122; DES = 0.06; TETO, TT = 30, 10; FECHA = 5
RDIAS, REND = 30, (1.0 if SEM_RAMPA else 0.5)
H0 = {'A': 76, 'C': 69, 'D': 75}
ALVO = {'A': 101, 'C': 107, 'D': 107}
INI = date(2026, 10, 26)
D = date.fromisoformat
# (turma, opera, fixa, (A, C, D)) — plano oficial depois do script 25
OF = [('2026-11-02', '2026-11-16', 1, (4, 7, 4)), ('2026-11-16', '2026-11-30', 1, (5, 9, 6)),
      ('2026-12-07', '2026-12-21', 0, (10, 10, 10)), ('2027-01-11', '2027-01-25', 0, (10, 10, 10)),
      ('2027-01-25', '2027-02-08', 0, (10, 10, 10)), ('2027-02-08', '2027-02-22', 1, (10, 10, 10)),
      ('2027-02-22', '2027-03-08', 0, (2, 10, 10)), ('2027-03-08', '2027-03-22', 0, (2, 7, 10)),
      ('2027-03-22', '2027-04-05', 0, (2, 4, 4)), ('2027-04-05', '2027-04-19', 0, (2, 4, 4)),
      ('2027-04-19', '2027-05-03', 0, (2, 4, 5)), ('2027-05-03', '2027-05-17', 0, (2, 4, 4)),
      ('2027-05-17', '2027-05-31', 0, (2, 4, 4)), ('2027-05-31', '2027-06-14', 0, (2, 3, 4)),
      ('2027-06-14', '2027-06-28', 0, (2, 4, 4)), ('2027-06-28', '2027-07-12', 0, (2, 4, 4))]

def rampa(turmas, ws):
    r = {t: 0.0 for t in T}
    for _, op, _, q in turmas:
        op = D(op)
        if op < ws and (ws - op).days <= RDIAS:
            for t, x in zip(T, q): r[t] += x * (1 - REND)
    return r

def sim(turmas, ate, rows=None):
    h = dict(H0); ws = INI
    while ws < ate:
        if rows is not None:
            r = rampa(turmas, ws); rows.append((ws, dict(h), {t: h[t] - r[t] for t in T}))
        e = {t: 0 for t in T}; x = {t: 0.0 for t in T}
        for _, op, _, q in turmas:
            op = D(op); q = dict(zip(T, q))
            if ws <= op < ws + td(7):
                for t in T: e[t] += q[t]
            if op < ws:
                sem = (ws - op).days // 7 + 1
                if 1 <= sem <= 13:
                    for t in T: x[t] += q[t] * max((N14 if sem <= 4 else N513) - TX[t], 0)
        for t in T: h[t] = max(h[t] - (h[t] * TX[t] + x[t]) + e[t], 0)
        ws += td(7)
    r = rampa(turmas, ate)
    return {t: h[t] - r[t] for t in T}

def plano():
    sg = [(t, o, f, q if f else (0, 0, 0)) for t, o, f, q in OF]
    plan = [i for i, (t, o, f, q) in enumerate(OF) if not f]
    for k, i in enumerate(plan):
        t, o, f, _ = OF[i]
        nxt = D(OF[plan[k + 1]][1]) if k + 1 < len(plan) else D(o) + td(14)
        h = sim(sg, nxt)
        need = {u: min(max(0.0, (ALVO[u] - h[u]) / (1 - DES)), TT) for u in T}
        q = {u: min(math.floor(need[u] + 0.5), TT) for u in T}
        sg[i] = (t, o, f, (q['A'], q['C'], q['D']))
    return sg

def fecha(turmas):
    rows = []; sim(turmas, date(2027, 12, 31), rows)
    for ws, h, ef in rows:
        if sum(ALVO[t] - ef[t] for t in T) <= FECHA: return ws

sg = plano()
print('rampa' if not SEM_RAMPA else 'sem rampa', '| sugerido nas planejadas (sem as fixas):', sum(sum(q) for t, o, f, q in sg if not f),
      '| oficial:', sum(sum(q) for t, o, f, q in OF if not f))
print('  ', ' '.join('%s:%d/%d/%d' % (t[5:], *q) for t, o, f, q in sg if not f))
print('   fecha oficial', fecha(OF), '| fecha sugerido', fecha(sg))
rows = []; sim(sg, date(2027, 7, 12) + td(91), rows)
print('   01/03/2027 sugerido quadro / rende:', [(round(h[t], 2), round(ef[t], 2)) for ws, h, ef in rows if ws == date(2027, 3, 1) for t in T])
rows = []; sim(OF, date(2027, 7, 12) + td(91), rows)
print('   01/03/2027 oficial  quadro / rende:', [(round(h[t], 2), round(ef[t], 2)) for ws, h, ef in rows if ws == date(2027, 3, 1) for t in T])
