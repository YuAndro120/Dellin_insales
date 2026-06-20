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

    /** @param array<string, mixed> $body */
    public static function cityName(array $body): ?string
    {
        $order = self::order($body);
        $addr = $order['shipping_address'] ?? [];

        // Сначала из location
        $loc = $addr['location'] ?? null;
        if (is_array($loc)) {
            $city = trim((string) ($loc['city'] ?? ''));
            if ($city !== '') {
                return $city;
            }
        }

        // Fallback — full_locality_name
        $full = trim((string) ($addr['full_locality_name'] ?? ''));
        if ($full !== '') {
            return $full;
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
            // Цена позиции — checkout API inSales может присылать как total-price
            // (уже за всё количество), так и price (за единицу); приоритет первому.
            $totalPrice = (float) ($line['total-price'] ?? $line['total_price'] ?? 0);
            if ($totalPrice <= 0) {
                $unitPrice = (float) ($line['price'] ?? 0);
                $totalPrice = $unitPrice * $qty;
            }
            $out[] = [
                'quantity' => $qty,
                'weight' => $weight > 0 ? $weight : 0.0,
                'dimensions' => $dims,
                'total_price' => $totalPrice,
            ];
        }

        return $out;
    }

    /** @param array<string, mixed> $body */
    public static function street(array $body): ?string
    {
        $order = self::order($body);
        $loc = $order['shipping_address']['location'] ?? null;
        if (!is_array($loc)) {
            return null;
        }
        $street = trim((string) ($loc['street'] ?? ''));
        return $street !== '' ? $street : null;
    }

    /** @param array<string, mixed> $body */
    public static function house(array $body): ?string
    {
        $order = self::order($body);
        $loc = $order['shipping_address']['location'] ?? null;
        if (!is_array($loc)) {
            return null;
        }
        $house = trim((string) ($loc['house'] ?? ''));
        return $house !== '' ? $house : null;
    }
    /** @param array<string, mixed> $body */
    private static function order(array $body): array
    {
        $order = $body['order'] ?? $body;
        return is_array($order) ? $order : [];
    }
}
