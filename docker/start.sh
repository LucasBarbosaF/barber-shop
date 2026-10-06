#!/bin/sh

set -e

# Render fornece a porta através de PORT
PORT="${PORT:-10000}"

sed -i "s/listen 10000;/listen ${PORT};/" \
    /etc/nginx/conf.d/default.conf

# Cache Laravel
php artisan storage:link
php artisan config:clear
php artisan cache:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Inicia PHP-FPM + Nginx
exec /usr/bin/supervisord -n