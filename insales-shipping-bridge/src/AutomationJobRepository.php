<?php

declare(strict_types=1);

namespace ShippingBridge;

/**
 * Очередь фоновых задач автоматизации (таблица automation_jobs).
 * Обрабатывается воркером bin/automation_worker.php по cron.
 *
 * Почему очередь, а не синхронный вызов прямо в вебхуке: вебхук от inSales
 * должен ответить быстро (иначе inSales посчитает доставку неуспешной и
 * будет ретраить сам, увеличивая нагрузку) — а создание заявки в ДЛ это
 * несколько последовательных HTTP-вызовов к внешнему API, которые могут
 * занять секунды или упасть. Очередь разносит "принять событие" и
 * "выполнить действие" по времени и позволяет:
 *   - retry с exponential backoff при сбое внешнего API;
 *   - несколько параллельных воркеров без дублирования работы (SKIP LOCKED);
 *   - не терять события при пиковой нагрузке (много заказов меняют статус
 *     одновременно) — они просто копятся в очереди, а не роняют веб-сервер.
 */
final class AutomationJobRepository
{
    private const BACKOFF_BASE_SECONDS = 30;
    private const BACKOFF_MAX_SECONDS = 3600;

    public function __construct(private readonly \PDO $pdo) {}

    /**
     * Поставить задачу в очередь. Идемпотентно: если для этого заказа уже
     * есть активная (pending/processing) задача такого же типа — новую не
     * создаёт (защита от повторной постановки при дублирующихся вебхуках).
     *
     * @param array<string, mixed> $payload
     */
    public function enqueue(
        string $insalesShopId,
        string $insalesOrderId,
        string $jobType,
        array $payload = [],
    ): void {
        $exists = $this->pdo->prepare('
            SELECT id FROM automation_jobs
            WHERE insales_shop_id = :shop AND insales_order_id = :order
              AND job_type = :type AND status IN (\'pending\', \'processing\')
            LIMIT 1
        ');
        $exists->execute(['shop' => $insalesShopId, 'order' => $insalesOrderId, 'type' => $jobType]);
        if ($exists->fetchColumn() !== false) {
            return;
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO automation_jobs (insales_shop_id, insales_order_id, job_type, payload, status, next_attempt_at)
            VALUES (:shop, :order, :type, :payload, \'pending\', NOW())
        ');
        $stmt->execute([
            'shop' => $insalesShopId,
            'order' => $insalesOrderId,
            'type' => $jobType,
            'payload' => $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * Забрать до $limit задач, готовых к выполнению, и сразу пометить их
     * 'processing' (в одной транзакции с SELECT ... FOR UPDATE SKIP LOCKED —
     * так при нескольких одновременных воркерах они не возьмут одну и ту же
     * задачу дважды).
     *
     * @return list<array{id:int,insales_shop_id:string,insales_order_id:string,job_type:string,payload:array<string,mixed>,attempts:int,max_attempts:int}>
     */
    public function claimBatch(int $limit = 20): array
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('
                SELECT id, insales_shop_id, insales_order_id, job_type, payload, attempts, max_attempts
                FROM automation_jobs
                WHERE status = \'pending\' AND next_attempt_at <= NOW()
                ORDER BY id ASC
                LIMIT ' . (int) $limit . '
                FOR UPDATE SKIP LOCKED
            ');
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            if ($rows !== []) {
                $ids = array_column($rows, 'id');
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $upd = $this->pdo->prepare("UPDATE automation_jobs SET status = 'processing' WHERE id IN ({$placeholders})");
                $upd->execute($ids);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'insales_shop_id' => (string) $row['insales_shop_id'],
                'insales_order_id' => (string) $row['insales_order_id'],
                'job_type' => (string) $row['job_type'],
                'payload' => $row['payload'] !== null ? (json_decode((string) $row['payload'], true) ?: []) : [],
                'attempts' => (int) $row['attempts'],
                'max_attempts' => (int) $row['max_attempts'],
            ];
        }, $rows);
    }

    public function markDone(int $jobId): void
    {
        $stmt = $this->pdo->prepare("UPDATE automation_jobs SET status = 'done' WHERE id = :id");
        $stmt->execute(['id' => $jobId]);
    }

    /**
     * Провал попытки: увеличивает счётчик, считает экспоненциальную паузу
     * до следующей попытки (30с, 60с, 120с, ... до потолка в 1 час), и если
     * попытки исчерпаны — помечает 'failed' окончательно.
     */
    public function markFailed(int $jobId, int $attempts, int $maxAttempts, string $error): void
    {
        $attempts++;
        if ($attempts >= $maxAttempts) {
            $stmt = $this->pdo->prepare("
                UPDATE automation_jobs
                SET status = 'failed', attempts = :attempts, last_error = :error
                WHERE id = :id
            ");
            $stmt->execute(['attempts' => $attempts, 'error' => mb_substr($error, 0, 2000), 'id' => $jobId]);
            return;
        }

        $delay = min(self::BACKOFF_MAX_SECONDS, self::BACKOFF_BASE_SECONDS * (2 ** ($attempts - 1)));
        $stmt = $this->pdo->prepare("
            UPDATE automation_jobs
            SET status = 'pending', attempts = :attempts, last_error = :error,
                next_attempt_at = DATE_ADD(NOW(), INTERVAL :delay SECOND)
            WHERE id = :id
        ");
        $stmt->execute([
            'attempts' => $attempts,
            'error' => mb_substr($error, 0, 2000),
            'delay' => $delay,
            'id' => $jobId,
        ]);
    }
}