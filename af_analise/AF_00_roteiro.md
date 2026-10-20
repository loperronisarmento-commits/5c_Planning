# Falhas Administrativas (AF) — roteiro da análise

## O que está em jogo
No Capacity de 19/10, AF entra no absenteísmo com peso **0,53** porque 47% dos turnos AF tinham mensagens do rep. Esse peso muda a necessidade de reps (A 57,0 · C 61,5 · D 60,0) e o tamanho das turmas. O relatório da diretoria, por outro lado, **não conta AF como falta**. A análise precisa dizer qual peso usar no planejamento e por quê.

## Definição vigente
AF = slot escalado em que o rep faltou (ABS), num dia em que ele trabalhou **≥ 3 h fora de todos os turnos escalados**. Marcada pela `sp_abs_adh_af_processar` em `5c_wfm_db_mis.abs_adh_af`. É "falha de comunicação do planejamento", não falta do rep.

## Hipóteses a separar (cada uma pede resposta diferente)
| Hipótese | O que se vê nos dados | Peso que faria sentido | Ação |
|---|---|---|---|
| H1 Turno fixo cadastrado errado | mesmo rep, mesmo padrão "escalado A, trabalhou C" em dias seguidos (Q5, Q9) | ~0 para capacity (o rep está lá, só a escala está errada) mas **1 para a página escalada** | corrigir cadastro / escala |
| H2 Realocação pelo RTA não registrada | rep trabalhou outra modelo no mesmo horário ou horário vizinho (Q5, Q6b) | depende se a página escalada ficou coberta (Q6) | registrar realocação (pedido na Nora) |
| H3 Mudança de escala comunicada tarde | linha do `scheduleday` alterada < 24 h antes ou depois do turno; turnos removidos na origem (Q7) | parcial | SLA de publicação da escala |
| H4 Rep escolheu outro horário por conta própria | sem alteração de escala, sem padrão fixo, página escalada descoberta (Q6, Q7) | 1 (é falta) | tratar como ABS |

## Como o peso deve ser calculado
`peso_falha_adm` = fração dos slots AF em que a **página escalada ficou sem ninguém** no slot (Q6: `descoberto_de_fato / slots_af`). O "rep mandou mensagem" (47%) não é a medida certa: ele pode ter mandado mensagem de outra página, e a página escalada ficou descoberta mesmo assim. Vale calcular por turno A/C/D, porque o Capacity já usa absenteísmo por turno.

## Sensibilidade (Q3)
Ver quantos slots mudam de classe se o limiar for 2 h ou 4 h. Se a maioria dos AF está em 3–4 h, o limiar é frágil.

## Entregáveis previstos
1. Parecer AF (pt-BR, padrão 5C): volume, causas, peso recomendado (global e por turno), ações de cadastro/escala.
2. Script `.sql` para o usuário atualizar `fc_parametros.peso_falha_adm` (e, se fizer sentido, um peso por turno) e recalcular.
3. Lista de reps/páginas com AF recorrente para o planejamento corrigir na origem.

## O que preciso receber
- `claude/falhas_administrativas_af_briefing.md` (Projeto 5C | LUIS | DB).
- Resultados de `AF_01_diagnostico_somente_leitura.sql`, Q0 a Q9, em CSV (ou colados). Q0 primeiro, se alguma consulta falhar por nome de coluna.
- Se tiver: `5C_Plano_Capacity_Contratacao_19nov.xlsx` (aba Absenteísmo), para bater os números.
