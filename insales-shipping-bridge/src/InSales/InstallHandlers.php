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
button{padding:6px 14px;background:#3d5afe;color:#fff;border:0;border-radius:4px;cursor:pointer;font-size:13px;margin-top:6px}
button:disabled{background:#aaa}
button.green{background:#2e7d32}
input,select{padding:4px 6px;font-size:13px;border:1px solid #ccc;border-radius:4px;width:100%;box-sizing:border-box;margin-top:3px}
label{display:block;margin-top:8px;font-weight:500}
.ok{color:#0a0;margin-top:6px}
.err{color:#c00;margin-top:6px}
.hint{color:#666;font-size:.85em}
#labelForm{margin-top:12px;border-top:1px solid #eee;padding-top:10px;display:none}
#derivalForm{margin-top:8px;border-top:1px solid #eee;padding-top:10px;display:none}
</style>
</head><body>
<div id="app">
  <div id="derivalForm">
    <p class="hint">Забор груза от адреса:</p>
    <label>Дата забора</label>
    <select id="derivalDate"><option value="">Загрузка…</option></select>
    <label>Время приезда экспедитора</label>
    <select id="derivalTime"><option value="">— выберите дату —</option></select>
  </div>
  <button id="btn" onclick="submitOrder()">Оформить в Деловые Линии</button>
  <div id="status"></div>
  <div id="labelForm">
    <p class="hint">Этикетка для груза:</p>
    <label>Артикул грузового места <span class="hint">(необязательно, до 30 символов)</span></label>
    <input type="text" id="cargoPlace" maxlength="30" placeholder="Оставьте пустым — ДЛ подставит номер заявки">
    <label>Формат этикетки</label>
    <select id="labelFormat">
      <option value="80x50">80×50 мм</option>
      <option value="a4">A4</option>
    </select>
    <button class="green" onclick="submitLabels()">Сформировать этикетку</button>
    <div id="labelStatus"></div>
  </div>
</div>
<script>
var insalesId = '{$insalesId}';
var bridgeUrl = '{$url}';
var currentOrderId = null;

function loadDerivalTimeInterval(date) {
  var timeSel = document.getElementById('derivalTime');
  if (!date) { timeSel.innerHTML = '<option value="">— выберите дату —</option>'; return; }
  timeSel.innerHTML = '<option value="">Загрузка…</option>';
  fetch(bridgeUrl + '/insales/derival/time_interval?insales_id=' + insalesId + '&date=' + date, {headers: {Accept: 'application/json'}})
    .then(function(r){ return r.json(); })
    .then(function(j){
      if (!j.ok || !j.interval) { timeSel.innerHTML = '<option value="">Недоступно</option>'; return; }
      var from = j.interval.interval_from || '09:00:00';
      var to = j.interval.interval_to || '18:00:00';
      var fromH = parseInt(from.split(':')[0], 10);
      var toH = parseInt(to.split(':')[0], 10);
      timeSel.innerHTML = '';
      for (var h = fromH; h < toH; h += 2) {
        var startH = h, endH = Math.min(h + 2, toH);
        var opt = document.createElement('option');
        opt.value = String(startH).padStart(2, '0') + ':00-' + String(endH).padStart(2, '0') + ':00';
        opt.textContent = opt.value;
        timeSel.appendChild(opt);
      }
      if (timeSel.children.length === 0) { timeSel.innerHTML = '<option value="">Недоступно</option>'; }
    })
    .catch(function(){ timeSel.innerHTML = '<option value="">Ошибка</option>'; });
}

function loadDerivalDates() {
  var sel = document.getElementById('derivalDate');
  fetch(bridgeUrl + '/insales/derival/dates?insales_id=' + insalesId, {headers: {Accept: 'application/json'}})
    .then(function(r){ return r.json(); })
    .then(function(j){
      if (!j.ok || !j.dates || !j.dates.length) { sel.innerHTML = '<option value="">Нет доступных дат</option>'; return; }
      sel.innerHTML = '';
      j.dates.slice(0, 14).forEach(function(d){
        var opt = document.createElement('option');
        opt.value = d;
        opt.textContent = new Date(d).toLocaleDateString('ru-RU', {day: '2-digit', month: '2-digit', year: 'numeric', weekday: 'short'});
        sel.appendChild(opt);
      });
      loadDerivalTimeInterval(sel.value);
    })
    .catch(function(){ sel.innerHTML = '<option value="">Ошибка загрузки</option>'; });
}

function initDerivalForm() {
  fetch(bridgeUrl + '/insales/orders/preview', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({insales_id: insalesId, insales_order_id: String(window.order_info ? window.order_info.id : '')})
  })
  .then(function(r){ return r.json(); })
  .then(function(d){
    if (d.ok && d.delivery && d.delivery.derival_variant === 'address') {
      document.getElementById('derivalForm').style.display = 'block';
      loadDerivalDates();
      document.getElementById('derivalDate').addEventListener('change', function(){
        loadDerivalTimeInterval(this.value);
      });
    }
  })
  .catch(function(){});
}

if (window.order_info && window.order_info.id) {
  initDerivalForm();
} else {
  setTimeout(initDerivalForm, 500);
}

function submitOrder() {
  var orderId = window.order_info ? window.order_info.id : null;
  if (!orderId) {
    document.getElementById('status').innerHTML = '<p class="err">Не удалось получить ID заказа</p>';
    return;
  }
  currentOrderId = String(orderId);
  var btn = document.getElementById('btn');
  btn.disabled = true;
  btn.textContent = 'Отправка...';
  var derivalDateEl = document.getElementById('derivalDate');
  var derivalTimeEl = document.getElementById('derivalTime');
  fetch(bridgeUrl + '/insales/orders/submit', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({
      insales_id: insalesId,
      insales_order_id: currentOrderId,
      derival_date: derivalDateEl ? (derivalDateEl.value || undefined) : undefined,
      derival_time: derivalTimeEl ? (derivalTimeEl.value || undefined) : undefined
    })
  })
  .then(function(r){ return r.json(); })
  .then(function(data) {
    if (data.ok) {
      document.getElementById('status').innerHTML =
        '<p class="ok">✓ Заявка #' + data.request_id + ' создана</p>';
      btn.textContent = 'Оформлено';
      document.getElementById('labelForm').style.display = 'block';
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

function submitLabels() {
  var cp = document.getElementById('cargoPlace').value.trim();
  var fmt = document.getElementById('labelFormat').value;
  var st = document.getElementById('labelStatus');
  st.innerHTML = '<p class="hint">Формируем этикетку…</p>';
  fetch(bridgeUrl + '/insales/orders/labels', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({
      insales_id: insalesId,
      insales_order_id: currentOrderId,
      action: 'submit',
      cargo_place: cp !== '' ? cp : null,
      format: fmt
    })
  })
  .then(function(r){ return r.json(); })
  .then(function(data) {
    if (!data.ok) throw new Error(data.error || 'Ошибка');
    st.innerHTML = '<p class="hint">Ожидаем готовности этикетки…</p>';
    return pollLabels(0);
  })
  .catch(function(e) {
    st.innerHTML = '<p class="err">Ошибка: ' + e.message + '</p>';
  });
}

function pollLabels(attempt) {
  if (attempt > 10) {
    document.getElementById('labelStatus').innerHTML = '<p class="err">Этикетка не готова. Попробуйте позже.</p>';
    return;
  }
  setTimeout(function() {
    fetch(bridgeUrl + '/insales/orders/labels', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        insales_id: insalesId,
        insales_order_id: currentOrderId,
        action: 'get'
      })
    })
    .then(function(r){ return r.json(); })
    .then(function(data) {
      if (data.ok && data.ready && data.files && data.files.length > 0) {
        var html = '<p class="ok">✓ Этикетка готова:</p>';
        data.files.forEach(function(f) {
          html += '<p><a href="' + f + '" target="_blank">Скачать этикетку</a></p>';
        });
        document.getElementById('labelStatus').innerHTML = html;
      } else {
        pollLabels(attempt + 1);
      }
    })
    .catch(function() { pollLabels(attempt + 1); });
  }, 3000);
}
</script>
</body></html>
HTML;
  }
}
