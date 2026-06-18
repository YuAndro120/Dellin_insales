<?php

declare(strict_types=1);

namespace AdminPanel;

final class Config
{
    public function __construct(
        public readonly string $mysqlHost,
        public readonly int $mysqlPort,
        public readonly string $mysqlDatabase,
        public readonly string $mysqlUser,
        public readonly string $mysqlPassword,
        public readonly string $sessionSecret,
        public readonly string $bridgeLogDir,
        public readonly int $loginMaxAttempts,
    ) {
    }

    public static function fromEnv(): self
    {
        $envPath = dirname(__DIR__) . '/.env';
        if (is_file($envPath)) {
            self::loadEnvFile($envPath);
        }

        return new self(
            mysqlHost: (string) (getenv('MYSQL_HOST') ?: '127.0.0.1'),
            mysqlPort: (int) (getenv('MYSQL_PORT') ?: 3306),
            mysqlDatabase: (string) (getenv('MYSQL_DATABASE') ?: ''),
            mysqlUser: (string) (getenv('MYSQL_USER') ?: ''),
            mysqlPassword: (string) (getenv('MYSQL_PASSWORD') ?: ''),
            sessionSecret: (string) (getenv('ADMIN_SESSION_SECRET') ?: ''),
            bridgeLogDir: (string) (getenv('BRIDGE_LOG_DIR') ?: '/var/log/bridge'),
            loginMaxAttempts: (int) (getenv('LOGIN_MAX_ATTEMPTS') ?: 5),
        );
    }

    private static function loadEnvFile(string $path): void
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            $value = trim($value, '"\'');
            if ($key !== '' && getenv($key) === false) {
                putenv("{$key}={$value}");
            }
        }
    }
}
