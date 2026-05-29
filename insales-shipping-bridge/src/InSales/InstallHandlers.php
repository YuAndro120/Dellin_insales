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
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,sans-serif;background:#fff;font-size:13px}
#wrap{padding:8px}
button{width:100%;padding:9px;background:#f5501e;color:#fff;border:0;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;transition:background .2s}
button:hover{background:#e04418}
button:disabled{background:#aaa}
.ok{color:#16a34a;font-size:12px;margin-top:4px}
.err{color:#c00;font-size:12px;margin-top:4px}
#modal-wrap{display:none;margin-top:8px}
</style>
</head><body>
<div id="wrap">
  <button id="btn" onclick="go()">📦 Оформить в Деловые Линии</button>
  <div id="st"></div>
  <div id="modal-wrap">
    <iframe id="modal-frame" src="" style="width:100%;border:0;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.12)" scrolling="no"></iframe>
  </div>
</div>
<script>
var B='{$url}',I='{$insalesId}';
function go(){
  var id=window.order_info?window.order_info.id:null;
  if(!id){document.getElementById('st').innerHTML='<p class="err">ID заказа не найден</p>';return;}
  var btn=document.getElementById('btn');
  btn.disabled=true;btn.textContent='Загрузка…';
  var wrap=document.getElementById('modal-wrap');
  var frame=document.getElementById('modal-frame');
  frame.src=B+'/insales/modal?insales_id='+I+'&order_id='+id;
  frame.onload=function(){
    wrap.style.display='block';
    btn.style.display='none';
    document.getElementById('st').innerHTML='';
  };
}
window.addEventListener('message',function(e){
  if(e.data&&e.data.dlAction==='close'){
    document.getElementById('modal-wrap').style.display='none';
    document.getElementById('btn').style.display='block';
    document.getElementById('btn').disabled=false;
    document.getElementById('btn').textContent='📦 Оформить в Деловые Линии';
  }
  if(e.data&&e.data.dlAction==='resize'){
    document.getElementById('modal-frame').style.height=e.data.height+'px';
  }
  if(e.data&&e.data.dlAction==='success'){
    document.getElementById('btn').textContent='✓ Заявка #'+e.data.requestId+' оформлена';
    document.getElementById('btn').style.background='#16a34a';
    document.getElementById('btn').disabled=false;
    document.getElementById('modal-wrap').style.display='none';
    document.getElementById('btn').style.display='block';
  }
});
</script>
</body></html>
HTML;
    }
}