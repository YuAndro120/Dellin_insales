<?php

declare(strict_types=1);

namespace ShippingBridge;

/**
 * Структурированное логирование в файлы по дням с маскированием
 * персональных данных (телефон/email получателя).
 *
 * Лог-файл: /var/log/bridge/YYYY-MM-DD.log
 * Формат строки: [HH:MM:SS] [LEVEL] shop=<insales_id> order=<id|-> event=<name> <key=value ...>
 *
 * Ошибки (error()) дополнительно пишутся в таблицу admin_alerts той же БД
 * для отображения в админ-панели. Если запись в БД не удалась — это не
 * должно ронять основной запрос пользователя (тихий catch).
 */
final class Logger
{
    private const LOG_DIR = '/var/log/bridge';

    public static function info(string $shopId, ?string $orderId, string $event, array $context = []): void
    {
        self::write('INFO', $shopId, $orderId, $event, $context);
    }

    public static function error(string $shopId, ?string $orderId, string $event, array $context = []): void
    {
        self::write('ERROR', $shopId, $orderId, $event, $context);
        self::recordAlert('error', $shopId, $orderId, $event, $context);
    }

    /**
     * Маскирует телефон, оставляя код оператора и последние 2 цифры.
     * +79131409995 → +7913*****95
     */
    public static function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        $len = strlen($digits);
        if ($len < 6) {
            return $len > 0 ? str_repeat('*', $len) : '';
        }
        $visibleStart = 4;
        $visibleEnd = 2;
        $maskedLen = $len - $visibleStart - $visibleEnd;
        if ($maskedLen <= 0) {
            return $digits;
        }
        return substr($digits, 0, $visibleStart) . str_repeat('*', $maskedLen) . substr($digits, -$visibleEnd);
    }

    /**
     * Маскирует email, оставляя первую букву логина и домен.
     * ivan.petrov@example.com → i***@example.com
     */
    public static function maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false || $at === 0) {
            return $email === '' ? '' : '***';
        }
        $local = substr($email, 0, $at);
        $domain = substr($email, $at);
        return $local[0] . '***' . $domain;
    }

    /**
     * Частично маскирует адрес — оставляет город, скрывает дом/квартиру.
     * Используется только если нужно логировать полный адрес объекта;
     * по умолчанию город/улицу можно оставлять как есть (не персональные
     * данные сами по себе), маскировка дома — опционально на месте вызова.
     */
    public static function maskHouseNumber(string $house): string
    {
        if ($house === '') {
            return '';
        }
        return str_repeat('*', max(1, strlen($house)));
    }

    private static function write(string $level, string $shopId, ?string $orderId, string $event, array $context): void
    {
        $line = self::formatLine($level, $shopId, $orderId, $event, $context);
        $file = self::LOG_DIR . '/' . date('Y-m-d') . '.log';

        // Не блокируем основной поток приложения при сбое логирования.
        try {
            file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // Логирование не должно ронять запрос пользователя.
        }
    }

    private static function recordAlert(string $level, string $shopId, ?string $orderId, string $event, array $context): void
    {
        try {
            $pdo = self::pdo();
            if ($pdo === null) {
                return;
            }
            $message = $event;
            if (isset($context['errors'])) {
                $errors = $context['errors'];
                $message = is_array($errors) ? json_encode($errors, JSON_UNESCAPED_UNICODE) : (string) $errors;
            } elseif (isset($context['error'])) {
                $message = (string) $context['error'];
            }

            $stmt = $pdo->prepare(
                'INSERT INTO admin_alerts (level, event, shop_id, order_id, message, context)
                 VALUES (:level, :event, :shop_id, :order_id, :message, :context)'
            );
            $stmt->execute([
                ':level' => $level,
                ':event' => $event,
                ':shop_id' => $shopId !== '' ? $shopId : null,
                ':order_id' => ($orderId !== null && $orderId !== '') ? $orderId : null,
                ':message' => mb_substr($message, 0, 2000),
                ':context' => json_encode($context, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable) {
            // Сбой записи алерта не должен ронять основной запрос.
        }
    }

    private static ?\PDO $alertPdo = null;
    private static bool $alertPdoFailed = false;

    private static function pdo(): ?\PDO
    {
        if (self::$alertPdo instanceof \PDO) {
            return self::$alertPdo;
        }
        if (self::$alertPdoFailed) {
            return null;
        }

        try {
            // Переиспользуем то же соединение/учётные данные, что и основное
            // приложение (Db::pdo требует Config, но конфиг уже инициализирован
            // глобально через getenv() к моменту вызова Logger).
            self::$alertPdo = Db::pdo(Config::fromEnvForInsales());
            return self::$alertPdo;
        } catch (\Throwable) {
            self::$alertPdoFailed = true;
            return null;
        }
    }

    private static function formatLine(string $level, string $shopId, ?string $orderId, string $event, array $context): string
    {
        $time = date('H:i:s');
        $orderPart = $orderId !== null && $orderId !== '' ? $orderId : '-';

        $parts = [];
        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            $parts[] = $key . '=' . self::escapeValue((string) $value);
        }
        $contextStr = implode(' ', $parts);

        return "[{$time}] [{$level}] shop={$shopId} order={$orderPart} event={$event}" . ($contextStr !== '' ? ' ' . $contextStr : '');
    }

    private static function escapeValue(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', $value);
        if (str_contains($value, ' ')) {
            return '"' . str_replace('"', '\\"', $value) . '"';
        }
        return $value;
    }
}
