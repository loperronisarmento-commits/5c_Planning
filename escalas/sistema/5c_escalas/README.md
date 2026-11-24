# 5C Escalas (módulo do MIS Portal)

Planejamento de escalas: gera o **rascunho** da semana, recalcula quando algo muda (com De→Para), guarda as decisões tomadas em tela e prepara o CSV para postar no site da 5C.

**Grava só no schema `5c_escalas`.** Planner, escala postada (`scheduleday`), Capacity, headcount e CRM continuam sendo só lidos. A escala oficial continua no site da 5C.

## Antes de instalar

Rode no Navicat, nesta ordem, os scripts de `escalas/sql/`:
1. `ES_01_criar_schema_5c_escalas.sql`: schema e 22 tabelas.
2. `ES_02_carga_inicial.sql`: configuração das pessoas a partir do headcount.
3. `ES_03_permissao_usuario_portal.sql`: `GRANT SELECT, INSERT, UPDATE, DELETE` no `5c_escalas` para o usuário do portal.

Depois, os scripts de evolução na ordem do número (ES_04 em diante), que hoje vão até o `ES_139_entradas_u3_1011.sql` (1.25.1). Os scripts de cada versão estão no changelog (`lib/versao.php`, campo `sql`). O script que apaga o schema é o `ES_999_desfazer.sql` (fora da sequência; antes era `ES_99_desfazer.sql`). Os primeiros de evolução, para referência: `ES_27_funil_destino_nucleo.sql` (destino do Funil por unidade, turno e núcleo; opcional) e `ES_28_modo_treino.sql` (Modo Treino; trocar `<usuario_do_portal>` e `<host>` como no ES_03) `ES_29_roster_lideranca_30out.sql` (liderança pelo roster de 30/10; depois recalcular as semanas de 26/10 e 02/11) `ES_30_solicitacoes_sr.sql` (tela Solicitações; cria a tabela também no treino) `ES_31_corrige_es25_roster.sql` (desfaz 2 trocas do ES_25 cujo par mudou de cargo no ES_29) `ES_32_andressa_urach_02nov.sql` (página nova da Privacy em 02/11: grava no Capacity e cria os postos na U2) `ES_33_politica_paginas.sql` (política de páginas por mérito: parâmetros, prioridades e registro de trocas) `ES_34_solicitacao_pessoa_alvo.sql` (Solicitações: com quem o SR fica na dupla ou na assistência), `ES_35_renumerar_unidades.sql` (Victoria = U1, João = U2, Keller = U3, no Escalas e no Capacity) `ES_36_treinamento_new_hires.sql` (Treinamento de New Hires e parâmetros do absenteísmo no mérito) `ES_37_saida_com_entrada.sql` (saída de página com outra página entrando no lugar) e `ES_38_treinamento_lacunas.sql` (treinamento em página de lacuna).

## Instalação

1. Copie `5c_escalas/` para `mis_portal/systems/`, ao lado de `5c_schedule_anomalies/`. Se já tinha a versão de teste, substitua a pasta inteira.
2. No `.htaccess` da raiz do domínio (se ainda não tiver):

   ```apache
   # 5C ESCALAS
   RewriteRule ^escalas/?$ mis_portal/systems/5c_escalas/index.php [L,QSA]
   ```
3. O Apache precisa escrever em `5c_escalas/logs/`.
4. Abra `/escalas` e vá em **Administração › Diagnóstico**. Tudo deve aparecer verde:
   - **Conexão de leitura:** READ ONLY.
   - **Gravação no 5c_escalas:** OK. O teste grava uma linha e desfaz. Se falhar, faltou o ES_03.
   - **Trava dos outros schemas:** Ativa.
   - Todas as tabelas com leitura OK.

Usa o mesmo `.env` da plataforma (`env()` de `mis_portal/api/config/env.php`). Nenhuma credencial fica nesta pasta.
- **Endereço só deste módulo (opcional):** `ESC_DB_HOST` e `ESC_DB_PORT` no `.env` valem só para o 5C Escalas. Com o MySQL na mesma máquina do portal, use `ESC_DB_HOST=127.0.0.1`: a conexão não passa pela rede, e as outras aplicações continuam com o `DB_HOST`. O Diagnóstico mostra qual endereço está em uso.

## Acesso

Tabela `5c_escalas.esc_acesso`. No primeiro acesso, se ela estiver vazia, o módulo copia os aprovados do Schedule Anomalies: admin de lá vira ADMIN aqui e os demais, LEITURA. Depois disso, quem administra é a tela **Administração › Acessos**.

| Perfil | Pode |
|---|---|
| ADMIN | tudo, inclusive acessos, modelos, parâmetros e Diagnóstico |
| PLANEJAMENTO / WFM | gerar, recalcular, ajustar a grade, publicar, horas extras, aprovar indisponibilidade, cadastros e decisões |
| WFM | (antigo) vale como PLANEJAMENTO |
| GESTOR_TIME | (antigo, fora das opções) ver e registrar ação de correção |
| LEITURA | só ver |

## Telas

| Tela | O que faz | Grava em |
|---|---|---|
| Painel | Rascunhos das semanas, pendências; botão "Verificar mudanças" (recalcula só quando clicado) | `esc_entrada_*`, `esc_recalculo_evento`, `esc_plano*` |
| Gerar escala | Nova versão, recálculo manual, verificar mudanças, histórico de versões e De→Para. Gerar e recalcular passam por três etapas (verificação, Capacity, escala) numa tela de progresso que trava o sistema (lib/gerar_etapas.php) | `esc_plano*`, `esc_sugestao`, `esc_ciclo_estado` |
| Grade da escala | Por página, por pessoa e De→Para; ajuste manual de célula (fica travado); publicar; CSV por página ou .zip; PDF da escala das páginas e PDF de metas (atingimento de cada pessoa escalada na página nas 4 semanas anteriores); abaixo da grade, **Postar no site**: um cartão por página com o CSV para copiar, Date Schedule/Date End, "1 · Apaguei" e "2 · Postei" e o aviso de quais páginas mudaram desde a versão já postada | `esc_plano_alocacao`, `esc_plano`, `esc_exportacao`, `esc_plano_postagem` |
| Escala da liderança | OC e OS em 5x2 no ciclo de 21 dias (cada um num turno só), com o dia de Overlap em que os 3 do grupo estão no turno; OA numa semana fixa, 1 por turno todo dia; OM sem escala; CSV da semana | não grava |
| Horas extras | Postos sem ninguém (por faturamento) e buffers que faltam (Capacity × plantão); texto para pedir hora extra; PDF de anúncio do Overtime a partir de um dia e turno, no padrão visual 5C (papel timbrado, desde a 1.20.0; `lib/overtime_pdf.php`, `?p=horas&baixar=overtime&de=&turno=`); registrar quem se ofereceu e alocar no rascunho (trava turno repetido, mais de 12 h seguidas e mais de 52 h na semana) | `esc_hora_extra`, `esc_plano_alocacao` |
| Análise (WFM) | Abas Escala (horas necessárias × previstas, buffers, escalados, absenteísmo previsto e realizado), Demanda (previsão do Capacity, fluxo por página, funil do CRM) e Escala × demanda por unidade e turno | não grava |
| Mérito dos SRs | Turnos de cada SR (em posto, assistente, buffer -2, Overlap -5) × fatia do nível, e o mérito em pontos (desde a 1.19.0, regras M-01 a M-10): os pontos de cada componente com o dado ao lado, a nota e a faixa (Acima, Na média, Abaixo); o mérito aparece só para Planejamento e Admin | `esc_sr_merito` (cache) |
| KPI dos SRs (simulação) | KPI Kinetic, Process & Integrity do CEO, só visualização (1.32.0): Score = Kinetic × Process × Integrity e moedas, 4 semanas com pesos 4-3-2-1; detalhe semana a semana por SR (`?rep=`); exportar .xlsx e PDF; só WFM/Planejamento e Admin | não grava (cache em arquivo, `escCache`) |
| Sugestões de troca | Workload (remover turno), Buffer sem página, lacunas; aplicar ou descartar com motivo | `esc_sugestao`, `esc_plano_alocacao` |
| Buffers | Precisa (Capacity) × usados × sem página, por dia e turno; pool | não grava |
| Bot de cobrança | SLA de 5 e 10 min e inatividades (15 min sem mensagem numa página escalada) por semana, antes e depois do bot de cobrança (06/10/2026); por SR só para WFM/Planejamento e Admin | `esc_bot_inativ_semana`, `esc_bot_inativ_rep` (cada semana que fecha) |
| Saídas de página | Confirmar a saída ou marcar "não saiu", uma a uma ou várias selecionadas de uma vez; **aplicar a saída** de uma vez: foto do Capacity antes, fecha a página e recalcula, foto depois; fecha ou refaz os postos (mesma equipe); passa as vagas; gera as escalas de novo. Várias páginas aplicadas juntas recalculam o Capacity e a escala uma vez só; uma aplicação por vez (trava contra clique duplo). Comparação antes × depois | `esc_saida_checagem`, `esc_saida_pagina`, `esc_capacity_versao*`, `esc_posto*`, `esc_vaga_equipe`, `esc_plano*` e, no Capacity, `fc_pagina_carteira` + `sp_fc_postos` |
| Funil de entrada | Ativas no site, fora da escala (detectadas pela DB, não pelo CRM: `5c_pages.pages.active = 1` sem carteira aberta no Capacity; nova ou retorno; pôr na escala grava a carteira no Capacity e recalcula, os postos entram no próximo Gerar, no núcleo (OS) escolhido, `nucleo_numero`, ES_139, aplicado por `escEntradaFixarNucleos` na sincronização da estrutura; ignorar), cards do CRM e arquétipos; ajustar previsão e destino | `esc_pagina_entrada_checagem` (ES_138), `esc_pagina_previsao` e, no Capacity, `fc_pagina_carteira` + `sp_fc_recalcular_postos` |
| Configuração das páginas | Faturamento de 30 dias da página e da modelo (emulado ou pelo tier), a chave Automático / Sempre normal / Desconsiderar, a alocação (Active team, On demand, Exclusiva), o SLA, a cor (sensibilidade) e a plataforma; Privacy em R$; botão PDF com a configuração de cada página | `esc_pagina_config` |
| Restrições dos OM | Antes "Fixações". Os pedidos dos OMs lidos do banco na hora: por página (alocação, equipe fixa por turno com main e cover, quem é de outra unidade, só assistente, proibidos, divisões, várias páginas) e por pessoa (equipes, divisões, uma página, várias páginas, só estas páginas, serve como assistente, não faz assistente, solo, tier médio, tier médio ou alto, faixa de faturamento, página masculina, preferência por páginas, baixo fluxo, inegociáveis, não subordinado, mudanças marcadas e indisponibilidades longas, sem o motivo). PDF por unidade (U1, U2, U3) no papel timbrado, com versão (lib/restricoes_om.php, lib/documentos.php). Painel Gerenciar as restrições (1.20.0, lib/restricoes_om_gestao.php): desativar, ativar, editar e apagar, uma por uma ou em lote | `esc_documento_versao`, `esc_restricao_om_inativa` |
| Equilíbrio das unidades | Faturamento de 30 dias por unidade e turno contra a média do turno (±5% equilibrado) e por SR; trocas de unidade sugeridas (todas as páginas da modelo, depois de 28 dias na unidade); páginas sem quem as conheça (`v4_conhece_dias`, `v4_conhece_min`); divisão das páginas entre os OS (OS3 com 15% a 20% do faturamento) | `fc_pagina_carteira` (Capacity), `esc_posto*`, `esc_vaga_equipe`, `esc_colaborador_config`, `esc_plano*` |
| Mérito | Ajuda › Mérito: as regras M-01 a M-10 da nota em pontos, as faixas, exemplos e um simulador; PDF com versão | não grava (só a versão do documento) |
| Capacity | Seção Quadro e contratação (1.24.0). Falta de hoje (precisa − tem), evolução por versão (uma por dia), falta por unidade e turno, ocupação dos postos na escala da semana por carteira (SRs por dia ÷ postos) e as versões com o que mudou; faturamento de 30 dias por carteira só para `escPodeVerRestrito()` | não grava |
| Requisição de vagas | O Simulações 5C no módulo (`assets/requisicao.js`): falta por dia com as turmas do plano (`fc_contratacoes`), a partir do Capacity e do quadro de hoje; "Sugerir tamanhos"; lista para o recrutamento; acompanhamento planejada → aberta → contratada → em treinamento → operando | `esc_requisicao_vaga` (ES_136) |
| Turmas de treinamento (antes Treinamento, em Pessoas) | Lê o e-mail do Treinamento (turmas, horário, datas, trainees), recomenda as páginas para emprestar e gera a resposta; turma confirmada vai para a página 5C Schedule com -4 | `esc_treinamento_turma`, `esc_treinamento_pagina`, `esc_treinamento_trainee` |
| Folga juntos | Pares (casais) que precisam folgar nos mesmos dias: mostra as folgas dos próximos 21 dias e propõe o ajuste de ciclo a partir de uma data | `esc_folga_par`, `esc_colaborador_config`, `esc_lideranca`, `esc_nucleo` |
| Organograma | No visual do roster da 5C (preto e dourado, igual nos dois temas): COO → Director → Operational Manager → turnos T6/T1, T2/T3 e T4/T5 → Operational Coordinator e os 3 Operational Supervisors, cada um com os SRs do núcleo (clique no OS); Operational Assistants; busca por nome ou código, filtro de unidade e data; "Imprimir / PDF" em 1 folha A3 em pé, como está na tela. O brasão do topo é `assets/org_logo.png` quando o arquivo existe | não grava |
| Configuração dos times (Legado) | Estrutura antiga: violações com ação de correção; pendências no Planner (fecham sozinhas quando o Planner bate). Depois da virada (v4_virada) fica só para consulta | `esc_violacao_acao`, `esc_estrutura_pendencia` |
| Trocas de página (Legado, só admin) | Lotes do Planner da estrutura antiga, quem sai e quem entra, CSV e roteiro; marcar "apaguei" e "postei". Saiu do menu Páginas e do tour | `esc_troca_postagem` |
| Colaboradores | Turno fixo, modelo, tipo, trilha, folgas fixas (nova vigência a cada mudança) | `esc_colaborador_config` |
| Modelos de escala | Ciclo de trabalho e folga; o limite de anomalia sai do ciclo | `esc_modelo_escala` |
| Tutorial | Tour guiado pelas telas (com o que fazer na plataforma e no site da 5C), "Ações necessárias agora" e o Modo Treino | `5c_escalas_treino` (ES_28) |
| Glossário · FAQ | Termos e perguntas frequentes, com busca, em português e inglês (lib/ajuda.php) | — |
| Documentação do DBA | Só admin: bancos, conexões e permissões, tabelas e chaves lidas ao vivo, parâmetros, scripts, rotinas; PDF pela impressão e download em Markdown | `information_schema` |
| Changelog | O que mudou em cada versão do sistema; a versão atual aparece no rodapé (lib/versao.php: ESC_NAO_PUBLICADO acumula o que já foi instalado; ao publicar a versão oficial vira um item novo no topo de ESC_PATCH_NOTES) | — |
| Indisponibilidade | Antes "Pedidos e aprovação". Fila do WFM (por data, comentário obrigatório para recusar) e lançamento em nome de alguém | `esc_indisponibilidade*` |
| Owners e backups | Por página e turno: owners (vaga de dono) e backups (vaga de cobertura), núcleo dono, atingimento da meta 5CReps (Intraday) e a situação na política de páginas por mérito (lib/politica.php); trocas sugeridas de owner (aplica na hora) e de unidade (prévia e confirmação, mexe no Capacity); prioridades fixas | `esc_politica_troca`, `esc_pagina_prioridade` (ES_33), `esc_vaga_equipe`, `esc_posto` |
| Regras | Todas as regras de estrutura, alocação, restrições (equipe fixa, divisão, só assistente, baixo fluxo, uma página, várias páginas, uma plataforma por turno, Privacy, On demand e Exclusiva, bloqueio depois da saída, restrições que fecham sozinhas), liderança, pedidos, páginas, mérito e da política, com os valores dos Parâmetros; para ver e imprimir no papel timbrado, com versão | `esc_parametro` (lê), `esc_documento_versao` |
| Roster da liderança | Troca o OM de cada unidade, o OC de cada unidade e turno e o OS de cada núcleo (ou preenche o cargo vago) e inclui, tira ou muda a semana dos OA, a partir de uma data (lib/roster.php). Quem entra sai do núcleo de SR; quem sai fica com o lugar de quem entrou (núcleo e vagas fixas), vira SR no mesmo núcleo (OS) ou fica sem núcleo. O OS de cada núcleo numa data vem da configuração com a função interna de OS (tem vigência); a tabela `esc_nucleo` guarda o último | `esc_lideranca`, `esc_nucleo`, `esc_colaborador_config`, `esc_vaga_equipe` |
| Solicitações | Pedido do SR para trabalhar como assistente ou em dupla, ou mudar de turno, núcleo ou unidade: o líder (perfil com "corrigir") registra; o Planejamento aprova ou recusa e aplica a troca 1 por 1 com um par (cada um vai para o núcleo do outro e assume as vagas fixas dele a partir de uma data; lib/solicitacao.php). Quem pediu o contrário aparece primeiro como par | `esc_solicitacao` (ES_30), `esc_colaborador_config`, `esc_vaga_equipe` |
| Acessos, Parâmetros, Diagnóstico | Administração | `esc_acesso`, `esc_parametro` |

Toda alteração vai para `esc_log` (quem, quando, antes e depois).

## Idioma, tema e celular

- **Idioma:** PT ou EN no topo do menu (cookie `esc_idioma`; sem cookie, segue o navegador). Estilo gettext: o texto em português é a chave de `t()` (lib/i18n.php) e a tradução fica em `lang/en/<tela>.php`. Datas e números seguem o idioma; o que vem do banco não é traduzido; o conteúdo dos CSVs e as datas que se digitam no site da 5C não mudam. Para conferir a cobertura, rode o verificador de `t()` contra os catálogos.
- **Tema:** Claro, Escuro ou Automático (cookie `esc_tema`); todas as cores vêm dos tokens no topo do `assets/escalas.css`. Exceção: o organograma tem paleta própria (variáveis `--o-*` em `.org`), igual nos dois temas.
- **Celular:** a partir de 360 px, menu pelo botão "Menu", tabelas viram cartões.

## Modo Treino

`ES_28_modo_treino.sql` cria o `5c_escalas_treino` (mesmas tabelas e chaves) e dá permissão ao usuário do portal. Na Ajuda › Tutorial, "Ligar o Modo Treino" grava o cookie `esc_treino=1`: o sistema passa a ler e gravar no treino (`ESC_SCHEMA`, lib/db.php), mostra uma faixa em todas as telas, libera todas as ações menos as de administração e não deixa a saída de página mexer no Capacity. "Recomeçar o treino" apaga o treino e copia de novo os cadastros do 5c_escalas (lib/treino.php). Script novo de estrutura: rodar também no treino.

## Estrutura de 01/11/2026 (v4)

Toda semana a partir da virada (parâmetro `v4_virada`) é gerada pelo `lib/motor_v4.php`. Antes de gerar, rode os scripts `ES_04`, `ES_05`, `ES_06` e `ES_09` (`escalas/sql/`). Com o `ES_18`, a virada é 31/10 às 21:00 (o turno D de 31/10 já é da estrutura nova) e o turno D de 31/10 e o dia 01/11 são transição. A hora da virada define o primeiro turno: 05:00 = A, 13:00 = C, 21:00 = D. O `ES_08` é a alternativa sem transição, com o motor começando no turno A de 02/11.
- **Rotação:** 3 núcleos por unidade e turno, ciclo de 21 dias (P1 ter/sáb/dom · P2 seg/sex · P3 qua/qui). Todo dia 2 núcleos trabalham.
- **Vagas:** cada posto (foto do Capacity em `esc_posto`) tem a vaga do dono (núcleo dono, nos dias dele) e a de cobertura (outro núcleo, nas folgas do dono).
- **Melhor SR de cima para baixo (1.19.0, regras A-01 a A-04):** depois da divisão e da equipe fixa, cada página, da modelo que mais fatura para a que menos fatura, escolhe o melhor SR disponível na unidade e no turno: primeiro quem bateu `v4_melhores_meta_min` (65%) da meta na página em 30 dias, depois quem já fez a página em 30 dias, depois a faixa do mérito (Acima, Na média, Abaixo), a nota em pontos e o tempo de casa (`v4_melhores_receita_min` = 0: vale para todas as páginas). Quem estava no posto fica, a não ser que apareça um SR de faixa acima ou que ele esteja abaixo da meta mínima na página. O SR Abaixo só fica como regular numa página de modelo de US$ 100 mil ou mais sem outro disponível (sinalizado); no remanejamento sobe o de melhor faixa e, no acúmulo e na sobrecarga, o Abaixo vai por último (chaves `v4_merito_*`). Página desconsiderada não entra. Posto só de página desconsiderada não abre vaga: a página vira apoio no posto da página principal da mesma modelo, sem somar carga.
- **Ordem de preenchimento:** os postos vão da modelo que mais fatura para a que menos fatura (soma das páginas da modelo, US$/dia do turno, `tab_statement_interval` com `complete` + `pending`, `v4_receita_dias` = 30; página com menos dias de venda é emulada para 30 dias e a sem venda usa o meio da faixa do tier em `5c_crm.tiers`); dentro da modelo, a página que mais fatura. Página desconsiderada (`esc_pagina_config`, tela Configuração das páginas; no automático, OFTV abaixo de `v4_trivial_receita_min` = US$ 8 mil em 30 dias) tem carga zero e vai para o fim da fila. Em cada vaga: equipe fixa (`esc_vaga_equipe`, como preferência) → outra pessoa do núcleo → buffer do outro núcleo do turno → SR de Overlap que sobrou → remanejamento (o SR do posto de menor faturamento da unidade vai para a página maior) → acúmulo de um 2º posto por um SR, dentro da Política e da capacidade → sobrecarga página a página: a página que sobrou vai para um SR já alocado que faz página da mesma agência (a do Infloww, `fc_pagina_infloww`; fora dele, `5c_pages.pages.client`, com Noah, Independent e Lolly Agency como 5C Company), até `v4_over_capacity_max` (30%) acima da capacidade; ele entra cobrindo (não é dono nem conta para a semana seguinte) e acima de 100% fica marcado com ⚠ (SOBRECARGA) → lacuna. Os postos vêm do Capacity; desde 01/11 a distribuição é fixa (`5c_planning.fc_posto_fixo`, script 28 do Capacity) e só página fora dela segue a regra automática. No fim, em cada página e bloco, o primeiro SR é o REGULAR (sem # no CSV) e os demais, ASSISTENTE (#): o papel não diz quem é dono nem quem cobre.
- **Postos:** vêm do Capacity (`fc_postos` de um `data_calculo` fixo), carregados pelo `ES_10` (gerado por `sql/gerador/gerar_es10.py`), que remonta as equipes mantendo cada SR nas páginas que já fazia.
- **Buffer de plantão:** quem fica sem vaga. Vai para a página 5C Schedule com `-2` no código do CSV.
- **Overlap (dia de treinamento):** o 15º dia de trabalho do ciclo. Cada SR tem o próprio dia: cada SR tem uma posição fixa do ciclo em `esc_sr_overlap` (1.36.0, ES_211; quem entra recebe no Gerar a posição menos usada do núcleo, na proporção do parâmetro `v4_overlap_posicao_ciclo`, hoje 7,17,7,16,17: segunda, quinta e quarta, nunca o sábado ou o domingo do fim de semana, ES_205), e o OS e os assentos não mudam. O SR vai para a página **5C Schedule** com `-5` no código (ex.: `L149788-5`); se sobrar Overlap e houver lacuna, ele cobre a lacuna. Depois se decide se vira buffer ou treinamento.
- **Dia de transição** (parâmetro `v4_dia_transicao`, dias separados por vírgula; 31/10 e 01/11 pelo `ES_18`): as páginas desse dia são cobertas por quem já está escalado no `scheduleday`, no turno em que está. Só entram SRs; quem vai virar OS, OC ou OM fica de fora.
- **CSV da semana da virada:** antes da virada, repete o que já está postado, para não apagar nada no site. Gere a semana de 26/10 (só 01/11) e depois a de 02/11, que pega o T1 de segunda do plano anterior.
- **Escala da liderança** (`lib/lideranca.php`, `ES_19`): separada da dos SRs.
  - Operational Manager (OM, um por unidade): não segue escala.
  - Operational Coordinator (OC, um por unidade e turno) e Operational Supervisor (OS, um por núcleo): 5x2 no ciclo de 21 dias. O OS segue o núcleo; os OC usam o `deslocamento` de `esc_lideranca` (0, 14 e 7).
  - Operational Assistant (OA, sem unidade): 1 por turno, todo dia (24/7), numa semana fixa (`padrao_semana`, seg a dom). Os 4 OAs fazem 5 turnos cada e o Giovanni (supervisor) cobre 1. Quem faz dois turnos sempre muda para o mais tarde (11 h de descanso).
  - Sem o dia de treinamento separado: o 15º dia cai na posição `v4_lideranca_overlap_posicao` (5 = sábado), a mesma para todos, e nesse dia os 3 do grupo estão no turno.
  - Ausência aprovada em Indisponibilidade aparece como "ausente".
  - Entra no mesmo plano dos SRs (`ES_20`): quem trabalha no turno vai para a página 5C Schedule nos blocos do turno e sai no mesmo CSV, com `-8` (OS), `-7` (OC) e `-6` (OA) no código, como o `-2` do Buffer e o `-5` do Overlap. O CSV de cada página tem todos os turnos (T1 a T6) juntos.
- **Folga de emenda:** quem já vem do limite de dias seguidos do modelo (5 no 5x2, pelo `ES_22`), somando a escala postada antes do plano e os dias do plano, folga no dia (tipo `EMENDA` em `esc_plano_folga`). A vaga vai para outra pessoa do núcleo, para buffer ou vira lacuna. Na rotação de 21 dias isso não acontece; acontece na virada, com quem vinha sem folga.
- **Emenda de turno:** quem fecha o turno D às 05:00 do primeiro dia do plano fica fora do turno A desse dia (seriam 16 h seguidas).

### O que entrou na 1.19.x

- **Mérito em pontos (1.19.0):** regras M-01 a M-10 (`lib/merito.php`), no lugar da fórmula antiga. Faixas por `merito_pts_faixa_acima` (8) e `merito_pts_faixa_abaixo` (−8). Recalcular o cache no servidor: `phpdbg -qrr C:/WebServer/deploy/merito_recalcular.php <módulo>`.
- **Equipe fixa com main e cover (1.19.15, ES_114):** `esc_pagina_prioridade.papel`. Em três rodadas: primeiro os titulares (MAIN) de todas as páginas, depois quem cobre (COVER) e por último o resto da equipe (EQUIPE). O assistente marcado na página (`esc_pagina_assistente`, papel ASSISTENTE) tem a vez no posto de assistente; papel FORA = proibido na página.
- **Só páginas da própria unidade (1.35.0, `v4_so_propria_unidade` = 1):** o Gerar só põe o SR (titular, buffer, Overlap, divisão ou equipe fixa) em página da unidade dele; quem é de outra unidade só entra à mão, travado na Grade (ex.: Isla Moon). Com o parâmetro em 0 volta o comportamento abaixo.
- **Outra unidade só em último caso (1.19.9, desligado na 1.35.0):** o membro da equipe fixa que está em outra unidade só entra depois de todos os passos, na vaga vazia, e só se sobrou sem posto no turno da unidade dele. No Overlap, o membro cobre primeiro a lacuna da própria página.
- **Divisão do SR (1.19.9, ES_98):** `esc_sr_divisao`. O SR reparte os turnos disponíveis entre as páginas, na parte de cada uma (mesmo de outra unidade); o resto é a escala normal da unidade. Vale antes da equipe fixa, e a equipe fixa não toma a vaga que a divisão deu (correção da 1.19.15).
- **Restrições dos OM (1.19.9 a 1.19.15, `lib/restricoes_om.php`):** camada à parte do motor, que não muda as regras P-01 a P-04 nem o passo 0. Parâmetros: `v4_sr_uma_pagina`, `v4_sr_multipagina`, `v4_sr_so_assistente`, `v4_sr_baixo_fluxo`, `v4_sr_baixo_fluxo_estrito` e `v4_baixo_fluxo_max` (12 fãs/h), `v4_sr_pref_assistente`, `v4_sr_so_paginas`, `v4_sr_tier_medio` (`v4_tier_medio` = C,D), `v4_sr_faixa_receita`, `v4_sr_pref_masculinas` (`v4_paginas_masculinas`), `v4_sr_tier_medio_alto` (`v4_tier_medio_alto` = A,B,C,D), `v4_sr_solo` ("CÓDIGO" ou "CÓDIGO:até AAAA-MM-DD"; nem assistente nem em posto com assistente, também na cobertura do passo 7), `v4_sr_pref_paginas` (1.19.20, ES_123), `v4_nao_subordinado`, `v4_todos_os_dias`, além de "não faz assistente" (`esc_colaborador_config.pode_assistente` = 0). Valem na equipe fixa e no preenchimento (passos 1 a 7). A página da divisão vale sempre para quem divide.
- **Uma plataforma por turno (1.19.15, ES_111):** `v4_uma_plataforma_turno` (padrão 1). Quem faz Privacy, Fanvue ou PlayBoy não acumula nem cobre página de outra plataforma no mesmo turno (passos 6 e 7), e o Recalcular com o mínimo de mudanças (`lib/minimo.php`) tira a mistura da versão publicada. A plataforma vem de `esc_pagina_config.plataforma` ou do cadastro (`escPlataformaDe`). Privacy só com SR brasileiro: `v4_plataforma_so_brasil`, pela nacionalidade do headcount. Valores em R$ nas páginas de `v4_plataforma_brl`.
- **Alocação da página (`esc_pagina_config.alocacao`):** EQUIPE (Active team, padrão), SOB_DEMANDA (On demand: o Gerar não põe ninguém; alocação à mão com a célula travada) e EXCLUSIVA (solo: o SR não acumula nem cobre outra página; assistente só com posto fixo de 2 pessoas).
- **Restrições que fecham sozinhas (1.19.16, `escOmEncerrarOrfas`):** página que sai ou SR desligado têm as restrições fechadas no último dia (a pessoa sai da linha da equipe fixa; fecham só assistente, proibido e divisão; os parâmetros perdem quem passou do último dia e a página que saiu). Roda no fim da saída de página e antes de cada Gerar, com log. A liderança (OS, OM, OC, OA) não conta como desligada. O ES_120 fez a revisão cruzada.
- **Bloqueio depois da saída (1.19.11):** página que saiu pela tela Saídas não volta sozinha por `v4_saida_bloqueio_dias` (60) dias, mesmo ativa no site (`lib/estrutura.php`). Quando ela fica ativa de novo, aparece no Funil de entrada, seção Ativas no site, fora da escala (`lib/funil_entrada.php`, 1.25.0), como "não volta sozinha", e o Gerar avisa; ali ela é posta na escala (carteira de antes por padrão) ou ignorada.
- **Versão dos documentos (1.19.15, ES_115, `lib/documentos.php`):** organograma, escala da liderança (PDF e CSV), mérito, regras, owners e restrições dos OM mostram "versão vN" no cabeçalho e no nome do arquivo. A assinatura é o texto impresso, sem data e hora; o número só sobe quando ele muda (`esc_documento_versao`). Os PDFs e CSVs da escala levam a versão do plano (ex.: v32.1).
- **Mínimo de mudanças:** "Recalcular com o mínimo de mudanças" parte da versão publicada e só muda o necessário (página nova ou que saiu, indisponibilidade, turno dobrado, plataforma misturada); na postagem, as outras páginas ficam "igual".
- **"A partir de" (Gerar) e "CSV a partir de" (Grade):** o corte fica em `esc_plano.parametros.desde` (início do turno); antes dele vêm as células e as folgas da versão publicada. Os PDFs da Grade seguem o corte (1.19.5); a página sem ninguém no período fica fora do PDF (1.19.14).
- **"Antes do corte, igual ao site" (1.19.12 e 1.19.13):** marcada por padrão, copia de `5c_schedule.scheduleday` as células anteriores ao corte (`escMotorPreCorteDoSite`); na 5C Schedule o sufixo vem da versão publicada ou do cargo. No dia do corte, o CSV leva também os blocos de antes dele, sempre iguais ao site.
- **Tags de leitura (1.19.6, `lib/tags.php`):** chips ao lado de cada SR na Grade e no PDF (Meta ✓/✗, Exp./Inexp., Trainee, Faltas, SLA ↓, Infração, Mérito ↑/↓, Seq. meta), com cortes nos parâmetros `tag_*` (regra T-01, ainda a validar). "⚠ n" por página conta as tags vermelhas no turno.

## Recálculo e De→Para

- A cada verificação, o sistema tira uma "foto" de cada entrada: Capacity, estrutura e membros, páginas, colaboradores, pedidos aprovados, modelos e trocas de página previstas. O que mudou fica em `esc_entrada_diferenca`.
- Se algo mudou desde a versão atual de uma semana em aberto, ele gera a subversão (v1 → v1.1) e grava o De→Para célula a célula em `esc_plano_diferenca`.
- Ajuste manual feito na grade fica **travado** e o recálculo mantém.
- **Quem está na escala publicada não perde o dia sozinho.** Se o recálculo deixaria a pessoa sem trabalho no turno, ela é mantida e vira sugestão de workload. Se a sugestão for aplicada, o próximo recálculo não traz a pessoa de volta. Trocar de página no mesmo turno não conta como tirar o dia.
- A verificação roda só pelo botão "Verificar mudanças" (Painel e Gerar escala): abrir uma tela não recalcula nada.
- **Gerar em etapas (1.19.21, `lib/gerar_etapas.php`):** os botões Gerar, Recalcular tudo e Recalcular com o mínimo de mudanças chamam `pages/gerar.php` uma etapa por vez (campo `etapa`, resposta JSON) e o `escalas.js` mostra a sobreposição de progresso (`.ge-fundo`, `.app` inerte, `beforeunload`). Etapas: `verificar` (`escGerarPendencias`: saída "a conferir" de página aberta em `fc_pagina_carteira` bloqueia e leva a `saidas`; indisponibilidade PENDENTE na semana avisa e leva a `aprovar`; `IS_FREE_LOCK('5c_escalas.gerar')`), `capacity` (`escEstruturaAtualizar(true)`, sem a trava de `v4_estrutura_minutos`; órfãs e versão das restrições) e `escala` (`escGerarEscala`, com o flash e a URL da grade). Erro de conexão (`escErroConexao`, inclui 1160/08S01) devolve `repetir` e o JS repete até 2 vezes. Sem JavaScript, o mesmo POST roda as três em sequência.

## CSV para o site da 5C

- Formato do Schedule Ajustment: 6 linhas (T1 a T6) × 7 colunas (seg a dom), códigos separados por `, `, `#` para assistente. O `$` o site calcula sozinho.
- Na página 5C Schedule, o sufixo diz o que a pessoa faz: `-2` Buffer, `-5` Overlap, `-6` Operational Assistant, `-7` Operational Coordinator, `-8` Operational Supervisor.
- Tabelas: clicar no título da coluna ordena (de novo inverte).
- O T1 de segunda é o fim do turno D de domingo e vem do plano da semana anterior.
- Um arquivo por página, ou todos num .zip (precisa da extensão ZipArchive do PHP).

## Legado: motor v3 (times e parties, até a virada de 31/10)

Era o motor da estrutura antiga (`lib/motor.php`); as semanas a partir da virada (`v4_virada`) usam o motor v4, acima. Fica aqui como registro.

1. **Releitura do realizado:** lê as últimas 4 semanas do `scheduleday` e descobre a posição de cada pessoa no ciclo (trilha) e quantos dias seguidos ela já trabalhou. A trilha só é usada se não houver uma fixada em Colaboradores.
2. **Quem trabalha no dia:** o ciclo do modelo, menos as indisponibilidades aprovadas. O turno é fixo: no 3x1 só a folga avança.
3. **Necessidade:** Capacity (`fc_escala_pagina`) por página, dia e bloco. Página dedicada com N reps dá 1 regular e N−1 assistentes. Página compartilhada divide o rep até completar 1. Dias fora do horizonte do Capacity usam o mesmo dia da semana.
4. **Ordem de preenchimento:** party dona → outras parties do time → SR regular (quem está mais abaixo do alvo) → SR assistente → Buffers do turno → lacuna.
   - Titular ou SR de serviço sem página vira assistente na maior página da party ou do time.
   - Buffer sem página vira sugestão de remoção por falta de workload.
5. **Anomalias programadas:** dias seguidos (realizado + plano) acima do máximo do modelo. Para publicar com anomalia, é preciso marcar "estou ciente".

## O que ainda não está ligado

- **Infrações no mérito:** o componente M-09 lê a tabela `esc_sr_infraction`; sem carga nela, vale 0 para todos.
- **Pedido pela Nora:** a fila está pronta (canal NORA), falta o bot chamar o portal.
- **"Não saiu" numa saída de página:** fica registrado e avisa, mas a necessidade continua vindo do Capacity.
- **Postar via API:** não existe API de escrita no `scheduleday`.

## Para remover

Apague a pasta e a linha do `.htaccess`. Os dados ficam no `5c_escalas` (o `ES_999_desfazer.sql` apaga o schema).

## Quadro e contratação (1.24.0)

Seção nova do menu, entre Análise e Páginas: **Capacity** (`pages/capacity.php`), **Requisição de vagas** (`pages/requisicao.php`) e **Turmas de treinamento** (`pages/treinamento.php`, que saiu de Pessoas). Leituras e gravações comuns em `lib/quadro.php`; gráficos em SVG no servidor em `lib/graficos.php` (também usados pela Análise), com as cores em `assets/analise.css` (cinza e uma cor de destaque, rótulo direto, título que diz a conclusão).

- **Capacity:** só leitura, todos os perfis. Lê `5c_planning.fc_capacity_versao*` (a última versão de cada dia), `fc_capacity_versao_posto` para os postos por carteira e o plano atual da semana (`esc_plano_alocacao` × `fc_pagina_carteira`) para a ocupação e as lacunas. O "tem" do Capacity vem do cadastro do 5C Escalas desde 09/11 (`sp_fc_estrutura_rep_escalas`, 5C_Planning_34; antes, a foto de 27/10) e é guardado dia a dia desde 01/11 em `fc_quadro_dia` (5C_Planning_35). O absenteísmo do buffer conta de 01/11 em diante (5C_Planning_36: `abs_desde` e `abs_d_media_ac`).
- **Requisição de vagas:** a simulação roda no navegador (nada é gravado) com as premissas do `fc_parametros`; o acompanhamento grava em `esc_requisicao_vaga` (ES_136: uma linha por turma e turno, etapa ABERTA, CONTRATADA ou CANCELADA). Em treinamento e operando vêm de `esc_treinamento_turma`. Registrar pede o perfil de planejar.
- **Destino dos novatos:** colunas novas em `esc_treinamento_trainee` (ES_137): `situacao`, `unidade_destino`, `nucleo_destino_id`, `destino_motivo`, `destino_por`, `destino_em`. A sugestão (`escDstSugerir`) não grava; "Confirmar destinos" grava; "Cadastrar a partir de…" manda para Colaboradores (nível 0, modelo mais usado, núcleo e data do destino); "Cadastrar todos" faz o mesmo para todos os prontos do ciclo de uma vez (`escDstCadastrarTodos`, que grava por `escColaboradorGravar`, a mesma função de Colaboradores).

## Turmas editáveis, Fora das unidades e parâmetros legados (1.26.0)

- **Plano de turmas editável** (Requisição de vagas): incluir, mudar e apagar turmas, gravando em `5c_planning.fc_contratacoes` (`escReqPlanoNova`, `escReqPlanoSalvar`, `escReqPlanoApagar`, `escReqPlanoSalvarTodas` em `lib/quadro.php`). Turma em treinamento ou operando fica travada; no Modo Treino nada grava no plano.
- **Fora das unidades** (`pages/fora_unidades.php`, `lib/fora_unidades.php`): SRs do RH sem unidade no Escalas, com a sugestão pelas lacunas; "Cadastrar" usa o mesmo POST das Turmas de treinamento.
- **Parâmetros legados:** `ESC_PARAMETROS_LEGADO` em `lib/parametros.php` lista os parâmetros que nenhum código lê. Eles saem da tela Parâmetros e ficam em Legado › Parâmetros legados, só leitura. Nada muda no banco.

## Bot de cobrança (1.29.0)

- Tela Análise › Bot de cobrança (`pages/bot_cobranca.php`, regras e consultas em `lib/bot_cobranca.php`): SLA de 5 e 10 min por semana e turno (`5c_capacity.cap_rep_hora`, horas completas) e inatividades antes e depois do bot (06/10/2026), com a entrada do bot e a das 3 unidades marcadas nos gráficos.
- Inatividade = página escalada (5C Schedule, `faltou` = 0) sem mensagem de funcionário (`5c_wfm_db.msgdashboard`, Employee/sent, nome pelo `5c_bot.page_alias`) por 15 min ou mais, a regra do `5c_bot.vw_alerta_inatividade`. O ES_143 cria `esc_bot_inativ_semana`, `esc_bot_inativ_rep` e o procedimento `esc_sp_bot_inatividade(segunda)`, e carrega de 31/08 a 02/11; a tela calcula cada semana que fecha (uma por acesso de quem planeja, ~1 min; não roda no Modo Treino).
- Períodos: antes = 07/09 a 04/10; com o bot = 12/10 a 01/11; transição = semana de 05/10; 3 unidades = de 02/11 em diante. Não usa o corte de 01/11 (`escCorteDados`). Por SR só com `escPodeVerRestrito`.

## Experiência e meta pela modelo (1.28.0)

- Conhecer uma das páginas da modelo basta (Mikayla Free ou Mikayla Paid). Helpers em `lib/estrutura.php`: `escModeloDaPagina` (modelo do Capacity, `fc_pagina_carteira.modelo`, ou pelo nome), `escModeloPaginasAbertas`, `escModeloDiasSet` e `escModeloDias` (dias distintos na modelo; o mesmo dia em duas páginas conta uma vez). Meta: `escMeritoAtingimentoModelos` (`lib/merito.php`) soma realizado e meta de todas as páginas abertas da modelo.
- Usam a modelo: `conhece_cor` (P-02) e `historico` em `lib/motor_v4_carga.php`, `melhores['meta']` (A-01), as tags EXP/INX/BM/NM (`lib/tags.php`, cache `tags_modelo_`) e as colunas de dias e meta dos representantes no PDF das páginas (`pages/grade.php`).
- Continuam por página: a venda por hora (`melhores['pagina']`, `cap_rep_hora`), o PDF de metas da Grade e o conhecimento do Equilíbrio (que já agrupa a modelo nas trocas).

## Saídas e entradas lidas do site na hora (1.27.1)

- **Desativadas no site, sem saída detectada** (Saídas de página): carteira aberta no Capacity + `5c_pages.pages.active = 0` agora, sem evento `SAIDA` nos últimos 14 dias nem saída aplicada por perto. O evento do Capacity só sai depois da foto das 3h (`fc_pagina_status_diario`). As marcadas vão juntas para a prévia (`pagina[]`).
- **Saída manual de várias páginas:** "+ outra página" no "Aplicar saída de página"; a prévia e o POST aceitam `pagina[]` e aplicam por `escAplicarSaidasPaginas` (um recálculo). A página que entra no lugar só vale com uma página saindo.
- **Funil, página renomeada:** o nome antigo vem do `page_id` nas fotos e nos eventos do Capacity; vira RETORNO com a carteira de antes, e "Pôr na escala" grava pela ação `carteira_renomeada` de `escCapacityGravar` (copia modelo, regra e volume). Com o nome antigo ainda com carteira aberta, a página não aparece (só mudou o nome).
- **Funil, ativou agora:** inativa (ou sem foto) na última foto e ativa no site agora → situação RECENTE, no topo, com o dia do `updated_at`.

## Exportar o Mérito dos SRs (1.22.0)

Na tela Mérito dos SRs: `?p=srs&baixar=xlsx` (`lib/xlsx.php`, planilha sem biblioteca externa, só ZipArchive) e `?p=srs&ver=pdf&imprimir=1` (`@page merito-srs`, A4 paisagem). Valem os filtros `u` e `t`, o acesso é o de `escPodeVerRestrito()` e a versão vem de `escDocVersao('merito_srs', ...)`.

## KPI no lugar do mérito (1.34.0)

Desde a 1.34.0 o KPI é a referência de desempenho da escala (regras em `contexto/regras_escalacao_kpi.md`, K-01 a K-08, decisões D1 a D4 de 23/11).

- `lib/kpi.php`: `escKpiNivel` (nível 0 a 4), `escKpiBanco` (cache `esc_sr_kpi`, ES_198 e ES_199, recalcula a cada 3 h), `escKpiLinhas`, `escDesempenhoMotor` (formato do antigo `escMeritoMotor`: nota = score em %, faixa = nível, anos, `so_cobertura`) e `escDesempenhoNotas`.
- Motor (`lib/motor_v4.php`): K-03 (tabela de continuidade por nível, `$kpiTira`), D1 (no máximo `v4_kpi_mov_max` dos SRs perdem o posto pelo KPI na semana, `$kpiMovidos`), D2 (`$travaCobertura`: abaixo de `v4_kpi_so_cobertura` entre os 20% piores, nunca regular em posto até o passo 5).
- Equilíbrio, Funil, Owners (`lib/politica.php`), equipes e tags usam o KPI; o mérito só recalcula quando `v4_fonte_desempenho` = MERITO.
- Mérito dos SRs e Ajuda › Mérito no Legado (só admin, só leitura).

## KPI dos SRs (1.32.0)

Simulação do documento "KPI — Kinetic, Process e Integrity" (CEO, nov/2026), em `pages/kpi.php` e `lib/kpi.php`. Só lê o banco (`escQ`), guarda o resultado em arquivo temporário por 3 h (`escCache`; "Recalcular agora" = `recarregar=1`) e não alimenta o Gerar, o mérito, pagamentos nem notificações. Exportações sem número de versão, porque `escDocVersao` grava no banco.

- Semanas de segunda a domingo; pesos 4 (atual, até ontem), 3, 2 e 1; semana sem dado fica fora da média; corte de 01/11.
- Kinetic: meta semanal (realizado ÷ meta 5CReps rateado pelas mensagens, a mesma conta da M-10), média ponderada → base em rampa pelos pontos da tabela do documento (`ESC_KPI_KINETIC`, 1.32.2; antes, degraus). Moedas por semana.
- Process: SLA 5/10/15 do `cap_rep_hora` (o `resp_15min` vem do 5C_Planning_41); esperado 80/90/95% em `ESC_KPI_SLA`; piso 60%.
- Integrity: dias com ABS no `abs_adh_af` (falta aprovada já sai da escala), −15% cada. Quebra de P&G (23/11): avaliação do `5c_quality_db.extracao_base_resumida` com a seção "Model Best Practices" Não Conforme, ligada ao SR pelo nome no headcount; −10% com nota 80 até −30% com nota 0 (`ESC_KPI_PG`), na semana da data do contato.
- Sem dado ainda: Mass por turno, "não seguir o plano", DNR, multas e loja.
- Ajustes de 23/11 (CEO e usuário): só semanas fechadas; Kinetic = Σ(peso × realizado) ÷ Σ(peso × meta), moedas seguem por semana e sem teto; desconto de SLA proporcional; novato (< 2 semanas com meta) com 75% provisório; ação de correção só para os 20% de menor score (`escKpiPareto`).
- 1.32.1: gráfico de SRs por faixa de score (`escGrafColunas`), filtros em botões, ordenação `o`/`d` (padrão: rank pelo score, do maior para o menor) e coluna #. Página e turno sem meta no Intraday ficam fora da meta semanal.

## Acesso restrito (1.21.0)

`escPodeVerRestrito()` (perfil real ADMIN, PLANEJAMENTO ou WFM, também no Modo Treino) controla a Política de mérito (telas `owners` e `merito`, seções de mérito e política em Regras), as tags individuais, o motivo da indisponibilidade, Folga juntos, venda por hora, faturamento e valor de contrato. Telas `times`, `trocas` e `modelos` só para admin. Este README não é servido pela web (`.htaccess`).

## Corte de dados (1.23.0)

As janelas de desempenho nunca começam antes de 01/11/2026: `escCorteDados()` e `escDiasCorte()` em `lib/calendario.php`, com a data em `escV4Param('dados_desde', '2026-11-01')`. O Capacity, a sincronização da estrutura e o faturamento de 30 dias do Equilíbrio das unidades (`escEqFaturamento`, desde a 1.27.2) ficam de fora.

## Requisitos

PHP 7.4 ou mais novo, com `pdo_mysql` (e ZipArchive para o .zip). MySQL 8.0.19 ou mais novo (usa `INSERT ... AS n ON DUPLICATE KEY UPDATE`); o servidor .11 está no 9.5.
