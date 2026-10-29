FROM serversideup/php:8.5-cli-bookworm AS vendor

WORKDIR /var/www/html

COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --no-autoloader \
    --prefer-dist

FROM serversideup/php:8.5-fpm-bookworm AS runtime

LABEL org.opencontainers.image.source="https://github.com/marcel-breuer/metricove"

USER root
RUN apt-get update \
    && apt-get install -y --no-install-recommends poppler-utils tesseract-ocr tesseract-ocr-eng tesseract-ocr-deu \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pdo_pgsql redis

WORKDIR /var/www/html

COPY --chown=www-data:www-data apps/api/app ./app
COPY --chown=www-data:www-data apps/api/bootstrap ./bootstrap
COPY --chown=www-data:www-data apps/api/config ./config
COPY --chown=www-data:www-data apps/api/database ./database
COPY --chown=www-data:www-data apps/api/public ./public
COPY --chown=www-data:www-data apps/api/resources ./resources
COPY --chown=www-data:www-data apps/api/routes ./routes
COPY --chown=www-data:www-data apps/api/storage ./storage
COPY --chown=www-data:www-data apps/api/artisan ./artisan
COPY --chown=www-data:www-data apps/api/composer.json ./composer.json
COPY --from=vendor --chown=www-data:www-data /var/www/html/vendor ./vendor

RUN mkdir -p \
        bootstrap/cache \
        storage/app/private \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
    && chown -R www-data:www-data bootstrap/cache storage \
    && chmod -R u+rwX,go-rwx bootstrap/cache storage

USER www-data
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction --no-scripts \
    && php artisan package:discover --ansi --no-interaction

HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 CMD ["php-fpm-healthcheck"]

FROM serversideup/php:8.5-cli-bookworm AS test

WORKDIR /var/www/html

USER root
RUN apt-get update \
    && apt-get install -y --no-install-recommends poppler-utils tesseract-ocr tesseract-ocr-eng tesseract-ocr-deu \
    && rm -rf /var/lib/apt/lists/*

COPY --chown=www-data:www-data apps/api/ ./

USER www-data
RUN touch .env \
    && composer install \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --no-scripts \
    && composer dump-autoload --no-interaction --no-scripts \
    && php artisan package:discover --ansi --no-interaction \
    && composer test
