-- Для уже созданной БД: терминал отправителя настраивается в админке приложения, не в .env
ALTER TABLE `insales_shops`
  ADD COLUMN `sender_terminal_id` INT UNSIGNED NULL DEFAULT NULL
    COMMENT 'ID терминала отгрузки (задаёт владелец магазина после установки)'
  AFTER `api_password`;
