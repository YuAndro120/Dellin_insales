<?php

declare(strict_types=1);

namespace ShippingBridge;

/**
 * Терминал отправителя: из настроек магазина (после установки приложения) или из тела запроса (отладка).
 */
final class ShopDeliveryContext
{
    /**
     * @param array<string, mixed> $body
     */
    public static function resolveSenderTerminalId(array $body, ?ShopRepository $shops): int
    {
        if (isset($body['sender_terminal_id']) && (int) $body['sender_terminal_id'] > 0) {
            return (int) $body['sender_terminal_id'];
        }

        $shop = trim((string) ($body['shop'] ?? ''));
        if ($shop === '') {
            throw new \InvalidArgumentException(
                'Укажите shop (хост магазина myinsales.ru) или настройте терминал отгрузки в приложении inSales'
            );
        }
        if ($shops === null) {
            throw new \RuntimeException('Database is required to load shop settings');
        }

        $row = $shops->findActiveByHost($shop);
        if ($row === null) {
            throw new \RuntimeException('Shop not installed: ' . $shop);
        }

        $tid = (int) ($row['sender_terminal_id'] ?? 0);
        if ($tid <= 0) {
            throw new \RuntimeException(
                'Терминал отгрузки не настроен. Откройте приложение в админке inSales и укажите ID терминала отправителя.'
            );
        }

        return $tid;
    }
}
