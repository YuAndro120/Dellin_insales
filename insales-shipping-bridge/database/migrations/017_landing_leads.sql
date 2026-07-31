-- Заявки с лендинга (форма «Оставить заявку», заменившая три тарифные
-- карточки). ⚠️ Старая early_access_leads не подходит: колонки inn,
-- company_name, plan отсутствовали в её схеме, хотя EarlyAccessHandler
-- пытался их вставлять — INSERT падал на несуществующих колонках, и заявка
-- уходила в catch(\Throwable) как молчаливая ошибка 500. Новая таблица
-- заведена с полным набором полей формы с самого начала.
CREATE TABLE IF NOT EXISTS `landing_leads` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(190) NOT NULL,
  `phone` VARCHAR(32) NOT NULL,
  `company_name` VARCHAR(190) NULL DEFAULT NULL,
  `message` TEXT NULL DEFAULT NULL,
  `insales_id` VARCHAR(32) NULL DEFAULT NULL COMMENT 'Если форма открыта из настроек уже установленного приложения',
  `source` VARCHAR(32) NOT NULL DEFAULT 'landing',
  `notified` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Уведомление (Telegram/WhatsApp) успешно отправлено',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
