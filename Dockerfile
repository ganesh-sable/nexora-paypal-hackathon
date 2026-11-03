# Nexora AI E-Commerce Platform - Production Dockerfile
FROM php:8.2-apache

# Install PostgreSQL client development libraries and necessary system dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev \
    curl \
    ca-certificates \
    && docker-php-ext-install -j$(nproc) pdo pdo_pgsql pgsql opcache \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache rewrite and headers modules
RUN a2enmod rewrite headers

# Configure production PHP runtime settings
RUN { \
    echo 'opcache.enable=1'; \
    echo 'opcache.memory_consumption=128'; \
    echo 'opcache.interned_strings_buffer=8'; \
    echo 'opcache.max_accelerated_files=4000'; \
    echo 'opcache.revalidate_freq=2'; \
    echo 'opcache.fast_shutdown=1'; \
    echo 'memory_limit=256M'; \
    echo 'upload_max_filesize=16M'; \
    echo 'post_max_size=16M'; \
    echo 'expose_php=Off'; \
    echo 'date.timezone=UTC'; \
} > /usr/local/etc/php/conf.d/nexora-production.ini

# Configure DocumentRoot permissions for Apache
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Set working directory
WORKDIR /var/www/html

# Copy application source code
COPY . /var/www/html/

# Copy entrypoint script and ensure executable permissions
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh \
    && chown -R www-data:www-data /var/www/html

# Render sets PORT environment variable (defaults to 10000)
ENV PORT=10000
EXPOSE 10000

# Run entrypoint to configure Apache port dynamically at container startup
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]

# Launch Apache
CMD ["apache2-foreground"]
