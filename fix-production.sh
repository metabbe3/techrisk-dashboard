#!/bin/bash
# fix-production.sh - Run this on the production server to fix 500 errors

set -e  # Exit on any error

echo "=========================================="
echo "Techrisk Dashboard Production Fix Script"
echo "=========================================="
echo ""

# Check if running as correct user
if [ "$EUID" -eq 0 ]; then
    echo "⚠️  Warning: Running as root. This script should run as the web user (e.g., www-data)."
    read -p "Continue anyway? (y/n) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 1
    fi
fi

# 1. Generate APP_KEY
echo "Step 1: Generating APP_KEY..."
if php artisan key:generate --force; then
    echo "✓ APP_KEY generated"
else
    echo "✗ Failed to generate APP_KEY"
    exit 1
fi
echo ""

# 2. Build Vite assets
echo "Step 2: Building Vite assets..."
if [ ! -d "node_modules" ]; then
    echo "Node modules not found. Running npm install..."
    npm install
fi
if npm run build; then
    echo "✓ Assets built"
else
    echo "✗ Failed to build assets"
    exit 1
fi
echo ""

# 3. Fix storage symlink
echo "Step 3: Creating storage symlink..."
if php artisan storage:link; then
    echo "✓ Storage symlink created"
else
    echo "⚠️  Storage symlink may already exist or failed"
fi
echo ""

# 4. Set file permissions
echo "Step 4: Setting file permissions..."
chmod -R 755 storage 2>/dev/null || echo "⚠️  Could not set storage permissions"
chmod -R 755 bootstrap/cache 2>/dev/null || echo "⚠️  Could not set bootstrap cache permissions"
chmod -R 644 public/build/assets/* 2>/dev/null || echo "⚠️  Could not set asset permissions"
echo "✓ Permissions set"
echo ""

# 5. Clear all caches
echo "Step 5: Clearing all caches..."
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear
php artisan optimize:clear
echo "✓ Caches cleared"
echo ""

# 6. Rebuild cache
echo "Step 6: Rebuilding caches..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan optimize
echo "✓ Caches rebuilt"
echo ""

# 7. Test configuration
echo "Step 7: Testing configuration..."
if php artisan config:cache >/dev/null 2>&1; then
    echo "✓ Configuration is valid"
else
    echo "✗ Configuration has errors"
    php artisan config:cache
    exit 1
fi
echo ""

echo "=========================================="
echo "✓ Production fix complete!"
echo "=========================================="
echo ""
echo "IMPORTANT: Update your .env file with these values:"
echo ""
echo "APP_NAME=TechriskDashboard"
echo "APP_ENV=production"
echo "APP_DEBUG=false"
echo "APP_URL=https://techrisk.paas.dana.id"
echo ""
echo "SESSION_SECURE_COOKIE=true"
echo "ASSET_URL=https://techrisk.paas.dana.id"
echo "FILAMENT_FILESYSTEM_DISK=public"
echo ""
echo "After updating .env, restart services:"
echo "  - sudo systemctl restart php8.2-fpm"
echo "  - sudo systemctl reload nginx"
echo ""
echo "Don't forget to configure Supervisor for queue workers!"
echo "See the plan file for supervisor configuration."
echo ""
