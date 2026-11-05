SET NAMES utf8mb4;
-- =====================================================================
-- ES_126_v401_igual_v40_fora_das_trocas.sql  (05/11, aplicado pelo Claude via mysql.exe)
--
-- O rascunho v40.1 (plano 117, "Criar rascunho a partir desta", mínimo de mudanças) mexeu, além das trocas dos
-- supervisores (ES_125), em células do turno C de 05/11 que já estão no ar pela v40 (plano 116):
--   - Lucas Dupont (L099242) saiu de Blake, Mario Adrion, Katie Flood, Bianca e Eden The Doll (folga de rotação no
--     cadastro, mas ele está trabalhando pelo site);
--   - Lucas Wallace (L704704) ganhou a Charly além da Holly Jane, que é exclusiva;
--   - Alexandre Mortagua (A101727) saiu do 5C Schedule para a Courtney.
-- Para o repost levar só as trocas pedidas, essas células de 05/11 T4/T5 voltam a ser como na v40, travadas.
-- =====================================================================

USE `5c_escalas`;

START TRANSACTION;

DROP TEMPORARY TABLE IF EXISTS tmp_vol;
CREATE TEMPORARY TABLE tmp_vol (pagina VARCHAR(150) NOT NULL PRIMARY KEY);
INSERT INTO tmp_vol VALUES ('5C Schedule'), ('Bianca'), ('Blake'), ('Eden The Doll'), ('Katie Flood'), ('Mario Adrion'), ('Charly'), ('Courtney');

DELETE a FROM esc_plano_alocacao a JOIN tmp_vol v ON v.pagina = a.pagina
 WHERE a.plano_id = 117 AND a.dia = '2026-11-05' AND a.bloco IN ('T4', 'T5');
INSERT INTO esc_plano_alocacao (plano_id, dia, bloco, turno, page_id, pagina, posto_id, vaga, rep_code, papel, origem, travada, motivo, alterado_em, alterado_por)
SELECT 117, a.dia, a.bloco, a.turno, a.page_id, a.pagina, a.posto_id, a.vaga, a.rep_code, a.papel, a.origem, 1,
       LEFT(CONCAT('igual à v40, já no ar (ES_126); ', IFNULL(a.motivo, '')), 255), NOW(), NULL
  FROM esc_plano_alocacao a JOIN tmp_vol v ON v.pagina = a.pagina
 WHERE a.plano_id = 116 AND a.dia = '2026-11-05' AND a.bloco IN ('T4', 'T5');

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
VALUES (NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'grade.ajustar', 'esc_plano_alocacao', '117:2026-11-05:C:ES_126', NULL,
        '{"script":"ES_126","paginas":"5C Schedule, Bianca, Blake, Eden The Doll, Katie Flood, Mario Adrion, Charly, Courtney","como":"v40"}', NULL);

COMMIT;

-- Conferência: diferenças entre a v40.1 e a v40 no turno C de 05/11 (só as trocas dos supervisores)
SELECT y.pagina, x.v40, y.v401
  FROM (SELECT pagina, GROUP_CONCAT(DISTINCT CONCAT(IFNULL(UPPER(rep_code), '-'), IF(papel = 'ASSISTENTE', '#', '')) ORDER BY rep_code) v401
          FROM esc_plano_alocacao WHERE plano_id = 117 AND dia = '2026-11-05' AND bloco IN ('T4', 'T5') GROUP BY pagina) y
  LEFT JOIN (SELECT pagina, GROUP_CONCAT(DISTINCT CONCAT(IFNULL(UPPER(rep_code), '-'), IF(papel = 'ASSISTENTE', '#', '')) ORDER BY rep_code) v40
               FROM esc_plano_alocacao WHERE plano_id = 116 AND dia = '2026-11-05' AND bloco IN ('T4', 'T5') GROUP BY pagina) x ON x.pagina = y.pagina
 WHERE x.v40 IS NULL OR x.v40 <> y.v401 ORDER BY y.pagina;
