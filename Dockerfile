# Multi-stage build for Gym Tracker
# 1. Base image for runtime dependencies
FROM dunglas/frankenphp:1-php8.5 AS base

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
# 24 is the active LTS line, and the one CI builds with. 25 and 26 are not LTS
# yet, and the image was on 25 while CI tested on 24 — so the assets that
# shipped were never the assets that were tested.
FROM --platform=$BUILDPLATFORM node:24-slim AS frontend-builder
WORKDIR /app
COPY package*.json ./
RUN npm ci --legacy-peer-deps

COPY vite.config.js ./
COPY resources/ ./resources/

# Vite substitue `import.meta.env.*` AU MOMENT DU BUILD, et le build a lieu ici.
#
# La variable etait lue par resources/js/main.js mais fournie nulle part : ni le
# Dockerfile ni la CI ne la mentionnaient. Dans chaque image publiee, la
# condition etait donc fausse, `Sentry.init` n'etait jamais appele, et
# @sentry/vue voyageait dans le bundle sans jamais s'executer. Aucune erreur
# front n'a jamais ete remontee, et un tableau de bord vide se lit comme « tout
# va bien » (#1444).
ARG VITE_SENTRY_DSN_PUBLIC=""
ENV VITE_SENTRY_DSN_PUBLIC=$VITE_SENTRY_DSN_PUBLIC

# Le build, puis la verification que la variable est bien ARRIVEE dedans.
#
# Sans ce controle, un futur remaniement du Dockerfile peut la debrancher sans
# que rien ne le dise — c'est exactement comme cela qu'on en est arrive la. Le
# controle ne s'applique que si un DSN a ete fourni : une construction locale
# sans secret reste possible, elle produit simplement une image sans Sentry.
RUN npm run build \
    && if [ -n "$VITE_SENTRY_DSN_PUBLIC" ]; then \
        grep -rql "$VITE_SENTRY_DSN_PUBLIC" public/build/assets/ \
            || (echo "ERREUR : VITE_SENTRY_DSN_PUBLIC a ete fourni mais n'apparait pas dans le bundle." \
                && echo "Sentry front resterait muet dans cette image." && exit 1); \
    fi

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
    && mkdir -p /data/caddy /config/caddy \
    && touch storage/logs/laravel.log \
    && chown -R www-data:www-data storage bootstrap/cache public /data /config \
    && chmod -R 775 storage bootstrap/cache public /data /config

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD curl -f http://localhost:80/up || exit 1

# Expose production port
EXPOSE 80
USER www-data

ENTRYPOINT ["entrypoint.sh"]
CMD ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=80", "--workers=2"]
