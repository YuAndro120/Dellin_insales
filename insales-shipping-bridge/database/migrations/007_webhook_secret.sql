ALTER TABLE `insales_shops` ADD COLUMN `webhook_secret` VARCHAR(64) NULL DEFAULT NULL COMMENT 'Секрет для проверки входящих вебхуков inSales (?wsk=)' AFTER `app_access_token`;
