<?php

declare(strict_types=1);

namespace ShippingBridge;

use PDO;

final class ShopRepository
{
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

    public function saveSenderTerminalId(string $insalesId, int $terminalId): void
    {
        if ($terminalId <= 0) {
            throw new \InvalidArgumentException('sender_terminal_id must be positive');
        }
        $st = $this->pdo->prepare(
            'UPDATE insales_shops SET sender_terminal_id = :tid
             WHERE insales_id = :iid AND uninstalled_at IS NULL'
        );
        $st->execute([':tid' => $terminalId, ':iid' => $insalesId]);
        if ($st->rowCount() === 0) {
            throw new \RuntimeException('Shop not found or not active: ' . $insalesId);
        }
    }

    /** @return array{insales_id:string,shop_host:string,api_password:string,sender_terminal_id:?int}|null */
    public function findActiveByHost(string $shopHost): ?array
    {
        return $this->fetchOne(
            'SELECT insales_id, shop_host, api_password, sender_terminal_id FROM insales_shops
             WHERE shop_host = :h AND uninstalled_at IS NULL LIMIT 1',
            [':h' => $shopHost]
        );
    }

    /** @return array{insales_id:string,shop_host:string,api_password:string,sender_terminal_id:?int}|null */
    public function findActiveByInsalesId(string $insalesId): ?array
    {
        return $this->fetchOne(
            'SELECT insales_id, shop_host, api_password, sender_terminal_id FROM insales_shops
             WHERE insales_id = :iid AND uninstalled_at IS NULL LIMIT 1',
            [':iid' => $insalesId]
        );
    }

    /** @param array<string, scalar|null> $params */
    private function fetchOne(string $sql, array $params): ?array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['sender_terminal_id'] = isset($row['sender_terminal_id']) && $row['sender_terminal_id'] !== null
            ? (int) $row['sender_terminal_id']
            : null;

        return $row;
    }
}
