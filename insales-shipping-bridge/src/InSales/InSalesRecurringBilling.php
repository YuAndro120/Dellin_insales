<?php

declare(strict_types=1);

namespace ShippingBridge\InSales;

/**
 * Обёртка над нативным биллингом inSales для приложений маркетплейса.
 *
 * inSales предоставляет ДВА независимых механизма монетизации (см. README
 * партнёрской документации: liquidhub.ru/page/razrabotka-prilozheniy-dlya-marketpleysa-insales,
 * раздел "Если вы планируете монетизировать своё приложение и использовать
 * биллинг InSales"):
 *
 *  1) ApplicationCharge — разовый счёт с редиректом на подтверждение
 *     (аналог Stripe Checkout / Shopify ApplicationCharge). Полностью
 *     подтверждено по официальной документации api.insales.ru:
 *       POST   /admin/application_charges.json
 *       GET    /admin/application_charges.json
 *       GET    /admin/application_charges/:id.json
 *       POST   /admin/application_charges/:id/decline.json
 *     Тело: {"application_charge": {"name": "...", "price": 999, "return_url": "...", "test": true|false}}
 *     Ответ содержит "confirmation_url" — туда редиректим пользователя,
 *     после подтверждения магазин получает статус "pending" -> оплачивается
 *     мерчантом в личном кабинете inSales, статус меняется на "accepted"/"declined".
 *
 *  2) RecurringApplicationCharge — ЕДИНСТВЕННАЯ на магазин подписка
 *     (не список тарифов с id, а один объект "текущая подписка"). Это
 *     подтверждено:
 *       а) старым XML/legacy клиентом (github.com/nkrkv/pyinsales, insales/lib/api/recurringcharge.js) —
 *          singleton-эндпоинт /admin/recurring_application_charge.xml (GET/POST/PUT)
 *       б) актуальным JSON API (api.insales.ru, secции RecurringApplicationCharge:
 *          Create/Destroy/Get/Update/"add free days") — те же реальные ответы через
 *          sandbox api.insales.ru/simulate/recurringapplicationcharge/*, которые я
 *          вызвал напрямую и получил вот такую форму объекта:
 *            {"monthly": "999.0", "trial_expired_at": "2026-07-18",
 *             "paid_till": "2026-07-18", "blocked": false,
 *             "created_at": "...", "updated_at": "..."}
 *          т.е. НЕТ id, НЕТ name, НЕТ confirmation_url — это не Shopify-подобный
 *          флоу с подтверждением, а прямое выставление ежемесячной суммы,
 *          которую inSales списывает с баланса/карты магазина в личном
 *          кабинете inSales самостоятельно (аналогично их встроенным тарифам
 *          на "Приложения" в Центре Приложений).
 *
 * ВАЖНО — что НЕ подтверждено на 100% и требует проверки перед продакшеном:
 *   - точное имя поля для создания (использую "monthly" по аналогии с ответом
 *     и legacy XML-клиентом; альтернатива — "price");
 *   - есть ли поле для начального триала при создании (использую "trial_days";
 *     не увидел его в реальном ответе simulate, поэтому НЕ полагайтесь на него
 *     вслепую — тестируйте на sandbox-магазине, там же есть флаг test у
 *     ApplicationCharge, но для RecurringApplicationCharge я не нашёл
 *     подтверждения поддержки test-режима);
 *   - точный путь action'а "add free days" (использую
 *     /admin/recurring_application_charge/add_free_days.json — выведено из
 *     фрагмента якоря официальной документации
 *     "recurringapplicationcharge-add-free-days-to-recurring-application-charge-json",
 *     но URL руками я не confirмed).
 * Перед тем как пускать это в прод — сделайте один тестовый вызов create()
 * на тестовом магазине партнёрской программы (insales.ru/partnership) и
 * посмотрите реальный запрошенный/ответный JSON, либо уточните у саппорта
 * inSales через тикет в личном кабинете партнёра.
 *
 * Официальные источники:
 * @see https://liquidhub.ru/page/razrabotka-prilozheniy-dlya-marketpleysa-insales
 * @see https://api.insales.ru/?doc_format=JSON#recurring-application-charge
 * @see https://api.insales.ru/?doc_format=JSON#application-charge
 */
final class InSalesRecurringBilling
{
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
        return [
            'id'               => (int) ($res['id'] ?? 0),
            'name'             => (string) ($res['name'] ?? ''),
            'price'            => (string) ($res['price'] ?? ''),
            'status'           => (string) ($res['status'] ?? ''),
            'confirmation_url' => (string) ($res['confirmation_url'] ?? ''),
        ];
    }

    /** @return array{monthly:string,trial_expired_at:?string,paid_till:?string,blocked:bool} */
    private function normalize(array $res): array
    {
        return [
            'monthly'          => (string) ($res['monthly'] ?? '0'),
            'trial_expired_at' => isset($res['trial_expired_at']) ? (string) $res['trial_expired_at'] : null,
            'paid_till'        => isset($res['paid_till']) ? (string) $res['paid_till'] : null,
            'blocked'          => (bool) ($res['blocked'] ?? false),
        ];
    }
}