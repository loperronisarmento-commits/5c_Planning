SET NAMES utf8mb4;
-- =====================================================================
-- 5C_Planning_44_unidade_majstore.sql  (25/11/2026, pedido do usuário; aplicado pelo Claude via mysql.exe)
--
-- Unidade 5 "Majstore": agência parceira, no mesmo modelo da Vencel (unidade 4, script 40).
--   fc_carteira: carteira MAJS (unidade 5, "Majstore (agência parceira)") com ativo = 0. A página que for para lá
--   fica registrada como da Majstore, mas não entra na carga, nos postos nem no "precisa" da 5C (o sp_fc_postos só
--   lê carteiras ativas), nem no Funil, no equilíbrio e na estrutura das escalas (só informativo no Equilíbrio).
--   Nenhuma página ainda. Para mandar uma página: fechar a linha da carteira de hoje e abrir uma na MAJS (como o
--   script 40); para voltar, o contrário (volta para a carteira e o núcleo de antes).
-- =====================================================================

USE `5c_planning`;

SELECT 'antes' AS momento, carteira, unidade, codigo, nome, ativo FROM fc_carteira ORDER BY unidade, carteira;

INSERT INTO fc_carteira (carteira, unidade, codigo, nome, maior_pagina, ativo) VALUES ('MAJS', 5, 'MJ', 'Majstore (agência parceira)', NULL, 0)
ON DUPLICATE KEY UPDATE unidade = VALUES(unidade), nome = VALUES(nome), ativo = 0;

SELECT 'depois' AS momento, carteira, unidade, codigo, nome, ativo FROM fc_carteira ORDER BY unidade, carteira;
