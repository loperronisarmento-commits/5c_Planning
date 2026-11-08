SET NAMES utf8mb4;
-- =====================================================================
-- ES_134_posto_nas_vazias_0911.sql  (08/11, aplicado pelo Claude via mysql.exe)
--
-- Pedido do usuário (08/11): a lista de horas extra (tela Horas e PDF do Overtime) não mostrava as células vazias
-- das escalas dos OM, porque o ES_128, o ES_130 e o ES_132 gravaram as células travadas sem posto (posto_id NULL),
-- e a lista só conta vaga com posto. Aqui cada célula vazia (sem ninguém no bloco da página, fora treinamento e
-- 5C Schedule) ganha o posto principal da página no turno: posto que não é de assistente, com a página como
-- principal (não apoio), vigente na semana, e só quando ele é único. Página de apoio (ex.: Krystal 141t) fica
-- sem posto: ela segue a principal do posto, como o site já faz. Ninguém escalado muda.
-- Depois de um recálculo, reaplicar na ordem: ES_128, ES_130, ES_132, ES_133 e este.
-- =====================================================================

USE `5c_escalas`;
SET @plano := 132;

DROP TEMPORARY TABLE IF EXISTS tmp_map;
CREATE TEMPORARY TABLE tmp_map AS
SELECT pp.page_id, p.turno, MIN(p.id) AS posto_id, COUNT(DISTINCT p.id) AS n
  FROM esc_posto p JOIN esc_posto_pagina pp ON pp.posto_id = p.id
 WHERE p.tipo <> 'assistente' AND pp.apoio = 0
   AND p.vigente_desde <= '2026-11-15' AND (p.vigente_ate IS NULL OR p.vigente_ate >= '2026-11-09')
 GROUP BY pp.page_id, p.turno;

DROP TEMPORARY TABLE IF EXISTS tmp_cheia;
CREATE TEMPORARY TABLE tmp_cheia AS
SELECT DISTINCT dia, bloco, page_id FROM esc_plano_alocacao WHERE plano_id = @plano AND rep_code IS NOT NULL;
ALTER TABLE tmp_cheia ADD INDEX (page_id, dia, bloco);

DROP TEMPORARY TABLE IF EXISTS tmp_vazia;
CREATE TEMPORARY TABLE tmp_vazia AS
SELECT a.id, m.posto_id
  FROM esc_plano_alocacao a
  JOIN tmp_map m ON m.page_id = a.page_id AND m.turno = a.turno AND m.n = 1
  LEFT JOIN tmp_cheia c ON c.page_id = a.page_id AND c.dia = a.dia AND c.bloco = a.bloco
 WHERE a.plano_id = @plano AND a.rep_code IS NULL AND a.posto_id IS NULL AND a.origem <> 'TREINO' AND a.page_id <> 3
   AND c.page_id IS NULL;

START TRANSACTION;
UPDATE esc_plano_alocacao a JOIN tmp_vazia v ON v.id = a.id SET a.posto_id = v.posto_id;
INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
SELECT NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'grade.ajustar', 'esc_plano_alocacao', CONCAT(@plano, ':2026-11-09:ES_134'), NULL,
       JSON_OBJECT('script', 'ES_134', 'celulas_com_posto', (SELECT COUNT(*) FROM tmp_vazia)), NULL;
COMMIT;

SELECT COUNT(*) AS celulas_ligadas_ao_posto FROM tmp_vazia;
-- O que a tela Horas passa a ver (mesma consulta de pages/horas.php)
SELECT COUNT(*) AS vagas_bloco, COUNT(DISTINCT a.posto_id) AS postos
  FROM esc_plano_alocacao a WHERE a.plano_id = @plano AND a.rep_code IS NULL AND a.posto_id IS NOT NULL AND a.origem <> 'TREINO';
