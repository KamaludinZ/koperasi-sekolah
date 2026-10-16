# Image production Koperasi Sekolah (Laravel 12 + Livewire 3, PHP 8.5, PostgreSQL).
# Satu image untuk semua peran — pilih lewat CONTAINER_ROLE:
#   all (bawaan)  nginx + php-fpm + pekerja antrean + penjadwal dalam satu kontainer (cocok untuk Coolify/VPS kecil)
#   web           nginx + php-fpm saja
#   worker        pekerja antrean saja (queue:work)
#   scheduler     penjadwal saja (schedule:work)
# Aplikasi mendengar di port 8080 (http); TLS ditangani reverse proxy (Traefik Coolify, Caddy, Nginx).
# Panduan lengkap: docs/deploy.md

ARG PHP_VERSION=8.5
ARG ALPINE_VERSION=3.24
ARG NODE_VERSION=22

########################################################################################################
# 1) base — PHP-FPM + ekstensi + paket sistem yang dipakai saat runtime
########################################################################################################
FROM php:${PHP_VERSION}-fpm-alpine${ALPINE_VERSION} AS base

COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/

# pdo_pgsql: basis data utama · gd: barcode PNG & gambar PDF (dompdf) · zip: cadangan, uji pemulihan, impor XLSX
# intl: format angka/tanggal · pcntl: batas waktu pekerja antrean · opcache: performa
# postgresql18-client: pg_dump untuk cadangan (versi 18 bisa membaca server PostgreSQL ≤ 18)
RUN install-php-extensions pdo_pgsql gd zip intl pcntl opcache \
    && apk add --no-cache nginx supervisor su-exec tzdata curl postgresql18-client \
    && rm -f /etc/nginx/http.d/default.conf

WORKDIR /var/www/html

########################################################################################################
# 2) vendor — dependensi Composer tanpa paket pengembangan
########################################################################################################
FROM base AS vendor

ENV COMPOSER_ALLOW_SUPERUSER=1

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --no-progress --prefer-dist

########################################################################################################
# 3) assets — build Vite/Tailwind (public/build)
########################################################################################################
FROM node:${NODE_VERSION}-alpine AS assets

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
# Tailwind memindai view paginasi bawaan Laravel (lihat @source di resources/css/app.css).
COPY --from=vendor /var/www/html/vendor/laravel/framework/src/Illuminate/Pagination/resources/views \
    ./vendor/laravel/framework/src/Illuminate/Pagination/resources/views
RUN npm run build

########################################################################################################
# 4) app — image akhir
########################################################################################################
FROM base AS app

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stack \
    LOG_STACK=stderr \
    TZ=Asia/Jakarta \
    CONTAINER_ROLE=all \
    PHP_FPM_MAX_CHILDREN=20 \
    PHP_MEMORY_LIMIT=256M \
    QUEUE_WORKERS=1 \
    COMPOSER_ALLOW_SUPERUSER=1

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-koperasi.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-koperasi.conf
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/koperasi-entrypoint
COPY docker/healthcheck.sh /usr/local/bin/koperasi-healthcheck
COPY docker/artisan.sh /usr/local/bin/artisan

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=vendor /var/www/html/vendor ./vendor
# Kode milik root (tidak bisa diubah proses web); hanya storage & bootstrap/cache yang bisa ditulis www-data.
COPY . .
COPY --from=assets /app/public/build ./public/build

# dump-autoload juga menjalankan `package:discover` (skrip post-autoload-dump).
RUN composer dump-autoload --no-dev --optimize --no-interaction \
    && rm -f public/hot \
    && ln -sfn ../storage/app/public public/storage \
    && mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x /usr/local/bin/koperasi-entrypoint /usr/local/bin/koperasi-healthcheck /usr/local/bin/artisan

# Data unggahan, cadangan, ekspor, dan log — WAJIB dipasang sebagai volume persisten.
VOLUME ["/var/www/html/storage"]

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=90s --retries=3 CMD ["koperasi-healthcheck"]

ENTRYPOINT ["koperasi-entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
