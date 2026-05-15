# Бридж доставки для Insales (MVP → маркетплейс)

PHP-сервис: JSON API для расчёта, ПВЗ на карте, установка приложения inSales в MySQL, расчёт по **вариантам товаров** (вес + `dimensions` из каталога).

Документация API перевозчика (Swagger): [https://dev.dellin.ru/api/swagger/](https://dev.dellin.ru/api/swagger/)

Документация inSales (приложения и API): [Работа с API и приложения](https://www.insales.ru/collection/doc-rabota-s-api-i-prilozheniya), [Как интегрироваться](https://www.insales.ru/collection/doc-rabota-s-api-i-prilozheniya/product/kak-integrirovatsya-s-insales), [Справочник REST](https://api.insales.ru/?doc_format=JSON).

## Возможности

| Метод | Назначение |
|--------|------------|
| `GET /v1/terminals` | ПВЗ в bbox + фильтр КЛАДР |
| `POST /v1/calculate` | Расчёт по заданному грузу (JSON) |
| `POST /v1/calculate-city` | Ориентир по городу |
| `POST /v1/calculate-from-variants` | Загрузка вариантов из inSales + расчёт (нужны БД и установка приложения) |
| `GET /v1/cities/search?q=` | Подсказка городов (КЛАДР) |
| `GET /insales/install` | Установка приложения (параметры `token`, `shop`, `insales_id`) |
| `GET`/`POST /insales/app` | Настройки: выбор **терминала отгрузки** из API перевозчика |
| `GET /insales/terminals` | Список ПВЗ по `city_kladr` / `q` (для страницы настроек) |
| `GET /insales/cities/search` | Подсказка городов (КЛАДР) |
| `GET`/`POST /insales/uninstall` | Отключение (`insales_id`) |
| `public/widget/index.html` | Демо карты |

Защита JSON-эндпоинтов: заголовок `X-Bridge-Token`, если в `.env` задан `BRIDGE_SECRET`. Эндпоинты `/insales/*` вызываются **inSales**, без этого токена.

## Требования

- PHP 8.1+, расширения `curl`, `pdo_mysql`
- MySQL 8+ или MariaDB 10.3+
- Запись в `var/cache/`

## База данных

```bash
mysql -u root -p < database/schema.sql
```

Либо создайте БД вручную и выполните SQL из `database/schema.sql`.

## Переменные `.env`

См. `.env.example`: блок **перевозчика** (`SHIPPING_*`), **MySQL** (`MYSQL_*` или `DATABASE_URL`), **приложение inSales** (`INSALES_APP_ID`, `INSALES_APP_SECRET` — как в кабинете разработчика).

**Настройки на магазин** (не в `.env`): терминал отгрузки, email отправителя, UID контрагента, вес/габариты по умолчанию, объявленная стоимость, дней до отгрузки, вкл/выкл расчёт — форма **`/insales/app`**. Для API передайте `shop` (`*.myinsales.ru`).

**Как протестировать на тестовом магазине:** см. [docs/TESTING_INSALES.md](docs/TESTING_INSALES.md).

**Checkout без «Доставки inSales»:** [docs/CHECKOUT_INSALES.md](docs/CHECKOUT_INSALES.md) — внешний способ доставки API v2 (`/insales/external/v2/*`).

Для уже развёрнутой БД выполните миграцию:

```bash
mysql -u root -p insales_bridge < database/migrations/002_sender_terminal_per_shop.sql
mysql -u root -p insales_bridge < database/migrations/003_shop_delivery_settings.sql
```

В форме приложения inSales укажите:

- **URL установки** — `https://ваш-домен/insales/install`
- **URL входа** — `https://ваш-домен/insales/app`
- **URL деинсталляции** — `https://ваш-домен/insales/uninstall`

Для локальной разработки используйте туннель (ngrok, Cloudflare Tunnel, Herd expose) с HTTPS.

## `POST /v1/calculate` и `POST /v1/calculate-city`

В теле укажите `shop` (установленный магазин с настроенным терминалом отгрузки) либо для отладки — `sender_terminal_id` напрямую.

## `POST /v1/calculate-from-variants`

Тело (пример):

```json
{
  "shop": "myshop.myinsales.ru",
  "lines": [
    { "variant_id": 123, "product_id": 456, "quantity": 2 },
    { "variant_id": 789, "quantity": 1 }
  ],
  "arrival_terminal_id": 1000,
  "arrival_city_kladr": "7700000000000"
}
```

`arrival_city_kladr` **необязателен**, если передан `arrival_terminal_id` — КЛАДР подставится из справочника ПВЗ; в теле калькулятора `paymentCity` передаётся только при наличии значения.

Поле `product_id` **желательно**: иначе выполняется медленный обход страниц `GET /admin/products.json`.

Вес и строка `dimensions` (часто в **см**, формат `ДxШxВ`) читаются из объекта варианта inSales; для нескольких позиций: **суммируются** масса и объём, габариты — **максимум** по каждой оси среди позиций (упрощение MVP).

## Виджет

`/widget/index.html?bridge=https://ваш-хост&ymapkey=ключ` — [API Карт](https://developer.tech.yandex.ru/).

## Корень веб-сервера

Каталог `public/` (или rewrite на `public/index.php`).
