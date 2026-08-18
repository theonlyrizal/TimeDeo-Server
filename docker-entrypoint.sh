#!/bin/sh
set -e

# Railway / Fly / most PaaS inject a $PORT the app MUST listen on.
# Apache defaults to 80; rewrite its listen port to $PORT at container start.
# Falls back to 80 for a plain `docker run` locally.
PORT="${PORT:-80}"

sed -ri "s/^Listen [0-9]+/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

exec apache2-foreground
