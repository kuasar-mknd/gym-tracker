#!/bin/sh
set -e

echo "Waiting for database at $DB_HOST:$DB_PORT..."
MAX_TRIES=60
TRIES=0

until php -r "try { new PDO(\"mysql:host=\" . getenv(\"DB_HOST\") . \";port=\" . getenv(\"DB_PORT\"), getenv(\"DB_USERNAME\"), getenv(\"DB_PASSWORD\"), [PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false]); exit(0); } catch (Exception \$e) { exit(1); }" 2>/dev/null; do
    TRIES=$((TRIES+1))
    if [ $TRIES -ge $MAX_TRIES ]; then
        echo "Database connection failed after $MAX_TRIES attempts (120 seconds)"
        exit 1
    fi
    echo "Attempt $TRIES/$MAX_TRIES - waiting for MySQL at $DB_HOST:$DB_PORT..."
    sleep 2
done

echo "Database ready!"

# Cache config and routes
php artisan config:cache
php artisan route:cache

# Run migrations ONLY for the app service (when command is octane)
if echo "$@" | grep -q "octane:frankenphp"; then
    echo "Running migrations..."
    php artisan migrate --force
fi

echo "Starting: $@"
exec "$@"
