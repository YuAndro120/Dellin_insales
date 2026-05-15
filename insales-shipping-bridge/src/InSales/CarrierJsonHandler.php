<?php

declare(strict_types=1);

namespace ShippingBridge\InSales;

use ShippingBridge\CarrierApi;
use ShippingBridge\Config;
use ShippingBridge\Http\Response;
use ShippingBridge\TerminalRepository;

/**
 * JSON для страницы настроек inSales: города и терминалы из API перевозчика.
 */
final class CarrierJsonHandler
{
    public static function citiesSearch(Config $config): void
    {
        self::requireAppkey($config);
        $q = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($q) < 2) {
            Response::json(['ok' => false, 'error' => 'q must be at least 2 chars'], 422, self::cors($config));
            return;
        }
        $api = new CarrierApi($config);
        $list = $api->searchCities($q);
        Response::json(['ok' => true, 'cities' => $list], 200, self::cors($config));
    }

    public static function terminals(Config $config): void
    {
        self::requireAppkey($config);
        $prefix = isset($_GET['city_kladr']) ? trim((string) $_GET['city_kladr']) : null;
        if ($prefix === '') {
            $prefix = null;
        }
        $query = trim((string) ($_GET['q'] ?? ''));
        $limit = isset($_GET['limit']) ? max(1, min(500, (int) $_GET['limit'])) : 100;
        $refresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';

        $api = new CarrierApi($config);
        $repo = new TerminalRepository($config, $api);
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

    private static function requireAppkey(Config $config): void
    {
        if ($config->appkey === '') {
            Response::json([
                'ok' => false,
                'error' => 'Задайте SHIPPING_API_APPKEY в .env на сервере (ключ API перевозчика)',
            ], 503, self::cors($config));
            exit;
        }
    }

    /** @return list<string> */
    private static function cors(Config $config): array
    {
        return Response::corsHeaders($config->corsOrigin);
    }
}
