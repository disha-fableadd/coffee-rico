#!/bin/bash

# Laravel WhatsApp Bulk Deployment Script
# This script handles post-deployment tasks

set -e

echo "🚀 Starting deployment process..."

# Navigate to project directory
cd /services

# Check PHP version
echo "🐘 Checking PHP version..."
php --version

# Check if .env exists, if not copy from example
if [ ! -f .env ]; then
    echo "📝 Creating .env file from example..."
    cp .env.example .env
fi

# Install/Update Composer dependencies
echo "📦 Installing Composer dependencies..."
composer install --no-dev --optimize-autoloader --no-interaction

# Generate application key if not set
echo "🔑 Generating application key..."
php artisan key:generate --force

# Clear and cache configuration
echo "⚙️ Optimizing application..."
php artisan config:clear
php artisan config:cache
php artisan route:clear
php artisan route:cache
php artisan view:clear
php artisan view:cache

# Run database migrations
echo "🗄️ Running database migrations..."
php artisan migrate --force

# Set proper permissions
echo "🔐 Setting file permissions..."
chmod -R 755 storage
chmod -R 755 bootstrap/cache
chown -R www-data:www-data storage
chown -R www-data:www-data bootstrap/cache

# Install NPM dependencies and build assets (if package-lock.json exists)
if [ -f "package-lock.json" ]; then
    echo "📦 Installing NPM dependencies..."
    npm ci
    
    echo "🏗️ Building frontend assets..."
    npm run build
else
    echo "ℹ️ No package-lock.json found, skipping NPM build process"
fi

# Clear application cache
echo "🧹 Clearing application cache..."
php artisan cache:clear
php artisan queue:restart

echo "✅ Deployment completed successfully!"
echo ""
echo "🌐 Your application should now be accessible at:"
echo "   - Main application: http://your-domain.com"
echo "   - Admin panel: http://your-domain.com/admin (if applicable)"
echo ""
echo "📋 Next steps:"
echo "   1. Configure your web server to point to /services/public"
echo "   2. Set up SSL certificate for HTTPS"
echo "   3. Configure your database connection in .env"
echo "   4. Set up cron jobs for scheduled tasks"
echo "   5. Configure queue workers if using queues"
