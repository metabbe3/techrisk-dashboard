#!/bin/bash

echo "=================================="
echo "Clearing Laravel & Docker Caches"
echo "=================================="
echo ""

echo "1. Clearing Laravel application cache..."
docker compose exec app php artisan cache:clear

echo ""
echo "2. Clearing Laravel configuration cache..."
docker compose exec app php artisan config:clear

echo ""
echo "3. Clearing Laravel route cache..."
docker compose exec app php artisan route:clear

echo ""
echo "4. Clearing Laravel view cache..."
docker compose exec app php artisan view:clear

echo ""
echo "5. Clearing Laravel event cache..."
docker compose exec app php artisan event:clear

echo ""
echo "6. Clearing Composer cache..."
docker compose exec app composer clear-cache

echo ""
echo "7. Checking for pending migrations..."
PENDING=$(docker compose exec app php artisan migrate:status 2>/dev/null | grep -c "Pending" || true)

if [ "$PENDING" -gt 0 ]; then
    echo ""
    echo "  ⚠  $PENDING pending migration(s) detected!"
    echo ""
    docker compose exec app php artisan migrate:status 2>/dev/null | grep "Pending"
    echo ""
    read -p "  Run pending migrations now? (y/n): " -n 1 -r
    echo ""
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        echo "  Running migrations..."
        docker compose exec app php artisan migrate --force
        echo "  ✓ Migrations completed"
    else
        echo "  ⚠  Skipping migrations. Run manually: docker compose exec app php artisan migrate"
    fi
else
    echo "  ✓ No pending migrations"
fi

echo ""
echo "8. Fixing storage directory permissions..."

# Create directories that must exist
docker compose exec app mkdir -p storage/app/temp
docker compose exec app mkdir -p storage/app/private/chat-attachments
docker compose exec app mkdir -p storage/app/private/markdown/documents
docker compose exec app mkdir -p storage/app/public/investigation-forms
docker compose exec app mkdir -p storage/framework/cache
docker compose exec app mkdir -p storage/framework/sessions
docker compose exec app mkdir -p storage/framework/views

# Set permissions so www-data can read/write
docker compose exec app chmod -R 775 storage/app/temp
docker compose exec app chmod -R 775 storage/app/private
docker compose exec app chmod -R 775 storage/app/public
docker compose exec app chmod -R 775 storage/framework
docker compose exec app chown -R www-data:www-data storage

echo "  ✓ Storage permissions fixed"

echo ""
echo "9. Restarting queue workers (picks up code changes)..."
docker compose exec worker php artisan queue:restart 2>/dev/null || docker exec techrisk-worker-1 php artisan queue:restart 2>/dev/null || true
echo "  ✓ Workers restarting"

echo ""
echo "10. Restarting Docker containers..."
docker compose restart

echo ""
echo "11. Caching Laravel config for production..."
sleep 3
docker compose exec app php artisan config:cache
echo "  ✓ Config cached"

echo ""
echo "=================================="
echo "Done! Caches cleared and containers restarted."
echo "=================================="
echo ""
echo "Please wait a few seconds for containers to fully restart,"
echo "then refresh your browser (Ctrl+Shift+R) to see the changes."
