# Use the official PHP image with Apache
FROM php:8.2-apache

# Install mysqli extension for MySQL
RUN docker-php-ext-install mysqli && docker-php-ext-enable mysqli

# Copy your PHP files into the container
COPY . /var/www/html/

# Enable Apache's mod_rewrite (optional, for cleaner URLs)
RUN a2enmod rewrite

# Expose port 80
EXPOSE 80