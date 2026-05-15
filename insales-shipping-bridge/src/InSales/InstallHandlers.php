<?php

declare(strict_types=1);

namespace ShippingBridge\InSales;

use ShippingBridge\Config;
use ShippingBridge\ShopRepository;

/**
 * Точки входа протокола установки приложения inSales (HTML + HTTP 200).
 */
final class InstallHandlers
{
    public static function install(Config $config, ShopRepository $shops): void
    {
        header('Content-Type: text/html; charset=utf-8');

        if ($config->insalesAppSecret === null || $config->insalesAppSecret === '') {
            http_response_code(500);
            echo '<p>Не задан INSALES_APP_SECRET в .env</p>';
            return;
        }

        $token = (string) ($_GET['token'] ?? '');
        $shop = trim((string) ($_GET['shop'] ?? ''));
        $insalesId = trim((string) ($_GET['insales_id'] ?? ''));

        if ($token === '' || $shop === '' || $insalesId === '') {
            http_response_code(400);
            echo '<p>Ожидаются параметры token, shop, insales_id</p>';
            return;
        }

        $apiPassword = md5($token . $config->insalesAppSecret);
        $shops->upsertOnInstall($insalesId, $shop, $apiPassword);

        http_response_code(200);
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Установка</title></head><body>';
        echo '<p>Приложение установлено. Магазин: ' . htmlspecialchars($shop, ENT_QUOTES, 'UTF-8') . '</p>';
        echo '<p><a href="/insales/app">Страница приложения</a></p>';
        echo '</body></html>';
    }

    public static function appPage(): void
    {
        header('Content-Type: text/html; charset=utf-8');
        http_response_code(200);
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Доставка</title></head><body>';
        echo '<h1>Интеграция доставки</h1>';
        echo '<p>JSON API бриджа: <code>/v1/calculate-from-variants</code>, <code>/v1/terminals</code> и др.</p>';
        echo '<p>Виджет карты: <a href="/widget/index.html">/widget/index.html</a></p>';
        echo '</body></html>';
    }

    public static function uninstall(ShopRepository $shops): void
    {
        header('Content-Type: text/html; charset=utf-8');
        $insalesId = trim((string) ($_GET['insales_id'] ?? ''));
        if ($insalesId === '') {
            http_response_code(400);
            echo '<p>Ожидается insales_id</p>';
            return;
        }
        $shops->markUninstalled($insalesId);
        http_response_code(200);
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Удалено</title></head><body><p>Приложение отключено для магазина.</p></body></html>';
    }
}
