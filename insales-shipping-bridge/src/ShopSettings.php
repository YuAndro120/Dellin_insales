<?php

declare(strict_types=1);

namespace ShippingBridge;

/**
 * Настройки доставки магазина (из БД + значения по умолчанию из .env сервера).
 */
final class ShopSettings
{
    public const DERIVAL_TERMINAL = 'terminal';
    public const DERIVAL_ADDRESS = 'address';

    public function __construct(
        public readonly string $insalesId,
        public readonly string $shopHost,
        public readonly bool $hasDellinAuth,
        public readonly ?int $senderTerminalId,
        public readonly string $derivalVariant,
        public readonly ?string $derivalCityKladr,
        public readonly ?string $derivalStreet,
        public readonly ?string $derivalHouse,
        public readonly string $requesterEmail,
        public readonly ?string $counteragentUid,
        public readonly ?int $senderCounterAgentId,
        public readonly ?string $senderName,
        public readonly string $senderType,
        public readonly ?string $senderInn,
        public readonly ?string $senderDocType,
        public readonly ?string $senderDocSerial,
        public readonly ?string $senderDocNumber,
        public readonly ?string $senderContactName,
        public readonly ?string $senderContactPhone,
        public readonly ?string $senderOpfUid,
        public readonly string $senderOpfName,
        public readonly ?string $senderJuridicalAddress,
        public readonly ?string $freightUid,
        public readonly string $freightName,
        public readonly string $packageUid,
        public readonly string $packageName,
        public readonly bool $packageInCalc,
        public readonly int $produceDaysOffset,
        public readonly float $defaultStatedValue,
        public readonly float $defaultWeightKg,
        public readonly string $defaultDimensionsCm,
        public readonly bool $isEnabled,
        public readonly array $deliveryTypes,
        public readonly string $deliveryPayer,
        public readonly string $requesterRole,
    ) {}

    public function isDerivalTerminal(): bool
    {
        return $this->derivalVariant !== self::DERIVAL_ADDRESS;
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $email = trim((string) ($row['requester_email'] ?? ''));

        $uid = $row['counteragent_uid'] ?? null;
        $uid = ($uid === null || $uid === '') ? null : (string) $uid;

        $offset = (int) ($row['produce_days_offset'] ?? 2);
        if ($offset < 0 || $offset > 30) {
            $offset = 2;
        }

        $variant = (string) ($row['derival_variant'] ?? self::DERIVAL_TERMINAL);
        if (!in_array($variant, [self::DERIVAL_TERMINAL, self::DERIVAL_ADDRESS], true)) {
            $variant = self::DERIVAL_TERMINAL;
        }

        $senderType = (string) ($row['sender_type'] ?? 'person');
        if (!in_array($senderType, ['person', 'ip', 'company'], true)) {
            $senderType = 'person';
        }

        $hasAuth = trim((string) ($row['dellin_appkey'] ?? '')) !== ''
            && trim((string) ($row['dellin_pat_enc'] ?? '')) !== '';

        return new self(
            insalesId: (string) $row['insales_id'],
            shopHost: (string) $row['shop_host'],
            hasDellinAuth: $hasAuth,
            senderTerminalId: isset($row['sender_terminal_id']) && $row['sender_terminal_id'] !== null
                ? (int) $row['sender_terminal_id']
                : null,
            derivalVariant: $variant,
            derivalCityKladr: self::nullableString($row['derival_city_kladr'] ?? null),
            derivalStreet: self::nullableString($row['derival_street'] ?? null),
            derivalHouse: self::nullableString($row['derival_house'] ?? null),
            requesterEmail: $email,
            counteragentUid: is_string($uid) && $uid !== '' ? $uid : null,
            senderCounterAgentId: isset($row['sender_counteragent_id']) && $row['sender_counteragent_id'] !== null
                ? (int) $row['sender_counteragent_id']
                : null,
            senderName: self::nullableString($row['sender_name'] ?? null),
            senderType: $senderType,
            senderInn: self::nullableString($row['sender_inn'] ?? null),
            senderDocType: self::nullableString($row['sender_doc_type'] ?? null),
            senderDocSerial: self::nullableString($row['sender_doc_serial'] ?? null),
            senderDocNumber: self::nullableString($row['sender_doc_number'] ?? null),
            senderContactName: self::nullableString($row['sender_contact_name'] ?? null),
            senderContactPhone: self::nullableString($row['sender_contact_phone'] ?? null),
            senderOpfUid: self::nullableString($row['sender_opf_uid'] ?? null),
            senderOpfName: (string) ($row['sender_opf_name'] ?? ''),
            packageInCalc: (int) ($row['package_in_calc'] ?? 0) === 1,
            senderJuridicalAddress: self::nullableString($row['sender_juridical_address'] ?? null),
            freightUid: self::nullableString($row['freight_uid'] ?? null),
            freightName: (string) ($row['freight_name'] ?? ''),
            packageUid: (string) ($row['package_uid']  ?? ''),
            packageName: (string) ($row['package_name'] ?? ''),
            produceDaysOffset: $offset,
            defaultStatedValue: (float) ($row['default_stated_value'] ?? 0),
            defaultWeightKg: max(0.01, (float) ($row['default_weight_kg'] ?? 1)),
            defaultDimensionsCm: self::normalizeDimensions((string) ($row['default_dimensions_cm'] ?? '20x20x20')),
            isEnabled: (int) ($row['is_enabled'] ?? 1) === 1,
            deliveryTypes: array_filter(
                explode(',', (string) ($row['delivery_types'] ?? 'auto')),
                static fn(string $t): bool => in_array($t, ['auto', 'avia', 'express', 'small_package'], true)
            ),
            deliveryPayer: in_array($row['delivery_payer'] ?? 'sender', ['sender', 'receiver'], true) ? (string) $row['delivery_payer'] : 'sender',
            requesterRole: in_array($row['requester_role'] ?? 'sender', ['sender', 'receiver', 'payer'], true) ? (string)$row['requester_role'] : 'sender',
        );
    }

    private static function nullableString(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $t = trim($v);

        return $t !== '' ? $t : null;
    }

    private static function normalizeDimensions(string $raw): string
    {
        $raw = trim(str_replace(['х', 'Х', '×', ' '], ['x', 'x', 'x', ''], $raw));
        if ($raw === '' || !str_contains($raw, 'x')) {
            return '20x20x20';
        }

        return $raw;
    }
}
