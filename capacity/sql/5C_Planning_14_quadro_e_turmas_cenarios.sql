-- =====================================================================
-- 5C | Forecast de WFM — 14. Quadro da estrutura de 01/11 e plano de turmas em 2 cenários
-- Schema: 5c_planning        Versão 1.0 — 27/10/2026        Rodar depois do 10, 11 e 12.
--
--   CAPACITY     = alvo é a necessidade dos postos (fc_necessidade_estrutura: titulares + buffer = 315 em 27/10)
--   QUADRO_12_OS = alvo é o quadro previsto: 12 reps por OS × 3 OS por unidade e turno (324)
--
-- Plano simulado em 27/10 (foto de 220 reps: A 76 · C 69 · D 75), mesmas regras do plano de 26/10:
--   turmas de 02/11 e 16/11 já pedidas; depois 07/12, 11/01/2027 e a cada 14 dias; teto de 20 por turma;
--   14 dias de treinamento; saídas de 6,4% do quadro por mês (A 24% · C 41% · D 36%);
--   novatos saem 12% em 30 dias e mais 11% até 90 dias.
--
-- NÃO mexe na fc_contratacoes (o plano oficial, que alimenta a previsão de HC). Para adotar um
-- cenário, use o bloco 5 (comentado) depois de decidir.
-- =====================================================================
SET NAMES utf8mb4;
USE `5c_planning`;

INSERT INTO fc_parametros (chave, valor, descricao) VALUES
 ('reps_por_os', 12, 'Quadro previsto da estrutura de 01/11: reps por OS (cenário QUADRO_12_OS)')
ON DUPLICATE KEY UPDATE descricao = VALUES(descricao);

-- ---------------------------------------------------------------------
-- 1. Cenários
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS fc_quadro_cenario (
  cenario    VARCHAR(20)  NOT NULL PRIMARY KEY,
  nome       VARCHAR(80)  NOT NULL,
  descricao  VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO fc_quadro_cenario (cenario, nome, descricao) VALUES
 ('CAPACITY', 'Necessidade do capacity', 'Alvo = titulares (postos × 1,5) + buffer do turno (absenteísmo, falha adm. e pico), por unidade e turno (fc_necessidade_estrutura)'),
 ('QUADRO_12_OS', 'Quadro de 12 reps por OS', 'Alvo = reps_por_os × OS de cada unidade e turno (3 OS = 36 por unidade e turno; 324 no total)')
ON DUPLICATE KEY UPDATE nome = VALUES(nome), descricao = VALUES(descricao);

-- ---------------------------------------------------------------------
-- 2. Quadro alvo e vagas por unidade e turno (sempre o cálculo mais recente)
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW vw_fc_11_quadro_vagas AS
SELECT 'CAPACITY' AS `Cenário`, CONCAT('U', n.unidade) AS `Unidade`, n.turno AS `Turno`,
       n.precisa AS `Alvo`, n.tem AS `Tem`, GREATEST(n.precisa - n.tem, 0) AS `Vagas`,
       n.data_calculo AS `Data do cálculo`
FROM fc_necessidade_estrutura n
WHERE n.data_calculo = (SELECT MAX(data_calculo) FROM fc_necessidade_estrutura)
UNION ALL
SELECT 'QUADRO_12_OS', CONCAT('U', n.unidade), n.turno,
       CAST(os.n * p.valor AS SIGNED), n.tem, GREATEST(CAST(os.n * p.valor AS SIGNED) - n.tem, 0), n.data_calculo
FROM fc_necessidade_estrutura n
JOIN fc_parametros p ON p.chave = 'reps_por_os'
JOIN (SELECT unidade, turno, COUNT(*) AS n FROM fc_estrutura_lider
      WHERE nivel = 'OS' AND vigente_ate IS NULL GROUP BY unidade, turno) os
  ON os.unidade = n.unidade AND os.turno = n.turno
WHERE n.data_calculo = (SELECT MAX(data_calculo) FROM fc_necessidade_estrutura);

-- ---------------------------------------------------------------------
-- 3. Turmas por cenário
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS fc_contratacoes_cenario (
  cenario               VARCHAR(20)  NOT NULL,
  turma                 VARCHAR(30)  NOT NULL,
  data_inicio_trn       DATE         NOT NULL,
  data_inicio_operacao  DATE         NOT NULL,
  turno                 ENUM('A','C','D') NOT NULL,
  qtd                   SMALLINT     NOT NULL,
  status                ENUM('PLANEJADA','CONFIRMADA','REALIZADA','CANCELADA') NOT NULL DEFAULT 'PLANEJADA',
  obs                   VARCHAR(255) NULL,
  simulado_em           DATE         NOT NULL,
  PRIMARY KEY (cenario, turma, turno),
  CONSTRAINT fk_cc_cenario FOREIGN KEY (cenario) REFERENCES fc_quadro_cenario (cenario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.1 Falta projetada de cada cenário (pessoas; negativo = sobra)
CREATE TABLE IF NOT EXISTS fc_projecao_turmas_cenario (
  cenario      VARCHAR(20)  NOT NULL,
  data         DATE         NOT NULL,
  falta_a      DECIMAL(6,1) NOT NULL,
  falta_c      DECIMAL(6,1) NOT NULL,
  falta_d      DECIMAL(6,1) NOT NULL,
  falta_total  DECIMAL(6,1) NOT NULL,
  hc_projetado DECIMAL(6,1) NOT NULL,
  simulado_em  DATE         NOT NULL,
  PRIMARY KEY (cenario, data),
  CONSTRAINT fk_pt_cenario FOREIGN KEY (cenario) REFERENCES fc_quadro_cenario (cenario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELETE FROM fc_contratacoes_cenario WHERE simulado_em = '2026-10-27';
DELETE FROM fc_projecao_turmas_cenario WHERE simulado_em = '2026-10-27';

INSERT INTO fc_contratacoes_cenario (cenario, turma, data_inicio_trn, data_inicio_operacao, turno, qtd, status, obs, simulado_em) VALUES
 ('CAPACITY', '2026-11-02', '2026-11-02', '2026-11-16', 'C', 9, 'CONFIRMADA', 'Pedido feito', '2026-10-27'),
 ('CAPACITY', '2026-11-02', '2026-11-02', '2026-11-16', 'D', 6, 'CONFIRMADA', 'Pedido feito', '2026-10-27'),
 ('CAPACITY', '2026-11-16', '2026-11-16', '2026-11-30', 'A', 5, 'CONFIRMADA', 'Pedido feito', '2026-10-27'),
 ('CAPACITY', '2026-11-16', '2026-11-16', '2026-11-30', 'C', 9, 'CONFIRMADA', 'Pedido feito', '2026-10-27'),
 ('CAPACITY', '2026-11-16', '2026-11-16', '2026-11-30', 'D', 6, 'CONFIRMADA', 'Pedido feito', '2026-10-27'),
 ('CAPACITY', '2026-12-07', '2026-12-07', '2026-12-21', 'A', 6, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('CAPACITY', '2026-12-07', '2026-12-07', '2026-12-21', 'C', 8, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('CAPACITY', '2026-12-07', '2026-12-07', '2026-12-21', 'D', 6, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('CAPACITY', '2027-01-11', '2027-01-11', '2027-01-25', 'A', 5, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('CAPACITY', '2027-01-11', '2027-01-11', '2027-01-25', 'C', 8, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('CAPACITY', '2027-01-11', '2027-01-11', '2027-01-25', 'D', 7, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('CAPACITY', '2027-01-25', '2027-01-25', '2027-02-08', 'A', 5, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('CAPACITY', '2027-01-25', '2027-01-25', '2027-02-08', 'C', 8, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('CAPACITY', '2027-01-25', '2027-01-25', '2027-02-08', 'D', 7, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('CAPACITY', '2027-02-08', '2027-02-08', '2027-02-22', 'A', 6, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('CAPACITY', '2027-02-08', '2027-02-08', '2027-02-22', 'C', 8, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('CAPACITY', '2027-02-08', '2027-02-08', '2027-02-22', 'D', 6, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('CAPACITY', '2027-02-22', '2027-02-22', '2027-03-08', 'A', 5, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('CAPACITY', '2027-02-22', '2027-02-22', '2027-03-08', 'C', 8, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('CAPACITY', '2027-02-22', '2027-02-22', '2027-03-08', 'D', 7, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('CAPACITY', '2027-03-08', '2027-03-08', '2027-03-22', 'A', 5, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('CAPACITY', '2027-03-08', '2027-03-08', '2027-03-22', 'C', 9, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('CAPACITY', '2027-03-08', '2027-03-08', '2027-03-22', 'D', 6, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('CAPACITY', '2027-03-22', '2027-03-22', '2027-04-05', 'A', 5, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('CAPACITY', '2027-03-22', '2027-03-22', '2027-04-05', 'C', 8, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('CAPACITY', '2027-03-22', '2027-03-22', '2027-04-05', 'D', 7, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('CAPACITY', '2027-04-05', '2027-04-05', '2027-04-19', 'A', 5, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('CAPACITY', '2027-04-05', '2027-04-05', '2027-04-19', 'C', 8, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('CAPACITY', '2027-04-05', '2027-04-05', '2027-04-19', 'D', 7, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('CAPACITY', '2027-04-19', '2027-04-19', '2027-05-03', 'A', 5, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('CAPACITY', '2027-04-19', '2027-04-19', '2027-05-03', 'C', 8, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('CAPACITY', '2027-04-19', '2027-04-19', '2027-05-03', 'D', 7, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('CAPACITY', '2027-05-03', '2027-05-03', '2027-05-17', 'A', 5, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('CAPACITY', '2027-05-03', '2027-05-03', '2027-05-17', 'C', 9, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('CAPACITY', '2027-05-03', '2027-05-03', '2027-05-17', 'D', 6, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('CAPACITY', '2027-05-17', '2027-05-17', '2027-05-31', 'A', 5, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('CAPACITY', '2027-05-17', '2027-05-17', '2027-05-31', 'C', 8, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('CAPACITY', '2027-05-17', '2027-05-17', '2027-05-31', 'D', 7, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('CAPACITY', '2027-05-31', '2027-05-31', '2027-06-14', 'A', 5, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('CAPACITY', '2027-05-31', '2027-05-31', '2027-06-14', 'C', 8, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('CAPACITY', '2027-05-31', '2027-05-31', '2027-06-14', 'D', 7, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('CAPACITY', '2027-06-14', '2027-06-14', '2027-06-28', 'A', 5, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('CAPACITY', '2027-06-14', '2027-06-14', '2027-06-28', 'C', 8, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('CAPACITY', '2027-06-14', '2027-06-14', '2027-06-28', 'D', 7, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('CAPACITY', '2027-06-28', '2027-06-28', '2027-07-12', 'A', 3, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('CAPACITY', '2027-06-28', '2027-06-28', '2027-07-12', 'C', 6, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('CAPACITY', '2027-06-28', '2027-06-28', '2027-07-12', 'D', 5, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2026-11-02', '2026-11-02', '2026-11-16', 'C', 9, 'CONFIRMADA', 'Pedido feito', '2026-10-27'),
 ('QUADRO_12_OS', '2026-11-02', '2026-11-02', '2026-11-16', 'D', 6, 'CONFIRMADA', 'Pedido feito', '2026-10-27'),
 ('QUADRO_12_OS', '2026-11-16', '2026-11-16', '2026-11-30', 'A', 5, 'CONFIRMADA', 'Pedido feito', '2026-10-27'),
 ('QUADRO_12_OS', '2026-11-16', '2026-11-16', '2026-11-30', 'C', 9, 'CONFIRMADA', 'Pedido feito', '2026-10-27'),
 ('QUADRO_12_OS', '2026-11-16', '2026-11-16', '2026-11-30', 'D', 6, 'CONFIRMADA', 'Pedido feito', '2026-10-27'),
 ('QUADRO_12_OS', '2026-12-07', '2026-12-07', '2026-12-21', 'A', 6, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2026-12-07', '2026-12-07', '2026-12-21', 'C', 7, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2026-12-07', '2026-12-07', '2026-12-21', 'D', 7, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2027-01-11', '2027-01-11', '2027-01-25', 'A', 6, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2027-01-11', '2027-01-11', '2027-01-25', 'C', 7, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2027-01-11', '2027-01-11', '2027-01-25', 'D', 7, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2027-01-25', '2027-01-25', '2027-02-08', 'A', 6, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2027-01-25', '2027-01-25', '2027-02-08', 'C', 7, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2027-01-25', '2027-01-25', '2027-02-08', 'D', 7, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2027-02-08', '2027-02-08', '2027-02-22', 'A', 6, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2027-02-08', '2027-02-08', '2027-02-22', 'C', 8, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2027-02-08', '2027-02-08', '2027-02-22', 'D', 6, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2027-02-22', '2027-02-22', '2027-03-08', 'A', 6, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2027-02-22', '2027-02-22', '2027-03-08', 'C', 7, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2027-02-22', '2027-02-22', '2027-03-08', 'D', 7, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2027-03-08', '2027-03-08', '2027-03-22', 'A', 6, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2027-03-08', '2027-03-08', '2027-03-22', 'C', 8, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2027-03-08', '2027-03-08', '2027-03-22', 'D', 6, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2027-03-22', '2027-03-22', '2027-04-05', 'A', 6, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2027-03-22', '2027-03-22', '2027-04-05', 'C', 7, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2027-03-22', '2027-03-22', '2027-04-05', 'D', 7, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2027-04-05', '2027-04-05', '2027-04-19', 'A', 5, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2027-04-05', '2027-04-05', '2027-04-19', 'C', 8, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2027-04-05', '2027-04-05', '2027-04-19', 'D', 7, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2027-04-19', '2027-04-19', '2027-05-03', 'A', 6, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2027-04-19', '2027-04-19', '2027-05-03', 'C', 7, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2027-04-19', '2027-04-19', '2027-05-03', 'D', 7, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2027-05-03', '2027-05-03', '2027-05-17', 'A', 5, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2027-05-03', '2027-05-03', '2027-05-17', 'C', 8, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2027-05-03', '2027-05-03', '2027-05-17', 'D', 7, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2027-05-17', '2027-05-17', '2027-05-31', 'A', 5, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2027-05-17', '2027-05-17', '2027-05-31', 'C', 8, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2027-05-17', '2027-05-17', '2027-05-31', 'D', 7, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2027-05-31', '2027-05-31', '2027-06-14', 'A', 6, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2027-05-31', '2027-05-31', '2027-06-14', 'C', 8, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2027-05-31', '2027-05-31', '2027-06-14', 'D', 6, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2027-06-14', '2027-06-14', '2027-06-28', 'A', 5, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2027-06-14', '2027-06-14', '2027-06-28', 'C', 8, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2027-06-14', '2027-06-14', '2027-06-28', 'D', 7, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2027-06-28', '2027-06-28', '2027-07-12', 'A', 5, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2027-06-28', '2027-06-28', '2027-07-12', 'C', 8, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27'),
 ('QUADRO_12_OS', '2027-06-28', '2027-06-28', '2027-07-12', 'D', 7, 'PLANEJADA', 'Simulação de 27/10', '2026-10-27');

INSERT INTO fc_projecao_turmas_cenario (cenario, data, falta_a, falta_c, falta_d, falta_total, hc_projetado, simulado_em) VALUES
 ('CAPACITY', '2026-11-16', 26.2, 45.7, 32.2, 104.1, 210.9, '2026-10-27'),
 ('CAPACITY', '2026-11-30', 27.7, 39.9, 28.9, 96.4, 218.6, '2026-10-27'),
 ('CAPACITY', '2026-12-21', 25.5, 36.4, 27.5, 89.5, 225.5, '2026-10-27'),
 ('CAPACITY', '2026-12-31', 21.2, 31.3, 23.9, 76.3, 238.7, '2026-10-27'),
 ('CAPACITY', '2027-01-24', 24.7, 37.6, 29.1, 91.3, 223.7, '2026-10-27'),
 ('CAPACITY', '2027-02-07', 21.9, 33.5, 25.4, 80.7, 234.3, '2026-10-27'),
 ('CAPACITY', '2027-02-21', 19.4, 29.8, 22.1, 71.4, 243.6, '2026-10-27'),
 ('CAPACITY', '2027-03-08', 16.4, 26.7, 20.2, 63.3, 251.7, '2026-10-27'),
 ('CAPACITY', '2027-03-31', 11.2, 17.5, 13.9, 42.6, 272.4, '2026-10-27'),
 ('CAPACITY', '2027-04-30', 7.8, 12.6, 9.4, 29.8, 285.2, '2026-10-27'),
 ('CAPACITY', '2027-05-31', 5.0, 7.7, 6.7, 19.3, 295.7, '2026-10-27'),
 ('CAPACITY', '2027-06-30', -2.9, -4.2, -4.0, -11.0, 326.0, '2026-10-27'),
 ('QUADRO_12_OS', '2026-11-16', 34.2, 42.7, 36.2, 113.1, 210.9, '2026-10-27'),
 ('QUADRO_12_OS', '2026-11-30', 35.7, 36.9, 32.9, 105.4, 218.6, '2026-10-27'),
 ('QUADRO_12_OS', '2026-12-21', 33.5, 33.4, 31.5, 98.5, 225.5, '2026-10-27'),
 ('QUADRO_12_OS', '2026-12-31', 29.2, 29.3, 26.9, 85.3, 238.7, '2026-10-27'),
 ('QUADRO_12_OS', '2027-01-24', 32.7, 35.4, 32.2, 100.3, 223.7, '2026-10-27'),
 ('QUADRO_12_OS', '2027-02-07', 28.9, 32.3, 28.5, 89.7, 234.3, '2026-10-27'),
 ('QUADRO_12_OS', '2027-02-21', 25.6, 29.5, 25.3, 80.4, 243.6, '2026-10-27'),
 ('QUADRO_12_OS', '2027-03-08', 22.6, 26.2, 23.4, 72.3, 251.7, '2026-10-27'),
 ('QUADRO_12_OS', '2027-03-31', 15.6, 18.8, 17.1, 51.6, 272.4, '2026-10-27'),
 ('QUADRO_12_OS', '2027-04-30', 11.6, 14.6, 12.6, 38.8, 285.2, '2026-10-27'),
 ('QUADRO_12_OS', '2027-05-31', 8.1, 11.2, 9.0, 28.3, 295.7, '2026-10-27'),
 ('QUADRO_12_OS', '2027-06-30', -0.5, -0.9, -0.6, -2.0, 326.0, '2026-10-27');

-- ---------------------------------------------------------------------
-- 4. Visão: as turmas dos dois cenários lado a lado
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW vw_fc_12_turmas_cenarios AS
SELECT c.turma AS `Turma`, c.data_inicio_operacao AS `Começa a operar`,
       SUM(IF(c.cenario = 'CAPACITY' AND c.turno = 'A', c.qtd, 0)) AS `Capacity A`,
       SUM(IF(c.cenario = 'CAPACITY' AND c.turno = 'C', c.qtd, 0)) AS `Capacity C`,
       SUM(IF(c.cenario = 'CAPACITY' AND c.turno = 'D', c.qtd, 0)) AS `Capacity D`,
       SUM(IF(c.cenario = 'CAPACITY', c.qtd, 0)) AS `Capacity total`,
       SUM(IF(c.cenario = 'QUADRO_12_OS' AND c.turno = 'A', c.qtd, 0)) AS `Quadro A`,
       SUM(IF(c.cenario = 'QUADRO_12_OS' AND c.turno = 'C', c.qtd, 0)) AS `Quadro C`,
       SUM(IF(c.cenario = 'QUADRO_12_OS' AND c.turno = 'D', c.qtd, 0)) AS `Quadro D`,
       SUM(IF(c.cenario = 'QUADRO_12_OS', c.qtd, 0)) AS `Quadro total`,
       MAX(c.status) AS `Status`
FROM fc_contratacoes_cenario c
WHERE c.simulado_em = (SELECT MAX(simulado_em) FROM fc_contratacoes_cenario)
GROUP BY c.turma, c.data_inicio_operacao
ORDER BY c.turma;

-- ---------------------------------------------------------------------
-- 5. Adotar um cenário como plano oficial (DEPOIS da decisão; descomente UM dos dois)
--    Troca as turmas a partir de 07/12 em fc_contratacoes e recalcula a previsão.
-- ---------------------------------------------------------------------
-- SET @cenario = 'CAPACITY';        -- ou 'QUADRO_12_OS'
-- DELETE FROM fc_contratacoes WHERE turma >= '2026-12-07';
-- INSERT INTO fc_contratacoes (turma, data_inicio_trn, data_inicio_operacao, turno, qtd, status, obs)
-- SELECT turma, data_inicio_trn, data_inicio_operacao, turno, qtd, status, CONCAT('Cenário ', cenario, ' de 27/10')
-- FROM fc_contratacoes_cenario
-- WHERE cenario = @cenario AND turma >= '2026-12-07'
--   AND simulado_em = (SELECT MAX(simulado_em) FROM fc_contratacoes_cenario);
-- CALL sp_fc_rodar_dia();

-- ---------------------------------------------------------------------
-- 6. Conferência
-- ---------------------------------------------------------------------
SELECT * FROM vw_fc_11_quadro_vagas ORDER BY `Cenário`, `Unidade`, FIELD(`Turno`, 'A', 'C', 'D');
SELECT `Cenário`, SUM(`Alvo`) AS `Alvo`, SUM(`Tem`) AS `Tem`, SUM(`Vagas`) AS `Vagas`
FROM vw_fc_11_quadro_vagas GROUP BY `Cenário`;
SELECT * FROM vw_fc_12_turmas_cenarios;
SELECT cenario, data, falta_a, falta_c, falta_d, falta_total, hc_projetado
FROM fc_projecao_turmas_cenario ORDER BY cenario, data;
