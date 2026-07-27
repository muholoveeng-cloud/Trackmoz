-- TrackMoz — Melhorias missões e automatização
-- Executar uma vez no phpMyAdmin ou via scripts/run_migration_001.php

ALTER TABLE `missoes`
  ADD COLUMN IF NOT EXISTS `codigo_missao` varchar(20) DEFAULT NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `distancia_km` decimal(10,2) DEFAULT NULL AFTER `data_chegada`,
  ADD COLUMN IF NOT EXISTS `tempo_estimado_min` int DEFAULT NULL AFTER `distancia_km`;

-- Índice único (ignorar erro se já existir)
-- ALTER TABLE `missoes` ADD UNIQUE KEY `uk_codigo_missao` (`codigo_missao`);

-- Preencher códigos em missões antigas
UPDATE `missoes` SET `codigo_missao` = CONCAT('TMZ-', YEAR(`data_criacao`), '-', LPAD(`id`, 5, '0'))
WHERE `codigo_missao` IS NULL OR `codigo_missao` = '';
