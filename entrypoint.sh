#!/bin/bash
set -euo pipefail

# Wait for database at $DB_HOST:$DB_PORT
MAX_TRIES=600
TRIES=0

until php -r "try { new PDO(\"mysql:host=\" . getenv(\"DB_HOST\") . \";port=\" . getenv(\"DB_PORT\"), getenv(\"DB_USERNAME\"), getenv(\"DB_PASSWORD\"), [PDO::ATTR_TIMEOUT => 2]); exit(0); } catch (Exception \$e) { echo 'Waiting for DB: ' . \$e->getMessage() . PHP_EOL; exit(1); }"; do

    TRIES=$((TRIES+1))
    if [ $TRIES -ge $MAX_TRIES ]; then
        echo "Error: Database did not become available in time."
        exit 1
    fi
    sleep 2
done

# Cache everything for maximum performance
echo "Caching configuration..."
php artisan config:cache

echo "Caching routes..."
php artisan package:discover --ansi
php artisan storage:link
php artisan route:cache

echo "Caching views..."
php artisan view:cache

echo "Caching events..."
php artisan event:cache

# Run migrations ONLY for the app service (when command contains octane)
if echo "$@" | grep -q "octane:frankenphp"; then
    php artisan migrate --force --quiet || true
    php artisan filament:upgrade --no-interaction
fi

# Execute the main command
exec "$@"
