#!/bin/bash

# Docker Deployment Script for Laravel WhatsApp Bulk
# This script handles Docker-based deployment

set -e

echo "🐳 Starting Docker deployment process..."

# Check if Docker is installed
if ! command -v docker &> /dev/null; then
    echo "❌ Docker is not installed. Please install Docker first."
    exit 1
fi

# Check if Docker Compose is installed
if ! command -v docker-compose &> /dev/null; then
    echo "❌ Docker Compose is not installed. Please install Docker Compose first."
    exit 1
fi

# Stop existing containers
echo "🛑 Stopping existing containers..."
docker-compose down

# Build and start services
echo "🏗️ Building and starting services..."
docker-compose up -d --build

# Wait for services to be ready
echo "⏳ Waiting for services to be ready..."
sleep 30

# Run deployment commands inside the app container
echo "🚀 Running deployment commands..."
docker-compose exec app bash -c "
    # Install Composer dependencies
    composer install --no-dev --optimize-autoloader --no-interaction
    
    # Generate application key
    php artisan key:generate --force
    
    # Cache configuration
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    
    # Run migrations
    php artisan migrate --force
    
    # Set permissions
    chmod -R 755 storage bootstrap/cache
    chown -R www-data:www-data storage bootstrap/cache
"

# Install NPM dependencies and build assets
echo "📦 Installing NPM dependencies and building assets..."
docker-compose exec app bash -c "
    npm ci
    npm run build
"

# Show running containers
echo "📋 Running containers:"
docker-compose ps

echo "✅ Docker deployment completed successfully!"
echo ""
echo "🌐 Your application is now accessible at:"
echo "   - Main application: http://localhost:8080"
echo "   - phpMyAdmin: http://localhost:8081"
echo ""
echo "📋 Useful commands:"
echo "   - View logs: docker-compose logs -f"
echo "   - Stop services: docker-compose down"
echo "   - Restart services: docker-compose restart"
echo "   - Access app container: docker-compose exec app bash"
