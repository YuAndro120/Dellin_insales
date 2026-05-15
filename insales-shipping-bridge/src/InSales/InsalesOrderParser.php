<?php

declare(strict_types=1);

namespace ShippingBridge\InSales;

/**
 * Разбор тела запроса checkout inSales (внешний способ доставки API v2).
 *
 * @see https://www.insales.ru/collection/doc-prochee/product/javascript-api-oformleniya-zakaza-dlya-vneshnih-sposobov-dostavki
 */
final class InsalesOrderParser
{
    /** @param array<string, mixed> $body */
    public static function accountId(array $body): string
    {
        $order = self::order($body);
        $id = $order['account_id'] ?? $body['account_id'] ?? null;

        return $id !== null ? (string) $id : '';
    }

    /** @param array<string, mixed> $body */
    public static function pickupPointId(array $body): ?int
    {
        if (isset($body['id']) && is_numeric($body['id'])) {
            return (int) $body['id'];
        }

        return null;
    }

    /** @param array<string, mixed> $body */
    public static function cityKladr(array $body): ?string
    {
        $order = self::order($body);
        $loc = $order['shipping_address']['location'] ?? null;
        if (!is_array($loc)) {
            return null;
        }
        $code = (string) ($loc['kladr_code'] ?? '');
        if (strlen($code) >= 2) {
            return $code;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $body
     * @return list<array{quantity:int,weight:float,dimensions:string}>
     */
    public static function orderLines(array $body): array
    {
        $order = self::order($body);
        $raw = $order['order_lines'] ?? $order['oder_lines'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $line) {
            if (!is_array($line)) {
                continue;
            }
            $qty = max(1, (int) ($line['quantity'] ?? 1));
            $weight = (float) ($line['weight'] ?? 0);
            $dims = trim((string) ($line['dimensions'] ?? ''));
            $out[] = [
                'quantity' => $qty,
                'weight' => $weight > 0 ? $weight : 0.0,
                'dimensions' => $dims,
            ];
        }

        return $out;
    }

    /** @param array<string, mixed> $body */
    private static function order(array $body): array
    {
        $order = $body['order'] ?? $body;
        return is_array($order) ? $order : [];
    }
}
