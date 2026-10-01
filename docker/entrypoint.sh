#!/bin/sh
# Подготовка приложения при каждом старте сервиса web:
# зависимости, APP_KEY, миграции, первый оператор. Всё идемпотентно.
set -e
cd /var/www/html

if [ ! -f .env ]; then
    echo "Нет файла .env. Скопируйте .env.example в .env и впишите TELEGRAM_BOT_TOKEN и GEMINI_API_KEY." >&2
    exit 1
fi

composer install --no-interaction --no-progress --prefer-dist --quiet

if ! grep -qE '^APP_KEY=[^[:space:]]+' .env; then
    php artisan key:generate --force --no-interaction
fi

php artisan migrate --force --no-interaction
php artisan db:seed --force --no-interaction

exec "$@"
