-- Настройки доставки на магазин (форма /insales/app)
ALTER TABLE `insales_shops`
  ADD COLUMN `requester_email` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Email заказчика в API перевозчика' AFTER `sender_terminal_id`,
  ADD COLUMN `counteragent_uid` VARCHAR(64) NULL DEFAULT NULL COMMENT 'UID контрагента (если есть в ЛК перевозчика)' AFTER `requester_email`,
  ADD COLUMN `produce_days_offset` TINYINT UNSIGNED NOT NULL DEFAULT 2 COMMENT 'Дней до даты отгрузки' AFTER `counteragent_uid`,
  ADD COLUMN `default_stated_value` DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Объявленная стоимость по умолчанию, руб.' AFTER `produce_days_offset`,
  ADD COLUMN `default_weight_kg` DECIMAL(10,3) NOT NULL DEFAULT 1.000 COMMENT 'Вес по умолчанию, кг' AFTER `default_stated_value`,
  ADD COLUMN `default_dimensions_cm` VARCHAR(32) NOT NULL DEFAULT '20x20x20' COMMENT 'Габариты по умолчанию, см (ДxШxВ)' AFTER `default_weight_kg`,
  ADD COLUMN `is_enabled` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 — расчёт доставки включён' AFTER `default_dimensions_cm`;
