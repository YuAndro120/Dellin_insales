-- Integer ID контрагента-отправителя в адресной книге ДЛ и UID характера груза
ALTER TABLE `insales_shops`
  ADD COLUMN IF NOT EXISTS `sender_counteragent_id` INT UNSIGNED NULL DEFAULT NULL
    COMMENT 'Integer counteragentID отправителя из адресной книги ДЛ (для sender.counteragentID в заявке)',
  ADD COLUMN IF NOT EXISTS `freight_uid` VARCHAR(64) NULL DEFAULT NULL
    COMMENT 'UID характера груза из справочника ДЛ (cargo.freightUID)';
