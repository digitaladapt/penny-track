# ── Stage 1: Composer ──────────────────────────────────────────────
FROM dunglas/frankenphp:1-php8.4 AS composer

# System deps for composer install
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Build arg for application version (pass with --build-arg APP_VERSION=v1.3.0 in CI)
ARG APP_VERSION=dev

# Copy only manifests first for better layer caching
COPY composer.json composer.lock symfony.lock ./

RUN composer install --no-dev --no-interaction --no-scripts

# Copy the rest of the application
COPY . .

# Write the version file (used by SystemController at runtime)
RUN echo "${APP_VERSION}" > VERSION

# Run composer auto-scripts (cache:clear, assets:install, importmap:install)
ENV APP_ENV=prod
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && composer run-script --no-dev post-install-cmd

# ── Stage 2: Runtime ───────────────────────────────────────────────
FROM dunglas/frankenphp:1-php8.4 AS runtime

# Install only runtime system deps
RUN apt-get update && apt-get install -y --no-install-recommends \
        sqlite3 curl \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-penny-track.ini

WORKDIR /app

# Copy the built application from the composer stage
COPY --from=composer /app /app

# Copy Docker support files
COPY docker/Caddyfile /app/docker/Caddyfile
COPY docker/entrypoint.sh /app/docker/entrypoint.sh
RUN chmod +x /app/docker/entrypoint.sh

# Create var directory for SQLite DB, logs, cache
RUN mkdir -p /app/var

# Environment defaults
ENV APP_ENV=prod \
    FRANKENPHP_WORKER=1 \
    FRANKENPHP_RESET_KERNEL=1 \
    DATABASE_URL="sqlite:///%kernel.project_dir%/var/data.db"

# Volume for SQLite database, logs, and cache
VOLUME /app/var

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -sf http://localhost:80/api/health || exit 1

ENTRYPOINT ["/app/docker/entrypoint.sh"]
