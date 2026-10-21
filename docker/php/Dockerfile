# Test runner with all database drivers the bundle is tested against.
FROM php:8.6.0RC2-cli@sha256:9340d1f21a99b4b4310f33c7dd52334555799997f8c84b631f6941f75eb653a7
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_mysql pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*
