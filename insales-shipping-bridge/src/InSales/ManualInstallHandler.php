<?php

declare(strict_types=1);

namespace ShippingBridge\InSales;

use ShippingBridge\Config;
use ShippingBridge\ShopRepository;

/**
 * Ручная установка, когда inSales не даёт ссылку /admin/applications/.../install
 * (часто для неопубликованных приложений).
 *
 * Защита: MANUAL_INSTALL_SECRET в .env (или BRIDGE_SECRET, если MANUAL не задан).
 */
final class ManualInstallHandler
{
    public static function handle(Config $config, ShopRepository $shops, string $method): void
    {
        header('Content-Type: text/html; charset=utf-8');
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        if ($config->insalesAppSecret === null || $config->insalesAppSecret === '') {
            http_response_code(500);
            echo '<p>INSALES_APP_SECRET не задан в .env</p>';
            return;
        }

        $expectedSecret = trim((string) (getenv('MANUAL_INSTALL_SECRET') ?: $config->bridgeSecret));
        if ($expectedSecret === '') {
            http_response_code(503);
            echo '<p>Задайте MANUAL_INSTALL_SECRET или BRIDGE_SECRET в .env для ручной установки.</p>';
            return;
        }

        if ($method === 'POST') {
            $key = trim((string) ($_POST['secret'] ?? ''));
            $shop = trim((string) ($_POST['shop'] ?? ''));
            $insalesId = trim((string) ($_POST['insales_id'] ?? ''));
            if (!hash_equals($expectedSecret, $key)) {
                http_response_code(403);
                echo '<p>Неверный секрет.</p>';
                return;
            }
            if ($shop === '' || $insalesId === '') {
                http_response_code(400);
                echo '<p>Укажите shop и insales_id.</p>';
                return;
            }
            $token = bin2hex(random_bytes(16));
            $apiPassword = md5($token . $config->insalesAppSecret);
            $shops->upsertOnInstall($insalesId, $shop, $apiPassword);
            $q = http_build_query(['shop' => $shop, 'insales_id' => $insalesId]);
            header('Location: /insales/app?' . $q, true, 302);
            exit;
        }

        $secret = trim((string) ($_GET['secret'] ?? ''));
        if ($secret === '' || !hash_equals($expectedSecret, $secret)) {
            http_response_code(403);
            echo '<p>Доступ: /insales/manual-install?secret=ВАШ_MANUAL_INSTALL_SECRET</p>';
            return;
        }

        echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><title>Ручная установка</title>';
        echo '<style>body{font-family:system-ui;max-width:520px;margin:2rem auto;padding:0 1rem}';
        echo 'label{display:block;margin:.75rem 0 .25rem}input{width:100%;padding:.5rem;box-sizing:border-box}';
        echo 'button{margin-top:1rem;padding:.6rem 1.2rem}.hint{font-size:.9rem;color:#555}</style></head><body>';
        echo '<h1>Ручная установка приложения</h1>';
        echo '<p class="hint">Используйте, если ссылки inSales /admin/applications/.../install не работают.</p>';
        echo '<form method="post">';
        echo '<input type="hidden" name="secret" value="' . $h($secret) . '">';
        echo '<label>shop (хост магазина)</label>';
        echo '<input name="shop" required placeholder="myshop-ddy891.myinsales.ru" value="myshop-ddy891.myinsales.ru">';
        echo '<label>insales_id (account_id магазина)</label>';
        echo '<input name="insales_id" required placeholder="число из account.json">';
        echo '<p class="hint">Как узнать id: войдите в админку магазина и откройте<br>';
        echo '<code>/admin/account.json</code> — поле <code>id</code>.</p>';
        echo '<button type="submit">Установить и открыть настройки</button>';
        echo '</form></body></html>';
    }
}
