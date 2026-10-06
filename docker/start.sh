#!/bin/sh

set -e

echo "Iniciando Vite..."

npm run dev -- --host 0.0.0.0 &

echo "Iniciando PHP-FPM..."

php-fpm -D

echo "Iniciando Nginx..."

nginx -g "daemon off;"