<?php

declare(strict_types=1);

namespace ShippingBridge\InSales;

use ShippingBridge\ShopRepository;

/**
 * Страница настроек приложения в бэкофисе inSales (после установки).
 * Терминал отправителя выбирается из справочника API перевозчика.
 */
final class AppSettingsHandler
{
    public static function handle(ShopRepository $shops, string $method): void
    {
        header('Content-Type: text/html; charset=utf-8');

        $shopHost = trim((string) ($_GET['shop'] ?? $_POST['shop'] ?? ''));
        $insalesId = trim((string) ($_GET['insales_id'] ?? $_POST['insales_id'] ?? ''));

        if ($shopHost === '' && $insalesId === '') {
            http_response_code(400);
            self::renderError('Укажите параметры shop или insales_id (их передаёт inSales при открытии приложения).');
            return;
        }

        $row = $insalesId !== ''
            ? $shops->findActiveByInsalesId($insalesId)
            : $shops->findActiveByHost($shopHost);

        if ($row === null) {
            http_response_code(404);
            self::renderError('Магазин не найден. Сначала установите приложение в магазине inSales.');
            return;
        }

        $insalesId = $row['insales_id'];
        $shopHost = $row['shop_host'];
        $saved = false;
        $error = null;

        if ($method === 'POST') {
            $terminalId = (int) ($_POST['sender_terminal_id'] ?? 0);
            try {
                $shops->saveSenderTerminalId($insalesId, $terminalId);
                $row = $shops->findActiveByInsalesId($insalesId) ?? $row;
                $saved = true;
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        http_response_code(200);
        self::renderForm($shopHost, $insalesId, $row['sender_terminal_id'], $saved, $error);
    }

    private static function renderForm(
        string $shopHost,
        string $insalesId,
        ?int $currentTerminalId,
        bool $saved,
        ?string $error
    ): void {
        $tid = $currentTerminalId !== null && $currentTerminalId > 0 ? (string) $currentTerminalId : '';
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>Настройки доставки</title>';
        echo '<style>';
        echo 'body{font-family:system-ui,sans-serif;max-width:560px;margin:2rem auto;padding:0 1rem}';
        echo 'label{display:block;margin:.75rem 0 .25rem;font-weight:500}';
        echo 'input,select{width:100%;padding:.5rem;box-sizing:border-box;font-size:14px}';
        echo 'button{margin-top:1rem;padding:.6rem 1.2rem;cursor:pointer}';
        echo '.ok{color:#0a0}.err{color:#c00}';
        echo '#citySuggestions{list-style:none;margin:0;padding:0;border:1px solid #ccc;border-radius:4px;max-height:180px;overflow:auto;display:none}';
        echo '#citySuggestions li{padding:.5rem .75rem;cursor:pointer;border-bottom:1px solid #eee}';
        echo '#citySuggestions li:hover{background:#f0f4ff}';
        echo '#termStatus{font-size:.9rem;color:#555;margin:.25rem 0}';
        echo '</style></head><body>';
        echo '<h1>Настройки доставки</h1>';
        echo '<p>Магазин: <strong>' . $h($shopHost) . '</strong></p>';

        if ($saved) {
            echo '<p class="ok">Настройки сохранены.</p>';
        }
        if ($error !== null) {
            echo '<p class="err">' . $h($error) . '</p>';
        }

        echo '<form method="post" action="/insales/app" id="settingsForm">';
        echo '<input type="hidden" name="shop" value="' . $h($shopHost) . '">';
        echo '<input type="hidden" name="insales_id" value="' . $h($insalesId) . '">';
        echo '<input type="hidden" id="city_kladr" value="">';

        echo '<label for="citySearch">Город отгрузки</label>';
        echo '<input type="text" id="citySearch" autocomplete="off" placeholder="Например: Москва, Казань">';
        echo '<ul id="citySuggestions"></ul>';

        echo '<label for="sender_terminal_id">Терминал отгрузки</label>';
        echo '<p id="termStatus">Выберите город — список терминалов загрузится из API перевозчика.</p>';
        echo '<select id="sender_terminal_id" name="sender_terminal_id" required disabled>';
        echo '<option value="">— выберите терминал —</option>';
        if ($tid !== '') {
            echo '<option value="' . $h($tid) . '" selected>ID ' . $h($tid) . ' (сохранённый)</option>';
        }
        echo '</select>';

        echo '<button type="submit">Сохранить</button>';
        echo '</form>';
        echo '<p style="margin-top:2rem;font-size:.85rem"><a href="/widget/index.html" target="_blank">Демо карты ПВЗ</a></p>';

        echo '<script>';
        echo '(function(){';
        echo 'var savedId=' . json_encode($tid) . ';';
        echo 'var cityInput=document.getElementById("citySearch");';
        echo 'var cityKladr=document.getElementById("city_kladr");';
        echo 'var cityList=document.getElementById("citySuggestions");';
        echo 'var termSelect=document.getElementById("sender_terminal_id");';
        echo 'var termStatus=document.getElementById("termStatus");';
        echo 'var cityTimer;';
        echo 'function fetchJson(url){return fetch(url,{headers:{Accept:"application/json"}}).then(function(r){return r.json();});}';
        echo 'function loadTerminals(kladr,q){';
        echo 'termStatus.textContent="Загрузка терминалов…";termSelect.disabled=true;';
        echo 'var url="/insales/terminals?limit=200&city_kladr="+encodeURIComponent(kladr);';
        echo 'if(q)url+="&q="+encodeURIComponent(q);';
        echo 'fetchJson(url).then(function(j){';
        echo 'if(!j.ok)throw new Error(j.error||"Ошибка загрузки");';
        echo 'termSelect.innerHTML="";';
        echo 'var o=document.createElement("option");o.value="";o.textContent="— выберите терминал —";termSelect.appendChild(o);';
        echo '(j.terminals||[]).forEach(function(t){';
        echo 'var opt=document.createElement("option");opt.value=t.id;';
        echo 'opt.textContent="ID "+t.id+" — "+(t.name||"")+(t.address?" — "+t.address:"");';
        echo 'if(String(t.id)===String(savedId))opt.selected=true;';
        echo 'termSelect.appendChild(opt);});';
        echo 'termSelect.disabled=(j.count||0)===0;';
        echo 'termStatus.textContent=(j.count||0)?"Найдено терминалов: "+j.count:"В этом городе терминалы не найдены";';
        echo '}).catch(function(e){termStatus.textContent="Ошибка: "+e.message;termSelect.disabled=true;});}';
        echo 'cityInput.addEventListener("input",function(){';
        echo 'clearTimeout(cityTimer);var q=cityInput.value.trim();cityList.style.display="none";';
        echo 'if(q.length<2)return;';
        echo 'cityTimer=setTimeout(function(){';
        echo 'fetchJson("/insales/cities/search?q="+encodeURIComponent(q)).then(function(j){';
        echo 'if(!j.ok)return;cityList.innerHTML="";';
        echo '(j.cities||[]).slice(0,12).forEach(function(c){';
        echo 'var code=c.code||c.kladr||c.cityID||"";if(!code)return;';
        echo 'var li=document.createElement("li");li.textContent=(c.name||c.searchString||code);';
        echo 'li.dataset.kladr=code;li.dataset.name=li.textContent;';
        echo 'li.addEventListener("click",function(){';
        echo 'cityInput.value=li.dataset.name;cityKladr.value=li.dataset.kladr;';
        echo 'cityList.style.display="none";loadTerminals(li.dataset.kladr,"");});';
        echo 'cityList.appendChild(li);});';
        echo 'cityList.style.display=cityList.children.length?"block":"none";';
        echo '});},350);});';
        echo 'document.addEventListener("click",function(e){if(!cityList.contains(e.target)&&e.target!==cityInput)cityList.style.display="none";});';
        echo '})();';
        echo '</script>';
        echo '</body></html>';
    }

    private static function renderError(string $message): void
    {
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Ошибка</title></head><body>';
        echo '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></body></html>';
    }
}
