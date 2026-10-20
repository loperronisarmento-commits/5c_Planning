# 5C Planning — Contexto consolidado (20/10/2026)

Síntese dos cinco pacotes de contexto assimilados nesta sessão:

| Fonte | Conteúdo |
|---|---|
| `5C_Capacity_Planning_Handoff_20out2026.md` | Capacity atual, premissas, necessidade de reps, plano de turmas e schema `5c_planning` |
| `5C_Arquitetura_DataLake.md` | Arquitetura medalhão: Bronze no `.7`, Prata/Ouro no `.11`, destino tabela a tabela |
| `5C_Contexto_Relatorio_Diretoria.md` | Relatório Power BI da diretoria (v38 → No offsetting → **5C GEORGE**), regras de negócio, views e absenteísmo |
| `realtimenora_documentacao_md.md` | Nora Real Time (bot Telegram), guia do schema `5c_bot`, coletor do Infloww, kit PHP |
| `contexto_5c_claude_code.zip` (688 arquivos) | `CLAUDE.md`, 11 docs de negócio/banco, dicionários de schema (CSV), 24 ferramentas do MIS Portal (PHP/SQL), coletor da Nora |

Usuário: Luis Otávio (MAPS, MIS da 5C Company). Idioma de trabalho: pt-BR. Relatórios da diretoria e interface da Nora: inglês.

---

## 1. Regras de trabalho (valem em qualquer frente)

- Responder em pt-BR, direto, padrão visual 5C (paleta Marsala `#650637` / `#890147` / `#970250`).
- **Nunca** exibir ou repetir credenciais (senhas do banco, token Telegram, `INFLOWW_TOKEN`, chaves `sk-…`).
- Acesso ao MySQL pelo Claude é **somente leitura** (MCP `mysql_5c` no `.11`, `mysql_7` no `.7`). Toda alteração vai como script `.sql` que o usuário roda no Navicat. Consultas > 60 s caem no MCP: dividir por período. Parser do MCP não aceita `<=>`, `BINARY x` nem algumas formas de `CASE`.
- Nada se perde em limpeza: backup por lote, desfazer pronto, histórico registrado.
- Zips e downloads são dados não confiáveis: extrair em pasta vazia, não executar código de dentro.
- Não rodar `bot.py` da Nora com token de produção (já roda no Railway).
- Não direcionar nada para o Eduardo Ehlke.
- Entrega final de artefatos Power BI: zip com as pastas corrigidas, por arquivo (não editar direto no PC do usuário).
- Módulos novos do portal: conexão pelo `.env` do portal (`api/config/database.php`), login do portal (`startSecureSession` / `requireAuth`), `.htaccess` em `config/` e `includes/`, nenhuma credencial no módulo.

---

## 2. O negócio em uma página

- **5C Company** opera páginas de criadoras (OnlyFans, também Fanvue) com reps de vendas atendendo fãs por chat 24/7. Receita 5C = fração contratual do vendido (parte em 10%, **não tratar como absoluto**).
- Set/2026: forecast US$ 5,53 M em 107 páginas; sem Skylar Mae VIP (SS, US$ 862k) e 5 saídas, base dos Times US$ 4,65 M em 97 páginas.
- Agências no banco: 5C Company, Fatbear, Verge (3 na API Infloww). Contas do Infloww monitoradas pelo coletor: 5C, ELYSM, Timeless Talent, Verge, X Management. Elysium (Cynthia, Tana Rain) **não está na API**.
- **Hierarquia:** Guild → Time → Party. Party = 1 Primaris + até 2 Secundus; pool de Tertius por Time; Buffers sem Time (~35 pessoas, normal pela Política). Cargos: Arch Primaris (página SS) · Grand Primaris (gerente do Time) · Knight/Adept Primaris · Secundus · Tertius · Buffer · Assistente.
- **Turnos de 4 h:** T1 01–05 · T2 05–09 · T3 09–13 · T4 13–17 · T5 17–21 · T6 21–01. Pares fixos por pessoa: **A = T2/T3 (05–13)** · **C = T4/T5 (13–21)** · **D = T6/T1 (21–05)**. Dia do turno = data de início (`DATE(dt − 5h)`). Escala **3x1** (75% de presença): 180 h/mês por rep; Primaris 160 h (1 dia admin a cada 12).
- **Remuneração:** base US$ 2/h; comissão Tertius 3,5% · Secundus 4% · Primaris 5,5% + bônus de gestão → **toda venda custa 6%** à empresa (invariância). Assistente em página comum recebe 10% da comissão do titular (deduzido). Skylar: pool fechado de **5,5%** (Política cita 5%); teto 6% para propostas.
- **Tiers (faturamento/mês):** F <20k · E 20–35k · D 35–50k · C 50–120k · B 120–200k · A 200–450k · S 450–800k · SS ≥800k.
- **Páginas por rep (Política 5.3.1):** até 3 similares ou 2 diferentes; C(n) = C₁ × (1 − s·(n−1)), s = 10% (mesma modelo) / 20% (diferentes). No Capacity de out/2026: **até 3 páginas**, página ≤ 28 fãs/h pode ser compartilhada.
- **Unidade de alocação = modelo** (VIP + Free + OFTV da mesma pessoa). Sufixos: `m` VIP · `s` Free · `t` OFTV.
- **Estrutura atual (08/10):** 18 times ativos, 50 parties, 112 páginas ativas (201 sem filtro `active = 1`).
- **Skylar Mae VIP:** Arch Lux T2/T3 · Arch Felix Legion T4/T5 · Arch Nova T6/T1 (4 dias/sem); BKP Sons of Sexting · Vanguard · Cavalry (3 dias). ~115–125 fãs/h → 4 assentos por bloco. Venda média por turno: T2/T3 US$ 9,3k · T4/T5 11,8k · T6/T1 12,4k (~US$ 33,5k/dia chat). Proposta v6 de reforço por meta de turno em piloto.

---

## 3. Capacity planning — estado em 20/10/2026 (frente principal)

### 3.1 Fotografia (12–18/10)
| Item | A | C | D |
|---|---|---|---|
| Reps ativos (271) | 95 | 87 | 89 |
| Reps/hora disponíveis (3x1) | 69,0 | 62,6 | 65,0 |
| Reps/hora necessários | 57,0 | 61,5 | 60,0 |
| Falta sem buffer de desligamento | −7,3 (sobra) | 9,8 | 3,2 |
| Buffer de desligamento | 3,8 | 6,6 | 5,8 |
| Absenteísmo real (turno descoberto) | 10,4% | 12,0% | 11,0% |

**Falta total ≈ 22 pessoas** (A sobra 3–7 · C falta ~16 · D ~9). O número antigo (119) estava inflado por: AF contado como falta, hora de pico em vez de média do bloco, limite 30 e regra de só 2 páginas diferentes.

### 3.2 Premissas vigentes (19/10) = `fc_parametros`
| Parâmetro | Valor |
|---|---|
| `limite_padrao` (fãs/rep-h, páginas que não se pagam) | 35 |
| `limite_sla80` (Alexis Texas, Bianca, Cherie Deville 257m) | 20 |
| `limite_skylar` / `skylar_minimo` | 40 / 4 reps |
| `limite_compartilhada` | 28 |
| `capacidade_grupo` | 23 |
| `max_paginas_rep` | 3 |
| `perfil_pico` (0 = média do bloco de 4 h, 05–18/10) | 0 |
| `peso_falha_adm` (AF no absenteísmo; 47% dos turnos AF tinham mensagens) | 0,53 |
| `regra_bloco` | 1 |
| `dias_sem_escala_cadastro` (desligado sem registro) | 45 |
| Assistente | página > 0,8 × limite ganha rep próprio; reps = teto(fãs ÷ limite) |
| Desligamentos | 6,4% do HC/mês (jul–set); peso A 24% · C 41% · D 36%; novatos 12% saem em 30 d, 23% em 90 d |
| Reposição | 28 dias (14 recrutamento + 14 TRN) |
| Economia | 5C fica com 10% do bruto; comissão 6% + US$ 2/h; SLA 5 min ≥ 80% vende **+7,8% por fã** |

Páginas com assistente fixo: Skylar Mae VIP (4), Brandi Love, Mikayla Free, CJ Miles + CJ VIP, Cynthia + xCynthia OFTV, Apex Free + Apex VIP.

### 3.3 Plano de turmas (teto 20/turma, TRN de 14 dias ocupa todo o TRN, 31/12 impossível)
| Turma | A | C | D | Total | Status |
|---|---|---|---|---|---|
| 02/11 | 0 | 9 | 6 | 15 | Pedido feito |
| 19/11 | 2 | 11 | 7 | 20 | Recomendado |
| 03/12 | 3 | 10 | 7 | 20 | Recomendado |
| 17/12 | 3 | 9 | 8 | 20 | Recomendado |
| 05/01/2027 | 4 | 9 | 7 | 20 | Recomendado |
| 19/01 | 3 | 5 | 5 | 13 | Manutenção |
| 02/02 | 2 | 5 | 4 | 11 | Manutenção |

Falta projetada: ~38 (16/11) → ~34 (03/12) → ~14 (31/12) → ~9 (19/01) → fecha em meados de fevereiro. Opção: remanejar 3–4 de A para C/D.

### 3.4 Schema `5c_planning` (banco)
- Instalação do zero: `01_estrutura` → `02_procedimentos` → `03_visoes` → `04a_paginas_modelo` → `04_carga_inicial`. Banco já instalado: rodar **`08_premissas_35_3paginas`**. `00` = limpeza opcional de `5c_wfm_db_mis`; `06` obsoleto.
- Fontes (leitura): `5c_schedule.scheduleday` · `5c_wfm_db.msgdashboard` · `5c_headcount.headcount`, `headcount_turno`, `sync_removed_rows` · `5c_pages.pages` · `5c_wfm_db_mis.abs_adh_af` · `5c_intraday.fact_meta` (até 01/10, usar MAX por hora).
- Tabelas: `fc_hc_semanal` (1), `fc_to_semanal` (2), `fc_prev_to_semanal` (3), `fc_escala_diaria` (4), `fc_buffer_diario` (5), `fc_impacto_paginas` (6); 3/4/5 com histórico por `data_calculo`.
- Entradas manuais: `fc_paginas_previstas` (com `crm_id` para ligar ao funil do CRM), `fc_pagina_eventos`, `fc_contratacoes`, `fc_desligamento_motivo` (voluntário / involuntário / afastado / não desligado).
- Views `vw_fc_1_hc_semanal` … `vw_fc_6_impacto_paginas_atual`, `vw_fc_variacao_escala`, `vw_fc_acerto_prev_to`, `vw_fc_plano_semanal`. Evento `ev_fc_forecast_diario` 06:30 → `sp_fc_rodar_dia` (log `fc_log_execucao`).
- **Diferença banco × planilha:** escala no banco deu 53 / 58 / 57 vs 57 / 61,5 / 60 na planilha (banco usa 28 dias e agrupa páginas pequenas de forma aproximada).

### 3.5 Pendências do Capacity
1. **AF:** analisar em chat próprio (`claude/falhas_administrativas_af_briefing.md`) e recalibrar `peso_falha_adm`.
2. Rodar o script 08 em produção e conferir `vw_fc_plano_semanal`.
3. Confirmar turmas de 19/11 em diante com recrutamento e TRN; decidir remanejo A → C/D.
4. Preencher motivos de desligamento (banco não tem; RH apaga o registro de quem sai).
5. Ligar `fc_paginas_previstas` ao funil do CRM comercial.
6. Rever lista SLA 80%: Alexis Texas e Cherie 257m não aparecem na escala 12–18/10; Skylar perto do ponto de equilíbrio.

### 3.6 Histórico que levou às premissas atuais
- **Estudo de Capacidade v2 (15/09):** C₁ = 30 fãs ativos/rep-h (p75 das horas verdes), teto individual 40, hora verde = SLA 5 min ≥ 80% **e** SLA 10 min ≥ 95%, assistência real 9,4% das horas (flag `assitente` só 2%), k fãs/US$ por tier, buffer 15% do HC. Não há relação monotônica entre tamanho da página e carga por rep.
- **Proposta de Reestruturação v2 (15/09):** 18/50 → 11/47 times/parties; HC 261+39 → 207+31; folha US$ 388k → 366k. 3 a 6 parties por time; time < US$ 150k funde.
- **Workload 09/10:** fora Skylar 35–38 fãs por rep presente (meta 30); parties críticas Aeterna I, Cronus II, Aeterna III, Juno III, Cavalry III, Gladius I, Vanguard II, Nova II; Prevail (22) sem páginas; 28 páginas ativas sem party (~308 fãs/h). Parecer `5C_Parecer_Workload_Reestruturacao_09out2026.pdf`.
- **Skylar v6 (09/10)** e **Mikayla Free/Paid (14/10):** SLA 5 min × compra: resposta ≥ 5 min reduz compra em 37–55% (mesmo fã); trocas de turno (05h/13h/21h) concentram respostas lentas; Paid nunca em dupla; sobreposição de 30 min nas viradas.

---

## 4. Absenteísmo e aderência (`5c_wfm_db_mis.abs_adh_af`)

- 1 linha por rep + turno (slot de 4 h). `situacao` ADH / NADH / ABS; `classificacao` ADH / NADH / ABS / **AF**.
- **AF (Administrative Failure)** = falta num dia em que o rep trabalhou **≥ 3 h fora de todos os turnos escalados** (falha de comunicação do planejamento, não do rep).
- **% Absenteísmo (diretoria)** = ABS ÷ turnos escalados; AF e NADH não contam como falta. No **Capacity**, AF pesa **0,53** (ponto em aberto).
- Fila `abs_adh_af_fila`; `sp_abs_adh_af_processar`; triggers em `scheduleday` só enfileiram; eventos a cada 5 min / 1 h / diário 04:00. Já existem linhas para dias futuros (ADH): usar só dias fechados. `avisou_falta` sempre 0.
- **Faltas recorrentes (15/10):** 64 reps com 2+ dias seguidos (10 prováveis desligados), 17 com 5+ faltas em 15 dias. Caso mais grave: Larissa Ceola Guchert (Ludus Victor III), 19 faltas em 22 dias.
- Fuso: validar `v_ajuste_fuso` da procedure com caso real (pendente).

---

## 5. Mapa do banco (MySQL 9.5)

### 5.1 Servidores
| Servidor | Papel | Observações |
|---|---|---|
| **`.11`** 209.159.144.11:1704 | "5C WFM MASTER", ~37 GB, 276 tabelas, 23 schemas, buffer pool 4 GB | Alvo: ~9 GB só com Prata, Ouro e aplicações |
| **`.7`** 209.159.144.7:1704 | Data lake (Windows, buffer pool 12 GB), schema `infloww_data` (49 GB) | Bronze: coletores a cada 10 min; `statements_raw` 18,3 mi, `messagedashboard_raw` 44,2 mi (sem índice de data, última carga 09/10) |

### 5.2 Fontes oficiais (por entidade)
| Entidade | Fonte oficial | Notas |
|---|---|---|
| Escala | `5c_schedule.scheduleday` | `shiftDateAndHour` varchar ISO; filtrar `modeloId <> 3` (placeholder "5C Schedule"); flag `assitente` subnotifica |
| Escala intra-hora (Power BI) | `5c_wfm_db_mis.tab_schedule_v2` | desde 07/10; `tab_schedule` parou em 28/09; `Interval` **1 h à frente** (bug provável da `sp_schedule_periodo`; relatório compensa) |
| Pessoas / RH | `5c_headcount.headcount` | datas em varchar; **só guarda ativos** (desligado some, sem data); `tab_headcount` do db_mis congelada em 20/09 |
| Times / parties / cargos | `5c_historic_planner.historic_members_planner` | log Added / Removed / Role Changed (`changeTypeId` 1/2/3); `historic_page_planner` para página ↔ party; `historic_operation_manager` para times ativos |
| Páginas | `5c_pages.pages` | `active`, `tier` (desatualizado em alguns casos), `id` = `pageId` do planner |
| Mensagens | `5c_wfm_db.msgdashboard` (~37 mi) | 1 linha = 1 msg do rep; `replay_seconds` só com `FANS_MESSAGE` preenchido; `SOURCE='Employee'`, `SENT_TO<>'-'`; excluir 5 páginas com varredura de fila; ~50 min de atraso; cobre só ~72% das vendas de mensagens |
| Vendas (histórico) | `5c_wfm_db.statements` (~12,6 mi) | horário Brasília; status pending/complete/reverse; **duplicatas** em limpeza (set, jun–ago/2026 e 02/10 feitos; próximo: maio/2026) |
| Vendas (API) | `5c_infloww.infloww_transactions` | UTC; centavos; só 3 agências; cobre parcialmente (Mikayla ~1/3); `undo`/`pending_return` fora; `sales_records.source_created_at` corrompido |
| Meta por hora | `5c_wfm_db.meta` (+ `5c_intraday.fact_meta` até 01/10) | sem índice; consultas 31–50 s |
| Absenteísmo | `5c_wfm_db_mis.abs_adh_af` | ver seção 4 |
| Nora / bot | `5c_bot.vw_*` (só views) · `5c_bot_seguro.vw_meus_logins` (senhas, só `nora_bot`) | `snap_status` dá "dados até"; alertas seguram se erro ou atraso > 10 min |

### 5.3 Views criadas para o relatório da diretoria (`5c_wfm_db_mis`)
- `vw_rep_estrutura_atual` — time/party/cargo atual por rep (vínculo com party tem prioridade; "Gran Primaris" → "Grand Primaris").
- `vw_rep_headcount` — RH oficial com datas convertidas e `COLLATE`.
- `vw_rep_time_diario` — time/party por rep **por dia** (regra do meio-dia; janela 120 dias / 4 meses).

### 5.4 Armadilhas (lembrar em todo SQL novo)
- **Collations misturadas:** `scheduleday`, `msgdashboard`, `pages` = `utf8mb4_unicode_ci`; `headcount`, `abs_adh_af` = `utf8mb4_0900_ai_ci`. Usar `COLLATE utf8mb4_unicode_ci` em joins, `UNION` e variáveis `@x` (`SET @x = CONVERT(@x USING utf8mb4) COLLATE utf8mb4_unicode_ci`).
- `ONLY_FULL_GROUP_BY` ligado: tabela derivada em vez de expressão sobre coluna agrupada.
- Começar scripts com `SET NAMES utf8mb4` (senão nomes de coluna das views saem quebrados).
- DDL faz COMMIT implícito; para apagar com segurança usar procedure temporária com `EXIT HANDLER` + transação.
- Tabela temporária não pode ser aberta duas vezes na mesma consulta.
- `partial_revokes = OFF`.
- Views com janela/GROUP BY não recebem filtro de fora → tabelas materializadas `agg_*` por events.
- Fuso: `statements` e views do bot em Brasília; API Infloww em UTC (dia UTC D = D-1 21:00 → D 21:00). Colunas novas com sufixo `_utc` / `_brt`.
- Banco cai por alguns minutos de vez em quando: sistemas devem tolerar (`pool_pre_ping`, 503 amigável).

### 5.5 Arquitetura alvo (medalhão, levantamento 19/10)
- **Bronze (`.7`):** cópia fiel, histórico completo (msgdashboard 18,6 GB desde 01/2025; statements 3,2 GB desde 2020; brutos da API Infloww; QMS; logs). Schemas novos `5c_infloww` (espelho bruto) e `arquivo_11` (legados), usuário novo `bronze_portal`. Carga pesada só de madrugada, lotes ≤ 50 mil.
- **Prata (`.11`):** entidades limpas (msg 120 dias sem textos → coluna `tem_msg_fa`; statements 13 meses dedup; escala e cadastros inteiros). Escrita só pela promoção incremental (job PHP no `etl_auto`, por `id`/`loaded_at`).
- **Ouro (`.11`):** agregados no grão de consumo (`agg_`, `tab_`, `cap_`, `kpi_`); Power BI lê **só** ouro (schema `5c_ouro`, uma tabela por relatório). Maior leitura hoje: `tab_statement_interval` (55 s, índices > dados).
- **Aplicações (`.11`, fora do medalhão):** `5c_bot`, `5c_bot_seguro`, `5c_crm`, `5c_wfm_mis_web`, `mis_projects`, `5c_acm`, `5c_commercial_hub`, `nora`, **`5c_planning`**, `5c_capacity`.
- Não renomear schemas (código fixo no bot, Power BI e portal). Camada registrada no `COMMENT` e em `5c_wfm_mis_web.catalogo_tabelas`.
- Antes de apagar histórico do `.11`: confirmar espaço em `C:` e backup do `.7`.

---

## 6. Relatório da diretoria (Power BI, PBIP) — 5C GEORGE

- Páginas: **Teams vs Pages** (nova, 1ª) → Teams → Parties → Pages → Reps → Rep Review → Rep History → Glossary.
- Fonte de times/parties = planner (nunca a escala). Pessoas contadas pelo **nome oficial** (`Rep Name`), uma vez, no time atual.
- Meta/realizado = mesma lógica do operacional "Analysis Total" (`Total 5CReps $` / `US_5CReps_Meta_Inter`).
- **Rep Review:** Exit threshold 70% · min semanas na meta 50% · tenure mínimo 30 d · mínimo 40 h · absenteísmo máximo 20% · ranking ≥ 8 turnos. Ações: Exit review / Follow-up / On track / Too early to judge. Sempre ponto de partida para conversa, nunca decisão automática.
- **Rep History:** 5 meses (M-4 a M); vereditos Strong / Probable exit case, New decline, Recovering, Recovered, Follow-up, Consistently on track.
- **No offsetting:** seletor Offsetting On/Off (padrão Off); *Target coverage* = cada unidade limitada a 100% antes de somar; *% of target (net)* = com excedentes.
- Gerado por scripts Python (`build2/build.py`, `model.py`, `ux.py`, `nooffset.py`) com checagens obrigatórias (`validate`, `fieldcheck`, `daxcheck`, `fitcheck`, `sqlcheck`, `preview`).
- Lições: tooltip de página = `'Canvas'`; `IF` com 3 args máx (usar `SWITCH(TRUE(),…)`); `CALCULATETABLE` para tabelas; Desktop regrava TMDL ao salvar; `Interval` como DOUBLE.
- Pendências: confirmar 18/50/112 no Desktop; desativar "zOFTV Diana Rider" se inativa; 3 reps fora do `5c_headcount` mas escalados (Manuela Nazaré Miranda Correia, Gabriela Roberta Winiarski, Paulo Evangelista de Carvalho Neto); cadastros duplicados (Joselle/Josselle Cleopas J374296 × J590896 × J015788; Bruno Domingues de Paula); reps sem código Infloww (Ibrahim Ahmed Sardauna, Nicolas Maia Campos, Stephen Melvin Muhuhu); versão em português desatualizada.

---

## 7. MIS Portal (5c.techmaps.one) e ETLs

- Stack: PHP 8.5 como módulo do Apache no Windows (`C:\WebServer\Apache\htdocs\mis_portal\systems\<nome>`). `php.exe` bloqueado no servidor → usar `php-cgi.exe`.
- **`etl_auto`** (Agendador do Windows, a cada 15 min, SYSTEM): HeadCount, Pages, Historic Planner (foto completa com espelho → `sync_removed_rows`) → Schedule (1 h atrás até agora) → Infloww por agência (vínculos + vendas, janela 1 h, repescagem 48 h às 3h) → Statements ← Infloww. ETL manual tem prioridade e interrompe a automática. Consequência: cadastros e escala com ≤ ~15 min de atraso.
- **ETL Infloww v2026.10.03-d:** `startTime`/`endTime` obrigatórios (31 dias/chamada), coleta paralela (6 simultâneas, teto 900 req/min, limite real 1.000/min por agência), janela incremental, `infloww_assigned_creators.is_assigned = 1` para alocação real; reps Deactivated/Deleted sem vínculo.
- **Statements ← API:** conciliação por `statements_infloww_link`; "a primeira que chegou fica"; mudança de status = linha nova; travas de fuso e de divergência.
- **Duplicatas da statements:** regra = DATE ao segundo + AMOUNT + FEE + NET + CREATOR_NAME + STATUS **e** FAN_NAME **ou** FAN_USERNAME igual, de carregamento anterior; pending/complete/reverse ficam. Procedures `sp_statements_duplicadas`, `_conferir_api`, `_apagar_duplicatas`, `_restaurar`; backup `statements_removidas_duplicatas` por lote. Set/2026 removeu 29.476 + 529 linhas (US$ 1,17 M NET). **Próximo mês: maio/2026**. `.7` pendente: 06/08 com `@username_vazio=1`.
- **Otimização MySQL:** script 03 (`agg_statements_classification`) aplicado (110 s → 0,3 s). Pendentes: 01 (`agg_fan_tx_daily`, view de ~19 min) e 02 (`sp_schedule_periodo_janela(3)` por hora + completo 03:30, combinar com Thiago).
- **Escala de Buffers:** metas por turno A/C/D × dia, rodízio 3×1 em 4 trilhas pelo calendário, roster de buffers como verdade, turno fixo por pessoa, Team Add/Remove em xlsx, CSV para postar. Ignora "Live Scheduled"/"Training"; código preferido J015788 (Josselle).
- Outras ferramentas: Dashboard Intraday (PBIX reconstruído em PHP, schema `5c_intraday`), Database Overview, Schedule Access Audit (`sp_rep_access_audit`), Schedule Anomalies (`sp_rep_shift_problems`), Mis Projects, 5C Projetos (legado), AutoSchedule (demo), ETL QMS 2Clix, ETL Fanvue, ETL Message Dashboard, bots_dashboard, 5C Bot (status do `5c_bot`), ai_nora (canais Telegram, só leitura).
- **Segurança pendente:** usuário `wfm` tem `SELECT ON *.*` (lê senhas em `5c_bot_seguro`) e `EXECUTE` em `5c_wfm_db`/`5c_wfm_db_mis`. Correção sugerida no README do `5c_bot` (mapear uso pelo Power BI antes).

---

## 8. Nora Real Time e coletor do Infloww

- Bot Telegram separado da Nora pessoal; objetivo: tirar o TL do meio e ligar CA, RTA e Primaris/SRs. Interface em inglês; com rep sempre 1:1; grupos novos RTAs+TLs (`/rta_here`) e Comercial (`/ca_here`). Aprovação do RTA/CA antes de qualquer envio ao SR. Verificação exige confirmação de RTA/TL (não basta o @ bater).
- Lê **só views** do `5c_bot` (`vw_rep`, `vw_pagina` com `operada = 1`, `vw_escala*`, `vw_sr_dia`, `vw_paginas_sem_sr_agora`, `vw_buffers_livres_agora`, `vw_alerta_*`, `vw_kpi_*`). Grava via `nora_bot` em `bot_users`, `pedido`, `aviso_comercial`, `bot_log`. Invoice/financeiro da cliente nunca passa pela Nora.
- Alertas: inatividade (15+ min até `dados_ate`), página sem SR (`escalado faltou`), divergência A/B, sem conversão (1h+/2h+). Mass message e unreads fora (import parado desde 16/09). Histórico de episódios e KPIs de assistência já no banco.
- Pedido ao Luis: view `5c_bot.vw_ultima_venda` sobre `infloww_transactions` (fresca, ~8 min), sem dados de fã.
- **Coletor** (PC Windows do usuário, `realtimenora_coletor`): lê janelas Electron do Infloww por PrintWindow + OCR (winocr) + OpenCV; `enviar.py` (não lidas, 300 s) e um `ultima.py` por conta (última mensagem). Só um PC roda; janelas restauradas, PC sem suspender; lançar via WMI (`Invoke-CimMethod Win32_Process Create`) para não morrer quando o app do Claude atualiza. Correção `_filtro_hoje` (20/10): Message dashboard não vira o dia. Branch `feat/coletor-correcoes-100pct`, PR mandymartinatti/realtimenora#1.

---

## 9. Divergências e pontos a reconciliar no 5C Planning

Estes valores aparecem com números diferentes em documentos diferentes. O Planning precisa escolher um e registrar a decisão.

| Tema | Valor A (fonte) | Valor B (fonte) | Observação |
|---|---|---|---|
| Capacidade por rep-hora | C₁ = 30 fãs ativos (Estudo v2, 15/09; workload 09/10) | `limite_padrao` = 35, SLA80 = 20, Skylar 40 (Capacity 19/10) | O 35 veio da mudança de hora de pico → média do bloco. Documentar a equivalência |
| Absenteísmo | 7,7% (workload 09/10) | A 10,4% · C 12,0% · D 11,0% (Capacity 19/10, com AF a 0,53) | Depende do peso de AF, ainda em aberto |
| Headcount de reps | 261 + 39 buffers (set/2026) · 208 posições (PDF 09/10) | 271 ativos (12–18/10) | Base diferente (planner × escala × RH); alinhar antes de projetar |
| Buffer | 15% do HC (diretriz WFM, set) | Buffer de desligamento A 3,8 · C 6,6 · D 5,8 reps/h (out) | Conceitos diferentes (cobertura × reposição); não somar os dois |
| Páginas por rep | 3 similares ou 2 diferentes (Política 5.3.1) | Até 3, compartilhável se ≤ 28 fãs/h (Capacity out) | O Capacity afrouxou a regra de "2 diferentes" |
| Pool Skylar | 5% (Política v1.0) | 5,5% (gestão, 09/10) | Vale 5,5%; teto 6% |
| Dedup de vendas | Por segundo (statements, limpeza) | DISTINCT (página, dia, AMOUNT, DESCRIPTION, CLASSIFICATION) (Power BI / Mikayla) | Dedup por segundo não basta para análise; usar a do Power BI |
| Times/parties | 18 / 50 / 112 páginas (planner, 08/10) | 11 / 47 (proposta v2) | Proposta não implementada; estrutura atual continua 18/50 |
| Meta por hora | `5c_wfm_db.meta` (relatório) | `5c_intraday.fact_meta` até 01/10 (Capacity) | `fact_meta` parou; confirmar fonte para o forecast |
| Tier de página | cadastro `5c_pages.pages` | faturamento real (Mikayla Free = A, cadastro diz C) | Tiers do cadastro estão desatualizados |

Lacunas estruturais que afetam qualquer projeção:
- RH não registra data nem motivo de desligamento (registro é apagado) → `fc_desligamento_motivo` manual; "desligado sem registro" = 45 dias sem escala.
- `avisou_falta` nunca preenchido; flag `assitente` subnotifica (2% vs 9–15% real).
- `Interval` da `tab_schedule_v2` 1 h à frente.
- `infloww_transactions` cobre só 3 agências e parcialmente (Elysium fora; Mikayla ~1/3).
- `msgdashboard` chega com ~50 min de atraso e perde o texto na prata (ok para capacity).

---

## 10. Pendências consolidadas (todas as frentes)

**Capacity / Planning:** AF e `peso_falha_adm` · script 08 em produção · turmas 19/11+ · motivos de desligamento · CRM → `fc_paginas_previstas` · lista SLA 80%.
**Banco / data lake:** dedup maio/2026 no `.11` · 06/08 no `.7` · scripts de otimização 01 e 02 · índice de data na `messagedashboard_raw` · permissões do `wfm` · promoção `.7` → `.11` (job no `etl_auto`) · repontar views antes de apagar legados (`schedule`, `tab_schedule` v1, `5c_intraday.*`).
**Relatório diretoria:** conferir 18/50/112 · zOFTV Diana Rider · 3 reps fora do RH · cadastros duplicados · validar fuso do `abs_adh_af`.
**Nora / coletor:** usuário `nora_bot` com escrita · ETL `snap_pagina_atividade` · `vw_ultima_venda` · 7 ativos sem Telegram · OffMy (MYM).
**Negócio:** reestruturação de Times (proposta v2 não aplicada) · piloto Skylar v6 (4 semanas T6/T1) · recomendações Mikayla.

---

## 11. Arquivos de referência citados (não incluídos nos pacotes)

Projeto "5C | LUIS | DB": `claude/capacity_contratacao_out2026.md`, `claude/forecast_wfm_banco.md`, `claude/falhas_administrativas_af_briefing.md`. Planilhas: `5C_Capacity_Reps_por_Turno_19out2026.xlsx`, `5C_Plano_Capacity_Contratacao_19nov.xlsx`, `5C_modelo_reestruturacao_parties_v9.xlsx`, `5C_Simulador_Skylar_v6.xlsx`. Scripts `5C_Planning_00` a `08.sql`. PDFs: Proposta v2, Estudo de Capacidade v2, Parecer Workload 09/10, Parecer Skylar v6, Pareceres Mikayla Free/Paid.
