#!/bin/sh
set -eu

cd /var/www/html

php docker/write-env.php
chown root:www-data .env
chmod 640 .env

php docker/ensure-installed.php

mkdir -p storage/lang
chown -R www-data:www-data storage

if [ -f storage/initial-admin-credentials.txt ]; then
    chown root:root storage/initial-admin-credentials.txt
    chmod 600 storage/initial-admin-credentials.txt
fi

exec apache2-foreground
