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
        $dates = $logs->availableDates();
        $selectedDate = trim((string) ($_GET['date'] ?? ($dates[0] ?? date('Y-m-d'))));
        $shopFilter = trim((string) ($_GET['shop'] ?? ''));
        $levelFilter = trim((string) ($_GET['level'] ?? ''));

        $lines = $logs->readDate($selectedDate, $shopFilter, $levelFilter, 500);
        $unread = $repo->unreadAlertsCount();

        Layout::head('Логи');
        Layout::sidebar('logs', (string) Auth::currentUserEmail(), $unread);

        echo '<div class="pg-title">Логи</div>';
        echo '<div class="pg-sub">Последние 500 записей за выбранный день</div>';

        echo '<form method="get" class="filter-bar">';
        echo '<select name="date" onchange="this.form.submit()">';
        if ($dates === []) {
            echo '<option value="' . htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        foreach ($dates as $d) {
            $sel = $d === $selectedDate ? ' selected' : '';
            echo '<option value="' . htmlspecialchars($d, ENT_QUOTES, 'UTF-8') . '"' . $sel . '>' . htmlspecialchars($d, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        echo '</select>';

        echo '<input type="text" name="shop" placeholder="ID магазина…" value="' . htmlspecialchars($shopFilter, ENT_QUOTES, 'UTF-8') . '" style="padding:8px 12px;background:var(--bg3);border:1px solid var(--line);border-radius:8px;color:var(--ink);font-size:13px">';

        echo '<select name="level" onchange="this.form.submit()">';
        echo '<option value=""' . ($levelFilter === '' ? ' selected' : '') . '>Все уровни</option>';
        foreach (['INFO', 'ERROR'] as $lvl) {
            $sel = strcasecmp($levelFilter, $lvl) === 0 ? ' selected' : '';
            echo '<option value="' . $lvl . '"' . $sel . '>' . $lvl . '</option>';
        }
        echo '</select>';

        echo '<button type="submit" class="btn-ghost">Применить</button>';
        echo '</form>';

        echo '<div class="card" style="padding:0;overflow:hidden">';
        if ($lines === []) {
            echo '<div class="empty-state">Нет записей по заданным фильтрам</div>';
        } else {
            foreach ($lines as $l) {
                $levelClass = strcasecmp($l['level'], 'ERROR') === 0 ? ' level-error' : (strcasecmp($l['level'], 'WARNING') === 0 ? ' level-warning' : '');
                echo '<div class="log-line' . $levelClass . '">' . htmlspecialchars($l['raw'], ENT_QUOTES, 'UTF-8') . '</div>';
            }
        }
        echo '</div>';

        Layout::footer();
    }
}
