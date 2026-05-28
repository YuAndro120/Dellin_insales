-- Integer ID контрагента-отправителя в адресной книге ДЛ и UID характера груза
ALTER TABLE `insales_shops`
  ADD COLUMN `sender_counteragent_id` INT UNSIGNED NULL DEFAULT NULL
    COMMENT 'Integer counteragentID отправителя из адресной книги ДЛ (для sender.counteragentID в заявке)'
    AFTER `counteragent_uid`,
  ADD COLUMN `freight_uid` VARCHAR(64) NULL DEFAULT NULL
    COMMENT 'UID характера груза из справочника ДЛ (cargo.freightUID)'
    AFTER `sender_counteragent_id`;
