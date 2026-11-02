FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --prefer-dist --no-progress --no-scripts

FROM php:8.3-cli
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql pcntl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY --from=vendor /app/vendor ./vendor
COPY public ./public
COPY src ./src
COPY bin ./bin

EXPOSE 8080
USER www-data
# The built-in server is fine for this exercise. Say what you would use in production.
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
