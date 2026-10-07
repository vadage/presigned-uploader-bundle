# Test runner with all database drivers the bundle is tested against.
FROM php:8.5.11-cli@sha256:211e1f4cce06289b8d390fc4c36209fc8cae79f3c5f08eaa3a160a71a2df2211
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_mysql pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*
