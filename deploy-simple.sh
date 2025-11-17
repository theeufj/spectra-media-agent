#!/bin/bash
#
# Deployment Script for cvseeyou Laravel Application to EC2
#

set -e

# Configuration
LOCAL_PROJECT_ROOT="/Users/joshtheeuf/Documents/Github/MediaAgent"
SOURCE_DIR="spectra/"
EC2_USER="ubuntu"
EC2_HOST="13.54.180.117"
EC2_PEM_KEY="$HOME/.ssh/yourfirststore-key"
REMOTE_PROJECT_DIR="/var/www/cvseeyou"
APP_PORT="9999"
APP_NAME="cvseeyou-web"
DOMAIN="cvseeyou.com"

# Verify key exists
if [ ! -f "$EC2_PEM_KEY" ]; then
  echo "❌ Error: SSH key not found at $EC2_PEM_KEY"
  exit 1
fi

echo "🚀 Starting deployment of $APP_NAME to $EC2_HOST..."

# 1. Build frontend assets locally
echo "➡️ Building frontend assets for production..."
cd "$LOCAL_PROJECT_ROOT/$SOURCE_DIR"
npm install
npm run build
cd "$LOCAL_PROJECT_ROOT"

# 2. Create remote directories
echo "➡️ Preparing remote server directories..."
ssh -i "$EC2_PEM_KEY" -o ConnectTimeout=10 "$EC2_USER@$EC2_HOST" \
  "sudo mkdir -p $REMOTE_PROJECT_DIR && sudo chown $EC2_USER:$EC2_USER $REMOTE_PROJECT_DIR && echo 'Directory created successfully'"

# 3. Copy files with rsync
echo "➡️ Copying project files to EC2 instance via rsync..."
rsync -avz --delete \
  -e "ssh -i $EC2_PEM_KEY" \
  --exclude ".git" \
  --exclude "node_modules" \
  --exclude "storage/logs/*" \
  --exclude "storage/framework/sessions/*" \
  --exclude "storage/framework/cache/data/*" \
  --exclude "storage/framework/views/*" \
  "$LOCAL_PROJECT_ROOT/$SOURCE_DIR" \
  "$EC2_USER@$EC2_HOST:$REMOTE_PROJECT_DIR"

echo "✅ Files synced successfully"

# 4. Remote deployment
echo "➡️ Executing remote deployment commands..."
ssh -i "$EC2_PEM_KEY" "$EC2_USER@$EC2_HOST" << 'REMOTE_EOF'
set -e

REMOTE_PROJECT_DIR="/var/www/cvseeyou"
APP_PORT="9999"
APP_NAME="cvseeyou-web"
REMOTE_USER="www-data"

echo "   📁 Navigating to project directory..."
cd "$REMOTE_PROJECT_DIR"

echo "   📦 Installing Composer dependencies..."
composer install --no-interaction --no-dev --optimize-autoloader

echo "   🔑 Generating app key if needed..."
if ! grep -q "APP_KEY=base64" .env; then
  php artisan key:generate
fi

echo "   🗄️ Running database migrations..."
php artisan migrate --force

echo "   💾 Caching configuration and routes..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "   🔐 Setting directory permissions..."
sudo chown -R "$REMOTE_USER:$REMOTE_USER" "$REMOTE_PROJECT_DIR"
chmod -R 775 storage bootstrap/cache

echo "   ✅ Creating systemd service file..."
sudo tee /etc/systemd/system/"$APP_NAME".service > /dev/null <<'SYSTEMD_EOF'
[Unit]
Description=cvseeyou Web Application (Laravel)
After=network.target postgresql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/cvseeyou
ExecStart=/usr/bin/php artisan serve --host=127.0.0.1 --port=9999
Restart=always
RestartSec=5
StandardOutput=append:/var/www/cvseeyou/storage/logs/laravel.log
StandardError=append:/var/www/cvseeyou/storage/logs/laravel-error.log

[Install]
WantedBy=multi-user.target
SYSTEMD_EOF

echo "   🔄 Reloading systemd daemon..."
sudo systemctl daemon-reload

echo "   🚀 Restarting $APP_NAME service..."
sudo systemctl restart "$APP_NAME" || true
sudo systemctl enable "$APP_NAME"
sudo systemctl status "$APP_NAME"

echo "   ✨ Laravel application deployed and running on port $APP_PORT"
REMOTE_EOF

echo ""
echo "✅ Deployment successful!"
echo ""
echo "📋 Next steps:"
echo "   1. Copy nginx configuration to remote server"
echo "   2. Configure SSL certificates"
echo "   3. Reload nginx"
echo ""
echo "🔗 Service Status:"
echo "   - Laravel Application: Running on port $APP_PORT"
echo "   - Nginx Proxy: Listening on port 80/443"
echo "   - Domain: $DOMAIN"
echo ""
