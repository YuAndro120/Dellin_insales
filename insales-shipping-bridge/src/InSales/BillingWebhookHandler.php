<?php

declare(strict_types=1);

namespace ShippingBridge\InSales;

use ShippingBridge\Config;
use ShippingBridge\Db;
use ShippingBridge\ShopRepository;
use ShippingBridge\SubscriptionRepository;
use ShippingBridge\TbankAcquiring;

/**
 * Страница выбора и оплаты тарифа внутри /insales/app.
 * Доступна по адресу /insales/billing?shop=...&insales_id=...&atk=...
 * (тот же токен доступа atk, что используется для /insales/app).
 */
final class BillingPage
{
    /** @var array<string,array{label:string,price:int,description:string}> */
    private const PLANS = [
        SubscriptionRepository::PLAN_CALC_ONLY => [
            'label' => 'Калькулятор',
            'price' => 999,
            'description' => 'Расчёт стоимости с учётом скидок Деловых Линий в корзине. Без отправки заявок из админки.',
        ],
        SubscriptionRepository::PLAN_FULL => [
            'label' => 'Полный',
            'price' => 1999,
            'description' => 'Расчёт стоимости и полное оформление заявок в Деловые Линии прямо из админки.',
        ],
        SubscriptionRepository::PLAN_AUTOMATION => [
            'label' => 'Автоматизация',
            'price' => 4999,
            'description' => 'Всё из тарифа «Полный», плюс автоматизация по стадиям заказа, отчёты по логистике, счета и платёжные ссылки.',
        ],
    ];

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

        if ($method === 'POST' && isset($_POST['select_plan'])) {
            self::handlePlanSelection($config, $subscriptions, $insalesId, $shopHost, (string) $_POST['select_plan']);
            return;
        }

        self::renderPlansPage($subscriptions, $insalesId, $shopHost, $requiredAccessToken ?? '');
    }

    private static function handlePlanSelection(
        Config $config,
        SubscriptionRepository $subscriptions,
        string $insalesId,
        string $shopHost,
        string $plan,
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
        $orderId = self::buildOrderId($insalesId, $plan);
        $amountKopecks = $planInfo['price'] * 100;

        $acquiring = new TbankAcquiring($config->tbankTerminalKey, $config->tbankTerminalPassword);

        try {
            $result = $acquiring->init(
                $orderId,
                $amountKopecks,
                'Подписка "' . $planInfo['label'] . '" — ' . $shopHost,
                [
                    // Рекуррентные платежи: сохраняем карту для последующих автосписаний.
                    'Recurrent' => 'Y',
                    'CustomerKey' => $insalesId,
                ],
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

    private static function buildOrderId(string $insalesId, string $plan): string
    {
        return $insalesId . '-' . $plan . '-' . time();
    }

    private static function renderPlansPage(
        SubscriptionRepository $subscriptions,
        string $insalesId,
        string $shopHost,
        string $accessToken,
    ): void {
        $h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $sub = $subscriptions->findByInsalesId($insalesId);
        $currentPlan = $sub['plan'] ?? null;
        $currentStatus = $sub['status'] ?? null;

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');

        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Тариф</title>';
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

        if ($currentStatus === 'trial' && isset($sub['trial_ends_at'])) {
            $daysLeft = max(0, (int) ceil((strtotime((string) $sub['trial_ends_at']) - time()) / 86400));
            echo '<div class="status-banner">Пробный период активен: осталось ' . $daysLeft . ' дн. (полный доступ на время триала)</div>';
        } elseif ($currentStatus === 'active') {
            echo '<div class="status-banner">Подписка активна до ' . $h(date('d.m.Y', strtotime((string) $sub['current_period_ends_at']))) . '</div>';
        } elseif ($currentStatus === 'past_due') {
            echo '<div class="status-banner" style="background:#fff3e0;border-color:#ffd9a8">Оплата просрочена — доступ ограничен. Выберите тариф ниже, чтобы продолжить.</div>';
        }

        echo '<div class="plans">';
        foreach (self::PLANS as $planKey => $info) {
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
        echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:40px"><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></body></html>';
    }
}
