<?php

declare(strict_types=1);

namespace ShippingBridge;

/** Учётные данные Dellin для запросов к API. */
final class CarrierCredentials
{
    public function __construct(
        public readonly string $appkey,
        public readonly string $pat,
    ) {
    }

    public function isComplete(): bool
    {
        return $this->appkey !== '' && $this->pat !== '';
    }
}
