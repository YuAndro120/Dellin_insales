<?php

declare(strict_types=1);

namespace ShippingBridge;

/**
 * Правила сопоставления статусов (таблица automation_rules).
 *
 * direction = 'insales_to_dl': trigger_value — custom_status_permalink (или
 *   fulfillment_status) заказа inSales, action — пока только 'create_dl_order'.
 * direction = 'dl_to_insales': trigger_value — статус ДЛ (см. справочник
 *   https://api.dellin.ru/v1/references/statuses.json), action —
 *   custom_status_permalink inSales, который нужно проставить.
 */
final class AutomationRuleRepository
{
    public const DIRECTION_INSALES_TO_DL = 'insales_to_dl';
    public const DIRECTION_DL_TO_INSALES = 'dl_to_insales';

    public const ACTION_CREATE_DL_ORDER = 'create_dl_order';

    public function __construct(private readonly \PDO $pdo) {}

    /** @return list<array{trigger_value:string,action:string,enabled:bool}> */
    public function findByDirection(string $insalesShopId, string $direction): array
    {
        $stmt = $this->pdo->prepare('
            SELECT trigger_value, action, enabled
            FROM automation_rules
            WHERE insales_shop_id = :shop AND direction = :direction
            ORDER BY id ASC
        ');
        $stmt->execute(['shop' => $insalesShopId, 'direction' => $direction]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return array_map(static fn(array $r): array => [
            'trigger_value' => (string) $r['trigger_value'],
            'action' => (string) $r['action'],
            'enabled' => (bool) $r['enabled'],
        ], $rows);
    }

    /** Найти включённое правило по триггеру — используется на «горячем» пути (вебхук/поллер). */
    public function findAction(string $insalesShopId, string $direction, string $triggerValue): ?string
    {
        $stmt = $this->pdo->prepare('
            SELECT action FROM automation_rules
            WHERE insales_shop_id = :shop AND direction = :direction
              AND trigger_value = :trigger AND enabled = 1
            LIMIT 1
        ');
        $stmt->execute(['shop' => $insalesShopId, 'direction' => $direction, 'trigger' => $triggerValue]);
        $action = $stmt->fetchColumn();

        return $action === false ? null : (string) $action;
    }

    public function upsert(string $insalesShopId, string $direction, string $triggerValue, string $action, bool $enabled = true): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO automation_rules (insales_shop_id, direction, trigger_value, action, enabled)
            VALUES (:shop, :direction, :trigger, :action, :enabled)
            ON DUPLICATE KEY UPDATE action = VALUES(action), enabled = VALUES(enabled)
        ');
        $stmt->execute([
            'shop' => $insalesShopId,
            'direction' => $direction,
            'trigger' => $triggerValue,
            'action' => $action,
            'enabled' => $enabled ? 1 : 0,
        ]);
    }

    public function delete(string $insalesShopId, string $direction, string $triggerValue): void
    {
        $stmt = $this->pdo->prepare('
            DELETE FROM automation_rules
            WHERE insales_shop_id = :shop AND direction = :direction AND trigger_value = :trigger
        ');
        $stmt->execute(['shop' => $insalesShopId, 'direction' => $direction, 'trigger' => $triggerValue]);
    }
}