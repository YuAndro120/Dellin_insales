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
    private const URL_ORDER = 'https://api.dellin.ru/v2/request.json';
    private const URL_FREIGHT_SEARCH = 'https://api.dellin.ru/v1/public/freight_types/search.json';
    private const COUNTRY_NAMES = [
        '0x8f51001438c4d49511dbd774581edb7a' => 'Россия',
        '0x8f51001438c4d49511dbd774581edb7b' => 'Украина',
        '0x8f51001438c4d49511dbd774581edb7c' => 'Азербайджан',
        '0x8f51001438c4d49511dbd774581edb7d' => 'Армения',
        '0x8f51001438c4d49511dbd774581edb7e' => 'Беларусь',
        '0x8f51001438c4d49511dbd774581edb7f' => 'Грузия',
        '0x8f51001438c4d49511dbd774581edb80' => 'Казахстан',
        '0x8f51001438c4d49511dbd774581edb81' => 'Киргизия',
        '0x8f51001438c4d49511dbd774581edb82' => 'Латвия',
        '0x8f51001438c4d49511dbd774581edb83' => 'Литва',
        '0x8f51001438c4d49511dbd774581edb84' => 'Молдова, Республика',
        '0x8f51001438c4d49511dbd774581edb85' => 'Таджикистан',
        '0x8f51001438c4d49511dbd774581edb87' => 'Узбекистан',
        '0x8f51001438c4d49511dbd774581edb88' => 'Эстония',
        '0xa9b000215e563c5011e0d52a69cbabde' => 'Абхазия',
        '0xb21d7239a076b58b464ae336de6895b2' => 'Южная Осетия',
        '0x84ed051e7bdab59c4fcf738912c522e5' => 'Чешская Республика',
        '0x8cffb625a38584164e2cffe2a5f446c8' => 'Словения',
        '0x8d4cb224b9b273df4b885153c9e52b2b' => 'Франция',
        '0x9427c27796b5614948e72201d6e82c89' => 'Индия',
        '0x9795e5dc479f486e4ad2389a9d4d7a02' => 'Швейцария',
        '0x9adc54882d656ec84a91658e203ee756' => 'Иран',
        '0x9d8dcf51127632ef43754a9ef364dcc8' => 'Соединённое Королевство',
        '0x9e76bc9772217316433fdcae2ed5c2bf' => 'Вьетнам',
        '0xa55c42e94c12eb1a4a2e12dbff508a0d' => 'Монголия',
        '0xa6312706cee109f5426588b9a0959dc9' => 'Турция',
        '0xb38cc4ddc0f197e2424c8c1f44334240' => 'Китай',
        '0xb81907e141da1aa34c9a911b354d5ce0' => 'Сербия',
        '0xb826831f232e43ab46044fc5dcf10bfa' => 'ОАЭ',
        '0xb95547dfd61758e04da6fd61d8f4d623' => 'Финляндия',
        '0xbb22f971afbc99ef4e2d7a4ad011d198' => 'Польша',
        '0xbb7e8329166f827544e569f2a911a993' => 'Нидерланды',
    ];

    public function __construct(private readonly Config $config) {}

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
     * Оформление заявки на доставку в Деловые Линии.
     *
     * @param array<string, mixed> $order — строка из dellin_orders
     * @return array{request_id: int, barcode: string}
     */
    public function createOrder(
        string $sessionId,
        \ShippingBridge\ShopSettings $settings,
        array $order,
        ?CarrierCredentials $credentials = null,
    ): array {
        $appkey = $this->resolveAppkey($credentials);

        $arrivalCityKladr = str_pad((string) ($order['arrival_city_kladr'] ?? ''), 25, '0');
        $arrivalStreet    = (string) ($order['arrival_street'] ?? '');
        $arrivalHouse     = (string) ($order['arrival_house'] ?? '');
        $arrivalFlat      = (string) ($order['arrival_flat'] ?? '');
        $weight           = max(0.1, (float) ($order['weight'] ?? 1.0));
        $statedValue      = max(1.0, (float) ($order['stated_value'] ?? 1000.0));
        $receiverName     = (string) ($order['receiver_name'] ?? 'Получатель');
        $receiverPhone    = preg_replace('/\D/', '', (string) ($order['receiver_phone'] ?? ''));
        $receiverEmail    = (string) ($order['receiver_email'] ?? '');

        // Резолвим КЛАДР улицы
        $streetKladr = null;
        if ($arrivalStreet !== '') {
            $cityId = $this->getCityId($arrivalCityKladr, $credentials);
            if ($cityId !== null) {
                $streetKladr = $this->getStreetKladr($cityId, $arrivalStreet, $credentials);
            }
        }

        // Дата отгрузки
        $produceDate = (new \DateTimeImmutable())
            ->modify('+' . $settings->produceDaysOffset . ' days')
            ->format('Y-m-d');

        // Адрес прибытия: variant=address + address object, или terminal + city если адреса нет.
        // При variant=address передача city запрещена документацией API.
        // Если есть адрес — доставка до адреса, иначе до ближайшего терминала в городе
        if ($streetKladr !== null && $arrivalHouse !== '') {
            $arrivalBlock = [
                'variant' => 'address',
                'address' => array_filter([
                    'street' => $streetKladr,
                    'house'  => $arrivalHouse,
                    'flat'   => $arrivalFlat !== '' ? $arrivalFlat : null,
                ]),
            ];
        } elseif ($arrivalStreet !== '' && $arrivalHouse !== '') {
            $cityName = (string) ($order['arrival_city_name'] ?? '');
            $search = implode(', ', array_filter([$cityName, $arrivalStreet, $arrivalHouse]));
            $arrivalBlock = [
                'variant' => 'address',
                'address' => array_filter([
                    'search' => $search,
                    'flat'   => $arrivalFlat !== '' ? $arrivalFlat : null,
                ]),
            ];
        } else {
            // Нет адреса — доставка до ближайшего терминала по КЛАДР города
            $arrivalBlock = [
                'variant' => 'terminal',
                'city'    => $arrivalCityKladr,
            ];
        }

        // Добавляем интервал если адресная доставка
        $deliveryInterval = (string) ($order['delivery_interval'] ?? '');
        if ($deliveryInterval !== '' && ($arrivalBlock['variant'] ?? '') === 'address') {
            $parts = explode('-', $deliveryInterval);
            if (count($parts) === 2) {
                $arrivalBlock['time'] = [
                    'worktimeStart' => trim($parts[0]),
                    'worktimeEnd'   => trim($parts[1]),
                ];
            }
        }
        // Телефон получателя — для анонимного получателя нужен формат 7ХХХХХХХХХХ (11 цифр)
        $receiverPhoneNorm = $receiverPhone;
        if (strlen($receiverPhoneNorm) === 10) {
            $receiverPhoneNorm = '7' . $receiverPhoneNorm;
        }
        if (strlen($receiverPhoneNorm) !== 11 || $receiverPhoneNorm[0] !== '7') {
            $receiverPhoneNorm = '70000000000';
        }

        // Заказчик: uid обязателен для авторизованных пользователей с полным доступом к контрагентам
        $requester = array_filter([
            'role'  => 'sender',
            'email' => $settings->requesterEmail ?? '',
        ]);

        // freightUID: характер груза из настроек магазина
        $freightUid = $settings->freightUid ?? '';
        if ($freightUid === '') {
            throw new \RuntimeException(
                'Не задан UID характера груза (freight_uid). ' .
                    'Откройте настройки приложения и заполните поле «UID характера груза».'
            );
        }
        // ОПФ берём из настроек магазина, fallback — физлицо РФ
        $senderForm = $settings->senderOpfUid ?? '0xAB91FEEA04F6D4AD48DF42161B6C2E7A';

        $senderCounterAgent = [
            'form' => $senderForm,
            'name' => $settings->senderName ?? 'Отправитель',
        ];
        if ($settings->senderInn !== null) {
            $senderCounterAgent['inn'] = $settings->senderInn;
        }
        if ($settings->senderType === 'person') {
            $senderCounterAgent['document'] = [
                'type'   => $settings->senderDocType ?? 'passport',
                'serial' => $settings->senderDocSerial ?? '0000',
                'number' => $settings->senderDocNumber ?? '000000',
            ];
        }
        // Определяем блок получателя
        $isJuridical = str_contains($order['receiver_type'] ?? '', 'Juridical');
        $receiverInn = (string) ($order['receiver_inn'] ?? '');

        if ($isJuridical && $receiverInn !== '') {
            $receiverBlock = [
                'counteragent'   => [
                    'form'     => '0x976e9778e676cd8d473a4ada0fbad3f5',
                    'name'     => $receiverName ?: 'Получатель',
                    'inn'      => $receiverInn,
                    'isAnonym' => false,
                ],
                'contactPersons' => [['name' => $receiverName ?: 'Получатель']],
                'phoneNumbers'   => [['number' => $receiverPhoneNorm]],
            ];
        } else {
            $receiverBlock = [
                'counteragent' => [
                    'form'     => '0xAB91FEEA04F6D4AD48DF42161B6C2E7A',
                    'name'     => $receiverName ?: 'Получатель',
                    'isAnonym' => true,
                    'phone'    => $receiverPhoneNorm,
                ],
            ];
        }
        $body = [
            'appkey'    => $appkey,
            'sessionID' => $sessionId,
            'inOrder'   => true,
            'delivery'  => [
                'deliveryType' => ['type' => 'auto'],
                'derival'      => [
                    'produceDate' => $produceDate,
                    'variant'     => 'terminal',
                    'terminalID'  => (string) $settings->senderTerminalId,
                ],
                'arrival' => $arrivalBlock,
            ],
            'members' => [
                'requester' => [
                    'role'  => 'sender',
                    'uid'   => $settings->counteragentUid ?? '',
                    'email' => $settings->requesterEmail ?? '',
                ],
                'sender' => [
                    'counteragent'   => $senderCounterAgent,
                    'contactPersons' => [['name' => $settings->senderContactName ?? $settings->senderName ?? 'Отправитель']],
                    'phoneNumbers'   => [['number' => preg_replace('/\D/', '', $settings->senderContactPhone ?? '') ?: '70000000000']],
                ],
                'receiver' => $receiverBlock,
            ],
            'cargo' => [
                'quantity'    => 1,
                'weight'      => $weight,
                'totalWeight' => $weight,
                'length'      => 0.3,
                'width'       => 0.2,
                'height'      => 0.2,
                'totalVolume' => round(0.3 * 0.2 * 0.2, 4),
                'insurance'   => [
                    'statedValue' => $statedValue,
                    'term'        => true,
                ],
                'freightUID' => $freightUid,
            ],
            'payment' => [
                'type'         => 'noncash',
                'primaryPayer' => 'sender',
            ],
        ];
        $res = $this->postJson(self::URL_ORDER, $body);

        if (!empty($res['errors'])) {
            throw new \RuntimeException('Dellin order error: ' . json_encode($res['errors'], JSON_UNESCAPED_UNICODE));
        }

        $requestId = (int) ($res['data']['requestID'] ?? 0);
        $barcode   = (string) ($res['data']['barcode'] ?? '');

        if ($requestId === 0) {
            throw new \RuntimeException('Dellin не вернул requestID: ' . json_encode($res, JSON_UNESCAPED_UNICODE));
        }

        return ['request_id' => $requestId, 'barcode' => $barcode];
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
            // Integer counteragentID для использования в sender.counteragentID запроса заявки
            $counteragentId = null;
            foreach (['counteragentID', 'counterAgentId', 'id'] as $k) {
                if (isset($item[$k]) && is_numeric($item[$k]) && (int) $item[$k] > 0) {
                    $counteragentId = (int) $item[$k];
                    break;
                }
            }
            $byUid[$uid] = new DellinCounteragent($uid, $name, $counteragentId);
        }

        $list = array_values($byUid);
        usort($list, static fn(DellinCounteragent $a, DellinCounteragent $b): int => strcasecmp($a->name, $b->name));

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
     * Поиск ОПФ по названию из справочника Dellin.
     * @return list<array{uid: string, name: string, title: string}>
     */
    public function searchOpf(string $sessionId, string $query): array
    {
        $raw = $this->postJson('https://api.dellin.ru/v1/references/opf_list.json', [
            'appkey'    => $this->config->dellinAppkey,
            'sessionID' => $sessionId,
            'name'      => $query,
        ]);

        // Приоритет: сначала РФ, потом остальные
        $rf = [];
        $other = [];
        $seen = [];

        foreach ($raw['data'] ?? [] as $item) {
            $uid  = (string) ($item['uid'] ?? '');
            $name = (string) ($item['name'] ?? '');
            if ($uid === '' || $name === '') {
                continue;
            }
            $key = $name . '|' . ($item['countryUID'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $isRf = ($item['countryUID'] ?? '') === '0x8f51001438c4d49511dbd774581edb7a';
            $entry = [
                'uid'        => $uid,
                'name'       => $name,
                'title'      => (string) ($item['title'] ?? ''),
                'country_uid' => (string) ($item['countryUID'] ?? ''),
                'country_name' => self::COUNTRY_NAMES[$item['countryUID'] ?? ''] ?? '',
            ];
            if ($isRf) {
                $rf[] = $entry;
            } else {
                $other[] = $entry;
            }
        }

        return array_merge($rf, $other);
    }
    public function searchFreightTypes(string $q, int $page = 1, ?CarrierCredentials $credentials = null): array
    {
        $res = $this->postJson(self::URL_FREIGHT_SEARCH, [
            'appkey' => $this->config->dellinAppkey,
            'q'      => $q,
            'page'   => $page,
        ]);
        file_put_contents('/tmp/freight_debug.json', json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $items = [];
        foreach ($res['freightTypes'] ?? $res['data'] ?? [] as $ft) {
            $items[] = [
                'uid'   => (string) ($ft['uid']   ?? ''),
                'name'  => (string) ($ft['name']  ?? ''),
                'title' => (string) ($ft['title'] ?? ''),
            ];
        }
        return $items;
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

    /**
     * Передача артикулов грузовых мест (инициация генерации этикеток).
     */
    public function submitShipmentLabels(
        string $sessionId,
        string $orderId,
        ?string $cargoPlace = null,
        string $format = '80x50',
        ?CarrierCredentials $credentials = null,
    ): bool {
        $res = $this->postJson(
            'https://api.dellin.ru/v2/request/cargo/shipment_labels/batch.json',
            [
                'appkey'    => $this->resolveAppkey($credentials),
                'sessionID' => $sessionId,
                'format'    => $format,
                'orders'    => [[
                    'orderID'     => $orderId,
                    'cargoPlaces' => [[
                        'cargoPlace' => $cargoPlace,
                        'amount'     => 1,
                    ]],
                ]],
            ]
        );
        return ($res['data']['state'] ?? '') === 'enqueued';
    }

    /**
     * Получение ссылок на этикетки.
     * @return list<string>
     */
    public function getShipmentLabels(
        string $sessionId,
        string $orderId,
        ?CarrierCredentials $credentials = null,
    ): array {
        $res = $this->postJson(
            'https://api.dellin.ru/v2/request/cargo/shipment_labels/get/batch.json',
            [
                'appkey'    => $this->resolveAppkey($credentials),
                'sessionID' => $sessionId,
                'orderIDs'  => [$orderId],
            ]
        );
        foreach ($res['data'] ?? [] as $item) {
            if ((string) ($item['orderId'] ?? '') === $orderId) {
                $files = $item['files'] ?? [];
                // Нормализуем URL — добавляем https:// если отсутствует
                return array_map(static function (string $f): string {
                    if (str_starts_with($f, 'http')) {
                        return $f;
                    }
                    return 'https://' . ltrim($f, '/');
                }, $files);
            }
        }
        return [];
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

        $paddedCityKladr = str_pad($arrivalCityKladr, 25, '0');
        $arrival = [
            'variant' => 'address',
        ];

        if ($arrivalHouse !== null && $arrivalHouse !== '') {
            if ($streetKladr !== null && $streetKladr !== '') {
                $arrival['city'] = $paddedCityKladr;
                $arrival['address'] = [
                    'street' => $streetKladr,
                    'house' => $arrivalHouse,
                ];
            } elseif ($arrivalStreet !== null && $arrivalStreet !== '') {
                $cityName = $calcCtx->arrivalCityName ?? '';
                $searchStr = ($cityName !== '' ? $cityName . ', ' : '') . $arrivalStreet . ', ' . $arrivalHouse;
                $arrival['address'] = [
                    'search' => $searchStr,
                ];
            } else {
                $arrival['city'] = $paddedCityKladr;
            }
        } else {
            $arrival['city'] = $paddedCityKladr;
        }

        return [
            'sessionID' => $sessionId,
            'appkey' => $this->resolveAppkey($credentials),
            'delivery' => [
                'deliveryType' => [
                    'type' => 'auto',
                ],
                'arrival' => $arrival,
                'derival' => $this->buildDerival(
                    $calcCtx,
                    $senderTerminalId
                ),
                'packages' => [],
            ],
            'cargo' => $c,
            'members' => [
                'requester' => $requester,
            ],
            'payment' => $this->buildPayment($paddedCityKladr),
            'productInfo' => [
                'type' => 4,
                'productType' => 5,
                'info' => [
                    [
                        'param' => 'shipping-bridge',
                        'value' => 'mvp-1-city',
                    ],
                ],
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
        $l = (float) ($cargo['length'] ?? 0.2);
        $wd = (float) ($cargo['width'] ?? 0.2);
        $h = (float) ($cargo['height'] ?? 0.2);
        $q = (int) ($cargo['quantity'] ?? 1);
        $stated = (float) ($cargo['stated_value'] ?? 0.0);

        $w = max(0.01, $w);
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
            'totalVolume' => round($l * $wd * $h * $q, 4),
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
