# Capacity · estrutura em 3 unidades (01/11/2026)

Seção 1 do 5C Escalas. O banco (`5c_planning`) passa a calcular sozinho, todo dia, a necessidade de pessoas da estrutura nova. São as mesmas regras do `posts.py` usado no relatório "Estrutura 5C em Unidades" de 27/10:

- **Hierarquia:** unidade (OUM) → OM por turno → 3 OS em rodízio 5x2.
- **Carteiras:** duas por unidade (C1 e C2).
- **Postos por página e turno:** cada posto é 1 rep online o turno inteiro. Páginas pequenas são agrupadas: até 3 por rep se forem da mesma modelo; até 2 se as modelos forem diferentes (Política, decisão de 28/10).
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

## Scripts (`sql/`, Navicat, nesta ordem)

| # | Script | O que faz |
|---|---|---|
| 0 | `5C_Planning_09_calendario_turmas_26out.sql` | **Ainda não rodou em produção.** A `fc_contratacoes` está com o plano de 19/10. Grava as turmas de 26/10 e recalcula. |
| 1 | `5C_Planning_10_estrutura_unidades.sql` | Parâmetros novos, tabelas da estrutura (carteira, página → carteira, liderança, reps), tabelas de resultado e visões 7 a 10. Só acrescenta objetos. |
| 2 | `5C_Planning_11_procedimento_postos.sql` | `sp_fc_postos` e `sp_fc_postos_carteira` (a regra dos postos). Recria o `sp_fc_rodar_dia` com a etapa nova `postos` no fim; o resto fica igual. |
| 3 | `5C_Planning_12_carga_estrutura_01nov.sql` | Carga de 01/11: 6 carteiras, 86 páginas (com modelo e regra), 39 líderes e 220 reps. |
| 4 | `5C_Planning_13_validacao_postos.sql` | Recalcula 26/10 e hoje e compara com a referência. Tudo deve sair "ok". |
| 5 | `5C_Planning_14_quadro_e_turmas_cenarios.sql` | Quadro alvo e vagas por unidade e turno nos 2 cenários, e o plano de turmas de cada um. Não mexe no plano oficial (`fc_contratacoes`). |

Para desfazer do 10 ao 14: `5C_Planning_99_desfazer_10_a_12.sql`. Ele também devolve o `sp_fc_rodar_dia` de 19/10.

Os scripts 00 a 09 são a série de 19 a 26/10, copiados aqui para a pasta ficar completa.

## O que o 13 deve mostrar

Recalculando 26/10 com os fãs/h que o banco tem hoje para 12–25/10, a referência é:

| Postos | Titulares | Buffer | Precisa | Tem | Saldo |
|---|---|---|---|---|---|
| 175 | 265 | 62 | **327** | 220 | **−107** |

| Turno | Buffers presentes por dia | Buffer do turno | U1 / U2 / U3 |
|---|---|---|---|
| A | 12,7 | 20 | 6 / 7 / 7 |
| C | 13,6 | 21 | 7 / 7 / 7 |
| D | 13,7 | 21 | 6 / 7 / 8 |

| Unidade · turno | Postos | Titulares | Faltas/dia | Pico/dia | Presentes/dia | Buffer | Precisa | Tem | Saldo |
|---|---|---|---|---|---|---|---|---|---|
| U1 · A | 17 | 26 | 1,6 | 2,2 | 4,2 | 6 | 32 | 25 | −7 |
| U1 · C | 18 | 27 | 1,9 | 1,9 | 4,2 | 7 | 34 | 20 | −14 |
| U1 · D | 17 | 26 | 1,9 | 1,6 | 4,0 | 6 | 32 | 21 | −11 |
| U2 · A | 19 | 29 | 1,8 | 2,1 | 4,3 | 7 | 36 | 28 | −8 |
| U2 · C | 19 | 29 | 2,0 | 2,3 | 4,8 | 7 | 36 | 25 | −11 |
| U2 · D | 20 | 30 | 2,2 | 1,9 | 4,7 | 7 | 37 | 30 | −7 |
| U3 · A | 21 | 32 | 2,0 | 1,9 | 4,2 | 7 | 39 | 23 | −16 |
| U3 · C | 22 | 33 | 2,3 | 1,8 | 4,6 | 7 | 40 | 24 | −16 |
| U3 · D | 22 | 33 | 2,5 | 1,9 | 5,0 | 8 | 41 | 24 | −17 |

Taxa de falta (31/08–25/10), absenteísmo + falha administrativa sem dobra: A 9,1% + 0,2% · C 10,3% + 0,2% · D 10,9% + 0,4%.

Como a conta mudou:
- 27/10: 162 postos × 1,4 + 15% = 269; × 1,5 + 15% = 286; com o buffer calculado = 318; com o buffer igual nos 3 núcleos = 325; com o buffer contado no total do turno = 315.
- 28/10, Política de 2 páginas por rep quando as modelos são diferentes: 161 → 175 postos e 244 → 265 titulares. O pico cai um pouco, porque os grupos menores têm mais capacidade.
- 28/10, falha administrativa sem `AF_DOBRA`: o buffer cai de 70 (já com a regra das 2 páginas; seria 335 no total) para 62.
- Resultado: **327**.

O 13 confere o buffer de cada turno exatamente. Na distribuição entre unidades aceita diferença de 1, porque no turno A a U1 e a U3 ficam quase empatadas.

O relatório de 27/10 deu 162 postos com até 3 páginas por rep; a diferença de 1 posto (U1 · C1, turno D) é do `msgdashboard`, atualizado depois.

A lógica da procedure foi conferida contra o `posts.py` nas 18 combinações de carteira e turno, com os mesmos fãs/h e o limite antigo (3 páginas): o número de postos é o mesmo em todas. A regra das 2 páginas foi conferida no espelho da procedure (175 postos). Em empates de arredondamento (carga terminada em ,x5) o MySQL arredonda para cima e o Python para o par. Isso pode trocar qual página pequena entra em qual grupo, mas não muda nenhuma contagem.

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

O plano oficial (`fc_contratacoes`) segue com as turmas de 26/10 (script 09). O bloco 5 do script 14 troca o plano oficial pelo cenário escolhido e recalcula.

## O que não muda

`fc_escala_diaria`, `fc_buffer_diario`, `fc_escala_pagina`, `fc_impacto_paginas`, as visões 1 a 6 e o `vw_fc_plano_semanal` continuam com o modelo de 19/10 (3x1). O Planning tool e o 5C Escalas leem essas tabelas.

Ainda falta decidir:
- se o plano semanal (`vw_fc_plano_semanal`) passa a usar a necessidade da estrutura nova, em vez do modelo de 19/10;
- qual cenário de contratação vira o plano oficial (bloco 5 do script 14);
- se o TRN aguenta turmas maiores que 20.
