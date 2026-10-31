SET NAMES utf8mb4;
-- =====================================================================
-- ES_36 · Treinamento de New Hires e parâmetros do mérito (30/10/2026)
--
-- 1) Turmas de treinamento (lidas do e-mail do Treinamento), trainees e as páginas
--    emprestadas para o treinamento. Os trainees entram no plano na página
--    5C Schedule com o sufixo -4 (origem TREINO) e ficam fora da escala normal até
--    o dia em que ficam disponíveis para a operação.
-- 2) Parâmetros do absenteísmo no mérito (tolerância 6%, crítico 15%).
--
-- Roda no 5c_escalas e no 5c_escalas_treino (Modo Treino). Pode rodar de novo.
-- =====================================================================

-- ---------- 5c_escalas ----------
USE `5c_escalas`;

ALTER TABLE esc_plano_alocacao MODIFY origem ENUM('PARTY_DONA','OUTRA_PARTY','TERTIUS','TERTIUS_ASSISTENTE','BUFFER','MANUAL','LACUNA','VAGA_DONO','VAGA_COBERTURA','BUFFER_NUCLEO','TRANSICAO','OVERLAP','SOBRECARGA','LIDER_OS','LIDER_OC','LIDER_OA','TREINO') NOT NULL;

CREATE TABLE IF NOT EXISTS esc_treinamento_turma (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  codigo              VARCHAR(30)  NOT NULL COMMENT 'ex.: NH45A2026',
  ciclo               VARCHAR(30)  NULL     COMMENT 'ex.: NH45-2026 (turmas que começam juntas)',
  turno               ENUM('A','C','D') NOT NULL,
  hc                  SMALLINT     NULL,
  instrutor           VARCHAR(120) NULL,
  horario             VARCHAR(40)  NULL,
  inicio              DATE         NOT NULL,
  fim                 DATE         NOT NULL COMMENT 'último dia de treinamento (seg a sex)',
  disponivel_em       DATE         NOT NULL COMMENT 'primeiro dia que pode entrar na escala normal',
  nesting_de          DATE         NULL,
  nesting_ate         DATE         NULL,
  paginas_necessarias TINYINT      NOT NULL DEFAULT 1,
  status              ENUM('RASCUNHO','ATIVA','CANCELADA') NOT NULL DEFAULT 'RASCUNHO',
  alertas             TEXT         NULL     COMMENT 'JSON: o que a leitura do e-mail achou estranho',
  email_texto         MEDIUMTEXT   NULL,
  criado_por          INT          NULL,
  criado_em           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em       DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_treinamento_turma (codigo),
  KEY ix_treinamento_datas (status, inicio, disponivel_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Turmas de New Hires (ES_36)';

CREATE TABLE IF NOT EXISTS esc_treinamento_trainee (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  turma_id     INT          NOT NULL,
  rep_code     VARCHAR(10)  NOT NULL,
  nome         VARCHAR(150) NULL,
  nome_social  VARCHAR(150) NULL,
  UNIQUE KEY uk_treinamento_trainee (turma_id, rep_code),
  KEY ix_treinamento_rep (rep_code),
  CONSTRAINT fk_treinamento_trainee_turma FOREIGN KEY (turma_id) REFERENCES esc_treinamento_turma (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Trainees de cada turma (ES_36)';

CREATE TABLE IF NOT EXISTS esc_treinamento_pagina (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  turma_id   INT          NOT NULL,
  pagina     VARCHAR(150) NOT NULL,
  page_id    INT          NULL,
  posto_id   INT          NULL,
  owner_rep  VARCHAR(10)  NULL COMMENT 'dono do turno: continua dono e com a comissão',
  fans_h     DECIMAL(8,3) NULL,
  ating      DECIMAL(6,4) NULL COMMENT 'atingimento da página na escolha',
  criado_por INT          NULL,
  criado_em  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_treinamento_pagina (turma_id, pagina),
  CONSTRAINT fk_treinamento_pagina_turma FOREIGN KEY (turma_id) REFERENCES esc_treinamento_turma (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Páginas emprestadas para o treinamento (ES_36)';

INSERT INTO esc_parametro (chave, valor, tipo, grupo, descricao) VALUES
  ('merito_abs_tolerancia',   '0.06', 'DECIMAL', 'merito',      'Absenteísmo até aqui quase não pesa (presença = 1 − absenteísmo)'),
  ('merito_abs_critico',      '0.15', 'DECIMAL', 'merito',      'Absenteísmo crítico: daqui para cima o SR fica sempre abaixo da média'),
  ('merito_abs_piso',         '0.5',  'DECIMAL', 'merito',      'Presença no absenteísmo crítico, como fração da presença na tolerância'),
  ('merito_abs_teto_critico', '0.8',  'DECIMAL', 'merito',      'Com absenteísmo crítico, a nota fica no máximo nesta fração da mediana'),
  ('treinamento_ating_min',   '0.85', 'DECIMAL', 'treinamento', 'Página emprestada para treinamento: atingimento mínimo no mês'),
  ('treinamento_janela_dias', '28',   'INT',     'treinamento', 'Dias para medir o atingimento da página candidata'),
  ('treinamento_faixas',      '4,8',  'TEXTO',   'treinamento', 'Trainees por página: até 4 = 1 página, 5 a 8 = 2, 9 ou mais = 3')
ON DUPLICATE KEY UPDATE descricao = VALUES(descricao);

-- ---------- 5c_escalas_treino (Modo Treino) ----------
USE `5c_escalas_treino`;

ALTER TABLE esc_plano_alocacao MODIFY origem ENUM('PARTY_DONA','OUTRA_PARTY','TERTIUS','TERTIUS_ASSISTENTE','BUFFER','MANUAL','LACUNA','VAGA_DONO','VAGA_COBERTURA','BUFFER_NUCLEO','TRANSICAO','OVERLAP','SOBRECARGA','LIDER_OS','LIDER_OC','LIDER_OA','TREINO') NOT NULL;
CREATE TABLE IF NOT EXISTS esc_treinamento_turma   LIKE `5c_escalas`.esc_treinamento_turma;
CREATE TABLE IF NOT EXISTS esc_treinamento_trainee LIKE `5c_escalas`.esc_treinamento_trainee;
CREATE TABLE IF NOT EXISTS esc_treinamento_pagina  LIKE `5c_escalas`.esc_treinamento_pagina;

USE `5c_escalas`;

-- ---------- Conferência ----------
SELECT 'Origem TREINO no plano' AS item, IF(COLUMN_TYPE LIKE '%TREINO%', 'ok', 'FALTA') AS situacao
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '5c_escalas' AND TABLE_NAME = 'esc_plano_alocacao' AND COLUMN_NAME = 'origem'
UNION ALL SELECT 'Tabelas de treinamento (3)', COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '5c_escalas' AND TABLE_NAME LIKE 'esc_treinamento_%'
UNION ALL SELECT 'Tabelas no Modo Treino (3)', COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '5c_escalas_treino' AND TABLE_NAME LIKE 'esc_treinamento_%'
UNION ALL SELECT 'Parâmetros novos (7)', COUNT(*) FROM esc_parametro WHERE chave IN ('merito_abs_tolerancia','merito_abs_critico','merito_abs_piso','merito_abs_teto_critico','treinamento_ating_min','treinamento_janela_dias','treinamento_faixas');
