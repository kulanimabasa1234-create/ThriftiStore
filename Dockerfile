FROM php:8.2-apache

# Install PostgreSQL PDO extension
RUN apt-get update && apt-get install -y libpq-dev && docker-php-ext-install pdo_pgsql

# Enable Apache mod_rewrite (optional)
RUN a2enmod rewrite

# Copy all files to the web root
COPY . /var/www/html/

# Set the document root to /var/www/html (default)
EXPOSE 80
