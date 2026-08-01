<?php

declare(strict_types=1);

namespace ShippingBridge\InSales;

use ShippingBridge\Config;
use ShippingBridge\Db;

/**
 * Приём заявок с форм лендинга. Обслуживает обе страницы продукта:
 *  - / (index.html)         — ДЛ Коннект для inSales, source=landing
 *  - /amocrm.html            — ДЛ Коннект для amoCRM (пресейл), source=landing_amocrm
 * ⚠️ Раньше source был захардкожен как 'landing' — с появлением второй
 * посадочной страницы это смешивало заявки двух разных продуктов в одну
 * кучу. Теперь source приходит из формы и валидируется по белому списку.
 *
 * Заменяет EarlyAccessHandler — тот вставлял inn/company_name/plan в
 * таблицу early_access_leads, у которой таких колонок не было; INSERT
 * падал на каждой заявке, и она молча терялась под общим catch(\Throwable).
 * Таблица landing_leads (миграция 017) заведена под реальные поля формы.
 * Обязательны только имя и телефон — остальное необязательно.
 */
final class LandingLeadHandler
{
    private const ALLOWED_SOURCES = ['landing', 'landing_amocrm'];

    public static function handle(Config $config, string $method): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
            return;
        }

        $name        = trim((string) ($_POST['name']         ?? ''));
        $phone       = trim((string) ($_POST['phone']        ?? ''));
        $companyName = trim((string) ($_POST['company_name'] ?? '')) ?: null;
        $message     = trim((string) ($_POST['message']      ?? '')) ?: null;
        $insalesId   = trim((string) ($_POST['insales_id']   ?? '')) ?: null;
        $source      = trim((string) ($_POST['source']       ?? ''));
        $source      = in_array($source, self::ALLOWED_SOURCES, true) ? $source : 'landing';

        if ($name === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Укажите имя']);
            return;
        }
        if ($phone === '' || mb_strlen(preg_replace('/\D/', '', $phone) ?? '') < 10) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Укажите корректный телефон']);
            return;
        }

        $lead = [
            'name' => $name, 'phone' => $phone,
            'company_name' => $companyName, 'message' => $message,
            'source' => $source,
        ];

        try {
            $pdo = Db::pdo($config);
            $stmt = $pdo->prepare(
                'INSERT INTO landing_leads (name, phone, company_name, message, insales_id, source)
                 VALUES (:name, :phone, :company, :message, :iid, :src)'
            );
            $stmt->execute([
                ':name' => $name, ':phone' => $phone, ':company' => $companyName,
                ':message' => $message, ':iid' => $insalesId, ':src' => $source,
            ]);
            $leadId = (int) $pdo->lastInsertId();

            $notified = LeadNotifier::notify($config, $lead);
            if ($notified) {
                $pdo->prepare('UPDATE landing_leads SET notified = 1 WHERE id = ?')->execute([$leadId]);
            }

            \ShippingBridge\Logger::info($insalesId ?? '-', null, 'landing.lead', [
                'lead_id' => $leadId,
                'source' => $source,
                'phone' => \ShippingBridge\Logger::maskPhone($phone),
                'notified' => $notified,
            ]);

            echo json_encode(['ok' => true]);
        } catch (\Throwable $e) {
            \ShippingBridge\Logger::error($insalesId ?? '-', null, 'landing.lead_failed', ['error' => $e->getMessage()]);
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Не удалось сохранить заявку']);
        }
    }
}