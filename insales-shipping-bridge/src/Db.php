<?php

declare(strict_types=1);

namespace ShippingBridge;

use PDO;

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(Config $config): PDO
    {
        if ($config->databaseDsn === null || $config->databaseDsn === '') {
            throw new \RuntimeException('Database is not configured (set DATABASE_URL or MYSQL_* in .env).');
        }
        if (self::$pdo === null) {
            self::$pdo = new PDO(
                $config->databaseDsn,
                $config->databaseUser ?: null,
                $config->databasePassword ?: null,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
        }
        return self::$pdo;
    }

    /** Сброс соединения (тесты). */
    public static function reset(): void
    {
        self::$pdo = null;
    }
}
