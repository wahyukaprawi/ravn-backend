FROM php:8.5-fpm-trixie

ARG APP_UID=33
ARG APP_GID=33

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        zip \
        curl \
        ca-certificates \
        libicu-dev \
        libzip-dev \
        zlib1g-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libwebp-dev \
        libonig-dev \
        libcurl4-openssl-dev \
        default-mysql-client \
        iproute2 \
        acl \
        supervisor \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql \
    && docker-php-ext-install -j"$(nproc)" intl \
    && docker-php-ext-install -j"$(nproc)" zip \
    && docker-php-ext-install -j"$(nproc)" gd \
    && docker-php-ext-install -j"$(nproc)" bcmath \
    && docker-php-ext-install -j"$(nproc)" exif \
    && docker-php-ext-install -j"$(nproc)" pcntl

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN { \
        echo 'memory_limit = 512M'; \
        echo 'upload_max_filesize = 64M'; \
        echo 'post_max_size = 64M'; \
        echo 'max_execution_time = 60'; \
        echo 'date.timezone = UTC'; \
        echo 'display_errors = On'; \
        echo 'error_reporting = E_ALL'; \
        echo 'opcache.enable = 1'; \
        echo 'opcache.validate_timestamps = 1'; \
        echo 'opcache.revalidate_freq = 0'; \
    } > /usr/local/etc/php/conf.d/zz-ravn.ini \
    && { \
        echo '[www]'; \
        echo 'pm = dynamic'; \
        echo 'pm.max_children = 16'; \
        echo 'pm.start_servers = 3'; \
        echo 'pm.min_spare_servers = 2'; \
        echo 'pm.max_spare_servers = 6'; \
        echo 'clear_env = no'; \
    } > /usr/local/etc/php-fpm.d/zz-ravn.conf

COPY docker/supervisor/queue.conf /etc/supervisor/conf.d/queue.conf

WORKDIR /var/www/html

COPY . /var/www/html

RUN mkdir -p \
        storage/framework/cache \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/framework/testing \
        storage/logs \
        storage/app/public \
        bootstrap/cache \
    && chown -R "${APP_UID}:${APP_GID}" storage bootstrap/cache

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 9000

HEALTHCHECK --interval=10s --timeout=5s --start-period=30s --retries=6 \
    CMD ss -ltn | grep -q ':9000' || exit 1

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
