-- Схема для админ-панели. Выполняется В ОТДЕЛЬНОЙ БАЗЕ или в той же БД
-- insales_bridge — таблицы префиксованы `admin_`, чтобы не пересекаться
-- с таблицами основного приложения (insales_shops, dellin_orders).
--
-- mysql -u root -p insales_bridge < database/schema.sql

CREATE TABLE IF NOT EXISTS `admin_users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(255) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_alerts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `level` ENUM('info','warning','error') NOT NULL DEFAULT 'error',
  `event` VARCHAR(128) NOT NULL COMMENT 'Например order.create.error',
  `shop_id` VARCHAR(32) NULL DEFAULT NULL COMMENT 'insales_id магазина, если применимо',
  `order_id` VARCHAR(64) NULL DEFAULT NULL,
  `message` TEXT NOT NULL,
  `context` JSON NULL DEFAULT NULL COMMENT 'Доп. данные события (уже маскированные)',
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_is_read` (`is_read`),
  KEY `idx_shop` (`shop_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_login_attempts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip_address` VARCHAR(45) NOT NULL,
  `attempted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `success` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_ip_time` (`ip_address`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
