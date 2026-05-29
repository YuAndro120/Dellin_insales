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
<style>body{margin:0;padding:8px;font-family:system-ui,sans-serif}button{width:100%;padding:9px;background:#f60;color:#fff;border:0;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer}button:disabled{background:#aaa}.ok{color:#2e7d32;font-size:12px;margin-top:4px}.err{color:#c00;font-size:12px;margin-top:4px}</style>
</head><body>
<button id="btn" onclick="go()">📦 Оформить в Деловые Линии</button>
<div id="st"></div>
<script>
var B='{$url}',I='{$insalesId}',P=window.parent;
function go(){
  var id=window.order_info?window.order_info.id:null;
  if(!id){document.getElementById('st').innerHTML='<p class="err">ID заказа не найден</p>';return;}
  fetch(B+'/insales/modal?insales_id='+I+'&order_id='+id)
  .then(function(r){return r.text();})
  .then(function(html){
    var d=P.document,el=d.getElementById('dl-modal-root');
    if(!el){el=d.createElement('div');el.id='dl-modal-root';d.body.appendChild(el);}
    el.innerHTML=html;
    var s=d.createElement('script');
    s.textContent='window.__dlInit&&window.__dlInit("'+B+'","'+I+'","'+id+'")';
    d.body.appendChild(s);
  })
  .catch(function(e){document.getElementById('st').innerHTML='<p class="err">'+e.message+'</p>';});
}
</script>
</body></html>
HTML;
  }
}