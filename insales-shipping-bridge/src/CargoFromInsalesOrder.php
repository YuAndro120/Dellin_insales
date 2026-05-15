<?php

declare(strict_types=1);

namespace ShippingBridge;

/**
 * Сборка груза из позиций заказа checkout inSales (вес в кг, dimensions в см).
 */
final class CargoFromInsalesOrder
{
    /**
     * @param list<array{quantity:int,weight:float,dimensions:string}> $lines
     * @return array{weight:float,volume:float,length:float,width:float,height:float,quantity:int,stated_value:float}
     */
    public static function aggregate(array $lines, ?ShopSettings $defaults = null): array
    {
        if ($lines === []) {
            throw new \InvalidArgumentException('order_lines required');
        }

        $fallbackWeight = $defaults?->defaultWeightKg ?? 1.0;
        $fallbackDims = $defaults?->defaultDimensionsCm ?? '20x20x20';
        $fallbackStated = $defaults?->defaultStatedValue ?? 0.0;

        $totalWeight = 0.0;
        $totalVolume = 0.0;
        $maxL = 0.01;
        $maxW = 0.01;
        $maxH = 0.01;

        foreach ($lines as $line) {
            $qty = max(1, (int) $line['quantity']);
            $w = $line['weight'] > 0 ? $line['weight'] : $fallbackWeight;
            $dimsRaw = trim($line['dimensions']);
            [$l, $wd, $h] = $dimsRaw !== ''
                ? self::parseDimensionsCmToMeters($dimsRaw)
                : self::parseDimensionsCmToMeters($fallbackDims);

            $totalWeight += $w * $qty;
            $totalVolume += $l * $wd * $h * $qty;
            $maxL = max($maxL, $l);
            $maxW = max($maxW, $wd);
            $maxH = max($maxH, $h);
        }

        if ($totalWeight < 0.01) {
            $totalWeight = 0.01;
        }
        if ($totalVolume < 0.000001) {
            $totalVolume = 0.01;
        }

        return [
            'weight' => round($totalWeight, 3),
            'volume' => round($totalVolume, 4),
            'length' => round($maxL, 2),
            'width' => round($maxW, 2),
            'height' => round($maxH, 2),
            'quantity' => 1,
            'stated_value' => round(max(0.0, $fallbackStated), 2),
        ];
    }

    /** @return array{0:float,1:float,2:float} */
    private static function parseDimensionsCmToMeters(string $raw): array
    {
        $raw = trim(str_replace(['х', 'Х', '×', ' '], ['x', 'x', 'x', ''], $raw));
        if ($raw === '' || !str_contains($raw, 'x')) {
            return [0.2, 0.2, 0.2];
        }
        $parts = array_map('trim', explode('x', strtolower($raw)));
        $nums = [];
        foreach ($parts as $p) {
            $nums[] = max(0.1, (float) str_replace(',', '.', preg_replace('/[^0-9.,\-]/', '', $p)));
        }
        while (count($nums) < 3) {
            $nums[] = 10.0;
        }
        sort($nums);

        return [$nums[2] / 100.0, $nums[1] / 100.0, $nums[0] / 100.0];
    }
}
