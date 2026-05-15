<?php

declare(strict_types=1);

namespace ShippingBridge;

/**
 * Сборка параметров груза для калькулятора перевозчика из строк inSales (вес + строка dimensions).
 * В inSales часто dimensions в см в виде "ДxШxВ"; вес — в кг.
 * Сводка по нескольким позициям (MVP): сумма масс, сумма объёмов, по осям — максимум из габаритов позиций.
 *
 * Swagger перевозчика: https://dev.dellin.ru/api/swagger/
 */
final class CargoFromVariants
{
    /**
     * @param list<array{variant: array<string,mixed>, quantity: int}> $lines
     * @return array{weight:float,volume:float,length:float,width:float,height:float,quantity:int,stated_value:float}
     */
    public static function aggregate(array $lines, ?ShopSettings $defaults = null): array
    {
        if ($lines === []) {
            throw new \InvalidArgumentException('No lines');
        }

        $fallbackWeight = $defaults?->defaultWeightKg ?? 1.0;
        $fallbackDims = $defaults?->defaultDimensionsCm ?? '20x20x20';
        $fallbackStated = $defaults?->defaultStatedValue ?? 0.0;

        $totalWeight = 0.0;
        $totalVolume = 0.0;
        $maxL = 0.01;
        $maxW = 0.01;
        $maxH = 0.01;

        foreach ($lines as $row) {
            $qty = max(1, (int) ($row['quantity'] ?? 1));
            $v = $row['variant'] ?? [];
            if (!is_array($v)) {
                continue;
            }
            $wRaw = trim((string) ($v['weight'] ?? ''));
            $dimsRaw = trim((string) ($v['dimensions'] ?? ''));
            $w = $wRaw !== '' ? self::parseWeight($wRaw) : $fallbackWeight;
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

    private static function parseWeight(string $raw): float
    {
        $raw = str_replace(',', '.', trim($raw));
        $v = (float) preg_replace('/[^0-9.\-]/', '', $raw);
        return $v > 0 ? $v : 0.01;
    }

    /**
     * @return array{0:float,1:float,2:float} метры
     */
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
        $hM = $nums[0] / 100.0;
        $wM = $nums[1] / 100.0;
        $lM = $nums[2] / 100.0;

        return [$lM, $wM, $hM];
    }
}
