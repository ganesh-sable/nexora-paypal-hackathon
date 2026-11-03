#!/bin/sh
set -e

# Configure Apache port dynamically based on Render's $PORT env var (defaults to 10000)
APP_PORT="${PORT:-10000}"

sed -i "s/Listen 80/Listen ${APP_PORT}/g" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost \*:${APP_PORT}>/g" /etc/apache2/sites-available/000-default.conf

exec "$@"
