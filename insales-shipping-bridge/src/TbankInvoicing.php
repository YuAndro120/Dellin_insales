<?php

declare(strict_types=1);

namespace ShippingBridge;

/**
 * Интеграция с T-API Т-Банка для выставления счетов юридическим лицам.
 * Отдельный продукт от интернет-эквайринга (TbankAcquiring) — другая
 * авторизация (Bearer-токен, не SHA-256 подпись терминала).
 *
 * Документация:
 * - Выставить счёт: https://developer.tbank.ru/docs/api/post-api-v-1-invoice-send
 * - Статус счёта: https://developer.tbank.ru/docs/api/get-api-v-1-invoice-invoice-id-info
 *
 * Лимиты: 4 запроса/сек на выставление, 20 запросов/сек на проверку статуса.
 */
final class TbankInvoicing
{
    private const BASE_URL = 'https://business.tbank.ru/openapi';

    public function __construct(private readonly string $bearerToken) {}

    /**
     * Выставляет счёт юрлицу. Возвращает массив с данными ответа банка
     * (включая invoiceId, нужный для последующей проверки статуса).
     *
     * @param array{name:string,inn:string,kpp?:string} $payer
     * @param list<array{name:string,price:float,unit:string,vat:string,amount:float}> $items
     * @param list<array{email?:string,contactPhone?:string}> $contacts
     * @return array<string,mixed>
     */
    public function sendInvoice(
        string $invoiceNumber,
        \DateTimeImmutable $dueDate,
        array $payer,
        array $items,
        array $contacts = [],
        ?string $comment = null,
        ?string $customPaymentPurpose = null,
        ?string $accountNumber = null,
    ): array {
        if (!preg_match('/^\d{1,15}$/', $invoiceNumber)) {
            throw new \InvalidArgumentException('invoiceNumber должен быть числовой строкой до 15 цифр.');
        }
        if ($items === []) {
            throw new \InvalidArgumentException('Нужна хотя бы одна позиция счёта.');
        }

        $body = [
            'invoiceNumber' => $invoiceNumber,
            'dueDate' => $dueDate->format('Y-m-d'),
            'payer' => array_filter([
                'name' => $payer['name'] ?? null,
                'inn' => $payer['inn'] ?? null,
                'kpp' => $payer['kpp'] ?? null,
            ]),
            'items' => array_map(static function (array $item): array {
                return [
                    'name' => $item['name'],
                    'price' => $item['price'],
                    'unit' => $item['unit'],
                    'vat' => $item['vat'],
                    'amount' => $item['amount'],
                ];
            }, $items),
        ];

        if ($accountNumber !== null) {
            $body['accountNumber'] = $accountNumber;
        }
        if ($contacts !== []) {
            $body['contacts'] = $contacts;
        }
        if ($comment !== null) {
            $body['comment'] = mb_substr($comment, 0, 1000);
        }
        if ($customPaymentPurpose !== null) {
            $body['customPaymentPurpose'] = mb_substr($customPaymentPurpose, 0, 512);
        }

        return $this->request('POST', '/api/v1/invoice/send', $body);
    }

    /**
     * Получает текущий статус выставленного счёта.
     *
     * @return array<string,mixed>
     */
    public function getInvoiceInfo(string $invoiceId): array
    {
        return $this->request('GET', "/api/v1/openapi/invoice/{$invoiceId}/info", null);
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, ?array $body): array
    {
        $ch = curl_init(self::BASE_URL . $path);

        $headers = [
            'Authorization: Bearer ' . $this->bearerToken,
            'Accept: application/json',
            'X-Request-Id: ' . $this->generateRequestId(),
        ];

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
        ];

        if ($body !== null) {
            $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }

        curl_setopt_array($ch, $opts);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('T-API network error: ' . $error);
        }

        $decoded = json_decode($response, true);

        if ($httpCode >= 400) {
            $message = is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_UNICODE) : (string) $response;
            throw new \RuntimeException("T-API HTTP {$httpCode}: " . mb_substr($message, 0, 1000));
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function generateRequestId(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
