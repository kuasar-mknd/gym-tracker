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

# Expose port
EXPOSE 80

# Use artisan serve (more reliable than Octane for initial deployment)
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=80"]
