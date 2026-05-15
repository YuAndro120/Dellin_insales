-- MySQL 8+ / MariaDB 10.3+, utf8mb4
CREATE DATABASE IF NOT EXISTS `shipping_bridge`
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `shipping_bridge`;

CREATE TABLE IF NOT EXISTS `insales_shops` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `insales_id` VARCHAR(32) NOT NULL COMMENT 'Стабильный ID магазина из параметра установки',
  `shop_host` VARCHAR(255) NOT NULL COMMENT 'Например shop.myinsales.ru',
  `api_password` CHAR(32) NOT NULL COMMENT 'MD5(token+secret) для Basic Auth к API магазина',
  `installed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `uninstalled_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_insales_id` (`insales_id`),
  KEY `idx_shop_host` (`shop_host`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
