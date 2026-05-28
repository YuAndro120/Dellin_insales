<?php

declare(strict_types=1);

namespace ShippingBridge;

/**
 * Настройки доставки магазина из БД (или отладочные параметры в теле запроса).
 */
final class ShopDeliveryContext
{
    /**
     * @param array<string, mixed> $body
     */
    public static function resolveSettings(array $body, ?ShopRepository $shops, Config $config): ShopSettings
    {
        if (isset($body['sender_terminal_id']) && (int) $body['sender_terminal_id'] > 0
            && isset($body['requester_email']) && is_string($body['requester_email'])) {
            return new ShopSettings(
                insalesId: 'debug',
                shopHost: (string) ($body['shop'] ?? 'debug'),
                hasDellinAuth: true,
                senderTerminalId: (int) $body['sender_terminal_id'],
                derivalVariant: ShopSettings::DERIVAL_TERMINAL,
                derivalCityKladr: null,
                derivalStreet: null,
                derivalHouse: null,
                requesterEmail: trim($body['requester_email']),
                counteragentUid: isset($body['counteragent_uid']) ? (string) $body['counteragent_uid'] : null,
                senderCounterAgentId: isset($body['sender_counteragent_id']) ? (int) $body['sender_counteragent_id'] : null,
                freightUid: isset($body['freight_uid']) ? (string) $body['freight_uid'] : null,
                produceDaysOffset: (int) ($body['produce_days_offset'] ?? 2),
                defaultStatedValue: (float) ($body['default_stated_value'] ?? 0),
                defaultWeightKg: max(0.01, (float) ($body['default_weight_kg'] ?? 1)),
                defaultDimensionsCm: (string) ($body['default_dimensions_cm'] ?? '20x20x20'),
                isEnabled: true,
            );
        }

        $shop = trim((string) ($body['shop'] ?? ''));
        if ($shop === '') {
            throw new \InvalidArgumentException(
                'Укажите shop (хост магазина *.myinsales.ru) или настройте доставку в приложении inSales'
            );
        }
        if ($shops === null) {
            throw new \RuntimeException('Database is required to load shop settings');
        }

        $settings = $shops->findSettingsByHost($shop, $config);
        if ($settings === null) {
            throw new \RuntimeException('Shop not installed: ' . $shop);
        }
        if (!$settings->hasDellinAuth) {
            throw new \RuntimeException('Подключите API-ключ и PAT в настройках приложения inSales');
        }
        if (!$settings->isEnabled) {
            throw new \RuntimeException('Расчёт доставки отключён в настройках приложения');
        }
        self::assertDerivalConfigured($settings);

        return $settings;
    }

    public static function assertDerivalConfigured(ShopSettings $settings): void
    {
        if ($settings->isDerivalTerminal()) {
            if ($settings->senderTerminalId === null || $settings->senderTerminalId <= 0) {
                throw new \RuntimeException(
                    'Терминал отгрузки не настроен. Откройте приложение в админке inSales и сохраните настройки.'
                );
            }

            return;
        }

        if ($settings->derivalCityKladr === null || $settings->derivalStreet === null || $settings->derivalHouse === null) {
            throw new \RuntimeException('Адрес забора груза не настроен в приложении inSales');
        }
    }

    public static function requireSenderTerminalId(ShopSettings $settings): int
    {
        if (!$settings->isDerivalTerminal()) {
            return 0;
        }

        $tid = $settings->senderTerminalId;
        if ($tid === null || $tid <= 0) {
            throw new \RuntimeException('Sender terminal is not configured');
        }

        return $tid;
    }
}
