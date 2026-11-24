-- ES_216 (24/11/2026): vagas de equipe sobrepostas (B2 da auditoria de 24/11).
-- O Gerar de uma semana anterior, rodado depois do da semana seguinte, abria uma segunda linha sem fim
-- para o mesmo SR, posto e vaga (escEquipesCompletar; corrigido no código na 1.38.0).
-- Limpeza: em cada par sobreposto, a linha mais antiga termina na véspera da mais nova.
-- Nenhuma linha é apagada; a mais nova (mesmo status) continua valendo.
SET NAMES utf8mb4;

-- Antes: 189 pares (186 com as duas vigentes hoje)
SELECT COUNT(*) AS pares, SUM(a.vigente_ate IS NULL) AS antiga_aberta
  FROM `5c_escalas`.esc_vaga_equipe a
  JOIN `5c_escalas`.esc_vaga_equipe b ON b.posto_id = a.posto_id AND b.vaga = a.vaga AND UPPER(b.rep_code) = UPPER(a.rep_code)
   AND b.id <> a.id AND b.vigente_desde > a.vigente_desde AND (a.vigente_ate IS NULL OR a.vigente_ate >= b.vigente_desde);

UPDATE `5c_escalas`.esc_vaga_equipe a
  JOIN `5c_escalas`.esc_vaga_equipe b ON b.posto_id = a.posto_id AND b.vaga = a.vaga AND UPPER(b.rep_code) = UPPER(a.rep_code)
   AND b.id <> a.id AND b.vigente_desde > a.vigente_desde AND (a.vigente_ate IS NULL OR a.vigente_ate >= b.vigente_desde)
   SET a.vigente_ate = b.vigente_desde - INTERVAL 1 DAY;

-- Depois: 0 pares; vagas vigentes hoje por (posto, vaga, SR) sem repetição
SELECT COUNT(*) AS pares
  FROM `5c_escalas`.esc_vaga_equipe a
  JOIN `5c_escalas`.esc_vaga_equipe b ON b.posto_id = a.posto_id AND b.vaga = a.vaga AND UPPER(b.rep_code) = UPPER(a.rep_code)
   AND b.id <> a.id AND b.vigente_desde > a.vigente_desde AND (a.vigente_ate IS NULL OR a.vigente_ate >= b.vigente_desde);
SELECT COUNT(*) AS repetidas FROM (SELECT posto_id, vaga, UPPER(rep_code) r, COUNT(*) n FROM `5c_escalas`.esc_vaga_equipe
  WHERE vigente_desde <= CURDATE() AND (vigente_ate IS NULL OR vigente_ate >= CURDATE()) GROUP BY posto_id, vaga, UPPER(rep_code) HAVING n > 1) x;
