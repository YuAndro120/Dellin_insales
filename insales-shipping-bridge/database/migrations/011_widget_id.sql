-- Запоминаем id зарегистрированного виджета заказа, чтобы при
-- переустановке обновлять существующий виджет (PUT) вместо создания
-- нового (POST) — раньше это приводило к накоплению дубликатов в
-- карточке заказа inSales.
ALTER TABLE `insales_shops`
  ADD COLUMN `widget_id` INT UNSIGNED NULL DEFAULT NULL
    COMMENT 'id виджета оформления заказа в inSales (application_widgets)' AFTER `webhook_secret`;