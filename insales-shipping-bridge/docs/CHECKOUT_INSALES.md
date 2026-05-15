# Checkout inSales без «Доставки inSales»

Ваша интеграция работает через **«Внешний способ доставки»** (API v2).  
Это **не** платная «Доставка inSales» с отдельным договором — расчёт делает **ваш сервер** (бридж + API перевозчика).

Документация inSales: [JavaScript API оформления заказа для внешних способов доставки](https://www.insales.ru/collection/doc-prochee/product/javascript-api-oformleniya-zakaza-dlya-vneshnih-sposobov-dostavki)

---

## 1. Подготовка

1. Приложение установлено, в `/insales/app` заполнены настройки (терминал отгрузки, email и т.д.).
2. На сервере в `.env` заданы ключи перевозчика и `PUBLIC_BRIDGE_URL` (публичный URL бриджа, с HTTPS в проде).
3. Выполнен `git pull` и миграции БД.

---

## 2. URL для админки магазина

Подставьте ваш домен вместо `https://bridge.example.com`:

| Назначение | URL | Метод |
|------------|-----|--------|
| Курьер / расчёт по адресу (API v2) | `https://bridge.example.com/insales/external/v2/courier` | POST |
| Список ПВЗ на checkout | `https://bridge.example.com/insales/external/v2/pickup_points` | POST |
| Пересчёт выбранного ПВЗ | `https://bridge.example.com/insales/external/v2/pickup_point` | POST |

Эти же URL показаны в настройках приложения (`/insales/app`).

---

## 3. Способ доставки «ПВЗ» (терминалы Деловых Линий)

Тип inSales: **`DeliveryVariant::PickUp`** — на checkout показывается карта/список ПВЗ.

В **admin2** нет формы с двумя URL. Способ создаётся **через API магазина**:

1. Откройте настройки приложения: `/insales/app?shop=ВАШ_МАГАЗИН.myinsales.ru&insales_id=...`
2. Нажмите **«Создать способ доставки «Деловые Линии — терминал»»** — бридж вызовет `POST /admin/delivery_variants.json` с `pick_up_sources_attributes`:
   - **URL списка:** `.../insales/external/v2/pickup_points` (POST)
   - **URL точки:** `.../insales/external/v2/pickup_point` (POST)

Либо вручную через curl (логин = `INSALES_APP_ID`, пароль = пароль установки из БД).

inSales на checkout отправит JSON с `order` (позиции, КЛАДР, `account_id`).  
Бридж сопоставляет `account_id` с магазином из таблицы `insales_shops`.

---

## 4. Способ доставки «Курьер» (API v2)

**Доставка** → **Внешний способ доставки** → версия API **v2**.

- **URL расчёта:** `.../insales/external/v2/courier`

Покупатель указывает адрес с КЛАДР — бридж считает доставку до города/терминала получателя.

---

## 5. Поля заказа

В ответе бридж передаёт `fields_values`:

- `dellin_terminal_id` — ID выбранного ПВЗ
- `dellin_delivery_type` — `pickup` или `courier`

При необходимости создайте в магазине **доп. поля заказа** с такими `handle` (необязательно для MVP).

---

## 6. CORS

Checkout идёт с домена `*.myinsales.ru`. Бридж отдаёт заголовки:

```
Access-Control-Allow-Origin: *
Access-Control-Allow-Headers: Accept, Accept-Language, Content-Language, Content-Type
```

При необходимости задайте `CORS_ORIGIN` в `.env`.

---

## 7. Проверка

1. В корзине добавьте товар с **весом и габаритами** (или задайте значения по умолчанию в приложении).
2. Оформление заказа → выберите способ доставки с вашим внешним URL.
3. Для ПВЗ — откройте карту точек, выберите ПВЗ, цена должна пересчитаться.

Если точек нет: проверьте КЛАДР в адресе, `SHIPPING_API_APPKEY`, терминал отгрузки в приложении.

---

## Отличие от «Доставки inSales»

| | Внешний способ (ваш бридж) | Доставка inSales |
|---|---------------------------|------------------|
| Договор | С перевозчиком напрямую | Через inSales |
| Расчёт | Ваш сервер | Серверы inSales |
| Настройка | URL на ваш API | Подключение в кабинете inSales |
