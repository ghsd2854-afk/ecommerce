FROM php:8.4-fpm

RUN apt-get update && apt-get install -y \
    git unzip libzip-dev libpng-dev libonig-dev libxml2-dev \
    && docker-php-ext-install pdo_mysql mbstring zip gd bcmath

# gRPC PHP extension (Requirement 2: the app calls the inventory service over gRPC).
# Compiled once (Docker caches this layer). MAKEFLAGS limits parallel compile jobs
# so the build does not run out of memory on Docker Desktop / WSL.
ARG GRPC_BUILD_JOBS=2
RUN MAKEFLAGS="-j${GRPC_BUILD_JOBS}" pecl install grpc \
    && docker-php-ext-enable grpc \
    && rm -rf /tmp/pear

# Requirement 3: Redis client extension (phpredis) for the cache.
# A separate layer AFTER gRPC, so adding it does not recompile gRPC (Docker reuses that layer).
RUN pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /tmp/pear

# OPcache: keeps compiled PHP in memory instead of re-reading every file on each request
RUN docker-php-ext-install opcache
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
