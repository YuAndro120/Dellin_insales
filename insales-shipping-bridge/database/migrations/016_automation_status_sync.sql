-- Автоматизация "статус inSales -> действие" и "статус ДЛ -> статус inSales".
-- Тариф "Автоматизация" (см. ShippingBridge\Plans::ALL[automation]).

-- Правила сопоставления, настраиваемые пользователем в личном кабинете.
CREATE TABLE IF NOT EXISTS `automation_rules` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `insales_shop_id` VARCHAR(32) NOT NULL COMMENT 'insales_id магазина',
  `direction` ENUM('insales_to_dl','dl_to_insales') NOT NULL,
  -- Для direction=insales_to_dl: trigger_value = custom_status_permalink (или
  -- fulfillment_status, если у магазина выключены кастомные статусы) заказа
  -- inSales, action = 'create_dl_order' (сейчас единственное поддерживаемое
  -- действие; поле сделано строкой, а не enum, чтобы расширять без ALTER).
  -- Для direction=dl_to_insales: trigger_value = статус ДЛ (см.
  -- https://api.dellin.ru/v1/references/statuses.json), action =
  -- custom_status_permalink инSales, который нужно проставить.
  `trigger_value` VARCHAR(128) NOT NULL,
  `action` VARCHAR(128) NOT NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rule` (`insales_shop_id`, `direction`, `trigger_value`),
  KEY `idx_shop_direction` (`insales_shop_id`, `direction`, `enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Очередь фоновых задач автоматизации — обрабатывается воркером
-- bin/automation_worker.php по cron (например раз в минуту).
CREATE TABLE IF NOT EXISTS `automation_jobs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `insales_shop_id` VARCHAR(32) NOT NULL,
  `insales_order_id` VARCHAR(64) NOT NULL,
  `job_type` ENUM('create_dl_order','update_insales_status') NOT NULL,
  -- Доп. данные задачи (например, для update_insales_status — какой именно
  -- custom_status_permalink проставить).
  `payload` JSON NULL DEFAULT NULL,
  `status` ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
  `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `max_attempts` INT UNSIGNED NOT NULL DEFAULT 8,
  `next_attempt_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_error` TEXT NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- Идемпотентность: на один заказ и тип задачи — не больше одной активной
  -- (pending/processing) записи. done/failed не мешают создать новую (нужно
  -- для случая, когда статус меняется туда-сюда несколько раз).
  KEY `idx_polling` (`status`, `next_attempt_at`),
  KEY `idx_shop_order` (`insales_shop_id`, `insales_order_id`, `job_type`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Последний известный статус inSales-заказа — чтобы WebhookOrderHandler мог
-- понять, ИЗМЕНИЛСЯ ли статус (а не просто пришло дублирующее orders/update
-- по другой причине), и не создавать задачу повторно на каждый чих.
ALTER TABLE `dellin_orders`
  ADD COLUMN `insales_custom_status` VARCHAR(128) NULL DEFAULT NULL
    COMMENT 'Последний известный custom_status_permalink (или fulfillment_status) заказа inSales'
    AFTER `insales_order_number`;

-- Курсор инкрементального опроса "Журнал заказов" ДЛ (метод поддерживает
-- фильтр lastUpdate — берём только заказы, изменившиеся после этой отметки,
-- чтобы не упираться в лимит 1600 запросов/час).
ALTER TABLE `insales_shops`
  ADD COLUMN `dellin_status_poll_cursor` DATETIME NULL DEFAULT NULL
    COMMENT 'lastUpdate для инкрементального опроса ДЛ orders/log' AFTER `widget_id`;