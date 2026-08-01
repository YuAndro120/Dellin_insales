#!/usr/bin/env bash
# ============================================================
# Докачивает бинарные ассеты, которых нет в архиве:
#   - woff2-шрифты (latin + cyrillic) через google-webfonts-helper
#   - three.min.js r128 с cdnjs
# Запускать ОДИН раз из корня сайта (там, где index.html):
#   bash get-assets.sh
# Требуются: curl, unzip (на VPS: apt install unzip)
# После этого сайт полностью автономен — внешних запросов ноль.
# ============================================================
set -euo pipefail

command -v curl >/dev/null  || { echo "Нужен curl";  exit 1; }
command -v unzip >/dev/null || { echo "Нужен unzip: sudo apt install unzip"; exit 1; }

mkdir -p fonts js
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

fetch_font () {
  local id="$1" variants="$2"
  echo "→ Шрифт: $id ($variants)"
  curl -fsSL "https://gwfh.mranftl.com/api/fonts/${id}?download=zip&subsets=latin,cyrillic&variants=${variants}&formats=woff2" \
       -o "$TMP/${id}.zip"
  unzip -oq "$TMP/${id}.zip" -d "$TMP/${id}"
}

fetch_font piazzolla      "500,500italic,600"
fetch_font golos-text     "regular,500,600"
fetch_font jetbrains-mono "regular,500"

# Переименовываем в стабильные имена, на которые ссылается style.css.
# Порядок важен: сначала italic, чтобы глоб *-500.woff2 не зацепил его.
mv "$TMP"/piazzolla/piazzolla-*-500italic.woff2      fonts/piazzolla-500italic.woff2
mv "$TMP"/piazzolla/piazzolla-*-500.woff2            fonts/piazzolla-500.woff2
mv "$TMP"/piazzolla/piazzolla-*-600.woff2            fonts/piazzolla-600.woff2
mv "$TMP"/golos-text/golos-text-*-regular.woff2      fonts/golos-text-400.woff2
mv "$TMP"/golos-text/golos-text-*-500.woff2          fonts/golos-text-500.woff2
mv "$TMP"/golos-text/golos-text-*-600.woff2          fonts/golos-text-600.woff2
mv "$TMP"/jetbrains-mono/jetbrains-mono-*-regular.woff2 fonts/jetbrains-mono-400.woff2
mv "$TMP"/jetbrains-mono/jetbrains-mono-*-500.woff2  fonts/jetbrains-mono-500.woff2

echo "→ three.min.js (r128)"
curl -fsSL "https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js" -o js/three.min.js

echo
echo "Проверка:"
ls -la fonts/*.woff2 js/three.min.js

# Все файлы должны быть ненулевого размера
for f in fonts/*.woff2 js/three.min.js; do
  [ -s "$f" ] || { echo "ОШИБКА: $f пустой"; exit 1; }
done

echo
echo "✓ Готово. Сайт автономен: внешних запросов больше нет."
