# TimeDeo API — PHP 8.2 + Apache (mod_php), serving server/*.php as-is.
#
# Build context = this `server/` directory. On Railway set the service's
# Settings → Root Directory to `server` so this Dockerfile is used.
FROM php:8.2-apache

# The only PHP extension the app needs (PDO MySQL driver).
RUN docker-php-ext-install pdo_mysql

# Use PHP's PRODUCTION ini (display_errors=Off, etc.) so uncaught errors are
# never dumped to clients. Error detail is additionally gated by APP_DEBUG.
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Copy ONLY the PHP files into the web root. init*.sql, *.md, the Dockerfile and
# the entrypoint are intentionally NOT web-served (see .dockerignore + COPY glob).
WORKDIR /var/www/html
COPY *.php ./

# Apache must listen on the platform-assigned $PORT (Railway/Fly inject it).
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh
ENTRYPOINT ["docker-entrypoint.sh"]
