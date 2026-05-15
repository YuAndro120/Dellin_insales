<?php

declare(strict_types=1);

namespace ShippingBridge\Http;

final class Response
{
    public static function json(mixed $data, int $status = 200, array $extraHeaders = []): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        foreach ($extraHeaders as $h) {
            header($h);
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function corsHeaders(string $origin): array
    {
        if ($origin === '*') {
            return [
                'Access-Control-Allow-Origin: *',
                'Access-Control-Allow-Methods: GET, POST, OPTIONS',
                'Access-Control-Allow-Headers: Content-Type, X-Bridge-Token, Authorization',
                'Access-Control-Max-Age: 86400',
            ];
        }
        return [
            'Access-Control-Allow-Origin: ' . $origin,
            'Access-Control-Allow-Methods: GET, POST, OPTIONS',
            'Access-Control-Allow-Headers: Content-Type, X-Bridge-Token, Authorization',
            'Access-Control-Allow-Credentials: true',
            'Access-Control-Max-Age: 86400',
        ];
    }
}
