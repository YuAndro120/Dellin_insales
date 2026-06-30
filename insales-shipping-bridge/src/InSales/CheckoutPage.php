<?php

declare(strict_types=1);

namespace ShippingBridge\InSales;

use ShippingBridge\Config;
use ShippingBridge\ShopRepository;
use ShippingBridge\SubscriptionRepository;

/**
 * Страница оплаты тарифа: выбор периода (1/12 мес), способа оплаты (карта/счёт),
 * автопродление. POST уходит на /insales/billing (карта) или /insales/billing/invoice (счёт).
 */
final class CheckoutPage
{
  private const PLANS = [
    SubscriptionRepository::PLAN_CALC_ONLY => [
      'label'    => 'Старт',
      'price'    => 999,
      'features' => [
        'Расчёт стоимости со скидками ДЛ в корзине',
        'Сроки доставки для покупателя',
        'Все типы доставки ДЛ',
      ],
    ],
    SubscriptionRepository::PLAN_FULL => [
      'label'    => 'Полный',
      'price'    => 1999,
      'features' => [
        'Всё из тарифа «Старт»',
        'Оформление заявки в ДЛ из админки',
        'Терминалы, забор от адреса, расписание',
        'Полные настройки отправителя',
        'Трек-номер автоматически в заказ',
      ],
    ],
  ];

  private const ANNUAL_DISCOUNT = 0.20; // 20% скидка при оплате за год

  public static function handle(Config $config, ShopRepository $shops): void
  {
    header('Content-Type: text/html; charset=utf-8');

    $plan      = trim((string) ($_GET['plan']       ?? ''));
    $insalesId = trim((string) ($_GET['insales_id'] ?? ''));
    $shopHost  = trim((string) ($_GET['shop']       ?? ''));
    $atk       = trim((string) ($_GET['atk']        ?? ''));

    $landingUrl = rtrim($config->landingUrl ?? 'https://receptly.ru', '/');

    $paid = $_GET['paid'] ?? null;
    if ($paid === '1') {
      self::renderSuccess(self::PLANS[$plan] ?? [], $insalesId, $shopHost, $landingUrl);
      return;
    }
    if ($paid === '0') {
      self::renderFail($plan, $insalesId, $shopHost, $atk);
      return;
    }

    if (!isset(self::PLANS[$plan])) {
      http_response_code(404);
      echo '<p style="font-family:sans-serif;padding:40px">Тариф не найден.</p>';
      return;
    }

    $planInfo   = self::PLANS[$plan];
    $monthPrice = $planInfo['price'];
    $yearPrice  = (int) round($monthPrice * 12 * (1 - self::ANNUAL_DISCOUNT));

    $h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

    $billingUrl  = rtrim($config->publicBridgeUrl ?? '', '/') . '/insales/billing';
    $invoiceUrl  = rtrim($config->publicBridgeUrl ?? '', '/') . '/insales/billing/invoice';

    echo <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Оформление подписки — ДЛ Коннект</title>
  <link href="/fonts/fonts.css" rel="stylesheet">
    :root {
      --bg:         #16181c;
      --bg-soft:    #1c1f24;
      --bg-card:    #1f2228;
      --bg-input:   #252830;
      --line:       #2c3038;
      --line-soft:  #24272e;
      --ink:        #f2efe9;
      --ink-dim:    #a8a59d;
      --ink-faint:  #6b6963;
      --amber:      #e8742c;
      --amber-soft: #3a2a1c;
      --green:      #5fb88a;
      --green-soft: #1a2b22;
      --display:    'Space Grotesk', sans-serif;
      --sans:       'Inter', sans-serif;
      --mono:       'IBM Plex Mono', monospace;
      --radius:     12px;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0 }
    html { scroll-behavior: smooth }
    body { font-family: var(--sans); background: var(--bg); color: var(--ink); min-height: 100vh; -webkit-font-smoothing: antialiased }

    /* NAV */
    .nav { border-bottom: 1px solid var(--line-soft); padding: 16px 24px; display: flex; align-items: center; gap: 12px }
    .nav-brand { font-family: var(--display); font-weight: 600; font-size: 17px; text-decoration: none; color: var(--ink); display: flex; align-items: center; gap: 8px }
    .nav-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--amber) }
    .nav-sep { color: var(--line); font-size: 20px; font-weight: 200 }
    .nav-step { font-size: 13px; color: var(--ink-faint); font-family: var(--mono) }

    /* LAYOUT */
    .checkout-wrap { max-width: 960px; margin: 0 auto; padding: 48px 24px; display: grid; grid-template-columns: 1fr 1fr; gap: 40px; align-items: start }
    @media (max-width: 720px) { .checkout-wrap { grid-template-columns: 1fr; padding: 32px 16px } }

    /* LEFT — PLAN SUMMARY */
    .plan-summary { position: sticky; top: 32px }
    .plan-label-tag { font-family: var(--mono); font-size: 11px; color: var(--amber); text-transform: uppercase; letter-spacing: .08em; margin-bottom: 16px }
    .plan-title { font-family: var(--display); font-size: 28px; font-weight: 600; letter-spacing: -0.02em; margin-bottom: 8px }
    .plan-price-block { margin: 20px 0; padding: 20px; background: var(--bg-card); border: 1px solid var(--line); border-radius: var(--radius) }
    .plan-price-row { display: flex; align-items: baseline; gap: 6px }
    .plan-price-amount { font-family: var(--mono); font-size: 36px; font-weight: 600; color: var(--ink) }
    .plan-price-period { font-size: 14px; color: var(--ink-faint) }
    .plan-price-detail { font-size: 12.5px; color: var(--ink-faint); margin-top: 6px; font-family: var(--mono) }
    .plan-price-discount { display: inline-block; background: var(--green-soft); color: var(--green); font-size: 11px; font-family: var(--mono); padding: 2px 8px; border-radius: 20px; margin-top: 8px }
    .plan-features { list-style: none; margin-top: 24px; display: flex; flex-direction: column; gap: 0 }
    .plan-features li { display: flex; align-items: flex-start; gap: 10px; font-size: 14px; color: var(--ink-dim); padding: 11px 0; border-top: 1px solid var(--line-soft) }
    .plan-features li:first-child { border-top: 0; padding-top: 0 }
    .plan-check { color: var(--green); flex-shrink: 0; font-size: 13px; margin-top: 1px }
    .trial-badge { display: inline-flex; align-items: center; gap: 6px; margin-top: 20px; font-size: 12.5px; color: var(--green); font-family: var(--mono) }
    .trial-badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: var(--green); flex-shrink: 0 }

    /* RIGHT — PAYMENT FORM */
    .payment-form { background: var(--bg-card); border: 1px solid var(--line); border-radius: 16px; padding: 32px }
    .form-section { margin-bottom: 28px }
    .form-section:last-child { margin-bottom: 0 }
    .form-section-title { font-family: var(--mono); font-size: 11px; color: var(--ink-faint); text-transform: uppercase; letter-spacing: .07em; margin-bottom: 14px }

/* PERIOD TOGGLE */
    .period-toggle { display: grid; grid-template-columns: 1fr 1fr; gap: 6px }
    .period-option { position: relative }
    .period-option input { position: absolute; opacity: 0; width: 0; height: 0 }
    .period-option label { display: flex; align-items: center; gap: 10px; padding: 11px 14px; background: transparent; border: 1px solid var(--line); border-radius: 8px; cursor: pointer; transition: border-color .15s }
    .period-option input:checked + label { border-color: var(--amber) }
    .period-option label:hover { border-color: var(--ink-faint) }
    .period-radio { width: 14px; height: 14px; border-radius: 50%; border: 1.5px solid var(--ink-faint); flex-shrink: 0; display: flex; align-items: center; justify-content: center; transition: border-color .15s }
    .period-option input:checked + label .period-radio { border-color: var(--amber) }
    .period-option input:checked + label .period-radio::after { content: ''; width: 6px; height: 6px; border-radius: 50%; background: var(--amber) }
    .period-info { display: flex; flex-direction: column; gap: 1px }
    .period-name { font-size: 13.5px; font-weight: 500; color: var(--ink) }
    .period-price { font-family: var(--mono); font-size: 12px; color: var(--ink-faint) }
    .period-save { font-size: 11px; color: var(--green); font-family: var(--mono) }

    /* PAYMENT METHOD */
    .method-toggle { display: grid; grid-template-columns: 1fr 1fr; gap: 6px }
    .method-option { position: relative }
    .method-option input { position: absolute; opacity: 0; width: 0; height: 0 }
    .method-option label { display: flex; align-items: center; gap: 10px; padding: 11px 14px; background: transparent; border: 1px solid var(--line); border-radius: 8px; cursor: pointer; transition: border-color .15s; font-size: 13.5px; font-weight: 500 }
    .method-option input:checked + label { border-color: var(--amber) }
    .method-option label:hover { border-color: var(--ink-faint) }
    .method-radio { width: 14px; height: 14px; border-radius: 50%; border: 1.5px solid var(--ink-faint); flex-shrink: 0; display: flex; align-items: center; justify-content: center; transition: border-color .15s }
    .method-option input:checked + label .method-radio { border-color: var(--amber) }
    .method-option input:checked + label .method-radio::after { content: ''; width: 6px; height: 6px; border-radius: 50%; background: var(--amber) }
    .method-icon { font-size: 15px }

    /* INVOICE FIELDS */
    .invoice-fields { display: none; flex-direction: column; gap: 12px; margin-top: 16px }
    .invoice-fields.visible { display: flex }
    .field-group { display: flex; flex-direction: column; gap: 6px }
    .field-label { font-size: 12px; color: var(--ink-faint); font-family: var(--mono) }
    .field-input { background: var(--bg-input); border: 1.5px solid var(--line); border-radius: 8px; color: var(--ink); font-size: 14px; padding: 11px 14px; font-family: var(--sans); transition: border-color .15s; width: 100% }
    .field-input:focus { outline: none; border-color: var(--amber) }
    .field-input::placeholder { color: var(--ink-faint) }

    /* RECURRENT */
    .recurrent-wrap { display: flex; align-items: flex-start; gap: 10px; padding: 14px 16px; background: var(--bg-soft); border: 1px solid var(--line); border-radius: 10px; cursor: pointer }
    .recurrent-wrap input[type="checkbox"] { appearance: none; width: 17px; height: 17px; border: 1.5px solid var(--line); border-radius: 4px; background: var(--bg-input); flex-shrink: 0; margin-top: 1px; cursor: pointer; transition: background .15s, border-color .15s; position: relative }
    .recurrent-wrap input[type="checkbox"]:checked { background: var(--amber); border-color: var(--amber) }
    .recurrent-wrap input[type="checkbox"]:checked::after { content: '✓'; position: absolute; top: -1px; left: 2px; font-size: 12px; color: #1a1208; font-weight: 700 }
    .recurrent-text { font-size: 13.5px; color: var(--ink-dim); line-height: 1.5 }
    .recurrent-text strong { color: var(--ink); font-weight: 500 }

    /* SUBMIT */
    .submit-btn { width: 100%; padding: 15px; background: var(--amber); color: #1a1208; font-weight: 700; font-size: 15px; font-family: var(--display); border: 0; border-radius: 10px; cursor: pointer; transition: transform .15s, box-shadow .15s; letter-spacing: -0.01em }
    .submit-btn:hover { transform: translateY(-1px); box-shadow: 0 8px 24px -8px rgba(232,116,44,.5) }
    .submit-btn:active { transform: translateY(0) }
    .submit-btn:disabled { opacity: .5; cursor: not-allowed; transform: none; box-shadow: none }
    .submit-note { font-size: 12px; color: var(--ink-faint); text-align: center; margin-top: 12px; font-family: var(--mono) }

    /* ERROR */
    .form-error { background: #2c1414; border: 1px solid #5c2020; border-radius: 8px; padding: 12px 14px; font-size: 13px; color: #f08080; margin-bottom: 16px; display: none }
    .form-error.visible { display: block }
  </style>
</head>
<body>

<nav class="nav">
  <a href="{$h($landingUrl)}" class="nav-brand"><span class="nav-dot"></span>ДЛ Коннект</a>
  <span class="nav-sep">/</span>
  <span class="nav-step">Оформление подписки</span>
</nav>

<div class="checkout-wrap">

  <!-- LEFT: план -->
  <div class="plan-summary">
    <div class="plan-label-tag">Тариф</div>
    <div class="plan-title">{$h($planInfo['label'])}</div>

    <div class="plan-price-block" id="priceBlock">
      <div class="plan-price-row">
        <span class="plan-price-amount" id="priceAmount">{$monthPrice} ₽</span>
        <span class="plan-price-period" id="pricePeriod">/ мес</span>
      </div>
      <div class="plan-price-detail" id="priceDetail">&nbsp;</div>
      <span class="plan-price-discount" id="priceDiscount" style="display:none">Скидка 20%</span>
    </div>

    <ul class="plan-features">
HTML;

    foreach ($planInfo['features'] as $feature) {
      echo '<li><span class="plan-check">✓</span>' . $h($feature) . '</li>';
    }

    echo <<<HTML
    </ul>

    <div class="trial-badge">14 дней бесплатного периода</div>
  </div>

  <!-- RIGHT: форма -->
  <div class="payment-form">

    <!-- Период -->
    <div class="form-section">
      <div class="form-section-title">Период подписки</div>
      <div class="period-toggle">
        <div class="period-option">
          <input type="radio" name="period" id="period-month" value="month" checked>
          <label for="period-month">
            <span class="period-radio"></span>
            <span class="period-info">
              <span class="period-name">1 месяц</span>
              <span class="period-price">{$monthPrice} ₽</span>
            </span>
          </label>
        </div>
        <div class="period-option">
          <input type="radio" name="period" id="period-year" value="year">
          <label for="period-year">
            <span class="period-radio"></span>
            <span class="period-info">
              <span class="period-name">12 месяцев <span class="period-save">−20%</span></span>
              <span class="period-price">{$yearPrice} ₽</span>
            </span>
          </label>
        </div>
      </div>
    </div>

    <!-- Способ оплаты -->
    <div class="form-section">
      <div class="form-section-title">Способ оплаты</div>
      <div class="method-toggle">
        <div class="method-option">
          <input type="radio" name="payment_method" id="method-card" value="card" checked>
          <label for="method-card">
            <span class="method-radio"></span>
            <span class="method-icon">💳</span>
            Картой
          </label>
        </div>
        <div class="method-option">
          <input type="radio" name="payment_method" id="method-invoice" value="invoice">
          <label for="method-invoice">
            <span class="method-radio"></span>
            <span class="method-icon">🧾</span>
            Счёт для юрлица
          </label>
        </div>
      </div>

      <!-- Поля юрлица -->
      <div class="invoice-fields" id="invoiceFields">
        <div class="field-group">
          <div class="field-label">Название организации</div>
          <input type="text" class="field-input" id="payerName" placeholder="ООО «Ромашка»">
        </div>
        <div class="field-group">
          <div class="field-label">ИНН (10 или 12 цифр)</div>
          <input type="text" class="field-input" id="payerInn" placeholder="7700000000" inputmode="numeric" maxlength="12">
        </div>
        <div class="field-group">
          <div class="field-label">КПП (необязательно)</div>
          <input type="text" class="field-input" id="payerKpp" placeholder="770001001" inputmode="numeric" maxlength="9">
        </div>
        <div class="field-group">
          <div class="field-label">Email для отправки счёта</div>
          <input type="email" class="field-input" id="payerEmail" placeholder="buh@company.ru">
        </div>
      </div>
    </div>

    <!-- Автопродление (только при оплате картой) -->
    <div class="form-section" id="recurrentSection">
      <label class="recurrent-wrap">
        <input type="checkbox" id="recurrentCheck">
        <div class="recurrent-text">
          <strong>Автопродление</strong><br>
          Подписка продлится автоматически в конце периода. Отменить можно в любой момент из настроек приложения.
        </div>
      </label>
    </div>

    <!-- Ошибка -->
    <div class="form-error" id="formError"></div>

    <!-- Кнопка -->
    <button class="submit-btn" id="submitBtn" onclick="handleSubmit()">Оплатить</button>
    <div class="submit-note" id="submitNote">Оплата через защищённую форму Т-Банка</div>

    <!-- Скрытые формы -->
    <form id="cardForm" method="POST" action="{$h($billingUrl)}" style="display:none">
      <input type="hidden" name="select_plan" value="{$h($plan)}">
      <input type="hidden" name="insales_id" value="{$h($insalesId)}">
      <input type="hidden" name="shop" value="{$h($shopHost)}">
      <input type="hidden" name="atk" value="{$h($atk)}">
      <input type="hidden" name="period" id="cardPeriod" value="month">
      <input type="hidden" name="recurrent" id="cardRecurrent" value="0">
    </form>
  </div>
</div>

<script>
var MONTH_PRICE = {$monthPrice};
var YEAR_PRICE  = {$yearPrice};
var INVOICE_URL = '{$h($invoiceUrl)}';
var INSALES_ID  = '{$h($insalesId)}';
var SHOP        = '{$h($shopHost)}';
var ATK         = '{$h($atk)}';
var PLAN        = '{$h($plan)}';

function getPeriod() {
  return document.getElementById('period-year').checked ? 'year' : 'month';
}
function getMethod() {
  return document.getElementById('method-invoice').checked ? 'invoice' : 'card';
}

function updatePriceBlock() {
  var period = getPeriod();
  var amountEl = document.getElementById('priceAmount');
  var periodEl = document.getElementById('pricePeriod');
  var detailEl = document.getElementById('priceDetail');
  var discountEl = document.getElementById('priceDiscount');

  if (period === 'year') {
    amountEl.textContent = YEAR_PRICE.toLocaleString('ru-RU') + ' ₽';
    periodEl.textContent = '/ год';
    detailEl.textContent = Math.round(YEAR_PRICE / 12).toLocaleString('ru-RU') + ' ₽ в месяц';
    discountEl.style.display = 'inline-block';
  } else {
    amountEl.textContent = MONTH_PRICE.toLocaleString('ru-RU') + ' ₽';
    periodEl.textContent = '/ мес';
    detailEl.innerHTML = '&nbsp;';
    discountEl.style.display = 'none';
  }
}

function updateMethodUI() {
  var method = getMethod();
  var invoiceFields = document.getElementById('invoiceFields');
  var recurrentSection = document.getElementById('recurrentSection');
  var submitNote = document.getElementById('submitNote');

  if (method === 'invoice') {
    invoiceFields.classList.add('visible');
    recurrentSection.style.display = 'none';
    submitNote.textContent = 'Счёт придёт на указанный email';
    document.getElementById('submitBtn').textContent = 'Получить счёт';
  } else {
    invoiceFields.classList.remove('visible');
    recurrentSection.style.display = '';
    submitNote.textContent = 'Оплата через защищённую форму Т-Банка';
    document.getElementById('submitBtn').textContent = 'Оплатить';
  }
}

function showError(msg) {
  var el = document.getElementById('formError');
  el.textContent = msg;
  el.classList.add('visible');
}
function hideError() {
  document.getElementById('formError').classList.remove('visible');
}

function handleSubmit() {
  hideError();
  var method = getMethod();
  var period  = getPeriod();
  var btn = document.getElementById('submitBtn');
  btn.disabled = true;

  if (method === 'card') {
    document.getElementById('cardPeriod').value = period;
    document.getElementById('cardRecurrent').value = document.getElementById('recurrentCheck').checked ? '1' : '0';
    document.getElementById('cardForm').submit();
    return;
  }

  // Счёт — AJAX
  var payerName  = document.getElementById('payerName').value.trim();
  var payerInn   = document.getElementById('payerInn').value.trim();
  var payerKpp   = document.getElementById('payerKpp').value.trim();
  var payerEmail = document.getElementById('payerEmail').value.trim();

  if (!payerName) { showError('Укажите название организации'); btn.disabled = false; return; }
  if (!/^(\d{10}|\d{12})$/.test(payerInn)) { showError('ИНН должен содержать 10 или 12 цифр'); btn.disabled = false; return; }
  if (!payerEmail || !/\S+@\S+\.\S+/.test(payerEmail)) { showError('Укажите корректный email'); btn.disabled = false; return; }

  btn.textContent = 'Формируем счёт…';

  var body = new FormData();
  body.append('insales_id', INSALES_ID);
  body.append('shop', SHOP);
  body.append('atk', ATK);
  body.append('plan', PLAN);
  body.append('period', period);
  body.append('payer_name', payerName);
  body.append('payer_inn', payerInn);
  body.append('payer_kpp', payerKpp);
  body.append('email', payerEmail);

  fetch(INVOICE_URL, { method: 'POST', body: body })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.ok) {
  var msg = 'Счёт №' + (data.invoice_number || '') + ' выставлен.';
  if (data.due_date) msg += ' Оплатите до ' + data.due_date + '.';
  msg += ' Реквизиты отправлены на указанный email.';
  document.getElementById('formError').style.background = 'var(--green-soft)';
  document.getElementById('formError').style.borderColor = 'var(--green)';
  document.getElementById('formError').style.color = 'var(--green)';
  showError(msg);
  btn.textContent = 'Счёт выставлен';
} else {
  showError(data.error || 'Не удалось выставить счёт. Попробуйте позже.');
  btn.disabled = false;
  btn.textContent = 'Получить счёт';
}
    })
    .catch(function() {
      showError('Ошибка сети. Проверьте соединение и попробуйте снова.');
      btn.disabled = false;
      btn.textContent = 'Получить счёт';
    });
}

// Инициализация
document.querySelectorAll('input[name="period"]').forEach(function(el) {
  el.addEventListener('change', updatePriceBlock);
});
document.querySelectorAll('input[name="payment_method"]').forEach(function(el) {
  el.addEventListener('change', updateMethodUI);
});
updatePriceBlock();
updateMethodUI();
</script>
</body>
</html>
HTML;
  }
  private static function renderSuccess(array $planInfo, string $insalesId, string $shopHost, string $landingUrl): void
  {
    $h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    $appUrl = '/insales/app?' . http_build_query(['insales_id' => $insalesId, 'shop' => $shopHost]);
    echo <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Оплата прошла — ДЛ Коннект</title>
  <link href="/fonts/fonts.css" rel="stylesheet">
  <style>
    :root { --bg:#16181c; --bg-card:#1f2228; --line:#2c3038; --ink:#f2efe9; --ink-dim:#a8a59d; --ink-faint:#6b6963; --amber:#e8742c; --green:#5fb88a; --green-soft:#1a2b22 }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0 }
    body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--ink); min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 24px; -webkit-font-smoothing: antialiased }
    .card { background: var(--bg-card); border: 1px solid var(--line); border-radius: 16px; padding: 48px 40px; max-width: 440px; width: 100%; text-align: center }
    .icon { width: 52px; height: 52px; background: var(--green-soft); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 24px; font-size: 22px }
    h1 { font-family: 'Space Grotesk', sans-serif; font-size: 22px; font-weight: 600; margin-bottom: 10px; letter-spacing: -0.01em }
    p { font-size: 14px; color: var(--ink-dim); line-height: 1.6; margin-bottom: 8px }
    .plan { display: inline-block; font-size: 12px; font-family: monospace; background: var(--green-soft); color: var(--green); padding: 3px 10px; border-radius: 20px; margin-bottom: 28px }
    .btn { display: inline-block; background: var(--amber); color: #1a1208; font-weight: 600; font-size: 14px; padding: 12px 24px; border-radius: 9px; text-decoration: none; margin-top: 8px }
    .back { display: block; margin-top: 16px; font-size: 13px; color: var(--ink-faint); text-decoration: none }
    .back:hover { color: var(--ink) }
  </style>
</head>
<body>
  <div class="card">
    <div class="icon">✓</div>
    <h1>Оплата прошла</h1>
    <p>Тариф активирован для вашего магазина.</p>
    <div class="plan">{$h($planInfo['label'] ?? 'Тариф')}</div>
    <br>
    <a href="{$h($appUrl)}" class="btn">Перейти в настройки</a>
    <a href="{$h($landingUrl)}" class="back">На главную</a>
  </div>
</body>
</html>
HTML;
  }

  private static function renderFail(string $plan, string $insalesId, string $shopHost, string $atk): void
  {
    $h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    $retryUrl = '/checkout?' . http_build_query(['plan' => $plan, 'insales_id' => $insalesId, 'shop' => $shopHost, 'atk' => $atk]);
    echo <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ошибка оплаты — ДЛ Коннект</title>
  <link href="/fonts/fonts.css" rel="stylesheet">
  <style>
    :root { --bg:#16181c; --bg-card:#1f2228; --line:#2c3038; --ink:#f2efe9; --ink-dim:#a8a59d; --ink-faint:#6b6963; --amber:#e8742c; --red-soft:#2c1414 }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0 }
    body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--ink); min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 24px; -webkit-font-smoothing: antialiased }
    .card { background: var(--bg-card); border: 1px solid var(--line); border-radius: 16px; padding: 48px 40px; max-width: 440px; width: 100%; text-align: center }
    .icon { width: 52px; height: 52px; background: var(--red-soft); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 24px; font-size: 22px }
    h1 { font-family: 'Space Grotesk', sans-serif; font-size: 22px; font-weight: 600; margin-bottom: 10px; letter-spacing: -0.01em }
    p { font-size: 14px; color: var(--ink-dim); line-height: 1.6; margin-bottom: 28px }
    .btn { display: inline-block; background: var(--amber); color: #1a1208; font-weight: 600; font-size: 14px; padding: 12px 24px; border-radius: 9px; text-decoration: none }
  </style>
</head>
<body>
  <div class="card">
    <div class="icon">✕</div>
    <h1>Оплата не прошла</h1>
    <p>Платёж был отклонён или отменён. Попробуйте ещё раз или выберите другой способ оплаты.</p>
    <a href="{$h($retryUrl)}" class="btn">Попробовать снова</a>
  </div>
</body>
</html>
HTML;
  }
}
