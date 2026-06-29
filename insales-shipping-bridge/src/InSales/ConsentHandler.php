<?php

declare(strict_types=1);

namespace ShippingBridge\InSales;

use ShippingBridge\Config;
use ShippingBridge\Db;

/**
 * Сохранение согласий на обработку персональных данных и куки.
 * POST /insales/consent
 */
final class ConsentHandler
{
    public static function handle(Config $config, string $method): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
            return;
        }

        $source     = trim((string) ($_POST['source']     ?? 'landing'));
        $email      = trim((string) ($_POST['email']      ?? '')) ?: null;
        $insalesId  = trim((string) ($_POST['insales_id'] ?? '')) ?: null;
        $consentPd  = !empty($_POST['consent_pd'])      ? 1 : 0;
        $consentCk  = !empty($_POST['consent_cookies']) ? 1 : 0;

        if (!$consentPd) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Необходимо согласие на обработку персональных данных']);
            return;
        }

        $ip        = self::clientIp();
        $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);

        try {
            $pdo = Db::pdo($config);
            $stmt = $pdo->prepare(
                'INSERT INTO consents (insales_id, email, ip, user_agent, consent_pd, consent_cookies, source)
                 VALUES (:insales_id, :email, :ip, :ua, :pd, :ck, :src)'
            );
            $stmt->execute([
                ':insales_id' => $insalesId,
                ':email'      => $email,
                ':ip'         => $ip,
                ':ua'         => $userAgent,
                ':pd'         => $consentPd,
                ':ck'         => $consentCk,
                ':src'        => in_array($source, ['landing', 'app'], true) ? $source : 'landing',
            ]);

            // Для приложения — ставим cookie на 1 год
            if ($source === 'app') {
                setcookie('consent_given', '1', [
                    'expires'  => time() + 365 * 24 * 3600,
                    'path'     => '/',
                    'secure'   => true,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }

            \ShippingBridge\Logger::info($insalesId ?? '-', null, 'consent.saved', [
                'source' => $source,
                'ip'     => $ip,
                'pd'     => $consentPd,
                'ck'     => $consentCk,
            ]);

            echo json_encode(['ok' => true]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    private static function clientIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            $val = $_SERVER[$key] ?? '';
            if ($val !== '') {
                return trim(explode(',', $val)[0]);
            }
        }
        return '0.0.0.0';
    }
}
