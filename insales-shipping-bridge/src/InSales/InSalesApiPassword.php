<?php

declare(strict_types=1);

namespace ShippingBridge\InSales;

/**
 * Пароль Basic Auth к JSON API магазина: MD5(token + secret приложения).
 *
 * @see https://www.insales.ru/collection/doc-rabota-s-api-i-prilozheniya/product/kak-integrirovatsya-s-insales
 */
final class InSalesApiPassword
{
    public static function compute(string $installToken, string $appSecret): string
    {
        $installToken = trim($installToken);
        if ($installToken === '') {
            throw new \InvalidArgumentException('Пустой token установки');
        }
        if (trim($appSecret) === '') {
            throw new \InvalidArgumentException('INSALES_APP_SECRET не задан');
        }

        return md5($installToken . $appSecret);
    }

    /** Из полного URL редиректа или сырого token. */
    public static function parseInstallToken(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (preg_match('/[?&]token=([^&]+)/i', $raw, $m) === 1) {
            return rawurldecode($m[1]);
        }

        return $raw;
    }
}
