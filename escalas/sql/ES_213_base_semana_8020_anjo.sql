SET NAMES utf8mb4;
-- =====================================================================
-- ES_213 · Base da semana anterior, limite 80/20 das movimentações e Anjo do OS (5C Escalas 1.37.0, 24/11, pedido do
-- usuário: "pegue o padrão de alocações da semana anterior no banco de dados e tome como verdadeiro. Em seguida, aplicaremos
-- a regra do 80/20 [...] E depois disso, preciso de uma mecânica de Anjo"). Regras em contexto/regras_base_semana_8020_anjo.md.
--   esc_anjo: 1 SR por núcleo (OS) por semana, que ficaria sem a página e fica nela mais uma semana (o Planejamento registra).
--   esc_base_movimento: as movimentações de cada plano gerado em relação à semana anterior (tela Movimentações e Anjos).
--   Parâmetros: v4_base_semana_anterior (1 liga), v4_base_min_fatia (0,25: página da base = 25% ou mais dos blocos do SR na
--   semana anterior) e v4_base_mov_max (0,20: no máximo 20% das trocas propostas acontecem, as de maior impacto).
--   Também no schema de treino (modo treino). Pode rodar pelo MCP (sem transação).
-- =====================================================================
USE `5c_escalas`;

CREATE TABLE IF NOT EXISTS esc_anjo (
  id INT AUTO_INCREMENT PRIMARY KEY,
  semana DATE NOT NULL COMMENT 'segunda-feira da semana em que o Anjo vale',
  nucleo_id INT NOT NULL COMMENT 'núcleo do OS que escolheu',
  rep_code VARCHAR(30) NOT NULL,
  observacao VARCHAR(255) NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  criado_por INT NULL,
  UNIQUE KEY uk_anjo_semana_nucleo (semana, nucleo_id),
  KEY ix_anjo_rep (rep_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Anjo do OS: 1 SR por núcleo e semana que fica na página da semana anterior (1.37.0)';

CREATE TABLE IF NOT EXISTS esc_base_movimento (
  id INT AUTO_INCREMENT PRIMARY KEY,
  plano_id INT NOT NULL,
  semana DATE NOT NULL,
  plano_base_id INT NULL COMMENT 'plano da semana anterior usado como base',
  rep_code VARCHAR(30) NOT NULL,
  unidade TINYINT NULL,
  turno CHAR(1) NULL,
  nucleo_id INT NULL,
  tipo VARCHAR(12) NOT NULL COMMENT 'OPCIONAL (troca do motor), NUCLEO (mudou de unidade, turno ou núcleo; conta nos 20%), FORCADA (fora dos 20%)',
  situacao VARCHAR(12) NOT NULL COMMENT 'MUDA, FICA (80/20), ANJO ou FORCADA',
  paginas_base VARCHAR(500) NULL,
  pagina_para VARCHAR(255) NULL COMMENT 'página principal na proposta do motor (vazio = buffer)',
  entra_rep VARCHAR(30) NULL COMMENT 'quem entra na página da base na proposta',
  score_sai DECIMAL(6,1) NULL,
  score_entra DECIMAL(6,1) NULL,
  receita DECIMAL(14,2) NULL COMMENT 'faturamento de 30 dias da página no turno',
  impacto DECIMAL(14,2) NULL COMMENT 'faturamento × (score de quem entra − score de quem sai) ÷ 100',
  motivo VARCHAR(255) NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_bm_plano (plano_id),
  KEY ix_bm_semana (semana)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Movimentações em relação à semana anterior, por plano (1.37.0)';

CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.esc_anjo LIKE `5c_escalas`.esc_anjo;
CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.esc_base_movimento LIKE `5c_escalas`.esc_base_movimento;

SELECT 'antes' AS momento, chave, valor FROM esc_parametro WHERE chave LIKE 'v4\_base\_%';
INSERT INTO esc_parametro (chave, valor, tipo, grupo, descricao)
SELECT x.chave, x.valor, t.tipo, t.grupo, x.descricao
  FROM (SELECT 'v4_base_semana_anterior' AS chave, '1' AS valor, 'Base da semana anterior: o SR fica na página em que estava na semana anterior; só mudam as trocas de maior impacto (80/20) e os Anjos ficam. 0 = desliga (1.37.0, ES_213)' AS descricao
        UNION ALL SELECT 'v4_base_min_fatia', '0.25', 'Página da base: a que teve 25% ou mais dos blocos do SR na semana anterior (1.37.0, ES_213)'
        UNION ALL SELECT 'v4_base_mov_max', '0.20', 'No máximo 20% das trocas propostas sobre a semana anterior acontecem, as de maior impacto (faturamento × ganho de KPI); mudança de unidade, turno ou núcleo conta (1.37.0, ES_213)') x
  JOIN esc_parametro t ON t.chave = 'v4_so_propria_unidade'
 WHERE NOT EXISTS (SELECT 1 FROM esc_parametro p WHERE p.chave = x.chave);
UPDATE esc_parametro SET tipo = 'DECIMAL' WHERE chave IN ('v4_base_min_fatia', 'v4_base_mov_max') AND tipo <> 'DECIMAL';
SELECT 'depois' AS momento, chave, valor, tipo, grupo FROM esc_parametro WHERE chave LIKE 'v4\_base\_%';
SELECT 'depois' AS momento, TABLE_SCHEMA, TABLE_NAME FROM information_schema.TABLES WHERE TABLE_NAME IN ('esc_anjo', 'esc_base_movimento') ORDER BY 2, 3;
