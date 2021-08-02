FROM php:8.0-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libzip-dev \
    zip \
    unzip

# Clear cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install sockets

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Create system user to run Composer and Artisan Commands
RUN useradd -G www-data,root -d /home/ubuntu ubuntu
RUN mkdir -p /home/ubuntu/.composer && \
    chown -R ubuntu:ubuntu /home/ubuntu

# Set working directory
WORKDIR /var/www

USER ubuntu
