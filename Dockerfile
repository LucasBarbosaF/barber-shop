# ============================================
# Frontend - Vite
# ============================================
FROM node:22 AS frontend

WORKDIR /var/www

COPY package*.json ./

RUN npm ci

COPY . .

RUN npm run dev


# ============================================
# Backend - Laravel
# ============================================
FROM php:8.4-fpm

RUN apt-get update && apt-get install -y \
    nginx \
    supervisor \
    git \
    unzip \
    curl \
    libpq-dev \
    libzip-dev \
    libicu-dev \
    libonig-dev \
    libxml2-dev \
    && docker-php-ext-install \
        pdo \
        pdo_pgsql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        intl \
        zip \
    && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Composer
COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

# Código Laravel
COPY . .

# Build do Vite
COPY --from=frontend /var/www/public/build ./public/build

# Laravel
RUN php artisan package:discover --ansi

# Permissões
RUN chown -R www-data:www-data \
    /var/www/storage \
    /var/www/bootstrap/cache

RUN chmod -R 775 \
    /var/www/storage \
    /var/www/bootstrap/cache

# Nginx
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf

# Supervisor
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Start
COPY docker/start.sh /start.sh

RUN chmod +x /start.sh

EXPOSE 10000

CMD ["/start.sh"]