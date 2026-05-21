-- Учётные данные Dellin и способ отгрузки на магазин
ALTER TABLE `insales_shops`
  ADD COLUMN `dellin_appkey` VARCHAR(64) NULL DEFAULT NULL COMMENT 'API-ключ (appkey) Dellin' AFTER `api_password`,
  ADD COLUMN `dellin_pat_enc` TEXT NULL DEFAULT NULL COMMENT 'PAT (зашифрован)' AFTER `dellin_appkey`,
  ADD COLUMN `derival_variant` VARCHAR(16) NOT NULL DEFAULT 'terminal' COMMENT 'terminal=самопривоз, address=забор' AFTER `dellin_pat_enc`,
  ADD COLUMN `derival_city_kladr` VARCHAR(32) NULL DEFAULT NULL COMMENT 'КЛАДР города забора' AFTER `derival_variant`,
  ADD COLUMN `derival_street` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Улица забора' AFTER `derival_city_kladr`,
  ADD COLUMN `derival_house` VARCHAR(64) NULL DEFAULT NULL COMMENT 'Дом забора' AFTER `derival_street`;
