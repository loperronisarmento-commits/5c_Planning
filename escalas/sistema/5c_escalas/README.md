# 5C Escalas (módulo do MIS Portal)

Planejamento de escalas: gera o **rascunho** da semana, recalcula quando algo muda (com De→Para), guarda as decisões tomadas em tela e prepara o CSV para postar no site da 5C.

**Grava só no schema `5c_escalas`.** Planner, escala postada (`scheduleday`), Capacity, headcount e CRM continuam sendo só lidos. A escala oficial continua no site da 5C.

## Antes de instalar

Rode no Navicat, nesta ordem, os scripts de `escalas/sql/`:
1. `ES_01_criar_schema_5c_escalas.sql`: schema e 22 tabelas.
2. `ES_02_carga_inicial.sql`: configuração das pessoas a partir do headcount.
3. `ES_03_permissao_usuario_portal.sql`: `GRANT SELECT, INSERT, UPDATE, DELETE` no `5c_escalas` para o usuário do portal.

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

## Acesso

Tabela `5c_escalas.esc_acesso`. No primeiro acesso, se ela estiver vazia, o módulo copia os aprovados do Schedule Anomalies: admin de lá vira ADMIN aqui e os demais, LEITURA. Depois disso, quem administra é a tela **Administração › Acessos**.

| Perfil | Pode |
|---|---|
| ADMIN | tudo, inclusive acessos, modelos e parâmetros |
| PLANEJAMENTO | gerar, recalcular, ajustar a grade, publicar, cadastros e decisões |
| WFM | aprovar indisponibilidade, cadastros e decisões |
| GESTOR_TIME | ver e registrar ação de correção |
| LEITURA | só ver |

## Telas

| Tela | O que faz | Grava em |
|---|---|---|
| Painel | Rascunhos das semanas, pendências; verifica mudanças a cada 15 min e recalcula | `esc_entrada_*`, `esc_recalculo_evento`, `esc_plano*` |
| Gerar escala | Nova versão, recálculo manual, verificar mudanças, histórico de versões e De→Para | `esc_plano*`, `esc_sugestao`, `esc_ciclo_estado` |
| Grade da escala | Por página, por pessoa e De→Para; ajuste manual de célula (fica travado); publicar; CSV por página ou .zip | `esc_plano_alocacao`, `esc_plano`, `esc_exportacao` |
| Equilíbrio Tertius | % de turnos como regular × alvo, faltas das últimas 4 semanas | não grava |
| Sugestões de troca | Workload (remover turno), Buffer sem página, lacunas; aplicar ou descartar com motivo | `esc_sugestao`, `esc_plano_alocacao` |
| Buffers | Precisa (Capacity) × usados × sem página, por dia e turno; pool | não grava |
| Trocas de página | Lotes do Planner, quem sai e quem entra, CSV e roteiro; marcar "apaguei" e "postei" | `esc_troca_postagem` |
| Saídas de página | Confirmar a saída ou marcar "não saiu" | `esc_saida_checagem` |
| Funil de entrada | Cards do CRM e arquétipos; ajustar previsão e destino | `esc_pagina_previsao` |
| Configuração dos times | Violações com ação de correção; pendências no Planner (fecham sozinhas quando o Planner bate) | `esc_violacao_acao`, `esc_estrutura_pendencia` |
| Colaboradores | Turno fixo, modelo, tipo, trilha, folgas fixas (nova vigência a cada mudança) | `esc_colaborador_config` |
| Modelos de escala | Ciclo de trabalho e folga; o limite de anomalia sai do ciclo | `esc_modelo_escala` |
| Pedidos e aprovação | Fila do WFM (por data, comentário obrigatório para recusar) e lançamento em nome de alguém | `esc_indisponibilidade*` |
| Acessos, Parâmetros, Diagnóstico | Administração | `esc_acesso`, `esc_parametro` |

Toda alteração vai para `esc_log` (quem, quando, antes e depois).

## Como a escala é gerada

1. **Releitura do realizado:** lê as últimas 4 semanas do `scheduleday` e descobre a posição de cada pessoa no ciclo (trilha) e quantos dias seguidos ela já trabalhou. A trilha só é usada se não houver uma fixada em Colaboradores.
2. **Quem trabalha no dia:** o ciclo do modelo, menos as indisponibilidades aprovadas. O turno é fixo: no 3x1 só a folga avança.
3. **Necessidade:** Capacity (`fc_escala_pagina`) por página, dia e bloco. Página dedicada com N reps dá 1 regular e N−1 assistentes. Página compartilhada divide o rep até completar 1. Dias fora do horizonte do Capacity usam o mesmo dia da semana.
4. **Ordem de preenchimento:** party dona → outras parties do time → Tertius regular (quem está mais abaixo do alvo) → Tertius assistente → Buffers do turno → lacuna.
   - Titular ou Tertius de serviço sem página vira assistente na maior página da party ou do time.
   - Buffer sem página vira sugestão de remoção por falta de workload.
5. **Anomalias programadas:** dias seguidos (realizado + plano) acima do máximo do modelo. Para publicar com anomalia, é preciso marcar "estou ciente".

## Estrutura de 01/11/2026 (v4)

Toda semana a partir da virada (parâmetro `v4_virada`, 01/11 às 05:00) é gerada pelo `lib/motor_v4.php`. Antes de gerar, rode os scripts `ES_04`, `ES_05` e `ES_06` (`escalas/sql/`).
- **Rotação:** 3 núcleos por unidade e turno, ciclo de 21 dias (P1 ter/sáb/dom · P2 seg/sex · P3 qua/qui). Todo dia 2 núcleos trabalham.
- **Vagas:** cada posto (foto do Capacity em `esc_posto`) tem a vaga do dono (núcleo dono, nos dias dele) e a de cobertura (outro núcleo, nas folgas do dono).
- **Ordem de preenchimento:** equipe fixa da vaga (`esc_vaga_equipe`, como preferência) → outra pessoa do núcleo → buffer do outro núcleo do turno → lacuna.
- **Buffer de plantão:** quem fica sem vaga. Não vai para o CSV.
- **01/11:** dia de transição, montado com quem está escalado no `scheduleday` nesse dia.
- **CSV:** a semana de 02/11 pega o T1 de segunda do plano da semana de 26/10, que tem só o dia 01/11. Gere as duas semanas.

## Recálculo e De→Para

- A cada verificação, o sistema tira uma "foto" de cada entrada: Capacity, estrutura e membros, páginas, colaboradores, pedidos aprovados, modelos e trocas de página previstas. O que mudou fica em `esc_entrada_diferenca`.
- Se algo mudou desde a versão atual de uma semana em aberto, ele gera a subversão (v1 → v1.1) e grava o De→Para célula a célula em `esc_plano_diferenca`.
- Ajuste manual feito na grade fica **travado** e o recálculo mantém.
- **Quem está na escala publicada não perde o dia sozinho.** Se o recálculo deixaria a pessoa sem trabalho no turno, ela é mantida e vira sugestão de workload. Se a sugestão for aplicada, o próximo recálculo não traz a pessoa de volta. Trocar de página no mesmo turno não conta como tirar o dia.
- A verificação roda ao abrir o Painel (no máximo a cada 15 min) e no botão "Verificar mudanças agora" em Gerar escala.

## CSV para o site da 5C

- Formato do Schedule Ajustment: 6 linhas (T1 a T6) × 7 colunas (seg a dom), códigos separados por `, `, `#` para assistente. O `$` o site calcula sozinho.
- O T1 de segunda é o fim do turno D de domingo e vem do plano da semana anterior.
- Um arquivo por página, ou todos num .zip (precisa da extensão ZipArchive do PHP).

## O que ainda não está ligado

- **Desempenho no mérito dos Tertius:** o alvo é o mesmo para todos (parâmetro `merito_alvo_padrao`, 50% se não existir).
- **Pedido pela Nora:** a fila está pronta (canal NORA), falta o bot chamar o portal.
- **"Não saiu" numa saída de página:** fica registrado e avisa, mas a necessidade continua vindo do Capacity.
- **Postar via API:** não existe API de escrita no `scheduleday`.

## Para remover

Apague a pasta e a linha do `.htaccess`. Os dados ficam no `5c_escalas` (o `ES_99_desfazer.sql` apaga o schema).

## Requisitos

PHP 7.4 ou mais novo, com `pdo_mysql` (e ZipArchive para o .zip). MySQL 8.0.19 ou mais novo (usa `INSERT ... AS n ON DUPLICATE KEY UPDATE`); o servidor .11 está no 9.5.
