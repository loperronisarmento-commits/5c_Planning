-- =====================================================================
-- ES_16_merito_infracoes_saidas.sql  (28/10)
-- 1. Mérito dos SRs (eixo do 5C George ligado):
--      nota = Desempenho × SLA × (1 − absenteísmo) ÷ (1 + pena das infrações)
--    · Desempenho = realizado ÷ meta 5CReps das páginas, rateado pelas
--      mensagens do SR em cada página e turno (mesma lógica do 5C George);
--    · SLA = respostas em até 5 min ÷ respostas;
--    · absenteísmo = faltas ÷ blocos escalados (5C Schedule);
--    · pena = Σ peso da classificação × 0,5^(dias desde a aplicação ÷ meia-vida):
--      cada infração pesa menos com o tempo, até sumir ("pagando a pena").
--      Sem infração, pena = 0 (divide por 1).
-- 2. Tabela de infrações (prevista; ainda sem carga): esc_sr_infraction.
-- 3. Versões do Capacity guardadas antes e depois de cada recálculo feito pelo
--    sistema (saída de página), para comparar.
-- 4. Registro das saídas de página aplicadas pelo sistema.
-- Só cria tabelas e parâmetros. Pode rodar de novo.
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

CREATE TABLE IF NOT EXISTS esc_sr_infraction (
  infraction_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sr_code         VARCHAR(30)  NOT NULL COMMENT 'código do SR (ex.: A123456)',
  classification  VARCHAR(30)  NOT NULL COMMENT 'classificação (peso no parâmetro merito_infracao_pesos; sem peso = 1)',
  occurrency_date DATE         NOT NULL COMMENT 'quando aconteceu',
  applied_date    DATE         NULL     COMMENT 'quando a medida foi aplicada (a pena conta daqui; sem data, da ocorrência)',
  description     VARCHAR(500) NULL,
  action          VARCHAR(100) NULL     COMMENT 'medida aplicada (ex.: advertência, suspensão)',
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (infraction_id),
  KEY ix_inf_sr (sr_code, applied_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Infrações dos SRs (entra no mérito com efeito decrescente)';

CREATE TABLE IF NOT EXISTS esc_sr_merito (
  rep_code     VARCHAR(30)   NOT NULL,
  desempenho   DECIMAL(7,4)  NULL COMMENT 'realizado ÷ meta 5CReps',
  sla          DECIMAL(6,4)  NULL COMMENT 'respostas em até 5 min ÷ respostas',
  absenteismo  DECIMAL(6,4)  NULL COMMENT 'faltas ÷ blocos escalados',
  pena         DECIMAL(7,4)  NOT NULL DEFAULT 0 COMMENT 'Σ peso × 0,5^(dias ÷ meia-vida)',
  infracoes    SMALLINT      NOT NULL DEFAULT 0 COMMENT 'infrações dentro da janela',
  nota         DECIMAL(7,4)  NULL COMMENT 'desempenho × SLA × (1 − abs.) ÷ (1 + pena)',
  horas        INT           NOT NULL DEFAULT 0 COMMENT 'horas com mensagens na janela',
  respostas    INT           NOT NULL DEFAULT 0,
  janela_de    DATE          NOT NULL,
  janela_ate   DATE          NOT NULL,
  calculado_em DATETIME      NOT NULL,
  PRIMARY KEY (rep_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Mérito dos SRs (cache, recalculado a cada merito_cache_horas)';

CREATE TABLE IF NOT EXISTS esc_capacity_versao (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  data_calculo  DATE         NOT NULL,
  momento       ENUM('ANTES','DEPOIS') NOT NULL,
  saida_id      INT UNSIGNED NULL COMMENT 'esc_saida_pagina que provocou o recálculo',
  motivo        VARCHAR(255) NULL,
  postos        SMALLINT     NOT NULL DEFAULT 0,
  titulares     SMALLINT     NOT NULL DEFAULT 0,
  buffer        SMALLINT     NOT NULL DEFAULT 0,
  precisa       SMALLINT     NOT NULL DEFAULT 0,
  criado_em     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  criado_por    INT          NULL,
  PRIMARY KEY (id),
  KEY ix_capv_data (data_calculo, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Foto do Capacity (5c_planning) antes e depois de um recálculo';

CREATE TABLE IF NOT EXISTS esc_capacity_versao_ut (
  versao_id     INT UNSIGNED NOT NULL,
  unidade       TINYINT UNSIGNED NOT NULL,
  turno         CHAR(1)      NOT NULL,
  postos        SMALLINT     NULL,
  titulares     SMALLINT     NULL,
  buffer        SMALLINT     NULL,
  presentes_dia DECIMAL(7,2) NULL,
  precisa       SMALLINT     NULL,
  tem           SMALLINT     NULL,
  saldo         SMALLINT     NULL,
  PRIMARY KEY (versao_id, unidade, turno)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Necessidade por unidade e turno de cada foto do Capacity';

CREATE TABLE IF NOT EXISTS esc_capacity_versao_posto (
  versao_id     INT UNSIGNED NOT NULL,
  carteira      VARCHAR(10)  NOT NULL,
  turno         CHAR(1)      NOT NULL,
  posto         SMALLINT     NOT NULL,
  tipo          VARCHAR(20)  NULL,
  paginas       VARCHAR(500) NULL COMMENT 'páginas do posto, separadas por " + "',
  carga         DECIMAL(7,1) NULL,
  capacidade    DECIMAL(7,1) NULL,
  PRIMARY KEY (versao_id, carteira, turno, posto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Postos de cada foto do Capacity';

CREATE TABLE IF NOT EXISTS esc_saida_pagina (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  pagina           VARCHAR(150) NOT NULL,
  vale_desde       DATE         NOT NULL COMMENT 'primeiro dia sem a página',
  evento_id        INT          NULL COMMENT 'fc_pagina_eventos (saída detectada), se houver',
  motivo           VARCHAR(255) NULL,
  status           ENUM('APLICADA','ERRO') NOT NULL,
  capacity_antes   INT UNSIGNED NULL,
  capacity_depois  INT UNSIGNED NULL,
  resultado        JSON         NULL COMMENT 'postos fechados e refeitos, vagas passadas, planos gerados',
  erro             VARCHAR(500) NULL,
  criado_em        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  criado_por       INT          NULL,
  PRIMARY KEY (id),
  KEY ix_saida_pag (pagina, vale_desde)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Saídas de página aplicadas pelo sistema (Capacity + postos + escala)';

INSERT INTO esc_parametro (chave, valor, tipo, grupo, descricao) VALUES
 ('merito_janela_dias', '28', 'INT', 'merito', 'Dias de histórico para desempenho, SLA e absenteísmo do mérito'),
 ('merito_min_respostas', '100', 'INT', 'merito', 'Mínimo de respostas na janela para o SR ter nota (abaixo: sem nota, fica no meio da fila)'),
 ('merito_cache_horas', '20', 'INT', 'merito', 'De quantas em quantas horas o mérito é recalculado'),
 ('merito_desempenho_teto', '2', 'DECIMAL', 'merito', 'Teto do desempenho (realizado ÷ meta) na nota; evita distorção com meta muito pequena'),
 ('merito_infracao_pesos', 'LEVE:1,MEDIA:2,GRAVE:4', 'TEXTO', 'merito', 'Peso de cada classificação de infração (classificação sem peso = 1)'),
 ('merito_infracao_meia_vida_dias', '90', 'INT', 'merito', 'Em quantos dias a pena de uma infração cai pela metade'),
 ('merito_infracao_janela_dias', '365', 'INT', 'merito', 'Infrações mais antigas que isto já não contam'),
 ('v4_historico_dias', '56', 'INT', 'v4', 'Dias de escala passada usados para saber quem já conhece cada página (menos troca de gente)')
AS n ON DUPLICATE KEY UPDATE descricao = n.descricao;

-- Conferência
SELECT 'esc_sr_infraction' AS tabela, COUNT(*) AS linhas FROM esc_sr_infraction
UNION ALL SELECT 'esc_sr_merito', COUNT(*) FROM esc_sr_merito
UNION ALL SELECT 'esc_capacity_versao', COUNT(*) FROM esc_capacity_versao
UNION ALL SELECT 'esc_saida_pagina', COUNT(*) FROM esc_saida_pagina;
SELECT chave, valor FROM esc_parametro WHERE grupo IN ('merito') OR chave = 'v4_historico_dias';
