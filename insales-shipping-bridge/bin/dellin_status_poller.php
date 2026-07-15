#!/usr/bin/env php
<?php

/**
 * Поллер статусов Деловых Линий.
 *
 * Опрашивает «Журнал заказов» ДЛ (POST /v3/orders.json с фильтром lastUpdate)
 * для каждого активного магазина с настроенными учётными данными ДЛ. Если
 * статус заявки изменился и есть правило dl_to_insales — ставит задачу в
 * очередь automation_jobs (тип update_insales_status), которую обработает
 * automation_worker.php.
 *
 * Почему опрос, а не вебхуки: на стороне ДЛ существует раздел
 * "Уведомления о событиях" (/v1/webhooks/events), но эндпоинт подписки
 * (/v1/webhooks/subscriptions или аналог) не подтверждён в официальной
 * документации и не тестировался — поэтому выбран инкрементальный опрос
 * через параметр lastUpdate, который ДЛ явно поддерживает. При появлении
 * официальных вебхуков ДЛ этот поллер можно выключить.
 *
 * Crontab (пример — каждые 5 минут достаточно для большинства сценариев):
 *   * /5 * * * * /usr/bin/php /var/www/insales-integration/insales-shipping-bridge/bin/dellin_status_poller.php >> /var/log/bridge/dellin_poller.log 2>&1
 */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use ShippingBridge\AutomationJobRepository;
use ShippingBridge\AutomationRuleRepository;
use ShippingBridge\CarrierApi;
use ShippingBridge\CarrierCredentials;
use ShippingBridge\Config;
use ShippingBridge\Db;
use ShippingBridge\SecretStore;
use ShippingBridge\ShopRepository;
use ShippingBridge\SubscriptionRepository;

$config = Config::fromEnvForInsales();
$pdo = Db::pdo($config);
$shopRepo = new ShopRepository($pdo);
$jobRepo = new AutomationJobRepository($pdo);
$ruleRepo = new AutomationRuleRepository($pdo);
$subRepo = new SubscriptionRepository($pdo);
$api = new CarrierApi($config);

$shops = $shopRepo->findAllWithCarrierCreds();
if (empty($shops)) {
    exit(0);
}

foreach ($shops as $shop) {
    $shopId = $shop['insales_id'];

    // Тариф "Автоматизация" — только для него имеет смысл поллить и ставить
    // задачи. Это не жёсткая блокировка (задача всё равно проверит тариф
    // перед выполнением), но экономит лишние запросы к API ДЛ.
    if (!$subRepo->hasAtLeast($shopId, SubscriptionRepository::PLAN_AUTOMATION)) {
        continue;
    }

    // Нет правил dl_to_insales — пропускаем этот магазин.
    $rules = $ruleRepo->findByDirection($shopId, AutomationRuleRepository::DIRECTION_DL_TO_INSALES);
    $enabledRules = array_filter($rules, static fn(array $r): bool => $r['enabled']);
    if (empty($enabledRules)) {
        continue;
    }

    try {
        $creds = new CarrierCredentials(
            $shop['dellin_appkey'],
            SecretStore::decrypt($shop['dellin_pat_enc'], $config->bridgeSecret),
        );
    } catch (\Throwable $e) {
        echo date('[H:i:s]') . " [WARN] shop={$shopId} cannot decrypt PAT: {$e->getMessage()}" . PHP_EOL;
        continue;
    }

    // Инкрементальный курсор: если не было ни одного опроса, берём сутки назад
    // (разумный начальный горизонт — не заваливаем очередь всей историей).
    $cursor = $shop['dellin_status_poll_cursor']
        ?? date('Y-m-d H:i', time() - 86400);

    try {
        $orders = $api->pollOrdersLog($creds, $cursor);
    } catch (\Throwable $e) {
        echo date('[H:i:s]') . " [ERR] shop={$shopId} poll failed: {$e->getMessage()}" . PHP_EOL;
        continue;
    }

    $queued = 0;
    foreach ($orders as $order) {
        $dlState = $order['state'];
        $dlOrderId = $order['orderId']; // это orderNumber ДЛ в нашей схеме
        if ($dlState === '' || $dlOrderId === '') {
            continue;
        }

        // Ищем наш внутренний insales_order_id по barcode/request_id.
        // orderId из журнала ДЛ соответствует dellin_request_id в нашей таблице.
        $findStmt = $pdo->prepare('
            SELECT insales_order_id FROM dellin_orders
            WHERE insales_shop_id = :shop AND dellin_request_id = :rid
            LIMIT 1
        ');
        $findStmt->execute(['shop' => $shopId, 'rid' => $dlOrderId]);
        $insalesOrderId = $findStmt->fetchColumn();
        if ($insalesOrderId === false) {
            // Заявка оформлена в ДЛ, но не через наше приложение, или ещё
            // не записана — пропускаем без шума.
            continue;
        }
        $insalesOrderId = (string) $insalesOrderId;

        // Найти правило для этого статуса ДЛ.
        $action = $ruleRepo->findAction($shopId, AutomationRuleRepository::DIRECTION_DL_TO_INSALES, $dlState);
        if ($action === null) {
            continue;
        }

        // action — это custom_status_permalink inSales, который нужно проставить.
        $jobRepo->enqueue($shopId, $insalesOrderId, 'update_insales_status', [
            'custom_status_permalink' => $action,
            'dl_state' => $dlState,
        ]);
        $queued++;
    }

    // Сдвигаем курсор на текущее время — в следующий запуск возьмём только свежие изменения.
    $newCursor = date('Y-m-d H:i');
    $shopRepo->updateDellinPollCursor($shopId, $newCursor);

    if (!empty($orders)) {
        echo date('[H:i:s]') . " shop={$shopId} polled=" . count($orders) . " queued={$queued} cursor={$newCursor}" . PHP_EOL;
    }
}