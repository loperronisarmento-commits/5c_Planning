# 5C Escalas — especificação (rascunho v3, 20/10/2026)

Sistema de planejamento de escalas no MIS Portal (PHP, servidor `.11`). Incorpora três módulos que existem hoje: **Escala de Buffers**, **Schedule Anomalies** e **Schedule Access Audit**.

- **Rascunho clicável das 19 telas:** https://claude.ai/artifact/BJDKQYLPz119juqs4hcBP8. É privado; para outra pessoa abrir, compartilhe pelo menu Share.
- **Fonte das telas:** `escalas/rascunho_telas/`.

---

## 1. O que o sistema faz

1. **Estrutura Team → Party → membros.** Vem do Planner (API historicplanner).
   - Regra: cada party com 1 Primaris e 2 Secundus.
   - A distribuição desses titulares nos turnos **não é regra**. Também **não há mínimo de Tertius por turno**: se a party concentra os titulares em 2 turnos, o terceiro fica com Tertius.
   - A tela de times mostra as violações com ação de correção, faltas ou sobras de Tertius por time e turno (ligadas ao Capacity das páginas de cada party) e permite **adicionar, mover e remover** pessoas.
   - **Não existe API de escrita no Planner.** Cada mudança vira uma **pendência no Planner** (aba própria na Configuração dos times), com a instrução pronta para copiar.
   - A pendência **fecha sozinha** quando a próxima leitura do `historicplanner` mostra a formação pedida. Ela fica **Atrasada** 2 dias após a data sem aparecer, e **Divergente** se o Planner mudar diferente do pedido.
   - Enquanto a pendência está aberta, a escala já usa a formação nova a partir da data informada.
2. **Colaboradores.** Cada pessoa tem **turno fixo** (A, C ou D, sempre o mesmo), modelo de escala (3x1, 5x2, 4x3…) e posição no ciclo.
   - No **3x1, o turno não roda**: só a folga avança pelo calendário.
   - A posição no ciclo é recalculada toda semana pela releitura do realizado.
   - O cartão de cada pessoa mostra código e Telegram (`5c_bot.vw_rep.telegram`, que vem do headcount).
3. **Geração da escala** por time, dia e turno, a partir da necessidade por página do Capacity:
   0. **releitura do realizado:** `scheduleday` das últimas 2 semanas → dias seguidos, última folga real e posição no ciclo;
   1. party dona da página;
   2. outras parties do time (mesmo turno, em ordem), respeitando o limite de páginas e de fãs/h;
   3. Tertius como regular;
   4. Tertius como assistente (onde o Capacity pede 2 ou mais reps);
   5. Buffers (pool sem time);
   6. o que sobrar vira lacuna.
   
   **Validação:** um plano com anomalia programada não pode ser publicado (limites pelo modelo de cada pessoa).
4. **Recálculo durante a semana.** É disparado por:
   - entrada ou saída de página (`fc_pagina_eventos`, já automático);
   - mudança de estrutura;
   - indisponibilidade aprovada;
   - falta não programada;
   - cálculo diário do Capacity às 06:30.
   
   **Buffers que faltam são adicionados sozinhos**, gerando uma nova versão do plano (ex.: v3.1) com aviso. **Dias já escalados nunca são retirados**: o excesso vira **sugestão de remoção de turno por falta de workload**. Exemplo real: 10 páginas saíram em 20/10, ≈ −5,3 reps/h por turno.

   **Saída de página exige checagem.** O gatilho abre uma pendência para quem opera o sistema (tela Buffers, contador no menu e aviso no Painel).
   - O efeito já vem calculado por página (reps/h por turno e pessoas, de `fc_impacto_paginas`), mas só entra na escala depois de **Confirmar saída**.
   - **Não saiu** descarta o gatilho: a página continua na necessidade e o sistema pergunta de novo em 3 dias se ela seguir parada.
   - O que conferir: CRM em churn ou cancelada, saída do Planner, e se não é só uma pausa.
   - Hoje o `sp_fc_paginas_eventos` grava a saída automática direto como `EFETIVADA`, e o `sp_fc_impacto_paginas` já desconta. Para a checagem valer também no Capacity, a saída AUTO precisa nascer com status `A_CONFERIR` e ser ignorada no impacto até a confirmação (ajuste no Capacity, script à parte).
5. **Buffers automáticos.** O pool é preenchido pela necessidade do Capacity por dia e bloco de 4 h, depois dos times. Um modo manual permite ajustar.
6. **Mérito dos Tertius.** O alvo de turnos como regular vai de 30% a 70%, pelo quadrante desempenho × assiduidade:

   | Quadrante | Alvo regular |
   |---|---|
   | Destaque | 70% |
   | Entrega, mas falta | 50% |
   | Presente, abaixo da meta | 50% |
   | Atenção | 30% |
   | Novatos (menos de 30 dias) | 50% |

   A vaga de regular vai para quem está mais abaixo do próprio alvo; a de assistente, para quem está mais acima. A cobertura vem antes. A tela explica cada caso.
7. **Sugestões** dos tipos workload, cobertura, mérito, folga e estrutura. Cada uma tem Aplicar, Descartar (com motivo) e Desfazer. Nada é aplicado sozinho.
8. **Indisponibilidade.** O colaborador pede um dia, vários dias ou **recorrente** (dias da semana, frequência e horário, no máximo 3 meses). A **WFM** aprova:
   - vê o impacto na cobertura e quantas vezes a pessoa já pediu (90 dias e total);
   - recusar exige comentário;
   - a série recorrente pode ser aprovada inteira ou data a data.

   **Caminho principal do colaborador: a Nora (tela 19).** O colaborador manda `/pedido` no Telegram.
   - A Nora pergunta: um dia, vários dias ou toda semana; o dia (só os dias em que a pessoa está escalada, com o turno); turno todo ou parte (por bloco); e o motivo (atestado pode ir como foto). Depois mostra um resumo para enviar ou corrigir.
   - Com menos de 72 h, o pedido vai como urgente.
   - Cai na **mesma fila** da tela Aprovar, marcado "via Nora".
   - **A consulta e a decisão ficam no site** (tela Aprovar): histórico, contagem de pedidos e impacto na cobertura. Recusar exige comentário.
   - A Nora avisa o colaborador quando o WFM decide (com o comentário, se recusado). `/escala` mostra a semana.
   - A Nora reconhece o rep pelo Telegram cadastrado no headcount (`vw_rep.telegram`). Quem não bate é orientado a procurar o WFM.
   - A Nora grava pelo portal (endpoint interno do 5C Escalas com token de serviço), não direto no banco. Assim a fonte única é o `esc_indisponibilidade` (campo `canal`: nora ou site).
   - O formulário do site continua para o WFM lançar em nome de alguém e para quem não usa Telegram.
9. **Funil de entrada de páginas.** Espelha as etapas do **CRM**: Pipeline (30%) → Onboarding (80%) → Active (1ª semana).
   - Cada card do `5c_crm.pipeline_cards` vira uma página prevista. A data vem do contrato (`contracts.start_date`, `status`) e o tier, do cliente (`clients.tier_id` → `tiers`, faixa de receita mensal).
   - O cadastro da página é feito no CRM (botão "Cadastrar página no CRM"). Aqui só se **ajusta a previsão** (vale só para escala e Capacity) e se define o **destino**.
   - Card sem tier ou sem data aparece com aviso "dados faltando no CRM".
   - **Volume por arquétipo.** O CRM normalmente só traz o tier. O volume vem das páginas ativas com faturamento e perfil parecidos:
     - arquétipo = tier (faixa de faturamento) + perfil (Main, VIP, Free, OFTV);
     - fãs/h por turno e curva por dia × bloco = média das páginas do arquétipo nos últimos 28 dias (`fc_fans_pagina_hora`);
     - menos de 3 páginas: junta os perfis do mesmo tier; nenhuma: interpola entre os tiers vizinhos pela receita e marca **confiança baixa**;
     - o WFM pode trocar a base por uma **página de referência** ou por fãs/h informados;
     - na 1ª semana em Active usa a estimativa; depois, os fãs reais.
   - Reps por turno pela regra do Capacity (até 28 fãs/h divide rep; acima, teto de fãs ÷ 35).
   - Impacto ponderado pela probabilidade, por semana de entrada.
   - Destino sugerido (time e party), com o motivo.
   - Simulação na escala.
   - Ao confirmar, a escala é recalculada.
10. **Trocas de página entre parties** (tela 18). Acontece muito: 46 a 79 trocas por semana em outubro, quase sempre lançadas no Planner com menos de 24 h de antecedência.
    - **Fonte:** `5c_historic_planner.historic_page_planner`, `changeType = 'Planned Move'` (destino: time e party). `isApplied = 0` é troca futura. O **corte é no horário do `timestamp`**. Quase sempre é 20:59, ou seja, a troca vale a partir do T6 (21:00) do dia. **T6 e T1 são um bloco fechado:** quem começa o T6 termina o T1 do dia seguinte. Isso bate com o `scheduleday` da Mandi Cat, que foi para Cronus Invictus II em 18/10: os membros de Ippon I atuaram até o T5 de 18/10 e os de Cronus Invictus a partir daí.
    - **Realocação:** cada pessoa já escalada mantém **dia e turno** e muda só de página:
      - titular (Primaris, Secundus) vai para a página que a **própria party** recebeu; se recebeu mais de uma, a de maior necessidade no turno vem primeiro;
      - Tertius fica no time;
      - Buffer fica na página.
    - **Cadeia fechada** (toda party perde e recebe uma página): só troca de página. **Cadeia aberta:** a party que recebeu sem perder ganha lacuna (coberta por Tertius e Buffers); a que perdeu sem receber tem titulares sobrando (vira sugestão de assistente ou de cobertura).
    - **Postagem sem API:** para cada página afetada, o sistema prepara Model, Date Schedule e Date End (do dia da troca até o fim da escala postada) e o Code CSV daquele intervalo (7 colunas, vazias fora do intervalo). O CSV parte do que está postado e troca só os códigos, mantendo o `#` de quem é assistente. O `$` não vai no CSV: o site calcula sozinho.
    - **Party que recebeu sem perder:** os titulares assumem a página nova junto com as que já têm, se couber no limite de fãs/h. O que não couber vira lacuna, coberta por Tertius e Buffers.
    - **Ordem no Schedule Ajustment** (troca no dia X às 21:00), em todas as páginas do pacote antes de postar qualquer uma:
      1. **Delete Range** de X+1 até o fim da escala postada;
      2. apagar **à mão o T6 do dia X**;
      3. **Save Schedule CSV** com Date Schedule = X. A coluna do dia X vai só com o T6 (T1 a T5 em branco). O intervalo precisa começar em X, senão o site não aceita o CSV. O T1 de X+1 já sai com a party nova.

      Se o Planner marcar outro horário, o corte é nesse horário: apagam-se à mão os blocos do dia X a partir dele, e a coluna do dia X vai só com esses blocos.

      Cada página é marcada como apagada e depois como postada. A próxima leitura do `scheduleday` confere e marca "Conferido", ou aponta a célula que não bateu.
    - Roda sozinho a cada leitura do Planner (15 min) e avisa o WFM no Telegram quando há troca para o dia seguinte. Também há pacote .zip (um CSV por página + roteiro).
11. **Monitoramento.**
    - **Anomalias:** o limite de dias seguidos vem do modelo (3x1 até 3, 5x2 até 5, 4x3 até 4). Separa anomalia programada de não programada.
    - **Auditoria de acesso:** Infloww × páginas do plano publicado.
12. **Postagem.**
    - **Hoje:** CSV no formato atual (T1–T6, um arquivo por página), com prévia, Copiar e Baixar.
    - **Previsto:** "Postar via API", desligado até existir a API de escrita do `scheduleday`.
    - Também há um CSV "uma linha por turno" (papel e origem) pensado para essa API.
13. **Glossário** com busca e categorias.

## 2. Menu e telas

| Seção | Telas |
|---|---|
| (contexto no menu) | Semana e Time escolhidos uma vez, valem para todas as telas |
| Planejamento | 01 Painel · 05 Gerar escala · 06 Grade · 07 Equilíbrio Tertius · 08 Sugestões · 09 Buffers |
| Páginas | 16 Funil de entrada · 18 Trocas de página |
| Cadastros | 02 Configuração dos times · 03 Colaboradores · 04 Modelos de escala |
| Indisponibilidade | 19 Pedido pela Nora (principal) · 10 Solicitar no site · 11 Aprovar (WFM) |
| Acesso do colaborador | 17 Login no site por código de rep + código de uso único pela Nora (**fase 2**: com a Nora, o rep não precisa entrar no site para pedir) |
| Monitoramento | 12 Anomalias da escala · 13 Auditoria de acesso |
| Administração | 14 Acessos por tela |
| Ajuda | 15 Glossário |

Telas clicáveis no rascunho: 02, 06, 08, 09, 10, 11, 14, 15, 16, 17, 18 e 19.

**Login do colaborador (17).** Os reps ainda não têm login no portal.
- Entra com o código de rep e recebe pela Nora um código de 6 dígitos no Telegram do headcount (`vw_rep.telegram`).
- O código vale 5 minutos e uma vez só; reenvio depois de 60 s. 5 erros bloqueiam por 15 min e avisam o WFM.
- Só quem está ativo no headcount recebe código. A tela não revela se o código de rep existe.
- Sessão de 12 h, ou 30 dias com "manter conectado". O colaborador vê só a própria escala, os próprios pedidos e o Glossário. Todo acesso vai para a Auditoria de acesso.
- Gestão, WFM e Planejamento entram com a conta do portal, como hoje.

## 3. Integrações

**API 5C de leitura** (`api.5ccompany.com`). Token Bearer de 1 h via `POST /api/Auth/token`; os dados são só GET, sem paginação.

| Dado | Endpoint | Grava em | Uso no sistema |
|---|---|---|---|
| Escala realizada | `GET /api/PowerBi/scheduleday?start=dd/MM/yyyy&end=dd/MM/yyyy` | `5c_schedule.scheduleday` | Releitura, anomalias, conferência da postagem |
| Times, parties e membros | `GET /api/PowerBi/historicplanner` (pagePlanner, membersPlanner, tierPlanner, operationManager) | `5c_historic_planner.*` | Estrutura e páginas por party |
| Headcount | `GET /api/PowerBi/headcount` (inclui `telegram`) | `5c_headcount.headcount` | Pessoas ativas, Telegram |
| Páginas | `GET /api/PowerBi/pages` | `5c_pages.pages` | Páginas ativas e tier |

**Atualizar agora.** Usa as importações que já existem no portal: `import_stream.php` de cada ETL, com progresso pelos eventos `status`, `progress`, `log`, `done` e `fatal`.
- A importação manual tem prioridade sobre a `etl_auto`, via `etlAutoYieldForManual`.
- A escala é importada por período (`start`/`end`). O espelho protege dias que vierem vazios e trava remoções acima de 50%.

**Escrita.** **Não existe** API de escrita (POST/PUT) para o `scheduleday` nem para o Planner.
- `scheduleday`: o botão "Postar via API" fica previsto e desligado.
- Planner: mudanças de estrutura viram pendências, conferidas na leitura do `historicplanner`.

**CSV de postagem (Schedule Ajustment do site da 5C).** Campos da tela: Model (página), Date Schedule, Date End e Code CSV. Botões: Load Previous CSV, Load CSV (No Absences), Delete Range e Save Schedule CSV.
- **6 linhas:** T1 (01–05), T2 (05–09), T3 (09–13), T4 (13–17), T5 (17–21), T6 (21–01).
- **Sempre 7 colunas, de segunda a domingo,** separadas por `;`. Dia fora do intervalo fica vazio. Exemplos: de terça em diante começa com `;`; de quarta a sábado fica `;;…;…;…;…;`.
- Códigos separados por `, `, com marcadores. Isso foi conferido no `scheduleday` da Aja (12 a 18/10), bloco a bloco:
  - `$` = secondary (`shiftSecundaria` no `scheduleday`): a pessoa tem mais de um turno no mesmo horário, normalmente em double. **O site calcula sozinho no input**, então o CSV gerado não leva `$`;
  - `#` = `assitente = 1`; quando há `$`, vem depois dele (`M112585$#`);
  - falta não entra no CSV (o site tem "Load CSV (No Absences)").
- O `$` vale por bloco. A mesma pessoa pode ter `$` no T6 e não ter no T1.
- O Delete Range apaga só a página escolhida em Model.
- **T6 e T1 são um bloco fechado:** o T1 de um dia é o fim do turno D do dia anterior. O T6 de domingo continua no T1 de segunda da semana seguinte; se ela já estiver postada, vira um segundo pacote só com esse T1.
- Uma coluna pode ir com só alguns blocos preenchidos (ex.: só o T6 no dia da troca); os outros ficam em branco.
- Um CSV por página; o .zip junta todos.

**CRM.** O funil fica no CRM (`5c_crm`):
- `pipeline_stages`: pipeline, onboarding, active, churn, canceled;
- `pipeline_cards` (cliente, contrato, etapa);
- `contracts`: valor, ciclo, `start_date`, `status`;
- `clients.tier_id` → `tiers` (faixa de receita mensal em US$: SS 801k–1,1M, S 450–800k, A 201–450k, B 121–200k, C 61–120k, D 41–60k, E 21–40k, F até 20k).

Hoje só há 3 cards de teste em Pipeline. Um job lê o CRM e grava em `5c_planning.fc_paginas_previstas` (`origem = 'CRM'`, `crm_id`), que hoje está vazia.

**Tier confiável.** O tier de `5c_bot.vw_pagina` está quase todo como C (padrão do cadastro). O tier real das páginas ativas está em `5c_wfm_db_mis.tab_intraday_base.Tier`, que é o usado nos arquétipos.

## 4. Achados do levantamento (20/10)

- **Formação:** 53 parties, 28 conformes e 25 fora da regra:
  - 5 sem Primaris;
  - 1 com 2 Primaris;
  - 13 com 3 ou 4 Secundus;
  - 6 com menos de 2 Secundus;
  - além disso, 11 parties sem página ativa.
- **Tertius por time e turno** (Capacity → página → party → time): faltam ~52 em 26 time-turnos e sobram ~23 em 16. O saldo de ~29 é coberto pelos 57 buffers. Os extremos são Cavalry (+13,8) e Vanguard (−6,7).
- **A cobertura entre parties raramente cabe no limite de 35 fãs/h**, porque as páginas têm de 28 a 45 fãs/h. Na prática, quem cobre são os Tertius e os Buffers.
- **Horizonte do Capacity:** o `fc_escala_pagina` cobre só 7 dias (perfil por dia da semana). É preciso estender para escalas de 2 a 4 semanas.
- **AF corrigida em produção (20/10):** absenteísmo do Capacity A 8,9% · C 9,9% · D 10,8%.
- **Arquétipos (21/09 – 18/10, 93 páginas com dado).** Fãs/h médios por tier e perfil:

  | Arquétipo | Páginas | A | C | D | Faixa |
  |---|---|---|---|---|---|
  | SS · VIP | 1 | 117,5 | 122,4 | 115,0 | — |
  | A · Main | 4 | 33,8 | 38,8 | 35,3 | 14,8 a 51,1 |
  | B · Main | 4 | 25,3 | 32,1 | 34,4 | 27,4 a 36,2 |
  | C · Main | 13 | 17,2 | 19,5 | 17,8 | 8,4 a 31,3 |
  | C · VIP | 6 | 14,2 | 18,4 | 16,3 | 9,2 a 21,7 |
  | D · Main | 8 | 9,3 | 10,4 | 9,2 | 3,9 a 18,8 |
  | E · Main | 12 | média 9,8 | | | 4,4 a 17,0 |
  | F · Main | 29 | média 6,3 | | | 2,0 a 9,1 |

  Não há página S ativa com dado. A fórmula atual do Capacity (receita ÷ 720 × k do tier) dá B 33,3, C 22,2 e D 11,7 fãs/h no meio da faixa, ou seja, **superestima C e D**. Vale recalibrar os `k_*` com os arquétipos.
- **Saídas de 20/10** (todas automáticas, por inativação no cadastro de páginas): Cynthia −2,00 reps/h por turno (−8,7 pessoas); as outras 9 de −0,33 a −0,43 cada. Total −5,36.

## 5. Base de dados `5c_escalas` (scripts em `escalas/sql/`)

Schema novo e separado, `utf8mb4_unicode_ci`. **Nenhuma tabela existente é alterada.** A escala oficial continua no site da 5C e no `scheduleday`. Aqui ficam as configurações, as decisões tomadas em tela e o **rascunho próprio** da escala, com o De→Para de cada recálculo.

| Script | O que faz |
|---|---|
| `ES_01_criar_schema_5c_escalas.sql` | Cria o schema, as 22 tabelas, os 4 modelos (3x1, Primaris 3x1 + admin, 5x2, 4x3) e os parâmetros. Pode rodar de novo. |
| `ES_02_carga_inicial.sql` | Configuração de cada colaborador a partir do headcount vigente (turno fixo, tipo, modelo). Conferido em 21/10: 291 pessoas, 50 Primaris e 56 Buffers. |
| `ES_03_permissao_usuario_portal.sql` | `GRANT SELECT, INSERT, UPDATE, DELETE` no `5c_escalas` para o usuário do portal (preencher usuário e host). |
| `ES_99_desfazer.sql` | Apaga o schema inteiro. |

**A. Cadastros e configuração**

| Tabela | Para quê |
|---|---|
| `esc_modelo_escala` | Ciclo (`1110`, `1111100`…), tipo contínuo ou semana fixa, horas/dia, trilhas, dia admin, máx. de dias seguidos |
| `esc_colaborador_config` | Turno fixo, modelo, trilha, âncora, folgas fixas, pode ser assistente, vigência |
| `esc_parametro` | Formação, geração, mérito e prazos |
| `esc_acesso` | Perfil por usuário do portal (Admin, WFM, Planejamento, Gestor do time, Leitura) |

**B. Decisões tomadas em tela**

| Tabela | Decisão |
|---|---|
| `esc_indisponibilidade` + `_data` | Pedido (Nora, site ou WFM) e a decisão de cada data, com comentário |
| `esc_estrutura_pendencia` | Mudança de formação a aplicar no Planner e a conferência |
| `esc_violacao_acao` | Ação de correção, responsável, prazo, justificativa |
| `esc_saida_checagem` | Saída de página confirmada ou "não saiu" |
| `esc_pagina_previsao` | Previsão ajustada (arquétipo, referência ou informada) e destino da página do funil |
| `esc_sugestao` | Sugestão aplicada ou descartada, com motivo |
| `esc_troca_postagem` | Pacote das trocas de página: CSV, apagado, postado, conferido |
| `esc_exportacao` | CSVs gerados para postar e a conferência com o `scheduleday` |

**C. Rascunho da escala**

| Tabela | Conteúdo |
|---|---|
| `esc_plano` | Cada versão da semana (v3, v3.1…), status, Capacity usado, hash das entradas, quem gerou e publicou |
| `esc_plano_alocacao` | Dia × bloco × página × pessoa, papel, origem (passo 1 a 5, manual, lacuna). `travada` = ajuste manual que o recálculo respeita |
| `esc_plano_folga` | Folgas do período (ciclo, folga fixa, indisponibilidade, admin) |
| `esc_ciclo_estado` | Releitura do realizado: dias seguidos, última folga, trilha calculada |

**D. Recálculo e De→Para.** A escala é recalculada sempre que muda qualquer entrada: Capacity, estrutura, membros do time, páginas, trocas de página, headcount, indisponibilidade, colaboradores, modelos ou parâmetros.

1. **Foto das entradas** (`esc_entrada_foto`): a cada leitura, o sistema grava o hash de cada fonte. Hash igual = nada mudou.
2. **De→Para das entradas** (`esc_entrada_diferenca`): se o hash mudou, grava o que mudou, item a item. Exemplo: rep X, campo party, de Felix Legion I para Felix Legion II; página Y, campo reps_dedicados no T6, de 2 para 1.
3. **Evento de recálculo** (`esc_recalculo_evento`): o gatilho, a versão de origem e a nova. Saída de página fica em `AGUARDANDO_CHECAGEM` até alguém confirmar.
4. **Nova versão do plano** (`esc_plano`, ex.: v3 → v3.1). As células travadas (ajuste manual) são mantidas.
5. **De→Para da escala** (`esc_plano_diferenca`): cada célula que mudou (entra, sai, troca, papel, lacuna aberta ou fechada) com o motivo. Retirar um dia já escalado nunca é aplicado sozinho: fica `aplicado = 0` e vira sugestão de workload.

**E. Auditoria.** `esc_log`: quem fez o quê, pelo site, pela Nora ou pelo sistema, com o antes e o depois.

**Ficou de fora desta etapa:** login do colaborador no site (fase 2), fotos de arquétipo e de mérito (calculados na hora a partir das tabelas existentes) e meta manual de buffer (entra quando a tela de Buffers tiver o modo manual com gravação).

**Funil.** O ajuste fica em `esc_pagina_previsao`. Levar o volume do arquétipo para o Capacity (`5c_planning.fc_paginas_previstas.fas_h_prev`) é uma escrita no `5c_planning`; fica para depois, com script próprio.

## 6. Padrões de UX aplicados

- Semana e Time globais no menu; filtros locais só refinam.
- Contadores no menu: violações, pedidos e sugestões abertas.
- Toda ação tem retorno visível (aviso com **Desfazer**). Ações irreversíveis (Publicar, Remover) pedem confirmação mostrando o impacto. Recusar exige comentário.
- Telas longas viram abas (Configuração dos times). Linhas e cartões clicáveis abrem um painel de detalhe com o **porquê** (ex.: origem de cada célula da grade).
- Filtros como chips com contagem. Estados vazios explicados. Botões desligados dizem por quê (ex.: "Postar via API · em breve").
- Hover e foco visível para teclado; cores sempre com texto ou ícone, nunca só a cor.
- O Glossário explica cada termo e leva à tela onde ele aparece.

## 7. Perguntas em aberto

1. ~~Login do colaborador~~ **Respondida:** os reps ainda não têm login. O pedido de indisponibilidade vai pela Nora (tela 19). O login no site por código de uso único (tela 17) fica para uma fase 2. O rep envia pela Nora, e a consulta e a decisão ficam no site.
2. **Indisponibilidade:** antecedência mínima (rascunho: 72 h), limite de pedidos por mês, teto do recorrente (rascunho: 3 meses).
3. **Cobertura entre parties:** respeita os 35 fãs/h (quase nunca cabe) ou pode passar com alerta?
4. **Turno secundário (dobra):** entra no planejamento como oferta?
5. **Remuneração no mérito:** confirmar o cálculo (base US$ 2/h + comissão; assistente com 10% da comissão do titular).
6. **Tertius e Buffers:** um Tertius pode cobrir outro turno? Buffer pode ser assistente?
7. **Primaris:** o dia admin a cada 12 entra no ciclo ou é escolhido?
8. **Horizonte:** a escala é gerada por semana ou por 2 a 4 semanas? Isso define o horizonte do Capacity.
9. **Mérito:** usar a medida do 5C George (Target coverage sem offsetting)? Os cortes de 100% e 10% estão bons?
10. **Funil, respondida em parte:** o funil fica no CRM, que normalmente só traz o tier; o volume vem do arquétipo. **Em aberto:** quem define o destino da página e quem classifica o perfil (Main, VIP, Free, OFTV) quando o CRM não informa.
11. **Pendências de estrutura, respondida em parte:** não existe API de escrita no Planner, então viram pendências conferidas na leitura. **Em aberto:** quem aplica no Planner e em quanto tempo (rascunho: 2 dias até virar "Atrasada").
12. **API de escrita do scheduleday:** há previsão? Qual formato aceitaria (sugestão: o CSV "uma linha por turno")?
13. **Remoção de turno por workload:** com quanta antecedência avisar a pessoa, e por qual canal (Telegram/Nora)?
14. **Checagem de saída de página:** quem confirma (WFM ou Planejamento) e em quanto tempo? Rascunho: até a próxima geração; se ninguém conferir em 48 h, avisa no Telegram.
15. **Recalibrar o Capacity:** ajustar os `k_*` dos tiers pelos arquétipos e criar o status `A_CONFERIR` nas saídas automáticas? Os dois são scripts no `5c_planning`.
16. **Schedule Ajustment, respondida:** o CSV tem sempre 7 colunas, com vazio fora do intervalo. A troca vale às 21:00 do dia; apaga-se de X+1 em diante mais o T6 de X à mão, e posta-se de X com só o T6 nesse dia. O Delete Range apaga só a página do Model. O `$` (secondary) o site calcula sozinho.
17. ~~Trocas fora das 21:00~~ **Respondida:** o corte é no horário apontado no `historic_page_planner`.

## 8. Próximos passos

1. Revisar as telas no canvas (comentários direto nas telas) e responder às perguntas da seção 7.
2. Entregar o DDL do schema `5c_escalas` e a carga inicial (`headcount_turno` → colaboradores; modelos 3x1, 5x2, 4x3).
3. Montar o núcleo PHP: auth do portal, acesso por tela, logger, lock e cliente da API 5C. Depois as telas, na ordem 02 → 03/04 → 05/06 → 09 → 10/11 → 07/08 → 16 → 12/13.
