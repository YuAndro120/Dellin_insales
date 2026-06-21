<?php

declare(strict_types=1);

namespace ShippingBridge;

/**
 * Работа с выставленными счетами юрлицам (таблица invoices).
 */
final class InvoiceRepository
{
    public function __construct(private readonly \PDO $pdo) {}

    /**
     * Генерирует следующий номер счёта — увеличивающаяся числовая
     * последовательность на основе текущего количества счетов + время,
     * чтобы избежать коллизий без отдельного auto_increment поля наружу.
     * Требование Т-Банка: строка из цифр, до 15 символов.
     */
    public function generateInvoiceNumber(): string
    {
        // YYMMDD + 6 случайных цифр — укладывается в 15 символов, читаемо по дате.
        $datePart = date('ymd');
        $randomPart = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        return $datePart . $randomPart;
    }

    public function create(
        string $insalesId,
        string $plan,
        float $amount,
        string $invoiceNumber,
        string $payerName,
        string $payerInn,
        ?string $payerKpp,
        \DateTimeImmutable $dueDate,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO invoices (insales_id, plan, amount, invoice_number, payer_name, payer_inn, payer_kpp, due_date, status)
             VALUES (:iid, :plan, :amount, :number, :name, :inn, :kpp, :due, :status)'
        );
        $stmt->execute([
            ':iid' => $insalesId,
            ':plan' => $plan,
            ':amount' => $amount,
            ':number' => $invoiceNumber,
            ':name' => $payerName,
            ':inn' => $payerInn,
            ':kpp' => $payerKpp,
            ':due' => $dueDate->format('Y-m-d'),
            ':status' => 'pending',
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function markSent(int $id, string $tbankInvoiceId, array $rawResponse): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE invoices SET status = 'sent', tbank_invoice_id = :tid, raw_response = :raw WHERE id = :id"
        );
        $stmt->execute([
            ':tid' => $tbankInvoiceId,
            ':raw' => json_encode($rawResponse, JSON_UNESCAPED_UNICODE),
            ':id' => $id,
        ]);
    }

    public function markFailed(int $id, array $rawResponse): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE invoices SET status = 'failed', raw_response = :raw WHERE id = :id"
        );
        $stmt->execute([
            ':raw' => json_encode($rawResponse, JSON_UNESCAPED_UNICODE),
            ':id' => $id,
        ]);
    }

    public function markPaid(int $id): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE invoices SET status = 'paid', paid_at = CURRENT_TIMESTAMP WHERE id = :id"
        );
        $stmt->execute([':id' => $id]);
    }

    public function findByTbankInvoiceId(string $tbankInvoiceId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM invoices WHERE tbank_invoice_id = :tid LIMIT 1');
        $stmt->execute([':tid' => $tbankInvoiceId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /** @return list<array<string,mixed>> Счета, ожидающие оплаты — для периодической проверки статуса. */
    public function findPendingPayment(): array
    {
        $stmt = $this->pdo->query(
            "SELECT * FROM invoices WHERE status = 'sent' AND tbank_invoice_id IS NOT NULL ORDER BY created_at ASC"
        );
        return $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
    }
}
