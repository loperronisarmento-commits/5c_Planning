-- ES_217 (24/11/2026): KPI dos SRs como indicador oficial (1.38.0, item A da revisão de 24/11).
-- O código não lê mais o mérito no Gerar. As chaves que o Gerar usa passam a ter nome de KPI.
-- Os valores e os padrões do código são os mesmos (1, 1, 1, 1 e 2), então a ordem com o deploy não importa.
-- O grupo "merito" fica como está: a tela de Parâmetros o mostra em Legacy, só leitura.
-- A coluna esc_sr_kpi.moedas sai no ES_218, depois do deploy (o código antigo ainda grava nela).
SET NAMES utf8mb4;

-- Antes
SELECT chave, grupo, valor, LEFT(descricao, 90) AS descricao FROM `5c_escalas`.esc_parametro
 WHERE chave LIKE 'v4_merito%' OR chave IN ('merito_desempenho_teto', 'v4_fonte_desempenho') ORDER BY chave;

UPDATE `5c_escalas`.esc_parametro SET chave = 'v4_kpi_passo0',
       descricao = 'A-01/P-01: no passo 0 escolhe por meta na página, nível do KPI, score e tempo de casa (0 desliga)'
 WHERE chave = 'v4_merito_faixa_passo0';
UPDATE `5c_escalas`.esc_parametro SET chave = 'v4_kpi_abaixo_fora_topo',
       descricao = 'A-02/K-04: o SR de nível 0 só fica como regular em página prioritária sem outro disponível (0 desliga)'
 WHERE chave = 'v4_merito_abaixo_fora_topo';
UPDATE `5c_escalas`.esc_parametro SET chave = 'v4_kpi_acumulo',
       descricao = 'A-03: no acúmulo e na sobrecarga o nível do KPI é o 1º critério (o nível 0 vai por último; 0 desliga)'
 WHERE chave = 'v4_merito_faixa_acumulo';
UPDATE `5c_escalas`.esc_parametro SET chave = 'v4_kpi_remanejar',
       descricao = 'A-04: no remanejamento sobe o SR de melhor nível do KPI (no empate, o do posto que menos fatura; 0 desliga)'
 WHERE chave = 'v4_merito_faixa_remanejar';
UPDATE `5c_escalas`.esc_parametro SET chave = 'v4_meta_teto', grupo = 'v4',
       descricao = 'Teto do atingimento (realizado ÷ meta) no PDF de metas da Grade; evita distorção com meta muito pequena'
 WHERE chave = 'merito_desempenho_teto';
-- O Gerar sempre usa o KPI: a chave que alternava para o mérito não é mais lida.
DELETE FROM `5c_escalas`.esc_parametro WHERE chave = 'v4_fonte_desempenho';

-- Depois
SELECT chave, grupo, valor, LEFT(descricao, 90) AS descricao FROM `5c_escalas`.esc_parametro
 WHERE chave LIKE 'v4_kpi_%' OR chave LIKE 'v4_merito%' OR chave IN ('v4_meta_teto', 'merito_desempenho_teto', 'v4_fonte_desempenho') ORDER BY chave;
