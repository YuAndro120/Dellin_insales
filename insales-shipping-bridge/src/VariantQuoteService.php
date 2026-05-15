<?php

declare(strict_types=1);

namespace ShippingBridge;

use ShippingBridge\InSales\InSalesClient;

/**
 * Загрузка вариантов из inSales и расчёт тарифа через CarrierApi.
 */
final class VariantQuoteService
{
    public function __construct(
        private readonly Config $config,
        private readonly ShopRepository $shops,
        private readonly InSalesClient $insales,
        private readonly CarrierApi $carrier,
    ) {
    }

    /**
     * @param array{shop: string, lines: list<array<string,mixed>>, arrival_terminal_id: int, arrival_city_kladr: string} $body
     * @return array{ok: bool, price?: float|null, currency?: string, days?: int|null, errors?: mixed, cargo?: array, debug_lines?: int}
     */
    public function quoteFromCartLines(array $body): array
    {
        $shop = trim((string) ($body['shop'] ?? ''));
        $terminalId = (int) ($body['arrival_terminal_id'] ?? 0);
        $kladr = (string) ($body['arrival_city_kladr'] ?? '');
        $linesIn = $body['lines'] ?? [];
        if ($shop === '' || $terminalId <= 0 || strlen($kladr) < 10 || !is_array($linesIn) || $linesIn === []) {
            throw new \InvalidArgumentException('shop, lines[], arrival_terminal_id, arrival_city_kladr required');
        }

        $row = $this->shops->findActiveByHost($shop);
        if ($row === null) {
            throw new \RuntimeException('Shop not installed or unknown host: ' . $shop);
        }
        $login = $this->config->insalesAppId ?? '';
        if ($login === '') {
            throw new \RuntimeException('INSALES_APP_ID is not configured');
        }
        $pass = $row['api_password'];

        $resolved = [];
        foreach ($linesIn as $line) {
            if (!is_array($line)) {
                continue;
            }
            $vid = (int) ($line['variant_id'] ?? 0);
            $qty = max(1, (int) ($line['quantity'] ?? 1));
            if ($vid <= 0) {
                continue;
            }
            $pid = isset($line['product_id']) ? (int) $line['product_id'] : 0;
            $pair = $pid > 0
                ? $this->insales->getVariantByProduct($shop, $login, $pass, $pid, $vid)
                : $this->insales->findVariantAcrossProducts($shop, $login, $pass, $vid);
            if ($pair === null) {
                throw new \RuntimeException('Variant not found: ' . $vid);
            }
            $resolved[] = ['variant' => $pair['variant'], 'quantity' => $qty];
        }

        if ($resolved === []) {
            throw new \InvalidArgumentException('No valid lines');
        }

        $cargo = CargoFromVariants::aggregate($resolved);
        $sid = $this->carrier->login();
        $calc = $this->carrier->calculateToTerminal($sid, $terminalId, $kladr, $cargo);

        return [
            'ok' => $calc['price'] !== null,
            'price' => $calc['price'],
            'currency' => 'RUB',
            'days' => $calc['days'],
            'errors' => $calc['errors'] ?? null,
            'cargo' => $cargo,
            'debug_lines' => count($resolved),
        ];
    }
}
