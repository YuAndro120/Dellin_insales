-- Добавляет 'insales' в допустимые значения payment_method — нужно после
-- перехода на нативный RecurringApplicationCharge inSales (см.
-- BillingPage::handlePlanSelection). Раньше ENUM допускал только
-- 'card' и 'invoice' (оба — способы оплаты через Т-Банк), из-за чего
-- запись payment_method = 'insales' обрезалась MySQL с предупреждением
-- SQLSTATE[01000]: 1265 Data truncated for column 'payment_method'.

ALTER TABLE `subscriptions`
  MODIFY COLUMN `payment_method` ENUM('card','invoice','insales') NULL DEFAULT NULL
  COMMENT 'Способ оплаты последней/текущей транзакции';

ALTER TABLE `payments`
  MODIFY COLUMN `payment_method` ENUM('card','invoice','insales') NOT NULL
  COMMENT 'Способ оплаты';