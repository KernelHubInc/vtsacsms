FROM php:8.4-cli-alpine AS php-base

ENV APP_ENV=local \
    COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app

RUN apk add --no-cache curl icu-libs libjpeg-turbo libpng libpq libzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libjpeg-turbo-dev libpng-dev libzip-dev postgresql-dev linux-headers \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install -j$(nproc) bcmath gd intl opcache pcntl pdo_pgsql zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && printf 'variables_order=EGPCS\n' > /usr/local/etc/php/conf.d/zz-environment.ini

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

FROM php-base AS php-dependencies

COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer install --no-interaction --prefer-dist --no-scripts --no-progress

FROM node:22-alpine AS frontend

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY --from=php-dependencies /app/vendor ./vendor
COPY resources ./resources
COPY vite.config.js ./
RUN npm run build

FROM php-dependencies AS development

COPY . .
COPY --from=frontend /app/public/build ./public/build
RUN touch .env \
    && composer dump-autoload --classmap-authoritative --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data

EXPOSE 8000

HEALTHCHECK --interval=10s --timeout=3s --retries=5 \
    CMD ["sh", "-c", "curl --fail --silent http://127.0.0.1:8000/health/live | grep -q '\"status\":\"ok\"'"]

CMD ["php", "-S", "0.0.0.0:8000", "-t", "public", "server.php"]

FROM php:8.4-fpm-alpine AS production-base

ENV APP_ENV=production \
    APP_DEBUG=false \
    COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app

RUN apk add --no-cache curl fcgi icu-libs libjpeg-turbo libpng libpq libzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libjpeg-turbo-dev libpng-dev libzip-dev postgresql-dev linux-headers \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install -j$(nproc) bcmath gd intl opcache pcntl pdo_pgsql zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && printf 'variables_order=EGPCS\nopcache.enable=1\nopcache.validate_timestamps=0\nopcache.memory_consumption=192\n' > /usr/local/etc/php/conf.d/zz-production.ini

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

FROM production-base AS production-dependencies

COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer install --no-interaction --prefer-dist --no-dev --no-scripts --no-progress

FROM production-base AS runtime

COPY --from=production-dependencies /app/vendor ./vendor
COPY . .
COPY --from=frontend /app/public/build ./public/build

RUN touch .env \
    && rm -f database/seeders/MilestoneOneDemoSeeder.php \
    && composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data

EXPOSE 9000

HEALTHCHECK --interval=10s --timeout=3s --retries=5 \
    CMD ["sh", "-c", "SCRIPT_NAME=/health/live SCRIPT_FILENAME=/app/public/index.php REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 >/dev/null"]

CMD ["php-fpm", "-F"]

FROM nginx:1.29-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=runtime /app/public /app/public

EXPOSE 8080

HEALTHCHECK --interval=10s --timeout=3s --retries=5 \
    CMD ["wget", "--quiet", "--spider", "http://127.0.0.1:8080/health/live"]
