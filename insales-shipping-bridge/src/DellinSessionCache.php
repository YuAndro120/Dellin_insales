<?php

declare(strict_types=1);

namespace ShippingBridge;

/**
 * Кэш sessionID Деловых Линий по магазинам. Сессия ДЛ живёт 30 дней —
 * вместо повторного login()/loginWithPat() на каждый запрос (лишний
 * HTTP round-trip к API ДЛ) переиспользуем сохранённый sessionID,
 * пока он не близок к истечению.
 *
 * При первой же ошибке использования закэшированной сессии (ДЛ ответил,
 * что сессия невалидна) вызывающий код должен сбросить кэш через
 * invalidate() и получить новую сессию обычным логином — это не
 * обрабатывается автоматически внутри кэша, чтобы не плодить скрытые
 * повторные HTTP-вызовы при каждой ошибке.
 */
final class DellinSessionCache
{
    // Считаем сессию валидной чуть меньше заявленных 30 дней — запас на
    // неточности в документации и на разницу часовых поясов сервера/ДЛ.
    private const SESSION_LIFETIME_DAYS = 25;

    public function __construct(private readonly \PDO $pdo) {}

    public function get(string $insalesId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT session_id FROM dellin_sessions WHERE insales_id = :iid AND expires_at > NOW() LIMIT 1'
        );
        $stmt->execute([':iid' => $insalesId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $sessionId = trim((string) $row['session_id']);
        return $sessionId !== '' ? $sessionId : null;
    }

    public function store(string $insalesId, string $sessionId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO dellin_sessions (insales_id, session_id, expires_at)
             VALUES (:iid, :sid, DATE_ADD(NOW(), INTERVAL :days DAY))
             ON DUPLICATE KEY UPDATE
                session_id = VALUES(session_id),
                created_at = CURRENT_TIMESTAMP,
                expires_at = VALUES(expires_at)'
        );
        $stmt->bindValue(':iid', $insalesId);
        $stmt->bindValue(':sid', $sessionId);
        $stmt->bindValue(':days', self::SESSION_LIFETIME_DAYS, \PDO::PARAM_INT);
        $stmt->execute();
    }

    public function invalidate(string $insalesId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM dellin_sessions WHERE insales_id = :iid');
        $stmt->execute([':iid' => $insalesId]);
    }
}
