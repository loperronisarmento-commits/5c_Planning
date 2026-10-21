-- =====================================================================
-- ES_02_carga_inicial.sql
-- Primeira carga do 5c_escalas a partir do headcount vigente.
--
-- O QUE FAZ
--   Cria a configuração de cada colaborador (esc_colaborador_config)
--   a partir de 5c_headcount.headcount_turno:
--     - turno fixo:  T2T3 -> A, T4T5 -> C, T6T1 -> D
--     - tipo:        Time -> TIME, Buffer -> BUFFER
--     - modelo:      cargo com "Primaris" -> "Primaris 3x1 + admin";
--                    os demais -> "3x1"
--     - trilha:      fica vazia; a releitura do realizado (scheduleday)
--                    calcula depois
--   Quem tem mais de um registro vigente no headcount entra uma vez só,
--   com o registro mais recente.
--
-- Só LÊ o 5c_headcount. Só GRAVA no 5c_escalas.
-- Pode rodar de novo: quem já tem configuração vigente não é duplicado.
--
-- Rodar depois do ES_01.
-- =====================================================================

USE `5c_escalas`;

-- 1) Conferência antes de gravar (só leitura): quantos entram por modelo e turno.
WITH h AS (
  SELECT code, cargo, tipo_alocacao, turno,
         ROW_NUMBER() OVER (PARTITION BY code ORDER BY vigente_desde DESC, id DESC) AS rn
    FROM `5c_headcount`.`headcount_turno`
   WHERE vigente_desde <= CURDATE()
     AND (vigente_ate IS NULL OR vigente_ate >= CURDATE())
)
SELECT IF(cargo LIKE '%Primaris%', 'Primaris 3x1 + admin', '3x1') AS modelo,
       ELT(FIELD(turno, 'T2T3', 'T4T5', 'T6T1'), 'A', 'C', 'D') AS turno,
       IF(tipo_alocacao = 'Buffer', 'BUFFER', 'TIME') AS tipo,
       COUNT(*) AS pessoas
  FROM h
 WHERE rn = 1
 GROUP BY modelo, turno, tipo
 ORDER BY modelo, turno, tipo;

-- 2) Gravação.
INSERT INTO esc_colaborador_config
       (rep_code, modelo_id, tipo, turno, trilha, ancora, pode_assistente,
        vigente_desde, observacao, origem)
WITH h AS (
  SELECT code, cargo, tipo_alocacao, turno,
         ROW_NUMBER() OVER (PARTITION BY code ORDER BY vigente_desde DESC, id DESC) AS rn
    FROM `5c_headcount`.`headcount_turno`
   WHERE vigente_desde <= CURDATE()
     AND (vigente_ate IS NULL OR vigente_ate >= CURDATE())
)
SELECT UPPER(h.code),
       m.id,
       IF(h.tipo_alocacao = 'Buffer', 'BUFFER', 'TIME'),
       ELT(FIELD(h.turno, 'T2T3', 'T4T5', 'T6T1'), 'A', 'C', 'D'),
       NULL,
       NULL,
       1,
       CURDATE(),
       CONCAT('carga inicial do headcount: ', h.cargo),
       'CARGA_HEADCOUNT'
  FROM h
  JOIN esc_modelo_escala m
    ON m.nome = IF(h.cargo LIKE '%Primaris%', 'Primaris 3x1 + admin', '3x1')
 WHERE h.rn = 1
   AND h.turno IN ('T2T3', 'T4T5', 'T6T1')
   AND NOT EXISTS (
         SELECT 1 FROM esc_colaborador_config c
          WHERE c.rep_code = UPPER(h.code) COLLATE utf8mb4_unicode_ci
            AND (c.vigente_ate IS NULL OR c.vigente_ate >= CURDATE())
       );

-- 3) Conferência depois de gravar.
SELECT m.nome AS modelo, c.turno, c.tipo, COUNT(*) AS pessoas
  FROM esc_colaborador_config c
  JOIN esc_modelo_escala m ON m.id = c.modelo_id
 WHERE c.vigente_ate IS NULL OR c.vigente_ate >= CURDATE()
 GROUP BY m.nome, c.turno, c.tipo
 ORDER BY m.nome, c.turno, c.tipo;

-- 4) Primeiro acesso: coloque aqui o seu user_id do MIS Portal como ADMIN
--    (o mesmo que aparece em mis_module_access_schedule_anomalies).
-- INSERT IGNORE INTO esc_acesso (user_id, nome, perfil) VALUES (<seu_user_id>, '<seu nome>', 'ADMIN');
