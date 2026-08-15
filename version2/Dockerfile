FROM php:8.3-cli-alpine

RUN apk add --no-cache git unzip \
    && docker-php-ext-install opcache

WORKDIR /app

COPY composer.json composer.json
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress

COPY . .

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
