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
        $apiPassword = InSalesApiPassword::compute($token, $config->insalesAppSecret);
        $shops->upsertOnInstall($insalesId, $shop, $apiPassword);

        $client = new InSalesClient();

        // Регистрируем webhook на создание и обновление заказов
        $webhookUrl = rtrim($config->publicBridgeUrl, '/') . '/insales/webhook/orders';
        foreach (['orders/create', 'orders/update'] as $topic) {
            try {
                $client->registerWebhook($shop, $config->insalesAppId ?? '', $apiPassword, $topic, $webhookUrl);
            } catch (\Throwable) {
                // Не блокируем установку если webhook уже существует или ошибка
            }
        }

        // Регистрируем виджет в карточке заказа
        try {
            $client->registerWidget(
                $shop,
                $config->insalesAppId ?? '',
                $apiPassword,
                self::buildWidgetCode($config->publicBridgeUrl, $insalesId),
            );
        } catch (\Throwable) {
            // Не блокируем установку если виджет уже существует
        }

        http_response_code(200);
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Установка</title></head><body>';
        $q = http_build_query(['shop' => $shop, 'insales_id' => $insalesId]);
        echo '<p>Приложение установлено. Магазин: ' . htmlspecialchars($shop, ENT_QUOTES, 'UTF-8') . '</p>';
        echo '<p><a href="/insales/app?' . htmlspecialchars($q, ENT_QUOTES, 'UTF-8') . '">Перейти к настройкам (терминал отгрузки)</a></p>';
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

    private static function buildWidgetCode(string $bridgeUrl, string $insalesId): string
    {
        $url = rtrim($bridgeUrl, '/');
        return <<<HTML
<html><head><meta charset="utf-8">
<style>
body{font-family:system-ui,sans-serif;margin:0;padding:8px;font-size:13px}
button{padding:6px 14px;background:#3d5afe;color:#fff;border:0;border-radius:4px;cursor:pointer;font-size:13px}
button:disabled{background:#aaa}
.ok{color:#0a0;margin-top:6px}
.err{color:#c00;margin-top:6px}
</style>
</head><body>
<div id="app">
  <button id="btn" onclick="submitOrder()">Оформить в Деловые Линии</button>
  <div id="status"></div>
</div>
<script>
var insalesId = '{$insalesId}';
var bridgeUrl = '{$url}';

function submitOrder() {
  var orderId = window.order_info ? window.order_info.id : null;
  if (!orderId) {
    document.getElementById('status').innerHTML = '<p class="err">Не удалось получить ID заказа</p>';
    return;
  }
  var btn = document.getElementById('btn');
  btn.disabled = true;
  btn.textContent = 'Отправка...';

  fetch(bridgeUrl + '/insales/orders/submit', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({insales_id: insalesId, insales_order_id: String(orderId)})
  })
  .then(function(r){ return r.json(); })
  .then(function(data) {
    if (data.ok) {
      document.getElementById('status').innerHTML =
        '<p class="ok">✓ Заявка создана. Штрихкод: <b>' + data.barcode + '</b></p>';
      btn.textContent = 'Оформлено';
    } else {
      document.getElementById('status').innerHTML = '<p class="err">Ошибка: ' + data.error + '</p>';
      btn.disabled = false;
      btn.textContent = 'Оформить в Деловые Линии';
    }
  })
  .catch(function() {
    document.getElementById('status').innerHTML = '<p class="err">Ошибка сети</p>';
    btn.disabled = false;
    btn.textContent = 'Оформить в Деловые Линии';
  });
}
</script>
</body></html>
HTML;
    }
}