# Deployment Guide for cvseeyou.com

## Architecture Overview

```
                          Internet
                            |
                          Nginx (Port 443/80)
                          cvseeyou.com
                            |
                        Port 8000
                    Laravel Application
                  (Complete Backend)
```

## Pre-Deployment Checklist

- [ ] EC2 instance running Ubuntu 20.04+ with public IP
- [ ] SSH access configured with `yourvideoagent.pem` key
- [ ] PostgreSQL 12+ installed and running
- [ ] PHP 8.1+ and Composer installed
- [ ] Node.js 16+ and npm installed
- [ ] Nginx installed
- [ ] Domain `cvseeyou.com` pointing to EC2 instance
- [ ] SSL certificate (Let's Encrypt or other)
- [ ] `.env` file prepared for Laravel

## Step 1: Prepare the Remote Server

SSH into your EC2 instance:
```bash
ssh -i yourvideoagent.pem ubuntu@your-ec2-ip
```

Create directories:
```bash
sudo mkdir -p /var/www/cvseeyou
sudo mkdir -p /var/log/nginx
sudo chown -R ubuntu:ubuntu /var/www/cvseeyou
```

Create `.env` file for Laravel:
```bash
cat > /var/www/cvseeyou/.env <<EOF
APP_NAME=cvseeyou
APP_ENV=production
APP_KEY=base64:YOUR_APP_KEY_HERE  # Will be generated during deploy
APP_DEBUG=false
APP_URL=https://cvseeyou.com

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=cvseeyou
DB_USERNAME=postgres
DB_PASSWORD=your-secure-password

CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis

MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=465
MAIL_USERNAME=your-email@example.com
MAIL_PASSWORD=your-password
MAIL_FROM_ADDRESS=noreply@cvseeyou.com
MAIL_FROM_NAME="cvseeyou"
EOF
```

Ensure PostgreSQL database exists:
```bash
sudo -u postgres createdb cvseeyou
```

## Step 2: Update Local Deploy Script

Edit `deploy.sh` and update these variables:
```bash
EC2_USER="ubuntu"
EC2_HOST="your-ec2-ip-or-hostname"  # Replace with your EC2 public IP
EC2_PEM_KEY="$(pwd)/yourvideoagent.pem"
```

## Step 3: Run the Deployment

From the project root directory:
```bash
cd /Users/joshtheeuf/Documents/Github/MediaAgent
chmod +x spectra/deploy.sh
./spectra/deploy.sh
```

The script will:
1. Build frontend assets locally
2. Copy files to remote server via rsync
3. Install Composer dependencies
4. Run database migrations
5. Cache configuration
6. Create systemd service
7. Start the Laravel service on port 8000

## Step 4: Configure Nginx

Copy the nginx configuration:
```bash
sudo cp spectra/nginx-cvseeyou.conf /etc/nginx/sites-available/cvseeyou
sudo ln -s /etc/nginx/sites-available/cvseeyou /etc/nginx/sites-enabled/
```

Update paths in the nginx config if needed, then test:
```bash
sudo nginx -t
```

If successful, reload nginx:
```bash
sudo systemctl reload nginx
```

## Step 5: Setup SSL Certificate (Let's Encrypt)

```bash
sudo apt-get install certbot python3-certbot-nginx -y
sudo certbot certonly --nginx -d cvseeyou.com -d www.cvseeyou.com
```

Update the nginx config with certificate paths, then reload:
```bash
sudo systemctl reload nginx
```

## Step 6: Verify Services

Check Laravel service:

```bash
sudo systemctl status cvseeyou
```

Check nginx:

```bash
sudo systemctl status nginx
```

Test connectivity:

```bash
curl http://localhost:8000/login
```

## Step 7: Monitor Logs

Laravel logs:
```bash
sudo tail -f /var/www/cvseeyou/storage/logs/laravel.log
```

Nginx access logs:
```bash
sudo tail -f /var/log/nginx/cvseeyou_access.log
```

Nginx error logs:
```bash
sudo tail -f /var/log/nginx/cvseeyou_error.log
```

## Troubleshooting

### Laravel service won't start

```bash
sudo journalctl -u cvseeyou -n 50
```

### 502 Bad Gateway

- Check if Laravel is running: `sudo systemctl status cvseeyou`
- Check if port 8000 is listening: `sudo lsof -i :8000`
- Check nginx logs for details

### Database connection issues

- Verify PostgreSQL is running: `sudo systemctl status postgresql`
- Check .env database credentials
- Verify database exists: `sudo -u postgres psql -l`

### Permission denied errors

```bash
sudo chown -R www-data:www-data /var/www/cvseeyou
sudo chmod -R 775 /var/www/cvseeyou/storage
sudo chmod -R 775 /var/www/cvseeyou/bootstrap/cache
```

## Rollback (if needed)

Keep a backup of the previous version:
```bash
sudo mv /var/www/cvseeyou /var/www/cvseeyou-backup
```

Then run deployment again to deploy the latest version.

## Ongoing Maintenance

### Update Laravel Application

```bash
./spectra/deploy.sh
```

### View Service Status

```bash
sudo systemctl status cvseeyou
```

### Restart Service

```bash
sudo systemctl restart cvseeyou
```

### Run Migrations Manually

```bash
ssh ubuntu@your-ec2-ip
cd /var/www/cvseeyou
php artisan migrate
```

### Clear Application Cache

```bash
ssh ubuntu@your-ec2-ip
cd /var/www/cvseeyou
php artisan cache:clear
php artisan config:clear
php artisan view:clear
```

## Architecture Notes

- **Laravel Application (Port 8000)**: Serves marketing pages, dashboard, admin interface, and handles all application logic
- **Nginx (Port 80/443)**: Reverse proxy that routes all requests to the Laravel application
  - `/` (root) → Laravel (marketing site)
  - `/login`, `/register`, `/dashboard`, `/campaigns`, etc. → Laravel
  - All routes are handled by a single Laravel application

## Security Reminders

- [ ] Keep .env file secure (not in git)
- [ ] Use strong database passwords
- [ ] Enable automatic security updates: `sudo apt-get install unattended-upgrades`
- [ ] Consider using AWS Security Groups to restrict access
- [ ] Monitor application logs for suspicious activity
- [ ] Regularly backup PostgreSQL database
- [ ] Keep PHP, Composer, and packages updated
