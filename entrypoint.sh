#!/bin/sh
set -e

echo "Waiting for database at $DB_HOST:$DB_PORT..."
MAX_TRIES=150
TRIES=0

until php -r "try { new PDO(\"mysql:host=\" . getenv(\"DB_HOST\") . \";port=\" . getenv(\"DB_PORT\"), getenv(\"DB_USERNAME\"), getenv(\"DB_PASSWORD\"), [PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false]); exit(0); } catch (Exception \$e) { echo \$e->getMessage(); exit(1); }" > /tmp/db_error 2>&1; do
    TRIES=$((TRIES+1))
    if [ $TRIES -ge $MAX_TRIES ]; then
        echo "Database connection failed after $MAX_TRIES attempts (5 minutes)"
        echo "Last error: $(cat /tmp/db_error)"
        exit 1
    fi
    # Only echo every 5 tries to keep logs clean, or if it's the first try
    if [ $((TRIES % 5)) -eq 0 ] || [ $TRIES -eq 1 ]; then
        echo "Attempt $TRIES/$MAX_TRIES - waiting for MySQL at $DB_HOST:$DB_PORT... (Last error: $(cat /tmp/db_error))"
    fi
    sleep 2
done

echo "Database ready!"

# Cache config and routes
php artisan config:cache
php artisan route:cache

# Run migrations ONLY for the app service (when command contains octane)
if echo "$@" | grep -q "octane:frankenphp"; then
    echo "Running migrations..."
    php artisan migrate --force
fi

echo "Starting: $@"
exec "$@"
