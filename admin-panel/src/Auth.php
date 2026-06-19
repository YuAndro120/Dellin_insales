<?php

declare(strict_types=1);

namespace AdminPanel;

final class Auth
{
    public static function check(): bool
    {
        self::ensureSession();
        return isset($_SESSION['admin_user_id']) && (int) $_SESSION['admin_user_id'] > 0;
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: /login');
            exit;
        }
    }

    public static function currentUserEmail(): ?string
    {
        self::ensureSession();
        return $_SESSION['admin_user_email'] ?? null;
    }

    public static function attempt(Config $config, \PDO $pdo, string $email, string $password): bool
    {
        $ip = self::clientIp();

        if (self::isRateLimited($pdo, $ip, $config->loginMaxAttempts)) {
            return false;
        }

        $stmt = $pdo->prepare('SELECT id, email, password_hash FROM admin_users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        $ok = $user !== false && password_verify($password, (string) $user['password_hash']);

        self::recordAttempt($pdo, $ip, $ok);

        if (!$ok) {
            return false;
        }

        self::ensureSession();
        session_regenerate_id(true);
        $_SESSION['admin_user_id'] = (int) $user['id'];
        $_SESSION['admin_user_email'] = (string) $user['email'];
        session_write_close();
        session_start(); // переоткрываем, чтобы остальной код запроса видел $_SESSION как обычно

        $upd = $pdo->prepare('UPDATE admin_users SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id');
        $upd->execute([':id' => $user['id']]);

        return true;
    }

    public static function logout(): void
    {
        self::ensureSession();
        $_SESSION = [];
        if (session_id() !== '') {
            session_destroy();
        }
    }

    private static function isRateLimited(\PDO $pdo, string $ip, int $maxAttempts): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS cnt FROM admin_login_attempts
             WHERE ip_address = :ip AND success = 0 AND attempted_at > (NOW() - INTERVAL 15 MINUTE)'
        );
        $stmt->execute([':ip' => $ip]);
        $row = $stmt->fetch();
        return $row !== false && (int) $row['cnt'] >= $maxAttempts;
    }

    private static function recordAttempt(\PDO $pdo, string $ip, bool $success): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO admin_login_attempts (ip_address, success) VALUES (:ip, :success)'
        );
        $stmt->execute([':ip' => $ip, ':success' => $success ? 1 : 0]);
    }

    private static function clientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    private const SESSION_LIFETIME_SECONDS = 30 * 24 * 60 * 60; // 30 дней

    private static function ensureSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.gc_maxlifetime', (string) self::SESSION_LIFETIME_SECONDS);
            session_set_cookie_params([
                'lifetime' => self::SESSION_LIFETIME_SECONDS,
                'path' => '/',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
            session_start();
        }
    }
}
