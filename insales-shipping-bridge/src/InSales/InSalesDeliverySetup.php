<?php

declare(strict_types=1);

namespace ShippingBridge\InSales;

use ShippingBridge\Config;

/**
 * Создание способа доставки PickUp в магазине через API inSales (два URL для ПВЗ).
 *
 * @see https://api.insales.ru/?doc_format=JSON — DeliveryVariant::PickUp
 */
final class InSalesDeliverySetup
{
    private const DELIVERY_TITLE = 'Деловые Линии — терминал';

    public function __construct(
        private readonly InSalesClient $client,
        private readonly Config $config,
    ) {
    }

    /**
     * @return array{id: int, title: string}
     */
    public function createPickUpDeliveryVariant(string $shopHost, string $apiPasswordMd5): array
    {
        $login = $this->config->insalesAppId ?? '';
        if ($login === '') {
            throw new \RuntimeException('INSALES_APP_ID не задан в .env');
        }

        $existing = $this->findExistingPickUp($shopHost, $login, $apiPasswordMd5);
        if ($existing !== null) {
            return $existing;
        }

        $base = $this->publicBridgeBaseUrl();
        $payload = [
            'delivery_variant' => [
                'title' => self::DELIVERY_TITLE,
                'type' => 'DeliveryVariant::PickUp',
                'description' => 'Доставка до терминала Деловых Линий (выбор ПВЗ на оформлении заказа)',
                'add_payment_gateways' => true,
                'inverted' => true,
                'pick_up_sources_attributes' => [
                    [
                        'title' => 'Деловые Линии',
                        'pick_up_source_http_method' => 'POST',
                        'url' => $base . '/insales/external/v2/pickup_points',
                        'point_info_url' => $base . '/insales/external/v2/pickup_point',
                    ],
                ],
            ],
        ];

        $created = $this->client->postJson(
            $shopHost,
            $login,
            $apiPasswordMd5,
            '/admin/delivery_variants.json',
            $payload
        );

        $id = (int) ($created['id'] ?? 0);
        if ($id <= 0) {
            throw new \RuntimeException('InSales не вернул id способа доставки: ' . json_encode($created, JSON_UNESCAPED_UNICODE));
        }

        return ['id' => $id, 'title' => (string) ($created['title'] ?? self::DELIVERY_TITLE)];
    }

    /**
     * @return array{id: int, title: string}|null
     */
    private function findExistingPickUp(string $shopHost, string $login, string $apiPasswordMd5): ?array
    {
        $list = $this->client->getJsonPath($shopHost, $login, $apiPasswordMd5, '/admin/delivery_variants.json');
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $title = (string) ($row['title'] ?? '');
            if ($title === self::DELIVERY_TITLE) {
                $id = (int) ($row['id'] ?? 0);
                if ($id > 0) {
                    return ['id' => $id, 'title' => $title];
                }
            }
        }

        return null;
    }

    private function publicBridgeBaseUrl(): string
    {
        $base = trim((string) (getenv('PUBLIC_BRIDGE_URL') ?: ''));
        if ($base !== '') {
            return rtrim($base, '/');
        }

        return 'http://127.0.0.1';
    }
}
