<?php

declare(strict_types=1);

namespace AdminPanel;

/**
 * Читает JSON Lines логи бриджа (/var/log/bridge/YYYY-MM-DD.jsonl).
 * Поддерживает иерархию: день → магазин (с агрегатами) → события (с
 * человекочитаемыми названиями) → полная карточка одного события.
 */
final class LogReader
{
    /** Человекочитаемые названия событий — для отображения вместо сырых event-кодов. */
    private const EVENT_LABELS = [
        'order.create.request' => 'Оформление заявки в ДЛ — запрос',
        'order.create.error' => 'Оформление заявки в ДЛ — ошибка',
        'order.create.no_request_id' => 'Оформление заявки в ДЛ — нет requestID в ответе',
        'order.create.success' => 'Оформление заявки в ДЛ — успешно',
        'calc.city.request' => 'Расчёт (курьер до адреса) — запрос',
        'calc.city.response' => 'Расчёт (курьер до адреса) — ответ',
        'calc.terminal.request' => 'Расчёт (до терминала) — запрос',
        'calc.terminal.response' => 'Расчёт (до терминала) — ответ',
        'calc.terminals_batch.summary' => 'Расчёт по списку ПВЗ — сводка',
        'calc.courier.error' => 'Расчёт (курьер) — ошибка',
        'calc.pickup_point.error' => 'Расчёт (ПВЗ) — ошибка',
        'calc.pickup_points_batch.error' => 'Расчёт по списку ПВЗ — ошибка батча',
        'calc.pickup_point.date_unavailable' => 'Расчёт (ПВЗ) — дата недоступна для терминала',
        'calc.date_fallback.exhausted' => 'Расчёт — не найдена доступная дата за все попытки',
        'settings.save' => 'Сохранение настроек магазина',
        'billing.payment.succeeded' => 'Оплата подписки — успешно',
        'billing.payment.failed' => 'Оплата подписки — ошибка',
        'billing.rebill_id.saved' => 'Сохранён RebillId для автопродления',
        'billing.webhook.invalid_token' => 'Вебхук Т-Банка — неверный токен',
        'billing.webhook.misconfigured' => 'Вебхук Т-Банка — не настроен',
        'billing.webhook.unparseable_order_id' => 'Вебхук Т-Банка — не распознан OrderId',
    ];

    public function __construct(private readonly string $logDir) {}

    /** @return list<string> доступные даты (YYYY-MM-DD), новые сначала */
    public function availableDates(): array
    {
        if (!is_dir($this->logDir)) {
            return [];
        }
        $files = glob($this->logDir . '/*.jsonl') ?: [];
        $dates = [];
        foreach ($files as $f) {
            $dates[] = basename($f, '.jsonl');
        }
        rsort($dates);
        return $dates;
    }

    /**
     * Магазины, упомянутые в логе за дату, с агрегатами.
     * @return list<array{shop:string,total:int,errors:int,last_time:string}>
     */
    public function shopsForDate(string $date): array
    {
        $entries = $this->readRawEntries($date);
        $byShop = [];

        foreach ($entries as $entry) {
            $shop = (string) ($entry['shop'] ?? '-');
            if (!isset($byShop[$shop])) {
                $byShop[$shop] = ['shop' => $shop, 'total' => 0, 'errors' => 0, 'last_time' => ''];
            }
            $byShop[$shop]['total']++;
            if (($entry['level'] ?? '') === 'ERROR') {
                $byShop[$shop]['errors']++;
            }
            $byShop[$shop]['last_time'] = (string) ($entry['time'] ?? $byShop[$shop]['last_time']);
        }

        $list = array_values($byShop);
        usort($list, static fn($a, $b) => strcmp($b['last_time'], $a['last_time']));
        return $list;
    }

    /**
     * События конкретного магазина за дату, новые сначала, с человекочитаемым label.
     * @return list<array{index:int,time:string,level:string,event:string,label:string,order:?string}>
     */
    public function eventsForShop(string $date, string $shop): array
    {
        $entries = $this->readRawEntries($date);
        $out = [];

        foreach ($entries as $i => $entry) {
            if ((string) ($entry['shop'] ?? '-') !== $shop) {
                continue;
            }
            $event = (string) ($entry['event'] ?? '');
            $out[] = [
                'index' => $i,
                'time' => (string) ($entry['time'] ?? ''),
                'level' => (string) ($entry['level'] ?? 'INFO'),
                'event' => $event,
                'label' => self::EVENT_LABELS[$event] ?? $event,
                'order' => $entry['order'] ?? null,
            ];
        }

        usort($out, static fn($a, $b) => strcmp($b['time'], $a['time']));
        return $out;
    }

    /**
     * Полная карточка одного события по индексу строки в файле дня.
     * @return array<string,mixed>|null
     */
    public function eventByIndex(string $date, int $index): ?array
    {
        $entries = $this->readRawEntries($date);
        $entry = $entries[$index] ?? null;
        if ($entry === null) {
            return null;
        }
        $event = (string) ($entry['event'] ?? '');
        $entry['label'] = self::EVENT_LABELS[$event] ?? $event;
        return $entry;
    }

    /** @return list<array<string,mixed>> */
    private function readRawEntries(string $date): array
    {
        $date = preg_replace('/[^0-9\-]/', '', $date) ?? '';
        $path = $this->logDir . '/' . $date . '.jsonl';
        if ($date === '' || !is_file($path)) {
            return [];
        }

        $entries = [];
        $fh = fopen($path, 'r');
        if ($fh === false) {
            return [];
        }
        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $entries[] = $decoded;
            }
        }
        fclose($fh);
        return $entries;
    }
}
