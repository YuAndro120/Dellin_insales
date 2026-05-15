<?php

declare(strict_types=1);

namespace ShippingBridge\InSales;

/**
 * Клиент JSON API магазина inSales (Basic: идентификатор приложения + пароль установки).
 * @see https://www.insales.ru/collection/doc-rabota-s-api-i-prilozheniya/product/kak-integrirovatsya-s-insales
 */
final class InSalesClient
{
    /**
     * @return array<string,mixed>
     */
    public function getProductJson(
        string $shopHost,
        string $applicationLogin,
        string $apiPasswordMd5,
        int $productId
    ): array {
        $url = $this->buildUrl($shopHost, $applicationLogin, $apiPasswordMd5, "/admin/products/{$productId}.json");
        return $this->getJson($url);
    }

    /**
     * Вариант по product_id + variant_id (предпочтительно для корзины).
     * @return array{variant: array<string,mixed>, product_id: int}|null
     */
    public function getVariantByProduct(
        string $shopHost,
        string $applicationLogin,
        string $apiPasswordMd5,
        int $productId,
        int $variantId
    ): ?array {
        $data = $this->getProductJson($shopHost, $applicationLogin, $apiPasswordMd5, $productId);
        $variants = $data['variants'] ?? [];
        if (!is_array($variants)) {
            return null;
        }
        foreach ($variants as $v) {
            if (!is_array($v)) {
                continue;
            }
            if ((int) ($v['id'] ?? 0) === $variantId) {
                return ['variant' => $v, 'product_id' => $productId];
            }
        }
        return null;
    }

    /**
     * Медленный поиск: обход страниц каталога (если в запросе не передан product_id).
     * @return array{variant: array<string,mixed>, product_id: int}|null
     */
    public function findVariantAcrossProducts(
        string $shopHost,
        string $applicationLogin,
        string $apiPasswordMd5,
        int $variantId
    ): ?array {
        $url = $this->buildUrl($shopHost, $applicationLogin, $apiPasswordMd5, '/admin/products.json?per_page=250');
        $page = 1;
        while ($page <= 20) {
            $sep = str_contains($url, '?') ? '&' : '?';
            $pageUrl = $url . $sep . 'page=' . $page;
            $list = $this->getJson($pageUrl);
            $items = $list['products'] ?? [];
            if (!is_array($items) || $items === []) {
                break;
            }
            foreach ($items as $product) {
                if (!is_array($product)) {
                    continue;
                }
                $pid = (int) ($product['id'] ?? 0);
                foreach ($product['variants'] ?? [] as $v) {
                    if (is_array($v) && (int) ($v['id'] ?? 0) === $variantId) {
                        return ['variant' => $v, 'product_id' => $pid];
                    }
                }
            }
            if (count($items) < 250) {
                break;
            }
            $page++;
        }
        return null;
    }

    private function buildUrl(string $shopHost, string $login, string $pass, string $path): string
    {
        $host = preg_replace('#^https?://#i', '', $shopHost) ?: $shopHost;
        $user = rawurlencode($login);
        $pw = rawurlencode($pass);
        return 'https://' . $user . ':' . $pw . '@' . $host . $path;
    }

    /**
     * @return array<string, mixed>
     */
    public function getJsonPath(
        string $shopHost,
        string $applicationLogin,
        string $apiPasswordMd5,
        string $path,
    ): array {
        $url = $this->buildUrl($shopHost, $applicationLogin, $apiPasswordMd5, $path);

        return $this->getJson($url);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function postJson(
        string $shopHost,
        string $applicationLogin,
        string $apiPasswordMd5,
        string $path,
        array $payload,
    ): array {
        return $this->requestJson('POST', $shopHost, $applicationLogin, $apiPasswordMd5, $path, $payload);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function requestJson(
        string $method,
        string $shopHost,
        string $login,
        string $pass,
        string $path,
        ?array $payload,
    ): array {
        $url = $this->buildUrl($shopHost, $login, $pass, $path);
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
        ];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CUSTOMREQUEST => $method,
        ];
        if ($payload !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) {
            throw new \RuntimeException('InSales HTTP error');
        }
        $data = json_decode($body, true);
        if ($code >= 400) {
            throw new \RuntimeException("InSales API HTTP {$code}: " . mb_substr((string) $body, 0, 1500));
        }

        return is_array($data) ? $data : [];
    }

    /** @return array<string,mixed> */
    private function getJson(string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new \RuntimeException('Invalid InSales URL');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 60,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) {
            throw new \RuntimeException('InSales HTTP error');
        }
        $data = json_decode($body, true);
        if ($code >= 400) {
            throw new \RuntimeException("InSales API HTTP {$code}: " . mb_substr((string) $body, 0, 1500));
        }

        return is_array($data) ? $data : [];
    }
}
