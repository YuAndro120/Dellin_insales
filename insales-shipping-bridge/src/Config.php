<?php

declare(strict_types=1);

namespace ShippingBridge;

final class Config
{
    public function __construct(
        public readonly string $appkey,
        public readonly string $login,
        public readonly string $password,
        public readonly string $senderRequesterEmail,
        public readonly ?string $senderCounteragentUid,
        public readonly string $bridgeSecret,
        public readonly string $corsOrigin,
        public readonly string $cacheDir,
        public readonly ?string $databaseDsn,
        public readonly ?string $databaseUser,
        public readonly ?string $databasePassword,
        public readonly ?string $insalesAppId,
        public readonly ?string $insalesAppSecret,
    ) {
    }

    /** Полная конфигурация для API расчёта и справочников. */
    public static function fromEnv(): self
    {
        self::bootstrapDotEnv();

        [$dsn, $dbUser, $dbPass] = self::databaseFromEnv();

        return new self(
            appkey: self::req('SHIPPING_API_APPKEY'),
            login: self::req('SHIPPING_API_LOGIN'),
            password: self::req('SHIPPING_API_PASSWORD'),
            senderRequesterEmail: self::req('SHIPPING_REQUESTER_EMAIL'),
            senderCounteragentUid: self::opt('SHIPPING_COUNTERAGENT_UID'),
            bridgeSecret: self::opt('BRIDGE_SECRET') ?? '',
            corsOrigin: self::opt('CORS_ORIGIN') ?? '*',
            cacheDir: self::opt('CACHE_DIR') ?? dirname(__DIR__) . '/var/cache',
            databaseDsn: $dsn,
            databaseUser: $dbUser,
            databasePassword: $dbPass,
            insalesAppId: self::opt('INSALES_APP_ID'),
            insalesAppSecret: self::opt('INSALES_APP_SECRET'),
        );
    }

    /**
     * Минимальная конфигурация для /insales/* (установка приложения).
     * Ключи API перевозчика не обязательны — на установке не используются.
     */
    public static function fromEnvForInsales(): self
    {
        self::bootstrapDotEnv();

        [$dsn, $dbUser, $dbPass] = self::databaseFromEnv();
        if ($dsn === null || $dsn === '') {
            throw new \RuntimeException('Database is not configured (MYSQL_* or DATABASE_URL).');
        }

        return new self(
            appkey: self::opt('SHIPPING_API_APPKEY') ?? '',
            login: self::opt('SHIPPING_API_LOGIN') ?? '',
            password: self::opt('SHIPPING_API_PASSWORD') ?? '',
            senderRequesterEmail: self::opt('SHIPPING_REQUESTER_EMAIL') ?? 'noreply@localhost',
            senderCounteragentUid: self::opt('SHIPPING_COUNTERAGENT_UID'),
            bridgeSecret: self::opt('BRIDGE_SECRET') ?? '',
            corsOrigin: self::opt('CORS_ORIGIN') ?? '*',
            cacheDir: self::opt('CACHE_DIR') ?? dirname(__DIR__) . '/var/cache',
            databaseDsn: $dsn,
            databaseUser: $dbUser,
            databasePassword: $dbPass,
            insalesAppId: self::opt('INSALES_APP_ID'),
            insalesAppSecret: self::opt('INSALES_APP_SECRET'),
        );
    }

    public function hasDatabase(): bool
    {
        return $this->databaseDsn !== null && $this->databaseDsn !== '';
    }

    /** @return array{0:?string,1:?string,2:?string} */
    private static function databaseFromEnv(): array
    {
        $url = getenv('DATABASE_URL');
        if (is_string($url) && $url !== '') {
            return [$url, getenv('DATABASE_USER') ?: null, getenv('DATABASE_PASSWORD') ?: null];
        }
        $db = getenv('MYSQL_DATABASE');
        if (!is_string($db) || $db === '') {
            return [null, null, null];
        }
        $host = getenv('MYSQL_HOST') ?: '127.0.0.1';
        $port = getenv('MYSQL_PORT') ?: '3306';
        $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $db . ';charset=utf8mb4';
        $user = getenv('MYSQL_USER') ?: null;
        $pass = getenv('MYSQL_PASSWORD') ?: null;

        return [$dsn, $user, $pass];
    }

    private static function req(string $key): string
    {
        $v = self::opt($key);
        if ($v === null) {
            throw new \RuntimeException("Missing required environment variable: {$key}");
        }
        return $v;
    }

    private static function opt(string $key): ?string
    {
        $v = getenv($key);
        if ($v === false || $v === '') {
            return null;
        }
        return $v;
    }

    private static function bootstrapDotEnv(): void
    {
        static $loaded = false;
        if ($loaded) {
            return;
        }
        $envFile = dirname(__DIR__) . '/.env';
        if (is_file($envFile)) {
            self::loadDotEnv($envFile);
        }
        $loaded = true;
    }

    private static function loadDotEnv(string $path): void
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v, " \t\"'");
            if ($k !== '' && getenv($k) === false) {
                putenv("{$k}={$v}");
                $_ENV[$k] = $v;
            }
        }
    }
}
