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

# Install Swoole with pgsql support
RUN pecl install swoole && \
    docker-php-ext-enable swoole

# We also need swoole compiled with --enable-swoole-pgsql
# So we compile from source instead
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

# Start Hypervel server
CMD ["php", "artisan", "serve"]
