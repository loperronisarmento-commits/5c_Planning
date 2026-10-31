# 5C Escalas · Revisão de UX (item 0)

**Data:** 29/10/2026 · **Versão avaliada:** v1.8.0 (24 telas, ~5.900 linhas de PHP, CSS e JS)
**Método:** leitura de todas as telas, do layout e dos scripts, guiada pelas 10 heurísticas de Nielsen e pela WCAG 2.1 AA. O contraste foi calculado cor a cor a partir do CSS. A evidência de cada achado é o arquivo e a linha (`arquivo:linha`).
**Status:** aguardando aprovação. Nada foi alterado.

---

## 1. Resumo

| Severidade | Qtd. | Exemplos |
|---|---|---|
| **Crítica** | 6 | "Gerar nova versão" apaga os ajustes travados sem avisar; aplicar saída de novo / duplo clique; erro em formulário perde tudo o que foi digitado; Configuração dos times continua aceitando ações depois de 31/10 |
| **Alta** | 27 | Painel recalcula ao abrir; publicar sem confirmação; contraste de bordas e textos secundários; modal da grade sem foco; menu no celular; cargos antigos na tela |
| **Média** | 33 | Rótulos faltando, jargão técnico, alvos de toque, estados vazios, nomes do menu inconsistentes |
| **Baixa** | 9 | Grafia, glifos sem texto alternativo, placeholders |
| **Total** | **75** | (110 achados brutos das 3 frentes; os repetidos foram juntados) |

**Os 5 problemas que mais custam hoje**
1. **Trabalho perdido:** "Gerar nova versão" (o botão primário) descarta os ajustes manuais travados, e qualquer erro de formulário apaga o que foi digitado.
2. **Ação pesada sem proteção:** aplicar saída, publicar, recalcular e verificar não têm confirmação com resumo, nem progresso, nem bloqueio de duplo clique. O "Duplicate entry" do Capacity em 29/10 veio daí.
3. **A tela muda por baixo do usuário:** abrir o Painel dispara uma verificação que recalcula rascunhos e cria versão nova enquanto alguém edita a grade.
4. **Estrutura antiga misturada com a nova:** Primaris/Secundus/Tertius, parties e times aparecem em 7 telas e no motor. Configuração dos times, Buffers e Funil continuam operáveis depois de 31/10 21:00.
5. **Acessibilidade e celular:** bordas de campo quase invisíveis (1,2:1), texto secundário em 3,8:1, ordenação e modal só com mouse, e no celular o menu de 25 links empilha antes do conteúdo.

---

## 2. Achados críticos e altos

Esforço: **P** = horas · **M** = 1–2 dias · **G** = 3+ dias. O ID entre parênteses é o da frente que encontrou (P = Planejamento, C = Cadastros/Páginas, G = Global).

| ID | Tela / fluxo | Problema | Heurística / critério | Sev. | Evidência | Recomendação | Esf. |
|---|---|---|---|---|---|---|---|
| UX-01 | Gerar escala | "Gerar nova versão" é o botão primário e descarta os ajustes travados do rascunho; só "Recalcular" os mantém | N5 prevenção de erros | **Crítica** | `gerar.php:167`, `motor.php:669` (`if ($modo === 'RECALCULO' && $base)`) | Com rascunho existente, "Recalcular" passa a ser o primário; "Gerar do zero" pede confirmação ("N ajustes manuais serão perdidos") | P |
| UX-02 | Global (todo POST) | Formulário com erro volta com `?erro=` e perde o que foi digitado e o contexto (modal da célula, aba, filtros `dias/q/editar/dia`) | N9 recuperação · WCAG 3.3.1 | **Crítica** | `index.php:128` (só preserva `semana, plano, time, turno, status, corte`) | Mensagem e POST em "flash" na sessão: reabrir o formulário preenchido, com o erro no campo | M |
| UX-03 | Saídas · aplicar | "Confirmar e aplicar" continua disponível numa saída já aplicada (recalcula Capacity e escala de novo) | N5 | **Crítica** | `saidas.php:281` | Esconder quando já aplicada; mostrar "Aplicada em dd/mm" + "Comparar" | P |
| UX-04 | Saídas · aplicar (linha e topo) | Ação longa e irreversível sem progresso e sem bloquear o botão (a trava no servidor evita o recálculo duplo, mas o usuário não sabe o que está acontecendo) | N1 visibilidade · N5 | **Crítica** | `saidas.php:284` (`onclick="return confirm(...)"`) | Padrão global de ação longa (ver §6): botão desabilitado, "Aplicando… pode levar alguns minutos", `aria-busy` | P |
| UX-05 | Configuração dos times | A tela é da estrutura antiga e continua aceitando pendências com data depois de 31/10 21:00, sem aviso | N1 · N5 | **Crítica** | `times.php:165` ("cada party com 1 Primaris e 2 Secundus") | Faixa "Estrutura antiga: vale até 31/10 21:00"; somente leitura depois; sai do menu (grupo "Legado") | P |
| UX-06 | Indisponibilidade · decisão | Todas as datas vêm marcadas: "Registrar decisão" sem mexer aprova tudo, e recusar é desmarcar uma a uma | N5 · N1 | **Crítica** | `aprovar.php:147` (`'checked'` para PENDENTE) | Botões explícitos "Aprovar marcadas" / "Recusar todas" + resumo "X aprovadas / Y recusadas" antes de enviar | M |
| UX-07 | Painel | Abrir o Painel (GET) roda a verificação e recalcula rascunhos (até 180 s, no máximo a cada 15 min): a tela fica lenta e a versão muda por baixo de quem edita | N1 · N3 controle · desempenho | Alta | `painel.php:37` (`escMotorVerificar(null)`) | Tirar do GET: agendar (cron) ou botão "Verificar mudanças"; no Painel, só o aviso "entradas mudaram desde vX · recalcular?" | M |
| UX-08 | Grade · publicar | Publicar sem confirmação nem resumo (lacunas, anomalias, postos vazios); o "estou ciente" só é cobrado pelo servidor | N5 | Alta | `grade.php:83` | Diálogo com o resumo; botão habilitado só com "ciente" quando houver anomalia | P |
| UX-09 | Gerar / Grade / Equilíbrio | Recalcular, Verificar, Criar rascunho, Recalcular mérito e Publicar: sem progresso nem bloqueio de duplo clique (pode gerar 2 subversões) | N1 · N5 | Alta | `gerar.php:170`, `tertius.php:118` (só "Gerar" troca o rótulo) | Mesmo padrão global de ação longa | P |
| UX-10 | Grade · ajustar célula | Só valida o formato do código: não avisa de folga, indisponibilidade, outro turno, a mesma pessoa em 2 páginas no bloco, nem 12 h/52 h (regras que a tela Horas já tem) | N5 | Alta | `grade.php:43` | Reusar as checagens de Horas e mostrar alerta no campo ("salvar mesmo assim") | M |
| UX-11 | Grade · ajustar célula | Cada clique na célula recarrega a página inteira (e monta o CSV de todas as páginas) só para abrir o modal | Desempenho percebido · N7 | Alta | `grade.php:294`, `:551` | Abrir o modal por `fetch` (parcial); montar a postagem só na aba "Postar" | G |
| UX-12 | Grade · modal | O modal não leva o foco para dentro, não prende o Tab, o fundo segue navegável e o foco não volta à célula | WCAG 2.4.3 · 2.1.2 | Alta | `grade.php:398` (`role="dialog"` sem gestão de foco) | `<dialog>` nativo (ou `inert` no fundo), foco no 1º campo e retorno à célula | M |
| UX-13 | Grade · versão substituída | Editar uma versão substituída dá erro mandando "Criar rascunho a partir desta" (botão que só existe na publicada); a tag usa o mesmo amarelo do rascunho | N9 · N1 | Alta | `grade.php:25`, `:303` | Faixa "Substituída pela vX → abrir"; cor própria por status | P |
| UX-14 | Escala da liderança | "Baixar CSV" (primário) gera um CSV próprio a partir do rodízio, sem os ajustes da grade; a liderança já sai no CSV da 5C Schedule na Grade: duas fontes para postar | N4 consistência · N5 | Alta | `lideranca.php:6` × `grade.php:579` | Ler do plano; o CSV vira "conferência" ou some, com link para a Grade | M |
| UX-15 | Buffers | Tela da estrutura antiga ("cobre o que sobra depois dos times"); a fonte difere da usada em Horas e Análise, e os números divergem | N4 · N2 | Alta | `buffers.php:22` × `horas.php:157` | Unificar a fonte e refazer para buffer de plantão por unidade e turno, ou fundir na Análise | M |
| UX-16 | Painel, Gerar, Sugestões, Trocas, Funil, Grade (por pessoa) | Cargos e estrutura antigos na tela (Primaris, Secundus, Tertius, party, "sem estrutura") | N2 linguagem do usuário · N4 | Alta | `painel.php:85`, `gerar.php:193`, `trocas.php:380`, `funil.php:129`, `grade.php:461` | Trocar por OM/OC/OS/OA/SR n, unidade, turno e núcleo; o que for só da estrutura antiga vai para "Legado" | M |
| UX-17 | Saídas · confirmação | O `confirm()` é genérico (sem página, data, postos e SRs afetados) e não há desfazer | N5 · N3 | Alta | `saidas.php:177` | Prévia (simulação) com contagens e semanas afetadas antes de confirmar | G |
| UX-18 | Saídas · linha | "Confirmar e aplicar" (rosa) fica colado em "Confirmar" (leve): é fácil clicar errado | N5 · N8 | Alta | `saidas.php:280–284` | Tirar "aplicar" da linha (menu "Mais" ou só no lote/topo) | M |
| UX-19 | Saídas | "Desfazer" só volta a checagem; postos e escala continuam aplicados | N2 · N4 | Alta | `saidas.php:287` | Renomear para "Voltar para a conferir" e avisar que a aplicação não é revertida | P |
| UX-20 | Trocas | "Sem substituto" e "lacuna(s)" pedem decisão, mas não dizem os blocos nem levam a lugar nenhum (beco sem saída) | N1 · N3 | Alta | `trocas.php:415` | Listar dia e bloco, com link para a célula na Grade | M |
| UX-21 | Trocas · postar | Não avisa se o CSV mudou depois de "postado" (o hash é gravado, mas não é lido) | N1 · N5 | Alta | `trocas.php:285` | Comparar o hash e mostrar a tag "mudou: postar de novo" (como na Grade) | P |
| UX-22 | Funil | Probabilidade ambígua: "1" (querendo 1%) vira 100% | N5 · WCAG 3.3.2 | Alta | `funil.php:27` | Sempre em %: `type=number min=0 max=100` com sufixo "%" | P |
| UX-23 | Funil | Destino ainda é escolhido por party (estrutura antiga) | N2 · N4 | Alta | `funil.php:129` | Destino = unidade · turno (· núcleo) | M |
| UX-24 | Colaboradores · trocar núcleo | O select de núcleo não tem opção vazia: colaborador novo cai calado em "U1 · A · 1" | N5 | Alta | `colaboradores.php:186` | 1ª opção "Escolha o núcleo…" (value vazio) | P |
| UX-25 | Colaboradores · trocar núcleo | Fecha as vagas fixas sem confirmar e sem mostrar "de → para" | N5 · N1 | Alta | `colaboradores.php:207` | Resumo "U1·A·2 → U2·C·1 · fecha N vagas a partir de dd/mm" e confirmação | M |
| UX-26 | Modelos | Editar o ciclo de um modelo em uso muda o ciclo de todo mundo sem aviso; desmarcar "ativo" pula a checagem que o botão Desativar faz | N5 · N4 | Alta | `modelos.php:61` | Aviso "afeta N pessoas · duplicar em vez de editar?"; mesma regra do Desativar | M |
| UX-27 | Indisponibilidade | "Cancelar pedido" fica ao lado da decisão, com o mesmo peso e sem confirmação (irreversível) | N5 | Alta | `aprovar.php:153` | Confirmação + estilo de ação perigosa, separado da decisão | P |
| UX-28 | Global · contraste | Bordas de input, select, botão e chip quase invisíveis | WCAG 1.4.11 (3:1) | Alta | `escalas.css:144` · 1,21:1 | Token `--borda-campo` ≥ 3:1 só para controles | P |
| UX-29 | Global · contraste | `--texto-3` (#6b6f7a) em texto: link da versão, "▸ ver SRs", eixos dos gráficos, itens desligados | WCAG 1.4.3 | Alta | `escalas.css:13` · 3,76–3,95:1 | Clarear para ≥ #8a8e99 (≈5:1) ou usar `--texto-2` | P |
| UX-30 | Global · tema | Sem `color-scheme: dark`: o ícone do calendário some, e checkbox, lista do select e barra de rolagem saem claros | WCAG 1.4.11 · consistência | Alta | `escalas.css:2` (0 ocorrências) | `color-scheme` por tema | P |
| UX-31 | Global · tabelas | Ordenação só por clique no `<th>` (sem foco nem tecla) | WCAG 2.1.1 · 4.1.2 | Alta | `escalas.js:146–148` | `<button>` dentro do `th`, `aria-sort` e anúncio da ordem | P |
| UX-32 | Global · busca em select | A caixa de busca entra dentro do `<label>` e "rouba" o nome do select; digitar troca o valor sem avisar | WCAG 1.3.1 · 3.2.2 · 4.1.2 | Alta | `escalas.js:75`, `:85` | Busca fora do label, `aria-controls`, só troca o valor no Enter | M |
| UX-33 | Global · avisos | `?ok=`/`?erro=` na URL: a mensagem volta a cada recarga, vai junto ao compartilhar e aceita texto vindo do link; com "manter rolagem", o aviso fica fora da vista | N1 · segurança de conteúdo · WCAG 4.1.3 | Alta | `acesso.php:135`, `escalas.js:51` | Flash na sessão (lido e apagado) + aviso fixo/toast com `aria-live`, perto da ação | M |
| UX-34 | Global · celular | Abaixo de 760 px o menu (25 links, ~900 px de altura) empilha antes do conteúdo em toda tela | Mobile · WCAG 1.4.10 | Alta | `escalas.css:133` | Menu recolhível (hambúrguer) ou barra inferior com 4–5 atalhos | M |
| UX-35 | Global · carregando | Só "Gerar" mostra que está processando; Análise e Diagnóstico (~20 consultas) carregam sem sinal | N1 · WCAG 4.1.3 | Alta | `gerar.php:165` (único caso) | Indicador de navegação (barra no topo) + padrão de ação longa | P |
| UX-36 | Equilíbrio dos SRs | Nota, absenteísmo e infrações individuais visíveis para qualquer perfil com "ver" (LEITURA) | Privacidade · N5 | Alta* | `acesso.php:21`, `tertius.php` sem `escExigir` | Colunas de mérito só para Planejamento e Admin | P |
| UX-37 | Menu | "Equilíbrio Sales Representative" mistura PT/EN; "Configuração dos times" perde o sentido após 31/10 | N2 · N4 | Alta | `index.php:56`, `times.php:165` | Renomear e reorganizar (ver §4); estrutura antiga em "Legado" | P |
| UX-38 | i18n (pré-requisito) | ~1.580 textos fixos em 34 arquivos, `lang="pt-BR"` fixo, enums do banco viram texto com `strtolower` | Requisito do item 1 | Alta | `aprovar.php:138`, `helpers.php:30` | Função `t('chave')` + catálogos PT/EN; mapa de rótulos dos enums; datas e números por locale | G |

\* Severidade alta por privacidade, mesmo sendo pouco visível.

## 3. Achados médios e baixos

| ID | Tela | Problema → recomendação | Critério | Sev. | Esf. |
|---|---|---|---|---|---|
| UX-39 | Global · erros | "Detalhes em logs/app.log" e mensagens de SQL cruas na tela (`index.php:127`, `saidas.php:197`) → texto humano + código de ocorrência + "Tentar de novo"; o detalhe técnico fica recolhido | N2 · N9 | Média | P |
| UX-40 | Global · erro de tela | Falha vira painel solto (h2, sem h1, HTTP 200, sem ação) (`index.php:143`) → título da tela, Recarregar, Voltar ao Painel, HTTP 500 | N9 · WCAG 2.4.6 | Média | P |
| UX-41 | Global · permissão | O menu mostra tudo a todos; LEITURA recebe "Bloqueado: seu perfil não pode fazer isso." (`acesso.php:97`) → esconder ou desabilitar com motivo; dizer o perfil exigido e quem libera | N5 · N2 | Média | P |
| UX-42 | Global · bloqueio | A tela de banco fora ou acesso restrito fala em ES_01/ES_03 (`index.php:87`) → mensagem para o usuário + detalhe no log; listar os admins | N2 | Baixa | P |
| UX-43 | Global · auto-envio | Selects e datas enviam ao mudar; a data recarrega no meio da digitação (`organograma.php:73`, `colaboradores.php:223`, `trocas.php:336`, `times.php:227`) → enviar só com data completa (blur/Enter) ou botão "Ver" | WCAG 3.2.2 · N3 | Média | P |
| UX-44 | Global · copiar | "Copiado" não é anunciado; a falha é silenciosa (`escalas.js:25`) → `aria-live` e "Não copiou: selecione e use Ctrl+C" | WCAG 4.1.3 · N9 | Média | P |
| UX-45 | Global · teclado | Sem "pular para o conteúdo" (25 links antes do `<main>`) (`index.php:201`) | WCAG 2.4.1 | Média | P |
| UX-46 | Global · menu | Item ativo sem `aria-current`; badge só número; "↗" lido como "seta nordeste" (`index.php:206`, `:211`) | WCAG 1.3.1 · 1.1.1 | Média | P |
| UX-47 | Global · toque | Botão 34 px, "Alocar/Recusar" 26 px, chips ~29 px, checkbox 13 px (`escalas.css:106`, `:165`) → mínimo 44 px em `pointer: coarse` | WCAG 2.5.5 · mobile | Média | P |
| UX-48 | Global · iOS | Inputs com 13 px dão zoom ao focar (`escalas.css:144`) → 16 px em `pointer: coarse` | Mobile | Baixa | P |
| UX-49 | Global · contraste | Linha inativa com opacidade .55 (2,98:1); placeholder padrão 4,22:1; hover do primário 4,17:1 (`escalas.css:189`, `:107`) | WCAG 1.4.3 | Média | P |
| UX-50 | Grade, Análise, Organograma | Elementos não textuais < 3:1: amostra de folga 1,52:1, série "neutro" 2,05:1, bordas dos turnos 1,8–2,1:1 | WCAG 1.4.11 | Média | P |
| UX-51 | Global · glifos | "→" (~50), "×" (~43), "⚠", "↕▲▼▸▾" via `::after` são lidos pelo leitor de tela | WCAG 1.1.1 | Baixa | P |
| UX-52 | Global · consistência | Menu × título × `<title>` diferentes ("Análise (WFM)" × "Análise da escala e da demanda"; "Pedidos e aprovação" × "Indisponibilidade") → uma única fonte de rótulo | N4 · WCAG 2.4.2 | Média | P |
| UX-53 | Global · menu | Grupo Planejamento com 9 itens heterogêneos; Patch notes só num link de 11 px; o selo do perfil usa o estilo amarelo de "teste" | N6 · Hick | Média | P |
| UX-54 | Global · status | Enums crus em minúsculas ("substituido" sem acento, "workload"); a mensagem do Recalcular manda ao "fim da tela Gerar", mas o redirect vai para a Grade (`gerar.php:219`, `:33`) | N2 · N4 | Baixa | P |
| UX-55 | Painel | KPIs de parties ("bloqueiam a geração") irrelevantes na estrutura nova; KPI "CRM" sem valor (`painel.php:84`, `:99`) → cartão "Semana dd/mm: vX · lacunas · sugestões · postadas 12/40" | N8 · N1 | Média | M |
| UX-56 | Grade · publicar × postar | "Publicar" (sistema) e "postar" (site) se confundem; sem indicação de etapa (`grade.php:570`) → stepper 1 Gerar · 2 Ajustar · 3 Publicar · 4 Postar | N2 · N1 | Média | M |
| UX-57 | Grade, Sugestões, Buffers, Equilíbrio | Abrem sozinhas uma semana, sem seletor de semana/versão; cada tela tem um seletor diferente → componente único "Semana + versão" | N3 · N4 | Média | M |
| UX-58 | Grade · modal | O datalist traz o headcount inteiro, sem quem está de folga, indisponível ou já alocado → "Disponíveis no turno" primeiro, com status | N6 | Média | M |
| UX-59 | Grade | Origem (dono, cobertura, buffer, manual) só pela cor; motivo só no `title`; a legenda omite as cores da liderança → sigla por origem e motivo no modal | WCAG 1.4.1 | Média | M |
| UX-60 | Grade · postar | Dezenas de `<pre>` abertos; "só apagar" sem marcação; cada marcação recarrega a página → aba "Postar" própria, CSV recolhido, filtro "A fazer", marcação sem recarregar, progresso fixo | N8 · N1 · mobile | Média | M |
| UX-61 | Sugestões / Horas | "Aplicar" e "Recusar" sem confirmação e sem dizer o efeito; "Cobrir na grade" só leva ao turno, sem abrir a célula, e não fecha a sugestão | N5 · N7 | Média | M |
| UX-62 | Horas / Sugestões | Campos só com placeholder; botão "Registrar" sem contexto; conflitos (16 h, 52 h) só aparecem ao "Alocar" → validar ao registrar e marcar "não elegível: motivo" | WCAG 3.3.2 · N5 | Média | M |
| UX-63 | Análise | "Faltas" mostra % e não quantidade; dicas dos gráficos só com mouse | N4 · WCAG 2.1.1 | Média | P |
| UX-64 | Equilíbrio | URL `p=tertius` e título híbrido; jargão "rode o ES_16" (`tertius.php:119`) → `p=equilibrio`, "Equilíbrio e mérito dos SRs", mensagem humana | N2 | Média | P |
| UX-65 | Buffers | Estado só por cor; célula "3 / 2 / 1" críptica; sem estado vazio | WCAG 1.4.1 · N6 | Média | P |
| UX-66 | Saídas | Jargão e unidades soltas ("reps/h", "livre para o motor", colunas A/C/D); observação sem rótulo; o lote mostra "Aplicando…" em todo botão | N2 · WCAG 3.3.2 | Média | P |
| UX-67 | Saídas · celular | Tabela de 9 colunas com até 5 controles por linha → cartões no celular | Mobile | Média | M |
| UX-68 | Trocas | Progresso do lote espalhado; quem sai e quem entra só no `title`; "Schedule Ajustment" (grafia) | N1 · N6 · WCAG 1.3.1 | Média | M |
| UX-69 | Funil | Nomes internos na tela ("tab_intraday_base", "arquetipo"); 11 campos sempre visíveis e sem validação | N2 · N8 | Média | M |
| UX-70 | Times (legado) | "Cancelar" pendência sem confirmação; erro genérico; volta ao topo e não à lista | N5 · N9 | Média | P |
| UX-71 | Colaboradores | No cadastro antigo a lista corta em 400 sem avisar | N1 | Baixa | P |
| UX-72 | Indisponibilidade | Comentário obrigatório só validado no servidor; "Lançar" mostra "Até" e "Dias da semana" mesmo em "Um dia"; texto fixa 72 h/3 meses (são parâmetros); lista limitada a 200, sem busca; cores de status inconsistentes | WCAG 3.3.1 · N8 · N4 | Média | M |
| UX-73 | Acessos | Inputs por linha sem rótulo; textos de perfis antigos (WFM, Gestor do time); log com chaves técnicas | WCAG 3.3.2 · N2 | Média | P |
| UX-74 | Parâmetros | Valor sempre `type=text` e sem rótulo, mesmo para inteiro, decimal ou sim/não | WCAG 3.3.2 · N5 | Média | P |
| UX-75 | i18n · datas | `dataBr`/`num` com padrão BR fixo + ~55 `date('d/m…')` soltos; a ordenação JS depende do formato | Requisito do item 1 | Média | M |

## 4. Contraste (WCAG 2.1 AA): pares que falham

| Texto / elemento | Fundo | Razão | Onde |
|---|---|---|---|
| #6b6f7a (`--texto-3`) | #0b090c menu | 3,95:1 | link "vX · patch notes" (11 px) |
| #6b6f7a | #131014 painel | 3,76:1 | itens desligados, "▸ ver SRs" (10 px) |
| #6b6f7a | #0f0c10 campo | 3,87:1 | eixos e rótulos dos gráficos (10–11 px) |
| `.muted` × opacidade .55 | #131014 | 2,98:1 | linha inativa |
| branco | #f01372 (primário no hover) | 4,17:1 | `.botao.primario:hover` |
| #757575 (placeholder padrão) | #0f0c10 | 4,22:1 | placeholders |
| #2a2129 (borda de controle) | #131014 / #0f0c10 | 1,21 / 1,25:1 (não-texto, mín. 3:1) | input, select, botão, chip |
| #3a3238 | #131014 | 1,52:1 (não-texto) | legenda "folga" |
| #4b4350 (`--neutro`) | #0f0c10 | 2,05:1 (não-texto) | barras neutras dos gráficos |
| bordas org D/C/A/OA | #131014 | 1,8–2,8:1 (não-texto) | organograma |

Passam: `.muted` e `th` 7,2:1 · links 6,7–7,5:1 · tags 8,4–10,5:1 · botão primário e badge 5,68:1 · avisos 13–15:1 · cores de origem da grade 8,3–17:1.

---

## 5. Priorização (impacto × esforço)

| | **Esforço baixo** | **Esforço médio/alto** |
|---|---|---|
| **Impacto alto** | **Fazer já (Fase A):** UX-01, 03, 04, 05, 08, 09, 13, 19, 21, 22, 24, 27, 28, 29, 30, 31, 35, 36, 37 · cargos antigos (UX-16, parte P) | **Planejar (Fase B):** UX-02 e 33 (flash: base de tudo), 06, 07, 10, 12, 14, 15, 17, 20, 23, 25, 26, 32, 34 |
| **Impacto médio** | **Encaixar junto:** UX-39 a 54, 63, 64, 65, 66, 70, 71, 73, 74 | **Depois (Fase C/D):** UX-11, 55 a 62, 67, 68, 69, 72, 75 e i18n completo (UX-38) |

**Ordem proposta**
- **Fase A · segurança e quick wins.** Críticos, contraste, teclado, nomes antigos, menu reorganizado e o padrão de ação longa.
- **Fase B · base dos componentes.** Flash de mensagem e POST, aviso fixo/toast, diálogo de confirmação com resumo, `<dialog>` acessível, menu mobile, tokens de cor. Os itens 2 (tema) e 3 (mobile) do pedido entram aqui.
- **Fase C · idioma (item 1).** Catálogo PT/EN, enums, datas e números por locale, troca de idioma.
- **Fase D · Central de Ajuda (item 4) e versionamento (item 5).** Glossário, FAQ, tour e Modo Treino, documentação do DBA, Changelog bilíngue e versão no rodapé.

## 6. Fluxos principais: antes × depois

**Gerar e publicar a semana**
- *Antes:* Painel → Gerar → semana → "Gerar nova versão" (pode apagar ajustes) → Grade → Sugestões e Horas pelo menu → volta à Grade (sem seletor) → marca "ciente" → Publicar sem confirmação. **~9–11 cliques, 4 telas**, risco de perder trabalho.
- *Depois:* Painel (cartão da semana com pendências) → Grade com stepper (Gerar · Ajustar · Publicar · Postar) → "Recalcular" como padrão, com progresso → pendências na própria tela (lacunas, sugestões, hora extra) → Publicar com diálogo-resumo. **~4–5 cliques, 1–2 telas.**

**Ajustar uma célula da grade**
- *Antes:* clique (recarga completa) → datalist com o headcount inteiro → "Salvar e travar" (outra recarga). Se der erro, o modal fecha e o digitado se perde. **2–3 cargas pesadas.**
- *Depois:* o clique abre o painel na hora, com "disponíveis no turno" e alertas de conflito → salvar → a célula se atualiza no lugar. O erro aparece no campo, com os dados preservados. **0 recargas.**

**Postar os CSVs no site**
- *Antes:* rolar por dezenas de CSVs abertos → Delete Range → "Apaguei" (recarga) → Copiar → colar → "Postei" (recarga), N vezes; "só apagar" sem controle.
- *Depois:* aba "Postar" com checklist em "A fazer", progresso fixo X/Y, CSV recolhido, "Copiar" com confirmação audível, marcação sem recarregar, "só apagar" também marcável.

**Aplicar saída de página**
- *Antes:* tabela larga → "Confirmar e aplicar" ao lado de "Confirmar" → confirm genérico → minutos sem sinal → aviso longo; o botão continua lá e "Desfazer" engana.
- *Depois:* "Aplicar…" → prévia (postos, SRs remanejados, semanas) → confirma com página e data → progresso → resultado com "Comparar"; a linha vira "Aplicada em dd/mm".

**Trocar uma pessoa de núcleo/turno**
- *Antes:* Colaboradores → buscar → Editar → select longo → data → Salvar. Fecha vagas sem confirmar; se der erro, o formulário some.
- *Depois:* Editar → núcleo (sem padrão) → resumo "de → para" + vagas que fecham → confirmar; se der erro, o formulário reabre preenchido.

---

## 7. Padrões a adotar (guia rápido)

**Arquitetura do menu (proposta)**
- **Escala:** Painel · Gerar escala · Grade da escala · Escala da liderança · Horas extras
- **Análise:** Análise · Equilíbrio dos SRs · Sugestões de troca · Buffers
- **Páginas:** Trocas de página · Saídas de página · Funil de entrada
- **Pessoas:** Organograma · Colaboradores · Indisponibilidade · Modelos de escala
- **Ajuda:** Glossário · FAQ · Tutorial · Changelog *(Documentação do DBA só para admin)*
- **Administração:** Acessos · Parâmetros · Diagnóstico
- **Legado (até 31/10):** Configuração dos times
- **Fonte única de rótulos:** o mesmo nome no menu, no título da tela e na aba do navegador.

**Componentes**
- **Botões:** um primário por área (a ação que conclui a tarefa); secundário para o resto; "perigo" (vermelho, com confirmação) para ação irreversível, nunca colado na ação leve. Altura 36 px no desktop e 44 px no toque.
- **Ação longa:** desabilita o botão, troca o texto ("Recalculando… pode levar até 1 min"), `aria-busy`, barra no topo e bloqueio de duplo envio. Uma única implementação em `escalas.js`, ativada por `data-longo`.
- **Confirmação:** diálogo próprio (`<dialog>`) com resumo do efeito e números ("fecha 4 postos, remaneja 6 SRs, gera de novo 2 semanas"); o botão de confirmar repete o verbo ("Aplicar saída").
- **Mensagens:** flash na sessão; aviso fixo no topo da área de conteúdo com `role="status"`/`alert`; erro de campo embaixo do campo; nunca caminho de arquivo ou SQL (só o código da ocorrência).
- **Estados de tela:** carregando (barra/skeleton), vazio (frase + próxima ação), erro (o que houve + Tentar de novo + Voltar), sem permissão (perfil exigido + quem libera), sucesso (o que mudou + link).
- **Status (texto + cor, nunca só cor):** Rascunho (amarelo) · Publicada (verde) · Substituída (cinza) · Pendente (laranja) · Aprovado (verde) · Recusado (vermelho) · Erro (vermelho).
- **Tabelas:** cabeçalho com botão de ordenação acessível; colunas numéricas à direita; 1ª coluna fixa nas largas; no celular, cartões.
- **Formulários:** rótulo visível sempre (placeholder só como exemplo); tipo certo (`number`, `date`, `email`, `inputmode=decimal`); valor padrão sensato; validação no campo ao sair dele; dados preservados no erro.
- **Seletor "Semana + versão"** igual em todas as telas de escala.

**Cores (tokens semânticos, tema escuro e claro)**
- **Superfícies:** `--fundo`, `--superficie`, `--superficie-2`, `--superficie-hover`.
- **Texto:** `--texto` (≥ 12:1), `--texto-2` (≥ 7:1), `--texto-3` (≥ 4,5:1, substitui o #6b6f7a).
- **Borda:** `--borda` (decorativa) e `--borda-campo` (≥ 3:1).
- **Marca:** `--primaria` #c2185b (5,7:1 com branco) e `--primaria-hover` (mais escura).
- **Estado:** `--ok`, `--alerta`, `--erro`, `--info`, cada um com `-bg` e `-texto` testados nos 2 temas.
- **Turnos:** `--turno-a/c/d` com versão de borda ≥ 3:1.
- **Regra:** nenhuma cor fixa fora dos tokens. Hoje são 109 ocorrências, que viram tokens na Fase B.

**Tipografia e espaçamento**
- **Escala:** 12 (legenda, mínimo) · 14 (corpo) · 16 (entrada no toque) · 18 (h3) · 22 (h2) · 28 (h1). Peso 400/600; números tabulares em tabela.
- **Espaçamento em múltiplos de 4:** 4 · 8 · 12 · 16 · 24 · 32. Painel com padding 16 (celular) / 20 (desktop).
- **Largura de leitura:** textos longos com no máximo ~75 caracteres por linha.

**Tom de voz**
- Direto, no infinitivo ou imperativo, sem jargão de banco ou código: "Gerar de novo a semana", não "rodar o motor"; "Faltam dados do Capacity", não "fc_buffer_diario vazio".
- Cargos sempre OM, OC, OS, OA e SR n. Turnos sempre "T2/T3 (05–13h)".
- Mensagem de erro com o que aconteceu + o que fazer. Sucesso com o que mudou + o próximo passo.
- Em EN: mesmo tom, termos do negócio iguais (Sales Representative, Overlap, Buffer).

---

## 8. Dúvidas que preciso que você decida antes de implementar

1. **Idioma "sem recarregar a página".** O sistema monta a tela no servidor. Trocar o tema sem recarregar é simples. Para o idioma, a proposta é a tela trocar o conteúdo no lugar, sem piscar e mantendo a rolagem (por baixo, uma recarga parcial). Aceita assim, ou precisa ser 100% no navegador? (Isso exigiria mandar os dois idiomas em toda tela e reescrever as telas em JS.)
2. **Escopo do inglês.** Traduzo a interface, a Ajuda e o Changelog. Os dados do banco (nomes de página, motivos digitados, nomes de pessoas) ficam como estão. Certo?
3. **Modo Treino.** Para praticar sem afetar dados reais, a proposta é um schema separado `5c_escalas_treino`, com dados fictícios carregados por script (você roda no Navicat) e um seletor "Modo Treino" que aponta o sistema para ele, com faixa visível em todas as telas. Pode ser assim?
4. **Telas da estrutura antiga** (Configuração dos times, Buffers no formato atual, regras por party em Trocas e Funil): depois de 31/10 21:00, a proposta é Times virar somente leitura em "Legado", e Buffers, Trocas e Funil serem refeitos para unidade · turno · núcleo. Concorda?
5. **Recálculo automático no Painel** (UX-07): mover para agendado (cron, a cada 15 min) ou só um botão "Verificar mudanças"?
6. **Mérito visível só para Planejamento e Admin** (UX-36): confirma?
7. **PDF da documentação do DBA:** pela impressão do navegador (como o organograma), além do download em Markdown. Ok?
8. **Versão no rodapé:** a versão sai do topo do menu e vai para o rodapé de todas as telas, com link para o Changelog. Ok?

**Respostas (30/10/2026, relatório aprovado):**
1. O mais simples: recarga parcial mantendo a rolagem.
2. Correto: interface, Ajuda e Changelog; dados do banco ficam como estão.
3. Schema separado `5c_escalas_treino`, com botão para o trainee **resetar** tudo e recomeçar.
4. De acordo. **Lembrar o usuário, depois de criado o Legado, de modificar a estrutura dos registros de times** (ele ainda vai ver como ficam).
5. Só pelo botão "Verificar mudanças" (menos recálculo no servidor).
6. Mérito só para Planejamento e Admin.
7. Ok (impressão do navegador + Markdown).
8. Ok (versão no rodapé com link para o Changelog).

**Estimativa:** Fase A 1–2 dias · Fase B 3–4 dias · Fase C (i18n, ~1.580 textos) 4–5 dias · Fase D (Ajuda, tour, Modo Treino, DBA) 4–6 dias.

---

## 9. Implementação · Fase A (30/10/2026, publicada como v1.9.0)

| Item | Situação |
|---|---|
| UX-01 Gerar: "Recalcular" primário, "Gerar do zero" com confirmação e contagem de travados | feito |
| UX-03/04/18/19 Saídas: sem reaplicar (tela e servidor), ação longa, botão perigo separado, "Voltar para a conferir" | feito · corrigido também o JS do lote, que estava quebrado |
| UX-05 Times no grupo Legado, só leitura após a virada, sem pendência com data ≥ virada | feito |
| UX-06/27 Indisponibilidade: nada pré-marcado, Aprovar todas / só marcadas / Recusar todas, cancelar com confirmação | feito |
| UX-07 Painel: verificação só pelo botão | feito |
| UX-08 Publicar com resumo e "ciente" obrigatório | feito (confirm nativo; diálogo próprio na Fase B) |
| UX-09/35 Ação longa global (`data-longo`, `data-confirmar`) e barra de navegação | feito |
| UX-13 Faixa de versão substituída + status com texto | feito |
| UX-16 Cargos antigos (Grade por pessoa, Gerar, Painel, Trocas, Indisponibilidade) | feito · Funil/Sugestões ficam com a Fase B (UX-23) |
| UX-21 Trocas: CSV mudou depois de postado | feito |
| UX-22 Funil em % | feito |
| UX-24 Núcleo sem padrão | feito |
| UX-28/29/30 Contraste e `color-scheme` | feito |
| UX-31 Ordenação por teclado + aviso para leitor de tela | feito |
| UX-36 Mérito só Planejamento/Admin | feito |
| UX-37 Menu reorganizado, rótulo único, Administração só admin, versão no rodapé | feito |

Verificação: `php -l` em todos os arquivos, `node` no escalas.js, telas renderizadas com dados de exemplo (Saídas, Grade, Gerar, Painel, Indisponibilidade, Equilíbrio, Colaboradores, Funil) e conferidas em screenshot. Não testado contra o banco real (local sem acesso); Configuração dos times só com lint.

## 10. Implementação · Fase B, parte 1 (30/10/2026, publicada como v1.10.0)

| Item | Situação |
|---|---|
| UX-02/33 Flash na sessão, formulário reabre preenchido, aviso fixo com fechar, mensagem fora da URL | feito |
| UX-39 Erro inesperado com código de ocorrência e "Tentar de novo" | feito |
| Diálogo de confirmação próprio (`<dialog>`, `escConfirmar`) no lugar do `confirm()` | feito |
| UX-12 Janela da célula: fundo inerte, foco dentro e retorno à célula | feito |
| Item 2 · Tema claro/escuro/automático com tokens (AA nos dois temas, gráficos inclusos) | feito |
| Item 3 · Celular: menu recolhível, tabelas em cartões, 44 px no toque, 360 px | feito |
| UX-20 Trocas: dia/bloco com link para a Grade | feito |
| UX-25 Colaboradores: "de → para" e vagas que fecham | feito |
| UX-26 Modelos: aviso de ciclo em uso e regra do ativo | feito |
| UX-32 Busca nas listas | feito |
| UX-10 Alertas de conflito ao ajustar célula | parte 2 |
| UX-14 Escala da liderança lendo do plano | parte 2 |
| UX-15 Buffers na estrutura nova | parte 2 |
| UX-17 Prévia (simulação) antes de aplicar saída | parte 2 |
| UX-23 Funil com destino unidade · turno · núcleo (precisa de script no banco) | parte 2 |

Verificação: `php -l` em tudo, `node` no JS, telas renderizadas com dados de exemplo nos dois temas e em 375 px (iframe). Não testado contra o banco real.

## 11. Implementação · Fase B parte 2, Fase C e Fase D (30/10/2026, publicadas como v1.11.0)

**Fase B, parte 2**
| Item | Situação |
|---|---|
| UX-10 Alertas de conflito ao ajustar célula (folga, indisponibilidade, outro turno, outra página no bloco, 12 h, 52 h) com "salvar mesmo assim" | feito (lib/motor.php · escGradeConflitos) |
| UX-14 Escala da liderança compara com o plano ("≠ grade"); postar pela Grade; CSV só de conferência | feito |
| UX-15 Buffers na estrutura nova pela mesma fonte de Horas extras; Painel idem | feito |
| UX-17 Prévia antes de aplicar saída (postos que fecham, refeitos, SRs sem vaga, semanas) | feito (lib/saida.php · escSaidaPrevia) |
| UX-23 Funil com destino unidade · turno · núcleo | feito; precisa do ES_27 (sem ele, continua por party) |

**Fase C · idioma (item 1)**
- `t()` estilo gettext (português é a chave), catálogos `lang/en/*.php` (~1.300 chaves), `escRotulo()` para os enums do banco, datas e números por idioma (`escData`, `escDataHora`, `num`, `pct`), JS por `window.ESC_I18N`/`T()`.
- Seletor PT/EN no menu (cookie + navegador como padrão); troca recarrega no mesmo ponto da tela (resposta 1: o mais simples).
- Verificação: 1.546 usos de `t()`/`tn()` em 39 arquivos, todos com tradução e os mesmos marcadores.

**Fase D · Central de Ajuda (item 4) e versionamento (item 5)**
- Glossário (29 termos) e FAQ (20 perguntas) com busca; Tutorial com tour guiado de 12 passos (destaque na tela, progresso salvo, retomar), "Ações necessárias agora" (Na plataforma / No site 5C) também no Painel.
- Modo Treino: schema `5c_escalas_treino` (ES_28), faixa em todas as telas, "Recomeçar o treino", ações liberadas menos admin, Capacity bloqueado.
- Documentação do DBA (só admin), PDF pela impressão e Markdown.
- Changelog em português e inglês (lib/versao_en.php).

## 12. Revisão de bugs (30/10/2026, 3 revisores independentes: segurança, regras e front-end)

**Corrigidos:** tokens do tema escuro que apontavam para si mesmos (foco, links, avisos e fundo dos diálogos sumiam); mérito real visível no Modo Treino; Acessos e Parâmetros abertos por URL; admin se desativando ou tirando o último admin; pedido cancelado virando "parcial"; hora extra alocada duas vezes; exclusão de versão apagando sugestão aplicada e hora extra alocada, e corrida com Publicar; Colaboradores sobrescrevendo vigência futura e o formulário antigo mudando turno depois da virada; saída aplicada no passado, duas vezes com outra data ou para página inexistente, e lote aplicando metade; prévia contando "sem vaga" diferente do aplicar; "Criar rascunho a partir desta" a partir de versão errada; T1 de segunda vindo de rascunho; edição de célula antes da virada; recálculo devolvendo publicado a célula travada; 12 h sem olhar a semana vizinha; liderança incluída à mão saindo com -2; fuso de Brasília; datas gravadas no idioma do usuário; modelo do 5x2 renomeável; Funil gravando pela metade; normalizador de código apagando comentário; treino recomeçado em paralelo; arquivos do treino sem marca; cartões do celular e tabelas largas; contraste do mapa de calor no claro; foco da janela da célula; impressão A3 vazando para outras telas; botão de ordenar em coluna vazia; nome da busca; diálogo sem descrição; textos do organograma no CSS; data vazia na troca de núcleo; reenvio preenchendo o formulário errado; anúncios de leitor de tela; aviso atrás do menu no celular; erro lido duas vezes.

**Em aberto (proposta, não mexido sem teste com dados reais):**
- **Motor usa a estrutura de domingo para a semana inteira** (lib/motor.php · escV4Carregar(end($dias))). Mudança no meio da semana (saída, troca de núcleo, config com fim no meio) vale para a semana toda, inclusive dias passados. A proteção do publicado segura quem já estava, mas a página pode cair em "Só apagar". Correção: vigência por dia dentro do motor V4 e não recalcular dias passados. Mexe no coração do motor; fazer com a semana real ao lado e comparar o De→Para.
- Downloads (GET) gravam o log de exportação sem CSRF (efeito pequeno).
- Duas gerações simultâneas da mesma semana podem criar versões repetidas (depende de chave única).
- Texto do WhatsApp de hora extra sai no idioma de quem está na tela.
