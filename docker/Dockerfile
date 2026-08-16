FROM composer:2 AS composer-stage

FROM php:8.3-cli AS base

RUN apt-get update && apt-get install -y \
    libzip-dev zip unzip libpng-dev libjpeg-dev \
    libfreetype6-dev libicu-dev libxml2-dev nginx supervisor \
    && docker-php-ext-configure gd \
    && docker-php-ext-install gd pdo_mysql zip bcmath intl xml fileinfo pcntl posix \
    && pecl install redis && docker-php-ext-enable redis

COPY --from=composer-stage /usr/bin/composer /usr/local/bin/composer

# ── service (主 API, webman 8787) ──
FROM base AS app
WORKDIR /app
COPY service/ /app/

RUN composer install --no-dev --optimize-autoloader

COPY docker/nginx.conf /etc/nginx/sites-enabled/default
COPY docker/supervisor.conf /etc/supervisor/conf.d/app.conf

EXPOSE 80
CMD ["supervisord", "-n"]

# ── admin (管理后台, webman 8788) ──
FROM base AS admin
WORKDIR /app
COPY admin/ /app/

RUN composer install --no-dev --optimize-autoloader

COPY docker/nginx-admin.conf /etc/nginx/sites-enabled/default
COPY docker/supervisor.conf /etc/supervisor/conf.d/app.conf

EXPOSE 80
CMD ["supervisord", "-n"]
