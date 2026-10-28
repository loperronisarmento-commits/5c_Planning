# 5C Escalas · Planning tool — especificação (rascunho v4, 27/10/2026)

Sistema de planejamento de escalas no MIS Portal (PHP, servidor `.11`). O 5C Escalas fica dividido em 3 seções, cada uma tratada à parte:
1. **Capacity:** quantas pessoas cada unidade e turno precisa (postos por página).
2. **Planning tool** (este documento): monta, ajusta e publica a escala de quem trabalha.
3. **Simulations:** cenários sem mexer no oficial.

O Planning tool incorpora três módulos que existem hoje: **Escala de Buffers**, **Schedule Anomalies** e **Schedule Access Audit**.

**O que mudou da v3 para a v4:** a estrutura de **01/11/2026** substitui Guild → Time → Party, Tertius e o 3x1. Os detalhes estão no pacote de 27/10: `estrutura_unidades/doc_estrutura.md`.

| Era (v3, até 31/10) | Passa a ser (v4, a partir de 02/11) |
|---|---|
| Team → Party → membros, do Planner | Unidade → carteira (C1/C2) → postos → reps |
| Primaris, Secundus, Tertius (cargos) | Níveis 0 a 5 pelo % de hoje; liderança OUM, OM, OS |
| Necessidade por página e bloco de 4 h (`fc_escala_pagina`) | Postos por página e turno (posts.py), × 1,5 (rotação de 21 dias) + 15% de buffer |
| Escala 3x1, Primaris 3x1 + admin | 5x2 rotativo em ciclo de 21 dias (5x2, 5x2, 4x3), por núcleo (Decurio + Equites) |
| Ordem: party dona → time → Tertius → buffer | Equipe fixa da vaga (dono ou cobertura de outro núcleo) → buffer do núcleo |
| Mérito dos Tertius (% regular por quadrante) | % de turno regular por nível (níveis 0 e 1 em rodízio) |

**O que não muda:**
- indisponibilidade e Nora;
- CSV do Schedule Ajustment;
- releitura do realizado;
- recálculo com De→Para;
- anomalias, auditoria de acesso e acessos;
- regras de escala da Política de Escalas v1.0.

O rascunho clicável (https://claude.ai/artifact/BJDKQYLPz119juqs4hcBP8, fonte em `escalas/rascunho_telas/`) ainda mostra as telas da v3. Ele será refeito depois das decisões da seção 8.

---

## 1. Estrutura de 01/11

- **Hierarquia:**
  - CEO → Director → 3 OUM, um por unidade;
  - cada OUM tem 3 OM, um por turno;
  - cada OM tem 3 OS, no mesmo turno.
  - Ordem no organograma: D (T6/T1), C (T4/T5), A (T2/T3).
- **Núcleos:** cada OS é o **Decurio** de um núcleo. Os reps do núcleo são os **Equites** dele (1.1 a 1.n). Cada unidade tem 9 núcleos: Decurios 1 a 3 no turno A, 4 a 6 no C e 7 a 9 no D.
- **No Planner (D13):**

  | Estrutura nova | Planner |
  |---|---|
  | Unidade | Guild (sem uso hoje) |
  | OM e quem está abaixo dele (unidade × turno) | Team |
  | Decurio e a equipe dele (o núcleo) | Party |
  | Equites | Membros da party |

  - A página fica atribuída à Guild ("Assigned to Guild"), não mais à party: cada party atende um turno só.
  - O Planner já aceita isso hoje, com Team e Party vazios.
  - **Carteiras não vão para o Planner.** Ficam só no 5C Escalas, com histórico.
  - O posto de cada pessoa (vaga fixa, equipe e páginas) também fica só no 5C Escalas.
  - O núcleo inteiro fica **fixo no turno** e tira folga junto.
  - O que roda é só o padrão de folgas.
- **Rotação de 21 dias** (planilha "Escala 5x2 rotativa", 27/10):

  | Padrão | Folgas na semana | Semana |
  |---|---|---|
  | P1 | terça, sábado e domingo | 4x3 (32 h) |
  | P2 | segunda e sexta | 5x2 (40 h) |
  | P3 | quarta e quinta | 5x2 (40 h) |

  - Núcleo 1 segue P1 → P2 → P3; núcleo 2, P3 → P1 → P2; núcleo 3, P2 → P3 → P1. É a mesma sequência para os 3 turnos.
  - **Todo dia exatamente 1 núcleo de cada turno está de folga e 2 trabalham.** A tela confere isso (mínimo e máximo de 2 ativos).
  - Cada pessoa trabalha 14 de 21 dias (37,33 h por semana em média) e faz no máximo 4 dias seguidos.
  - **Cada posto precisa de 1,5 pessoa** (21 ÷ 14), e não de 1,4.
  - A planilha é editável. No sistema, os 3 padrões ficam num modelo de escala configurável, e a checagem de "2 ativos por dia" bloqueia a publicação.
- **Turnos:**

  | Turno | Horário | Blocos |
  |---|---|---|
  | A | 05–13h | T2 e T3 |
  | C | 13–21h | T4 e T5 |
  | D | 21–05h | T6 e T1 do dia seguinte |

- **Carteiras:** 2 por unidade, chamadas C1 e C2 (dentro do sistema, "U1 · C1"). Cada página ativa pertence a uma carteira. Hoje:

  | Unidade | Carteira | Páginas | Maiores páginas |
  |---|---|---|---|
  | U1 | C1 | 8 | Skylar Mae VIP, Lily Lou OFTV |
  | U1 | C2 | 14 | Cynthia, Britt VIP |
  | U2 | C1 | 15 | Elly Clutch 100m, Liv |
  | U2 | C2 | 16 | Nicole Doshi, Brandi Love |
  | U3 | C1 | 19 | Midwest Emma Free 221s, Rocket Reyna VIP |
  | U3 | C2 | 14 | Mikayla Free, Mikayla Paid |

  São 86 páginas no modelo. O faturamento fica igual entre as unidades: US$ 1,85 M, 1,86 M e 1,84 M.
- **Postos:** 1 posto = 1 rep online o turno inteiro. Vêm do Capacity (posts.py). São 162 ao todo: 55 titulares, 84 agrupados, 8 de apoio e 15 assistentes fixos.

  | Tipo | O que é |
  |---|---|
  | Titular | 1 rep só para a página |
  | Agrupada | até 3 páginas de até 28 fãs/h no mesmo rep |
  | Apoio | página acima de 40 fãs/h: o titular fica com 35 e o excedente vai para um rep que também é titular de páginas pequenas |
  | Assistente fixo | Skylar Mae VIP (3 por turno), Mikayla Free e Midwest Emma Free 221s (1 por turno cada) |

- **Pessoas:** cada rep (Equite) tem unidade, turno, núcleo (o Decurio), nível e a(s) vaga(s) fixa(s) que ocupa. No modelo de 27/10 são 220 reps reais e 39 líderes. A necessidade é de 269 pessoas no chat (230 titulares + 39 de buffer), com falta de 49. Essa conta usa o fator 1,4; com 1,5 a necessidade sobe (o Capacity refaz).
- **Níveis:**
  - Primaris vira nível 5 (cai de 5,5% para 4,5%; é o único que cai).
  - Secundus vira nível 3.
  - Tertius e quem está sem cargo viram nível 1.
  - Contratados novos entram no nível 0.
  - Os níveis 2 e 4 ficam vazios, como degraus de promoção.
  - **Turnos regulares por nível (27/10):** todos abaixo do Decurio fazem turnos regulares e acompanham a folga do núcleo.
    - Os níveis 3 a 5 atuam sempre em página.
    - Os níveis 0 a 2 alternam entre atuar em uma ou mais páginas e a função de **buffer**. O nível define a fatia regular: nível 2 mais, nível 1 médio, nível 0 menos.

## 2. O que o Planning tool faz

1. **Estrutura das unidades.** Unidades, carteiras com as páginas, liderança (OUM, OM, Decurios), núcleos, postos e quem ocupa cada posto.
   - **Fonte: o Planner** (decisão D1). O sistema lê a estrutura do `historicplanner`, que reflete o que o Planejamento ajusta nos times do site da 5C.
   - **Mudar algo pelo sistema gera um resumo** para o funcionário do Planejamento aplicar no site. O resumo diz:
     - quem muda de núcleo, de posto ou de turno;
     - que página vai para qual carteira ou unidade;
     - a partir de quando vale.
   - O resumo vira pendência, com o mesmo fluxo da v3: fecha sozinha quando a leitura seguinte do Planner mostra a mudança; fica "Atrasada" em 2 dias; fica "Divergente" se o Planner mudar diferente.
   - Enquanto a pendência está aberta, a escala já usa a formação nova a partir da data informada.
   - Toda mudança entra no De→Para.
   - Mover página entre carteiras ou unidades segue a regra dos 10 dias da Política (6.4). Antecipar exige justificativa de emergência.
   - **O que vai para o resumo do Planner:**
     - página → Guild;
     - pessoa → Party (núcleo), e com isso o Team e o turno;
     - OM e Decurio.
   - **O que fica só no sistema:**
     - página → carteira;
     - posto e a equipe fixa de cada vaga.
     - Tudo com histórico (quem, quando, de → para) e no De→Para.
2. **Colaboradores.** Cada pessoa tem:
   - unidade, turno (A, C ou D) e núcleo (Decurio);
   - nível;
   - vaga(s) fixa(s): a do posto próprio ou de cobertura (níveis 3 a 5) ou até 2 vagas com fatia regular (níveis 0 a 2), mais os dias de buffer (ver o item 3);
   - modelo de escala (o 5x2 rotativo de 21 dias, herdado do núcleo) e vigência.
   - O cartão mostra código e Telegram (`5c_bot.vw_rep.telegram`).
   - Mudança de nível ou de cargo só vale a partir de segunda-feira às 05:00 e nunca é retroativa (Política 6.2).
3. **Equipe de cada posto** (D2: pessoas fixas no posto e backup para a folga). Vale para cada unidade e turno, com 3 núcleos e P postos.
   - **Dono do posto:** cada posto pertence a um núcleo. Os postos ficam divididos por igual entre os 3 núcleos (~P/3 cada).
   - **Cobertura da folga do dono:** quando o núcleo dono folga, o posto é coberto por **um dos outros 2 núcleos**, que trabalham nesse dia. A cobertura é fixa por posto.
     - O núcleo j cobre um posto do núcleo i quando i folga, e um posto do núcleo k quando k folga (o "par").
     - Nos dias em que j trabalha, sempre um dos outros dois está de folga, então essa vaga de cobertura sempre existe.
   - **Vagas por núcleo em cada dia de trabalho:** ~P/3 postos próprios + ~P/6 de cobertura = **P/2 vagas**. No turno, isso dá 1,5 × P pessoas para ocupar as vagas com folga.
   - **Quem ocupa as vagas:**
     - **Níveis 3 a 5:** fixos numa vaga (posto próprio ou cobertura), todos os dias de trabalho do núcleo.
     - **Níveis 0 a 2:** dividem as vagas que sobram, cada um numa fatia regular pelo nível (2 > 1 > 0, decisão D10). Nos outros dias de trabalho fazem **buffer**.
     - Cada vaga ocupada por eles tem uma **equipe pequena e fixa** (2 ou 3 pessoas cujas fatias somam 1).
   - **Assistente fixo e apoio** são postos como os outros. No CSV, o assistente leva `#`.
   - **Menor troca de pessoas por página (D15).** Regras do montador, em ordem:
     1. **Equipe fixa por vaga.** Uma pessoa de nível 3 a 5 quando houver; senão, o mesmo grupo de 2 ou 3 pessoas de nível 0 a 2. A página de um posto vê sempre as mesmas pessoas, o ciclo inteiro e de uma semana para a outra.
     2. **Cada pessoa de nível 0 a 2 entra em no máximo 2 vagas** (de preferência da mesma página ou carteira). O resto dos dias dela é buffer.
     3. **Página com vários postos** (Skylar, Mikayla Free, Midwest Emma Free): os postos dela são espalhados pelos 3 núcleos, e a cobertura de um posto dela fica com quem já atua nela.
     4. **Par da cobertura:** quando não dá para ser a mesma página, os 2 postos do par ficam na mesma carteira e, se der, com páginas parecidas.
     5. **Quem entra numa vaga:** primeiro quem já atuou naquelas páginas e naquele turno nas últimas 4 semanas (`scheduleday`); depois quem está no time do posto no modelo de 27/10.
     6. **Estabilidade:** a cada semana o montador parte das equipes atuais e só mexe no que mudou (saída de pessoa, de página ou de posto, mudança de nível). A tela mostra quantas equipes mudaram.
     7. **Falta e indisponibilidade:** quem cobre é o buffer do dia que mais atuou na página nas últimas 4 semanas.
   - **Medida na tela:** pessoas diferentes por página e turno no ciclo e % de equipes mantidas da semana anterior.
   - **Teste no modelo de 27/10:** com uma pessoa fixa por vaga (dono e cobertura), 237 das 258 páginas-turno ficam com exatamente 2 pessoas no ciclo. Com os níveis 0 a 2 dividindo vagas, esse número sobe para 3 ou 4 nas vagas deles; o limite de 2 vagas por pessoa segura o resto.
   - **Com o headcount de 27/10 quase não sobra dia de buffer.** Postos de cada turno × quem trabalha por dia:
     - U1 C, U1 D, U2 C, U3 A, U3 C e U3 D: as vagas passam do que os níveis 0 a 2 dão. Eles ficam 100% regulares, e mesmo assim falta gente.
     - U1 A, U2 A e U2 D: sobra espaço, e a fatia regular média dos níveis 0 a 2 fica em 75–81%.
     - A função de buffer aparece de verdade conforme as turmas entram.
4. **Geração da escala.** Para cada unidade, turno e dia:
   - **Transição de 01/11 (D14):** domingo, do T2 em diante, é exceção.
     - A escala desse dia é montada com quem já está escalado nos turnos que vamos programar (o `scheduleday` de 01/11), encaixado nos postos novos.
     - A rotação só começa a valer em 02/11, que é a semana 1 do ciclo: núcleo 1 em P1, núcleo 2 em P3, núcleo 3 em P2.
     - Daí em diante valem todas as regras.
   0. **Releitura do realizado:** `scheduleday` das últimas 2 semanas. Dá os dias seguidos e confere se cada núcleo está na semana certa da rotação.
   1. **Folga do dia:** pelo padrão do núcleo (P1, P2 ou P3), mais as indisponibilidades aprovadas.
   2. **Vagas do núcleo no dia:** os postos dele, ou, se o núcleo dono folga, os postos que ele cobre.
   3. **Níveis 3 a 5** na vaga fixa deles.
   4. **Níveis 0 a 2:** cada vaga restante vai para alguém da equipe fixa dela. Vai primeiro quem está mais abaixo da fatia regular do nível no ciclo.
   5. **Buffer:** quem do núcleo trabalha e ficou sem vaga. Cobre falta, indisponibilidade e posto sem equipe, primeiro no núcleo e depois no turno da unidade.
   6. O que sobrar vira **lacuna**.

   **Validação antes de publicar:**
   - nada de anomalia programada (dias seguidos acima do modelo);
   - no máximo 52 h por semana e 12 h seguidas (Política 6.6);
   - no máximo 4 pessoas em página comum e 5 na SS;
   - limite de páginas por rep (decisão **D8**).
5. **Escala da liderança.**
   - O Decurio segue a folga do próprio núcleo, então todo dia há 2 Decurios em serviço por unidade e turno.
   - Que carteira cada Decurio acompanha no dia é a decisão **D6**.
   - A escala dos OM e dos OUM também entra (decisão **D6b**).
   - A liderança não ocupa posto no chat. Ela aparece na grade e nas telas de equipe, mas não no CSV das páginas.
6. **Recálculo durante a semana.** É disparado por:
   - mudança de postos (Capacity);
   - entrada ou saída de página;
   - página movida de carteira ou unidade;
   - mudança de pessoa, nível ou posto;
   - indisponibilidade aprovada;
   - falta não programada.

   Regras que continuam da v3:
   - Buffers que faltam são adicionados sozinhos, numa nova versão (v3 → v3.1).
   - Dia já escalado nunca é retirado sozinho: vira sugestão de workload.
   - Saída de página exige checagem ("Confirmar saída" ou "Não saiu").
7. **Buffers.** Não há um pool à parte: o buffer é uma **função** dos níveis 0 a 2 de cada núcleo, nos dias em que não estão numa vaga.
   - Por isso o buffer segue a folga do núcleo: há sempre buffer dos 2 núcleos em serviço.
   - O tamanho esperado (buffer do Capacity, 15%) vem da seção Capacity. O Planning tool mostra o buffer real do dia contra o esperado.
   - Tem um modo manual para ajustar.
8. **Equipes e níveis** (substitui o Equilíbrio Tertius).
   - Mostra quem está fixo em cada vaga, as equipes dos níveis 0 a 2 e a fatia regular de cada pessoa contra a do nível.
   - Mostra os dias de buffer feitos no ciclo.
   - Sugere promover (nível 0 → 1 → 2 → 3) quem está acima da fatia com bom desempenho. Mudança de nível só vale a partir de segunda às 05:00 (Política 6.2).
9. **Sugestões** dos tipos:
   - workload;
   - cobertura;
   - rebalanceamento entre carteiras e unidades (ex.: U3 pede 101 pessoas contra 80 na U1);
   - folga;
   - estrutura.

   Cada uma tem Aplicar, Descartar (com motivo) e Desfazer. Nada é aplicado sozinho.
10. **Indisponibilidade:** igual à v3. A Nora é o caminho principal, a fila é a mesma, e a decisão fica no site com o WFM.
   - Pedido com menos de 72 h é urgente. Só vale como justificativa por saúde, emergência familiar ou logística imprevista (Política 6.1 e 8).
   - Falta comunicada com mais de 72 h de antecedência não é punida.
11. **Funil de entrada de páginas:** igual à v3 (CRM, arquétipos, previsão ponderada). Muda o destino: em vez de time e party, **unidade e carteira**, com o motivo (faturamento, carga da unidade). Os postos da página prevista vêm do Capacity.
12. **Trocas de página** entre carteiras ou unidades (tela 18).
    - A fonte depende de **D1**: o Planner (`Planned Move`) ou a própria tela Estrutura.
    - O corte e a postagem não mudam:
      - corte no horário da troca, quase sempre 21:00;
      - T6 e T1 são um bloco fechado;
      - Delete Range de X+1 até o fim da escala postada; o T6 de X é apagado à mão;
      - CSV com Date Schedule = X.
    - **Realocação:** cada pessoa escalada mantém dia e turno. O que acontece com o titular do posto da página movida depende de **D7**:
      - (a) vai junto com a página para a outra carteira; ou
      - (b) fica na carteira e assume o posto que a página nova abrir.
13. **Monitoramento:**
    - **Anomalias:** o limite de dias seguidos vem do modelo. Na rotação de 21 dias são no máximo 4 seguidos e 5 por semana. Também entra como anomalia o núcleo fora da semana certa da rotação. A anomalia programada fica separada da não programada.
    - **Auditoria de acesso:** Infloww × páginas dos postos do plano publicado.
14. **Postagem:** igual à v3. Um CSV T1–T6 por página e o .zip. "Postar via API" fica desligado até existir a API de escrita.
15. **Entrega e prazos (Política 6.1):**
    - escala da semana (segunda a domingo) publicada até quinta-feira às 18:00;
    - trocas com 72 h de antecedência, e o WFM responde em até 72 h;
    - alteração com menos de 24 h só com justificativa e solicitante;
    - todo prazo em UTC−3.

## 3. Menu e telas

| Seção | Telas (número do rascunho v3 → o que muda) |
|---|---|
| (contexto no menu) | Semana e **Unidade** (no lugar de Time), escolhidas uma vez |
| Planejamento | 01 Painel · 05 Gerar escala · 06 Grade · **07 Equipes e níveis** (era Equilíbrio Tertius) · 08 Sugestões · 09 Buffers |
| Equipe | **02 Estrutura das unidades** (era Configuração dos times: unidades, carteiras, páginas, núcleos, postos e equipes) · **20 Rotação e liderança** (nova) · 03 Colaboradores · 04 Modelos de escala |
| Páginas | 16 Funil de entrada (destino = unidade e carteira) · 18 Trocas de página (entre carteiras e unidades) |
| Indisponibilidade | 19 Pedido pela Nora · 10 Solicitar no site · 11 Aprovar (WFM) |
| Acesso do colaborador | 17 Login no site (fase 2) |
| Monitoramento | 12 Anomalias · 13 Auditoria de acesso |
| Administração | 14 Acessos por tela (perfis por unidade, turno e carteira: decisão **D12**) |
| Ajuda | 15 Glossário, com os termos novos: unidade, carteira, núcleo, Decurio, Equites, posto, vaga, cobertura, buffer, apoio, OUM, OM, nível, P1/P2/P3 |

**Mudanças por tela:**
- **01 Painel:** indicadores por unidade e turno (postos, precisa, tem, saldo), núcleos em serviço no dia, pendências do Planner e o prazo de quinta 18:00.
- **02 Estrutura das unidades:** organograma, as duas carteiras com as páginas, os 3 núcleos de cada turno, os postos e, em cada posto, a equipe da vaga do dono e a da cobertura.
  - Ações: mover página, trocar a equipe de uma vaga, mudar alguém de núcleo.
  - Cada ação gera o resumo para o Planejamento aplicar no site (D1).
- **03 Colaboradores:** colunas de unidade, turno, núcleo, nível, vaga(s), fatia regular no ciclo e dias de buffer.
- **04 Modelos:** o padrão é o 5x2 rotativo de 21 dias, com P1, P2 e P3 editáveis e a checagem de 2 núcleos por dia. O 3x1 e o 4x3 ficam para exceções. O "Primaris 3x1 + admin" sai.
- **05 Gerar e 06 Grade:** a grade passa a ter 3 visões:
  - **por posto:** posto × dia, mostrando quem está e se é da vaga do dono, da cobertura ou buffer;
  - **por pessoa;**
  - **De→Para.**
  - Os filtros são unidade, carteira, turno e núcleo.
- **07 Equipes e níveis:** quem está fixo em cada vaga, as equipes dos níveis 0 a 2, a fatia regular contra a do nível, os dias de buffer e as sugestões de promoção.
- **09 Buffers:** o pool por unidade e turno, ligado ao buffer do Capacity.
- **20 Rotação e liderança (nova):** a planilha de 21 dias dentro do sistema.
  - Os 3 núcleos de cada turno, com a semana P1, P2 ou P3 de cada um e a checagem de 2 ativos por dia.
  - Os Decurios seguem a folga do núcleo.
  - A cobertura dos OM e OUM.

## 4. Integrações

**Fontes de leitura:**

| Dado | Fonte | Uso |
|---|---|---|
| Escala realizada | `5c_schedule.scheduleday` (API `PowerBi/scheduleday`, ou o espelho do servidor novo quando o `5c_scheduleday` do .7 estiver pronto) | Releitura, anomalias, conferência da postagem |
| Postos por página e turno | Seção Capacity, `5c_planning` (tabela a combinar; ver o contrato na nota da pasta compartilhada das sessões) | Necessidade da escala |
| Estrutura | Planner (`5c_historic_planner`), que reflete os times ajustados no site da 5C (D1) | Unidades, carteiras, núcleos, liderança, páginas; conferência das pendências |
| Headcount | `5c_headcount.headcount`, espelhado do servidor novo pelos scripts `novo_servidor/` | Pessoas ativas, Telegram |
| Histórico de pessoas e páginas | `5c_headcount.headcount_history` e `5c_pages.paginas_history` (NS_05) | Entradas e saídas com data, para o recálculo |
| Páginas | `5c_pages.pages` | Páginas ativas |
| CRM | `5c_crm` (igual à v3) | Funil |

**Escrita:** continua sem API de escrita no `scheduleday` e no Planner. A postagem é por CSV no Schedule Ajustment, igual à v3:
- 6 linhas (T1 a T6) × 7 colunas (segunda a domingo), separadas por `;`;
- códigos separados por `, `;
- `#` para assistente; o `$` o site calcula;
- T6 e T1 são um bloco fechado;
- o Delete Range apaga só o Model escolhido.

O que muda no CSV com a estrutura nova:
- O rep do turno A aparece em T2 e T3; o do C, em T4 e T5; o do D, no T6 do dia e no T1 do dia seguinte.
- O rep de posto agrupado aparece em todas as páginas do posto; o site marca o `$`.
- Como o apoio aparece na página grande (`#` ou não) é a decisão **D9**.

## 5. Regras da Política de Escalas que o sistema aplica

| Regra | Onde |
|---|---|
| 52 h por semana e 12 h seguidas no máximo (6.6) | Validação ao publicar e nas sugestões |
| Escala publicada até quinta 18:00; trocas com 72 h; emergência com menos de 24 h justificada (6.1) | Painel (prazo), Aprovar, De→Para |
| Mudança de cargo ou nível só às segundas, 05:00, sem retroativo (6.2) | Colaboradores |
| Página só muda de carteira ou unidade depois de 10 dias; antecipação documentada (6.4) | Estrutura, Trocas de página |
| Saída de rep de equipe só depois de 30 dias; ninguém é movido em PTO/férias (6.3, 6.4) | Estrutura, Colaboradores |
| Até 4 pessoas por página comum e 5 na SS (4.4, 4.5) | Validação |
| Não existe assistente sem titular presente (5.2) | Validação: assistente fixo só no dia em que o titular do posto está |
| Desligamento planejado: aviso até quinta 18:00, vale segunda 05:00 (7.3) | Colaboradores, recálculo |

**Fica para a Política revisar:** a hierarquia Guild → Time → Party, os cargos e a comissão por cargo (seções 2 e 4) foram substituídos pela estrutura de 01/11. O sistema segue a estrutura nova e só usa da Política as regras de escala acima.

## 6. Base de dados `5c_escalas`

As 22 tabelas da v3 continuam (scripts `escalas/sql/ES_01` a `ES_03`, já instalados em 21/10). A v4 precisa de um **script ES_04 de migração**, que só adiciona e não apaga nada. Proposta, a fechar depois das decisões:

| Tabela | O que guarda |
|---|---|
| `esc_unidade` | U1–U3 ↔ Guild do Planner, OUM, vigência |
| `esc_lideranca` | OUM, OM por turno, Decurio de cada núcleo, vigência |
| `esc_nucleo` | Unidade, turno, número (1 a 3), Decurio e a trilha da rotação (P1, P3 ou P2 na semana 1), vigência |
| `esc_posto` | Foto dos postos do Capacity (`5c_planning.fc_postos` e `fc_postos_pagina`) usada em cada plano, com o núcleo dono e o núcleo que cobre a folga |
| `esc_vaga_equipe` | Quem está fixo em cada vaga (dono e cobertura de cada posto): uma pessoa de nível 3 a 5, ou a equipe de 2 ou 3 pessoas de nível 0 a 2 com a fatia de cada uma. Com vigência. É a base da medida de estabilidade (equipes mantidas de uma semana para a outra) |

**Lido do `5c_planning` (seção Capacity), sem cópia de cadastro:**
- `fc_carteira` e `fc_pagina_carteira`: página → carteira, com vigência. É a fonte única; a regra dos 10 dias se confere ali.
- `fc_postos` e `fc_postos_pagina`: postos e fração por página.
- `fc_necessidade_estrutura`: o esperado por unidade e turno.
- A `fc_estrutura_rep` do Capacity é provisória. Quando o 5C Escalas tiver as pessoas por núcleo, o Capacity passa a ler do 5C Escalas (uma view `vw_esc_estrutura_rep`).
- Gravar página → carteira pela tela Estrutura exige liberar a escrita do módulo só nessa tabela do `5c_planning`. Hoje ele só grava no `5c_escalas`.
| `esc_pendencia_planner` | Resumo de cada mudança para o Planejamento aplicar no site e a conferência na leitura do Planner (a `esc_estrutura_pendencia` da v3 serve; avaliar se basta) |

Mudanças nas tabelas atuais (colunas novas; as antigas ficam para o histórico):
- **`esc_colaborador_config`:**
  - colunas novas `unidade`, `nucleo_id`, `nivel` e `fatia_regular` (a do nível, com ajuste individual);
  - `turno` continua;
  - a `trilha` e a `ancora` vêm do núcleo;
  - `folgas_fixas` deixa de ser usado.
- **`esc_plano_alocacao`:** ganha `posto_id` e o papel novo (dono, cobertura, assistente, apoio, buffer). A célula continua por dia × bloco × página × pessoa, para o CSV.
- **`esc_modelo_escala`:** entra o modelo **"5x2 rotativo 21 dias"**, contínuo, com 3 trilhas e máximo de 5 dias seguidos.
  - Ciclo `101110001110111100111`: P1 `1011100`, P2 `0111011`, P3 `1100111`, de segunda a domingo; 1 = trabalha.
  - O núcleo 1 começa no P1 (deslocamento 0), o núcleo 2 no P3 (14) e o núcleo 3 no P2 (7).
  - A fórmula do motor v3 (`idx = (dias + t + L − 1) mod L`) já serve.
  - O "Primaris 3x1 + admin" fica inativo.
- **`esc_parametro`:** buffer por unidade e turno, limite de páginas por rep e a data-âncora da rotação (D14).
- **Carga inicial (ES_05):** vem do modelo de 27/10 (`estrutura_unidades/data_real_final.json`): unidades, carteiras, liderança, postos e o posto de cada rep. O núcleo de cada rep sai do supervisor de referência (`sup`). As equipes de cada vaga (com os pares de cobertura) o sistema propõe e o usuário confirma (ver o item 3 da seção 2).

O módulo PHP (`escalas/sistema/5c_escalas/`) segue a mesma ordem:
1. leitura da estrutura e dos postos;
2. motor (a nova ordem de preenchimento);
3. telas 02, 03, 05, 06, 20, 07 e 09;
4. as demais.

## 7. Padrões de UX

Iguais à v3:
- contexto global no menu, agora Semana e Unidade;
- contadores no menu;
- Desfazer em toda ação;
- confirmação com impacto antes de publicar ou remover;
- recusar exige comentário;
- detalhe com o porquê de cada célula;
- estados vazios explicados;
- cor sempre com texto.

## 8. Decisões

**Respondidas pelo usuário em 27/10:**
- **D1 · Fonte da estrutura:** o Planner. Mudança feita pelo sistema gera um resumo para o funcionário do Planejamento aplicar no site da 5C. O site atualiza os times, e isso volta ao banco pela leitura do Planner.
- **D2 · Posto:** pessoas fixas no posto, mais backup para a folga do titular. A seção 2, item 3, traz a forma: cada posto tem a vaga do dono e a da cobertura (de outro núcleo), cada uma com equipe fixa.
- **D3 · Folgas:** rotativas, no ciclo de 21 dias (5x2, 5x2 e 4x3; a semana de 4x3 inclui sábado e domingo), com P1, P2 e P3 defasados em 1 semana entre os 3 núcleos do turno. Planilha de 27/10.
- **D11 · Virada:** a escala nova sai por este sistema. A virada era em 01/11 às 05:00; em 28/10 a escala antiga foi postada até **01/11 T6**, e o sistema passa a fazer a partir do **turno A de 02/11** (`ES_08`). O T1 de 02/11 é o fim do turno D postado.
- **D13 · Planner:**
  - Guild = unidade; Team = OM e quem está abaixo; Party = Decurio e o núcleo dele; membros = Equites.
  - A página fica com a Guild.
  - Postos e equipes ficam só no 5C Escalas, com histórico. Carteiras ficam no `5c_planning` (ver abaixo).
- **D14 · Âncora:** a semana de 02/11 é a semana 1. O domingo 01/11 seria de transição; com a escala antiga postada até 01/11 T6 (28/10), não há mais dia de transição.
- **D15 · Backup:** deve dar a menor troca possível de pessoas atuando numa página. Ficou equipe fixa por vaga, com as 7 regras do montador da seção 2, item 3, testadas no modelo de 27/10.
- **D16 · Nomes:** Decurio = OS; Equites = reps.
- **Buffers:** cada núcleo (equipe do supervisor) tem o próprio grupo de buffers, que segue a folga do núcleo.
  - Os níveis 0 a 2 alternam entre atuar em uma ou mais páginas e a função de buffer.
  - Todos fazem turnos regulares; o nível define quantos (2 mais, 1 médio, 0 menos).
  - O tamanho dos grupos de buffer está sendo definido na seção Capacity.
- **Carteiras:** página → carteira fica só no `5c_planning` (tabela `fc_pagina_carteira` do Capacity, com histórico). O 5C Escalas lê dali.

**Abertas, na ordem em que travam o trabalho:**

1. **D10 · Fatia regular por nível.** Valores dos níveis 0, 1 e 2. Proposta inicial, como parâmetro editável: nível 2 = 80%, nível 1 = 60%, nível 0 = 40% dos dias de trabalho em página (o resto é buffer). No modelo de 27/10, os nível 1 estavam em 47–63%.
2. **OM e OUM:** mantêm o nome? A escala deles segue a mesma rotação de 21 dias?
3. **D6b · Folga do OM:** quem cobre o OM na folga dele?
4. **D5 · Postos na semana:** os mesmos todos os dias (posts.py, maior bloco do turno) ou por dia da semana?
5. **D8 · Páginas por rep:** o posts.py agrupa até 3 de modelos diferentes; a Política (5.3.1) permite 2 quando são diferentes. Qual vale?
6. **D9 · Apoio no CSV:** quem faz apoio na página grande entra como assistente (`#`) ou como regular?
7. **D7 · Página que muda de carteira:** quem está fixo no posto vai junto ou fica na carteira?
8. **D12 · Acesso por perfil:** o OUM vê a própria unidade, o OM o próprio turno e o Decurio o próprio núcleo? Algum deles aprova indisponibilidade, ou só o WFM?

**Resolvidas pelas respostas acima:**
- **D4:** a cobertura fica dentro da unidade e do turno. A cobertura pode estar na outra carteira; entre unidades, só buffer.
- **D6:** o Decurio acompanha o próprio núcleo, que é a party dele no Planner. Os Equites dele podem ocupar postos das 2 carteiras.

**Perguntas da v3 que continuam:**
- Remoção de turno por workload: com quanta antecedência avisar a pessoa e por qual canal.
- Checagem de saída de página: quem confirma e em quanto tempo.
- Previsão de uma API de escrita no `scheduleday`.
- No funil: quem define o destino da página e quem classifica o perfil quando o CRM não informa.

**Respondidas pela Política ou pela estrutura nova:**
- Antecedência da indisponibilidade: 72 h.
- Limite de horas: 52 h por semana e 12 h seguidas.
- Horizonte: escala semanal, entregue na quinta.
- O dia administrativo do Primaris e a cobertura entre parties deixaram de existir.

## 9. Scripts da v4 (28/10)

Em `escalas/sql/`, rodar no Navicat nesta ordem:

| Script | O que faz |
|---|---|
| `ES_04_estrutura_unidades.sql` | Só adiciona:<br>- tabelas `esc_unidade`, `esc_lideranca`, `esc_nucleo`, `esc_posto`, `esc_posto_pagina` e `esc_vaga_equipe`;<br>- colunas novas em colaboradores, plano e alocação;<br>- o modelo "5x2 rotativo 21 dias";<br>- os parâmetros `v4_*` (fatia por nível 80/60/40%, âncora 02/11, virada 01/11 05:00);<br>- a view `vw_esc_estrutura_rep` para o Capacity.<br>Pode rodar de novo. |
| `ES_05_carga_estrutura_27out.sql` | Gerado por `gerador/gerar_es05.py` a partir do modelo de 27/10:<br>- 3 unidades, 3 OUM e 9 OM;<br>- 27 núcleos;<br>- 245 pessoas: 219 Equites e 26 Decurios com código;<br>- 162 postos com as páginas;<br>- a **proposta** de equipe de cada vaga (status PROPOSTA).<br>Fecha a configuração antiga dessas pessoas em 31/10. Termina com consultas de conferência. Pode rodar de novo. |
| `ES_06_ajuste_pessoas_01nov.sql` | Fecha a configuração da v3 dos 12 desligados.<br>Põe nos núcleos os 20 reps ativos que estavam fora do modelo de 27/10:<br>- turno = o que mais trabalharam em 14 dias;<br>- nível pelo cargo de hoje;<br>- núcleo = o mais curto do turno.<br>Entram sem equipe fixa. |
| `ES_08_virada_02nov.sql` | Passa a virada para 02/11 01:00 e deixa sem dia de transição (`v4_dia_transicao` vazio): a escala antiga vai até 01/11 T6. |
| `ES_07_conferencia_v4.sql` | Só leitura: conferência do plano da semana de 02/11 depois de gerar. |
| `ES_98_desfazer_v4.sql` | Desfaz o ES_05 e o ES_04 sem apagar o schema. |

**Motor v4 (`lib/motor_v4.php`, 28/10):** o "Gerar escala" usa sozinho a v4 em toda semana a partir da virada.
- **Ordem de preenchimento de cada vaga:**
  1. equipe fixa da vaga, como **preferência**: fica quem atuou da última vez enquanto estiver abaixo da fatia;
  2. outra pessoa do núcleo, primeiro quem conhece as páginas;
  3. buffer do outro núcleo em serviço no turno;
  4. lacuna.
- Quem fica sem vaga é o buffer de plantão e não vai para o CSV.
- **Virada em 02/11 (`ES_08`):** o motor começa no turno A de 02/11. O T1 de 02/11 sai no CSV com o T1 já postado ou, se não houver, com quem está no T6 de 01/11 (continua o turno D). Quem está nesse turno D fica fora do turno A de 02/11 (seriam 16 h seguidas; em 28/10 eram 4 pessoas).
- **Dia de transição** (`v4_dia_transicao`, hoje vazio): se tiver um dia, ele é montado com quem está escalado no `scheduleday`, no turno em que está.
- **Teste com os dados carregados (ES_05 + ES_06)**, sem nenhuma violação:
  - ninguém em 2 postos no mesmo turno, nenhum trabalho na folga do núcleo nem fora do turno, nenhum assistente sem titular na página;
  - todos com 4 ou 5 dias na semana.
- **Semana de 02/11:**
  - 58 posto-dias sem ninguém, em média 8 por dia: U1 C 17, U2 C 10, U3 A 12, U3 C 14, U3 D 5;
  - média de 2,8 pessoas diferentes por página e turno na semana (o mínimo na rotação é 2).
- **01/11** (quando ainda era transição): 24 postos sem ninguém.

**O que a carga encontrou:**
- **Isabel da Silva Marinho** (Decurio da U2, turno A) não está no headcount. O núcleo entra sem código de Decurio, e ela fica fora da lista de pessoas.
- Um rep do modelo estava "(sem Cadastro)" (B109639) e ficou de fora.
- **Daniswings 157m** ficou inativa no cadastro depois de 27/10. Entra nos postos com o id dela, e a conferência avisa.
- **Proposta de equipes:** 424 linhas. Falta de gente para cobrir as vagas, por unidade e turno:

  | | A | C | D |
  |---|---|---|---|
  | U1 | 0,2 | 7,1 | 4,6 |
  | U2 | 1,5 | 4,6 | 0,2 |
  | U3 | 8,5 | 11,2 | 9,6 |

  - Em todos os turnos, os níveis 0 a 2 precisaram passar da fatia do nível, então não sobra dia de buffer.
  - Em média cada página-turno vê 3,2 pessoas no ciclo: as vagas dos níveis 0 a 2 são divididas.
  - Um núcleo não empresta gente a outro, por isso a falta passa um pouco da conta simples.

## 10. Próximos passos (virada em 02/11, turno A)

O prazo é curto: a escala de **02/11 a 08/11** precisa sair do sistema até **quinta, 29/10, às 18:00** (Política 6.1). Ordem proposta, só com o que essa escala precisa:

1. **28/10:**
   - responder os buffers em núcleo e OM/OUM (itens 1 e 2 das abertas);
   - script `ES_04_estrutura_unidades.sql` (só aditivo) com o modelo "5x2 rotativo 21 dias";
   - `ES_05_carga_estrutura_27out.sql` com unidades, núcleos, postos e a proposta de equipes por vaga.
2. **28–29/10:**
   - motor v4 no módulo (rotação, vagas, equipes, fatia por nível, buffer, lacuna);
   - grade por posto e CSV T1–T6 por página;
   - testes no harness da v3 (php-wasm com o banco simulado);
   - o zip para instalar.
3. **29/10, até 18:00:** gerar, revisar e publicar a escala de 02/11 a 08/11; os CSVs vão para o Schedule Ajustment.
4. **Depois da virada:** telas 02, 07 e 20 completas, resumo para o Planner, as demais telas no canvas e o restante das decisões.

**Núcleos de hoje × o que a rotação pede** (modelo de 27/10). O núcleo de cada rep é o supervisor de referência; nenhum rep tem supervisor de outra unidade ou turno.

| Unidade · turno | Postos | Precisa (1,5 × postos, sem buffer) | Tem | Saldo | Reps por núcleo hoje |
|---|---|---|---|---|---|
| U1 · A | 15 | 23 | 25 | +2 | 8 · 9 · 8 |
| U1 · C | 17 | 26 | 20 | −6 | 6 · 7 · 7 |
| U1 · D | 16 | 24 | 21 | −3 | 7 · 7 · 7 |
| U2 · A | 17 | 26 | 28 | +2 | 10 · 9 · 9 |
| U2 · C | 18 | 27 | 25 | −2 | 9 · 8 · 8 |
| U2 · D | 18 | 27 | 30 | +3 | 10 · 10 · 10 |
| U3 · A | 19 | 29 | 23 | −6 | 7 · 8 · 8 |
| U3 · C | 22 | 33 | 24 | −9 | 8 · 8 · 8 |
| U3 · D | 20 | 30 | 24 | −6 | 8 · 8 · 8 |
| **Total** | **162** | **245** | **220** | **−25 (−36 + 11 de sobra)** | |

Sem contar o buffer, faltam 25 pessoas. Os turnos com sobra não cobrem os outros, porque o núcleo é fixo no turno. Os buffers de 15% somam mais ~37.

Na escala de 01/11, os postos sem gente aparecem como **lacuna**, ou ficam sem equipe de cobertura (a folga do dono vira lacuna). O sistema mostra a lista para o Planejamento decidir: buffer, hora extra dentro de 52 h, ou aceitar a lacuna.
