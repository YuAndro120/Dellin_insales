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
                    'sender_contact_phone'  => trim((string) ($_POST['sender_contact_phone']  ?? '')),
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
        } catch (\Throwable $e) {
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
    ?>

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

                            <div class="page-grid">
                                <div class="page-col">
                                    <!-- Организация -->
                                    <div class="card">
                                        <div class="card-hdr">
                                            <div>
                                                <div class="card-title">Организация</div>
                                                <div class="card-sub">Юридические данные отправителя</div>
                                            </div>
                                            <button type="button" id="btnLoadFromInsales" class="btn-sub">⬇ Из inSales</button>
                                        </div>
                                        <div class="card-body">
                                            <div class="sec-lbl">Тип отправителя</div>
                                            <div class="seg" id="segSenderType">
                                                <button type="button" class="seg-btn<?= $s->senderType === 'person' ? ' on' : '' ?>" data-val="person">Физическое лицо</button>
                                                <button type="button" class="seg-btn<?= $s->senderType !== 'person' ? ' on' : '' ?>" data-val="org">Организация</button>
                                            </div>
                                            <input type="hidden" id="sender_type" name="sender_type" value="<?= $h($s->senderType ?? 'person') ?>">

                                            <!-- ФИЗЛИЦО -->
                                            <div id="blockPerson" class="type-block<?= $s->senderType !== 'person' ? ' hidden' : '' ?>">
                                                <div class="field">
                                                    <label>ФИО</label>
                                                    <input type="text" id="sender_name" name="sender_name" value="<?= $h($s->senderName ?? '') ?>" placeholder="Иванов Иван Иванович">
                                                </div>
                                                <div class="field">
                                                    <label>Тип документа</label>
                                                    <select id="sender_doc_type" name="sender_doc_type">
                                                        <option value="passport" <?= $s->senderDocType === 'passport'       ? ' selected' : '' ?>>Паспорт РФ</option>
                                                        <option value="drivingLicence" <?= $s->senderDocType === 'drivingLicence' ? ' selected' : '' ?>>Водительское удостоверение</option>
                                                    </select>
                                                </div>
                                                <div class="g2">
                                                    <div class="field">
                                                        <label>Серия</label>
                                                        <input type="text" name="sender_doc_serial" value="<?= $h($s->senderDocSerial ?? '') ?>" placeholder="5222">
                                                    </div>
                                                    <div class="field">
                                                        <label>Номер</label>
                                                        <input type="text" name="sender_doc_number" value="<?= $h($s->senderDocNumber ?? '') ?>" placeholder="191652">
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- ОРГАНИЗАЦИЯ -->
                                            <div id="blockOrg" class="type-block<?= $s->senderType === 'person' ? ' hidden' : '' ?>">
                                                <div class="field">
                                                    <label>Название организации</label>
                                                    <input type="text" id="sender_name" name="sender_name" value="<?= $h($s->senderName ?? '') ?>" placeholder="ИП Иванов / ООО Ромашка">
                                                </div>
                                                <div class="field">
                                                    <label>ОПФ из справочника ДЛ</label>
                                                    <?php $hasOpf = ($s->senderOpfUid ?? '') !== ''; ?>
                                                    <div id="opfSaved" <?= !$hasOpf ? ' style="display:none"' : '' ?> class="opf-saved">
                                                        <div>
                                                            <div class="opf-name" id="opfSavedName"><?= $h($s->senderOpfName !== '' ? $s->senderOpfName : 'Сохранено') ?></div>
                                                            <div class="opf-country" id="opfSavedCountry">из справочника ДЛ</div>
                                                        </div>
                                                        <button type="button" id="opfEditBtn" class="btn-g" style="font-size:11px;padding:5px 10px;flex-shrink:0">
                                                            <svg width="12" height="12" viewBox="0 0 16 16" fill="none" style="vertical-align:-1px;margin-right:3px" aria-hidden="true">
                                                                <path d="M11.333 2a1.886 1.886 0 012.667 2.667L5.333 13.333 2 14l.667-3.333L11.333 2z" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" />
                                                            </svg>
                                                            Изменить
                                                        </button>
                                                    </div>
                                                    <div id="opfSearchWrap" <?= $hasOpf ? ' style="display:none"' : '' ?> class="opf-search-wrap">
                                                        <input class="opf-search-input" type="text" id="opfSearchInput" autocomplete="off" placeholder="Начните вводить — ИП, ООО, АО…">
                                                        <div style="font-size:11px;color:var(--ink3);margin-top:5px">Поиск по справочнику Деловых Линий</div>
                                                        <ul id="opfList" class="opf-list"></ul>
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
                                                <div class="field">
                                                    <label>ИНН</label>
                                                    <input type="text" id="sender_inn" name="sender_inn" value="<?= $h($s->senderInn ?? '') ?>" placeholder="1234567890" class="<?= (($s->senderInn ?? '') === '' && $s->senderType !== 'person') ? 'field-err' : '' ?>">
                                                    <div class="field-err-msg" id="innErrMsg">Введите ИНН — обязательное поле для организаций</div>
                                                </div>
                                                <div class="field">
                                                    <label>Юридический адрес</label>
                                                    <input type="text" name="sender_juridical_address" value="<?= $h($s->senderJuridicalAddress ?? '') ?>" placeholder="г. Москва, ул. Примерная, д. 1">
                                                </div>
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
                                                    <div class="card-title">Контрагент ДЛ</div>
                                                    <div class="card-sub">Выберите контрагента для оформления заявок</div>
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
                                                    <div class="card-title">Контрагент ДЛ</div>
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
                                                <div class="card-title">Контактное лицо</div>
                                                <div class="card-sub">Для связи при отправке груза</div>
                                            </div>
                                        </div>
                                        <div class="card-body">
                                            <div class="g2">
                                                <div class="field"><label>Имя</label><input type="text" id="sender_contact_name" name="sender_contact_name" value="<?= $h($s->senderContactName ?? '') ?>" placeholder="Иванов Иван"></div>
                                                <div class="field"><label>Телефон</label><input type="text" id="sender_contact_phone" name="sender_contact_phone" value="<?= $h($s->senderContactPhone ?? '') ?>" placeholder="79131409995"></div>
                                                <div class="field" style="grid-column:1/-1"><label>Email для уведомлений ДЛ</label><input type="email" id="requester_email" name="requester_email" value="<?= $h($s->requesterEmail) ?>" required></div>
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
                            </div><!-- /page-grid -->

                            <div class="btn-row" style="justify-content:flex-start">
                                <button type="submit" class="btn-p">Сохранить изменения</button>
                                <a href="/insales/app?shop=<?= $h($s->shopHost) ?>&insales_id=<?= $h($s->insalesId) ?>&atk=<?= $h($accessToken) ?>" class="btn-g" style="text-decoration:none;display:inline-flex;align-items:center">Отмена</a>
                            </div>
                        </form>
                    </div><!-- /page-sender -->


                    <!-- ══ ДОСТАВКА ══ -->
                    <div class="page" id="page-shipping">
                        <div class="pg-hdr">
                            <div class="pg-title">Параметры доставки</div>
                            <div class="pg-sub">Настройки расчёта и оформления заказов</div>
                        </div>
                        <form method="post" action="/insales/app">
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

                            <div class="page-grid">
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
                                                    <?php if ($hasDerivalAddr): ?>
                                                        <div class="btn-row" style="border:0;padding-top:8px;margin-top:4px">
                                                            <button type="button" id="derivalAddrCancelBtn" class="btn-g">Отмена</button>
                                                        </div>
                                                    <?php endif; ?>
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
                            </div><!-- /page-grid -->

                            <div class="btn-row" style="justify-content:flex-start">
                                <button type="submit" class="btn-p">Сохранить изменения</button>
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
                                <form method="post" action="/insales/app">
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

                // ── Nav ──
                document.querySelectorAll('.nav-item').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        document.querySelectorAll('.nav-item').forEach(function(b) {
                            b.classList.remove('active');
                        });
                        document.querySelectorAll('.page').forEach(function(p) {
                            p.classList.remove('active');
                        });
                        this.classList.add('active');
                        var pg = document.getElementById('page-' + this.dataset.page);
                        if (pg) pg.classList.add('active');
                        var lbl = document.getElementById('topbar-page');
                        if (lbl) lbl.textContent = this.dataset.label || '';
                        if (window.innerWidth <= 768) closeSidebar();
                        sessionStorage.setItem('activeNavPage', this.dataset.page);
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
                // ── Load from inSales ──
                var btnLoad = document.getElementById('btnLoadFromInsales');
                if (btnLoad) {
                    btnLoad.addEventListener('click', function() {
                        var btn = this;
                        btn.disabled = true;
                        btn.textContent = 'Загрузка…';
                        fetch('/insales/account?shop=' + shopQ + '&insales_id=' + iidQ)
                            .then(function(r) {
                                return r.json();
                            })
                            .then(function(d) {
                                if (!d.ok) throw new Error(d.error || 'Ошибка');
                                if (d.organization && $('sender_name')) $('sender_name').value = d.organization;
                                if (d.phone && $('sender_contact_phone')) $('sender_contact_phone').value = d.phone.replace(/\D/g, '');
                                if (d.email && $('requester_email')) $('requester_email').value = d.email;
                                var name = (d.organization || '').toLowerCase();
                                var typeInput = $('sender_type');
                                var newType = 'person';
                                if (name.indexOf('ип ') === 0 || name.indexOf('ип"') === 0) {
                                    newType = 'ip';
                                } else if (name.indexOf('ооо') !== -1 || name.indexOf(' ао ') !== -1 || name.indexOf('зао') !== -1) {
                                    newType = 'company';
                                }
                                if (typeInput) typeInput.value = newType;
                                document.querySelectorAll('#segSenderType .seg-btn').forEach(function(b) {
                                    b.classList.toggle('on', b.dataset.val === newType);
                                });
                                btn.textContent = '✓ Загружено';
                                btn.disabled = false;
                            })
                            .catch(function(e) {
                                alert('Ошибка: ' + e.message);
                                btn.disabled = false;
                                btn.textContent = '⬇ Из inSales';
                            });
                    });
                }
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
            };
            window.setDerivalVariant = function(btn, val) {
                btn.parentNode.querySelectorAll('.seg-btn').forEach(function(b) {
                    b.classList.remove('on');
                });
                btn.classList.add('on');
                document.getElementById('derival_variant').value = val;
                document.getElementById('derivalTerminalBlock').style.display = val === 'terminal' ? '' : 'none';
                document.getElementById('derivalAddressBlock').style.display = val === 'address' ? '' : 'none';
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
<title>{$t}</title>
<link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600&family=Instrument+Serif:ital@0;1&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
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
.page-col{display:flex;flex-direction:column;gap:0}
.page-col .card:last-child{flex:1}
@media(max-width:900px){.page-grid{grid-template-columns:1fr}}
 
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
.field input:not([type="checkbox"]),.field select{width:100%;padding:9px 12px;background:var(--s2);border:1px solid var(--line);border-radius:var(--r2);font-size:13px;color:var(--ink);font-family:var(--sans);transition:border .15s,box-shadow .15s,background .15s;-webkit-appearance:none;outline:none}
.field input:focus,.field select:focus{border-color:var(--amber);box-shadow:0 0 0 3px var(--ambl);background:var(--s1)}
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
.opf-search-wrap{background:var(--s2);border:1px solid var(--line);border-radius:var(--r2);padding:10px 12px}
.opf-search-input{width:100%;padding:7px 10px;background:var(--s1);border:1px solid var(--line);border-radius:var(--r2);font-size:12px;color:var(--ink);outline:none;transition:border .15s,box-shadow .15s}
.opf-search-input:focus{border-color:var(--amber);box-shadow:0 0 0 3px var(--ambl)}
.opf-list{margin-top:6px;background:var(--s1);border:1px solid var(--line);border-radius:var(--r2);overflow:hidden;display:none;max-height:180px;overflow-y:auto}
.opf-item{padding:8px 12px;font-size:12px;color:var(--ink2);border-bottom:1px solid var(--s3);cursor:pointer;transition:background .12s}
.opf-item:last-child{border-bottom:0}
.opf-item:hover{background:var(--ambl);color:var(--amber)}
.opf-item-sub{font-size:11px;color:var(--ink3);margin-top:1px}
.field input.field-err{border-color:#ef4444;box-shadow:0 0 0 3px #fef2f2;background:var(--s1)}
.field-err-msg{font-size:11px;color:#b91c1c;margin-top:4px;display:none}
</style>
</head>
<body>
HTML;
    }

    private static function renderError(string $message): void
    {
        self::renderHtmlHead('Ошибка — Деловые Линии');
        echo '<div style="padding:40px;max-width:480px;margin:auto">';
        echo '<div class="alert-err">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>';
        echo '</div></body></html>';
    }
}
