FROM php:8.2-apache

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Install MySQL PDO extension
RUN docker-php-ext-install pdo pdo_mysql

# Set Apache document root to /var/www/html/ledger
ENV APACHE_DOCUMENT_ROOT /var/www/html

# Allow .htaccess overrides
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Copy Ledger into the container
COPY ledger/ /var/www/html/ledger/

# Ensure logs directory is writable
RUN mkdir -p /var/www/html/ledger/logs/er \
    && chown -R www-data:www-data /var/www/html/ledger/logs \
    && chown www-data:www-data /var/www/html/ledger/config.template.php \
    && chmod -R 755 /var/www/html/ledger

# config.php needs to be writable by the installer
RUN touch /var/www/html/ledger/config.php \
    && chown www-data:www-data /var/www/html/ledger/config.php \
    && chmod 644 /var/www/html/ledger/config.php

EXPOSE 80
