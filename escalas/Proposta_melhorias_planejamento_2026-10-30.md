# 5C Escalas · Análise do planejamento e proposta de melhorias (30/10/2026)

Os números foram lidos do banco (só leitura) na madrugada de 30/10. Os cálculos do Capacity são de 29/10.

## 1. Resumo

- **Prioridade nas próximas 40 horas: a virada de 31/10 às 21:00.**
  - A semana de 02/11 tem a v9 publicada, mas nenhuma página foi marcada como postada.
  - A semana de 26/10, que cobre o turno D de 31/10 e o 01/11 (transição), não tem versão publicada: só o rascunho v9.1, com 126 lacunas.
- **O déficit de quadro é estrutural e está concentrado na U3.**
  - O Capacity pede 294 pessoas nos 9 grupos de unidade e turno e o quadro tem 220.
  - A U3 responde por 48 dos 74 que faltam (A −15, C −16, D −17).
  - Pelo plano de turmas, esse déficit dura até o 1º semestre de 2027. A escala vai conviver com lacunas toda semana.
- **A escala muda demais.**
  - Foram 12 versões em cada uma das duas semanas, em dois dias.
  - As lacunas da semana de 26/10 foram de 50 para 126 entre a v5 e a v9.1.
  - Os postos são recalculados todo dia pelo Capacity, e cada recálculo muda gente e CSV.
- **Partes do processo são manuais e frágeis.**
  - A postagem no site da 5C é por copiar e colar, página por página.
  - Os scripts rodam à mão no Navicat.
  - Não há teste automatizado nem ambiente de homologação.
  - O dado real (quem faltou, quem saiu) não volta para o planejamento.

## 2. Situação em números

| Indicador | Valor | Leitura |
|---|---|---|
| Necessidade × quadro (Capacity 29/10) | 294 × 220, saldo −74 | U1 −9 · U2 −17 · U3 −48 |
| Absenteísmo por turno | A 9,4% · C 10,5% · D 11,3% | O turno D é o pior; o buffer de plantão cobre isso |
| Semana 02/11, publicada (v9) | 96 lacunas · 24 de 1.120 posto-dias sem ninguém | 19 dos 24 posto-dias vazios são da U3 |
| Semana 02/11, rascunho (v9.1) | 120 lacunas · 30 posto-dias vazios | Piorou depois da publicação; precisa de decisão |
| Semana 26/10 (transição) | Sem versão publicada; rascunho v9.1 com 126 lacunas | Risco para 31/10 D e 01/11 |
| Vagas fixas (equipe da vaga) | 428 abertas, todas como PROPOSTA | Nenhuma confirmada |
| Mérito | 239 SRs, 36 sem nota | Menos de 100 respostas em 28 dias |
| Pedidos de indisponibilidade | 1 no banco | O ES_26 (20 pedidos do formulário) parece não ter rodado |

## 3. Desafios previstos

1. **Virada e transição (31/10 e 01/11).**
   - Escala da transição não publicada.
   - CSVs da semana de 02/11 não postados.
   - Liderança entrando no CSV da 5C Schedule pela primeira vez.
   - Uma falha aqui aparece direto no atendimento.
2. **Lacuna crônica na U3.**
   - Sem redistribuição entre unidades, a U3 fica com lacuna todo dia enquanto U1 e U2 quase empatam.
   - A hora extra tem limite (12 h seguidas, 52 h na semana) e cansa sempre as mesmas pessoas.
3. **Instabilidade dos postos.**
   - O Capacity recalcula os postos todo dia (decisão D5 em aberto).
   - Cada mudança gera versão nova, troca gente de página e obriga a postar de novo.
   - Isso pesa na operação, nos SRs (página muda) e na confiança na escala.
4. **Entrada das turmas.**
   - 02/11 e 16/11, depois 30 por turma a partir de 07/12.
   - Novato rende cerca de 50% nos primeiros 30 dias e chega como SR 0.
   - Sem regra de distribuição, o núcleo que recebe os novatos fica sobrecarregado e o Overlap (treinamento) concorre com a cobertura.
5. **Cadastro desatualizado.**
   - O turnover dos novatos é alto: 26% saem em 90 dias.
   - Quem sai do headcount continua no cadastro de colaboradores até alguém ver.
   - Já apareceram páginas inativas com posto, códigos duplicados e gente fora do headcount.
6. **O planejado não aprende com o realizado.**
   - Faltas reais, trocas feitas no site e horas extras não voltam para o sistema.
   - O mérito e o absenteísmo por SR ficam defasados, e o buffer do Capacity não é calibrado com a falta real da escala.
7. **Pedidos recorrentes.**
   - O formulário de preferências mostrou pedidos de sábado livre e de estudo à noite.
   - Se forem aprovados um a um, sem olhar o conjunto, o fim de semana e o turno D ficam sem gente.
8. **Risco técnico e de dono único.**
   - Os scripts SQL são aplicados à mão e não há controle de versão do banco.
   - Não há testes, staging ou CI.
   - As tabelas de alocação crescem cerca de 5 mil linhas por versão (54 mil hoje).
   - O conhecimento está concentrado em poucas pessoas.
9. **Postagem manual no site da 5C.**
   - São dezenas de páginas por semana, e o "Delete Range" errado apaga a escala certa.
   - É o ponto do processo com mais chance de erro humano.

## 4. Proposta de solução

### Agora (até 31/10 21:00)

1. **Checklist da virada.** Responsável e horário para cada passo, no Painel ("Ações necessárias"):
   - rodar ES_23 a ES_26 (e ES_27 e ES_28 quando quiser o Funil novo e o Modo Treino) e conferir se o ES_26 entrou;
   - decidir se a v9.1 da semana de 02/11 substitui a v9 publicada;
   - publicar a semana de 26/10 se 31/10 D e 01/11 precisarem de CSV, ou registrar que a transição usa o que já está no site;
   - postar as páginas da semana de 02/11 pela tela de postagem, que marca o que já foi feito e mostra o que não mudou.
2. **Plantão da virada.** Um OC por turno com a lista de buffers de plantão e de quem topou hora extra (tela Horas extras), para cobrir as lacunas da U3 nas primeiras 48 h.

### Curto prazo (2 a 4 semanas)

3. **Postos congelados por semana.**
   - O motor passa a ler a versão do Capacity congelada na geração (`fc_capacity_versao_posto`), não o cálculo do dia.
   - A escala só muda por evento explícito: saída ou entrada de página, troca, decisão manual.
   - Efeito esperado: de 12 versões por semana para 2 ou 3, e postagem só do que mudou.
4. **Cobertura entre unidades no mesmo turno.**
   - Regra no motor: quando uma unidade tem saldo positivo no turno e outra tem lacuna, oferecer o buffer de plantão da primeira (sugestão na Grade, com aceite).
   - Na contratação, priorizar a U3 nas turmas até o saldo empatar.
5. **Conciliação diária do cadastro.**
   - Uma rotina compara headcount, cadastro de colaboradores e páginas (desligados, novos, códigos duplicados, páginas inativas com posto).
   - O resultado aparece como pendência no Painel, com o botão de resolver.
   - Evita escalar quem já saiu.
6. **Escala × realizado.**
   - Ler o `scheduleday` postado e as faltas do dia para medir a aderência (planejado × postado × trabalhado) por página e turno.
   - Isso alimenta o mérito (absenteísmo real) e o Capacity (calibrar o buffer).
   - Primeiro como tela de leitura; depois como gatilho de recálculo.
7. **Política para pedidos recorrentes.**
   - Limite de pedidos recorrentes aprovados por núcleo e dia da semana (ex.: no máximo 20% do núcleo com o sábado livre).
   - A tela de Indisponibilidade passa a mostrar o efeito antes de aprovar ("com este, o sábado do núcleo U3 · C · 2 fica com 4 de 7").

### Médio prazo (1 a 3 meses)

8. **Entrada das turmas como fluxo do sistema.**
   - Cadastro da turma (data, quantidade por turno), distribuição automática nos núcleos com menor saldo e nível SR 0.
   - Overlap de integração e rampa de produtividade (50% nos 30 primeiros dias), que o Capacity considera no "tem".
9. **Postagem automatizada no site da 5C.**
   - Investigar se o site tem API ou importação em lote.
   - Sem isso, um robô de navegador que faz o Delete Range e cola o CSV com confirmação humana, só nas páginas que mudaram, e grava o "Postei" sozinho.
10. **Simulação dentro da plataforma.** Usar o Modo Treino como cenário para:
    - "e se esta página sair?";
    - "e se a turma de 07/12 vier com 20?";
    - "e se aprovarmos estes pedidos?".
    O motor roda no schema de treino e compara com o real.
11. **Qualidade técnica.**
    - Testes automatizados do motor e das regras (12 h, 52 h, 5 dias seguidos, CSV, turno D/T1).
    - Ambiente de homologação com o schema de treino.
    - Controle de versão do banco (tabela de migrações aplicadas, para não depender de lembrar qual ES rodou).
    - Arquivar versões substituídas com mais de 90 dias.
12. **Avisos fora da tela.** Resumo diário das "Ações necessárias" por e-mail, Nora ou WhatsApp para Planejamento e para os OCs: pedidos pendentes, saídas a conferir, páginas a postar, lacunas do dia seguinte.

## 5. Indicadores para acompanhar

| Indicador | Meta inicial |
|---|---|
| Posto-dias sem ninguém (% da semana) | ≤ 1% fora da U3; queda semanal na U3 |
| Versões por semana | ≤ 3 |
| Tempo de gerar até postar | ≤ 1 dia útil, com a semana postada até quinta 18:00 |
| Aderência planejado × trabalhado | ≥ 95% dos blocos |
| Horas extras por SR | nenhuma pessoa acima de 2 por semana |
| Absenteísmo real × previsto | diferença ≤ 1 ponto percentual por turno |
| Cadastro conciliado | 0 pendências com mais de 24 h |

## 6. Decisões para você

1. Congelar os postos por semana (item 3)? Isso fecha a D5.
2. Permitir cobertura entre unidades no mesmo turno (item 4)?
3. Teto para pedidos recorrentes por núcleo e dia (item 7)? Qual percentual?
4. Investir em postagem automatizada (item 9)? Precisa de acesso ao site da 5C para avaliar.
5. Quem é o segundo responsável técnico pelo 5C Escalas (item 11)?
