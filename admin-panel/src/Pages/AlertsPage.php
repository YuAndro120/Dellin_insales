<?php

declare(strict_types=1);

namespace AdminPanel\Pages;

use AdminPanel\Auth;
use AdminPanel\Layout;
use AdminPanel\Repository;

final class AlertsPage
{
    public static function handle(Repository $repo, string $method): void
    {
        if ($method === 'POST') {
            if (isset($_POST['mark_read'])) {
                $repo->markAlertRead((int) $_POST['mark_read']);
            } elseif (isset($_POST['mark_all_read'])) {
                $repo->markAllAlertsRead();
            }
            header('Location: /alerts');
            exit;
        }

        $onlyUnread = ($_GET['filter'] ?? '') === 'unread';
        $alerts = $repo->alerts($onlyUnread, 200);
        $unread = $repo->unreadAlertsCount();

        Layout::head('Алерты');
        Layout::sidebar('alerts', (string) Auth::currentUserEmail(), $unread);

        echo '<div class="pg-title">Алерты</div>';
        echo '<div class="pg-sub">Ошибки и важные события из бриджа</div>';

        echo '<div class="filter-bar">';
        echo '<a class="btn-ghost" href="/alerts">Все</a>';
        echo '<a class="btn-ghost" href="/alerts?filter=unread">Непрочитанные (' . $unread . ')</a>';
        if ($unread > 0) {
            echo '<form method="post" style="margin-left:auto"><button type="submit" name="mark_all_read" value="1" class="btn">Отметить все прочитанными</button></form>';
        }
        echo '</div>';

        echo '<div class="card" style="padding:0">';
        if ($alerts === []) {
            echo '<div class="empty-state">Алертов нет</div>';
        } else {
            foreach ($alerts as $a) {
                $isUnread = (int) $a['is_read'] === 0;
                $rowClass = $isUnread ? ' unread' : '';
                $dotClass = $isUnread ? '' : ' read';
                $level = (string) $a['level'];
                $badgeClass = $level === 'error' ? 'badge-err' : ($level === 'warning' ? 'badge-warn' : 'badge-neutral');

                echo '<div class="alert-row' . $rowClass . '">';
                echo '<div class="alert-dot' . $dotClass . '"></div>';
                echo '<div style="flex:1">';
                echo '<div><span class="badge ' . $badgeClass . '">' . htmlspecialchars($level, ENT_QUOTES, 'UTF-8') . '</span> <strong>' . htmlspecialchars((string) $a['event'], ENT_QUOTES, 'UTF-8') . '</strong></div>';
                echo '<div style="margin-top:4px">' . htmlspecialchars((string) $a['message'], ENT_QUOTES, 'UTF-8') . '</div>';
                $meta = [];
                if (!empty($a['shop_id'])) {
                    $meta[] = 'shop=' . $a['shop_id'];
                }
                if (!empty($a['order_id'])) {
                    $meta[] = 'order=' . $a['order_id'];
                }
                $meta[] = date('d.m.Y H:i:s', strtotime((string) $a['created_at']));
                echo '<div class="alert-meta">' . htmlspecialchars(implode(' · ', $meta), ENT_QUOTES, 'UTF-8') . '</div>';
                echo '</div>';
                if ($isUnread) {
                    echo '<form method="post"><input type="hidden" name="mark_read" value="' . (int) $a['id'] . '"><button type="submit" class="btn-ghost" style="font-size:11px;padding:5px 10px">Прочитано</button></form>';
                }
                echo '</div>';
            }
        }
        echo '</div>';

        Layout::footer();
    }
}
