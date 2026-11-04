# Test runner with all database drivers the bundle is tested against.
FROM php:8.5.12-cli@sha256:9236fd373d3bcda0659cf94838a744bd79791419292958963f8c6b34a0071554
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_mysql pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*
