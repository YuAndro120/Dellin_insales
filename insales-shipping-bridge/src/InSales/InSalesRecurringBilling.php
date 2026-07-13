<?php

declare(strict_types=1);

namespace ShippingBridge\InSales;

/**
 * Обёртка над нативным биллингом inSales для приложений маркетплейса.
 *
 * ИТОГ ПРОВЕРКИ НА РЕАЛЬНОМ МАГАЗИНЕ (2026-07): RecurringApplicationCharge
 * (singleton-эндпоинт /admin/recurring_application_charge.json) хоть и
 * отвечает 200 с полями monthly/paid_till/blocked, но НЕ создаёт никакого
 * видимого мерчанту счёта и не запускает реальную оплату — то есть деньги
 * по факту не двигаются. Использовать его для прод-биллинга нельзя, методы
 * ниже оставлены для истории/на случай если саппорт inSales прояснит, как
 * заставить его работать по-настоящему.
 *
 * АКТИВНЫЙ РАБОЧИЙ СПОСОБ — ApplicationCharge (разовый счёт с реальным
 * confirmation_url, куда попадает мерчант, и статусом pending → accepted/
 * declined, который можно проверить через GET). Полностью подтверждено по
 * официальной документации api.insales.ru:
 *   POST   /admin/application_charges.json
 *   GET    /admin/application_charges.json
 *   GET    /admin/application_charges/:id.json
 *   POST   /admin/application_charges/:id/decline.json
 * Тело: {"application_charge": {"name": "...", "price": 999, "return_url": "...", "test": true|false}}
 * Ответ содержит "confirmation_url" — туда редиректим мерчанта; после его
 * подтверждения статус меняется с "pending" на "accepted" (оплачено) или
 * "declined" (отклонено).
 *
 * ⚠️ Единственное, что не подтверждено на 100% про ApplicationCharge:
 * буквальное значение статуса при успешной оплате — в документации явно
 * показаны только "pending" и "declined", "accepted" — по аналогии со
 * старым Shopify API (откуда явно списан весь дизайн этого API). Если на
 * практике статус после оплаты окажется другим словом — поправьте константу
 * PAID_STATUSES ниже и всё остальное продолжит работать.
 *
 * Официальные источники:
 * @see https://liquidhub.ru/page/razrabotka-prilozheniy-dlya-marketpleysa-insales
 * @see https://api.insales.ru/?doc_format=JSON#application-charge
 */
final class InSalesRecurringBilling
{
    /** @see class docblock — единственное неподтверждённое буквально значение. */
    private const PAID_STATUSES = ['accepted', 'paid'];

    public function __construct(private readonly InSalesClient $client) {}

    /**
     * Устанавливает/меняет ежемесячную сумму подписки магазина в личном
     * кабинете inSales. inSales сам будет списывать эту сумму каждый
     * период с баланса/карты магазина — деньги приходят вам как разработчику
     * приложения (комиссионная модель Центра Приложений).
     *
     * @return array{monthly:string,trial_expired_at:?string,paid_till:?string,blocked:bool}
     */
    public function setMonthlyCharge(
        string $shopHost,
        string $applicationLogin,
        string $apiPasswordMd5,
        float $monthlyPrice,
        ?int $trialDays = null,
    ): array {
        $payload = [
            'recurring_application_charge' => array_filter([
                'monthly'    => $monthlyPrice,
                'trial_days' => $trialDays,
            ], static fn($v) => $v !== null),
        ];

        // Пытаемся обновить существующую подписку (PUT); если её ещё нет —
        // создаём (POST). inSales возвращает 404/422 на PUT без объекта —
        // в этом случае откатываемся на create.
        try {
            return $this->normalize($this->client->putJson(
                $shopHost,
                $applicationLogin,
                $apiPasswordMd5,
                '/admin/recurring_application_charge.json',
                $payload,
            ));
        } catch (\RuntimeException $e) {
            return $this->normalize($this->client->postJson(
                $shopHost,
                $applicationLogin,
                $apiPasswordMd5,
                '/admin/recurring_application_charge.json',
                $payload,
            ));
        }
    }

    /**
     * Текущее состояние подписки магазина в inSales — используйте это как
     * источник истины при входе пользователя в приложение (аналог вашего
     * effectivePlan() в SubscriptionRepository), т.к. явных вебхуков на
     * изменение биллинга inSales может не присылать.
     *
     * @return array{monthly:string,trial_expired_at:?string,paid_till:?string,blocked:bool}|null
     */
    public function getCurrentCharge(
        string $shopHost,
        string $applicationLogin,
        string $apiPasswordMd5,
    ): ?array {
        try {
            $res = $this->client->getJsonPath(
                $shopHost,
                $applicationLogin,
                $apiPasswordMd5,
                '/admin/recurring_application_charge.json',
            );
        } catch (\RuntimeException $e) {
            return null;
        }
        return $res === [] ? null : $this->normalize($res);
    }

    /** Отменяет подписку (магазин перестаёт списываться). */
    public function cancel(
        string $shopHost,
        string $applicationLogin,
        string $apiPasswordMd5,
    ): void {
        $this->client->deleteJson(
            $shopHost,
            $applicationLogin,
            $apiPasswordMd5,
            '/admin/recurring_application_charge.json',
        );
    }

    /**
     * Продлевает бесплатный период (например, в качестве промо/компенсации).
     * ⚠️ Путь и имя параметра "days" — вывод из якоря документации, ТРЕБУЕТ
     * проверки перед использованием в проде (см. комментарий класса выше).
     */
    public function addFreeDays(
        string $shopHost,
        string $applicationLogin,
        string $apiPasswordMd5,
        int $days,
    ): array {
        return $this->normalize($this->client->postJson(
            $shopHost,
            $applicationLogin,
            $apiPasswordMd5,
            '/admin/recurring_application_charge/add_free_days.json',
            ['days' => $days],
        ));
    }

    /**
     * Разовый счёт (ApplicationCharge) — если нужен разовый платёж, а не
     * подписка. Полностью подтверждено по официальной документации.
     * После создания редиректите пользователя на $result['confirmation_url'].
     *
     * @return array{id:int,name:string,price:string,status:string,confirmation_url:string}
     */
    public function createOneTimeCharge(
        string $shopHost,
        string $applicationLogin,
        string $apiPasswordMd5,
        string $name,
        float $price,
        string $returnUrl,
        bool $test = false,
    ): array {
        $res = $this->client->postJson(
            $shopHost,
            $applicationLogin,
            $apiPasswordMd5,
            '/admin/application_charges.json',
            ['application_charge' => [
                'name'       => $name,
                'price'      => $price,
                'return_url' => $returnUrl,
                'test'       => $test,
            ]],
        );
        return $this->normalizeCharge($res);
    }

    /**
     * Получить один счёт по id — используется при возврате мерчанта с
     * confirmation_url, чтобы проверить итоговый статус через прямой
     * server-to-server вызов (а не доверять параметрам в URL редиректа,
     * которые в принципе можно подделать).
     *
     * @return array{id:int,name:string,price:string,status:string,confirmation_url:string}|null
     */
    public function getOneTimeCharge(
        string $shopHost,
        string $applicationLogin,
        string $apiPasswordMd5,
        int $chargeId,
    ): ?array {
        try {
            $res = $this->client->getJsonPath(
                $shopHost,
                $applicationLogin,
                $apiPasswordMd5,
                "/admin/application_charges/{$chargeId}.json",
            );
        } catch (\RuntimeException $e) {
            return null;
        }
        return $res === [] ? null : $this->normalizeCharge($res);
    }

    /**
     * Список всех разовых счетов магазина (API уже сам скоупит по магазину
     * через Basic-авторизацию — чужие счета сюда не попадут). Используется,
     * когда id счёта неизвестен (например, потеряли его между запросом и
     * возвратом пользователя) — берём последний по created_at.
     *
     * @return list<array{id:int,name:string,price:string,status:string,confirmation_url:string}>
     */
    public function listOneTimeCharges(
        string $shopHost,
        string $applicationLogin,
        string $apiPasswordMd5,
    ): array {
        $res = $this->client->getJsonPath($shopHost, $applicationLogin, $apiPasswordMd5, '/admin/application_charges.json');
        $items = is_array($res) && array_is_list($res) ? $res : ($res['application_charges'] ?? []);
        $out = [];
        foreach ((array) $items as $item) {
            if (is_array($item)) {
                $out[] = $this->normalizeCharge($item);
            }
        }
        return $out;
    }

    /** Считаем ли статус счёта "оплачено" — см. PAID_STATUSES в шапке класса. */
    public function isPaid(array $charge): bool
    {
        return in_array($charge['status'], self::PAID_STATUSES, true);
    }

    /** @return array{id:int,name:string,price:string,status:string,confirmation_url:string} */
    private function normalizeCharge(array $res): array
    {
        return [
            'id'               => (int) ($res['id'] ?? 0),
            'name'             => (string) ($res['name'] ?? ''),
            'price'            => (string) ($res['price'] ?? ''),
            'status'           => (string) ($res['status'] ?? ''),
            'confirmation_url' => (string) ($res['confirmation_url'] ?? ''),
        ];
    }

    /**
     * @return array{monthly:string,trial_expired_at:?string,paid_till:?string,blocked:bool,raw:array<string,mixed>}
     */
    private function normalize(array $res): array
    {
        return [
            'monthly'          => (string) ($res['monthly'] ?? '0'),
            'trial_expired_at' => isset($res['trial_expired_at']) ? (string) $res['trial_expired_at'] : null,
            'paid_till'        => isset($res['paid_till']) ? (string) $res['paid_till'] : null,
            'blocked'          => (bool) ($res['blocked'] ?? false),
            'raw'              => $res, // сырой ответ целиком — для логирования/диагностики
        ];
    }
}