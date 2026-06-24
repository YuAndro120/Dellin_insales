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
                'interval'            => $order['delivery_interval'] ?? null,
                'produce_date'        => (new \DateTimeImmutable())
                    ->modify('+' . $settings->produceDaysOffset . ' days')
                    ->format('d.m.Y'),
                'derival_variant'     => $settings->derivalVariant,
                'derival_time_from'   => $settings->derivalTimeFrom ?? '',
                'derival_time_to'     => $settings->derivalTimeTo ?? '',
                'derival_break_from'  => $settings->derivalBreakFrom ?? '',
                'derival_break_to'    => $settings->derivalBreakTo ?? '',
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

        // Проверка тарифа: отправка заявки в ДЛ доступна только на тарифах
        // "Полный" и выше — на тарифе "Калькулятор" доступен только preview().
        $pdo = Db::pdo($config);
        $subscriptions = new \ShippingBridge\SubscriptionRepository($pdo);
        if (!$subscriptions->hasAtLeast($insalesId, \ShippingBridge\SubscriptionRepository::PLAN_FULL)) {
            Response::json([
                'ok' => false,
                'error' => 'Оформление заявки в Деловые Линии доступно на тарифе "Полный" и выше. Расчёт стоимости доступен на вашем текущем тарифе.',
                'upgrade_required' => true,
            ], 402, $cors);
            return;
        }
        $existing = $shops->findOrderByInsalesId($insalesId, $insalesOrderId);
        if ($existing !== null && $existing['dellin_request_id'] !== null) {
            // Backfill: заявка уже была, но трек мог не записаться при первом
            // оформлении (например, поле ещё не существовало). Пробуем снова.
            $fieldId = $shops->findOrderFieldId($insalesId);
            if ($fieldId !== null) {
                $authForBackfill = $shops->findApiAuthByInsalesId($insalesId);
                if ($authForBackfill !== null) {
                    try {
                        $clientForBackfill = new InSalesClient();
                        $insalesOrderForBackfill = $clientForBackfill->getOrder(
                            $authForBackfill['shop_host'],
                            $config->insalesAppId ?? '',
                            $authForBackfill['api_password'],
                            (int) $insalesOrderId,
                        );
                        self::writeTrackingNumber(
                            $clientForBackfill,
                            $authForBackfill,
                            $config,
                            (int) $insalesOrderId,
                            $insalesOrderForBackfill,
                            $fieldId,
                            (string) $existing['dellin_request_id'],
                        );
                    } catch (\Throwable) {
                        // best-effort, ответ не блокируем
                    }
                }
            }
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
            self::upsertOrderError($pdo, $order, $e->getMessage());
            \ShippingBridge\Logger::error(
                $insalesId,
                $insalesOrderId,
                'order.create.failed',
                ['error' => $e->getMessage(), 'order_number' => $order['insales_order_number'] ?? '']
            );
            Response::json(['ok' => false, 'error' => self::humanizeError($e->getMessage())], 422, $cors);
            return;
        }

        // Сохраняем результат
        self::upsertOrder($pdo, $order, (int) $result['request_id'], (string) $result['barcode']);

        // Записываем номер заказа ДЛ в наше поле заказа inSales.
        $fieldId = $shops->findOrderFieldId($insalesId);
        $trackingWritten = false;
        if ($fieldId !== null && $fieldId > 0) {
            $trackingWritten = self::writeTrackingNumber(
                $client,
                $auth,
                $config,
                (int) $insalesOrderId,
                $insalesOrder,
                $fieldId,
                (string) $result['request_id'],
            );
        }

        Response::json([
            'ok'               => true,
            'request_id'       => $result['request_id'],
            'barcode'          => $result['barcode'],
            'tracking_written' => $trackingWritten,
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

        $cargoLines = [];
        foreach ($raw['order-lines'] ?? $raw['order_lines'] ?? [] as $line) {
            $qty = max(1, (int) ($line['quantity'] ?? 1));
            $totalPrice = (float) ($line['total-price'] ?? $line['total_price'] ?? 0);
            if ($totalPrice <= 0) {
                $totalPrice = (float) ($line['price'] ?? 0) * $qty;
            }
            $cargoLines[] = [
                'quantity' => $qty,
                'weight' => (float) ($line['weight'] ?? 0),
                'dimensions' => trim((string) ($line['dimensions'] ?? '')),
                'total_price' => $totalPrice,
            ];
        }

        $cargo = $cargoLines !== []
            ? \ShippingBridge\CargoFromInsalesOrder::aggregate($cargoLines)
            : ['weight' => 1.0, 'total_weight' => 1.0, 'volume' => 0.008, 'length' => 0.2, 'width' => 0.2, 'height' => 0.2, 'quantity' => 1, 'stated_value' => 0.0, 'oversized_weight' => 0.0, 'oversized_volume' => 0.0];

        $weight = $cargo['weight'];
        $totalWeight = $cargo['total_weight'];
        $statedValue = $cargo['stated_value'];
        $maxDimsRaw = round($cargo['length'] * 100, 1) . 'x' . round($cargo['width'] * 100, 1) . 'x' . round($cargo['height'] * 100, 1);

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
            // external_id закодирован как realTerminalId * 10 + typeIndex
            // (см. ExternalCheckoutHandler::pickupPoints/pickupPoint, та же схема
            // кодирования) — раскодируем здесь, так как inSales не гарантированно
            // сохраняет dellin_calc_type из fields_values для точек самовывоза.
            $encodedId = (int) $outletExternalId;
            if ($encodedId > 0) {
                $typeListForDecode = ['auto', 'avia', 'express', 'small'];
                $decodedType = $typeListForDecode[$encodedId % 10] ?? 'auto';
                $terminalId = (string) intdiv($encodedId, 10);
                if ($deliveryCalcType === 'auto') {
                    $deliveryCalcType = $decodedType;
                }
            } else {
                $terminalId = $outletExternalId;
            }
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
            'total_weight'               => round($totalWeight, 3),
            'stated_value'               => round($statedValue, 2),
            'dimensions_cm'              => $maxDimsRaw,
            'quantity'                   => $cargo['quantity'],
            'oversized_weight'           => $cargo['oversized_weight'],
            'oversized_volume'           => $cargo['oversized_volume'],
            'delivery_interval'          => $deliveryInterval,
            'delivery_calc_type'         => $deliveryCalcType,
            'dellin_delivery_type'       => $deliveryType,
            'dellin_terminal_id'         => $terminalId,
            'receiver_type'              => (string) ($client['type'] ?? 'Client::Person'),
            'receiver_inn'               => (string) ($client['inn'] ?? ''),
            'receiver_kpp'               => (string) ($client['kpp'] ?? ''),
            'receiver_juridical_address' => (string) ($client['juridical_address'] ?? ''),
            'manager_comment'            => trim((string) ($raw['manager_comment'] ?? '')),
        ];
    }

    private static function upsertOrderError(
        \PDO $pdo,
        array $order,
        string $error,
    ): void {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO dellin_orders
                    (insales_shop_id, insales_order_id, insales_order_number,
                     receiver_name, arrival_city_name, weight, stated_value, last_error, created_at)
                 VALUES
                    (:shop, :oid, :onum, :rname, :city, :wt, :sv, :err, NOW())
                 ON DUPLICATE KEY UPDATE
                    last_error = :err,
                    updated_at = NOW()'
            );
            $stmt->execute([
                ':shop'  => $order['insales_shop_id'],
                ':oid'   => $order['insales_order_id'],
                ':onum'  => $order['insales_order_number'] ?? '',
                ':rname' => $order['receiver_name'] ?? '',
                ':city'  => $order['arrival_city_name'] ?? '',
                ':wt'    => $order['weight'] ?? 0,
                ':sv'    => $order['stated_value'] ?? 0,
                ':err'   => mb_substr($error, 0, 2000),
            ]);
        } catch (\Throwable) {
            // не блокируем ответ
        }
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
    private static function humanizeError(string $raw): string
    {
        // Ошибки от API ДЛ
        if (str_contains($raw, '130025')) {
            return 'Для оформления заявки требуется документ получателя.';
        }
        if (str_contains($raw, '130004')) {
            return 'Не заполнены обязательные параметры заказа. Проверьте габариты и вес товара в inSales.';
        }
        if (str_contains($raw, '130021')) {
            return 'Выбранный тип доставки недоступен для этого маршрута. Попробуйте другой тариф.';
        }
        if (str_contains($raw, '130015')) {
            return 'Выбранная упаковка недоступна для этого вида перевозки. Отключите упаковку в настройках.';
        }
        if (str_contains($raw, '130022')) {
            return 'Некорректный адрес или терминал. Проверьте настройки отправителя.';
        }
        if (str_contains($raw, '110003')) {
            return 'Отсутствует обязательный параметр в запросе к ДЛ. Проверьте настройки приложения.';
        }
        if (str_contains($raw, 'HTTP 400')) {
            return 'Ошибка при создании заявки в Деловых Линиях. Проверьте настройки отправителя и попробуйте снова.';
        }
        if (str_contains($raw, 'HTTP 401') || str_contains($raw, 'HTTP 403')) {
            return 'Ошибка авторизации в API Деловых Линий. Обновите PAT-токен в настройках подключения.';
        }
        if (str_contains($raw, 'HTTP 429')) {
            return 'Превышен лимит запросов к API Деловых Линий. Попробуйте через минуту.';
        }
        if (str_contains($raw, 'HTTP 5')) {
            return 'Сервис Деловых Линий временно недоступен. Попробуйте через несколько минут.';
        }
        // Ошибки сети/сервера
        if (str_contains($raw, 'cURL') || str_contains($raw, 'curl')) {
            return 'Ошибка сети при обращении к API Деловых Линий. Проверьте соединение и повторите.';
        }
        return $raw;
    }
    /**
     * Записывает номер заказа ДЛ в наше поле заказа inSales по сохранённому field_id.
     * Если значение уже было — обновляем ту же строку, иначе создаём новую.
     *
     * @param array{shop_host:string,api_password:string} $auth
     * @param array<string,mixed> $insalesOrder уже загруженный заказ (для поиска id значения)
     */
    private static function writeTrackingNumber(
        InSalesClient $client,
        array $auth,
        Config $config,
        int $insalesOrderId,
        array $insalesOrder,
        int $fieldId,
        string $trackValue,
    ): bool {
        if ($trackValue === '' || $trackValue === '0') {
            return false;
        }

        // Ищем id уже существующего значения именно этого поля в заказе,
        // чтобы обновить его, а не создавать дубль.
        $valueRowId = null;
        foreach ($insalesOrder['fields_values'] ?? [] as $fv) {
            $fvFieldId = (int) ($fv['field_id'] ?? $fv['order_field_id'] ?? 0);
            if ($fvFieldId === $fieldId) {
                $valueRowId = (int) ($fv['id'] ?? 0) ?: null;
                break;
            }
        }

        $attr = $valueRowId !== null
            ? ['id' => $valueRowId, 'value' => $trackValue]    // обновление
            : ['field_id' => $fieldId, 'value' => $trackValue]; // создание

        try {
            $client->updateOrder(
                $auth['shop_host'],
                $config->insalesAppId ?? '',
                $auth['api_password'],
                $insalesOrderId,
                ['fields_values_attributes' => [$attr]],
            );
            \ShippingBridge\Logger::info(
                $config->insalesAppId ?? '',
                (string) $insalesOrderId,
                'tracking.written',
                ['field_id' => $fieldId, 'value' => $trackValue],
            );
            return true;
        } catch (\Throwable $e) {
            \ShippingBridge\Logger::error(
                $config->insalesAppId ?? '',
                (string) $insalesOrderId,
                'tracking.write_failed',
                ['error' => $e->getMessage()],
            );
            return false;
        }
    }
}
