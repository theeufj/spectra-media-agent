# Facebook Ads Account Creation Integration

## Changes Made

### 1. Database Migration
- **File**: `database/migrations/2025_11_17_102907_add_facebook_ads_fields_to_customers_table.php`
- **Changes**: Added two columns to `customers` table:
  - `facebook_ads_account_id` - Stores the Facebook Ads account ID (unique)
  - `facebook_ads_access_token` - Stores encrypted access token

### 2. Models
- **File**: `app/Models/Customer.php`
- **Changes**: Updated `$fillable` array to include:
  - `facebook_ads_account_id`
  - `facebook_ads_access_token`

### 3. Services
- **File**: `app/Services/FacebookAds/CreateFacebookAdsAccount.php`
- **New Service**: Handles Facebook Ads account creation with two methods:
  - `__invoke()` - Creates a new ad account
  - `getOrCreate()` - Gets existing account or creates new one if none exist

### 4. Controller
- **File**: `app/Http/Controllers/CustomerController.php`
- **Changes**: Updated `store()` method to:
  - Import `CreateFacebookAdsAccount` service
  - Attempt Facebook Ads account creation after customer is created
  - Store the account ID on customer record
  - Handle errors gracefully (doesn't fail customer creation)
  - Comprehensive logging of all operations

## Complete Customer Creation Flow

When a user creates a new customer from the profile page:

### Step 1: Customer Created Locally
- Customer record stored in database with all details (name, timezone, currency, etc.)
- User linked as owner

### Step 2: Google Ads Account Created
- Managed account created under MCC
- Credentials configured with timezone and currency
- Account ID stored on customer record

### Step 3: Facebook Ads Account Created/Linked
- Attempts to get existing accounts
- If none exist, creates new ad account
- Account ID stored on customer record
- Access token stored (encrypted)

### Step 4: Session Set
- New customer set as active session

## Configuration Required

Add these to your `.env`:
```env
GOOGLE_ADS_MCC_CUSTOMER_ID=your_mcc_id
FACEBOOK_APP_ID=your_app_id
FACEBOOK_APP_SECRET=your_app_secret
```

## Error Handling

- **Google Ads Creation Fails**: Customer still created, warning logged
- **Facebook Ads Creation Fails**: Customer still created, warning logged
- **Access Token Missing**: Customer created, no Facebook account linked
- **MCC Not Configured**: Google account creation skipped, warning logged

All errors are logged comprehensively for debugging.

## Database Structure

```sql
customers table:
- id
- name
- business_type
- description
- country
- timezone
- currency_code
- website
- phone
- google_ads_customer_id (stores MCC managed account ID)
- facebook_ads_account_id (stores Facebook account ID)
- facebook_ads_access_token (encrypted)
- created_at
- updated_at
```

## Usage

### In Your Application

```php
// Get customer with all ad accounts linked
$customer = Customer::find($id);

// Use Google Ads
$googleAdsClient = new GoogleAdsClient($customer);

// Use Facebook Ads
$facebookService = new FacebookAdsOrchestrationService($customer);
$accounts = $facebookService->getAdAccounts();
```

## Next Steps

1. Set up Facebook OAuth to get access tokens
2. Configure Facebook App permissions for ads management
3. Test customer creation with both platforms
4. Monitor logs for any integration issues
