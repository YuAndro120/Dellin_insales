<?php

declare(strict_types=1);

namespace ShippingBridge;

/**
 * Интеграция с интернет-эквайрингом Т-Банка (приём платежей картой).
 * Документация: https://developer.tbank.ru/eacq/intro
 *
 * Подпись запроса (Token): SHA-256 от конкатенации ЗНАЧЕНИЙ корневых
 * параметров запроса, отсортированных по алфавиту по имени ключа,
 * с добавленным паролем терминала как ещё одним полем Password.
 * Вложенные объекты/массивы (Receipt, DATA и т.п.) в подпись НЕ включаются.
 */
final class TbankAcquiring
{
    private const BASE_URL = 'https://securepay.tinkoff.ru/v2';

    public function __construct(
        private readonly string $terminalKey,
        private readonly string $terminalPassword,
    ) {}

    /**
     * Инициирует платёж. Возвращает массив с PaymentId и PaymentURL для редиректа.
     *
     * @param array<string,mixed> $extra Дополнительные корневые поля (например DATA, Receipt, CustomerKey, Recurrent)
     * @return array{payment_id:string,payment_url:string,raw:array<string,mixed>}
     */
    public function init(
        string $orderId,
        int $amountKopecks,
        string $description,
        array $extra = [],
    ): array {
        $payload = array_merge([
            'TerminalKey' => $this->terminalKey,
            'Amount' => $amountKopecks,
            'OrderId' => $orderId,
            'Description' => $description,
        ], $extra);

        $payload['Token'] = $this->buildToken($payload);

        $res = $this->request('/Init', $payload);

        if (($res['Success'] ?? false) !== true) {
            throw new \RuntimeException('Tinkoff Init error: ' . ($res['Message'] ?? 'unknown') . ' / ' . ($res['Details'] ?? ''));
        }

        return [
            'payment_id' => (string) ($res['PaymentId'] ?? ''),
            'payment_url' => (string) ($res['PaymentURL'] ?? ''),
            'raw' => $res,
        ];
    }

    /**
     * Списывает платёж по ранее сохранённому RebillId (автопродление подписки)
     * без участия пользователя.
     *
     * @return array{payment_id:string,status:string,raw:array<string,mixed>}
     */
    public function charge(string $paymentId, string $rebillId): array
    {
        $payload = [
            'TerminalKey' => $this->terminalKey,
            'PaymentId' => $paymentId,
            'RebillId' => $rebillId,
        ];
        $payload['Token'] = $this->buildToken($payload);

        $res = $this->request('/Charge', $payload);

        if (($res['Success'] ?? false) !== true) {
            throw new \RuntimeException('Tinkoff Charge error: ' . ($res['Message'] ?? 'unknown') . ' / ' . ($res['Details'] ?? ''));
        }

        return [
            'payment_id' => (string) ($res['PaymentId'] ?? $paymentId),
            'status' => (string) ($res['Status'] ?? ''),
            'raw' => $res,
        ];
    }

    public function getState(string $paymentId): array
    {
        $payload = [
            'TerminalKey' => $this->terminalKey,
            'PaymentId' => $paymentId,
        ];
        $payload['Token'] = $this->buildToken($payload);

        return $this->request('/GetState', $payload);
    }

    /**
     * Проверяет токен входящего уведомления (webhook) от банка.
     * Уведомление содержит свой собственный Token, который нужно
     * пересчитать и сравнить через hash_equals.
     *
     * @param array<string,mixed> $notification Тело уведомления (JSON, уже декодированный)
     */
    public function verifyNotificationToken(array $notification): bool
    {
        $receivedToken = (string) ($notification['Token'] ?? '');
        if ($receivedToken === '') {
            return false;
        }

        $withoutToken = $notification;
        unset($withoutToken['Token']);

        // Уведомление тоже может содержать вложенные поля (Receipt, DATA) — исключаем массивы.
        $rootScalars = array_filter($withoutToken, static fn($v) => !is_array($v));

        $expectedToken = $this->buildToken($rootScalars);

        return hash_equals($expectedToken, $receivedToken);
    }

    /**
     * @param array<string,mixed> $rootParams Только скалярные корневые поля, без Token
     */
    private function buildToken(array $rootParams): string
    {
        $params = $rootParams;
        unset($params['Token']);
        $params['Password'] = $this->terminalPassword;

        // Убираем вложенные структуры — в подпись идут только корневые скалярные значения.
        $params = array_filter($params, static fn($v) => !is_array($v));

        ksort($params);

        $concatenated = '';
        foreach ($params as $value) {
            $concatenated .= self::stringifyValue($value);
        }

        return hash('sha256', $concatenated);
    }

    private static function stringifyValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return (string) $value;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function request(string $path, array $payload): array
    {
        $ch = curl_init(self::BASE_URL . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('Tinkoff API network error: ' . $error);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Tinkoff API invalid response: ' . mb_substr((string) $response, 0, 500));
        }

        return $decoded;
    }
}
