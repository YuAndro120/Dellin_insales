-- UTM-метки заявки (utm_source/utm_medium/utm_campaign) — чтобы видеть не
-- только визиты по каналу (это уже умеет Яндекс.Метрика по UTM/рефереру),
-- а именно оформленные заявки: сессионная атрибуция в Метрике теряется при
-- смене устройства/браузера, а тут метка привязана к конкретному лиду
-- навсегда. NULL — если пришли без UTM (прямой заход, старые ссылки).
ALTER TABLE `landing_leads`
  ADD COLUMN `utm_source` VARCHAR(64) NULL DEFAULT NULL AFTER `source`,
  ADD COLUMN `utm_medium` VARCHAR(64) NULL DEFAULT NULL AFTER `utm_source`,
  ADD COLUMN `utm_campaign` VARCHAR(64) NULL DEFAULT NULL AFTER `utm_medium`;
