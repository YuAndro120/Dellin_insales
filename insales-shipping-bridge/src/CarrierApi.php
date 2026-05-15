<?php

declare(strict_types=1);

namespace ShippingBridge;

/**
 * HTTP-клиент к JSON API перевозчика (калькулятор, справочник терминалов, подсказка КЛАДР).
 */
final class CarrierApi
{
    private const URL_LOGIN = 'https://api.dellin.ru/v1/customers/login.json';
    private const URL_CALC = 'https://api.dellin.ru/v2/calculator.json';
    private const URL_KLADR = 'https://api.dellin.ru/v2/public/kladr.json';
    private const URL_TERMINALS_MANIFEST = 'https://api.dellin.ru/v3/public/terminals.json';

    public function __construct(private readonly Config $config)
    {
    }

    public function login(): string
    {
        $res = $this->postJson(self::URL_LOGIN, [
            'appkey' => $this->config->appkey,
            'login' => $this->config->login,
            'password' => $this->config->password,
        ]);
        if (!empty($res['errors'])) {
            throw new \RuntimeException('Auth errors: ' . json_encode($res['errors'], JSON_UNESCAPED_UNICODE));
        }
        $sid = $res['sessionID'] ?? $res['data']['sessionID'] ?? null;
        if (!is_string($sid) || $sid === '') {
            throw new \RuntimeException('sessionID not in response: ' . json_encode($res, JSON_UNESCAPED_UNICODE));
        }
        return $sid;
    }

    /** @return list<array<string,mixed>> */
    public function searchCities(string $q): array
    {
        $raw = $this->postJson(self::URL_KLADR, [
            'appkey' => $this->config->appkey,
            'q' => $q,
        ]);
        $cities = $raw['cities'] ?? [];
        return is_array($cities) ? array_values($cities) : [];
    }

    /**
     * @param array{weight?:float,volume?:float,length?:float,width?:float,height?:float,quantity?:int,stated_value?:float} $cargo
     * @return array{price:float|null,days:int|null,metadata:array,raw?:array,errors?:mixed}
     */
    public function calculateToTerminal(string $sessionId, int $arrivalTerminalId, string $arrivalCityKladr, array $cargo): array
    {
        $body = $this->buildCalculatorBody($sessionId, $arrivalTerminalId, $arrivalCityKladr, $cargo);
        $res = $this->postJson(self::URL_CALC, $body);
        return $this->parseCalculatorResponse($res);
    }

    public function calculateToCity(string $sessionId, string $arrivalCityKladr, array $cargo): array
    {
        $body = $this->buildCalculatorBodyCityArrival($sessionId, $arrivalCityKladr, $cargo);
        $res = $this->postJson(self::URL_CALC, $body);
        return $this->parseCalculatorResponse($res);
    }

    /** @return array{url:string,hash?:string} */
    public function terminalsManifest(): array
    {
        $res = $this->postJson(self::URL_TERMINALS_MANIFEST, ['appkey' => $this->config->appkey]);
        if (empty($res['url']) || !is_string($res['url'])) {
            throw new \RuntimeException('Terminals manifest: missing url. ' . json_encode($res, JSON_UNESCAPED_UNICODE));
        }
        return ['url' => $res['url'], 'hash' => $res['hash'] ?? null];
    }

    public function fetchTerminalsDataset(string $url): array
    {
        $json = $this->http('GET', $url, null);
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        return is_array($data) ? $data : [];
    }

    private function buildCalculatorBody(string $sessionId, int $arrivalTerminalId, string $arrivalCityKladr, array $cargo): array
    {
        $produce = date('Y-m-d', strtotime('+2 days'));
        $c = $this->normalizeCargo($cargo);

        $requester = [
            'role' => 'sender',
            'email' => $this->config->senderRequesterEmail,
        ];
        if ($this->config->senderCounteragentUid !== null && $this->config->senderCounteragentUid !== '') {
            $requester['uid'] = $this->config->senderCounteragentUid;
        }

        return [
            'sessionID' => $sessionId,
            'appkey' => $this->config->appkey,
            'delivery' => [
                'deliveryType' => ['type' => 'auto'],
                'arrival' => [
                    'variant' => 'terminal',
                    'terminalID' => $arrivalTerminalId,
                    'requirements' => [],
                ],
                'derival' => [
                    'variant' => 'terminal',
                    'terminalID' => $this->config->senderTerminalId,
                    'produceDate' => $produce,
                    'requirements' => [],
                ],
                'packages' => [],
            ],
            'cargo' => $c,
            'members' => [
                'requester' => $requester,
            ],
            'payment' => [
                'paymentCity' => $arrivalCityKladr,
                'type' => 'noncash',
                'primaryPayer' => 'sender',
            ],
            'productInfo' => [
                'type' => 4,
                'productType' => 5,
                'info' => [['param' => 'shipping-bridge', 'value' => 'mvp-1']],
            ],
        ];
    }

    private function buildCalculatorBodyCityArrival(string $sessionId, string $arrivalCityKladr, array $cargo): array
    {
        $produce = date('Y-m-d', strtotime('+2 days'));
        $c = $this->normalizeCargo($cargo);

        $requester = [
            'role' => 'sender',
            'email' => $this->config->senderRequesterEmail,
        ];
        if ($this->config->senderCounteragentUid !== null && $this->config->senderCounteragentUid !== '') {
            $requester['uid'] = $this->config->senderCounteragentUid;
        }

        return [
            'sessionID' => $sessionId,
            'appkey' => $this->config->appkey,
            'delivery' => [
                'deliveryType' => ['type' => 'auto'],
                'arrival' => [
                    'variant' => 'terminal',
                    'city' => ['code' => $arrivalCityKladr],
                    'requirements' => [],
                ],
                'derival' => [
                    'variant' => 'terminal',
                    'terminalID' => $this->config->senderTerminalId,
                    'produceDate' => $produce,
                    'requirements' => [],
                ],
                'packages' => [],
            ],
            'cargo' => $c,
            'members' => [
                'requester' => $requester,
            ],
            'payment' => [
                'paymentCity' => $arrivalCityKladr,
                'type' => 'noncash',
                'primaryPayer' => 'sender',
            ],
            'productInfo' => [
                'type' => 4,
                'productType' => 5,
                'info' => [['param' => 'shipping-bridge', 'value' => 'mvp-1-city']],
            ],
        ];
    }

    /** @param array<string,mixed> $cargo */
    private function normalizeCargo(array $cargo): array
    {
        $w = (float) ($cargo['weight'] ?? 1.0);
        $vol = (float) ($cargo['volume'] ?? 0.01);
        $l = (float) ($cargo['length'] ?? 0.2);
        $wd = (float) ($cargo['width'] ?? 0.2);
        $h = (float) ($cargo['height'] ?? 0.2);
        $q = (int) ($cargo['quantity'] ?? 1);
        $stated = (float) ($cargo['stated_value'] ?? 0.0);

        $w = max(0.01, $w);
        $vol = max(0.01, $vol);
        $l = max(0.01, $l);
        $wd = max(0.01, $wd);
        $h = max(0.01, $h);
        $q = max(1, $q);

        return [
            'quantity' => $q,
            'length' => round($l, 2),
            'width' => round($wd, 2),
            'height' => round($h, 2),
            'weight' => round($w, 2),
            'totalVolume' => round($vol, 2),
            'totalWeight' => round($w * $q, 2),
            'insurance' => [
                'statedValue' => round($stated, 2),
                'payer' => 'sender',
                'term' => false,
            ],
        ];
    }

    /** @param array<string,mixed> $res */
    private function parseCalculatorResponse(array $res): array
    {
        $meta = $res['metadata'] ?? [];
        $status = (int) ($meta['status'] ?? 0);
        $errors = $res['errors'] ?? null;

        if ($errors !== null || $status !== 200) {
            return [
                'price' => null,
                'days' => null,
                'metadata' => is_array($meta) ? $meta : [],
                'errors' => $errors,
                'raw' => $res,
            ];
        }

        $data = $res['data'] ?? [];
        $price = isset($data['price']) ? (float) $data['price'] : null;

        $days = null;
        if (isset($data['orderDates']['arrivalToOspReceiver'])) {
            try {
                $d0 = new \DateTimeImmutable('today');
                $d1 = new \DateTimeImmutable((string) $data['orderDates']['arrivalToOspReceiver']);
                $days = (int) $d0->diff($d1)->format('%a');
            } catch (\Throwable) {
                $days = null;
            }
        }

        return [
            'price' => $price,
            'days' => $days,
            'metadata' => is_array($meta) ? $meta : [],
            'raw' => $res,
        ];
    }

    /** @return array<string,mixed> */
    private function postJson(string $url, ?array $body): array
    {
        $json = $this->http('POST', $url, $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE));
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    private function http(string $method, string $url, ?string $jsonBody): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }
        $headers = ['Accept: application/json'];
        if ($jsonBody !== null) {
            $headers[] = 'Content-Type: application/json; charset=utf-8';
        }
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 120,
        ];
        if ($method === 'POST' && $jsonBody !== null) {
            $opts[CURLOPT_POSTFIELDS] = $jsonBody;
        }
        if ($method === 'GET') {
            $opts[CURLOPT_HTTPGET] = true;
        }
        curl_setopt_array($ch, $opts);
        $out = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($out === false) {
            throw new \RuntimeException('HTTP error: ' . $err);
        }
        if ($code >= 400) {
            throw new \RuntimeException("HTTP {$code}: " . mb_substr($out, 0, 2000));
        }
        return $out;
    }
}
