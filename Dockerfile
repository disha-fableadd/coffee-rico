FROM php:8.2-apache

# Install required packages
RUN apt-get update && \
    apt-get install -y unzip curl git zip && \
    docker-php-ext-install mysqli

# Set working directory
WORKDIR /var/www/html

# Copy project files
COPY . .

# Set ownership (optional)
RUN chown -R www-data:www-data /var/www/html

# Expose Apache port
EXPOSE 80

