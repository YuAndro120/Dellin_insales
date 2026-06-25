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

    $subscriptions = new \ShippingBridge\SubscriptionRepository(\ShippingBridge\Db::pdo($config));
    $subscriptions->ensureTrialSubscription($insalesId);

    $client = new InSalesClient();

    // Регистрируем webhook на создание и обновление заказов
    $webhookSecret = $shops->findWebhookSecret($insalesId) ?? '';
    $webhookUrl = rtrim($config->publicBridgeUrl, '/') . '/insales/webhook/orders'
      . ($webhookSecret !== '' ? '?wsk=' . $webhookSecret : '');
    foreach (['orders/create', 'orders/update'] as $topic) {
      try {
        $client->registerWebhook($shop, $config->insalesAppId ?? '', $apiPassword, $topic, $webhookUrl);
      } catch (\Throwable) {
        // Не блокируем установку если webhook уже существует или ошибка
      }
    }

    // Регистрируем виджет в карточке заказа: обновляем существующий,
    // если он уже был создан ранее (widget_id сохранён), иначе создаём
    // новый и запоминаем его id — это предотвращает накопление
    // дубликатов виджета при каждой переустановке приложения.
    try {
      $widgetCode = self::buildWidgetCode($config->publicBridgeUrl, $insalesId);
      $existingWidgetId = $shops->findWidgetId($insalesId);

      if ($existingWidgetId !== null) {
        $client->updateWidget($shop, $config->insalesAppId ?? '', $apiPassword, $existingWidgetId, $widgetCode, 300);
      } else {
        $newWidgetId = $client->registerWidget($shop, $config->insalesAppId ?? '', $apiPassword, $widgetCode, 300);
        if ($newWidgetId > 0) {
          $shops->saveWidgetId($insalesId, $newWidgetId);
        }
      }
    } catch (\Throwable) {
      // Не блокируем установку при сбое регистрации/обновления виджета
    }

    // Создаём способы доставки ДЛ (update-or-create — дублей не будет).
    $deliverySetup = new InSalesDeliverySetup($client, $config);
    try {
      $deliverySetup->createPickUpDeliveryVariant($shop, $apiPassword);
    } catch (\Throwable) {
      // Не блокируем установку при сбое создания способа доставки «терминал»
    }
    try {
      $deliverySetup->createCourierDeliveryVariant($shop, $apiPassword);
    } catch (\Throwable) {
      // Не блокируем установку при сбое создания способа доставки «курьер»
    }

    // Создаём собственное поле заказа под трек-номер ДЛ.
    try {
      if ($shops->findOrderFieldId($insalesId) === null) {
        $field = $client->createOrderField(
          $shop,
          $config->insalesAppId ?? '',
          $apiPassword,
          'Трек-номер Деловые Линии'
        );
        if ($field['id'] > 0) {
          $shops->saveOrderFieldId($insalesId, $field['id']);
        }
      }
    } catch (\Throwable) {
      // Не блокируем установку при сбое создания поля
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
<link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Instrument Sans',system-ui,sans-serif;background:#f5f3f0;color:#1a1714;font-size:13px;padding:10px}
.card{background:#fff;border:1px solid #e4dfd8;border-radius:10px;padding:12px 14px;margin-bottom:8px}
.card-title{font-size:10px;font-weight:600;color:#8c8580;text-transform:uppercase;letter-spacing:.08em;margin-bottom:10px}
.field{margin-bottom:10px}
.field:last-child{margin-bottom:0}
.field label{display:block;font-size:10px;font-weight:600;color:#4a4540;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px}
.field select,.field input{width:100%;padding:7px 10px;background:#f9f8f6;border:1px solid #e4dfd8;border-radius:7px;font-size:13px;color:#1a1714;font-family:inherit;outline:none;transition:border .15s;-webkit-appearance:none}
.field select:focus,.field input:focus{border-color:#f5501e;background:#fff}
.btn{width:100%;padding:10px;background:#f5501e;color:#fff;border:0;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:background .15s}
.btn:hover{background:#c73e12}
.btn:disabled{background:#d4cfc9;cursor:not-allowed}
.btn-grn{background:#14864a}.btn-grn:hover{background:#0f6438}
.btn-row{display:flex;gap:6px;margin-top:0}
.btn-row .btn{flex:1}
.ok{display:flex;align-items:center;gap:8px;padding:9px 12px;background:#edfaf3;border:1px solid #c6f0d8;border-radius:8px;color:#14864a;font-size:12px;font-weight:500}
.err{padding:9px 12px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;color:#b91c1c;font-size:12px}
.preview-row{display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid #f2efeb;font-size:12px}
.preview-row:last-child{border-bottom:0}
.preview-lbl{color:#8c8580}
.preview-val{color:#1a1714;font-weight:500;text-align:right;max-width:60%}
#labelForm{display:none}
#derivalForm{display:none}
#previewBlock{display:none}
#confirmBlock{display:none}
</style>
</head><body>

<div id="derivalForm" class="card">
  <div class="card-title">Забор груза от адреса</div>
  <div class="field">
    <label>Дата забора</label>
    <select id="derivalDate"><option value="">Загрузка…</option></select>
  </div>
  <div class="field">
    <label>Время приезда экспедитора</label>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
      <input type="time" id="derivalTimeFrom" style="width:100%;padding:7px 10px;background:#f9f8f6;border:1px solid #e4dfd8;border-radius:7px;font-size:13px;font-family:inherit;outline:none">
      <input type="time" id="derivalTimeTo" style="width:100%;padding:7px 10px;background:#f9f8f6;border:1px solid #e4dfd8;border-radius:7px;font-size:13px;font-family:inherit;outline:none">
    </div>
    <div style="font-size:10px;color:#8c8580;margin-top:4px">В среднем минимальный интервал — 4 часа</div>
  </div>
</div>

<div id="previewBlock" class="card">
  <div class="card-title">Данные для отправки в ДЛ</div>
  <div id="previewContent"></div>
</div>

<div id="status" style="margin-bottom:8px"></div>

<button id="btn" class="btn" onclick="showPreview()">Оформить в Деловые Линии</button>

<div id="confirmBlock" class="btn-row" style="margin-top:8px">
  <button class="btn btn-grn" onclick="submitOrder()">✓ Подтвердить</button>
  <button class="btn" style="background:#6b6963" onclick="cancelPreview()">Отмена</button>
</div>

<div id="labelForm" class="card" style="margin-top:8px">
  <div class="card-title">Этикетка для груза</div>
  <div class="field">
    <label>Артикул грузового места <span style="font-weight:400;text-transform:none;letter-spacing:0"></span></label>
    <input type="text" id="cargoPlace" maxlength="30" placeholder="Не обязательно. Максимум 30 символов">
  </div>
  <div class="field">
    <label>Формат этикетки</label>
    <select id="labelFormat">
      <option value="80x50">80×50 мм</option>
      <option value="a4">A4</option>
    </select>
  </div>
  <button class="btn btn-grn" onclick="submitLabels()" style="margin-top:4px">Сформировать этикетку</button>
  <div id="labelStatus" style="margin-top:8px"></div>
</div>

<script>
var insalesId = '{$insalesId}';
var bridgeUrl = '{$url}';
var currentOrderId = null;
var previewData = null;

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
    if (!d.ok) return;
    previewData = d;
    if (d.delivery && d.delivery.derival_variant === 'address') {
      document.getElementById('derivalForm').style.display = 'block';
      loadDerivalDates();
      // Подставляем дефолтное время из настроек
      if (d.delivery.derival_time_from) {
        document.getElementById('derivalTimeFrom').value = d.delivery.derival_time_from;
      }
      if (d.delivery.derival_time_to) {
        document.getElementById('derivalTimeTo').value = d.delivery.derival_time_to;
      }
    }
  })
  .catch(function(){});
}

function showPreview() {
  var orderId = window.order_info ? window.order_info.id : null;
  if (!orderId) {
    document.getElementById('status').innerHTML = '<div class="err">Не удалось получить ID заказа</div>';
    return;
  }
  var btn = document.getElementById('btn');
  btn.disabled = true;
  btn.textContent = 'Загрузка…';

  fetch(bridgeUrl + '/insales/orders/preview', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({insales_id: insalesId, insales_order_id: String(orderId)})
  })
  .then(function(r){ return r.json(); })
  .then(function(d){
    btn.disabled = false;
    btn.textContent = 'Оформить в Деловые Линии';
    if (!d.ok) {
      document.getElementById('status').innerHTML = '<div class="err">Ошибка: ' + (d.error || 'неизвестная ошибка') + '</div>';
      return;
    }
    previewData = d;
    var r = d.receiver || {};
    var c = d.cargo || {};
    var addr = [r.city, r.street, r.house ? 'д.' + r.house : '', r.flat ? 'кв.' + r.flat : ''].filter(Boolean).join(', ');
    var rows = [
      ['Получатель', r.name || '—'],
      ['Телефон', r.phone || '—'],
      ['Адрес', addr || '—'],
      ['Вес', (c.weight || '—') + ' кг'],
      ['Объявл. стоимость', (c.stated_value ? c.stated_value + ' ₽' : '—')],
    ];
    if (d.delivery && d.delivery.produce_date) rows.push(['Дата отгрузки', d.delivery.produce_date]);
    var html = rows.map(function(row){
      return '<div class="preview-row"><span class="preview-lbl">' + row[0] + '</span><span class="preview-val">' + row[1] + '</span></div>';
    }).join('');
    document.getElementById('previewContent').innerHTML = html;
    document.getElementById('previewBlock').style.display = 'block';
    document.getElementById('confirmBlock').style.display = 'flex';
    document.getElementById('status').innerHTML = '';
    btn.style.display = 'none';
  })
  .catch(function(){
    btn.disabled = false;
    btn.textContent = 'Оформить в Деловые Линии';
    document.getElementById('status').innerHTML = '<div class="err">Ошибка сети</div>';
  });
}

function cancelPreview() {
  document.getElementById('previewBlock').style.display = 'none';
  document.getElementById('confirmBlock').style.display = 'none';
  document.getElementById('btn').style.display = '';
  document.getElementById('status').innerHTML = '';
}

if (window.order_info && window.order_info.id) {
  initDerivalForm();
} else {
  setTimeout(initDerivalForm, 500);
}

function submitOrder() {
  var orderId = window.order_info ? window.order_info.id : null;
  if (!orderId) {
    document.getElementById('status').innerHTML = '<div class="err">Не удалось получить ID заказа</div>';
    return;
  }
  currentOrderId = String(orderId);
  document.getElementById('confirmBlock').style.display = 'none';
  document.getElementById('previewBlock').style.display = 'none';
  var btn = document.getElementById('btn');
  btn.disabled = true;
  btn.textContent = 'Отправка…';
  btn.style.display = '';
  var derivalDateEl = document.getElementById('derivalDate');
  var derivalTimeEl = document.getElementById('derivalTime');
  fetch(bridgeUrl + '/insales/orders/submit', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({
      insales_id: insalesId,
      insales_order_id: currentOrderId,
      derival_date: derivalDateEl ? (derivalDateEl.value || undefined) : undefined,
      derival_time: (function(){
        var f = document.getElementById('derivalTimeFrom');
        var t = document.getElementById('derivalTimeTo');
        if (f && t && f.value && t.value) return f.value + '-' + t.value;
        return undefined;
      })()
    })
  })
  .then(function(r){ return r.json(); })
  .then(function(data) {
    if (data.ok) {
      document.getElementById('status').innerHTML = '<div class="ok">✓ Заявка #' + data.request_id + ' создана</div>';
      btn.textContent = 'Оформлено';
      document.getElementById('labelForm').style.display = 'block';
    } else {
      document.getElementById('status').innerHTML = '<div class="err">Ошибка: ' + data.error + '</div>';
      btn.disabled = false;
      btn.textContent = 'Оформить в Деловые Линии';
    }
  })
  .catch(function() {
    document.getElementById('status').innerHTML = '<div class="err">Ошибка сети</div>';
    btn.disabled = false;
    btn.textContent = 'Оформить в Деловые Линии';
  });
}

function submitLabels() {
  var cp = document.getElementById('cargoPlace').value.trim();
  var fmt = document.getElementById('labelFormat').value;
  var st = document.getElementById('labelStatus');
  st.innerHTML = '<p style="font-size:12px;color:#8c8580">Формируем этикетку…</p>';
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
    st.innerHTML = '<p style="font-size:12px;color:#8c8580">Ожидаем готовности этикетки…</p>';
    setTimeout(function(){ pollLabels(0); }, 3000);
  })
  .catch(function(e) {
    st.innerHTML = '<div class="err">Ошибка: ' + e.message + '</div>';
  });
}

function pollLabels(attempt) {
  var st = document.getElementById('labelStatus');
  if (attempt > 10) { st.innerHTML = '<div class="err">Этикетка не готова. Попробуйте позже.</div>'; return; }
  setTimeout(function() {
    fetch(bridgeUrl + '/insales/orders/labels', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({insales_id: insalesId, insales_order_id: currentOrderId, action: 'get'})
    })
    .then(function(r){ return r.json(); })
    .then(function(data) {
      if (data.ok && data.ready && data.files && data.files.length > 0) {
        var links = data.files.map(function(f){ return '<a href="' + (f.url||f) + '" target="_blank" style="color:#f5501e;font-size:12px">' + (f.name||'Скачать этикетку') + '</a>'; }).join(' · ');
        st.innerHTML = '<div class="ok">✓ Готово: ' + links + '</div>';
      } else {
        pollLabels(attempt + 1);
      }
    })
    .catch(function() { pollLabels(attempt + 1); });
  }, 3000);
}
</script>
<div class="card" style="margin-top:8px;background:#f9f8f6;border-color:#e4dfd8">
  <div style="font-size:11px;color:#8c8580;line-height:1.6">
    <div style="margin-bottom:6px">⚙️ <strong style="color:#4a4540">Параметры отправителя и терминал</strong> — настраиваются в разделе «Приложения → ДЛ Коннект» в панели управления магазином.</div>
    <div>📦 <strong style="color:#4a4540">Адрес и данные получателя</strong> — берутся из заказа. Для изменения откройте страницу заказа.</div>
  </div>
</div>
</body></html>
HTML;
  }
}
