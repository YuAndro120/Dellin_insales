<?php

declare(strict_types=1);

namespace ShippingBridge;

/** Параметры расчёта для конкретного магазина (передаются в CarrierApi). */
final class CalculatorContext
{
    public function __construct(
        public readonly string $requesterEmail,
        public readonly ?string $counteragentUid,
        public readonly int $produceDaysOffset,
    ) {
    }

    public static function fromShopSettings(ShopSettings $s): self
    {
        return new self(
            requesterEmail: $s->requesterEmail,
            counteragentUid: $s->counteragentUid,
            produceDaysOffset: $s->produceDaysOffset,
        );
    }
}
