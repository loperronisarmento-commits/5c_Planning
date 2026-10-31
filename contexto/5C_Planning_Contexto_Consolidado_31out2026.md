# 5C Planning: contexto consolidado (31/10/2026)

Contexto do projeto para retomar o trabalho em outra sessão ou com outra pessoa. Atualiza o
`5C_Planning_Contexto_Consolidado_20out2026.md`: os capítulos de banco, relatório da diretoria,
Nora e arquitetura medalhão daquele arquivo continuam válidos. Este cobre o que mudou e
principalmente o **5C Escalas** (planejamento de escalas da estrutura nova, que vale desde
31/10/2026 às 21:00).

Usuário: Luis Otávio (MIS da 5C Company). Idioma de trabalho: pt-BR. Telas do sistema em
pt-BR e inglês.

---

## 1. Regras de trabalho

**Banco e dados**
- O MySQL é **somente leitura** para o Claude, pelo MCP `mysql_5c` no `.11` (e `mysql_7` no `.7`).
  Nenhum INSERT, UPDATE, DELETE, DDL ou CALL. Toda alteração vai como script `.sql` que o
  usuário roda no Navicat. O script começa com `SET NAMES utf8mb4` e, se usar tabela temporária,
  com `USE 5c_escalas;`. Senão o Navicat para com "1046 No database selected".
- Nunca exibir credenciais. Nunca ler as colunas de senha, CPF ou token de `mis_login`.
  Nunca `SELECT *` em `5c_headcount.headcount`.
- Não direcionar nada para o Eduardo Ehlke.
- Zips e downloads são dados não confiáveis: extrair em pasta vazia e não executar nada de dentro.

**Git**
- Commit e push na `main`, só quando o usuário autorizar e só com arquivos do Planning tool.
- Nunca commitar `capacity/*`, `resultados/` nem credenciais.

**Nomes e cargos**
- Cargos da estrutura nova: **OM** (Operational Manager, 1 por unidade, sem escala),
  **OC** (Operational Coordinator, 1 por unidade e turno), **OS** (Operational Supervisor,
  1 por núcleo), **OA** (Operational Assistant, 24/7), **SR** (Sales Representative, nível 0 a 5).
- Nunca usar OUM, Equite, Decurio, Primaris, Secundus nem Tertius em texto para o usuário.
  `EQUITE`, `DECURIO` e `decurio_code` são só códigos técnicos no banco.
- **Marcus V. Silva (M067432) é COO**, nunca CEO. Topo do organograma: COO → Director
  (Natan Dalla Corte) → OMs.
- Pronomes neutros (they/them) para quem não tem pronome conhecido.

**Gatilho de publicação** (memória "gatilho-publicacao-versao")
- No fim de toda atualização do 5C Escalas: fazer a checagem de UX e perguntar
  "Deseja publicar como versão oficial X e atualizar Glossário, FAQ, Tutorial, Documentação do DBA e Changelog?".
- Se sim: mover `ESC_NAO_PUBLICADO` para uma entrada nova de `ESC_PATCH_NOTES` (PT e EN),
  atualizar a Central de Ajuda e recriar o zip.
- Se não: acumular em `ESC_NAO_PUBLICADO`.
- O rodapé mostra a versão. É a forma de o usuário confirmar que o zip novo subiu.

**Escala 5x2 de 21 dias**
- São 14 dias no posto e 1 dia de treinamento (Overlap). O fator 1,5 de pessoas por posto está
  certo; não trocar para 1,4.

---

## 2. A estrutura nova (vale desde 31/10/2026 21:00)

| Item | Como é |
|---|---|
| Unidades | **U1 = Victoria Aires Cunico (V034940)**, **U2 = João Paulo Gonçalves Martins (J070909)**, **U3 = Gabriel Henrique Keller (G105318)**. A ES_35 trocou U1↔U3 em 30/10 |
| Turnos | A = T2/T3 (05–13), C = T4/T5 (13–21), D = T6/T1 (21–05). O turno D de um dia começa às 21:00 do dia anterior |
| Núcleos | 27 núcleos = 3 por unidade e turno, cada um com um OS. A cada dia 2 dos 3 núcleos do turno trabalham |
| Ciclo | 5x2 de 21 dias, até 5 dias seguidos, folga de emenda |
| Fatia por nível | SR 0 = 0,4 · SR 1 = 0,6 · SR 2 = 0,8 · SR 3 a 5 = 1,0 (ou `fatia_regular` do cadastro). Assistente: só SR até o nível 2 e com "pode ser assistente" |
| Posto | Posto do Capacity com núcleo dono (vaga DONO = owners) e núcleo de cobertura (vaga COBERTURA = backups). A equipe da vaga é preferência, não trava |
| Virada | `v4_virada = 2026-10-31 21:00`. Antes disso vale a escala antiga já postada no site |
| Dias de transição | `v4_dia_transicao = 2026-10-31,2026-11-01`. Nesses dias o gerador mantém quem já estava escalado em cada turno na escala antiga |
| Sufixos no CSV da página 5C Schedule | -2 buffer · -4 trainee (New Hire) · -5 Overlap · -6 OA · -7 OC · -8 OS |
| Liderança | OC e OS em 5x2 no ciclo de 21 dias, num turno só, com o dia de Overlap em que os 3 do grupo estão no turno. OA em semana fixa, 1 por turno todo dia. OM sem escala |

---

## 3. 5C Escalas (módulo do MIS Portal)

### 3.1 Onde está
- Código: `D:\5C Planning\5c_Planning\escalas\sistema\5c_escalas\` (instalado em
  `mis_portal/systems/5c_escalas/`, rota `/escalas`).
- Zip para subir: `escalas\sistema\5c_escalas.zip`, com 98 arquivos. É recriado pelo script
  `xlsx/zip.py` do scratchpad da sessão; a lista `novos` desse script precisa incluir todo arquivo
  novo (ex.: `lib/equipes.php`, `lib/estrutura.php`).
- Scripts SQL: `escalas\sql\ES_*.sql`. Os geradores Python offline (`gerar_es10.py`, `gerar_es17.py`)
  ficam em `escalas\sql\gerador\`.
- Documentos: `escalas\ES_00_especificacao.md`, `Politica_paginas_merito_2026-10-30.md`,
  `UX_revisao_2026-10-29.md`, `Proposta_melhorias_planejamento_2026-10-30.md` e o README do módulo.
- Grava só no schema `5c_escalas`. Únicas exceções, sempre por instrução da lista fechada em
  `escCapacityGravar` (`lib/saida.php`):
  - `5c_planning.fc_pagina_carteira` (saída de página, página nova, carteira e correção de vigência);
  - os CALLs `sp_fc_postos` e `sp_fc_recalcular_postos`.

### 3.2 Versão atual
- **Montada: 1.17.0** (31/10, commit `f7aa749`). O zip `escalas/sistema/5c_escalas.zip` já é a
  1.17.0 e leva também a 1.15.0 a 1.16.1, caso elas não tenham subido. Depois de subir, o rodapé
  deve mostrar 1.17.0.
- A 1.14.1 era a última confirmada no ar.

### 3.3 Mapa do código (lib/)
| Arquivo | Papel |
|---|---|
| `motor.php` | `escMotorGerar($seg, $modo)`: gera ou recalcula a semana. Na estrutura nova, nesta ordem: `escEstruturaAtualizar()` → `escEquipesCompletar()` → `escV4Carregar()` → bloqueios (New Hires, saída sem página) → `escMotorAlocarV4()` → liderança e trainees → grava o plano |
| `motor_v4.php` | Alocação da estrutura nova: equipe da vaga, melhores SRs nos tiers SS/A/B, remanejamento por faturamento, transição, buffers, Overlap, emenda. Prioridade do posto = faturamento; posto só de OFTV sem faturamento recebe −1 (prioridade mínima) |
| `estrutura.php` | **Antes de gerar** (no máximo a cada 30 min): inclui no Capacity as páginas ativas com movimento (escala ou venda em 14 dias); recalcula o Capacity de hoje; sincroniza os postos com `fc_postos`; página sem posto num turno vira apoio no posto da mesma modelo. Tem trava: se o Capacity vier com menos de 90% das páginas, nada é trocado |
| `equipes.php` | **Nenhum posto sem equipe**: completa a vaga vazia pelo núcleo, depois por outro núcleo do turno, depois nível 0–2 acima da fatia e, por último, o menos carregado |
| `saida.php` | Saída de página (com página entrando no lugar), escrita no Capacity e foto do Capacity. O posto refeito herda a vigência do original |
| `treinamento.php` | Turmas de New Hires: lê o e-mail, sugere páginas (lacuna, emprestada ou outra), cobre a página total ou parcialmente, gera as linhas -4 e bloqueia até o fim da turma |
| `merito.php` | Mérito do SR = Desempenho × SLA × Presença ÷ (1 + pena); com `merito_abs_ativo` = 0 a presença vale 1 e não há teto do crítico. `escAbsRealizado()`: absenteísmo sem a falha administrativa, pela `abs_adh_af`; se não der para ler, pelo 5C Schedule por horário. `escMeritoAtingimentoPaginas()`: atingimento de cada rep em cada página por período (PDF de metas) |
| `politica.php` | Política de páginas por mérito (troca de owner, troca de unidade). Na troca de unidade as pessoas ficam: a página vai para o time do destino |
| `lideranca.php`, `roster.php` | Escala e roster da liderança |
| `funil_sugestao.php` | Funil: destinos indicados e arquétipos (`escFunilArquetipos`, `escFunilVolume`) |
| `solicitacao.php` | Solicitações do SR (assistente, dupla, troca de turno, núcleo ou unidade) |
| `versao.php`, `versao_en.php`, `ajuda.php` | Changelog, pendente de publicação, glossário, FAQ e tutorial |

### 3.4 Telas principais
- **Painel; Gerar escala:** gerar ou recalcular, com a mensagem do que o Capacity e as equipes fizeram.
- **Grade:**
  - "Baixar PDF das páginas" (escala) e "Baixar PDF das metas" (`?ver=metas`): o histórico de
    atingimento de cada pessoa escalada na página nas 4 semanas anteriores.
  - Postar no site: um cartão por página com o CSV, a lista "Só apagar" e o roteiro da semana da virada.
  - Postagem da semana da virada: só a partir de 31/10 21:00, com Delete Range de 01/11, apagar à mão em 31/10 só o T6 e Date Schedule 31/10.
- **Escala da liderança:** botão "Baixar PDF", em A4 paisagem com papel timbrado, um turno por folha.
- **Organograma:** "Baixar PDF" em A3 numa folha só, com a data da estrutura. Os SRs vêm de
  `esc_colaborador_config` e as páginas dos postos; avisa das trocas de unidade com data futura.
- **Horas extras:** por bloco de 4 h (T1 a T6), no máximo 12 h seguidas e 52 h por semana.
- **Treinamento; Saídas de página; Trocas de página; Owners e backups; Solicitações; Roster da liderança; Funil.**
- **SRs (mérito):** botão "Recalcular mérito agora".
- **Ajuda:** Mérito, Regras, FAQ, Glossário, Tutorial, Documentação do DBA e Changelog.

### 3.5 Parâmetros (`esc_parametro`, grupos v4 e merito)
`v4_virada`, `v4_dia_transicao`, `v4_remanejar_transicao` (1), `v4_nivel_max_assistente` (2),
`v4_estrutura_auto` (1), `v4_estrutura_minutos` (30), `v4_estrutura_movimento_dias` (14),
`v4_estrutura_minimo_paginas` (0,9), `v4_overlap_pagina` (5C Schedule), `v4_receita_dias`,
`v4_tiers_melhores_srs` (SS,A,B), `v4_historico_dias` (56), `v4_horas_semana_max`,
`v4_over_capacity_max`, `merito_abs_ativo` (1; 0 tira o absenteísmo da nota, ES_53), `merito_abs_tolerancia` (0,06), `merito_abs_critico` (0,15),
`merito_abs_piso` (0,5), `merito_abs_teto_critico` (0,8), `merito_janela_dias` (28),
`merito_cache_horas` (20), `org_coo`, `org_diretor`.

---

## 4. Banco: o que importa para o 5C Escalas

**`5c_escalas`**
- `esc_posto`: versão, unidade, carteira, turno, núcleo dono e de cobertura, vigência.
- `esc_posto_pagina`: página, `page_id` (código do site), fans/h, apoio.
- `esc_vaga_equipe`: vaga DONO/COBERTURA, rep, fatia, vigência, origem, motivo.
- `esc_colaborador_config` (pessoa → unidade, turno, núcleo, nível), `esc_nucleo`, `esc_lideranca`.
- `esc_plano` e `esc_plano_alocacao`: versões da semana e a grade.
- Saídas: `esc_saida_pagina`, `esc_saida_checagem`, `esc_saida_sem_pagina`.
- `esc_treinamento_*`, `esc_hora_extra`, `esc_sr_merito` (cache), `esc_parametro`, `esc_log`.

**`5c_planning` (Capacity, de outra sessão)**
- `fc_pagina_carteira`: página → carteira, modelo, vigência, `fas_h_estimado`.
- `fc_carteira`: 6 ativas, U1C1 a U3C2.
- `fc_pagina_carga`, `fc_postos`, `fc_postos_pagina`, `fc_necessidade_estrutura` e `fc_capacity_versao*`.
- `fc_pagina_status_diario`: foto diária de páginas ativas.
- Procedures: `sp_fc_postos(data)`, `sp_fc_recalcular_postos(data, obs)` (postos + versão) e `sp_fc_capacity_versao`.

**Armadilhas aprendidas (30–31/10)**
1. **Início da estrutura no Capacity:** o `sp_fc_postos` usa como referência
   `GREATEST(data, MIN(vigente_desde) da carteira)` e só considera as páginas com vigência até essa referência.
   - Uma página gravada com data anterior a 01/11 tira todas as outras do cálculo. Foi o que aconteceu às 23:30: 28 postos em vez de ~150.
   - Página nova entra sempre a partir do início da estrutura.
   - Página que começa depois da referência (ex.: Andressa em 02/11) não entra no cálculo de hoje. O posto dela não pode ser fechado por isso.
2. **Foto de páginas ativas:** o Capacity ignora página inativa na `fc_pagina_status_diario`
   do dia. Página ativada no site depois da foto (ex.: Maddie, 412) fica fora até a próxima foto.
3. **Nome da página:** Capacity e site podem diferir em maiúsculas ("Mandi CAT" e "Mandi Cat",
   "Eden The Doll" e "Eden the Doll"). No PHP a comparação precisa ignorar caixa e espaços;
   sem `page_id`, a página fica sem CSV.
4. **Vigência:** vigência fechada por saída usa `vigente_ate = GREATEST(vigente_desde, ontem)`.
   Posto que nunca chegou a valer vai para 29/10~29/10, antes da virada, onde o motor não lê.
   A cópia de posto refeito precisa herdar `vigente_ate`.
5. **Absenteísmo oficial:** vem de `5c_wfm_db_mis.abs_adh_af`, `classificacao` ABS/NADH/ADH/AF,
   com AF_DOBRA e AF_FORA. O "faltou" cru do 5C Schedule conta cada página e a escala dobrada,
   e dava cerca do dobro (Iago: 22,9% contra 4,4% real).
6. **Collation:** `abs_adh_af.codigo` é `0900_ai_ci` e o resto é `unicode_ci`. Usar parâmetros
   ou `COLLATE` nos joins.
7. **Saída com erro já gravada:** uma saída de página com status ERRO pode ter gravado a parte da
   escala antes do erro (casos da 215m e da Skylar em 30/10).

---

## 5. Scripts ES (rodados pelo usuário até o ES_43; do ES_44 em diante, confirmar com o usuário)

| Script | O que faz |
|---|---|
| ES_01–03 | Schema 5c_escalas, carga inicial, permissão do usuário do portal |
| ES_04–10 | Estrutura nova: unidades, núcleos, carga de 27/10, transição, postos do Capacity de 28/10 |
| ES_11–13 | Overlap (dia de treinamento), faturamento e acúmulo, Overlap defasado |
| ES_14–16 | Horas extras, saída da Skylar Mae VIP, mérito/infrações/saídas |
| ES_17–18 | Equipes das vagas pelo histórico, virada em 31/10 21:00 |
| ES_19–21 | Escala da liderança, liderança no plano, quadro de 29/10 |
| ES_22–26 | 5x2 até 5 seguidos, controle de postagem, fim dos cadastros 3x1, trocas por turno, pedidos de ausência |
| ES_27–29 | Funil por núcleo, Modo Treino (`5c_escalas_treino`), roster da liderança de 30/10 |
| ES_30–34 | Solicitações do SR, correção do ES_25, Andressa Urach (02/11), política de páginas por mérito, pessoa da dupla |
| ES_35 | Renumera unidades (Victoria U1, João U2, Keller U3) no Escalas e no Capacity |
| ES_36–38 | Treinamento de New Hires; saída com página entrando; treinamento por lacuna e hora extra por bloco |
| ES_39 | Desfaz as saídas de Cynthia, Ms Red VIP e Free (aplicadas por engano) e fecha os postos refeitos duplicados |
| ES_40 | Desfaz a atualização de estrutura de 30/10 23:30 (159 postos reabertos, 28 fechados) |
| ES_41 | Tira a Cherie Deville 257t do Capacity e libera a trava |
| ES_42 | Código da página no site nos postos novos (Mandi CAT, Eden The Doll) |
| ES_43 | Reabre os postos da Andressa (02/11) e marca a Maddie ativa na foto de 30/10 |
| ES_44 | Correção com o mínimo de mudanças no que já foi postado (v23.1 e v19.1) |
| ES_45–46 | Folga juntos (casais) e alinhamento do ciclo dos 3 casais a partir de 09/11 |
| ES_47 | Indisponibilidade recorrente do Rhazzel (R025361), de 01/11/2026 a 01/02/2027 |
| ES_48 | Remanejamento dos SRs pelas preferências do Shift Preference Form (73% a 76% do Capacity) |
| ES_49 | Descarta a v19.1 da semana de 02/11, para o "Gerar" sair do zero |
| ES_50 | Folga juntos: Marcos (M137535) na U2 D núcleo 2 a partir de 09/11, junto do Rafael Garcia da Costa |
| ES_51 | Trocas de unidade do Equilíbrio a partir de 02/11: Mikayla U1 → U3, Nicole U2 → U1 |
| ES_52 | Equilíbrio de pessoas: 18 SRs da U2 para a U1 e a U3 a partir de 02/11 (só esta vez) |
| ES_53 | Parâmetro `merito_abs_ativo` |
| ES_54 | Privacy: equipe da Aline Novak (04/10–31/10) na Aline e na Andressa Urach, 6 SRs para a U2 |
| ES_98 / ES_99 | Desfaz a estrutura nova / o schema inteiro |

---

## 6. Histórico de versões (5C Escalas)

| Versão | Data | O que trouxe |
|---|---|---|
| 1.0–1.2 | 21–28/10 | Primeira versão; estrutura nova; postos do Capacity, Overlap e faturamento |
| 1.3–1.8 | 28–29/10 | Horas extras, saídas, mérito; virada 31/10 21:00; liderança; 5x2 até 5 seguidos; postagem pela grade |
| 1.9–1.12 | 30/10 | Revisão de UX (segurança, temas, celular, idioma EN, Central de Ajuda); organograma no visual do roster |
| 1.13.0 | 30/10 | Solicitações, roster da liderança, política por mérito, treinamento de New Hires, unidades renumeradas |
| 1.13.1–1.13.4 | 30/10 | Treinamento (mesma página em turnos, todas as páginas, realocação); postagem da virada; tela volta no cartão |
| 1.13.5 | 30/10 | Saída de página: posto refeito com a vigência do original (ES_39) |
| 1.13.6 | 30/10 | Absenteísmo sem falha administrativa no mérito e na Análise |
| 1.13.7 | 30/10 | Nenhum posto sem equipe |
| 1.14.0 | 30/10 | Capacity recalculado antes de gerar; páginas ativas incluídas sozinhas; OFTV sem faturamento com prioridade mínima |
| 1.14.1 | 31/10 | Correções da atualização de estrutura (ES_40–43), COO, PDF da escala da liderança e do organograma |
| 1.15.0 | 31/10 | Recalcular com o mínimo de mudanças, PDF da escala das páginas, transição sem turno dobrado (ES_44) |
| 1.16.0–1.16.1 | 31/10 | Folga juntos, Equilíbrio das unidades com divisão dos OS, regras de 31/10 do Capacity, correções do motor (ES_45–51) |
| 1.17.0 | 31/10 | PDF de metas, troca de unidade nos postos (pessoas ficam), `merito_abs_ativo` (ES_52–54) |

---

## 7. Estado em 31/10/2026 e pendências

**Para a 1.17.0 (31/10, noite)**
1. Rodar no Navicat ES_52, ES_53 e ES_54, nessa ordem.
2. Subir o zip 1.17.0 e conferir o rodapé.
3. Gerar escala › semana de 02/11 › Gerar; conferir Mikayla na U3, Nicole na U1 e a equipe da
   Privacy na Aline e na Andressa; publicar e postar; abrir o Organograma em 02/11.
4. Testar o "Baixar PDF das metas" na Grade (primeira vez com dados reais).

**Para a virada (de antes da 1.17.0; conferir o que já foi feito)**
1. Subir o zip atual. Ele tem a 1.14.1 e a correção acumulada dos dois turnos na transição.
2. Recalcular as semanas 26/10 e 02/11 e conferir:
   - a mensagem "Capacity recalculado antes de gerar: … iguais, … novos, … fechados";
   - Maddie, Andressa, Cynthia, Ms Red VIP e Free, e as páginas novas (Liam, Jessie, Sophie Sierra,
     Aria, Kas, Haven Free, Becky Recme, Silvia Saige VIP e Free, e as OFTV);
   - a Marina em 01/11 só no T2/T3;
   - a lista "Só apagar" com só as 5 inativas: Skylar Mae VIP (60), Huney Baked 215m (273),
     Huney Baked 215s (286), Maddie Cavill (405) e Louise (485).
3. No zip da semana 26/10, esperar 100 CSVs: 99 páginas mais o 5C Schedule.
4. Publicar e postar a semana 26/10 seguindo o roteiro da virada; depois a 02/11.
5. Em SRs, clicar em "Recalcular mérito agora". Se aparecer o aviso de absenteísmo oficial
   indisponível, pedir ao DBA `SELECT` em `5c_wfm_db_mis.abs_adh_af` para o usuário do portal.

**Decisões em aberto com o usuário**
- **Maddie (412) e Maddie Cavill (405):** o sistema pôs as duas na mesma modelo e carteira (U3C2).
  Se forem modelos diferentes, separar.
- **Ativas sem movimento:** Cherie Deville 257t, Lily Lou 143m, Mateus Yudi e OnlyFans Mike
  ficaram fora. Confirmar se alguma precisa de escala.
- **Legado dos times:** depois da virada, lembrar o usuário de decidir o destino dos registros
  de times e parties (memória "lembrete-legado-times").
- **Hora extra em semana publicada:** sem resposta à proposta de criar uma cópia em rascunho
  para incluir quem se oferecer depois de publicar.
- **`pode_assistente`:** script opcional para zerar o campo em SR nível 3 ou mais (oferecido, não pedido).
- **Sessão do Capacity:** avisar que o 5C Escalas agora grava páginas em
  `fc_pagina_carteira` e chama `sp_fc_recalcular_postos` antes de gerar.
- **`fc_estrutura_rep`:** o "tem" do Capacity é contado à parte e não acompanha as mudanças de
  pessoas do ES_52 e do ES_54; o relatório do Capacity fica com as unidades antigas até atualizar.
- **Defeitos do Capacity:** o procedimento falha ("buffer cannot be null") quando o cálculo fica
  sem postos, e a foto de páginas ativas atrasa ativações do mesmo dia.

**Conferir com dados reais** (só testado localmente)
- PDFs da escala da liderança e do organograma.
- Escolha de equipe para as vagas vazias.
- Sincronização dos postos com o Capacity no primeiro recálculo depois da ES_43.

---

## 8. Testes e ferramentas locais

- **PHP local:** `D:\Ferramentas\php\php.exe -d extension_dir=D:/Ferramentas/php/ext -d extension=pdo_sqlite -d extension=mbstring`.
- **Testes:** estão no scratchpad da sessão (`...\Temp\claude\D--5C-Planning\e1fc531a-...\scratchpad\`),
  que é temporário. Copiar para o projeto se for preciso guardar. Últimos resultados:

  | Teste | Resultado |
  |---|---|
  | `trn/estrutura_teste.php` | 19 ok |
  | `trn/estrutura_fora_teste.php` | 7 ok |
  | `trn/equipes_teste.php` | 13 ok |
  | `trn/motor_teste.php` (motor_v4 real com o fixture `app16/fixture_es14.json`) | 12 ok |
  | `trn/trn_teste.php` | 44 ok |
  | `trn/entrada_teste.php` | 22 ok |
  | `ux/saida_teste.php` | 11 ok |
  | `trn/saida_vig_teste.php` | 6 ok |
  | `trn/abs_teste.php` | 7 ok |
  | `trn/he_bloco_teste.php` | 7 ok |

- **1.17.0:** lint de todos os arquivos mudados sem erro; teste da chave `merito_abs_ativo` e de
  `escMeritoAtingimentoPaginas` com dados simulados (fatia das mensagens e nome com espaço duplo) ok.
  Sem teste das telas com o banco.
- **Checagem de traduções:** `ux/i18n_check.py`. Os únicos avisos que sobram são 4 falsos positivos de `lib/i18n.php`.
- **Renderização sem servidor:**
  - `ux/org_render2.php` (organograma) e `ux/lid_render.php` (escala da liderança) montam o HTML;
  - o Edge headless gera o PDF: `msedge --headless=new --no-pdf-header-footer --print-to-pdf=...`.
- **Cuidado com aspas:** heredocs no Bash quebram com aspas e barras. Escrever os scripts
  Python ou PHP com a ferramenta de arquivo e só então rodar.

---

## 9. Sessões ligadas a esta

- **capacity** (`local_ba72f79f-b0ac-4b8e-8355-88fde4f5d39c`): dona do `5c_planning`.
- **simulations** (`local_cfe0ad22-e1ba-460f-8ed9-526026c4af7d`).
- **documento** (`local_f9bb7035-1a22-4d50-ae09-953711a1d35a`).
- **contexto de 31/10 e 1.17.0** (`local_7ca64bbd-eedb-4f97-900a-14c3cb4f7497`) e a filha
  **organograma e troca de unidade** (`local_1a47501f-8a21-4f5a-a323-7d412271faac`).
- **Notas compartilhadas:** `C:\Users\Luis Otavio\AppData\Roaming\Claude\side-session-notes\local_b953c3ec-1f06-42e0-ac84-50dad37d2223`.
