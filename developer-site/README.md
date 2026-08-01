# developer.receptly.ru — персональный сайт

Статический сайт: 5 HTML-страниц, один CSS, два JS, ноль зависимостей после
установки. Самодостаточная папка — можно распаковать куда угодно, наружу
она ни на что не ссылается.

## Структура

```
developer-site/
├── index.html            главная (герой + боли + услуги + тизеры кейсов)
├── cases.html            кейсы: ДЛ Коннект, Cerevio
├── blog.html             блог: список заметок
├── post-152fz.html       статья «152-ФЗ для маленького SaaS»
├── post-api-cache.html   статья «Протухшая сессия»
├── favicon.svg           иконка (мотив зачёркнутой «А»)
├── robots.txt, sitemap.xml
├── css/style.css         все стили (+ @font-face на локальные шрифты)
├── js/main.js            reveal-анимации, прогресс чтения
├── js/hero3d.js          3D-граф в герое
├── fonts/                woff2 — появятся после get-assets.sh
├── get-assets.sh         докачивает шрифты и three.min.js (запустить 1 раз)
└── deploy/nginx-developer.receptly.ru.conf
```

## Деплой — по шагам

### 0. Перед заливкой замени контакты
Поиск по всем HTML: `hello@receptly.ru` → твоя почта,
`https://t.me/username` и `@username` → твой Telegram.

### 1. DNS
A-запись у регистратора: `developer` → `176.53.160.91`.

### 2. Файлы на сервер
```bash
# любым способом: git-репозиторий, scp, rsync. Например:
rsync -av --exclude 'deploy' developer-site/ root@176.53.160.91:/var/www/developer/
```

### 3. Докачать ассеты (один раз, на сервере)
```bash
cd /var/www/developer
bash get-assets.sh        # нужны curl и unzip (apt install unzip)
```
Скрипт скачает 8 woff2-шрифтов (latin+cyrillic) и three.min.js r128,
проверит, что файлы не пустые. После этого внешних запросов у сайта нет —
это важно и для 152-ФЗ, и для строгого CSP из nginx-конфига.

```bash
sudo chown -R www-data:www-data /var/www/developer
```

### 4. Nginx
```bash
sudo cp deploy/nginx-developer.receptly.ru.conf /etc/nginx/sites-available/developer.receptly.ru
sudo ln -s /etc/nginx/sites-available/developer.receptly.ru /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

### 5. TLS (когда DNS уже отвечает)
```bash
sudo certbot --nginx -d developer.receptly.ru
```

### 6. Проверка
- https://developer.receptly.ru открывается, шрифты сериф/моно на месте
  (если вместо Piazzolla системная Georgia — шаг 3 не выполнен);
- в консоли браузера нет CSP-ошибок;
- 3D-граф в герое крутится (на мобильных он скрыт — так задумано).

## Важно

- **Не клади это в webroot inSales-бриджа.** Папка самодостаточная,
  nginx-конфиг смотрит в /var/www/developer — бридж и сайт не пересекаются,
  git pull одного не трогает другого.
- **Matomo.** Если захочешь аналитику (site ID=3), добавь
  `_paq.push(['disableCookies']);` перед `trackPageView` — иначе строка
  в подвале «не использует cookies» станет неправдой. И расширь CSP:
  `script-src 'self' https://matomo.receptly.ru; connect-src 'self' https://matomo.receptly.ru; img-src 'self' data: https://matomo.receptly.ru`.
- **Правки контента.** Статьи блога — обычные HTML, копируй любую как шаблон.
  Новую статью добавь в blog.html и sitemap.xml.
