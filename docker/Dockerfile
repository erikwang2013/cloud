FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    libzip-dev zip unzip libpng-dev libjpeg-dev \
    libfreetype6-dev nginx supervisor \
    && docker-php-ext-configure gd \
    && docker-php-ext-install gd pdo_mysql zip bcmath \
    && pecl install redis && docker-php-ext-enable redis

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /app
COPY service/ /app/

RUN composer install --no-dev --optimize-autoloader

COPY docker/nginx.conf /etc/nginx/sites-enabled/default
COPY docker/supervisor.conf /etc/supervisor/conf.d/app.conf

EXPOSE 80
CMD ["supervisord", "-n"]
