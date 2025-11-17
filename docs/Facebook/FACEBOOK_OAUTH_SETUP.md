# Facebook OAuth Setup Guide

## Overview

Users can now connect their Facebook accounts to their customer accounts from the profile page. This allows Spectra to manage Facebook Ads campaigns on their behalf.

## Setup Instructions

### 1. Create a Facebook App

1. Go to [Facebook Developers](https://developers.facebook.com/)
2. Click "Create App"
3. Choose "Business" as your app type
4. Fill in the app details:
   - App Name: "Spectra Media Agent"
   - App Contact Email: your-email@example.com
   - App Purpose: Select "Manage Business"

### 2. Add Facebook Login Product

1. In your app dashboard, click "Add Product"
2. Find "Facebook Login" and click "Set Up"
3. Choose "Web" as your platform
4. Add your website URL (e.g., `https://yourdomain.com`)

### 3. Configure OAuth Redirect URIs

1. Go to Settings → Basic in your app dashboard
2. Find and note:
   - App ID
   - App Secret

3. Go to Facebook Login → Settings
4. Add your redirect URI under "Valid OAuth Redirect URIs":
   ```
   https://yourdomain.com/auth/facebook/callback
   ```

### 4. Request Necessary Permissions

In your app, request these permissions:
- `ads_management` - Manage ad accounts
- `business_management` - Access business accounts
- `email` - Get user email

These are configured in the controller's `scopes()` call.

### 5. Environment Configuration

Add the following to your `.env` file:

```env
FACEBOOK_APP_ID=your_app_id_here
FACEBOOK_APP_SECRET=your_app_secret_here
FACEBOOK_OAUTH_CALLBACK_URL=https://yourdomain.com/auth/facebook/callback
```

### 6. Verify Configuration

The configuration is already added to `config/services.php`:

```php
'facebook' => [
    'client_id' => env('FACEBOOK_APP_ID'),
    'client_secret' => env('FACEBOOK_APP_SECRET'),
    'redirect' => env('FACEBOOK_OAUTH_CALLBACK_URL'),
],
```

## Routes

The following routes have been added:

```php
// Redirect to Facebook login
GET /auth/facebook/redirect
Route name: facebook.redirect

// Facebook OAuth callback
GET /auth/facebook/callback
Route name: facebook.callback

// Disconnect Facebook account
POST /auth/facebook/disconnect
Route name: facebook.disconnect
```

All routes require authentication (`auth` middleware).

## Frontend Integration

### Profile Page

On the profile page (`/profile`), for each customer account, there's now a "Facebook Ads Integration" section that shows:

**If not connected:**
- "Connect Facebook Account" button
- Clicking takes user through Facebook OAuth flow

**If connected:**
- Green checkmark with connected account ID
- "Disconnect Facebook" button
- Allows user to disconnect the account

### Data Stored

When a user connects their Facebook account, the following is stored:

```php
Customer model:
- facebook_ads_account_id: The Facebook user's ID
- facebook_ads_access_token: The encrypted access token
```

## Flow

1. User visits `/profile`
2. User clicks "Connect Facebook Account" button on a customer account
3. User is redirected to Facebook OAuth login
4. User authorizes Spectra to manage their ads
5. Facebook redirects back to `/auth/facebook/callback`
6. Access token and account ID are encrypted and stored
7. User is redirected back to profile with success message

## Testing

### Development/Sandbox Testing

1. Add test users to your app in Facebook Developer dashboard
2. Use those test accounts to authenticate
3. Test on `http://localhost` (use `FACEBOOK_OAUTH_CALLBACK_URL=http://localhost:8000/auth/facebook/callback` in `.env`)

### Production

1. Submit your app for review (required for production use)
2. Wait for Facebook to approve your app
3. Update environment variables with production credentials
4. Deploy to production

## Security

- **Access tokens are encrypted**: Using Laravel's `Crypt` facade with `APP_KEY`
- **Tokens are customer-scoped**: Each customer can only connect to one Facebook account
- **Session validation**: Only active customers can connect
- **Ownership verification**: Users can only connect to customers they own

## Troubleshooting

### "Invalid OAuth Redirect URI"
- Verify the redirect URI in Facebook app settings matches `FACEBOOK_OAUTH_CALLBACK_URL`
- Don't forget to add `https://` or `http://`

### "Access denied by user"
- User clicked "Cancel" on Facebook login
- They can try again by clicking "Connect Facebook Account" again

### "No active customer in session"
- User must select a customer first
- On profile page, the active customer is set from the session
- Ensure customer is selected before attempting to connect

### "You do not have access to this customer account"
- User is trying to connect an account they don't own
- Verify the active customer belongs to the authenticated user

## Advanced: Retrieving Facebook Ad Accounts

After connecting, you can retrieve the user's ad accounts:

```php
use App\Services\FacebookAds\AdAccountService;

$adAccountService = new AdAccountService($customer);
$accounts = $adAccountService->listAdAccounts();

foreach ($accounts as $account) {
    echo $account['id']; // Ad account ID
    echo $account['name']; // Ad account name
}
```

## Disconnecting

Users can disconnect their Facebook account by clicking "Disconnect Facebook" on the profile page. This:
- Clears the stored access token
- Clears the Facebook ads account ID
- Does NOT delete any campaigns or ads on Facebook

## Additional Resources

- [Facebook Graph API Docs](https://developers.facebook.com/docs/graph-api)
- [Facebook Login Documentation](https://developers.facebook.com/docs/facebook-login)
- [Ads Management API](https://developers.facebook.com/docs/marketing-api)
