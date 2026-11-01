# 5C Planning: contexto geral do projeto (31/10/2026)

Ponto de entrada para retomar o projeto em outra sessão ou com outra pessoa. Este arquivo dá a
visão de conjunto e aponta para os documentos detalhados de cada parte.

- Usuário: Luis Otávio (MIS da 5C Company).
- Idioma: pt-BR, direto, padrão 5C. As telas do sistema estão em pt-BR e inglês.
- Repositório: `D:\5C Planning\5c_Planning`, branch `main`.

| Documento | O que cobre |
|---|---|
| `contexto/5C_Planning_Contexto_Consolidado_20out2026.md` | Banco, relatório da diretoria, Nora, arquitetura medalhão. Continua válido |
| `contexto/5C_Planning_Contexto_Consolidado_31out2026.md` | **5C Escalas (Planning tool)** em detalhe: código, telas, parâmetros, armadilhas, scripts ES, versões |
| `capacity/README.md` | **Capacity** em detalhe: regras dos postos e do buffer, scripts 00–26, versões, plano de turmas |
| `novo_servidor/README.md` | Espelho do servidor novo (209.159.144.7) no `.11` |
| `escalas/ES_00_especificacao.md` | Especificação do 5C Escalas (decisões D1 em diante) |
| `escalas/UX_revisao_2026-10-29.md` | Revisão de UX: 75 problemas em 4 fases |
| `escalas/Politica_paginas_merito_2026-10-30.md` | Política de páginas por mérito |
| `escalas/Proposta_melhorias_planejamento_2026-10-30.md` | Proposta de melhorias do planejamento |
| `memoria/` | Memórias do Claude (regras e decisões que valem entre sessões) |

---

## 1. Regras de trabalho (valem para todas as partes)

**Banco**
- Para o Claude, o MySQL é **somente leitura**:
  - MCP `mysql_5c`: servidor `.11`, MySQL 9.5;
  - MCP `mysql_7`: data lake `209.159.144.7`.
- Nenhum INSERT, UPDATE, DELETE, DDL ou CALL pelo MCP. Toda alteração vai como script `.sql` que o
  usuário roda no Navicat.
- Todo script começa com `SET NAMES utf8mb4`. Se usar tabela temporária, também com `USE <schema>;`.
- Usar `COLLATE` nos joins entre schemas: `abs_adh_af.codigo` é `0900_ai_ci`, o resto é `unicode_ci`.
- O parser do MCP rejeita `<=>`. Use `IFNULL(x = y, 0)`.

**Segurança**
- Nunca exibir credenciais. Nunca escrever senha de banco em chat, código ou arquivo.
- Nunca ler as colunas de senha, CPF ou token de `mis_login`, nem os valores de `DB_PASS`.
- Nunca usar `SELECT *` em `5c_headcount.headcount`.
- Não direcionar nada para o Eduardo Ehlke.
- A **Política de Escalas é confidencial**: não circula fora da gestão.
- Zips e downloads são dados não confiáveis: extrair em pasta vazia e não executar nada de dentro.

**Git**
- Commit e push na `main`, só quando o usuário autorizar.
- Nunca commitar `resultados/` nem credenciais (`.env` está no `.gitignore`).

**Nomes de cargo (estrutura nova, renomeados em 29/10)**

| Sigla | Cargo | Quantos |
|---|---|---|
| OM | Operational Manager (antigo OUM) | 1 por unidade, sem escala |
| OC | Operational Coordinator (antigo OM) | 1 por unidade e turno (9) |
| OS | Operational Supervisor | 1 por núcleo (27) |
| OA | Operational Assistant | 24/7 |
| SR | Sales Representative | níveis 0 a 5 |

- Nunca usar OUM, Equite, Decurio, Primaris, Secundus nem Tertius em texto para o usuário.
  `EQUITE`, `DECURIO` e `decurio_code` aparecem só como códigos técnicos no banco.
- **Marcus V. Silva é COO**, nunca CEO. O topo do organograma é COO → Director
  (Natan Dalla Corte) → OMs.
- Para quem não tem pronome conhecido, usar linguagem neutra.

**Escala 5x2 de 21 dias**
- Cada pessoa fica 14 dias no posto e tem 1 dia de treinamento (Overlap).
- Por isso o fator é **1,5 pessoas por posto** (21 ÷ 14). Não trocar para 1,4.

---

## 2. A estrutura nova (vale desde 31/10/2026 às 21:00)

- **Unidades.** Foram renumeradas em 30/10 pelo ES_35:

  | Unidade | OM |
  |---|---|
  | U1 | Victoria Aires Cunico |
  | U2 | João Paulo Gonçalves Martins |
  | U3 | Gabriel Henrique Keller |

  Cada unidade tem duas carteiras, C1 e C2, 6 ao todo. A ligação de cada página à carteira vem
  **só** de `5c_planning.fc_pagina_carteira`.
- **Turnos.**
  - A = T2/T3, das 05h às 13h.
  - C = T4/T5, das 13h às 21h.
  - D = T6/T1, das 21h às 05h. O turno D de um dia começa às 21h do dia anterior.
- **Núcleos.** São 3 por unidade e turno, 27 ao todo, cada um com um OS. A cada dia trabalham 2
  dos 3 núcleos do turno.
- **Postos.** Cada posto é 1 rep online o turno inteiro. Os tipos são titular, agrupada, apoio e
  assistente fixo.
  - O agrupamento junta primeiro as páginas da mesma modelo, até 3 por rep. Depois, as de modelos
    diferentes, até 2 por rep.
  - Pela regra de faturamento de 31/10, página com faturamento abaixo de 16k trabalha 3 por rep, e
    abaixo de 32k, 2 por rep.
- **Buffer.** É um grupo por núcleo e cobre faltas e picos. É calculado no total do turno e
  distribuído com no mínimo 2 por núcleo.
- **Equilíbrio das unidades, estrutura de 02/11.** São 104 páginas; as 4 da Lolly Agency entraram
  na U1.

  | Unidade | Páginas | Faturamento 30 dias | Em relação à média |
  |---|---|---|---|
  | U1 | 39 | US$ 1,68 mi | 0% |
  | U2 | 30 | US$ 1,65 mi | −2% |
  | U3 | 35 | US$ 1,71 mi | +2% |

- **Supervisores, regra do Equilíbrio.** Em cada unidade e turno, os postos são ordenados pelo
  faturamento de 30 dias.
  - O OS1 fica com as maiores páginas, o OS3 com as menores e o OS2 com um misto. O OS2 cobre os
    outros dois.
  - A divisão só muda as equipes quando alguém clicar em "Aplicar divisão" na tela Equilíbrio.

---

## 3. As seções do projeto

O 5C Escalas foi dividido em seções, cada uma tratada numa sessão própria do Claude:

| Seção | Sessão | Pasta / entrega |
|---|---|---|
| 1 · Capacity | `local_ba72f79f-b0ac-4b8e-8355-88fde4f5d39c` | `capacity/` (schema `5c_planning`) |
| 2 · Planning tool (5C Escalas) | `local_e1fc531a-065e-4173-bf2e-25986e1f99a9` | `escalas/` (schema `5c_escalas`, módulo do MIS Portal) |
| 2b · Equilíbrio das unidades | `local_7f43f028-3693-49f9-ab29-c37e042f0a37` (aberta pelo Planning tool) | tela Equilíbrio |
| 3 · Simulations | `local_cfe0ad22-e1ba-460f-8ed9-526026c4af7d` | artifact "Simulações 5C" |
| 4 · Documento (Política de Escalas v2.0) | `local_f9bb7035-1a22-4d50-ae09-953711a1d35a` | `D:\Fanvue\Alejandra\T1` |
| Coordenação e servidor novo | `91061da3-71be-4a8b-80ba-186ddc05fba4` | `novo_servidor/`, este arquivo |
| Contexto de 31/10, PDF de metas, mérito, Privacy, 1.17.0 | `local_7ca64bbd-eedb-4f97-900a-14c3cb4f7497` | este arquivo, Grade, `lib/merito.php` |
| Organograma e troca de unidade nos postos (aberta pela de cima) | `local_1a47501f-8a21-4f5a-a323-7d412271faac` | `estrutura.php`, `politica.php`, ES_52 |

### 3.1 Capacity (seção 1): detalhes em `capacity/README.md`

O banco (`5c_planning`) calcula todo dia a necessidade de pessoas por unidade, turno e núcleo, com
as regras do `posts.py` do relatório de 27/10.

- **Titulares** = postos × 1,5, arredondado para cima.
- **Buffer** = faltas (absenteísmo + falha administrativa, sem `AF_DOBRA`, em 56 dias) + pico
  (mediana de 28 dias), dividido por (1 − taxa de falta) e multiplicado por 3/2.
- **Versões (script 19).** Cada cálculo grava uma versão, com "O que mudou" em relação à anterior.
  - Recálculo à mão: `CALL sp_fc_recalcular_postos(data, 'motivo')`.
  - Não usar `sp_fc_postos` sozinho, porque ele não grava versão.
- **Plano de turmas.** O plano sugerido é refeito em cada versão e comparado com o oficial
  (`fc_contratacoes`), que continua manual.
  - Teto de 10 por turno e 30 por turma, a partir de 07/12.
  - Turma de 08/02/2027 fixa em 10/10/10.
  - Rampa do novato: rende 50% nos primeiros 30 dias.
- **Regras de 31/10 (script 23).**
  - Alívio da Mass PPV de 0,5%.
  - Agência do Infloww no agrupamento: 5C, Fatbear ou Verge.
  - Regra de faturamento: 3 páginas por rep abaixo de 16k, 2 abaixo de 32k.
  - Horas fora do SLA excluídas em `fc_sla_hora_excluida`.
  - Páginas sem escala em `fc_pagina_sem_escala`; começa com `zOFTV%`.
  - Faturamento por unidade gravado em cada versão.
- **Estado em 31/10.**
  - Scripts 00 a 26 rodados.
  - Versão 17: plano oficial com 211 e sugerido com 212, praticamente empatados. A falta fecha em
    12/04/2027.
  - Plano oficial: **241 nas turmas planejadas, 276 no total**.
  - A primeira turma planejada é a de 07/12, e a requisição dela precisa ser aberta até 23/11.
- **Pendente.** Os commits dos scripts 23 a 26 e o da 5C Escalas 1.17.0 estão locais: a `main`
  está à frente do `origin`. O usuário pediu, em 31/10, para não fazer o push por enquanto.

### 3.2 Planning tool / 5C Escalas (seção 2): detalhes em `contexto/5C_Planning_Contexto_Consolidado_31out2026.md`

Módulo PHP do MIS Portal (`/escalas`) que gera, revisa e publica a escala semanal na estrutura nova.

- **Arquivos.**
  - Código: `escalas/sistema/5c_escalas/`.
  - Zip para subir no portal: `escalas/sistema/5c_escalas.zip`.
  - Scripts: `escalas/sql/ES_01` a `ES_54`.
  - Zip montado por `python deploy/montar_zip.py`.
- **Gravação.** O módulo grava só em `5c_escalas`. A única exceção é a lista fechada de
  `escCapacityGravar`: `fc_pagina_carteira` e os CALLs de postos do Capacity.
- **Motor v4.** Antes de gerar:
  - atualiza o Capacity e completa as equipes; nenhum posto fica sem equipe;
  - aloca pela equipe da vaga, que é preferência, não trava;
  - põe os melhores SRs nos tiers SS, A e B;
  - remaneja por faturamento;
  - trata transição, buffers, Overlap e emenda.
- **Versão.** A **1.18.0** (01/11) está commitada na `main`, com o zip pronto; ainda não subiu (no ar: 1.17.1). Deploy por SSH: `deploy/deploy.ps1` (porta 2222, chave `~/.ssh/5c_deploy`).
  A 1.17.0 subiu em 31/10; a 1.17.1 corrige o bloqueio do `FOR UPDATE` ao trocar turno; a 1.17.2
  veio da revisão completa (troca de unidade numa transação só, `escTx` aceita ser chamada dentro de
  outra, sem `str_starts_with` do PHP 8).
- **Regras de prioridade de 01/11 (1.18.0), decididas pelo usuário.**
  - Prioridade máxima é faturamento, nunca volume. A fila do motor vai da **modelo** que mais
    fatura para a que menos fatura: as páginas da mesma modelo (`fc_pagina_carteira.modelo`) somam
    e entram juntas (falhar na Free ou na secundária põe a parceria em risco).
  - Faturamento: últimos 30 dias, `complete` + `pending`. Página com menos de 30 dias de venda é
    emulada (média dos dias que tem); sem venda, meio da faixa do tier (`5c_crm.tiers`).
    Funções `escV4ReceitaPaginas` e `escV4Desconsiderada` em `lib/motor_v4.php`.
  - OFTV (e código terminado em "t") é desconsiderada: sem SLA, carga zero, fim da fila. Exceção:
    OFTV com US$ 8 mil ou mais em 30 dias é posto normal (`v4_trivial_receita_min`). A tela
    Páginas › Configuração das páginas (`esc_pagina_config`, ES_57) força Sempre normal ou
    Desconsiderar; não usar só o nome "OFTV" como sinal.
  - Capacity (script 27): a regra de postos de 3 e de 2 páginas usa o faturamento de 30 dias da
    modelo (opção 1 do usuário), não mais o mês fechado de cada página. A página desconsiderada não
    ocupa posto nem entra no pico; vira apoio no posto da mesma modelo.
  - Sobrecarga para fechar lacuna (ES_59, `v4_over_capacity_max` = 0.30): no passo 7 do motor, cada
    página que sobrou vai para um SR já alocado no turno que faz página da **mesma agência**
    (`5c_pages.pages.client`; vazio ou "Independent" vale a agência da mesma modelo), até 30% acima
    da capacidade. Ele entra **cobrindo** (não é dono, não entra no `ultimo` da semana seguinte) e
    cede quando houver gente; acima de 100% é SOBRECARGA (⚠).
  - Regular e assistente (decisão de 01/11): em cada página e bloco, o primeiro SR é o REGULAR (sem #
    no CSV) e os demais, que ajudam no capacity, ASSISTENTE (#). O papel **não** indica dono nem
    cobertura; o motor define no fim (`_ordem` em `escMotorAlocarV4`).
  - Distribuição das páginas desde 02/11 (ES_61 a ES_64, Capacity 28): unidade de quem liderava a página no
    Legacy (hoje OM/OC/OS), Midwest Emma na U2 e Mikayla na U1 a pedido; < US$ 16 mil em postos de 3+,
    < US$ 32 mil em postos de 2+, mesma agência junta (Infloww; Noah, Independent e Lolly = 5C Company);
    Savannah com a Ivy Vale (Fanvue); C1 ≈ 60% / C2 ≈ 40% do faturamento da unidade, mesmo número de postos
    (C1 pode ter 1 a mais); posto = 1 pessoa (página > 35 fãs/h no pico ocupa 2). O Capacity segue a tabela
    `fc_posto_fixo` exatamente (sp_fc_postos_carteira do script 28); página fora dela segue a regra automática.
    O Capacity usa a foto diária do cadastro (`fc_pagina_status_diario`): página reativada no dia precisa da foto corrigida.
  - Saíram Arthur Novais Moraes e Larissa Ceola Guchert (ES_60). Andressa Urach cancelada (ES_63; ainda ativa no site).
  - A Skylar saiu (não está ativa no site). Antes de dizer que falta página, conferir as ativas em
    `5c_pages.pages`.
  - Melhores SRs (passo 0 do motor) pelo faturamento, não pelo tier: modelos com
    `v4_melhores_receita_min` (US$ 100 mil em 30 dias) ou mais recebem antes os SRs de maior venda/hora,
    na ordem da fila (ES_58). Ordena só pela venda/hora (na página, ou geral sem histórico); ter histórico na página não passa na frente (antes a Free ficava com SR de US$ 12/h). Posto só de página desconsiderada não abre vaga: a página vira apoio no
    posto da página principal da modelo (caso da Nicole OFTV, que tinha posto próprio por causa da
    troca de unidade).
  - PDF das páginas: um arquivo por unidade (PDF U1/U2/U3, `?ver=pdf&unidade=N`), para cada liderança
    ver só a sua; o arquivo com todas só para quem pode planejar. Ordem de faturamento, folha de resumo
    por unidade e venda por hora (US$/h) de cada SR.
  - Simulação local do Gerar (sem gravar): `resultados/sim_0211/` (harness.php, cópia da estrutura em
    overlay_base.sqlite, leituras respondidas pelo MySQL só de leitura). O rodapé do sistema mostra a versão no ar. Ao fim de toda mudança vale o
  gatilho de publicação: checagem de UX e a pergunta se publica como versão oficial e atualiza
  Ajuda e Changelog. Uma versão só por vez: se duas sessões mexem no módulo, juntar na mesma.
- **O que a 1.17.0 trouxe (31/10).**
  - Grade: PDF de metas (`?ver=metas`): por página, cada pessoa escalada na semana com o
    atingimento individual (5CReps ÷ meta pela fatia das mensagens, como no mérito) nas 4 semanas
    anteriores. Função `escMeritoAtingimentoPaginas` em `lib/merito.php`.
  - Troca de unidade gravada no Capacity vale nos postos no próximo "Gerar"; as pessoas ficam na
    unidade (a página vai para o time do destino). Organograma avisa das trocas futuras.
  - Mérito: parâmetro `merito_abs_ativo` (0 tira o absenteísmo da nota; ES_53).
  - ES_52: 18 SRs saem da U2 (equilíbrio de pessoas, ~74% em cada unidade e turno).
  - ES_54: a equipe que fez a Aline Novak de 04/10 a 31/10 vira a equipe da Aline e da Andressa
    Urach (Privacy), com 6 SRs indo para a U2; o Marcos fica no C até 08/11 e no D a partir de 09/11.
- **Pendências.**
  - 1.18.0: ES_57, script 27 do Capacity, ES_58 e ES_59 já rodaram em 01/11; falta subir o zip e conferir o rodapé;
    abrir Páginas › Configuração das páginas; gerar a semana de 09/11 e conferir a ordem.
    O usuário do portal precisa ler `5c_crm.tiers` (sem isso, página sem venda fica com zero).
  - Rodar ES_52, ES_53 e ES_54 (nessa ordem), subir o zip 1.17.0 e conferir o rodapé.
  - Gerar, publicar e postar a semana de 02/11; abrir o Organograma em 02/11.
  - Testar o PDF de metas no portal (só passou no lint e num teste com dados simulados).
  - Recalcular o mérito.
  - Resolver os dois pontos abertos: as duas Maddies e as páginas ativas sem movimento.
  - Destino dos registros de times e parties: perguntar ao usuário depois do Legado.
  - `fc_estrutura_rep` (o "tem" do Capacity) não acompanha as mudanças de pessoas do ES_52/ES_54.
- **PHP local** para lint e testes: `D:\Ferramentas\php\php.exe` (com
  `-d extension_dir=D:/Ferramentas/php/ext -d extension=mbstring`).

### 3.3 Simulations (seção 3)

- **Página.** Artifact "Simulações 5C": https://claude.ai/artifact/EKkcSVayfJ5vdewPro4uZH,
  republicado várias vezes no mesmo link, até a v8 ou mais.
- **O que simula.**
  - Cenários de capacity.
  - Estrutura e carteiras.
  - Turmas de contratação: o `sim.py` portado para JS, com a necessidade da seção 1.
  - Equilíbrio de faturamento entre unidades.
- **Correções e decisões que vieram da Simulations.**
  - Achou que a saída dos novatos era contada duas vezes no `turmas_quadro.py`. O espelho no banco
    (`sp_fc_prev_to`) está certo.
  - Pediu o teto de 10 por turno e 30 por turma, e a turma real de 02/11 (4/7/4).
  - Com o Planning tool, definiu a regra dos supervisores do Equilíbrio (seção 2).

### 3.4 Documento: Política de Escalas v2.0 (seção 4)

Arquivos em `D:\Fanvue\Alejandra\T1`, **confidenciais**:

- **`Politica-escalas-alta-gestao-v2_0.docx`** (20 páginas).
  - Conteúdo: estrutura, postos, rotação de 21 dias, responsabilidades (WFM é dono da escala e das
    páginas; RTA executa as mudanças de turno) e anexo com os dados de 28/10.
  - A seção 4 (Remuneração) ficou igual à v1.2, a pedido do usuário. Por isso ainda tem os nomes
    antigos de cargo.
  - Faltam validar 3 itens marcados como proposta: assistentes por nível, metas de OM, OC e OS, e
    maturação.
- **`Guia-do-Representante-v2_0.docx`** (12 páginas), sem nenhum valor de remuneração.
- **`divulgacao_politica_v2/`**: 8 imagens e o texto do post para o 5C News, em inglês.
- Nada foi publicado. A sessão foi interrompida quando o usuário mudou de assunto para a saída da
  Skylar.

### 3.5 Servidor novo e data lake (coordenação): detalhes em `novo_servidor/README.md`

- **Espelho no `.11`.** Tabelas FEDERATED e procedures de sync, com events a cada 10 minutos:
  - NS_01 a NS_04: headcount e páginas;
  - NS_05 a NS_07: Fanvue e os históricos de headcount e páginas;
  - NS_99: desfaz tudo.
- **Credenciais.** NS_01 e NS_05 têm os marcadores `<usuario>`, `<senha>` e `<porta>`, que o
  usuário preenche ao rodar.
- **`etl_auto/`.** Versão do ETL automático sem as etapas HeadCount e Pages.
- **Achados do `.7`.**
  - `fanvue_data.statements_raw.paid_at_utc` está em horário de Brasília. A conversão para UTC é
    +3h.
  - `statements_raw` do Infloww tem várias versões de cada transação e mistura sync e API. Não
    usar direto.
  - Faltam no `.11` a Cherie Deville 257m e a Daniswings 157m.
  - A Ivy Vale vende pelo Fanvue, não pelo Infloww.
- Commits `8265ed3` e `cbccdfb` já estão no GitHub. Falta confirmar com o usuário se os scripts NS
  e o `etl_auto` já rodaram.

---

## 4. Linha do tempo das decisões

| Data | Decisão |
|---|---|
| 26–27/10 | Pacote "Estrutura 5C em Unidades": 3 unidades, carteiras C1/C2, postos por página, níveis por % |
| 27/10 | Seções 1–3 abertas; fator 1,5 (rotação 21 dias); `fc_pagina_carteira` fonte única; buffer por núcleo |
| 28/10 | Cenário Capacity vira plano oficial; Política de 2 páginas por rep (modelos diferentes); AF sem dobra; agrupar primeiro a mesma modelo; saída da Skylar |
| 29/10 | Cargos renomeados (OM, OC, OS, OA, SR); revisão de UX (75 achados); quadro de liderança (ES_21) |
| 30/10 | Versões do capacity e plano sugerido; teto 10 por turno / 30 por turma; unidades renumeradas (U1 = Victoria, U3 = Keller); política de páginas por mérito; 5C Escalas 1.13 |
| 31/10 | Regras de faturamento, Mass, Infloww, SLA (script 23); planos v16 e rampa do novato; virada da estrutura às 21:00; 5C Escalas 1.14.1; equilíbrio de faturamento entre unidades |
| 31/10 (noite) | 5C Escalas 1.17.0: PDF de metas, troca de unidade nos postos (pessoas ficam), absenteísmo opcional no mérito; equilíbrio de pessoas (ES_52); equipe da Aline na Aline e na Andressa (ES_54) |

---

## 5. Onde mais procurar

- **Rascunho das telas (canvas).** https://claude.ai/artifact/BJDKQYLPz119juqs4hcBP8. Não dividir em
  páginas: as seções viraram sessões, não abas do canvas.
- **Pacote de 27/10.** `5C_contexto_27out.zip`: relatório de estrutura, `data_real_final.json`,
  `posts.py` e `sim.py`.
- **Testes locais do 5C Escalas.** Ficam no scratchpad da sessão do Planning tool, que é
  temporário; a lista está no consolidado de 31/10, seção 8.
- **Pasta `resultados/`.** Fica fora do Git e não entra neste pacote.
