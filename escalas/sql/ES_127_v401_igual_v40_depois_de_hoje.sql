SET NAMES utf8mb4;
-- =====================================================================
-- ES_127_v401_igual_v40_depois_de_hoje.sql  (05/11, aplicado pelo Claude via mysql.exe)
--
-- Complemento do ES_126: o rascunho v40.1 (plano 117, mínimo de mudanças) também mudou células de 05/11 T6 a 08/11
-- em relação à v40 publicada (plano 116): Ellie D, Sebi Barraza, Alix Lynx, Krystal, Matty Caron, Sean Adams,
-- Ariana Marie e 13 páginas no A de 08/11. O pedido era repostar só as trocas dos supervisores de 05/11 C, e o CSV
-- "a partir de 05/11 C" leva também esses dias: as células de 05/11 T6 em diante voltam a ser como na v40, travadas.
-- =====================================================================

USE `5c_escalas`;

START TRANSACTION;

DROP TEMPORARY TABLE IF EXISTS tmp_d117;
CREATE TEMPORARY TABLE tmp_d117 AS
SELECT dia, bloco, pagina, GROUP_CONCAT(DISTINCT CONCAT(IFNULL(UPPER(rep_code), '-'), IF(papel = 'ASSISTENTE', '#', '')) ORDER BY rep_code) r
  FROM esc_plano_alocacao WHERE plano_id = 117 AND (dia > '2026-11-05' OR (dia = '2026-11-05' AND bloco = 'T6')) GROUP BY dia, bloco, pagina;
DROP TEMPORARY TABLE IF EXISTS tmp_d116;
CREATE TEMPORARY TABLE tmp_d116 AS
SELECT dia, bloco, pagina, GROUP_CONCAT(DISTINCT CONCAT(IFNULL(UPPER(rep_code), '-'), IF(papel = 'ASSISTENTE', '#', '')) ORDER BY rep_code) r
  FROM esc_plano_alocacao WHERE plano_id = 116 AND (dia > '2026-11-05' OR (dia = '2026-11-05' AND bloco = 'T6')) GROUP BY dia, bloco, pagina;
DROP TEMPORARY TABLE IF EXISTS tmp_dif;
CREATE TEMPORARY TABLE tmp_dif AS
SELECT y.dia, y.bloco, y.pagina FROM tmp_d117 y LEFT JOIN tmp_d116 x ON x.dia = y.dia AND x.bloco = y.bloco AND x.pagina = y.pagina WHERE x.r IS NULL OR x.r <> y.r;
INSERT INTO tmp_dif SELECT x.dia, x.bloco, x.pagina FROM tmp_d116 x LEFT JOIN tmp_d117 y ON x.dia = y.dia AND x.bloco = y.bloco AND x.pagina = y.pagina WHERE y.r IS NULL;
SELECT COUNT(*) AS celulas_a_voltar FROM tmp_dif;

DELETE a FROM esc_plano_alocacao a JOIN tmp_dif d ON d.dia = a.dia AND d.bloco = a.bloco AND d.pagina = a.pagina WHERE a.plano_id = 117;
INSERT INTO esc_plano_alocacao (plano_id, dia, bloco, turno, page_id, pagina, posto_id, vaga, rep_code, papel, origem, travada, motivo, alterado_em, alterado_por)
SELECT 117, a.dia, a.bloco, a.turno, a.page_id, a.pagina, a.posto_id, a.vaga, a.rep_code, a.papel, a.origem, 1,
       LEFT(CONCAT('igual à v40, já no ar (ES_127); ', IFNULL(a.motivo, '')), 255), NOW(), NULL
  FROM esc_plano_alocacao a JOIN tmp_dif d ON d.dia = a.dia AND d.bloco = a.bloco AND d.pagina = a.pagina WHERE a.plano_id = 116;

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
SELECT NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'grade.ajustar', 'esc_plano_alocacao', '117:2026-11-05T6+:ES_127', NULL,
       JSON_OBJECT('script', 'ES_127', 'celulas', (SELECT COUNT(*) FROM tmp_dif), 'como', 'v40'), NULL;

COMMIT;

-- Conferência: de 05/11 T6 em diante a v40.1 tem de ser igual à v40 (0 linhas)
SELECT y.dia, y.bloco, y.pagina
  FROM (SELECT dia, bloco, pagina, GROUP_CONCAT(DISTINCT CONCAT(IFNULL(UPPER(rep_code), '-'), IF(papel = 'ASSISTENTE', '#', '')) ORDER BY rep_code) r
          FROM esc_plano_alocacao WHERE plano_id = 117 AND (dia > '2026-11-05' OR (dia = '2026-11-05' AND bloco = 'T6')) GROUP BY dia, bloco, pagina) y
  LEFT JOIN (SELECT dia, bloco, pagina, GROUP_CONCAT(DISTINCT CONCAT(IFNULL(UPPER(rep_code), '-'), IF(papel = 'ASSISTENTE', '#', '')) ORDER BY rep_code) r
               FROM esc_plano_alocacao WHERE plano_id = 116 AND (dia > '2026-11-05' OR (dia = '2026-11-05' AND bloco = 'T6')) GROUP BY dia, bloco, pagina) x
    ON x.dia = y.dia AND x.bloco = y.bloco AND x.pagina = y.pagina
 WHERE x.r IS NULL OR x.r <> y.r;
