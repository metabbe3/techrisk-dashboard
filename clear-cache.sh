#!/bin/bash

echo "=================================="
echo "Clearing Laravel & Docker Caches"
echo "=================================="
echo ""

echo "0. Pre-flight: reset bootstrap cache + sync vendor to composer.lock..."
# rm BEFORE any artisan call: a stale packages.php breaks artisan boot itself
# (prod 2026-10-30: ScribeServiceProvider not found — manifest listed a removed dev pkg)
docker compose exec app sh -c "rm -f bootstrap/cache/packages.php bootstrap/cache/services.php bootstrap/cache/config.php"
# --no-scripts: composer script events (pre-package-uninstall) boot the app
# mid-install and die on packages not yet installed; package:discover rebuilds
# the manifest by hand (facade aliases like Mcp live there, not in config/app.php)
docker compose exec app composer install --no-dev --optimize-autoloader --no-interaction --no-scripts
docker compose exec app php artisan package:discover
echo "  ✓ vendor synced, bootstrap caches clean"

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
docker compose exec app mkdir -p bootstrap/cache

# Set permissions so www-data can read/write
docker compose exec app chmod -R 775 storage/app/temp
docker compose exec app chmod -R 775 storage/app/private
docker compose exec app chmod -R 775 storage/app/public
docker compose exec app chmod -R 775 storage/framework
docker compose exec app chmod -R 775 bootstrap/cache
docker compose exec app chown -R www-data:www-data storage bootstrap/cache

echo "  ✓ Storage permissions fixed"

echo ""
echo "9. Restarting queue workers (picks up code changes)..."
docker compose exec worker php artisan queue:restart 2>/dev/null || docker exec techrisk-worker-1 php artisan queue:restart 2>/dev/null || true
echo "  ✓ Workers restarting"

echo ""
echo "10. Restarting Docker containers..."
docker compose restart

echo ""
echo "11. Microsoft SSO env scaffold (appends blank MICROSOFT_* vars if absent)..."
# .env is gitignored, so the SSO vars reach prod through this script. Never
# overwrites existing values — fills only what's missing. Runs BEFORE
# config:cache below so the cached config picks the values up.
docker compose exec app sh -c '
touch .env
grep -q "^MICROSOFT_CLIENT_ID=" .env || echo "MICROSOFT_CLIENT_ID=" >> .env
grep -q "^MICROSOFT_CLIENT_SECRET=" .env || echo "MICROSOFT_CLIENT_SECRET=" >> .env
grep -q "^MICROSOFT_TENANT_ID=" .env || echo "MICROSOFT_TENANT_ID=" >> .env
grep -q "^MICROSOFT_REDIRECT_URI=" .env || echo "MICROSOFT_REDIRECT_URI=https://techrisk.paas.dana.id/auth/microsoft/callback" >> .env
MISSING=""
[ -z "$(grep "^MICROSOFT_CLIENT_ID=." .env | cut -d= -f2-)" ] && MISSING="$MISSING MICROSOFT_CLIENT_ID"
[ -z "$(grep "^MICROSOFT_CLIENT_SECRET=." .env | cut -d= -f2-)" ] && MISSING="$MISSING MICROSOFT_CLIENT_SECRET"
[ -z "$(grep "^MICROSOFT_TENANT_ID=." .env | cut -d= -f2-)" ] && MISSING="$MISSING MICROSOFT_TENANT_ID"
[ -z "$(grep "^MICROSOFT_REDIRECT_URI=." .env | cut -d= -f2-)" ] && MISSING="$MISSING MICROSOFT_REDIRECT_URI"
if [ -n "$MISSING" ]; then
    echo "  ⚠  Still blank:$MISSING — the SSO button will fail until filled (password login unaffected)"
else
    echo "  ✓  All MICROSOFT_* vars set. Redirect URI:"
    echo "     $(grep "^MICROSOFT_REDIRECT_URI=" .env | cut -d= -f2-)"
    echo "     (must match the Azure app registration exactly)"
fi'
echo "  ✓ SSO env scaffold done"

echo ""
echo "12. Caching Laravel config for production..."
sleep 3
# -u www-data: run as the web user so config.php isn't root-owned (root-owned
# cache files are the "Permission denied" 500s this step 8 exists to fix)
docker compose exec -u www-data app php artisan config:cache
echo "  ✓ Config cached"

echo ""
echo "=================================="
echo "Done! Caches cleared and containers restarted."
echo "=================================="
echo ""
echo "Please wait a few seconds for containers to fully restart,"
echo "then refresh your browser (Ctrl+Shift+R) to see the changes."
