# Motor de alocações: registro, rascunho e ajuste

> **Atualização de 24/11/2026 (5C Escalas 1.38.0):** o **KPI dos SRs** é o indicador oficial de desempenho. O Mérito virou **Legacy** (fora do menu principal, só leitura, dados preservados) e as **moedas** saíram do KPI. Onde este documento fala em mérito, nota em pontos ou faixa (Acima, Na média, Abaixo), vale hoje o KPI: score de 0% a 120% e nível de 0 a 4 (regras em `contexto/regras_escalacao_kpi.md`). As chaves das regras A-01 a A-04 mudaram para `v4_kpi_passo0`, `v4_kpi_abaixo_fora_topo`, `v4_kpi_acumulo` e `v4_kpi_remanejar` (ES_217).

Este arquivo guarda as regras novas que cruzam o **mérito** do SR com a **prioridade das páginas**: quem vai para qual página, em que ordem e com quais limites. Vale do rascunho até a validação.

As duas entradas têm cada uma o seu arquivo e não se mexem por aqui:
- **Mérito** (a nota do SR, M-NN): `contexto/regras_merito.md`.
- **Prioridade e tratamento das páginas** (a fila e o tratamento de cada página, P-NN): `contexto/regras_prioridade_paginas.md`.

Este arquivo fica fora do `CLAUDE.md` da raiz para não pesar no contexto de toda sessão. Só se lê quando o assunto é o motor de alocações.

Uma regra só sai daqui para o código, os parâmetros e o `CLAUDE.md` depois de **Validada** pelo usuário. Enquanto isso, vale o motor atual (ver "Onde está o motor de hoje").

## Como usar

1. Regra nova entra em **Rascunhos**, com o modelo abaixo e o próximo ID livre (A-01, A-02...).
2. Em discussão ou simulação, vai para **Em ajuste**.
3. Com o ok do usuário, vai para **Validadas**, com a data e quem validou.
4. Quando for implementada (código, script ES_NN, parâmetro), registrar onde e em qual versão do módulo.
5. Regra abandonada vai para **Descartadas**, com o motivo. O ID não é reaproveitado.

Cada mudança de uma regra entra no "Histórico" dela, com a data. Não apagar o texto de uma regra validada: marcar o que mudou.

### Modelo

```markdown
### A-NN · <nome curto>
- **Status:** Rascunho | Em ajuste | Validada em DD/MM/AAAA | Implementada na X.Y.Z | Descartada
- **Pedido por:** <quem> em DD/MM/AAAA
- **Objetivo:** <o que a regra resolve, em uma frase>
- **Usa do mérito:** <M-NN, faixa ou nota>
- **Usa da prioridade:** <P-NN, posição na fila ou tratamento da página>
- **Regra:** <como o cruzamento decide quem vai para qual página, em linguagem direta>
- **Passo do motor:** <onde entra na ordem de preenchimento e o que substitui>
- **Limites e exceções:** <equipe fixa, Fanvue/Privacy, só brasileiros, exclusiva, sob demanda, descanso, capacidade>
- **Dados e fontes:** <tabelas, colunas, período>
- **Parâmetros:** <chave em esc_parametro, valor proposto>
- **Exemplo:** <semana real ou simulada, com o antes e o depois>
- **Impacto esperado:** <quantos SRs e quantas páginas mudam, efeito em lacunas e trocas de gente>
- **Em aberto:** <dúvidas para o usuário>
- **Histórico:**
  - DD/MM: <o que mudou>
```

## Simulação de 02/11 (base das A-01 a A-04)

Simulação só de leitura, sem rodar o Gerar. Ela olha a escala que já existe e conta as trocas que cada
regra faria, uma por uma, dentro do mesmo dia, turno e unidade.

- **Escala:** plano 93, a semana de 02/11, na versão v30.1 publicada.
  - São 1.009 SR-turnos como regular, contados pelos blocos T2, T4 e T6, com 211 SRs.
- **Faixa:** a nota em pontos da M-01, na simulação do mérito (03/10 a 30/10, arquivo
  `resultados/sim_merito_pontos_0211.csv`).
  - Acima: 30 SRs e 147 SR-turnos.
  - Na média: 140 SRs e 659 SR-turnos.
  - Abaixo: 41 SRs e 203 SR-turnos.
  - Todos os SRs do plano têm nota.
- **Página prioritária:** enquanto o `regras_prioridade_paginas.md` não tiver uma regra P-NN para
  isso, é a página de modelo com `v4_melhores_receita_min` (US$ 100 mil) ou mais em 30 dias.
  - São as mesmas do passo 0, 15 modelos: Mikayla, Cynthia, Brandi Love, Elly Clutch, Nicole Doshi,
    Liv, Madison Moore, Midwest Emma, Marie Dee, CJ Miles, Rocket Reyna, Sisi Rose, Britt,
    Holly Jane e Rebecca.
- **Quem está no topo hoje:**

  | Faixa | Turnos em página prioritária |
  |---|---|
  | Acima | 74% |
  | Na média | 36% |
  | Abaixo | 20% |

  - O topo já é preenchido quase todo pelo passo 0 (322 SR-turnos) e pela equipe fixa (52).
  - Os passos 1 a 4 não chegam às páginas prioritárias. Por isso, uma regra para o topo tem de
    entrar no passo 0.
- **Arquivos (fora do git):** `resultados/sim_motor_0211/`, com o export do plano (`aloc93.tsv`) e
  as trocas de cada regra (`a01_trocas.csv`, `a02_trocas.csv`).
- **Limite da simulação:** ela não refaz o Gerar.
  - Não confere a capacidade nem o nível máximo de assistente.
  - Não confere "só brasileiros" na Privacy nem FORA/ASSISTENTE.
  - Os números são o teto do que mudaria. O número exato sai do Gerar simulado em
    `resultados/sim_0211/`, já com o código novo.

## Validadas

### A-01 · SR Acima primeiro nas páginas prioritárias
- **Status:** Validada em 03/11/2026 (Luis Otávio). Implementada e publicada na 1.19.0 (03/11)
- **Pedido por:** Luis Otávio em 02/11/2026 (sugestão do Claude aceita para rascunho)
- **Objetivo:** pôr o SR Acima nas páginas que mais faturam antes dos outros.
- **Usa do mérito:** faixa da M-01 (Acima com 8 pontos ou mais, Na média, Abaixo com menos de −8).
- **Usa da prioridade:** página prioritária (modelo de US$ 100 mil ou mais; ver "Simulação de 02/11").
- **Regra (juntada com a P-01 em 03/11):** no passo 0, o SR de cada página é escolhido nesta ordem:
  1. **Quem bateu 65% ou mais da meta na página** nos últimos 30 dias. Isso vem antes de tudo, também
     da faixa (decisão de 03/11). Hoje esse corte é de 70%.
  2. **Quem já fez a página** nos últimos 30 dias (tem meta nela), mesmo abaixo de 65% (03/11, depois
     da Mikayla Free do plano 96).
  3. **A faixa:** Acima, depois Na média, depois Abaixo.
  4. **A nota em pontos**, depois o tempo de casa (M-06) e o código. A venda/hora sai, porque já entra
     na nota pela M-07.
  - **Escopo, da P-01:** todas as páginas (menos as On demand), de cima para baixo na fila, e não só as
    prioritárias. Fica por parâmetro: `v4_melhores_receita_min` = 0.
  - **Continuidade, da P-01:** quem estava no posto da última vez fica, salvo em dois casos:
    - o melhor candidato está numa **faixa acima** da dele (decisão de 03/11; substitui os "15% de
      nota a mais" da P-01, que não servem para pontos);
    - ele está abaixo de 65% da meta na página;
    - ele nunca fez a página e o melhor candidato já fez (03/11).
  - A fila dos postos não muda: a modelo que mais fatura continua escolhendo primeiro.
- **Passo do motor:** passo 0 (melhores SRs), na chave `$k` da escolha (`motor_v4.php`, por volta da
  linha 1031).
  - Os passos 1 a 4 não mudam, porque não chegam às páginas prioritárias (ver a simulação).
- **Limites e exceções:**
  - A equipe fixa da página vem antes e não muda. Isso inclui Fanvue e Privacy.
  - Valem como hoje: só brasileiros na Privacy, FORA/ASSISTENTE, o nível máximo de assistente e a
    página exclusiva (Sisi Rose; o SR dela não acumula).
  - A página On demand continua sem gente no Gerar.
  - Na transição, o passo 0 não roda.
- **Dados e fontes:** a faixa vem de `esc_sr_merito`, depois que a M-01 estiver no código. A venda/hora
  e a meta são as de hoje (`$est['melhores']`).
- **Parâmetros:**
  - `v4_merito_faixa_passo0` = 1 (0 desliga e volta à regra de hoje).
  - `v4_melhores_meta_min` = 0.65. Hoje essa chave não tem linha em `esc_parametro`, e o código usa o
    padrão de 0.70. O corte de 65% vale também sem a faixa.
- **Exemplo (plano 93):**
  - Em 02/11, turno C da U3, o I131039 (Acima, nas páginas Alexis Clark, Bianca, Katie Flood e
    Liam) fica com a Britt VIP no lugar do A007546 (Na média).
  - Em 02/11, turno C da U2, o J066864 (Acima, na Blair VIP) fica com a Nicole Doshi e a Aline Novak
    no lugar do C021331 (Abaixo).
  - Antes de valer este caso é preciso conferir se o J066864 é brasileiro, porque a Aline Novak é
    Privacy.
- **Impacto esperado:**
  - Só com a faixa à frente, seriam 25 SR-turnos trocados na semana: 13 SRs Acima subiriam, e dos que
    desceriam 9 eram Abaixo e 16 Na média.
  - Com a meta na página antes da faixa, 10 dessas 25 trocas não acontecem, porque quem estava bateu
    70% da meta ou mais.
    - **Sobram 15 trocas:** descem 11 Na média e 4 Abaixo.
    - A simulação só enxerga quem bateu 70% (o número aparece no motivo do plano 93). Quem ficou entre
      65% e 70% também fica, então o número final pode ser um pouco menor que 15.
  - As trocas se concentram em Britt, Rebecca, Madison Moore, Sisi Rose e CJ VIP.
  - O número de lacunas não muda, porque cada troca é 1 por 1.
- **Em aberto:**
  1. ~~"Bateu a meta na página" vem antes da faixa?~~ Sim, e antes de tudo, com corte de 65% (03/11).
  2. ~~A faixa pode pesar mais que o desempate da M-01?~~ Sim, ok explícito do Luis Otávio em 03/11:
     a faixa passa a ser critério nos passos 0, 5, 6 e 7. A M-01 diz "só desempate"; a anotação lá é
     da sessão de mérito.
- **Histórico:**
  - 02/11: criada e simulada no plano 93.
  - 03/11: a meta na página (65% ou mais) vem antes da faixa (Luis Otávio). Em ajuste.
  - 03/11: validada (Luis Otávio), com o ok explícito para a faixa pesar além do desempate da M-01.
  - 03/11: "já fez a página em 30 dias" entra entre a meta e a faixa, também na continuidade
    (Luis Otávio, depois de SRs Acima que nunca fizeram a Mikayla Free entrarem no plano 96). Instalado,
    a publicar.
  - 03/11: **juntada com a P-01** (Luis Otávio). O escopo e a continuidade vêm da P-01; a ordem vem
    desta regra, com a nota em pontos no lugar da venda/hora. Na continuidade, quem estava só sai
    para um candidato de faixa acima. A janela do atingimento é de 30 dias, como no passo 0 de hoje.
    Avisada a sessão de prioridade, para atualizar a P-01.

### A-02 · SR Abaixo fora das páginas prioritárias
- **Status:** Validada em 03/11/2026 (Luis Otávio). Implementada e publicada na 1.19.0 (03/11)
- **Pedido por:** Luis Otávio em 02/11/2026 (sugestão do Claude aceita para rascunho)
- **Objetivo:** não deixar página prioritária com um SR que está mal no mérito.
- **Usa do mérito:** faixa Abaixo (menos de −8 pontos).
- **Usa da prioridade:** página prioritária.
- **Regra:** o SR Abaixo não fica como **regular** em página prioritária.
  - **Exceção:** quem bateu 65% ou mais da meta naquela página fica, porque a meta na página vem antes
    de tudo (A-01). A venda/hora alta sozinha não segura o SR Abaixo no topo (03/11).
  - No passo 0, ele só entra se não houver nenhum SR Na média ou Acima disponível na unidade e no
    turno. Nesse caso ele entra por último e a célula fica sinalizada ("Abaixo em página
    prioritária"). Nada é proibido: a regra só muda a ordem.
  - No remanejamento (passo 5), o SR Abaixo não sobe para página prioritária.
  - Ele pode continuar como assistente (#) na página.
- **Passo do motor:** passo 0, na mesma chave da A-01 (as duas são uma só mudança: o Abaixo fica no
  fim da ordem). Também o passo 5, que confere a faixa antes de remanejar.
- **Limites e exceções:**
  - Quem é da equipe fixa da página fica, mesmo sendo Abaixo, porque ela vence.
    - No plano 93, são 11 SR-turnos.
    - Desde 09/11, a Holly Jane tem equipe fixa (ES_75).
  - Valem como hoje: só brasileiros, FORA/ASSISTENTE, exclusiva e On demand.
- **Dados e fontes:** as mesmas da A-01.
- **Parâmetros:** `v4_merito_abaixo_fora_topo` = 1.
- **Exemplo (plano 93):** em 02/11, turno A da U3, o J058241 (Abaixo) sai da Cynthia, e entra o
  C016602 (Na média, que estava na Aja).
- **Impacto esperado:**
  - Hoje, 29 SR-turnos Abaixo estão em página prioritária fora da equipe fixa, todos pelo passo 0
    (venda/hora alta).
  - 28 deles têm substituto Na média ou Acima na unidade e no turno. 1 ficaria sinalizado.
  - Com a meta na página antes de tudo, 13 desses 28 ficam, porque bateram 70% ou mais da meta na
    página. **Saem 15.** Quem ficou entre 65% e 70% também fica, e a simulação não enxerga esses casos.
  - 4 das 15 saídas são as mesmas da A-01.
  - As páginas são Holly Jane (5, que somem a partir de 09/11 com a equipe fixa), Cynthia (4),
    Rebecca (5), CJ Miles (4), Britt (3), Midwest Emma (2) e Marie Dee (1).
  - O número de lacunas não muda.
- **Em aberto:**
  1. ~~O Abaixo que vende muito por hora deve sair mesmo assim?~~ Sim, porque a venda não é o único
     fator (03/11). Fica só se bateu a meta na página.
  2. ~~A questão da M-01.~~ Resolvida como na A-01 (03/11).
- **Histórico:**
  - 02/11: criada e simulada no plano 93.
  - 03/11: o Abaixo sai do topo mesmo vendendo muito, salvo se bateu 65% da meta na página. Nada é
    proibido: ele vai por último (Luis Otávio). Em ajuste.
  - 03/11: validada (Luis Otávio), com o ok explícito para a faixa pesar além do desempate da M-01.
  - 03/11: com a P-01 junto da A-01, o passo 0 vale para todas as páginas, e "página prioritária"
    ganha um parâmetro próprio: `v4_pagina_prioritaria_min` = 100000 (US$ da modelo em 30 dias). No
    passo 0, o Abaixo já vai para o fim pela faixa; a regra própria fica no passo 5 e na
    sinalização.

### A-03 · Limites por faixa no acúmulo e na sobrecarga
- **Status:** Validada em 03/11/2026 (Luis Otávio). Implementada e publicada na 1.19.0 (03/11)
- **Pedido por:** Luis Otávio em 02/11/2026 (sugestão do Claude aceita para rascunho)
- **Objetivo:** não dar carga extra (acumular posto ou cobrir página acima da capacidade) a quem está
  mal no mérito.
- **Usa do mérito:** faixa Abaixo, e a faixa como ordem de escolha.
- **Usa da prioridade:** a ordem das lacunas de hoje (do maior faturamento para o menor). Nada novo.
- **Regra:** no acúmulo (passo 6) e na sobrecarga (passo 7), o **SR Abaixo vai por último**. Entre
  os outros, as regras dos dois passos ficam iguais (03/11).
  - O SR Abaixo só acumula ou cobre se ninguém mais couber. Não é proibido, para não abrir lacuna.
  - Acima e Na média não se separam aqui. Na primeira versão do código a ordem era Acima, Na média,
    Abaixo, e no Gerar simulado o SR Acima passava a puxar as páginas até 130% mesmo quando um Na
    média tinha folga: a sobrecarga ia de 75 para 110 vagas-dia. Foi corrigido para o que o usuário
    pediu ("ele vem por último").
  - O teto da sobrecarga (`v4_over_capacity_max`, 30%) fica igual para todas as faixas.
- **Passo do motor:**
  - Passo 6: a chave `$k`, logo depois da preferência pela mesma modelo (página desconsiderada).
  - Passo 7: a chave `$k`, à frente de `-$c['n']`.
- **Limites e exceções:**
  - Valem como hoje: a mesma agência na sobrecarga, a política de juntar páginas no acúmulo, o SR
    da página exclusiva e o "todos os dias" (não acumulam), e FORA.
- **Dados e fontes:** as mesmas da A-01.
- **Parâmetros:** `v4_merito_faixa_acumulo` = 1.
- **Exemplo (plano 93):**
  - Acúmulo (74 células): 28 com SR Abaixo.
  - Sobrecarga (972 células): 200 com SR Abaixo.
  - São 76 SR-turnos Abaixo, de 354 com acúmulo ou sobrecarga.
- **Impacto esperado:** as 228 células do SR Abaixo passam para outro SR quando houver um da mesma
  agência com capacidade. Senão, ficam com ele. As lacunas não aumentam. Quantas mudam de SR só o
  Gerar simulado diz, porque depende da capacidade de cada um.
- **Em aberto:**
  1. ~~Abaixo por último ou proibido?~~ Por último; nada é proibido (03/11).
  2. ~~O SR Acima pode passar do teto de 30% da sobrecarga?~~ Não: as regras dos passos ficam iguais
     (03/11).
- **Histórico:**
  - 02/11: criada e simulada no plano 93.
  - 03/11: o Abaixo vai por último e nada é proibido; o teto continua igual para todos (Luis Otávio).
    Em ajuste.
  - 03/11: validada (Luis Otávio), com o ok explícito para a faixa pesar além do desempate da M-01.
  - 03/11: implementada na 1.19.0 com o Abaixo por último e Acima igual a Na média, como na resposta
    do usuário (ver a Regra).

### A-04 · Remanejamento por mérito
- **Status:** Validada em 03/11/2026 (Luis Otávio). Implementada e publicada na 1.19.0 (03/11)
- **Pedido por:** Luis Otávio em 02/11/2026 (sugestão do Claude aceita para rascunho)
- **Objetivo:** quando uma página maior fica vazia, mandar para ela o melhor SR, e não o SR que
  estiver na menor página.
- **Usa do mérito:** a faixa.
- **Usa da prioridade:** a fila de hoje. A página de faturamento maior recebe o SR de um posto de
  faturamento menor.
- **Regra:** no remanejamento, o SR escolhido para subir passa a ser o de **melhor faixa** entre os
  que estão em postos de faturamento menor da mesma unidade e turno.
  - A ordem é: Acima, depois Na média, depois Abaixo.
  - No empate, vale a regra de hoje: sobe o SR do posto que menos fatura.
  - O SR Abaixo não sobe para página prioritária (A-02).
  - A lacuna continua ficando na página menor.
- **Passo do motor:** passo 5, na escolha da `$fonte` (por volta da linha 1233).
  - Hoje vale só `$receitaPosto` menor. Passa a valer `[faixa, -receita]`.
- **Limites e exceções:**
  - Continua valendo hoje: não tira o regular de página com assistente, não mexe em quem cobre
    lacuna no dia de treinamento, e só remaneja dentro da unidade e do turno.
  - Equipe fixa: o SR da equipe fixa não é tirado da página dela para remanejar (na 1.18 o código
    não protegia isso; a 1.19.0 protege).
- **Dados e fontes:** as mesmas da A-01.
- **Parâmetros:** `v4_merito_faixa_remanejar` = 1.
- **Exemplo (plano 93):**
  - 78 SR-turnos foram remanejados: 56 Na média, 15 Abaixo e 7 Acima.
  - Nenhum Abaixo subiu para página prioritária.
- **Impacto esperado:**
  - Em até 19 dos 71 remanejamentos de SR não Acima, havia um Acima num posto menor da mesma
    unidade e turno, que subiria no lugar.
  - O número de lacunas não muda. A página menor que perde o SR pode passar a ser outra.
- **Em aberto:**
  1. Troca de owner (Política de páginas por mérito, seção 4.1). Explicada ao usuário em 03/11.
     Fica **fora** da A-04, porque muda a equipe fixa e não é decidida no Gerar. Se o usuário quiser,
     vira a A-05.
  2. ~~A questão da M-01.~~ Resolvida como na A-01 (03/11).
- **Histórico:**
  - 02/11: criada e simulada no plano 93.
  - 03/11: Em ajuste, junto com as A-01 a A-03.
  - 03/11: validada (Luis Otávio), com o ok explícito para a faixa pesar além do desempate da M-01.

## Implementação (5C Escalas 1.19.0, 03/11; sem commit e sem publicação)

A implementação foi pedida pelo usuário em 03/11 ("Sim, implementar"), junto com a nota em pontos.

1. **Nota em pontos (M-01 a M-10)**, em `lib/merito.php`:
   - A M-10 (sequência de semanas batendo a meta, validada em 03/11) entrou no mesmo dia, a pedido da
     sessão de mérito: `escMeritoPtsSeq`, parâmetros `merito_pts_seq*`, colunas `pts_seq` e
     `semanas_meta`.
   - M-02: a página principal conta todas as linhas do 5C Schedule na janela, como a simulação
     validada (confirmado pela sessão de mérito em 03/11). Saiu um filtro de "horas sem falta" que
     não estava na regra.
   - `escMeritoCalcular` soma os componentes. Cada um tem a sua função (`escMeritoPtsMeta`,
     `escMeritoPtsFaltas`, `escMeritoPtsSla`) e os seus parâmetros `merito_pts_*` (`escMeritoPtsParam`).
   - `escMeritoFaixa` dá a faixa. `escMeritoMotor` entrega ao Gerar a nota, a faixa (Acima = 2,
     Na média = 1, Abaixo = 0) e os anos de casa.
   - As funções da fórmula antiga saíram (presença, pena, teto do crítico).
   - O cache `esc_sr_merito` ganha uma coluna por componente (ES_91). Sem o script, ele grava só as
     colunas que existem. O cache da fórmula antiga é refeito sozinho na primeira leitura.
2. **Script `escalas/sql/ES_91_merito_pontos_motor_alocacao.sql`** (aplicado em 03/11, na publicação da 1.19.0):
   - colunas do cache;
   - parâmetros `merito_pts_*`, `v4_melhores_meta_min` = 0.65, `v4_merito_faixa_passo0`,
     `v4_merito_abaixo_fora_topo`, `v4_merito_faixa_acumulo`, `v4_merito_faixa_remanejar` (todos 1)
     e `v4_pagina_prioritaria_min` = 100000;
   - `v4_melhores_receita_min` = 0 (P-01: todas as páginas);
   - descrição "fora de uso" nos parâmetros da fórmula antiga.

   Com cada chave `v4_merito_*` valendo 0, o passo volta à regra da 1.18.
3. **Página prioritária:** `$est['prioritaria']` em `escV4Carregar`, pela modelo com
   `v4_pagina_prioritaria_min` ou mais. Se uma P-NN definir de outro jeito, só esse trecho muda.
4. **No motor (`lib/motor_v4.php`, `escMotorAlocarV4`):**

   | Regra | Passo | O que mudou no código |
   |---|---|---|
   | A-01 e P-01 | 0 (melhor SR) | Chave `[bateu ≥ 65%, faixa, nota, anos de casa, código]`; continuidade com quem estava no posto (sai só para faixa acima ou com menos de 65% da meta na página); motivo com a faixa, os pontos e a meta |
   | A-02 | 0 e 5 | Motivo "Abaixo em página prioritária" quando ele fica por falta de outro; no passo 5 com `v4_merito_faixa_remanejar` = 0, o Abaixo vai por último para página prioritária |
   | A-04 | 5 (remanejamento) | Escolha da `$fonte` por `[faixa, −faturamento do posto]`; o SR da equipe fixa da página não sai dela |
   | A-03 | 6 e 7 | O SR Abaixo vai por último na chave `$k` |
   | M-06 | 1 a 4 | No desempate, a nota em pontos e depois os anos de casa (sem nota vale 0, e não mais 1) |

   - A equipe fixa da página, a fila, o teto da sobrecarga e as travas (só brasileiros,
     FORA/ASSISTENTE, exclusiva, On demand, nível do assistente) ficam como estavam.
   - As travas P-02, P-03 por modelo e P-04 não estão nesta versão.
5. **Telas:**
   - SRs (`pages/tertius.php`): os pontos de cada componente com o dado ao lado, a nota e a faixa.
   - Ajuda › Mérito (`pages/merito.php`): componentes, faixas, exemplos e simulador novos.
   - Também mudaram as Regras, o glossário e a FAQ da Ajuda, o Equilíbrio (aceita nota negativa) e o
     Changelog (PT e EN).
6. **Checagem:** lint nos 10 arquivos; as telas SRs, Mérito (PT e EN) e Regras renderizadas pelo
   harness; o simulador conferido com 5 casos.

### Gerar simulado (03/11, `resultados/sim_0211/`, semana de 02/11)

- Os dados são os do cache do harness. As leituras novas vieram do banco em 03/11.
- A configuração das páginas fica vazia no harness: sem exclusiva nem On demand.
- A nota em pontos é a do cálculo novo, na janela de 06/10 a 02/11, com a M-10: 46 SRs Acima, 144 Na
  média e 37 Abaixo, de 227. A M-10 deu 0 semanas a 145 SRs, 1 a 49, 2 a 19 e 3 ou mais a 14, os
  mesmos números da simulação da sessão de mérito.
- As colunas de lacunas e sobrecargas contam vagas-dia; as de faixa contam página-turnos com regular.

| Rodada | Lacunas (células) | Sobrecargas | Regular nas páginas prioritárias | Trocas de regular de um dia para o outro |
|---|---|---|---|---|
| A · regra da 1.18 | 46 | 75 | Acima 30%, Na média 61%, Abaixo 9% | 1.087 |
| B · 1.19.0 completa | 52 | 93 | Acima 38%, Na média 55%, Abaixo 7% | 1.053 |

Antes da M-10, a rodada C (1.19.0 com o passo 0 só nas prioritárias) deu 54 lacunas e 86
sobrecargas, com a mesma divisão do topo da B.

Cada regra sozinha, comparada com a A (antes da M-10):
- **Meta de 65%:** quase nada muda (26 página-turnos).
- **Passo 0 por faixa:** o topo muda como na B; lacunas iguais e +5 de sobrecarga.
- **A-04:** lacunas iguais e −1 de sobrecarga.
- **A-03:** −2 lacunas e +10 de sobrecarga.

Juntas, elas dão +3 página-turnos em lacuna na semana (de 23 para 26):
- A mediana do faturamento da modelo na lacuna fica igual (US$ 17,5 mil).
- A maior passa a ser a Blair VIP (US$ 58 mil), num turno C da U2. Com o passo 0 de cima para baixo,
  o último SR livre da unidade, que é Abaixo, ficou com a Ariana Marie, que vem antes na fila; na
  1.18, ele ia para a Blair pelo remanejamento.

## Em ajuste

_Nenhuma no momento._

## Rascunhos

_Nenhuma no momento._

## Descartadas

_Nenhuma ainda._

## Onde está o motor de hoje (só referência, não alterar daqui)

- **Gerar:** `escalas/sistema/5c_escalas/lib/motor.php` (`escMotorGerar`) chama `lib/motor_v4.php` (`escV4Carregar` e `escMotorAlocarV4`).
- **Fila dos postos:** faturamento da modelo em 30 dias. No desempate, o faturamento da página, o tipo do posto e a carga.
- **Passos de cada vaga:**
  1. Melhor SR por venda/hora nas modelos de US$ 100 mil ou mais.
  2. Equipe fixa.
  3. Equipe da vaga ou núcleo.
  4. Buffer do outro núcleo.
  5. Overlap.
  6. Remanejamento.
  7. Acúmulo.
  8. Sobrecarga.
  9. Lacuna.
- **Quem escolher no empate:** quem estava no posto, depois a equipe da página, depois quem mais trabalhou nela nas últimas 8 semanas. Por fim, a nota de mérito.
- **Mérito no motor hoje:** `lib/merito.php` (`escMerito*`). As regras M-01 a M-10 estão no código desde a 1.19.0 (03/11).
- **Restrições:**
  - FORA e ASSISTENTE: `esc_pagina_assistente`.
  - Só brasileiros: `v4_plataforma_so_brasil`.
  - Alocação da página: `esc_pagina_config.alocacao`.
  - Descanso de 11 h no "a partir de".
- **Histórico das mudanças:** `escalas/sistema/5c_escalas/lib/versao.php`.
