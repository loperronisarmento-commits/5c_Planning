# Plano de turmas para o quadro da estrutura de 01/11 (dois cenários).
# Mesmas regras do sim.py de 26/10, com:
#   - foto de 27/10: 220 reps (A 76 · C 69 · D 75);
#   - alvo por turno: CAPACITY = titulares (postos × 1,5) + buffer do turno (faltas, falha adm. e pico; 100 / 111 / 104 = 315)
#                     QUADRO   = 12 reps por OS (3 OS × 3 unidades × 12 = 108 por turno = 324);
#   - saídas = 6,4% do quadro ATUAL por mês (cresce com o quadro), peso A 14/59 · C 24/59 · D 21/59;
#   - novatos: 12% saem em 30 dias, mais 11% até 90 dias; treinamento de 14 dias; teto de 20 por turma;
#   - turmas de 02/11 (C9 D6) e 16/11 (A5 C9 D6) já pedidas; depois 07/12, 11/01/2027 e a cada 14 dias.
import json, sys
from datetime import date, timedelta as td
T = 'ACD'
SHARE = {'A': 14 / 59, 'C': 24 / 59, 'D': 21 / 59}
INI = date(2026, 10, 27); H0 = {'A': 76, 'C': 69, 'D': 75}
ALVOS = {'capacity': {'A': 100, 'C': 111, 'D': 104}, 'quadro': {'A': 108, 'C': 108, 'D': 108}}
FIXAS = [(date(2026, 11, 2), {'A': 0, 'C': 9, 'D': 6}), (date(2026, 11, 16), {'A': 5, 'C': 9, 'D': 6})]
DATAS = [date(2026, 12, 7)] + [date(2027, 1, 11) + td(14 * i) for i in range(13)]   # até 21/06/2027
TETO = 20

def sim(cl, ate):
    h = dict(H0); d = INI
    while d < ate:
        tot = sum(h.values())
        for t in T: h[t] -= tot * 0.064 / 30.4 * SHARE[t]
        for cd, q in cl:
            idade = (d - (cd + td(14))).days
            if idade == 0:
                for t in T: h[t] += q[t]
            if 0 <= idade < 30:
                for t in T: h[t] -= q[t] * 0.12 / 30
            elif 30 <= idade < 90:
                for t in T: h[t] -= q[t] * 0.11 / 60
        d += td(1)
    return h

def plano(alvo):
    cl = list(FIXAS); res = []
    for i, c in enumerate(DATAS):
        nxt = (DATAS[i + 1] if i + 1 < len(DATAS) else c + td(14)) + td(14)
        h = sim(cl, nxt)
        need = {t: max(0.0, (alvo[t] - h[t]) / 0.94) for t in T}
        tot = sum(need.values())
        if tot > TETO:
            raw = {t: TETO * need[t] / tot for t in T}; q = {t: int(raw[t]) for t in T}
            for t in sorted(T, key=lambda t: -(raw[t] - q[t]))[:TETO - sum(q.values())]: q[t] += 1
        else:
            q = {t: round(need[t]) for t in T}
        cl.append((c, q)); res.append((c, q))
    return cl, res

saida = {}
for nome, alvo in ALVOS.items():
    cl, res = plano(alvo)
    marcos = [date(2026, 11, 16), date(2026, 11, 30), date(2026, 12, 21), date(2026, 12, 31), date(2027, 1, 24),
              date(2027, 2, 7), date(2027, 2, 21), date(2027, 3, 8), date(2027, 3, 31), date(2027, 4, 30),
              date(2027, 5, 31), date(2027, 6, 30)]
    proj = []
    for m in marcos:
        h = sim(cl, m)
        proj.append({'data': m.isoformat(), 'falta': {t: round(alvo[t] - h[t], 1) for t in T},
                     'total': round(sum(alvo[t] - h[t] for t in T), 1), 'hc': round(sum(h.values()), 1)})
    fecha = None
    d = INI
    while d <= date(2027, 6, 30):
        if sum(alvo[t] - sim(cl, d)[t] for t in T) <= 5:
            fecha = d.isoformat(); break
        d += td(7)
    turmas = [{'turma': c.isoformat(), 'opera': (c + td(14)).isoformat(), **q, 'total': sum(q.values()),
               'status': 'Pedido feito' if c <= date(2026, 11, 16) else 'Recomendado'} for c, q in cl]
    saida[nome] = {'alvo': alvo, 'turmas': turmas, 'proj': proj, 'fecha_ate_5': fecha,
                   'contratar_ate_mar': sum(x['total'] for x in turmas if x['turma'] <= '2027-03-08'),
                   'contratar_total': sum(x['total'] for x in turmas)}
    print('==', nome, 'alvo', sum(alvo.values()), '| contratações até 08/03:', saida[nome]['contratar_ate_mar'],
          '| até 21/06:', saida[nome]['contratar_total'], '| falta ≤ 5 em', fecha)
    for x in turmas: print('  ', x['turma'], x['A'], x['C'], x['D'], '=', x['total'], x['status'])
    for p in proj: print('   falta', p['data'], p['falta'], p['total'], 'HC', p['hc'])
json.dump(saida, open(sys.argv[1], 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
