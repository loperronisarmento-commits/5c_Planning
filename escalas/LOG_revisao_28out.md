# 5C Escalas — log da revisão de 28/10 (tarde)

## Status do projeto

- **Escalas geradas (v4.0, rascunho), conferidas no banco:**
  - **26/10** (só 01/11, transição): 25 postos sem ninguém.
  - **02/11 a 08/11:** 30 postos sem ninguém (antes da rodada eram 67).
    - Por unidade e turno: U1 C 14 · U3 A 8 · U2 C 3 · U3 D 3 · U3 C 2.
    - O Overlap ficou espalhado na semana: 17 SRs ficaram na página 5C Schedule, e o resto cobriu lacunas.
    - Há 4 posto-dias acima da capacidade (até 108%) e 22 anomalias de dias seguidos, que vêm da escala antiga.
- **Falta para 29/10 às 18:00:** revisar na Grade, publicar as duas versões, baixar os CSVs e postar no Schedule Ajustment.
- **Commit:** as mudanças desta rodada não foram commitadas (ES_12, ES_13, motor, telas e zip). O último commit é o 723ea6b.
- **Zip:** o `sistema/5c_escalas.zip` tem tudo desta rodada, inclusive as correções abaixo.

## O que foi feito

### 1. Revisão do código
Um agente leu o módulo inteiro (index, config, lib, pages e o JS) e conferiu no banco, só lendo. Achados e o que foi feito com cada um:

| # | Problema | Situação |
|---|---|---|
| 1 | **Colaboradores:** salvar uma pessoa com data nova apagava a estrutura de 01/11 dela (núcleo, nível, função, fatia). Ela sumia da escala dali em diante. A lista de tipos também não tinha "Núcleo", e salvar trocava o tipo para "Time". | **Corrigido.** A nova vigência leva junto unidade, núcleo, nível, função, fatia e âncora, e a opção "Núcleo (estrutura nova)" entrou na lista. |
| 2 | **Grade:** dava para publicar uma versão já substituída, se a aba estivesse aberta desde antes de um recálculo. | **Corrigido.** Só o rascunho atual pode ser publicado. |
| 3 | **Telas Pedidos, Sugestões, Equilíbrio, Buffers e Parâmetros:** "Erro interno", porque a variável `$p` era sobrescrita. | **Corrigido antes desta revisão.** A revisão confirmou que nenhuma outra variável do `index.php` é sobrescrita. |
| 4 | **Saídas de página:** apertar Enter na observação registrava uma decisão sem querer. | **Corrigido.** Enter não envia mais o formulário. |
| 5 | **Diagnóstico:** qualquer perfil podia abrir. A tela grava uma linha de teste e desfaz, e mostra o endereço do banco. | **Corrigido.** Agora só abre para ADMIN. |
| 6 | **Grade:** `?turno[]=x` na URL derrubava a tela. | **Corrigido.** |
| 7 | **JS:** a rolagem guardada podia voltar na tela errada, e Esc numa busca fechava a janela e perdia o que foi digitado. | **Corrigido.** A rolagem fica guardada por tela, e o primeiro Esc só limpa a busca. |
| 8 | **CSV:** o T1 de segunda e a regra das 16 h usam o plano mais recente da semana anterior, mesmo que seja rascunho. | **Mantido de propósito.** A 26/10 e a 02/11 vão ser postadas juntas, a partir dos rascunhos. |
| 9 | **Buffers e Sugestões** ainda contam o buffer da estrutura antiga. Nas escalas novas, a tela Buffers mostra 0 usados, e o botão "Aplicar" de "Buffer sem página" não marca folga. | **Pendente.** Não afeta a escala nem o CSV. Fica para a próxima versão dessas telas. |
| 10 | **Colaboradores:** quando a pessoa já tem uma configuração com data futura (as de 01/11), salvar edita essa configuração futura. | **Mantido.** Hoje é isso que se quer: editar a configuração de 01/11. |
| 11 | **Recálculo automático:** dois acessos ao Painel ao mesmo tempo podem criar duas subversões. | **Pendente.** É raro. |
| 12 | **CSV em .zip:** um plano sem nenhuma alocação gera um zip vazio, com aviso no log. | **Pendente.** É raro. |

Conferido e sem problema:
- todo formulário que grava tem a proteção contra envio forjado (CSRF), e toda ação tem tratamento;
- todo link e todo item de menu levam a uma tela que existe;
- toda entrada do usuário vai para o banco como parâmetro (`?`);
- não há mistura de collation entre schemas.

### 2. Testes de tela no navegador
- **Bloqueado:** o site abre na tela de login do MIS Portal, e eu não entro com senha no lugar do usuário.
- **Para testar:** entrar uma vez pelo navegador do app Claude (o login fica guardado) e pedir o teste.
- **O que vai ser testado:** abrir cada menu, os filtros, a janela da Grade, as buscas e os downloads.
- **O que não vai ser clicado sem pedido** (o banco é de produção): Gerar, Publicar, Salvar e Lançar.
- **Teste que foi possível fazer:** o motor rodou com os dados reais (semanas de 26/10, 02/11 e 09/11) e passou em todas as regras, sem erro. O lint do PHP e do JS deu OK.

### 3. Polimento visual (Grade)
- **Cabeçalho e coluna fixos:** os dias e a coluna das páginas ficam visíveis ao rolar. A tabela tem altura máxima e rolagem própria.
- **Legenda:** virou amostras coloridas (vaga do dono, cobertura, buffer do núcleo, transição, Overlap -5, acima da capacidade ⚠, ajuste manual), em vez de uma frase longa.
- **Carteira no lugar de "sem party":** cada página mostra a carteira (ex.: `U1C2`), e a 5C Schedule aparece como "Overlap · dia de treinamento (-5)".
- **Filtro por time:** não aparece nas escalas da estrutura nova, onde não se aplica.
- **Respiro:** as células da Grade ficaram um pouco mais largas e com linhas mais espaçadas.

### 4. Outras mudanças desta tarde (já entregues e geradas)
- Vagas preenchidas da página de maior faturamento para a de menor, com remanejamento.
- Overlap na página 5C Schedule com `-5`, cobrindo lacunas quando sobra gente.
- Acúmulo de postos até 15% acima da capacidade, sinalizado com ⚠.
- Melhores SRs (venda/hora na página) primeiro nas páginas dos tiers SS, A e B.
- Overlap em dias diferentes para cada SR, em 5 folgas do ciclo, com no máximo 5 dias seguidos.
- Busca por nome nos campos de colaborador e de página, e nas listas grandes.
- Uma conexão só nas ações que gravam, com novas tentativas por até 15 s, e `ESC_DB_HOST` só para o módulo.
- Nomes de cargo novos (OUM, OM, OS, SR) nas telas da estrutura nova.

## Próximos passos
1. Instalar o zip novo, que tem as correções da revisão e o polimento.
2. Entrar no MIS Portal pelo navegador do app Claude, para os testes de tela.
3. Revisar e publicar 26/10 e 02/11, e postar os CSVs até 29/10 às 18:00.
4. Autorizar o commit desta rodada.
5. Depois da virada:
   - telas de Buffers e Sugestões para a estrutura nova;
   - trava contra recálculo em dobro;
   - telas de equipe e níveis.
