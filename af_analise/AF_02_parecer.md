# 5C Planning — Parecer: Falhas Administrativas (AF) e `peso_falha_adm`

**Data:** 20/10/2026 · **Janela:** 25/08 a 19/10/2026 (56 dias fechados) · **Fonte:** `5c_wfm_db_mis.abs_adh_af`, `tab_message_interval`, `5c_schedule.scheduleday`, `sync_removed_rows`, `5c_planning.fc_buffer_diario` (só leitura, MCP `.11`) · **Resultados:** `af_analise/resultados/Q0…Q11.csv` (fora do git)

---

## Resumo

1. **A marcação de AF está errada na origem.** A `sp_abs_adh_af_processar` só reconhece a **hora de início** de cada slot de 4 h como "dentro da escala". Com isso, 3 de cada 4 horas trabalhadas no turno escalado contam como "fora". Somando o corte por dia de calendário e o limiar real de **2 h** (não 3 h), quase todo rep que faltou em parte do dia vira AF.
2. **Só 89 dos 1.441 slots AF (6,2%) são AF de verdade**, com ≥ 3 h de trabalho fora de qualquer turno escalado. O resto se divide assim: o rep **trabalhou no próprio slot** (28,6%), **escala dobrada** com o outro turno cumprido (33,9%), **falta parcial** (3,7%) e **falta do turno inteiro** (27,6%, quase toda no turno D).
3. **Peso recomendado por turno: A 0,22 · C 0,24 · D 0,54** (global equivalente 0,37), contra 0,53 hoje. O turno D é diferente porque ali a maior parte do "AF" é falta do turno inteiro.
4. **Impacto no Capacity:** absenteísmo A 10,42 → **9,18%** · C 12,00 → **10,44%** · D 11,02 → **11,10%**. A falta total cai de **22,0 para 19,3 pessoas**: C cai ~1,6, D fica igual e a sobra de A sobe ~1,1. O plano de turmas não muda de forma material.
5. **O critério da roteiro (página descoberta, Q6) não serve:** daria 0,22, mas 98% dos "descobertos" são slots sem página real na escala, e nas páginas reais outra pessoa cobriu em 99,5% dos casos. O critério antigo ("rep mandou mensagem", 47% → 0,53) misturava mensagem do dia inteiro com presença no slot.
6. **A correção definitiva é na procedure, e já está pronta em `AF_04_corrigir_procedure_af.sql`** (seção 12). Com ela, AF passa a ser só "trabalhou fora da escala" ou "cumpriu o outro turno escalado", e o peso vai a **0**. Absenteísmo do Capacity: **A 8,9% · C 9,9% · D 10,8%**, falta **18,1 pessoas**. O AF_04 substitui o AF_03: o AF_03 só se justifica como paliativo se a procedure não for trocada agora.

---

## 1. Base e ajustes feitos depois do Q0

| Item | Esperado no roteiro | Encontrado | Ajuste |
|---|---|---|---|
| Colunas de `abs_adh_af` | codigo, code_infloww, slot_inicio, dia, hora, situacao, classificacao, horas_fora_escala, administrative_failure | Todas existem | — |
| `dia` | dia do turno | **dia de calendário** (`DATE(slot_inicio)`) | Análise refeita por dia do turno (`DATE(slot − 5 h)`) |
| `hora` | hora do slot | só **1, 5, 9, 13, 17, 21** (início do slot) | Janela do slot = `[slot_inicio, +4 h)` |
| Limiar AF | 3 h | `v_min_horas_af = 2` | Sensibilidade com 2/3/4 h (seção 4) |
| Fuso | validar `v_ajuste_fuso` | `0`; escala e mensagens em Brasília (clockIn 20:58 no slot T6/T1; hora 00 = T6) | `@ajuste_h = 0` confirmado |
| Fonte de mensagens | `msgdashboard` | a procedure lê **`tab_message_interval`** (hora × rep × página) | Q5/Q6/Q6b usam a mesma fonte; ~300 mil linhas em vez de 37 mi, sem estourar 60 s |
| Meia hora | — | 50% das linhas de `tab_message_interval` vêm em :30 | Truncar para a hora cheia |
| Página do slot | via `vw_pagina` | `scheduleday.modeloNome = tab_message_interval.Creator` (122/133 páginas) | Casa direto; `vw_pagina` só para time (o `teamName` da escala vem vazio) |
| `vw_rep_estrutura_atual` | codigo/time/party/cargo | `rep_code`, `team`, `party`, `role` | Q4 ajustado |
| Onde o peso entra | `sp_fc_rodar_dia` | **`sp_fc_buffer`** (chamado pelo `sp_fc_rodar_dia`) | Seção 7 |

Todos os ajustes estão registrados no cabeçalho do `AF_01_diagnostico_somente_leitura.sql`.

---

## 2. Volume (Q1, Q2)

**Por turno, 56 dias**

| Turno | Escalados | ADH | NADH | ABS | AF | % ABS | % AF | Reps com AF |
|---|---|---|---|---|---|---|---|---|
| A (T2/T3) | 8.178 | 6.058 | 1.119 | 676 | 325 | 8,3% | 4,0% | 74 |
| C (T4/T5) | 8.457 | 6.147 | 1.087 | 765 | 458 | 9,0% | 5,4% | 79 |
| D (T6/T1) | 8.662 | 5.780 | 1.593 | 631 | 658 | 7,3% | 7,6% | 124 |
| **Total** | **25.297** | 17.985 | 3.799 | 2.072 | **1.441** | 8,2% | **5,7%** | — |

AF pesa mais no D: 658 slots, mais do que o próprio ABS do turno.

**Série semanal (segunda-feira)**

| Semana | Escalados | % ABS | % AF | % ABS + AF |
|---|---|---|---|---|
| 24/08* | 2.963 | 7,0 | 6,3 | 13,4 |
| 31/08 | 3.438 | 7,7 | 4,1 | 11,8 |
| 07/09 | 3.271 | 8,8 | 5,4 | 14,1 |
| 14/09 | 3.092 | 7,1 | 5,7 | 12,7 |
| 21/09 | 3.055 | 6,7 | 5,8 | 12,5 |
| 28/09 | 3.021 | 7,4 | 5,4 | 12,8 |
| 05/10 | 3.052 | **10,9** | **7,2** | **18,1** |
| 12/10 | 2.994 | 9,9 | 5,7 | 15,6 |
| 19/10* | 411 | 9,0 | 7,5 | 16,6 |

\* semana parcial. O ABS sobe de ~7–8% para ~10% em outubro. A AF fica estável em 5–6%, com pico em 05/10. O aumento recente é falta, não AF.

---

## 3. Por que a AF está errada na origem

Trecho da `sp_abs_adh_af_processar` (passo 3.5):

```sql
-- conta as horas com mensagem que NÃO estão na escala
AND NOT EXISTS (SELECT 1 FROM abs_adh_af h
                WHERE h.codigo = x.codigo AND h.dia = x.dia AND h.hora = x.hora)
```

| Defeito | Efeito | Exemplo real (13/10) |
|---|---|---|
| **D1. Só a hora de início do slot conta como escala** (`h.hora` vale 1/5/9/13/17/21) | Trabalhar o slot inteiro (4 h) gera 3 h "fora". Os picos de `horas_fora_escala` em 2, 4 e 6, com quase nada em 3 e 5, são a assinatura desse defeito (Q3) | B456106 faltou o T1 e trabalhou o T6 escalado (21–23 h). A procedure contou 22 h e 23 h como fora e marcou **AF** |
| **D2. Dia de calendário** (`DATE(dt) = a.dia`) | A hora 00 do T6 cai no dia seguinte, e o T1 de madrugada pertence ao turno da noite anterior. O rep que trabalhou a madrugada e faltou a noite inteira vira AF | 312 slots D "AF" são falta do turno inteiro (seção 5) |
| **D3. Limiar de 2 h**, não 3 h | Junto com D1, basta trabalhar meio slot escalado | — |
| **D4. Placeholder entra como escala** (sem `modeloId <> 3`) | 339 slots AF (24%) só têm a linha "5C Schedule", sem página real | — |
| **D5. `faltou = 1` em slot trabalhado** (vem da escala, não da procedure) | 412 slots AF com, em média, **3,2 de 4 h** de mensagens do próprio rep | C051206 mandou mensagem dentro do próprio slot AF |

O mesmo cálculo afeta o relatório da diretoria, que **não conta AF como falta**. Com a AF corrigida, o absenteísmo do D na diretoria passa de 7,3% para algo perto de 11% (ABS + falta do turno inteiro + falta parcial). Isso vale registrar antes da próxima apresentação.

---

## 4. Sensibilidade do limiar (Q3)

| Limiar | AF pela procedure (com o defeito D1) | AF com horas corrigidas |
|---|---|---|
| 2 h (vigente) | 1.441 | 129 |
| **3 h** (documentado) | 1.214 | **89** |
| 4 h | 1.115 | 79 |

- **O limiar não é frágil; o cálculo das horas é.** Com as horas corrigidas, passar de 3 h para 4 h tira só 10 slots, porque a maioria das AF reais tem ≥ 4 h fora: o rep trabalhou um bloco inteiro fora da escala.
- Recomendação: voltar o limiar para **3 h** (o que está documentado) **junto com** a correção de D1/D2. Mudar só o limiar não resolve nada: com D1, 1.214 slots continuariam como AF.

---

## 5. O que é, de fato, um slot AF (Q5, Q6, Q7, Q10)

**Partição dos 1.441 slots AF** (categorias exclusivas, nesta ordem de prioridade):

| # | Categoria | A | C | D | Total | % | Leitura |
|---|---|---|---|---|---|---|---|
| 1 | **AF real**: ≥ 3 h fora de qualquer janela escalada | 19 | 35 | 35 | **89** | 6,2% | O rep trabalhou, mas em horário que a escala não tinha |
| 2 | **Trabalhou no próprio slot** (3,2 h/4 em média) | 90 | 151 | 171 | **412** | 28,6% | `faltou = 1` indevido. Em 225 slots ele estava em **outra página** |
| 3 | **Escala dobrada**: escalado em 2 turnos no dia, cumpriu um | 162 | 200 | 127 | **489** | 33,9% | Turno extra/secundário não cumprido. 208 vêm com `shiftSecundaria = 1` e 182 só com placeholder |
| 4 | **Falta parcial** no próprio turno | 11 | 29 | 13 | **53** | 3,7% | ABS |
| 5 | **Falta do turno inteiro** | 43 | 43 | 312 | **398** | 27,6% | ABS (efeito D2 no turno D) |

**Q5 — padrões mais frequentes (rep-dias do turno com AF)**

| Escalado | Faltou | Trabalhou | Rep-dias | Categoria |
|---|---|---|---|---|
| T6T1 | T6T1 | (sem mensagem) | 222 | Falta do turno D inteiro |
| T2T3 + T4T5 | T4T5 | T2T3 | 53 | Dobra A+C |
| T4T5 + T6T1 | T4T5 | T6T1 | 53 | Dobra C+D |
| T6T1 | T6T1 | T6T1 | 52 | Trabalhou e consta falta |
| T2T3 + T6T1 | T6T1 | T2T3 | 49 | Dobra A+D |
| T2T3 + T4T5 | T2T3 | T4T5 | 40 | Dobra A+C |

### Hipóteses

| Hipótese | Evidência | Peso no AF | Conclusão |
|---|---|---|---|
| **H1** Turno fixo cadastrado errado | AF real é pouca (89) e pouco persistente: o máximo é 4 dias em 56 (J019571, A098851). O padrão sistemático é outro: **escala dobrada recorrente** no mesmo rep (A042393 com 24 slots em 21 dias, F373986 com 20, A178283 com 18, A177909 com 17) | 34% (dobra) + parte dos 6% | **Confirmada como "turno secundário fixo que o rep não cumpre"**, não como turno principal errado. Corrigir na escala |
| **H2** Realocação pelo RTA não registrada | 225 slots com o rep trabalhando **outra página** no próprio slot. Concentra no Brat (Matty Caron, Mo Saffari, Blake, Sean Adams, Jay) e em Cherie Deville e Levi | ~16% | **Confirmada.** O rep estava lá; a página escalada foi coberta por outra pessoa |
| **H3** Mudança de escala tardia | Alteração < 24 h: AF 0,8% contra ADH 1,1% (`updated_at`). `imported_at` mede o ETL, não a publicação. Turnos removidos no rep-dia: AF 21,6% contra ADH 17,0% e ABS 15,6% (dados só desde 03/10) | sinal fraco (+5 pp) | **Não sustentada** com os dados atuais. Não existe log de publicação da escala |
| **H4** Rep em horário próprio | AF real sem dobra e sem trabalho no slot: ~77 slots | ~5% | **Pequena.** Tratar caso a caso (lista na seção 8) |

**Q6 — página descoberta (o critério da roteiro)**

| Turno | Slots AF | Rep mandou msg no slot | Outra pessoa cobriu a página | "Descoberto" | Peso Q6 | Dos descobertos, sem página real |
|---|---|---|---|---|---|---|
| A | 325 | 92 | 256 | 60 | 0,185 | 60 |
| C | 458 | 157 | 279 | 148 | 0,323 | 143 |
| D | 658 | 175 | 532 | 104 | 0,158 | 103 |
| **Total** | **1.441** | **424** | **1.067** | **312** | **0,217** | **306** |

Nas 1.102 escalas AF com página real, só **6** ficaram sem ninguém. As páginas são divididas por vários reps, então "outra pessoa mandou mensagem" quase sempre é verdade, com ou sem falta. Além disso, para o Capacity, a falta coberta por outro rep consumiu capacidade de outra pessoa, e é justamente isso que o absenteísmo precisa prever. **O Q6 mede compartilhamento de página, não ausência.** Fica registrado e descartado como base do peso.

---

## 6. Peso recomendado

**Regra:** o peso é a fração de horas de rep perdidas no slot AF, em relação à oferta base do 3x1, que é o que o Capacity conta como disponível.

| Categoria | Peso | Por quê |
|---|---|---|
| 1 AF real | 0 | O rep trabalhou as horas, só que fora da escala |
| 2 Trabalhou no slot | 1 − horas/4 (média 0,20) | Só as horas sem mensagem são perdidas |
| 3 Escala dobrada | **0** | É turno extra, fora da oferta 3x1: o rep não conta como disponível nesse turno no Capacity. Contar a falta do extra infla a necessidade de quem é do turno |
| 4 Falta parcial | 1 | Falta de fato |
| 5 Falta do turno inteiro | 1 | Falta de fato |

**Resultado (Q10b)**

| Critério | A | C | D | Global |
|---|---|---|---|---|
| Vigente (47% "tinham mensagem") | 0,53 | 0,53 | 0,53 | 0,53 |
| Q6, página descoberta (descartado) | 0,19 | 0,32 | 0,16 | 0,22 |
| Rep mandou mensagem **no próprio slot** (29,4%) | — | — | — | 0,71 |
| Conservador (dobra = falta) | 0,72 | 0,68 | 0,73 | 0,71 |
| **Recomendado (dobra = 0)** | **0,22** | **0,24** | **0,54** | **0,37** |

- **Por que por turno:** o D tem 2,3× o peso de A e C, porque ali o "AF" é sobretudo falta do turno inteiro (efeito D2). Um peso único de 0,37 subestimaria o D em ~1,3 pp e superestimaria A e C. A mudança no `sp_fc_buffer` é pequena (seção 7).
- **Comparação com o critério antigo:** os 47% "com mensagem" juntavam mensagem em qualquer hora do dia com presença no slot. O rep que faltou metade do turno e trabalhou a outra metade entrava como "presente". Medido dentro do slot, são 29,4%.
- **Cenário conservador (0,72 / 0,68 / 0,73):** só vale se a gestão decidir que turno secundário é oferta firme, com o rep obrigado a cumprir. Hoje, 489 slots extras não cumpridos em 56 dias indicam o contrário.

---

## 7. Impacto no Capacity

O `sp_fc_buffer` calcula `taxa = (ABS + AF × peso) ÷ slots`, por turno e dia da semana (56 dias), e `buffer = teto(escala × r ÷ (1 − r))`. O delta do peso foi aplicado sobre a base gravada em `fc_buffer_diario` de 19/10. Falta em pessoas = `(reps/h necessários ÷ (1 − abs) − reps/h disponíveis) ÷ 0,75`. Essa fórmula reproduz os 22 do Capacity.

| Cenário | Abs A | Abs C | Abs D | Reps/h c/ abs A · C · D | Falta A · C · D (c/ buffer deslig.) | **Falta total** |
|---|---|---|---|---|---|---|
| Base 19/10 (0,53) | 10,42% | 12,00% | 11,02% | 63,6 · 69,9 · 67,4 | −3,4 · 16,3 · 9,0 | **22,0** |
| **Recomendado (0,22 / 0,24 / 0,54)** | **9,18%** | **10,44%** | **11,10%** | 62,8 · 68,7 · 67,5 | −4,5 · **14,7** · 9,1 | **19,3** |
| Global único 0,37 | 9,78% | 11,14% | 9,82% | 63,2 · 69,2 · 66,5 | −4,0 · 15,4 · 7,8 | 19,3 |
| Conservador (dobra = falta) | 11,18% | 12,81% | 12,52% | 64,2 · 70,5 · 68,6 | −2,6 · 17,2 · 10,6 | 25,1 |
| Só ABS (critério diretoria) | 8,30% | 9,14% | 7,03% | 62,2 · 67,7 · 64,5 | −5,3 · 13,4 · 5,2 | 13,3 |
| AF = ABS (peso 1) | 12,30% | 14,53% | 14,55% | 65,0 · 72,0 · 70,2 | −1,5 · 19,1 · 12,8 | 30,3 |

Reps/h necessários sem absenteísmo (A 57,0 · C 61,5 · D 60,0) e disponíveis (69,0 · 62,6 · 65,0) mantidos. Buffer de desligamento (3,8 · 6,6 · 5,8) não muda com o peso.

**Leitura para o plano de turmas:** a falta cai ~3 pessoas, quase toda no C (−1,6), com A sobrando mais (+1,1). O pedido de 02/11 (C 9 · D 6) continua válido. Nas turmas recomendadas, dá para tirar **1 a 2 vagas de C** (sugestão: 05/01) ou mantê-las como folga, já que o ABS de outubro está subindo (seção 2). O remanejo A → C/D continua fazendo sentido: A passa a sobrar ~4,5.

---

## 8. Reps com AF recorrente (Q4, Q4b, Q9) e ação por caso

Critério da ação: **dobra ≥ 10** → corrigir escala (remover ou confirmar o turno secundário) · **trabalhou no slot ≥ 5** → corrigir `faltou` / registrar realocação · **AF real ≥ 3** → corrigir turno fixo ou tratar como ABS · **falta de fato ≥ 8** → tratar como ABS (conversa normal de absenteísmo).

| Código | Nome | Time / Party | Turno | AF | Real | Slot | Dobra | Falta | Ação |
|---|---|---|---|---|---|---|---|---|---|
| A042393 | Anderson Xavier Ribeiro | — (Secundus) | D | 40 | 1 | 9 | **24** | 6 | Corrigir escala: turno secundário fixo nunca cumprido (21 dias) |
| F373986 | Fernando Fabretti T. O. de B. | — | A | 30 | 4 | 2 | **20** | 4 | Corrigir escala (dobra) + conferir cadastro (sem time) |
| J019571 | Jan Roger Maia da Silva | Lux / Lux II | C | 27 | **8** | 5 | **14** | 0 | Corrigir turno fixo: trabalha fora da escala (4 dias) e não cumpre a dobra |
| A178283 | André Benetti Ventura | Aeterna / Aeterna I | C | 23 | 2 | 3 | **18** | 0 | Corrigir escala (dobra) |
| A177909 | Isabel da Silva Marinho | Brat / Brat II | D | 25 | 0 | 1 | **17** | 7 | Corrigir escala (dobra); ABS alto (15%) |
| T102316 | Társila Gomes G. de Freitas | Cavalry / Cavalry I | A | 24 | 0 | 6 | **16** | 2 | Corrigir escala (dobra) |
| N108054 | Nátali Bianchini Vieira | Lux / Lux III | A | 20 | 0 | 3 | **15** | 2 | Corrigir escala (dobra) |
| J145594 | João Victor H. C. Beber | Aeterna / Aeterna I | D | 27 | 0 | 6 | **12** | **9** | Corrigir escala (dobra) + tratar faltas como ABS |
| L098436 | Lucas Fernandes Silva | Aeterna / Aeterna I | C | 17 | 1 | 4 | **12** | 0 | Corrigir escala (dobra) |
| G101263 | Gabriela Roberta Winiarski | Nalu Maris / I | A | 15 | 0 | 3 | **12** | 0 | Corrigir escala (dobra); fora do RH (pendência do relatório) |
| P176228 | Penellopy L. S. de O. Rosário | Ippon / Ippon I | D | 15 | 0 | 0 | **12** | 3 | Corrigir escala (dobra) |
| Y135044 | Yuri de Souza O. Bonfim | Ippon / Ippon I (GP) | A | 19 | 1 | 5 | **11** | 2 | Corrigir escala (dobra); ABS 34% |
| F417564 | Felister Mwende Mwongela | — | D | 17 | 0 | 0 | **11** | 6 | Corrigir escala (dobra) + cadastro sem time |
| B074550 | Brenda Emilia da C. Lisboa | — (Tertius) | A | 14 | 2 | 0 | **11** | 1 | Corrigir escala (dobra); ABS 20% |
| R471964 | Renalyn Olivar | Aruanãs / Aruanãs I | C | 13 | 0 | 0 | **10** | 3 | Corrigir escala (dobra); ABS 24% |
| C099427 | Carolina Xavier Broggio | Cavalry / Cavalry I | C | 12 | 2 | 0 | **10** | 0 | Corrigir escala (dobra) |
| M172528 | Matheus da Silva Martins | Brat / Brat I | D | 12 | 0 | **12** | 0 | 0 | Corrigir `faltou`: trabalhou todos os 12 slots marcados como falta |
| T143032 | Thiago de Meneses Arantes | Aeterna / Aeterna III | C | 15 | 0 | **8** | 2 | 5 | Registrar realocação / corrigir `faltou` |
| G022816 | Georgia Rosa Monteblanco | Cavalry / Cavalry I | D | 20 | 0 | **7** | 9 | 4 | Registrar realocação + rever dobra |
| L007270 | Lucas Fabião Giusti | — | C | 11 | 0 | **7** | 3 | 1 | Registrar realocação; cadastro sem time |
| S349873 | Sheila Akinyi Owino | Brat / Brat I | D | 10 | 0 | **7** | 0 | 3 | Corrigir `faltou` / realocação |
| M497745 | Marina Ferreira da Silva | Cavalry / Cavalry IV | A | 10 | 0 | **6** | 4 | 0 | Registrar realocação |
| A142232 | Alex Emanuel M. de Souza | Nova / Nova II (KP) | D | 17 | 0 | 7 | 0 | **10** | Tratar como ABS (15 dias com AF) |
| D154823 | Daniel Fratane Amado | Cavalry / Cavalry II (KP) | D | 13 | **4** | 4 | 0 | 5 | AF real: confirmar horário de fato e corrigir turno fixo |
| A098851 | Arthur Novais Moraes | — (Secundus) | A | 12 | **4** | 0 | 3 | 5 | AF real em 4 dias + ABS 35%: tratar como ABS / rever turno |
| I131318 | Ingrid Marcia Bessa | Lux / Lux I | C | 11 | **4** | 3 | 4 | 0 | AF real: confirmar horário e corrigir escala |
| B065640 | Beatriz Silva Santos | Nalu Maris / I | D | 9 | **4** | 2 | 1 | 2 | AF real: confirmar horário |
| J058241 | Julian Grasiele Gonçalves | Ippon / Ippon I | A | 16 | **3** | 4 | 6 | 3 | AF real + ABS 20%: conversa de escala |
| P094137 | Pedro Ribeiro da Silva Neto | Vortex / Vortex I (GP) | D | 13 | 0 | 2 | 0 | **11** | Tratar como ABS; ABS 34% |
| W016277 | Willian Nascimento Rocha | Gladius / Gladius I | D | 13 | 0 | 1 | 2 | **10** | Tratar como ABS |
| G115750 | Guilherme de Lucca Vicente | Ravenus / Ravenus I (GP) | D | 11 | 0 | 1 | 0 | **10** | Tratar como ABS |
| J132213 | José Diogo M. D. C. Martins | Vanguard / Vanguard I | A | 21 | 0 | 5 | 7 | **9** | Tratar como ABS + rever dobra |
| B456106 | Bárbara Viturina dos Santos | Nalu Maris / I | D | 9 | 0 | 0 | 0 | **9** | Tratar como ABS |
| J070909 | João Paulo G. Martins | Cavalry / Cavalry I (GP) | D | 18 | 2 | 5 | 3 | **8** | Tratar como ABS (5 dias seguidos 14–18/10) |
| P628774 | Pedro Emanuel Sena Fernandes | Ravenus / Ravenus I | D | 9 | 0 | 1 | 0 | **8** | Tratar como ABS |

Lista completa (61 reps com ≥ 9 AF ou ≥ 3 AF reais) em `resultados/Q4.csv` e `resultados/Q4b_rep_categorias.csv`. Persistência de 3+ dias seguidos em `resultados/Q9.csv`: 15 sequências de 4–5 dias, e nenhuma delas é AF real contínua.

---

## 9. Páginas e times com AF recorrente (Q8)

| Time | Páginas | Slots AF | Padrão dominante | Ação |
|---|---|---|---|---|
| (placeholder "5C Schedule") | — | 339 | Escala sem página | Proibir publicar escala só com placeholder para quem está escalado em página; ou excluir `modeloId = 3` da procedure |
| **Brat** | Matty Caron (70), Mo Saffari (56), Blake (52), Sean Adams (43), Jay (43), Krystal 141m/t (35) | ~300 | **Trabalhou no slot** (30–45%) e dobra | Registrar realocação entre as páginas do time (pedido na Nora). Rever a escala secundária |
| (sem time) Skylar Mae VIP | — | 53 | **Dobra** (35 de 53) | Os BKP da Skylar (Sons of Sexting, Vanguard, Cavalry) estão escalados como turno extra e não cumprem. Confirmar o modelo de BKP ou tirar da escala |
| Aeterna | Jewelz Blu 291m/s (43/42), Mrs Robinson 303m (36) | ~120 | Dobra (Jewelz) / trabalhou no slot (Mrs Robinson) | Rever dobra; registrar realocação |
| Lux | Levi Paid/Free (41/41) | 82 | Trabalhou no slot (19) e AF real (6) | Registrar realocação; confirmar horário |
| Prevail + sem time | Cherie Deville 257m/s/t (36 cada) | 108 | Trabalhou no slot (21) | Registrar realocação; Cherie também está na lista SLA 80% (pendência) |
| Vanguard | Blair VIP 155m (34), Madison Moore 115m/s (31) | ~95 | Dobra (Blair 19) | Rever dobra |
| Cavalry | Molly Stewart VIP (42), Midwest Emma 221s (30) | 72 | Dobra / AF real (Emma 6) | Rever dobra; confirmar horário |
| Nalu Maris / sem time | Bianca (30), Alexis Texas (30) | 60 | Dobra (17 cada) | Rever dobra; ambas na lista SLA 80% |

---

## 10. Ações de cadastro e escala

| # | Ação | Dono sugerido | Efeito |
|---|---|---|---|
| 1 | **Rodar `AF_04_corrigir_procedure_af.sql`**: procedure corrigida (D1–D3, dobra, presença), reprocessamento do histórico e peso → 0 | Luis (Navicat) | Ver seção 12. Substitui os itens 2 e 3 |
| 2 | ~~Rodar `AF_03_atualizar_peso.sql`~~: só como paliativo, se o AF_04 ficar para depois | Luis (Navicat) | Pesos 0,22 / 0,24 / 0,54 até a procedure ser trocada |
| 3 | Depois do AF_04: **acompanhar 2 semanas** a distribuição por `motivo_classificacao` e o caso a caso com o RTA | MIS | Confirma a regra antes de mexer em turmas |
| 4 | **Escala dobrada:** revisar os 16 reps com dobra ≥ 10 e a escala de BKP da Skylar; definir se turno secundário é obrigatório | Planejamento / WFM | ~490 slots/8 semanas deixam de virar "falta" |
| 5 | **`faltou` indevido / realocação:** RTA registra a troca de página (pedido na Nora) em vez de marcar falta; foco no Brat, Cherie Deville e Levi | RTA / TLs | ~410 slots/8 semanas deixam de virar "falta" |
| 6 | **Placeholder "5C Schedule"** em rep escalado: limpar na publicação da escala. **Não** excluir na procedure: é escala real de buffers e reps sem página fixa (~1.075 slots em 28 dias, metade ADH) | Planejamento | 339 slots AF sem página |
| 7 | **Log de publicação da escala** (quem/quando alterou) para poder medir H3 | MIS / Schedule | Hoje `updated_at` e `imported_at` só medem o ETL |

---

## 11. O que fica em aberto

- **Escala dobrada é oferta firme ou extra opcional?** A recomendação (peso 0) supõe extra. Se a gestão decidir que é obrigatória, usar o cenário conservador (0,72 / 0,68 / 0,73 → falta 25,1).
- **H3 sem medida:** não há registro de quando a escala foi publicada ou alterada. `sync_removed_rows` só tem dados desde 03/10 e mostra sinal fraco (+5 pp).
- **Base do Capacity × réplica:** a réplica com 0,53 dá 10,34 / 11,86 / 11,32, contra 10,42 / 12,00 / 11,02 gravados em 19/10. A diferença vem de rep-dias reprocessados pela fila depois do cálculo. O impacto foi aplicado como delta sobre a base gravada.
- **Páginas sem mensagens no banco** (MYM/Fanvue: Ivy Vale, Bonnie Locket, Marine…): presença no slot não é medível. Hoje entram como falta.
- **Validação manual:** conferir 10 casos da lista da seção 8 com o RTA antes de mexer na escala (principalmente M172528, A042393 e J019571).
- **Relatório da diretoria:** decidir se a correção da procedure (AF_04) entra antes ou depois da próxima apresentação, porque ela muda o % de absenteísmo exibido (seção 12).

---

## 12. Correção da origem: AF v2 (`AF_04_corrigir_procedure_af.sql`)

**Regra nova da `sp_abs_adh_af_processar`**, aplicada a todo slot com `situacao = ABS`, por **dia do turno** (05:00 a 05:00). Hora "dentro da escala" = qualquer hora da janela `[slot, slot + 4 h)` de qualquer slot escalado do rep.

| Prioridade | Condição | `classificacao` | `motivo_classificacao` | Peso no Capacity |
|---|---|---|---|---|
| 1 | ≥ 2 h de mensagem do próprio rep dentro do slot | NADH | PRESENTE | 0 (rep estava lá; `faltou` indevido) |
| 2 | ≥ 3 h de mensagem fora de qualquer janela escalada | AF | AF_FORA | 0 |
| 3 | ≥ 2 h trabalhadas em slot escalado de **outro turno** (A/C/D) | AF | AF_DOBRA | 0 |
| 4 | Nenhuma das anteriores | ABS | — | 1 |

`situacao` continua vindo da escala (ABS). A coluna `motivo_classificacao` é nova. O placeholder "5C Schedule" continua contando como escala. Limiares parametrizados no topo da procedure (`v_min_horas_af = 3`, `v_min_horas_dobra = 2`, `v_min_horas_presenca = 2`). Mensagens truncadas na hora cheia e sem "AI …".

**Simulação (25/08–19/10, só leitura, `resultados/Q12_simulacao_af_v2.csv`)**

| Hoje → AF v2 | A | C | D | Total |
|---|---|---|---|---|
| AF → AF (dobra) | 170 | 229 | 141 | 540 |
| AF → AF (fora) | 18 | 31 | 32 | 81 |
| AF → NADH (presente) | 76 | 113 | 146 | 335 |
| AF → ABS | 61 | 85 | **339** | 485 |
| ABS → AF (dobra/fora) | 4 | 8 | 32 | 44 |
| ABS → NADH (presente) | 2 | 3 | 2 | 7 |
| ABS → ABS | 670 | 754 | 597 | 2.021 |

Totais em 56 dias: **AF 1.441 → ~665**, **ABS 2.072 → ~2.506**, NADH +~342.

**Efeito**

| | A | C | D | Falta total |
|---|---|---|---|---|
| Capacity, absenteísmo (peso 0) | **8,89%** | **9,86%** | **10,77%** | — |
| Reps/h com absenteísmo | 62,6 | 68,2 | 67,2 | — |
| Falta com buffer de desligamento | −4,8 | 14,1 | 8,8 | **18,1** |
| Diretoria, % ABS (AF e NADH fora) | 8,3% → ~8,9% | 9,0% → ~9,9% | 7,3% → **~10,8%** | — |

Comparado com o AF_03 (19,3), a falta cai mais 1,2 pessoa, porque a presença no slot passa a valer 0 em vez de ~0,2. O D sobe de verdade na diretoria: as faltas da noite inteira deixam de ser escondidas como AF.

**Execução:** rodar o AF_04 bloco a bloco. Ele faz backup da tabela inteira e do corpo da procedure, adiciona a coluna, troca a procedure com o evento pausado, reenfileira ~62 mil rep-dias em ordem cronológica, processa (1 lote manual e depois o evento de 5 min, ~1 h), confere (distribuição, antes × depois e 3 casos do 13/10), zera os pesos e recalcula o Capacity. O DESFAZER traz a procedure original completa, a volta dos dados pelo backup e a volta dos pesos.

**Limites da regra:** AF_DOBRA não diz qual dos dois turnos era o extra; trata os dois como cumprimento de escala. Páginas sem mensagem no banco (MYM/Fanvue) não têm como provar presença; a falta fica ABS.
