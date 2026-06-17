FROM php:8.4-cli

# Install system dependencies (including JPEG, WebP, and ImageMagick libs)
RUN apt-get update && apt-get install -y \
    git curl zip unzip \
    libpq-dev libpng-dev libonig-dev libxml2-dev libzip-dev \
    libjpeg62-turbo-dev libwebp-dev \
    libmagickwand-dev \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Configure GD with JPEG + WebP support, then install all extensions
RUN docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install pdo pdo_pgsql pdo_mysql mbstring exif pcntl bcmath gd zip \
    && pecl install imagick \
    && docker-php-ext-enable imagick

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Copy and install dependencies
COPY . .
RUN composer install --no-dev --optimize-autoloader

# Storage permissions
RUN chmod -R 775 storage bootstrap/cache

EXPOSE 8000

CMD php artisan migrate --force && php artisan admin:setup && php artisan optimize && php artisan serve --host=0.0.0.0 --port=${PORT:-8000}
