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
    opcache

# 2. Builder stage for Frontend assets
FROM --platform=$BUILDPLATFORM node:20-slim AS frontend-builder
WORKDIR /app
COPY package*.json ./
RUN npm ci
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
RUN composer dump-autoload --classmap-authoritative --no-dev
RUN php artisan storage:link
RUN chmod -R 777 storage bootstrap/cache
RUN mkdir -p storage/logs && touch storage/logs/laravel.log

# Install mysql client for health checks
RUN apt-get update && apt-get install -y default-mysql-client && rm -rf /var/lib/apt/lists/*


EXPOSE 80

# Startup script that waits for DB and starts Octane
CMD sh -c '\
  echo "Waiting for database at $DB_HOST:$DB_PORT..." && \
  MAX_TRIES=60 && \
  TRIES=0 && \
  until php -r "try { new PDO(\"mysql:host=\" . getenv(\"DB_HOST\") . \";port=\" . getenv(\"DB_PORT\"), getenv(\"DB_USERNAME\"), getenv(\"DB_PASSWORD\"), [PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false]); exit(0); } catch (Exception \$e) { exit(1); }" 2>/dev/null; do \
    TRIES=$((TRIES+1)) && \
    if [ $TRIES -ge $MAX_TRIES ]; then \
      echo "Database connection failed after $MAX_TRIES attempts (120 seconds)" && \
      exit 1; \
    fi && \
    echo "Attempt $TRIES/$MAX_TRIES - waiting for MySQL at $DB_HOST:$DB_PORT..." && \
    sleep 2; \
  done && \
  echo "Database ready!" && \
  php artisan config:cache && \
  php artisan route:cache && \
  php artisan migrate --force && \
  echo "Starting Octane..." && \
  php artisan octane:frankenphp --host=0.0.0.0 --port=80 --workers=1'
