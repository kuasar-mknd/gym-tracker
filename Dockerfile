FROM dunglas/frankenphp:1-php8.4

ENV SERVER_NAME=:80

RUN install-php-extensions \
    pcntl \
    pdo_mysql \
    redis \
    bcmath \
    intl \
    zip \
    opcache

# Install Node.js for frontend build
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - && \
    apt-get install -y nodejs && \
    npm install -g npm

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /app

# Copy dependency files
COPY composer.json composer.lock ./

# Install dependencies
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer install --no-dev --no-autoloader --no-scripts

# Copy app files
COPY . .

# Build frontend
RUN npm ci && npm run build

# Finalize composer
RUN composer dump-autoload --classmap-authoritative --no-dev
RUN php artisan storage:link

# Permissions
RUN chmod -R 777 storage bootstrap/cache

# Create log directory
RUN mkdir -p storage/logs && touch storage/logs/laravel.log

# Expose port
EXPOSE 80

# Set environment for debugging
ENV APP_DEBUG=true
ENV LOG_LEVEL=debug

# Startup script that waits for DB and starts Octane
CMD sh -c '\
  echo "Waiting for database..." && \
  MAX_TRIES=30 && \
  TRIES=0 && \
  until php -r "try { new PDO(\"mysql:host=\$_ENV[DB_HOST];port=\$_ENV[DB_PORT];dbname=\$_ENV[DB_DATABASE]\", \$_ENV[DB_USERNAME], \$_ENV[DB_PASSWORD]); echo \"Connected!\n\"; exit(0); } catch (Exception \$e) { echo \"Waiting...\n\"; exit(1); }" 2>/dev/null; do \
    TRIES=$((TRIES+1)) && \
    if [ $TRIES -ge $MAX_TRIES ]; then \
      echo "Database connection failed after $MAX_TRIES attempts" && \
      exit 1; \
    fi && \
    echo "Attempt $TRIES/$MAX_TRIES - waiting for MySQL..." && \
    sleep 2; \
  done && \
  echo "Database ready!" && \
  php artisan config:cache && \
  php artisan route:cache && \
  php artisan migrate --force || true && \
  echo "Starting Octane..." && \
  php artisan octane:frankenphp --host=0.0.0.0 --port=80 --workers=1'

