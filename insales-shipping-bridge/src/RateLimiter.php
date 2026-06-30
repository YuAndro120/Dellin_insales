<?php

declare(strict_types=1);

namespace ShippingBridge;

/**
 * Простой rate limiter на основе таблицы admin_login_attempts.
 * Защищает auth-эндпоинты (PAT-логин, consent) от перебора и спама.
 */
final class RateLimiter
{
    /**
     * @return bool true если лимит превышен и запрос нужно отклонить
     */
    public static function isBlocked(Config $config, string $action, int $maxAttempts = 5, int $windowSeconds = 300): bool
    {
        $ip = self::clientIp();
        $pdo = Db::pdo($config);

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM admin_login_attempts
             WHERE ip_address = :ip AND success = 0 AND attempted_at > (NOW() - INTERVAL :window SECOND)'
        );
        $stmt->bindValue(':ip', $ip . '|' . $action);
        $stmt->bindValue(':window', $windowSeconds, \PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn() >= $maxAttempts;
    }

    public static function recordAttempt(Config $config, string $action, bool $success): void
    {
        $ip = self::clientIp();
        $pdo = Db::pdo($config);

        $stmt = $pdo->prepare(
            'INSERT INTO admin_login_attempts (ip_address, success) VALUES (:ip, :success)'
        );
        $stmt->execute([
            'ip'      => $ip . '|' . $action,
            'success' => $success ? 1 : 0,
        ]);
    }

    private static function clientIp(): string
    {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        // Берём первый IP из цепочки X-Forwarded-For
        if (str_contains($ip, ',')) {
            $ip = trim(explode(',', $ip)[0]);
        }
        return substr($ip, 0, 45);
    }
}
