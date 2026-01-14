#!/bin/bash

echo "=================================="
echo "Clearing Laravel & Docker Caches"
echo "=================================="
echo ""

echo "1. Clearing Laravel application cache..."
docker-compose exec app php artisan cache:clear

echo ""
echo "2. Clearing Laravel configuration cache..."
docker-compose exec app php artisan config:clear

echo ""
echo "3. Clearing Laravel route cache..."
docker-compose exec app php artisan route:clear

echo ""
echo "4. Clearing Laravel view cache..."
docker-compose exec app php artisan view:clear

echo ""
echo "5. Clearing Laravel event cache..."
docker-compose exec app php artisan event:clear

echo ""
echo "6. Clearing Composer cache..."
docker-compose exec app composer clear-cache

echo ""
echo "7. Restarting Docker containers..."
docker-compose restart

echo ""
echo "=================================="
echo "Done! Caches cleared and containers restarted."
echo "=================================="
echo ""
echo "Please wait a few seconds for containers to fully restart,"
echo "then refresh your browser (Ctrl+Shift+R) to see the changes."
