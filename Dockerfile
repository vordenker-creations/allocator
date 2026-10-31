FROM php:8.4-apache

# Install system dependencies & PHP extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
    unzip \
    git \
    && docker-php-ext-install pdo pdo_mysql opcache \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Set timezone to Asia/Ho_Chi_Minh
RUN printf "date.timezone = Asia/Ho_Chi_Minh\n" > /usr/local/etc/php/conf.d/timezone.ini

# Configure Apache DocumentRoot to /var/www/html/public
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
