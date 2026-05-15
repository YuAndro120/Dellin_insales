<?php

declare(strict_types=1);

namespace ShippingBridge;

/**
 * Настройки доставки магазина (из БД + значения по умолчанию из .env сервера).
 */
final class ShopSettings
{
    public function __construct(
        public readonly string $insalesId,
        public readonly string $shopHost,
        public readonly ?int $senderTerminalId,
        public readonly string $requesterEmail,
        public readonly ?string $counteragentUid,
        public readonly int $produceDaysOffset,
        public readonly float $defaultStatedValue,
        public readonly float $defaultWeightKg,
        public readonly string $defaultDimensionsCm,
        public readonly bool $isEnabled,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row, Config $config): self
    {
        $email = trim((string) ($row['requester_email'] ?? ''));
        if ($email === '') {
            $email = $config->senderRequesterEmail;
        }

        $uid = $row['counteragent_uid'] ?? null;
        if ($uid === null || $uid === '') {
            $uid = $config->senderCounteragentUid;
        }

        $offset = (int) ($row['produce_days_offset'] ?? 2);
        if ($offset < 0 || $offset > 30) {
            $offset = 2;
        }

        return new self(
            insalesId: (string) $row['insales_id'],
            shopHost: (string) $row['shop_host'],
            senderTerminalId: isset($row['sender_terminal_id']) && $row['sender_terminal_id'] !== null
                ? (int) $row['sender_terminal_id']
                : null,
            requesterEmail: $email,
            counteragentUid: is_string($uid) && $uid !== '' ? $uid : null,
            produceDaysOffset: $offset,
            defaultStatedValue: (float) ($row['default_stated_value'] ?? 0),
            defaultWeightKg: max(0.01, (float) ($row['default_weight_kg'] ?? 1)),
            defaultDimensionsCm: self::normalizeDimensions((string) ($row['default_dimensions_cm'] ?? '20x20x20')),
            isEnabled: (int) ($row['is_enabled'] ?? 1) === 1,
        );
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
