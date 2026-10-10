FROM php:8.3-cli

# pdo_pgsql for Supabase; curl, mbstring and sodium ship with the official image.
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev unzip \
 && docker-php-ext-install pdo_pgsql \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --optimize-autoloader

COPY . .
RUN mkdir -p uploads/avatars && chmod +x start.sh

CMD ["./start.sh"]
