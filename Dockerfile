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

# Install supervisor
RUN apk add --no-cache supervisor

# Supervisor config
COPY <<'SUPERVISORD' /etc/supervisor/conf.d/payments-core.conf
[supervisord]
nodaemon=true
logfile=/dev/stdout
logfile_maxbytes=0

[program:hypervel]
command=php /app/artisan serve
autostart=true
autorestart=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0
priority=10

[program:worker-inbound]
command=php /app/artisan queue:work --queue=payments-webhooks-high --sleep=1 --max-time=3600
autostart=true
autorestart=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0
priority=20

[program:worker-postback]
command=php /app/artisan queue:work --queue=payments-postbacks-high --sleep=1 --max-time=3600
autostart=true
autorestart=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0
priority=20
SUPERVISORD

COPY <<'ENTRYPOINT' /app/entrypoint.sh
#!/bin/sh
set -e
echo "Running migrations..."
php artisan migrate --force 2>&1 || echo "Migration warning (may already be up to date)"
echo "Starting supervisor (server + workers)..."
exec supervisord -c /etc/supervisor/conf.d/payments-core.conf
ENTRYPOINT
RUN chmod +x /app/entrypoint.sh

CMD ["/app/entrypoint.sh"]
