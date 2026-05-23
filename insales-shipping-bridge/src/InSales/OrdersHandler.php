<?php

declare(strict_types=1);

namespace ShippingBridge\InSales;

use ShippingBridge\Config;
use ShippingBridge\Db;
use ShippingBridge\ShopRepository;

final class OrdersHandler
{
    public static function handle(Config $config, ShopRepository $shops, string $method): void
    {
        header('Content-Type: text/html; charset=utf-8');

        $shopHost  = trim((string) ($_GET['shop'] ?? $_POST['shop'] ?? ''));
        $insalesId = trim((string) ($_GET['insales_id'] ?? $_POST['insales_id'] ?? ''));

        if ($shopHost === '' && $insalesId === '') {
            http_response_code(400);
            self::renderError('Укажите параметры shop или insales_id.');
            return;
        }

        $settings = $insalesId !== ''
            ? $shops->findSettingsByInsalesId($insalesId, $config)
            : $shops->findSettingsByHost($shopHost, $config);

        if ($settings === null) {
            http_response_code(404);
            self::renderError('Магазин не найден.');
            return;
        }

        $pdo = Db::pdo($config);

        $stmt = $pdo->prepare('
            SELECT id, insales_order_number, receiver_name, receiver_phone,
                   arrival_city_name, arrival_street, arrival_house,
                   weight, stated_value,
                   dellin_request_id, dellin_barcode, dellin_status_title,
                   created_at
            FROM dellin_orders
            WHERE insales_shop_id = :shop_id
            ORDER BY created_at DESC
            LIMIT 100
        ');
        $stmt->execute(['shop_id' => $settings->insalesId]);
        $orders = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $q = http_build_query(['shop' => $settings->shopHost, 'insales_id' => $settings->insalesId]);

        self::renderHead('Заказы — Деловые Линии');
        echo '<h1>Заказы</h1>';
        echo '<p><a href="/insales/app?' . htmlspecialchars($q, ENT_QUOTES, 'UTF-8') . '">← Настройки</a></p>';

        if ($orders === []) {
            echo '<p style="color:#666">Заказов пока нет. Они появятся после оформления покупателем доставки через Деловые Линии.</p>';
            echo '</body></html>';
            return;
        }

        echo '<table style="width:100%;border-collapse:collapse;font-size:.88rem">';
        echo '<thead><tr style="border-bottom:2px solid #e5e5e5;text-align:left">';
        echo '<th style="padding:.5rem">№ заказа</th>';
        echo '<th style="padding:.5rem">Получатель</th>';
        echo '<th style="padding:.5rem">Город / адрес</th>';
        echo '<th style="padding:.5rem">Вес, кг</th>';
        echo '<th style="padding:.5rem">Статус ДЛ</th>';
        echo '<th style="padding:.5rem">Действие</th>';
        echo '</tr></thead><tbody>';

        foreach ($orders as $order) {
            $id         = (int) $order['id'];
            $number     = htmlspecialchars((string) ($order['insales_order_number'] ?? '—'), ENT_QUOTES, 'UTF-8');
            $receiver   = htmlspecialchars((string) ($order['receiver_name'] ?? '—'), ENT_QUOTES, 'UTF-8');
            $phone      = htmlspecialchars((string) ($order['receiver_phone'] ?? ''), ENT_QUOTES, 'UTF-8');
            $city       = htmlspecialchars((string) ($order['arrival_city_name'] ?? '—'), ENT_QUOTES, 'UTF-8');
            $street     = htmlspecialchars((string) ($order['arrival_street'] ?? ''), ENT_QUOTES, 'UTF-8');
            $house      = htmlspecialchars((string) ($order['arrival_house'] ?? ''), ENT_QUOTES, 'UTF-8');
            $weight     = number_format((float) ($order['weight'] ?? 0), 2);
            $dlStatus   = htmlspecialchars((string) ($order['dellin_status_title'] ?? ''), ENT_QUOTES, 'UTF-8');
            $barcode    = htmlspecialchars((string) ($order['dellin_barcode'] ?? ''), ENT_QUOTES, 'UTF-8');
            $requestId  = $order['dellin_request_id'];
            $address    = $city . ($street !== '' ? ', ' . $street : '') . ($house !== '' ? ', ' . $house : '');

            $statusBadge = $requestId
                ? '<span style="color:#0a0">' . ($dlStatus ?: 'Оформлен') . '</span>'
                : '<span style="color:#888">Не оформлен</span>';

            $actionUrl = '/insales/orders/edit?' . $q . '&order_id=' . $id;

            echo '<tr style="border-bottom:1px solid #eee">';
            echo '<td style="padding:.5rem">' . $number . '</td>';
            echo '<td style="padding:.5rem">' . $receiver . '<br><small style="color:#666">' . $phone . '</small></td>';
            echo '<td style="padding:.5rem">' . $address . '</td>';
            echo '<td style="padding:.5rem">' . $weight . '</td>';
            echo '<td style="padding:.5rem">' . $statusBadge . ($barcode ? '<br><small>' . $barcode . '</small>' : '') . '</td>';
            echo '<td style="padding:.5rem"><a href="' . htmlspecialchars($actionUrl, ENT_QUOTES, 'UTF-8') . '" style="color:#3d5afe">Открыть</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</body></html>';
    }

    private static function renderHead(string $title): void
    {
        echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
        echo '<style>';
        echo 'body{font-family:system-ui,sans-serif;max-width:900px;margin:1.5rem auto;padding:0 1rem;color:#1a1a1a}';
        echo 'h1{font-size:1.35rem}';
        echo 'a{color:#3d5afe}';
        echo 'table th{font-weight:600;color:#444}';
        echo '</style></head><body>';
    }

    private static function renderError(string $message): void
    {
        self::renderHead('Ошибка');
        echo '<p style="color:#c00">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></body></html>';
    }
}
