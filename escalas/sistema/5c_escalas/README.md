# 5C Escalas (módulo do MIS Portal)

Planejamento de escalas: gera o **rascunho** da semana, recalcula quando algo muda (com De→Para), guarda as decisões tomadas em tela e prepara o CSV para postar no site da 5C.

**Grava só no schema `5c_escalas`.** Planner, escala postada (`scheduleday`), Capacity, headcount e CRM continuam sendo só lidos. A escala oficial continua no site da 5C.

## Antes de instalar

Rode no Navicat, nesta ordem, os scripts de `escalas/sql/`:
1. `ES_01_criar_schema_5c_escalas.sql`: schema e 22 tabelas.
2. `ES_02_carga_inicial.sql`: configuração das pessoas a partir do headcount.
3. `ES_03_permissao_usuario_portal.sql`: `GRANT SELECT, INSERT, UPDATE, DELETE` no `5c_escalas` para o usuário do portal.

Depois, os scripts de evolução na ordem do número (ES_04 em diante). Os mais recentes: `ES_27_funil_destino_nucleo.sql` (destino do Funil por unidade, turno e núcleo; opcional) e `ES_28_modo_treino.sql` (Modo Treino; trocar `<usuario_do_portal>` e `<host>` como no ES_03) `ES_29_roster_lideranca_30out.sql` (liderança pelo roster de 30/10; depois recalcular as semanas de 26/10 e 02/11) `ES_30_solicitacoes_sr.sql` (tela Solicitações; cria a tabela também no treino) `ES_31_corrige_es25_roster.sql` (desfaz 2 trocas do ES_25 cujo par mudou de cargo no ES_29) `ES_32_andressa_urach_02nov.sql` (página nova da Privacy em 02/11: grava no Capacity e cria os postos na U2) `ES_33_politica_paginas.sql` (política de páginas por mérito: parâmetros, prioridades e registro de trocas) `ES_34_solicitacao_pessoa_alvo.sql` (Solicitações: com quem o SR fica na dupla ou na assistência), `ES_35_renumerar_unidades.sql` (Victoria = U1, João = U2, Keller = U3, no Escalas e no Capacity) `ES_36_treinamento_new_hires.sql` (Treinamento de New Hires e parâmetros do absenteísmo no mérito) `ES_37_saida_com_entrada.sql` (saída de página com outra página entrando no lugar) e `ES_38_treinamento_lacunas.sql` (treinamento em página de lacuna).

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
| Gerar escala | Nova versão, recálculo manual, verificar mudanças, histórico de versões e De→Para | `esc_plano*`, `esc_sugestao`, `esc_ciclo_estado` |
| Grade da escala | Por página, por pessoa e De→Para; ajuste manual de célula (fica travado); publicar; CSV por página ou .zip; PDF da escala das páginas e PDF de metas (atingimento de cada pessoa escalada na página nas 4 semanas anteriores); abaixo da grade, **Postar no site**: um cartão por página com o CSV para copiar, Date Schedule/Date End, "1 · Apaguei" e "2 · Postei" e o aviso de quais páginas mudaram desde a versão já postada | `esc_plano_alocacao`, `esc_plano`, `esc_exportacao`, `esc_plano_postagem` |
| Escala da liderança | OC e OS em 5x2 no ciclo de 21 dias (cada um num turno só), com o dia de Overlap em que os 3 do grupo estão no turno; OA numa semana fixa, 1 por turno todo dia; OM sem escala; CSV da semana | não grava |
| Horas extras | Postos sem ninguém (por faturamento) e buffers que faltam (Capacity × plantão); texto para pedir hora extra; registrar quem se ofereceu e alocar no rascunho (trava turno repetido, mais de 12 h seguidas e mais de 52 h na semana) | `esc_hora_extra`, `esc_plano_alocacao` |
| Análise (WFM) | Abas Escala (horas necessárias × previstas, buffers, escalados, absenteísmo previsto e realizado), Demanda (previsão do Capacity, fluxo por página, funil do CRM) e Escala × demanda por unidade e turno | não grava |
| Equilíbrio dos SRs | Turnos de cada SR (em posto, assistente, buffer -2, Overlap -5) × fatia do nível, e o mérito do 5C George: nota = Desempenho × SLA × (1 − absenteísmo) ÷ (1 + pena das infrações); o mérito aparece só para Planejamento e Admin | `esc_sr_merito` (cache) |
| Sugestões de troca | Workload (remover turno), Buffer sem página, lacunas; aplicar ou descartar com motivo | `esc_sugestao`, `esc_plano_alocacao` |
| Buffers | Precisa (Capacity) × usados × sem página, por dia e turno; pool | não grava |
| Trocas de página | Lotes do Planner, quem sai e quem entra, CSV e roteiro; marcar "apaguei" e "postei" | `esc_troca_postagem` |
| Saídas de página | Confirmar a saída ou marcar "não saiu", uma a uma ou várias selecionadas de uma vez; **aplicar a saída** de uma vez: foto do Capacity antes, fecha a página e recalcula, foto depois; fecha ou refaz os postos (mesma equipe); passa as vagas; gera as escalas de novo. Várias páginas aplicadas juntas recalculam o Capacity e a escala uma vez só; uma aplicação por vez (trava contra clique duplo). Comparação antes × depois | `esc_saida_checagem`, `esc_saida_pagina`, `esc_capacity_versao*`, `esc_posto*`, `esc_vaga_equipe`, `esc_plano*` e, no Capacity, `fc_pagina_carteira` + `sp_fc_postos` |
| Funil de entrada | Cards do CRM e arquétipos; ajustar previsão e destino | `esc_pagina_previsao` |
| Configuração das páginas | Faturamento de 30 dias da página e da modelo (emulado ou pelo tier) e a chave Automático / Sempre normal / Desconsiderar | `esc_pagina_config` |
| Organograma | No visual do roster da 5C (preto e dourado, igual nos dois temas): COO → Director → Operational Manager → turnos T6/T1, T2/T3 e T4/T5 → Operational Coordinator e os 3 Operational Supervisors, cada um com os SRs do núcleo (clique no OS); Operational Assistants; busca por nome ou código, filtro de unidade e data; "Imprimir / PDF" em 1 folha A3 em pé, como está na tela. O brasão do topo é `assets/org_logo.png` quando o arquivo existe | não grava |
| Configuração dos times (Legado) | Estrutura antiga: violações com ação de correção; pendências no Planner (fecham sozinhas quando o Planner bate). Depois da virada (v4_virada) fica só para consulta | `esc_violacao_acao`, `esc_estrutura_pendencia` |
| Colaboradores | Turno fixo, modelo, tipo, trilha, folgas fixas (nova vigência a cada mudança) | `esc_colaborador_config` |
| Modelos de escala | Ciclo de trabalho e folga; o limite de anomalia sai do ciclo | `esc_modelo_escala` |
| Tutorial | Tour guiado pelas telas (com o que fazer na plataforma e no site da 5C), "Ações necessárias agora" e o Modo Treino | `5c_escalas_treino` (ES_28) |
| Glossário · FAQ | Termos e perguntas frequentes, com busca, em português e inglês (lib/ajuda.php) | — |
| Documentação do DBA | Só admin: bancos, conexões e permissões, tabelas e chaves lidas ao vivo, parâmetros, scripts, rotinas; PDF pela impressão e download em Markdown | `information_schema` |
| Changelog | O que mudou em cada versão do sistema; a versão atual aparece no rodapé (lib/versao.php: ESC_NAO_PUBLICADO acumula o que já foi instalado; ao publicar a versão oficial vira um item novo no topo de ESC_PATCH_NOTES) | — |
| Pedidos e aprovação | Fila do WFM (por data, comentário obrigatório para recusar) e lançamento em nome de alguém | `esc_indisponibilidade*` |
| Owners e backups | Por página e turno: owners (vaga de dono) e backups (vaga de cobertura), núcleo dono, atingimento da meta 5CReps (Intraday) e a situação na política de páginas por mérito (lib/politica.php); trocas sugeridas de owner (aplica na hora) e de unidade (prévia e confirmação, mexe no Capacity); prioridades fixas | `esc_politica_troca`, `esc_pagina_prioridade` (ES_33), `esc_vaga_equipe`, `esc_posto` |
| Regras | Todas as regras de estrutura, alocação, liderança, pedidos, páginas, mérito e da política, com os valores dos Parâmetros; para ver e imprimir no papel timbrado | `esc_parametro` |
| Roster da liderança | Troca o OM de cada unidade, o OC de cada unidade e turno e o OS de cada núcleo (ou preenche o cargo vago) e inclui, tira ou muda a semana dos OA, a partir de uma data (lib/roster.php). Quem entra sai do núcleo de SR; quem sai fica com o lugar de quem entrou (núcleo e vagas fixas), vira SR no mesmo núcleo (OS) ou fica sem núcleo. O OS de cada núcleo numa data vem da configuração com funcao DECURIO (tem vigência); `esc_nucleo.decurio_*` guarda o último | `esc_lideranca`, `esc_nucleo`, `esc_colaborador_config`, `esc_vaga_equipe` |
| Solicitações | Pedido do SR para trabalhar como assistente ou em dupla, ou mudar de turno, núcleo ou unidade: o líder (perfil com "corrigir") registra; o Planejamento aprova ou recusa e aplica a troca 1 por 1 com um par (cada um vai para o núcleo do outro e assume as vagas fixas dele a partir de uma data; lib/solicitacao.php). Quem pediu o contrário aparece primeiro como par | `esc_solicitacao` (ES_30), `esc_colaborador_config`, `esc_vaga_equipe` |
| Acessos, Parâmetros, Diagnóstico | Administração | `esc_acesso`, `esc_parametro` |

Toda alteração vai para `esc_log` (quem, quando, antes e depois).

## Idioma, tema e celular

- **Idioma:** PT ou EN no topo do menu (cookie `esc_idioma`; sem cookie, segue o navegador). Estilo gettext: o texto em português é a chave de `t()` (lib/i18n.php) e a tradução fica em `lang/en/<tela>.php`. Datas e números seguem o idioma; o que vem do banco não é traduzido; o conteúdo dos CSVs e as datas que se digitam no site da 5C não mudam. Para conferir a cobertura, rode o verificador de `t()` contra os catálogos.
- **Tema:** Claro, Escuro ou Automático (cookie `esc_tema`); todas as cores vêm dos tokens no topo do `assets/escalas.css`. Exceção: o organograma tem paleta própria (variáveis `--o-*` em `.org`), igual nos dois temas.
- **Celular:** a partir de 360 px, menu pelo botão "Menu", tabelas viram cartões.

## Modo Treino

`ES_28_modo_treino.sql` cria o `5c_escalas_treino` (mesmas tabelas e chaves) e dá permissão ao usuário do portal. Na Ajuda › Tutorial, "Ligar o Modo Treino" grava o cookie `esc_treino=1`: o sistema passa a ler e gravar no treino (`ESC_SCHEMA`, lib/db.php), mostra uma faixa em todas as telas, libera todas as ações menos as de administração e não deixa a saída de página mexer no Capacity. "Recomeçar o treino" apaga o treino e copia de novo os cadastros do 5c_escalas (lib/treino.php). Script novo de estrutura: rodar também no treino.

## Como a escala é gerada

1. **Releitura do realizado:** lê as últimas 4 semanas do `scheduleday` e descobre a posição de cada pessoa no ciclo (trilha) e quantos dias seguidos ela já trabalhou. A trilha só é usada se não houver uma fixada em Colaboradores.
2. **Quem trabalha no dia:** o ciclo do modelo, menos as indisponibilidades aprovadas. O turno é fixo: no 3x1 só a folga avança.
3. **Necessidade:** Capacity (`fc_escala_pagina`) por página, dia e bloco. Página dedicada com N reps dá 1 regular e N−1 assistentes. Página compartilhada divide o rep até completar 1. Dias fora do horizonte do Capacity usam o mesmo dia da semana.
4. **Ordem de preenchimento:** party dona → outras parties do time → Tertius regular (quem está mais abaixo do alvo) → Tertius assistente → Buffers do turno → lacuna.
   - Titular ou Tertius de serviço sem página vira assistente na maior página da party ou do time.
   - Buffer sem página vira sugestão de remoção por falta de workload.
5. **Anomalias programadas:** dias seguidos (realizado + plano) acima do máximo do modelo. Para publicar com anomalia, é preciso marcar "estou ciente".

## Estrutura de 01/11/2026 (v4)

Toda semana a partir da virada (parâmetro `v4_virada`) é gerada pelo `lib/motor_v4.php`. Antes de gerar, rode os scripts `ES_04`, `ES_05`, `ES_06` e `ES_09` (`escalas/sql/`). Com o `ES_18`, a virada é 31/10 às 21:00 (o turno D de 31/10 já é da estrutura nova) e o turno D de 31/10 e o dia 01/11 são transição. A hora da virada define o primeiro turno: 05:00 = A, 13:00 = C, 21:00 = D. O `ES_08` é a alternativa sem transição, com o motor começando no turno A de 02/11.
- **Rotação:** 3 núcleos por unidade e turno, ciclo de 21 dias (P1 ter/sáb/dom · P2 seg/sex · P3 qua/qui). Todo dia 2 núcleos trabalham.
- **Vagas:** cada posto (foto do Capacity em `esc_posto`) tem a vaga do dono (núcleo dono, nos dias dele) e a de cobertura (outro núcleo, nas folgas do dono).
- **Melhores SRs (pelo faturamento, `v4_melhores_receita_min` = US$ 100 mil da modelo em 30 dias):** antes da equipe fixa, cada vaga das páginas dessas modelos recebe o SR disponível do turno com a maior venda/hora na página (`cap_rep_hora`; sem histórico nela, a venda/hora geral), na ordem da fila: a modelo que mais fatura escolhe primeiro. Página desconsiderada não entra. Posto só de página desconsiderada (ex.: OFTV que ficou com posto próprio numa troca de unidade) não abre vaga: a página vira apoio no posto da página principal da mesma modelo, sem somar carga.
- **Ordem de preenchimento:** os postos vão da modelo que mais fatura para a que menos fatura (soma das páginas da modelo, US$/dia do turno, `tab_statement_interval` com `complete` + `pending`, `v4_receita_dias` = 30; página com menos dias de venda é emulada para 30 dias e a sem venda usa o meio da faixa do tier em `5c_crm.tiers`); dentro da modelo, a página que mais fatura. Página desconsiderada (`esc_pagina_config`, tela Configuração das páginas; no automático, OFTV abaixo de `v4_trivial_receita_min` = US$ 8 mil em 30 dias) tem carga zero e vai para o fim da fila. Em cada vaga: equipe fixa (`esc_vaga_equipe`, como preferência) → outra pessoa do núcleo → buffer do outro núcleo do turno → SR de Overlap que sobrou → remanejamento (o SR do posto de menor faturamento da unidade vai para a página maior) → acúmulo de um 2º posto por um SR, dentro da Política e da capacidade → sobrecarga página a página: a página que sobrou vai para um SR já alocado que faz página da mesma agência (a do Infloww, `fc_pagina_infloww`; fora dele, `5c_pages.pages.client`, com Noah, Independent e Lolly Agency como 5C Company), até `v4_over_capacity_max` (30%) acima da capacidade; ele entra cobrindo (não é dono nem conta para a semana seguinte) e acima de 100% fica marcado com ⚠ (SOBRECARGA) → lacuna. Os postos vêm do Capacity; desde 01/11 a distribuição é fixa (`5c_planning.fc_posto_fixo`, script 28 do Capacity) e só página fora dela segue a regra automática. No fim, em cada página e bloco, o primeiro SR é o REGULAR (sem # no CSV) e os demais, ASSISTENTE (#): o papel não diz quem é dono nem quem cobre.
- **Postos:** vêm do Capacity (`fc_postos` de um `data_calculo` fixo), carregados pelo `ES_10` (gerado por `sql/gerador/gerar_es10.py`), que remonta as equipes mantendo cada SR nas páginas que já fazia.
- **Buffer de plantão:** quem fica sem vaga. Vai para a página 5C Schedule com `-2` no código do CSV.
- **Overlap (dia de treinamento):** o 15º dia de trabalho do ciclo. Cada SR tem o próprio dia: os SRs de cada núcleo se revezam entre as folgas do ciclo do parâmetro `v4_overlap_posicao_ciclo` (5, 6, 7, 16 e 17 pelo `ES_13`), e o OS e os assentos não mudam. O SR vai para a página **5C Schedule** com `-5` no código (ex.: `L149788-5`); se sobrar Overlap e houver lacuna, ele cobre a lacuna. Depois se decide se vira buffer ou treinamento.
- **Dia de transição** (parâmetro `v4_dia_transicao`, dias separados por vírgula; 31/10 e 01/11 pelo `ES_18`): as páginas desse dia são cobertas por quem já está escalado no `scheduleday`, no turno em que está. Só entram SRs; quem vai virar OS, OC ou OM fica de fora.
- **CSV da semana da virada:** antes da virada, repete o que já está postado, para não apagar nada no site. Gere a semana de 26/10 (só 01/11) e depois a de 02/11, que pega o T1 de segunda do plano anterior.
- **Escala da liderança** (`lib/lideranca.php`, `ES_19`): separada da dos SRs.
  - Operational Manager (OM, um por unidade): não segue escala.
  - Operational Coordinator (OC, um por unidade e turno) e Operational Supervisor (OS, um por núcleo): 5x2 no ciclo de 21 dias. O OS segue o núcleo; os OC usam o `deslocamento` de `esc_lideranca` (0, 14 e 7).
  - Operational Assistant (OA, sem unidade): 1 por turno, todo dia (24/7), numa semana fixa (`padrao_semana`, seg a dom). Os 4 OAs fazem 5 turnos cada e o Giovanni (supervisor) cobre 1. Quem faz dois turnos sempre muda para o mais tarde (11 h de descanso).
  - Sem o dia de treinamento separado: o 15º dia cai na posição `v4_lideranca_overlap_posicao` (5 = sábado), a mesma para todos, e nesse dia os 3 do grupo estão no turno.
  - Ausência aprovada em Pedidos e aprovação aparece como "ausente".
  - Entra no mesmo plano dos SRs (`ES_20`): quem trabalha no turno vai para a página 5C Schedule nos blocos do turno e sai no mesmo CSV, com `-8` (OS), `-7` (OC) e `-6` (OA) no código, como o `-2` do Buffer e o `-5` do Overlap. O CSV de cada página tem todos os turnos (T1 a T6) juntos.
- **Folga de emenda:** quem já vem do limite de dias seguidos do modelo (5 no 5x2, pelo `ES_22`), somando a escala postada antes do plano e os dias do plano, folga no dia (tipo `EMENDA` em `esc_plano_folga`). A vaga vai para outra pessoa do núcleo, para buffer ou vira lacuna. Na rotação de 21 dias isso não acontece; acontece na virada, com quem vinha sem folga.
- **Emenda de turno:** quem fecha o turno D às 05:00 do primeiro dia do plano fica fora do turno A desse dia (seriam 16 h seguidas).

## Recálculo e De→Para

- A cada verificação, o sistema tira uma "foto" de cada entrada: Capacity, estrutura e membros, páginas, colaboradores, pedidos aprovados, modelos e trocas de página previstas. O que mudou fica em `esc_entrada_diferenca`.
- Se algo mudou desde a versão atual de uma semana em aberto, ele gera a subversão (v1 → v1.1) e grava o De→Para célula a célula em `esc_plano_diferenca`.
- Ajuste manual feito na grade fica **travado** e o recálculo mantém.
- **Quem está na escala publicada não perde o dia sozinho.** Se o recálculo deixaria a pessoa sem trabalho no turno, ela é mantida e vira sugestão de workload. Se a sugestão for aplicada, o próximo recálculo não traz a pessoa de volta. Trocar de página no mesmo turno não conta como tirar o dia.
- A verificação roda só pelo botão "Verificar mudanças" (Painel e Gerar escala): abrir uma tela não recalcula nada.

## CSV para o site da 5C

- Formato do Schedule Ajustment: 6 linhas (T1 a T6) × 7 colunas (seg a dom), códigos separados por `, `, `#` para assistente. O `$` o site calcula sozinho.
- Na página 5C Schedule, o sufixo diz o que a pessoa faz: `-2` Buffer, `-5` Overlap, `-6` Operational Assistant, `-7` Operational Coordinator, `-8` Operational Supervisor.
- Tabelas: clicar no título da coluna ordena (de novo inverte).
- O T1 de segunda é o fim do turno D de domingo e vem do plano da semana anterior.
- Um arquivo por página, ou todos num .zip (precisa da extensão ZipArchive do PHP).

## O que ainda não está ligado

- **Infrações no mérito:** a tabela `esc_sr_infraction` existe, mas ainda não tem carga; até lá a pena é 0 para todos.
- **Pedido pela Nora:** a fila está pronta (canal NORA), falta o bot chamar o portal.
- **"Não saiu" numa saída de página:** fica registrado e avisa, mas a necessidade continua vindo do Capacity.
- **Postar via API:** não existe API de escrita no `scheduleday`.

## Para remover

Apague a pasta e a linha do `.htaccess`. Os dados ficam no `5c_escalas` (o `ES_99_desfazer.sql` apaga o schema).

## Requisitos

PHP 7.4 ou mais novo, com `pdo_mysql` (e ZipArchive para o .zip). MySQL 8.0.19 ou mais novo (usa `INSERT ... AS n ON DUPLICATE KEY UPDATE`); o servidor .11 está no 9.5.
