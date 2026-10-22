# ETL automática (a cada 15 min)

Roda sozinha, a cada 15 minutos, as ETLs que mantêm o banco atualizado ao
longo do dia — usando **o mesmo código das telas** (cada ETL roda como um
processo filho, sem mudar a lógica delas).

| Grupo | ETLs | Janela |
|---|---|---|
| Cadastros 5C | Historic Planner | foto completa (com espelho: o que sumiu da API sai do banco) |
| Escala | Schedule | o(s) dia(s) entre 1 h atrás e agora (a API só filtra por data) |
| Infloww — vínculos (por agência ativa) | all creators, creators, employees, assigned creators | foto atual |
| Infloww — vendas (por agência ativa) | transações, sales records, reembolsos | **de 1 h atrás até a hora da execução** |

**HeadCount e Pages** não rodam mais aqui: vêm do servidor novo
(209.159.144.7) por tabelas federadas, copiados pelos events
`5c_headcount.ev_sync_headcount_novo_servidor` e
`5c_pages.ev_sync_pages_novo_servidor` a cada 10 min. O histórico fica no
`import_log` de cada schema.

**Repescagem diária:** na primeira execução bem-sucedida entre 3h00 e 3h59,
as vendas do Infloww são buscadas nas **últimas 48 h** — pega o que a API
mostrou com atraso maior que a janela e status que mudaram depois.

Tudo ajustável em `config.php` (`window_minutes`, `catchup_hour`,
`catchup_hours`, grupos ligados, tempo máximo por etapa).

## Prioridade da ETL manual

Toda ETL disparada por uma pessoa (telas das ETLs, "Executar Todos" do
Infloww, atualização automática do Access Audit/Anomalies) **tem
prioridade**:

1. Antes de começar, ela avisa o runner (`lib/auto_coord.php`).
2. O runner mata a etapa em andamento **na hora** — o banco desfaz a
   transação aberta; o que já tinha sido gravado em etapas anteriores fica —,
   apaga arquivo de coleta pela metade, libera a fila do Infloww e encerra o
   ciclo. A manual espera isso (normalmente < 1 s; no máximo 20 s) e segue.
3. A automática não começa um ciclo novo enquanto houver ETL manual rodando
   ou se houve ETL manual nos últimos 5 minutos (`manual_quiet_minutes`).

Também não há sobreposição: se um ciclo ainda estiver rodando quando o
próximo for disparado, o novo é pulado.

## Instalação (no servidor)

1. Copiar a pasta `etl_auto` para `mis_portal\systems\` e substituir os
   arquivos alterados das ETLs (ver lista no zip).
2. Clicar com o botão direito em `etl_auto\instalar_tarefa.bat` →
   **Executar como administrador**. Ele testa `php.exe` e `php-cgi.exe`
   (neste servidor o Windows bloqueia o `php.exe`; o `php-cgi.exe` funciona),
   confere `curl` e `pdo_mysql`, gera `rodar_auto.cmd`, roda um ciclo de
   teste e cria a tarefa **"MIS - ETL automatica"** (a cada 15 min, como
   SYSTEM). Pasta do PHP: `PHP_DIR` no começo do arquivo (padrão
   `C:\WebServer\PHP\`).
3. Detalhes do Windows que o instalador já resolve: usa o mesmo `php.ini` do
   Apache (`-c`), a pasta `ext` com caminho absoluto, e coloca a pasta do PHP
   no `PATH` (a extensão `curl` depende de DLLs que ficam lá — sem isso dá
   "Unable to load dynamic library 'curl'"). Para investigar problemas de
   execução: `diagnostico.bat` (gera `diagnostico.txt`).
4. Acompanhar em `http://5c.techmaps.one/mis_portal/systems/etl_auto/`.

Para remover a tarefa: `remover_tarefa.bat`. Para só pausar: botão
**Pausar** na tela (e-mails em `config.php` → `admin_emails`).

## Tela de status (`index.php`)

Mostra se está rodando agora (e em qual etapa, com o tempo decorrido), se
está pausada, a **contagem regressiva para a próxima execução** (perguntada
ao próprio Agendador com `schtasks /Query`; se não der, estimada pelo último
disparo), a última ETL manual, e os últimos 40 ciclos com o resultado de cada
etapa. Login: a mesma sessão do portal. Atualiza a cada 30 s.

**Rodar agora** (admins): dispara a própria tarefa do Agendador
(`schtasks /Run`) — o ciclo roda como SYSTEM pelo `rodar_auto.cmd`, igual ao
automático. Para testes, roda **mesmo pausada** e mesmo com ETL manual
recente; só não roda por cima de outro ciclo ou de uma ETL manual em
andamento. Fica marcado como "forçado" (com quem disparou) no histórico.
Requer que o Apache rode como uma conta com permissão na tarefa (o serviço
padrão, LocalSystem, tem) e que `exec` não esteja em `disable_functions`.

## Arquivos

```
etl_auto/
├─ run_auto.php          ← o runner (chamado pelo Agendador de Tarefas)
├─ cli_exec.php          ← roda UMA ETL em modo automático (usado pelo runner)
├─ config.php            ← configuração
├─ index.php             ← tela de status
├─ lib/auto_coord.php    ← coordenação automática x manual (usado também pelas ETLs)
├─ instalar_tarefa.bat / remover_tarefa.bat
├─ rodar_auto.cmd       ← gerado pelo instalador (é o que a tarefa chama)
└─ state/                ← estado e logs (bloqueado para a web)
   ├─ logs/auto_AAAA-MM-DD.log   (30 dias)
   ├─ history.json               (últimos 200 ciclos)
   └─ running.json, preempt.json, last_manual.json, last_catchup.txt, paused.flag
```

## O que mudou nas ETLs

- Guards de login (`require_api_auth.php`, `require_api_access.php`,
  `etl_infloww/api/_auth_guard.php`): aceitam execução **só em linha de
  comando** (`php.exe`, ou `php-cgi.exe` sem `GATEWAY_INTERFACE`, que só existe
  quando um servidor web chama o CGI) com `ETL_AUTO_RUN=1` (o runner define).
  Pela web nada muda.
- `import_stream.php` das ETLs 5C e `etl_infloww/api/global_lock.php`:
  avisam o runner antes de começar (prioridade da manual). Se a pasta
  `etl_auto` não existir, funcionam exatamente como antes.
- `etl_infloww/api/infloww_client.php`: aceita janela exata em
  `?fromTime=...&toTime=...` (ISO UTC), usada pelas vendas da automática.
- `etl_infloww/api/send_to_mysql.php`: em modo automático recebe o nome do
  arquivo por `?file=`.
- Nos logs de importação do Infloww, a automática aparece como
  `etl-auto@sistema` / "Execução automática".

## Observações

- Uma etapa interrompida da ETL 5C pode deixar a linha dela em
  `import_log` com status `running` (o processo foi encerrado no meio) —
  não afeta nada; a checagem de idade das fontes só considera `success`.
- As ETLs 5C gravam um arquivo de log por execução em `logs/` — com a
  automática, são ~96 arquivos/dia por ETL. Vale apagar os antigos de vez
  em quando.
