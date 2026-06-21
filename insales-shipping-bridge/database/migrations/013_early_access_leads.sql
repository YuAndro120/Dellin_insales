-- Заявки на ранний доступ к тарифу "Автоматизация" (форма на лендинге).
CREATE TABLE IF NOT EXISTS `early_access_leads` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(255) NOT NULL,
  `insales_id` VARCHAR(32) NULL DEFAULT NULL COMMENT 'Если форма открыта из настроек приложения',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;