# Espelho do sp_fc_plano_sugerido (scripts 19 e 21), com os dados de 30/10.
# Regras do 21: teto de 10 por turno e 30 por turma; troca de 5 reps do C para o A (data em TROCAS).
# Uso: python -I -X utf8 plano_sugerido_espelho.py
import sys, math
from datetime import date, timedelta as td
T='ACD'
TX={'A':0.0089,'C':0.0179,'D':0.0186}; N14,N513=0.03,0.0122; DES=0.06; TETO=30; TETO_TURNO=10; FECHA=5  # SUM/SUM no MySQL = DECIMAL com 4 casas (taxas de 30/10)
H0={'A':76,'C':69,'D':75}
TROCAS=[(date(2026,10,27),'C','A',5)]   # (data, de, para, qtd) — desligada no script 22: deixe [] para conferir o plano atual
OF=[('2026-11-02','2026-11-16',1,(4,7,4)),('2026-11-16','2026-11-30',1,(5,9,6))]+[(t,o,0,q) for t,o,q in [
('2026-12-07','2026-12-21',(6,7,7)),('2027-01-11','2027-01-25',(6,7,7)),('2027-01-25','2027-02-08',(6,7,7)),('2027-02-08','2027-02-22',(6,7,7)),
('2027-02-22','2027-03-08',(6,8,6)),('2027-03-08','2027-03-22',(5,8,7)),('2027-03-22','2027-04-05',(5,8,7)),('2027-04-05','2027-04-19',(5,8,7)),
('2027-04-19','2027-05-03',(6,7,7)),('2027-05-03','2027-05-17',(5,8,7)),('2027-05-17','2027-05-31',(5,8,7)),('2027-05-31','2027-06-14',(5,8,7)),
('2027-06-14','2027-06-28',(5,8,7)),('2027-06-28','2027-07-12',(5,8,7))]]
D=lambda s: date.fromisoformat(s)
def sim(turmas, ini, ate, rows=None):
    h=dict(H0); ws=ini
    for d,de,para,q in TROCAS:
        if d<ini: h[de]-=q; h[para]+=q
    while ws<ate:
        for d,de,para,q in TROCAS:
            if ws<=d<ws+td(7): h[de]-=q; h[para]+=q
        if rows is not None: rows.append((ws,dict(h)))
        e={t:0 for t in T}; x={t:0.0 for t in T}
        for _,op,_,q in turmas:
            op=D(op); q=dict(zip(T,q))
            if ws<=op<ws+td(7):
                for t in T: e[t]+=q[t]
            if op<ws:
                sem=(ws-op).days//7+1
                if 1<=sem<=13:
                    for t in T: x[t]+=q[t]*max((N14 if sem<=4 else N513)-TX[t],0)
        for t in T: h[t]=max(h[t]-(h[t]*TX[t]+x[t])+e[t],0)
        ws+=td(7)
    return h
def plano(alvo, pdata):
    ini=pdata-td(pdata.weekday())
    sg=[(t,o,f,q if (f or D(t)<pdata) else (0,0,0)) for t,o,f,q in OF]
    plan=[i for i,(t,o,f,q) in enumerate(OF) if not f and D(t)>=pdata]
    for k,i in enumerate(plan):
        t,o,f,_=OF[i]
        nxt=(D(OF[plan[k+1]][1]) if k+1<len(plan) else D(o)+td(14))
        h=sim(sg,ini,nxt)
        need={u:min(max(0.0,(alvo[u]-h[u])/(1-DES)),TETO_TURNO) for u in T}; tot=sum(need.values())
        if tot>TETO:
            raw={u:TETO*need[u]/tot for u in T}; q={u:math.floor(raw[u]) for u in T}
            for u in sorted(T,key=lambda u:-(raw[u]-q[u]))[:TETO-sum(q.values())]: q[u]+=1
        else:
            q={u:min(math.floor(need[u]+0.5),TETO_TURNO) for u in T}
        sg[i]=(t,o,f,(q['A'],q['C'],q['D']))
    return ini,sg
def fecha(turmas, ini, alvo):
    rows=[]; sim(turmas,ini,date(2027,12,31),rows)
    for ws,h in rows:
        if sum(alvo[t]-h[t] for t in T)<=FECHA: return ws
for nome,alvo in [('291 (versão 4)',{'A':94,'C':100,'D':97}),('293 (versão 5)',{'A':94,'C':100,'D':99})]:
    ini,sg=plano(alvo,date(2026,10,28))
    tot_of=sum(sum(q) for t,o,f,q in OF if not f); tot_sg=sum(sum(q) for t,o,f,q in sg if not f)
    print('== alvo',nome,'| planejadas oficial',tot_of,'sugerido',tot_sg,'| fecha oficial',fecha(OF,ini,alvo),'sugerido',fecha(sg,ini,alvo))
    print('   ',' '.join('%s:%d/%d/%d'%(t[5:],*q) for t,o,f,q in sg if not f))
    rows=[]; sim(sg,ini,date(2027,7,12)+td(91),rows)
    print('    01/03/2027 hc sugerido', [(round(h['A'],2),round(h['C'],2),round(h['D'],2)) for ws,h in rows if ws==date(2027,3,1)])
    rows=[]; sim(OF,ini,date(2027,7,12)+td(91),rows)
    print('    semana 2027-03-01 hc oficial', [ (round(h['A'],2),round(h['C'],2),round(h['D'],2)) for ws,h in rows if ws==date(2027,3,1)], 'ultima', rows[-1][0])
