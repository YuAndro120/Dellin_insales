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

    /** @return array{insales_id:string,shop_host:string,api_password:string}|null */
    public function findActiveByHost(string $shopHost): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT insales_id, shop_host, api_password FROM insales_shops
             WHERE shop_host = :h AND uninstalled_at IS NULL LIMIT 1'
        );
        $st->execute([':h' => $shopHost]);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }
}
