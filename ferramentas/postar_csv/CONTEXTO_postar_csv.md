# Postar CSV em lote: contexto

Atualizado em 01/11/2026. É o par do **Delete Range em lote** (`ferramentas/delete_range/`): o mesmo favorito do Chrome, mas para colar o CSV de cada página e clicar em **Save Schedule CSV**.

## Fluxo
1. **No 5C Escalas.** Abra a Grade e clique em **Pacote para postar em lote (.json)** (`?p=grade&baixar=lote`).
   - O arquivo é sempre o da **última versão publicada** da semana: se a Grade aberta for de outra versão, o link redireciona.
   - O botão só aparece na versão publicada.
2. **Delete Range.** Faça o Delete Range nas páginas que já têm escala na semana, com o favorito Delete Range em lote.
3. **No WebPortal.** Abra Shifts and Schedule › Schedule Tools e clique no favorito **Postar CSV em lote**.
   1. Escolha o .json, ou cole o conteúdo dele.
   2. Já vêm marcadas as páginas "a fazer". As "sem mudança" (CSV igual ao da versão já postada) e as "já postada" ficam desmarcadas.
   3. Confira o Date Schedule e clique em Postar CSV.
4. **De volta na Grade.** Marque "Postei".

## O pacote (.json)
`{semana, versao, publicado_em, treino, date_schedule (dd/mm/aaaa), paginas: [{id, nome, estado, csv}]}`.
- `id` é o Model do site, igual ao `page_id` do plano.
- O CSV é o mesmo dos cartões da Grade: `escCsvT1T6`, 6 linhas (T1 a T6) × 7 dias.
- O download fica registrado em `esc_exportacao`.

## O site (levantado em 01/11/2026)
- **Botão.** `Save Schedule CSV` chama `submitScheduleCSV(this)`, que valida o Date Schedule e o Model e chama `auxSubmitSchedule()`.
- **Requisição.** `POST /Scheduler/InsertNewScheduleEntry` com `inputCSV`, `StartWeek` (o Date Schedule) e `ModelId`. **O Date End não é usado.**
  - Não há popup de confirmação.
  - Se der certo: `swal("Success", resposta.message)`, depois um GET em `/Scheduler/GetScheduleWeekForCleanTable` para redesenhar a tabela.
  - Se der erro: `swal("Error", xhr.responseText, "error")`.
- **Leitura do que está postado.** `POST /Scheduler/ScheduleDay2CSV` com `ModelId`, `StartDate`, `EndDate` e `ExcludeAbsences` só lê e devolve o CSV com as linhas separadas por `||`. É o mesmo endpoint do botão Load Previus CSV.
- **Assistente.** No mesmo bloco, o site marca como assistente (`#`) quem vem depois do primeiro código, mesmo sem `#` no CSV. Isso bate com a regra do sistema: o primeiro é o REGULAR e os outros são ASSISTENTE.

## O que o favorito faz
Para cada página marcada:
1. Lê no site o T6 de domingo real (`ScheduleDay2CSV` da semana anterior, linha T6, coluna de domingo) e põe essas pessoas no T1 de segunda do CSV, o regular primeiro e sem o `$`.
   - O T1 de segunda fecha o turno D que começou no T6 de domingo, e o T6 pode ter mudado à mão depois da geração.
   - Se a leitura falhar, fica o T1 da Grade e o log avisa.
   - O CSV vai sempre completo. O T1 de amanhã, que o Delete Range não apaga, o site descarta sozinho quando vem duplicado.
2. Fecha qualquer popup aberto.
3. Seleciona o Model, preenche o Date Schedule e cola o CSV no `schedule-csv-text-area`.
4. Clica em Save Schedule CSV.
5. Espera o Success ou o Error, por até 180 s.
6. Trata o resultado:
   - **Error de "being processed":** espera 8 s e repete, até 5 vezes.
   - **Outro Error:** para naquela página. Não repete, porque o site pode ter gravado parte da escala.
   - **Popup de erro ao recarregar a tabela depois do Success:** registra só como aviso.

O favorito guarda no `localStorage` do site (chave `pcBatchFeitas`, por semana e versão) as páginas que já postou. Se parar no meio, é só carregar o mesmo arquivo e continuar.

Depois de mudar o `postar_csv.js`, rode `node build_bookmarklet.js` e reinstale o favorito.

## Primeira postagem real (01/11/2026)
- **O que foi postado:** semana de 02 a 08/11, versão v30 (plano 92), 103 páginas, Date Schedule 02/11/2026.
- **Antes:** as 103 páginas estavam vazias na semana, então não houve Delete Range.
- **Conferência:** a primeira página (Abg) foi conferida pelo `ScheduleDay2CSV` e ficou idêntica à Grade, a menos do `#` automático.
- **Como foi feita:** os CSVs saíram da Grade publicada no portal. O pacote .json ainda não estava no servidor, porque é da 1.18.5.
- **Conferência final, página a página, pelo `ScheduleDay2CSV`:** 103 de 103 postadas, sem falha, e 101 idênticas à Grade.
  - O `$` que o site acrescenta (SR em mais de uma página no mesmo bloco) e o `#` foram ignorados na comparação.
  - **Nicole Doshi (64) e Rocket Reyna OFTV (465)** ficaram com as pessoas certas, mas 24 e 35 células sem regular. O CSV punha o assistente antes do regular, e o site marca como assistente todo código depois do primeiro.
  - Isso foi corrigido no `escCsvT1T6` (1.18.5): o regular vai primeiro.
- **Refeito em 01/11:** as duas páginas passaram por Delete Range de 02 a 08/11 e foram repostadas com o regular primeiro. Agora todas as células estão certas, menos uma.
  - **O T1 de 02/11 da Nicole Doshi.** O Delete Range não apaga turno protegido ("This Shift dont delete: T1 Day: 02/11/2026"). O D422040# ficou e o I122095 entrou depois dele como assistente. Essa célula precisa de ajuste à mão no 5C Schedule.
- **Lição:** a primeira célula do CSV define o regular. Turno protegido (o T1 de amanhã) não sai no Delete Range, então a postagem precisa evitar duplicar quem ficou lá.
