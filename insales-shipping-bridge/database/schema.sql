-- MySQL 8+ / MariaDB 10.3+, utf8mb4
-- Создайте БД заранее, затем: mysql -u USER -p ИМЯ_БД < database/schema.sql
-- Имя БД должно совпадать с MYSQL_DATABASE в .env (например insales_bridge).

CREATE TABLE IF NOT EXISTS `insales_shops` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `insales_id` VARCHAR(32) NOT NULL COMMENT 'Стабильный ID магазина из параметра установки',
  `shop_host` VARCHAR(255) NOT NULL COMMENT 'Например shop.myinsales.ru',
  `api_password` CHAR(32) NOT NULL COMMENT 'MD5(token+secret) для Basic Auth к API магазина',
  `sender_terminal_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'Терминал отгрузки',
  `requester_email` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Email для API перевозчика',
  `counteragent_uid` VARCHAR(64) NULL DEFAULT NULL COMMENT 'UID контрагента',
  `produce_days_offset` TINYINT UNSIGNED NOT NULL DEFAULT 2 COMMENT 'Дней до отгрузки',
  `default_stated_value` DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Объявленная стоимость по умолчанию',
  `default_weight_kg` DECIMAL(10,3) NOT NULL DEFAULT 1.000 COMMENT 'Вес по умолчанию, кг',
  `default_dimensions_cm` VARCHAR(32) NOT NULL DEFAULT '20x20x20' COMMENT 'Габариты по умолчанию, см',
  `is_enabled` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Расчёт включён',
  `installed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `uninstalled_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_insales_id` (`insales_id`),
  KEY `idx_shop_host` (`shop_host`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
