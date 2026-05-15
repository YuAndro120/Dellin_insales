<?php

declare(strict_types=1);

namespace ShippingBridge\InSales;

use ShippingBridge\Config;
use ShippingBridge\ShopRepository;
use ShippingBridge\ShopSettings;

/**
 * Страница настроек приложения в бэкофисе inSales.
 */
final class AppSettingsHandler
{
    public static function handle(ShopRepository $shops, Config $config, string $method): void
    {
        header('Content-Type: text/html; charset=utf-8');

        $shopHost = trim((string) ($_GET['shop'] ?? $_POST['shop'] ?? ''));
        $insalesId = trim((string) ($_GET['insales_id'] ?? $_POST['insales_id'] ?? ''));

        if ($shopHost === '' && $insalesId === '') {
            http_response_code(400);
            self::renderError('Укажите параметры shop или insales_id (их передаёт inSales при открытии приложения).');
            return;
        }

        $settings = $insalesId !== ''
            ? $shops->findSettingsByInsalesId($insalesId, $config)
            : $shops->findSettingsByHost($shopHost, $config);

        if ($settings === null) {
            http_response_code(404);
            self::renderError('Магазин не найден. Сначала установите приложение в магазине inSales.');
            return;
        }

        $saved = false;
        $error = null;

        if ($method === 'POST') {
            try {
                $shops->saveDeliverySettings($settings->insalesId, [
                    'sender_terminal_id' => (int) ($_POST['sender_terminal_id'] ?? 0),
                    'requester_email' => trim((string) ($_POST['requester_email'] ?? '')),
                    'counteragent_uid' => trim((string) ($_POST['counteragent_uid'] ?? '')) ?: null,
                    'produce_days_offset' => (int) ($_POST['produce_days_offset'] ?? 2),
                    'default_stated_value' => (float) str_replace(',', '.', (string) ($_POST['default_stated_value'] ?? '0')),
                    'default_weight_kg' => (float) str_replace(',', '.', (string) ($_POST['default_weight_kg'] ?? '1')),
                    'default_dimensions_cm' => trim((string) ($_POST['default_dimensions_cm'] ?? '20x20x20')),
                    'is_enabled' => isset($_POST['is_enabled']),
                ]);
                $settings = $shops->findSettingsByInsalesId($settings->insalesId, $config) ?? $settings;
                $saved = true;
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        http_response_code(200);
        self::renderForm($settings, $saved, $error);
    }

    private static function renderForm(ShopSettings $s, bool $saved, ?string $error): void
    {
        $h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $tid = $s->senderTerminalId !== null && $s->senderTerminalId > 0 ? (string) $s->senderTerminalId : '';

        echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>Настройки доставки</title>';
        echo '<style>';
        echo 'body{font-family:system-ui,sans-serif;max-width:640px;margin:1.5rem auto;padding:0 1rem;color:#1a1a1a}';
        echo 'h1{font-size:1.35rem}h2{font-size:1rem;margin:1.5rem 0 .5rem;border-bottom:1px solid #e5e5e5;padding-bottom:.35rem}';
        echo 'label{display:block;margin:.6rem 0 .2rem;font-weight:500;font-size:.9rem}';
        echo '.hint{font-size:.8rem;color:#666;font-weight:400;margin-top:.15rem}';
        echo 'input,select{width:100%;padding:.5rem;box-sizing:border-box;font-size:14px;border:1px solid #ccc;border-radius:4px}';
        echo 'input[type=checkbox]{width:auto;margin-right:.4rem}';
        echo '.row-check{display:flex;align-items:center;margin:.75rem 0}';
        echo 'button{margin-top:1.25rem;padding:.65rem 1.4rem;cursor:pointer;background:#3d5afe;color:#fff;border:0;border-radius:6px;font-size:14px}';
        echo '.ok{color:#0a0}.err{color:#c00}.info{background:#f6f8fa;border-radius:6px;padding:.75rem 1rem;font-size:.85rem;margin-bottom:1rem}';
        echo '#citySuggestions{list-style:none;margin:0;padding:0;border:1px solid #ccc;border-radius:4px;max-height:160px;overflow:auto;display:none}';
        echo '#citySuggestions li{padding:.45rem .75rem;cursor:pointer;border-bottom:1px solid #eee}';
        echo '#citySuggestions li:hover{background:#f0f4ff}#termStatus{font-size:.85rem;color:#555;margin:.2rem 0}';
        echo '</style></head><body>';

        echo '<h1>Настройки доставки</h1>';
        echo '<div class="info">';
        echo '<strong>Магазин:</strong> ' . $h($s->shopHost) . '<br>';
        echo '<strong>Статус:</strong> ' . ($s->isEnabled ? 'расчёт включён' : 'расчёт отключён');
        if ($tid !== '') {
            echo '<br><strong>Терминал отгрузки:</strong> ID ' . $h($tid);
        }
        echo '</div>';

        if ($saved) {
            echo '<p class="ok">Настройки сохранены.</p>';
        }
        if ($error !== null) {
            echo '<p class="err">' . $h($error) . '</p>';
        }

        echo '<form method="post" action="/insales/app">';
        echo '<input type="hidden" name="shop" value="' . $h($s->shopHost) . '">';
        echo '<input type="hidden" name="insales_id" value="' . $h($s->insalesId) . '">';
        echo '<input type="hidden" id="city_kladr" value="">';

        echo '<h2>Отгрузка</h2>';
        echo '<label for="citySearch">Город отгрузки</label>';
        echo '<input type="text" id="citySearch" autocomplete="off" placeholder="Москва, Казань…">';
        echo '<ul id="citySuggestions"></ul>';
        echo '<label for="sender_terminal_id">Терминал отгрузки <span class="hint">из API перевозчика</span></label>';
        echo '<p id="termStatus">Выберите город, чтобы загрузить список терминалов.</p>';
        echo '<select id="sender_terminal_id" name="sender_terminal_id" required disabled>';
        echo '<option value="">— выберите терминал —</option>';
        if ($tid !== '') {
            echo '<option value="' . $h($tid) . '" selected>ID ' . $h($tid) . ' (сохранённый)</option>';
        }
        echo '</select>';

        echo '<h2>Контакт в API перевозчика</h2>';
        echo '<label for="requester_email">Email отправителя</label>';
        echo '<span class="hint">Поле requester.email в калькуляторе</span>';
        echo '<input type="email" id="requester_email" name="requester_email" required value="' . $h($s->requesterEmail) . '">';
        echo '<label for="counteragent_uid">UID контрагента <span class="hint">необязательно</span></label>';
        echo '<input type="text" id="counteragent_uid" name="counteragent_uid" value="' . $h($s->counteragentUid ?? '') . '" placeholder="из личного кабинета перевозчика">';

        echo '<h2>Параметры груза по умолчанию</h2>';
        echo '<p class="hint">Если у варианта товара в inSales не заполнены вес или габариты.</p>';
        echo '<label for="default_weight_kg">Вес, кг</label>';
        echo '<input type="number" step="0.001" min="0.01" id="default_weight_kg" name="default_weight_kg" value="' . $h((string) $s->defaultWeightKg) . '">';
        echo '<label for="default_dimensions_cm">Габариты, см (Д×Ш×В)</label>';
        echo '<input type="text" id="default_dimensions_cm" name="default_dimensions_cm" value="' . $h($s->defaultDimensionsCm) . '" placeholder="20x20x20">';
        echo '<label for="default_stated_value">Объявленная стоимость, ₽</label>';
        echo '<input type="number" step="0.01" min="0" id="default_stated_value" name="default_stated_value" value="' . $h((string) $s->defaultStatedValue) . '">';

        echo '<h2>Срок и активность</h2>';
        echo '<label for="produce_days_offset">Дней до даты отгрузки</label>';
        echo '<input type="number" min="0" max="30" id="produce_days_offset" name="produce_days_offset" value="' . $h((string) $s->produceDaysOffset) . '">';
        echo '<div class="row-check">';
        echo '<input type="checkbox" id="is_enabled" name="is_enabled" value="1"' . ($s->isEnabled ? ' checked' : '') . '>';
        echo '<label for="is_enabled" style="margin:0">Включить расчёт доставки для этого магазина</label>';
        echo '</div>';

        echo '<button type="submit">Сохранить настройки</button>';
        echo '</form>';
        echo '<p style="margin-top:2rem;font-size:.85rem"><a href="/widget/index.html?bridge=' . $h((string) (getenv('PUBLIC_BRIDGE_URL') ?: '')) . '" target="_blank">Демо карты ПВЗ</a></p>';

        echo '<script>';
        echo '(function(){';
        echo 'var savedId=' . json_encode($tid) . ';';
        echo 'var cityInput=document.getElementById("citySearch");';
        echo 'var cityList=document.getElementById("citySuggestions");';
        echo 'var termSelect=document.getElementById("sender_terminal_id");';
        echo 'var termStatus=document.getElementById("termStatus");';
        echo 'var cityTimer;';
        echo 'function fetchJson(u){return fetch(u,{headers:{Accept:"application/json"}}).then(function(r){return r.json();});}';
        echo 'function loadTerminals(kladr){termStatus.textContent="Загрузка…";termSelect.disabled=true;';
        echo 'fetchJson("/insales/terminals?limit=200&city_kladr="+encodeURIComponent(kladr)).then(function(j){';
        echo 'if(!j.ok)throw new Error(j.error||"err");termSelect.innerHTML="";';
        echo 'var o=document.createElement("option");o.value="";o.textContent="— выберите —";termSelect.appendChild(o);';
        echo '(j.terminals||[]).forEach(function(t){var opt=document.createElement("option");opt.value=t.id;';
        echo 'opt.textContent="ID "+t.id+" — "+(t.name||"")+(t.address?" — "+t.address:"");';
        echo 'if(String(t.id)===String(savedId))opt.selected=true;termSelect.appendChild(opt);});';
        echo 'termSelect.disabled=!(j.count>0);termStatus.textContent="Найдено: "+(j.count||0);';
        echo '}).catch(function(e){termStatus.textContent="Ошибка: "+e.message;});}';
        echo 'cityInput.addEventListener("input",function(){clearTimeout(cityTimer);var q=cityInput.value.trim();cityList.style.display="none";if(q.length<2)return;';
        echo 'cityTimer=setTimeout(function(){fetchJson("/insales/cities/search?q="+encodeURIComponent(q)).then(function(j){';
        echo 'if(!j.ok)return;cityList.innerHTML="";(j.cities||[]).slice(0,12).forEach(function(c){';
        echo 'var code=c.code||c.kladr||"";if(!code)return;var li=document.createElement("li");li.textContent=c.name||c.searchString||code;';
        echo 'li.addEventListener("click",function(){cityInput.value=li.textContent;cityList.style.display="none";loadTerminals(code);});cityList.appendChild(li);});';
        echo 'cityList.style.display=cityList.children.length?"block":"none";});},350);});';
        echo '})();</script></body></html>';
    }

    private static function renderError(string $message): void
    {
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Ошибка</title></head><body>';
        echo '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></body></html>';
    }
}
