<?php

declare(strict_types=1);

namespace ShippingBridge;

use PDO;

final class ShopRepository
{
    private const SELECT_FIELDS = <<<'SQL'
insales_id, shop_host, api_password, sender_terminal_id,
requester_email, counteragent_uid, produce_days_offset,
default_stated_value, default_weight_kg, default_dimensions_cm, is_enabled
SQL;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function upsertOnInstall(string $insalesId, string $shopHost, string $apiPasswordMd5): void
    {
        $sql = <<<'SQL'
INSERT INTO insales_shops (insales_id, shop_host, api_password, installed_at, uninstalled_at)
VALUES (:iid, :host, :pass, CURRENT_TIMESTAMP, NULL)
ON DUPLICATE KEY UPDATE
  shop_host = VALUES(shop_host),
  api_password = VALUES(api_password),
  installed_at = CURRENT_TIMESTAMP,
  uninstalled_at = NULL
SQL;
        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':iid' => $insalesId,
            ':host' => $shopHost,
            ':pass' => $apiPasswordMd5,
        ]);
    }

    public function markUninstalled(string $insalesId): void
    {
        $st = $this->pdo->prepare(
            'UPDATE insales_shops SET uninstalled_at = CURRENT_TIMESTAMP WHERE insales_id = :iid'
        );
        $st->execute([':iid' => $insalesId]);
    }

    public function updateApiPassword(string $insalesId, string $apiPasswordMd5): void
    {
        $st = $this->pdo->prepare(
            'UPDATE insales_shops SET api_password = :pass WHERE insales_id = :iid AND uninstalled_at IS NULL'
        );
        $st->execute([':pass' => $apiPasswordMd5, ':iid' => $insalesId]);
        if ($st->rowCount() === 0) {
            throw new \RuntimeException('Магазин не найден для обновления пароля API');
        }
    }

    /**
     * @param array{
     *   sender_terminal_id: int,
     *   requester_email: string,
     *   counteragent_uid: ?string,
     *   produce_days_offset: int,
     *   default_stated_value: float,
     *   default_weight_kg: float,
     *   default_dimensions_cm: string,
     *   is_enabled: bool,
     * } $data
     */
    public function saveDeliverySettings(string $insalesId, array $data): void
    {
        if ($data['sender_terminal_id'] <= 0) {
            throw new \InvalidArgumentException('sender_terminal_id must be positive');
        }
        $email = trim($data['requester_email']);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Укажите корректный email для API перевозчика');
        }
        $offset = (int) $data['produce_days_offset'];
        if ($offset < 0 || $offset > 30) {
            throw new \InvalidArgumentException('Дней до отгрузки: от 0 до 30');
        }

        $st = $this->pdo->prepare(
            'UPDATE insales_shops SET
              sender_terminal_id = :tid,
              requester_email = :email,
              counteragent_uid = :uid,
              produce_days_offset = :offset,
              default_stated_value = :stated,
              default_weight_kg = :weight,
              default_dimensions_cm = :dims,
              is_enabled = :enabled
             WHERE insales_id = :iid AND uninstalled_at IS NULL'
        );
        $st->execute([
            ':tid' => $data['sender_terminal_id'],
            ':email' => $email,
            ':uid' => ($data['counteragent_uid'] ?? '') !== '' ? $data['counteragent_uid'] : null,
            ':offset' => $offset,
            ':stated' => max(0, (float) $data['default_stated_value']),
            ':weight' => max(0.01, (float) $data['default_weight_kg']),
            ':dims' => $data['default_dimensions_cm'],
            ':enabled' => $data['is_enabled'] ? 1 : 0,
            ':iid' => $insalesId,
        ]);
        if ($st->rowCount() === 0) {
            throw new \RuntimeException('Shop not found or not active: ' . $insalesId);
        }
    }

    public function findSettingsByHost(string $shopHost, Config $config): ?ShopSettings
    {
        $row = $this->fetchRow(
            'SELECT ' . self::SELECT_FIELDS . ' FROM insales_shops
             WHERE shop_host = :h AND uninstalled_at IS NULL LIMIT 1',
            [':h' => $shopHost]
        );

        return $row !== null ? ShopSettings::fromRow($row, $config) : null;
    }

    public function findSettingsByInsalesId(string $insalesId, Config $config): ?ShopSettings
    {
        $row = $this->fetchRow(
            'SELECT ' . self::SELECT_FIELDS . ' FROM insales_shops
             WHERE insales_id = :iid AND uninstalled_at IS NULL LIMIT 1',
            [':iid' => $insalesId]
        );

        return $row !== null ? ShopSettings::fromRow($row, $config) : null;
    }

    /** Для OAuth/API inSales (Basic Auth). */
    public function findActiveByHost(string $shopHost): ?array
    {
        $row = $this->fetchRow(
            'SELECT insales_id, shop_host, api_password FROM insales_shops
             WHERE shop_host = :h AND uninstalled_at IS NULL LIMIT 1',
            [':h' => $shopHost]
        );

        return $row;
    }

    /** @return array{insales_id: string, shop_host: string, api_password: string}|null */
    public function findApiAuthByInsalesId(string $insalesId): ?array
    {
        $row = $this->fetchRow(
            'SELECT insales_id, shop_host, api_password FROM insales_shops
             WHERE insales_id = :iid AND uninstalled_at IS NULL LIMIT 1',
            [':iid' => $insalesId]
        );
        if ($row === null) {
            return null;
        }

        return [
            'insales_id' => (string) $row['insales_id'],
            'shop_host' => (string) $row['shop_host'],
            'api_password' => (string) $row['api_password'],
        ];
    }

    /** @param array<string, scalar|null> $params */
    private function fetchRow(string $sql, array $params): ?array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }
}
