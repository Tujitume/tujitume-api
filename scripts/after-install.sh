#!/bin/bash
set -e

APP_DIR="/var/www/tujitume/api-prod"

cd "$APP_DIR"

echo "Fixing permissions..."

sudo chown -R ubuntu:www-data "$APP_DIR"

sudo find "$APP_DIR" -type d -exec chmod 775 {} \;
sudo find "$APP_DIR" -type f -exec chmod 664 {} \;

sudo chmod -R 775 "$APP_DIR/storage"
sudo chmod -R 775 "$APP_DIR/bootstrap/cache"

sudo find "$APP_DIR/storage" -type d -exec chmod g+s {} \;
sudo find "$APP_DIR/bootstrap/cache" -type d -exec chmod g+s {} \;

echo "Clearing Laravel caches..."

php artisan optimize:clear

echo "Caching configuration..."

php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "Running migrations..."

php artisan migrate --force

echo "Restarting queues..."

php artisan queue:restart

echo "Restarting WebSockets..."

sudo supervisorctl restart laravel-websockets

echo "Reloading Apache..."

sudo systemctl reload apache2

echo "Deployment complete."

#Killing Screen & Create New NPM Screen
#killall screen
#screen -S serversession
#echo $STY
#cd /var/www/test.jitume/React
#sudo npm run dev

