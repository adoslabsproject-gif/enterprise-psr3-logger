# Log Worker - Redis to File Writer
#
# Reads log entries from Redis queue and writes them to files.
# Lightweight container optimized for low resource usage.
#
# BUILD:
#   docker build -t log-worker -f docker/log-worker/Dockerfile .
#
# RUN:
#   docker run -e REDIS_HOST=redis -v /var/log/app:/var/log/app log-worker
#
# COMPOSE:
#   See docker/log-worker/docker-compose.yml

FROM php:8.3-cli-alpine

# Install Redis extension
RUN apk add --no-cache \
    $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

# Install pcntl for signal handling
RUN docker-php-ext-install pcntl

# Create app directory
WORKDIR /app

# Copy only the worker script (minimal image)
COPY bin/log-worker.php /app/log-worker.php

# Create log directory
RUN mkdir -p /var/log/app && chmod 777 /var/log/app

# Environment defaults
ENV REDIS_HOST=127.0.0.1 \
    REDIS_PORT=6379 \
    REDIS_DATABASE=0 \
    LOG_APP_NAME=app \
    LOG_PATH=/var/log/app \
    LOG_BATCH_SIZE=100 \
    LOG_FLUSH_INTERVAL=5 \
    LOG_WORKER_VERBOSE=false

# Health check
HEALTHCHECK --interval=30s --timeout=5s --start-period=5s --retries=3 \
    CMD php -r "exit(file_exists('/app/log-worker.php') ? 0 : 1);"

# Run worker
CMD ["php", "/app/log-worker.php"]
