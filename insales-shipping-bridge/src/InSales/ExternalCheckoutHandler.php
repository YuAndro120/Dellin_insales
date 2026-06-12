<?php

declare(strict_types=1);

namespace ShippingBridge\InSales;

use ShippingBridge\CalculatorContext;
use ShippingBridge\CarrierApi;
use ShippingBridge\CarrierCredentials;
use ShippingBridge\CargoFromInsalesOrder;
use ShippingBridge\Config;
use ShippingBridge\Http\Response;
use ShippingBridge\ShopDeliveryContext;
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
            ShopDeliveryContext::assertDerivalConfigured($settings);
            $calcCtx = CalculatorContext::fromShopSettings($settings)->withArrivalCityName(
                InsalesOrderParser::cityName($body)
            );
            $senderId = ShopDeliveryContext::requireSenderTerminalId($settings);

            $creds = self::carrierCredentials($shops, $config, $settings);
            $api = new CarrierApi($config);
            $termRepo = new TerminalRepository($config, $api, $creds);
            $lines = InsalesOrderParser::orderLines($body);
            if ($lines === []) {
                self::jsonError(['errors' => ['Нет позиций в заказе']], 422, $cors);
                return;
            }
            $cargo = CargoFromInsalesOrder::aggregate($lines, $settings);

            if ($uri === '/insales/external/v2/courier') {
                self::courier($body, $api, $creds, $senderId, $cargo, $calcCtx, $cors, $settings);
                return;
            }
            if ($uri === '/insales/external/v2/pickup_points') {
                self::pickupPoints($body, $api, $creds, $termRepo, $senderId, $cargo, $calcCtx, $cors, $settings);
                return;
            }
            if ($uri === '/insales/external/v2/pickup_point') {
                self::pickupPoint($body, $api, $creds, $senderId, $cargo, $calcCtx, $cors, $settings);
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
        CarrierCredentials $creds,
        int $senderId,
        array $cargo,
        CalculatorContext $calcCtx,
        array $cors,
        ShopSettings $settings,
    ): void {
        $kladr = InsalesOrderParser::cityKladr($body);
        if ($kladr === null || strlen($kladr) < 10) {
            self::jsonError(['errors' => ['Укажите населённый пункт с КЛАДР в адресе доставки']], 422, $cors);
            return;
        }

        $street = InsalesOrderParser::street($body);
        $house  = InsalesOrderParser::house($body);

        if ($street === null || $street === '' || $house === null || $house === '') {
            self::jsonError(['errors' => ['Для курьерской доставки укажите улицу и номер дома']], 422, $cors);
            return;
        }

        $sid  = $api->loginWithPat($creds);
        $calc = $api->calculateToCity($sid, $senderId, $kladr, $street, $house, $cargo, $calcCtx, $creds);
        if ($calc['price'] === null) {
            $msg = is_array($calc['errors'] ?? null)
                ? json_encode($calc['errors'], JSON_UNESCAPED_UNICODE)
                : 'Не удалось рассчитать доставку';
            self::jsonError(['errors' => [$msg]], 422, $cors);
            return;
        }

        Response::json([[
            'price'                   => (float) $calc['price'],
            'tariff_id'               => 'dellin_courier',
            'shipping_company_handle' => self::COMPANY,
            'title'                   => 'Курьерская доставка Деловых Линий',
            'description' => 'Доставка до адреса получателя' .
                ($calcCtx->packageInCalc && ($settings->packageName ?? '') !== ''
                    ? ' · упаковка: ' . $settings->packageName
                    : ''),
            'delivery_interval' => self::interval($calc['days'], $calcCtx->produceDaysOffset),
            'fields_values'           => [
                ['handle' => 'dellin_delivery_type', 'value' => 'courier'],
            ],
            'errors'   => [],
            'warnings' => [],
        ]], 200, $cors);
    }

    /** Список ПВЗ для карты checkout. */
    private static function pickupPoints(
        array $body,
        CarrierApi $api,
        CarrierCredentials $creds,
        TerminalRepository $termRepo,
        int $senderId,
        array $cargo,
        CalculatorContext $calcCtx,
        array $cors,
        ShopSettings $settings,
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

        // Типы доставки из настроек магазина
        $deliveryTypes = $settings->deliveryTypes ?: ['auto'];
        $typeNames = [
            'auto'          => 'ДЛ Авто',
            'avia'          => 'ДЛ Авиа',
            'express'       => 'ДЛ Экспресс',
            'small' => 'ДЛ Малогабаритный груз',
        ];
        // Индекс типа для кодирования в ID точки
        $typeIndex = array_flip(['auto', 'avia', 'express', 'small']);

        $sid = $api->login($creds);
        $out = [];

        foreach ($points as $t) {
            $tid = (int) ($t['id'] ?? 0);
            if ($tid <= 0) continue;

            foreach ($deliveryTypes as $dtype) {
                try {
                    $calc = $api->calculateToTerminal(
                        $sid,
                        $senderId,
                        $tid,
                        isset($t['city_kladr']) ? (string) $t['city_kladr'] : $kladr,
                        $cargo,
                        $calcCtx,
                        $creds,
                        $dtype  // ← тип доставки
                    );
                    if ($calc['price'] === null) continue;

                    $point = self::mapPickupPoint(
                        $t,
                        (float) $calc['price'],
                        $calc['days'],
                        $calcCtx->packageInCalc && $settings->packageName !== '' ? $settings->packageName : '',
                        $calcCtx->produceDaysOffset,
                        $typeNames[$dtype] ?? ''
                    );
                    // Кодируем тип в ID: terminal_id * 10 + type_index
                    // Например терминал 53, тип avia (индекс 1) → ID = 531
                    $point['id']    = $tid * 10 + ($typeIndex[$dtype] ?? 0);
                    $point['title'] = ($typeNames[$dtype] ?? 'ДЛ') . ' — ' . ($t['name'] ?? 'ПВЗ');
                    // Сохраняем тип для оформления заказа
                    $point['fields_values'][] = ['handle' => 'dellin_calc_type', 'value' => $dtype];
                    $out[] = $point;
                } catch (\Throwable $ex) {
                    error_log('DELLIN CALC ' . $dtype . ' terminal ' . $tid . ': ' . $ex->getMessage());
                }
            }

            if (count($out) >= 50) break;
        }

        Response::json($out, 200, $cors);
    }

    /** Пересчёт для выбранной точки (point-info / выбор ПВЗ). */
    private static function pickupPoint(
        array $body,
        CarrierApi $api,
        CarrierCredentials $creds,
        int $senderId,
        array $cargo,
        CalculatorContext $calcCtx,
        array $cors,
        ShopSettings $settings,
    ): void {
        $pointId = InsalesOrderParser::pickupPointId($body);
        if ($pointId === null || $pointId <= 0) {
            self::jsonError(['errors' => ['id точки самовывоза обязателен']], 422, $cors);
            return;
        }

        // Декодируем: ID = terminal_id * 10 + type_index
        $typeList  = ['auto', 'avia', 'express', 'small'];
        $dtype     = $typeList[$pointId % 10] ?? 'auto';
        $realTid   = (int) ($pointId / 10);

        $kladr = InsalesOrderParser::cityKladr($body);
        $sid   = $api->login($creds);
        $calc  = $api->calculateToTerminal(
            $sid,
            $senderId,
            $realTid,
            $kladr,
            $cargo,
            $calcCtx,
            $creds,
            $dtype
        );
        if ($calc['price'] === null) {
            self::jsonError(['errors' => ['Расчёт для выбранного ПВЗ недоступен']], 422, $cors);
            return;
        }

        $typeNames = [
            'auto'          => 'ДЛ Авто',
            'avia'          => 'ДЛ Авиа',
            'express'       => 'ДЛ Экспресс',
            'small' => 'ДЛ Малогабаритный груз',
        ];
        $typeIndex = array_flip($typeList);

        $point = self::mapPickupPoint(
            [
                'id'      => $realTid,
                'name'    => ($typeNames[$dtype] ?? 'ДЛ') . ' — ПВЗ #' . $realTid,
                'address' => '',
                'lat'     => 0,
                'lng'     => 0,
            ],
            (float) $calc['price'],
            $calc['days'],
            $calcCtx->packageInCalc && $settings->packageName !== '' ? $settings->packageName : '',
            $calcCtx->produceDaysOffset,
            $typeNames[$dtype] ?? ''
        );
        $point['id'] = $pointId; // возвращаем закодированный ID
        $point['fields_values'][] = ['handle' => 'dellin_calc_type', 'value' => $dtype];

        Response::json([$point], 200, $cors);
    }

    /** @param array<string, mixed> $t */
    private static function mapPickupPoint(array $t, float $price, ?int $days, string $packageName = '', int $produceDaysOffset = 0, string $typeLabel = ''): array
    {
        return [
            'id'                      => (int) ($t['id'] ?? 0),
            'latitude'                => (float) ($t['lat'] ?? 0),
            'longitude'               => (float) ($t['lng'] ?? 0),
            'shipping_company_handle' => self::COMPANY,
            'price'                   => $price,
            'title'                   => (string) ($t['name'] ?? 'Пункт выдачи'),
            'type'                    => 'pvz',
            'address'                 => (string) ($t['address'] ?? ''),
            'description' => trim(implode(' · ', array_filter([
                $typeLabel,
                $packageName !== '' ? 'упаковка: ' . $packageName : '',
                $typeLabel === '' && $packageName === '' ? (string) ($t['city'] ?? '') : '',
            ]))),
            'phones'                  => [],
            'delivery_interval' => self::interval($days, $produceDaysOffset),
            'payment_method'          => ['PREPAID'],
            'fields_values'           => [
                ['handle' => 'dellin_terminal_id', 'value' => (string) ($t['id'] ?? '')],
                ['handle' => 'dellin_delivery_type', 'value' => 'pickup'],
            ],
        ];
    }

    /** @return array{description:string,min_days?:int,max_days?:int} */
    private static function interval(?int $days, int $produceDaysOffset = 0): array
    {
        if ($days === null || $days <= 0) {
            return ['description' => 'Срок уточняется'];
        }
        $total = $days + $produceDaysOffset;
        return [
            'description' => 'Срок уточняется',
            'min_days'    => 0,
            'max_days'    => 0,
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
        if (!$settings->hasDellinAuth) {
            throw new \RuntimeException('Подключите API-ключ и PAT в настройках приложения');
        }
        if (!$settings->isEnabled) {
            throw new \RuntimeException('Расчёт доставки отключён в настройках приложения');
        }

        return $settings;
    }

    private static function carrierCredentials(
        ShopRepository $shops,
        Config $config,
        ShopSettings $settings,
    ): CarrierCredentials {
        if ($config->bridgeSecret === '') {
            throw new \RuntimeException('BRIDGE_SECRET не настроен на сервере');
        }
        $creds = $shops->findCarrierCredentials($settings->insalesId, $config->bridgeSecret);
        if ($creds === null || !$creds->isComplete()) {
            throw new \RuntimeException('Учётные данные Dellin не настроены');
        }

        return $creds;
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
