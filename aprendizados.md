# Aprendizados do 5C Planning

## Decisões de arquitetura
1. **O banco é a fonte de verdade e o Claude só lê.** Toda mudança vira um script numerado e versionado (ES_NN, 5C_Planning_NN), com cabeçalho do que faz e por quê. Isso dá:
   - auditoria;
   - ordem de instalação;
   - um script "desfazer" quando a mudança é arriscada.
2. **Cada schema tem um dono.** O 5C Escalas grava só em `5c_escalas`. A escrita no Capacity passa por uma lista fechada (`escCapacityGravar`) e não é espalhada pelo código.
3. **Regras viram dados, não código.** Os pedidos dos managers ("fulano não faz tal página", "equipe fixa", "sem assistente") são linhas com vigência em tabelas: `esc_pagina_prioridade`, `esc_pagina_assistente` e `esc_parametro`.
   - O motor aplica a regra em toda geração.
   - Editar células à mão se perde no próximo Gerar.
4. **A vigência fica em cada linha** (`vigente_desde` e `vigente_ate`). Ela permite:
   - férias e coberturas por dia, sem apagar histórico;
   - trocas futuras sem mexer no passado.
5. **O motor é feito em passos ordenados**, com uma prioridade única: faturamento, nunca volume.
   - A fila é por modelo, porque as páginas da mesma modelo entram juntas.
   - Os passos são: equipe fixa → melhores → vagas → acúmulo → sobrecarga.
   - O papel REGULAR ou ASSISTENTE só é decidido no fim.
6. **Proteção do publicado com filtro.** Manter o que já foi publicado evita trocas desnecessárias. Só vale para quem ainda está na estrutura, na mesma unidade e turno; se não, quem saiu volta.
7. **Distribuição fixa no lugar de automática onde a gestão decide.** A tabela `fc_posto_fixo` é seguida exatamente, e a regra automática fica só para as exceções.

## Padrões de código (PHP do módulo)
- As funções têm prefixo do módulo e da área: `escMotor*`, `escV4*`, `escEstrutura*`, `escMerito*`.
- Os textos ficam em pt-BR com `t()` e a tradução em `lang/en/*.php`.
- As escritas acontecem dentro de `escTx` (transação), que aceita ser aninhada. Leituras com trava usam `FOR UPDATE` só na conexão de gravação.
- Toda mudança grava no `esc_log`: quem, ação, entidade, antes e depois em JSON.
- Os parâmetros são lidos por `escV4Param('chave', 'padrão')`, para que a falta da linha no banco não quebre a geração.
- Compatibilidade com PHP 7: sem `str_starts_with` nem match.
- Toda versão entra em `lib/versao.php` e `versao_en.php`, e aparece no rodapé e no changelog.
- Closures em PHP só enxergam o que está no `use (...)`. Esse erro tornou a 1.18.3 inútil e foi corrigido na 1.18.4.

## Lições operacionais
- **CALL pelo MCP faz rollback.** Usar o `mysql.exe` com `--defaults-extra-file`, sem expor a senha.
- **Deploy num servidor Windows por SSH:**
  - o shell remoto é o cmd.exe, que interpreta `|` e `&`; por isso o comando vai em `-EncodedCommand` (Base64 UTF-16);
  - sem subsistema SFTP, o envio usa `scp -O`;
  - o zip é montado dos arquivos do git (`deploy/montar_zip.py`), nunca à mão.
- **Uma troca antiga ainda "APLICADA" é reaplicada a cada Gerar.** O Gerar só aplica a mais recente de cada página, e as antigas vão para DESCARTADA (ES_71).
- **Nome não identifica pessoa.** Dois "Caetano Júnior" levaram à remoção errada (ES_70). Confirmar sempre pelo `rep_code`.
- **Simular antes de publicar.** O harness `resultados/sim_0211`:
  - roda o Gerar real com leituras do MySQL e gravação em SQLite;
  - gera PDFs e organogramas para os managers revisarem antes do site;
  - um arquivo com CRLF quebrou a chave da última coluna (`capacidade\r`), por isso é preciso normalizar as quebras de linha.
- **Escala já postada não muda pelo sistema.** O que já está no 5C Schedule é editado lá.
- **Comparar site e simulação.** As diferenças restantes eram dados vivos (vendas, pessoas) ou proteção do publicado, não bugs. Medir o percentual de células iguais ajuda a separar uma coisa da outra.

## Conceitos de negócio
- **Fator 1,5:** a rotação de 21 dias tem 14 dias trabalhados.
- **Buffer:** cobre faltas e picos e é calculado sobre o total do turno.
- **Mérito** = Desempenho × SLA × Presença ÷ (1 + pena). O absenteísmo oficial vem de `abs_adh_af`, sem AF_DOBRA.
- **Overlap:** o dia de treinamento da rotação, na página "5C Schedule".
- **REGULAR e ASSISTENTE:** o regular é o primeiro SR do bloco e os demais são assistentes (`#` no CSV). O papel não indica quem é dono nem quem cobre.
