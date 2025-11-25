# Facebook Login for Business Setup Guide

This guide walks you through setting up **Facebook Login for Business** with Laravel Socialite to allow users to authenticate with Facebook and grant permissions for publishing ads across the Meta network.

## Overview

**Facebook Login for Business** is specifically designed for business use cases and provides:

1. **User Authentication**: Allow users to sign up/login with their Facebook account
2. **Business Assets Access**: Access to ad accounts, pages, and business manager
3. **Ad Publishing Permissions**: Obtain long-lived access tokens to manage and publish ads on behalf of the user
4. **Configuration-Based Login**: Use Configuration ID for centralized permission management

## Prerequisites

- Laravel application with Socialite installed
- Facebook Developer account
- Meta Business Manager account (for production)
- Access to Facebook App Dashboard

## Step 1: Create a Facebook App (Business Type)

1. Go to [Facebook Developers](https://developers.facebook.com/)
2. Click **"My Apps"** in the top right
3. Click **"Create App"**
4. Select **"Business"** as the app type (required for ads management)
5. Fill in:
   - **App Name**: Your application name (e.g., "Spectra Media Agent")
   - **App Contact Email**: Your email address
6. Click **"Create App"**

## Step 2: Add Facebook Login for Business

1. In your Facebook App Dashboard, go to **"Add Products"**
2. Find **"Facebook Login for Business"** (NOT regular "Facebook Login")
3. Click **"Set Up"**
4. Select **"Web"** as the platform
5. Enter your **Site URL**: `http://localhost:8000` (for development)
6. Click **"Save"** and **"Continue"**

**Important**: Make sure you select "Facebook Login for Business" - this is different from the standard Facebook Login product and provides business-specific features.

## Step 3: Configure OAuth Settings

1. In the left sidebar, find **"Use cases"** or **"Facebook Login"**
2. Click on **"Settings"** under Facebook Login
3. Configure the following:

### Valid OAuth Redirect URIs
Add these URLs (adjust domain for production):
```
http://localhost:8000/auth/facebook/callback
https://yourdomain.com/auth/facebook/callback
```

### Client OAuth Settings
- **Client OAuth Login**: `ON`
- **Web OAuth Login**: `ON`

### Login from Devices
- Can be left `OFF` for web-only authentication

4. Click **"Save Changes"**

## Step 4: Configure App Domains

1. Go to **"Settings"** → **"Basic"** in the left sidebar
2. Scroll to **"App Domains"**
3. Add:
   ```
   localhost
   yourdomain.com
   ```
4. Click **"Save Changes"**

## Step 5: Get App Credentials

1. In **"Settings"** → **"Basic"**
2. Copy your:
   - **App ID** (e.g., `1618936546186126`)
   - **App Secret** (click "Show" to reveal)

## Step 6: Configure Permissions for Ads Management

To publish ads on the Meta network, you need specific permissions:

1. Go to **"App Review"** → **"Permissions and Features"**
2. Request the following permissions:
   - `ads_management` - Create and manage ads
   - `ads_read` - Read ad account information
   - `business_management` - Access Business Manager
   - `pages_show_list` - Show list of Pages
   - `pages_read_engagement` - Read Page engagement data

3. For each permission:
   - Click **"Request Advanced Access"**
   - Fill in the usage details
   - Submit for review (Note: Some permissions require app review)

### Development Mode Permissions
While in Development Mode, you can test with:
- `email` - Already granted
- `public_profile` - Already granted

For ads testing, add test users or admins to your app.

## Step 7: Configure Laravel Environment

Add the following to your `.env` file:

```env
FACEBOOK_APP_ID=your_app_id_here
FACEBOOK_APP_SECRET=your_app_secret_here
FACEBOOK_CONFIG_ID=your_config_id_here
```

**Important**: 
- `FACEBOOK_CONFIG_ID` is **required** for Facebook Login for Business
- This ID tells Facebook which permission configuration to use
- You can find it in Facebook Login for Business → Settings → Configuration Settings

## Step 8: Update Services Configuration

Your `config/services.php` should already be configured:

```php
'facebook' => [
    'client_id' => env('FACEBOOK_APP_ID'),
    'client_secret' => env('FACEBOOK_APP_SECRET'),
    'redirect' => env('APP_URL') . '/auth/facebook/callback',
    'config_id' => env('FACEBOOK_CONFIG_ID'),
],
```

## Step 9: Enable Facebook Platform in Admin

1. Login to your application as an admin
2. Navigate to **Admin** → **Platforms**
3. Find or create **Facebook** platform:
   - **Name**: Facebook
   - **Slug**: `facebook`
   - **Status**: Enabled
4. Click **"Save"**

## Step 10: Test the Integration

### For Development Testing:

1. Go to your login page: `http://localhost:8000/login`
2. You should see a **"Sign in with Facebook"** button
3. Click the button to test the OAuth flow
4. If successful, you'll be redirected back and logged in

### Troubleshooting:

**Issue**: "URL blocked" error
- **Solution**: Double-check that your redirect URI is exactly whitelisted in Facebook App settings

**Issue**: "App Not Set Up" error
- **Solution**: Make sure "Client OAuth Login" and "Web OAuth Login" are enabled in Facebook Login settings

**Issue**: No Facebook button appears
- **Solution**: Check that Facebook platform is enabled in Admin → Platforms and the slug is `facebook`

## Step 11: Production Setup

Before going to production:

1. **Switch App Mode**:
   - Go to **"Settings"** → **"Basic"**
   - Toggle the app from **Development** to **Live**

2. **Update Redirect URIs**:
   - Replace localhost URLs with your production domain
   - Example: `https://yourdomain.com/auth/facebook/callback`

3. **Update App Domains**:
   - Remove `localhost`
   - Add your production domain

4. **Complete App Review**:
   - Submit for review if using advanced permissions (ads_management, etc.)
   - Provide detailed use cases and video demonstrations as required

5. **Update Environment Variables**:
   - Set production `APP_URL` in `.env`
   - Verify Facebook credentials are correct

## Permission Scopes Explained

### Current Implementation (Authentication)
- `email` - User's email address
- `public_profile` - User's name and profile picture

### For Ads Management (Future Enhancement)
To fully manage ads, you'll need to request additional scopes in the redirect:

```php
return Socialite::driver('facebook')
    ->scopes([
        'email',
        'public_profile',
        'ads_management',
        'ads_read',
        'business_management',
        'pages_show_list',
        'pages_read_engagement'
    ])
    ->redirect();
```

**Note**: Advanced scopes require Facebook App Review approval.

## Architecture Overview

### Authentication Flow
1. User clicks "Sign in with Facebook" on login page
2. User is redirected to Facebook OAuth (`/auth/facebook/redirect`)
3. User grants permissions on Facebook
4. Facebook redirects back to callback (`/auth/facebook/callback`)
5. Application creates/updates user account
6. Access token is stored for API access
7. User is logged in and redirected to dashboard

### Route Structure
- **Authentication Routes** (guest access):
  - `GET /auth/facebook/redirect` - Initiates OAuth flow
  - `GET /auth/facebook/callback` - Handles OAuth callback

- **Ads Management Routes** (authenticated access):
  - `GET /auth/facebook-ads/redirect` - Connect Facebook Ads account
  - `GET /auth/facebook-ads/callback` - Handle Ads connection callback
  - `POST /auth/facebook-ads/disconnect` - Disconnect Facebook Ads

### Controllers
- `App\Http\Controllers\Auth\FacebookController` - User authentication
- `App\Http\Controllers\FacebookOAuthController` - Ads account management

## Security Considerations

1. **Token Storage**: Access tokens are stored encrypted in the database
2. **State Parameter**: Laravel Socialite automatically handles CSRF protection via state parameter
3. **HTTPS**: Always use HTTPS in production for OAuth flows
4. **Token Refresh**: Facebook access tokens expire - implement token refresh logic for long-term access
5. **Scope Limitation**: Only request permissions you actually need

## Next Steps

After completing this setup:
1. Test with multiple users (add testers in Facebook App Dashboard)
2. Implement token refresh logic for long-lived access
3. Submit for App Review if using advanced permissions
4. Monitor OAuth errors in application logs
5. Implement graceful fallbacks if Facebook login fails

## Resources

- [Facebook Login Documentation](https://developers.facebook.com/docs/facebook-login)
- [Laravel Socialite Documentation](https://laravel.com/docs/socialite)
- [Facebook Marketing API](https://developers.facebook.com/docs/marketing-apis)
- [Facebook App Review Process](https://developers.facebook.com/docs/app-review)
