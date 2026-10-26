# ==============================================================================
# Stage 1: Build & Dependencies
# ==============================================================================
FROM composer:2.7 AS builder

WORKDIR /build

# Copy composer manifests
COPY composer.json composer.lock ./

# Install production dependencies only (no dev dependencies, optimized autoloader)
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --ignore-platform-reqs

# ==============================================================================
# Stage 2: Production Lean Runtime
# ==============================================================================
FROM php:8.4-fpm-alpine AS runner

# Install essential system utilities and libraries
RUN apk add --no-cache \
    nginx \
    supervisor \
    bash \
    curl \
    libpng \
    libjpeg-turbo \
    freetype \
    libzip \
    icu-libs \
    libxml2 \
    oniguruma \
    mariadb-client

# Install build dependencies, compile PHP extensions, and clean up build headers
RUN apk add --no-cache --virtual .build-deps \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        libzip-dev \
        icu-dev \
        libxml2-dev \
        oniguruma-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        gd \
        zip \
        intl \
        opcache \
        bcmath \
        exif \
        soap \
        pcntl \
    && apk del .build-deps \
    && rm -rf /var/cache/apk/*

# Production PHP & OPcache tuning
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=256'; \
        echo 'opcache.interned_strings_buffer=16'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.revalidate_freq=0'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'upload_max_filesize=50M'; \
        echo 'post_max_size=50M'; \
        echo 'memory_limit=512M'; \
        echo 'max_execution_time=180'; \
        echo 'date.timezone=UTC'; \
        echo 'display_errors=Off'; \
        echo 'log_errors=On'; \
    } > /usr/local/etc/php/conf.d/espocrm-production.ini

WORKDIR /var/www/html

# Copy application source code
COPY --chown=www-data:www-data . /var/www/html

# Copy optimized production dependencies from builder stage
COPY --from=builder --chown=www-data:www-data /build/vendor /var/www/html/vendor

# Ensure proper write permissions for data and custom runtime directories
RUN chmod -R 775 /var/www/html/data /var/www/html/custom

# Copy production startup & supervisor configuration
COPY deploy/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
COPY deploy/supervisord.conf /etc/supervisord.conf
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80 9000

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
  CMD curl -f http://localhost:80/api/v1/App/user || exit 1

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
