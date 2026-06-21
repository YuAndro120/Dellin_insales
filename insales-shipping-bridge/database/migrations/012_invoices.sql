-- Выставленные счета юрлицам через T-API Т-Банка (выставление счетов).
-- Отдельная таблица от payments (там — платежи через интернет-эквайринг),
-- так как структура данных и жизненный цикл отличаются.

CREATE TABLE IF NOT EXISTS `invoices` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `insales_id` VARCHAR(32) NOT NULL,
  `plan` ENUM('calc_only','full','automation') NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL COMMENT 'Сумма счёта в рублях',
  `invoice_number` VARCHAR(20) NOT NULL COMMENT 'Номер счёта (наш, до 15 цифр)',
  `tbank_invoice_id` VARCHAR(64) NULL DEFAULT NULL COMMENT 'invoiceId из ответа Т-Банка',
  `payer_name` VARCHAR(512) NOT NULL,
  `payer_inn` VARCHAR(12) NOT NULL,
  `payer_kpp` VARCHAR(9) NULL DEFAULT NULL,
  `status` ENUM('pending','sent','paid','overdue','cancelled','failed') NOT NULL DEFAULT 'pending',
  `due_date` DATE NOT NULL,
  `paid_at` TIMESTAMP NULL DEFAULT NULL,
  `raw_response` JSON NULL DEFAULT NULL COMMENT 'Последний ответ Т-Банка по этому счёту',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invoice_number` (`invoice_number`),
  KEY `idx_insales_id` (`insales_id`),
  KEY `idx_status` (`status`),
  KEY `idx_tbank_invoice_id` (`tbank_invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;