<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use AdminPanel\Auth;
use AdminPanel\Config;
use AdminPanel\Db;
use AdminPanel\LogReader;
use AdminPanel\Repository;
use AdminPanel\Pages\AlertsPage;
use AdminPanel\Pages\DashboardPage;
use AdminPanel\Pages\LoginPage;
use AdminPanel\Pages\LogsPage;
use AdminPanel\Pages\ShopsPage;

$config = Config::fromEnv();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$uri = rtrim($uri, '/');
if ($uri === '') {
    $uri = '/';
}

// Логин/логаут не требуют аутентификации
if ($uri === '/login') {
    LoginPage::handle($config, $method);
    exit;
}

if ($uri === '/logout') {
    Auth::logout();
    header('Location: /login');
    exit;
}

Auth::requireLogin();

$pdo = Db::pdo($config);
$repo = new Repository($pdo);
$logs = new LogReader($config->bridgeLogDir);

if ($uri === '/') {
    DashboardPage::handle($repo);
    exit;
}

if ($uri === '/shops') {
    ShopsPage::handleList($repo);
    exit;
}

if (preg_match('#^/shops/([A-Za-z0-9_\-]+)$#', $uri, $m)) {
    ShopsPage::handleDetail($repo, $m[1]);
    exit;
}

if ($uri === '/logs') {
    LogsPage::handle($repo, $logs);
    exit;
}

if ($uri === '/alerts') {
    AlertsPage::handle($repo, $method);
    exit;
}

if ($uri === '/crm') {
    \AdminPanel\Pages\CrmPage::handle($repo, $method);
    exit;
}

http_response_code(404);
echo '404 — страница не найдена. <a href="/" style="color:#5fb4ff">На главную</a>';
