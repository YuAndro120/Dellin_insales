<?php

declare(strict_types=1);

namespace ShippingBridge;

/** Контрагент Dellin для UI и калькулятора (uid + отображаемое имя + integer ID). */
final class DellinCounteragent
{
    public function __construct(
        public readonly string $uid,
        public readonly string $name,
        public readonly ?int $counteragentId = null,
    ) {
    }
}
