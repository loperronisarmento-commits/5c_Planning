# Política de páginas por mérito: owners, backups e trocas

Proposta de 30/10/2026 para o 5C Escalas. Os números são padrões, e todos ficam em Parâmetros para ajustar sem mexer no código.

## 1. Como funciona hoje

O sistema **não troca páginas de unidade de semana para semana.**

- **Onde a página fica:** a página fica na carteira da unidade definida no Capacity (`fc_pagina_carteira`). O posto de cada turno vem dessa carteira (`esc_posto`).
- **Quem atende o posto:** cada posto tem um **núcleo dono** e um **núcleo de cobertura**, com a equipe fixa de cada vaga (`esc_vaga_equipe`).
- **Como o motor preenche:** a cada semana ele preenche as vagas nesta ordem:
  1. equipe fixa;
  2. outra pessoa do mesmo núcleo que já conhece a página;
  3. buffer;
  4. lacuna.

  A equipe fixa é preferência: ninguém muda de página por desempenho.
- **Quando a página sai da unidade:** só na saída de página, numa troca do Planner ou por ajuste manual.

**Consequência:** hoje não existe regra de mérito que tire uma página de quem vai mal ou dê página maior para quem vai bem. É isso que esta política cria.

## 2. Medida de desempenho

| Nível | Medida | Fonte |
|---|---|---|
| Página na unidade | **Atingimento** = 5CReps realizado ÷ meta 5CReps, nas horas em que a página esteve na unidade | `tab_intraday_base` (por hora e página; atualizado até ontem) |
| Representante (owner) | Atingimento do SR na página: realizado ÷ meta, rateado pelas mensagens do SR na página no turno (mesma regra do mérito de hoje), mais a nota de mérito (SLA, absenteísmo, infrações) | `esc_sr_merito` e `cap_rep_hora` |
| Unidade como um todo | Atingimento de todas as páginas da unidade, ponderado pela meta | `tab_intraday_base` |

**Janela de avaliação:** de quando a unidade ou o owner assumiu a página até 28 dias móveis. São precisos pelo menos 3 dias com dados para avaliar.

> A tabela por SR do WFM (`sr_performance_by_shift`) está parada, sem dados nos últimos 14 dias. Por isso o atingimento do SR sai da mesma conta do mérito.

## 3. Faixas e prazos (retorno decrescente)

Quanto melhor o atingimento, mais prazo a unidade ou o owner ganha para se recuperar. A curva é configurável:

    prazo(a) = P_min + (P_max − P_min) × ((a − piso) ÷ (corte − piso))^γ      para piso ≤ a < corte

| Parâmetro | Padrão | Função |
|---|---|---|
| `pol_piso` | 50% | Atingimento igual ou abaixo do piso: prazo mínimo |
| `pol_corte_acomp` | 85% | Abaixo do corte, o prazo está correndo; acima dele, só acompanhamento |
| `pol_prazo_min` | 3 dias | Prazo mínimo para quem vai extremamente mal |
| `pol_prazo_max` | 14 dias | Prazo de quem está logo abaixo de 85% |
| `pol_gamma` | 2 | Formato da curva: com γ > 1 o prazo é curto embaixo e cresce rápido perto de 85%; com γ < 1 a regra fica mais tolerante |
| `pol_prazo_acomp` | 28 dias | Entre 85% e 100%: acompanha por um período longo antes de sugerir troca |
| `pol_destaque` | 110% | Igual ou acima disso por `pol_destaque_dias`: candidata a página maior |
| `pol_destaque_dias` | 14 dias | Tempo sustentado no destaque |
| `pol_carencia` | 7 dias | Depois de qualquer troca, não reavalia antes disso (estabilidade) |
| `pol_owner_min` | 14 dias | Tempo mínimo do owner na página antes de trocar de owner |
| `pol_max_trocas_semana` | 2 por unidade | Limite de páginas que uma unidade perde ou recebe por semana |

Prazo com os padrões (γ = 2):

| Atingimento | ≤ 50% | 60% | 65% | 70% | 75% | 80% | 85–99% | ≥ 100% | ≥ 110% por 14 dias |
|---|---|---|---|---|---|---|---|---|---|
| Prazo | 3 dias | 4 | 5 | 7 | 9 | 11 | acompanha 28 dias | mantém | candidata a página maior |

O prazo conta a partir do dia em que a página entrou na faixa. Se ela sobe para uma faixa melhor, o prazo é recalculado; se volta para 100%, o prazo zera.

## 4. As duas trocas

### 4.1 Troca de owner (dentro da unidade)

Quando a página vai bem na unidade, mas o **owner** está abaixo do corte e o prazo dele venceu:
- o sistema sugere trocar o owner com o backup ou com outro SR do mesmo núcleo que vá melhor na página;
- a troca é 1 por 1, como nas Solicitações: ninguém fica sem vaga e nenhum posto fica sem dono.

### 4.2 Troca de unidade (a página muda de unidade)

Quando a **página na unidade** está abaixo do corte e o prazo venceu:
1. **Destino:** o sistema sugere para onde levar a página, com a mesma nota do Funil (Capacity da unidade, mérito da equipe, carga do núcleo e afinidade com a modelo).
2. **Bônus de destaque:** as equipes em destaque ganham um bônus na nota de destino.
3. **Sem desequilibrar:** a unidade que perde a página não pode ficar sem capacidade para o que já tem.

### 4.3 Página maior para quem vai bem

- **Quem é candidata:** a equipe (núcleo) com atingimento de 110% ou mais por 14 dias.
- **O que ela ganha:** prioridade quando houver página de tier maior entrando (Funil) ou saindo de outra unidade (troca de unidade).

## 5. Prioridades fixas

Regra configurável que vence a nota, por agência ou modelo:

| Páginas | Prioridade para | Como fica |
|---|---|---|
| Privacy: Andressa Urach e Aline Novak | Equipe do Marcos Vinicius Cid Mélo (Felix Legion III, T4/T5 legado) | A confirmar (ver seção 8) |

Hoje a **Aline Novak** está na U2 (carteira U2C2). No T4/T5 ela está numa página de dupla cujo núcleo dono é o **núcleo 3**. O Marcos está no **núcleo 2** da U2 · T4/T5, então ele hoje não é owner da Aline.

## 6. Telas

1. **Owners e backups (nova, em Páginas):**
   - **Por página e turno:** unidade e carteira, núcleo dono e de cobertura, owner(s) e backups, desde quando cada um está na página.
   - **Atingimento:** de 7, 14 e 28 dias da página na unidade e de cada owner.
   - **Situação:** "na meta", "acompanhamento", "prazo correndo: faltam X dias", "prazo vencido: troca sugerida" ou "destaque".
   - **Filtros e busca:** filtro por unidade, turno, núcleo e situação; busca por página ou pessoa.
2. **Sugestões:** três tipos novos, que entram na fila de hoje e nunca se aplicam sozinhos:
   - **Troca de owner:** aplica direto, como as Solicitações.
   - **Troca de unidade:** fecha a página na carteira de uma unidade e abre na outra, com a prévia de postos e vagas, como a saída de página.
   - **Candidata a página maior:** só registra; é usada no Funil e na troca de unidade.
3. **Funil:** os destinos indicados passam a usar o destaque e as prioridades fixas.

## 7. Andressa Urach (Privacy)

Entrada prevista para **02/11 às 13:00** (turno T4/T5), com **25 fãs/h**.

- **No Capacity:** registrar em `fc_paginas_previstas` com previsão de 25 fãs/h, data 02/11 e probabilidade 100%. Assim o Capacity já conta o posto e os SRs.
- **Na escala:** com 25 fãs/h ela ocupa 1 posto por turno (cerca de 2 SRs por turno) e entra na unidade da prioridade da Privacy.

## 8. O que falta decidir

1. **"Equipe do Marcos":** é o núcleo dele hoje (U2 · T4/T5 · núcleo 2) ou o time legado (Marcos, Loik Costa da Silva e Melody Llavores Vale)? Loik e Melody estão hoje na U3.
2. **Data da Andressa:** é 02/11 às 13:00? O pedido dizia 02/10, que já passou.
3. **Faixas e prazos:** começar com os padrões acima?
4. **Troca de unidade:** o sistema aplica no Capacity (fecha numa carteira e abre na outra, como faz na saída de página), ou só sugere e o Capacity é ajustado à mão?
