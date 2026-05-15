<?php

declare(strict_types=1);

namespace ShippingBridge;

/**
 * КЛАДР получателя: из запроса или из справочника терминалов по terminal_id.
 */
final class ArrivalKladrResolver
{
    public function __construct(
        private readonly TerminalRepository $terminals,
    ) {
    }

    public function resolve(?string $fromRequest, int $terminalId): ?string
    {
        $kladr = trim((string) $fromRequest);
        if ($kladr !== '') {
            return $kladr;
        }
        if ($terminalId <= 0) {
            return null;
        }

        return $this->terminals->findCityKladrByTerminalId($terminalId);
    }
}
