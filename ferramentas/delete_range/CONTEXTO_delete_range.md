# Delete Range em lote: contexto do projeto

Atualizado em 01/11/2026.

## Objetivo

Para repostar as escalas geradas no site da 5C, é preciso primeiro apagar as escalas que já estão postadas. O site não tem API de escrita, e o **Delete Range** apaga só uma página (Model) por vez. Com dezenas de páginas por semana, isso vira muito trabalho manual.

A solução é um **favorito do Chrome (bookmarklet)**. Você clica nele na tela do site e ele abre um painel. No painel você informa o range de datas e marca as páginas. Depois ele repete o Delete Range sozinho em cada página e espera a mensagem de sucesso antes de passar para a próxima.

Esse é o passo "Delete Range" do roteiro de postagem do 5C Planning (`escalas/ES_00_especificacao.md`, `pages/grade.php`, `pages/trocas.php`): ele é feito em todas as páginas antes de colar qualquer CSV.

## Arquivos

| Arquivo | O que é |
|---|---|
| `delete_range.js` | Código-fonte do favorito (painel + automação). |
| `build_bookmarklet.js` | Gera a página de instalação a partir do `delete_range.js`. Uso: `node build_bookmarklet.js`. |
| `instalar_delete_range.html` | Página com o botão para arrastar para a barra de favoritos. |
| `CONTEXTO_delete_range.md` | Este documento. |

Toda vez que mudar o `delete_range.js`, rode o `build_bookmarklet.js` e reinstale o favorito: apague o antigo e arraste o novo.

## O site (levantado em 01/11/2026)

- **URL:** `https://5ccompany.com/WebPortal`. O site é de página única: o menu troca o conteúdo sem mudar a URL.
- **Caminho no menu:** SHIFTS AND SCHEDULE > **Schedule Tools**, seção "Schedule Ajustment".
- Ao entrar no portal pode aparecer o popup "Tickets pending validation". É só clicar em "See later".
- Não há iframe, e a página tem jQuery.

### Elementos da seção "Schedule Ajustment"

| Campo | id | Tipo |
|---|---|---|
| Code CSV | `schedule-csv-text-area` | textarea |
| Model | `select-model-schedule` | `<select>` comum (sem select2), 121 páginas. value = id da página. A 1ª opção vazia é "Select a Model For Schedule CSV". |
| Date Schedule | `schedule-set-date` | input de texto, formato `dd/mm/aaaa` |
| Date End | `schedule-set-date-end` | input de texto, formato `dd/mm/aaaa` |
| Load Previus CSV | `loadPreviusCSV` | `GetCSVScheduleSelectDate(this, false)` |
| Load CSV (No Absences) | `loadCSVNoAbsences` | `GetCSVScheduleSelectDate(this, true)` |
| Delete Range | `deleteRange` | `DeleteRangeSchedule(this)` |
| Save Schedule CSV | (sem id) | `submitScheduleCSV(this)` |

Exemplos de ids: 3 = 5C Schedule, 92 = Abg, 11 = Aja, 370 = Aja Free.

### Como o Delete Range funciona por dentro

`DeleteRangeSchedule`:
1. Valida os campos. Se faltar algo, mostra um popup "info" com um destes títulos:
   - "Date Unselected"
   - "Model Unselected"
   - "Invalid Range" (quando a data inicial é depois da final)
2. Chama `confirmScheduleDelete(modelId, ...)`, que mostra a confirmação:
   - **Model 3 (5C Schedule):** popup "Delete Model 3" com os botões Cancel, Delete Specific Reason e **Delete All Reasons**. "Delete All Reasons" usa `reasonId = null`.
   - **Os outros Models:** popup "Are you sure?" com os botões Cancel e **OK**, também com `reasonId = null`.
3. Envia este POST via `$.ajax`:
   ```
   POST /Scheduler/DeleteScheduleForRange
   StartDate=dd/mm/aaaa  EndDate=dd/mm/aaaa  PageId=<id>  ReasonId=null
   ```
4. Mostra o resultado:
   - Se der certo: `swal("Success", resposta, "success")`. O texto é "All schedules in the range that were not reported are deleted".
   - Se der erro: `swal("Error", mensagem, "error")`, com a mensagem tirada de `xhr.responseJSON.message`. Um exemplo é "Your request is being processed. This will just take a moment...".

Só as escalas **não reportadas** são apagadas.

### Popups

O site usa **SweetAlert v2** (a função `swal`, e não o `Swal` do SweetAlert2). O popup aberto fica em `.swal-overlay.swal-overlay--show-modal .swal-modal`. Dentro dele:
- o título está em `.swal-title`;
- o texto está em `.swal-text`;
- os botões têm a classe `.swal-button`.

## O que o favorito faz

1. Confere se a tela Schedule Tools está aberta, ou seja, se o `select-model-schedule` existe. Se não estiver, avisa.
2. Abre um painel fixo no canto superior direito com:
   - os campos De e Até;
   - a lista das páginas, montada a partir do próprio select, com filtro, links "todas" e "nenhuma" e o contador de marcadas;
   - o botão **Apagar range**, o botão **Parar depois da página atual** e um log.
3. Valida o formato das datas e se o fim não é antes do início, e pede uma confirmação final com a lista de páginas.
4. Para cada página marcada:
   1. Fecha qualquer popup que esteja aberto.
   2. Seleciona o Model pelo id e preenche as duas datas, disparando `input` e `change`.
   3. Clica em `#deleteRange`.
   4. Espera o popup de confirmação (até 10 s). Se for "Delete All Reasons", clica nele; se for "Are you sure?", clica em OK. Se aparecer outro popup, como "Invalid Range", registra como falha.
   5. Espera um popup com o título **Success** ou **Error** (até 180 s).
   6. Se vier Success, registra ✔ e segue para a próxima página.
   7. Se vier Error, clica OK, espera 8 s e repete a página inteira, até 5 tentativas.
   8. Pausa 1,5 s antes da próxima página.
5. No fim, mostra quantas páginas deram certo e quais falharam. O log também aparece no console com o prefixo `[DeleteRange]`.
6. Guarda as últimas datas e as páginas marcadas no `localStorage` do site, na chave `drBatchLast`.

As configurações ficam no objeto `CFG`, no topo do `delete_range.js`:
- `waitResultMs`: 180000
- `retryWaitMs`: 8000
- `maxRetries`: 5
- `pauseBetweenMs`: 1500

### Por que clicar na tela em vez de chamar o endpoint direto

O favorito clica nos botões do próprio site. Assim ele segue o mesmo caminho que você faz à mão, incluindo as validações e as mensagens do site, como foi pedido. Chamar `POST /Scheduler/DeleteScheduleForRange` direto seria mais rápido. É uma alternativa possível, mas pula as confirmações do site.

## Teste feito (01/11/2026)

O teste rodou na tela Schedule Tools real, **sem apagar nada**:
- O `jQuery.ajax` foi trocado por um falso só para `DeleteScheduleForRange`, que nunca chegou ao servidor.
- O `confirm()` foi aceito automaticamente.
- Range: 02/11/2026 a 08/11/2026. Páginas: 5C Schedule (3), Abg (92) e Aja (11).
- Na primeira chamada da Abg, o servidor falso devolveu o erro "Your request is being processed".

Resultado: 3 ok, 0 falhas.
- O 5C Schedule passou pelo "Delete All Reasons".
- Abg e Aja passaram pelo "OK".
- A Abg tentou de novo após 8 s e deu certo.
- Os dados enviados foram `{StartDate:"02/11/2026", EndDate:"08/11/2026", PageId:"<id>", ReasonId:null}`.

Depois do teste, o ajax, o confirm, o painel e o `localStorage` foram restaurados.

**Ainda não foi feita nenhuma exclusão real.** Na primeira vez, rode com uma página só e confira no site.

## Limitações e cuidados

- O favorito depende dos ids e textos do site (`select-model-schedule`, `schedule-set-date`, `schedule-set-date-end`, `deleteRange`, os títulos "Success" e "Error", os botões "Delete All Reasons" e "OK"). Se o site mudar, é preciso ajustar o `delete_range.js`.
- Ele apaga sempre **todas as razões** (`ReasonId = null`). O "Delete Specific Reason" não é usado.
- Não dá para desfazer: o próprio site avisa "Once Deleted, you will not be able to recover these days!".
- O "Parar" só interrompe entre uma página e outra, nunca no meio de uma exclusão.
- Para que o favorito funcione, a aba precisa estar aberta e com sessão ativa no WebPortal.
- Na semana da virada, o roteiro do 5C Planning manda fazer o Delete Range a partir do dia seguinte à virada e apagar à mão só o T6 do dia da virada. O favorito não faz essa parte manual.

## Próximos passos possíveis

- Ler, do sistema do 5C Planning, a lista de páginas que estão "no site" na semana e marcar só essas automaticamente.
- Depois do Delete Range, colar o CSV de cada página e clicar em Save Schedule CSV, com confirmação humana.
- Registrar "Apaguei" e "Postei" no sistema de escalas.
