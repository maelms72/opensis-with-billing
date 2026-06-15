FROM php:8.2-apache

# Install PHP extensions needed by openSIS
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libxml2-dev \
    libonig-dev \
    default-mysql-client \
    unzip \
    curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        pdo \
        pdo_mysql \
        mysqli \
        gd \
        zip \
        xml \
        mbstring \
        opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Disable mpm_event; enable mpm_prefork (required for mod_php)
RUN a2dismod mpm_event && a2enmod mpm_prefork

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# PHP config: increase limits for file uploads and execution time
RUN echo "upload_max_filesize = 20M\n\
post_max_size = 20M\n\
memory_limit = 256M\n\
max_execution_time = 60\n\
date.timezone = UTC" > /usr/local/etc/php/conf.d/opensis.ini

# Apache config: allow .htaccess overrides
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Copy application files
COPY . /var/www/html/

# Fix permissions
RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \;

# Make writable dirs writable (openSIS writes to these)
RUN chmod -R 775 /var/www/html/assets \
    && chmod -R 775 /var/www/html/lang 2>/dev/null || true

# Copy and set up entrypoint
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Railway injects $PORT — Apache must listen on it
RUN echo 'ServerName localhost' >> /etc/apache2/apache2.conf

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
