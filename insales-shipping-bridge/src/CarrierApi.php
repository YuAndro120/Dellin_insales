<?php

declare(strict_types=1);

namespace ShippingBridge;

/**
 * HTTP-клиент к JSON API перевозчика (калькулятор, справочник терминалов, подсказка КЛАДР).
 */
final class CarrierApi
{
    private const URL_LOGIN_V4 = 'https://api.dellin.ru/v4/auth/login.json';
    private const URL_LOGIN_V1 = 'https://api.dellin.ru/v1/customers/login.json';
    private const URL_BOOK_COUNTERAGENTS = 'https://api.dellin.ru/v2/book/counteragents.json';
    private const URL_CALC = 'https://api.dellin.ru/v2/calculator.json';
    private const URL_KLADR = 'https://api.dellin.ru/v2/public/kladr.json';
    private const URL_KLADR_STREET = 'https://api.dellin.ru/v1/public/kladr_street.json';
    private const URL_ADDRESS_SUGGEST = 'https://api.dellin.ru/v1/public/address_suggest.json';
    private const URL_ADDRESS_CLEAN   = 'https://api.dellin.ru/v1/public/address_clean.json';
    private const URL_TERMINALS_MANIFEST = 'https://api.dellin.ru/v3/public/terminals.json';

    public function __construct(private readonly Config $config)
    {
    }

    public function login(?CarrierCredentials $credentials = null): string
    {
        $creds = $credentials ?? $this->config->defaultCarrierCredentials();
        if ($creds !== null && $creds->isComplete()) {
            return $this->loginWithPat($creds);
        }

        throw new \RuntimeException('PAT не настроен. Укажите персональный токен в настройках магазина.');
    }

    public function loginWithPat(CarrierCredentials $credentials): string
    {
        $res = $this->postJson(self::URL_LOGIN_V4, [
            'appkey' => $credentials->appkey,
            'pat' => $credentials->pat,
        ]);

        return $this->extractSessionId($res);
    }

    /**
     * Контрагенты, доступные по PAT (сначала из ответа login, иначе адресная книга).
     *
     * @return list<DellinCounteragent>
     */
    public function listCounteragents(CarrierCredentials $credentials): array
    {
        $loginRes = $this->postJson(self::URL_LOGIN_V4, [
            'appkey' => $credentials->appkey,
            'pat' => $credentials->pat,
        ]);
        $list = $this->parseCounteragents($loginRes);
        if ($list !== []) {
            return $list;
        }

        $sid = $this->extractSessionId($loginRes);
        $bookRes = $this->postJson(self::URL_BOOK_COUNTERAGENTS, [
            'appkey' => $credentials->appkey,
            'sessionID' => $sid,
        ]);

        return $this->parseCounteragents($bookRes);
    }

    /**
     * @param array<string, mixed> $res
     * @return list<DellinCounteragent>
     */
    private function parseCounteragents(array $res): array
    {
        if (!empty($res['errors'])) {
            return [];
        }

        $raw = $res['data']['counteragents']
            ?? $res['counteragents']
            ?? $res['data']['counterAgents']
            ?? $res['counterAgents']
            ?? $res['data']['members']
            ?? $res['members']
            ?? null;

        if ($raw === null && isset($res['data']) && is_array($res['data']) && array_is_list($res['data'])) {
            $raw = $res['data'];
        }

        $items = $this->normalizeList($raw);
        $byUid = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $uid = $item['uid'] ?? $item['UID'] ?? $item['counteragentUID'] ?? $item['id'] ?? null;
            if ($uid === null || $uid === '') {
                continue;
            }
            $uid = (string) $uid;
            $name = trim((string) (
                $item['name']
                ?? $item['brand']
                ?? $item['juridicalName']
                ?? $item['fullName']
                ?? $item['title']
                ?? ''
            ));
            if ($name === '') {
                $name = 'Контрагент ' . $uid;
            }
            $inn = trim((string) ($item['inn'] ?? ''));
            if ($inn !== '') {
                $name .= ' (ИНН ' . $inn . ')';
            }
            $byUid[$uid] = new DellinCounteragent($uid, $name);
        }

        $list = array_values($byUid);
        usort($list, static fn (DellinCounteragent $a, DellinCounteragent $b): int => strcasecmp($a->name, $b->name));

        return $list;
    }

    private function normalizeList(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (is_array($raw) && array_is_list($raw)) {
            return $raw;
        }
        if (is_array($raw)) {
            foreach (['counteragent', 'counteragents', 'member', 'members'] as $key) {
                if (isset($raw[$key])) {
                    return $this->normalizeList($raw[$key]);
                }
            }

            return array_values($raw);
        }

        return [];
    }

    /** @param array<string,mixed> $res */
    private function extractSessionId(array $res): string
    {
        if (!empty($res['errors'])) {
            $err = is_string($res['errors']) ? $res['errors'] : json_encode($res['errors'], JSON_UNESCAPED_UNICODE);
            throw new \RuntimeException('Ошибка авторизации Dellin: ' . $err);
        }
        $sid = $res['sessionID'] ?? $res['data']['sessionID'] ?? $res['data']['sessionId'] ?? null;
        if (!is_string($sid) || $sid === '') {
            throw new \RuntimeException('sessionID not in response: ' . json_encode($res, JSON_UNESCAPED_UNICODE));
        }

        return $sid;
    }

    /** @return list<array<string,mixed>> */
    public function searchCities(string $q, ?CarrierCredentials $credentials = null): array
    {
        $creds = $credentials ?? $this->config->defaultCarrierCredentials();
        $appkey = $creds?->appkey ?? $this->config->dellinAppkey;
        if ($appkey === '') {
            throw new \RuntimeException('API-ключ Dellin не задан');
        }

        $raw = $this->postJson(self::URL_KLADR, [
            'appkey' => $appkey,
            'q' => $q,
        ]);
        $cities = $raw['cities'] ?? [];

        return is_array($cities) ? array_values($cities) : [];
    }

    /**
     * @param array{weight?:float,volume?:float,length?:float,width?:float,height?:float,quantity?:int,stated_value?:float} $cargo
     * @return array{price:float|null,days:int|null,metadata:array,raw?:array,errors?:mixed}
     */
    public function calculateToTerminal(
        string $sessionId,
        int $senderTerminalId,
        int $arrivalTerminalId,
        ?string $arrivalCityKladr,
        array $cargo,
        CalculatorContext $calcCtx,
        ?CarrierCredentials $credentials = null,
    ): array {
        $body = $this->buildCalculatorBody(
            $sessionId,
            $senderTerminalId,
            $arrivalTerminalId,
            $arrivalCityKladr,
            $cargo,
            $calcCtx,
            $credentials,
        );
        $res = $this->postJson(self::URL_CALC, $body);

        return $this->parseCalculatorResponse($res);
    }

    // --- Резолвинг КЛАДР улицы для расчёта доставки до адреса ---

    public function getCityId(string $cityKladr, ?CarrierCredentials $credentials = null): ?int
    {
        $appkey = $this->resolveAppkey($credentials);
        $raw = $this->postJson(self::URL_KLADR, [
            'appkey' => $appkey,
            'code'   => $cityKladr,
        ]);
        $cities = $raw['cities'] ?? [];
        return isset($cities[0]['cityID']) ? (int) $cities[0]['cityID'] : null;
    }

    public function getStreetKladr(int $cityId, string $streetName, ?CarrierCredentials $credentials = null): ?string
    {
        $appkey = $this->resolveAppkey($credentials);
        $raw = $this->postJson(self::URL_KLADR_STREET, [
            'appkey' => $appkey,
            'cityID' => $cityId,
            'street' => $streetName,
            'limit'  => 1,
        ]);
        $streets = $raw['streets'] ?? [];
        return isset($streets[0]['code']) ? (string) $streets[0]['code'] : null;
    }

    /**
     * Подсказки адреса по введённой строке (требует отдельного доступа от Dellin).
     *
     * @return list<array{value: string, data: array<string, mixed>}>
     */
    public function suggestAddress(
        string $sessionId,
        string $query,
        string $cityKladr,
        int $count = 10,
        ?CarrierCredentials $credentials = null,
    ): array {
        $raw = $this->postJson(self::URL_ADDRESS_SUGGEST, [
            'appkey'    => $this->resolveAppkey($credentials),
            'sessionID' => $sessionId,
            'query'     => $query,
            'city_code' => $cityKladr,
            'count'     => $count,
            'mode'      => 'pretty',
        ]);

        return $raw['suggestions'] ?? [];
    }

    /**
     * Стандартизация адреса — получить КЛАДР улицы и дом из произвольной строки.
     * Требует отдельного доступа от Dellin.
     *
     * @return array<string, mixed>|null
     */
    public function cleanAddress(
        string $sessionId,
        string $address,
        ?CarrierCredentials $credentials = null,
    ): ?array {
        $raw = $this->postJson(self::URL_ADDRESS_CLEAN, [
            'appkey'    => $this->resolveAppkey($credentials),
            'sessionID' => $sessionId,
            'data'      => [$address],
            'type'      => 'address',
            'mode'      => 'pretty',
        ]);

        $items = $raw['cleanEssenceResponse']['data'] ?? [];
        return $items[0] ?? null;
    }

    public function calculateToCity(
        string $sessionId,
        int $senderTerminalId,
        string $arrivalCityKladr,
        ?string $arrivalStreet,
        ?string $arrivalHouse,
        array $cargo,
        CalculatorContext $calcCtx,
        ?CarrierCredentials $credentials = null,
    ): array {
        // Резолвим КЛАДР улицы если передана улица
        $streetKladr = null;
        if ($arrivalStreet !== null && $arrivalStreet !== '') {
            $cityId = $this->getCityId($arrivalCityKladr, $credentials);
            if ($cityId !== null) {
                $streetKladr = $this->getStreetKladr($cityId, $arrivalStreet, $credentials);
            }
        }

        $body = $this->buildCalculatorBodyCityArrival(
            $sessionId,
            $senderTerminalId,
            $arrivalCityKladr,
            $arrivalStreet,
            $arrivalHouse,
            $cargo,
            $calcCtx,
            $credentials,
            $streetKladr,
        );
        $res = $this->postJson(self::URL_CALC, $body);

        return $this->parseCalculatorResponse($res);
    }

    /** @return array{url:string,hash?:string} */
    public function terminalsManifest(?CarrierCredentials $credentials = null): array
    {
        $creds = $credentials ?? $this->config->defaultCarrierCredentials();
        $appkey = $creds?->appkey ?? $this->config->dellinAppkey;
        if ($appkey === '') {
            throw new \RuntimeException('API-ключ Dellin не задан');
        }

        $res = $this->postJson(self::URL_TERMINALS_MANIFEST, ['appkey' => $appkey]);
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

    private function buildCalculatorBody(
        string $sessionId,
        int $senderTerminalId,
        int $arrivalTerminalId,
        ?string $arrivalCityKladr,
        array $cargo,
        CalculatorContext $calcCtx,
        ?CarrierCredentials $credentials,
    ): array {
        $c = $this->normalizeCargo($cargo);
        $requester = $this->buildRequester($calcCtx);

        return [
            'sessionID' => $sessionId,
            'appkey' => $this->resolveAppkey($credentials),
            'delivery' => [
                'deliveryType' => ['type' => 'auto'],
                'arrival' => [
                    'variant' => 'terminal',
                    'terminalID' => $arrivalTerminalId,
                    'requirements' => [],
                ],
                'derival' => $this->buildDerival($calcCtx, $senderTerminalId),
                'packages' => [],
            ],
            'cargo' => $c,
            'members' => ['requester' => $requester],
            'payment' => $this->buildPayment($arrivalCityKladr),
            'productInfo' => [
                'type' => 4,
                'productType' => 5,
                'info' => [['param' => 'shipping-bridge', 'value' => 'mvp-1']],
            ],
        ];
    }

    private function buildCalculatorBodyCityArrival(
        string $sessionId,
        int $senderTerminalId,
        string $arrivalCityKladr,
        ?string $arrivalStreet,
        ?string $arrivalHouse,
        array $cargo,
        CalculatorContext $calcCtx,
        ?CarrierCredentials $credentials,
        ?string $streetKladr = null,
    ): array {
        $c = $this->normalizeCargo($cargo);
        $requester = $this->buildRequester($calcCtx);

        // Если есть КЛАДР улицы и дом — доставка до адреса, иначе до терминала в городе
        if ($streetKladr !== null && $arrivalHouse !== null && $arrivalHouse !== '') {
            $arrival = [
                'variant' => 'address',
                'city' => str_pad($arrivalCityKladr, 25, '0'),
                'address' => [
                    'street' => ['code' => $streetKladr],
                    'house' => $arrivalHouse,
                ],
                'requirements' => [],
            ];
        } else {
            $arrival = [
                'variant' => 'address',
                'city' => str_pad($arrivalCityKladr, 25, '0'),
                'requirements' => [],
            ];
        }

        return [
            'sessionID' => $sessionId,
            'appkey' => $this->resolveAppkey($credentials),
            'delivery' => [
                'deliveryType' => ['type' => 'auto'],
                'arrival' => $arrival,
                'derival' => $this->buildDerival($calcCtx, $senderTerminalId),
                'packages' => [],
            ],
            'cargo' => $c,
            'members' => ['requester' => $requester],
            'payment' => $this->buildPayment($arrivalCityKladr),
            'productInfo' => [
                'type' => 4,
                'productType' => 5,
                'info' => [['param' => 'shipping-bridge', 'value' => 'mvp-1-city']],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function buildDerival(CalculatorContext $calcCtx, int $senderTerminalId): array
    {
        $produce = date('Y-m-d', strtotime('+' . $calcCtx->produceDaysOffset . ' days'));

        if ($calcCtx->derivalVariant === ShopSettings::DERIVAL_ADDRESS) {
            $city = $calcCtx->derivalCityKladr ?? '';
            $street = $calcCtx->derivalStreet ?? '';
            $house = $calcCtx->derivalHouse ?? '';
            if (strlen($city) < 10 || $street === '' || $house === '') {
                throw new \InvalidArgumentException('Адрес забора груза не заполнен');
            }

            return [
                'variant' => 'address',
                'address' => [
                    'city' => ['code' => $city],
                    'street' => $street,
                    'house' => $house,
                ],
                'produceDate' => $produce,
                'requirements' => [],
            ];
        }

        if ($senderTerminalId <= 0) {
            throw new \InvalidArgumentException('Терминал отгрузки не настроен');
        }

        return [
            'variant' => 'terminal',
            'terminalID' => $senderTerminalId,
            'produceDate' => $produce,
            'requirements' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function buildRequester(CalculatorContext $calcCtx): array
    {
        $requester = [
            'role' => 'sender',
            'email' => $calcCtx->requesterEmail,
        ];
        if ($calcCtx->counteragentUid !== null && $calcCtx->counteragentUid !== '') {
            $requester['uid'] = $calcCtx->counteragentUid;
        }

        return $requester;
    }

    private function resolveAppkey(?CarrierCredentials $credentials): string
    {
        $creds = $credentials ?? $this->config->defaultCarrierCredentials();
        $appkey = $creds?->appkey ?? $this->config->dellinAppkey;
        if ($appkey === '') {
            throw new \RuntimeException('API-ключ Dellin не задан');
        }

        return $appkey;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayment(?string $paymentCityKladr): array
    {
        $payment = [
            'type' => 'noncash',
            'primaryPayer' => 'sender',
        ];
        if ($paymentCityKladr !== null && $paymentCityKladr !== '') {
            $payment['paymentCity'] = str_pad($paymentCityKladr, 25, '0');
        }

        return $payment;
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
