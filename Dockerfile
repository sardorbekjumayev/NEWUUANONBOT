FROM php:8.3-cli-alpine

RUN apk add --no-cache curl \
    && docker-php-ext-install opcache

COPY . .

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app"]
