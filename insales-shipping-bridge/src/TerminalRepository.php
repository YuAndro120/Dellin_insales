<?php

declare(strict_types=1);

namespace ShippingBridge;

/**
 * Кэш полного справочника терминалов оператора и выборка точек для карты (bbox / префикс КЛАДР).
 */
final class TerminalRepository
{
    private const CACHE_NAME = 'terminals_dataset.json';
    private const CACHE_TTL = 43200;

    public function __construct(
        private readonly Config $config,
        private readonly CarrierApi $api,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function getPoints(?string $cityKladrPrefix, ?float $swLat, ?float $swLon, ?float $neLat, ?float $neLon, int $limit = 500): array
    {
        $dataset = $this->loadDataset();
        $citiesRaw = $dataset['city'] ?? [];
        if (!is_array($citiesRaw)) {
            return [];
        }
        $cities = array_is_list($citiesRaw) ? $citiesRaw : array_values($citiesRaw);

        $out = [];
        foreach ($cities as $city) {
            if (!is_array($city)) {
                continue;
            }
            $cityCode = (string) ($city['code'] ?? '');
            if ($cityKladrPrefix !== null && $cityKladrPrefix !== '' && !str_starts_with($cityCode, $cityKladrPrefix)) {
                continue;
            }
            $cityName = (string) ($city['name'] ?? '');
            $cityLat = isset($city['latitude']) ? (float) $city['latitude'] : null;
            $cityLon = isset($city['longitude']) ? (float) $city['longitude'] : null;

            $terminals = $city['terminals']['terminal'] ?? $city['terminals'] ?? null;
            $list = $this->normalizeTerminalList($terminals);
            foreach ($list as $t) {
                if (!is_array($t)) {
                    continue;
                }
                $id = (int) ($t['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                [$lat, $lon] = $this->resolveCoords($t, $cityLat, $cityLon);
                if ($lat === null || $lon === null) {
                    continue;
                }
                if ($swLat !== null && $swLon !== null && $neLat !== null && $neLon !== null) {
                    if ($lat < $swLat || $lat > $neLat || $lon < $swLon || $lon > $neLon) {
                        continue;
                    }
                }
                $out[] = [
                    'id' => $id,
                    'name' => (string) ($t['name'] ?? $t['title'] ?? 'Пункт выдачи'),
                    'address' => (string) ($t['address'] ?? $t['fullAddress'] ?? ''),
                    'lat' => $lat,
                    'lng' => $lon,
                    'city' => $cityName,
                    'city_kladr' => $cityCode,
                ];
                if (count($out) >= $limit) {
                    return $out;
                }
            }
        }

        return $out;
    }

    /**
     * Поиск терминалов для выбора в настройках (по префиксу КЛАДР и/или тексту).
     *
     * @return list<array<string,mixed>>
     */
    public function search(?string $cityKladrPrefix, ?string $textQuery, int $limit = 100): array
    {
        $scanLimit = min(max($limit * 5, 200), 3000);
        $points = $this->getPoints($cityKladrPrefix, null, null, null, null, $scanLimit);
        if ($textQuery === null || $textQuery === '') {
            return array_slice($points, 0, $limit);
        }

        $needle = mb_strtolower($textQuery);
        $filtered = [];
        foreach ($points as $t) {
            $hay = mb_strtolower(
                ($t['name'] ?? '') . ' ' . ($t['address'] ?? '') . ' ' . ($t['city'] ?? '') . ' ' . ($t['id'] ?? '')
            );
            if (str_contains($hay, $needle)) {
                $filtered[] = $t;
                if (count($filtered) >= $limit) {
                    break;
                }
            }
        }

        return $filtered;
    }

    public function invalidateCache(): void
    {
        $path = rtrim($this->config->cacheDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::CACHE_NAME;
        if (is_file($path)) {
            unlink($path);
        }
    }

    /** КЛАДР населённого пункта по ID терминала (из справочника v3). */
    public function findCityKladrByTerminalId(int $terminalId): ?string
    {
        if ($terminalId <= 0) {
            return null;
        }

        $dataset = $this->loadDataset();
        $citiesRaw = $dataset['city'] ?? [];
        if (!is_array($citiesRaw)) {
            return null;
        }
        $cities = array_is_list($citiesRaw) ? $citiesRaw : array_values($citiesRaw);

        foreach ($cities as $city) {
            if (!is_array($city)) {
                continue;
            }
            $cityCode = (string) ($city['code'] ?? '');
            if ($cityCode === '') {
                continue;
            }
            foreach ($this->normalizeTerminalList($city['terminals']['terminal'] ?? $city['terminals'] ?? null) as $t) {
                if (is_array($t) && (int) ($t['id'] ?? 0) === $terminalId) {
                    return $cityCode;
                }
            }
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function loadDataset(): array
    {
        $path = rtrim($this->config->cacheDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::CACHE_NAME;
        if (is_file($path)) {
            $age = time() - (int) filemtime($path);
            if ($age < self::CACHE_TTL) {
                $raw = file_get_contents($path);
                if ($raw !== false) {
                    return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                }
            }
        }

        if (!is_dir($this->config->cacheDir)) {
            mkdir($this->config->cacheDir, 0775, true);
        }

        $manifest = $this->api->terminalsManifest();
        $data = $this->api->fetchTerminalsDataset($manifest['url']);
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE));
        return $data;
    }

    private function normalizeTerminalList(mixed $terminals): array
    {
        if ($terminals === null) {
            return [];
        }
        if (is_array($terminals) && array_is_list($terminals)) {
            return $terminals;
        }
        if (is_array($terminals)) {
            return [$terminals];
        }
        return [];
    }

    /** @return array{0:?float,1:?float} */
    private function resolveCoords(array $t, ?float $cityLat, ?float $cityLon): array
    {
        $lat = null;
        $lon = null;
        foreach (['latitude', 'lat'] as $k) {
            if (isset($t[$k])) {
                $lat = (float) $t[$k];
                break;
            }
        }
        foreach (['longitude', 'lng', 'longtitude'] as $k) {
            if (isset($t[$k])) {
                $lon = (float) $t[$k];
                break;
            }
        }
        if (($lat === null || $lon === null) && isset($t['address']) && is_array($t['address'])) {
            $a = $t['address'];
            if (isset($a['latitude'], $a['longitude'])) {
                $lat = (float) $a['latitude'];
                $lon = (float) $a['longitude'];
            }
        }
        if ($lat === null && $cityLat !== null) {
            $lat = $cityLat;
        }
        if ($lon === null && $cityLon !== null) {
            $lon = $cityLon;
        }
        return [$lat, $lon];
    }
}
