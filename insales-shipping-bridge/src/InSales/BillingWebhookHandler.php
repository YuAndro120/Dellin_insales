<?php

declare(strict_types=1);

namespace ShippingBridge\InSales;

use ShippingBridge\Config;
use ShippingBridge\Db;
use ShippingBridge\Logger;
use ShippingBridge\SubscriptionRepository;
use ShippingBridge\TbankAcquiring;

/**
 * Обработчик уведомлений от Т-Банка об изменении статуса платежа
 * (интернет-эквайринг). URL регистрируется в личном кабинете терминала
 * как NotificationURL: https://ваш-домен/insales/billing/webhook
 *
 * Банк ожидает ответ ТЕЛОМ "OK" (заглавными буквами) и HTTP 200,
 * иначе будет повторять отправку: раз в час 24 часа, затем раз в сутки месяц.
 */
final class BillingWebhookHandler
{
    public static function handle(Config $config): void
    {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw === '') {
            http_response_code(400);
            echo 'EMPTY';
            return;
        }

        $notification = json_decode($raw, true);
        if (!is_array($notification)) {
            http_response_code(400);
            echo 'INVALID JSON';
            return;
        }

        if ($config->tbankTerminalKey === null || $config->tbankTerminalPassword === null) {
            Logger::error('-', null, 'billing.webhook.misconfigured', []);
            http_response_code(500);
            echo 'NOT CONFIGURED';
            return;
        }

        $acquiring = new TbankAcquiring($config->tbankTerminalKey, $config->tbankTerminalPassword);

        if (!$acquiring->verifyNotificationToken($notification)) {
            Logger::error('-', null, 'billing.webhook.invalid_token', [
                'order_id' => (string) ($notification['OrderId'] ?? ''),
            ]);
            http_response_code(403);
            echo 'INVALID TOKEN';
            return;
        }

        $orderId = (string) ($notification['OrderId'] ?? '');
        $status = (string) ($notification['Status'] ?? '');
        $paymentId = (string) ($notification['PaymentId'] ?? '');
        $rebillId = (string) ($notification['RebillId'] ?? '');
        $success = (bool) ($notification['Success'] ?? false);

        // OrderId у нас формируется как "{insales_id}-{plan}-{timestamp}",
        // см. BillingPage::buildOrderId().
        [$insalesId, $plan] = self::parseOrderId($orderId);

        if ($insalesId === '') {
            Logger::error('-', null, 'billing.webhook.unparseable_order_id', ['order_id' => $orderId]);
            http_response_code(200);
            echo 'OK'; // подтверждаем приём, чтобы банк не ретраил бессмысленно
            return;
        }

        $pdo = Db::pdo($config);
        $subscriptions = new SubscriptionRepository($pdo);

        if ($status === 'CONFIRMED' && $success) {
            $periodEnd = (new \DateTimeImmutable())->modify('+30 days');
            $subscriptions->activateAfterPayment(
                $insalesId,
                $plan,
                'card',
                $periodEnd,
                $rebillId !== '' ? $rebillId : null,
            );

            $subscriptions->recordPayment(
                $insalesId,
                $plan,
                ((float) ($notification['Amount'] ?? 0)) / 100,
                'card',
                'succeeded',
                $paymentId,
                $orderId,
            );

            Logger::info($insalesId, null, 'billing.payment.succeeded', [
                'plan' => $plan,
                'payment_id' => $paymentId,
                'period_end' => $periodEnd->format('Y-m-d'),
            ]);
        } elseif (in_array($status, ['REJECTED', 'CANCELED', 'DEADLINE_EXPIRED'], true)) {
            $subscriptions->recordPayment(
                $insalesId,
                $plan,
                ((float) ($notification['Amount'] ?? 0)) / 100,
                'card',
                'failed',
                $paymentId,
                $orderId,
            );

            Logger::error($insalesId, null, 'billing.payment.failed', [
                'plan' => $plan,
                'payment_id' => $paymentId,
                'status' => $status,
            ]);
        }

        // Для остальных промежуточных статусов (NEW, FORM_SHOWED, AUTHORIZING и т.д.)
        // просто подтверждаем приём без действий.

        http_response_code(200);
        echo 'OK';
    }

    /** @return array{0:string,1:string} [insalesId, plan] */
    private static function parseOrderId(string $orderId): array
    {
        $parts = explode('-', $orderId);
        if (count($parts) < 2) {
            return ['', SubscriptionRepository::PLAN_FULL];
        }
        $insalesId = $parts[0];
        $plan = $parts[1];
        if (!in_array($plan, [SubscriptionRepository::PLAN_CALC_ONLY, SubscriptionRepository::PLAN_FULL, SubscriptionRepository::PLAN_AUTOMATION], true)) {
            $plan = SubscriptionRepository::PLAN_FULL;
        }
        return [$insalesId, $plan];
    }
}
