<?php

declare(strict_types=1);

namespace ShippingBridge\InSales;

use ShippingBridge\Config;
use ShippingBridge\Db;
use ShippingBridge\Logger;
use ShippingBridge\Plans;
use ShippingBridge\ShopRepository;
use ShippingBridge\SubscriptionRepository;
use ShippingBridge\TbankAcquiring; // оставлено для возможного отката, см. handlePlanSelection()
use ShippingBridge\InSales\InSalesRecurringBilling;

/**
 * Страница выбора и оплаты тарифа внутри /insales/app.
 * Доступна по адресу /insales/billing?shop=...&insales_id=...&atk=...
 * (тот же токен доступа atk, что используется для /insales/app).
 *
 * С 2026-07 основной биллинг — ApplicationCharge inSales (разовый счёт,
 * см. InSalesRecurringBilling). Т-Банк эквайринг ЗАКОММЕНТИРОВАН, но не
 * удалён — на случай отката смотрите handlePlanSelectionViaTbank() ниже
 * и BillingWebhookHandler.php (там тоже закомментирован приём уведомлений).
 *
 * Названия/цены/описания тарифов — из ShippingBridge\Plans (единый источник,
 * синхронизированный с лендингом public/index.html). Раньше здесь была своя
 * копия этих данных с другими цифрами — см. историю правок.
 */
final class BillingPage
{
    // Тарифы (label/price/description) — см. ShippingBridge\Plans::ALL.

    public static function handle(Config $config, ShopRepository $shops, string $method): void
    {
        $shopHost = trim((string) ($_GET['shop'] ?? $_POST['shop'] ?? ''));
        $insalesId = trim((string) ($_GET['insales_id'] ?? $_POST['insales_id'] ?? ''));

        if ($insalesId === '') {
            http_response_code(400);
            self::renderError('Не указан insales_id.');
            return;
        }

        $settings = $shops->findSettingsByInsalesId($insalesId, $config);
        if ($settings === null) {
            http_response_code(404);
            self::renderError('Магазин не найден.');
            return;
        }

        $requiredAccessToken = $shops->findAccessToken($insalesId);
        if ($requiredAccessToken !== null) {
            $providedAccessToken = trim((string) ($_GET['atk'] ?? $_POST['atk'] ?? ''));
            if ($providedAccessToken === '' || !hash_equals($requiredAccessToken, $providedAccessToken)) {
                http_response_code(403);
                self::renderError('Доступ запрещён.');
                return;
            }
        }

        $pdo = Db::pdo($config);
        $subscriptions = new SubscriptionRepository($pdo);

        $accessToken = trim((string) ($_GET['atk'] ?? $_POST['atk'] ?? ''));

        // Возврат мерчанта с confirmation_url ApplicationCharge — проверяем
        // реальный статус счёта через API, а не доверяем самому факту
        // редиректа (в URL нет подписи/подтверждения).
        if ($method === 'GET' && isset($_GET['check_charge']) && isset($_GET['plan'])) {
            self::handleChargeReturn($config, $subscriptions, $insalesId, $shopHost, $accessToken, (string) $_GET['plan']);
            return;
        }

        if ($method === 'POST' && isset($_POST['select_plan'])) {
            $wantsRecurrent = isset($_POST['recurrent']) && $_POST['recurrent'] === '1';
            $period = trim((string) ($_POST['period'] ?? 'month'));
            self::handlePlanSelection($config, $subscriptions, $insalesId, $shopHost, (string) $_POST['select_plan'], $wantsRecurrent, $period);
            return;
        }

        // GET — показываем карточки тарифов прямо в приложении.
        // Раньше здесь был редирект на внешний лендинг (receptly.ru) — это
        // уводило пользователя из inSales вместо показа страницы оплаты,
        // а после успешной оплаты через inSales (redirect на этот же URL
        // с ?paid=1) редирект на лендинг просто терял баннер об успехе.
        self::renderPlansPage($subscriptions, $insalesId, $shopHost, $accessToken);
    }

    /**
     * АКТИВНЫЙ путь: биллинг через ApplicationCharge inSales (разовый счёт).
     *
     * Это НЕ автосписание — каждый период мерчант заново нажимает «Выбрать
     * и оплатить» и подтверждает счёт на стороне inSales. Раньше пробовали
     * RecurringApplicationCharge (см. историю в InSalesRecurringBilling) —
     * тот эндпоинт отвечал 200, но не создавал видимого мерчанту счёта и
     * не запускал реальную оплату, поэтому от него отказались.
     *
     * Ограничение: ApplicationCharge — это просто сумма без понятия
     * "период", поэтому годовой тариф со скидкой (period=year) через этот
     * механизм не считаем отдельно — используем цену тарифа как есть.
     * Если нужно вернуть годовые тарифы со скидкой, это должно уйти на
     * handlePlanSelectionViaTbank() (см. ниже, закомментировано).
     */
    private static function handlePlanSelection(
        Config $config,
        SubscriptionRepository $subscriptions,
        string $insalesId,
        string $shopHost,
        string $plan,
        bool $wantsRecurrent, // не используется — ApplicationCharge не поддерживает автопродление
        string $period = 'month',
    ): void {
        if (!isset(Plans::ALL[$plan])) {
            http_response_code(400);
            self::renderError('Неизвестный тариф.');
            return;
        }

        $planInfo = Plans::ALL[$plan];
        $accessToken = trim((string) ($_POST['atk'] ?? ''));

        $shops = new ShopRepository(Db::pdo($config));
        $shopCreds = $shops->findApiAuthByInsalesId($insalesId); // ['insales_id','shop_host','api_password']
        if ($shopCreds === null || $config->insalesAppId === null) {
            http_response_code(500);
            self::renderError('Не удалось получить учётные данные приложения для этого магазина.');
            return;
        }

        $billing = new InSalesRecurringBilling(new \ShippingBridge\InSales\InSalesClient());

        // После подтверждения/отклонения счёта inSales вернёт мерчанта
        // именно на этот URL — по нему мы поймём, какой план проверять.
        $returnUrl = rtrim($config->publicBridgeUrl, '/') . '/insales/billing?' . http_build_query([
            'shop' => $shopHost,
            'insales_id' => $insalesId,
            'atk' => $accessToken,
            'plan' => $plan,
            'check_charge' => '1',
        ]);

        try {
            $charge = $billing->createOneTimeCharge(
                $shopHost,
                $config->insalesAppId,
                $shopCreds['api_password'],
                'Подписка «' . $planInfo['label'] . '» — ' . $shopHost,
                (float) $planInfo['price'],
                $returnUrl,
                false, // test=true — только вручную при отладке на тестовом магазине
            );
        } catch (\Throwable $e) {
            http_response_code(502);
            self::renderError('Не удалось выставить счёт в inSales: ' . $e->getMessage());
            return;
        }

        if ($charge['confirmation_url'] === '') {
            http_response_code(502);
            self::renderError('inSales не вернул ссылку на подтверждение счёта. Попробуйте позже.');
            return;
        }

        Logger::info($insalesId, null, 'billing.insales.charge_created', [
            'plan' => $plan,
            'charge_id' => $charge['id'],
            'status' => $charge['status'],
        ]);

        header('Location: ' . $charge['confirmation_url'], true, 302);
    }

    /**
     * Возврат мерчанта с confirmation_url ApplicationCharge. Не доверяем
     * параметрам в URL (их теоретически можно подделать) — сверяем реальный
     * статус счёта прямым server-to-server запросом к inSales.
     */
    private static function handleChargeReturn(
        Config $config,
        SubscriptionRepository $subscriptions,
        string $insalesId,
        string $shopHost,
        string $accessToken,
        string $plan,
    ): void {
        if (!isset(Plans::ALL[$plan])) {
            self::renderPlansPage($subscriptions, $insalesId, $shopHost, $accessToken);
            return;
        }

        $shops = new ShopRepository(Db::pdo($config));
        $shopCreds = $shops->findApiAuthByInsalesId($insalesId);
        if ($shopCreds === null || $config->insalesAppId === null) {
            self::renderPlansPage($subscriptions, $insalesId, $shopHost, $accessToken);
            return;
        }

        $billing = new InSalesRecurringBilling(new \ShippingBridge\InSales\InSalesClient());

        try {
            $charges = $billing->listOneTimeCharges($shopHost, $config->insalesAppId, $shopCreds['api_password']);
        } catch (\Throwable $e) {
            Logger::error($insalesId, null, 'billing.insales.charge_check_failed', ['error' => $e->getMessage()]);
            self::renderPlansPage($subscriptions, $insalesId, $shopHost, $accessToken, 'pending');
            return;
        }

        // Счета этого магазина уже отскоуплены Basic-авторизацией — чужие
        // сюда не попадут. Берём тот, что создан последним (наибольший id).
        $latest = null;
        foreach ($charges as $c) {
            if ($latest === null || $c['id'] > $latest['id']) {
                $latest = $c;
            }
        }

        Logger::info($insalesId, null, 'billing.insales.charge_checked', [
            'plan' => $plan,
            'charge' => $latest,
        ]);

        if ($latest !== null && $billing->isPaid($latest)) {
            $periodEnd = (new \DateTimeImmutable())->modify('+30 days');
            $subscriptions->activateAfterPayment($insalesId, $plan, 'insales', $periodEnd);
            $subscriptions->recordPayment(
                $insalesId,
                $plan,
                (float) $latest['price'],
                'insales',
                'succeeded',
                (string) $latest['id'], // используем это поле как ссылку на charge_id inSales
            );
            self::renderPlansPage($subscriptions, $insalesId, $shopHost, $accessToken, '1');
            return;
        }

        if ($latest !== null && $latest['status'] === 'declined') {
            self::renderPlansPage($subscriptions, $insalesId, $shopHost, $accessToken, '0');
            return;
        }

        // Счёт ещё pending (мерчант закрыл окно, не дойдя до конца) —
        // не считаем ни оплаченным, ни отклонённым.
        self::renderPlansPage($subscriptions, $insalesId, $shopHost, $accessToken, 'pending');
    }

    /*
     * ============================================================
     * РЕЗЕРВНЫЙ путь: биллинг через Т-Банк эквайринг.
     * ЗАКОММЕНТИРОВАНО в пользу нативного биллинга inSales (см. выше).
     * Ничего не удалено — чтобы откатиться, переименуйте этот метод
     * обратно в handlePlanSelection() и закомментируйте новый.
     * ============================================================
     *
    private static function handlePlanSelectionViaTbank(
        Config $config,
        SubscriptionRepository $subscriptions,
        string $insalesId,
        string $shopHost,
        string $plan,
        bool $wantsRecurrent,
        string $period = 'month',
    ): void {
        if (!isset(self::PLANS[$plan])) {
            http_response_code(400);
            self::renderError('Неизвестный тариф.');
            return;
        }

        if ($config->tbankTerminalKey === null || $config->tbankTerminalPassword === null) {
            http_response_code(500);
            self::renderError('Приём платежей временно недоступен. Обратитесь в поддержку.');
            return;
        }

        $planInfo = self::PLANS[$plan];
        $orderId  = self::buildOrderId($insalesId, $plan, $period);

        $monthPrice = $planInfo['price'];
        $amountKopecks = $period === 'year'
            ? (int) round($monthPrice * 12 * 0.80) * 100
            : $monthPrice * 100;

        $acquiring = new TbankAcquiring($config->tbankTerminalKey, $config->tbankTerminalPassword);

        $accessToken = trim((string) ($_POST['atk'] ?? ''));
        $returnQuery = http_build_query(['shop' => $shopHost, 'insales_id' => $insalesId, 'atk' => $accessToken]);
        $publicUrl = rtrim($config->publicBridgeUrl, '/');

        $initExtra = [
            'SuccessURL' => $publicUrl . '/checkout?plan=' . urlencode($plan) . '&' . $returnQuery . '&paid=1',
            'FailURL'    => $publicUrl . '/checkout?plan=' . urlencode($plan) . '&' . $returnQuery . '&paid=0',
        ];
        if ($wantsRecurrent) {
            // Плательщик добровольно согласился на автопродление (чекбокс на форме) —
            // сохраняем карту для последующих автосписаний по RebillId.
            $initExtra['Recurrent'] = 'Y';
            $initExtra['CustomerKey'] = $insalesId;
        }

        try {
            $result = $acquiring->init(
                $orderId,
                $amountKopecks,
                'Подписка "' . $planInfo['label'] . '" — ' . $shopHost,
                $initExtra,
            );
        } catch (\Throwable $e) {
            http_response_code(502);
            self::renderError('Не удалось создать платёж: ' . $e->getMessage());
            return;
        }

        if ($result['payment_url'] === '') {
            http_response_code(502);
            self::renderError('Банк не вернул ссылку на оплату. Попробуйте позже.');
            return;
        }

        header('Location: ' . $result['payment_url'], true, 302);
    }
    *
    * ============================================================
    */

    private static function buildOrderId(string $insalesId, string $plan, string $period = 'month'): string
    {
        return $insalesId . '-' . $plan . '-' . $period . '-' . time();
    }

    private static function renderPlansPage(
        SubscriptionRepository $subscriptions,
        string $insalesId,
        string $shopHost,
        string $accessToken,
        ?string $paidOverride = null,
    ): void {
        $h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $sub = $subscriptions->findByInsalesId($insalesId);
        $currentPlan = $sub['plan'] ?? null;
        $currentStatus = $sub['status'] ?? null;
        $paidParam = $paidOverride ?? ($_GET['paid'] ?? null);

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');

        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Тариф</title><link rel="icon" type="image/png" href="/images/logo.png"><link rel="apple-touch-icon" href="/images/logo.png">';
        echo '<style>
            body{font-family:-apple-system,sans-serif;background:#f7f7f8;margin:0;padding:32px 20px;color:#1a1a1a}
            .wrap{max-width:880px;margin:0 auto}
            h1{font-size:22px;margin-bottom:6px}
            .sub{color:#666;font-size:13px;margin-bottom:28px}
            .status-banner{background:#eef6ff;border:1px solid #cfe4ff;border-radius:10px;padding:12px 16px;margin-bottom:24px;font-size:13px}
            .plans{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
            .plan-card{background:#fff;border:1px solid #e5e5e5;border-radius:14px;padding:20px;display:flex;flex-direction:column}
            .plan-card.current{border-color:#1a1a1a;box-shadow:0 0 0 1px #1a1a1a}
            .plan-label{font-size:15px;font-weight:600;margin-bottom:4px}
            .plan-price{font-size:26px;font-weight:700;margin-bottom:10px}
            .plan-price span{font-size:13px;font-weight:400;color:#888}
            .plan-desc{font-size:12.5px;color:#666;line-height:1.5;flex:1;margin-bottom:16px}
            .plan-btn{padding:9px;border-radius:8px;border:0;background:#1a1a1a;color:#fff;font-size:13px;font-weight:600;cursor:pointer;width:100%}
            .plan-btn.current-btn{background:#eee;color:#888;cursor:default}
            .badge-current{display:inline-block;background:#1a1a1a;color:#fff;font-size:10px;padding:2px 8px;border-radius:10px;margin-bottom:8px}
        </style></head><body><div class="wrap">';

        echo '<h1>Тариф</h1>';
        echo '<div class="sub">Магазин: ' . $h($shopHost) . '</div>';

        if ($paidParam === '1') {
            echo '<div class="status-banner" style="background:#e8f9ee;border-color:#b8e8c8">✓ Оплата прошла успешно. Тариф обновлён.</div>';
        } elseif ($paidParam === 'pending') {
            echo '<div class="status-banner" style="background:#fff3e0;border-color:#ffd9a8">Счёт в inSales выставлен, но мы не смогли автоматически подтвердить оплату. Проверьте статус счёта в личном кабинете inSales — тариф обновится, как только оплата будет подтверждена.</div>';
        } elseif ($paidParam === '0') {
            echo '<div class="status-banner" style="background:#fdeaea;border-color:#f4b8b8">Оплата не прошла. Попробуйте ещё раз или выберите другой способ.</div>';
        }
        if ($currentStatus === 'trial' && isset($sub['trial_ends_at'])) {
            $daysLeft = max(0, (int) ceil((strtotime((string) $sub['trial_ends_at']) - time()) / 86400));
            echo '<div class="status-banner">Пробный период активен: осталось ' . $daysLeft . ' дн. (полный доступ на время триала)</div>';
        } elseif ($currentStatus === 'active') {
            echo '<div class="status-banner">Подписка активна до ' . $h(date('d.m.Y', strtotime((string) $sub['current_period_ends_at']))) . '</div>';
        } elseif ($currentStatus === 'past_due') {
            echo '<div class="status-banner" style="background:#fff3e0;border-color:#ffd9a8">Оплата просрочена — доступ ограничен. Выберите тариф ниже, чтобы продолжить.</div>';
        }

        echo '<div class="plans">';
        foreach (Plans::ALL as $planKey => $info) {
            $isCurrent = $currentPlan === $planKey && $currentStatus === 'active';
            echo '<div class="plan-card' . ($isCurrent ? ' current' : '') . '">';
            if ($isCurrent) {
                echo '<span class="badge-current">Текущий тариф</span>';
            }
            echo '<div class="plan-label">' . $h($info['label']) . '</div>';
            echo '<div class="plan-price">' . $info['price'] . ' ₽ <span>/ мес</span></div>';
            echo '<div class="plan-desc">' . $h($info['description']) . '</div>';
            if ($isCurrent) {
                echo '<button class="plan-btn current-btn" disabled>Уже подключён</button>';
            } else {
                echo '<form method="post" action="/insales/billing">';
                echo '<input type="hidden" name="shop" value="' . $h($shopHost) . '">';
                echo '<input type="hidden" name="insales_id" value="' . $h($insalesId) . '">';
                echo '<input type="hidden" name="atk" value="' . $h($accessToken) . '">';
                echo '<input type="hidden" name="select_plan" value="' . $h($planKey) . '">';
                echo '<p style="font-size:11.5px;color:#666;margin:0 0 12px;line-height:1.4">Оплата на 30 дней. Продление — вручную, тем же способом, когда период закончится.</p>';
                echo '<button type="submit" class="plan-btn">Выбрать и оплатить</button>';
                echo '</form>';
            }
            echo '</div>';
        }
        echo '</div>';

        echo '<div style="margin-top:24px"><a href="/insales/app?shop=' . $h($shopHost) . '&insales_id=' . $h($insalesId) . '&atk=' . $h($accessToken) . '" style="color:#3d5afe;text-decoration:none;font-size:13px">← К настройкам</a></div>';

        echo '</div></body></html>';
    }

    private static function renderError(string $message): void
    {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Тариф</title><link rel="icon" type="image/png" href="/images/logo.png"></head><body style="font-family:sans-serif;padding:40px"><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></body></html>';
    }
}