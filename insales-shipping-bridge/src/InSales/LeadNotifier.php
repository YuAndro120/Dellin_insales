<?php

declare(strict_types=1);

namespace ShippingBridge\InSales;

use ShippingBridge\Config;

/**
 * Уведомление о новой заявке с лендинга — Telegram и/или WhatsApp, оба пути
 * необязательны и независимы: включаются переменными окружения, если ни
 * одна не задана — просто ничего не отправляется (заявка при этом всё
 * равно сохранена в БД, ничего не теряется).
 *
 * TELEGRAM (официальный Bot API, бесплатно, без рисков для аккаунта):
 *   TELEGRAM_BOT_TOKEN — токен бота от @BotFather
 *   TELEGRAM_CHAT_ID   — куда слать (свой user id или id чата с ботом)
 *
 * WHATSAPP (неофициальный шлюз к личному номеру — формат Green API,
 * тот же формат поддерживают Wappi.pro, Chat2Desk и подобные сервисы;
 * если провайдер другой — понадобится подправить URL/тело запроса):
 *   WHATSAPP_GATEWAY_URL — например https://{apiUrl}/waInstance{id}/sendMessage/{token}
 *   WHATSAPP_TO           — номер получателя, формат 79991234567@c.us
 * ⚠️ Такие шлюзы работают через привязку личного номера WhatsApp по
 * QR-коду и формально нарушают ToS WhatsApp — риск блокировки номера
 * есть, хоть на практике для низкого объёма личных уведомлений он невысок.
 */
final class LeadNotifier
{
    private const PRODUCT_LABELS = [
        'landing' => 'ДЛ Коннект для inSales',
        'landing_amocrm' => 'ДЛ Коннект для amoCRM',
    ];

    public static function notify(Config $config, array $lead): bool
    {
        $text = self::formatMessage($lead);
        $sentTelegram = self::sendTelegram($config, $text);
        $sentWhatsapp = self::sendWhatsapp($config, $text);
        return $sentTelegram || $sentWhatsapp;
    }

    private static function formatMessage(array $lead): string
    {
        $product = self::PRODUCT_LABELS[$lead['source'] ?? ''] ?? 'ДЛ Коннект';
        $lines = [
            "Новая заявка — {$product}",
            'Имя: ' . $lead['name'],
            'Телефон: ' . $lead['phone'],
        ];
        if (!empty($lead['company_name'])) {
            $lines[] = 'Компания/магазин: ' . $lead['company_name'];
        }
        if (!empty($lead['message'])) {
            $lines[] = 'Комментарий: ' . $lead['message'];
        }
        return implode("\n", $lines);
    }

    private static function sendTelegram(Config $config, string $text): bool
    {
        $token = $config->telegramBotToken;
        $chatId = $config->telegramChatId;
        if ($token === null || $token === '' || $chatId === null || $chatId === '') {
            return false;
        }
        try {
            $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['chat_id' => $chatId, 'text' => $text], JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
            ]);
            $out = curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($http >= 400) {
                \ShippingBridge\Logger::error('-', null, 'lead_notify.telegram_failed', ['http' => $http, 'response' => mb_substr((string) $out, 0, 500)]);
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            \ShippingBridge\Logger::error('-', null, 'lead_notify.telegram_error', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private static function sendWhatsapp(Config $config, string $text): bool
    {
        $url = $config->whatsappGatewayUrl;
        $to = $config->whatsappTo;
        if ($url === null || $url === '' || $to === null || $to === '') {
            return false;
        }
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['chatId' => $to, 'message' => $text], JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
            ]);
            $out = curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($http >= 400) {
                \ShippingBridge\Logger::error('-', null, 'lead_notify.whatsapp_failed', ['http' => $http, 'response' => mb_substr((string) $out, 0, 500)]);
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            \ShippingBridge\Logger::error('-', null, 'lead_notify.whatsapp_error', ['error' => $e->getMessage()]);
            return false;
        }
    }
}