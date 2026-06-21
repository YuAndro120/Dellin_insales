<?php

declare(strict_types=1);

namespace ShippingBridge\InSales;

use ShippingBridge\Config;
use ShippingBridge\Db;
use ShippingBridge\InvoiceRepository;
use ShippingBridge\ShopRepository;
use ShippingBridge\SubscriptionRepository;
use ShippingBridge\TbankInvoicing;

/**
 * Выставление счёта юрлицу за подписку (альтернатива оплате картой через
 * TbankAcquiring/BillingPage). POST-эндпоинт — принимает данные плательщика
 * и тариф, создаёт счёт через T-API, возвращает ссылку на PDF счёта.
 */
final class InvoicingPage
{
    /** Цены тарифов в рублях — те же значения, что в BillingPage. */
    private const PLAN_PRICES = [
        SubscriptionRepository::PLAN_CALC_ONLY => 999,
        SubscriptionRepository::PLAN_FULL => 1999,
        SubscriptionRepository::PLAN_AUTOMATION => 4999,
    ];

    private const PLAN_LABELS = [
        SubscriptionRepository::PLAN_CALC_ONLY => 'Калькулятор',
        SubscriptionRepository::PLAN_FULL => 'Полный',
        SubscriptionRepository::PLAN_AUTOMATION => 'Автоматизация',
    ];

    public static function handle(Config $config, ShopRepository $shops, string $method): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
            return;
        }

        $insalesId = trim((string) ($_POST['insales_id'] ?? ''));
        $shopHost = trim((string) ($_POST['shop'] ?? ''));
        $plan = trim((string) ($_POST['plan'] ?? ''));
        $payerName = trim((string) ($_POST['payer_name'] ?? ''));
        $payerInn = trim((string) ($_POST['payer_inn'] ?? ''));
        $payerKpp = trim((string) ($_POST['payer_kpp'] ?? '')) ?: null;
        $email = trim((string) ($_POST['email'] ?? ''));

        if ($insalesId === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Не указан insales_id']);
            return;
        }

        if (!isset(self::PLAN_PRICES[$plan]) || $plan === SubscriptionRepository::PLAN_AUTOMATION) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Недоступный тариф']);
            return;
        }

        if ($payerName === '' || !preg_match('/^(\d{10}|\d{12})$/', $payerInn)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Укажите корректные название организации и ИНН (10 или 12 цифр)']);
            return;
        }

        $settings = $shops->findSettingsByInsalesId($insalesId, $config);
        if ($settings === null) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Магазин не найден']);
            return;
        }

        // Та же мягкая проверка токена доступа, что и у остальных защищённых страниц.
        $requiredAccessToken = $shops->findAccessToken($insalesId);
        if ($requiredAccessToken !== null) {
            $providedAccessToken = trim((string) ($_POST['atk'] ?? ''));
            if ($providedAccessToken === '' || !hash_equals($requiredAccessToken, $providedAccessToken)) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Доступ запрещён']);
                return;
            }
        }

        if ($config->tbankInvoicingToken === null || $config->tbankInvoicingToken === '') {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Выставление счетов временно недоступно. Обратитесь в поддержку.']);
            return;
        }

        $pdo = Db::pdo($config);
        $invoices = new InvoiceRepository($pdo);
        $invoicing = new TbankInvoicing($config->tbankInvoicingToken);

        $amount = self::PLAN_PRICES[$plan];
        $planLabel = self::PLAN_LABELS[$plan];
        $invoiceNumber = $invoices->generateInvoiceNumber();
        $dueDate = (new \DateTimeImmutable())->modify('+5 days');

        $invoiceRecordId = $invoices->create(
            $insalesId,
            $plan,
            (float) $amount,
            $invoiceNumber,
            $payerName,
            $payerInn,
            $payerKpp,
            $dueDate,
        );

        try {
            $contacts = [];
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $contacts[] = ['email' => $email];
            }

            $response = $invoicing->sendInvoice(
                $invoiceNumber,
                $dueDate,
                ['name' => $payerName, 'inn' => $payerInn, 'kpp' => $payerKpp],
                [[
                    'name' => 'Подписка «ДЛ Коннект» — тариф «' . $planLabel . '»',
                    'price' => (float) $amount,
                    'unit' => 'шт',
                    'vat' => 'None',
                    'amount' => 1,
                ]],
                $contacts,
                'Оплата доступа к сервису ДЛ Коннект на 30 дней, магазин ' . $shopHost,
            );

            $tbankInvoiceId = (string) ($response['invoiceId'] ?? $response['id'] ?? '');
            if ($tbankInvoiceId === '') {
                throw new \RuntimeException('Банк не вернул идентификатор счёта');
            }

            $invoices->markSent($invoiceRecordId, $tbankInvoiceId, $response);

            \ShippingBridge\Logger::info($insalesId, null, 'billing.invoice.sent', [
                'plan' => $plan,
                'amount' => $amount,
                'invoice_number' => $invoiceNumber,
                'tbank_invoice_id' => $tbankInvoiceId,
            ]);

            echo json_encode([
                'ok' => true,
                'invoice_id' => $tbankInvoiceId,
                'invoice_number' => $invoiceNumber,
                'due_date' => $dueDate->format('Y-m-d'),
                'amount' => $amount,
            ]);
        } catch (\Throwable $e) {
            $invoices->markFailed($invoiceRecordId, ['error' => $e->getMessage()]);

            \ShippingBridge\Logger::error($insalesId, null, 'billing.invoice.error', [
                'plan' => $plan,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);

            http_response_code(502);
            echo json_encode(['ok' => false, 'error' => 'Не удалось выставить счёт: ' . $e->getMessage()]);
        }
    }
}
