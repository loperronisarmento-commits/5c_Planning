# 5C Planning: contexto otimizado (01/11/2026, 5C Escalas 1.18.4)

Use este arquivo como prompt de contexto no início de uma sessão. Os detalhes estão em `contexto/CLAUDE.md`, `contexto/5C_Planning_Contexto_Consolidado_31out2026.md`, `capacity/README.md` e `escalas/ES_00_especificacao.md`.

## Regras fixas
- Usuário: Luis Otávio, MIS da 5C Company. Responder em pt-BR, de forma direta. As telas são em pt-BR e EN.
- Repositório `D:\5C Planning\5c_Planning`, branch `main`.
  - Commit, push e deploy só com autorização explícita, dada a cada vez.
  - Nunca commitar `resultados/` nem credenciais.
- MySQL pelo MCP:
  - `mysql_5c` é o servidor `.11`: desde 02/11/2026 o Claude grava direto, só o que o usuário pediu;
  - `mysql_7` é o data lake `.7`: só leitura.
- Alterações no banco:
  - ficam registradas como script `escalas/sql/ES_NN_*.sql` ou `capacity/sql/5C_Planning_NN_*.sql`;
  - todo script começa com `SET NAMES utf8mb4`;
  - um CALL roda pelo `mysql.exe --defaults-extra-file=%APPDATA%\MySQL\5c.cnf` (o MCP faz rollback de CALL).
- Segurança e privacidade:
  - nunca abrir o `5c.cnf` nem a config do MCP;
  - nunca ler senha, CPF, token nem `DB_PASS`;
  - nunca usar `SELECT *` em `5c_headcount.headcount`;
  - a Política de Escalas é confidencial.
- Cargos: OM (unidade), OC (unidade e turno), OS (núcleo), OA e SR (níveis 0 a 5). Nunca usar os nomes antigos (OUM, Equite, Decurio...).
  - Marcus V. Silva é COO.
  - O Director é Natan Dalla Corte.
- Pronomes: linguagem neutra quando o pronome não é conhecido.

## Estrutura (desde 31/10 às 21:00)
- **Unidades e carteiras:** 3 unidades (U1 Victoria, U2 João Paulo, U3 Keller), com 2 carteiras cada (C1 ≈ 60% e C2 ≈ 40% do faturamento).
  - A página pertence à carteira só pelo `5c_planning.fc_pagina_carteira`.
- **Turnos:**
  - A = T2/T3, das 05h às 13h;
  - C = T4/T5, das 13h às 21h;
  - D = T6/T1, das 21h às 05h; o D de um dia começa às 21h do dia anterior.
- **Núcleos:** 3 por unidade e turno, 27 ao todo. Por dia trabalham 2 dos 3.
- **Rotação 5x2 de 21 dias:** 14 dias no posto e 1 de Overlap. O fator é **1,5 pessoa por posto**.
- **Posto:** 1 SR online o turno inteiro.
  - A página com mais de 35 fãs/h no pico ocupa 2 postos.
  - Agrupamento: até 3 páginas da mesma modelo por posto, ou até 2 de modelos diferentes.
  - Por faturamento: abaixo de US$ 16 mil vão 3 páginas por posto, abaixo de US$ 32 mil vão 2.
- **Distribuição dos postos:** fixa em `fc_posto_fixo`, que o `sp_fc_postos_carteira` segue exatamente. A página que não está nessa tabela cai na regra automática.
- **Supervisores (Equilíbrio):**
  - OS1 fica com as maiores páginas, OS3 com as menores e OS2 com um misto, cobrindo os outros dois.
  - O OS novo recebe as páginas de menor valor e em maior número (`v4_os_novato`).

## Capacity (`5c_planning`)
- **Titulares** = postos × 1,5, arredondado para cima.
- **Buffer:** (faltas de 56 dias + pico de 28 dias) ÷ (1 − taxa de falta) × 3/2, com no mínimo 2 por núcleo.
- **Recálculo:** `CALL sp_fc_recalcular_postos(data,'motivo')`, que grava uma versão. Não usar o `sp_fc_postos` sozinho.
- **Armadilhas:**
  - página com `vigente_desde` anterior ao início da estrutura derruba o cálculo;
  - página reativada precisa da foto `fc_pagina_status_diario` corrigida;
  - nomes podem diferir na caixa entre Capacity e site, então comparar sem caixa e sem espaços.

## 5C Escalas (módulo PHP do MIS Portal, schema `5c_escalas`)
- **Código:** `escalas/sistema/5c_escalas/`, servido em `/escalas`.
- **Gravação:** só em `5c_escalas`. As exceções estão em `escCapacityGravar`: `fc_pagina_carteira` e os CALLs de postos.
- **Gerar** (`escMotorGerar`, `lib/motor.php`), nesta ordem:
  1. `escEstruturaAtualizar`: sincroniza com o Capacity e aplica só a troca de unidade **mais recente** de cada página.
  2. `escEquipesCompletar`: nenhum posto fica sem equipe.
  3. `escMotorAlocarV4`, em `lib/motor_v4.php`.
  4. `escMotorProtegerPublicado`: mantém da escala publicada só quem continua na estrutura, na mesma unidade e turno e num posto da página.
- **Passos do motor v4:**
  - −1: equipe fixa da página (`esc_pagina_prioridade`, vigência por linha).
  - 0: melhores SRs. Primeiro quem fez a página e bateu ≥ 70% da meta em 30 dias (`v4_melhores_meta_min`), depois por US$/h. A fila vai da modelo que mais fatura para a que menos fatura.
  - 1 a 3: vagas DONO e COBERTURA, que são preferência e não trava.
  - 6: acumula postos.
  - 7: sobrecarga por grupo de modelo, depois mesma agência, até +30% (`v4_over_capacity_max`).
  - Fim: `_ordem` define quem é REGULAR (o primeiro) e quem é ASSISTENTE (`#` no CSV).
- **Regras dos managers por página:**
  - `esc_pagina_prioridade`: equipe fixa da página.
  - `esc_pagina_assistente.papel`:
    - ASSISTENTE: só assistente na página, em qualquer nível;
    - FORA: nunca entra na página.
  - `v4_todos_os_dias`: SR fora da rotação, todo dia no próprio posto, com folga a cada `v4_todos_os_dias_seguidos` dias.
  - Página sem assistente: tirar os assistentes dela.
  - Páginas que dividem posto ficam com 1 pessoa por dia; a divisão exata é feita travando células na Grade.
- **Faturamento:**
  - conta 30 dias, `complete` + `pending`;
  - página com menos de 30 dias é emulada;
  - página sem venda usa o meio da faixa do tier;
  - OFTV ou código terminado em "t" é desconsiderada (carga zero), salvo com ≥ US$ 8 mil;
  - `esc_pagina_config` força o tratamento.
- **Postagem no site da 5C:** favoritos do Chrome em `ferramentas/`, que rodam em WebPortal › Schedule Tools.
  - Delete Range em lote (`POST /Scheduler/DeleteScheduleForRange`).
  - Postar CSV em lote (`POST /Scheduler/InsertNewScheduleEntry`, com `inputCSV`, `StartWeek` e `ModelId`), que lê o pacote .json da Grade (`?baixar=lote`, sempre a última versão publicada).
  - Para ler o que está postado: `POST /Scheduler/ScheduleDay2CSV`.
- **Escala já postada:** a noite de hoje e o T1 de amanhã são editados à mão no 5C Schedule. O `5c_schedule.scheduleday` é só importação.
- **Tabelas principais:**
  - postos e equipes: `esc_posto`, `esc_posto_pagina`, `esc_vaga_equipe`;
  - pessoas e liderança: `esc_colaborador_config`, `esc_nucleo`, `esc_lideranca`;
  - planos: `esc_plano`, `esc_plano_alocacao`;
  - regras e histórico: `esc_parametro`, `esc_politica_troca`, `esc_indisponibilidade(_data)`, `esc_log`.
- **Versão:** a 1.18.4 está no ar, no commit `5fb92ed`. Toda versão nova entra em `lib/versao.php` e `lib/versao_en.php`, e o changelog aparece no rodapé.

## Fluxo de entrega
1. Lint local: `D:\Ferramentas\php\php.exe -l`.
2. Simulação sem gravar: `resultados/sim_0211/run.sh gerar|pdf N|org N dia altura`.
3. Montar o zip: `python deploy/montar_zip.py`.
4. Deploy: `.\deploy\deploy.ps1`, com o aval do usuário.
   - Ele usa SSH na porta 2222 com `scp -O`, porque o servidor não tem SFTP, e `-EncodedCommand`, porque o shell remoto é o cmd.exe.
   - O deploy faz backup no servidor; `-Listar` e `-Voltar` restauram.
5. Conferir o rodapé e gerar a semana no site.
6. Commit; o push é feito pelo usuário.

## Pendências (01/11)
- Revisar as 24 sugestões WORKLOAD do plano 92 (v30) e publicar.
- Fazer à mão no 5C Schedule as trocas da noite de 01/11: Sheila, Caroline, Leonardo e Angeline.
- Confirmar se as 12 células MANUAL travadas do J102994 (04 a 06/11) foram de propósito.
- Perguntas sem resposta: qual página "saiu da 5C" e qual o dia extra do Luan na Liv (exige travar na Grade).
