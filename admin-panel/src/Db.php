<?php

declare(strict_types=1);

namespace AdminPanel;

final class Db
{
    private static ?\PDO $pdo = null;

    public static function pdo(Config $config): \PDO
    {
        if (self::$pdo instanceof \PDO) {
            return self::$pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config->mysqlHost,
            $config->mysqlPort,
            $config->mysqlDatabase,
        );

        self::$pdo = new \PDO($dsn, $config->mysqlUser, $config->mysqlPassword, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_TIMEOUT => 5,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return self::$pdo;
    }
}
