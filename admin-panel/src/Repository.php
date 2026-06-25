<?php

declare(strict_types=1);

namespace AdminPanel;

final class Repository
{
    public function __construct(private readonly \PDO $pdo) {}

    /** @return array{shops_total:int,shops_active_7d:int,orders_today:int,orders_7d:int,errors_today:int,success_rate_7d:float} */
    public function dashboardSummary(): array
    {
        $shopsTotal = (int) $this->scalar('SELECT COUNT(*) FROM insales_shops WHERE uninstalled_at IS NULL');

        $shopsActive7d = (int) $this->scalar(
            "SELECT COUNT(DISTINCT insales_shop_id) FROM dellin_orders WHERE created_at > (NOW() - INTERVAL 7 DAY)"
        );

        $ordersToday = (int) $this->scalar(
            'SELECT COUNT(*) FROM dellin_orders WHERE DATE(created_at) = CURDATE()'
        );

        $orders7d = (int) $this->scalar(
            'SELECT COUNT(*) FROM dellin_orders WHERE created_at > (NOW() - INTERVAL 7 DAY)'
        );

        $errorsToday = (int) $this->scalar(
            "SELECT COUNT(*) FROM admin_alerts WHERE level = 'error' AND DATE(created_at) = CURDATE()"
        );

        $total7d = (int) $this->scalar('SELECT COUNT(*) FROM dellin_orders WHERE created_at > (NOW() - INTERVAL 7 DAY)');
        $withRequest7d = (int) $this->scalar(
            'SELECT COUNT(*) FROM dellin_orders WHERE created_at > (NOW() - INTERVAL 7 DAY) AND dellin_request_id IS NOT NULL'
        );
        $successRate7d = $total7d > 0 ? round($withRequest7d / $total7d * 100, 1) : 0.0;

        return [
            'shops_total' => $shopsTotal,
            'shops_active_7d' => $shopsActive7d,
            'orders_today' => $ordersToday,
            'orders_7d' => $orders7d,
            'errors_today' => $errorsToday,
            'success_rate_7d' => $successRate7d,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function ordersPerDay(int $days = 14): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT DATE(created_at) AS d,
                    COUNT(*) AS total,
                    SUM(CASE WHEN dellin_request_id IS NOT NULL THEN 1 ELSE 0 END) AS submitted
             FROM dellin_orders
             WHERE created_at > (NOW() - INTERVAL :days DAY)
             GROUP BY DATE(created_at)
             ORDER BY d ASC"
        );
        $stmt->bindValue(':days', $days, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function shopsList(string $search = ''): array
    {
        $sql = "SELECT s.insales_id, s.shop_host, s.installed_at, s.uninstalled_at,
                       (s.dellin_pat_enc IS NOT NULL AND s.dellin_pat_enc != '') AS has_pat,
                       (SELECT COUNT(*) FROM dellin_orders o WHERE o.insales_shop_id = s.insales_id) AS orders_total,
                       (SELECT COUNT(*) FROM dellin_orders o WHERE o.insales_shop_id = s.insales_id AND o.dellin_request_id IS NOT NULL) AS orders_submitted,
                       (SELECT MAX(o.created_at) FROM dellin_orders o WHERE o.insales_shop_id = s.insales_id) AS last_order_at,
                       sub.plan AS sub_plan, sub.status AS sub_status,
                       sub.current_period_ends_at AS sub_ends_at, sub.trial_ends_at
                FROM insales_shops s
                LEFT JOIN subscriptions sub ON sub.insales_id = s.insales_id";
        $params = [];
        if ($search !== '') {
            $sql .= ' WHERE s.shop_host LIKE :search OR s.insales_id LIKE :search';
            $params[':search'] = '%' . $search . '%';
        }
        $sql .= ' ORDER BY s.installed_at DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function shopByInsalesId(string $insalesId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM insales_shops WHERE insales_id = :iid LIMIT 1');
        $stmt->execute([':iid' => $insalesId]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function subscriptionForShop(string $insalesId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT sub.plan, sub.status, sub.trial_ends_at, sub.current_period_ends_at,
                    (SELECT SUM(p.amount) FROM payments p WHERE p.insales_id = sub.insales_id AND p.status = \'succeeded\') AS total_paid,
                    (SELECT p.payment_method FROM payments p WHERE p.insales_id = sub.insales_id AND p.status = \'succeeded\' ORDER BY p.created_at DESC LIMIT 1) AS last_method,
                    (SELECT p.created_at FROM payments p WHERE p.insales_id = sub.insales_id AND p.status = \'succeeded\' ORDER BY p.created_at DESC LIMIT 1) AS last_paid_at,
                    (SELECT p.amount FROM payments p WHERE p.insales_id = sub.insales_id AND p.status = \'succeeded\' ORDER BY p.created_at DESC LIMIT 1) AS last_amount
             FROM subscriptions sub
             WHERE sub.insales_id = :iid
             LIMIT 1'
        );
        $stmt->execute([':iid' => $insalesId]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function ordersForShop(string $insalesId, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, insales_order_id, insales_order_number, receiver_name,
                    arrival_city_name, weight, stated_value,
                    dellin_request_id, dellin_barcode, dellin_status_title, created_at
             FROM dellin_orders
             WHERE insales_shop_id = :iid
             ORDER BY created_at DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':iid', $insalesId);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function alerts(bool $onlyUnread = false, int $limit = 100): array
    {
        $sql = 'SELECT * FROM admin_alerts';
        if ($onlyUnread) {
            $sql .= ' WHERE is_read = 0';
        }
        $sql .= ' ORDER BY created_at DESC LIMIT :lim';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function unreadAlertsCount(): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM admin_alerts WHERE is_read = 0');
    }

    public function markAlertRead(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE admin_alerts SET is_read = 1 WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public function markAllAlertsRead(): void
    {
        $this->pdo->exec('UPDATE admin_alerts SET is_read = 1 WHERE is_read = 0');
    }

    private function scalar(string $sql): mixed
    {
        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            return null;
        }
        $row = $stmt->fetch(\PDO::FETCH_NUM);
        return $row !== false ? $row[0] : null;
    }

    /** @return list<array<string,mixed>> */
    public function leadsList(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, email, inn, company_name, plan, crm_status, created_at
             FROM early_access_leads
             ORDER BY created_at DESC'
        );
        return $stmt->fetchAll();
    }

    public function updateLeadStatus(int $id, string $status): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE early_access_leads SET crm_status = :s WHERE id = :id'
        );
        $stmt->execute([':s' => $status, ':id' => $id]);
    }
}
