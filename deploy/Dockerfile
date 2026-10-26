# Multi-stage / Production Dockerfile for EspoCRM Fork
FROM php:8.4-fpm-alpine AS base

# Install system dependencies
RUN apk add --no-cache \
    nginx \
    supervisor \
    bash \
    curl \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    icu-dev \
    libxml2-dev \
    oniguruma-dev \
    mariadb-client

# Install PHP extensions required by EspoCRM
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        gd \
        zip \
        intl \
        opcache \
        bcmath \
        exif \
        soap \
        pcntl

# Configure PHP for Production
RUN { \
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
    } > /usr/local/etc/php/conf.d/espocrm-production.ini

# Set working directory
WORKDIR /var/www/html

# Copy application code into container
COPY --chown=www-data:www-data . /var/www/html

# Ensure proper write permissions for runtime directories
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
