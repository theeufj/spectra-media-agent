# Facebook OAuth Integration - Implementation Summary

## Changes Made

### 1. Configuration
- **File**: `config/services.php`
- **Changes**: Added Facebook OAuth configuration with environment variables
```php
'facebook' => [
    'client_id' => env('FACEBOOK_APP_ID'),
    'client_secret' => env('FACEBOOK_APP_SECRET'),
    'redirect' => env('FACEBOOK_OAUTH_CALLBACK_URL'),
]
```

### 2. Controller
- **File**: `app/Http/Controllers/FacebookOAuthController.php`
- **New Controller** with three methods:
  - `redirect()` - Initiates Facebook OAuth flow
  - `callback()` - Handles OAuth callback, stores encrypted token
  - `disconnect()` - Removes Facebook credentials from customer

### 3. Routes
- **File**: `routes/auth.php`
- **Added Routes**:
  - `GET /auth/facebook/redirect` → `facebook.redirect`
  - `GET /auth/facebook/callback` → `facebook.callback`
  - `POST /auth/facebook/disconnect` → `facebook.disconnect`

### 4. Frontend
- **File**: `resources/js/Pages/Profile/Edit.jsx`
- **Changes**:
  - Imported `DangerButton` component
  - Added "Facebook Ads Integration" section to each customer account
  - Shows connection status and buttons to connect/disconnect
  - Uses Inertia router for form submissions

### 5. Database Schema
- **No new migrations needed** - using existing `facebook_ads_account_id` and `facebook_ads_access_token` columns

## User Flow

### Connecting Facebook

1. User visits `/profile`
2. User selects a customer account (sets active customer)
3. Finds "Facebook Ads Integration" section in customer details
4. Clicks "Connect Facebook Account" button
5. Redirected to `facebook.redirect` route
6. Redirected to Facebook OAuth login
7. User authenticates and grants permissions
8. Facebook redirects to `facebook.callback`
9. Controller stores encrypted token on customer record
10. User redirected back to profile with success message

### Disconnecting Facebook

1. User clicks "Disconnect Facebook" button
2. Form submits to `facebook.disconnect`
3. Controller clears credentials
4. User redirected back to profile with success message

## Environment Setup

Add these to your `.env`:

```env
FACEBOOK_APP_ID=your_app_id
FACEBOOK_APP_SECRET=your_app_secret
FACEBOOK_OAUTH_CALLBACK_URL=https://yourdomain.com/auth/facebook/callback
```

## Security Features

✅ **Access Tokens Encrypted**
- Uses Laravel `Crypt` facade with `APP_KEY`
- Tokens are unreadable in database

✅ **Session Validation**
- Requires active customer in session
- Only connected customers can authenticate

✅ **Ownership Verification**
- User must own the customer account
- Prevents unauthorized account connections

✅ **Secure Scoping**
- Only requests necessary permissions
- Uses `ads_management`, `business_management`, `email`

## Integration with Facebook Services

The stored credentials work with existing Facebook Ads services:

```php
// Use the access token with Facebook services
use App\Services\FacebookAds\AdAccountService;

$adAccountService = new AdAccountService($customer);
$accounts = $adAccountService->listAdAccounts();

// Create campaigns
use App\Services\FacebookAds\CampaignService;

$campaignService = new CampaignService($customer);
$campaign = $campaignService->createCampaign(
    $accountId,
    'Campaign Name',
    'LINK_CLICKS',
    50000
);
```

## Testing

### Local Testing

```env
FACEBOOK_APP_ID=local_test_app_id
FACEBOOK_APP_SECRET=local_test_app_secret
FACEBOOK_OAUTH_CALLBACK_URL=http://localhost:8000/auth/facebook/callback
```

Run locally with test app, add yourself as test user in Facebook Developer dashboard.

### Production

1. Submit app for review in Facebook Developer dashboard
2. Wait for approval
3. Update environment variables
4. Deploy

## Files Changed/Created

✅ **Created:**
- `app/Http/Controllers/FacebookOAuthController.php`
- `docs/FACEBOOK_OAUTH_SETUP.md`

✅ **Modified:**
- `config/services.php`
- `routes/auth.php`
- `resources/js/Pages/Profile/Edit.jsx`

## Next Steps

1. **Get Facebook App ID and Secret**
   - Go to [Facebook Developers](https://developers.facebook.com/)
   - Create app with type "Business"
   - Add Facebook Login product
   - Configure redirect URI

2. **Update `.env` with credentials**
   ```bash
   FACEBOOK_APP_ID=xxxxx
   FACEBOOK_APP_SECRET=xxxxx
   FACEBOOK_OAUTH_CALLBACK_URL=https://yourdomain.com/auth/facebook/callback
   ```

3. **Test the flow**
   - Visit profile page
   - Click "Connect Facebook Account"
   - Authenticate with test account
   - Verify token is stored

4. **Use with Facebook services**
   - Create campaigns
   - Manage ad accounts
   - Track performance

## Error Handling

All errors are gracefully handled:

| Scenario | Behavior |
|----------|----------|
| No active customer | Error message, redirect to profile |
| User doesn't own customer | Error message, redirect to profile |
| OAuth fails | Error message, redirect to profile |
| Disconnection fails | Error message, stays on profile |

All errors are logged to `storage/logs/laravel.log`

## Scopes Requested

- `ads_management` - Required to manage ads
- `business_management` - Required to access business accounts
- `email` - Needed for user identification

## References

- [Facebook OAuth Documentation](https://developers.facebook.com/docs/facebook-login)
- [Facebook Ads API](https://developers.facebook.com/docs/marketing-api)
- [Laravel Socialite](https://laravel.com/docs/socialite)
