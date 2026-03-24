FROM php:8.4-cli-alpine

# Install system deps
RUN apk add --no-cache \
    libpq-dev \
    libstdc++ \
    openssl-dev \
    curl-dev \
    linux-headers \
    $PHPIZE_DEPS

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_pgsql pcntl bcmath

# Compile Swoole from source with pgsql coroutine support
RUN apk add --no-cache git && \
    cd /tmp && \
    git clone --depth 1 --branch v6.0.2 https://github.com/swoole/swoole-src.git && \
    cd swoole-src && \
    phpize && \
    ./configure \
        --enable-openssl \
        --enable-swoole-curl \
        --enable-swoole-pgsql && \
    make -j$(nproc) && \
    make install && \
    cd / && rm -rf /tmp/swoole-src && \
    docker-php-ext-enable swoole

# Install Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Cleanup build deps
RUN apk del $PHPIZE_DEPS git linux-headers

WORKDIR /app

# Copy composer files
COPY composer.json composer.lock ./
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Copy application
COPY . .

# Ensure storage dirs exist
RUN mkdir -p storage/logs storage/app storage/framework/cache storage/framework/sessions storage/framework/views runtime

# Expose Swoole port
EXPOSE 9501

# Startup: run migrations then start server
COPY <<'ENTRYPOINT' /app/entrypoint.sh
#!/bin/sh
set -e
echo "Running migrations..."
php artisan migrate --force 2>&1 || echo "Migration warning (may already be up to date)"

echo "Starting queue workers in background..."
php artisan queue:work --queue=payments-webhooks-high --sleep=1 --max-time=3600 &
php artisan queue:work --queue=payments-postbacks-high --sleep=1 --max-time=3600 &

echo "Starting Hypervel server..."
exec php artisan serve
ENTRYPOINT
RUN chmod +x /app/entrypoint.sh

CMD ["/app/entrypoint.sh"]
