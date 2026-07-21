#!/bin/bash
set -e

APP_DIR="/var/www/tujitume/api-prod"

cd "$APP_DIR"

echo "Fixing permissions..."

sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache

echo "Clearing Laravel caches..."

php artisan optimize:clear

echo "Caching configuration..."

php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "Running migrations..."

php artisan migrate --force

echo "Restarting queues..."

php artisan queue:restart || true

echo "Restarting WebSockets..."

sudo supervisorctl restart laravel-websockets || true

echo "Reloading Apache..."

sudo systemctl reload apache2

echo "Deployment complete."

#Killing Screen & Create New NPM Screen
#killall screen
#screen -S serversession
#echo $STY
#cd /var/www/test.jitume/React
#sudo npm run dev

