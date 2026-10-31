SET NAMES utf8mb4;
-- =====================================================================
-- ES_39 · Desfazer saídas aplicadas por engano e postos duplicados (30/10/2026)
--
-- Parte 1 — postos duplicados (defeito da saída de página, corrigido no 1.13.5):
--   quando a saída refazia um posto que tinha outras páginas, a cópia "-S" saía
--   sem a data de fim do original. As cópias do MODELO_27OUT (válidas só em 31/10)
--   ficaram abertas e passaram a valer junto com os postos do Capacity a partir de
--   01/11: 30 páginas/turno com dois postos (ex.: Thalia 147m C, Mia Melons C,
--   Keera A/C, Blair VIP D...). O gerador dividia as pessoas entre os dois e
--   sobrava lacuna. E as cópias do Capacity ("CAP...-S") levaram também a
--   equipe que só valia em 31/10 (duas equipes no mesmo posto).
--   Corrige: cópias MODELO_27OUT-S terminam em 31/10 (posto e equipe); nas cópias
--   CAP...-S que continuam (Mia Melons C e Isla D) fecha a equipe antiga de 31/10.
--
-- Parte 2 — saídas de Cynthia, Ms Red VIP e Ms Red Free (lote de 30/10 20:14):
--   as três continuam ativas no site da 5C, com vendas. Volta tudo como estava:
--   página no Capacity, postos (MODELO_27OUT em 31/10, Capacity a partir de
--   01/11) e equipes; fecha as cópias refeitas sem elas; marca a checagem como
--   "não saiu". As equipes dos postos do Capacity voltam assim: em cada vaga, a
--   equipe mais recente (montador de 29/10 15:31) fica aberta e a anterior volta
--   a valer só em 31/10 (as frações somam 1 em cada vaga).
--   Se alguma delas saiu de verdade, tire o nome do INSERT INTO es39_paginas
--   antes de rodar (os postos dela ficam como estão).
--
-- Trava: a parte 2 só roda se a Cynthia ainda está fechada pela saída de 30/10.
-- Depois de rodar: Capacity › recalcular 30/10; 5C Escalas › Recalcular as
-- semanas 26/10 e 02/11, conferir e publicar de novo.
-- =====================================================================

-- As tabelas temporárias (es39_*) ficam no 5c_escalas: sem o USE o Navicat para com
-- "1046 - No database selected" (1ª versão).
USE `5c_escalas`;

SET @ok := (SELECT COUNT(*) FROM `5c_planning`.`fc_pagina_carteira`
             WHERE pagina = 'Cynthia' AND vigente_ate = '2026-11-01' AND obs LIKE 'Saiu em 30/10/2026%');

START TRANSACTION;

-- ---------- Parte 1: postos duplicados ----------
UPDATE `5c_escalas`.`esc_vaga_equipe` v JOIN `5c_escalas`.`esc_posto` p ON p.id = v.posto_id
   SET v.vigente_ate = '2026-10-31'
 WHERE p.versao = 'MODELO_27OUT-S' AND v.vigente_ate IS NULL;
UPDATE `5c_escalas`.`esc_posto` SET vigente_ate = '2026-10-31'
 WHERE versao = 'MODELO_27OUT-S' AND vigente_ate IS NULL;

-- Cópias CAP...-S abertas: fecha quem veio de uma equipe que só valia em 31/10
-- (no posto de origem havia equipe mais nova na mesma vaga).
DROP TEMPORARY TABLE IF EXISTS es39_equipe_velha;
CREATE TEMPORARY TABLE es39_equipe_velha AS
SELECT s.id
  FROM `5c_escalas`.`esc_vaga_equipe` s
  JOIN `5c_escalas`.`esc_vaga_equipe` o
    ON o.posto_id = CASE s.posto_id WHEN 448 THEN 298 WHEN 449 THEN 305 END
   AND o.vaga = s.vaga AND o.rep_code = s.rep_code AND o.ordem = s.ordem AND o.fatia = s.fatia
 WHERE s.posto_id IN (448, 449) AND s.vigente_ate IS NULL
   AND o.criado_em < (SELECT MAX(w.criado_em) FROM `5c_escalas`.`esc_vaga_equipe` w WHERE w.posto_id = o.posto_id AND w.vaga = o.vaga);
UPDATE `5c_escalas`.`esc_vaga_equipe` v JOIN es39_equipe_velha x ON x.id = v.id
   SET v.vigente_desde = '2026-10-29', v.vigente_ate = '2026-10-29',
       v.motivo = LEFT(CONCAT('ES_39: equipe só de 31/10 copiada pela saída; ', IFNULL(v.motivo, '')), 255);

-- ---------- Parte 2: Cynthia, Ms Red VIP e Ms Red Free não saíram ----------
DROP TEMPORARY TABLE IF EXISTS es39_paginas;
CREATE TEMPORARY TABLE es39_paginas (pagina VARCHAR(100) PRIMARY KEY);
INSERT INTO es39_paginas VALUES ('Cynthia'), ('Ms Red VIP'), ('Ms Red Free');

-- Postos fechados pelo lote que voltam (só os que têm páginas da lista)
DROP TEMPORARY TABLE IF EXISTS es39_volta;
CREATE TEMPORARY TABLE es39_volta (posto_id INT PRIMARY KEY, copia_id INT NULL);
INSERT INTO es39_volta (posto_id, copia_id) VALUES
  (27, NULL), (34, NULL), (42, NULL), (282, NULL), (290, NULL), (299, NULL),  -- Cynthia
  (36, 440), (295, 441),                                                     -- Cynthia + Isla
  (121, NULL), (368, NULL), (379, NULL), (389, NULL),                        -- Ms Red
  (107, 450), (126, 451), (117, 452);                                        -- Ms Red + outras
-- tira da volta o posto que não tem nenhuma página que ficou na lista
DELETE v FROM es39_volta v
 WHERE NOT EXISTS (SELECT 1 FROM `5c_escalas`.`esc_posto_pagina` pp JOIN es39_paginas g ON g.pagina = pp.pagina WHERE pp.posto_id = v.posto_id);

-- Equipes: MODELO_27OUT voltam a 31/10; Capacity: a mais recente da vaga abre em 01/11, a anterior fica em 31/10
DROP TEMPORARY TABLE IF EXISTS es39_equipe;
CREATE TEMPORARY TABLE es39_equipe AS
SELECT e.id, p.versao,
       e.criado_em >= (SELECT MAX(w.criado_em) FROM `5c_escalas`.`esc_vaga_equipe` w WHERE w.posto_id = e.posto_id AND w.vaga = e.vaga) AS recente
  FROM `5c_escalas`.`esc_vaga_equipe` e
  JOIN `5c_escalas`.`esc_posto` p ON p.id = e.posto_id
  JOIN es39_volta v ON v.posto_id = e.posto_id
 WHERE e.vigente_desde = '2026-10-29' AND e.vigente_ate = '2026-10-29';
UPDATE `5c_escalas`.`esc_vaga_equipe` e JOIN es39_equipe x ON x.id = e.id
   SET e.vigente_desde = IF(x.versao = 'CAP20261028_1607' AND x.recente, '2026-11-01', '2026-10-31'),
       e.vigente_ate   = IF(x.versao = 'CAP20261028_1607' AND x.recente, NULL, '2026-10-31')
 WHERE @ok = 1;

UPDATE `5c_escalas`.`esc_posto` p JOIN es39_volta v ON v.posto_id = p.id
   SET p.vigente_desde = IF(p.versao = 'CAP20261028_1607', '2026-11-01', '2026-10-31'),
       p.vigente_ate   = IF(p.versao = 'CAP20261028_1607', NULL, '2026-10-31')
 WHERE @ok = 1 AND p.vigente_ate = '2026-10-29';

-- Cópias refeitas sem as páginas: fecham (vigência de um dia antes da virada, como na saída)
UPDATE `5c_escalas`.`esc_vaga_equipe` e JOIN es39_volta v ON v.copia_id = e.posto_id
   SET e.vigente_desde = '2026-10-29', e.vigente_ate = '2026-10-29'
 WHERE @ok = 1;
UPDATE `5c_escalas`.`esc_posto` p JOIN es39_volta v ON v.copia_id = p.id
   SET p.vigente_desde = '2026-10-29', p.vigente_ate = '2026-10-29'
 WHERE @ok = 1;

-- Capacity: a página volta a valer
UPDATE `5c_planning`.`fc_pagina_carteira` c JOIN es39_paginas g ON g.pagina = c.pagina
   SET c.vigente_ate = NULL, c.obs = NULL
 WHERE @ok = 1 AND c.vigente_ate = '2026-11-01' AND c.obs LIKE 'Saiu em 30/10/2026%';

-- Checagem e registro da saída
UPDATE `5c_escalas`.`esc_saida_checagem` c JOIN es39_paginas g ON g.pagina = c.pagina
   SET c.decisao = 'NAO_SAIU', c.observacao = LEFT(CONCAT('ES_39: saída de 30/10 desfeita, página ativa no site. ', IFNULL(c.observacao, '')), 255)
 WHERE @ok = 1 AND c.decisao = 'CONFIRMADA';
UPDATE `5c_escalas`.`esc_saida_pagina` s JOIN es39_paginas g ON g.pagina = s.pagina
   SET s.erro = 'Desfeita pelo ES_39 (a página não saiu).'
 WHERE @ok = 1 AND s.id BETWEEN 7 AND 9;

COMMIT;

-- ---------- Conferência ----------
SELECT 'trava (1 = parte 2 rodou)' AS item, @ok AS valor
UNION ALL
SELECT 'página/turno com posto refeito duplicado em 02/11 (esperado 0)', COUNT(*) FROM (
  SELECT pp.pagina, p.turno FROM `5c_escalas`.`esc_posto_pagina` pp JOIN `5c_escalas`.`esc_posto` p ON p.id = pp.posto_id
   WHERE p.vigente_desde <= '2026-11-02' AND (p.vigente_ate IS NULL OR p.vigente_ate >= '2026-11-02')
   GROUP BY pp.pagina, p.turno HAVING COUNT(*) > 1 AND SUM(p.versao LIKE '%-S') > 0) d
UNION ALL
SELECT CONCAT('postos abertos em 02/11: ', pp.pagina), COUNT(DISTINCT p.id)
  FROM `5c_escalas`.`esc_posto_pagina` pp JOIN `5c_escalas`.`esc_posto` p ON p.id = pp.posto_id
 WHERE pp.pagina IN ('Cynthia', 'Ms Red VIP', 'Ms Red Free', 'Thalia 147m')
   AND p.vigente_desde <= '2026-11-02' AND (p.vigente_ate IS NULL OR p.vigente_ate >= '2026-11-02')
 GROUP BY pp.pagina
UNION ALL
SELECT CONCAT('Capacity: ', c.pagina, ' ', c.carteira), IFNULL(c.vigente_ate, 'aberta')
  FROM `5c_planning`.`fc_pagina_carteira` c WHERE c.pagina IN ('Cynthia', 'Ms Red VIP', 'Ms Red Free');
