# Capacity · estrutura em 3 unidades (01/11/2026)

Seção 1 do 5C Escalas. O banco (`5c_planning`) passa a calcular sozinho, todo dia, a necessidade de pessoas da estrutura nova. São as mesmas regras do `posts.py` usado no relatório "Estrutura 5C em Unidades" de 27/10:

- **Hierarquia:** unidade (OUM) → OM por turno → 3 OS em rodízio 5x2.
- **Carteiras:** duas por unidade (C1 e C2).
- **Postos por página e turno:** cada posto é 1 rep online o turno inteiro. Páginas pequenas são agrupadas em duas fases (decisão de 28/10):
  1. primeiro as da mesma modelo, até 3 por rep (ex.: CJ VIP + CJ Miles, Jena Wolfy VIP + Free);
  2. as que ficaram sozinhas, entre modelos diferentes, até 2 por rep (Política).
- **Necessidade:**
  - **Titulares (quadro fixo enxuto):** postos × 1,5, arredondado para cima. É a escala 5x2 rotativa em ciclo de 21 dias: cada pessoa trabalha 14 de 21 dias (21 ÷ 14 = 1,5).
  - **Buffer:** cobre todas as faltas e os aumentos de fluxo. O quadro fixo não tem folga para isso (decisões de 27 e 28/10). Segue quatro fatores previsíveis:
    - capacity (postos);
    - absenteísmo;
    - falha administrativa;
    - aumento de fluxo.
    - **Faltas por dia** = postos × (absenteísmo + falha administrativa) do turno, nos últimos 56 dias (`abs_adh_af`).
      - A falha administrativa entra sem a `AF_DOBRA` (decisão de 28/10). A dobra era 91% da AF e é decisão de escala, não falta; com os buffers ela deixa de existir. Fica só a `AF_FORA` (trabalhou fora da escala).
    - **Pico por dia** = mediana dos picos diários dos últimos 28 dias.
      - O pico de um dia é o pior horário: soma dos fãs que passam da capacidade dos postos de cada página, convertida em reps (÷ 35).
      - Só conta o que passa de 40 fãs/h por rep, o teto individual da Política.
      - Os fãs são multiplicados pelo `fator_volume` (sazonalidade prevista; hoje 1).
    - **Presentes por dia** (cada unidade) = (faltas + pico) ÷ (1 − taxa de falta), porque o buffer também falta.
    - **Buffer do turno:** é contado no total das 3 unidades, para não arredondar núcleo a núcleo. É a soma dos presentes × 3 ÷ 2, arredondada para cima, porque o buffer segue a folga do núcleo e 2 de 3 trabalham por dia.
    - **Distribuição:**
      - cada unidade recebe a parte proporcional aos presentes dela, nunca menos que 2 por núcleo (6 por unidade);
      - dentro da unidade, o Planning tool divide entre os núcleos, de forma desigual se precisar, respeitando o mínimo.
    - Os pesos ficam em `fc_parametros`: `peso_af_buffer`, `percentil_pico`, `teto_pico_fans_rep`, `janela_buffer_dias`, `janela_pico_dias`, `nucleos_por_turno`, `nucleos_folga_dia`, `buffer_minimo_nucleo` (2) e `fator_volume`.
- **Tem:** reps reais de cada unidade e turno.

> **Unidades renumeradas em 30/10 (ES_35, do Planning tool):** U1 = Victoria e U3 = Keller. A troca vale no banco para todas as datas e versões. Neste README e nos scripts até o 22, U1 é o Keller e U3 é a Victoria: a Skylar (U1 · C1), por exemplo, hoje é U3 · C1.

## Scripts (`sql/`, Navicat, nesta ordem)

| # | Script | O que faz |
|---|---|---|
| 0 | `5C_Planning_09_calendario_turmas_26out.sql` | **Ainda não rodou em produção.** A `fc_contratacoes` está com o plano de 19/10. Grava as turmas de 26/10 e recalcula. |
| 1 | `5C_Planning_10_estrutura_unidades.sql` | Parâmetros novos, tabelas da estrutura (carteira, página → carteira, liderança, reps), tabelas de resultado e visões 7 a 10. Só acrescenta objetos. |
| 2 | `5C_Planning_11_procedimento_postos.sql` | `sp_fc_postos` e `sp_fc_postos_carteira` (a regra dos postos). Recria o `sp_fc_rodar_dia` com a etapa nova `postos` no fim; o resto fica igual. |
| 3 | `5C_Planning_12_carga_estrutura_01nov.sql` | Carga de 01/11: 6 carteiras, 86 páginas (com modelo e regra), 39 líderes e 220 reps. |
| 4 | `5C_Planning_13_validacao_postos.sql` | Recalcula 26/10 e hoje e compara com a referência. Tudo deve sair "ok". |
| 5 | `5C_Planning_14_quadro_e_turmas_cenarios.sql` | Quadro alvo e vagas por unidade e turno nos 2 cenários, e o plano de turmas de cada um. Não mexe no plano oficial (`fc_contratacoes`). |
| 6 | `5C_Planning_15_adotar_cenario_capacity.sql` | Adota o cenário Capacity (decisão de 28/10): troca as turmas planejadas a partir de 07/12 em `fc_contratacoes`, com cópia de segurança, e recalcula. Tem o bloco para desfazer. |
| 7 | `5C_Planning_16_postos_semana_01nov.sql` | Grava os postos com `data_calculo` = 28/10, já agrupando primeiro a mesma modelo. É a base fixa que o Planning tool usa na escala de 01/11 a 08/11. Rodar depois do 11 (versão 1.1) e do 13. |
| 8 | `5C_Planning_17_turma_0211_real.sql` | Corrige a turma de 02/11 em `fc_contratacoes` para os contratados reais: A 4 · C 7 · D 4. O total continua 15. |
| 9 | `5C_Planning_18_sem_skylar.sql` | Tira a Skylar Mae VIP da estrutura (a modelo saiu em 28/10) e recalcula a base de 28/10. Depois dele, o 13 não confere mais: a referência de 26/10 tinha a Skylar. |
| 10 | `5C_Planning_19_versoes_e_plano_sugerido.sql` | Versões do capacity com "O que mudou", plano de turmas sugerido em cada versão e comparação com o oficial (visões 13 a 17). Recria o `sp_fc_rodar_dia` com a etapa `versao`. |
| 11 | `5C_Planning_20_adotar_plano_sugerido.sql` | Adota o plano sugerido da versão 4 (capacity de 30/10) como plano oficial: as turmas planejadas passam de 280 para 202 pessoas. Tem cópia de segurança e o bloco para desfazer. |
| 12 | `5C_Planning_21_teto_10_por_turno_e_troca_c_a.sql` | Teto de 10 por turno e 30 por turma, e troca de 5 reps do C para o A na simulação (`fc_plano_troca_turno`). Refaz o plano sugerido da última versão e adota como oficial. |
| 13 | `5C_Planning_22_plano_sem_troca_c_a.sql` | Desliga a troca C → A do 21: ela já estava no cadastro da estrutura. Refaz o plano sugerido da última versão, com teto de 10 por turno, e adota como oficial. |

Para desfazer do 10 ao 14: `5C_Planning_99_desfazer_10_a_12.sql`. Ele também devolve o `sp_fc_rodar_dia` de 19/10.

Os scripts 00 a 09 são a série de 19 a 26/10, copiados aqui para a pasta ficar completa.

## O que o 13 deve mostrar

Recalculando 26/10 com os fãs/h que o banco tem hoje para 12–25/10, a referência é:

| Postos | Titulares | Buffer | Precisa | Tem | Saldo |
|---|---|---|---|---|---|
| 177 | 269 | 61 | **330** | 220 | **−110** |

| Turno | Buffers presentes por dia | Buffer do turno | U1 / U2 / U3 |
|---|---|---|---|
| A | 12,2 | 19 | 6 / 7 / 6 |
| C | 13,4 | 21 | 6 / 8 / 7 |
| D | 13,6 | 21 | 6 / 7 / 8 |

| Unidade · turno | Postos | Titulares | Faltas/dia | Pico/dia | Presentes/dia | Buffer | Precisa | Tem | Saldo |
|---|---|---|---|---|---|---|---|---|---|
| U1 · A | 17 | 26 | 1,6 | 2,1 | 4,0 | 6 | 32 | 25 | −7 |
| U1 · C | 18 | 27 | 1,9 | 1,8 | 4,2 | 6 | 33 | 20 | −13 |
| U1 · D | 17 | 26 | 1,9 | 1,6 | 4,0 | 6 | 32 | 21 | −11 |
| U2 · A | 19 | 29 | 1,8 | 2,1 | 4,3 | 7 | 36 | 28 | −8 |
| U2 · C | 19 | 29 | 2,0 | 2,3 | 4,9 | 8 | 37 | 25 | −12 |
| U2 · D | 21 | 32 | 2,4 | 1,9 | 4,8 | 7 | 39 | 30 | −9 |
| U3 · A | 21 | 32 | 2,0 | 1,6 | 3,9 | 6 | 38 | 23 | −15 |
| U3 · C | 23 | 35 | 2,4 | 1,5 | 4,4 | 7 | 42 | 24 | −18 |
| U3 · D | 22 | 33 | 2,5 | 1,9 | 4,9 | 8 | 41 | 24 | −17 |

Taxa de falta (31/08–25/10), absenteísmo + falha administrativa sem dobra: A 9,1% + 0,2% · C 10,3% + 0,2% · D 10,9% + 0,4%.

Como a conta mudou:
- 27/10: 162 postos × 1,4 + 15% = 269; × 1,5 + 15% = 286; com o buffer calculado = 318; com o buffer igual nos 3 núcleos = 325; com o buffer contado no total do turno = 315.
- 28/10, Política de 2 páginas por rep quando as modelos são diferentes: 161 → 175 postos e 244 → 265 titulares. O pico cai um pouco, porque os grupos menores têm mais capacidade.
- 28/10, falha administrativa sem `AF_DOBRA`: o buffer cai de 70 (já com a regra das 2 páginas; seria 335 no total) para 62.
- 28/10, agrupar primeiro a mesma modelo: os postos com 2+ páginas da mesma modelo passam de 3 para 28; 175 → 177 postos (U2 · C1 turno D e U3 · C1 turno C), 265 → 269 titulares, buffer 62 → 61. Os 10 pares que continuam separados não cabem juntos (as duas páginas passam de 28 fãs/h ou somam mais de 31,5) ou têm regra própria (Mikayla).
- Resultado: **330**. O plano de turmas adotado (simulado com 327) não muda: com o teto de 20 por turma, o alvo de 330 dá as mesmas 315 contratações e fecha no mesmo fim de junho.

O 13 confere o buffer de cada turno exatamente. Na distribuição entre unidades aceita diferença de 1, porque no turno A a U1 e a U3 ficam quase empatadas.

O relatório de 27/10 deu 162 postos com até 3 páginas por rep; a diferença de 1 posto (U1 · C1, turno D) é do `msgdashboard`, atualizado depois.

A lógica da procedure foi conferida contra o `posts.py` nas 18 combinações de carteira e turno, com os mesmos fãs/h e o limite antigo (3 páginas): o número de postos é o mesmo em todas. A regra das 2 páginas foi conferida no espelho da procedure (175 postos), e o agrupamento em duas fases também (177 postos). Em empates de arredondamento (carga terminada em ,x5) o MySQL arredonda para cima e o Python para o par. Isso pode trocar qual página pequena entra em qual grupo, mas não muda nenhuma contagem.

## Onde ler

| Visão | Conteúdo |
|---|---|
| `vw_fc_7_necessidade_estrutura` | Por unidade e turno: postos, titulares, buffer, precisa, tem, saldo. |
| `vw_fc_8_postos_pagina` | Postos de cada página em cada turno (0,5 = página agrupada), fãs/h e como é atendida. |
| `vw_fc_9_carteiras` | Postos, titulares e reps de cada carteira por turno (base dos 3 OS). |
| `vw_fc_10_paginas_fora_da_estrutura` | Páginas ativas sem carteira e páginas na carteira que ficaram inativas. |

O detalhe fica nas tabelas, com histórico de 180 dias:
- `fc_pagina_carga`: carga de cada página no turno e a origem dela (CHAT, ESTIMADA ou SEM_DADO);
- `fc_postos`;
- `fc_postos_pagina`;
- `fc_necessidade_estrutura` (por unidade e turno, com absenteísmo, falha, pico, presentes e buffer);
- `fc_buffer_turno` (buffer de cada turno, contado no total das unidades);
- `fc_necessidade_carteira`.

## Manutenção

- **Página muda de carteira:** preencha `vigente_ate` na linha atual de `fc_pagina_carteira` e insira a nova com `vigente_desde`. O cálculo usa a linha vigente na data.
- **Página nova:** insira em `fc_pagina_carteira` com modelo e regra. Sem chat ainda, preencha `fas_h_estimado` (receita ÷ 720 × k do tier); sem isso, entra com `fans_sem_dado` (10 fãs/h).
- **Regras por página:** a coluna `regra` aceita estes valores:
  - `SKYLAR`: limite 40, mínimo 4;
  - `SLA80`: limite 20, nunca agrupa;
  - `ASSISTENTE_FIXO`: mínimo 2 por turno;
  - `EXCLUSIVA`: rep próprio;
  - `PADRAO`.

  Exemplo: tirar o assistente fixo da Midwest Emma Free 221s é trocar a regra dela para `PADRAO`.
- **Parâmetros:** ficam em `fc_parametros`:
  - `fator_pessoas_posto` (1,5), `teto_apoio`, `assistente_fixo_minimo`;
  - buffer: `peso_af_buffer` (1), `percentil_pico` (0,50), `teto_pico_fans_rep` (40), `janela_buffer_dias` (56), `janela_pico_dias` (28);
  - `desconto_mesma_modelo` e `desconto_modelo_diferente`;
  - `janela_postos_dias` e `carga_minima_posto`;
  - `max_paginas_rep_modelos_diferentes` (2, Política);
  - mais os de antes: `limite_padrao` 35, `limite_compartilhada` 28, `max_paginas_rep` 3 (mesma modelo) e outros.
- **Reps:** `fc_estrutura_rep` tem a foto de 27/10. Quando o Planning tool tiver o cadastro próprio de quem está em cada unidade, turno e OS, ele passa a alimentar esta tabela.

## Plano de contratação com o quadro novo (2 cenários)

Simulado em 28/10 a partir dos 220 reps reais (foto de 27/10): A 76 · C 69 · D 75. Script: `simulacao/turmas_quadro.py`.

As regras são as do plano de 26/10:
- turmas de 02/11 e 16/11 já pedidas;
- depois, 07/12, 11/01/2027 e a cada 14 dias;
- no máximo 20 por turma e 14 dias de treinamento;
- saídas de 6,4% do quadro por mês;
- novatos: 12% saem em 30 dias e mais 11% até 90 dias.

| | Capacity | Quadro de 12 por OS |
|---|---|---|
| Alvo (A / C / D) | 107 / 110 / 110 = **327** | 108 / 108 / 108 = **324** |
| Vagas hoje | 107 | 104 |
| Contratações até a turma de 08/03 | 155 | 155 |
| Falta ≤ 5 pessoas | fim de jun/2027 | meados de jun/2027 |
| Contratações até jun/2027 | 315 | 315 |
| Depois de fechar | ~20 por turma (reposição) | ~20 por turma (reposição) |

Pontos de atenção:
- **Os dois cenários ficaram quase iguais no total.** A necessidade (327) passou o quadro de 12 por OS (324) em 3.
- **A distribuição entre unidades é diferente:**
  - a U3 precisa de mais que 12 por OS: 39 / 40 / 41 contra 36 por turno;
  - a U1 precisa de menos (32–34); a U2 fica perto (36–37).
- **O teto de 20 por turma segura o plano:** todas as turmas até junho saem cheias.
  - Com teto de 25, a falta fecha em abr/2027; com 30, no fim de mar/2027.
  - Isso depende de o Treinamento (TRN) conseguir turmas maiores.
- **Pior momento:** 24/01, com falta de 103 no cenário Capacity, por causa das 5 semanas sem turma entre 07/12 e 11/01.
- **Vagas por unidade (`vw_fc_11_quadro_vagas`):**
  - Capacity: U1 32 · U2 26 · U3 49;
  - 12 por OS: U1 42 · U2 25 · U3 37.

**Decisão de 28/10: o cenário Capacity vira o plano oficial.** O script 15 troca as turmas planejadas a partir de 07/12 em `fc_contratacoes` pelas do cenário. As turmas de 02/11 e 16/11 já confirmadas ficam como estão. No total são 315 pessoas: 35 já confirmadas e 280 em 14 turmas planejadas, até 28/06/2027.

Com o teto de 20 por turma, os dois cenários contratam o mesmo número de pessoas por turma. Só a divisão entre turnos muda, em 5 turmas de fev a mai. A escolha pesa mais na divisão das vagas entre as unidades.

## Saída da Skylar (28/10)

A Skylar Mae VIP (U1 · C1) ocupava 4 postos em cada turno: 1 titular e 3 assistentes, pela regra `SKYLAR` (limite 40, mínimo 4). A modelo saiu em 28/10. O script 18 fecha a vigência da página em `fc_pagina_carteira` e recalcula a base de 28/10. Nenhuma outra página muda de posto, porque a Skylar nunca dividia posto.

| Base de 28/10 | Postos | Titulares | Buffer (A / C / D) | Precisa | Tem | Saldo |
|---|---|---|---|---|---|---|
| Com a Skylar | 172 | 259 | 62 (19 / 22 / 21) | 321 | 220 | −101 |
| Sem a Skylar | 160 | 241 | 59 (18 / 20 / 21) | **300** | 220 | **−80** |

- **Só a U1 muda:** 13 / 14 / 12 postos e 20 / 21 / 18 titulares. O pico da U1 cai de 2,18 / 1,96 / 1,62 para 1,82 / 1,35 / 1,62.
- **A U1 · C1 fica pequena:** 5 / 5 / 4 postos, contra 8 / 9 / 8 da U1 · C2. Os reps que estavam na Skylar sobram ali.
- **O plano de turmas não foi mexido:** continua com 315 contratações. O alvo do quadro caiu cerca de 21, então rever as turmas a partir de 07/12 é decisão do usuário.

## Versões do capacity e plano sugerido (script 19)

Pedido de 28/10: o capacity e as contratações não podem depender de recálculo à mão, e o capacity anterior tem que ficar guardado para comparar.

**Versões.** Cada cálculo grava uma versão nova, e as anteriores ficam guardadas:
- o que é gravado: o resumo, as unidades × turnos e os postos com as páginas;
- quem grava: o cálculo diário, ou um recálculo à mão com `CALL sp_fc_recalcular_postos(data, 'motivo')`. Não use `CALL sp_fc_postos` sozinho: ele não grava versão;
- **"O que mudou" sai sozinho**, da comparação com a versão anterior: páginas que saíram ou entraram, quantas mudaram de postos e a diferença do "precisa".

Uma página desativada no cadastro sai no cálculo do dia seguinte, e a versão registra a saída. No caso da Skylar, o 18 só foi preciso porque ela continua ativa no cadastro.

**Plano sugerido.** Em cada versão, o banco refaz as turmas planejadas do calendário oficial, em ordem, para o quadro chegar ao "precisa" de cada turno:
- as turmas confirmadas ficam como estão;
- a conta de turnover é a mesma do `sp_fc_prev_to`;
- o quadro de partida é o "tem" da versão;
- desistência no treinamento de 6% e no máximo 20 por turma.

O plano oficial (`fc_contratacoes`) continua manual. A versão fica com alerta quando o total das turmas planejadas difere em 10 ou mais.

**Decisão de 30/10: o plano sugerido da versão 4 vira o oficial (script 20).**
- As 14 turmas planejadas passam de 280 para 202 pessoas, e o total do plano fica em 237.
- Até 08/03 continuam 20 por turma; de 22/03 em diante, de 8 a 17.
- A falta fecha na mesma semana que no plano anterior, 29/03/2027.

**Decisão de 30/10, mais tarde: teto de 30 por turma, sendo 10 por turno, a partir de 07/12, e 5 reps passam do C para o A (script 21).**
- O plano sugerido da versão 5 (precisa 293), com essas regras, vira o oficial: 207 pessoas nas turmas planejadas, 242 no total.
- As primeiras turmas saem cheias nos turnos C e D, 10/10/10 em 07/12, e a falta fecha em 15/03/2027.
- Enquanto o cadastro da estrutura não mostrar a troca, ela entra na simulação pela `fc_plano_troca_turno`. Quando o cadastro passar a mostrar, marque `ativo = 0`, senão ela conta duas vezes.

**Correção de 30/10 (script 22): a troca já estava no cadastro.**
- O cadastro da estrutura (A 76 · C 69 · D 75) já mostrava os 5 reps no A, e o 21 tinha somado a troca de novo.
- O 22 desliga a troca e readota o plano com teto de 10 por turno: 208 pessoas nas turmas planejadas, 243 no total.
- A falta fecha em 01/03/2027.

| Visão | O que mostra |
|---|---|
| `vw_fc_13_capacity_versoes` | Todas as versões, com o "O que mudou", o total das turmas planejadas (oficial e sugerido) e quando a falta fecha |
| `vw_fc_14_capacity_mudancas` | A última versão contra a anterior, por unidade e turno |
| `vw_fc_15_capacity_paginas` | Páginas que mudaram de postos na última versão |
| `vw_fc_16_plano_oficial_vs_sugerido` | Turma a turma, com "REVISAR" quando a diferença é de 3 ou mais |
| `vw_fc_17_projecao_quadro` | Quadro projetado por semana com os dois planos contra o "precisa" |

**Histórico inicial (rodando em 30/10):**
- O cálculo de 28/10 não está mais inteiro no banco: `fc_postos` de 28/10 ficou com 37 postos e sem necessidade.
- Por isso as duas versões de 28/10 entram reconstruídas, só com as unidades.

| Versão | Precisa | Turmas planejadas (oficial / sugerido) | Falta fecha em |
|---|---|---|---|
| 28/10 com a Skylar (reconstruída) | 321 | 280 / 239 | 10/05/2027 |
| 28/10 sem a Skylar (reconstruída) | 300 | 280 / 214 | 12/04/2027 |
| 29/10 (diário) | 294 | 280 / 207 | 29/03/2027 |
| 30/10 (diário) | 291 | 280 / **202** | 29/03/2027 |

**Por que o sugerido fica abaixo do oficial mesmo com 330:** o plano oficial veio de `simulacao/turmas_quadro.py`.
- Lá, os novatos perdem 12% em 30 dias e mais 11% até 90 dias **além** do turnover de todo o quadro (6,4% ao mês). O novato acaba contado duas vezes.
- No banco (`sp_fc_prev_to`), os 12% e 23% já são o total do novato.
- O histórico confirma o banco: das 193 pessoas que entraram de out/2025 a jul/2026, 15,5% saíram em 30 dias e 26,4% em 90.
- Com a mesma conta do Python, o plano semanal dá 279 com alvo 327, igual ao oficial.
- Na diferença de hoje (78), cerca de 33 vêm da conta dos novatos; o resto vem do alvo menor (Skylar e carga das páginas).

## O que não muda

`fc_escala_diaria`, `fc_buffer_diario`, `fc_escala_pagina`, `fc_impacto_paginas`, as visões 1 a 6 e o `vw_fc_plano_semanal` continuam com o modelo de 19/10 (3x1). O Planning tool e o 5C Escalas leem essas tabelas.

Ainda falta decidir:
- se o plano semanal (`vw_fc_plano_semanal`) passa a usar a necessidade da estrutura nova, em vez do modelo de 19/10;
- se o TRN aguenta turmas maiores que 20.
