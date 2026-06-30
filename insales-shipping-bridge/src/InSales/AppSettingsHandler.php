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

        $shopHost  = trim((string) ($_GET['shop']       ?? $_POST['shop']       ?? ''));
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

        // Защита от перебора insales_id: если у магазина задан access-токен,
        // требуем его совпадения. Если запрос пришёл из inSales (есть user_id) — пропускаем.
        $requiredAccessToken = $shops->findAccessToken($settings->insalesId);
        if ($requiredAccessToken !== null) {
            $providedAccessToken = trim((string) ($_GET['atk'] ?? $_POST['atk'] ?? ''));
            $hasInsalesSession   = trim((string) ($_GET['user_id'] ?? '')) !== '';
            if (!$hasInsalesSession) {
                if ($providedAccessToken === '' || !hash_equals($requiredAccessToken, $providedAccessToken)) {
                    http_response_code(403);
                    self::renderError('Доступ запрещён. Откройте приложение через раздел «Приложения» в админке вашего магазина inSales.');
                    return;
                }
            }
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
                // фоновое обновление, не блокируем UI
            }
        }

        $error           = null;
        $saved           = false;
        $deliveryCreated = null;

        if ($method === 'POST' && isset($_POST['save_dellin_auth'])) {
            $error = self::handleDellinAuth($shops, $config, $settings);
            if ($error === null) {
                $q = http_build_query([
                    'shop' => $settings->shopHost,
                    'insales_id' => $settings->insalesId,
                    'atk' => $shops->findAccessToken($settings->insalesId) ?? '',
                ]);
                header('Location: /insales/app?' . $q, true, 302);
                exit;
            }
        } elseif (!$settings->hasDellinAuth) {
            self::renderAuthPage($settings, $error, $shops->findAccessToken($settings->insalesId) ?? '');
            return;
        } elseif ($method === 'POST' && isset($_POST['update_pat'])) {
            $error = self::handleDellinAuth($shops, $config, $settings);
            if ($error === null) {
                $saved = true;
            }
            $settings = $shops->findSettingsByInsalesId($settings->insalesId, $config) ?? $settings;
        } elseif ($method === 'POST' && isset($_POST['create_pickup_delivery'])) {
            try {
                $auth = $shops->findApiAuthByInsalesId($settings->insalesId);
                if ($auth === null) {
                    throw new \RuntimeException('Нет данных авторизации магазина. Переустановите приложение.');
                }
                $setup           = new InSalesDeliverySetup(new InSalesClient(), $config);
                $deliveryCreated = $setup->createPickUpDeliveryVariant($auth['shop_host'], $auth['api_password']);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
            $settings = $shops->findSettingsByInsalesId($settings->insalesId, $config) ?? $settings;
        } elseif ($method === 'POST') {
            try {
                $senderType = trim((string) ($_POST['sender_type'] ?? 'person'));
                $senderInn  = trim((string) ($_POST['sender_inn']  ?? ''));
                if (in_array($senderType, ['ip', 'company'], true) && $senderInn === '') {
                    throw new \RuntimeException('Заполните ИНН — он обязателен для ИП и юридических лиц.');
                }
                $senderOpfUid = trim((string) ($_POST['sender_opf_uid'] ?? ''));
                if (in_array($senderType, ['ip', 'company'], true) && $senderOpfUid === '') {
                    throw new \RuntimeException('Выберите ОПФ из справочника Деловых Линий.');
                }
                $types = array_filter(
                    (array) ($_POST['delivery_types'] ?? ['auto']),
                    static fn(string $t): bool => in_array($t, ['auto', 'avia', 'express', 'small'], true)
                );
                $variant = (string) ($_POST['derival_variant'] ?? ShopSettings::DERIVAL_TERMINAL);
                \ShippingBridge\Logger::info($settings->insalesId, null, 'settings.save', [
                    'derival_variant' => $variant,
                    'delivery_types' => implode(',', $types) ?: 'auto',
                    'sender_type' => trim((string) ($_POST['sender_type'] ?? 'person')),
                    'has_inn' => trim((string) ($_POST['sender_inn'] ?? '')) !== '' ? 'yes' : 'no',
                    'sender_phone' => \ShippingBridge\Logger::maskPhone((string) ($_POST['sender_contact_phone'] ?? '')),
                    'requester_email' => \ShippingBridge\Logger::maskEmail(trim((string) ($_POST['requester_email'] ?? ''))),
                ]);
                $shops->saveDeliverySettings($settings->insalesId, [
                    'derival_variant'       => $variant,
                    'sender_terminal_id'    => (int) ($_POST['sender_terminal_id']    ?? 0),
                    'derival_city_kladr'    => trim((string) ($_POST['derival_city_kladr'] ?? '')),
                    'derival_street'        => trim((string) ($_POST['derival_street']     ?? '')),
                    'derival_house'         => trim((string) ($_POST['derival_house']      ?? '')),
                    'derival_time_from'     => trim((string) ($_POST['derival_time_from']  ?? '')),
                    'derival_time_to'       => trim((string) ($_POST['derival_time_to']    ?? '')),
                    'derival_break_from'    => trim((string) ($_POST['derival_break_from'] ?? '')),
                    'derival_break_to'      => trim((string) ($_POST['derival_break_to']   ?? '')),
                    'requester_email'       => trim((string) ($_POST['requester_email']    ?? '')),
                    'counteragent_uid'      => trim((string) ($_POST['counteragent_uid']   ?? '')) ?: null,
                    'sender_counteragent_id' => (int) ($_POST['sender_counteragent_id'] ?? 0) ?: null,
                    'freight_uid'           => trim((string) ($_POST['freight_uid']    ?? '')) ?: null,
                    'freight_name' => trim((string) ($_POST['freight_name'] ?? '')),
                    'package_uid' => trim((string) ($_POST['package_uid'] ?? '')),
                    'package_name' => trim((string) ($_POST['package_name'] ?? '')),
                    'package_in_calc' => isset($_POST['package_in_calc']),
                    'produce_days_offset'   => (int) ($_POST['produce_days_offset']    ?? 2),
                    'default_stated_value'  => (float) str_replace(',', '.', (string) ($_POST['default_stated_value'] ?? '0')),
                    'default_weight_kg'     => (float) str_replace(',', '.', (string) ($_POST['default_weight_kg']    ?? '1')),
                    'default_dimensions_cm' => trim((string) ($_POST['default_dimensions_cm'] ?? '20x20x20')),
                    'is_enabled'            => isset($_POST['is_enabled']),
                    'sender_name'           => trim((string) ($_POST['sender_name']           ?? '')),
                    'sender_type'           => trim((string) ($_POST['sender_type']           ?? 'person')),
                    'sender_inn'            => trim((string) ($_POST['sender_inn']            ?? '')),
                    'sender_doc_type'       => trim((string) ($_POST['sender_doc_type']       ?? 'passport')),
                    'sender_doc_serial'     => trim((string) ($_POST['sender_doc_serial']     ?? '')),
                    'sender_doc_number'     => trim((string) ($_POST['sender_doc_number']     ?? '')),
                    'sender_contact_name'   => trim((string) ($_POST['sender_contact_name']   ?? '')),
                    'sender_contact_phone'  => self::buildContactPhoneField(
                        trim((string) ($_POST['sender_contact_phone']  ?? '')),
                        trim((string) ($_POST['sender_contact_phone2'] ?? '')),
                        trim((string) ($_POST['sender_contact_ext']    ?? ''))
                    ),
                    'sender_opf_uid'        => trim((string) ($_POST['sender_opf_uid']        ?? '')),
                    'sender_opf_name' => trim((string) ($_POST['sender_opf_name'] ?? '')),
                    'sender_juridical_address' => trim((string) ($_POST['sender_juridical_address'] ?? '')),
                    'delivery_payer' => trim((string) ($_POST['delivery_payer'] ?? 'sender')),
                    'requester_role' => trim((string) ($_POST['requester_role'] ?? 'sender')),
                    'delivery_types' => implode(',', $types) ?: 'auto',
                ]);
                $settings = $shops->findSettingsByInsalesId($settings->insalesId, $config) ?? $settings;
                $saved    = true;
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        [$counteragents, $counteragentsError] = self::loadCounteragents($shops, $config, $settings);

        $subscriptionPdo = \ShippingBridge\Db::pdo($config);
        $subscriptionRepo = new \ShippingBridge\SubscriptionRepository($subscriptionPdo);
        $subscriptionData = $subscriptionRepo->findByInsalesId($settings->insalesId);

        http_response_code(200);
        self::renderSettingsPage(
            $settings,
            $config,
            $saved,
            $deliveryCreated,
            $error,
            $counteragents,
            $counteragentsError,
            $shops->findAccessToken($settings->insalesId) ?? '',
            $subscriptionData
        );
    }
 
    // ──────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────────────────

    /** @return array{0:list<DellinCounteragent>,1:?string} */
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
        if (\ShippingBridge\RateLimiter::isBlocked($config, 'dellin_auth', 5, 300)) {
            return 'Слишком много попыток. Подождите 5 минут и попробуйте снова.';
        }
        if ($config->bridgeSecret === '') {
            return 'Задайте BRIDGE_SECRET в .env на сервере.';
        }
        $appkey = trim((string) ($_POST['dellin_appkey'] ?? ''));
        $pat    = trim((string) ($_POST['dellin_pat']    ?? ''));
        if ($pat === '') {
            return 'Укажите персональный токен (PAT).';
        }
        // При обновлении PAT — берём существующий appkey из БД
        if ($appkey === '' && $config->bridgeSecret !== '') {
            $existing = $shops->findCarrierCredentials($settings->insalesId, $config->bridgeSecret);
            if ($existing !== null) {
                $appkey = $existing->appkey;
            }
        }
        if ($appkey === '') {
            return 'Укажите API-ключ (appkey).';
        }
        try {
            $api = new CarrierApi($config);
            $api->loginWithPat(new CarrierCredentials($appkey, $pat));
            $shops->saveDellinCredentials($settings->insalesId, $appkey, $pat, $config->bridgeSecret);
            \ShippingBridge\RateLimiter::recordAttempt($config, 'dellin_auth', true);
        } catch (\Throwable $e) {
            \ShippingBridge\RateLimiter::recordAttempt($config, 'dellin_auth', false);
            return 'Не удалось авторизоваться в Dellin: ' . $e->getMessage();
        }
        return null;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Render: Auth page (first time)
    // ──────────────────────────────────────────────────────────────────────────

    private static function renderAuthPage(ShopSettings $s, ?string $error, string $accessToken = ''): void
    {
        $h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        self::renderHtmlHead('Подключение — Деловые Линии');
?>
        <div class="auth-wrap">
            <div class="auth-card">
                <div class="auth-logo">
                    <svg viewBox="0 0 1000 880" xmlns="http://www.w3.org/2000/svg" width="40" height="40">
                        <polygon fill="#1a1714" points="574,713 190,544 108,688 540,772" />
                        <polygon fill="#1a1714" points="739,422 499,0 409,159 697,497" />
                        <polygon fill="#1a1714" points="678,531 390,193 308,336 644,589" />
                        <polygon fill="#1a1714" points="626,621 290,368 208,513 592,681" />
                        <polygon fill="#1a1714" points="90,721 0,880 479,880 521,805" />
                        <polygon fill="#1a1714" points="520,880 648,880 760,682 872,880 1000,880 760,457" />
                    </svg>
                </div>
                <div class="auth-title">Подключение к Деловым Линиям</div>
                <div class="auth-sub">Магазин: <strong><?= $h($s->shopHost) ?></strong></div>
                <p class="auth-desc">Укажите API-ключ и персональный токен (PAT) из личного кабинета ДЛ → Настройки → Интеграция.</p>
                <?php if ($error !== null): ?>
                    <div class="alert-err"><?= $h($error) ?></div>
                <?php endif; ?>
                <form method="post" action="/insales/app">
                    <input type="hidden" name="shop" value="<?= $h($s->shopHost) ?>">
                    <input type="hidden" name="insales_id" value="<?= $h($s->insalesId) ?>">
                    <input type="hidden" name="atk" value="<?= $h($accessToken) ?>">
                    <input type="hidden" name="save_dellin_auth" value="1">
                    <div class="field">
                        <label>Персональный токен (PAT)</label>
                        <input type="password" name="dellin_pat" required autocomplete="off" placeholder="dl-api-…">
                    </div>
                    <button type="submit" class="btn-p" style="width:100%;margin-top:8px">Подключить →</button>
                </form>
                <a href="https://dev.dellin.ru/api/swagger/" target="_blank" class="auth-link">Документация API ДЛ ↗</a>
            </div>
        </div>
    <?php
        echo '</body></html>';
    }
 
    // ──────────────────────────────────────────────────────────────────────────
    // Render: Main settings page
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @param array{id:int,title:string}|null $deliveryCreated
     * @param list<DellinCounteragent>         $counteragents
     */
    private static function renderSettingsPage(
        ShopSettings $s,
        Config $config,
        bool $saved,
        ?array $deliveryCreated,
        ?string $error,
        array $counteragents,
        ?string $counteragentsError,
        string $accessToken = '',
        ?array $subscription = null,
    ): void {
        $h   = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $tid = $s->senderTerminalId !== null && $s->senderTerminalId > 0 ? (string) $s->senderTerminalId : '';
        $isTerminal = $s->isDerivalTerminal();

        // Counteragent uid
        $counteragentUid = $s->counteragentUid ?? '';
        if ($counteragentUid === '' && count($counteragents) === 1) {
            $counteragentUid = $counteragents[0]->uid;
        }
        $counteragentUidNorm = $counteragentUid;
        $counteragentName = '';
        foreach ($counteragents as $c) {
            // Сравниваем без 0x префикса
            $cClean = strtolower(ltrim($c->uid, '0x'));
            $uClean = strtolower(ltrim($counteragentUid, '0x'));
            if ($c->uid === $counteragentUid || $cClean === $uClean) {
                $counteragentName = $c->name;
                $counteragentUidNorm = $c->uid;
                break;
            }
        }

        $shopQ = rawurlencode($s->shopHost);
        $iidQ  = rawurlencode($s->insalesId);

        self::renderHtmlHead('Настройки — Деловые Линии');

        $consentGiven = !empty($_COOKIE['consent_given']);
        $hasDellinAuth = $s->hasDellinAuth ?? false;
        $showOnboarding = !$consentGiven;
    ?>

        <?php if ($showOnboarding): ?>
            <div id="onboardingOverlay" style="position:fixed;inset:0;z-index:9999;background:rgba(26,23,20,.6);backdrop-filter:blur(3px);display:flex;align-items:center;justify-content:center;padding:20px">
                <div style="background:var(--s1);border:1px solid var(--line2);border-radius:var(--r3);padding:28px;max-width:440px;width:100%;box-shadow:0 24px 64px rgba(26,23,20,.3)">

                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:18px">
                        <div style="width:34px;height:34px;background:var(--gold);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;padding:5px">
                            <svg viewBox="0 0 1000 880" xmlns="http://www.w3.org/2000/svg">
                                <polygon fill="#1a1714" points="574,713 190,544 108,688 540,772" />
                                <polygon fill="#1a1714" points="739,422 499,0 409,159 697,497" />
                                <polygon fill="#1a1714" points="678,531 390,193 308,336 644,589" />
                                <polygon fill="#1a1714" points="626,621 290,368 208,513 592,681" />
                                <polygon fill="#1a1714" points="90,721 0,880 479,880 521,805" />
                                <polygon fill="#1a1714" points="520,880 648,880 760,682 872,880 1000,880 760,457" />
                            </svg>
                        </div>
                        <div>
                            <div style="font-size:13px;font-weight:600;color:var(--ink)">Деловые Линии</div>
                            <div style="font-size:10px;color:var(--ink3);font-family:var(--mono)">Добро пожаловать</div>
                        </div>
                        <div style="margin-left:auto;font-size:10px;font-weight:600;font-family:var(--mono);background:var(--grnl);color:var(--grn);border:1px solid var(--grnb);padding:3px 9px;border-radius:20px">Первый вход</div>
                    </div>

                    <div style="height:1px;background:var(--s3);margin-bottom:18px"></div>

                    <?php if (!$consentGiven): ?>
                        <div style="font-size:10px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--ink3);margin-bottom:10px;display:flex;align-items:center;gap:8px">Согласие на обработку данных<span style="flex:1;height:1px;background:var(--s3)"></span></div>

                        <label id="consentPdRow" style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;padding:10px 12px;border:1px solid #f5c4b3;border-radius:var(--r2);background:var(--ambl);margin-bottom:8px">
                            <input type="checkbox" id="consentPd" style="margin-top:2px;accent-color:var(--amber);flex-shrink:0;width:14px;height:14px">
                            <span style="font-size:12px;color:var(--ink2);line-height:1.5">Согласен на обработку персональных данных согласно <a href="/privacy.html" target="_blank" style="color:var(--amber);text-decoration:none">Политике конфиденциальности</a> ИП Андронов Ю. В. <span style="color:var(--amber)">*</span></span>
                        </label>
                        <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;padding:10px 12px;border:1px solid var(--line);border-radius:var(--r2);background:var(--s2);margin-bottom:18px">
                            <input type="checkbox" id="consentCookies" style="margin-top:2px;accent-color:var(--amber);flex-shrink:0;width:14px;height:14px">
                            <span style="font-size:12px;color:var(--ink2);line-height:1.5">Согласен на использование файлов cookie для работы сервиса</span>
                        </label>
                        <div style="height:1px;background:var(--s3);margin-bottom:18px"></div>
                    <?php endif; ?>

                    <?php if (!$hasDellinAuth): ?>
                        <div style="font-size:10px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--ink3);margin-bottom:10px;display:flex;align-items:center;gap:8px">Подключение к Деловым Линиям<span style="flex:1;height:1px;background:var(--s3)"></span></div>

                        <div style="background:var(--s2);border:1px solid var(--line);border-radius:var(--r2);padding:12px 14px;margin-bottom:12px">
                            <div style="display:flex;flex-direction:column;gap:7px;margin-bottom:10px">
                                <div style="display:flex;align-items:flex-start;gap:8px;font-size:12px;color:var(--ink2)">
                                    <span style="width:18px;height:18px;border-radius:50%;background:var(--ambl);border:1px solid #f5c4b3;color:var(--amber);font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px">1</span>
                                    Войдите в личный кабинет Деловых Линий
                                </div>
                                <div style="display:flex;align-items:flex-start;gap:8px;font-size:12px;color:var(--ink2)">
                                    <span style="width:18px;height:18px;border-radius:50%;background:var(--ambl);border:1px solid #f5c4b3;color:var(--amber);font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px">2</span>
                                    Перейдите в Настройки → Интеграция API
                                </div>
                                <div style="display:flex;align-items:flex-start;gap:8px;font-size:12px;color:var(--ink2)">
                                    <span style="width:18px;height:18px;border-radius:50%;background:var(--ambl);border:1px solid #f5c4b3;color:var(--amber);font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px">3</span>
                                    Создайте токен доступа (PAT) и вставьте ниже
                                </div>
                            </div>
                            <a href="https://lk.dellin.ru" target="_blank" style="display:inline-flex;align-items:center;gap:5px;font-size:12px;color:var(--amber);text-decoration:none;font-weight:600;padding:6px 10px;background:var(--ambl);border:1px solid #f5c4b3;border-radius:var(--r)">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6" />
                                    <polyline points="15 3 21 3 21 9" />
                                    <line x1="10" y1="14" x2="21" y2="3" />
                                </svg>
                                Открыть ЛК Деловых Линий
                            </a>
                        </div>

                        <div style="margin-bottom:12px">
                            <div style="font-size:11px;font-weight:600;color:var(--ink2);letter-spacing:.03em;text-transform:uppercase;margin-bottom:5px">Персональный токен (PAT)</div>
                            <input type="password" id="onboardingPat" placeholder="dl-api-…" autocomplete="off"
                                style="width:100%;padding:9px 12px;background:var(--s2);border:1px solid var(--line);border-radius:var(--r2);font-size:13px;color:var(--ink);font-family:var(--sans);outline:none;box-sizing:border-box">
                            <div style="font-size:11px;color:var(--ink3);margin-top:4px">Найти в ЛК ДЛ → Настройки → Интеграция API</div>
                        </div>
                    <?php endif; ?>

                    <div id="onboardingErr" style="font-size:12px;color:#b91c1c;margin-bottom:10px;display:none"></div>

                    <button id="onboardingSubmit" disabled
                        style="width:100%;padding:11px;background:var(--s3);border:0;border-radius:var(--r2);color:var(--ink3);font-size:13px;font-weight:600;cursor:not-allowed;font-family:var(--sans);transition:all .2s">
                        Подключить и начать работу
                    </button>
                    <div style="margin-top:10px;font-size:11px;color:var(--ink3);text-align:center">* Обязательное поле. Без согласия использование приложения невозможно.</div>
                </div>
            </div>

            <script>
                (function() {
                    var consentGiven = <?= json_encode($consentGiven) ?>;
                    var hasAuth = <?= json_encode($hasDellinAuth) ?>;
                    var insalesId = <?= json_encode($s->insalesId) ?>;
                    var shop = <?= json_encode($s->shopHost) ?>;
                    var atk = <?= json_encode($accessToken) ?>;

                    var cbPd = document.getElementById('consentPd');
                    var cbCk = document.getElementById('consentCookies');
                    var patInput = document.getElementById('onboardingPat');
                    var btn = document.getElementById('onboardingSubmit');
                    var errEl = document.getElementById('onboardingErr');

                    function updateBtn() {
                        var pdOk = consentGiven || (cbPd && cbPd.checked);
                        var patOk = hasAuth || (patInput && patInput.value.trim().length > 4);
                        var ok = pdOk && patOk;
                        btn.disabled = !ok;
                        btn.style.background = ok ? 'var(--amber)' : 'var(--s3)';
                        btn.style.color = ok ? '#fff' : 'var(--ink3)';
                        btn.style.cursor = ok ? 'pointer' : 'not-allowed';
                    }

                    if (cbPd) cbPd.addEventListener('change', updateBtn);
                    if (cbCk) cbCk.addEventListener('change', updateBtn);
                    if (patInput) patInput.addEventListener('input', updateBtn);
                    updateBtn();

                    btn.addEventListener('click', function() {
                        if (btn.disabled) return;
                        btn.disabled = true;
                        btn.textContent = 'Сохраняем…';
                        if (errEl) errEl.style.display = 'none';

                        // Step 1: save consent if needed
                        function saveConsent(next) {
                            if (consentGiven) {
                                next();
                                return;
                            }
                            var fd = new FormData();
                            fd.append('source', 'app');
                            fd.append('insales_id', insalesId);
                            fd.append('consent_pd', cbPd && cbPd.checked ? '1' : '0');
                            fd.append('consent_cookies', cbCk && cbCk.checked ? '1' : '0');
                            fetch('/insales/consent', {
                                    method: 'POST',
                                    body: fd
                                })
                                .then(function(r) {
                                    return r.json();
                                })
                                .then(function(d) {
                                    if (d.ok) {
                                        next();
                                    } else {
                                        showErr(d.error || 'Ошибка сохранения согласия');
                                    }
                                })
                                .catch(function() {
                                    showErr('Ошибка сети');
                                });
                        }

                        // Step 2: save PAT if needed
                        function savePat(next) {
                            if (hasAuth) {
                                next();
                                return;
                            }
                            var fd = new FormData();
                            fd.append('shop', shop);
                            fd.append('insales_id', insalesId);
                            fd.append('atk', atk);
                            fd.append('save_dellin_auth', '1');
                            fd.append('dellin_pat', patInput ? patInput.value.trim() : '');
                            fetch('/insales/app', {
                                    method: 'POST',
                                    body: fd
                                })
                                .then(function(r) {
                                    if (r.ok || r.redirected) {
                                        next();
                                    } else {
                                        showErr('Не удалось сохранить токен');
                                    }
                                })
                                .catch(function() {
                                    showErr('Ошибка сети');
                                });
                        }

                        function finish() {
                            document.getElementById('onboardingOverlay').style.display = 'none';
                            window.location.reload();
                        }

                        function showErr(msg) {
                            btn.disabled = false;
                            btn.textContent = 'Подключить и начать работу';
                            btn.style.background = 'var(--amber)';
                            btn.style.color = '#fff';
                            btn.style.cursor = 'pointer';
                            if (errEl) {
                                errEl.textContent = msg;
                                errEl.style.display = 'block';
                            }
                        }

                        saveConsent(function() {
                            savePat(finish);
                        });
                    });

                    // Focus PAT input if visible
                    if (patInput) setTimeout(function() {
                        patInput.focus();
                    }, 100);
                })();
            </script>
        <?php endif; ?>

        <div class="app">

            <!-- SIDEBAR -->
            <aside class="sidebar" id="sidebar">
                <div class="brand">
                    <div class="brand-logo">
                        <svg viewBox="0 0 1000 880" xmlns="http://www.w3.org/2000/svg">
                            <polygon fill="#1a1714" points="574,713 190,544 108,688 540,772" />
                            <polygon fill="#1a1714" points="739,422 499,0 409,159 697,497" />
                            <polygon fill="#1a1714" points="678,531 390,193 308,336 644,589" />
                            <polygon fill="#1a1714" points="626,621 290,368 208,513 592,681" />
                            <polygon fill="#1a1714" points="90,721 0,880 479,880 521,805" />
                            <polygon fill="#1a1714" points="520,880 648,880 760,682 872,880 1000,880 760,457" />
                        </svg>
                    </div>
                    <div>
                        <div class="brand-name">Деловые Линии</div>
                        <div class="brand-host"><?= $h($s->shopHost) ?></div>
                    </div>
                </div>
                <div class="sbar-status">
                    <div class="sdot"></div>
                    <div class="stxt">PAT активен</div>
                </div>
                <?php
                $planLabels = [
                    'calc_only' => 'Калькулятор',
                    'full' => 'Полный',
                    'automation' => 'Автоматизация',
                ];
                $subStatus = $subscription['status'] ?? null;
                $subPlanLabel = $planLabels[$subscription['plan'] ?? ''] ?? '—';
                ?>
                <?php if ($subStatus === 'trial' && !empty($subscription['trial_ends_at'])): ?>
                    <div class="sbar-status" style="margin-top:6px">
                        <div class="sdot" style="background:#f5a623"></div>
                        <div class="stxt">Пробный период до <?= $h(date('d.m.Y', strtotime((string) $subscription['trial_ends_at']))) ?></div>
                    </div>
                <?php elseif ($subStatus === 'active' && !empty($subscription['current_period_ends_at'])): ?>
                    <div class="sbar-status" style="margin-top:6px">
                        <div class="sdot" style="background:#3dd68c"></div>
                        <div class="stxt">Тариф «<?= $h($subPlanLabel) ?>» до <?= $h(date('d.m.Y', strtotime((string) $subscription['current_period_ends_at']))) ?></div>
                    </div>
                <?php elseif ($subStatus === 'past_due'): ?>
                    <div class="sbar-status" style="margin-top:6px">
                        <div class="sdot" style="background:#e5484d"></div>
                        <div class="stxt">Оплата просрочена</div>
                    </div>
                <?php endif; ?>
                <nav class="nav">
                    <div class="nav-lbl">Настройки</div>
                    <button class="nav-item active" data-page="sender" data-label="Отправитель"><span class="nav-ico">👤</span>Отправитель</button>
                    <button class="nav-item" data-page="shipping" data-label="Доставка"><span class="nav-ico">📦</span>Доставка</button>
                    <button class="nav-item" data-page="connection" data-label="Подключение"><span class="nav-ico">🔌</span>Подключение<span class="nav-badge">✓</span></button>
                    <button class="nav-item" data-page="support" data-label="Поддержка"><span class="nav-ico">💬</span>Поддержка</button>
                </nav>
                <div style="padding:0 12px;margin-top:8px">
                    <a href="<?= $h(rtrim($config->landingUrl ?? 'https://receptly.ru', '/')) ?>/?shop=<?= $shopQ ?>&insales_id=<?= $iidQ ?>&atk=<?= $h($accessToken) ?>" target="_blank" rel="noopener" style="display:block;text-align:center;padding:9px 12px;background:var(--amber);color:#1a1714;border-radius:8px;text-decoration:none;font-size:13px;font-weight:600">Тариф</a>
                </div>
                <div class="sbar-footer">
                    <div class="sbar-ver">inSales Bridge</div>
                </div>
            </aside>

            <!-- TOPBAR mobile -->
            <header class="topbar" id="topbar">
                <div class="burger" id="burger"><span></span><span></span><span></span></div>
                <div class="topbar-brand">
                    <div class="topbar-logo"><svg viewBox="0 0 1000 880" xmlns="http://www.w3.org/2000/svg">
                            <polygon fill="#1a1714" points="574,713 190,544 108,688 540,772" />
                            <polygon fill="#1a1714" points="739,422 499,0 409,159 697,497" />
                            <polygon fill="#1a1714" points="678,531 390,193 308,336 644,589" />
                            <polygon fill="#1a1714" points="626,621 290,368 208,513 592,681" />
                            <polygon fill="#1a1714" points="90,721 0,880 479,880 521,805" />
                            <polygon fill="#1a1714" points="520,880 648,880 760,682 872,880 1000,880 760,457" />
                        </svg></div>
                    <span class="topbar-name">Деловые Линии</span>
                </div>
                <span class="topbar-page" id="topbar-page">Отправитель</span>
            </header>
            <div class="overlay" id="overlay"></div>

            <!-- MAIN -->
            <main class="main">
                <div class="content">

                    <?php if ($saved): ?>
                        <div class="alert-ok">✓ Настройки сохранены</div>
                    <?php endif; ?>
                    <?php if ($error !== null): ?>
                        <div class="alert-err"><?= $h($error) ?></div>
                    <?php endif; ?>
                    <?php if ($deliveryCreated !== null): ?>
                        <div class="alert-ok">✓ Способ доставки создан: «<?= $h($deliveryCreated['title']) ?>» (id <?= $h((string)$deliveryCreated['id']) ?>)</div>
                    <?php endif; ?>

                    <!-- ══ ОТПРАВИТЕЛЬ ══ -->
                    <div class="page active" id="page-sender">
                        <div class="pg-hdr">
                            <div class="pg-title">Отправитель</div>
                            <div class="pg-sub">Реквизиты вашей организации для оформления заявок в Деловых Линиях</div>
                        </div>

                        <form method="post" action="/insales/app" id="settingsForm">
                            <input type="hidden" name="shop" value="<?= $h($s->shopHost) ?>">
                            <input type="hidden" name="insales_id" value="<?= $h($s->insalesId) ?>">
                            <input type="hidden" name="atk" value="<?= $h($accessToken) ?>">

                            <?php
                            // Progress checklist — Отправитель
                            $chk_type    = ($s->senderType ?? '') !== '';
                            $chk_name    = ($s->senderName ?? '') !== '';
                            $chk_inn     = $s->senderType === 'person' || ($s->senderInn ?? '') !== '';
                            $chk_contact = ($s->senderContactName ?? '') !== '' && ($s->senderContactPhone ?? '') !== '';
                            $chk_email   = ($s->requesterEmail ?? '') !== '';
                            $chk_ca      = $counteragentUid !== '';
                            $checks_sender = [$chk_type, $chk_name, $chk_inn, $chk_contact, $chk_email, $chk_ca];
                            $done_sender   = count(array_filter($checks_sender));
                            $total_sender  = count($checks_sender);
                            // Общий прогресс
                            $done_conn   = $s->hasDellinAuth ? 1 : 0;
                            $done_sender_pct = $done_sender;
                            $done_ship   = (($s->derivalVariant ?? '') !== '' && (($s->senderTerminalId ?? 0) > 0 || ($s->derivalStreet ?? '') !== '')) ? 1 : 0;
                            $total_prog  = 2 + $total_sender; // conn(1) + sender(6) + ship(1)
                            $done_prog   = $done_conn + $done_sender_pct + $done_ship;
                            $pct = (int) round($done_prog / ($total_sender + 2) * 100);
                            // Next action
                            $next_action = '';
                            if (!$chk_name) $next_action = 'Заполните название организации или ФИО.';
                            elseif (!$chk_inn && $s->senderType !== 'person') $next_action = 'Введите ИНН — он нужен для оформления заявок.';
                            elseif (!$chk_contact) $next_action = 'Укажите имя и телефон контактного лица.';
                            elseif (!$chk_email) $next_action = 'Добавьте email для уведомлений от ДЛ.';
                            elseif (!$chk_ca) $next_action = 'Выберите контрагента в Деловых Линиях.';
                            else $next_action = 'Всё готово — переходите к настройке доставки.';
                            ?>
                            php<div class="<?= $done_sender === $total_sender ? 'page-grid' : 'page-grid-3' ?>">
                                <div class="page-col">
                                    <!-- Организация -->
                                    <div class="card">
                                        <div class="card-hdr">
                                            <div>
                                                <div class="card-title">Организация</div>
                                                <div class="card-sub">Юридические данные отправителя</div>
                                            </div>
                                        </div>
                                        <div class="card-body">
                                            <div class="sec-lbl">Тип отправителя</div>
                                            <div class="seg" id="segSenderType">
                                                <button type="button" class="seg-btn<?= $s->senderType === 'person' ? ' on' : '' ?>" data-val="person">Физическое лицо</button>
                                                <button type="button" class="seg-btn<?= $s->senderType !== 'person' ? ' on' : '' ?>" data-val="org">Организация</button>
                                            </div>
                                            <input type="hidden" id="sender_type" name="sender_type" value="<?= $h($s->senderType ?? 'person') ?>">

                                            <?php
                                            $hasPersonData = ($s->senderName ?? '') !== '' && $s->senderType === 'person';
                                            $docTypeLabel = $s->senderDocType === 'drivingLicence' ? 'Вод. удостоверение' : 'Паспорт РФ';
                                            ?>
                                            <!-- ФИЗЛИЦО -->
                                            <div id="blockPerson" class="type-block<?= $s->senderType !== 'person' ? ' hidden' : '' ?>">
                                                <div id="personCard" <?= !$hasPersonData ? ' style="display:none"' : '' ?>>
                                                    <div class="term-saved">
                                                        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px">
                                                            <div>
                                                                <div class="term-name" id="personCardName"><?= $h($s->senderName ?? '') ?></div>
                                                                <div class="term-addr" id="personCardDoc"><?= $h($docTypeLabel) ?><?= ($s->senderDocSerial ?? '') !== '' ? ' · ' . $h($s->senderDocSerial) . ' ' . $h($s->senderDocNumber ?? '') : '' ?></div>
                                                            </div>
                                                            <button type="button" id="personEditBtn" class="btn-g" style="font-size:11px;padding:5px 10px;flex-shrink:0">
                                                                <svg width="12" height="12" viewBox="0 0 16 16" fill="none" style="vertical-align:-1px;margin-right:3px" aria-hidden="true">
                                                                    <path d="M11.333 2a1.886 1.886 0 012.667 2.667L5.333 13.333 2 14l.667-3.333L11.333 2z" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" />
                                                                </svg>
                                                                Изменить
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div id="personForm" <?= $hasPersonData ? ' style="display:none"' : '' ?>>
                                                    <div class="field"><label>ФИО</label><input type="text" id="sender_name_person" name="sender_name" value="<?= $h($s->senderName ?? '') ?>" placeholder="Иванов Иван Иванович"></div>
                                                    <div class="field"><label>Тип документа</label><select id="sender_doc_type" name="sender_doc_type">
                                                            <option value="passport" <?= $s->senderDocType === 'passport' ? ' selected' : '' ?>>Паспорт РФ</option>
                                                            <option value="drivingLicence" <?= $s->senderDocType === 'drivingLicence' ? ' selected' : '' ?>>Водительское удостоверение</option>
                                                        </select></div>
                                                    <div class="g2">
                                                        <div class="field"><label>Серия</label><input type="text" name="sender_doc_serial" value="<?= $h($s->senderDocSerial ?? '') ?>" placeholder="5222"></div>
                                                        <div class="field"><label>Номер</label><input type="text" name="sender_doc_number" value="<?= $h($s->senderDocNumber ?? '') ?>" placeholder="191652"></div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- ОРГАНИЗАЦИЯ -->
                                            <?php
                                            $hasOrgData = ($s->senderName ?? '') !== '' && $s->senderType !== 'person';
                                            $hasOpf = ($s->senderOpfUid ?? '') !== '';
                                            $orgCardTitle = ($s->senderOpfName ? $s->senderOpfName . ' ' : '') . ($s->senderName ?? '');
                                            ?>
                                            <div id="blockOrg" class="type-block<?= $s->senderType === 'person' ? ' hidden' : '' ?>">
                                                <div id="orgCard" <?= !$hasOrgData ? ' style="display:none"' : '' ?>>
                                                    <div class="term-saved">
                                                        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px">
                                                            <div>
                                                                <div class="term-name" id="orgCardName"><?= $h($orgCardTitle) ?></div>
                                                                <div class="term-addr" id="orgCardInn"><?= ($s->senderInn ?? '') !== '' ? 'ИНН ' . $h($s->senderInn) : 'ИНН не указан' ?></div>
                                                            </div>
                                                            <button type="button" id="orgEditBtn" class="btn-g" style="font-size:11px;padding:5px 10px;flex-shrink:0">
                                                                <svg width="12" height="12" viewBox="0 0 16 16" fill="none" style="vertical-align:-1px;margin-right:3px" aria-hidden="true">
                                                                    <path d="M11.333 2a1.886 1.886 0 012.667 2.667L5.333 13.333 2 14l.667-3.333L11.333 2z" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" />
                                                                </svg>
                                                                Изменить
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div id="orgForm" <?= $hasOrgData ? ' style="display:none"' : '' ?>>
                                                    <div class="field"><label>Название организации</label><input type="text" id="sender_name_org" name="sender_name" value="<?= $h($s->senderName ?? '') ?>" placeholder="Андронов Юрий Витальевич"></div>
                                                    <div id="opfFieldWrap" style="display:none">
                                                        <div class="field">
                                                            <label>ОПФ из справочника ДЛ</label>
                                                            <div id="opfSaved" <?= !$hasOpf ? ' style="display:none"' : '' ?> class="opf-saved">
                                                                <div>
                                                                    <div class="opf-name" id="opfSavedName"><?= $h($s->senderOpfName !== '' ? $s->senderOpfName : 'Сохранено') ?></div>
                                                                    <div class="opf-country" id="opfSavedCountry">из справочника ДЛ</div>
                                                                </div>
                                                                <button type="button" id="opfEditBtn" class="btn-g" style="font-size:11px;padding:5px 10px;flex-shrink:0">Изменить вручную</button>
                                                            </div>
                                                            <div id="opfSearchWrap" <?= $hasOpf ? ' style="display:none"' : '' ?> class="opf-search-wrap">
                                                                <input class="opf-search-input" type="text" id="opfSearchInput" autocomplete="off" placeholder="Начните вводить — ИП, ООО, АО…">
                                                                <div style="font-size:11px;color:var(--ink3);margin-top:5px">Поиск по справочнику Деловых Линий</div>
                                                                <ul id="opfList" class="opf-list"></ul>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div id="opfAutoStatus" style="font-size:11px;color:var(--ink3);margin-bottom:10px;display:none"></div>
                                                    <div class="field">
                                                        <label>ИНН</label>
                                                        <div style="position:relative">
                                                            <input type="text" id="sender_inn" name="sender_inn" value="<?= $h($s->senderInn ?? '') ?>" placeholder="1234567890 — начните вводить…" class="<?= (($s->senderInn ?? '') === '' && $s->senderType !== 'person') ? 'field-err' : '' ?>" autocomplete="off" inputmode="numeric" maxlength="12">
                                                            <ul id="innSuggestions" class="suggestions" style="position:absolute;top:100%;left:0;right:0;z-index:50"></ul>
                                                        </div>
                                                        <div class="field-err-msg" id="innErrMsg">Введите ИНН — обязательное поле для организаций</div>
                                                        <div id="innStatus" style="font-size:11px;color:var(--ink3);margin-top:4px"></div>
                                                    </div>
                                                </div>
                                                <input type="hidden" id="sender_opf_uid" name="sender_opf_uid" value="<?= $h($s->senderOpfUid ?? '') ?>">
                                                <input type="hidden" id="sender_opf_name" name="sender_opf_name" value="<?= $h($s->senderOpfName) ?>">
                                                <input type="hidden" name="freight_name" value="<?= $h($s->freightName ?? '') ?>">
                                                <input type="hidden" name="freight_uid" value="<?= $h($s->freightUid ?? '') ?>">
                                                <input type="hidden" name="package_uid" value="<?= $h($s->packageUid  ?? '') ?>">
                                                <input type="hidden" name="package_name" value="<?= $h($s->packageName ?? '') ?>">
                                                <input type="checkbox" name="package_in_calc" value="1" <?= $s->packageInCalc ? ' checked' : '' ?> style="display:none">
                                                <input type="hidden" name="derival_city_name" value="<?= $h($s->derivalCityName ?? '') ?>">
                                                <?php foreach ($s->deliveryTypes as $dt): ?>
                                                    <input type="hidden" name="delivery_types[]" value="<?= $h($dt) ?>">
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>

                                </div><!-- /page-col-left -->
                                <div class="page-col">
                                    <!-- Контрагент ДЛ -->
                                    <?php if (count($counteragents) >= 1 || $counteragentUid !== ''): ?>
                                        <div class="card">
                                            <div class="card-hdr">
                                                <div>
                                                    <div class="card-title">Контрагент в Деловых Линиях</div>
                                                    <div class="card-sub">Выберите Ваш аккаунт в системе перевозчика - тот от чьего имени создаются заявки</div>
                                                </div>
                                            </div>
                                            <div class="card-body">
                                                <div class="field">
                                                    <label>Контрагент</label>
                                                    <select name="counteragent_uid" required>
                                                        <option value="">— выберите —</option>
                                                        <?php if (count($counteragents) === 0 && $counteragentUid !== ''): ?>
                                                            <option value="<?= $h($counteragentUid) ?>" selected><?= $h($counteragentName ?: $counteragentUid) ?></option>
                                                        <?php endif; ?>
                                                        <?php foreach ($counteragents as $c): ?>
                                                            <option value="<?= $h($c->uid) ?>" <?= $c->uid === $counteragentUid ? ' selected' : '' ?>><?= $h($c->name) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                    <?php elseif (count($counteragents) === 1): ?>
                                        <div class="card">
                                            <div class="card-hdr">
                                                <div>
                                                    <div class="card-title">Ваш аккаунт в системе ДЛ — выберите от чьего имени создаются заявки</div>
                                                </div>
                                            </div>
                                            <div class="card-body sm">
                                                <div class="ir">
                                                    <span class="ir-l">Выбран контрагент</span>
                                                    <span class="ir-v"><?= $h($counteragentName) ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Контактное лицо -->
                                    <div class="card">
                                        <div class="card-hdr">
                                            <div>
                                                <div class="card-title">Контактное лицо отправителя</div>
                                                <div class="card-sub">Человек, которому ДЛ позвонит при заборе груза или если возникнут вопросы по перевозке</div>
                                            </div>
                                        </div>
                                        <div class="card-body" style="padding:14px 16px">
                                            <div class="g2" style="gap:10px">
                                                <div class="field" style="margin-bottom:0"><label>Имя</label><input type="text" id="sender_contact_name" name="sender_contact_name" value="<?= $h($s->senderContactName ?? '') ?>" placeholder="Иванов Иван"></div>
                                                <?php
                                                $ph2raw = explode(';', $s->senderContactPhone ?? '')[1] ?? '';
                                                $ph2num = preg_replace('/,.*$/', '', $ph2raw);
                                                $ph2ext = (strpos($ph2raw, ',') !== false) ? substr($ph2raw, strpos($ph2raw, ',') + 1) : '';
                                                $hasPhone2 = trim($ph2raw) !== '';
                                                ?>
                                                <div class="field" style="margin-bottom:0">
                                                    <label>Телефон</label>
                                                    <div class="phone-wrap" id="phoneWrap1">
                                                        <button type="button" class="phone-flag" id="phoneFlag1" title="Выбор страны">
                                                            <span class="flag-code" id="flagCode1">+7</span>
                                                        </button>
                                                        <input type="text" id="sender_contact_phone" name="sender_contact_phone"
                                                            value="<?= $h(explode(';', $s->senderContactPhone ?? '')[0]) ?>"
                                                            placeholder="912 345-67-89" inputmode="tel" autocomplete="tel" class="phone-input">
                                                        <div class="phone-flag-dropdown" id="phoneDropdown1" style="display:none;position:fixed"></div>
                                                    </div>
                                                    <div class="phone-valid-hint" id="phoneHint1"></div>
                                                    <div id="addPhone2Btn" style="<?= $hasPhone2 ? 'display:none;' : '' ?>margin-top:6px">
                                                        <button type="button" onclick="showPhone2()" style="font-size:11px;color:var(--amber);background:none;border:0;cursor:pointer;padding:0;font-weight:500">+ доп. номер</button>
                                                    </div>
                                                </div>
                                                <div id="phone2Block" style="grid-column:1/-1;<?= $hasPhone2 ? '' : 'display:none' ?>;margin-top:2px;padding-top:10px;border-top:1px solid var(--s3)">
                                                    <div class="g2" style="gap:10px;grid-template-columns:1fr 90px">
                                                        <div class="field" style="margin-bottom:0">
                                                            <label>Доп. номер</label>
                                                            <div class="phone-wrap" id="phoneWrap2">
                                                                <button type="button" class="phone-flag" id="phoneFlag2" title="Выбор страны">
                                                                    <span class="flag-code" id="flagCode2">+7</span>
                                                                </button>
                                                                <input type="text" id="sender_contact_phone2" name="sender_contact_phone2"
                                                                    value="<?= $h($ph2num) ?>" placeholder="495 990-12-34"
                                                                    inputmode="tel" autocomplete="tel" class="phone-input">
                                                                <div class="phone-flag-dropdown" id="phoneDropdown2" style="display:none"></div>
                                                            </div>
                                                            <div class="phone-valid-hint" id="phoneHint2"></div>
                                                        </div>
                                                        <div class="field" style="margin-bottom:0">
                                                            <label>Добавочный</label>
                                                            <input type="text" id="sender_contact_ext" name="sender_contact_ext" value="<?= $h($ph2ext) ?>" placeholder="123" inputmode="numeric" maxlength="6">
                                                        </div>
                                                    </div>
                                                    <button type="button" id="removePhone2Btn" style="font-size:11px;color:var(--ink3);background:none;border:0;cursor:pointer;padding:0;margin-top:6px">✕ Убрать доп. номер</button>
                                                </div>
                                                <div class="field" style="grid-column:1/-1;margin-bottom:0;margin-top:6px"><label>Email для уведомлений ДЛ</label><input type="email" id="requester_email" name="requester_email" value="<?= $h($s->requesterEmail) ?>" required></div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Терминал отгрузки перенесён в страницу Доставка -->
                                    <?php if (count($counteragents) === 0 && $counteragentUid !== ''): ?>
                                        <input type="hidden" name="counteragent_uid" value="<?= $h($counteragentUid) ?>">
                                    <?php endif; ?>
                                    <input type="hidden" name="sender_counteragent_id" value="<?= $h($s->senderCounterAgentId !== null ? (string)$s->senderCounterAgentId : '') ?>">
                                    <input type="hidden" name="derival_variant" value="<?= $h($s->derivalVariant ?? 'terminal') ?>">
                                    <input type="hidden" name="sender_terminal_id" value="<?= $h($tid) ?>">
                                    <input type="hidden" name="derival_city_kladr" value="<?= $h($s->derivalCityKladr ?? '') ?>">
                                    <input type="hidden" name="derival_street" value="<?= $h($s->derivalStreet ?? '') ?>">
                                    <input type="hidden" name="derival_house" value="<?= $h($s->derivalHouse ?? '') ?>">
                                    <input type="hidden" name="requester_email" value="<?= $h($s->requesterEmail) ?>">
                                    <input type="hidden" name="produce_days_offset" value="<?= $h((string)$s->produceDaysOffset) ?>">
                                    <input type="hidden" name="default_weight_kg" value="<?= $h((string)$s->defaultWeightKg) ?>">
                                    <input type="hidden" name="default_stated_value" value="<?= $h((string)$s->defaultStatedValue) ?>">
                                    <input type="hidden" name="default_dimensions_cm" value="<?= $h($s->defaultDimensionsCm) ?>">
                                    <input type="hidden" name="is_enabled" value="<?= $s->isEnabled ? '1' : '' ?>">
                                    <input type="hidden" name="delivery_payer" value="<?= $h($s->deliveryPayer ?? 'sender') ?>">
                                    <input type="hidden" name="requester_role" value="<?= $h($s->requesterRole ?? 'sender') ?>">
                                    <input type="hidden" name="derival_city_name" value="<?= $h($s->derivalCityName ?? '') ?>">

                                </div><!-- /page-col-right -->
                                <?php if ($done_sender !== $total_sender): ?>
                                    <!-- Колонка прогресса -->
                                    <div>
                                        <div class="prog-card">
                                            <!-- Прогресс-бар -->
                                            <div class="prog-bar-wrap">
                                                <div class="prog-bar-label">
                                                    <span style="font-size:11px;font-weight:600;color:var(--ink2)">Прогресс настройки</span>
                                                    <span class="prog-bar-pct"><?= $pct ?>%</span>
                                                </div>
                                                <div class="prog-bar">
                                                    <div class="prog-bar-fill" style="width:<?= $pct ?>%"></div>
                                                </div>
                                            </div>
                                            <!-- Шаги -->
                                            <div class="prog-steps">
                                                <div class="prog-step">
                                                    <div class="prog-step-ico <?= $done_conn ? 'done' : 'todo' ?>">
                                                        <?php if ($done_conn): ?><svg width="10" height="10" viewBox="0 0 10 10" fill="none">
                                                                <path d="M2 5l1.5 1.5 3.5-3.5" stroke="#14864a" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                                            </svg><?php else: ?><svg width="8" height="8" viewBox="0 0 8 8" fill="none">
                                                                <circle cx="4" cy="4" r="3" stroke="#ccc5bb" stroke-width="1.2" />
                                                            </svg><?php endif; ?>
                                                    </div>
                                                    <div>
                                                        <div class="prog-step-lbl" style="color:<?= $done_conn ? 'var(--grn)' : 'var(--ink3)' ?>">Подключение</div>
                                                        <div class="prog-step-sub"><?= $done_conn ? 'PAT активен' : 'Требуется PAT' ?></div>
                                                    </div>
                                                </div>
                                                <div class="prog-step">
                                                    <div class="prog-step-ico curr">
                                                        <svg width="8" height="8" viewBox="0 0 8 8" fill="none">
                                                            <circle cx="4" cy="4" r="3" fill="#f5501e" />
                                                        </svg>
                                                    </div>
                                                    <div>
                                                        <div class="prog-step-lbl" style="color:var(--amber)">Отправитель</div>
                                                        <div class="prog-step-sub"><?= $done_sender ?> из <?= $total_sender ?> заполнено</div>
                                                    </div>
                                                </div>
                                                <div class="prog-step">
                                                    <div class="prog-step-ico <?= $done_ship ? 'done' : 'todo' ?>">
                                                        <?php if ($done_ship): ?><svg width="10" height="10" viewBox="0 0 10 10" fill="none">
                                                                <path d="M2 5l1.5 1.5 3.5-3.5" stroke="#14864a" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                                            </svg><?php else: ?><svg width="8" height="8" viewBox="0 0 8 8" fill="none">
                                                                <circle cx="4" cy="4" r="3" stroke="#ccc5bb" stroke-width="1.2" />
                                                            </svg><?php endif; ?>
                                                    </div>
                                                    <div>
                                                        <div class="prog-step-lbl" style="color:var(--ink3)">Доставка</div>
                                                        <div class="prog-step-sub"><?= $done_ship ? 'Настроено' : 'Не начато' ?></div>
                                                    </div>
                                                </div>
                                                <div class="prog-step">
                                                    <div class="prog-step-ico <?= ($pct >= 100) ? 'done' : 'todo' ?>">
                                                        <?php if ($pct >= 100): ?><svg width="10" height="10" viewBox="0 0 10 10" fill="none">
                                                                <path d="M2 5l1.5 1.5 3.5-3.5" stroke="#14864a" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                                            </svg><?php else: ?><svg width="8" height="8" viewBox="0 0 8 8" fill="none">
                                                                <circle cx="4" cy="4" r="3" stroke="#ccc5bb" stroke-width="1.2" />
                                                            </svg><?php endif; ?>
                                                    </div>
                                                    <div>
                                                        <div class="prog-step-lbl" style="color:var(--ink3)">Готово</div>
                                                        <div class="prog-step-sub">Расчёт в корзине</div>
                                                    </div>
                                                </div>
                                            </div>
                                            <!-- Чеклист -->
                                            <div class="prog-checklist">
                                                <div style="font-size:10px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--ink3);margin-bottom:8px">Эта страница</div>
                                                <?php
                                                $cl_sender = [
                                                    [$chk_type,    'Тип отправителя выбран'],
                                                    [$chk_name,    'Название / ФИО заполнено'],
                                                    [$chk_inn,     'ИНН указан'],
                                                    [$chk_ca,      'Контрагент выбран'],
                                                    [$chk_contact, 'Контактное лицо и телефон'],
                                                    [$chk_email,   'Email для уведомлений'],
                                                ];
                                                foreach ($cl_sender as [$ok, $label]):
                                                ?>
                                                    <div class="prog-cl-item <?= $ok ? 'ok' : 'warn' ?>">
                                                        <div class="prog-cl-ico <?= $ok ? 'ok' : 'warn' ?>">
                                                            <?php if ($ok): ?><svg width="8" height="8" viewBox="0 0 8 8" fill="none">
                                                                    <path d="M1.5 4l1.5 1.5 3.5-3.5" stroke="#14864a" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" />
                                                                </svg><?php else: ?><svg width="8" height="8" viewBox="0 0 8 8" fill="none">
                                                                    <circle cx="4" cy="4" r="2.5" stroke="#ccc5bb" stroke-width="1.2" />
                                                                </svg><?php endif; ?>
                                                        </div>
                                                        <?= $h($label) ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <!-- Следующий шаг -->
                                            <div class="prog-next">
                                                <div class="prog-next-lbl"><?= $done_sender === $total_sender ? '✓ Готово' : 'Следующий шаг' ?></div>
                                                <div class="prog-next-txt"><?= $h($next_action) ?></div>
                                                <?php if ($done_sender === $total_sender): ?>
                                                    <a href="#" onclick="document.querySelector('.nav-item[data-page=shipping]').click();return false;" style="display:inline-block;margin-top:8px;font-size:11px;color:var(--amber);text-decoration:none;font-weight:500">Перейти к доставке →</a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div><!-- /progress col -->
                                <?php endif; ?>

                            </div><!-- /page-grid-3 -->

                            <div class="btn-row" style="justify-content:flex-start">
                                <button type="submit" class="btn-p js-save-btn">Сохранить изменения</button>
                                <a href="/insales/app?shop=<?= $h($s->shopHost) ?>&insales_id=<?= $h($s->insalesId) ?>&atk=<?= $h($accessToken) ?>" class="btn-g btn-cancel" style="text-decoration:none;align-items:center">Отмена</a>
                            </div>
                        </form>
                    </div><!-- /page-sender -->


                    <!-- ══ ДОСТАВКА ══ -->
                    <div class="page" id="page-shipping">
                        <div class="pg-hdr">
                            <div class="pg-title">Параметры доставки</div>
                            <div class="pg-sub">Настройки расчёта и оформления заказов</div>
                        </div>
                        <form method="post" action="/insales/app" id="form-shipping">
                            <input type="hidden" name="shop" value="<?= $h($s->shopHost) ?>">
                            <input type="hidden" name="insales_id" value="<?= $h($s->insalesId) ?>">
                            <input type="hidden" name="atk" value="<?= $h($accessToken) ?>">
                            <input type="hidden" name="requester_email" value="<?= $h($s->requesterEmail) ?>">
                            <input type="hidden" name="counteragent_uid" value="<?= $h($counteragentUid) ?>">
                            <input type="hidden" name="sender_counteragent_id" value="<?= $h($s->senderCounterAgentId !== null ? (string)$s->senderCounterAgentId : '') ?>">
                            <input type="hidden" name="sender_name" value="<?= $h($s->senderName ?? '') ?>">
                            <input type="hidden" name="sender_type" value="<?= $h($s->senderType ?? 'person') ?>">
                            <input type="hidden" name="sender_inn" value="<?= $h($s->senderInn ?? '') ?>">
                            <input type="hidden" name="sender_doc_type" value="<?= $h($s->senderDocType ?? 'passport') ?>">
                            <input type="hidden" name="sender_doc_serial" value="<?= $h($s->senderDocSerial ?? '') ?>">
                            <input type="hidden" name="sender_doc_number" value="<?= $h($s->senderDocNumber ?? '') ?>">
                            <input type="hidden" name="sender_contact_name" value="<?= $h($s->senderContactName ?? '') ?>">
                            <input type="hidden" name="sender_contact_phone" value="<?= $h($s->senderContactPhone ?? '') ?>">
                            <input type="hidden" name="sender_opf_uid" value="<?= $h($s->senderOpfUid ?? '') ?>">
                            <input type="hidden" name="sender_opf_name" value="<?= $h($s->senderOpfName ?? '') ?>">
                            <input type="hidden" name="sender_juridical_address" value="<?= $h($s->senderJuridicalAddress ?? '') ?>">
                            <input type="hidden" name="is_enabled" value="<?= $s->isEnabled ? '1' : '' ?>">
                            <input type="hidden" name="package_uid" value="<?= $h($s->packageUid) ?>">
                            <input type="hidden" name="package_name" value="<?= $h($s->packageName) ?>">
                            <input type="hidden" name="derival_city_name" value="<?= $h($s->derivalCityName ?? '') ?>">

                            <?php
                            $ship_terminal = ($s->senderTerminalId ?? 0) > 0;
                            $ship_addr     = ($s->derivalStreet ?? '') !== '' && ($s->derivalHouse ?? '') !== '';
                            $ship_route    = $ship_terminal || $ship_addr;
                            $ship_freight  = ($s->freightUid ?? '') !== '';
                            $ship_pkg      = $s->packageUid !== '';
                            $ship_types    = count($s->deliveryTypes) > 0;
                            $ship_enabled  = $s->isEnabled;
                            $checks_ship   = [$ship_route, $ship_freight, $ship_pkg, $ship_types, $ship_enabled];
                            $done_ship2    = count(array_filter($checks_ship));
                            $total_ship2   = count($checks_ship);
                            $pct_ship      = (int) round($done_ship2 / $total_ship2 * 100);
                            $next_ship = '';
                            if (!$ship_route) $next_ship = 'Выберите терминал отгрузки или укажите адрес забора груза.';
                            elseif (!$ship_freight) $next_ship = 'Укажите характер груза из справочника ДЛ.';
                            elseif (!$ship_types) $next_ship = 'Включите хотя бы один тип доставки.';
                            elseif (!$ship_enabled) $next_ship = 'Включите отображение доставки в корзине.';
                            else $next_ship = 'Доставка настроена — расчёт доступен в корзине.';
                            ?>
                            <div class="<?= $done_ship2 === $total_ship2 ? 'page-grid' : 'page-grid-3' ?>">
                                <div class="page-col">
                                    <!-- Способ отгрузки -->
                                    <div class="card">
                                        <div class="card-hdr">
                                            <div>
                                                <div class="card-title">Способ отгрузки</div>
                                                <div class="card-sub">Откуда передавать груз в Деловые Линии</div>
                                            </div>
                                        </div>
                                        <div class="card-body sm">
                                            <div class="ir">
                                                <span class="ir-l">Вариант отгрузки</span>
                                                <div class="seg" id="derivalVariantSeg" style="margin-bottom:0;white-space:nowrap">
                                                    <button type="button" class="seg-btn<?= $s->derivalVariant === 'terminal' ? ' on' : '' ?>" onclick="setDerivalVariant(this,'terminal')" style="white-space:nowrap;padding:7px 6px">От терминала</button>
                                                    <button type="button" class="seg-btn<?= $s->derivalVariant === 'address' ? ' on' : '' ?>" onclick="setDerivalVariant(this,'address')" style="white-space:nowrap;padding:7px 6px">От адреса</button>
                                                </div>
                                            </div>
                                        </div>
                                        <input type="hidden" id="derival_variant" name="derival_variant" value="<?= $h($s->derivalVariant ?? 'terminal') ?>">
                                        <!-- Блок терминала -->
                                        <div id="derivalTerminalBlock" <?= $s->derivalVariant === 'address' ? ' style="display:none"' : '' ?>>
                                            <div class="card-body">
                                                <div id="termSearch" <?= $tid !== '' ? ' style="display:none"' : '' ?>>
                                                    <div class="field">
                                                        <label>Город терминала</label>
                                                        <input type="text" id="citySearch" autocomplete="off" placeholder="Начните вводить город — Омск, Москва…">
                                                        <ul id="citySuggestions" class="suggestions"></ul>
                                                    </div>
                                                    <div id="termSkeleton" style="display:none;padding:8px 0">
                                                        <div style="height:12px;background:var(--line);border-radius:4px;width:60%;margin-bottom:6px;animation:skel 1.5s ease-in-out infinite"></div>
                                                        <div style="height:12px;background:var(--line);border-radius:4px;width:40%;animation:skel 1.5s ease-in-out infinite"></div>
                                                    </div>
                                                    <div id="termSelectWrap" style="display:none">
                                                        <div class="field">
                                                            <label>Терминал</label>
                                                            <select id="sender_terminal_id" name="sender_terminal_id">
                                                                <option value="">— выберите терминал —</option>
                                                                <?php if ($tid !== ''): ?>
                                                                    <option value="<?= $h($tid) ?>" selected>Терминал #<?= $h($tid) ?></option>
                                                                <?php endif; ?>
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div id="termCard" <?= $tid === '' ? ' style="display:none"' : '' ?>>
                                                    <div class="term-saved">
                                                        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:12px">
                                                            <div>
                                                                <div class="term-name" id="termCardName">Терминал #<?= $h($tid) ?></div>
                                                                <div class="term-addr" id="termCardAddr">Загрузка данных…</div>
                                                            </div>
                                                            <button type="button" id="termEditBtn" class="btn-g" style="font-size:11px;padding:5px 10px;flex-shrink:0">
                                                                <svg width="12" height="12" viewBox="0 0 16 16" fill="none" style="vertical-align:-1px;margin-right:3px" aria-hidden="true">
                                                                    <path d="M11.333 2a1.886 1.886 0 012.667 2.667L5.333 13.333 2 14l.667-3.333L11.333 2z" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" />
                                                                </svg>
                                                                Изменить
                                                            </button>
                                                        </div>
                                                        <div style="height:1px;background:var(--line);margin:10px 0"></div>
                                                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                                                            <div>
                                                                <div style="font-size:10px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--ink3);margin-bottom:4px">Режим работы</div>
                                                                <div style="font-size:12px;color:var(--ink2);line-height:1.5" id="termCardSchedule">—</div>
                                                            </div>
                                                            <div>
                                                                <div style="font-size:10px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--ink3);margin-bottom:4px">Макс. параметры</div>
                                                                <div style="font-size:12px;color:var(--ink2);line-height:1.5" id="termCardDims">—</div>
                                                            </div>
                                                        </div>
                                                        <div class="term-chips" style="margin-top:10px">
                                                            <span class="chip" id="termCardCity"></span>
                                                            <span class="chip" style="background:var(--grnl);color:var(--grn);border-color:var(--grnb)">✓ Принимает груз</span>
                                                        </div>
                                                    </div>
                                                </div>
                                                <input type="hidden" id="derival_city_kladr" name="derival_city_kladr" value="<?= $h($s->derivalCityKladr ?? '') ?>">
                                            </div>
                                        </div>
                                        <!-- Блок адреса забора -->
                                        <div id="derivalAddressBlock" <?= $s->derivalVariant !== 'address' ? ' style="display:none"' : '' ?>>
                                            <?php $hasDerivalAddr = ($s->derivalStreet ?? '') !== '' && ($s->derivalHouse ?? '') !== ''; ?>
                                            <div id="derivalAddressSearch" <?= $hasDerivalAddr ? ' style="display:none"' : '' ?>>
                                                <div class="card-body">
                                                    <div class="field">
                                                        <label>Город</label>
                                                        <input type="text" id="derivalCitySearch" autocomplete="off" placeholder="Начните вводить город…" value="<?= $h($s->derivalCityName ?? '') ?>">
                                                        <input type="hidden" id="derival_city_kladr_addr" name="derival_city_kladr" value="<?= $h($s->derivalCityKladr ?? '') ?>">
                                                        <input type="hidden" id="derival_city_name_hidden" name="derival_city_name" value="<?= $h($s->derivalCityName ?? '') ?>">
                                                        <ul id="derivalCitySuggestions" class="suggestions"></ul>
                                                    </div>
                                                    <div class="g2">
                                                        <div class="field">
                                                            <label>Улица</label>
                                                            <input type="text" id="derival_street_input" name="derival_street" value="<?= $h($s->derivalStreet ?? '') ?>" placeholder="Ленина">
                                                        </div>
                                                        <div class="field">
                                                            <label>Дом</label>
                                                            <input type="text" id="derival_house_input" name="derival_house" value="<?= $h($s->derivalHouse ?? '') ?>" placeholder="5">
                                                        </div>
                                                    </div>
                                                    <div class="g2">
                                                        <div class="field">
                                                            <label>Забор с</label>
                                                            <input type="time" name="derival_time_from" value="<?= $h($s->derivalTimeFrom ?? '') ?>">
                                                        </div>
                                                        <div class="field">
                                                            <label>Забор до</label>
                                                            <input type="time" name="derival_time_to" value="<?= $h($s->derivalTimeTo ?? '') ?>">
                                                        </div>
                                                    </div>
                                                    <div class="g2">
                                                        <div class="field">
                                                            <label>Перерыв с</label>
                                                            <input type="time" name="derival_break_from" value="<?= $h($s->derivalBreakFrom ?? '') ?>">
                                                        </div>
                                                        <div class="field">
                                                            <label>Перерыв до</label>
                                                            <input type="time" name="derival_break_to" value="<?= $h($s->derivalBreakTo ?? '') ?>">
                                                        </div>
                                                    </div>
                                                    <div class="btn-row" style="border:0;padding-top:8px;margin-top:4px">
                                                        <button type="submit" class="btn-p">Сохранить</button>
                                                        <?php if ($hasDerivalAddr): ?>
                                                            <button type="button" id="derivalAddrCancelBtn" class="btn-g">Отмена</button>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <div id="derivalAddressCard" <?= !$hasDerivalAddr ? ' style="display:none"' : '' ?>>
                                                <div class="card-body">
                                                    <div class="term-saved">
                                                        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px">
                                                            <div>
                                                                <div class="term-name">Адрес забора груза</div>
                                                                <div class="term-addr" id="derivalCardAddr">
                                                                    <?= $h(trim((($s->derivalCityName ?? '') . ', ' . ($s->derivalStreet ?? '') . ', д. ' . ($s->derivalHouse ?? '')), ', ')) ?>
                                                                </div>
                                                            </div>
                                                            <button type="button" id="derivalAddrEditBtn" class="btn-g" style="font-size:11px;padding:5px 10px;flex-shrink:0">
                                                                <svg width="12" height="12" viewBox="0 0 16 16" fill="none" style="vertical-align:-1px;margin-right:3px" aria-hidden="true">
                                                                    <path d="M11.333 2a1.886 1.886 0 012.667 2.667L5.333 13.333 2 14l.667-3.333L11.333 2z" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" />
                                                                </svg>
                                                                Изменить
                                                            </button>
                                                        </div>
                                                        <?php if (($s->derivalTimeFrom ?? '') !== '' || ($s->derivalBreakFrom ?? '') !== ''): ?>
                                                            <div style="height:1px;background:var(--line);margin:10px 0"></div>
                                                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                                                                <div>
                                                                    <div style="font-size:10px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--ink3);margin-bottom:4px">Время забора</div>
                                                                    <div style="font-size:12px;color:var(--ink2)"><?= ($s->derivalTimeFrom ?? '') !== '' ? $h($s->derivalTimeFrom . ' – ' . ($s->derivalTimeTo ?? '')) : '—' ?></div>
                                                                </div>
                                                                <div>
                                                                    <div style="font-size:10px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--ink3);margin-bottom:4px">Обед</div>
                                                                    <div style="font-size:12px;color:var(--ink2)"><?= ($s->derivalBreakFrom ?? '') !== '' ? $h($s->derivalBreakFrom . ' – ' . ($s->derivalBreakTo ?? '')) : '—' ?></div>
                                                                </div>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Груз по умолчанию -->
                                    <div class="card">
                                        <div class="card-hdr">
                                            <div>
                                                <div class="card-title">Груз по умолчанию</div>
                                                <div class="card-sub">Если у товара в inSales не заданы вес или габариты</div>
                                            </div>
                                        </div>
                                        <div class="card-body">
                                            <div class="g2">
                                                <div class="field"><label>Вес, кг</label><input type="number" step="0.001" min="0.01" name="default_weight_kg" value="<?= $h((string)$s->defaultWeightKg) ?>"></div>
                                                <div class="field"><label>Объявл. стоимость, ₽</label><input type="number" step="0.01" min="0" name="default_stated_value" value="<?= $h((string)$s->defaultStatedValue) ?>"></div>
                                                <div class="field"><label>Дней до отгрузки</label><input type="number" min="0" max="30" name="produce_days_offset" value="<?= $h((string)$s->produceDaysOffset) ?>"></div>
                                                <div class="field"><label>Габариты Д × Ш × В, см</label><input type="text" name="default_dimensions_cm" value="<?= $h($s->defaultDimensionsCm) ?>" placeholder="20x20x20">
                                                    <div class="hint">Длина × ширина × высота в сантиметрах</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                </div><!-- /page-col-left -->
                                <div class="page-col">
                                    <!-- Характер груза -->
                                    <div class="card">
                                        <div class="card-hdr">
                                            <div>
                                                <div class="card-title">Характер груза</div>
                                                <div class="card-sub">Из справочника Деловых Линий</div>
                                            </div>
                                        </div>
                                        <div class="card-body">
                                            <?php $hasFreight = ($s->freightUid ?? '') !== ''; ?>
                                            <div class="field">
                                                <label>Характер груза</label>
                                                <div id="freightSaved" <?= !$hasFreight ? ' style="display:none"' : '' ?> class="opf-saved">
                                                    <div>
                                                        <div class="opf-name" id="freightSavedName"><?= $h($s->freightName ?? 'Сохранено') ?></div>
                                                        <div class="opf-country">из справочника ДЛ</div>
                                                    </div>
                                                    <button type="button" id="freightEditBtn" class="btn-g" style="font-size:11px;padding:5px 10px;flex-shrink:0">
                                                        <svg width="12" height="12" viewBox="0 0 16 16" fill="none" style="vertical-align:-1px;margin-right:3px" aria-hidden="true">
                                                            <path d="M11.333 2a1.886 1.886 0 012.667 2.667L5.333 13.333 2 14l.667-3.333L11.333 2z" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" />
                                                        </svg>
                                                        Изменить
                                                    </button>
                                                </div>
                                                <div id="freightSearchWrap" <?= $hasFreight ? ' style="display:none"' : '' ?> class="opf-search-wrap">
                                                    <input class="opf-search-input" type="text" id="freightSearch" autocomplete="off" placeholder="Начните вводить — бытовая техника, одежда…">
                                                    <ul id="freightSuggestions" class="opf-list"></ul>
                                                </div>
                                                <input type="hidden" id="freight_uid" name="freight_uid" value="<?= $h($s->freightUid  ?? '') ?>">
                                                <input type="hidden" id="freight_name" name="freight_name" value="<?= $h($s->freightName ?? '') ?>">
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Упаковка -->
                                    <div class="card">
                                        <div class="card-hdr">
                                            <div>
                                                <div class="card-title">Упаковка</div>
                                                <div class="card-sub">Из справочника Деловых Линий</div>
                                            </div>
                                        </div>
                                        <div class="card-body">
                                            <?php $hasPkg = $s->packageUid !== ''; ?>
                                            <div class="field">
                                                <label>Вид упаковки</label>
                                                <div id="pkgSaved" <?= !$hasPkg ? ' style="display:none"' : '' ?> class="opf-saved">
                                                    <div>
                                                        <div class="opf-name" id="pkgSavedName"><?= $h($s->packageName ?: 'Сохранено') ?></div>
                                                        <div class="opf-country">из справочника ДЛ</div>
                                                    </div>
                                                    <button type="button" id="pkgEditBtn" class="btn-g" style="font-size:11px;padding:5px 10px;flex-shrink:0">
                                                        <svg width="12" height="12" viewBox="0 0 16 16" fill="none" style="vertical-align:-1px;margin-right:3px">
                                                            <path d="M11.333 2a1.886 1.886 0 012.667 2.667L5.333 13.333 2 14l.667-3.333L11.333 2z" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" />
                                                        </svg>
                                                        Изменить
                                                    </button>
                                                </div>
                                                <div id="pkgSearchWrap" <?= $hasPkg ? ' style="display:none"' : '' ?> class="opf-search-wrap">
                                                    <div id="pkgLoading" style="font-size:12px;color:var(--ink3);padding:6px 0">Загрузка упаковок…</div>
                                                    <ul id="pkgList" class="opf-list" style="display:none"></ul>
                                                    <button type="button" id="pkgClearBtn" class="btn-g" style="font-size:11px;margin-top:8px">Без упаковки</button>
                                                </div>
                                                <input type="hidden" id="package_uid" name="package_uid" value="<?= $h($s->packageUid) ?>">
                                                <input type="hidden" id="package_name" name="package_name" value="<?= $h($s->packageName) ?>">
                                                <div class="ir">
                                                    <span class="ir-l">Учитывать упаковку в расчёте стоимости</span>
                                                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
                                                        <input type="checkbox" name="package_in_calc" value="1" <?= $s->packageInCalc ? ' checked' : '' ?> style="width:auto;cursor:pointer;accent-color:var(--amber)">
                                                        <span style="font-size:12px;color:var(--ink3)">Включено</span>
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Расчёт доставки -->
                                    <div class="card">
                                        <div class="card-hdr">
                                            <div class="card-title">Расчёт доставки</div>
                                        </div>
                                        <div class="card-body sm">
                                            <div class="ir">
                                                <span class="ir-l">Показывать доставку в корзине</span>
                                                <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
                                                    <input type="checkbox" name="is_enabled" value="1" <?= $s->isEnabled ? ' checked' : '' ?> style="width:auto;cursor:pointer;accent-color:var(--amber)">
                                                    <span style="font-size:12px;color:var(--ink3)">Включено</span>
                                                </label>
                                            </div>
                                            <div class="ir" style="align-items:flex-start;flex-direction:column;gap:10px">
                                                <span class="ir-l">Типы доставки в корзине</span>
                                                <div style="display:flex;flex-direction:column;gap:8px">
                                                    <?php foreach (
                                                        [
                                                            'auto'          => 'Автодоставка',
                                                            'avia'          => 'Авиадоставка',
                                                            'express'       => 'Экспресс',
                                                            'small' => 'Малогабаритный груз',
                                                        ] as $val => $label
                                                    ): ?>
                                                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:var(--ink2)">
                                                            <input type="checkbox" name="delivery_types[]" value="<?= $val ?>"
                                                                <?= in_array($val, $s->deliveryTypes, true) ? 'checked' : '' ?>
                                                                style="width:auto;cursor:pointer;accent-color:var(--amber)">
                                                            <?= $label ?>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                            <div class="ir">
                                                <span class="ir-l">Роль для скидок ДЛ</span>
                                                <div class="seg" style="width:auto;margin-bottom:0">
                                                    <button type="button" class="seg-btn<?= $s->requesterRole === 'sender' ? ' on' : '' ?>" onclick="setRequesterRole(this,'sender')">Отправитель</button>
                                                    <button type="button" class="seg-btn<?= $s->requesterRole === 'receiver' ? ' on' : '' ?>" onclick="setRequesterRole(this,'receiver')">Получатель</button>
                                                    <button type="button" class="seg-btn<?= $s->requesterRole === 'payer' ? ' on' : '' ?>" onclick="setRequesterRole(this,'payer')">Плательщик</button>
                                                </div>
                                                <input type="hidden" id="requester_role" name="requester_role" value="<?= $h($s->requesterRole) ?>">
                                            </div>
                                        </div>
                                    </div>

                                </div><!-- /page-col-right -->

                                <!-- Колонка прогресса — Доставка -->
                                <?php if ($done_ship2 !== $total_ship2): ?>
                                    <div>
                                        <div class="prog-card">
                                            <div class="prog-bar-wrap">
                                                <div class="prog-bar-label">
                                                    <span style="font-size:11px;font-weight:600;color:var(--ink2)">Прогресс настройки</span>
                                                    <span class="prog-bar-pct"><?= $pct_ship ?>%</span>
                                                </div>
                                                <div class="prog-bar">
                                                    <div class="prog-bar-fill" style="width:<?= $pct_ship ?>%"></div>
                                                </div>
                                            </div>
                                            <div class="prog-steps">
                                                <div class="prog-step">
                                                    <div class="prog-step-ico done"><svg width="10" height="10" viewBox="0 0 10 10" fill="none">
                                                            <path d="M2 5l1.5 1.5 3.5-3.5" stroke="#14864a" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                                        </svg></div>
                                                    <div>
                                                        <div class="prog-step-lbl" style="color:var(--grn)">Подключение</div>
                                                        <div class="prog-step-sub">PAT активен</div>
                                                    </div>
                                                </div>
                                                <div class="prog-step">
                                                    <div class="prog-step-ico done"><svg width="10" height="10" viewBox="0 0 10 10" fill="none">
                                                            <path d="M2 5l1.5 1.5 3.5-3.5" stroke="#14864a" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                                        </svg></div>
                                                    <div>
                                                        <div class="prog-step-lbl" style="color:var(--grn)">Отправитель</div>
                                                        <div class="prog-step-sub">Заполнено</div>
                                                    </div>
                                                </div>
                                                <div class="prog-step">
                                                    <div class="prog-step-ico curr"><svg width="8" height="8" viewBox="0 0 8 8" fill="none">
                                                            <circle cx="4" cy="4" r="3" fill="#f5501e" />
                                                        </svg></div>
                                                    <div>
                                                        <div class="prog-step-lbl" style="color:var(--amber)">Доставка</div>
                                                        <div class="prog-step-sub"><?= $done_ship2 ?> из <?= $total_ship2 ?> настроено</div>
                                                    </div>
                                                </div>
                                                <div class="prog-step">
                                                    <div class="prog-step-ico <?= $pct_ship >= 100 ? 'done' : 'todo' ?>"><?php if ($pct_ship >= 100): ?><svg width="10" height="10" viewBox="0 0 10 10" fill="none">
                                                                <path d="M2 5l1.5 1.5 3.5-3.5" stroke="#14864a" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                                            </svg><?php else: ?><svg width="8" height="8" viewBox="0 0 8 8" fill="none">
                                                                <circle cx="4" cy="4" r="3" stroke="#ccc5bb" stroke-width="1.2" />
                                                            </svg><?php endif; ?></div>
                                                    <div>
                                                        <div class="prog-step-lbl" style="color:var(--ink3)">Готово</div>
                                                        <div class="prog-step-sub">Расчёт в корзине</div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="prog-checklist">
                                                <div style="font-size:10px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--ink3);margin-bottom:8px">Эта страница</div>
                                                <?php
                                                $cl_ship = [
                                                    [$ship_route,   'Терминал или адрес выбран'],
                                                    [$ship_freight,  'Характер груза указан'],
                                                    [$ship_pkg,      'Упаковка выбрана'],
                                                    [$ship_types,    'Типы доставки настроены'],
                                                    [$ship_enabled,  'Доставка включена в корзине'],
                                                ];
                                                foreach ($cl_ship as [$ok, $label]):
                                                ?>
                                                    <div class="prog-cl-item <?= $ok ? 'ok' : 'warn' ?>">
                                                        <div class="prog-cl-ico <?= $ok ? 'ok' : 'warn' ?>"><?php if ($ok): ?><svg width="8" height="8" viewBox="0 0 8 8" fill="none">
                                                                    <path d="M1.5 4l1.5 1.5 3.5-3.5" stroke="#14864a" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" />
                                                                </svg><?php else: ?><svg width="8" height="8" viewBox="0 0 8 8" fill="none">
                                                                    <circle cx="4" cy="4" r="2.5" stroke="#ccc5bb" stroke-width="1.2" />
                                                                </svg><?php endif; ?></div>
                                                        <?= $h($label) ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <div class="prog-next">
                                                <div class="prog-next-lbl"><?= $done_ship2 === $total_ship2 ? '✓ Готово' : 'Следующий шаг' ?></div>
                                                <div class="prog-next-txt"><?= $h($next_ship) ?></div>
                                            </div>
                                        </div>
                                    </div><!-- /progress col -->
                                <?php endif; ?>

                            </div><!-- /page-grid -->

                            <div class="btn-row" style="justify-content:flex-start">
                                <button type="submit" class="btn-p js-save-btn">Сохранить изменения</button>
                                <a href="/insales/app?shop=<?= $h($s->shopHost) ?>&insales_id=<?= $h($s->insalesId) ?>&atk=<?= $h($accessToken) ?>" class="btn-g btn-cancel" style="text-decoration:none;align-items:center">Отмена</a>
                            </div>
                        </form>
                    </div>

                    <!-- ══ ПОДКЛЮЧЕНИЕ ══ -->
                    <div class="page" id="page-connection">
                        <div class="pg-hdr">
                            <div class="pg-title">Подключение</div>
                            <div class="pg-sub">Авторизация в API Деловых Линий</div>
                        </div>

                        <!-- Статус -->
                        <div class="card">
                            <div class="card-hdr">
                                <div class="card-title">Статус подключения</div>
                                <span class="bdg bdg-g">Активно</span>
                            </div>
                            <div class="card-body sm">
                                <div class="ir"><span class="ir-l">Сессия API</span><span class="bdg bdg-g">✓ Авторизован</span></div>
                                <?php if ($counteragentName !== ''): ?>
                                    <div class="ir"><span class="ir-l">Контрагент ДЛ</span><span class="ir-v"><?= $h($counteragentName) ?></span></div>
                                <?php endif; ?>
                                <?php if ($counteragentUid !== ''): ?>
                                    <div class="ir"><span class="ir-l">UID контрагента</span><span class="ir-v" style="font-size:10px"><?= $h(substr($counteragentUid, 0, 24)) ?>…</span></div>
                                <?php endif; ?>
                                <?php if ($counteragentsError !== null): ?>
                                    <div class="ir"><span class="ir-l" style="color:#c00">Ошибка загрузки контрагентов</span><span class="ir-v" style="font-size:11px;color:#c00"><?= $h($counteragentsError) ?></span></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- PAT -->
                        <div class="card">
                            <div class="card-hdr">
                                <div>
                                    <div class="card-title">Персональный токен (PAT)</div>
                                    <div class="card-sub">Личный кабинет ДЛ → Настройки → Интеграция API</div>
                                </div>
                            </div>
                            <div class="card-body">
                                <form method="post" action="/insales/app" id="form-connection">
                                    <input type="hidden" name="shop" value="<?= $h($s->shopHost) ?>">
                                    <input type="hidden" name="insales_id" value="<?= $h($s->insalesId) ?>">
                                    <input type="hidden" name="atk" value="<?= $h($accessToken) ?>">
                                    <input type="hidden" name="update_pat" value="1">
                                    <div class="field">
                                        <label>PAT-токен</label>
                                        <input type="password" name="dellin_pat" autocomplete="off" placeholder="dl-api-…">
                                        <div class="hint">Хранится в зашифрованном виде, не передаётся третьим лицам</div>
                                    </div>
                                    <div class="btn-row" style="border:0;padding-top:8px;margin-top:4px">
                                        <button type="submit" class="btn-p">Обновить токен</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- ══ ПОДДЕРЖКА ══ -->
                    <div class="page" id="page-support">
                        <div class="pg-hdr">
                            <div class="pg-title">Поддержка</div>
                            <div class="pg-sub">Если что-то работает не так — напишите нам</div>
                        </div>

                        <div class="card">
                            <div class="card-hdr">
                                <div>
                                    <div class="card-title">Связаться с нами</div>
                                    <div class="card-sub">Отвечаем в течение 1 рабочего дня</div>
                                </div>
                            </div>
                            <div class="card-body sm">
                                <div class="ir">
                                    <span class="ir-l">Email поддержки</span>
                                    <a href="mailto:g120255908@gmail.com" class="ir-v" style="text-decoration:none;color:var(--amber)">g120255908@gmail.com</a>
                                </div>
                                <div class="ir">
                                    <span class="ir-l">Что указать в письме</span>
                                    <span class="ir-v" style="font-family:var(--sans);font-weight:400;text-align:right;max-width:60%">домен магазина, номер заказа, скриншот ошибки</span>
                                </div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-hdr">
                                <div class="card-title">Частые вопросы</div>
                            </div>
                            <div class="card-body">
                                <div class="field">
                                    <label>Заказ не передаётся в Деловые Линии</label>
                                    <div class="hint" style="margin-top:2px">Проверьте, что в разделе «Подключение» указан действующий PAT-токен, а в разделе «Отправитель» заполнен ИНН (для ИП и юрлиц) и выбрана ОПФ из справочника ДЛ.</div>
                                </div>
                                <div class="field">
                                    <label>Не отображается нужный тип доставки в корзине</label>
                                    <div class="hint" style="margin-top:2px">Включите нужные типы доставки в разделе «Доставка» → «Типы доставки в корзине», и убедитесь что переключатель «Показывать доставку в корзине» активен.</div>
                                </div>
                                <div class="field">
                                    <label>Ошибка при расчёте малогабаритного груза (МГГ)</label>
                                    <div class="hint" style="margin-top:2px">МГГ имеет ограничение по габаритам — не более 0.54 × 0.39 × 0.39 м. Проверьте габариты товара в inSales.</div>
                                </div>
                                <div class="field" style="margin-bottom:0">
                                    <label>Как изменить адрес забора груза</label>
                                    <div class="hint" style="margin-top:2px">В разделе «Способ отгрузки» переключите вариант на «От адреса» и укажите город, улицу и дом — оттуда будет приезжать экспедитор.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div><!-- /content -->
            </main>
        </div><!-- /app -->

        <script>
            (function() {
                var savedTermId = <?= json_encode($tid) ?>;
                var shopQ = <?= json_encode($shopQ) ?>;
                var iidQ = <?= json_encode($iidQ) ?>;
                var apiBase = '/insales/cities/search?shop=' + shopQ + '&insales_id=' + iidQ + '&q=';
                var termBase = '/insales/terminals?shop=' + shopQ + '&insales_id=' + iidQ;
                var freightBase = '/insales/freight/search?shop=' + shopQ + '&insales_id=' + iidQ + '&q=';
                var opfBase = '/insales/opf/search?shop=' + shopQ + '&insales_id=' + iidQ + '&q=';

                function $(id) {
                    return document.getElementById(id);
                }

                function fetchJ(u) {
                    return fetch(u, {
                        headers: {
                            Accept: 'application/json'
                        }
                    }).then(function(r) {
                        return r.json();
                    });
                }

                // ── Nav (с проверкой несохранённых изменений) ──
                function switchToPage(btn) {
                    document.querySelectorAll('.nav-item').forEach(function(b) {
                        b.classList.remove('active');
                    });
                    document.querySelectorAll('.page').forEach(function(p) {
                        p.classList.remove('active');
                    });
                    btn.classList.add('active');
                    var pg = document.getElementById('page-' + btn.dataset.page);
                    if (pg) pg.classList.add('active');
                    var lbl = document.getElementById('topbar-page');
                    if (lbl) lbl.textContent = btn.dataset.label || '';
                    if (window.innerWidth <= 768) closeSidebar();
                    sessionStorage.setItem('activeNavPage', btn.dataset.page);
                }
                document.querySelectorAll('.nav-item').forEach(function(btn) {
                    btn.addEventListener('click', function(e) {
                        if (window.hasDirtyForm && window.hasDirtyForm()) {
                            e.preventDefault();
                            var targetBtn = this;
                            window.showDirtyModalGlobal(function(confirmed) {
                                if (confirmed) {
                                    window.clearDirtyForm();
                                    switchToPage(targetBtn);
                                }
                            });
                            return;
                        }
                        switchToPage(this);
                    });
                });

                // ── Burger ──
                var sidebar = $('sidebar'),
                    overlay = $('overlay'),
                    burger = $('burger');

                function openSidebar() {
                    sidebar.classList.add('open');
                    overlay.classList.add('show');
                }

                function closeSidebar() {
                    sidebar.classList.remove('open');
                    overlay.classList.remove('show');
                }
                if (burger) burger.addEventListener('click', function() {
                    sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
                });
                if (overlay) overlay.addEventListener('click', closeSidebar);
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') closeSidebar();
                });

                // ── Segment: sender type ──
                var segBtns = document.querySelectorAll('#segSenderType .seg-btn');
                var typeInput = $('sender_type');

                function switchType(val, animate) {
                    var isPerson = val === 'person';
                    // update hidden input: person → person, org → keep ip/company or default ip
                    if (isPerson) {
                        typeInput.value = 'person';
                    } else {
                        var cur = typeInput.value;
                        if (cur === 'person') typeInput.value = 'ip';
                    }
                    segBtns.forEach(function(b) {
                        b.classList.toggle('on', b.dataset.val === val);
                    });

                    var bPerson = $('blockPerson'),
                        bOrg = $('blockOrg');
                    if (!bPerson || !bOrg) return;

                    if (animate) {
                        var hiding = isPerson ? bOrg : bPerson;
                        var showing = isPerson ? bPerson : bOrg;
                        hiding.style.opacity = '0';
                        hiding.style.transform = 'translateY(-4px)';
                        setTimeout(function() {
                            hiding.classList.add('hidden');
                            hiding.style.opacity = '';
                            hiding.style.transform = '';
                            showing.classList.remove('hidden');
                            showing.style.opacity = '0';
                            showing.style.transform = 'translateY(4px)';
                            requestAnimationFrame(function() {
                                requestAnimationFrame(function() {
                                    showing.style.opacity = '1';
                                    showing.style.transform = 'translateY(0)';
                                });
                            });
                            setTimeout(function() {
                                showing.style.opacity = '';
                                showing.style.transform = '';
                            }, 250);
                        }, 180);
                    } else {
                        (isPerson ? bOrg : bPerson).classList.add('hidden');
                        (isPerson ? bPerson : bOrg).classList.remove('hidden');
                    }

                    // INN validation reset
                    var innEl = $('sender_inn'),
                        innMsg = $('innErrMsg');
                    if (innEl) innEl.classList.remove('field-err');
                    if (innMsg) innMsg.style.display = 'none';
                    var f = document.querySelector('#settingsForm');
                    if (f && f._markDirty) f._markDirty();
                }

                segBtns.forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        switchType(this.dataset.val, true);
                    });
                });

                // INN live validation
                var innEl = $('sender_inn');
                if (innEl) {
                    innEl.addEventListener('input', function() {
                        if (this.value.trim()) {
                            this.classList.remove('field-err');
                            var msg = $('innErrMsg');
                            if (msg) msg.style.display = 'none';
                        }
                    });
                }

                // Form submit validation
                var settingsForm = document.getElementById('settingsForm');
                if (settingsForm) {
                    settingsForm.addEventListener('submit', function(e) {
                        var isPerson = typeInput.value === 'person';
                        if (!isPerson) {
                            var inn = $('sender_inn') ? $('sender_inn').value.trim() : '';
                            if (!inn) {
                                e.preventDefault();
                                var innEl2 = $('sender_inn'),
                                    msg = $('innErrMsg');
                                if (innEl2) {
                                    innEl2.classList.add('field-err');
                                    innEl2.focus();
                                }
                                if (msg) msg.style.display = 'block';
                                return false;
                            }
                        }
                    });
                }

                // ── OPF saved/search ──
                var opfEditBtn = $('opfEditBtn');
                var opfSearchWrap = $('opfSearchWrap');
                var opfSaved = $('opfSaved');
                var opfSearchInput = $('opfSearchInput');
                var opfListEl = $('opfList');

                if (opfEditBtn) {
                    opfEditBtn.addEventListener('click', function() {
                        opfSaved.style.display = 'none';
                        opfSearchWrap.style.display = '';
                        if (opfSearchInput) opfSearchInput.focus();
                    });
                }

                if (opfSearchInput) {
                    opfSearchInput.addEventListener('input', function() {
                        var q = this.value.trim();
                        if (q.length < 1) {
                            opfListEl.style.display = 'none';
                            return;
                        }
                        fetchJ(opfBase + encodeURIComponent(q)).then(function(j) {
                            if (!j.ok || !opfListEl) return;
                            opfListEl.innerHTML = '';
                            (j.items || []).slice(0, 15).forEach(function(it) {
                                var li = document.createElement('li');
                                li.className = 'opf-item';
                                li.innerHTML = '<div>' + it.title + '</div>' + (it.country_name ? '<div class="opf-item-sub">' + it.country_name + '</div>' : '');
                                (function(item) {
                                    li.addEventListener('click', function() {
                                        $('sender_opf_uid').value = item.uid;
                                        $('sender_opf_name').value = item.title;
                                        $('opfSavedName').textContent = item.title;
                                        $('opfSavedCountry').textContent = item.country_name || '';
                                        opfSaved.style.display = 'flex';
                                        opfSearchWrap.style.display = 'none';
                                        opfListEl.style.display = 'none';
                                    });
                                })(it);
                                opfListEl.appendChild(li);
                            });
                            opfListEl.style.display = opfListEl.children.length ? 'block' : 'none';
                        });
                    });
                    document.addEventListener('click', function(e) {
                        if (opfSearchInput && opfListEl && !opfSearchInput.contains(e.target) && !opfListEl.contains(e.target))
                            opfListEl.style.display = 'none';
                    });
                }

                // ── City autocomplete ──
                var cityInput = $('citySearch'),
                    citySugg = $('citySuggestions'),
                    timer;
                if (cityInput) {
                    cityInput.addEventListener('input', function() {
                        clearTimeout(timer);
                        var q = cityInput.value.trim();
                        if (citySugg) citySugg.style.display = 'none';
                        if (q.length < 2) return;
                        timer = setTimeout(function() {
                            fetchJ(apiBase + encodeURIComponent(q)).then(function(j) {
                                if (!j.ok || !citySugg) return;
                                citySugg.innerHTML = '';
                                (j.cities || []).slice(0, 12).forEach(function(c) {
                                    var code = c.code || c.kladr || '';
                                    if (!code) return;
                                    var li = document.createElement('li');
                                    li.textContent = c.name || c.searchString || code;
                                    li.addEventListener('click', function() {
                                        cityInput.value = li.textContent;
                                        citySugg.style.display = 'none';
                                        var fld = $('derival_city_kladr');
                                        if (fld) fld.value = code;
                                        loadTerminals(code);
                                    });
                                    citySugg.appendChild(li);
                                });
                                if (citySugg) citySugg.style.display = citySugg.children.length ? 'block' : 'none';
                            });
                        }, 350);
                    });
                    document.addEventListener('click', function(e) {
                        if (citySugg && !cityInput.contains(e.target) && !citySugg.contains(e.target))
                            citySugg.style.display = 'none';
                    });
                }

                // ── Load terminals ──
                function loadTerminals(kladr) {
                    var wrap = $('termSelectWrap'),
                        skel = $('termSkeleton'),
                        sel = $('sender_terminal_id');
                    if (!sel) return;
                    if (wrap) wrap.style.display = 'none';
                    if (skel) skel.style.display = 'block';
                    fetchJ(termBase + '&limit=200&city_kladr=' + encodeURIComponent(kladr)).then(function(j) {
                        if (skel) skel.style.display = 'none';
                        if (!j.ok) return;
                        sel.innerHTML = '<option value="">— выберите терминал —</option>';
                        (j.terminals || []).forEach(function(t) {
                            var opt = document.createElement('option');
                            opt.value = String(t.id);
                            opt.textContent = t.name || (t.city + ' #' + t.id);
                            opt.dataset.addr = t.address || '';
                            opt.dataset.city = t.city || '';
                            opt.dataset.schedule = t.schedule || '';
                            opt.dataset.wt = t.max_weight || '';
                            opt.dataset.ml = t.max_length || '';
                            opt.dataset.mw = t.max_width || '';
                            opt.dataset.mh = t.max_height || '';
                            if (String(t.id) === String(savedTermId)) opt.selected = true;
                            sel.appendChild(opt);
                        });
                        if (wrap) wrap.style.display = 'block';
                        // если был сохранён терминал — сразу показать карточку
                        if (savedTermId !== '' && sel.value !== '') showTermCard(sel.options[sel.selectedIndex]);
                    }).catch(function() {
                        if (skel) skel.style.display = 'none';
                    });
                }

                // ── Terminal select change ──
                var termSel = $('sender_terminal_id');
                if (termSel) {
                    termSel.addEventListener('change', function() {
                        var opt = this.options[this.selectedIndex];
                        if (opt.value) showTermCard(opt);
                    });
                }

                // ── Show terminal card ──
                function showTermCard(opt) {
                    var search = $('termSearch'),
                        card = $('termCard');
                    if (!search || !card) return;
                    $('termCardName').textContent = opt.textContent.trim();
                    $('termCardAddr').textContent = 'г. ' + (opt.dataset.city || '') + ', ' + (opt.dataset.addr || '');
                    $('termCardCity').textContent = 'г. ' + (opt.dataset.city || '');
                    var sched = (opt.dataset.schedule || '—').split(';').map(function(s) {
                        return s.trim();
                    }).join('\n');
                    $('termCardSchedule').innerHTML = sched.replace(/\n/g, '<br>');
                    var ml = opt.dataset.ml,
                        mw = opt.dataset.mw,
                        mh = opt.dataset.mh,
                        wt = opt.dataset.wt;
                    $('termCardDims').innerHTML =
                        (ml && mw && mh ? 'до ' + ml + ' × ' + mw + ' × ' + mh + ' м<br>' : '') +
                        (wt ? 'до ' + Number(wt).toLocaleString('ru') + ' кг' : '—');
                    search.style.display = 'none';
                    card.style.display = 'block';
                }

                // ── Edit button ──
                var editBtn = $('termEditBtn');
                if (editBtn) {
                    editBtn.addEventListener('click', function() {
                        $('termSearch').style.display = 'block';
                        $('termCard').style.display = 'none';
                    });
                }

                // ── Init: если терминал уже сохранён — загрузить список и показать карточку ──
                if (savedTermId !== '') {
                    // Загружаем все терминалы по UID города через findCityKladr (на бэке уже есть)
                    // Временно показываем карточку с минимальными данными
                    var savedCard = $('termCard'),
                        savedSearch = $('termSearch');
                    if (savedCard && savedSearch) {
                        $('termCardName').textContent = 'Терминал #' + savedTermId;
                        $('termCardAddr').textContent = 'Загрузка данных…';
                        $('termCardSchedule').textContent = '—';
                        $('termCardDims').textContent = '—';
                        $('termCardCity').textContent = '';
                        savedSearch.style.display = 'none';
                        savedCard.style.display = 'block';
                        // Загружаем реальные данные
                        fetchJ(termBase + '&q=' + encodeURIComponent(savedTermId)).then(function(j) {
                            var t = (j.terminals || []).find(function(x) {
                                return String(x.id) === String(savedTermId);
                            });
                            if (!t) return;
                            var fakeOpt = {
                                textContent: t.name || 'Терминал #' + t.id,
                                dataset: {
                                    addr: t.address || '',
                                    city: t.city || '',
                                    schedule: t.schedule || '',
                                    wt: t.max_weight || '',
                                    ml: t.max_length || '',
                                    mw: t.max_width || '',
                                    mh: t.max_height || ''
                                }
                            };
                            showTermCard(fakeOpt);
                        });
                    }
                }

                // ── Freight autocomplete ──
                var freightSaved = $('freightSaved');
                var freightSearchWrap = $('freightSearchWrap');
                var freightEditBtn = $('freightEditBtn');
                var fi = $('freightSearch'),
                    fl = $('freightSuggestions'),
                    fh = $('freight_uid'),
                    fn = $('freight_name'),
                    timer;

                if (freightEditBtn) {
                    freightEditBtn.addEventListener('click', function() {
                        freightSaved.style.display = 'none';
                        freightSearchWrap.style.display = '';
                        if (fi) fi.focus();
                        var form = document.querySelector('#form-shipping');
                        if (form && form._markDirty) form._markDirty();
                    });
                }

                if (fi) {
                    fi.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter') e.preventDefault();
                    });
                    fi.addEventListener('input', function() {
                        clearTimeout(timer);
                        var q = fi.value.trim();
                        if (fl) fl.style.display = 'none';
                        if (q.length < 2) return;
                        timer = setTimeout(function() {
                            fetchJ(freightBase + encodeURIComponent(q)).then(function(j) {
                                if (!j.ok || !fl) return;
                                fl.innerHTML = '';
                                (j.items || []).slice(0, 15).forEach(function(it) {
                                    var li = document.createElement('li');
                                    li.className = 'opf-item';
                                    li.innerHTML = '<div>' + it.name + '</div>';
                                    li.addEventListener('click', function() {
                                        fh.value = it.uid;
                                        fn.value = it.name;
                                        $('freightSavedName').textContent = it.name;
                                        freightSaved.style.display = 'flex';
                                        freightSearchWrap.style.display = 'none';
                                        fl.style.display = 'none';
                                        fi.value = '';
                                        var form = document.querySelector('#form-shipping');
                                        if (form && form._markDirty) form._markDirty();
                                    });
                                    fl.appendChild(li);
                                });
                                fl.style.display = fl.children.length ? 'block' : 'none';
                            });
                        }, 350);
                    });
                    document.addEventListener('click', function(e) {
                        if (fl && fi && !fi.contains(e.target) && !fl.contains(e.target)) fl.style.display = 'none';
                    });
                }

                window.clearFreight = function() {
                    fh.value = '';
                    fn.value = '';
                    fi.value = '';
                    freightSaved.style.display = 'none';
                    freightSearchWrap.style.display = '';
                };

                // ── Package ──
                var pkgEditBtn = document.getElementById('pkgEditBtn');
                var pkgSearchWrap = document.getElementById('pkgSearchWrap');
                var pkgSaved = document.getElementById('pkgSaved');
                var pkgList = document.getElementById('pkgList');
                var pkgLoading = document.getElementById('pkgLoading');

                function loadPackages() {
                    if (!pkgList) return;
                    pkgLoading.style.display = 'block';
                    pkgList.style.display = 'none';
                    var dims = <?= json_encode($s->defaultDimensionsCm) ?>.split('x');
                    var l = parseFloat(dims[0] || 20) / 100;
                    var w = parseFloat(dims[1] || 20) / 100;
                    var h = parseFloat(dims[2] || 20) / 100;
                    var wt = <?= json_encode($s->defaultWeightKg) ?>;
                    var kladr = <?= json_encode($s->derivalCityKladr ?? '') ?>;
                    var url = '/insales/packages?insales_id=' + iidQ +
                        '&length=' + l + '&width=' + w + '&height=' + h +
                        '&weight=' + wt + '&kladr=' + encodeURIComponent(kladr);
                    fetchJ(url).then(function(j) {
                        pkgLoading.style.display = 'none';
                        if (!j.ok || !j.items || !j.items.length) {
                            pkgLoading.textContent = 'Нет доступных упаковок';
                            pkgLoading.style.display = 'block';
                            return;
                        }
                        pkgList.innerHTML = '';
                        j.items.forEach(function(pkg) {
                            var li = document.createElement('li');
                            li.className = 'opf-item';
                            li.innerHTML = '<div>' + pkg.name + '</div>';
                            li.addEventListener('click', function() {
                                document.getElementById('package_uid').value = pkg.uid;
                                document.getElementById('package_name').value = pkg.name;
                                document.getElementById('pkgSavedName').textContent = pkg.name;
                                pkgSaved.style.display = 'flex';
                                pkgSearchWrap.style.display = 'none';
                                var f = document.querySelector('#form-shipping');
                                if (f && f._markDirty) f._markDirty();
                            });
                            pkgList.appendChild(li);
                        });
                        pkgList.style.display = 'block';
                    }).catch(function() {
                        pkgLoading.textContent = 'Ошибка загрузки';
                        pkgLoading.style.display = 'block';
                    });
                }

                if (pkgEditBtn) {
                    pkgEditBtn.addEventListener('click', function() {
                        pkgSaved.style.display = 'none';
                        pkgSearchWrap.style.display = '';
                        loadPackages();
                        var f = document.querySelector('#form-shipping');
                        if (f && f._markDirty) f._markDirty();
                    });
                }

                var pkgClearBtn = document.getElementById('pkgClearBtn');
                if (pkgClearBtn) {
                    pkgClearBtn.addEventListener('click', function() {
                        document.getElementById('package_uid').value = '';
                        document.getElementById('package_name').value = '';
                        pkgSaved.style.display = 'none';
                        pkgSearchWrap.style.display = 'none';
                    });
                }
                if (pkgSearchWrap && pkgSearchWrap.style.display !== 'none') {
                    loadPackages();
                }
                // Восстанавливаем активную страницу после сохранения
                var savedPage = sessionStorage.getItem('activeNavPage');
                if (savedPage) {
                    var savedBtn = document.querySelector('.nav-item[data-page="' + savedPage + '"]');
                    if (savedBtn) savedBtn.click();
                }
                // ── Derival city autocomplete ──
                var derivalCityInput = document.getElementById('derivalCitySearch');
                var derivalCityKladr = document.getElementById('derival_city_kladr_addr');
                var derivalCitySugg = document.getElementById('derivalCitySuggestions');
                var derivalTimer;
                if (derivalCityInput) {
                    derivalCityInput.addEventListener('input', function() {
                        clearTimeout(derivalTimer);
                        var q = derivalCityInput.value.trim();
                        if (derivalCitySugg) derivalCitySugg.style.display = 'none';
                        if (q.length < 2) return;
                        derivalTimer = setTimeout(function() {
                            fetchJ(apiBase + encodeURIComponent(q)).then(function(j) {
                                if (!j.ok || !derivalCitySugg) return;
                                derivalCitySugg.innerHTML = '';
                                (j.cities || []).slice(0, 12).forEach(function(c) {
                                    var code = c.code || c.kladr || '';
                                    if (!code) return;
                                    var li = document.createElement('li');
                                    li.textContent = c.name || c.searchString || code;
                                    li.addEventListener('click', function() {
                                        derivalCityInput.value = li.textContent;
                                        derivalCitySugg.style.display = 'none';
                                        if (derivalCityKladr) derivalCityKladr.value = code;
                                        // Сохраняем название города
                                        var nameField = document.getElementById('derival_city_name_hidden');
                                        if (nameField) nameField.value = li.textContent;
                                    });
                                    derivalCitySugg.appendChild(li);
                                });
                                derivalCitySugg.style.display = derivalCitySugg.children.length ? 'block' : 'none';
                            });
                        }, 350);
                    });
                    document.addEventListener('click', function(e) {
                        if (derivalCitySugg && !derivalCityInput.contains(e.target) && !derivalCitySugg.contains(e.target))
                            derivalCitySugg.style.display = 'none';
                    });
                }
            })();
            window.setRequesterRole = function(btn, val) {
                btn.parentNode.querySelectorAll('.seg-btn').forEach(function(b) {
                    b.classList.remove('on');
                });
                btn.classList.add('on');
                var el = document.getElementById('requester_role');
                if (el) el.value = val;
                var f = btn.closest('form');
                if (f && f._markDirty) f._markDirty();
            };
            window.setDerivalVariant = function(btn, val) {
                btn.parentNode.querySelectorAll('.seg-btn').forEach(function(b) {
                    b.classList.remove('on');
                });
                btn.classList.add('on');
                document.getElementById('derival_variant').value = val;
                document.getElementById('derivalTerminalBlock').style.display = val === 'terminal' ? '' : 'none';
                document.getElementById('derivalAddressBlock').style.display = val === 'address' ? '' : 'none';
                var f = btn.closest('form');
                if (f && f._markDirty) f._markDirty();
            };
            // ── Derival address card / edit ──
            var derivalAddrEditBtn = document.getElementById('derivalAddrEditBtn');
            var derivalAddrCancelBtn = document.getElementById('derivalAddrCancelBtn');
            var derivalAddressSearch = document.getElementById('derivalAddressSearch');
            var derivalAddressCard = document.getElementById('derivalAddressCard');

            if (derivalAddrEditBtn) {
                derivalAddrEditBtn.addEventListener('click', function() {
                    derivalAddressCard.style.display = 'none';
                    derivalAddressSearch.style.display = '';
                });
            }
            if (derivalAddrCancelBtn) {
                derivalAddrCancelBtn.addEventListener('click', function() {
                    derivalAddressSearch.style.display = 'none';
                    derivalAddressCard.style.display = '';
                });
            }

            (function() {
                var dirtyForm = null;

                window.hasDirtyForm = function() {
                    return dirtyForm !== null;
                };
                window.clearDirtyForm = function() {
                    if (dirtyForm) {
                        var submitBtn = dirtyForm.querySelector('button.js-save-btn');
                        dirtyForm.classList.remove('form-dirty');
                        if (submitBtn) submitBtn.disabled = true;
                    }
                    dirtyForm = null;
                };
                window.showDirtyModalGlobal = function(cb) {
                    showDirtyModal(cb);
                };

                window.markFormDirty = function(formOrEl) {
                    var form = formOrEl instanceof HTMLFormElement ?
                        formOrEl :
                        (formOrEl ? formOrEl.closest('form') : null);
                    if (!form) return;
                    var submitBtn = form.querySelector('button.js-save-btn');
                    if (!submitBtn) return;
                    if (dirtyForm === form) return;
                    dirtyForm = form;
                    form.classList.add('form-dirty');
                    submitBtn.disabled = false;
                };

                var forms = document.querySelectorAll('form[method="post"]');
                forms.forEach(function(form) {
                    var submitBtn = form.querySelector('button.js-save-btn');
                    if (!submitBtn) return;
                    submitBtn.disabled = true;

                    function markDirty() {
                        window.markFormDirty(form);
                    }

                    function markClean() {
                        dirtyForm = null;
                        form.classList.remove('form-dirty');
                        submitBtn.disabled = true;
                    }
                    form.querySelectorAll('input, select, textarea').forEach(function(input) {
                        input.addEventListener('change', markDirty);
                        input.addEventListener('input', markDirty);
                    });
                    form.addEventListener('submit', function() {
                        markClean();
                    });
                });
                window.addEventListener('beforeunload', function(e) {
                    if (dirtyForm) {
                        e.preventDefault();
                        e.returnValue = '';
                    }
                });

                // Кнопка Отмена — показывает модалку если есть несохранённые изменения
                document.querySelectorAll('.btn-cancel').forEach(function(btn) {
                    btn.addEventListener('click', function(e) {
                        if (!dirtyForm) return;
                        e.preventDefault();
                        var href = btn.getAttribute('href') || btn.dataset.href;
                        showDirtyModal(function(confirmed) {
                            if (confirmed) {
                                window.clearDirtyForm();
                                if (href) window.location.href = href;
                                else window.location.reload();
                            }
                        });
                    });
                });
            })();
            // Кастомная модалка
            var _modalCallback = null;
            var _modalEl = (function() {
                var el = document.createElement('div');
                el.innerHTML = '<div id="dirty-overlay" style="display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.45);backdrop-filter:blur(2px)">' +
                    '<div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:var(--s1);border:1px solid var(--line2);border-radius:var(--r3);padding:28px 32px;max-width:380px;width:90%;box-shadow:0 24px 48px rgba(0,0,0,.4)">' +
                    '<div style="font-size:15px;font-weight:600;color:var(--ink);margin-bottom:10px">Несохранённые изменения</div>' +
                    '<div style="font-size:13.5px;color:var(--ink2);line-height:1.6;margin-bottom:24px">Вы внесли изменения, которые ещё не сохранены. Уйти без сохранения?</div>' +
                    '<div style="display:flex;gap:10px;justify-content:flex-end">' +
                    '<button id="dirty-cancel" class="btn-g">Остаться</button>' +
                    '<button id="dirty-ok" style="padding:9px 20px;background:#dc2626;border:0;border-radius:var(--r2);color:#fff;font-size:13px;font-weight:600;cursor:pointer;font-family:var(--sans)">Уйти без сохранения</button>' +
                    '</div></div></div>';
                document.body.appendChild(el.firstChild);
                document.getElementById('dirty-cancel').addEventListener('click', function() {
                    document.getElementById('dirty-overlay').style.display = 'none';
                    _modalCallback && _modalCallback(false);
                });
                document.getElementById('dirty-ok').addEventListener('click', function() {
                    document.getElementById('dirty-overlay').style.display = 'none';
                    _modalCallback && _modalCallback(true);
                });
                return document.getElementById('dirty-overlay');
            })();

            function showDirtyModal(cb) {
                _modalCallback = cb;
                _modalEl.style.display = 'block';
            }

            // ── Person/Org card edit ──
            (function() {
                function $(id) {
                    return document.getElementById(id);
                }
                var personEditBtn = $('personEditBtn');
                var personCard = $('personCard');
                var personForm = $('personForm');
                if (personEditBtn) {
                    personEditBtn.addEventListener('click', function() {
                        personCard.style.display = 'none';
                        personForm.style.display = '';
                        var inp = document.getElementById('sender_name_person');
                        if (inp) inp.focus();
                    });
                }
                var orgEditBtn = $('orgEditBtn');
                var orgCard = $('orgCard');
                var orgForm = $('orgForm');
                if (orgEditBtn) {
                    orgEditBtn.addEventListener('click', function() {
                        orgCard.style.display = 'none';
                        orgForm.style.display = '';
                        var inp = $('sender_inn');
                        if (inp) inp.focus();
                    });
                }
                window.updateOrgCard = function(opfName, name, inn) {
                    var cn = $('orgCardName'),
                        ci = $('orgCardInn');
                    if (cn) cn.textContent = (opfName ? opfName + ' ' : '') + name;
                    if (ci) ci.textContent = inn ? 'ИНН ' + inn : 'ИНН не указан';
                    if (orgCard) orgCard.style.display = '';
                    if (orgForm) orgForm.style.display = 'none';
                };
            })();

            // ── DaData INN autocomplete ──
            (function() {
                function $(id) {
                    return document.getElementById(id);
                }

                function fetchJ(u) {
                    return fetch(u, {
                        headers: {
                            Accept: 'application/json'
                        }
                    }).then(function(r) {
                        return r.json();
                    });
                }
                var shopQ = <?= json_encode(rawurlencode($s->shopHost)) ?>;
                var iidQ = <?= json_encode(rawurlencode($s->insalesId)) ?>;
                var opfBase = '/insales/opf/search?shop=' + shopQ + '&insales_id=' + iidQ + '&q=';
                var dadataKey = <?= json_encode(getenv('DADATA_API_KEY') ?: '') ?>;
                var innEl = $('sender_inn');
                var innSugg = $('innSuggestions');
                var innStatus = $('innStatus');
                var innTimer;

                function dadataSearch(q, cb) {
                    if (!dadataKey) return;
                    fetch('https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/party', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Authorization': 'Token ' + dadataKey
                        },
                        body: JSON.stringify({
                            query: q,
                            count: 5,
                            status: ['ACTIVE']
                        })
                    }).then(function(r) {
                        return r.json();
                    }).then(cb).catch(function() {});
                }

                function fillOrgFromDaData(suggestion) {
                    var d = suggestion.data;
                    var innInput = $('sender_inn');
                    if (innInput && d.inn) {
                        innInput.value = d.inn;
                        innInput.classList.remove('field-err');
                    }
                    var opfShort = (d.opf && d.opf.short) ? d.opf.short.trim() : '';
                    var fullName = (d.name && d.name.short) ? d.name.short.trim() : (suggestion.value || '');
                    var nameOnly = fullName;
                    if (opfShort && nameOnly.toUpperCase().startsWith(opfShort.toUpperCase())) nameOnly = nameOnly.slice(opfShort.length).trim();
                    var nameInput = document.querySelector('#blockOrg input[name="sender_name"]');
                    if (nameInput && nameOnly) nameInput.value = nameOnly;
                    if (innStatus) innStatus.textContent = 'Подбираем ОПФ…';
                    if (opfShort) {
                        fetchJ(opfBase + encodeURIComponent(opfShort)).then(function(j) {
                            var items = j.items || [],
                                matched = null;
                            for (var i = 0; i < items.length; i++) {
                                if (items[i].title.toUpperCase() === opfShort.toUpperCase()) {
                                    matched = items[i];
                                    break;
                                }
                            }
                            if (!matched)
                                for (var i = 0; i < items.length; i++) {
                                    if (items[i].title.toUpperCase().indexOf(opfShort.toUpperCase()) !== -1) {
                                        matched = items[i];
                                        break;
                                    }
                                }
                            var opfStatus = $('opfAutoStatus');
                            if (matched) {
                                $('sender_opf_uid').value = matched.uid;
                                $('sender_opf_name').value = matched.title;
                                $('opfSavedName').textContent = matched.title;
                                var opfSavedEl = $('opfSaved');
                                if (opfSavedEl) opfSavedEl.style.display = 'flex';
                                var opfWrap = $('opfFieldWrap');
                                if (opfWrap) opfWrap.style.display = 'none';
                                if (opfStatus) {
                                    opfStatus.style.display = 'block';
                                    opfStatus.textContent = '✓ ОПФ: ' + matched.title;
                                }
                                if (innStatus) innStatus.textContent = '✓ ' + opfShort + ' ' + nameOnly;
                                window.updateOrgCard(matched.title, nameOnly, d.inn || '');
                            } else {
                                var opfWrap2 = $('opfFieldWrap');
                                if (opfWrap2) opfWrap2.style.display = '';
                                if (opfStatus) {
                                    opfStatus.style.display = 'block';
                                    opfStatus.textContent = 'ОПФ не найден — выберите вручную';
                                }
                                if (innStatus) innStatus.textContent = nameOnly + ' — выберите ОПФ';
                            }
                        });
                    }
                }

                if (innEl) {
                    innEl.addEventListener('input', function() {
                        if (this.value.trim()) {
                            this.classList.remove('field-err');
                            var m = $('innErrMsg');
                            if (m) m.style.display = 'none';
                        }
                        clearTimeout(innTimer);
                        var q = this.value.trim();
                        if (innSugg) innSugg.style.display = 'none';
                        if (innStatus) innStatus.textContent = '';
                        if (!dadataKey || q.length < 4) return;
                        innTimer = setTimeout(function() {
                            dadataSearch(q, function(res) {
                                if (!innSugg) return;
                                innSugg.innerHTML = '';
                                var suggs = (res && res.suggestions) ? res.suggestions : [];
                                if (!suggs.length) {
                                    innSugg.style.display = 'none';
                                    return;
                                }
                                suggs.forEach(function(s) {
                                    var li = document.createElement('li');
                                    li.innerHTML = '<strong>' + s.value + '</strong>' + (s.data.inn ? ' <span style="color:var(--ink3);font-size:11px">ИНН ' + s.data.inn + '</span>' : '') + (s.data.address && s.data.address.value ? '<br><span style="font-size:11px;color:var(--ink3)">' + s.data.address.value + '</span>' : '');
                                    li.addEventListener('click', function() {
                                        innSugg.style.display = 'none';
                                        fillOrgFromDaData(s);
                                    });
                                    innSugg.appendChild(li);
                                });
                                innSugg.style.display = 'block';
                            });
                        }, 400);
                    });
                    innEl.addEventListener('blur', function() {
                        setTimeout(function() {
                            if (innSugg) innSugg.style.display = 'none';
                        }, 200);
                    });
                    document.addEventListener('click', function(e) {
                        if (innEl && innSugg && !innEl.contains(e.target) && !innSugg.contains(e.target)) innSugg.style.display = 'none';
                    });
                }
            })();

            // ── Phone widget ──
            (function() {
                function $(id) {
                    return document.getElementById(id);
                }
                var COUNTRIES = [{
                        code: 'RU',
                        dial: '7',
                        name: 'Россия',
                        len: 11
                    }, {
                        code: 'KZ',
                        dial: '7',
                        name: 'Казахстан',
                        len: 11
                    },
                    {
                        code: 'BY',
                        dial: '375',
                        name: 'Беларусь',
                        len: 12
                    }, {
                        code: 'UA',
                        dial: '380',
                        name: 'Украина',
                        len: 12
                    },
                    {
                        code: 'KG',
                        dial: '996',
                        name: 'Кыргызстан',
                        len: 12
                    }, {
                        code: 'AM',
                        dial: '374',
                        name: 'Армения',
                        len: 11
                    },
                    {
                        code: 'GE',
                        dial: '995',
                        name: 'Грузия',
                        len: 12
                    }, {
                        code: 'UZ',
                        dial: '998',
                        name: 'Узбекистан',
                        len: 12
                    },
                ];

                function detectCountry(digits) {
                    if (!digits) return COUNTRIES[0];
                    if (digits[0] === '8') return COUNTRIES[0];
                    for (var pl = 3; pl >= 1; pl--) {
                        var p = digits.slice(0, pl);
                        for (var i = 0; i < COUNTRIES.length; i++) {
                            if (COUNTRIES[i].dial === p) return COUNTRIES[i];
                        }
                    }
                    return COUNTRIES[0];
                }

                function phoneIsValid(digits, c) {
                    if (!digits) return false;
                    var nationalLen = c.len - c.dial.length;
                    return digits.length === nationalLen;
                }

                function initPhone(inputId, codeId, dropId, hintId, wrapId) {
                    var input = $(inputId),
                        codeEl = $(codeId),
                        dropdown = $(dropId),
                        hintEl = $(hintId),
                        wrap = $(wrapId);
                    if (!input) return;
                    var cur = COUNTRIES[0];
                    if (dropdown) {
                        COUNTRIES.forEach(function(c) {
                            var item = document.createElement('div');
                            item.className = 'pflag-item';
                            item.innerHTML = '<span class="pflag-cc">' + c.code + '</span><span style="font-size:13px;color:var(--ink)">' + c.name + '</span><span style="font-size:11px;color:var(--ink3);margin-left:auto">+' + c.dial + '</span>';
                            item.addEventListener('click', function(e) {
                                e.stopPropagation();
                                cur = c;
                                if (codeEl) codeEl.textContent = '+' + c.dial;
                                dropdown.style.display = 'none';
                                input.focus();
                                updateHint();
                            });
                            dropdown.appendChild(item);
                        });
                    }
                    if (wrap) {
                        var btn = wrap.querySelector('.phone-flag');
                        if (btn) btn.addEventListener('click', function(e) {
                            e.stopPropagation();
                            if (!dropdown) return;
                            var isOpen = dropdown.style.display !== 'none';
                            document.querySelectorAll('.phone-flag-dropdown').forEach(function(d) {
                                d.style.display = 'none';
                            });
                            if (isOpen) return;
                            var rect = btn.getBoundingClientRect(),
                                spaceBelow = window.innerHeight - rect.bottom;
                            dropdown.style.display = 'block';
                            dropdown.style.width = '240px';
                            dropdown.style.left = rect.left + 'px';
                            if (spaceBelow >= 120) {
                                dropdown.style.top = (rect.bottom + 4) + 'px';
                                dropdown.style.bottom = 'auto';
                            } else {
                                dropdown.style.bottom = (window.innerHeight - rect.top + 4) + 'px';
                                dropdown.style.top = 'auto';
                            }
                        });
                    }
                    document.addEventListener('click', function() {
                        if (dropdown) dropdown.style.display = 'none';
                    });

                    function updateHint() {
                        if (!hintEl) return;
                        var digits = input.value.replace(/\D/g, '');
                        if (!digits) {
                            hintEl.textContent = '';
                            hintEl.className = 'phone-valid-hint';
                            return;
                        }
                        var nationalLen = cur.len - cur.dial.length;
                        if (phoneIsValid(digits, cur)) {
                            hintEl.textContent = '✓ Номер корректен';
                            hintEl.className = 'phone-valid-hint valid';
                        } else {
                            var m = nationalLen - digits.length;
                            hintEl.textContent = m > 0 ? 'Ещё ' + m + (m === 1 ? ' цифра' : m < 5 ? ' цифры' : ' цифр') : '✗ Неверный формат';
                            hintEl.className = 'phone-valid-hint ' + (m > 0 ? 'pending' : 'invalid');
                        }
                    }
                    input.addEventListener('input', function() {
                        var detected = detectCountry(this.value.replace(/\D/g, ''));
                        if (detected.code !== cur.code) {
                            cur = detected;
                            if (codeEl) codeEl.textContent = '+' + cur.dial;
                        }
                        updateHint();
                        var f = this.closest('form');
                        if (f && f._markDirty) f._markDirty();
                    });
                    var initC = detectCountry(input.value.replace(/\D/g, ''));
                    cur = initC;
                    if (codeEl) codeEl.textContent = '+' + cur.dial;
                    updateHint();
                }
                initPhone('sender_contact_phone', 'flagCode1', 'phoneDropdown1', 'phoneHint1', 'phoneWrap1');
                initPhone('sender_contact_phone2', 'flagCode2', 'phoneDropdown2', 'phoneHint2', 'phoneWrap2');

                window.showPhone2 = function() {
                    var b = $('phone2Block'),
                        btn = $('addPhone2Btn');
                    if (b) {
                        b.style.removeProperty('display');
                    }
                    if (btn) btn.style.display = 'none';
                    var inp = $('sender_contact_phone2');
                    if (inp) inp.focus();
                    var f = document.querySelector('#settingsForm');
                    if (f && f._markDirty) f._markDirty();
                };
                var rm = $('removePhone2Btn');
                if (rm) rm.addEventListener('click', function() {
                    var b = $('phone2Block'),
                        btn = $('addPhone2Btn');
                    if (b) b.style.display = 'none';
                    if (btn) btn.style.display = '';
                    var p2 = $('sender_contact_phone2'),
                        ext = $('sender_contact_ext');
                    if (p2) p2.value = '';
                    if (ext) ext.value = '';
                    var h2 = $('phoneHint2');
                    if (h2) {
                        h2.textContent = '';
                        h2.className = 'phone-valid-hint';
                    }
                    var f = document.querySelector('#settingsForm');
                    if (f && f._markDirty) f._markDirty();
                });

                // expose _markDirty
                document.querySelectorAll('form[method="post"]').forEach(function(form) {
                    form._markDirty = function() {
                        var btn = form.querySelector('button.js-save-btn');
                        if (btn) btn.disabled = false;
                        form.classList.add('form-dirty');
                    };
                });
            })();
        </script>
<?php
        echo '</body></html>';
    }

    // ──────────────────────────────────────────────────────────────────────────
    // HTML head with all styles
    // ──────────────────────────────────────────────────────────────────────────

    private static function renderHtmlHead(string $title): void
    {
        $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        echo <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/x-icon" href="/favicon.ico">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
<link rel="apple-touch-icon" sizes="192x192" href="/favicon-192.png">
<title>{$t}</title>
<link href="/fonts/fonts.css" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --ink:#1a1714;--ink2:#4a4540;--ink3:#8c8580;
  --bg:#f5f3f0;--s1:#fff;--s2:#f9f8f6;--s3:#f2efeb;
  --line:#e4dfd8;--line2:#ccc5bb;
  --amber:#f5501e;--amb2:#c73e12;--ambl:#fff2ee;--amb3:#fde0d6;
  --gold:#FCAF17;--grn:#14864a;--grnl:#edfaf3;--grnb:#c6f0d8;
  --sans:'Instrument Sans',sans-serif;
  --serif:'Instrument Serif',serif;
  --mono:'JetBrains Mono',monospace;
  --r:6px;--r2:10px;--r3:14px;
  --sh:0 1px 3px rgba(26,23,20,.06),0 4px 16px rgba(26,23,20,.07);
}
html,body{height:100%}
body{font-family:var(--sans);background:var(--bg);color:var(--ink);font-size:14px;line-height:1.5;-webkit-font-smoothing:antialiased}
 
/* LAYOUT */
.app{display:flex;height:100vh;overflow:hidden}
 
/* SIDEBAR */
.sidebar{width:236px;flex-shrink:0;background:var(--s1);border-right:1px solid var(--line);display:flex;flex-direction:column;transition:transform .25s cubic-bezier(.22,1,.36,1);z-index:200;overflow-y:auto}
.brand{padding:18px 18px 16px;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:10px}
.brand-logo{width:34px;height:34px;flex-shrink:0;background:var(--gold);border-radius:8px;display:flex;align-items:center;justify-content:center;padding:5px}
.brand-logo svg{width:100%;height:100%}
.brand-name{font-size:13px;font-weight:600;color:var(--ink);line-height:1.2}
.brand-host{font-size:10px;color:var(--ink3);font-family:var(--mono);margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sbar-status{margin:12px 14px 0;padding:8px 12px;background:var(--grnl);border:1px solid var(--grnb);border-radius:var(--r);display:flex;align-items:center;gap:7px}
.sdot{width:6px;height:6px;border-radius:50%;background:var(--grn);flex-shrink:0}
.stxt{font-size:11px;color:var(--grn);font-weight:500}
.nav{padding:14px 10px;flex:1}
.nav-lbl{font-size:9px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:var(--ink3);padding:0 10px;margin-bottom:3px}
.nav-item{display:flex;align-items:center;gap:9px;padding:9px 10px;border-radius:var(--r2);cursor:pointer;font-size:13px;font-weight:500;color:var(--ink3);border:0;background:0;width:100%;font-family:var(--sans);text-align:left;margin-bottom:1px;transition:all .15s}
.nav-item:hover{background:var(--s3);color:var(--ink2)}
.nav-item.active{background:var(--ambl);color:var(--amber)}
.nav-ico{width:16px;flex-shrink:0;display:flex;align-items:center;justify-content:center}
.nav-badge{margin-left:auto;font-size:10px;font-family:var(--mono);background:var(--grnl);color:var(--grn);border:1px solid var(--grnb);padding:1px 6px;border-radius:20px}
.sbar-footer{padding:14px 16px;border-top:1px solid var(--line)}
.sbar-ver{font-size:10px;color:var(--ink3);font-family:var(--mono)}
 
/* TOPBAR mobile */
.topbar{display:none;position:fixed;top:0;left:0;right:0;z-index:300;background:var(--s1);border-bottom:1px solid var(--line);padding:12px 16px;align-items:center;gap:12px;box-shadow:0 1px 8px rgba(26,23,20,.06)}
.burger{width:36px;height:36px;border-radius:var(--r);background:var(--s3);border:1px solid var(--line);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;cursor:pointer;flex-shrink:0;transition:background .15s}
.burger:hover{background:var(--line)}
.burger span{display:block;width:16px;height:1.5px;background:var(--ink2);border-radius:2px}
.topbar-brand{display:flex;align-items:center;gap:8px}
.topbar-logo{width:26px;height:26px;background:var(--gold);border-radius:6px;padding:4px;flex-shrink:0}
.topbar-name{font-size:14px;font-weight:600;color:var(--ink)}
.topbar-page{margin-left:auto;font-size:12px;color:var(--ink3)}
.overlay{display:none;position:fixed;inset:0;z-index:150;background:rgba(26,23,20,.35);backdrop-filter:blur(2px)}
.overlay.show{display:block}
 
/* MAIN */
.main{flex:1;overflow-y:auto;scrollbar-width:thin;scrollbar-color:var(--line2) transparent}
.main::-webkit-scrollbar{width:4px}
.main::-webkit-scrollbar-thumb{background:var(--line2);border-radius:4px}
.content{max-width:1100px;padding:36px 40px}
.page-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:stretch}
.page-grid-3{display:grid;grid-template-columns:1fr 1fr 280px;gap:16px;align-items:start}
.page-col{display:flex;flex-direction:column;gap:12px}
@media(max-width:1100px){.page-grid-3{grid-template-columns:1fr 1fr}}
@media(max-width:900px){.page-grid{grid-template-columns:1fr}.page-grid-3{grid-template-columns:1fr}}
/* PROGRESS CARD */
.prog-card{background:var(--s1);border:1px solid var(--line);border-radius:var(--r3);overflow:hidden;position:sticky;top:24px}
.prog-bar-wrap{padding:14px 16px;border-bottom:1px solid var(--s3)}
.prog-bar-label{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px}
.prog-bar-pct{font-size:10px;color:var(--ink3);font-family:var(--mono)}
.prog-bar{height:4px;background:var(--s3);border-radius:4px;overflow:hidden;margin-bottom:0}
.prog-bar-fill{height:100%;background:var(--grn);border-radius:4px}
.prog-steps{display:flex;flex-direction:column;padding:12px 16px;gap:0;border-bottom:1px solid var(--s3)}
.prog-step{display:flex;align-items:flex-start;gap:9px;padding:7px 0;border-bottom:1px solid var(--s3)}
.prog-step:last-child{border-bottom:0;padding-bottom:0}
.prog-step:first-child{padding-top:0}
.prog-step-ico{width:20px;height:20px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px}
.prog-step-ico.done{background:var(--grnl);border:1px solid var(--grnb)}
.prog-step-ico.curr{background:var(--ambl);border:1px solid #f5c4b3}
.prog-step-ico.todo{background:var(--s3);border:1px solid var(--line)}
.prog-step-lbl{font-size:12px;font-weight:600;color:var(--ink);line-height:1.3}
.prog-step-sub{font-size:11px;color:var(--ink3);margin-top:1px}
.prog-checklist{padding:12px 16px;border-bottom:1px solid var(--s3)}
.prog-cl-item{display:flex;align-items:center;gap:7px;font-size:11px;padding:4px 0;border-bottom:1px solid var(--s3)}
.prog-cl-item:last-child{border-bottom:0}
.prog-cl-item.ok{color:var(--grn)}
.prog-cl-item.warn{color:var(--ink3)}
.prog-cl-ico{width:14px;height:14px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.prog-cl-ico.ok{background:var(--grnl);border:1px solid var(--grnb)}
.prog-cl-ico.warn{background:var(--s3);border:1px solid var(--line)}
.prog-next{padding:10px 16px;background:var(--ambl);border-top:1px solid #f5c4b3}
.prog-next-lbl{font-size:9px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--amber);margin-bottom:3px}
.prog-next-txt{font-size:11px;color:var(--ink2);line-height:1.4}
 
/* PAGES */
.pg-hdr{margin-bottom:26px}
.pg-title{font-family:var(--serif);font-size:26px;color:var(--ink);line-height:1.2;margin-bottom:4px}
.pg-sub{font-size:13px;color:var(--ink3)}
.page{display:none;animation:pgIn .2s ease}
.page.active{display:block}
@keyframes pgIn{from{opacity:0;transform:translateY(5px)}to{opacity:1;transform:none}}
 
/* ALERTS */
.alert-ok{padding:10px 16px;background:var(--grnl);border:1px solid var(--grnb);border-radius:var(--r2);font-size:13px;color:var(--grn);margin-bottom:16px}
.alert-err{padding:10px 16px;background:#fef2f2;border:1px solid #fecaca;border-radius:var(--r2);font-size:13px;color:#b91c1c;margin-bottom:16px}
 
/* CARD */
.card{background:var(--s1);border:1px solid var(--line);border-radius:var(--r3);margin-bottom:12px;box-shadow:var(--sh);overflow:hidden}
.card-hdr{padding:14px 20px;border-bottom:1px solid var(--s3);display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.card-title{font-size:13px;font-weight:600;color:var(--ink)}
.card-sub{font-size:11px;color:var(--ink3);margin-top:1px}
.card-body{padding:20px}
.card-body.sm{padding:14px 20px}
 
/* FIELDS */
.field{margin-bottom:14px}
.field:last-child{margin-bottom:0}
.field>label{display:block;font-size:11px;font-weight:600;color:var(--ink2);letter-spacing:.03em;text-transform:uppercase;margin-bottom:5px}
/* field input styles moved to PHONE WIDGET section below */
.field .hint{font-size:11px;color:var(--ink3);margin-top:4px}
.g2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.g3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
 
/* SEGMENT */
.seg{display:flex;gap:2px;padding:3px;background:var(--s3);border-radius:var(--r2);border:1px solid var(--line);margin-bottom:16px}
.seg-btn{flex:1;padding:7px 8px;border-radius:7px;font-size:12px;font-weight:500;color:var(--ink3);cursor:pointer;text-align:center;transition:all .15s;border:0;background:transparent;font-family:var(--sans)}
.seg-btn:hover{color:var(--ink2)}
.seg-btn.on{background:var(--s1);color:var(--ink);box-shadow:0 1px 3px rgba(26,23,20,.09)}
 
/* AUTOCOMPLETE */
.suggestions{list-style:none;border:1px solid var(--line);border-radius:var(--r2);max-height:200px;overflow-y:auto;display:none;margin-top:4px;background:var(--s1);box-shadow:var(--sh);position:relative;z-index:10}
.suggestions li{padding:8px 12px;cursor:pointer;font-size:12px;color:var(--ink2);border-bottom:1px solid var(--s3)}
.suggestions li:last-child{border-bottom:0}
.suggestions li:hover{background:var(--ambl);color:var(--amber)}
.act{display:inline-flex;align-items:center;gap:6px;margin-top:5px;padding:3px 8px 3px 10px;background:var(--s3);border:1px solid var(--line);border-radius:20px;font-size:11px;color:var(--ink2)}
.act-rm{cursor:pointer;color:var(--ink3);font-size:13px;transition:color .1s;background:0;border:0;font-family:var(--sans)}
.act-rm:hover{color:var(--amber)}
 
/* SECTION LABEL */
.sec-lbl{font-size:10px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--ink3);margin-bottom:10px;display:flex;align-items:center;gap:8px}
.sec-lbl::after{content:'';flex:1;height:1px;background:var(--s3)}
 
/* INFO ROWS */
.ir{display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--s3);gap:12px}
.ir:last-child{border-bottom:0}
.ir-l{font-size:12px;color:var(--ink3)}
.ir-v{font-size:12px;color:var(--ink);font-family:var(--mono)}
 
/* BADGES */
.bdg{display:inline-flex;align-items:center;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:600;font-family:var(--mono);white-space:nowrap}
.bdg-g{background:var(--grnl);color:var(--grn);border:1px solid var(--grnb)}
 
/* BUTTONS */
.btn-p{padding:9px 22px;background:var(--amber);border:0;border-radius:var(--r2);color:#fff;font-size:13px;font-weight:600;cursor:pointer;font-family:var(--sans);transition:all .15s}
.btn-p:hover{background:var(--amb2)}
.btn-p:active{transform:translateY(1px)}
.btn-p:disabled{background:var(--s3);color:var(--ink3);cursor:not-allowed;transform:none}
.btn-p:disabled:hover{background:var(--s3)}
.form-dirty{
  position:relative;
  border-radius:var(--r3);
  box-shadow:0 0 0 1px var(--amber),0 0 24px -4px rgba(245,80,30,.25);
  transition:box-shadow .3s ease;
  padding:16px;
  margin:-16px
}
.form-dirty .btn-row{margin-top:24px;padding-bottom:4px}
.btn-cancel{display:none}
.form-dirty .btn-cancel{display:inline-flex}
.btn-g{padding:8px 16px;background:transparent;border:1px solid var(--line2);border-radius:var(--r2);color:var(--ink2);font-size:12px;font-weight:500;cursor:pointer;font-family:var(--sans);transition:all .15s}
.btn-g:hover{border-color:var(--ink3);color:var(--ink);background:var(--s3)}
.btn-sub{padding:7px 12px;background:var(--s3);border:1px solid var(--line);border-radius:var(--r2);color:var(--ink3);font-size:12px;cursor:pointer;font-family:var(--sans);transition:all .15s}
.btn-sub:hover{color:var(--ink2);border-color:var(--line2)}
.btn-row{display:flex;gap:8px;align-items:center;margin-top:20px;padding-top:18px;border-top:1px solid var(--s3);flex-wrap:wrap}
 
/* AUTH PAGE */
.auth-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px 16px;background:var(--bg)}
.auth-card{background:var(--s1);border:1px solid var(--line);border-radius:var(--r3);padding:32px;max-width:420px;width:100%;box-shadow:var(--sh)}
.auth-logo{width:48px;height:48px;background:var(--gold);border-radius:10px;display:flex;align-items:center;justify-content:center;padding:6px;margin-bottom:16px}
.auth-title{font-family:var(--serif);font-size:22px;color:var(--ink);margin-bottom:4px}
.auth-sub{font-size:12px;color:var(--ink3);margin-bottom:12px}
.auth-desc{font-size:13px;color:var(--ink2);margin-bottom:20px;line-height:1.5}
.auth-link{display:block;margin-top:16px;font-size:12px;color:var(--ink3);text-decoration:none}
.auth-link:hover{color:var(--amber)}
 
/* RESPONSIVE */
@media(max-width:768px){
  .app{display:block;height:auto;overflow:visible}
  .topbar{display:flex}
  .sidebar{position:fixed;top:0;left:0;bottom:0;transform:translateX(-100%);box-shadow:4px 0 24px rgba(26,23,20,.12)}
  .sidebar.open{transform:translateX(0)}
  .main{height:auto;overflow:visible;padding-top:58px}
  .content{padding:20px 16px;max-width:100%}
  .g2{grid-template-columns:1fr}
  .g3{grid-template-columns:1fr 1fr}
  .pg-title{font-size:22px}
  .btn-p,.btn-g{flex:1;justify-content:center;text-align:center}
}
@media(max-width:420px){
  .g3{grid-template-columns:1fr}
}
/* TERMINAL CARD */
@keyframes skel{0%,100%{opacity:1}50%{opacity:.4}}
.term-saved{background:var(--s2);border:1px solid var(--line);border-radius:var(--r2);padding:14px 16px}
.term-name{font-size:13px;font-weight:600;color:var(--ink);margin-bottom:3px}
.term-addr{font-size:12px;color:var(--ink2)}
.term-chips{display:flex;gap:5px;flex-wrap:wrap;margin-top:10px}
.chip{font-size:11px;color:var(--ink3);background:var(--s1);border:1px solid var(--line);padding:3px 9px;border-radius:20px}

/* Sender type animation */
.type-block{transition:opacity .2s ease,transform .2s ease}
.type-block.hidden{display:none}
/* OPF saved card */
.opf-saved{background:var(--s2);border:1px solid var(--line);border-radius:var(--r2);padding:10px 14px;display:flex;align-items:center;justify-content:space-between;gap:10px}
.opf-name{font-size:13px;font-weight:500;color:var(--ink)}
.opf-country{font-size:11px;color:var(--ink3);margin-top:1px}
.opf-search-wrap{background:var(--s2);border:1px solid var(--line);border-radius:var(--r2);padding:10px 12px;position:relative}
.opf-search-input{width:100%;padding:7px 10px;background:var(--s1);border:1px solid var(--line);border-radius:var(--r2);font-size:12px;color:var(--ink);outline:none;transition:border .15s,box-shadow .15s}
.opf-search-input:focus{border-color:var(--amber);box-shadow:0 0 0 3px var(--ambl)}
.opf-list{margin-top:6px;background:var(--s1);border:1px solid var(--line);border-radius:var(--r2);display:none;max-height:180px;overflow-y:auto;position:relative;z-index:20}
.opf-item{padding:8px 12px;font-size:12px;color:var(--ink2);border-bottom:1px solid var(--s3);cursor:pointer;transition:background .12s}
.opf-item:last-child{border-bottom:0}
.opf-item:hover{background:var(--ambl);color:var(--amber)}
.opf-item-sub{font-size:11px;color:var(--ink3);margin-top:1px}
.field input.field-err{border-color:#ef4444;box-shadow:0 0 0 3px #fef2f2;background:var(--s1)}
.field-err-msg{font-size:11px;color:#b91c1c;margin-top:4px;display:none}

/* PHONE WIDGET */
.phone-wrap{position:relative;display:flex;align-items:stretch;height:36px;border:1px solid var(--line);border-radius:var(--r2);background:var(--s2);transition:border .15s,box-shadow .15s;overflow:hidden}
.phone-wrap:focus-within{border-color:var(--amber);box-shadow:0 0 0 3px var(--ambl);background:var(--s1)}
.phone-flag{display:flex;align-items:center;justify-content:center;width:48px;padding:0;background:transparent;border:0;border-right:1px solid var(--line);cursor:pointer;flex-shrink:0;transition:background .12s;border-radius:0;height:100%}
.phone-flag:hover{background:var(--s3)}
.flag-code{font-size:12px;font-weight:700;color:var(--ink)}
.phone-input{flex:1;padding:8px 10px;background:transparent;border:0;font-size:13px;color:var(--ink);font-family:var(--sans);outline:none;min-width:0;border-radius:0;height:36px}
.field input:not([type="checkbox"]):not(.phone-input),.field select{width:100%;padding:9px 12px;background:var(--s2);border:1px solid var(--line);border-radius:var(--r2);font-size:13px;color:var(--ink);font-family:var(--sans);transition:border .15s,box-shadow .15s,background .15s;-webkit-appearance:none;outline:none}
.field input:not(.phone-input):focus,.field select:focus{border-color:var(--amber);box-shadow:0 0 0 3px var(--ambl);background:var(--s1)}
.phone-flag-dropdown{position:fixed;z-index:9999;background:var(--s1);border:1px solid var(--line);border-radius:var(--r2);box-shadow:0 8px 24px rgba(26,23,20,.16);min-width:220px;max-height:220px;overflow-y:auto;padding:4px}
.pflag-item{display:flex;align-items:center;gap:8px;padding:7px 12px;border-radius:var(--r);cursor:pointer;transition:background .12s}
.pflag-cc{font-size:11px;font-weight:700;letter-spacing:.04em;color:var(--ink);font-family:var(--mono);min-width:28px}
.pflag-item:hover{background:var(--ambl)}
.phone-valid-hint{font-size:11px;margin-top:3px;min-height:14px;transition:color .15s}
.phone-valid-hint.valid{color:var(--grn)}
.phone-valid-hint.pending{color:var(--ink3)}
.phone-valid-hint.invalid{color:#b91c1c}
</style>
</head>
<body>
HTML;
    }

    private static function buildContactPhoneField(string $phone1, string $phone2, string $ext = ''): string
    {
        $phone1 = trim($phone1);
        $phone2 = trim($phone2);
        $ext    = trim($ext);
        $parts = [];
        if ($phone1 !== '') $parts[] = $phone1;
        if ($phone2 !== '') {
            $parts[] = $ext !== '' ? $phone2 . ',' . $ext : $phone2;
        }
        return implode(';', $parts);
    }

    private static function renderError(string $message): void
    {
        self::renderHtmlHead('Ошибка — Деловые Линии');
        echo '<div style="padding:40px;max-width:480px;margin:auto">';
        echo '<div class="alert-err">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>';
        echo '</div></body></html>';
    }
}
