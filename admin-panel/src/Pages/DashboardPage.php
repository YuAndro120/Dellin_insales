<?php

declare(strict_types=1);

namespace AdminPanel\Pages;

use AdminPanel\Auth;
use AdminPanel\Layout;
use AdminPanel\Repository;

final class DashboardPage
{
    public static function handle(Repository $repo): void
    {
        $summary = $repo->dashboardSummary();
        $perDay = $repo->ordersPerDay(14);
        $unread = $repo->unreadAlertsCount();

        Layout::head('Дашборд');
        Layout::sidebar('dashboard', (string) Auth::currentUserEmail(), $unread);

        echo '<div class="pg-title">Дашборд</div>';
        echo '<div class="pg-sub">Общая картина по всем магазинам</div>';

        echo '<div class="grid-4">';
        self::metric('Магазинов всего', (string) $summary['shops_total']);
        self::metric('Активны за 7 дн.', (string) $summary['shops_active_7d']);
        self::metric('Заявок сегодня', (string) $summary['orders_today']);
        $errClass = $summary['errors_today'] > 0 ? ' err' : '';
        self::metric('Ошибок сегодня', (string) $summary['errors_today'], $errClass);
        echo '</div>';

        echo '<div class="grid-2">';
        self::metric('Заявок за 7 дн.', (string) $summary['orders_7d']);
        $rateClass = $summary['success_rate_7d'] >= 90 ? ' ok' : ($summary['success_rate_7d'] < 70 ? ' err' : '');
        self::metric('Доля успешных за 7 дн.', $summary['success_rate_7d'] . '%', $rateClass);
        echo '</div>';

        echo '<div class="card">';
        echo '<div class="metric-label" style="margin-bottom:14px">Заявки по дням (14 дней)</div>';
        self::renderChart($perDay);
        echo '</div>';

        Layout::footer();
    }

    private static function metric(string $label, string $value, string $extraClass = ''): void
    {
        echo '<div class="card"><div class="metric-label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div>';
        echo '<div class="metric-value' . $extraClass . '">' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</div></div>';
    }

    /** @param list<array<string,mixed>> $perDay */
    private static function renderChart(array $perDay): void
    {
        if ($perDay === []) {
            echo '<div class="empty-state">Нет данных за выбранный период</div>';
            return;
        }

        $max = 1;
        foreach ($perDay as $row) {
            $max = max($max, (int) $row['total']);
        }

        echo '<div style="display:flex;align-items:flex-end;gap:6px;height:140px">';
        foreach ($perDay as $row) {
            $total = (int) $row['total'];
            $submitted = (int) $row['submitted'];
            $heightTotal = $max > 0 ? round($total / $max * 120) : 0;
            $heightSubmitted = $max > 0 ? round($submitted / $max * 120) : 0;
            $date = (string) $row['d'];
            $dayLabel = date('d.m', strtotime($date));

            echo '<div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px" title="' . htmlspecialchars($date, ENT_QUOTES, 'UTF-8') . ': ' . $total . ' всего, ' . $submitted . ' оформлено">';
            echo '<div style="position:relative;width:100%;height:120px;display:flex;align-items:flex-end;justify-content:center">';
            echo '<div style="width:60%;background:var(--line2);border-radius:3px 3px 0 0;height:' . $heightTotal . 'px"></div>';
            echo '<div style="width:60%;position:absolute;bottom:0;background:var(--accent);border-radius:3px 3px 0 0;height:' . $heightSubmitted . 'px;opacity:.85"></div>';
            echo '</div>';
            echo '<div style="font-size:10px;color:var(--ink3);font-family:var(--mono)">' . $dayLabel . '</div>';
            echo '</div>';
        }
        echo '</div>';
        echo '<div style="display:flex;gap:16px;margin-top:12px;font-size:11px;color:var(--ink3)">';
        echo '<span><span style="display:inline-block;width:8px;height:8px;background:var(--accent);border-radius:2px;margin-right:5px"></span>Оформлено в ДЛ</span>';
        echo '<span><span style="display:inline-block;width:8px;height:8px;background:var(--line2);border-radius:2px;margin-right:5px"></span>Всего заказов</span>';
        echo '</div>';
    }
}
