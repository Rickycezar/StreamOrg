# syntax=docker/dockerfile:1
#
# StreamOrg — production image (PHP 8.2 + Apache).
# Built by Coolify on every push (Dockerfile build pack); see README.md.
#
# Persistent data lives in two volumes mounted at runtime:
#   /var/www/html/public/media   downloaded game artwork
#   /var/www/html/tmp            PHP session files
# Everything else is configured through environment variables (src/Config.php).

# ---- 1. PHP dependencies ---------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
COPY src/ src/

# The extensions are checked in the runtime image, not in this build stage.
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress \
        --optimize-autoloader --ignore-platform-req='ext-*'

# ---- 2. Runtime --------------------------------------------------------------
FROM php:8.2-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
        curl libpq-dev libpng-dev libjpeg62-turbo-dev libwebp-dev \
 && docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql gd opcache \
 && rm -rf /var/lib/apt/lists/*

# Production php.ini, then StreamOrg's own settings on top.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-streamorg.ini"

# Apache: document root is public/, everything else unreachable over HTTP.
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
RUN a2enmod headers \
 && sed -ri 's/^ServerTokens .*/ServerTokens Prod/; s/^ServerSignature .*/ServerSignature Off/' \
        /etc/apache2/conf-available/security.conf

WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor

RUN chmod +x docker/entrypoint.sh \
 && mkdir -p public/media tmp/sessions \
 && chown -R www-data:www-data public/media tmp

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD curl -fsS http://localhost/healthz || exit 1

ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
CMD ["apache2-foreground"]
