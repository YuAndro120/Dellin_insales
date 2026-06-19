-- Кэш sessionID Деловых Линий по магазинам — сессия живёт 30 дней,
-- переиспользуем её вместо повторного login() на каждый запрос.

CREATE TABLE IF NOT EXISTS `dellin_sessions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `insales_id` VARCHAR(32) NOT NULL,
  `session_id` VARCHAR(64) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` TIMESTAMP NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_insales_id` (`insales_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;