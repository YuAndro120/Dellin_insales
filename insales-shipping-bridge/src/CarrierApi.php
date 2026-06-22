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
    private const URL_BOOK_COUNTERAGENTS = 'https://api.dellin.ru/v2/counteragents.json';
    private const URL_CALC = 'https://api.dellin.ru/v2/calculator.json';
    private const URL_KLADR = 'https://api.dellin.ru/v2/public/kladr.json';
    private const URL_KLADR_STREET = 'https://api.dellin.ru/v1/public/kladr_street.json';
    private const URL_ADDRESS_SUGGEST = 'https://api.dellin.ru/v1/public/address_suggest.json';
    private const URL_ADDRESS_CLEAN   = 'https://api.dellin.ru/v1/public/address_clean.json';
    private const URL_TERMINALS_MANIFEST = 'https://api.dellin.ru/v3/public/terminals.json';
    private const URL_ORDER = 'https://api.dellin.ru/v2/request.json';
    private const URL_FREIGHT_SEARCH = 'https://api.dellin.ru/v1/public/freight_types/search.json';
    private const URL_ADDRESS_DATES = 'https://api.dellin.ru/v2/request/address/dates.json';
    private const URL_ADDRESS_TIME_INTERVAL = 'https://api.dellin.ru/v2/request/address/time_interval.json';
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
            'pat'    => $credentials->pat,
        ]);
        return $this->extractSessionId($res);
    }

    /**
     * Контрагенты, доступные по PAT.
     * @return list<DellinCounteragent>
     */
    public function listCounteragents(CarrierCredentials $credentials): array
    {
        $loginRes = $this->postJson(self::URL_LOGIN_V4, [
            'appkey' => $credentials->appkey,
            'pat'    => $credentials->pat,
        ]);
        $sid = $this->extractSessionId($loginRes);
        $bookRes = $this->postJson(self::URL_BOOK_COUNTERAGENTS, [
            'appkey'    => $credentials->appkey,
            'sessionID' => $sid,
        ]);
        return $this->parseCounteragents($bookRes);
    }
 
    // ─────────────────────────────────────────────────────────────
    // Справочник упаковок
    // ─────────────────────────────────────────────────────────────

    /**
     * Справочник всех упаковок с названиями.
     * @return array<string,string>  uid => name
     */
    public function getPackagesReference(): array
    {
        $res = $this->postJson('https://api.dellin.ru/v1/public/request_services.json', [
            'appkey' => $this->config->dellinAppkey,
        ]);
        $url = $res['url'] ?? '';
        if ($url === '') return [];

        $csv = $this->http('GET', $url, null);
        $items = [];
        $lines = explode("\r\n", $csv);
        foreach ($lines as $i => $line) {
            if ($i === 0 || trim($line) === '') continue;
            $parts = str_getcsv($line, ',', '"');
            $uid  = trim($parts[1] ?? '');
            $name = trim($parts[2] ?? '');
            if ($uid === '' || $name === '') continue;
            $items[$uid] = $name;
        }
        return $items;
    }

    /**
     * Условия заказа: доступные упаковки, day_to_day, страхование.
     * @return array<string,mixed>
     */
    public function getRequestConditions(
        float $weight,
        float $volume,
        float $length,
        float $width,
        float $height,
        int $quantity = 1,
        ?string $derivalKladr = null,
        ?int $derivalTerminalId = null,
        string $deliveryType = 'auto',
    ): array {
        $typeIdMap = [
            'auto'          => 1,
            'avia'          => 2,
            'express'       => 6,
            'small' => 7,
        ];
        $body = [
            'appkey'       => $this->config->dellinAppkey,
            'blocks'       => ['packages', 'day_to_day', 'insurance'],
            'weight'       => $weight,
            'volume'       => $volume,
            'length'       => $length,
            'width'        => $width,
            'height'       => $height,
            'quantity'     => $quantity,
            'deliveryType' => $typeIdMap[$deliveryType] ?? 1,
        ];
        if ($derivalKladr !== null && $derivalKladr !== '') {
            $body['derivalPoint'] = $derivalKladr;
        }
        if ($derivalTerminalId !== null && $derivalTerminalId > 0) {
            $body['derivalTerminalID'] = $derivalTerminalId;
        }
        $body['arrivalPoint'] = $derivalKladr ?? '7700000000000000000000000';
        return $this->postJson(
            'https://api.dellin.ru/v1/public/request_conditions.json',
            $body
        );
    }
 
    // ─────────────────────────────────────────────────────────────
    // Оформление заявки
    // ─────────────────────────────────────────────────────────────

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
        string $deliveryType = 'auto',
    ): array {
        $appkey = $this->resolveAppkey($credentials);
        $arrivalCityKladr = str_pad((string) ($order['arrival_city_kladr'] ?? ''), 25, '0');
        $arrivalStreet    = (string) ($order['arrival_street'] ?? '');
        $arrivalHouse     = (string) ($order['arrival_house'] ?? '');
        $arrivalFlat      = (string) ($order['arrival_flat'] ?? '');
        $weight           = max(0.1, (float) ($order['weight'] ?? 1.0));
        $totalWeight      = max($weight, (float) ($order['total_weight'] ?? $weight));
        $statedValue      = max(1.0, (float) ($order['stated_value'] ?? 1000.0));
        $receiverName     = (string) ($order['receiver_name'] ?? 'Получатель');
        $receiverPhone    = preg_replace('/\D/', '', (string) ($order['receiver_phone'] ?? ''));
        // Если покупатель выбрал ПВЗ — игнорируем адрес, используем терминал
        $dellinDeliveryType = (string) ($order['dellin_delivery_type'] ?? '');
        $dellinTerminalId   = (string) ($order['dellin_terminal_id'] ?? '');
        if ($dellinDeliveryType === 'pickup') {
            $arrivalStreet = '';
            $arrivalHouse  = '';
            $arrivalFlat   = '';
        }

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

        // Блок прибытия
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
            $search   = implode(', ', array_filter([$cityName, $arrivalStreet, $arrivalHouse]));
            $arrivalBlock = [
                'variant' => 'address',
                'address' => array_filter([
                    'search' => $search,
                    'flat'   => $arrivalFlat !== '' ? $arrivalFlat : null,
                ]),
            ];
        } else {
            $realTerminalId = $dellinTerminalId !== '' ? (int) $dellinTerminalId : 0;
            if ($realTerminalId > 0) {
                $arrivalBlock = [
                    'variant'    => 'terminal',
                    'terminalID' => $realTerminalId,
                ];
            } else {
                $cityKladrForTerminal = $arrivalCityKladr !== ''
                    ? str_pad(substr($arrivalCityKladr, 0, 13), 25, '0')
                    : '';
                $arrivalBlock = [
                    'variant' => 'terminal',
                    'city'    => $cityKladrForTerminal,
                ];
            }
        }

        // Интервал доставки
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

        // Телефон получателя
        $receiverPhoneNorm = $receiverPhone;
        if (strlen($receiverPhoneNorm) === 10) {
            $receiverPhoneNorm = '7' . $receiverPhoneNorm;
        }
        if (strlen($receiverPhoneNorm) !== 11 || $receiverPhoneNorm[0] !== '7') {
            $receiverPhoneNorm = '70000000000';
        }

        // freightUID
        $freightUid = $settings->freightUid ?? '';
        if ($freightUid === '') {
            throw new \RuntimeException(
                'Не задан UID характера груза (freight_uid). ' .
                    'Откройте настройки приложения и заполните поле «Характер груза».'
            );
        }

        // ОПФ отправителя
        if ($settings->senderType === 'person') {
            $senderForm = '0xAB91FEEA04F6D4AD48DF42161B6C2E7A';
        } else {
            $senderForm = $settings->senderOpfUid ?? '0xbc1e63c5f81187e244490a5afd657cbd';
        }

        $senderCounterAgent = [
            'form' => $senderForm,
            'name' => $settings->senderName ?? 'Отправитель',
        ];
        if ($settings->senderInn !== null) {
            $senderCounterAgent['inn'] = $settings->senderInn;
        }
        if ($settings->senderType === 'person') {
            $senderCounterAgent['document'] = [
                'type'   => $settings->senderDocType   ?? 'passport',
                'serial' => $settings->senderDocSerial ?? '0000',
                'number' => $settings->senderDocNumber ?? '000000',
            ];
        }

        // Блок получателя
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

        // Габариты
        $dims = ($order['dimensions_cm'] ?? '') !== ''
            ? $order['dimensions_cm']
            : $settings->defaultDimensionsCm;
        $dimParts = array_map('floatval', explode('x', strtolower($dims)));
        $dimL = isset($dimParts[0]) && $dimParts[0] > 0 ? round($dimParts[0] / 100, 2) : 0.30;
        $dimW = isset($dimParts[1]) && $dimParts[1] > 0 ? round($dimParts[1] / 100, 2) : 0.20;
        $dimH = isset($dimParts[2]) && $dimParts[2] > 0 ? round($dimParts[2] / 100, 2) : 0.20;
        // Защита: если все три нуля (например "0x0x0") — подставляем дефолт
        if ($dimL <= 0 && $dimW <= 0 && $dimH <= 0) {
            $dimL = 0.30;
            $dimW = 0.20;
            $dimH = 0.20;
        }

        // Упаковка из настроек (UID из request_services.csv принимается ДЛ API напрямую)
        $packageUid = ($settings->packageInCalc ?? false) ? ($settings->packageUid ?? '') : '';

        // Блок cargo: quantity и негабарит берём из реальной агрегации позиций
        // заказа (CargoFromInsalesOrder::aggregate через parseInsalesOrder),
        // чтобы итоговая заявка совпадала с тем, что показывалось покупателю
        // при расчёте стоимости в корзине.
        $quantity = max(1, (int) ($order['quantity'] ?? 1));
        $oversizedWeight = (float) ($order['oversized_weight'] ?? 0.0);
        $oversizedVolume = (float) ($order['oversized_volume'] ?? 0.0);

        $cargoBlock = [
            'quantity'    => $quantity,
            'weight'      => $weight,
            'totalWeight' => $totalWeight,
            'length'      => $dimL,
            'width'       => $dimW,
            'height'      => $dimH,
            'totalVolume' => round($dimL * $dimW * $dimH, 4),
            'insurance'   => [
                'statedValue' => $statedValue,
                'term'        => true,
            ],
            'freightUID'  => $freightUid,
        ];

        if ($oversizedWeight > 0 || $oversizedVolume > 0) {
            $cargoBlock['oversizedWeight'] = round($oversizedWeight, 2);
            $cargoBlock['oversizedVolume'] = round($oversizedVolume, 4);
        }

        // Блок derival (терминал или адрес)
        $derivalBlock = $this->buildDerivalForOrder(
            $settings,
            $produceDate,
            (string) ($order['derival_date'] ?? ''),
            (string) ($order['derival_time'] ?? '')
        );

        $managerComment = trim((string) ($order['manager_comment'] ?? ''));
        $body = [
            'appkey'    => $appkey,
            'sessionID' => $sessionId,
            'inOrder'   => true,
            'delivery'  => [
                'deliveryType' => ['type' => $deliveryType],
                'derival'      => $derivalBlock,
                'arrival'      => $arrivalBlock,
                'packages'     => $packageUid !== '' ? [['uid' => $packageUid, 'count' => 1]] : [],
                'comment'      => $managerComment !== '' ? $managerComment : null,
            ],
            'members' => [
                'requester' => [
                    'role'  => $settings->requesterRole ?? 'sender',
                    'uid'   => $settings->counteragentUid ?? '',
                    'email' => $settings->requesterEmail ?? '',
                ],
                'sender' => [
                    'counteragent'   => $senderCounterAgent,
                    'contactPersons' => [['name' => $settings->senderContactName ?? $settings->senderName ?? 'Отправитель']],
                    'phoneNumbers'   => [['number' => preg_replace('/\D/', '', $settings->senderContactPhone ?? '') ?: '70000000000']],
                    'dataForReceipt' => [
                        'send'        => true,
                        'email'       => $settings->requesterEmail ?? null,
                        'phoneNumber' => preg_replace('/\D/', '', $settings->senderContactPhone ?? '') ?: null,
                    ],
                ],
                'receiver' => $receiverBlock,
            ],
            'cargo'   => $cargoBlock,
            'payment' => [
                'type'         => 'noncash',
                'primaryPayer' => 'sender',
            ],
        ];
        $orderIdForLog = (string) ($order['insales_order_id'] ?? '');
        \ShippingBridge\Logger::info($settings->insalesId, $orderIdForLog, 'order.create.request', [
            'delivery_type' => $deliveryType,
            'dellin_delivery_type' => $dellinDeliveryType,
            'dellin_terminal_id' => $dellinTerminalId,
            'body' => \ShippingBridge\Logger::maskSensitiveFields($body),
        ]);

        $res = $this->postJson(self::URL_ORDER, $body);

        if (!empty($res['errors'])) {
            \ShippingBridge\Logger::error($settings->insalesId, $orderIdForLog, 'order.create.error', [
                'errors' => $res['errors'],
                'response' => \ShippingBridge\Logger::maskSensitiveFields($res),
            ]);
            throw new \RuntimeException('Dellin order error: ' . json_encode($res['errors'], JSON_UNESCAPED_UNICODE));
        }

        $requestId = (int) ($res['data']['requestID'] ?? 0);
        $barcode   = (string) ($res['data']['barcode'] ?? '');

        if ($requestId === 0) {
            \ShippingBridge\Logger::error($settings->insalesId, $orderIdForLog, 'order.create.no_request_id', [
                'response' => $res,
            ]);
            throw new \RuntimeException('Dellin не вернул requestID: ' . json_encode($res, JSON_UNESCAPED_UNICODE));
        }

        \ShippingBridge\Logger::info($settings->insalesId, $orderIdForLog, 'order.create.success', [
            'request_id' => $requestId,
            'barcode' => $barcode,
            'response' => \ShippingBridge\Logger::maskSensitiveFields($res),
            'response_times' => self::summarizeResponseTimes(),
        ]);

        return ['request_id' => $requestId, 'barcode' => $barcode];
    }
    /**
     * Получить доступные даты забора груза от адреса отправителя.
     * @return list<string> массив дат в формате Y-m-d
     */
    public function getAddressDates(
        string $sessionId,
        \ShippingBridge\ShopSettings $settings,
        string $deliveryType = 'auto',
        ?CarrierCredentials $credentials = null,
    ): array {
        $cityKladr = $settings->derivalCityKladr ?? '';
        $cityName  = $settings->derivalCityName  ?? '';
        $street    = $settings->derivalStreet    ?? '';
        $house     = $settings->derivalHouse     ?? '';
        if ($cityKladr === '' || $street === '' || $house === '') {
            throw new \RuntimeException('Адрес забора груза не заполнен в настройках.');
        }
        $search = implode(', ', array_filter([$cityName, $street, $house]));

        $body = [
            'appkey'    => $this->resolveAppkey($credentials),
            'sessionID' => $sessionId,
            'delivery'  => [
                'deliveryType' => ['type' => $deliveryType],
                'derival'      => [
                    'address' => ['search' => $search],
                ],
            ],
            'cargo' => [
                'quantity'    => 1,
                'weight'      => 1.0,
                'height'      => 0.2,
                'width'       => 0.2,
                'length'      => 0.2,
                'totalVolume' => 0.008,
                'totalWeight' => 1.0,
            ],
        ];

        $res = $this->postJson(self::URL_ADDRESS_DATES, $body);
        if (!empty($res['errors'])) {
            throw new \RuntimeException('Dellin dates error: ' . json_encode($res['errors'], JSON_UNESCAPED_UNICODE));
        }
        $dates = $res['data']['dates'] ?? [];
        return $dates;
    }

    /**
     * Получить допустимый интервал времени приезда экспедитора на дату забора.
     * @return array{interval_from:string,interval_to:string,default_min_same_day_period:int,min_same_day_period:int,min_period:int,same_day:bool}
     */
    public function getAddressTimeInterval(
        string $sessionId,
        \ShippingBridge\ShopSettings $settings,
        string $produceDate,
        string $deliveryType = 'auto',
        ?CarrierCredentials $credentials = null,
    ): array {
        $cityKladr = $settings->derivalCityKladr ?? '';
        $cityName  = $settings->derivalCityName  ?? '';
        $street    = $settings->derivalStreet    ?? '';
        $house     = $settings->derivalHouse     ?? '';
        if ($cityKladr === '' || $street === '' || $house === '') {
            throw new \RuntimeException('Адрес забора груза не заполнен в настройках.');
        }
        $search = implode(', ', array_filter([$cityName, $street, $house]));

        $body = [
            'appkey'    => $this->resolveAppkey($credentials),
            'sessionID' => $sessionId,
            'delivery'  => [
                'deliveryType' => ['type' => $deliveryType],
                'derival'      => [
                    'produceDate' => $produceDate,
                    'address'     => ['search' => $search],
                ],
            ],
        ];

        $res = $this->postJson(self::URL_ADDRESS_TIME_INTERVAL, $body);
        if (!empty($res['errors'])) {
            throw new \RuntimeException('Dellin time interval error: ' . json_encode($res['errors'], JSON_UNESCAPED_UNICODE));
        }
        return $res['data'] ?? [];
    }
    // ─────────────────────────────────────────────────────────────
    // Расчёт стоимости
    // ─────────────────────────────────────────────────────────────

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
        string $deliveryType = 'auto',
        ?string $insalesIdForLog = null,
    ): array {
        $body = $this->buildCalculatorBody(
            $sessionId,
            $senderTerminalId,
            $arrivalTerminalId,
            $arrivalCityKladr,
            $cargo,
            $calcCtx,
            $credentials,
            $deliveryType,
        );
        if ($insalesIdForLog !== null) {
            \ShippingBridge\Logger::info($insalesIdForLog, null, 'calc.terminal.request', [
                'terminal_id' => $arrivalTerminalId,
                'delivery_type' => $deliveryType,
                'body' => \ShippingBridge\Logger::maskSensitiveFields($body),
            ]);
        }
        $res = $this->postJson(self::URL_CALC, $body);
        if ($insalesIdForLog !== null) {
            \ShippingBridge\Logger::info($insalesIdForLog, null, 'calc.terminal.response', [
                'terminal_id' => $arrivalTerminalId,
                'body' => \ShippingBridge\Logger::maskSensitiveFields($res),
                'response_times' => self::summarizeResponseTimes(),
            ]);
        }
        return $this->parseCalculatorResponse($res, 'terminal');
    }

    /**
     * Параллельный расчёт стоимости до нескольких терминалов одновременно
     * (через curl_multi) — на порядок быстрее последовательных вызовов
     * при большом списке ПВЗ. Поддерживает date-fallback: если для части
     * терминалов исходная дата недоступна (ошибка 180012), делает второй
     * параллельный проход только по ним со сдвинутой датой, и так далее
     * до $maxExtraDays.
     *
     * @param list<int> $terminalIds
     * @return array<int, array{price:float|null,days:int|null,metadata:array,raw?:array,errors?:mixed}|null>
     *         ключ — terminalID, значение — результат расчёта или null при полном провале (после всех попыток)
     */
    public function calculateToTerminalsBatch(
        string $sessionId,
        int $senderTerminalId,
        array $terminalIds,
        ?string $arrivalCityKladr,
        array $cargo,
        CalculatorContext $calcCtx,
        ?CarrierCredentials $credentials = null,
        string $deliveryType = 'auto',
        int $maxExtraDays = 5,
        ?string $insalesIdForLog = null,
    ): array {
        $results = [];
        $pending = array_values(array_unique($terminalIds));
        $bodies = [];

        for ($extra = 0; $extra <= $maxExtraDays && $pending !== []; $extra++) {
            $ctx = $extra === 0 ? $calcCtx : $calcCtx->withProduceDaysOffset($calcCtx->produceDaysOffset + $extra);

            $bodies = [];
            foreach ($pending as $tid) {
                $bodies[$tid] = $this->buildCalculatorBody(
                    $sessionId,
                    $senderTerminalId,
                    $tid,
                    $arrivalCityKladr,
                    $cargo,
                    $ctx,
                    $credentials,
                    $deliveryType,
                );
            }

            $rawResponses = $this->postJsonMulti(self::URL_CALC, $bodies);

            $stillPending = [];
            foreach ($pending as $tid) {
                $raw = $rawResponses[$tid] ?? null;
                if ($raw === null) {
                    // Сетевая ошибка/таймаут на этот конкретный запрос — не повторяем по дате,
                    // это не "дата недоступна", а сбой сети.
                    continue;
                }
                $parsed = $this->parseCalculatorResponse($raw, 'terminal');
                $errorsJson = isset($parsed['errors']) ? json_encode($parsed['errors'], JSON_UNESCAPED_UNICODE) : '';
                if ($parsed['price'] === null && str_contains($errorsJson, '180012')) {
                    $stillPending[] = $tid;
                    continue;
                }
                $results[$tid] = $parsed;
            }
            $pending = $stillPending;
        }

        // Терминалы, для которых так и не нашлось доступной даты за все попытки.
        foreach ($pending as $tid) {
            $results[$tid] = null;
        }
        if ($insalesIdForLog !== null) {
            $sampleBody = $bodies !== [] ? reset($bodies) : null;
            \ShippingBridge\Logger::info($insalesIdForLog, null, 'calc.terminals_batch.summary', [
                'delivery_type' => $deliveryType,
                'requested_terminals' => count($terminalIds),
                'resolved' => count(array_filter($results, static fn($r) => $r !== null)),
                'sample_request_body' => $sampleBody !== null ? \ShippingBridge\Logger::maskSensitiveFields($sampleBody) : null,
                'results_by_terminal' => array_map(
                    static fn($r) => $r !== null ? ['price' => $r['price'] ?? null, 'days' => $r['days'] ?? null, 'errors' => $r['errors'] ?? null] : null,
                    $results
                ),
                'response_times' => self::summarizeResponseTimes(),
            ]);
        }
        return $results;
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
        string $deliveryType = 'auto',
        ?string $insalesIdForLog = null,
    ): array {
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
            $deliveryType,
        );
        if ($insalesIdForLog !== null) {
            \ShippingBridge\Logger::info($insalesIdForLog, null, 'calc.city.request', [
                'delivery_type' => $deliveryType,
                'body' => \ShippingBridge\Logger::maskSensitiveFields($body),
            ]);
        }
        $res = $this->postJson(self::URL_CALC, $body);
        if ($insalesIdForLog !== null) {
            \ShippingBridge\Logger::info($insalesIdForLog, null, 'calc.city.response', [
                'body' => \ShippingBridge\Logger::maskSensitiveFields($res),
                'response_times' => self::summarizeResponseTimes(),
            ]);
        }
        return $this->parseCalculatorResponse($res, 'address');
    }
 
    // ─────────────────────────────────────────────────────────────
    // Справочники
    // ─────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    public function searchCities(string $q, ?CarrierCredentials $credentials = null): array
    {
        $creds  = $credentials ?? $this->config->defaultCarrierCredentials();
        $appkey = $creds?->appkey ?? $this->config->dellinAppkey;
        if ($appkey === '') {
            throw new \RuntimeException('API-ключ Dellin не задан');
        }
        $raw    = $this->postJson(self::URL_KLADR, ['appkey' => $appkey, 'q' => $q]);
        $cities = $raw['cities'] ?? [];
        return is_array($cities) ? array_values($cities) : [];
    }

    /** @return list<array{uid: string, name: string, title: string}> */
    public function searchOpf(string $sessionId, string $query): array
    {
        $raw = $this->postJson('https://api.dellin.ru/v1/references/opf_list.json', [
            'appkey'    => $this->config->dellinAppkey,
            'sessionID' => $sessionId,
            'name'      => $query,
        ]);
        $rf    = [];
        $other = [];
        $seen  = [];
        foreach ($raw['data'] ?? [] as $item) {
            $uid  = (string) ($item['uid']  ?? '');
            $name = (string) ($item['name'] ?? '');
            if ($uid === '' || $name === '') continue;
            $key = $name . '|' . ($item['countryUID'] ?? '');
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $isRf = ($item['countryUID'] ?? '') === '0x8f51001438c4d49511dbd774581edb7a';
            $entry = [
                'uid'          => $uid,
                'name'         => $name,
                'title'        => (string) ($item['title']      ?? ''),
                'country_uid'  => (string) ($item['countryUID'] ?? ''),
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
            'name'   => $q,
            'page'   => $page,
        ]);
        $items = [];
        foreach ($res['freight_types'] ?? $res['freightTypes'] ?? $res['data'] ?? [] as $ft) {
            $items[] = [
                'uid'  => (string) ($ft['sqlUID'] ?? $ft['uid']  ?? ''),
                'name' => (string) ($ft['value']  ?? $ft['name'] ?? ''),
            ];
        }
        return $items;
    }

    public function getCityId(string $cityKladr, ?CarrierCredentials $credentials = null): ?int
    {
        $appkey = $this->resolveAppkey($credentials);
        $raw    = $this->postJson(self::URL_KLADR, ['appkey' => $appkey, 'code' => $cityKladr]);
        $cities = $raw['cities'] ?? [];
        return isset($cities[0]['cityID']) ? (int) $cities[0]['cityID'] : null;
    }

    public function getStreetKladr(int $cityId, string $streetName, ?CarrierCredentials $credentials = null): ?string
    {
        $appkey  = $this->resolveAppkey($credentials);
        $raw     = $this->postJson(self::URL_KLADR_STREET, [
            'appkey' => $appkey,
            'cityID' => $cityId,
            'street' => $streetName,
            'limit' => 1,
        ]);
        $streets = $raw['streets'] ?? [];
        return isset($streets[0]['code']) ? (string) $streets[0]['code'] : null;
    }

    /** @return list<array{value: string, data: array<string, mixed>}> */
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

    /** @return array<string, mixed>|null */
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

    /** @return array{url:string,hash?:string} */
    public function terminalsManifest(?CarrierCredentials $credentials = null): array
    {
        $creds  = $credentials ?? $this->config->defaultCarrierCredentials();
        $appkey = $creds?->appkey ?? $this->config->dellinAppkey;
        if ($appkey === '') throw new \RuntimeException('API-ключ Dellin не задан');
        $res = $this->postJson(self::URL_TERMINALS_MANIFEST, ['appkey' => $appkey]);
        if (empty($res['url']) || !is_string($res['url'])) {
            throw new \RuntimeException('Terminals manifest: missing url. ' . json_encode($res, JSON_UNESCAPED_UNICODE));
        }
        return ['url' => $res['url'], 'hash' => $res['hash'] ?? null];
    }

    public function fetchTerminalsDataset(string $url): array
    {
        $json = $this->http('GET', $url, null);
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return is_array($data) ? $data : [];
    }

    // ─────────────────────────────────────────────────────────────
    // Этикетки
    // ─────────────────────────────────────────────────────────────

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
                    'cargoPlaces' => [['cargoPlace' => $cargoPlace, 'amount' => 1]],
                ]],
            ]
        );
        return ($res['data']['state'] ?? '') === 'enqueued';
    }

    /** @return list<string> */
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
                return array_map(static function (string $f): string {
                    return str_starts_with($f, 'http') ? $f : 'https://' . ltrim($f, '/');
                }, $item['files'] ?? []);
            }
        }
        return [];
    }

    // ─────────────────────────────────────────────────────────────
    // Приватные методы
    // ─────────────────────────────────────────────────────────────

    private function buildDerivalForOrder(
        \ShippingBridge\ShopSettings $settings,
        string $produceDate,
        string $derivalDate = '',
        string $derivalTime = '',
    ): array {
        if ($settings->isDerivalTerminal()) {
            return [
                'produceDate'  => $derivalDate !== '' ? $derivalDate : $produceDate,
                'variant'      => 'terminal',
                'terminalID'   => (string) $settings->senderTerminalId,
                'requirements' => [],
            ];
        }
        $cityKladr = $settings->derivalCityKladr ?? '';
        $cityName  = $settings->derivalCityName  ?? '';
        $street    = $settings->derivalStreet    ?? '';
        $house     = $settings->derivalHouse     ?? '';
        if ($cityKladr === '' || $street === '' || $house === '') {
            throw new \RuntimeException(
                'Заполните адрес забора груза в настройках приложения (город, улица, дом).'
            );
        }
        $search = implode(', ', array_filter([$cityName, $street, $house]));

        $derival = [
            'produceDate'  => $derivalDate !== '' ? $derivalDate : $produceDate,
            'variant'      => 'address',
            'address'      => ['search' => $search],
            'requirements' => [],
        ];

        if ($derivalTime !== '' && str_contains($derivalTime, '-')) {
            [$timeFrom, $timeTo] = explode('-', $derivalTime, 2);
            $derival['time'] = [
                'worktimeStart' => trim($timeFrom),
                'worktimeEnd'   => trim($timeTo),
            ];
        } else {
            $derival['time'] = ['worktimeStart' => '09:00', 'worktimeEnd' => '17:00'];
        }

        return $derival;
    }

    private function buildCalculatorBody(
        string $sessionId,
        int $senderTerminalId,
        int $arrivalTerminalId,
        ?string $arrivalCityKladr,
        array $cargo,
        CalculatorContext $calcCtx,
        ?CarrierCredentials $credentials,
        string $deliveryType = 'auto',
    ): array {
        $c         = $this->normalizeCargo($cargo);
        $requester = $this->buildRequester($calcCtx);
        $body = [
            'sessionID' => $sessionId,
            'appkey'    => $this->resolveAppkey($credentials),
            'delivery'  => [
                'deliveryType' => ['type' => $deliveryType],
                'arrival'      => [
                    'variant'      => 'terminal',
                    'terminalID'   => $arrivalTerminalId,
                    'requirements' => [],
                ],
                'derival'  => $this->buildDerival($calcCtx, $senderTerminalId),
                'packages' => ($calcCtx->packageInCalc && $calcCtx->packageUid !== '')
                    ? [['uid' => $calcCtx->packageUid, 'count' => 1]]
                    : [],
            ],
            'cargo'       => $c,
            'members'     => ['requester' => $requester],
            'payment'     => $this->buildPayment($arrivalCityKladr),
            'productInfo' => [
                'type'        => 4,
                'productType' => 5,
                'info'        => [['param' => 'DL Connect', 'value' => 'mvp 0.1.']],
            ],
        ];
        return $body;
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
        string $deliveryType = 'auto',
    ): array {
        $c               = $this->normalizeCargo($cargo);
        $requester       = $this->buildRequester($calcCtx);
        $paddedCityKladr = str_pad($arrivalCityKladr, 25, '0');
        $arrival         = ['variant' => 'address'];

        if ($arrivalHouse !== null && $arrivalHouse !== '') {
            if ($streetKladr !== null && $streetKladr !== '') {
                $arrival['city']    = $paddedCityKladr;
                $arrival['address'] = ['street' => $streetKladr, 'house' => $arrivalHouse];
            } elseif ($arrivalStreet !== null && $arrivalStreet !== '') {
                $cityName   = $calcCtx->arrivalCityName ?? '';
                $searchStr  = ($cityName !== '' ? $cityName . ', ' : '') . $arrivalStreet . ', ' . $arrivalHouse;
                $arrival['address'] = ['search' => $searchStr];
            } else {
                $arrival['city'] = $paddedCityKladr;
            }
        } else {
            $arrival['city'] = $paddedCityKladr;
        }

        return [
            'sessionID'   => $sessionId,
            'appkey'      => $this->resolveAppkey($credentials),
            'delivery'    => [
                'deliveryType' => ['type' => $deliveryType],
                'arrival'      => $arrival,
                'derival'      => $this->buildDerival($calcCtx, $senderTerminalId),
                'packages' => ($calcCtx->packageInCalc && $calcCtx->packageUid !== '')
                    ? [['uid' => $calcCtx->packageUid, 'count' => 1]]
                    : [],
            ],
            'cargo'       => $c,
            'members'     => ['requester' => $requester],
            'payment'     => $this->buildPayment($paddedCityKladr),
            'productInfo' => [
                'type'        => 4,
                'productType' => 5,
                'info'        => [['param' => 'DL Connect', 'value' => 'mvp 0.1.']],
            ],
        ];
    }

    private function buildDerival(CalculatorContext $calcCtx, int $senderTerminalId): array
    {
        $produce = date('Y-m-d', strtotime('+' . $calcCtx->produceDaysOffset . ' days'));

        if ($calcCtx->derivalVariant === ShopSettings::DERIVAL_ADDRESS) {
            $city   = $calcCtx->derivalCityKladr ?? '';
            $street = $calcCtx->derivalStreet    ?? '';
            $house  = $calcCtx->derivalHouse     ?? '';
            if (strlen($city) < 10 || $street === '' || $house === '') {
                throw new \InvalidArgumentException('Адрес забора груза не заполнен');
            }
            $cityName = $calcCtx->derivalCityName ?? '';
            $search = implode(', ', array_filter([$cityName, $street, $house]));
            return [
                'variant'      => 'address',
                'address'      => ['search' => $search],
                'produceDate'  => $produce,
                'time'         => ['worktimeStart' => '09:00', 'worktimeEnd' => '18:00'],
                'requirements' => [],
            ];
        }

        if ($senderTerminalId <= 0) {
            throw new \InvalidArgumentException('Терминал отгрузки не настроен');
        }
        return [
            'variant'      => 'terminal',
            'terminalID'   => $senderTerminalId,
            'produceDate'  => $produce,
            'requirements' => [],
        ];
    }

    private function buildRequester(CalculatorContext $calcCtx): array
    {
        $requester = [
            'role'  => $calcCtx->requesterRole ?? 'sender',
            'email' => $calcCtx->requesterEmail,
        ];
        if ($calcCtx->counteragentUid !== null && $calcCtx->counteragentUid !== '') {
            $requester['uid'] = $calcCtx->counteragentUid;
        }
        return $requester;
    }

    private function resolveAppkey(?CarrierCredentials $credentials): string
    {
        $creds  = $credentials ?? $this->config->defaultCarrierCredentials();
        $appkey = $creds?->appkey ?? $this->config->dellinAppkey;
        if ($appkey === '') throw new \RuntimeException('API-ключ Dellin не задан');
        return $appkey;
    }

    private function buildPayment(?string $paymentCityKladr): array
    {
        $payment = ['type' => 'noncash', 'primaryPayer' => 'sender'];
        if ($paymentCityKladr !== null && $paymentCityKladr !== '') {
            $payment['paymentCity'] = str_pad($paymentCityKladr, 25, '0');
        }
        return $payment;
    }

    private function normalizeCargo(array $cargo): array
    {
        $w      = max(0.01, (float) ($cargo['weight']   ?? 1.0));
        $l      = max(0.01, (float) ($cargo['length']   ?? 0.2));
        $wd     = max(0.01, (float) ($cargo['width']    ?? 0.2));
        $h      = max(0.01, (float) ($cargo['height']   ?? 0.2));
        $q      = max(1,    (int)   ($cargo['quantity'] ?? 1));
        $stated = (float) ($cargo['stated_value'] ?? 0.0);

        $result = [
            'quantity'    => $q,
            'length'      => round($l,  2),
            'width'       => round($wd, 2),
            'height'      => round($h,  2),
            'weight'      => round($w,  2),
            'totalVolume' => round($l * $wd * $h * $q, 4),
            'totalWeight' => round($w * $q, 2),
            'insurance'   => [
                'statedValue' => round($stated, 2),
                'payer'       => 'sender',
                'term'        => false,
            ],
        ];

        // Негабарит: вес ≥ 800 кг ИЛИ хотя бы одна грань ≥ 3 м для места.
        // oversizedWeight/oversizedVolume считаются заранее в CargoFromInsalesOrder
        // на уровне отдельных позиций заказа (там известны реальные габариты
        // каждого товара, не усреднённые максимумы) и передаются сюда готовыми.
        $oversizedWeight = (float) ($cargo['oversized_weight'] ?? 0.0);
        $oversizedVolume = (float) ($cargo['oversized_volume'] ?? 0.0);
        if ($oversizedWeight > 0 || $oversizedVolume > 0) {
            $result['oversizedWeight'] = round($oversizedWeight, 2);
            $result['oversizedVolume'] = round($oversizedVolume, 4);
        }

        return $result;
    }

    private function parseCounteragents(array $res): array
    {
        if (!empty($res['errors'])) return [];
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
            if (!is_array($item)) continue;
            $uid = $item['uid'] ?? $item['UID'] ?? $item['counteragentUID'] ?? $item['id'] ?? null;
            if ($uid === null || $uid === '') continue;
            $uid  = (string) $uid;
            $name = trim((string) (
                $item['name'] ?? $item['brand'] ?? $item['juridicalName'] ??
                $item['fullName'] ?? $item['title'] ?? ''
            ));
            if ($name === '') $name = 'Контрагент ' . $uid;
            $inn = trim((string) ($item['inn'] ?? ''));
            if ($inn !== '') $name .= ' (ИНН ' . $inn . ')';
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
        if ($raw === null) return [];
        if (is_array($raw) && array_is_list($raw)) return $raw;
        if (is_array($raw)) {
            foreach (['counteragent', 'counteragents', 'member', 'members'] as $key) {
                if (isset($raw[$key])) return $this->normalizeList($raw[$key]);
            }
            return array_values($raw);
        }
        return [];
    }

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

    private function parseCalculatorResponse(array $res, string $arrivalVariant = ''): array
    {
        $meta   = $res['metadata'] ?? [];
        $status = (int) ($meta['status'] ?? 0);
        $errors = $res['errors'] ?? null;
        if ($errors !== null || $status !== 200) {
            return ['price' => null, 'days' => null, 'metadata' => is_array($meta) ? $meta : [], 'errors' => $errors, 'raw' => $res];
        }
        $data  = $res['data'] ?? [];
        $price = isset($data['price']) ? (float) $data['price'] : null;
        $days  = $this->extractDeliveryDays($data['orderDates'] ?? [], $arrivalVariant);
        return ['price' => $price, 'days' => $days, 'metadata' => is_array($meta) ? $meta : [], 'raw' => $res];
    }

    /**
     * Извлекает срок доставки (в днях от сегодня) из orderDates ответа
     * калькулятора ДЛ — строго по конечной точке маршрута, без
     * промежуточных дат (риск показать заниженный/неверный срок клиенту).
     *
     * Подтверждённая (не предполагаемая) семантика полей по сценариям:
     * - arrival.variant === 'address' и тип avia/express → derivalToAddress
     *   (поле гарантированно присутствует только для avia/express, согласно
     *   официальной документации ДЛ).
     * - arrival.variant === 'address' и тип auto → derivalFromOspReceiver
     *   (последнее заполняемое поле в наборе orderDates для этого сценария,
     *   подтверждено реальным ответом API: arrivalToOspReceiver — лишь
     *   прибытие на терминал, ещё не у клиента).
     * - arrival.variant === 'terminal' (самовывоз/ПВЗ) → giveoutFromOspReceiver
     *   (момент готовности груза к выдаче клиенту на терминале).
     *
     * Если для сценария ожидаемое поле отсутствует — возвращаем null
     * (UI покажет "Срок уточняется"), не подставляем промежуточную дату.
     *
     * @param array<string,mixed> $orderDates
     */
    private function extractDeliveryDays(array $orderDates, string $arrivalVariant): ?int
    {
        if ($arrivalVariant === 'terminal') {
            $field = 'giveoutFromOspReceiver';
        } else {
            $field = isset($orderDates['derivalToAddress']) && $orderDates['derivalToAddress'] !== ''
                ? 'derivalToAddress'
                : 'derivalFromOspReceiver';
        }

        $value = $orderDates[$field] ?? null;
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            $d0 = new \DateTimeImmutable('today');
            $d1 = new \DateTimeImmutable($value);
            $diff = (int) $d0->diff($d1)->format('%a');
            return $diff >= 0 ? $diff : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function postJson(string $url, ?array $body): array
    {
        $start = microtime(true);
        $json = $this->http('POST', $url, $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE));
        self::recordResponseTime($url, microtime(true) - $start);
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Накапливает время отклика API ДЛ по эндпоинту в shared-памяти процесса
     * (статическая переменная класса) — используется только для логирования
     * сводки в конце запроса через summarizeResponseTimes(), не персистентно
     * между HTTP-запросами пользователей.
     */
    private static array $responseTimes = [];

    private static function recordResponseTime(string $url, float $seconds): void
    {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        self::$responseTimes[$path][] = round($seconds * 1000);
    }

    /**
     * Возвращает сводку по времени отклика API ДЛ за текущий HTTP-запрос
     * (среднее/максимум по эндпоинту) — вызывается явно в местах, где нужно
     * залогировать производительность (например после завершения расчёта).
     *
     * @return array<string, array{count:int,avg_ms:int,max_ms:int}>
     */
    public static function summarizeResponseTimes(): array
    {
        $summary = [];
        foreach (self::$responseTimes as $path => $times) {
            $summary[$path] = [
                'count' => count($times),
                'avg_ms' => (int) round(array_sum($times) / count($times)),
                'max_ms' => (int) max($times),
            ];
        }
        return $summary;
    }

    /**
     * Параллельно отправляет несколько POST JSON-запросов на один URL
     * через curl_multi. Запросы с ошибкой сети/таймаутом или невалидным
     * JSON в ответе пропускаются (значение null в результате), не валят
     * остальные параллельные запросы.
     *
     * @param array<int|string, array<string,mixed>> $bodiesByKey
     * @return array<int|string, array<string,mixed>|null>
     */
    private function postJsonMulti(string $url, array $bodiesByKey): array
    {
        if ($bodiesByKey === []) {
            return [];
        }

        $start = microtime(true);
        $multiHandle = curl_multi_init();
        $handles = [];

        foreach ($bodiesByKey as $key => $body) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json; charset=utf-8'],
                CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
                CURLOPT_TIMEOUT => 20,
                CURLOPT_CONNECTTIMEOUT => 5,
            ]);
            curl_multi_add_handle($multiHandle, $ch);
            $handles[$key] = $ch;
        }

        $running = null;
        do {
            $status = curl_multi_exec($multiHandle, $running);
            if ($running > 0) {
                curl_multi_select($multiHandle, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $results = [];
        foreach ($handles as $key => $ch) {
            $body = curl_multi_getcontent($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);

            if ($body === null || $body === '' || $error !== '') {
                $results[$key] = null;
            } elseif ($httpCode >= 400) {
                // ДЛ возвращает структурированные ошибки даже на 4xx — пробуем распарсить,
                // чтобы calculateToTerminalsBatch мог отличить "180012" от прочих сбоев.
                $decoded = json_decode($body, true);
                $results[$key] = is_array($decoded) ? $decoded : null;
            } else {
                $decoded = json_decode($body, true);
                $results[$key] = is_array($decoded) ? $decoded : null;
            }

            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }

        curl_multi_close($multiHandle);

        self::recordResponseTime($url, microtime(true) - $start);

        return $results;
    }

    private function http(string $method, string $url, ?string $jsonBody): string
    {
        $ch = curl_init($url);
        if ($ch === false) throw new \RuntimeException('curl_init failed');
        $headers = ['Accept: application/json'];
        if ($jsonBody !== null) $headers[] = 'Content-Type: application/json; charset=utf-8';
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
        ];
        if ($method === 'POST' && $jsonBody !== null) $opts[CURLOPT_POSTFIELDS] = $jsonBody;
        if ($method === 'GET') $opts[CURLOPT_HTTPGET] = true;
        curl_setopt_array($ch, $opts);
        $out  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($out === false) throw new \RuntimeException('HTTP error: ' . $err);
        if ($code >= 400) throw new \RuntimeException("HTTP {$code}: " . mb_substr($out, 0, 2000));
        return $out;
    }

    public static function toUuid(string $uid): string
    {
        if (str_contains($uid, '-')) return $uid;
        $h = strtolower(ltrim($uid, '0x'));
        if (strlen($h) !== 32) return $uid;
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($h, 24, 8),
            substr($h, 20, 4),
            substr($h, 16, 4),
            substr($h, 0, 4),
            substr($h, 4, 12)
        );
    }
}
