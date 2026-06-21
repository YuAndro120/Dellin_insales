<?php

declare(strict_types=1);

namespace ShippingBridge\InSales;

use ShippingBridge\Config;
use ShippingBridge\Db;

/**
 * Приём заявок на ранний доступ к тарифу "Автоматизация" с лендинга.
 */
final class EarlyAccessHandler
{
    public static function handle(Config $config, string $method): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
            return;
        }

        $email = trim((string) ($_POST['email'] ?? ''));
        $insalesId = trim((string) ($_POST['insales_id'] ?? '')) ?: null;

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Укажите корректный email']);
            return;
        }

        try {
            $pdo = Db::pdo($config);
            $stmt = $pdo->prepare('INSERT INTO early_access_leads (email, insales_id) VALUES (:email, :iid)');
            $stmt->execute([':email' => $email, ':iid' => $insalesId]);

            \ShippingBridge\Logger::info($insalesId ?? '-', null, 'early_access.lead', ['email' => \ShippingBridge\Logger::maskEmail($email)]);

            echo json_encode(['ok' => true]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Не удалось сохранить заявку']);
        }
    }
}
