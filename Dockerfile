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
    pdo \
    pdo_sqlite \
    sqlite3 \
    opcache

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Copy application files
COPY . .

# Copy Caddyfile for FrankenPHP
COPY Caddyfile.container /etc/caddy/Caddyfile

# Set environment for production
ENV APP_ENV=prod
ENV APP_DEBUG=0

# Install dependencies
RUN composer install --no-interaction --optimize-autoloader --no-dev

# Create necessary directories and set permissions
RUN mkdir -p var/cache var/log var/data && \
    chmod -R 777 var

# Copy and set up entrypoint script
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Expose port
EXPOSE 80

# Use entrypoint for runtime initialization
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]

