FROM php:8.4-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git curl zip unzip \
    libpq-dev libpng-dev libonig-dev libxml2-dev libzip-dev \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_pgsql pdo_mysql mbstring exif pcntl bcmath gd zip

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Copy and install dependencies
COPY . .
RUN composer install --no-dev --optimize-autoloader

# Storage permissions
RUN chmod -R 775 storage bootstrap/cache

EXPOSE 8000

CMD php artisan migrate --force && php artisan admin:setup && php artisan serve --host=0.0.0.0 --port=${PORT:-8000}
