<?php

declare(strict_types=1);

namespace AdminPanel;

final class LogReader
{
    public function __construct(private readonly string $logDir)
    {
    }

    /** @return list<string> доступные даты (имена файлов без .log), новые сначала */
    public function availableDates(): array
    {
        if (!is_dir($this->logDir)) {
            return [];
        }
        $files = glob($this->logDir . '/*.log') ?: [];
        $dates = [];
        foreach ($files as $f) {
            $dates[] = basename($f, '.log');
        }
        rsort($dates);
        return $dates;
    }

    /**
     * @return list<array{time:string,level:string,shop:string,order:string,event:string,raw:string}>
     */
    public function readDate(string $date, string $shopFilter = '', string $levelFilter = '', int $limit = 500): array
    {
        $date = preg_replace('/[^0-9\-]/', '', $date) ?? '';
        $path = $this->logDir . '/' . $date . '.log';
        if ($date === '' || !is_file($path)) {
            return [];
        }

        $lines = [];
        $fh = fopen($path, 'r');
        if ($fh === false) {
            return [];
        }

        while (($line = fgets($fh)) !== false) {
            $parsed = self::parseLine($line);
            if ($parsed === null) {
                continue;
            }
            if ($shopFilter !== '' && $parsed['shop'] !== $shopFilter) {
                continue;
            }
            if ($levelFilter !== '' && strcasecmp($parsed['level'], $levelFilter) !== 0) {
                continue;
            }
            $lines[] = $parsed;
        }
        fclose($fh);

        // Последние записи первыми
        $lines = array_reverse($lines);
        return array_slice($lines, 0, $limit);
    }

    /**
     * @return array{time:string,level:string,shop:string,order:string,event:string,raw:string}|null
     */
    private static function parseLine(string $line): ?array
    {
        $line = rtrim($line, "\r\n");
        if ($line === '') {
            return null;
        }

        // [HH:MM:SS] [LEVEL] shop=X order=Y event=Z ...
        if (!preg_match('/^\[(\d{2}:\d{2}:\d{2})\]\s+\[(\w+)\]\s+shop=(\S+)\s+order=(\S+)\s+event=(\S+)(.*)$/u', $line, $m)) {
            return [
                'time' => '',
                'level' => 'UNKNOWN',
                'shop' => '',
                'order' => '',
                'event' => '',
                'raw' => $line,
            ];
        }

        return [
            'time' => $m[1],
            'level' => $m[2],
            'shop' => $m[3],
            'order' => $m[4],
            'event' => $m[5],
            'raw' => $line,
        ];
    }
}
