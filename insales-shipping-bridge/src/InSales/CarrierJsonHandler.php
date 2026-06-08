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
        $cors = self::cors($config);
        $creds = self::resolveCredentials($shops, $config);
        if ($creds === null) return;

        try {
            $insalesId = trim((string) ($_GET['insales_id'] ?? ''));
            $settings  = $shops->findSettingsByInsalesId($insalesId, $config);

            $api = new CarrierApi($config);
            $reference = $api->getPackagesReference();

            $dims = explode('x', strtolower($settings?->defaultDimensionsCm ?? '20x20x20'));
            $l  = (float) ($_GET['length'] ?? ((float)($dims[0] ?? 20) / 100));
            $w  = (float) ($_GET['width']  ?? ((float)($dims[1] ?? 20) / 100));
            $h  = (float) ($_GET['height'] ?? ((float)($dims[2] ?? 20) / 100));
            $wt = (float) ($_GET['weight'] ?? ($settings?->defaultWeightKg ?? 1.0));
            $vol = max(0.01, round($l * $w * $h, 4));
            $kladr = trim((string) ($_GET['kladr'] ?? ($settings?->derivalCityKladr ?? '')));
            $tid   = $settings?->senderTerminalId ?? null;
            $dtype = trim((string) ($_GET['delivery_type'] ?? 'auto'));

            $conditions = $api->getRequestConditions(
                $wt,
                $vol,
                $l,
                $w,
                $h,
                1,
                $kladr ?: null,
                $tid,
                $dtype
            );

            $availableUids = array_column($conditions['packages'] ?? [], 'uid');
            $items = [];
            foreach ($availableUids as $uid) {
                $items[] = [
                    'uid'  => $uid,
                    'name' => $reference[$uid] ?? 'Упаковка',
                ];
            }

            Response::json([
                'ok'         => true,
                'items'      => $items,
                'day_to_day' => $conditions['day_to_day'] ?? null,
                'insurance'  => $conditions['insurance']  ?? null,
            ], 200, $cors);
        } catch (\Throwable $e) {
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
