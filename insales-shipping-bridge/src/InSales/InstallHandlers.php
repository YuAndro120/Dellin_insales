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

        $webhookUrl = rtrim($config->publicBridgeUrl, '/') . '/insales/webhook/orders';
        foreach (['orders/create', 'orders/update'] as $topic) {
            try {
                $client->registerWebhook($shop, $config->insalesAppId ?? '', $apiPassword, $topic, $webhookUrl);
            } catch (\Throwable) {
            }
        }

        try {
            $client->registerWidget(
                $shop,
                $config->insalesAppId ?? '',
                $apiPassword,
                self::buildWidgetCode($config->publicBridgeUrl, $insalesId),
            );
        } catch (\Throwable) {
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
<style>body{margin:0;padding:8px;font-family:-apple-system,sans-serif}button{width:100%;padding:9px;background:#f5501e;color:#fff;border:0;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer}button:hover{background:#e04418}button:disabled{background:#aaa}.err{color:#c00;font-size:12px;margin-top:4px}.ok{color:#16a34a;font-size:12px;margin-top:4px}</style>
</head><body>
<button id="btn" onclick="go()">📦 Оформить в Деловые Линии</button>
<div id="st"></div>
<script>
var B='{$url}',I='{$insalesId}';
function go(){
  var id=window.order_info?window.order_info.id:null;
  if(!id){document.getElementById('st').innerHTML='<p class="err">ID заказа не найден</p>';return;}
  window.open(B+'/insales/modal?insales_id='+I+'&order_id='+id,'_blank','width=620,height=820,resizable=yes');
}
window.addEventListener('message',function(e){
  if(e.data&&e.data.dlAction==='success'){
    document.getElementById('btn').textContent='✓ Заявка #'+e.data.requestId+' оформлена';
    document.getElementById('btn').style.background='#16a34a';
    document.getElementById('btn').disabled=false;
  }
});
</script>
</body></html>
HTML;
    }
}
