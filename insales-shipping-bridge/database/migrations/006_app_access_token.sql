ALTER TABLE `insales_shops` ADD COLUMN `app_access_token` VARCHAR(64) NULL DEFAULT NULL COMMENT 'Секретный токен доступа к /insales/app, генерируется при установке' AFTER `api_password`;
