<?php

declare(strict_types=1);

namespace AdminPanel\Pages;

use AdminPanel\Auth;
use AdminPanel\Layout;
use AdminPanel\Repository;

final class ShopsPage
{
    public static function handleList(Repository $repo): void
    {
        $search = trim((string) ($_GET['q'] ?? ''));
        $shops = $repo->shopsList($search);
        $unread = $repo->unreadAlertsCount();

        Layout::head('Магазины');
        Layout::sidebar('shops', (string) Auth::currentUserEmail(), $unread);

        echo '<div class="pg-title">Магазины</div>';
        echo '<div class="pg-sub">Все установленные магазины и их активность</div>';

        echo '<div class="search-bar"><form method="get"><input type="text" name="q" placeholder="Поиск по домену или ID…" value="' . htmlspecialchars($search, ENT_QUOTES, 'UTF-8') . '" style="width:280px"></form></div>';

        if ($shops === []) {
            echo '<div class="empty-state">Магазины не найдены</div>';
            Layout::footer();
            return;
        }

        echo '<table><thead><tr>';
        echo '<th>Магазин</th><th>Установлен</th><th>PAT</th><th>Заказов</th><th>Оформлено</th><th>Последний заказ</th><th>Статус</th>';
        echo '</tr></thead><tbody>';

        foreach ($shops as $s) {
            $iid = htmlspecialchars((string) $s['insales_id'], ENT_QUOTES, 'UTF-8');
            $host = htmlspecialchars((string) $s['shop_host'], ENT_QUOTES, 'UTF-8');
            $installed = self::formatDate((string) $s['installed_at']);
            $hasPat = (bool) $s['has_pat'];
            $ordersTotal = (int) $s['orders_total'];
            $ordersSubmitted = (int) $s['orders_submitted'];
            $lastOrder = $s['last_order_at'] !== null ? self::formatDate((string) $s['last_order_at']) : '—';
            $isUninstalled = $s['uninstalled_at'] !== null;

            $statusBadge = $isUninstalled
                ? '<span class="badge badge-neutral">отключён</span>'
                : ($hasPat ? '<span class="badge badge-ok">активен</span>' : '<span class="badge badge-warn">нет PAT</span>');

            echo '<tr>';
            echo '<td><a class="tlink" href="/shops/' . $iid . '">' . $host . '</a><div class="metric-sub">' . $iid . '</div></td>';
            echo '<td class="mono">' . $installed . '</td>';
            echo '<td>' . ($hasPat ? '<span class="badge badge-ok">да</span>' : '<span class="badge badge-warn">нет</span>') . '</td>';
            echo '<td class="mono">' . $ordersTotal . '</td>';
            echo '<td class="mono">' . $ordersSubmitted . '</td>';
            echo '<td class="mono">' . $lastOrder . '</td>';
            echo '<td>' . $statusBadge . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        Layout::footer();
    }

    public static function handleDetail(Repository $repo, string $insalesId): void
    {
        $shop = $repo->shopByInsalesId($insalesId);
        $unread = $repo->unreadAlertsCount();

        Layout::head('Магазин');
        Layout::sidebar('shops', (string) Auth::currentUserEmail(), $unread);

        if ($shop === null) {
            echo '<div class="pg-title">Магазин не найден</div>';
            Layout::footer();
            return;
        }

        $host = htmlspecialchars((string) $shop['shop_host'], ENT_QUOTES, 'UTF-8');
        echo '<div class="pg-title">' . $host . '</div>';
        echo '<div class="pg-sub">ID ' . htmlspecialchars($insalesId, ENT_QUOTES, 'UTF-8') . '</div>';

        echo '<div class="grid-4">';
        echo '<div class="card"><div class="metric-label">Установлен</div><div class="metric-value" style="font-size:15px">' . self::formatDate((string) $shop['installed_at']) . '</div></div>';
        $hasPat = !empty($shop['dellin_pat_enc']);
        echo '<div class="card"><div class="metric-label">PAT Деловых Линий</div><div class="metric-value" style="font-size:15px">' . ($hasPat ? '<span class="badge badge-ok">подключён</span>' : '<span class="badge badge-warn">не указан</span>') . '</div></div>';
        echo '<div class="card"><div class="metric-label">Вариант отгрузки</div><div class="metric-value" style="font-size:15px">' . htmlspecialchars((string) ($shop['derival_variant'] ?? '—'), ENT_QUOTES, 'UTF-8') . '</div></div>';
        echo '<div class="card"><div class="metric-label">Email уведомлений</div><div class="metric-value" style="font-size:13px">' . htmlspecialchars((string) ($shop['requester_email'] ?? '—'), ENT_QUOTES, 'UTF-8') . '</div></div>';
        echo '</div>';

        $orders = $repo->ordersForShop($insalesId, 50);

        echo '<div class="card"><div class="metric-label" style="margin-bottom:12px">Последние заказы</div>';
        if ($orders === []) {
            echo '<div class="empty-state">Заказов пока нет</div>';
        } else {
            echo '<table><thead><tr><th>№</th><th>Получатель</th><th>Город</th><th>Вес</th><th>Статус ДЛ</th><th>Создан</th></tr></thead><tbody>';
            foreach ($orders as $o) {
                $statusBadge = $o['dellin_request_id']
                    ? '<span class="badge badge-ok">' . htmlspecialchars((string) ($o['dellin_status_title'] ?: 'оформлен'), ENT_QUOTES, 'UTF-8') . '</span>'
                    : '<span class="badge badge-neutral">не оформлен</span>';
                echo '<tr>';
                echo '<td class="mono">' . htmlspecialchars((string) $o['insales_order_number'], ENT_QUOTES, 'UTF-8') . '</td>';
                echo '<td>' . htmlspecialchars((string) $o['receiver_name'], ENT_QUOTES, 'UTF-8') . '</td>';
                echo '<td>' . htmlspecialchars((string) $o['arrival_city_name'], ENT_QUOTES, 'UTF-8') . '</td>';
                echo '<td class="mono">' . htmlspecialchars((string) $o['weight'], ENT_QUOTES, 'UTF-8') . '</td>';
                echo '<td>' . $statusBadge . '</td>';
                echo '<td class="mono">' . self::formatDate((string) $o['created_at']) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';

        echo '<div style="margin-top:16px"><a class="tlink" href="/logs?shop=' . htmlspecialchars($insalesId, ENT_QUOTES, 'UTF-8') . '">Посмотреть логи этого магазина →</a></div>';

        Layout::footer();
    }

    private static function formatDate(string $raw): string
    {
        $ts = strtotime($raw);
        return $ts !== false ? date('d.m.Y H:i', $ts) : $raw;
    }
}
