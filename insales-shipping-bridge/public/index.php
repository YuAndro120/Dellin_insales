<?php

declare(strict_types=1);

use ShippingBridge\CarrierApi;
use ShippingBridge\Config;
use ShippingBridge\Db;
use ShippingBridge\Http\Response;
use ShippingBridge\InSales\AppSettingsHandler;
use ShippingBridge\InSales\CarrierJsonHandler;
use ShippingBridge\InSales\InstallHandlers;
use ShippingBridge\CalculatorContext;
use ShippingBridge\ShopDeliveryContext;
use ShippingBridge\InSales\InSalesClient;
use ShippingBridge\ShopRepository;
use ShippingBridge\TerminalRepository;
use ShippingBridge\ArrivalKladrResolver;
use ShippingBridge\VariantQuoteService;

require dirname(__DIR__) . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

$corsOrigin = getenv('CORS_ORIGIN') ?: '*';
$cors = Response::corsHeaders($corsOrigin);

if ($method === 'OPTIONS') {
    http_response_code(204);
    foreach ($cors as $h) {
        header($h);
    }
    exit;
}

if ($uri === '/health' || $uri === '/v1/health') {
    Response::json(['ok' => true, 'service' => 'insales-shipping-bridge', 'version' => 'mvp-3'], 200, $cors);
    exit;
}

if (str_starts_with($uri, '/insales/')) {
    try {
        $config = Config::fromEnvForInsales();
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
        exit;
    }
    $cors = Response::corsHeaders($config->corsOrigin);

    if ($uri === '/insales/cities/search' && $method === 'GET') {
        CarrierJsonHandler::citiesSearch($config);
        exit;
    }
    if ($uri === '/insales/terminals' && $method === 'GET') {
        CarrierJsonHandler::terminals($config);
        exit;
    }

    if (!$config->hasDatabase()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<p>Задайте MySQL в .env (MYSQL_* или DATABASE_URL) и выполните database/schema.sql</p>';
        exit;
    }
    try {
        $pdo = Db::pdo($config);
        $shops = new ShopRepository($pdo);
        if ($uri === '/insales/install' && $method === 'GET') {
            InstallHandlers::install($config, $shops);
            exit;
        }
        if ($uri === '/insales/app' && ($method === 'GET' || $method === 'POST')) {
            AppSettingsHandler::handle($shops, $config, $method);
            exit;
        }
        if ($uri === '/insales/uninstall' && ($method === 'GET' || $method === 'POST')) {
            InstallHandlers::uninstall($shops);
            exit;
        }
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
        exit;
    }
    Response::json(['ok' => false, 'error' => 'Not found', 'path' => $uri], 404, $cors);
    exit;
}

try {
    $config = Config::fromEnv();
} catch (Throwable $e) {
    Response::json(['ok' => false, 'error' => $e->getMessage()], 500, $cors);
    exit;
}

$cors = Response::corsHeaders($config->corsOrigin);

$checkAuth = static function () use ($config): void {
    if ($config->bridgeSecret === '') {
        return;
    }
    $token = $_SERVER['HTTP_X_BRIDGE_TOKEN'] ?? '';
    if (!is_string($token) || !hash_equals($config->bridgeSecret, $token)) {
        Response::json(['ok' => false, 'error' => 'Unauthorized'], 401, Response::corsHeaders($config->corsOrigin));
        exit;
    }
};

$readJson = static function (): array {
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') {
        return [];
    }
    $d = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    return is_array($d) ? $d : [];
};

try {
    if ($uri === '/v1/terminals' && $method === 'GET') {
        $checkAuth();
        $api = new CarrierApi($config);
        $repo = new TerminalRepository($config, $api);
        $prefix = isset($_GET['city_kladr']) ? (string) $_GET['city_kladr'] : null;
        $swLat = isset($_GET['sw_lat']) ? (float) $_GET['sw_lat'] : null;
        $swLon = isset($_GET['sw_lng']) ? (float) $_GET['sw_lng'] : null;
        $neLat = isset($_GET['ne_lat']) ? (float) $_GET['ne_lat'] : null;
        $neLon = isset($_GET['ne_lng']) ? (float) $_GET['ne_lng'] : null;
        $limit = isset($_GET['limit']) ? max(1, min(2000, (int) $_GET['limit'])) : 500;
        $bbox = ($swLat !== null && $swLon !== null && $neLat !== null && $neLon !== null)
            ? [$swLat, $swLon, $neLat, $neLon]
            : [null, null, null, null];
        $points = $repo->getPoints($prefix, $bbox[0], $bbox[1], $bbox[2], $bbox[3], $limit);
        Response::json(['ok' => true, 'terminals' => $points, 'count' => count($points)], 200, $cors);
        exit;
    }

    if ($uri === '/v1/cities/search' && $method === 'GET') {
        $checkAuth();
        $q = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($q) < 2) {
            Response::json(['ok' => false, 'error' => 'q must be at least 2 chars'], 422, $cors);
            exit;
        }
        $api = new CarrierApi($config);
        $list = $api->searchCities($q);
        Response::json(['ok' => true, 'cities' => $list], 200, $cors);
        exit;
    }

    if ($uri === '/v1/calculate' && $method === 'POST') {
        $checkAuth();
        $body = $readJson();
        $terminalId = (int) ($body['arrival_terminal_id'] ?? 0);
        if ($terminalId <= 0) {
            Response::json(['ok' => false, 'error' => 'arrival_terminal_id required'], 422, $cors);
            exit;
        }
        $cargo = is_array($body['cargo'] ?? null) ? $body['cargo'] : [];
        $pdo = $config->hasDatabase() ? Db::pdo($config) : null;
        $shops = $pdo !== null ? new ShopRepository($pdo) : null;
        try {
            $shopSettings = ShopDeliveryContext::resolveSettings($body, $shops, $config);
            $senderTerminalId = ShopDeliveryContext::requireSenderTerminalId($shopSettings);
            $calcCtx = CalculatorContext::fromShopSettings($shopSettings);
        } catch (Throwable $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422, $cors);
            exit;
        }
        $api = new CarrierApi($config);
        $repo = new TerminalRepository($config, $api);
        $paymentKladr = (new ArrivalKladrResolver($repo))->resolve(
            isset($body['arrival_city_kladr']) ? (string) $body['arrival_city_kladr'] : null,
            $terminalId
        );
        $sid = $api->login();
        $calc = $api->calculateToTerminal($sid, $senderTerminalId, $terminalId, $paymentKladr, $cargo, $calcCtx);
        Response::json([
            'ok' => $calc['price'] !== null,
            'price' => $calc['price'],
            'currency' => 'RUB',
            'days' => $calc['days'],
            'errors' => $calc['errors'] ?? null,
        ], $calc['price'] !== null ? 200 : 422, $cors);
        exit;
    }

    if ($uri === '/v1/calculate-city' && $method === 'POST') {
        $checkAuth();
        $body = $readJson();
        $arrivalKladr = (string) ($body['arrival_city_kladr'] ?? '');
        if (strlen($arrivalKladr) < 10) {
            Response::json(['ok' => false, 'error' => 'arrival_city_kladr required'], 422, $cors);
            exit;
        }
        $cargo = is_array($body['cargo'] ?? null) ? $body['cargo'] : [];
        $pdo = $config->hasDatabase() ? Db::pdo($config) : null;
        $shops = $pdo !== null ? new ShopRepository($pdo) : null;
        try {
            $shopSettings = ShopDeliveryContext::resolveSettings($body, $shops, $config);
            $senderTerminalId = ShopDeliveryContext::requireSenderTerminalId($shopSettings);
            $calcCtx = CalculatorContext::fromShopSettings($shopSettings);
        } catch (Throwable $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422, $cors);
            exit;
        }
        $api = new CarrierApi($config);
        $sid = $api->login();
        $calc = $api->calculateToCity($sid, $senderTerminalId, $arrivalKladr, $cargo, $calcCtx);
        Response::json([
            'ok' => $calc['price'] !== null,
            'price' => $calc['price'],
            'currency' => 'RUB',
            'days' => $calc['days'],
            'errors' => $calc['errors'] ?? null,
        ], $calc['price'] !== null ? 200 : 422, $cors);
        exit;
    }

    if ($uri === '/v1/calculate-from-variants' && $method === 'POST') {
        $checkAuth();
        if (!$config->hasDatabase()) {
            Response::json(['ok' => false, 'error' => 'Database not configured'], 503, $cors);
            exit;
        }
        $body = $readJson();
        $pdo = Db::pdo($config);
        $shops = new ShopRepository($pdo);
        $insales = new InSalesClient();
        $carrier = new CarrierApi($config);
        $termRepo = new TerminalRepository($config, $carrier);
        $svc = new VariantQuoteService($config, $shops, $insales, $carrier, new ArrivalKladrResolver($termRepo));
        try {
            $out = $svc->quoteFromCartLines($body);
        } catch (Throwable $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422, $cors);
            exit;
        }
        Response::json($out, $out['ok'] ? 200 : 422, $cors);
        exit;
    }

    Response::json(['ok' => false, 'error' => 'Not found', 'path' => $uri], 404, $cors);
} catch (Throwable $e) {
    Response::json([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500, $cors);
}
