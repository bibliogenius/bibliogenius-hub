FROM dunglas/frankenphp:latest-php8.3

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    sqlite3 \
    libsqlite3-dev \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN install-php-extensions \
    pdo_sqlite \
    opcache

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Copy application files
COPY . .

# Install dependencies
RUN composer install --no-interaction --optimize-autoloader --no-dev

# Create necessary directories
RUN mkdir -p var/cache var/log var/data && \
    chmod -R 777 var

# Set up database
RUN php bin/console doctrine:database:create --if-not-exists || true && \
    php bin/console doctrine:schema:update --force || true

# Expose port
EXPOSE 80

# Start FrankenPHP in PHP server mode
CMD ["frankenphp", "php-server", "-r", "/app/public"]
