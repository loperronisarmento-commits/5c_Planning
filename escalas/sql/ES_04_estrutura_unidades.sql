-- =====================================================================
-- ES_04_estrutura_unidades.sql
-- 5C Escalas · Planning tool v4: estrutura de 01/11/2026.
--
-- O QUE ESTE SCRIPT FAZ (só adiciona; não apaga nem muda dado existente)
--   - Tabelas novas: unidades, liderança, núcleos (Decurio + Equites),
--     foto dos postos usada no plano, páginas de cada posto e a equipe
--     fixa de cada vaga (dono e cobertura).
--   - Colunas novas em esc_colaborador_config, esc_plano e
--     esc_plano_alocacao; valores novos em ENUMs (os antigos continuam).
--   - Modelo "5x2 rotativo 21 dias" e os parâmetros da v4.
--   - View vw_esc_estrutura_rep (pessoas por unidade, turno e núcleo),
--     para o Capacity usar no lugar do fc_estrutura_rep.
--
-- Carteiras NÃO ficam aqui: a fonte é 5c_planning.fc_pagina_carteira
-- (seção Capacity). O código de carteira (U1C1…U3C2) é o mesmo.
--
-- COMO RODAR: Navicat, servidor .11, arquivo inteiro. Pode rodar de novo.
-- Depois: ES_05_carga_estrutura_27out.sql.
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

-- Ajudantes só desta execução (apagados no fim).
DROP PROCEDURE IF EXISTS esc_tmp_add_coluna;
DROP PROCEDURE IF EXISTS esc_tmp_executar_se;
DELIMITER $$
CREATE PROCEDURE esc_tmp_add_coluna(IN p_tabela VARCHAR(64), IN p_coluna VARCHAR(64), IN p_def TEXT)
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = '5c_escalas' AND TABLE_NAME = p_tabela AND COLUMN_NAME = p_coluna) THEN
    SET @esc_sql = CONCAT('ALTER TABLE `5c_escalas`.`', p_tabela, '` ADD COLUMN `', p_coluna, '` ', p_def);
    PREPARE s FROM @esc_sql; EXECUTE s; DEALLOCATE PREPARE s;
  END IF;
END$$
-- Executa o comando só se a condição (um SELECT que devolve 0/1) der 1.
CREATE PROCEDURE esc_tmp_executar_se(IN p_cond TEXT, IN p_cmd TEXT)
BEGIN
  SET @esc_ok = 0;
  SET @esc_sql = CONCAT('SELECT (', p_cond, ') INTO @esc_ok');
  PREPARE s FROM @esc_sql; EXECUTE s; DEALLOCATE PREPARE s;
  IF @esc_ok = 1 THEN
    SET @esc_sql = p_cmd;
    PREPARE s FROM @esc_sql; EXECUTE s; DEALLOCATE PREPARE s;
  END IF;
END$$
DELIMITER ;

-- =====================================================================
-- F. ESTRUTURA DE 01/11 (Guild = unidade, Team = OM, Party = núcleo)
-- =====================================================================

-- F1. Unidades (Guild no Planner).
CREATE TABLE IF NOT EXISTS esc_unidade (
  id              TINYINT UNSIGNED NOT NULL COMMENT '1 a 3',
  nome            VARCHAR(40)  NOT NULL COMMENT 'Unidade 1…',
  guild_id        INT          NULL COMMENT 'guildId do historicplanner, quando a Guild for criada',
  guild_nome      VARCHAR(100) NULL,
  ativo           TINYINT(1)   NOT NULL DEFAULT 1,
  atualizado_em   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  atualizado_por  INT          NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB COMMENT='Unidades (Guild no Planner)';

-- F2. Liderança acima do núcleo: OUM (unidade) e OM (unidade × turno = Team no Planner).
CREATE TABLE IF NOT EXISTS esc_lideranca (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nivel           ENUM('OUM','OM') NOT NULL,
  unidade         TINYINT UNSIGNED NOT NULL,
  turno           ENUM('A','C','D') NULL COMMENT 'OM: turno; OUM: NULL',
  rep_code        VARCHAR(30)  NULL COMMENT 'NULL = sem código no headcount (pendência)',
  nome            VARCHAR(120) NOT NULL,
  team_id         INT          NULL COMMENT 'teamId do historicplanner (OM)',
  vigente_desde   DATE         NOT NULL,
  vigente_ate     DATE         NULL,
  origem          ENUM('CARGA_27OUT','PLANNER','TELA') NOT NULL DEFAULT 'TELA',
  criado_em       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  criado_por      INT          NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_lider (nivel, unidade, turno, vigente_desde),
  KEY ix_lider_rep (rep_code),
  CONSTRAINT fk_lider_unidade FOREIGN KEY (unidade) REFERENCES esc_unidade (id),
  CONSTRAINT ck_lider_vig CHECK (vigente_ate IS NULL OR vigente_ate >= vigente_desde)
) ENGINE=InnoDB COMMENT='OUM e OM, com vigência';

-- F3. Núcleos: Decurio (OS) + Equites, fixos no turno (Party no Planner).
--     deslocamento = ponto de partida no ciclo de 21 dias na data-âncora
--     (núcleo 1 = 0 → P1; núcleo 2 = 14 → P3; núcleo 3 = 7 → P2).
CREATE TABLE IF NOT EXISTS esc_nucleo (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  unidade         TINYINT UNSIGNED NOT NULL,
  turno           ENUM('A','C','D') NOT NULL,
  numero          TINYINT UNSIGNED NOT NULL COMMENT '1 a 3 dentro da unidade e turno',
  decurio_code    VARCHAR(30)  NULL COMMENT 'NULL = Decurio sem código no headcount (pendência)',
  decurio_nome    VARCHAR(120) NOT NULL,
  deslocamento    TINYINT UNSIGNED NOT NULL COMMENT 'posição no ciclo de 21 dias na data-âncora',
  party_id        INT          NULL COMMENT 'partyId do historicplanner',
  vigente_desde   DATE         NOT NULL,
  vigente_ate     DATE         NULL,
  origem          ENUM('CARGA_27OUT','PLANNER','TELA') NOT NULL DEFAULT 'TELA',
  criado_em       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  criado_por      INT          NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_nucleo (unidade, turno, numero, vigente_desde),
  KEY ix_nucleo_decurio (decurio_code),
  CONSTRAINT fk_nucleo_unidade FOREIGN KEY (unidade) REFERENCES esc_unidade (id),
  CONSTRAINT ck_nucleo_num CHECK (numero BETWEEN 1 AND 3),
  CONSTRAINT ck_nucleo_desl CHECK (deslocamento < 21),
  CONSTRAINT ck_nucleo_vig CHECK (vigente_ate IS NULL OR vigente_ate >= vigente_desde)
) ENGINE=InnoDB COMMENT='Núcleos (Decurio + Equites), fixos no turno';

-- F4. Foto dos postos usada no plano (vem do Capacity: fc_postos; ou do
--     modelo de 27/10 na carga inicial). assinatura = páginas do posto em
--     ordem + tipo; serve para manter a equipe quando o Capacity renumera.
CREATE TABLE IF NOT EXISTS esc_posto (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  versao               VARCHAR(20)  NOT NULL COMMENT 'data_calculo do Capacity (AAAA-MM-DD) ou MODELO_27OUT',
  unidade              TINYINT UNSIGNED NOT NULL,
  carteira             VARCHAR(8)   NOT NULL COMMENT 'U1C1…U3C2 (fonte: 5c_planning.fc_carteira)',
  turno                ENUM('A','C','D') NOT NULL,
  seq                  SMALLINT UNSIGNED NOT NULL COMMENT 'número do posto dentro da carteira e turno',
  tipo                 ENUM('titular','double','apoio','assistente') NOT NULL,
  pagina_principal     VARCHAR(150) NOT NULL,
  n_paginas            TINYINT UNSIGNED NOT NULL,
  assinatura           VARCHAR(400) NOT NULL,
  carga                DECIMAL(6,1) NULL,
  capacidade           DECIMAL(5,1) NULL,
  nucleo_dono_id       INT UNSIGNED NULL,
  nucleo_cobertura_id  INT UNSIGNED NULL COMMENT 'NULL = folga do dono coberta pelo buffer',
  vigente_desde        DATE         NOT NULL,
  vigente_ate          DATE         NULL,
  criado_em            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_posto (versao, carteira, turno, seq),
  KEY ix_posto_assin (unidade, turno, assinatura(120)),
  CONSTRAINT fk_posto_unidade FOREIGN KEY (unidade) REFERENCES esc_unidade (id),
  CONSTRAINT fk_posto_dono FOREIGN KEY (nucleo_dono_id) REFERENCES esc_nucleo (id),
  CONSTRAINT fk_posto_cob FOREIGN KEY (nucleo_cobertura_id) REFERENCES esc_nucleo (id)
) ENGINE=InnoDB COMMENT='Postos usados no plano (foto do Capacity)';

CREATE TABLE IF NOT EXISTS esc_posto_pagina (
  posto_id        INT UNSIGNED NOT NULL,
  pagina          VARCHAR(150) NOT NULL COMMENT '= 5c_pages.pages.name',
  page_id         INT          NULL COMMENT '5c_pages.pages.id (página ativa); é o Model do CSV',
  fracao          DECIMAL(6,4) NOT NULL,
  fans_h          DECIMAL(6,1) NULL,
  apoio           TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = excedente de página grande',
  PRIMARY KEY (posto_id, pagina),
  KEY ix_pp_pagina (pagina),
  CONSTRAINT fk_pp_posto FOREIGN KEY (posto_id) REFERENCES esc_posto (id) ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='Páginas de cada posto';

-- F5. Equipe fixa de cada vaga. DONO = núcleo dono, nos dias dele;
--     COBERTURA = núcleo que cobre, nos dias de folga do dono.
--     Nível 3–5: uma pessoa com fatia 1. Nível 0–2: 2 ou 3 pessoas cujas
--     fatias somam 1 (cada pessoa em no máximo 2 vagas).
CREATE TABLE IF NOT EXISTS esc_vaga_equipe (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  posto_id        INT UNSIGNED NOT NULL,
  vaga            ENUM('DONO','COBERTURA') NOT NULL,
  rep_code        VARCHAR(30)  NOT NULL,
  fatia           DECIMAL(4,3) NOT NULL COMMENT 'parte dos dias da vaga que a pessoa faz',
  ordem           TINYINT UNSIGNED NOT NULL DEFAULT 1,
  status          ENUM('PROPOSTA','CONFIRMADA') NOT NULL DEFAULT 'PROPOSTA',
  motivo          VARCHAR(255) NULL COMMENT 'por que esta pessoa (histórico na página, time do posto…)',
  vigente_desde   DATE         NOT NULL,
  vigente_ate     DATE         NULL,
  origem          ENUM('CARGA_27OUT','MONTADOR','TELA') NOT NULL DEFAULT 'MONTADOR',
  criado_em       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  criado_por      INT          NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_vaga_pessoa (posto_id, vaga, rep_code, vigente_desde),
  KEY ix_vaga_rep (rep_code, vigente_desde),
  CONSTRAINT fk_vaga_posto FOREIGN KEY (posto_id) REFERENCES esc_posto (id),
  CONSTRAINT ck_vaga_fatia CHECK (fatia > 0 AND fatia <= 1),
  CONSTRAINT ck_vaga_vig CHECK (vigente_ate IS NULL OR vigente_ate >= vigente_desde)
) ENGINE=InnoDB COMMENT='Equipe fixa de cada vaga (dono e cobertura)';

-- =====================================================================
-- Colunas e valores novos nas tabelas da v3 (as antigas continuam)
-- =====================================================================

-- Colaboradores
CALL esc_tmp_add_coluna('esc_colaborador_config', 'unidade',       "TINYINT UNSIGNED NULL COMMENT 'v4: unidade' AFTER turno");
CALL esc_tmp_add_coluna('esc_colaborador_config', 'nucleo_id',     "INT UNSIGNED NULL COMMENT 'v4: núcleo (Party)' AFTER unidade");
CALL esc_tmp_add_coluna('esc_colaborador_config', 'nivel',         "TINYINT UNSIGNED NULL COMMENT 'v4: nível 0 a 5' AFTER nucleo_id");
CALL esc_tmp_add_coluna('esc_colaborador_config', 'funcao',        "ENUM('EQUITE','DECURIO') NULL COMMENT 'v4' AFTER nivel");
CALL esc_tmp_add_coluna('esc_colaborador_config', 'fatia_regular', "DECIMAL(4,3) NULL COMMENT 'v4: parte dos dias de trabalho em página (níveis 0–2); NULL = a do nível' AFTER funcao");
ALTER TABLE esc_colaborador_config
  MODIFY COLUMN tipo   ENUM('TIME','BUFFER','NUCLEO') NOT NULL DEFAULT 'TIME',
  MODIFY COLUMN origem ENUM('CARGA_HEADCOUNT','TELA','RELEITURA','CARGA_ESTRUTURA') NOT NULL DEFAULT 'TELA';
CALL esc_tmp_executar_se(
  "SELECT COUNT(*) = 0 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = '5c_escalas' AND CONSTRAINT_NAME = 'fk_colab_nucleo'",
  "ALTER TABLE `5c_escalas`.`esc_colaborador_config` ADD CONSTRAINT fk_colab_nucleo FOREIGN KEY (nucleo_id) REFERENCES `5c_escalas`.`esc_nucleo` (id)");
CALL esc_tmp_executar_se(
  "SELECT COUNT(*) = 0 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = '5c_escalas' AND TABLE_NAME = 'esc_colaborador_config' AND INDEX_NAME = 'ix_colab_nucleo'",
  "ALTER TABLE `5c_escalas`.`esc_colaborador_config` ADD KEY ix_colab_nucleo (nucleo_id, vigente_desde)");

-- Plano: qual estrutura gerou a versão
CALL esc_tmp_add_coluna('esc_plano', 'estrutura', "ENUM('V3','V4') NOT NULL DEFAULT 'V3' AFTER time_nome");
CALL esc_tmp_add_coluna('esc_plano', 'unidade',   "TINYINT UNSIGNED NULL COMMENT 'v4: NULL = todas' AFTER estrutura");

-- Alocação: posto e vaga
CALL esc_tmp_add_coluna('esc_plano_alocacao', 'posto_id', "INT UNSIGNED NULL COMMENT 'v4' AFTER pagina");
CALL esc_tmp_add_coluna('esc_plano_alocacao', 'vaga',     "ENUM('DONO','COBERTURA') NULL COMMENT 'v4' AFTER posto_id");
ALTER TABLE esc_plano_alocacao
  MODIFY COLUMN origem ENUM('PARTY_DONA','OUTRA_PARTY','TERTIUS','TERTIUS_ASSISTENTE','BUFFER','MANUAL','LACUNA',
                            'VAGA_DONO','VAGA_COBERTURA','BUFFER_NUCLEO','TRANSICAO') NOT NULL;

-- Folgas e fotos de entrada: valores novos
ALTER TABLE esc_plano_folga
  MODIFY COLUMN tipo ENUM('CICLO','FOLGA_FIXA','INDISPONIBILIDADE','ADMIN','ROTACAO') NOT NULL;
ALTER TABLE esc_entrada_foto
  MODIFY COLUMN fonte ENUM('CAPACITY','ESTRUTURA','MEMBROS','PAGINAS','TROCAS_PAGINA','HEADCOUNT','INDISPONIBILIDADE',
                           'COLABORADORES','MODELOS','PARAMETROS','POSTOS','NUCLEOS','EQUIPES','CARTEIRAS') NOT NULL;

-- =====================================================================
-- Modelo e parâmetros da v4
-- =====================================================================

-- Ciclo de 21 dias, de segunda a domingo: P1 1011100 · P2 0111011 · P3 1100111.
-- Máximo de 4 dias seguidos (inclusive na virada do ciclo).
INSERT IGNORE INTO esc_modelo_escala (nome, tipo, ciclo, horas_dia, trilhas, admin_a_cada, turno_d_conta_inicio, max_seguidos)
VALUES ('5x2 rotativo 21 dias', 'CONTINUO', '101110001110111100111', 8.00, 3, NULL, 1, 4);

INSERT IGNORE INTO esc_parametro (chave, valor, tipo, grupo, descricao) VALUES
  ('v4_virada',               '2026-11-01 05:00', 'TEXTO',   'v4', 'Início da estrutura de 01/11 (T2 de 01/11 em diante)'),
  ('v4_rotacao_ancora',       '2026-11-02',       'TEXTO',   'v4', 'Segunda-feira da semana 1 do ciclo de 21 dias'),
  ('v4_nucleos_ativos_dia',   '2',                'INT',     'v4', 'Núcleos em serviço por dia em cada unidade e turno (a publicação confere)'),
  ('v4_fator_pessoas_posto',  '1.5',              'DECIMAL', 'v4', 'Pessoas por posto na rotação de 21 dias (21 ÷ 14)'),
  ('v4_fatia_nivel_0',        '0.40',             'DECIMAL', 'v4', 'Nível 0: parte dos dias de trabalho em página (o resto é buffer)'),
  ('v4_fatia_nivel_1',        '0.60',             'DECIMAL', 'v4', 'Nível 1: parte dos dias de trabalho em página'),
  ('v4_fatia_nivel_2',        '0.80',             'DECIMAL', 'v4', 'Nível 2: parte dos dias de trabalho em página'),
  ('v4_max_vagas_nivel_baixo','2',                'INT',     'v4', 'Máximo de vagas por pessoa de nível 0 a 2 (menor troca de gente por página)'),
  ('v4_historico_dias',       '28',               'INT',     'v4', 'Dias de scheduleday para escolher quem já atua na página'),
  ('v4_horas_semana_max',     '52',               'INT',     'v4', 'Política 6.6: máximo de horas por semana'),
  ('v4_horas_seguidas_max',   '12',               'INT',     'v4', 'Política 6.6: máximo de horas seguidas');

-- =====================================================================
-- View para o Capacity: pessoas por unidade, turno e núcleo (vigentes hoje)
-- =====================================================================
CREATE OR REPLACE VIEW vw_esc_estrutura_rep AS
SELECT c.rep_code                    AS code,
       c.unidade,
       c.turno,
       n.numero                      AS nucleo,
       n.decurio_nome                AS os,
       c.nivel,
       c.funcao,
       c.vigente_desde,
       c.vigente_ate
  FROM esc_colaborador_config c
  JOIN esc_nucleo n ON n.id = c.nucleo_id
 WHERE c.vigente_desde <= CURDATE()
   AND (c.vigente_ate IS NULL OR c.vigente_ate >= CURDATE());

DROP PROCEDURE IF EXISTS esc_tmp_add_coluna;
DROP PROCEDURE IF EXISTS esc_tmp_executar_se;

-- Conferência
SELECT TABLE_NAME, TABLE_ROWS
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = '5c_escalas'
   AND TABLE_NAME IN ('esc_unidade','esc_lideranca','esc_nucleo','esc_posto','esc_posto_pagina','esc_vaga_equipe','vw_esc_estrutura_rep');
