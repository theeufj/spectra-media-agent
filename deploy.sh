#!/bin/bash
#
# Deployment Script for cvseeyou Laravel Application to EC2
#
# This script deploys the complete Laravel application (frontend + backend) to production.
# The application runs as a systemd service and is proxied through Nginx.
#
# REQUIREMENTS:
# - rsync installed on local machine
# - SSH access to EC2 instance via PEM key
# - Remote server: Ubuntu 20.04+, PHP 8.1+, Composer, Node.js, PostgreSQL, Nginx, systemd
# - .env file configured at: /var/www/cvseeyou/.env
#
# USAGE:
# 1. Update EC2_HOST with your server IP/hostname
# 2. Make executable: chmod +x deploy.sh
# 3. Run: ./deploy.sh
#

set -e

# --- LOCAL CONFIGURATION ---
LOCAL_PROJECT_ROOT="/Users/joshtheeuf/Documents/Github/MediaAgent"
SOURCE_DIR="spectra/"

# --- REMOTE SERVER CONFIGURATION ---
EC2_HOST="cvseeyou"
REMOTE_PROJECT_DIR="/var/www/cvseeyou"
REMOTE_USER="ubuntu"


# # Configuration
# SERVER_USER="ubuntu"
# SERVER_HOST="13.54.180.117"
# SSH_KEY="~/.ssh/yourfirststore-key"
# PROJECT_NAME="chrome-extension-builder"
# PROJECT_DIR="/home/ubuntu/$PROJECT_NAME"
# APP_PORT="8092"
# DOMAIN="cvseeyou.com"
# WWW_DOMAIN="www.cvseeyou.com"

# --- APPLICATION CONFIGURATION ---
APP_PORT="9999"
APP_NAME="cvseeyou-laravel"
DOMAIN="cvseeyou.com"

echo "🚀 Starting deployment of $APP_NAME to $EC2_HOST..."

# 1. Build frontend assets locally
echo "➡️ Building frontend assets for production..."
cd "$LOCAL_PROJECT_ROOT/$SOURCE_DIR"
npm install
npm run build
cd "$LOCAL_PROJECT_ROOT"

# Create remote directories if they don't exist
echo "➡️ Preparing remote server directories..."
ssh "$EC2_HOST" "sudo mkdir -p $REMOTE_PROJECT_DIR"

echo "➡️ Setting ownership for rsync..."
ssh "$EC2_HOST" "sudo chown ubuntu:ubuntu $REMOTE_PROJECT_DIR"

# 2. Copy files to remote server
echo "➡️ Copying project files to EC2 instance via rsync..."
rsync -avz --no-owner --no-group --delete \
  --exclude ".git" \
  --exclude "node_modules" \
  --exclude "storage/logs/*" \
  --exclude "storage/framework/sessions/*" \
  --exclude "storage/framework/cache/data/*" \
  --exclude "storage/framework/views/*" \
  "$LOCAL_PROJECT_ROOT/$SOURCE_DIR" \
  "$EC2_HOST:$REMOTE_PROJECT_DIR"

echo "➡️ Executing remote deployment commands..."




# 3. Remote deployment via SSH
ssh "$EC2_HOST" <<'REMOTE_EOF'
  set -e
  
  APP_PORT="9999"
  APP_NAME="cvseeyou-laravel"
  REMOTE_PROJECT_DIR="/var/www/cvseeyou"
  REMOTE_USER="www-data"
  
  echo "   📁 Navigating to project directory..."
  cd "$REMOTE_PROJECT_DIR"

  echo "   📦 Installing Composer dependencies..."
  composer install --no-interaction --no-dev --optimize-autoloader

  echo "   🔑 Generating app key if needed..."
  if ! grep -q "APP_KEY=base64" .env; then
    php artisan key:generate
  fi

  echo "   🗄️  Running database migrations..."
  php artisan migrate --force

  echo "   💾 Caching configuration and routes..."
  php artisan config:cache
  php artisan route:cache
  php artisan view:cache

  echo "   🔐 Setting final directory permissions..."
  sudo chown -R www-data:www-data "$REMOTE_PROJECT_DIR"
  sudo chmod -R 775 "$REMOTE_PROJECT_DIR/storage"
  sudo chmod -R 775 "$REMOTE_PROJECT_DIR/bootstrap/cache"

  echo "   ✅ Creating systemd service file..."
  sudo tee /etc/systemd/system/"$APP_NAME".service > /dev/null <<'SYSTEMD_EOF'
[Unit]
Description=cvseeyou Laravel Application
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
echo "   1. Update your nginx configuration to proxy requests to localhost:$APP_PORT"
echo "   2. Update the .env file on the remote server if needed"
echo "   3. Test the application: curl http://localhost:$APP_PORT"
echo ""
echo "🔗 Service Status:"
echo "   - Laravel Service: Running on port $APP_PORT"
echo "   - Domain: $DOMAIN (via nginx proxy)"
echo ""
