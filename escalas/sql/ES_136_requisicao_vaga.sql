SET NAMES utf8mb4;
-- =====================================================================
-- ES_136 · Requisição de vagas (09/11/2026)
--
-- O ciclo de contratação começa com a requisição de vagas ao recrutamento, por turma
-- (data de início do treinamento) e turno. A tela Requisição de vagas (o Simulações 5C
-- dentro do módulo) mostra o plano do Capacity (5c_planning.fc_contratacoes, só leitura)
-- e grava aqui o que foi pedido de fato e quantos foram contratados.
--   etapa ABERTA     requisição enviada ao recrutamento (qtd_pedida)
--         CONTRATADA recrutamento fechou (qtd_contratada)
--         CANCELADA  não vai mais acontecer
-- Em treinamento e operando saem das turmas de treinamento (esc_treinamento_turma).
-- Carga inicial: as duas turmas que já tinham pedido no plano de 28/10 (fc_contratacoes CONFIRMADA):
--   02/11 pedida 4 / 7 / 4, contratada 4 / 7 / 3 (o HC das turmas NH45);
--   16/11 pedida 5 / 9 / 6, ainda aberta.
-- Roda no 5c_escalas e no 5c_escalas_treino (sem a carga). Pode rodar de novo.
-- =====================================================================

USE `5c_escalas`;
CREATE TABLE IF NOT EXISTS esc_requisicao_vaga (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  turma_inicio DATE NOT NULL COMMENT 'início do treinamento da turma',
  turno ENUM('A','C','D') NOT NULL,
  qtd_pedida SMALLINT UNSIGNED NOT NULL,
  qtd_contratada SMALLINT UNSIGNED NULL,
  etapa ENUM('ABERTA','CONTRATADA','CANCELADA') NOT NULL DEFAULT 'ABERTA',
  aberta_em DATE NULL COMMENT 'quando a requisição foi enviada (NULL = antes do sistema)',
  referencia VARCHAR(60) NULL COMMENT 'número da requisição no RH',
  obs VARCHAR(255) NULL,
  criado_por INT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_por INT NULL,
  atualizado_em DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_req_turma_turno (turma_inicio, turno)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Requisição de vagas ao recrutamento, por turma e turno';

INSERT IGNORE INTO esc_requisicao_vaga (turma_inicio, turno, qtd_pedida, qtd_contratada, etapa, aberta_em, obs) VALUES
 ('2026-11-02', 'A', 4, 4, 'CONTRATADA', NULL, 'Carga do plano de 28/10; contratados = HC da NH45A2026'),
 ('2026-11-02', 'C', 7, 7, 'CONTRATADA', NULL, 'Carga do plano de 28/10; contratados = HC da NH45C2026'),
 ('2026-11-02', 'D', 4, 3, 'CONTRATADA', NULL, 'Carga do plano de 28/10; contratados = HC da NH45D2026'),
 ('2026-11-16', 'A', 5, NULL, 'ABERTA', NULL, 'Carga do plano de 28/10 (Pedido feito)'),
 ('2026-11-16', 'C', 9, NULL, 'ABERTA', NULL, 'Carga do plano de 28/10 (Pedido feito)'),
 ('2026-11-16', 'D', 6, NULL, 'ABERTA', NULL, 'Carga do plano de 28/10 (Pedido feito)');

USE `5c_escalas_treino`;
CREATE TABLE IF NOT EXISTS esc_requisicao_vaga LIKE `5c_escalas`.esc_requisicao_vaga;

USE `5c_escalas`;
SELECT 'tabela no 5c_escalas (1)' AS item, COUNT(*) AS tem FROM information_schema.TABLES WHERE TABLE_SCHEMA = '5c_escalas' AND TABLE_NAME = 'esc_requisicao_vaga'
UNION ALL SELECT 'tabela no Modo Treino (1)', COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '5c_escalas_treino' AND TABLE_NAME = 'esc_requisicao_vaga'
UNION ALL SELECT 'requisições carregadas (6)', COUNT(*) FROM esc_requisicao_vaga
UNION ALL SELECT 'pedidas 02/11 e 16/11 (35)', SUM(qtd_pedida) FROM esc_requisicao_vaga;
