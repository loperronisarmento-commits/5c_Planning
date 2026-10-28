-- =====================================================================
-- 5C | Forecast de WFM — 01. Estrutura (tabelas, parâmetros, cargas iniciais)
-- Schema: 5c_planning        Versão 1.0 — 19/10/2026
-- Rodar uma vez no Navicat (F5 / "Executar tudo").
-- Turnos: A = T2/T3 (05–13h) · C = T4/T5 (13–21h) · D = T6/T1 (21–05h)
-- "Dia do turno": as horas de 00h a 04h59 pertencem ao turno D do dia anterior.
-- =====================================================================
CREATE DATABASE IF NOT EXISTS `5c_planning` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
SET NAMES utf8mb4;
USE `5c_planning`;
SET SESSION cte_max_recursion_depth = 5000;

-- ---------------------------------------------------------------------
-- 1. Calendário (semana ISO: segunda a domingo; mês/ano da semana = da quinta-feira)
--    Obs.: alguns anos ISO têm semana 53 (ex.: 28/12/2026 a 03/01/2027).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS fc_calendario (
  dia            DATE        NOT NULL PRIMARY KEY,
  ano_iso        SMALLINT    NOT NULL,
  semana_iso     TINYINT     NOT NULL,
  inicio_semana  DATE        NOT NULL,
  mes_semana     TINYINT     NOT NULL COMMENT 'mês da quinta-feira da semana',
  mes            TINYINT     NOT NULL,
  ano            SMALLINT    NOT NULL,
  dia_semana     TINYINT     NOT NULL COMMENT '1=segunda ... 7=domingo',
  KEY ix_semana (ano_iso, semana_iso)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO fc_calendario
WITH RECURSIVE c AS (
  SELECT DATE('2025-01-01') AS d
  UNION ALL SELECT d + INTERVAL 1 DAY FROM c WHERE d < '2028-12-31'
)
SELECT d,
       YEARWEEK(d,3) DIV 100,
       YEARWEEK(d,3) MOD 100,
       d - INTERVAL WEEKDAY(d) DAY,
       MONTH(d - INTERVAL WEEKDAY(d) DAY + INTERVAL 3 DAY),
       MONTH(d), YEAR(d), WEEKDAY(d) + 1
FROM c;

-- ---------------------------------------------------------------------
-- 2. Parâmetros (editáveis; os procedimentos leem daqui)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS fc_parametros (
  chave          VARCHAR(40)   NOT NULL PRIMARY KEY,
  valor          DECIMAL(12,4) NOT NULL,
  descricao      VARCHAR(255)  NOT NULL,
  atualizado_em  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO fc_parametros (chave, valor, descricao) VALUES
 ('limite_padrao',          35,     'Fãs/h por rep nas páginas em que o SLA 80% não se paga; acima disso entra assistente (decisão de 19/10)'),
 ('limite_sla80',           20,     'Fãs/h por rep nas páginas em que o SLA 80% se paga (categoria SLA80)'),
 ('limite_skylar',          40,     'Fãs/h por rep na Skylar (capacidade medida na página)'),
 ('skylar_minimo',          4,      'Mínimo de reps por bloco na Skylar'),
 ('limite_compartilhada',   28,     'Página com até 28 fãs/h (35 × 0,8) pode dividir rep com outras; acima disso tem rep próprio'),
 ('capacidade_grupo',       23,     'Fãs/h médios que 1 rep atende somando até 3 páginas pequenas (C(n) da Política com C₁ = 35)'),
 ('max_paginas_rep',        3,      'Máximo de páginas por rep'),
 ('perfil_pico',            0,      '0 = média do bloco de 4 h (decisão de 19/10); 1 = hora mais cheia do bloco'),
 ('peso_falha_adm',         0.53,   'Fração das falhas administrativas (AF) que é ausência de fato: 47% dos AF tinham mensagens (05–16/10)'),
 ('fans_sem_dado',          10,     'Fãs/h assumidos para página escalada sem mensagens no banco (MYM, Fanvue...)'),
 ('janela_perfil_dias',     28,     'Dias de histórico para o perfil de fãs por dia da semana e hora (múltiplo de 7)'),
 ('janela_abs_dias',        56,     'Dias de histórico para a taxa de absenteísmo'),
 ('janela_to_semanas',      12,     'Semanas completas de histórico para a taxa de desligamento'),
 ('horizonte_dias',         42,     'Dias de previsão das tabelas diárias'),
 ('horizonte_semanas',      26,     'Semanas de previsão de turnover'),
 ('fator_volume',           1.00,   'Multiplicador dos fãs/h previstos (sazonalidade conhecida; ex.: 0,86 = −14% em novembro)'),
 ('regra_bloco',            1,      '1 = escala do turno = maior bloco de 4 h; 0 = média dos 2 blocos (arredondada para cima)'),
 ('presenca_3x1',           0.75,   'Fração dos dias em que a pessoa é escalada (3x1)'),
 ('prazo_reposicao_dias',   28,     'Dias para repor uma saída (espera da turma + 14 dias de treinamento)'),
 ('novato_sem_1_4',         0.03,   'Saída semanal de novatos nas semanas 1–4 após entrar em operação (12% em 30 dias)'),
 ('novato_sem_5_13',        0.0122, 'Saída semanal de novatos nas semanas 5–13 (23% em 90 dias)'),
 ('dias_sem_escala_saida',  14,     'Dias sem escala (e fora do cadastro) para considerar desligamento'),
 ('dias_sem_escala_cadastro', 45,   'Dias sem escala para considerar desligado quem ainda está no cadastro (afastados: corrigir em fc_desligamento_motivo)'),
 ('k_F', 0.42, 'Fãs/h por US$ de receita/h, tier F (estudo Capacity v2) — página nova sem histórico'),
 ('k_E', 0.30, 'idem tier E'), ('k_D', 0.21, 'idem tier D'), ('k_C', 0.20, 'idem tier C'),
 ('k_B', 0.16, 'idem tier B'), ('k_A', 0.15, 'idem tier A'), ('k_S', 0.12, 'idem tier S'), ('k_SS', 0.125, 'idem tier SS')
ON DUPLICATE KEY UPDATE descricao = VALUES(descricao);

-- ---------------------------------------------------------------------
-- 3. Cadastro de páginas para o forecast (modelo = agrupa sub-contas da mesma criadora)
--    categoria: SKYLAR (limite 40, mín. 4) · SLA80 (limite 20) · PADRAO (limite 30)
--    Páginas novas que aparecerem na escala entram sozinhas como PADRAO (modelo = nome).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS fc_pagina_modelo (
  pagina          VARCHAR(150) NOT NULL PRIMARY KEY,
  modelo          VARCHAR(150) NOT NULL,
  categoria       ENUM('SKYLAR','SLA80','PADRAO') NOT NULL DEFAULT 'PADRAO',
  ativo_forecast  TINYINT      NOT NULL DEFAULT 1 COMMENT '0 = tirar a página do forecast',
  obs             VARCHAR(255) NULL,
  atualizado_em   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_modelo (modelo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. Entradas manuais / CRM
-- ---------------------------------------------------------------------
-- 4.1 Turmas de contratação (alimenta a projeção de HC e o turnover de novatos)
CREATE TABLE IF NOT EXISTS fc_contratacoes (
  id                    INT AUTO_INCREMENT PRIMARY KEY,
  turma                 VARCHAR(30)  NOT NULL,
  data_inicio_trn       DATE         NOT NULL,
  data_inicio_operacao  DATE         NOT NULL COMMENT 'início do treinamento + 14 dias',
  turno                 ENUM('A','C','D') NOT NULL,
  qtd                   SMALLINT     NOT NULL,
  status                ENUM('PLANEJADA','CONFIRMADA','REALIZADA','CANCELADA') NOT NULL DEFAULT 'PLANEJADA',
  obs                   VARCHAR(255) NULL,
  criado_em             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_turma_turno (turma, turno)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO fc_contratacoes (turma, data_inicio_trn, data_inicio_operacao, turno, qtd, status, obs) VALUES
 ('2026-11-02','2026-11-02','2026-11-16','C',9,'CONFIRMADA','Pedido feito (corrigido em 19/10: 9 C + 6 D em 02/11)'),
 ('2026-11-02','2026-11-02','2026-11-16','D',6,'CONFIRMADA','Pedido feito (corrigido em 19/10: 9 C + 6 D em 02/11)'),
 ('2026-11-19','2026-11-19','2026-12-03','A',2,'PLANEJADA','Plano de 19/10 (limite 35, até 3 páginas, média do bloco)'),
 ('2026-11-19','2026-11-19','2026-12-03','C',11,'PLANEJADA','Plano de 19/10 (limite 35, até 3 páginas, média do bloco)'),
 ('2026-11-19','2026-11-19','2026-12-03','D',7,'PLANEJADA','Plano de 19/10 (limite 35, até 3 páginas, média do bloco)'),
 ('2026-12-03','2026-12-03','2026-12-17','A',3,'PLANEJADA','Plano de 19/10 (limite 35, até 3 páginas, média do bloco)'),
 ('2026-12-03','2026-12-03','2026-12-17','C',10,'PLANEJADA','Plano de 19/10 (limite 35, até 3 páginas, média do bloco)'),
 ('2026-12-03','2026-12-03','2026-12-17','D',7,'PLANEJADA','Plano de 19/10 (limite 35, até 3 páginas, média do bloco)'),
 ('2026-12-17','2026-12-17','2026-12-31','A',3,'PLANEJADA','Plano de 19/10 (limite 35, até 3 páginas, média do bloco)'),
 ('2026-12-17','2026-12-17','2026-12-31','C',9,'PLANEJADA','Plano de 19/10 (limite 35, até 3 páginas, média do bloco)'),
 ('2026-12-17','2026-12-17','2026-12-31','D',8,'PLANEJADA','Plano de 19/10 (limite 35, até 3 páginas, média do bloco)'),
 ('2027-01-05','2027-01-05','2027-01-19','A',4,'PLANEJADA','Plano de 19/10 (TRN ocupado até 31/12)'),
 ('2027-01-05','2027-01-05','2027-01-19','C',9,'PLANEJADA','Plano de 19/10 (TRN ocupado até 31/12)'),
 ('2027-01-05','2027-01-05','2027-01-19','D',7,'PLANEJADA','Plano de 19/10 (TRN ocupado até 31/12)');

-- 4.2 Páginas novas previstas (input manual ou integração com o funil do CRM)
--     Preencher fas_h_prev OU (tier + receita_mensal_prev). Status CONFIRMADA/EFETIVADA entra
--     na escala prevista a partir de data_prevista; PREVISTA entra só no impacto, ponderada pela probabilidade.
CREATE TABLE IF NOT EXISTS fc_paginas_previstas (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  pagina               VARCHAR(150) NOT NULL,
  modelo               VARCHAR(150) NULL COMMENT 'criadora; páginas da mesma modelo podem dividir rep',
  categoria            ENUM('SKYLAR','SLA80','PADRAO') NOT NULL DEFAULT 'PADRAO',
  tier                 ENUM('F','E','D','C','B','A','S','SS') NULL,
  receita_mensal_prev  DECIMAL(12,2) NULL,
  fas_h_prev           DECIMAL(8,2)  NULL COMMENT 'fãs ativos por hora (média do dia), se conhecido',
  data_prevista        DATE         NOT NULL,
  probabilidade        DECIMAL(4,2) NOT NULL DEFAULT 1.00,
  status               ENUM('PREVISTA','CONFIRMADA','EFETIVADA','CANCELADA') NOT NULL DEFAULT 'PREVISTA',
  origem               ENUM('CRM','MANUAL') NOT NULL DEFAULT 'MANUAL',
  crm_id               VARCHAR(64)  NULL,
  obs                  VARCHAR(255) NULL,
  criado_em            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_crm (crm_id),
  KEY ix_data (data_prevista, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4.3 Motivo do desligamento (o banco não registra; preencher para separar voluntário × involuntário)
CREATE TABLE IF NOT EXISTS fc_desligamento_motivo (
  code        VARCHAR(30) NOT NULL PRIMARY KEY,
  tipo        ENUM('VOLUNTARIO','INVOLUNTARIO','AFASTADO','NAO_DESLIGADO') NOT NULL COMMENT 'AFASTADO / NAO_DESLIGADO tiram a pessoa da contagem de desligamentos',
  motivo      VARCHAR(150) NULL,
  registrado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. Tabelas automáticas de apoio
-- ---------------------------------------------------------------------
-- 5.1 Fãs distintos por página e hora (carga diária a partir do msgdashboard)
CREATE TABLE IF NOT EXISTS fc_fans_pagina_hora (
  dia_turno  DATE         NOT NULL,
  hora       TINYINT      NOT NULL,
  pagina     VARCHAR(150) NOT NULL,
  bloco      CHAR(2)      NOT NULL,
  turno      CHAR(1)      NOT NULL,
  fans       INT          NOT NULL,
  reps       SMALLINT     NOT NULL,
  PRIMARY KEY (dia_turno, pagina, hora),
  KEY ix_pagina (pagina, dia_turno)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5.2 Foto diária do cadastro de páginas (o cadastro não tem data de inativação)
CREATE TABLE IF NOT EXISTS fc_pagina_status_diario (
  dia      DATE         NOT NULL,
  page_id  INT          NOT NULL,
  pagina   VARCHAR(255) NOT NULL,
  ativo    TINYINT      NOT NULL,
  PRIMARY KEY (dia, page_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5.3 Eventos de páginas (saídas automáticas pela foto diária; entradas vêm de fc_paginas_previstas)
CREATE TABLE IF NOT EXISTS fc_pagina_eventos (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  pagina        VARCHAR(150) NOT NULL,
  page_id       INT          NULL,
  tipo          ENUM('ENTRADA','SAIDA') NOT NULL,
  data_evento   DATE         NOT NULL,
  origem        ENUM('AUTO','CRM','MANUAL') NOT NULL DEFAULT 'AUTO',
  status        ENUM('PREVISTA','CONFIRMADA','EFETIVADA','CANCELADA') NOT NULL DEFAULT 'EFETIVADA',
  ref_prevista  INT          NULL COMMENT 'id em fc_paginas_previstas',
  registrado_em TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_evento (pagina, tipo, data_evento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5.4 Desligamentos identificados (base da tabela 2)
CREATE TABLE IF NOT EXISTS fc_desligamentos (
  code           VARCHAR(30)  NOT NULL PRIMARY KEY,
  nome           VARCHAR(150) NULL,
  data_saida     DATE         NOT NULL,
  turno          CHAR(1)      NULL,
  origem         ENUM('HEADCOUNT','ESCALA','SEM_ESCALA_CADASTRO') NOT NULL COMMENT 'HEADCOUNT = saiu do cadastro; ESCALA = sem escala há 14+ dias e fora do cadastro; SEM_ESCALA_CADASTRO = no cadastro, sem escala há 45+ dias',
  primeira_escala DATE        NULL,
  dias_de_casa   INT          NULL,
  atualizado_em  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_data (data_saida)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5.5 Log de execução
CREATE TABLE IF NOT EXISTS fc_log_execucao (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  data_calculo DATE NOT NULL,
  etapa     VARCHAR(40) NOT NULL,
  inicio    DATETIME NOT NULL,
  fim       DATETIME NULL,
  status    ENUM('OK','ERRO','RODANDO') NOT NULL DEFAULT 'RODANDO',
  mensagem  VARCHAR(500) NULL,
  KEY ix_data (data_calculo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. TABELAS PEDIDAS
-- ---------------------------------------------------------------------
-- (1) HC semanal realizado — reps com escala na semana, no turno em que mais trabalharam
CREATE TABLE IF NOT EXISTS fc_hc_semanal (
  ano_iso     SMALLINT NOT NULL,
  semana_iso  TINYINT  NOT NULL,
  mes         TINYINT  NOT NULL,
  ano         SMALLINT NOT NULL,
  inicio_semana DATE   NOT NULL,
  hc_a        SMALLINT NOT NULL,
  hc_c        SMALLINT NOT NULL,
  hc_d        SMALLINT NOT NULL,
  hc_total    SMALLINT NOT NULL,
  semana_fechada TINYINT NOT NULL COMMENT '0 = semana atual (escala ainda pode mudar)',
  atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (ano_iso, semana_iso)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- (2) Turnover semanal realizado
CREATE TABLE IF NOT EXISTS fc_to_semanal (
  ano_iso     SMALLINT NOT NULL,
  semana_iso  TINYINT  NOT NULL,
  mes         TINYINT  NOT NULL,
  ano         SMALLINT NOT NULL,
  inicio_semana DATE   NOT NULL,
  to_a        SMALLINT NOT NULL,
  to_c        SMALLINT NOT NULL,
  to_d        SMALLINT NOT NULL,
  to_sem_turno SMALLINT NOT NULL COMMENT 'saiu sem nenhuma escala (ex.: desistiu no treinamento)',
  to_total    SMALLINT NOT NULL,
  to_ate_90_dias SMALLINT NOT NULL COMMENT 'saídas com até 90 dias de casa',
  to_voluntario   SMALLINT NOT NULL,
  to_involuntario SMALLINT NOT NULL,
  atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (ano_iso, semana_iso)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- (3) Previsão de turnover semanal — recalculada todo dia, histórico mantido por data_calculo
CREATE TABLE IF NOT EXISTS fc_prev_to_semanal (
  data_calculo DATE     NOT NULL,
  ano_iso      SMALLINT NOT NULL,
  semana_iso   TINYINT  NOT NULL,
  mes          TINYINT  NOT NULL,
  ano          SMALLINT NOT NULL,
  inicio_semana DATE    NOT NULL,
  prev_to_a    DECIMAL(6,2) NOT NULL,
  prev_to_c    DECIMAL(6,2) NOT NULL,
  prev_to_d    DECIMAL(6,2) NOT NULL,
  prev_to_total DECIMAL(7,2) NOT NULL,
  hc_proj_a    DECIMAL(7,2) NOT NULL COMMENT 'HC projetado no início da semana',
  hc_proj_c    DECIMAL(7,2) NOT NULL,
  hc_proj_d    DECIMAL(7,2) NOT NULL,
  entradas_a   SMALLINT NOT NULL COMMENT 'novatos entrando em operação na semana (fc_contratacoes)',
  entradas_c   SMALLINT NOT NULL,
  entradas_d   SMALLINT NOT NULL,
  taxa_a       DECIMAL(7,5) NOT NULL COMMENT 'saídas por pessoa-semana (histórico)',
  taxa_c       DECIMAL(7,5) NOT NULL,
  taxa_d       DECIMAL(7,5) NOT NULL,
  PRIMARY KEY (data_calculo, ano_iso, semana_iso)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- (4) Pessoas a escalar por dia, sem buffer — recalculada todo dia, histórico mantido
CREATE TABLE IF NOT EXISTS fc_escala_diaria (
  data_calculo DATE     NOT NULL,
  dia          DATE     NOT NULL,
  a            SMALLINT NOT NULL,
  c            SMALLINT NOT NULL,
  d            SMALLINT NOT NULL,
  total        SMALLINT NOT NULL,
  paginas      SMALLINT NOT NULL COMMENT 'páginas cobertas no dia',
  PRIMARY KEY (data_calculo, dia),
  KEY ix_dia (dia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--     Detalhe por página e bloco (auditoria da tabela 4; guardado por 90 dias)
CREATE TABLE IF NOT EXISTS fc_escala_pagina (
  data_calculo DATE     NOT NULL,
  dia          DATE     NOT NULL,
  bloco        CHAR(2)  NOT NULL,
  pagina       VARCHAR(150) NOT NULL,
  modelo       VARCHAR(150) NOT NULL,
  categoria    VARCHAR(10)  NOT NULL,
  fans_pico    DECIMAL(8,2) NOT NULL,
  reps_dedicados SMALLINT   NOT NULL COMMENT '0 = página pequena, divide rep',
  origem       ENUM('HISTORICO','NOVA') NOT NULL,
  PRIMARY KEY (data_calculo, dia, bloco, pagina)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- (5) Pessoas a escalar por dia, só buffers — recalculada todo dia, histórico mantido
CREATE TABLE IF NOT EXISTS fc_buffer_diario (
  data_calculo DATE     NOT NULL,
  dia          DATE     NOT NULL,
  a            SMALLINT NOT NULL,
  c            SMALLINT NOT NULL,
  d            SMALLINT NOT NULL,
  total        SMALLINT NOT NULL,
  abs_a        SMALLINT NOT NULL COMMENT 'buffer de absenteísmo escalado no dia',
  abs_c        SMALLINT NOT NULL,
  abs_d        SMALLINT NOT NULL,
  to_a         SMALLINT NOT NULL COMMENT 'buffer de desligamento escalado no dia',
  to_c         SMALLINT NOT NULL,
  to_d         SMALLINT NOT NULL,
  taxa_abs_a   DECIMAL(5,4) NOT NULL,
  taxa_abs_c   DECIMAL(5,4) NOT NULL,
  taxa_abs_d   DECIMAL(5,4) NOT NULL,
  PRIMARY KEY (data_calculo, dia),
  KEY ix_dia (dia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- (6) Impacto de entradas e saídas de páginas no HC — recalculada todo dia, histórico mantido
CREATE TABLE IF NOT EXISTS fc_impacto_paginas (
  data_calculo  DATE         NOT NULL,
  tipo          ENUM('ENTRADA','SAIDA') NOT NULL,
  ref_id        INT          NOT NULL COMMENT 'id em fc_paginas_previstas (ENTRADA) ou fc_pagina_eventos (SAIDA)',
  pagina        VARCHAR(150) NOT NULL,
  data_evento   DATE         NOT NULL,
  status        VARCHAR(12)  NOT NULL,
  probabilidade DECIMAL(4,2) NOT NULL,
  fans_h_media  DECIMAL(8,2) NOT NULL,
  reps_h_a      DECIMAL(5,2) NOT NULL COMMENT 'variação de reps por hora (negativo na saída)',
  reps_h_c      DECIMAL(5,2) NOT NULL,
  reps_h_d      DECIMAL(5,2) NOT NULL,
  hc_a          DECIMAL(6,2) NOT NULL COMMENT 'variação de HC (com 3x1 e absenteísmo), × probabilidade',
  hc_c          DECIMAL(6,2) NOT NULL,
  hc_d          DECIMAL(6,2) NOT NULL,
  hc_total      DECIMAL(7,2) NOT NULL,
  PRIMARY KEY (data_calculo, tipo, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. Tabelas de trabalho (uso interno dos procedimentos; podem ser apagadas a qualquer momento)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS fc_wrk_turnos (
  codigo VARCHAR(30) NOT NULL, nome VARCHAR(150) NULL, td DATE NOT NULL, tu CHAR(1) NOT NULL,
  KEY ix_cod (codigo, td), KEY ix_td (td)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fc_wrk_rep (
  codigo VARCHAR(30) NOT NULL PRIMARY KEY, nome VARCHAR(150) NULL,
  primeira DATE NULL, ultima DATE NULL, ultima_plan DATE NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fc_wrk_perfil (
  pagina VARCHAR(150) NOT NULL, dow TINYINT NOT NULL, bloco CHAR(2) NOT NULL, fans_pico DECIMAL(8,2) NOT NULL,
  PRIMARY KEY (pagina, dow, bloco)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fc_wrk_cobertura (
  pagina VARCHAR(150) NOT NULL, bloco CHAR(2) NOT NULL, PRIMARY KEY (pagina, bloco)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fc_wrk_fator (
  dow TINYINT NOT NULL, bloco CHAR(2) NOT NULL, fator DECIMAL(8,4) NOT NULL, PRIMARY KEY (dow, bloco)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fc_wrk_carga (
  dia DATE NOT NULL, bloco CHAR(2) NOT NULL, pagina VARCHAR(150) NOT NULL, modelo VARCHAR(150) NOT NULL,
  categoria VARCHAR(10) NOT NULL, fans DECIMAL(8,2) NOT NULL, origem ENUM('HISTORICO','NOVA') NOT NULL,
  reps_ded SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (dia, bloco, pagina), KEY ix_modelo (dia, bloco, modelo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fc_wrk_bloco (
  dia DATE NOT NULL, bloco CHAR(2) NOT NULL, reps SMALLINT NOT NULL, PRIMARY KEY (dia, bloco)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fc_wrk_abs_taxa (
  dow TINYINT NOT NULL PRIMARY KEY, ta DECIMAL(6,4) NOT NULL, tc DECIMAL(6,4) NOT NULL, td DECIMAL(6,4) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fc_wrk_nova (
  id INT NOT NULL, dow TINYINT NOT NULL, bloco CHAR(2) NOT NULL, fans DECIMAL(8,2) NOT NULL, reps DECIMAL(5,2) NOT NULL,
  PRIMARY KEY (id, dow, bloco)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
