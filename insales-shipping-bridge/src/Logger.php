<?php

declare(strict_types=1);

namespace ShippingBridge;

/**
 * Структурированное логирование в JSON Lines (.jsonl) файлы по дням,
 * с маскированием персональных данных (телефон/email получателя).
 *
 * Лог-файл: /var/log/bridge/YYYY-MM-DD.jsonl
 * Каждая строка — отдельный JSON-объект:
 * {"time":"HH:MM:SS","level":"INFO","shop":"...","order":"...","event":"...","context":{...}}
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

    public static function maskHouseNumber(string $house): string
    {
        if ($house === '') {
            return '';
        }
        return str_repeat('*', max(1, strlen($house)));
    }

    /**
     * Рекурсивно маскирует известные чувствительные ключи внутри тела
     * запроса/ответа перед логированием (для $context['body'] и подобных).
     * Используется когда логируется сырой массив от API ДЛ/inSales, в
     * котором могут встретиться ФИО, телефон, email на разных уровнях
     * вложенности.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public static function maskSensitiveFields(array $data): array
    {
        $phoneKeys = ['phone', 'phoneNumber', 'number'];
        $emailKeys = ['email'];
        $nameKeys = []; // ФИО намеренно не маскируем — нужно для диагностики "кому едет заказ"

        $result = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $result[$key] = self::maskSensitiveFields($value);
                continue;
            }
            if (is_string($value) && in_array($key, $phoneKeys, true)) {
                $result[$key] = self::maskPhone($value);
                continue;
            }
            if (is_string($value) && in_array($key, $emailKeys, true)) {
                $result[$key] = self::maskEmail($value);
                continue;
            }
            $result[$key] = $value;
        }
        return $result;
    }

    private static function write(string $level, string $shopId, ?string $orderId, string $event, array $context): void
    {
        $line = self::formatLine($level, $shopId, $orderId, $event, $context);
        $file = self::LOG_DIR . '/' . date('Y-m-d') . '.jsonl';

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
            self::$alertPdo = Db::pdo(Config::fromEnvForInsales());
            return self::$alertPdo;
        } catch (\Throwable) {
            self::$alertPdoFailed = true;
            return null;
        }
    }

    private static function formatLine(string $level, string $shopId, ?string $orderId, string $event, array $context): string
    {
        $entry = [
            'time' => date('H:i:s'),
            'level' => $level,
            'shop' => $shopId,
            'order' => ($orderId !== null && $orderId !== '') ? $orderId : null,
            'event' => $event,
            'context' => $context,
        ];

        $json = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json !== false ? $json : '{"error":"log_encode_failed"}';
    }
}
