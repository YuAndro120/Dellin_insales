<?php

declare(strict_types=1);

namespace AdminPanel\Pages;

use AdminPanel\Auth;
use AdminPanel\Layout;
use AdminPanel\LogReader;
use AdminPanel\Repository;

final class LogsPage
{
    public static function handle(Repository $repo, LogReader $logs): void
    {
        $date = trim((string) ($_GET['date'] ?? ''));
        $shop = trim((string) ($_GET['shop'] ?? ''));
        $eventIndex = isset($_GET['event']) ? (int) $_GET['event'] : null;

        $unread = $repo->unreadAlertsCount();

        Layout::head('Логи');
        Layout::sidebar('logs', (string) Auth::currentUserEmail(), $unread);

        if ($date === '') {
            self::renderDatesList($logs);
        } elseif ($shop === '') {
            self::renderShopsList($logs, $date);
        } elseif ($eventIndex === null) {
            self::renderEventsList($logs, $date, $shop);
        } else {
            self::renderEventDetail($logs, $date, $shop, $eventIndex);
        }

        Layout::footer();
    }

    private static function renderDatesList(LogReader $logs): void
    {
        $dates = $logs->availableDates();

        echo '<div class="pg-title">Логи</div>';
        echo '<div class="pg-sub">Выберите день</div>';

        if ($dates === []) {
            echo '<div class="empty-state">Логов пока нет</div>';
            return;
        }

        echo '<div class="card" style="padding:0">';
        foreach ($dates as $d) {
            $h = htmlspecialchars($d, ENT_QUOTES, 'UTF-8');
            echo '<a href="/logs?date=' . $h . '" style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid var(--line);text-decoration:none;color:var(--ink)">';
            echo '<span class="mono">' . $h . '</span>';
            echo '<span style="color:var(--ink3);font-size:12px">→</span>';
            echo '</a>';
        }
        echo '</div>';
    }

    private static function renderShopsList(LogReader $logs, string $date): void
    {
        $shops = $logs->shopsForDate($date);
        $dateH = htmlspecialchars($date, ENT_QUOTES, 'UTF-8');

        echo '<div class="pg-title">' . $dateH . '</div>';
        echo '<div class="pg-sub"><a href="/logs" style="color:var(--accent);text-decoration:none">← Все дни</a> · Магазины с активностью в этот день</div>';

        if ($shops === []) {
            echo '<div class="empty-state">Нет событий за эту дату</div>';
            return;
        }

        echo '<table><thead><tr><th>Магазин</th><th>Событий</th><th>Ошибок</th><th>Последнее событие</th></tr></thead><tbody>';
        foreach ($shops as $s) {
            $shopH = htmlspecialchars($s['shop'], ENT_QUOTES, 'UTF-8');
            $errBadge = $s['errors'] > 0
                ? '<span class="badge badge-err">' . $s['errors'] . '</span>'
                : '<span class="badge badge-ok">0</span>';
            echo '<tr>';
            echo '<td><a class="tlink" href="/logs?date=' . $dateH . '&shop=' . $shopH . '">' . $shopH . '</a></td>';
            echo '<td class="mono">' . $s['total'] . '</td>';
            echo '<td>' . $errBadge . '</td>';
            echo '<td class="mono">' . htmlspecialchars($s['last_time'], ENT_QUOTES, 'UTF-8') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private static function renderEventsList(LogReader $logs, string $date, string $shop): void
    {
        $events = $logs->eventsForShop($date, $shop);
        $dateH = htmlspecialchars($date, ENT_QUOTES, 'UTF-8');
        $shopH = htmlspecialchars($shop, ENT_QUOTES, 'UTF-8');

        echo '<div class="pg-title">' . $shopH . '</div>';
        echo '<div class="pg-sub"><a href="/logs?date=' . $dateH . '" style="color:var(--accent);text-decoration:none">← ' . $dateH . '</a> · События магазина</div>';

        if ($events === []) {
            echo '<div class="empty-state">Нет событий</div>';
            return;
        }

        echo '<table><thead><tr><th>Время</th><th>Событие</th><th>Заказ</th><th>Уровень</th></tr></thead><tbody>';
        foreach ($events as $e) {
            $levelBadge = $e['level'] === 'ERROR'
                ? '<span class="badge badge-err">error</span>'
                : '<span class="badge badge-neutral">info</span>';
            $orderH = $e['order'] !== null ? htmlspecialchars((string) $e['order'], ENT_QUOTES, 'UTF-8') : '—';
            echo '<tr>';
            echo '<td class="mono">' . htmlspecialchars($e['time'], ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td><a class="tlink" href="/logs?date=' . $dateH . '&shop=' . $shopH . '&event=' . $e['index'] . '">' . htmlspecialchars($e['label'], ENT_QUOTES, 'UTF-8') . '</a></td>';
            echo '<td class="mono">' . $orderH . '</td>';
            echo '<td>' . $levelBadge . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private static function renderEventDetail(LogReader $logs, string $date, string $shop, int $eventIndex): void
    {
        $entry = $logs->eventByIndex($date, $eventIndex);
        $dateH = htmlspecialchars($date, ENT_QUOTES, 'UTF-8');
        $shopH = htmlspecialchars($shop, ENT_QUOTES, 'UTF-8');

        echo '<div class="pg-title">' . htmlspecialchars((string) ($entry['label'] ?? 'Событие'), ENT_QUOTES, 'UTF-8') . '</div>';
        echo '<div class="pg-sub"><a href="/logs?date=' . $dateH . '&shop=' . $shopH . '" style="color:var(--accent);text-decoration:none">← ' . $shopH . '</a> · ' . htmlspecialchars((string) ($entry['time'] ?? ''), ENT_QUOTES, 'UTF-8') . '</div>';

        if ($entry === null) {
            echo '<div class="empty-state">Событие не найдено</div>';
            return;
        }

        echo '<div class="grid-4" style="grid-template-columns:repeat(3,1fr)">';
        echo '<div class="card"><div class="metric-label">Магазин</div><div class="metric-value" style="font-size:15px" class="mono">' . htmlspecialchars((string) ($entry['shop'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</div></div>';
        echo '<div class="card"><div class="metric-label">Заказ</div><div class="metric-value" style="font-size:15px">' . htmlspecialchars((string) ($entry['order'] ?? '—'), ENT_QUOTES, 'UTF-8') . '</div></div>';
        $level = (string) ($entry['level'] ?? 'INFO');
        $levelBadge = $level === 'ERROR' ? '<span class="badge badge-err">error</span>' : '<span class="badge badge-neutral">info</span>';
        echo '<div class="card"><div class="metric-label">Уровень</div><div class="metric-value" style="font-size:15px">' . $levelBadge . '</div></div>';
        echo '</div>';

        $context = $entry['context'] ?? [];
        echo '<div class="card">';
        echo '<div class="metric-label" style="margin-bottom:12px">Данные события</div>';
        echo '<pre style="font-family:var(--mono);font-size:12.5px;color:var(--ink2);white-space:pre-wrap;word-break:break-word;line-height:1.6;background:var(--bg3);padding:14px;border-radius:8px;overflow-x:auto">';
        echo htmlspecialchars(json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), ENT_QUOTES, 'UTF-8');
        echo '</pre>';
        echo '</div>';
    }
}
