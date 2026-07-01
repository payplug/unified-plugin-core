FROM composer:2 AS composer

FROM php:7.4-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libzip-dev \
        libonig-dev \
        libxml2-dev \
    && docker-php-ext-install mbstring xml dom simplexml xmlwriter zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

RUN useradd --create-home --uid 1000 appuser

WORKDIR /app

USER appuser
