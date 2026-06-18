-- Таблицы биллинга: подписки магазинов и история платежей.
-- Выполняется в той же БД insales_bridge.

CREATE TABLE IF NOT EXISTS `subscriptions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `insales_id` VARCHAR(32) NOT NULL COMMENT 'Привязка к insales_shops.insales_id',
  `plan` ENUM('calc_only','full','automation') NOT NULL DEFAULT 'full' COMMENT 'calc_only=999, full=1999, automation=4999',
  `status` ENUM('trial','active','past_due','cancelled') NOT NULL DEFAULT 'trial',
  `trial_ends_at` TIMESTAMP NULL DEFAULT NULL,
  `current_period_ends_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'До какой даты оплачен текущий период',
  `payment_method` ENUM('card','invoice') NULL DEFAULT NULL COMMENT 'Способ оплаты последней/текущей транзакции',
  `tbank_rebill_id` VARCHAR(64) NULL DEFAULT NULL COMMENT 'Для автосписаний по сохранённой карте (интернет-эквайринг)',
  `tbank_customer_key` VARCHAR(64) NULL DEFAULT NULL COMMENT 'CustomerKey в терминах Т-Банка, привязка карты к магазину',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_insales_id` (`insales_id`),
  KEY `idx_status` (`status`),
  KEY `idx_trial_ends` (`trial_ends_at`),
  KEY `idx_period_ends` (`current_period_ends_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `insales_id` VARCHAR(32) NOT NULL,
  `plan` ENUM('calc_only','full','automation') NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL COMMENT 'Сумма в рублях',
  `payment_method` ENUM('card','invoice') NOT NULL,
  `status` ENUM('pending','succeeded','failed','refunded') NOT NULL DEFAULT 'pending',
  `tbank_payment_id` VARCHAR(64) NULL DEFAULT NULL COMMENT 'PaymentId интернет-эквайринга',
  `tbank_order_id` VARCHAR(64) NULL DEFAULT NULL COMMENT 'OrderId, который мы передали в Init',
  `tbank_invoice_id` VARCHAR(64) NULL DEFAULT NULL COMMENT 'invoiceId для способа оплаты по счёту',
  `period_start` TIMESTAMP NULL DEFAULT NULL,
  `period_end` TIMESTAMP NULL DEFAULT NULL,
  `raw_notification` JSON NULL DEFAULT NULL COMMENT 'Последнее уведомление от банка по этому платежу, для отладки',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_insales_id` (`insales_id`),
  KEY `idx_status` (`status`),
  KEY `idx_tbank_payment` (`tbank_payment_id`),
  KEY `idx_tbank_order` (`tbank_order_id`),
  KEY `idx_tbank_invoice` (`tbank_invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;