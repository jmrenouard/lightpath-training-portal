FROM php:8.3-cli-alpine3.21

# Install runtime libraries and build PHP extensions not bundled in the base image (mbstring and pdo_sqlite are built-in)
RUN apk add --no-cache \
        libzip \
        libpq \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        libzip-dev \
        libpq-dev \
    && docker-php-ext-install -j"$(nproc)" zip pdo_pgsql \
    && apk del --no-network .build-deps

# Create non-root runtime user and prepare writable storage paths
RUN addgroup -S lms \
    && adduser -S -G lms lms

WORKDIR /app

COPY . .

RUN mkdir -p /app/storage/data /app/storage/cache /app/storage/tmp /app/storage/courses \
    && chown -R lms:lms /app/storage

USER lms

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=5s \
    CMD wget -qO- "http://127.0.0.1:8080/index.php?page=verify" >/dev/null || exit 1

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
