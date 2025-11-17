#!/bin/bash
#
# HTTPS Mixed Content Fix Verification Script
# Verifies that the mixed content issue has been resolved
#
# USAGE:
#   ./verify-https-fix.sh            # Run locally against the deployed app
#   ./verify-https-fix.sh <hostname> # Run against a specific host
#

TARGET_URL="${1:-https://cvseeyou.com}"

echo "🔐 HTTPS Mixed Content Verification"
echo "===================================="
echo ""
echo "Target URL: $TARGET_URL"
echo ""

# Function to check for HTTP asset references in HTML
check_html_assets() {
    local url="$1"
    echo "📋 Checking HTML for mixed content references..."
    echo ""
    
    # Fetch the page and look for http:// asset URLs (excluding data: and other schemes)
    response=$(curl -s -H "User-Agent: Mozilla/5.0" "$url")
    
    # Check for common asset patterns with http://
    http_assets=$(echo "$response" | grep -o 'href="http://[^"]*\|src="http://[^"]*\|url(http://[^)]*' | grep -v 'https://' || true)
    
    if [ -z "$http_assets" ]; then
        echo "✅ No HTTP asset references found in HTML"
        echo ""
        return 0
    else
        echo "❌ Found HTTP asset references:"
        echo "$http_assets"
        echo ""
        return 1
    fi
}

# Function to verify Vite assets are HTTPS
check_vite_assets() {
    local url="$1"
    echo "📦 Checking Vite asset URLs..."
    echo ""
    
    response=$(curl -s -H "User-Agent: Mozilla/5.0" "$url")
    
    # Check for script and link tags with src/href containing build assets
    vite_refs=$(echo "$response" | grep -o 'src="[^"]*build[^"]*\|href="[^"]*build[^"]*' || true)
    
    if [ -z "$vite_refs" ]; then
        echo "⚠️  No Vite build assets found in page"
        echo "   This might be expected if Vite is in dev mode"
        echo ""
        return 0
    fi
    
    # Check if all are HTTPS
    http_refs=$(echo "$vite_refs" | grep 'http://' || true)
    
    if [ -z "$http_refs" ]; then
        echo "✅ All Vite assets are served over HTTPS"
        echo ""
        return 0
    else
        echo "❌ Found Vite assets served over HTTP:"
        echo "$http_refs"
        echo ""
        return 1
    fi
}

# Function to check response headers
check_headers() {
    local url="$1"
    echo "📡 Checking response headers..."
    echo ""
    
    headers=$(curl -s -I -H "User-Agent: Mozilla/5.0" "$url")
    
    # Check for security headers
    if echo "$headers" | grep -q "Strict-Transport-Security"; then
        echo "✅ HSTS (Strict-Transport-Security) header present"
    else
        echo "⚠️  HSTS header not found"
    fi
    
    if echo "$headers" | grep -q "X-Content-Type-Options"; then
        echo "✅ X-Content-Type-Options header present"
    else
        echo "⚠️  X-Content-Type-Options header not found"
    fi
    
    echo ""
}

# Function to check environment configuration
check_env_config() {
    echo "🔧 Checking environment configuration..."
    echo ""
    
    echo "Current APP_URL in .env:"
    if [ -f ".env" ]; then
        grep "APP_URL=" .env || echo "⚠️  APP_URL not found in .env"
    else
        echo "⚠️  .env file not found (run this from the Laravel root)"
    fi
    
    echo ""
}

# Run all checks
echo "Running checks..."
echo ""

check_env_config
check_headers "$TARGET_URL"
check_html_assets "$TARGET_URL"
check_vite_assets "$TARGET_URL"

echo "=========================="
echo "✅ Verification complete!"
echo ""
echo "If all checks passed, the mixed content issue should be resolved."
echo "If issues persist:"
echo "  1. Clear browser cache (Cmd+Shift+R on macOS)"
echo "  2. Verify .env has APP_URL=https://cvseeyou.com"
echo "  3. Run: php artisan config:cache"
echo "  4. Restart Laravel service: sudo systemctl restart cvseeyou-laravel"
echo ""
