# 5C Escalas — versão de teste (somente leitura)

Primeira versão do 5C Escalas no MIS Portal, para testar com dados reais.
**Não grava nada no banco.** Só lê o Planner, a escala postada, o Capacity, o headcount e o CRM.

## O que dá para testar

| Tela | O que mostra | De onde lê |
|---|---|---|
| Painel | Parties fora da regra, trocas previstas, saídas recentes, buffers do Capacity | Planner, `fc_buffer_diario`, `fc_pagina_eventos` |
| Trocas de página | Lotes de troca de party, quem sai e quem entra em cada página, roteiro de apagar e CSV pronto para o Schedule Ajustment | `historic_page_planner`, `scheduleday`, `vw_rep_estrutura_atual`, `headcount_turno` |
| Saídas de página | Saídas detectadas e o efeito em reps/h e pessoas | `fc_pagina_eventos`, `fc_impacto_paginas` |
| Funil de entrada | Cards do CRM e arquétipos (tier × perfil) | `5c_crm`, `tab_intraday_base`, `fc_fans_pagina_hora` |
| Configuração dos times | Violações (1 Primaris + 2 Secundus, página ativa) e formação por time com código e Telegram | `vw_rep_estrutura_atual`, `vw_pagina`, `vw_rep`, `headcount_turno` |
| Diagnóstico | Se o usuário do banco lê cada tabela e se a sessão está em READ ONLY | todas acima |

As outras telas do rascunho aparecem no menu como "Em breve". Anomalias e Auditoria de acesso apontam para os módulos que já existem.

## Instalação

1. Copie a pasta `5c_escalas/` para dentro de `mis_portal/systems/`, ao lado de `5c_schedule_anomalies/`:

   ```
   [DocumentRoot]/
   ├─ .htaccess
   └─ mis_portal/
      ├─ api/                ← helpers/ e config/ da plataforma (já existem)
      └─ systems/
         ├─ 5c_schedule_anomalies/
         └─ 5c_escalas/      ← esta pasta
   ```

2. No final do `.htaccess` da raiz do domínio (a pasta que contém `mis_portal/`), adicione:

   ```apache
   # 5C ESCALAS (teste, somente leitura)
   RewriteRule ^escalas/?$ mis_portal/systems/5c_escalas/index.php [L,QSA]
   ```

3. Garanta que o Apache consegue escrever em `5c_escalas/logs/` (o `app.log` é criado sozinho).

4. Abra `/escalas` logado no portal e vá primeiro em **Administração › Diagnóstico**.

Não precisa de `.env` novo: o módulo usa o mesmo `.env` da plataforma, pelo `env()` de `mis_portal/api/config/env.php` (`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`). Nenhuma credencial fica nesta pasta.

## Quem acessa

Nesta fase valem **os mesmos usuários liberados no Schedule Anomalies** (`5c_wfm_mis_web.mis_module_access_schedule_anomalies`), para não criar tabela nova. Para mudar, edite `ESC_TABELA_ACESSO` em `config/config.php`.

## Como o "somente leitura" é garantido

1. **Conexão própria em READ ONLY.** `lib/db.php` abre uma conexão nova (não persistente) com as variáveis do `.env` e executa `SET SESSION TRANSACTION READ ONLY`. O MySQL recusa qualquer INSERT, UPDATE, DELETE ou DDL nessa conexão. Não usa o `$pdo` compartilhado, para a trava não vazar para outros módulos.
2. **Filtro de SQL.** Toda consulta passa por `escConferirSomenteLeitura()`: só `SELECT`/`WITH`, sem `;`, sem `CALL`, `INTO`, `INSERT`, `UPDATE` etc.
3. **Sem escrita no código.** Botões de ação (confirmar saída, aplicar, aprovar) aparecem desligados.
4. **Limite de 30 s por consulta** (`max_execution_time`), para não pesar no servidor.

O Diagnóstico mostra a sessão como **READ ONLY** e o filtro como **Ativo**. Se aparecer diferente, não use e me avise.

O único arquivo gravado é o log em `logs/app.log`, no disco do servidor.

## Se o Diagnóstico mostrar "sem acesso · erro 1142"

O usuário do banco do portal não tem SELECT naquela tabela. É preciso um `GRANT SELECT` para esse schema, que é uma mudança no banco e fica a critério de vocês. A tela que depende dela mostra o erro e as outras continuam funcionando.

## Trocas de página: regras desta versão

- O corte é o bloco que começa no horário marcado no Planner (20:59 → T6 das 21h; 12:59 → T4 das 13h).
- Em cada bloco postado a partir do corte:
  - **fica** quem é Buffer, quem já é da party nova e quem é do time novo;
  - **entra** quem é titular da party nova e já está escalado no mesmo bloco, em qualquer página da party;
  - **sai** quem é de outro time, **só se** alguém da party nova entrou no bloco. Se não houver substituto, a pessoa continua e o bloco aparece em "Sem substituto".
- CSV no formato do Schedule Ajustment: 6 linhas (T1 a T6) × 7 colunas (seg a dom), `", "` entre códigos e `#` para assistente. O `$` o site calcula sozinho.
- Roteiro: Delete Range do dia seguinte ao corte até o fim da escala postada, apagar à mão os blocos do dia do corte a partir dele, e postar com Date Schedule no dia do corte.
- Para lotes já aplicados (escala já refeita à mão), a tela serve de conferência: o ideal é "0 blocos que mudam".

## Para remover

Apague a pasta `5c_escalas/` e a linha do `.htaccess`. Nada no banco precisa ser desfeito.

## Requisitos

PHP 7.4 ou mais novo, com `pdo_mysql`. Mesmo Apache e mesma sessão do portal.
