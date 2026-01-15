#!/bin/sh
set -e

# Wait for database at $DB_HOST:$DB_PORT
MAX_TRIES=600
TRIES=0

until php -r "try { new PDO(\"mysql:host=\" . getenv(\"DB_HOST\") . \";port=\" . getenv(\"DB_PORT\"), getenv(\"DB_USERNAME\"), getenv(\"DB_PASSWORD\"), [PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false]); exit(0); } catch (Exception \$e) { exit(1); }" > /dev/null 2>&1; do
    TRIES=$((TRIES+1))
    if [ $TRIES -ge $MAX_TRIES ]; then
        exit 1
    fi
    # Silent wait
    sleep 2
done

# Cache config and routes for performance
php artisan config:cache > /dev/null 2>&1
php artisan route:cache > /dev/null 2>&1

# Run migrations ONLY for the app service (when command contains octane)
if echo "$@" | grep -q "octane:frankenphp"; then
    php artisan migrate --force --quiet || true
fi

# Execute the main command
exec "$@"
