<?php

declare(strict_types=1);

namespace ShippingBridge\InSales;

use ShippingBridge\CarrierApi;
use ShippingBridge\Config;
use ShippingBridge\Db;
use ShippingBridge\Http\Response;
use ShippingBridge\ShopRepository;

final class OrderSubmitHandler
{
    public static function handle(Config $config, ShopRepository $shops): void
    {
        $cors = Response::corsHeaders($config->corsOrigin);

        $raw = file_get_contents('php://input') ?: '';
        $body = $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($body)) {
            $body = [];
        }

        $insalesId      = trim((string) ($body['insales_id'] ?? ''));
        $insalesOrderId = trim((string) ($body['insales_order_id'] ?? ''));

        if ($insalesId === '' || $insalesOrderId === '') {
            Response::json(['ok' => false, 'error' => 'insales_id и insales_order_id обязательны'], 422, $cors);
            return;
        }

        $settings = $shops->findSettingsByInsalesId($insalesId, $config);
        if ($settings === null) {
            Response::json(['ok' => false, 'error' => 'Магазин не найден'], 404, $cors);
            return;
        }

        $pdo = Db::pdo($config);
        $order = $shops->findOrderByInsalesId($insalesId, $insalesOrderId);
        if ($order === null) {
            Response::json(['ok' => false, 'error' => 'Заказ не найден. Дождитесь обработки webhook.'], 404, $cors);
            return;
        }

        if ($order['dellin_request_id'] !== null) {
            Response::json([
                'ok'      => true,
                'barcode' => $order['dellin_barcode'],
                'message' => 'Заявка уже оформлена',
            ], 200, $cors);
            return;
        }

        // Получаем credentials
        $creds = $shops->findCarrierCredentials($insalesId, $config->bridgeSecret);
        if ($creds === null) {
            Response::json(['ok' => false, 'error' => 'Не настроены учётные данные Dellin'], 422, $cors);
            return;
        }

        $api = new CarrierApi($config);

        try {
            $sid = $api->loginWithPat($creds);
            $result = $api->createOrder($sid, $settings, $order, $creds);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422, $cors);
            return;
        }

        $shops->updateOrderDellinResult(
            (int) $order['id'],
            (int) $result['request_id'],
            (string) $result['barcode'],
        );

        Response::json([
            'ok'         => true,
            'request_id' => $result['request_id'],
            'barcode'    => $result['barcode'],
        ], 200, $cors);
    }
}
