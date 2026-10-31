SET NAMES utf8mb4;
-- =====================================================================
-- ES_44 · Correção com o mínimo de mudança no que já foi postado (31/10)
--
-- As versões postadas no site são a v23 (semana 26/10, 98 páginas) e a v19 (semana 02/11,
-- 100 páginas). O recálculo de hoje (12:29) gerou a semana do zero com a estrutura nova do
-- Capacity e mudou 94 páginas da 02/11 e 69 da 26/10. Este script cria, a partir das versões
-- postadas, uma subversão com só as correções:
--
-- Semana 02/11 → v19.1 (= v19 + Maddie). Muda só 1 CSV: o da Maddie, que é nova.
--   A Maddie (~3 fãs/h; no Capacity ela fica no posto da Britt VIP e da Britt OFTV) entra como
--   página a mais do SR que já está na Britt VIP em cada bloco. Ninguém sai de página, ninguém
--   ganha hora: o SR atende as duas no mesmo bloco (fica com 26 a 32 fãs/h, abaixo de 35).
--
-- Semana 26/10 → v23.1 (= v23 + Maddie a partir de 31/10 21:00 + sem turno dobrado em 01/11).
--   Mudam 5 CSVs: Maddie, Ms Red VIP, Ms Red Free, Mario Adrion e Yuni Yung.
--   - Maddie: como acima, com o SR da Britt VIP (31/10 T6 até 02/11 T1).
--   - Marina (M059686) e D014864 estavam em A e C em 01/11 (16 h). Os dois são do turno A na
--     estrutura nova: ficam no A; no C (T4 e T5) as páginas deles vão como página a mais para
--     SRs da U1 já no turno C com pouca carga:
--       Ms Red VIP e Ms Red Free (~13 fãs/h) → A101727 (que está na Angel, 14 fãs/h);
--       Mario Adrion e Yuni Yung (~16 fãs/h) → V147960 (que está na Ariana Marie, 16 fãs/h).
--
-- As células corrigidas ficam travadas (ajuste manual). Os rascunhos v25 (26/10) e v20 (02/11)
-- viram "descartado", para a v23.1 e a v19.1 serem as versões atuais.
--
-- Depois: na Grade de cada semana, conferir e Publicar a v23.1 e a v19.1. Na postagem, só as
-- páginas acima aparecem para postar; as outras ficam "igual" ao que já está no site.
-- NÃO usar "Gerar" nem "Recalcular" nessas semanas: isso gera de novo e volta a mexer em tudo.
-- =====================================================================

USE `5c_escalas`;

SET @ok := (SELECT COUNT(*) FROM `esc_plano` WHERE id IN (55, 58) AND status = 'PUBLICADO') = 2
       AND NOT EXISTS (SELECT 1 FROM `esc_plano` WHERE plano_anterior_id IN (55, 58) AND subversao > 0);

START TRANSACTION;

-- 1. Subversões a partir das versões postadas
INSERT INTO `esc_plano` (semana_inicio, semana_fim, time_nome, estrutura, unidade, versao, subversao, status, plano_anterior_id,
                         recalculo_id, capacity_calculo, entradas_hash, parametros, resumo, gerado_em, gerado_por)
SELECT semana_inicio, semana_fim, time_nome, estrutura, unidade, versao, 1, 'RASCUNHO', id,
       NULL, capacity_calculo, entradas_hash, parametros,
       JSON_SET(IFNULL(resumo, JSON_OBJECT()), '$.es44', 'correção mínima sobre a versão postada: Maddie junto da Britt VIP; sem turno dobrado em 01/11'),
       NOW(), NULL
  FROM `esc_plano` WHERE id IN (55, 58) AND @ok;

SET @p26 := (SELECT id FROM `esc_plano` WHERE plano_anterior_id = 55 AND versao = 23 AND subversao = 1);
SET @p02 := (SELECT id FROM `esc_plano` WHERE plano_anterior_id = 58 AND versao = 19 AND subversao = 1);

-- 2. Cópia da grade postada
INSERT INTO `esc_plano_alocacao` (plano_id, dia, bloco, turno, page_id, pagina, posto_id, vaga, rep_code, papel, origem, travada, motivo, alterado_em, alterado_por)
SELECT IF(plano_id = 55, @p26, @p02), dia, bloco, turno, page_id, pagina, posto_id, vaga, rep_code, papel, origem, travada, motivo, alterado_em, alterado_por
  FROM `esc_plano_alocacao` WHERE plano_id IN (55, 58) AND @ok;

-- 3. Maddie com o SR da Britt VIP, bloco a bloco (a partir da virada)
INSERT INTO `esc_plano_alocacao` (plano_id, dia, bloco, turno, page_id, pagina, posto_id, vaga, rep_code, papel, origem, travada, motivo, alterado_em, alterado_por)
SELECT plano_id, dia, bloco, turno, 412, 'Maddie', CASE turno WHEN 'A' THEN 619 WHEN 'C' THEN 625 ELSE 626 END, NULL, rep_code, 'REGULAR', 'MANUAL', 1,
       'ES_44: Maddie junto da Britt VIP (página a mais, ~3 fãs/h), sem mexer no que já foi postado', NOW(), NULL
  FROM `esc_plano_alocacao`
 WHERE plano_id IN (@p26, @p02) AND pagina = 'Britt VIP' AND rep_code IS NOT NULL
   AND (dia > '2026-10-31' OR (dia = '2026-10-31' AND bloco = 'T6'));

-- 4. Sem turno dobrado em 01/11 (semana 26/10): o C da Marina e do D014864 vai para quem já está no C
UPDATE `esc_plano_alocacao`
   SET rep_code = IF(pagina IN ('Ms Red VIP', 'Ms Red Free'), 'A101727', 'V147960'),
       origem = 'MANUAL', travada = 1, alterado_em = NOW(),
       motivo = IF(pagina IN ('Ms Red VIP', 'Ms Red Free'),
                   'ES_44: página a mais de A101727 (Angel) no C; a Marina fica só no A em 01/11',
                   'ES_44: página a mais de V147960 (Ariana Marie) no C; o D014864 fica só no A em 01/11')
 WHERE plano_id = @p26 AND dia = '2026-11-01' AND bloco IN ('T4', 'T5')
   AND ((rep_code = 'M059686' AND pagina IN ('Ms Red VIP', 'Ms Red Free'))
     OR (rep_code = 'D014864' AND pagina IN ('Mario Adrion', 'Yuni Yung')));

-- 5. Rascunhos gerados do zero ficam descartados
UPDATE `esc_plano` SET status = 'DESCARTADO'
 WHERE @ok AND semana_inicio IN ('2026-10-26', '2026-11-02') AND status = 'RASCUNHO' AND id NOT IN (@p26, @p02);

COMMIT;

-- ---------- Conferência ----------
SELECT 'trava (1 = rodou)' AS item, CAST(@ok AS CHAR) AS valor
UNION ALL SELECT 'versão atual 26/10 (esperado v23.1 RASCUNHO)', (SELECT CONCAT('v', versao, '.', subversao, ' ', status, ' id ', id) FROM `esc_plano` WHERE semana_inicio = '2026-10-26' AND status IN ('RASCUNHO','PUBLICADO') ORDER BY versao DESC, subversao DESC LIMIT 1)
UNION ALL SELECT 'versão atual 02/11 (esperado v19.1 RASCUNHO)', (SELECT CONCAT('v', versao, '.', subversao, ' ', status, ' id ', id) FROM `esc_plano` WHERE semana_inicio = '2026-11-02' AND status IN ('RASCUNHO','PUBLICADO') ORDER BY versao DESC, subversao DESC LIMIT 1)
UNION ALL SELECT 'células v23.1 (esperado 902 = 894 + 8 da Maddie)', CAST(COUNT(*) AS CHAR) FROM `esc_plano_alocacao` WHERE plano_id = @p26
UNION ALL SELECT 'células v19.1 (esperado 4890 = 4848 + 42 da Maddie)', CAST(COUNT(*) AS CHAR) FROM `esc_plano_alocacao` WHERE plano_id = @p02
UNION ALL SELECT 'Marina e D014864 em 2 turnos em 01/11 (esperado 0)', CAST(COUNT(*) AS CHAR) FROM (
  SELECT rep_code FROM `esc_plano_alocacao` WHERE plano_id = @p26 AND dia = '2026-11-01' AND bloco IN ('T2','T3','T4','T5') AND rep_code IN ('M059686','D014864')
  GROUP BY rep_code HAVING COUNT(DISTINCT turno) > 1) d;
