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

# 2. Builder stage for Frontend assets
FROM --platform=$BUILDPLATFORM node:25-slim AS frontend-builder
WORKDIR /app
COPY package*.json ./
RUN npm ci --legacy-peer-deps


COPY . .
RUN npm run build

# 3. Builder stage for Composer dependencies
FROM composer:2 AS composer-builder
WORKDIR /app
COPY composer.* ./
RUN composer install --no-dev --no-autoloader --no-scripts --ignore-platform-reqs

# 4. Final production image
FROM base AS final
WORKDIR /app
ENV SERVER_NAME=:80
ENV APP_ENV=production
ENV APP_DEBUG=false

# Copy composer from builder to allow dump-autoload
COPY --from=composer-builder /usr/bin/composer /usr/bin/composer

# Copy application files FIRST (excludes vendor/ & public/build via .dockerignore)
COPY . .

# Copy PHP dependencies
COPY --from=composer-builder /app/vendor ./vendor

# Copy built frontend assets
COPY --from=frontend-builder /app/public/build ./public/build

# Finalize Laravel
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

RUN composer dump-autoload --classmap-authoritative --no-dev
RUN php artisan storage:link
RUN chmod -R 777 storage bootstrap/cache
RUN mkdir -p storage/logs && touch storage/logs/laravel.log

# Expose production port
EXPOSE 80


ENTRYPOINT ["entrypoint.sh"]
CMD ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=80", "--workers=1"]

