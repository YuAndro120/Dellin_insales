<?php

declare(strict_types=1);

namespace AdminPanel;

final class Layout
{
    public static function head(string $title): void
    {
        $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        echo <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$t} — Bridge Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0c0e12;--bg2:#121419;--bg3:#181b22;
  --line:#242832;--line2:#323744;
  --ink:#e8eaef;--ink2:#a8aebb;--ink3:#6b7280;
  --accent:#5fb4ff;--accent-dim:#1d3a52;
  --ok:#3dd68c;--ok-dim:#0f2e22;
  --warn:#f5a623;--warn-dim:#3a2a0d;
  --err:#e5484d;--err-dim:#3a1418;
  --sans:'Inter',-apple-system,sans-serif;
  --mono:'IBM Plex Mono',monospace;
  --r:8px;--r2:12px;
}
html,body{height:100%}
body{font-family:var(--sans);background:var(--bg);color:var(--ink);font-size:14px;line-height:1.5;-webkit-font-smoothing:antialiased}
.app{display:flex;min-height:100vh}
.sidebar{width:220px;flex-shrink:0;background:var(--bg2);border-right:1px solid var(--line);display:flex;flex-direction:column;padding:20px 0}
.brand{padding:0 20px 20px;border-bottom:1px solid var(--line);margin-bottom:16px}
.brand-name{font-size:14px;font-weight:600;color:var(--ink)}
.brand-sub{font-size:11px;color:var(--ink3);font-family:var(--mono);margin-top:2px}
.nav{padding:0 12px;flex:1}
.nav-item{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:9px 12px;border-radius:var(--r);color:var(--ink2);text-decoration:none;font-size:13px;font-weight:500;margin-bottom:2px;transition:all .12s}
.nav-item:hover{background:var(--bg3);color:var(--ink)}
.nav-item.active{background:var(--accent-dim);color:var(--accent)}
.nav-badge{background:var(--err);color:#fff;font-size:10px;font-weight:600;padding:1px 6px;border-radius:10px;font-family:var(--mono)}
.sidebar-footer{padding:12px 20px 0;border-top:1px solid var(--line);margin-top:12px}
.user-email{font-size:11px;color:var(--ink3);font-family:var(--mono);margin-bottom:8px;word-break:break-all}
.logout-link{font-size:12px;color:var(--ink3);text-decoration:none}
.logout-link:hover{color:var(--err)}
.main{flex:1;overflow-y:auto}
.content{max-width:1100px;padding:32px 36px}
.pg-title{font-size:22px;font-weight:700;margin-bottom:4px}
.pg-sub{font-size:13px;color:var(--ink3);margin-bottom:24px}
.grid-4{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:24px}
.card{background:var(--bg2);border:1px solid var(--line);border-radius:var(--r2);padding:18px}
.metric-label{font-size:11px;color:var(--ink3);text-transform:uppercase;letter-spacing:.06em;font-weight:600;margin-bottom:8px}
.metric-value{font-family:var(--mono);font-size:28px;font-weight:600;color:var(--ink)}
.metric-value.err{color:var(--err)}
.metric-value.ok{color:var(--ok)}
.metric-sub{font-size:11px;color:var(--ink3);margin-top:4px;font-family:var(--mono)}
table{width:100%;border-collapse:collapse;font-size:13px}
thead th{text-align:left;padding:8px 10px;font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:var(--ink3);font-weight:600;border-bottom:1px solid var(--line)}
tbody td{padding:9px 10px;border-bottom:1px solid var(--line);color:var(--ink2)}
tbody tr:hover{background:var(--bg3)}
tbody td.mono,.mono{font-family:var(--mono)}
a.tlink{color:var(--accent);text-decoration:none}
a.tlink:hover{text-decoration:underline}
.badge{display:inline-flex;align-items:center;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600;font-family:var(--mono)}
.badge-ok{background:var(--ok-dim);color:var(--ok)}
.badge-err{background:var(--err-dim);color:var(--err)}
.badge-warn{background:var(--warn-dim);color:var(--warn)}
.badge-neutral{background:var(--bg3);color:var(--ink3)}
.search-bar{margin-bottom:16px}
.search-bar input,.filter-bar select{padding:8px 12px;background:var(--bg3);border:1px solid var(--line);border-radius:var(--r);color:var(--ink);font-size:13px;font-family:var(--sans);outline:none}
.search-bar input:focus,.filter-bar select:focus{border-color:var(--accent)}
.filter-bar{display:flex;gap:8px;margin-bottom:16px;align-items:center}
.log-line{font-family:var(--mono);font-size:12px;padding:6px 10px;border-bottom:1px solid var(--line);color:var(--ink2);white-space:pre-wrap;word-break:break-all}
.log-line.level-error{color:var(--err)}
.log-line.level-warning{color:var(--warn)}
.empty-state{padding:40px 20px;text-align:center;color:var(--ink3);font-size:13px}
.btn{padding:7px 14px;background:var(--accent);border:0;border-radius:var(--r);color:#04101c;font-size:13px;font-weight:600;cursor:pointer;font-family:var(--sans)}
.btn:hover{opacity:.9}
.btn-ghost{padding:7px 14px;background:transparent;border:1px solid var(--line2);border-radius:var(--r);color:var(--ink2);font-size:13px;cursor:pointer;font-family:var(--sans)}
.btn-ghost:hover{border-color:var(--ink3);color:var(--ink)}
.alert-row{display:flex;align-items:flex-start;gap:10px;padding:12px;border-bottom:1px solid var(--line)}
.alert-row.unread{background:rgba(229,72,77,.05)}
.alert-dot{width:6px;height:6px;border-radius:50%;background:var(--err);margin-top:6px;flex-shrink:0}
.alert-dot.read{background:var(--line2)}
.alert-meta{font-size:11px;color:var(--ink3);font-family:var(--mono);margin-top:3px}
.login-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--bg)}
.login-card{background:var(--bg2);border:1px solid var(--line);border-radius:var(--r2);padding:32px;width:340px}
.login-title{font-size:18px;font-weight:700;margin-bottom:4px}
.login-sub{font-size:12px;color:var(--ink3);margin-bottom:20px}
.field{margin-bottom:14px}
.field label{display:block;font-size:11px;color:var(--ink3);text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;font-weight:600}
.field input{width:100%;padding:9px 12px;background:var(--bg3);border:1px solid var(--line);border-radius:var(--r);color:var(--ink);font-size:13px;outline:none}
.field input:focus{border-color:var(--accent)}
.alert-banner-err{padding:10px 14px;background:var(--err-dim);border:1px solid #5a2025;border-radius:var(--r);color:var(--err);font-size:13px;margin-bottom:14px}
</style>
</head>
<body>
HTML;
    }

    public static function sidebar(string $activePage, string $userEmail, int $unreadAlerts): void
    {
        $items = [
            'dashboard' => ['/', 'Дашборд'],
            'shops' => ['/shops', 'Магазины'],
            'logs' => ['/logs', 'Логи'],
            'alerts' => ['/alerts', 'Алерты'],
        ];
        $email = htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8');

        echo '<div class="app"><aside class="sidebar"><div class="brand"><div class="brand-name">Dellin Bridge</div><div class="brand-sub">admin panel</div></div><nav class="nav">';
        foreach ($items as $key => [$href, $label]) {
            $active = $key === $activePage ? ' active' : '';
            $badge = '';
            if ($key === 'alerts' && $unreadAlerts > 0) {
                $badge = '<span class="nav-badge">' . $unreadAlerts . '</span>';
            }
            echo '<a class="nav-item' . $active . '" href="' . $href . '"><span>' . $label . '</span>' . $badge . '</a>';
        }
        echo '</nav><div class="sidebar-footer"><div class="user-email">' . $email . '</div><a class="logout-link" href="/logout">Выйти</a></div></aside><main class="main"><div class="content">';
    }

    public static function footer(): void
    {
        echo '</div></main></div></body></html>';
    }
}
