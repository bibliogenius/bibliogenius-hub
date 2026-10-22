# Pinned for reproducible builds: FrankenPHP 1.13.1 (Caddy 2.11.7) on PHP
# 8.3.36 (CVE-2026-109618, CVE-2026-109619). Pin the PHP patch too: a bare
# "-php8.3" tag stops being rebuilt once its FrankenPHP release is superseded,
# silently freezing PHP. Bump deliberately.
FROM dunglas/frankenphp:1.13.1-php8.3.36

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    postgresql-client \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
# sodium: Ed25519 verification for account E2EE sync auth (ADR-043). Made
# explicit for reproducible builds rather than relying on the base image.
RUN install-php-extensions \
    pdo \
    pdo_pgsql \
    pgsql \
    intl \
    opcache \
    sodium

# Production OPcache tuning (prod image only; dev keeps timestamp validation).
# zz- prefix loads it after the extension's own conf.d file so it wins.
COPY docker/opcache-prod.ini /usr/local/etc/php/conf.d/zz-opcache-prod.ini

# Production PHP hardening (prod image only).
COPY docker/php-prod.ini /usr/local/etc/php/conf.d/zz-php-prod.ini

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

# App version (injected from git tag at build time)
ARG APP_VERSION=dev
ENV APP_VERSION=${APP_VERSION}

# Install dependencies (--no-scripts skips cache:clear which needs a live DB;
# the entrypoint handles cache:clear and cache:warmup at runtime)
RUN composer install --no-interaction --optimize-autoloader --no-dev --no-scripts

# Copy and set up entrypoint script
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Run as an unprivileged user with a fixed UID, so the one-time chown of the
# existing var volume and of the host cities dir is the same everywhere.
# Only var/ (cache, logs, covers) and Caddy's own state dirs are writable;
# the code stays root-owned and read-only for the PHP process.
# setcap -r: the base image grants frankenphp cap_net_bind_service as a file
# capability, which makes exec fail once compose drops every capability.
# Port 80 still binds through Docker's default
# net.ipv4.ip_unprivileged_port_start=0.
RUN groupadd --system --gid 10001 hub && \
    useradd --system --uid 10001 --gid hub --no-create-home --shell /usr/sbin/nologin hub && \
    mkdir -p var/cache var/log var/data && \
    chown -R hub:hub var /config/caddy /data/caddy && \
    setcap -r /usr/local/bin/frankenphp

USER hub

# Expose port
EXPOSE 80

# Use entrypoint for runtime initialization
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]

