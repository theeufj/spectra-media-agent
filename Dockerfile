# syntax=docker/dockerfile:1
FROM node:22-trixie-slim AS node
FROM composer:2 AS composer
FROM ghcr.io/mlocati/php-extension-installer:2.11.34 AS php-extensions

FROM php:8.5.12-apache-trixie

COPY --from=php-extensions /usr/bin/install-php-extensions /usr/local/bin/
RUN IPE_PROCESSOR_COUNT=4 install-php-extensions \
        bcmath gd intl pcntl pdo_pgsql pgsql redis soap zip

# Use the checksum-pinned Debian Trixie build for the official image's PHP ABI.
RUN set -eu; \
    grpc_arch="$(dpkg --print-architecture)"; \
    case "$grpc_arch" in \
        amd64) grpc_sha=0b4bd0565ae818ce7922b337259070877fd1c0cc6ae88374c844cd7dcd23a48e ;; \
        arm64) grpc_sha=c0d54d05ae0679c3d1afe68aba94080a665c6ca12a31acde4cb0a89ad316f056 ;; \
        *) IPE_PROCESSOR_COUNT=4 install-php-extensions grpc; exit ;; \
    esac; \
    grpc_version=1.83.1-1+0~20260828.47+debian13~1.gbp336955; \
    grpc_tmp="$(mktemp -d)"; \
    curl -fsSL --retry 3 "https://packages.sury.org/php/pool/main/p/php-grpc/php8.5-grpc_${grpc_version}_${grpc_arch}.deb" \
        -o "$grpc_tmp/grpc.deb"; \
    printf '%s  %s\n' "$grpc_sha" "$grpc_tmp/grpc.deb" | sha256sum -c -; \
    dpkg-deb -x "$grpc_tmp/grpc.deb" "$grpc_tmp"; \
    grpc_extdir="$(php-config --extension-dir)"; \
    install -m 0644 "$grpc_tmp/usr/lib/php/${grpc_extdir##*-}/grpc.so" "$grpc_extdir/grpc.so"; \
    docker-php-ext-enable grpc; \
    php --ri grpc; \
    rm -r "$grpc_tmp"

RUN apt-get update && apt-get install -y --no-install-recommends \
        chromium chromium-sandbox ffmpeg fonts-liberation gosu postgresql-client tini unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules/npm /usr/local/lib/node_modules/npm
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm

WORKDIR /app

COPY composer.json composer.lock package.json package-lock.json ./
ENV PUPPETEER_SKIP_DOWNLOAD=true \
    PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium \
    NODE_BINARY_PATH=/usr/local/bin/node \
    XDG_CONFIG_HOME=/app/storage/framework/chromium/config \
    XDG_CACHE_HOME=/app/storage/framework/chromium/cache

RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction \
        --prefer-dist --no-progress --no-scripts --no-autoloader \
    && composer check-platform-reqs --no-dev \
    && npm ci --include=dev --no-audit --no-fund

COPY . .

ARG VITE_APP_NAME="Site to Spend"
ARG VITE_STRIPE_KEY
RUN mkdir -p bootstrap/cache storage/app/private storage/app/public \
        storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/framework/chromium/config \
        storage/framework/chromium/cache storage/logs \
    && COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --no-dev --optimize \
    && npm run build \
    && ln -s ../storage/app/public public/storage \
    && chown -R www-data:www-data bootstrap/cache storage

COPY infrastructure/railway/apache.conf /etc/apache2/sites-available/spectra.conf
COPY infrastructure/railway/php.ini /usr/local/etc/php/conf.d/spectra.ini
COPY --chmod=0755 infrastructure/railway/start /usr/local/bin/spectra-start
RUN a2dissite 000-default && a2disconf docker-php \
    && a2dismod mpm_event mpm_worker \
    && a2enmod mpm_prefork headers && a2ensite spectra \
    && truncate -s 0 /etc/apache2/ports.conf \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && PORT=8080 apache2ctl configtest

ENV PORT=8080
EXPOSE 8080
STOPSIGNAL SIGTERM
ENTRYPOINT ["/usr/local/bin/spectra-start"]
CMD ["web"]
