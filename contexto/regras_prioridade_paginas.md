# Regras de prioridade das páginas: registro, rascunho e ajuste

Este arquivo guarda as regras novas sobre a ordem em que as páginas recebem gente no Gerar e sobre o tratamento de cada página, do rascunho até a validação. Fica fora do `CLAUDE.md` da raiz para não pesar no contexto de toda sessão. Só se lê quando o assunto é prioridade ou tratamento de página.

Uma regra só sai daqui para o código, os parâmetros e o `CLAUDE.md` depois de **Validada** pelo usuário. Enquanto isso, vale a prioridade atual (ver "Onde está a prioridade de hoje").

## Como usar

1. Regra nova entra em **Rascunhos**, com o modelo abaixo e o próximo ID livre (P-01, P-02...).
2. Em discussão ou simulação, vai para **Em ajuste**.
3. Com o ok do usuário, vai para **Validadas**, com a data e quem validou.
4. Quando for implementada (código, script ES_NN, parâmetro), registrar onde e em qual versão do módulo.
5. Regra abandonada vai para **Descartadas**, com o motivo. O ID não é reaproveitado.

Cada mudança de uma regra entra no "Histórico" dela, com a data. Não apagar o texto de uma regra validada: marcar o que mudou.

### Modelo

```markdown
### P-NN · <nome curto>
- **Status:** Rascunho | Em ajuste | Validada em DD/MM/AAAA | Implementada na X.Y.Z | Descartada
- **Pedido por:** <quem> em DD/MM/AAAA
- **Objetivo:** <o que a regra resolve, em uma frase>
- **Páginas:** <todas, um grupo (tier, plataforma, agência, modelo) ou uma lista>
- **Regra:** <a ordem de preenchimento ou o tratamento da página, em linguagem direta>
- **Tratamento:** <solo/exclusiva, com assistente, sob demanda, equipe fixa, desconsiderada, só brasileiros...>
- **Dados e fontes:** <tabelas, colunas, período>
- **Parâmetros:** <chave em esc_parametro ou coluna de configuração, valor proposto>
- **Exemplo:** <caso real ou simulado, com o antes e o depois>
- **Impacto esperado:** <quais páginas sobem ou descem na fila, efeito em lacunas e na capacidade>
- **Conflitos:** <com quais regras ou equipes fixas a regra briga e qual vence>
- **Em aberto:** <dúvidas para o usuário>
- **Histórico:**
  - DD/MM: <o que mudou>
```

## Validadas

As regras P-01 a P-04 foram pedidas pelo Luis Otávio em 03/11/2026 e **validadas por ele em 03/11/2026**. As decisões dele estão em cada regra. A implementação no motor ainda não foi feita (ver "O que a implementação exige"); os dados já estão no banco (ES_85 a ES_90) e a P-03 já vale no Gerar.

**Simulações de 03/11 (só leitura):**
- 103 páginas da carteira vigente (`fc_pagina_carteira`);
- plano 93 (semana de 02/11);
- 5C Schedule de 05/08 a 02/11 (90 dias).

**CSVs em `resultados/`:**
- `prioridade_paginas_fila_0311.csv`: a fila, com plataforma e "não divide rep". Ainda tem a cor da hipótese antiga (1 = vermelha); a cor certa está em `esc_pagina_config`.
- `prioridade_paginas_cobertura_flags_0311.csv`: quem conhece as páginas vermelhas e amarelas, por unidade e turno, com o critério de 7 dias ou mais em 90.
- `prioridade_paginas_flags_telegram_0311.csv`: as bandeiras lidas no Telegram.

**Dados gravados:** `escalas/sql/ES_85_sensibilidade_plataforma_paginas.sql`, aplicado em 03/11, o ES_86 (Savannah = Fanvue) e o ES_88 (as 7 páginas sem plataforma no site, OnlyFans). Ele criou em `esc_pagina_config` as colunas `sensibilidade`, `sensibilidade_origem`, `plataforma` e `plataforma_origem`, preenchidas para as 103 páginas. O motor ainda não lê essas colunas.

### Ordem de aplicação (validada em 03/11)

1. **Travas, que filtram quem pode ir para a página:**
   - nacionalidade: Privacy só com SR brasileiro;
   - plataforma (P-04);
   - não divide rep (P-03) e exclusiva;
   - fora da página ou só assistente;
   - página vermelha (P-02).
2. **Equipe fixa da página:** reserva a pessoa para a página dela. Vem antes do melhor SR, como já está no código.
3. **Melhor SR, de cima para baixo (P-01):** inclui a regra de continuidade e a cota da página amarela (P-02).
4. **Passos de preenchimento:** núcleo, buffer, Overlap, remanejamento, acúmulo e sobrecarga. Todos respeitam as travas do item 1. O que sobra fica em lacuna.

### O que a implementação exige

**Feito em 03/11:**
- **Dados:**
  - ES_85: cor e plataforma das 103 páginas;
  - ES_86: Savannah = Fanvue;
  - ES_87: EXCLUSIVA nas 11 modelos que não dividem rep, e Yuni Yung com posto próprio, com o Capacity recalculado;
  - ES_88: as 7 páginas sem plataforma no site confirmadas como OnlyFans;
  - ES_89: Maddie e Haven Free verdes;
  - ES_90: Cynthia e Alix Lynx amarelas.
- **Tela Páginas** (`pages/paginas.php` e `lang/en/paginas.php`):
  - cor e plataforma editáveis junto com o SLA e a alocação;
  - filtros Vermelhas, Amarelas e "Fora do OnlyFans";
  - contagem de vermelhas e amarelas;
  - origem de cada valor (Telegram, cadastro do cliente, site ou manual, com nome e data).
  - A origem só muda quando o valor muda. Ainda não foi publicada (versão a definir).

**Falta (motor e telas, versão nova):**
1. **Travas de candidato em todos os passos** (`motor_v4.php`):
   - plataforma por turno (P-04);
   - não divide rep por modelo, e não mais por posto (P-03);
   - vermelha: 7 dias ou mais em 90, ou equipe fixa (P-02).
2. **Passo 0 para todas as páginas, pela nota de mérito (P-01):**
   - continuidade (15% relativa; piso de 65% de atingimento na janela do mérito);
   - reserva de quem conhece a vermelha;
   - cota de 20% da amarela por turnos da semana.
3. **Parâmetros novos** (`esc_parametro`, por um ES_NN): `v4_vermelha_dias_min`, `v4_vermelha_janela_dias`, `v4_amarela_novos_max`, `v4_troca_merito_dif` e `v4_troca_atingimento_piso`. O passo 0 deixa de ter o corte de `v4_melhores_receita_min`.
4. **Capacity:** não agrupar páginas de plataformas diferentes no mesmo posto.
5. **Grade:** alerta quando a alocação manual quebra uma trava.
6. **Equilíbrio:** "Páginas sem quem as conheça" usando 7 dias em 90 para as vermelhas.
7. **Textos:** Ajuda e Changelog; o texto da ES_58 (1.18.0) passa a dizer que a equipe fixa vem antes do melhor SR.

### P-01 · Melhor SR para todas as páginas, de cima para baixo
- **Status:** Validada em 03/11/2026 (Luis Otávio). Ainda não implementada.
- **Pedido por:** Luis Otávio em 03/11/2026
- **Objetivo:** a página mais importante da fila fica sempre com o melhor SR disponível. As últimas ficam com o que sobra.
- **Páginas:** todas, menos as On demand. Hoje o passo 0 vale só para as modelos com US$ 100 mil ou mais (16 modelos, 25 páginas).
- **Regra:**
  - Em cada unidade, turno e dia, a primeira página da fila escolhe o melhor SR disponível, depois a segunda, e assim até a última.
  - A ordem da fila não muda: faturamento da modelo em 30 dias, depois o da página.
  - O corte de `v4_melhores_receita_min` deixa de existir.
  - **"Melhor" é a nota de mérito** (M-01, nota em pontos, validada em 02/11), não mais a venda por hora e a meta.
  - **Continuidade:** quem estava no posto da última vez fica, a não ser que:
    - o melhor SR disponível tenha nota de mérito pelo menos 15% maior, em diferença relativa (nota 100 contra 115 troca; 100 contra 114 não); ou
    - quem estava esteja abaixo de 65% de **atingimento da meta na página**, na janela do mérito (`merito_janela_dias`, 28 dias até ontem).
- **Tratamento:** —
- **Dados e fontes:** nota de mérito (`esc_sr_merito` ou a nota nova da M-01) e o "quem estava da última vez" (`$ultimo` do motor).
- **Parâmetros:**
  - `v4_melhores_receita_min` = 0, ou um parâmetro novo para ligar a regra;
  - `v4_troca_merito_dif` = 0,15;
  - `v4_troca_atingimento_piso` = 0,65 (atingimento da meta na página).
- **Exemplo:** em U1·A, a Mikayla Free escolhe primeiro, depois a Mikayla Paid, depois a Rocket Reyna VIP, e assim por diante. A Blaire (US$ 2 mil) fica com quem sobrou.
- **Impacto esperado:**
  - As páginas de cima ganham as maiores notas.
  - As de baixo ficam com o resto ou em lacuna.
  - A continuidade evita trocar a pessoa todo dia.
- **Conflitos:**
  - **Equipe fixa:** vem antes e reserva a pessoa (decisão de 03/11). Na ES_58, o que precisa de correção é o texto, não a ordem: o changelog da 1.18.0 e o comentário do passo 0 dizem que o melhor SR vem antes da equipe fixa.
  - **P-02:** a página de cima não leva o último SR disponível que conhece uma página vermelha mais abaixo.
  - **P-03:** o SR que fica numa modelo que não divide rep sai do resto da fila no turno.
- **Em aberto:**
  - Nada.
- **Histórico:**
  - 03/11: rascunho.
  - 03/11: decididos a equipe fixa antes do melhor SR, a nota de mérito como critério e a continuidade (15% de diferença e piso de 65%). Passou para Em ajuste.
  - 03/11: o piso de 65% é do atingimento da meta na página.
  - 03/11: os 15% são de diferença relativa, e o atingimento usa a janela do mérito. Nenhum ponto em aberto.
  - 03/11: validada pelo Luis Otávio.

### P-02 · Sensibilidade da página (vermelha, amarela, verde)
- **Status:** Validada em 03/11/2026 (Luis Otávio). Dados no banco (ES_85, ES_89 e ES_90); o motor ainda não usa.
- **Pedido por:** Luis Otávio em 03/11/2026
- **Objetivo:** proteger as páginas sensíveis, deixando nelas quem já as conhece.
- **Páginas:** todas, pela cor de cada uma.
- **Regra:**
  - **Vermelha:** só SR que já trabalhou na página, ou seja, **7 dias ou mais nos últimos 90**, ou que é da equipe fixa dela (`esc_pagina_prioridade`, decisão de 03/11). Sem ninguém assim disponível, **fica em lacuna**.
  - **Amarela:** no máximo **20% dos turnos de SR da semana** na página com quem não a conhece. Com 1 pessoa por posto, isso dá cerca de 1 turno por semana.
  - **Verde:** aloca pela regra de mérito.
- **Tratamento:** trava de candidato, no item 1 da ordem. A cor não muda a posição na fila.
- **Dados e fontes:** `esc_pagina_config.sensibilidade`, gravada pelo ES_85.
  - **Ordem das fontes:**
    1. a bandeira da última mensagem do Telegram "Movement Alert" para a página (🟢 verde, 🟡 amarela);
    2. `5c_wfm_db.client.flag`: 1 = verde, 2 = amarela, 3 = vermelha (decisão de 03/11).
  - **Resultado nas 103 páginas:**
    - verde: 55 (46 pelo Telegram, 7 pelo `client`, mais Maddie e Haven Free, definidas pelo usuário no ES_89);
    - amarela: 34 (32 pelo `client`, mais Cynthia e Alix Lynx, definidas pelo usuário no ES_90);
    - vermelha: 14;
  - **Vermelhas:** Abg, Angel, CJ Miles, CJ VIP, Draya, Kaylin, Madison Moore VIP e Free, Maya, Molly Stewart VIP, zOFTV Molly Stewart, Natasha Nice VIP, Nicole Doshi e Nicole OFTV.
- **Parâmetros:**
  - `v4_vermelha_dias_min` = 7;
  - `v4_vermelha_janela_dias` = 90;
  - `v4_amarela_novos_max` = 0,20.
- **Exemplo (cobertura por unidade e turno, 7 dias ou mais em 90):**
  - **Vermelhas:** 13 páginas por turno, ou seja, 36 combinações de página e turno. Em 13 delas há menos gente que conhece do que o necessário, e em 6 não há ninguém:
    - Draya A, C e D (U2);
    - Madison Moore VIP e Free D (U3);
    - Nicole Doshi C (U2), que precisa de 3.
  - **Amarelas:** 78 combinações. Em 32 há menos gente que conhece do que o necessário, e em 7 não há ninguém:
    - Lexis Star A e C;
    - Rebecca A e C;
    - Taylor Ballard C e D;
    - Tiff C.
- **Impacto esperado:**
  - As vermelhas sem conhecedor viram lacuna até alguém trocar de unidade, como no Equilíbrio, ou ganhar dias na página.
  - O Equilíbrio já mostra "Páginas sem quem as conheça" com 3 dias em 8 semanas. Para a vermelha, o certo é usar 7 dias em 90.
- **Conflitos:**
  - Com a P-01, resolvido pela reserva de quem conhece a vermelha.
  - **Telegram contra `client`.** Pela ordem decidida, a bandeira da entrada vence a do `client`. Quatro páginas são 3 (vermelha) no `client` e ficaram verdes pelo Telegram: Cynthia (🟢 em 21/10), Alix Lynx (🟢 em 27/08), Mia Melons e Tiny (🟢 em 18/08). Decisão de 03/11: Cynthia e Alix Lynx ficam amarelas (ES_90); Mia Melons e Tiny ficam verdes. E 43 páginas são 2 (amarela) no `client` e ficaram verdes.
- **Em aberto:**
  - Nada.
- **Histórico:**
  - 03/11: rascunho.
  - 03/11: decididos as cores do `client` (1 verde, 2 amarela, 3 vermelha), o Telegram como primeira fonte, os 7 dias em 90, a lacuna na vermelha e o 80/20 por turnos da semana. ES_85 aplicado. Passou para Em ajuste.
  - 03/11: quem é da equipe fixa da página conta como conhecedor, também na cota da amarela.
  - 03/11: Maddie e Haven Free definidas como verdes (ES_89).
  - 03/11: o Telegram continua sendo a primeira fonte. Cynthia e Alix Lynx foram definidas como amarelas (ES_90).
  - 03/11: validada pelo Luis Otávio.

### P-04 · Uma plataforma por SR
- **Status:** Validada em 03/11/2026 (Luis Otávio). Dados no banco (ES_85, ES_86 e ES_88); o motor ainda não usa.
- **Pedido por:** Luis Otávio em 03/11/2026
- **Objetivo:** o SR não mistura plataformas. A Privacy só faz Privacy, a Fanvue só faz Fanvue, e assim por diante.
- **Páginas:** todas, pela plataforma (`esc_pagina_config.plataforma`):
  - OnlyFans: 98 páginas. Destas, 7 não têm plataforma no site e foram confirmadas como OnlyFans pelo usuário em 03/11 (ES_88): Ariana Marie, Blair OFTV 155t, Britt OFTV, Haven Free, Lexis Star, Maddie e Rebecca;
  - PlayBoy: Silvia Saige VIP e Free;
  - Privacy: Aline Novak;
  - Fanvue: Ivy Vale e Savannah. A Savannah foi corrigida à mão pelo ES_86, porque o site a mostra como Onlyfans.
- **Regra:**
  - **Trava:** dentro do turno, todas as páginas do SR são da mesma plataforma.
  - **Preferência:** a mesma plataforma na semana inteira, pela estabilidade, sem ser trava.
  - Vale para o agrupamento de postos (Capacity), o acúmulo (passo 6), a sobrecarga (passo 7) e a alocação manual (alerta na Grade).
- **Tratamento:** trava de candidato.
- **Dados e fontes:** `5c_pages.pages.platform`, copiado pelo ES_85 com a origem de cada valor.
- **Parâmetros:** `esc_pagina_config.plataforma`, a ser editável na tela Páginas.
- **Exemplo:** no plano 93, 58 blocos de SR misturaram plataformas.
  - **Aline Novak (Privacy) com páginas OnlyFans: 38 blocos.** Entram Sophie Sierra, Keera, Draya, Matty Caron, CJ Miles, Ellie D, Nicole Doshi, Sean Adams, Sarah H e Apex.
  - **Ivy Vale e Savannah com OnlyFans: 20 blocos.** Entram Kas, Melody, Mandi CAT e Abg.
- **Impacto esperado:**
  - O SR da Aline e o da Ivy Vale deixam de cobrir páginas OnlyFans no turno, o que aumenta as lacunas da U2 e da U3.
  - A Silvia Saige fica sem acúmulo com OnlyFans.
- **Conflitos:**
  - Com a sobrecarga por agência (ES_59): a plataforma é uma condição a mais.
  - **Savannah:** resolvido em 03/11. Ela é Fanvue (ES_86), então o posto fixo com a Ivy Vale (U3C2 grupo 2) e a equipe fixa da Fanvue continuam como estão. O site (`5c_pages.pages.platform`) ainda a mostra como Onlyfans e precisa ser corrigido na origem.
- **Em aberto:**
  - Nada.
- **Histórico:**
  - 03/11: rascunho.
  - 03/11: decidido que a trava é dentro do turno, com preferência pela semana inteira. ES_85 aplicado. Passou para Em ajuste.
  - 03/11: Savannah = Fanvue (ES_86).
  - 03/11: as 7 páginas sem plataforma no site confirmadas como OnlyFans (ES_88). Pornhub, X e MYM continuam fora da escala. Nenhum ponto em aberto.
  - 03/11: validada pelo Luis Otávio.

### P-03 · Não divide representante com outra modelo
- **Status:** Validada em 03/11/2026 (Luis Otávio). Gravada pelo ES_87 e já vale no próximo Gerar, ainda com a trava por posto (a trava por modelo depende do motor).
- **Pedido por:** Luis Otávio em 03/11/2026
- **Objetivo:** nas modelos sensíveis ou grandes, o SR fica só nas páginas da própria modelo.
- **Páginas:** todas as páginas destas modelos:
  - Mikayla (Free e Paid);
  - Cynthia;
  - Elly Clutch;
  - Nicole Doshi;
  - Midwest Emma;
  - Liv;
  - Molly Stewart;
  - Holly Jane;
  - Britt;
  - Ariana Marie;
  - Madison Moore;
  - Sisi Rose, que já é exclusiva desde a ES_82.
- **Regra:**
  - O SR de uma página dessas, no turno, só tem páginas da mesma modelo. Mikayla Free e Paid podem ficar juntas.
  - Ele não acumula (passo 6), não cobre (passo 7) e não entra em posto agrupado com outra modelo.
  - A página dessas modelos também não vai para o SR de outra modelo.
- **Tratamento:** "não divide rep", no nível da modelo.
  - Hoje a EXCLUSIVA (solo) trava por página.
  - Proposta: a EXCLUSIVA passa a valer por modelo, com as páginas da mesma modelo liberadas. Para a Sisi Rose, que tem uma página só, nada muda.
- **Dados e fontes:** `esc_pagina_config.alocacao` e `fc_pagina_carteira.modelo`.
- **Parâmetros:** `alocacao` = EXCLUSIVA nas páginas dessas modelos, menos nas On demand (zOFTV Molly Stewart, Britt OFTV, zOFTV Diana Rider), que continuam On demand. Quando pedem, a alocação manual dessas OFTV costuma ser um SR do buffer só para responder as pessoas.
- **Exemplo:**
  - Só um posto fixo mistura uma dessas modelos com outra: **Ariana Marie + Yuni Yung** (U2C1, grupo 5).
  - Separar os dois dá +1 posto por turno, cerca de 4,5 SRs a mais na U2.
  - No plano 93, 138 blocos de SR juntaram uma dessas modelos com outra:
    - Ariana + Yuni: 42;
    - Midwest Emma + Victorya: 24;
    - Sisi + Blaire, Maya ou Charly: 30, antes da ES_82;
    - Liv com Liam, Katie Flood e outras: 22;
    - Nicole Doshi com Apex, Draya, Sophie Sierra, Keera, Matty Caron, Ellie D e Aline Novak: 20.
- **Impacto esperado:** esses blocos deixam de existir. A página que dividia o SR busca outra pessoa ou vira lacuna.
- **Conflitos:**
  - Com a P-01: o melhor SR que fica numa dessas modelos sai do resto da fila no turno.
  - Com a sobrecarga: menos gente para cobrir lacunas.
- **Em aberto:**
  - Nada.
- **Histórico:**
  - 03/11: rascunho.
  - 03/11: decidido que o 2º SR dos postos de 2 pessoas também é exclusivo.
  - 03/11: ES_87 aplicado.
    - EXCLUSIVA em 14 páginas: Mikayla Free e Paid, Cynthia, Elly Clutch 100m, Nicole Doshi, Nicole OFTV, Liv, Midwest Emma Free 221s, Molly Stewart VIP, Holly Jane, Britt VIP, Ariana Marie, Madison Moore VIP e Free. Com a Sisi Rose, são 15.
    - A Yuni Yung saiu do posto da Ariana Marie para um posto próprio (U2C1 grupo 10). A U2C1 passou de 11 para 12 postos por turno; o Capacity foi recalculado em 03/11.
    - Na 1.18.14 a EXCLUSIVA trava por posto. Até o motor travar por modelo, o SR da Mikayla Free também não acumula a Mikayla Paid.
  - Passou para Em ajuste.
  - 03/11: a OFTV On demand dessas modelos não fica presa ao SR da página principal. Quando pedem, normalmente vai um SR do buffer só para responder as pessoas. Nenhum ponto em aberto.
  - 03/11: validada pelo Luis Otávio.

## Em ajuste

_Nenhuma no momento._

## Rascunhos

_Nenhuma no momento._

## Descartadas

_Nenhuma ainda._

## Onde está a prioridade de hoje (só referência, não alterar daqui)

- **Ordem de preenchimento:** `escalas/sistema/5c_escalas/lib/motor_v4.php`, em `escMotorAlocarV4`.
  - Prioridade do posto: o faturamento da modelo nos 30 dias (`v4_receita_dias`), com todas as páginas dela somadas.
  - Desempate: o faturamento da própria página e, depois, o tipo do posto (titular, double, apoio, assistente) e a carga.
- **A fila é por unidade e turno.** Cada unidade preenche os próprios postos com a própria gente. As unidades só se misturam no Overlap (passo 4).
- **Passos de cada vaga, na ordem do código** (conferido em 02/11 na 1.18.14):
  - Antes de tudo, a equipe fixa da página (`esc_pagina_prioridade`). Ela vem antes do passo 0, embora o comentário do passo 0 e o changelog da ES_58 digam o contrário.
  0. Melhor SR (maior venda/hora; primeiro quem bateu a meta na página), só nas modelos com `v4_melhores_receita_min` (US$ 100 mil) ou mais. Não vale na transição.
  1. Equipe da vaga (`esc_vaga_equipe`): a preferência do núcleo dono ou de cobertura.
  2. Outra pessoa do mesmo núcleo, quem conhece a página primeiro.
  3. Buffer do outro núcleo em serviço.
  4. Overlap (dia de treinamento), da mesma unidade e depois das outras.
  5. Remanejamento: o SR da página de menor faturamento sobe para a de maior que está vazia.
  6. Acúmulo do posto inteiro, dentro da Política (mesma modelo até 3; modelos diferentes até 2).
  7. Sobrecarga, página a página, só da mesma agência, até `v4_over_capacity_max` (30%).
  - O que sobrar fica como lacuna.
- **Página On demand:** sai do posto antes da fila. Se o posto fica vazio, ele não abre vaga.
- **Página exclusiva:** o SR dela não entra nos passos 6 e 7 e o posto dela não vai para outro.
- **Tratamento por página, na tela Páginas** (`esc_pagina_config`):
  - `sla_modo`: AUTO, NORMAL ou DESCONSIDERAR. A página desconsiderada fica sem SLA, com carga zero e no fim da fila.
  - `alocacao`: EQUIPE, SOB_DEMANDA (ES_79) ou EXCLUSIVA/solo (ES_82).
  - `sensibilidade` e `plataforma`, com a origem de cada valor (ES_85, 03/11): só dados por enquanto; o motor ainda não lê.
- **Equipe fixa:** `esc_pagina_prioridade`. A pessoa vai para a unidade da página. Fanvue e Privacy vencem as outras equipes.
- **Só assistente ou fora da página:** `esc_pagina_assistente` (ASSISTENTE/FORA).
- **Plataforma só com SR brasileiro:** o parâmetro `v4_plataforma_so_brasil` (Privacy).
- **Quantas pessoas por posto (assistente):** `5c_planning.fc_posto_fixo.pessoas`. Depois de mudar, recalcular com `sp_fc_recalcular_postos`.
- **Política de páginas por mérito:** `escalas/Politica_paginas_merito_2026-10-30.md`.
- **Histórico das mudanças:** `escalas/sistema/5c_escalas/lib/versao.php`.
