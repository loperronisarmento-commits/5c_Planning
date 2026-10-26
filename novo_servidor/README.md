# Headcount e Pages pelo servidor novo (209.159.144.7)

O DBA passou o headcount e as páginas para o servidor novo, atualizados lá de 10 em 10 min (headcount a cada 5 min). Para não mexer em nenhuma das 23 views, procedures e ferramentas que leem `5c_headcount.headcount` e `5c_pages.pages` no .11, o .11 passa a **espelhar** o servidor novo:

```
API MisDev → 209.159.144.7 (5c_headcount.headcount, 5c_pages.paginas)
                 │  tabelas FEDERATED (fed_headcount, fed_paginas)
                 ▼
           .11  sp_sync_*_novo_servidor  (events a cada 10 min)
                 ▼
           .11  5c_headcount.headcount · 5c_pages.pages  (como sempre)
```

## Ordem (Navicat, no .11)

| # | Script | O que faz |
|---|---|---|
| 1 | `NS_01_servidores_e_federadas.sql` | `CREATE SERVER` e as 2 federadas. **Troque `<usuario>`, `<senha>` e `<porta>`** (os mesmos da `5c_wfm_db.teste_creatonomy`; veja com `SHOW CREATE TABLE`). Não salve o arquivo com a senha. |
| 2 | `NS_04_validacao.sql`, bloco A | Mostra o que a primeira carga vai mudar. Hoje: 395 × 395 pessoas, 133 × 133 páginas, 0 novos e 0 saindo. |
| 3 | `NS_02_procedures_sync.sql` | Cria as 2 procedures. No fim, rode os 2 `CALL` comentados. |
| 4 | `NS_04_validacao.sql`, blocos B | Confere o `import_log`, os checksums (iguais nos dois lados), os 4 campos e as views. |
| 5 | `NS_03_events.sql` | Liga os events: headcount às :02/:12/…, páginas às :07/:17/…. |
| 6 | `etl_auto/` | Tira HeadCount e Pages do etl_auto. Veja abaixo. |
| 7 | `NS_04_validacao.sql`, blocos C | Depois de 20 a 30 min: os events rodando e nada mais vindo da API antiga. |

Para voltar atrás: `NS_99_desfazer.sql` e recoloque as 2 linhas no `run_auto.php`.

## Fanvue e históricos (NS_05 a NS_07)

Rode depois do NS_01: os históricos usam os servidores criados nele.

| # | Script | O que faz |
|---|---|---|
| 1 | `NS_05_fanvue_e_historicos.sql` | Servidor e federada do Fanvue (**troque `<usuario>`, `<senha>` e `<porta>`**), federadas dos históricos, as tabelas locais `5c_headcount.headcount_history`, `5c_pages.paginas_history`, o de-para de criadora e o log, e as 3 procedures. |
| 2 | `NS_07_validacao_fanvue_historicos.sql`, bloco A | A federada lê e toda criadora tem de-para. |
| 3 | Os 3 `CALL` no fim do NS_05 | Primeira carga. Hoje o Fanvue traz só as vendas da Ivy Vale depois da última carga do etl_fanvue (~60 a 70 linhas). |
| 4 | NS_07, blocos B | Log, invoices por mês iguais, produção igual ao bruto, históricos completos. |
| 5 | `NS_06_events_fanvue_historicos.sql` | Históricos às :04/:14/…, Fanvue às :05/:15/…. |
| 6 | NS_07, bloco C | Depois de 20 a 30 min. |

**Fanvue.** Grava como o `etl_fanvue` do portal, só para invoices que o .11 ainda não tem:
- `statements_fanvue`: bruto, com Paid At em UTC;
- `statements`: Paid At − 3 h, valores sem sinal, "… FanVue", complete/reverse. A classificação continua vindo do `ev_refresh_statements_class_15min`.

Nada que já existe é alterado. O `etl_fanvue` pode continuar: se receber depois a mesma linha, ignora. Criadora nova no Fanvue entra em `5c_wfm_db.fanvue_criadora_de_para` e depois rode `CALL 5c_wfm_db.sp_sync_fanvue_novo_servidor(0);`. Até lá, as vendas dela ficam como "pendentes" no log. Tipo desconhecido (já apareceu um "Giveaway") entra só no bruto e também fica no log.

**Históricos.** Tabelas novas, só de leitura, com os mesmos ids do servidor novo:
- `headcount_history`: entrada, mudança (com os campos em `changed_fields`), saída e volta de cada pessoa, desde 13/10/2026;
- `paginas_history`: o mesmo por página, com o JSON de antes, desde 21/10/2026.

Exemplos de consulta no NS_07, bloco B5. O `totalTime` muda todo dia para quase todos, então filtre os campos que interessam.

**Avisar o DBA:** apesar do nome, o `paid_at_utc` do `fanvue_data.statements_raw` está em horário de Brasília em todas as linhas. Exemplo: a FVE-20261020-48379 está às 07:47:13 lá e às 10:47:13 UTC no .11. O espelho soma 3 h para gravar o Paid At (UTC). Se o DBA corrigir a coluna, é preciso trocar as duas contas marcadas com "FUSO" na `sp_sync_fanvue_novo_servidor`.

## etl_auto

Em `mis_portal/systems/etl_auto/run_auto.php`, apague estas 2 linhas (o Historic Planner continua):

```php
$steps[] = ['label' => 'HeadCount', ... "$sys/etl_headcount/public/import_stream.php" ...];
$steps[] = ['label' => 'Pages',     ... "$sys/5c_pages/public/import_stream.php" ...];
```

A pasta `etl_auto/` aqui tem `run_auto.php`, `config.php` e `README.md` já ajustados (só comentários e essas 2 linhas). Eles foram feitos a partir da cópia de 03/10/2026; se o seu `run_auto.php` mudou depois disso, apague só as 2 linhas no seu em vez de substituir o arquivo.

As telas manuais do **HeadCount** e do **5C Pages** no portal continuam puxando a API antiga. Se alguém clicar em "Atualizar", os dados voltam para a versão da API antiga até o próximo sync (no máximo 10 min). O ideal é tirar as duas telas do menu.

## Diferenças da API nova (avisar o DBA)

- **Faltam 4 campos no headcount:** `registerDate`, `startContract`, `contract` e `contractExpiring` vêm NULL. Eles são usados pelo `sp_fc_hc_to` (turnover e admissões), pela `vw_rep_headcount` e pelas views `v_reps_sem_turno`, `v_desligamentos_mensais` e `v_desligados`. Até a API mandar esses campos, o sync:
  - mantém o valor que já existe no .11;
  - para quem entrar a partir de agora usa `registerDate` = primeiro dia na API nova, `startContract` = `contractDate` (ou "Not Started"), `contract` = 0 e `contractExpiring` = "-".
  - Quando a API mandar os campos, o valor dela passa a valer sozinho.
- **Páginas:** a API nova só traz as ativas. Página que sai vira `active = 0` aqui e nunca é apagada, igual a hoje.
- **ids:** o headcount do servidor novo tem ids próprios (1 a 400). O sync casa pelo `code` e mantém o id do .11. Nada usa `headcount.id`.
- **raw_json:** o do headcount ganha as chaves novas (`shift`, `workPattern`, `discord`, `privacyId`…) e mantém as antigas.

## Travas

- O remoto voltou vazio (servidor fora, rede): nada muda e o `import_log` fica `failed`.
- O sync apagaria ou inativaria mais de 50% de uma tabela com 20 linhas ou mais: nada é removido, os inserts e updates seguem normalmente, e o motivo fica no `import_log`. É a mesma regra dos ETLs antigos.
- Quem sai do headcount vai para `sync_removed_rows` antes do DELETE, no mesmo formato de antes (o turnover do `sp_fc_hc_to` lê dali).
- Uma execução por vez (`GET_LOCK`), em transação: se der erro, nada fica pela metade.
