-- MySQL 8+ / MariaDB 10.3+, utf8mb4
-- Создайте БД заранее, затем: mysql -u USER -p ИМЯ_БД < database/schema.sql
-- Имя БД должно совпадать с MYSQL_DATABASE в .env (например insales_bridge).

CREATE TABLE IF NOT EXISTS `insales_shops` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `insales_id` VARCHAR(32) NOT NULL COMMENT 'Стабильный ID магазина из параметра установки',
  `shop_host` VARCHAR(255) NOT NULL COMMENT 'Например shop.myinsales.ru',
  `api_password` CHAR(32) NOT NULL COMMENT 'MD5(token+secret) для Basic Auth к API магазина',
  `dellin_appkey` VARCHAR(64) NULL DEFAULT NULL COMMENT 'API-ключ (appkey) Dellin',
  `dellin_pat_enc` TEXT NULL DEFAULT NULL COMMENT 'PAT (зашифрован)',
  `derival_variant` VARCHAR(16) NOT NULL DEFAULT 'terminal' COMMENT 'terminal=самопривоз, address=забор',
  `derival_city_kladr` VARCHAR(32) NULL DEFAULT NULL COMMENT 'КЛАДР города забора',
  `derival_street` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Улица забора',
  `derival_house` VARCHAR(64) NULL DEFAULT NULL COMMENT 'Дом забора',
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

CREATE TABLE IF NOT EXISTS `dellin_orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `insales_shop_id` VARCHAR(32) NOT NULL COMMENT 'insales_id магазина',
  `insales_order_id` VARCHAR(64) NOT NULL COMMENT 'ID заказа в inSales',
  `insales_order_number` VARCHAR(64) NULL DEFAULT NULL COMMENT 'Номер заказа для отображения',
  -- Получатель
  `receiver_name` VARCHAR(255) NULL DEFAULT NULL,
  `receiver_phone` VARCHAR(64) NULL DEFAULT NULL,
  `receiver_email` VARCHAR(255) NULL DEFAULT NULL,
  -- Адрес доставки
  `arrival_city_kladr` VARCHAR(32) NULL DEFAULT NULL,
  `arrival_city_name` VARCHAR(255) NULL DEFAULT NULL,
  `arrival_street` VARCHAR(255) NULL DEFAULT NULL,
  `arrival_house` VARCHAR(64) NULL DEFAULT NULL,
  `arrival_flat` VARCHAR(32) NULL DEFAULT NULL,
  -- Груз
  `weight` DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  `volume` DECIMAL(10,4) NOT NULL DEFAULT 0.0080,
  `stated_value` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  -- Результат оформления в ДЛ
  `dellin_request_id` BIGINT NULL DEFAULT NULL COMMENT 'ID заявки в ДЛ',
  `dellin_barcode` VARCHAR(64) NULL DEFAULT NULL COMMENT 'Штрихкод отправления',
  `dellin_status` VARCHAR(64) NULL DEFAULT NULL,
  `dellin_status_title` VARCHAR(255) NULL DEFAULT NULL,
  `dellin_status_updated_at` TIMESTAMP NULL DEFAULT NULL,
  -- Сырые данные от inSales
  `insales_payload` JSON NULL DEFAULT NULL COMMENT 'Оригинальный webhook payload',
  -- Мета
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_insales_order` (`insales_shop_id`, `insales_order_id`),
  KEY `idx_shop` (`insales_shop_id`),
  KEY `idx_dellin_request` (`dellin_request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;