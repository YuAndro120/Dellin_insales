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
        public readonly string $derivalVariant,
        public readonly ?string $derivalCityKladr,
        public readonly ?string $derivalStreet,
        public readonly ?string $derivalHouse,
    ) {
    }

    public static function fromShopSettings(ShopSettings $s): self
    {
        return new self(
            requesterEmail: $s->requesterEmail,
            counteragentUid: $s->counteragentUid,
            produceDaysOffset: $s->produceDaysOffset,
            derivalVariant: $s->derivalVariant,
            derivalCityKladr: $s->derivalCityKladr,
            derivalStreet: $s->derivalStreet,
            derivalHouse: $s->derivalHouse,
        );
    }
}
