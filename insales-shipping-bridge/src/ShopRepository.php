<?php

declare(strict_types=1);

namespace ShippingBridge;

use PDO;

final class ShopRepository
{
    private const SELECT_FIELDS = 'insales_id, shop_host, api_password, dellin_appkey, dellin_pat_enc, sender_terminal_id, derival_variant, derival_city_kladr, derival_street, derival_house, derival_time_from, derival_time_to, derival_break_from, derival_break_to, requester_email, counteragent_uid, sender_counteragent_id, sender_name, sender_type, sender_inn, sender_doc_type, sender_doc_serial, sender_doc_number, sender_contact_name, sender_contact_phone, freight_uid, produce_days_offset, default_stated_value, default_weight_kg, default_dimensions_cm, is_enabled, sender_opf_uid, sender_juridical_address, sender_opf_name, freight_name, delivery_payer, requester_role, package_uid, package_name, delivery_types, package_in_calc, derival_city_name';

    public function __construct(private readonly PDO $pdo) {}

    public function upsertOnInstall(string $insalesId, string $shopHost, string $apiPasswordMd5): void
    {
        $sql = <<<'SQL'
INSERT INTO insales_shops (insales_id, shop_host, api_password, app_access_token, webhook_secret, installed_at, uninstalled_at)
VALUES (:iid, :host, :pass, :token, :wsk, CURRENT_TIMESTAMP, NULL)
ON DUPLICATE KEY UPDATE
  shop_host = VALUES(shop_host),
  api_password = VALUES(api_password),
  app_access_token = COALESCE(insales_shops.app_access_token, VALUES(app_access_token)),
  webhook_secret = COALESCE(insales_shops.webhook_secret, VALUES(webhook_secret)),
  installed_at = CURRENT_TIMESTAMP,
  uninstalled_at = NULL
SQL;
        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':iid' => $insalesId,
            ':host' => $shopHost,
            ':pass' => $apiPasswordMd5,
            ':token' => bin2hex(random_bytes(24)),
            ':wsk' => bin2hex(random_bytes(24)),
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
        $this->assertActiveShop($insalesId);
    }

    public function saveDellinCredentials(string $insalesId, string $appkey, string $pat, string $bridgeSecret): void
    {
        $appkey = trim($appkey);
        $pat = trim($pat);
        if ($appkey === '' || $pat === '') {
            throw new \InvalidArgumentException('Укажите API-ключ и PAT');
        }

        $enc = SecretStore::encrypt($pat, $bridgeSecret);
        $st = $this->pdo->prepare(
            'UPDATE insales_shops SET dellin_appkey = :key, dellin_pat_enc = :pat
             WHERE insales_id = :iid AND uninstalled_at IS NULL'
        );
        $st->execute([':key' => $appkey, ':pat' => $enc, ':iid' => $insalesId]);
        $this->assertActiveShop($insalesId);
    }

    public function findCarrierCredentials(string $insalesId, string $bridgeSecret): ?CarrierCredentials
    {
        $row = $this->fetchRow(
            'SELECT dellin_appkey, dellin_pat_enc FROM insales_shops
             WHERE insales_id = :iid AND uninstalled_at IS NULL LIMIT 1',
            [':iid' => $insalesId]
        );
        if ($row === null) {
            return null;
        }
        $appkey = trim((string) ($row['dellin_appkey'] ?? ''));
        $enc = trim((string) ($row['dellin_pat_enc'] ?? ''));
        if ($appkey === '' || $enc === '') {
            return null;
        }

        try {
            $pat = SecretStore::decrypt($enc, $bridgeSecret);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Не удалось расшифровать PAT. Проверьте BRIDGE_SECRET в .env.', 0, $e);
        }

        return new CarrierCredentials($appkey, $pat);
    }

    public function findCarrierCredentialsByHost(string $shopHost, string $bridgeSecret): ?CarrierCredentials
    {
        $row = $this->fetchRow(
            'SELECT insales_id, dellin_appkey, dellin_pat_enc FROM insales_shops
             WHERE shop_host = :h AND uninstalled_at IS NULL LIMIT 1',
            [':h' => $shopHost]
        );
        if ($row === null) {
            return null;
        }

        return $this->findCarrierCredentials((string) $row['insales_id'], $bridgeSecret);
    }

    public function saveDeliverySettings(string $insalesId, array $data): void
    {
        $variant = $data['derival_variant'];
        if (!in_array($variant, [ShopSettings::DERIVAL_TERMINAL, ShopSettings::DERIVAL_ADDRESS], true)) {
            throw new \InvalidArgumentException('Некорректный способ отгрузки');
        }

        $terminalId = (int) ($data['sender_terminal_id'] ?? 0);
        $cityKladr = trim((string) ($data['derival_city_kladr'] ?? ''));
        $street = trim((string) ($data['derival_street'] ?? ''));
        $house = trim((string) ($data['derival_house'] ?? ''));
        $timeFrom  = trim((string) ($data['derival_time_from'] ?? '')) ?: null;
        $timeTo    = trim((string) ($data['derival_time_to'] ?? '')) ?: null;
        $breakFrom = trim((string) ($data['derival_break_from'] ?? '')) ?: null;
        $breakTo   = trim((string) ($data['derival_break_to'] ?? '')) ?: null;

        if ($variant === ShopSettings::DERIVAL_TERMINAL) {
            if ($terminalId <= 0) {
                throw new \InvalidArgumentException('Выберите терминал отгрузки');
            }
        } else {
            if (strlen($cityKladr) < 10 || $street === '' || $house === '') {
                throw new \InvalidArgumentException('Для забора груза укажите город, улицу и дом');
            }
            $terminalId = $terminalId > 0 ? $terminalId : 0;
        }

        $email = trim($data['requester_email']);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Укажите корректный email для API перевозчика');
        }
        $offset = (int) $data['produce_days_offset'];
        if ($offset < 0 || $offset > 30) {
            throw new \InvalidArgumentException('Дней до отгрузки: от 0 до 30');
        }

        $senderCaid = ($data['sender_counteragent_id'] ?? null);
        $senderCaid = ($senderCaid !== null && (int) $senderCaid > 0) ? (int) $senderCaid : null;
        $freightUid = trim((string) ($data['freight_uid'] ?? ''));

        $senderType = $data['sender_type'] ?? 'person';
        if (!in_array($senderType, ['person', 'ip', 'company'], true)) {
            $senderType = 'person';
        }

        $st = $this->pdo->prepare(
            'UPDATE insales_shops SET
              sender_terminal_id    = :tid,
              derival_variant       = :variant,
              derival_city_kladr    = :city,
              derival_street        = :street,
              derival_house         = :house,
              derival_time_from     = :time_from,
              derival_time_to       = :time_to,
              derival_break_from    = :break_from,
              derival_break_to      = :break_to,
              requester_email       = :email,
              counteragent_uid      = :uid,
              sender_counteragent_id = :sender_caid,
              sender_name           = :sender_name,
              sender_type           = :sender_type,
              sender_inn            = :sender_inn,
              sender_doc_type       = :sender_doc_type,
              sender_doc_serial     = :sender_doc_serial,
              sender_doc_number     = :sender_doc_number,
              sender_contact_name   = :sender_contact_name,
              sender_contact_phone  = :sender_contact_phone,
              sender_opf_uid        = :sender_opf_uid,
              sender_opf_name = :sender_opf_name,
              sender_juridical_address = :sender_juridical_address,
              freight_uid           = :freight_uid,
              freight_name = :freight_name,
              package_uid  = :package_uid,
              package_name = :package_name,
              package_in_calc = :package_in_calc,
              produce_days_offset   = :offset,
              default_stated_value  = :stated,
              default_weight_kg     = :weight,
              default_dimensions_cm = :dims,
              is_enabled            = :enabled,
              delivery_types = :delivery_types,
              delivery_payer = :delivery_payer,
              derival_city_name = :derival_city_name,
              requester_role = :requester_role
             WHERE insales_id = :iid AND uninstalled_at IS NULL'
        );
        $st->execute([
            ':tid'                 => $terminalId > 0 ? $terminalId : null,
            ':variant'             => $variant,
            ':city'                => $cityKladr !== '' ? $cityKladr : null,
            ':street'              => $street !== '' ? $street : null,
            ':house'               => $house !== '' ? $house : null,
            ':time_from'           => $timeFrom,
            ':time_to'             => $timeTo,
            ':break_from'          => $breakFrom,
            ':break_to'            => $breakTo,
            ':email'               => $email,
            ':uid'                 => ($data['counteragent_uid'] ?? '') !== '' ? $data['counteragent_uid'] : null,
            ':sender_caid'         => $senderCaid,
            ':sender_name'         => trim((string) ($data['sender_name'] ?? '')) ?: null,
            ':sender_type'         => $senderType,
            ':sender_inn'          => trim((string) ($data['sender_inn'] ?? '')) ?: null,
            ':sender_doc_type'     => trim((string) ($data['sender_doc_type'] ?? '')) ?: null,
            ':sender_doc_serial'   => trim((string) ($data['sender_doc_serial'] ?? '')) ?: null,
            ':sender_doc_number'   => trim((string) ($data['sender_doc_number'] ?? '')) ?: null,
            ':sender_contact_name' => trim((string) ($data['sender_contact_name'] ?? '')) ?: null,
            ':sender_contact_phone' => trim((string) ($data['sender_contact_phone'] ?? '')) ?: null,
            ':sender_opf_uid'       => trim((string) ($data['sender_opf_uid'] ?? '')) ?: null,
            ':sender_opf_name' => trim((string) ($data['sender_opf_name'] ?? '')),
            ':sender_juridical_address' => trim((string) ($data['sender_juridical_address'] ?? '')) ?: '',
            ':freight_uid'         => $freightUid !== '' ? $freightUid : null,
            ':freight_name' => trim((string) ($data['freight_name'] ?? '')),
            ':package_uid'  => trim((string) ($data['package_uid']  ?? '')),
            ':package_name' => trim((string) ($data['package_name'] ?? '')),
            ':package_in_calc' => isset($data['package_in_calc']) && $data['package_in_calc'] ? 1 : 0,
            ':offset'              => $offset,
            ':stated'              => max(0, (float) $data['default_stated_value']),
            ':weight'              => max(0.01, (float) $data['default_weight_kg']),
            ':dims'                => $data['default_dimensions_cm'],
            ':enabled'             => $data['is_enabled'] ? 1 : 0,
            ':iid'                 => $insalesId,
            ':delivery_payer' => in_array($data['delivery_payer'] ?? 'sender', ['sender', 'receiver'], true) ? $data['delivery_payer'] : 'sender',
            ':requester_role' => in_array($data['requester_role'] ?? 'sender', ['sender', 'receiver', 'payer'], true) ? $data['requester_role'] : 'sender',
            ':derival_city_name' => trim((string) ($_POST['derival_city_name'] ?? '')) ?: null,
            ':delivery_types' => implode(',', array_filter(
                is_array($data['delivery_types'] ?? ['auto'])
                    ? ($data['delivery_types'] ?? ['auto'])
                    : explode(',', (string) ($data['delivery_types'] ?? 'auto')),
                static fn(string $t): bool => in_array($t, ['auto', 'avia', 'express', 'small'], true)
            )) ?: 'auto',
        ]);
        $this->assertActiveShop($insalesId);
    }

    private function assertActiveShop(string $insalesId): void
    {
        $row = $this->fetchRow(
            'SELECT 1 FROM insales_shops WHERE insales_id = :iid AND uninstalled_at IS NULL LIMIT 1',
            [':iid' => $insalesId]
        );
        if ($row === null) {
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

        return $row !== null ? ShopSettings::fromRow($row) : null;
    }

    public function findSettingsByInsalesId(string $insalesId, Config $config): ?ShopSettings
    {
        $row = $this->fetchRow(
            'SELECT ' . self::SELECT_FIELDS . ' FROM insales_shops
             WHERE insales_id = :iid AND uninstalled_at IS NULL LIMIT 1',
            [':iid' => $insalesId]
        );

        return $row !== null ? ShopSettings::fromRow($row) : null;
    }

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
            'insales_id'   => (string) $row['insales_id'],
            'shop_host'    => (string) $row['shop_host'],
            'api_password' => (string) $row['api_password'],
        ];
    }

    /** @return array<string, mixed>|null */
    public function findOrderByInsalesId(string $insalesShopId, string $insalesOrderId): ?array
    {
        return $this->fetchRow(
            'SELECT * FROM dellin_orders
             WHERE insales_shop_id = :shop_id AND insales_order_id = :order_id
             LIMIT 1',
            [':shop_id' => $insalesShopId, ':order_id' => $insalesOrderId]
        );
    }

    public function updateOrderDellinResult(
        int $id,
        int $requestId,
        string $barcode,
    ): void {
        $stmt = $this->pdo->prepare('
            UPDATE dellin_orders
            SET dellin_request_id = :request_id,
                dellin_barcode    = :barcode,
                updated_at        = CURRENT_TIMESTAMP
            WHERE id = :id
        ');
        $stmt->execute([
            'request_id' => $requestId,
            'barcode'    => $barcode,
            'id'         => $id,
        ]);
    }

    public function findWidgetId(string $insalesId): ?int
    {
        $row = $this->fetchRow(
            'SELECT widget_id FROM insales_shops WHERE insales_id = :iid AND uninstalled_at IS NULL LIMIT 1',
            [':iid' => $insalesId]
        );
        if ($row === null || $row['widget_id'] === null) {
            return null;
        }
        return (int) $row['widget_id'];
    }

    public function saveWidgetId(string $insalesId, int $widgetId): void
    {
        $stmt = $this->pdo->prepare('UPDATE insales_shops SET widget_id = :wid WHERE insales_id = :iid');
        $stmt->execute([':wid' => $widgetId, ':iid' => $insalesId]);
    }

    public function findAccessToken(string $insalesId): ?string
    {
        $row = $this->fetchRow(
            'SELECT app_access_token FROM insales_shops WHERE insales_id = :iid AND uninstalled_at IS NULL LIMIT 1',
            [':iid' => $insalesId]
        );
        if ($row === null) {
            return null;
        }
        $token = trim((string) ($row['app_access_token'] ?? ''));
        return $token !== '' ? $token : null;
    }

    public function findWebhookSecret(string $insalesId): ?string
    {
        $row = $this->fetchRow(
            'SELECT webhook_secret FROM insales_shops WHERE insales_id = :iid AND uninstalled_at IS NULL LIMIT 1',
            [':iid' => $insalesId]
        );
        if ($row === null) {
            return null;
        }
        $secret = trim((string) ($row['webhook_secret'] ?? ''));
        return $secret !== '' ? $secret : null;
    }

    public function findActiveByWebhookSecret(string $secret): ?array
    {
        return $this->fetchRow(
            'SELECT insales_id, shop_host FROM insales_shops WHERE webhook_secret = :wsk AND uninstalled_at IS NULL LIMIT 1',
            [':wsk' => $secret]
        );
    }

    /** @param array<string, scalar|null> $params */
    private function fetchRow(string $sql, array $params): ?array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }
    public function findOrderFieldId(string $insalesId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT dellin_order_field_id FROM insales_shops WHERE insales_id = :iid AND uninstalled_at IS NULL LIMIT 1'
        );
        $stmt->execute([':iid' => $insalesId]);
        $row = $stmt->fetch();
        if ($row === false || $row['dellin_order_field_id'] === null) {
            return null;
        }
        return (int) $row['dellin_order_field_id'];
    }

    public function saveOrderFieldId(string $insalesId, int $fieldId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE insales_shops SET dellin_order_field_id = :fid WHERE insales_id = :iid'
        );
        $stmt->execute([':fid' => $fieldId, ':iid' => $insalesId]);
    }
}
