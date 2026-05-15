<?php

declare(strict_types=1);

namespace ShippingBridge\InSales;

use ShippingBridge\ShopRepository;

/**
 * Страница настроек приложения в бэкофисе inSales (после установки).
 * Терминал отправителя задаёт владелец магазина, не .env сервера.
 */
final class AppSettingsHandler
{
    public static function handle(ShopRepository $shops, string $method): void
    {
        header('Content-Type: text/html; charset=utf-8');

        $shopHost = trim((string) ($_GET['shop'] ?? $_POST['shop'] ?? ''));
        $insalesId = trim((string) ($_GET['insales_id'] ?? $_POST['insales_id'] ?? ''));

        if ($shopHost === '' && $insalesId === '') {
            http_response_code(400);
            self::renderError('Укажите параметры shop или insales_id (их передаёт inSales при открытии приложения).');
            return;
        }

        $row = $insalesId !== ''
            ? $shops->findActiveByInsalesId($insalesId)
            : $shops->findActiveByHost($shopHost);

        if ($row === null) {
            http_response_code(404);
            self::renderError('Магазин не найден. Сначала установите приложение в магазине inSales.');
            return;
        }

        $insalesId = $row['insales_id'];
        $shopHost = $row['shop_host'];
        $saved = false;
        $error = null;

        if ($method === 'POST') {
            $terminalId = (int) ($_POST['sender_terminal_id'] ?? 0);
            try {
                $shops->saveSenderTerminalId($insalesId, $terminalId);
                $row = $shops->findActiveByInsalesId($insalesId) ?? $row;
                $saved = true;
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        http_response_code(200);
        self::renderForm($shopHost, $insalesId, $row['sender_terminal_id'], $saved, $error);
    }

    private static function renderForm(
        string $shopHost,
        string $insalesId,
        ?int $currentTerminalId,
        bool $saved,
        ?string $error
    ): void {
        $tid = $currentTerminalId !== null && $currentTerminalId > 0 ? (string) $currentTerminalId : '';
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>Настройки доставки</title>';
        echo '<style>body{font-family:system-ui,sans-serif;max-width:520px;margin:2rem auto;padding:0 1rem}';
        echo 'label{display:block;margin:.75rem 0 .25rem}input{width:100%;padding:.5rem;box-sizing:border-box}';
        echo 'button{margin-top:1rem;padding:.6rem 1.2rem} .ok{color:#0a0}.err{color:#c00}</style></head><body>';
        echo '<h1>Настройки доставки</h1>';
        echo '<p>Магазин: <strong>' . $h($shopHost) . '</strong></p>';

        if ($saved) {
            echo '<p class="ok">Настройки сохранены.</p>';
        }
        if ($error !== null) {
            echo '<p class="err">' . $h($error) . '</p>';
        }

        echo '<form method="post" action="/insales/app">';
        echo '<input type="hidden" name="shop" value="' . $h($shopHost) . '">';
        echo '<input type="hidden" name="insales_id" value="' . $h($insalesId) . '">';
        echo '<label for="sender_terminal_id">ID терминала отгрузки</label>';
        echo '<p style="font-size:.9rem;color:#555">Укажите терминал, с которого отправляете груз (из личного кабинета перевозчика или справочника ПВЗ).</p>';
        echo '<input type="number" id="sender_terminal_id" name="sender_terminal_id" min="1" required value="' . $h($tid) . '">';
        echo '<button type="submit">Сохранить</button>';
        echo '</form>';
        echo '<p style="margin-top:2rem;font-size:.85rem"><a href="/widget/index.html">Демо карты ПВЗ</a></p>';
        echo '</body></html>';
    }

    private static function renderError(string $message): void
    {
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Ошибка</title></head><body>';
        echo '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></body></html>';
    }
}
