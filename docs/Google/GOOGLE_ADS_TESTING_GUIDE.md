# Google Ads Testing Guide

This guide covers how to test your Google Ads MCC (Manager Customer Center) integration, including creating sub-accounts and publishing campaigns.

## Prerequisites

Before testing, ensure you have:

1. **Google Ads API credentials** configured in `storage/app/google_ads_php.ini`
2. **Service account credentials** at `storage/app/secrets/google-ads-api-credentials.json`
3. **MCC Customer ID** configured in `config/googleads.php`
4. A valid **developer token** (test or production)

---

## Available Artisan Commands

### 1. Test MCC Connection

**Recommended first step** - Verify your MCC configuration is working:

```bash
php artisan googleads:test-mcc
```

This command will:
- ✅ Check configuration files exist
- ✅ Initialize the Google Ads client
- ✅ Test authentication
- ✅ List all accessible customer accounts
- ✅ Display customer details (name, currency, timezone, manager status)
- ✅ Show developer token status and limitations

**Expected output:**
```
🔍 Testing Google Ads MCC Configuration

Step 1: Checking configuration files...
  ✅ Found: google_ads_php.ini
  ✅ Found: google-ads-api-credentials.json

Step 2: Initializing Google Ads client...
✅ Client initialized successfully

Step 3: Testing authentication...
✅ Authentication successful

Step 4: Listing accessible customers...
✅ Found X accessible customer(s)
```

---

### 2. Test Basic Connection

Simple connection test to the Google Ads API:

```bash
php artisan googleads:test-connection
```

This will:
- Connect to the Google Ads API
- List accessible customers
- Attempt to create a test customer account

---

### 3. Create a Sub-Account

Create a Google Ads sub-account under your MCC for a specific customer:

```bash
php artisan googleads:create-subaccount {customer_id} [options]
```

**Arguments:**
- `customer_id` - The Laravel customer ID (from your `customers` table)

**Options:**
- `--name=` - Custom account name (default: "{Customer Name} - Google Ads")
- `--currency=USD` - Currency code (default: USD)
- `--timezone=America/New_York` - Timezone (default: America/New_York)

**Examples:**

```bash
# Basic usage - creates sub-account for customer ID 1
php artisan googleads:create-subaccount 1

# With custom name
php artisan googleads:create-subaccount 1 --name="My Test Account"

# With all options
php artisan googleads:create-subaccount 1 --name="Test Account" --currency=EUR --timezone=Europe/London
```

**What it does:**
1. Validates the customer exists
2. Checks if customer already has a Google Ads account
3. Creates a new sub-account under your MCC
4. Updates the customer record with the new `google_ads_customer_id`

---

### 4. Test Campaign Publishing

Full end-to-end test of creating a campaign:

```bash
php artisan googleads:test-campaign-publish [options]
```

**Options:**
- `--customer-id=` - Specific customer ID to use (without dashes)
- `--login-customer-id=` - Manager account ID for sub-accounts
- `--create-test-account` - Create a new test account first

**Examples:**

```bash
# Auto-select customer and create campaign
php artisan googleads:test-campaign-publish

# Create test account first, then publish campaign
php artisan googleads:test-campaign-publish --create-test-account

# Use specific customer ID
php artisan googleads:test-campaign-publish --customer-id=1234567890
```

**What it does:**
1. Lists accessible customers
2. Creates a test client account (optional)
3. Creates a campaign budget
4. Creates a search campaign
5. Verifies the campaign was created

---

## Troubleshooting

### Common Errors

#### 1. "The payload is invalid" (DecryptException)

**Cause:** The refresh token stored in the database is not encrypted, but the code expects it to be.

**Solution:** The `BaseGoogleAdsService` now handles both encrypted and plain-text tokens automatically. If you still see this error:
- Check that the customer has a valid `google_ads_refresh_token`
- Verify the token format (Google tokens start with `1//`)

#### 2. "INVALID_ARGUMENT" / "Request contains an invalid argument"

**Possible causes:**
- Invalid MCC Customer ID
- API version mismatch
- Missing required fields in the request
- Developer token doesn't have sufficient access

**Solutions:**
- Verify your MCC Customer ID in `config/googleads.php`
- Ensure you're using a test account if you have test-only access
- Check the API version in your Google Ads PHP library

#### 3. "MCC Customer ID not configured"

**Solution:** Add your MCC ID to `config/googleads.php`:

```php
return [
    'mcc_customer_id' => env('GOOGLE_ADS_MCC_CUSTOMER_ID', 'YOUR_MCC_ID'),
];
```

And in your `.env`:
```
GOOGLE_ADS_MCC_CUSTOMER_ID=1234567890
```

#### 4. "Customer not found" or authentication errors

**Solutions:**
- Run `php artisan googleads:test-mcc` to verify access
- Check that your service account is linked to the MCC in Google Ads UI
- Verify OAuth credentials are valid and not expired

---

## Developer Token Access Levels

| Access Level | Capabilities |
|--------------|--------------|
| **Test Account** | Only test accounts, no real campaigns, no ad serving |
| **Basic Access** | Production accounts, real campaigns, full API access |
| **Standard Access** | Higher rate limits, required for large-scale operations |

### Applying for Basic Access

1. Go to: https://ads.google.com/aw/apicenter
2. Click "Access level" section
3. Apply for "Basic Access"
4. Fill out the application form
5. Wait for Google approval (usually 24-48 hours)

---

## Testing Workflow

### Recommended Order

1. **Test MCC Access**
   ```bash
   php artisan googleads:test-mcc
   ```

2. **Create a Sub-Account** (for a test customer)
   ```bash
   php artisan googleads:create-subaccount 1
   ```

3. **Test Campaign Publishing**
   ```bash
   php artisan googleads:test-campaign-publish
   ```

4. **Verify in Google Ads UI**
   - Log into Google Ads
   - Check the MCC for the new sub-account
   - Verify the test campaign was created

---

## Configuration Files

### `storage/app/google_ads_php.ini`

```ini
[GOOGLE_ADS]
developerToken = "YOUR_DEVELOPER_TOKEN"
loginCustomerId = "YOUR_MCC_CUSTOMER_ID"

[OAUTH2]
clientId = "YOUR_CLIENT_ID"
clientSecret = "YOUR_CLIENT_SECRET"
refreshToken = "YOUR_REFRESH_TOKEN"
```

### `config/googleads.php`

```php
<?php

return [
    'mcc_customer_id' => env('GOOGLE_ADS_MCC_CUSTOMER_ID'),
    // Other configuration...
];
```

---

## Related Documentation

- [Google Ads API Documentation](https://developers.google.com/google-ads/api/docs/start)
- [Google Ads PHP Client Library](https://github.com/googleads/google-ads-php)
- [OAuth2 Authentication Guide](https://developers.google.com/google-ads/api/docs/oauth/overview)
