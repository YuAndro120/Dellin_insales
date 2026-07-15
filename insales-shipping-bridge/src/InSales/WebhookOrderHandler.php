<?php

declare(strict_types=1);

namespace ShippingBridge\InSales;

use ShippingBridge\AutomationJobRepository;
use ShippingBridge\AutomationRuleRepository;
use ShippingBridge\Db;
use ShippingBridge\Config;
use ShippingBridge\Http\Response;
use ShippingBridge\ShopRepository;
use ShippingBridge\SubscriptionRepository;

final class WebhookOrderHandler
{
    public static function handle(Config $config, ShopRepository $shops): void
    {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw === '') {
            Response::json(['ok' => false, 'error' => 'Empty payload'], 400);
            return;
        }

        $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            Response::json(['ok' => false, 'error' => 'Invalid JSON'], 400);
            return;
        }

        // Если в URL передан секрет вебхука — определяем магазин строго по нему
        // (приоритетный, защищённый путь). Старые регистрации без ?wsk= идут по
        // прежней (менее строгой) логике ниже — обратная совместимость.
        $wsk = trim((string) ($_GET['wsk'] ?? ''));
        if ($wsk === '') {
            Response::json(['ok' => false, 'error' => 'Missing webhook secret'], 403);
            return;
        }
        $shopRow = $shops->findActiveByWebhookSecret($wsk);
        if ($shopRow === null) {
            Response::json(['ok' => false, 'error' => 'Invalid webhook secret'], 403);
            return;
        }

        $insalesShopId = $shopRow['insales_id'];
        $orderId = (string) ($payload['id'] ?? '');
        $orderNumber = (string) ($payload['number'] ?? $payload['id'] ?? '');

        if ($orderId === '') {
            Response::json(['ok' => false, 'error' => 'Missing order id'], 400);
            return;
        }

        // Данные получателя
        $client = $payload['client'] ?? [];
        $receiverName = trim(
            ($client['name'] ?? '')
                ?: (($client['first-name'] ?? '') . ' ' . ($client['last-name'] ?? ''))
        );
        $receiverPhone = (string) ($client['phone'] ?? '');
        $receiverEmail = (string) ($client['email'] ?? '');

        // Адрес доставки
        $shipping = $payload['shipping-address'] ?? $payload['shipping_address'] ?? [];
        $cityName  = (string) ($shipping['city'] ?? '');
        $street    = (string) ($shipping['address'] ?? $shipping['street'] ?? '');
        $house     = (string) ($shipping['house'] ?? '');
        $flat      = (string) ($shipping['flat'] ?? $shipping['apartment'] ?? '');

        // КЛАДР из кастомного поля если inSales его передаёт
        $cityKladr = (string) ($shipping['kladr_id'] ?? $shipping['city_kladr'] ?? '');

        // Статус заказа: приоритет — пользовательский (custom_status_permalink),
        // если в магазине включены кастомные статусы; иначе системный
        // (fulfillment_status). См. AutomationRuleRepository — именно по
        // этому значению настраивается автоматизация "статус -> создать заказ в ДЛ".
        $currentStatus = (string) (
            $payload['custom-status-permalink']
            ?? $payload['custom_status_permalink']
            ?? $payload['fulfillment-status']
            ?? $payload['fulfillment_status']
            ?? ''
        );

        // Груз — суммируем по позициям
        $weight = 0.0;
        $statedValue = 0.0;
        foreach ($payload['order-lines'] ?? $payload['order_lines'] ?? [] as $line) {
            $qty = (int) ($line['quantity'] ?? 1);
            $lineWeight = (float) ($line['weight'] ?? 0);
            $weight += $lineWeight * $qty;
            $statedValue += (float) ($line['total-price'] ?? $line['total_price'] ?? 0) / 100;
        }
        if ($weight <= 0) {
            $weight = 1.0;
        }

        $pdo = Db::pdo($config);

        // Предыдущий известный статус — читаем ДО апдейта, чтобы понять,
        // изменился ли статус (а не просто пришло дублирующее orders/update
        // по другой причине, например правка адреса).
        $prevStatusStmt = $pdo->prepare('
            SELECT insales_custom_status FROM dellin_orders
            WHERE insales_shop_id = :shop_id AND insales_order_id = :order_id
        ');
        $prevStatusStmt->execute(['shop_id' => $insalesShopId, 'order_id' => $orderId]);
        $prevStatus = $prevStatusStmt->fetchColumn();
        $prevStatus = $prevStatus === false ? null : (string) $prevStatus;

        $stmt = $pdo->prepare('
            INSERT INTO dellin_orders (
                insales_shop_id, insales_order_id, insales_order_number, insales_custom_status,
                receiver_name, receiver_phone, receiver_email,
                arrival_city_kladr, arrival_city_name,
                arrival_street, arrival_house, arrival_flat,
                weight, stated_value, insales_payload
            ) VALUES (
                :shop_id, :order_id, :order_number, :custom_status,
                :receiver_name, :receiver_phone, :receiver_email,
                :city_kladr, :city_name,
                :street, :house, :flat,
                :weight, :stated_value, :payload
            )
            ON DUPLICATE KEY UPDATE
                insales_order_number = VALUES(insales_order_number),
                insales_custom_status = VALUES(insales_custom_status),
                receiver_name = VALUES(receiver_name),
                receiver_phone = VALUES(receiver_phone),
                receiver_email = VALUES(receiver_email),
                arrival_city_kladr = VALUES(arrival_city_kladr),
                arrival_city_name = VALUES(arrival_city_name),
                arrival_street = VALUES(arrival_street),
                arrival_house = VALUES(arrival_house),
                arrival_flat = VALUES(arrival_flat),
                weight = VALUES(weight),
                stated_value = VALUES(stated_value),
                insales_payload = VALUES(insales_payload),
                updated_at = CURRENT_TIMESTAMP
        ');

        $stmt->execute([
            'shop_id'       => $insalesShopId,
            'order_id'      => $orderId,
            'order_number'  => $orderNumber,
            'custom_status' => $currentStatus !== '' ? $currentStatus : null,
            'receiver_name' => $receiverName,
            'receiver_phone' => $receiverPhone,
            'receiver_email' => $receiverEmail,
            'city_kladr'    => $cityKladr,
            'city_name'     => $cityName,
            'street'        => $street,
            'house'         => $house,
            'flat'          => $flat,
            'weight'        => round($weight, 3),
            'stated_value'  => round($statedValue, 2),
            'payload'       => $raw,
        ]);

        // Автоматизация "статус inSales -> создать заказ в ДЛ" — только на
        // тарифе "Автоматизация" и только если статус реально изменился
        // (защита от повторной постановки на дублирующиеся вебхуки).
        if ($currentStatus !== '' && $currentStatus !== $prevStatus) {
            $subscriptions = new SubscriptionRepository($pdo);
            if ($subscriptions->hasAtLeast($insalesShopId, SubscriptionRepository::PLAN_AUTOMATION)) {
                $rules = new AutomationRuleRepository($pdo);
                $action = $rules->findAction($insalesShopId, AutomationRuleRepository::DIRECTION_INSALES_TO_DL, $currentStatus);
                if ($action === AutomationRuleRepository::ACTION_CREATE_DL_ORDER) {
                    (new AutomationJobRepository($pdo))->enqueue(
                        $insalesShopId,
                        $orderId,
                        'create_dl_order',
                    );
                    \ShippingBridge\Logger::info($insalesShopId, $orderId, 'automation.enqueued', [
                        'trigger_status' => $currentStatus,
                    ]);
                }
            }
        }

        Response::json(['ok' => true], 200);
    }
}
