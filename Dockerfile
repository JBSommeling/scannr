FROM php:8.4-cli

# System dependencies for PHP extensions, Chromium, and Node.js
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    curl \
    unzip \
    libxml2-dev \
    libzip-dev \
    chromium \
    fonts-liberation \
    libappindicator3-1 \
    libasound2 \
    libatk-bridge2.0-0 \
    libatk1.0-0 \
    libcups2 \
    libdbus-1-3 \
    libdrm2 \
    libgbm1 \
    libgtk-3-0 \
    libnspr4 \
    libnss3 \
    libx11-xcb1 \
    libxcomposite1 \
    libxdamage1 \
    libxrandr2 \
    xdg-utils \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions
RUN docker-php-ext-install xml simplexml pcntl

# Install Node.js 20
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Copy project files
COPY composer.json composer.lock ./
COPY src/ src/
COPY config/ config/
COPY database/ database/
COPY package.json package-lock.json ./
COPY .env.action ./
COPY artisan ./
COPY bootstrap/ bootstrap/
COPY storage/ storage/
COPY entrypoint.sh /entrypoint.sh

# Install Composer dependencies (no dev, optimized)
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# Install Puppeteer with system Chromium (skip bundled download)
ENV PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true
ENV PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium
RUN npm ci --omit=dev

# Ensure Laravel storage and cache directories are writable
RUN mkdir -p storage/logs \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    bootstrap/cache \
    && chmod -R 777 storage bootstrap/cache

# Set up minimal Laravel environment
RUN cp .env.action .env \
    && php artisan key:generate --no-interaction

# Tell Browsershot where to find Chromium, Node, and modules
ENV CHROME_PATH=/usr/bin/chromium
ENV SCANNR_NODE_BINARY=/usr/bin/node
ENV SCANNR_NPM_BINARY=/usr/bin/npm
ENV SCANNR_NODE_MODULES_PATH=/app/node_modules

RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
