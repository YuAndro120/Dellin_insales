<?php

declare(strict_types=1);

namespace ShippingBridge\InSales;

use ShippingBridge\CarrierApi;
use ShippingBridge\Config;
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
