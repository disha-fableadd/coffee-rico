# Deployment Guide for WhatsApp Bulk Laravel

This guide covers multiple deployment options for your Laravel WhatsApp bulk messaging application.

## 🚀 Deployment Options

### 1. GitHub Actions + FTP Deployment (Recommended)

This is the automated deployment method that triggers when you merge to the main branch.

#### Setup Steps:

1. **Add FTP Password to GitHub Secrets:**
   - Go to your GitHub repository
   - Navigate to Settings → Secrets and variables → Actions
   - Click "New repository secret"
   - Name: `FTP_PASSWORD`
   - Value: `6LOXQ~8yRUY[zK/t`

2. **Deploy:**
   - Push your changes to the `main` branch
   - GitHub Actions will automatically deploy to your FTP server
   - The deployment will be available at: `ftp://72.60.216.54/services`

#### Post-Deployment Steps:

After the GitHub Action completes, SSH into your server and run:

```bash
cd /services
chmod +x scripts/deploy.sh
./scripts/deploy.sh
```

### 2. Docker Deployment

For containerized deployment with Docker.

#### Prerequisites:
- Docker installed
- Docker Compose installed

#### Deploy with Docker:

```bash
# Make scripts executable
chmod +x scripts/docker-deploy.sh

# Run Docker deployment
./scripts/docker-deploy.sh
```

#### Manual Docker Commands:

```bash
# Build and start services
docker-compose up -d --build

# Run deployment commands
docker-compose exec app composer install --no-dev --optimize-autoloader
docker-compose exec app php artisan key:generate --force
docker-compose exec app php artisan migrate --force
docker-compose exec app npm ci && npm run build

# View logs
docker-compose logs -f
```

### 3. Manual FTP Deployment

If you prefer manual deployment:

1. **Upload files via FTP:**
   - Host: `72.60.216.54`
   - Username: `u573967329.devwtpusrweb`
   - Password: `6LOXQ~8yRUY[zK/t`
   - Path: `/services`

2. **Run deployment script:**
   ```bash
   cd /services
   chmod +x scripts/deploy.sh
   ./scripts/deploy.sh
   ```

## 🔧 Configuration

### Environment Variables

Create a `.env` file with the following variables:

```env
APP_NAME="WhatsApp Bulk"
APP_ENV=production
APP_KEY=base64:your-generated-key
APP_DEBUG=false
APP_URL=https://your-domain.com

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=whatsapp_bulk
DB_USERNAME=your_db_user
DB_PASSWORD=your_db_password

# WhatsApp API Configuration
WHATSAPP_API_URL=your_whatsapp_api_url
WHATSAPP_ACCESS_TOKEN=your_access_token
WHATSAPP_PHONE_NUMBER_ID=your_phone_number_id
```

### Database Setup

1. **Create database:**
   ```sql
   CREATE DATABASE whatsapp_bulk;
   ```

2. **Run migrations:**
   ```bash
   php artisan migrate
   ```

3. **Seed initial data (optional):**
   ```bash
   php artisan db:seed
   ```

## 🌐 Web Server Configuration

### Apache Configuration

Create a virtual host pointing to `/services/public`:

```apache
<VirtualHost *:80>
    ServerName your-domain.com
    DocumentRoot /services/public
    
    <Directory /services/public>
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog ${APACHE_LOG_DIR}/whatsapp-bulk-error.log
    CustomLog ${APACHE_LOG_DIR}/whatsapp-bulk-access.log combined
</VirtualHost>
```

### Nginx Configuration

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /services/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

## 📋 Post-Deployment Checklist

- [ ] Environment variables configured
- [ ] Database connection working
- [ ] File permissions set correctly
- [ ] SSL certificate installed (for production)
- [ ] Cron jobs configured for scheduled tasks
- [ ] Queue workers running (if using queues)
- [ ] Backup strategy implemented
- [ ] Monitoring and logging configured

## 🔍 Troubleshooting

### Common Issues:

1. **Permission Errors:**
   ```bash
   chmod -R 755 storage bootstrap/cache
   chown -R www-data:www-data storage bootstrap/cache
   ```

2. **Composer Issues:**
   ```bash
   composer install --no-dev --optimize-autoloader
   ```

3. **Cache Issues:**
   ```bash
   php artisan config:clear
   php artisan cache:clear
   php artisan view:clear
   ```

4. **Database Connection:**
   - Check database credentials in `.env`
   - Ensure database server is running
   - Verify database exists

## 📞 Support

For deployment issues, check:
- Application logs: `storage/logs/laravel.log`
- Web server logs
- GitHub Actions logs (for automated deployment)
- Docker logs: `docker-compose logs -f`
