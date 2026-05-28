<?php

declare(strict_types=1);

namespace ShippingBridge\InSales;

use ShippingBridge\CarrierApi;
use ShippingBridge\CarrierCredentials;
use ShippingBridge\Config;
use ShippingBridge\DellinCounteragent;
use ShippingBridge\ShopRepository;
use ShippingBridge\ShopSettings;

/**
 * Страница приложения inSales: авторизация Dellin → настройки доставки.
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

        $installTokenRaw = trim((string) ($_GET['token'] ?? ''));
        if ($installTokenRaw !== '' && ($config->insalesAppSecret ?? '') !== '') {
            try {
                $token = InSalesApiPassword::parseInstallToken($installTokenRaw);
                $shops->updateApiPassword(
                    $settings->insalesId,
                    InSalesApiPassword::compute($token, $config->insalesAppSecret)
                );
            } catch (\Throwable) {
                // token из URL — фоновое обновление, не блокируем UI
            }
        }

        $error = null;
        $saved = false;
        $deliveryCreated = null;

        if ($method === 'POST' && isset($_POST['save_dellin_auth'])) {
            $error = self::handleDellinAuth($shops, $config, $settings);
            if ($error === null) {
                $q = http_build_query(['shop' => $settings->shopHost, 'insales_id' => $settings->insalesId]);
                header('Location: /insales/app?' . $q, true, 302);
                exit;
            }
        } elseif (!$settings->hasDellinAuth) {
            self::renderAuthForm($settings, $error);
            return;
        } elseif ($method === 'POST' && isset($_POST['update_install_token'])) {
            try {
                $secret = $config->insalesAppSecret ?? '';
                if ($secret === '') {
                    throw new \RuntimeException('INSALES_APP_SECRET не задан в .env');
                }
                $token = InSalesApiPassword::parseInstallToken((string) ($_POST['install_token'] ?? ''));
                if ($token === '') {
                    throw new \RuntimeException('Вставьте token из URL после установки приложения в магазине');
                }
                $shops->updateApiPassword(
                    $settings->insalesId,
                    InSalesApiPassword::compute($token, $secret)
                );
                $saved = true;
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
            $settings = $shops->findSettingsByInsalesId($settings->insalesId, $config) ?? $settings;
        } elseif ($method === 'POST' && isset($_POST['create_pickup_delivery'])) {
            try {
                $auth = $shops->findApiAuthByInsalesId($settings->insalesId);
                if ($auth === null) {
                    throw new \RuntimeException('Нет данных авторизации магазина. Переустановите приложение.');
                }
                $setup = new InSalesDeliverySetup(new InSalesClient(), $config);
                $deliveryCreated = $setup->createPickUpDeliveryVariant($auth['shop_host'], $auth['api_password']);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
            $settings = $shops->findSettingsByInsalesId($settings->insalesId, $config) ?? $settings;
        } elseif ($method === 'POST') {
            try {
                $variant = (string) ($_POST['derival_variant'] ?? ShopSettings::DERIVAL_TERMINAL);
                $shops->saveDeliverySettings($settings->insalesId, [
                    'derival_variant' => $variant,
                    'sender_terminal_id' => (int) ($_POST['sender_terminal_id'] ?? 0),
                    'derival_city_kladr' => trim((string) ($_POST['derival_city_kladr'] ?? '')),
                    'derival_street' => trim((string) ($_POST['derival_street'] ?? '')),
                    'derival_house' => trim((string) ($_POST['derival_house'] ?? '')),
                    'requester_email' => trim((string) ($_POST['requester_email'] ?? '')),
                    'counteragent_uid' => trim((string) ($_POST['counteragent_uid'] ?? '')) ?: null,
                    'sender_counteragent_id' => (int) ($_POST['sender_counteragent_id'] ?? 0) ?: null,
                    'freight_uid' => trim((string) ($_POST['freight_uid'] ?? '')) ?: null,
                    'produce_days_offset' => (int) ($_POST['produce_days_offset'] ?? 2),
                    'default_stated_value' => (float) str_replace(',', '.', (string) ($_POST['default_stated_value'] ?? '0')),
                    'default_weight_kg' => (float) str_replace(',', '.', (string) ($_POST['default_weight_kg'] ?? '1')),
                    'default_dimensions_cm' => trim((string) ($_POST['default_dimensions_cm'] ?? '20x20x20')),
                    'is_enabled' => isset($_POST['is_enabled']),
                    'sender_name'          => trim((string) ($_POST['sender_name'] ?? '')),
                    'sender_type'          => trim((string) ($_POST['sender_type'] ?? 'person')),
                    'sender_inn'           => trim((string) ($_POST['sender_inn'] ?? '')),
                    'sender_doc_type'      => trim((string) ($_POST['sender_doc_type'] ?? 'passport')),
                    'sender_doc_serial'    => trim((string) ($_POST['sender_doc_serial'] ?? '')),
                    'sender_doc_number'    => trim((string) ($_POST['sender_doc_number'] ?? '')),
                    'sender_contact_name'  => trim((string) ($_POST['sender_contact_name'] ?? '')),
                    'sender_contact_phone' => trim((string) ($_POST['sender_contact_phone'] ?? '')),
                ]);
                $settings = $shops->findSettingsByInsalesId($settings->insalesId, $config) ?? $settings;
                $saved = true;
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        [$counteragents, $counteragentsError] = self::loadCounteragents($shops, $config, $settings);

        http_response_code(200);
        self::renderSettingsForm($settings, $config, $saved, $deliveryCreated, $error, $counteragents, $counteragentsError);
    }

    /**
     * @return array{0:list<DellinCounteragent>,1:?string}
     */
    private static function loadCounteragents(ShopRepository $shops, Config $config, ShopSettings $settings): array
    {
        if (!$settings->hasDellinAuth || $config->bridgeSecret === '') {
            return [[], null];
        }

        try {
            $creds = $shops->findCarrierCredentials($settings->insalesId, $config->bridgeSecret);
            if ($creds === null) {
                return [[], null];
            }
            $api = new CarrierApi($config);

            return [$api->listCounteragents($creds), null];
        } catch (\Throwable $e) {
            return [[], $e->getMessage()];
        }
    }

    private static function handleDellinAuth(ShopRepository $shops, Config $config, ShopSettings $settings): ?string
    {
        if ($config->bridgeSecret === '') {
            return 'Задайте BRIDGE_SECRET в .env на сервере (нужен для хранения PAT).';
        }

        $appkey = trim((string) ($_POST['dellin_appkey'] ?? ''));
        $pat = trim((string) ($_POST['dellin_pat'] ?? ''));
        if ($appkey === '' || $pat === '') {
            return 'Укажите API-ключ и персональный токен (PAT).';
        }

        try {
            $api = new CarrierApi($config);
            $api->loginWithPat(new CarrierCredentials($appkey, $pat));
            $shops->saveDellinCredentials($settings->insalesId, $appkey, $pat, $config->bridgeSecret);
        } catch (\Throwable $e) {
            return 'Не удалось авторизоваться в Dellin: ' . $e->getMessage();
        }

        return null;
    }

    private static function renderAuthForm(ShopSettings $s, ?string $error): void
    {
        $h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        self::renderHead('Подключение Dellin');
        echo '<h1>Подключение Dellin</h1>';
        echo '<p class="hint">Магазин: <strong>' . $h($s->shopHost) . '</strong></p>';
        echo '<p>Укажите API-ключ и персональный токен доступа (PAT) из личного кабинета Dellin → Настройки → Интеграция.</p>';
        echo '<p class="hint"><a href="https://dev.dellin.ru/api/swagger/" target="_blank" rel="noopener">Документация API</a></p>';

        if ($error !== null) {
            echo '<p class="err">' . $h($error) . '</p>';
        }

        echo '<form method="post" action="/insales/app">';
        echo '<input type="hidden" name="shop" value="' . $h($s->shopHost) . '">';
        echo '<input type="hidden" name="insales_id" value="' . $h($s->insalesId) . '">';
        echo '<input type="hidden" name="save_dellin_auth" value="1">';
        echo '<label for="dellin_appkey">API-ключ (appkey)</label>';
        echo '<input type="text" id="dellin_appkey" name="dellin_appkey" required autocomplete="off" placeholder="из dev.dellin.ru / ЛК → Интеграция">';
        echo '<label for="dellin_pat">Персональный токен (PAT)</label>';
        echo '<input type="password" id="dellin_pat" name="dellin_pat" required autocomplete="off">';
        echo '<button type="submit">Подключить</button>';
        echo '</form>';
        echo '</body></html>';
    }

    /**
     * @param array{id: int, title: string}|null $deliveryCreated
     */
    /**
     * @param array{id: int, title: string}|null $deliveryCreated
     * @param list<DellinCounteragent> $counteragents
     */
    private static function renderSettingsForm(
        ShopSettings $s,
        Config $config,
        bool $saved,
        ?array $deliveryCreated,
        ?string $error,
        array $counteragents,
        ?string $counteragentsError,
    ): void {
        $h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $tid = $s->senderTerminalId !== null && $s->senderTerminalId > 0 ? (string) $s->senderTerminalId : '';
        $isTerminal = $s->isDerivalTerminal();

        self::renderHead('Настройки доставки');
        echo '<h1>Настройки доставки</h1>';
        echo '<p class="hint">' . $h($s->shopHost) . ' · Dellin подключён (PAT)</p>';
        self::renderCounteragentBlock($h, $s, $counteragents, $counteragentsError);

        if ($saved) {
            echo '<p class="ok">Настройки сохранены.</p>';
        }
        if ($deliveryCreated !== null) {
            echo '<p class="ok">Способ доставки создан: <strong>' . $h($deliveryCreated['title']) . '</strong> (id '
                . $h((string) $deliveryCreated['id']) . ').</p>';
        }
        if ($error !== null) {
            echo '<p class="err">' . $h($error) . '</p>';
        }

        echo '<form method="post" action="/insales/app" id="settingsForm" name="settingsForm">';
        echo '<input type="hidden" name="shop" value="' . $h($s->shopHost) . '">';
        echo '<input type="hidden" name="insales_id" value="' . $h($s->insalesId) . '">';

        echo '<h2>Способ отгрузки</h2>';
        echo '<div class="row-check">';
        echo '<input type="radio" name="derival_variant" id="dv_terminal" value="terminal"' . ($isTerminal ? ' checked' : '') . '>';
        echo '<label for="dv_terminal" style="margin:0">Самопривоз на терминал</label>';
        echo '</div>';
        echo '<div class="row-check">';
        echo '<input type="radio" name="derival_variant" id="dv_address" value="address"' . (!$isTerminal ? ' checked' : '') . '>';
        echo '<label for="dv_address" style="margin:0">Забор груза с адреса</label>';
        echo '</div>';

        echo '<div id="blockTerminal"' . ($isTerminal ? '' : ' style="display:none"') . '>';
        echo '<label for="citySearch">Город терминала отгрузки</label>';
        echo '<input type="text" id="citySearch" autocomplete="off" placeholder="Москва, Казань…">';
        echo '<ul id="citySuggestions"></ul>';
        echo '<label for="sender_terminal_id">Терминал отгрузки</label>';
        echo '<p id="termStatus" class="hint">Выберите город, чтобы загрузить терминалы.</p>';
        echo '<select id="sender_terminal_id" name="sender_terminal_id"' . ($isTerminal ? '' : ' disabled') . '>';
        echo '<option value="">— выберите —</option>';
        if ($tid !== '') {
            echo '<option value="' . $h($tid) . '" selected>ID ' . $h($tid) . ' (сохранённый)</option>';
        }
        echo '</select>';
        echo '</div>';

        echo '<div id="blockAddress"' . (!$isTerminal ? '' : ' style="display:none"') . '>';
        echo '<label for="pickupCitySearch">Город забора</label>';
        echo '<input type="text" id="pickupCitySearch" autocomplete="off" value="">';
        echo '<ul id="pickupCitySuggestions"></ul>';
        echo '<input type="hidden" name="derival_city_kladr" id="derival_city_kladr" value="' . $h($s->derivalCityKladr ?? '') . '">';
        echo '<label for="derival_street">Улица</label>';
        echo '<input type="text" id="derival_street" name="derival_street" value="' . $h($s->derivalStreet ?? '') . '">';
        echo '<label for="derival_house">Дом</label>';
        echo '<input type="text" id="derival_house" name="derival_house" value="' . $h($s->derivalHouse ?? '') . '">';
        echo '</div>';

        echo '<h2>Контакт в API Dellin</h2>';
        echo '<label for="requester_email">Email отправителя</label>';
        echo '<input type="email" id="requester_email" name="requester_email" required value="' . $h($s->requesterEmail) . '">';

        $savedCaid = $s->senderCounterAgentId !== null ? (string) $s->senderCounterAgentId : '';
        echo '<label for="sender_counteragent_id">ID контрагента-отправителя <span class="hint">(целое число из личного кабинета ДЛ → Адресная книга)</span></label>';
        echo '<input type="number" id="sender_counteragent_id" name="sender_counteragent_id" min="1" value="' . $h($savedCaid) . '" placeholder="Например: 456783515">';

        $freightUid  = $h($s->freightUid ?? '');
        echo '<label>Характер груза <span class="hint">(из справочника Деловых Линий)</span></label>';
        echo '<input type="text" id="freightSearch" autocomplete="off" placeholder="Начните вводить название груза…">';
        echo '<ul id="freightSuggestions"></ul>';
        echo '<input type="hidden" id="freight_uid" name="freight_uid" value="' . $freightUid . '">';
        echo '<p id="freightSelected" class="hint">';
        echo $freightUid !== '' ? 'Сохранённый UID: <code>' . $freightUid . '</code>' : 'Не выбрано';
        echo '</p>';

        echo '<h2>Данные отправителя</h2>';
        echo '<p class="hint">Используются при оформлении заявки в Деловые Линии.</p>';
        echo '<button type="button" id="btnLoadFromInsales" class="btn-secondary" style="margin-top:0">Загрузить из inSales</button>';

        echo '<label for="sender_type">Тип отправителя</label>';
        echo '<select id="sender_type" name="sender_type" onchange="toggleSenderType()">';
        echo '<option value="person"' . ($s->senderType === 'person' ? ' selected' : '') . '>Физическое лицо</option>';
        echo '<option value="ip"'     . ($s->senderType === 'ip'     ? ' selected' : '') . '>ИП</option>';
        echo '<option value="company"' . ($s->senderType === 'company' ? ' selected' : '') . '>Юридическое лицо</option>';
        echo '</select>';

        echo '<label for="sender_name">ФИО / Название организации</label>';
        echo '<input type="text" id="sender_name" name="sender_name" value="' . $h($s->senderName ?? '') . '" placeholder="Иванов Иван Иванович / ООО Ромашка">';

        $showInn = in_array($s->senderType, ['ip', 'company'], true);
        echo '<div id="blockInn"' . ($showInn ? '' : ' style="display:none"') . '>';
        echo '<label for="sender_inn">ИНН</label>';
        echo '<input type="text" id="sender_inn" name="sender_inn" value="' . $h($s->senderInn ?? '') . '" placeholder="1234567890">';
        echo '</div>';

        $showDoc = $s->senderType !== 'company';
        echo '<div id="blockDoc"' . ($showDoc ? '' : ' style="display:none"') . '>';
        echo '<label for="sender_doc_type">Тип документа</label>';
        echo '<select id="sender_doc_type" name="sender_doc_type">';
        echo '<option value="passport"'      . ($s->senderDocType === 'passport'       ? ' selected' : '') . '>Паспорт РФ</option>';
        echo '<option value="drivingLicence"' . ($s->senderDocType === 'drivingLicence' ? ' selected' : '') . '>Водительское удостоверение</option>';
        echo '</select>';
        echo '<label for="sender_doc_serial">Серия</label>';
        echo '<input type="text" id="sender_doc_serial" name="sender_doc_serial" value="' . $h($s->senderDocSerial ?? '') . '" placeholder="5222">';
        echo '<label for="sender_doc_number">Номер</label>';
        echo '<input type="text" id="sender_doc_number" name="sender_doc_number" value="' . $h($s->senderDocNumber ?? '') . '" placeholder="191652">';
        echo '</div>';

        echo '<label for="sender_contact_name">Контактное лицо</label>';
        echo '<input type="text" id="sender_contact_name" name="sender_contact_name" value="' . $h($s->senderContactName ?? '') . '" placeholder="Иванов Иван">';
        echo '<label for="sender_contact_phone">Телефон контактного лица</label>';
        echo '<input type="text" id="sender_contact_phone" name="sender_contact_phone" value="' . $h($s->senderContactPhone ?? '') . '" placeholder="79131409995">';

        echo '<h2>Груз по умолчанию</h2>';
        echo '<p class="hint">Если у варианта товара в inSales не заполнены вес или габариты.</p>';
        echo '<label for="default_weight_kg">Вес, кг</label>';
        echo '<input type="number" step="0.001" min="0.01" id="default_weight_kg" name="default_weight_kg" value="' . $h((string) $s->defaultWeightKg) . '">';
        echo '<label for="default_dimensions_cm">Габариты, см (Д×Ш×В)</label>';
        echo '<input type="text" id="default_dimensions_cm" name="default_dimensions_cm" value="' . $h($s->defaultDimensionsCm) . '">';
        echo '<label for="default_stated_value">Объявленная стоимость, ₽</label>';
        echo '<input type="number" step="0.01" min="0" id="default_stated_value" name="default_stated_value" value="' . $h((string) $s->defaultStatedValue) . '">';

        echo '<h2>Срок и активность</h2>';
        echo '<label for="produce_days_offset">Дней до отгрузки</label>';
        echo '<input type="number" min="0" max="30" id="produce_days_offset" name="produce_days_offset" value="' . $h((string) $s->produceDaysOffset) . '">';
        echo '<div class="row-check">';
        echo '<input type="checkbox" id="is_enabled" name="is_enabled" value="1"' . ($s->isEnabled ? ' checked' : '') . '>';
        echo '<label for="is_enabled" style="margin:0">Включить расчёт доставки</label>';
        echo '</div>';

        echo '<button type="submit">Сохранить</button>';
        echo '</form>';

        echo '<h2>Доступ к API inSales</h2>';
        echo '<p class="hint">Нужен для кнопки создания способа доставки. Token — из адреса после установки приложения (<code>?token=...</code>).</p>';
        echo '<form method="post" action="/insales/app">';
        echo '<input type="hidden" name="shop" value="' . $h($s->shopHost) . '">';
        echo '<input type="hidden" name="insales_id" value="' . $h($s->insalesId) . '">';
        echo '<input type="hidden" name="update_install_token" value="1">';
        echo '<label for="install_token">Token установки</label>';
        echo '<input type="text" id="install_token" name="install_token" placeholder="из URL установки приложения">';
        echo '<button type="submit" class="btn-secondary">Обновить доступ</button>';
        echo '</form>';

        echo '<form method="post" action="/insales/app" style="margin-top:1rem">';
        echo '<input type="hidden" name="shop" value="' . $h($s->shopHost) . '">';
        echo '<input type="hidden" name="insales_id" value="' . $h($s->insalesId) . '">';
        echo '<input type="hidden" name="create_pickup_delivery" value="1">';
        echo '<button type="submit" class="btn-secondary">Создать способ доставки «до терминала» в inSales</button>';
        echo '</form>';

        $shopQ = rawurlencode($s->shopHost);
        $iidQ = rawurlencode($s->insalesId);
        echo '<script>';
        echo '(function(){';
        echo 'var savedId=' . json_encode($tid) . ';';
        echo 'var shopQ=' . json_encode($shopQ) . ';';
        echo 'var iidQ=' . json_encode($iidQ) . ';';
        echo 'var apiBase="/insales/cities/search?shop="+shopQ+"&insales_id="+iidQ+"&q=";';
        echo 'var termBase="/insales/terminals?shop="+shopQ+"&insales_id="+iidQ;';
        echo 'var freightBase="/insales/freight/search?shop="+shopQ+"&insales_id="+iidQ+"&q=";';
        echo 'function $(id){return document.getElementById(id);}';
        echo 'function fetchJson(u){return fetch(u,{headers:{Accept:"application/json"}}).then(function(r){return r.json();});}';
        echo 'function bindCity(inputId,listId,onPick){var input=$(inputId),list=$(listId),timer;';
        echo 'input.addEventListener("input",function(){clearTimeout(timer);var q=input.value.trim();list.style.display="none";if(q.length<2)return;';
        echo 'timer=setTimeout(function(){fetchJson(apiBase+encodeURIComponent(q)).then(function(j){';
        echo 'if(!j.ok)return;list.innerHTML="";(j.cities||[]).slice(0,12).forEach(function(c){';
        echo 'var code=c.code||c.kladr||"";if(!code)return;var li=document.createElement("li");li.textContent=c.name||c.searchString||code;';
        echo 'li.addEventListener("click",function(){input.value=li.textContent;list.style.display="none";onPick(code);});list.appendChild(li);});';
        echo 'list.style.display=list.children.length?"block":"none";});},350);});}';
        echo 'function loadTerminals(kladr){var sel=$("sender_terminal_id"),st=$("termStatus");st.textContent="Загрузка…";sel.disabled=true;';
        echo 'fetchJson(termBase+"&limit=200&city_kladr="+encodeURIComponent(kladr)).then(function(j){';
        echo 'if(!j.ok)throw new Error(j.error||"err");sel.innerHTML="";var o=document.createElement("option");o.value="";o.textContent="— выберите —";sel.appendChild(o);';
        echo '(j.terminals||[]).forEach(function(t){var opt=document.createElement("option");opt.value=t.id;';
        echo 'opt.textContent="ID "+t.id+" — "+(t.name||"")+(t.address?" — "+t.address:"");';
        echo 'if(String(t.id)===String(savedId))opt.selected=true;sel.appendChild(opt);});';
        echo 'sel.disabled=!(j.count>0);st.textContent="Найдено: "+(j.count||0);}).catch(function(e){st.textContent="Ошибка: "+e.message;});}';
        echo 'bindCity("citySearch","citySuggestions",loadTerminals);';
        echo 'bindCity("pickupCitySearch","pickupCitySuggestions",function(code){$("derival_city_kladr").value=code;});';
        echo 'function toggleDerival(){var t=$("dv_terminal").checked;$("blockTerminal").style.display=t?"":"none";';
        echo '$("blockAddress").style.display=t?"none":"";$("sender_terminal_id").disabled=!t;}';
        echo '$("dv_terminal").addEventListener("change",toggleDerival);';
        echo '$("dv_address").addEventListener("change",toggleDerival);';
        echo '(function(){';
        echo 'var fi=$("freightSearch"),fl=$("freightSuggestions"),fh=$("freight_uid"),fs=$("freightSelected"),timer;';
        echo 'if(!fi)return;';
        echo 'fi.addEventListener("input",function(){clearTimeout(timer);var q=fi.value.trim();fl.style.display="none";if(q.length<2)return;';
        echo 'timer=setTimeout(function(){fetchJson(freightBase+encodeURIComponent(q)).then(function(j){';
        echo 'if(!j.ok)return;fl.innerHTML="";(j.items||[]).slice(0,15).forEach(function(it){';
        echo 'var li=document.createElement("li");li.textContent=it.name+(it.comment?" — "+it.comment.slice(0,60):"");';
        echo 'li.title=it.uid;';
        echo 'li.addEventListener("click",function(){';
        echo 'fh.value=it.uid;fi.value=it.name;';
        echo 'fs.innerHTML="Выбрано: <strong>"+it.name+"</strong> <code>"+it.uid+"</code>";';
        echo 'fl.style.display="none";});fl.appendChild(li);});';
        echo 'fl.style.display=fl.children.length?"block":"none";});},350);});';
        echo 'document.addEventListener("click",function(e){if(!fi.contains(e.target)&&!fl.contains(e.target))fl.style.display="none";});';
        echo '})();';
        echo 'function toggleSenderType(){var t=document.getElementById("sender_type").value;document.getElementById("blockInn").style.display=(t==="ip"||t==="company")?"":"none";document.getElementById("blockDoc").style.display=(t==="company")?"none":"";}';
        echo 'document.getElementById("btnLoadFromInsales").addEventListener("click",function(){var btn=this;btn.disabled=true;btn.textContent="Загрузка…";fetch("/insales/account?shop="+shopQ+"&insales_id="+iidQ).then(function(r){return r.json();}).then(function(d){if(!d.ok)throw new Error(d.error||"Ошибка");if(d.organization)document.getElementById("sender_name").value=d.organization;if(d.phone)document.getElementById("sender_contact_phone").value=d.phone.replace(/\\D/g,"");if(d.email)document.getElementById("requester_email").value=d.email;var name=(d.organization||"").toLowerCase();var sel=document.getElementById("sender_type");if(name.indexOf("ип ")===0||name.indexOf("ип\\"")===-1){sel.value="ip";}else if(name.indexOf("ооо")!==-1||name.indexOf("ао")!==-1||name.indexOf("зао")!==-1){sel.value="company";}toggleSenderType();btn.textContent="✓ Загружено";}).catch(function(e){alert("Ошибка: "+e.message);btn.disabled=false;btn.textContent="Загрузить из inSales";});});';
        echo '})();</script>';
        echo '</body></html>';
    }

    /**
     * @param list<DellinCounteragent> $counteragents
     * @param callable(string): string $h
     */
    private static function renderCounteragentBlock(
        callable $h,
        ShopSettings $s,
        array $counteragents,
        ?string $counteragentsError,
    ): void {
        echo '<div class="info" style="margin:.75rem 0 1rem">';
        echo '<strong>Контрагент по PAT</strong><br>';

        if ($counteragentsError !== null) {
            echo '<span class="err">' . $h($counteragentsError) . '</span>';
            echo '</div>';
            echo '<label for="counteragent_uid">UID контрагента <span class="hint">вручную, если список не загрузился</span></label>';
            echo '<input type="text" id="counteragent_uid" name="counteragent_uid" form="settingsForm" value="' . $h($s->counteragentUid ?? '') . '">';

            return;
        }

        if ($counteragents === []) {
            echo '<span class="hint">Список контрагентов пуст. Укажите UID вручную при необходимости.</span>';
            echo '</div>';
            echo '<label for="counteragent_uid">UID контрагента</label>';
            echo '<input type="text" id="counteragent_uid" name="counteragent_uid" form="settingsForm" value="' . $h($s->counteragentUid ?? '') . '">';

            return;
        }

        $selected = $s->counteragentUid ?? '';
        $uids = array_map(static fn (DellinCounteragent $c): string => $c->uid, $counteragents);
        if ($selected === '' && count($counteragents) === 1) {
            $selected = $counteragents[0]->uid;
        }

        if (count($counteragents) === 1) {
            $c = $counteragents[0];
            echo $h($c->name);
            echo '<input type="hidden" id="counteragent_uid" name="counteragent_uid" form="settingsForm" value="' . $h($c->uid) . '">';
            echo '</div>';

            return;
        }

        echo '<label for="counteragent_uid">Выберите контрагента</label>';
        echo '<select id="counteragent_uid" name="counteragent_uid" form="settingsForm" required>';
        echo '<option value="">— выберите —</option>';
        foreach ($counteragents as $c) {
            $sel = $c->uid === $selected ? ' selected' : '';
            echo '<option value="' . $h($c->uid) . '"' . $sel . '>' . $h($c->name) . '</option>';
        }
        if ($selected !== '' && !in_array($selected, $uids, true)) {
            echo '<option value="' . $h($selected) . '" selected>' . $h('Сохранённый UID ' . $selected) . '</option>';
        }
        echo '</select>';
        echo '</div>';
    }

    private static function renderHead(string $title): void
    {
        echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
        echo '<style>';
        echo 'body{font-family:system-ui,sans-serif;max-width:640px;margin:1.5rem auto;padding:0 1rem;color:#1a1a1a}';
        echo 'h1{font-size:1.35rem}h2{font-size:1rem;margin:1.5rem 0 .5rem;border-bottom:1px solid #e5e5e5;padding-bottom:.35rem}';
        echo 'label{display:block;margin:.6rem 0 .2rem;font-weight:500;font-size:.9rem}';
        echo '.hint{font-size:.8rem;color:#666;font-weight:400}';
        echo 'input,select{width:100%;padding:.5rem;box-sizing:border-box;font-size:14px;border:1px solid #ccc;border-radius:4px}';
        echo 'input[type=checkbox],input[type=radio]{width:auto;margin-right:.4rem}';
        echo '.row-check{display:flex;align-items:center;margin:.5rem 0}';
        echo 'button,.btn-secondary{margin-top:1.25rem;padding:.65rem 1.4rem;cursor:pointer;background:#3d5afe;color:#fff;border:0;border-radius:6px;font-size:14px}';
        echo '.btn-secondary{background:#555;margin-top:.5rem}';
        echo '.ok{color:#0a0}.err{color:#c00}';
        echo '#citySuggestions,#pickupCitySuggestions,#freightSuggestions{list-style:none;margin:0;padding:0;border:1px solid #ccc;border-radius:4px;max-height:180px;overflow:auto;display:none}';
        echo '#citySuggestions li,#pickupCitySuggestions li,#freightSuggestions li{padding:.45rem .75rem;cursor:pointer;border-bottom:1px solid #eee;font-size:.875rem}';
        echo '#citySuggestions li:hover,#pickupCitySuggestions li:hover,#freightSuggestions li:hover{background:#f0f4ff}';
        echo 'code{font-size:.75rem;background:#f5f5f5;padding:.1rem .3rem;border-radius:3px}';
        echo '</style></head><body>';
    }

    private static function renderError(string $message): void
    {
        self::renderHead('Ошибка');
        echo '<p class="err">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></body></html>';
    }
}
