#!/bin/bash
# Author Emad Zaamout | support@ahtcloud.com

# Runs inside production server.

# Project directory on server for your project.
export WEB_DIR="/var/www/tujitume/api-prod"
# Your server user. Used to fix permission issue & install our project dependcies

# Change directory to project.
cd $WEB_DIR

# change user owner to ubuntu & fix storage permission issues.
sudo chown -R ubuntu:ubuntu .
sudo chown -R www-data storage
sudo chmod -R u+x .
sudo chmod g+w -R storage
sudo chown -R www-data public
sudo chown -R www-data React
unlink bootstrap/cache/config.php
#Restart WebSockets via Supervisor
sudo supervisorctl restart laravel-websockets
#Killing Screen & Create New NPM Screen
#killall screen
#screen -S serversession
#echo $STY
#cd /var/www/test.jitume/React
#sudo npm run dev

