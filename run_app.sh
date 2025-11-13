#!/bin/bash

# This script sets up and runs the Laravel application.

cd /Users/joshtheeuf/Documents/Github/MediaAgent/spectra

# 1. Copy .env.example to .env if it doesn't exist
if [ ! -f .env ]; then
    cp .env.example .env
    echo ".env file created. Please configure your database and other environment variables in the .env file."
fi

# 2. Install PHP dependencies
echo "Installing PHP dependencies..."
composer install

# 3. Generate application key
echo "Generating application key..."
php artisan key:generate

# 4. Run database migrations
echo "Running database migrations..."
php artisan migrate

# 5. Install Node.js dependencies
echo "Installing Node.js dependencies..."
npm install

# 6. Build frontend assets
echo "Building frontend assets..."
npm run build

# 7. Start the Laravel development server
echo "Starting Laravel development server..."
php artisan serve &

# 8. Start the Laravel queue worker
echo "Starting Laravel queue worker..."
php artisan queue:work
