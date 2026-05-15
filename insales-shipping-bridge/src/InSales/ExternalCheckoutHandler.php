<?php

declare(strict_types=1);

namespace ShippingBridge\InSales;

use ShippingBridge\CalculatorContext;
use ShippingBridge\CarrierApi;
use ShippingBridge\CargoFromInsalesOrder;
use ShippingBridge\Config;
use ShippingBridge\Http\Response;
use ShippingBridge\ShopRepository;
use ShippingBridge\ShopSettings;
use ShippingBridge\TerminalRepository;

/**
 * Эндпоинты для «Внешнего способа доставки» inSales (checkout API v2).
 * inSales вызывает их с витрины myinsales.ru — нужен CORS.
 */
final class ExternalCheckoutHandler
{
    private const COMPANY = 'dellin';

    public static function handle(string $uri, Config $config, ShopRepository $shops): void
    {
        $cors = self::corsHeaders($config);
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            foreach ($cors as $h) {
                header($h);
            }
            exit;
        }

        $body = self::readJsonBody();

        try {
            $settings = self::resolveShop($body, $shops, $config);
            $calcCtx = CalculatorContext::fromShopSettings($settings);
            $senderId = $settings->senderTerminalId;
            if ($senderId === null || $senderId <= 0) {
                self::jsonError(['errors' => ['Настройте терминал отгрузки в приложении inSales']], 422, $cors);
                return;
            }

            $api = new CarrierApi($config);
            $termRepo = new TerminalRepository($config, $api);
            $lines = InsalesOrderParser::orderLines($body);
            if ($lines === []) {
                self::jsonError(['errors' => ['Нет позиций в заказе']], 422, $cors);
                return;
            }
            $cargo = CargoFromInsalesOrder::aggregate($lines, $settings);

            if ($uri === '/insales/external/v2/courier') {
                self::courier($body, $api, $senderId, $cargo, $calcCtx, $cors);
                return;
            }
            if ($uri === '/insales/external/v2/pickup_points') {
                self::pickupPoints($body, $api, $termRepo, $senderId, $cargo, $calcCtx, $cors);
                return;
            }
            if ($uri === '/insales/external/v2/pickup_point') {
                self::pickupPoint($body, $api, $senderId, $cargo, $calcCtx, $cors);
                return;
            }

            self::jsonError(['error' => 'Not found'], 404, $cors);
        } catch (\Throwable $e) {
            self::jsonError(['errors' => [$e->getMessage()]], 422, $cors);
        }
    }

    /** Курьер / до города (один тариф). */
    private static function courier(
        array $body,
        CarrierApi $api,
        int $senderId,
        array $cargo,
        CalculatorContext $calcCtx,
        array $cors,
    ): void {
        $kladr = InsalesOrderParser::cityKladr($body);
        if ($kladr === null || strlen($kladr) < 10) {
            self::jsonError(['errors' => ['Укажите населённый пункт с КЛАДР в адресе доставки']], 422, $cors);
            return;
        }

        $sid = $api->login();
        $calc = $api->calculateToCity($sid, $senderId, $kladr, $cargo, $calcCtx);
        if ($calc['price'] === null) {
            $msg = is_array($calc['errors'] ?? null)
                ? json_encode($calc['errors'], JSON_UNESCAPED_UNICODE)
                : 'Не удалось рассчитать доставку';
            self::jsonError(['errors' => [$msg]], 422, $cors);
            return;
        }

        Response::json([[
            'price' => (float) $calc['price'],
            'tariff_id' => 'dellin_courier',
            'shipping_company_handle' => self::COMPANY,
            'title' => 'Доставка до терминала в городе получателя',
            'description' => 'Перевозчик: терминал → терминал/город',
            'delivery_interval' => self::interval($calc['days']),
            'fields_values' => [
                ['handle' => 'dellin_delivery_type', 'value' => 'courier'],
            ],
            'errors' => [],
            'warnings' => [],
        ]], 200, $cors);
    }

    /** Список ПВЗ для карты checkout. */
    private static function pickupPoints(
        array $body,
        CarrierApi $api,
        TerminalRepository $termRepo,
        int $senderId,
        array $cargo,
        CalculatorContext $calcCtx,
        array $cors,
    ): void {
        $kladr = InsalesOrderParser::cityKladr($body);
        if ($kladr === null) {
            self::jsonError(['errors' => ['Не определён КЛАДР города доставки']], 422, $cors);
            return;
        }

        $prefix = strlen($kladr) >= 13 ? substr($kladr, 0, 11) : $kladr;
        $points = $termRepo->search($prefix, null, 80);
        if ($points === []) {
            Response::json([], 200, $cors);
            return;
        }

        $sid = $api->login();
        $out = [];
        foreach ($points as $t) {
            $tid = (int) ($t['id'] ?? 0);
            if ($tid <= 0) {
                continue;
            }
            $price = null;
            $days = null;
            try {
                $calc = $api->calculateToTerminal(
                    $sid,
                    $senderId,
                    $tid,
                    isset($t['city_kladr']) ? (string) $t['city_kladr'] : $kladr,
                    $cargo,
                    $calcCtx
                );
                $price = $calc['price'];
                $days = $calc['days'];
            } catch (\Throwable) {
                continue;
            }
            if ($price === null) {
                continue;
            }
            $out[] = self::mapPickupPoint($t, (float) $price, $days);
            if (count($out) >= 50) {
                break;
            }
        }

        Response::json($out, 200, $cors);
    }

    /** Пересчёт для выбранной точки (point-info / выбор ПВЗ). */
    private static function pickupPoint(
        array $body,
        CarrierApi $api,
        int $senderId,
        array $cargo,
        CalculatorContext $calcCtx,
        array $cors,
    ): void {
        $pointId = InsalesOrderParser::pickupPointId($body);
        if ($pointId === null || $pointId <= 0) {
            self::jsonError(['errors' => ['id точки самовывоза обязателен']], 422, $cors);
            return;
        }

        $kladr = InsalesOrderParser::cityKladr($body);
        $sid = $api->login();
        $calc = $api->calculateToTerminal($sid, $senderId, $pointId, $kladr, $cargo, $calcCtx);
        if ($calc['price'] === null) {
            self::jsonError(['errors' => ['Расчёт для выбранного ПВЗ недоступен']], 422, $cors);
            return;
        }

        Response::json([self::mapPickupPoint([
            'id' => $pointId,
            'name' => 'ПВЗ #' . $pointId,
            'address' => '',
            'lat' => 0,
            'lng' => 0,
        ], (float) $calc['price'], $calc['days'])], 200, $cors);
    }

    /** @param array<string, mixed> $t */
    private static function mapPickupPoint(array $t, float $price, ?int $days): array
    {
        return [
            'id' => (int) ($t['id'] ?? 0),
            'latitude' => (float) ($t['lat'] ?? 0),
            'longitude' => (float) ($t['lng'] ?? 0),
            'shipping_company_handle' => self::COMPANY,
            'price' => $price,
            'title' => (string) ($t['name'] ?? 'Пункт выдачи'),
            'type' => 'pvz',
            'address' => (string) ($t['address'] ?? ''),
            'description' => (string) ($t['city'] ?? ''),
            'phones' => [],
            'delivery_interval' => self::interval($days),
            'payment_method' => ['PREPAID'],
            'fields_values' => [
                ['handle' => 'dellin_terminal_id', 'value' => (string) ($t['id'] ?? '')],
                ['handle' => 'dellin_delivery_type', 'value' => 'pickup'],
            ],
        ];
    }

    /** @return array{description:string,min_days?:int,max_days?:int} */
    private static function interval(?int $days): array
    {
        if ($days === null || $days <= 0) {
            return ['description' => 'Срок уточняется'];
        }

        return [
            'min_days' => $days,
            'max_days' => $days + 2,
            'description' => 'от ' . $days . ' дн.',
        ];
    }

    /** @return array<string, mixed> */
    private static function readJsonBody(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw === '') {
            return [];
        }
        $d = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return is_array($d) ? $d : [];
    }

    private static function resolveShop(array $body, ShopRepository $shops, Config $config): ShopSettings
    {
        $accountId = InsalesOrderParser::accountId($body);
        if ($accountId === '') {
            throw new \InvalidArgumentException('account_id не передан в запросе checkout');
        }

        $settings = $shops->findSettingsByInsalesId($accountId, $config);
        if ($settings === null) {
            throw new \RuntimeException('Магазин не установил приложение (account_id=' . $accountId . ')');
        }
        if (!$settings->isEnabled) {
            throw new \RuntimeException('Расчёт доставки отключён в настройках приложения');
        }

        return $settings;
    }

    /** @return list<string> */
    public static function corsHeadersForError(): array
    {
        return [
            'Access-Control-Allow-Origin: *',
            'Access-Control-Allow-Methods: GET, POST, OPTIONS',
            'Access-Control-Allow-Headers: Accept, Accept-Language, Content-Language, Content-Type',
        ];
    }

    /** @return list<string> */
    private static function corsHeaders(Config $config): array
    {
        $origin = $config->corsOrigin !== '' ? $config->corsOrigin : '*';

        return [
            'Access-Control-Allow-Origin: ' . $origin,
            'Access-Control-Allow-Methods: GET, POST, OPTIONS',
            'Access-Control-Allow-Headers: Accept, Accept-Language, Content-Language, Content-Type',
            'Access-Control-Max-Age: 86400',
        ];
    }

    /** @param array<string, mixed> $data */
    private static function jsonError(array $data, int $status, array $cors): void
    {
        Response::json($data, $status, $cors);
    }
}
