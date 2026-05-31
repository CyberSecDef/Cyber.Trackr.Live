# Self-contained, air-gap-ready image of the Cyber.Trackr.Live app.
# nginx + PHP-FPM in one container, with all of resources/data/ baked in.
# Build with bin/build-image.sh (see DOCKER.md).

# ---- base: PHP runtime + extensions shared by builder and runtime ----------
FROM php:8.4-fpm-bookworm AS base
# gd is required by phpoffice/phpword; zip by phpword + zipstream; intl by
# symfony/intl. The -dev libs stay installed because the compiled extensions
# link their runtime .so's (purging --auto-remove would strip them).
RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev libicu-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" zip intl opcache gd \
    && rm -rf /var/lib/apt/lists/*

# ---- builder: install prod dependencies and warm the prod cache -----------
FROM base AS builder
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
ENV APP_ENV=prod \
    APP_DEBUG=0

# Dependencies first so this layer caches independently of the data tree.
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-progress \
        --optimize-autoloader --no-interaction

# Now the application (and the ~4 GB resources/data tree). APP_SECRET is only a
# build-time placeholder for cache warmup; the real one is injected at runtime.
COPY . .
RUN export APP_SECRET=build-time-placeholder \
    && composer dump-autoload --no-dev --classmap-authoritative \
    && php bin/console cache:clear --env=prod --no-debug \
    && php bin/console assets:install public --env=prod --no-debug \
    && rm -rf var/log/* var/cache/dev var/cache/test

# ---- runtime: nginx + php-fpm under supervisor ----------------------------
FROM base AS runtime
RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx supervisor \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY --from=builder /app /app

COPY docker/nginx.conf       /etc/nginx/sites-available/default
COPY docker/fpm-pool.conf    /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/php.ini          /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/supervisord.conf /etc/supervisor/conf.d/app.conf
COPY docker/entrypoint.sh    /usr/local/bin/entrypoint.sh

ENV APP_ENV=prod \
    APP_DEBUG=0

RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p var/cache var/log \
    && chown -R www-data:www-data var

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/app.conf"]
