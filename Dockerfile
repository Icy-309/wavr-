FROM php:8.3-cli

# pdo_pgsql for Supabase, gd for avatar resizing; curl, mbstring and sodium
# ship with the official image.
RUN apt-get update  && apt-get install -y --no-install-recommends libpq-dev libjpeg62-turbo-dev libpng-dev libwebp-dev  && docker-php-ext-configure gd --with-jpeg --with-webp  && docker-php-ext-install pdo_pgsql gd  && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . .
RUN mkdir -p uploads/avatars && chmod +x start.sh

CMD ["./start.sh"]
