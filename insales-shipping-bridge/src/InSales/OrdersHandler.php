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

        $requiredAccessToken = $shops->findAccessToken($settings->insalesId);
        if ($requiredAccessToken !== null) {
            $providedAccessToken = trim((string) ($_GET['atk'] ?? $_POST['atk'] ?? ''));
            if ($providedAccessToken === '' || !hash_equals($requiredAccessToken, $providedAccessToken)) {
                http_response_code(403);
                self::renderError('Доступ запрещён.');
                return;
            }
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

        $q = http_build_query(['shop' => $settings->shopHost, 'insales_id' => $settings->insalesId, 'atk' => $requiredAccessToken ?? '']);
        $qWithToken = $q;

        self::renderHead('Заказы — Деловые Линии');
        echo '<h1>Заказы</h1>';
        echo '<p><a href="/insales/app?' . htmlspecialchars($qWithToken, ENT_QUOTES, 'UTF-8') . '">← Настройки</a></p>';

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
            $id        = (int) $order['id'];
            $number    = htmlspecialchars((string) ($order['insales_order_number'] ?? '—'), ENT_QUOTES, 'UTF-8');
            $receiver  = htmlspecialchars((string) ($order['receiver_name'] ?? '—'), ENT_QUOTES, 'UTF-8');
            $phone     = htmlspecialchars((string) ($order['receiver_phone'] ?? ''), ENT_QUOTES, 'UTF-8');
            $city      = htmlspecialchars((string) ($order['arrival_city_name'] ?? '—'), ENT_QUOTES, 'UTF-8');
            $street    = htmlspecialchars((string) ($order['arrival_street'] ?? ''), ENT_QUOTES, 'UTF-8');
            $house     = htmlspecialchars((string) ($order['arrival_house'] ?? ''), ENT_QUOTES, 'UTF-8');
            $weight    = number_format((float) ($order['weight'] ?? 0), 2);
            $dlStatus  = htmlspecialchars((string) ($order['dellin_status_title'] ?? ''), ENT_QUOTES, 'UTF-8');
            $barcode   = htmlspecialchars((string) ($order['dellin_barcode'] ?? ''), ENT_QUOTES, 'UTF-8');
            $requestId = $order['dellin_request_id'];
            $address   = $city . ($street !== '' ? ', ' . $street : '') . ($house !== '' ? ', ' . $house : '');

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

    public static function handleEdit(Config $config, ShopRepository $shops, string $method): void
    {
        header('Content-Type: text/html; charset=utf-8');

        $shopHost  = trim((string) ($_GET['shop'] ?? $_POST['shop'] ?? ''));
        $insalesId = trim((string) ($_GET['insales_id'] ?? $_POST['insales_id'] ?? ''));
        $orderId   = (int) ($_GET['order_id'] ?? $_POST['order_id'] ?? 0);

        if ($orderId <= 0) {
            http_response_code(400);
            self::renderError('Не указан order_id.');
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

        $requiredAccessToken = $shops->findAccessToken($settings->insalesId);
        if ($requiredAccessToken !== null) {
            $providedAccessToken = trim((string) ($_GET['atk'] ?? $_POST['atk'] ?? ''));
            if ($providedAccessToken === '' || !hash_equals($requiredAccessToken, $providedAccessToken)) {
                http_response_code(403);
                self::renderError('Доступ запрещён.');
                return;
            }
        }

        $pdo = Db::pdo($config);

        // Сохранение изменений
        $saved = false;
        $saveError = '';
        if ($method === 'POST') {
            try {
                $stmt = $pdo->prepare('
                    UPDATE dellin_orders SET
                        receiver_name     = :receiver_name,
                        receiver_phone    = :receiver_phone,
                        receiver_email    = :receiver_email,
                        arrival_city_name = :city_name,
                        arrival_city_kladr= :city_kladr,
                        arrival_street    = :street,
                        arrival_house     = :house,
                        arrival_flat      = :flat,
                        weight            = :weight,
                        stated_value      = :stated_value,
                        updated_at        = CURRENT_TIMESTAMP
                    WHERE id = :id AND insales_shop_id = :shop_id
                ');
                $stmt->execute([
                    'receiver_name'  => trim((string) ($_POST['receiver_name'] ?? '')),
                    'receiver_phone' => trim((string) ($_POST['receiver_phone'] ?? '')),
                    'receiver_email' => trim((string) ($_POST['receiver_email'] ?? '')),
                    'city_name'      => trim((string) ($_POST['arrival_city_name'] ?? '')),
                    'city_kladr'     => trim((string) ($_POST['arrival_city_kladr'] ?? '')),
                    'street'         => trim((string) ($_POST['arrival_street'] ?? '')),
                    'house'          => trim((string) ($_POST['arrival_house'] ?? '')),
                    'flat'           => trim((string) ($_POST['arrival_flat'] ?? '')),
                    'weight'         => (float) str_replace(',', '.', (string) ($_POST['weight'] ?? '1')),
                    'stated_value'   => (float) str_replace(',', '.', (string) ($_POST['stated_value'] ?? '0')),
                    'id'             => $orderId,
                    'shop_id'        => $settings->insalesId,
                ]);
                $saved = true;
            } catch (\Throwable $e) {
                $saveError = $e->getMessage();
            }
        }

        // Загружаем заказ
        $stmt = $pdo->prepare('
            SELECT * FROM dellin_orders
            WHERE id = :id AND insales_shop_id = :shop_id
        ');
        $stmt->execute(['id' => $orderId, 'shop_id' => $settings->insalesId]);
        $order = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($order === false) {
            http_response_code(404);
            self::renderError('Заказ не найден.');
            return;
        }

        $q = http_build_query(['shop' => $settings->shopHost, 'insales_id' => $settings->insalesId, 'atk' => $requiredAccessToken ?? '']);
        $number = htmlspecialchars((string) ($order['insales_order_number'] ?? $orderId), ENT_QUOTES, 'UTF-8');

        self::renderHead('Заказ ' . $number . ' — Деловые Линии');
        echo '<h1>Заказ ' . $number . '</h1>';
        echo '<p><a href="/insales/orders?' . htmlspecialchars($q, ENT_QUOTES, 'UTF-8') . '">← Все заказы</a></p>';

        if ($saved) {
            echo '<p class="ok">✓ Изменения сохранены</p>';
        }
        if ($saveError !== '') {
            echo '<p class="err">Ошибка сохранения: ' . htmlspecialchars($saveError, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        // Статус ДЛ
        if ($order['dellin_request_id']) {
            echo '<div style="background:#f0fff0;border:1px solid #c3e6cb;border-radius:6px;padding:.75rem 1rem;margin-bottom:1rem">';
            echo '<strong>Заявка ДЛ:</strong> #' . htmlspecialchars((string) $order['dellin_request_id'], ENT_QUOTES, 'UTF-8');
            if ($order['dellin_barcode']) {
                echo ' &nbsp;|&nbsp; <strong>Штрихкод:</strong> ' . htmlspecialchars((string) $order['dellin_barcode'], ENT_QUOTES, 'UTF-8');
            }
            if ($order['dellin_status_title']) {
                echo ' &nbsp;|&nbsp; <strong>Статус:</strong> ' . htmlspecialchars((string) $order['dellin_status_title'], ENT_QUOTES, 'UTF-8');
            }
            echo '</div>';
        }

        $f = static fn(string $key): string => htmlspecialchars((string) ($order[$key] ?? ''), ENT_QUOTES, 'UTF-8');

        $formAction = '/insales/orders/edit?' . $q . '&order_id=' . $orderId;
        echo '<form method="POST" action="' . htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8') . '">';
        echo '<input type="hidden" name="shop" value="' . htmlspecialchars($settings->shopHost, ENT_QUOTES, 'UTF-8') . '">';
        echo '<input type="hidden" name="insales_id" value="' . htmlspecialchars($settings->insalesId, ENT_QUOTES, 'UTF-8') . '">';
        echo '<input type="hidden" name="atk" value="' . htmlspecialchars($requiredAccessToken ?? '', ENT_QUOTES, 'UTF-8') . '">';
        echo '<input type="hidden" name="order_id" value="' . $orderId . '">';

        echo '<h2>Получатель</h2>';
        echo '<label>Имя <input type="text" name="receiver_name" value="' . $f('receiver_name') . '"></label>';
        echo '<label>Телефон <input type="text" name="receiver_phone" value="' . $f('receiver_phone') . '"></label>';
        echo '<label>Email <input type="text" name="receiver_email" value="' . $f('receiver_email') . '"></label>';

        echo '<h2>Адрес доставки</h2>';
        echo '<label>Город <input type="text" name="arrival_city_name" value="' . $f('arrival_city_name') . '"></label>';
        echo '<label>КЛАДР города <input type="text" name="arrival_city_kladr" value="' . $f('arrival_city_kladr') . '" placeholder="7700000000000000000000000"></label>';
        echo '<label>Улица <input type="text" name="arrival_street" value="' . $f('arrival_street') . '"></label>';
        echo '<label>Дом <input type="text" name="arrival_house" value="' . $f('arrival_house') . '"></label>';
        echo '<label>Квартира <input type="text" name="arrival_flat" value="' . $f('arrival_flat') . '"></label>';

        echo '<h2>Груз</h2>';
        echo '<label>Вес, кг <input type="text" name="weight" value="' . $f('weight') . '"></label>';
        echo '<label>Объявленная стоимость, ₽ <input type="text" name="stated_value" value="' . $f('stated_value') . '"></label>';

        echo '<button type="submit">Сохранить изменения</button>';
        echo '</form>';

        // Кнопка отправки в ДЛ
        if (!$order['dellin_request_id']) {
            $submitUrl = '/insales/orders/submit?' . $q . '&order_id=' . $orderId;
            echo '<form method="POST" action="' . htmlspecialchars($submitUrl, ENT_QUOTES, 'UTF-8') . '" style="margin-top:1.5rem">';
            echo '<input type="hidden" name="shop" value="' . htmlspecialchars($settings->shopHost, ENT_QUOTES, 'UTF-8') . '">';
            echo '<input type="hidden" name="insales_id" value="' . htmlspecialchars($settings->insalesId, ENT_QUOTES, 'UTF-8') . '">';
            echo '<input type="hidden" name="atk" value="' . htmlspecialchars($requiredAccessToken ?? '', ENT_QUOTES, 'UTF-8') . '">';
            echo '<input type="hidden" name="order_id" value="' . $orderId . '">';
            echo '<button type="submit" style="background:#2e7d32">Оформить в Деловые Линии</button>';
            echo '</form>';
        }

        echo '</body></html>';
    }

    private static function renderHead(string $title): void
    {
        echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
        echo '<style>';
        echo 'body{font-family:system-ui,sans-serif;max-width:900px;margin:1.5rem auto;padding:0 1rem;color:#1a1a1a}';
        echo 'h1{font-size:1.35rem}h2{font-size:1rem;margin:1.5rem 0 .5rem;border-bottom:1px solid #e5e5e5;padding-bottom:.35rem}';
        echo 'a{color:#3d5afe}';
        echo 'label{display:block;margin:.6rem 0 .2rem;font-weight:500;font-size:.9rem}';
        echo 'input{width:100%;padding:.5rem;box-sizing:border-box;font-size:14px;border:1px solid #ccc;border-radius:4px}';
        echo 'button{margin-top:1.25rem;padding:.65rem 1.4rem;cursor:pointer;background:#3d5afe;color:#fff;border:0;border-radius:6px;font-size:14px}';
        echo '.ok{color:#0a0}.err{color:#c00}';
        echo 'table th{font-weight:600;color:#444}';
        echo '</style></head><body>';
    }

    private static function renderError(string $message): void
    {
        self::renderHead('Ошибка');
        echo '<p style="color:#c00">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></body></html>';
    }
}
