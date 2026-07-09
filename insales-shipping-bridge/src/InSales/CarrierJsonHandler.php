<?php

declare(strict_types=1);

namespace ShippingBridge\InSales;

use ShippingBridge\CarrierApi;
use ShippingBridge\Config;
use ShippingBridge\DellinCounteragent;
use ShippingBridge\Http\Response;
use ShippingBridge\ShopRepository;
use ShippingBridge\TerminalRepository;

/**
 * JSON для страницы настроек inSales: города и терминалы из API перевозчика.
 */
final class CarrierJsonHandler
{
    public static function citiesSearch(Config $config, ShopRepository $shops): void
    {
        $creds = self::resolveCredentials($shops, $config);
        if ($creds === null) {
            return;
        }

        $q = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($q) < 2) {
            Response::json(['ok' => false, 'error' => 'q must be at least 2 chars'], 422, self::cors($config));
            return;
        }
        $api = new CarrierApi($config);
        $list = $api->searchCities($q, $creds);
        Response::json(['ok' => true, 'cities' => $list], 200, self::cors($config));
    }

    public static function packages(Config $config, ShopRepository $shops): void
    {
        $cors  = self::cors($config);
        $creds = self::resolveCredentials($shops, $config);
        if ($creds === null) return;

        try {
            $api       = new CarrierApi($config);
            $reference = $api->getPackagesReference();

            // Фильтруем только упаковки (исключаем услуги типа страховки, погрузки и т.д.)
            $packageKeywords = ['упаковка', 'коробка', 'обрешётка', 'короб', 'пленка', 'мешок', 'палет', 'амортиз', 'комплекс', 'спец'];
            $items = [];
            foreach ($reference as $uid => $name) {
                $nameLower = mb_strtolower($name);
                foreach ($packageKeywords as $kw) {
                    if (str_contains($nameLower, $kw)) {
                        $items[] = ['uid' => $uid, 'name' => $name];
                        break;
                    }
                }
            }

            Response::json([
                'ok'    => true,
                'items' => $items,
            ], 200, $cors);
        } catch (\Throwable $e) {
            \ShippingBridge\Logger::error(
                trim((string) ($_GET['insales_id'] ?? '-')),
                null,
                'json.packages.error',
                ['error' => $e->getMessage()]
            );
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422, $cors);
        }
    }
    public static function derivalDates(Config $config, ShopRepository $shops): void
    {
        $cors  = self::cors($config);
        $creds = self::resolveCredentials($shops, $config);
        if ($creds === null) return;

        $insalesId = trim((string) ($_GET['insales_id'] ?? ''));
        $settings  = $shops->findSettingsByInsalesId($insalesId, $config);
        if ($settings === null) {
            Response::json(['ok' => false, 'error' => 'Магазин не найден'], 404, $cors);
            return;
        }

        $deliveryType = trim((string) ($_GET['delivery_type'] ?? 'auto'));

        try {
            $api  = new CarrierApi($config);
            $sid  = $api->loginWithPat($creds);
            $dates = $api->getAddressDates($sid, $settings, $deliveryType, $creds);
            Response::json(['ok' => true, 'dates' => $dates], 200, $cors);
        } catch (\Throwable $e) {
            \ShippingBridge\Logger::error($insalesId, null, 'json.derival_dates.error', ['error' => $e->getMessage()]);
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422, $cors);
        }
    }

    public static function derivalTimeInterval(Config $config, ShopRepository $shops): void
    {
        $cors  = self::cors($config);
        $creds = self::resolveCredentials($shops, $config);
        if ($creds === null) return;

        $insalesId = trim((string) ($_GET['insales_id'] ?? ''));
        $settings  = $shops->findSettingsByInsalesId($insalesId, $config);
        if ($settings === null) {
            Response::json(['ok' => false, 'error' => 'Магазин не найден'], 404, $cors);
            return;
        }

        $produceDate  = trim((string) ($_GET['date'] ?? ''));
        $deliveryType = trim((string) ($_GET['delivery_type'] ?? 'auto'));
        if ($produceDate === '') {
            Response::json(['ok' => false, 'error' => 'Параметр date обязателен'], 422, $cors);
            return;
        }

        try {
            $api      = new CarrierApi($config);
            $sid      = $api->loginWithPat($creds);
            $interval = $api->getAddressTimeInterval($sid, $settings, $produceDate, $deliveryType, $creds);
            Response::json(['ok' => true, 'interval' => $interval], 200, $cors);
        } catch (\Throwable $e) {
            \ShippingBridge\Logger::error($insalesId, null, 'json.derival_time_interval.error', ['error' => $e->getMessage()]);
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422, $cors);
        }
    }
    public static function counteragents(Config $config, ShopRepository $shops): void
    {
        $creds = self::resolveCredentials($shops, $config);
        if ($creds === null) {
            return;
        }

        try {
            $api = new CarrierApi($config);
            $list = $api->listCounteragents($creds);
            Response::json([
                'ok' => true,
                'counteragents' => array_map(
                    static fn(DellinCounteragent $c): array => ['uid' => $c->uid, 'name' => $c->name],
                    $list,
                ),
                'count' => count($list),
            ], 200, self::cors($config));
        } catch (\Throwable $e) {
            \ShippingBridge\Logger::error(
                trim((string) ($_GET['insales_id'] ?? '-')),
                null,
                'json.counteragents.error',
                ['error' => $e->getMessage()]
            );
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422, self::cors($config));
        }
    }

    public static function terminals(Config $config, ShopRepository $shops): void
    {
        $creds = self::resolveCredentials($shops, $config);
        if ($creds === null) {
            return;
        }

        $prefix = isset($_GET['city_kladr']) ? trim((string) $_GET['city_kladr']) : null;
        if ($prefix === '') {
            $prefix = null;
        }
        $query = trim((string) ($_GET['q'] ?? ''));
        $limit = isset($_GET['limit']) ? max(1, min(500, (int) $_GET['limit'])) : 100;
        $refresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';

        $api = new CarrierApi($config);
        $repo = new TerminalRepository($config, $api, $creds);
        if ($refresh) {
            $repo->invalidateCache();
        }

        if ($prefix === null && $query === '') {
            Response::json([
                'ok' => false,
                'error' => 'Укажите city_kladr (КЛАДР города) или q (поиск по названию/адресу)',
            ], 422, self::cors($config));
            return;
        }

        $points = $repo->search($prefix, $query !== '' ? $query : null, $limit);
        Response::json(['ok' => true, 'terminals' => $points, 'count' => count($points)], 200, self::cors($config));
    }

    public static function freightSearch(Config $config, ShopRepository $shops): void
    {
        $creds = self::resolveCredentials($shops, $config);
        if ($creds === null) {
            return;
        }

        $q = trim((string) ($_GET['q'] ?? $_GET['name'] ?? ''));
        if (mb_strlen($q) < 2) {
            Response::json(['ok' => false, 'error' => 'q must be at least 2 chars'], 422, self::cors($config));
            return;
        }
        $page = max(1, (int) ($_GET['page'] ?? 1));

        try {
            $api   = new CarrierApi($config);
            $items = $api->searchFreightTypes($q, $page, $creds);
            Response::json(['ok' => true, 'items' => $items], 200, self::cors($config));
        } catch (\Throwable $e) {
            \ShippingBridge\Logger::error(
                trim((string) ($_GET['insales_id'] ?? '-')),
                null,
                'json.freight_search.error',
                ['error' => $e->getMessage()]
            );
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422, self::cors($config));
        }
    }

    private static function resolveCredentials(ShopRepository $shops, Config $config): ?\ShippingBridge\CarrierCredentials
    {
        if ($config->bridgeSecret === '') {
            Response::json(['ok' => false, 'error' => 'BRIDGE_SECRET не задан на сервере'], 503, self::cors($config));
            exit;
        }

        $insalesId = trim((string) ($_GET['insales_id'] ?? ''));
        $shop = trim((string) ($_GET['shop'] ?? ''));

        $creds = null;
        if ($insalesId !== '') {
            $creds = $shops->findCarrierCredentials($insalesId, $config->bridgeSecret);
        } elseif ($shop !== '') {
            $creds = $shops->findCarrierCredentialsByHost($shop, $config->bridgeSecret);
        }

        if ($creds === null) {
            $creds = $config->defaultCarrierCredentials();
        }

        if ($creds === null || !$creds->isComplete()) {
            Response::json(['ok' => false, 'error' => 'Сначала подключите API-ключ и PAT в приложении'], 401, self::cors($config));
            exit;
        }

        return $creds;
    }

    /** @return list<string> */
    private static function cors(Config $config): array
    {
        return Response::corsHeaders($config->corsOrigin);
    }
}
