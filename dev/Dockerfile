# PHP 8.1 CLI tooling image: Composer, PHPCS, PHPStan, PHPUnit (WP integration suite).
FROM php:8.1-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        subversion \
        unzip \
        less \
        default-mysql-client \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" mysqli zip gd \
    && rm -rf /var/lib/apt/lists/*

# Composer (from the official image).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

WORKDIR /workspace

CMD ["tail", "-f", "/dev/null"]
