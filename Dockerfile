FROM php:8.2-apache

# Install system dependencies and PHP extensions
RUN apt-get update && apt-get upgrade -y \
    libpng-dev \
    libonig-dev \
    libxml2-dev \ 
    zip \
    unzip \
    git \
    curl 

RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd 

# Get composer 
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer 

# Set working directory 
WORKDIR /var/www/html 

# Copy project files 
COPY . .

# Install dependencies 
RUN composer install --no-dev --optimize-autoloader 

# Set permissions for Laravel storage and cache 
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache 

# Configure Apache document root to point to Laravel's public folder 
ENV APACHE_DOCUMENT_ROOT /var/www/html/public 
RUN sed -ri -s 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf 
RUN sed -ri -s 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf 

# Enable Apache Mod rewrite 
RUN a2enmod rewrite 

EXPOSE 80

