#!/usr/bin/env php
<?php

/**
 * Воркер очереди задач автоматизации.
 *
 * Запускать по cron (один раз в минуту рекомендуется) или несколько
 * инстансов параллельно — SKIP LOCKED в AutomationJobRepository::claimBatch
 * гарантирует, что две копии воркера не возьмут одну задачу.
 *
 * Crontab (пример):
 *   * * * * * /usr/bin/php /var/www/insales-integration/insales-shipping-bridge/bin/automation_worker.php >> /var/log/bridge/automation_worker.log 2>&1
 *
 * Каждый запуск забирает до 20 задач и обрабатывает их последовательно.
 * При сбое одной задачи — retry с exponential backoff (до 8 попыток,
 * пауза удваивается от 30с до 1 часа между попытками).
 * При исчерпании попыток — status = 'failed', ошибка сохранена в last_error.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use ShippingBridge\AutomationJobRepository;
use ShippingBridge\Config;
use ShippingBridge\Db;
use ShippingBridge\InSales\InSalesClient;
use ShippingBridge\InSales\OrderSubmitHandler;
use ShippingBridge\ShopRepository;

$config = Config::fromEnvForInsales();
$pdo = Db::pdo($config);
$jobs = new AutomationJobRepository($pdo);
$shops = new ShopRepository($pdo);

$batch = $jobs->claimBatch(20);

if (empty($batch)) {
    exit(0);
}

foreach ($batch as $job) {
    $jobId = $job['id'];
    $shopId = $job['insales_shop_id'];
    $orderId = $job['insales_order_id'];
    $attempts = $job['attempts'];
    $maxAttempts = $job['max_attempts'];

    try {
        if ($job['job_type'] === 'create_dl_order') {
            $result = OrderSubmitHandler::submitAutomated($config, $shops, $shopId, $orderId);
            if (!$result['ok']) {
                throw new \RuntimeException($result['error'] ?? 'Unknown error from submitAutomated');
            }
            $jobs->markDone($jobId);
            echo date('[H:i:s]') . " [DONE] job#{$jobId} shop={$shopId} order={$orderId} request_id=" . ($result['request_id'] ?? '?') . PHP_EOL;

        } elseif ($job['job_type'] === 'update_insales_status') {
            $permalink = (string) ($job['payload']['custom_status_permalink'] ?? '');
            if ($permalink === '') {
                throw new \RuntimeException('update_insales_status: missing custom_status_permalink in payload');
            }
            $auth = $shops->findApiAuthByInsalesId($shopId);
            if ($auth === null) {
                throw new \RuntimeException('Нет данных авторизации для магазина ' . $shopId);
            }
            $client = new InSalesClient();
            $client->setOrderCustomStatus(
                $auth['shop_host'],
                $config->insalesAppId ?? '',
                $auth['api_password'],
                (int) $orderId,
                $permalink,
            );
            $jobs->markDone($jobId);
            echo date('[H:i:s]') . " [DONE] job#{$jobId} shop={$shopId} order={$orderId} status={$permalink}" . PHP_EOL;

        } else {
            // Неизвестный тип задачи — помечаем как окончательно failed
            // (нет смысла ретраить то, что не умеем обрабатывать).
            $jobs->markFailed($jobId, $attempts, 1, 'Unknown job type: ' . $job['job_type']);
            echo date('[H:i:s]') . " [SKIP] job#{$jobId} unknown type={$job['job_type']}" . PHP_EOL;
        }
    } catch (\Throwable $e) {
        $jobs->markFailed($jobId, $attempts, $maxAttempts, $e->getMessage());
        echo date('[H:i:s]') . " [FAIL] job#{$jobId} shop={$shopId} order={$orderId} attempt=" . ($attempts + 1) . "/{$maxAttempts} error=" . $e->getMessage() . PHP_EOL;
    }
}