FROM composer:2 AS composer
FROM php:8.3-cli-alpine

RUN apk add --no-cache sqlite-libs libcurl \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS sqlite-dev curl-dev \
    && docker-php-ext-install pdo_sqlite curl \
    && apk del .build-deps \
    && addgroup -g 10001 lepao \
    && adduser -D -u 10001 -G lepao lepao
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts
COPY --chown=lepao:lepao . .
RUN mkdir -p /app/data && chown lepao:lepao /app/data
USER lepao
EXPOSE 8900
ENV LEPAO_LISTEN=0.0.0.0:8900
CMD ["php", "bin/console.php", "serve"]
