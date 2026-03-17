#!/bin/sh
set -e

php artisan config:clear
php artisan cache:clear
php artisan config:cache
php artisan route:cache
php artisan storage:link --quiet 2>/dev/null || true
php artisan migrate --force

exec php-fpm