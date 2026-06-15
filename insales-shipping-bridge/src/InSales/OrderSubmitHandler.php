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
    public static function preview(Config $config, ShopRepository $shops): void
    {
        $cors = Response::corsHeaders($config->corsOrigin);
        $raw  = file_get_contents('php://input') ?: '';
        $body = json_decode($raw, true) ?: [];

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

        // Данные авторизации inSales
        $auth = $shops->findApiAuthByInsalesId($insalesId);
        if ($auth === null) {
            Response::json(['ok' => false, 'error' => 'Нет данных авторизации магазина'], 422, $cors);
            return;
        }

        // Получаем заказ из inSales
        $client = new InSalesClient();
        try {
            $insalesOrder = $client->getOrder(
                $auth['shop_host'],
                $config->insalesAppId ?? '',
                $auth['api_password'],
                (int) $insalesOrderId,
            );
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'error' => 'Ошибка получения заказа: ' . $e->getMessage()], 422, $cors);
            return;
        }

        $order = self::parseInsalesOrder($insalesId, $insalesOrderId, $insalesOrder);

        // Габариты из настроек
        $dims = explode('x', strtolower($settings->defaultDimensionsCm));
        $l = round((float) ($dims[0] ?? 20) / 100, 2);
        $w = round((float) ($dims[1] ?? 20) / 100, 2);
        $h = round((float) ($dims[2] ?? 20) / 100, 2);

        Response::json([
            'ok' => true,
            'sender' => [
                'name'          => $settings->senderName ?? '—',
                'type'          => $settings->senderType,
                'inn'           => $settings->senderInn ?? '—',
                'contact_name'  => $settings->senderContactName ?? '—',
                'contact_phone' => $settings->senderContactPhone ?? '—',
                'terminal_id'   => $settings->senderTerminalId,
            ],
            'receiver' => [
                'name'    => $order['receiver_name'],
                'phone'   => $order['receiver_phone'],
                'email'   => $order['receiver_email'],
                'city'    => $order['arrival_city_name'],
                'street'  => $order['arrival_street'],
                'house'   => $order['arrival_house'],
                'flat'    => $order['arrival_flat'],
            ],
            'cargo' => [
                'weight'       => $order['weight'],
                'length'       => $l,
                'width'        => $w,
                'height'       => $h,
                'stated_value' => $order['stated_value'],
                'freight_uid'  => $settings->freightUid ?? '',
            ],
            'delivery' => [
                'interval'        => $order['delivery_interval'] ?? null,
                'produce_date'    => (new \DateTimeImmutable())
                    ->modify('+' . $settings->produceDaysOffset . ' days')
                    ->format('d.m.Y'),
                'derival_variant' => $settings->derivalVariant,
            ],
        ], 200, $cors);
    }
    public static function handle(Config $config, ShopRepository $shops): void
    {
        $cors = Response::corsHeaders($config->corsOrigin);

        $raw  = file_get_contents('php://input') ?: '';
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

        // Настройки магазина
        $settings = $shops->findSettingsByInsalesId($insalesId, $config);
        if ($settings === null) {
            Response::json(['ok' => false, 'error' => 'Магазин не найден'], 404, $cors);
            return;
        }

        // Проверяем не оформлен ли уже
        $pdo = Db::pdo($config);
        $existing = $shops->findOrderByInsalesId($insalesId, $insalesOrderId);
        if ($existing !== null && $existing['dellin_request_id'] !== null) {
            Response::json([
                'ok'         => true,
                'request_id' => $existing['dellin_request_id'],
                'barcode'    => $existing['dellin_barcode'],
                'message'    => 'Заявка уже оформлена',
            ], 200, $cors);
            return;
        }

        // Получаем данные заказа из inSales API
        $auth = $shops->findApiAuthByInsalesId($insalesId);
        if ($auth === null) {
            Response::json(['ok' => false, 'error' => 'Нет данных авторизации магазина'], 422, $cors);
            return;
        }

        $client = new InSalesClient();
        try {
            $insalesOrder = $client->getOrder(
                $auth['shop_host'],
                $config->insalesAppId ?? '',
                $auth['api_password'],
                (int) $insalesOrderId,
            );
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'error' => 'Ошибка получения заказа из inSales: ' . $e->getMessage()], 422, $cors);
            return;
        }

        // Парсим данные получателя и адрес
        $order = self::parseInsalesOrder($insalesId, $insalesOrderId, $insalesOrder);
        $order['derival_date'] = trim((string) ($body['derival_date'] ?? ''));
        $order['derival_time'] = trim((string) ($body['derival_time'] ?? ''));
        error_log('[BRIDGE] order fields_values: ' . json_encode($insalesOrder['fields_values'] ?? [], JSON_UNESCAPED_UNICODE));
        error_log('[BRIDGE] parsed delivery_type: ' . ($order['dellin_delivery_type'] ?? 'пусто'));
        error_log('[BRIDGE] parsed terminal_id: ' . ($order['dellin_terminal_id'] ?? 'пусто'));
        // Credentials Dellin
        $creds = $shops->findCarrierCredentials($insalesId, $config->bridgeSecret);
        if ($creds === null) {
            Response::json(['ok' => false, 'error' => 'Не настроены учётные данные Dellin'], 422, $cors);
            return;
        }

        // Оформляем в ДЛ
        $api = new CarrierApi($config);
        try {
            $sid    = $api->loginWithPat($creds);
            $deliveryType = in_array(
                $order['delivery_calc_type'] ?? 'auto',
                ['auto', 'avia', 'express', 'small'],
                true
            ) ? $order['delivery_calc_type'] : 'auto';

            $result = $api->createOrder($sid, $settings, $order, $creds, $deliveryType);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422, $cors);
            return;
        }

        // Сохраняем результат
        self::upsertOrder($pdo, $order, (int) $result['request_id'], (string) $result['barcode']);

        Response::json([
            'ok'         => true,
            'request_id' => $result['request_id'],
            'barcode'    => $result['barcode'],
        ], 200, $cors);
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private static function parseInsalesOrder(
        string $insalesId,
        string $insalesOrderId,
        array $raw,
    ): array {
        $client  = $raw['client'] ?? [];
        $address = $raw['shipping-address'] ?? $raw['shipping_address'] ?? [];
        $location = $address['location'] ?? [];

        $receiverName = trim(
            (string) ($client['name'] ?? '')
                ?: (($client['first-name'] ?? '') . ' ' . ($client['last-name'] ?? ''))
        );

        $weight = 0.0;
        $statedValue = 0.0;
        $maxDimsRaw = '';
        foreach ($raw['order-lines'] ?? $raw['order_lines'] ?? [] as $line) {
            $qty = (int) ($line['quantity'] ?? 1);
            $weight += (float) ($line['weight'] ?? 0) * $qty;
            $statedValue += (float) ($line['total-price'] ?? $line['total_price'] ?? 0);
            if ($maxDimsRaw === '' && trim((string) ($line['dimensions'] ?? '')) !== '') {
                $maxDimsRaw = trim((string) $line['dimensions']);
            }
        }
        if ($weight <= 0) {
            $weight = 1.0;
        }

        // Интервал доставки из кастомного поля
        $deliveryInterval = null;
        $deliveryCalcType = 'auto';
        foreach ($raw['fields_values'] ?? [] as $fv) {
            if (($fv['handle'] ?? '') === 'delivery_interval') {
                $deliveryInterval = (string) ($fv['value'] ?? '');
            }
            if (($fv['handle'] ?? '') === 'dellin_calc_type') {
                $deliveryCalcType = (string) ($fv['value'] ?? 'auto');
            }
        }

        // Тип доставки из delivery_info (inSales сохраняет данные выбранного ПВЗ)
        $deliveryInfo = $raw['delivery_info'] ?? [];
        $outlet = $deliveryInfo['outlet'] ?? [];
        $outletType = (string) ($outlet['type'] ?? '');
        $outletExternalId = (string) ($outlet['external_id'] ?? '');
        $shippingHandle = (string) ($deliveryInfo['shipping_company_handle'] ?? '');

        $deliveryType = '';
        $terminalId = '';
        if ($shippingHandle === 'dellin' && $outletType === 'pvz') {
            $deliveryType = 'pickup';
            $terminalId = $outletExternalId;
        }
        // Тип расчёта доставки из tariff_id (inSales не сохраняет dellin_calc_type)
        $tariffId = (string) ($deliveryInfo['tariff_id'] ?? '');
        if ($deliveryCalcType === 'auto' && str_starts_with($tariffId, 'dellin_courier_')) {
            $extractedType = substr($tariffId, strlen('dellin_courier_'));
            if (in_array($extractedType, ['auto', 'avia', 'express', 'small'], true)) {
                $deliveryCalcType = $extractedType;
            }
        }
        if ($deliveryCalcType === 'auto' && $outletType === 'pvz') {
            // Для ПВЗ тип закодирован в dellin_calc_type через fields_values при выборе;
            // если не пришёл, попробуем извлечь из tariff_id ПВЗ (если используется тот же формат)
            if (str_starts_with($tariffId, 'dellin_')) {
                $maybeType = substr($tariffId, strlen('dellin_'));
                if (in_array($maybeType, ['auto', 'avia', 'express', 'small'], true)) {
                    $deliveryCalcType = $maybeType;
                }
            }
        }
        return [
            'insales_shop_id'            => $insalesId,
            'insales_order_id'           => $insalesOrderId,
            'insales_order_number'       => (string) ($raw['number'] ?? $insalesOrderId),
            'receiver_name'              => $receiverName,
            'receiver_phone'             => (string) ($client['phone'] ?? ''),
            'receiver_email'             => (string) ($client['email'] ?? ''),
            'arrival_city_kladr'         => (string) ($location['kladr_code'] ?? ''),
            'arrival_city_name'          => (string) ($location['city'] ?? $address['city'] ?? ''),
            'arrival_street'             => (string) ($location['street'] ?? ''),
            'arrival_house'              => (string) ($location['house'] ?? ''),
            'arrival_flat'               => (string) ($location['flat'] ?? $location['apartment'] ?? ''),
            'weight'                     => round($weight, 3),
            'stated_value'               => round($statedValue, 2),
            'dimensions_cm'              => $maxDimsRaw,
            'delivery_interval'          => $deliveryInterval,
            'delivery_calc_type'         => $deliveryCalcType,
            'dellin_delivery_type'       => $deliveryType,
            'dellin_terminal_id'         => $terminalId,
            'receiver_type'              => (string) ($client['type'] ?? 'Client::Person'),
            'receiver_inn'               => (string) ($client['inn'] ?? ''),
            'receiver_kpp'               => (string) ($client['kpp'] ?? ''),
            'receiver_juridical_address' => (string) ($client['juridical_address'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $order
     */
    private static function upsertOrder(
        \PDO $pdo,
        array $order,
        int $requestId,
        string $barcode,
    ): void {
        $stmt = $pdo->prepare('
            INSERT INTO dellin_orders (
                insales_shop_id, insales_order_id, insales_order_number,
                receiver_name, receiver_phone, receiver_email,
                arrival_city_kladr, arrival_city_name,
                arrival_street, arrival_house, arrival_flat,
                weight, stated_value,
                dellin_request_id, dellin_barcode
            ) VALUES (
                :shop_id, :order_id, :order_number,
                :receiver_name, :receiver_phone, :receiver_email,
                :city_kladr, :city_name,
                :street, :house, :flat,
                :weight, :stated_value,
                :request_id, :barcode
            )
            ON DUPLICATE KEY UPDATE
                dellin_request_id = VALUES(dellin_request_id),
                dellin_barcode    = VALUES(dellin_barcode),
                updated_at        = CURRENT_TIMESTAMP
        ');
        $stmt->execute([
            'shop_id'      => $order['insales_shop_id'],
            'order_id'     => $order['insales_order_id'],
            'order_number' => $order['insales_order_number'],
            'receiver_name' => $order['receiver_name'],
            'receiver_phone' => $order['receiver_phone'],
            'receiver_email' => $order['receiver_email'],
            'city_kladr'   => $order['arrival_city_kladr'],
            'city_name'    => $order['arrival_city_name'],
            'street'       => $order['arrival_street'],
            'house'        => $order['arrival_house'],
            'flat'         => $order['arrival_flat'],
            'weight'       => $order['weight'],
            'stated_value' => $order['stated_value'],
            'request_id'   => $requestId,
            'barcode'      => $barcode,
        ]);
    }
}
