# Multi-stage build for Gym Tracker
# 1. Base image for runtime dependencies
FROM dunglas/frankenphp:1-php8.4 AS base

RUN install-php-extensions \
    pcntl \
    pdo_mysql \
    redis \
    bcmath \
    intl \
    zip \
    opcache \
    sockets

# Install system dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    default-mysql-client \
    curl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*


# 2. Builder stage for Frontend assets
FROM --platform=$BUILDPLATFORM node:25-slim AS frontend-builder
WORKDIR /app
COPY package*.json ./
RUN npm ci --legacy-peer-deps

COPY vite.config.js tailwind.config.js ./
COPY resources/ ./resources/
RUN npm run build

# 3. Builder stage for Composer dependencies
FROM --platform=$BUILDPLATFORM composer:2 AS composer-builder
WORKDIR /app
COPY composer.* ./
RUN composer install --no-dev --no-autoloader --no-scripts --ignore-platform-reqs

# Finalize autoloader in builder to keep final image clean
COPY . .
RUN composer dump-autoload --classmap-authoritative --no-dev --no-scripts

# 4. Final production image
FROM base AS final
WORKDIR /app

# Production PHP config with OPcache + JIT
COPY docker/php-prod.ini /usr/local/etc/php/conf.d/99-prod.ini

ENV SERVER_NAME=:80
ENV APP_ENV=production
ENV APP_DEBUG=false

# Copy application files
COPY . .

# Copy PHP dependencies (including pre-generated autoloader)
COPY --from=composer-builder /app/vendor ./vendor

# Copy built frontend assets
COPY --from=frontend-builder /app/public/build ./public/build

# Finalize Laravel
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
    && touch storage/logs/laravel.log \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD curl -f http://localhost:80/up || exit 1

# Expose production port
EXPOSE 80
USER www-data

ENTRYPOINT ["entrypoint.sh"]
CMD ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=80", "--workers=2"]
