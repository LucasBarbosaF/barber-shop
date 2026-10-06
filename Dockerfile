FROM php:8.4-fpm

# Dependências do sistema
RUN apt-get update && apt-get install -y \
    git \
    curl \
    unzip \
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
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Copia projeto
COPY . .

# Instala dependências PHP
RUN composer install \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader

# Permissões Laravel
RUN chown -R www-data:www-data /var/www/storage \
    /var/www/bootstrap/cache

RUN chmod -R 775 /var/www/storage \
    /var/www/bootstrap/cache

EXPOSE 9000

CMD ["php-fpm"]