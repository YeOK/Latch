# Copyright (c) 2026 Latch contributors
# SPDX-License-Identifier: MIT
# Homelab / demo image — not the Fedora COPR production path.
# See source/docs/DOCKER.md

FROM composer:2 AS vendor

WORKDIR /app
COPY source/composer.json source/composer.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --no-interaction \
    --prefer-dist

COPY source/ ./
RUN composer dump-autoload --optimize --no-dev --no-interaction

FROM php:8.2-apache-bookworm

ARG LATCH_VERSION=0.5.6.0
LABEL org.opencontainers.image.title="Latch" \
      org.opencontainers.image.description="Self-hosted PHP forum (homelab / demo image)" \
      org.opencontainers.image.source="https://github.com/YeOK/Latch" \
      org.opencontainers.image.licenses="MIT" \
      org.opencontainers.image.version="${LATCH_VERSION}"

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        libsqlite3-0 \
        libzip4 \
        libzip-dev \
        libsqlite3-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_sqlite zip \
    && a2enmod rewrite headers \
    && printf 'ServerName localhost\n' > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername \
    && rm -rf /var/lib/apt/lists/* \
    && { \
        echo 'expose_php = Off'; \
        echo 'allow_url_fopen = On'; \
    } > /usr/local/etc/php/conf.d/latch.ini

ENV APACHE_DOCUMENT_ROOT=/var/www/latch/source/public
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/latch
COPY VERSION LICENSE ./
COPY --from=vendor /app /var/www/latch/source
COPY docker/entrypoint.sh /usr/local/bin/latch-entrypoint
RUN chmod +x /usr/local/bin/latch-entrypoint \
    && mkdir -p /persist/config \
        /var/www/latch/source/storage/database \
        /var/www/latch/source/storage/logs \
        /var/www/latch/source/storage/cache \
        /var/www/latch/source/storage/backups \
        /var/www/latch/source/storage/plugins \
        /var/www/latch/source/storage/uploads \
    && chown -R www-data:www-data /var/www/latch/source/storage /persist \
    && find /var/www/latch/source -type d -exec chmod 755 {} \; \
    && find /var/www/latch/source -type f -exec chmod 644 {} \; \
    && chmod 755 /var/www/latch/source/bin/latch

WORKDIR /var/www/latch/source

EXPOSE 80
HEALTHCHECK --interval=15s --timeout=5s --start-period=45s --retries=8 \
    CMD curl -fsS http://127.0.0.1/health || exit 1

ENTRYPOINT ["latch-entrypoint"]
CMD ["apache"]
