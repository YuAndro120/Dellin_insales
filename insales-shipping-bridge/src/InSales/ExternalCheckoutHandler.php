<?php

declare(strict_types=1);

namespace ShippingBridge\InSales;

use ShippingBridge\CalculatorContext;
use ShippingBridge\CarrierApi;
use ShippingBridge\CarrierCredentials;
use ShippingBridge\CargoFromInsalesOrder;
use ShippingBridge\Config;
use ShippingBridge\Db;
use ShippingBridge\DellinSessionCache;
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
            $sessionCache = new DellinSessionCache(Db::pdo($config));
            $lines = InsalesOrderParser::orderLines($body);
            if ($lines === []) {
                self::jsonError(['errors' => ['Нет позиций в заказе']], 422, $cors);
                return;
            }
            $cargo = CargoFromInsalesOrder::aggregate($lines, $settings);

            if ($uri === '/insales/external/v2/courier') {
                self::courier($body, $api, $creds, $senderId, $cargo, $calcCtx, $cors, $settings, $sessionCache);
                return;
            }
            if ($uri === '/insales/external/v2/pickup_points') {
                self::pickupPoints($body, $api, $creds, $termRepo, $senderId, $cargo, $calcCtx, $cors, $settings, $sessionCache);
                return;
            }
            if ($uri === '/insales/external/v2/pickup_point') {
                self::pickupPoint($body, $api, $creds, $senderId, $cargo, $calcCtx, $cors, $settings, $sessionCache);
                return;
            }

            self::jsonError(['error' => 'Not found'], 404, $cors);
        } catch (\Throwable $e) {
            $accountId = InsalesOrderParser::accountId($body);
            \ShippingBridge\Logger::error(
                $accountId !== '' ? $accountId : '-',
                null,
                'external_checkout.handle.error',
                ['uri' => $uri, 'error' => $e->getMessage()]
            );
            self::jsonError(['errors' => [$e->getMessage()]], 422, $cors);
        }
    }

    /** Курьер **/
    private static function courier(
        array $body,
        CarrierApi $api,
        CarrierCredentials $creds,
        int $senderId,
        array $cargo,
        CalculatorContext $calcCtx,
        array $cors,
        ShopSettings $settings,
        DellinSessionCache $sessionCache,
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

        $deliveryTypes = $settings->deliveryTypes ?: ['auto'];
        $typeNames = [
            'auto'    => 'ДЛ Авто',
            'avia'    => 'ДЛ Авиа',
            'express' => 'ДЛ Экспресс',
            'small'   => 'ДЛ Малогабаритный груз',
        ];

        $sid = self::loginCached($api, $creds, $sessionCache, $settings->insalesId);
$out = [];

foreach ($deliveryTypes as $dtype) {
    try {
        $calc = self::withSessionRetry(
            function (string $currentSid) use ($api, $senderId, $kladr, $street, $house, $cargo, $calcCtx, $creds, $dtype, $settings): ?array {
                return self::calculateWithDateFallback(
                    static fn(CalculatorContext $ctx) => $api->calculateToCity(
                        $currentSid,
                        $senderId,
                        $kladr,
                        $street,
                        $house,
                        $cargo,
                        $ctx,
                        $creds,
                        $dtype,
                        $settings->insalesId
                    ),
                    $calcCtx,
                    $settings->insalesId,
                    'courier',
                    $dtype,
                );
            },
            $api,
            $creds,
            $sessionCache,
            $settings->insalesId,
            $sid,
        );
                if ($calc === null || $calc['price'] === null) continue;

                $out[] = [
                    'price'                   => (float) $calc['price'],
                    'tariff_id'               => 'dellin_courier_' . $dtype,
                    'shipping_company_handle' => self::COMPANY,
                    'title'                   => ($typeNames[$dtype] ?? 'ДЛ') . ' — курьерская доставка',
                    'description'             => $calcCtx->packageInCalc && ($settings->packageName ?? '') !== ''
                        ? 'упаковка: ' . $settings->packageName
                        : '',
                    'delivery_interval'       => self::interval($calc['days'], $calcCtx->produceDaysOffset),
                    'fields_values'           => [
                        ['handle' => 'dellin_delivery_type', 'value' => 'courier'],
                        ['handle' => 'dellin_calc_type',     'value' => $dtype],
                    ],
                    'errors'   => [],
                    'warnings' => [],
                ];
            } catch (\Throwable $ex) {
                \ShippingBridge\Logger::error($settings->insalesId, null, 'calc.courier.error', [
                    'delivery_type' => $dtype,
                    'error' => $ex->getMessage(),
                ]);
            }
        }
        if ($out === []) {
            self::jsonError(['errors' => ['Не удалось рассчитать доставку']], 422, $cors);
            return;
        }

        Response::json($out, 200, $cors);
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
        DellinSessionCache $sessionCache,
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

        $sid = self::loginCached($api, $creds, $sessionCache, $settings->insalesId);
$out = [];

/** @var array<int, array<string,mixed>> $pointsByTid */
$pointsByTid = [];
$terminalIds = [];
foreach ($points as $t) {
    $tid = (int) ($t['id'] ?? 0);
    if ($tid <= 0) continue;
    $pointsByTid[$tid] = $t;
    $terminalIds[] = $tid;
}

// Один параллельный батч-запрос на каждый тип доставки — вместо
// последовательного вызова на каждую пару (терминал × тип).
foreach ($deliveryTypes as $dtype) {
    try {
        $calcResults = self::withSessionRetry(
            function (string $currentSid) use ($api, $senderId, $terminalIds, $kladr, $cargo, $calcCtx, $creds, $dtype, $settings) {
                return $api->calculateToTerminalsBatch(
                    $currentSid,
                    $senderId,
                    $terminalIds,
                    $kladr,
                    $cargo,
                    $calcCtx,
                    $creds,
                    $dtype,
                    5,
                    $settings->insalesId,
                );
            },
            $api,
            $creds,
            $sessionCache,
            $settings->insalesId,
            $sid,
        );
    } catch (\Throwable $ex) {
                \ShippingBridge\Logger::error($settings->insalesId, null, 'calc.pickup_points_batch.error', [
                    'delivery_type' => $dtype,
                    'error' => $ex->getMessage(),
                ]);
                continue;
            }

            foreach ($terminalIds as $tid) {
                $calc = $calcResults[$tid] ?? null;
                if ($calc === null || $calc['price'] === null) {
                    if ($calc === null) {
                        \ShippingBridge\Logger::error($settings->insalesId, null, 'calc.pickup_point.date_unavailable', [
                            'delivery_type' => $dtype,
                            'terminal_id' => $tid,
                        ]);
                    }
                    continue;
                }

                $t = $pointsByTid[$tid];
                $point = self::mapPickupPoint(
                    $t,
                    (float) $calc['price'],
                    $calc['days'],
                    $calcCtx->packageInCalc && $settings->packageName !== '' ? $settings->packageName : '',
                    $calcCtx->produceDaysOffset,
                    $typeNames[$dtype] ?? ''
                );
                $point['id']    = $tid * 10 + ($typeIndex[$dtype] ?? 0);
                $point['title'] = ($typeNames[$dtype] ?? 'ДЛ') . ' — ' . ($t['name'] ?? 'ПВЗ');
                $point['fields_values'][] = ['handle' => 'dellin_calc_type', 'value' => $dtype];
                $out[] = $point;

                if (count($out) >= 50) break 2;
            }
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
        DellinSessionCache $sessionCache,
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
$sid   = self::loginCached($api, $creds, $sessionCache, $settings->insalesId);
$calc  = self::withSessionRetry(
    function (string $currentSid) use ($api, $senderId, $realTid, $kladr, $cargo, $calcCtx, $creds, $dtype, $settings): ?array {
        return self::calculateWithDateFallback(
            static fn(CalculatorContext $ctx) => $api->calculateToTerminal(
                $currentSid,
                $senderId,
                $realTid,
                $kladr,
                $cargo,
                $ctx,
                $creds,
                $dtype,
                $settings->insalesId
            ),
            $calcCtx,
            $settings->insalesId,
            'pickup_point_single',
            $dtype,
        );
    },
    $api,
    $creds,
    $sessionCache,
    $settings->insalesId,
    $sid,
);
        if ($calc === null || $calc['price'] === null) {
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

    /**
 * Выполняет вызов API ДЛ с текущей закэшированной сессией. Если ДЛ
 * отвечает "Unauthorized" (сессия невалидна на их стороне раньше
 * заявленного срока — например, после смены PAT или отзыва сессии),
 * сбрасывает кэш, логинится заново и повторяет попытку один раз.
 *
 * @template T
 * @param callable(string $sid): T $callFn
 * @return T
 */
private static function withSessionRetry(
    callable $callFn,
    CarrierApi $api,
    CarrierCredentials $creds,
    DellinSessionCache $sessionCache,
    string $insalesId,
    string $sid,
) {
    try {
        return $callFn($sid);
    } catch (\Throwable $e) {
        if (!str_contains($e->getMessage(), 'Unauthorized') && !str_contains($e->getMessage(), '401')) {
            throw $e;
        }
        \ShippingBridge\Logger::info($insalesId, null, 'calc.session.invalidated_retry', [
            'error' => $e->getMessage(),
        ]);
        $sessionCache->invalidate($insalesId);
        $newSid = $api->loginWithPat($creds);
        $sessionCache->store($insalesId, $newSid);
        return $callFn($newSid);
    }
}
    /**
     * Возвращает закэшированный sessionID ДЛ для магазина, если он есть
     * и не истёк, иначе логинится заново и сохраняет результат в кэш.
     * Сессия ДЛ живёт 30 дней — это убирает лишний HTTP round-trip
     * к API ДЛ почти на каждом запросе расчёта.
     */
    private static function loginCached(
        CarrierApi $api,
        CarrierCredentials $creds,
        DellinSessionCache $sessionCache,
        string $insalesId,
    ): string {
        $cached = $sessionCache->get($insalesId);
        if ($cached !== null) {
            return $cached;
        }
        $sid = $api->loginWithPat($creds);
        $sessionCache->store($insalesId, $sid);
        return $sid;
    }

    /**
     * Пробует расчёт с дефолтным сдвигом даты отгрузки, и при ошибке
     * "180012 Выбранная дата недоступна" повторяет с увеличивающимся
     * сдвигом (+1, +2, +3, +4 дня), пока терминал/направление не примет
     * дату — терминалы могут быть закрыты на выбранную дату (выходной,
     * технический перерыв), и без этого покупатель видел бы ошибку расчёта.
     *
     * @param callable(CalculatorContext): array $calcFn
     * @return array{price:?float,days:?int,metadata?:array,raw?:array}|null
     */
    private static function calculateWithDateFallback(
        callable $calcFn,
        CalculatorContext $calcCtx,
        string $insalesId,
        string $context,
        string $deliveryType,
    ): ?array {
        $maxExtraDays = 5;
        $lastError = null;

        for ($extra = 0; $extra <= $maxExtraDays; $extra++) {
            $ctx = $extra === 0 ? $calcCtx : $calcCtx->withProduceDaysOffset($calcCtx->produceDaysOffset + $extra);
            try {
                return $calcFn($ctx);
            } catch (\Throwable $ex) {
                $lastError = $ex;
                if (!str_contains($ex->getMessage(), '180012')) {
                    // Не "дата недоступна" — нет смысла пробовать другие даты, пробрасываем сразу.
                    throw $ex;
                }
                // Иначе пробуем следующий день.
            }
        }

        \ShippingBridge\Logger::error($insalesId, null, 'calc.date_fallback.exhausted', [
            'context' => $context,
            'delivery_type' => $deliveryType,
            'tried_days' => $maxExtraDays + 1,
            'last_error' => $lastError?->getMessage(),
        ]);

        return null;
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
            'min_days'    => $total,
            'max_days'    => $total,
            'description' => $total . ' дн.',
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
