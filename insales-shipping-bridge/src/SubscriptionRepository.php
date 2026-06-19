<?php

declare(strict_types=1);

namespace ShippingBridge;

/**
 * Работа с подписками магазинов (таблица subscriptions) и историей
 * платежей (таблица payments). Отдельный репозиторий от ShopRepository,
 * чтобы не смешивать логику настроек магазина с логикой биллинга.
 */
final class SubscriptionRepository
{
    public const PLAN_CALC_ONLY = 'calc_only';   // 999 ₽ — только расчёт
    public const PLAN_FULL = 'full';              // 1999 ₽ — текущий функционал
    public const PLAN_AUTOMATION = 'automation';  // 4999 ₽ — автоматизация

    public const STATUS_TRIAL = 'trial';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAST_DUE = 'past_due';
    public const STATUS_CANCELLED = 'cancelled';

    private const TRIAL_DAYS = 14;

    public function __construct(private readonly \PDO $pdo) {}

    /**
     * Создаёт триал на 14 дней при первой установке. Если подписка уже
     * существует (переустановка) — не трогает её (COALESCE-подобная защита
     * через ON DUPLICATE KEY с сохранением текущих значений).
     */
    public function ensureTrialSubscription(string $insalesId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO subscriptions (insales_id, plan, status, trial_ends_at)
             VALUES (:iid, :plan, :status, DATE_ADD(NOW(), INTERVAL :days DAY))
             ON DUPLICATE KEY UPDATE
                insales_id = insales_id'
        );
        $stmt->bindValue(':iid', $insalesId);
        $stmt->bindValue(':plan', self::PLAN_FULL);
        $stmt->bindValue(':status', self::STATUS_TRIAL);
        $stmt->bindValue(':days', self::TRIAL_DAYS, \PDO::PARAM_INT);
        $stmt->execute();
    }

    public function findByInsalesId(string $insalesId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM subscriptions WHERE insales_id = :iid LIMIT 1');
        $stmt->execute([':iid' => $insalesId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Возвращает эффективный план для проверки доступа, учитывая истечение
     * триала и просрочку оплаты. Если подписка не найдена вообще (не должно
     * происходить для установленных магазинов, но на случай рассинхрона)
     * — возвращает null, что means "доступ запрещён, обратитесь в поддержку".
     */
    public function effectivePlan(string $insalesId): ?string
    {
        $sub = $this->findByInsalesId($insalesId);
        if ($sub === null) {
            return null;
        }

        $status = (string) $sub['status'];
        $plan = (string) $sub['plan'];

        if ($status === self::STATUS_TRIAL) {
            $trialEndsAt = $sub['trial_ends_at'] !== null ? strtotime((string) $sub['trial_ends_at']) : null;
            if ($trialEndsAt !== null && $trialEndsAt < time()) {
                // Триал истёк, а оплата не зафиксирована — доступ как у не оплатившего.
                return null;
            }
            return self::PLAN_FULL; // на триале — полный доступ независимо от plan в записи
        }

        if ($status === self::STATUS_ACTIVE) {
            $periodEndsAt = $sub['current_period_ends_at'] !== null ? strtotime((string) $sub['current_period_ends_at']) : null;
            if ($periodEndsAt !== null && $periodEndsAt < time()) {
                return null; // период оплаты истёк, ещё не списалось/не оплачено заново
            }
            return $plan;
        }

        // past_due, cancelled — доступа нет
        return null;
    }

    /**
     * Иерархия планов для сравнения "достаточно ли уровня доступа".
     * calc_only < full < automation
     */
    private const PLAN_RANK = [
        self::PLAN_CALC_ONLY => 1,
        self::PLAN_FULL => 2,
        self::PLAN_AUTOMATION => 3,
    ];

    public function hasAtLeast(string $insalesId, string $requiredPlan): bool
    {
        $effective = $this->effectivePlan($insalesId);
        if ($effective === null) {
            return false;
        }
        $effectiveRank = self::PLAN_RANK[$effective] ?? 0;
        $requiredRank = self::PLAN_RANK[$requiredPlan] ?? 99;
        return $effectiveRank >= $requiredRank;
    }

    public function recordPayment(
        string $insalesId,
        string $plan,
        float $amount,
        string $paymentMethod,
        string $status,
        ?string $tbankPaymentId = null,
        ?string $tbankOrderId = null,
        ?string $tbankInvoiceId = null,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO payments (insales_id, plan, amount, payment_method, status, tbank_payment_id, tbank_order_id, tbank_invoice_id)
             VALUES (:iid, :plan, :amount, :method, :status, :pid, :oid, :invid)'
        );
        $stmt->execute([
            ':iid' => $insalesId,
            ':plan' => $plan,
            ':amount' => $amount,
            ':method' => $paymentMethod,
            ':status' => $status,
            ':pid' => $tbankPaymentId,
            ':oid' => $tbankOrderId,
            ':invid' => $tbankInvoiceId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function activateAfterPayment(
        string $insalesId,
        string $plan,
        string $paymentMethod,
        \DateTimeImmutable $periodEndsAt,
        ?string $rebillId = null,
        ?string $customerKey = null,
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE subscriptions SET
                plan = :plan,
                status = :status,
                payment_method = :method,
                current_period_ends_at = :period_end,
                tbank_rebill_id = COALESCE(:rebill_id, tbank_rebill_id),
                tbank_customer_key = COALESCE(:customer_key, tbank_customer_key)
             WHERE insales_id = :iid'
        );
        $stmt->execute([
            ':plan' => $plan,
            ':status' => self::STATUS_ACTIVE,
            ':method' => $paymentMethod,
            ':period_end' => $periodEndsAt->format('Y-m-d H:i:s'),
            ':rebill_id' => $rebillId,
            ':customer_key' => $customerKey,
            ':iid' => $insalesId,
        ]);
    }

    public function saveRebillId(string $insalesId, string $rebillId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE subscriptions SET tbank_rebill_id = :rebill_id WHERE insales_id = :iid'
        );
        $stmt->execute([':rebill_id' => $rebillId, ':iid' => $insalesId]);
    }

    public function markPastDue(string $insalesId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE subscriptions SET status = 'past_due' WHERE insales_id = :iid"
        );
        $stmt->execute([':iid' => $insalesId]);
    }
}
