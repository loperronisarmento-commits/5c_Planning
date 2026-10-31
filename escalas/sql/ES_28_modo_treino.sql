-- =====================================================================
-- ES_28_modo_treino.sql  (30/10)
-- Modo Treino: um schema separado, 5c_escalas_treino, com as mesmas tabelas
-- do 5c_escalas (estrutura e chaves estrangeiras). Quem liga o Modo Treino na
-- tela (Ajuda › Tutorial) grava e lê só aqui; o Capacity, o Planner, o
-- headcount e a escala postada continuam só sendo lidos, e no treino o sistema
-- não aplica saída no Capacity.
--
-- Os dados não são criados por este script: o botão "Recomeçar o treino" (e o
-- primeiro acesso ao treino) apaga tudo do 5c_escalas_treino e copia de novo
-- os cadastros do 5c_escalas (modelos, núcleos, postos, equipes, colaboradores,
-- liderança, parâmetros e acessos), mais alguns pedidos fictícios para praticar.
-- Escalas, pedidos reais, saídas e logs do 5c_escalas não são copiados.
--
-- ANTES DE RODAR: troque <usuario_do_portal> e <host> pelo mesmo usuário do
-- ES_03 (o usuário do banco que o portal usa). Pode rodar de novo.
-- =====================================================================

SET NAMES utf8mb4;
CREATE DATABASE IF NOT EXISTS `5c_escalas_treino` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 1. Tabelas com a mesma estrutura do 5c_escalas
CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.`esc_acesso` LIKE `5c_escalas`.`esc_acesso`;
CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.`esc_capacity_versao` LIKE `5c_escalas`.`esc_capacity_versao`;
CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.`esc_capacity_versao_posto` LIKE `5c_escalas`.`esc_capacity_versao_posto`;
CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.`esc_capacity_versao_ut` LIKE `5c_escalas`.`esc_capacity_versao_ut`;
CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.`esc_ciclo_estado` LIKE `5c_escalas`.`esc_ciclo_estado`;
CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.`esc_colaborador_config` LIKE `5c_escalas`.`esc_colaborador_config`;
CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.`esc_entrada_diferenca` LIKE `5c_escalas`.`esc_entrada_diferenca`;
CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.`esc_entrada_foto` LIKE `5c_escalas`.`esc_entrada_foto`;
CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.`esc_estrutura_pendencia` LIKE `5c_escalas`.`esc_estrutura_pendencia`;
CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.`esc_exportacao` LIKE `5c_escalas`.`esc_exportacao`;
CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.`esc_hora_extra` LIKE `5c_escalas`.`esc_hora_extra`;
CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.`esc_indisponibilidade` LIKE `5c_escalas`.`esc_indisponibilidade`;
CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.`esc_indisponibilidade_data` LIKE `5c_escalas`.`esc_indisponibilidade_data`;
CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.`esc_lideranca` LIKE `5c_escalas`.`esc_lideranca`;
CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.`esc_log` LIKE `5c_escalas`.`esc_log`;
CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.`esc_modelo_escala` LIKE `5c_escalas`.`esc_modelo_escala`;
CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.`esc_nucleo` LIKE `5c_escalas`.`esc_nucleo`;
CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.`esc_pagina_previsao` LIKE `5c_escalas`.`esc_pagina_previsao`;
CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.`esc_parametro` LIKE `5c_escalas`.`esc_parametro`;
CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.`esc_plano` LIKE `5c_escalas`.`esc_plano`;
CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.`esc_plano_alocacao` LIKE `5c_escalas`.`esc_plano_alocacao`;
CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.`esc_plano_diferenca` LIKE `5c_escalas`.`esc_plano_diferenca`;
CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.`esc_plano_folga` LIKE `5c_escalas`.`esc_plano_folga`;
CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.`esc_plano_postagem` LIKE `5c_escalas`.`esc_plano_postagem`;
CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.`esc_posto` LIKE `5c_escalas`.`esc_posto`;
CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.`esc_posto_pagina` LIKE `5c_escalas`.`esc_posto_pagina`;
CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.`esc_recalculo_evento` LIKE `5c_escalas`.`esc_recalculo_evento`;
CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.`esc_saida_checagem` LIKE `5c_escalas`.`esc_saida_checagem`;
CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.`esc_saida_pagina` LIKE `5c_escalas`.`esc_saida_pagina`;
CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.`esc_sr_infraction` LIKE `5c_escalas`.`esc_sr_infraction`;
CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.`esc_sr_merito` LIKE `5c_escalas`.`esc_sr_merito`;
CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.`esc_sugestao` LIKE `5c_escalas`.`esc_sugestao`;
CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.`esc_troca_postagem` LIKE `5c_escalas`.`esc_troca_postagem`;
CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.`esc_unidade` LIKE `5c_escalas`.`esc_unidade`;
CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.`esc_vaga_equipe` LIKE `5c_escalas`.`esc_vaga_equipe`;
CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.`esc_violacao_acao` LIKE `5c_escalas`.`esc_violacao_acao`;

-- 2. Chaves estrangeiras (o CREATE ... LIKE não copia). Só cria a que ainda não existe.
SET @tem := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '5c_escalas_treino' AND CONSTRAINT_NAME = 'fk_colab_modelo');
SET @sql := IF(@tem = 0, 'ALTER TABLE `5c_escalas_treino`.`esc_colaborador_config` ADD CONSTRAINT `fk_colab_modelo` FOREIGN KEY (`modelo_id`) REFERENCES `5c_escalas_treino`.`esc_modelo_escala` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @tem := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '5c_escalas_treino' AND CONSTRAINT_NAME = 'fk_colab_nucleo');
SET @sql := IF(@tem = 0, 'ALTER TABLE `5c_escalas_treino`.`esc_colaborador_config` ADD CONSTRAINT `fk_colab_nucleo` FOREIGN KEY (`nucleo_id`) REFERENCES `5c_escalas_treino`.`esc_nucleo` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @tem := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '5c_escalas_treino' AND CONSTRAINT_NAME = 'fk_entdif_de');
SET @sql := IF(@tem = 0, 'ALTER TABLE `5c_escalas_treino`.`esc_entrada_diferenca` ADD CONSTRAINT `fk_entdif_de` FOREIGN KEY (`foto_de_id`) REFERENCES `5c_escalas_treino`.`esc_entrada_foto` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @tem := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '5c_escalas_treino' AND CONSTRAINT_NAME = 'fk_entdif_para');
SET @sql := IF(@tem = 0, 'ALTER TABLE `5c_escalas_treino`.`esc_entrada_diferenca` ADD CONSTRAINT `fk_entdif_para` FOREIGN KEY (`foto_para_id`) REFERENCES `5c_escalas_treino`.`esc_entrada_foto` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @tem := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '5c_escalas_treino' AND CONSTRAINT_NAME = 'fk_indisp_data');
SET @sql := IF(@tem = 0, 'ALTER TABLE `5c_escalas_treino`.`esc_indisponibilidade_data` ADD CONSTRAINT `fk_indisp_data` FOREIGN KEY (`indisponibilidade_id`) REFERENCES `5c_escalas_treino`.`esc_indisponibilidade` (`id`) ON DELETE CASCADE', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @tem := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '5c_escalas_treino' AND CONSTRAINT_NAME = 'fk_lider_unidade');
SET @sql := IF(@tem = 0, 'ALTER TABLE `5c_escalas_treino`.`esc_lideranca` ADD CONSTRAINT `fk_lider_unidade` FOREIGN KEY (`unidade`) REFERENCES `5c_escalas_treino`.`esc_unidade` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @tem := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '5c_escalas_treino' AND CONSTRAINT_NAME = 'fk_nucleo_unidade');
SET @sql := IF(@tem = 0, 'ALTER TABLE `5c_escalas_treino`.`esc_nucleo` ADD CONSTRAINT `fk_nucleo_unidade` FOREIGN KEY (`unidade`) REFERENCES `5c_escalas_treino`.`esc_unidade` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @tem := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '5c_escalas_treino' AND CONSTRAINT_NAME = 'fk_plano_anterior');
SET @sql := IF(@tem = 0, 'ALTER TABLE `5c_escalas_treino`.`esc_plano` ADD CONSTRAINT `fk_plano_anterior` FOREIGN KEY (`plano_anterior_id`) REFERENCES `5c_escalas_treino`.`esc_plano` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @tem := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '5c_escalas_treino' AND CONSTRAINT_NAME = 'fk_plano_recalc');
SET @sql := IF(@tem = 0, 'ALTER TABLE `5c_escalas_treino`.`esc_plano` ADD CONSTRAINT `fk_plano_recalc` FOREIGN KEY (`recalculo_id`) REFERENCES `5c_escalas_treino`.`esc_recalculo_evento` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @tem := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '5c_escalas_treino' AND CONSTRAINT_NAME = 'fk_aloc_plano');
SET @sql := IF(@tem = 0, 'ALTER TABLE `5c_escalas_treino`.`esc_plano_alocacao` ADD CONSTRAINT `fk_aloc_plano` FOREIGN KEY (`plano_id`) REFERENCES `5c_escalas_treino`.`esc_plano` (`id`) ON DELETE CASCADE', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @tem := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '5c_escalas_treino' AND CONSTRAINT_NAME = 'fk_pdif_de');
SET @sql := IF(@tem = 0, 'ALTER TABLE `5c_escalas_treino`.`esc_plano_diferenca` ADD CONSTRAINT `fk_pdif_de` FOREIGN KEY (`plano_de_id`) REFERENCES `5c_escalas_treino`.`esc_plano` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @tem := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '5c_escalas_treino' AND CONSTRAINT_NAME = 'fk_pdif_para');
SET @sql := IF(@tem = 0, 'ALTER TABLE `5c_escalas_treino`.`esc_plano_diferenca` ADD CONSTRAINT `fk_pdif_para` FOREIGN KEY (`plano_para_id`) REFERENCES `5c_escalas_treino`.`esc_plano` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @tem := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '5c_escalas_treino' AND CONSTRAINT_NAME = 'fk_pdif_recalc');
SET @sql := IF(@tem = 0, 'ALTER TABLE `5c_escalas_treino`.`esc_plano_diferenca` ADD CONSTRAINT `fk_pdif_recalc` FOREIGN KEY (`recalculo_id`) REFERENCES `5c_escalas_treino`.`esc_recalculo_evento` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @tem := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '5c_escalas_treino' AND CONSTRAINT_NAME = 'fk_pdif_sug');
SET @sql := IF(@tem = 0, 'ALTER TABLE `5c_escalas_treino`.`esc_plano_diferenca` ADD CONSTRAINT `fk_pdif_sug` FOREIGN KEY (`sugestao_id`) REFERENCES `5c_escalas_treino`.`esc_sugestao` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @tem := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '5c_escalas_treino' AND CONSTRAINT_NAME = 'fk_folga_plano');
SET @sql := IF(@tem = 0, 'ALTER TABLE `5c_escalas_treino`.`esc_plano_folga` ADD CONSTRAINT `fk_folga_plano` FOREIGN KEY (`plano_id`) REFERENCES `5c_escalas_treino`.`esc_plano` (`id`) ON DELETE CASCADE', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @tem := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '5c_escalas_treino' AND CONSTRAINT_NAME = 'fk_posto_cob');
SET @sql := IF(@tem = 0, 'ALTER TABLE `5c_escalas_treino`.`esc_posto` ADD CONSTRAINT `fk_posto_cob` FOREIGN KEY (`nucleo_cobertura_id`) REFERENCES `5c_escalas_treino`.`esc_nucleo` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @tem := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '5c_escalas_treino' AND CONSTRAINT_NAME = 'fk_posto_dono');
SET @sql := IF(@tem = 0, 'ALTER TABLE `5c_escalas_treino`.`esc_posto` ADD CONSTRAINT `fk_posto_dono` FOREIGN KEY (`nucleo_dono_id`) REFERENCES `5c_escalas_treino`.`esc_nucleo` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @tem := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '5c_escalas_treino' AND CONSTRAINT_NAME = 'fk_posto_unidade');
SET @sql := IF(@tem = 0, 'ALTER TABLE `5c_escalas_treino`.`esc_posto` ADD CONSTRAINT `fk_posto_unidade` FOREIGN KEY (`unidade`) REFERENCES `5c_escalas_treino`.`esc_unidade` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @tem := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '5c_escalas_treino' AND CONSTRAINT_NAME = 'fk_pp_posto');
SET @sql := IF(@tem = 0, 'ALTER TABLE `5c_escalas_treino`.`esc_posto_pagina` ADD CONSTRAINT `fk_pp_posto` FOREIGN KEY (`posto_id`) REFERENCES `5c_escalas_treino`.`esc_posto` (`id`) ON DELETE CASCADE', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @tem := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '5c_escalas_treino' AND CONSTRAINT_NAME = 'fk_recalc_de');
SET @sql := IF(@tem = 0, 'ALTER TABLE `5c_escalas_treino`.`esc_recalculo_evento` ADD CONSTRAINT `fk_recalc_de` FOREIGN KEY (`plano_de_id`) REFERENCES `5c_escalas_treino`.`esc_plano` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @tem := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '5c_escalas_treino' AND CONSTRAINT_NAME = 'fk_recalc_foto');
SET @sql := IF(@tem = 0, 'ALTER TABLE `5c_escalas_treino`.`esc_recalculo_evento` ADD CONSTRAINT `fk_recalc_foto` FOREIGN KEY (`foto_id`) REFERENCES `5c_escalas_treino`.`esc_entrada_foto` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @tem := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '5c_escalas_treino' AND CONSTRAINT_NAME = 'fk_recalc_para');
SET @sql := IF(@tem = 0, 'ALTER TABLE `5c_escalas_treino`.`esc_recalculo_evento` ADD CONSTRAINT `fk_recalc_para` FOREIGN KEY (`plano_para_id`) REFERENCES `5c_escalas_treino`.`esc_plano` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @tem := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '5c_escalas_treino' AND CONSTRAINT_NAME = 'fk_sug_plano');
SET @sql := IF(@tem = 0, 'ALTER TABLE `5c_escalas_treino`.`esc_sugestao` ADD CONSTRAINT `fk_sug_plano` FOREIGN KEY (`plano_id`) REFERENCES `5c_escalas_treino`.`esc_plano` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @tem := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '5c_escalas_treino' AND CONSTRAINT_NAME = 'fk_sug_recalc');
SET @sql := IF(@tem = 0, 'ALTER TABLE `5c_escalas_treino`.`esc_sugestao` ADD CONSTRAINT `fk_sug_recalc` FOREIGN KEY (`recalculo_id`) REFERENCES `5c_escalas_treino`.`esc_recalculo_evento` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @tem := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '5c_escalas_treino' AND CONSTRAINT_NAME = 'fk_vaga_posto');
SET @sql := IF(@tem = 0, 'ALTER TABLE `5c_escalas_treino`.`esc_vaga_equipe` ADD CONSTRAINT `fk_vaga_posto` FOREIGN KEY (`posto_id`) REFERENCES `5c_escalas_treino`.`esc_posto` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 3. Permissão: o mesmo usuário do portal grava no treino (como no 5c_escalas).
GRANT SELECT, INSERT, UPDATE, DELETE ON `5c_escalas_treino`.* TO '<usuario_do_portal>'@'<host>';

-- 4. Conferência: deve listar 36 tabelas e 25 chaves.
SELECT COUNT(*) AS tabelas FROM information_schema.TABLES WHERE TABLE_SCHEMA = '5c_escalas_treino' AND TABLE_TYPE = 'BASE TABLE';
SELECT COUNT(*) AS chaves FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '5c_escalas_treino';
