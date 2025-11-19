# Facebook Ads Deployment - Implementation Requirements

## Current Status

❌ **Not Production Ready** - Facebook deployment infrastructure is incomplete.

## Critical Missing Components

### 1. FacebookAdsDeploymentStrategy Class

**Priority:** HIGH
**Status:** Not implemented (commented out in `DeploymentService.php`)

**Required Implementation:**
```php
// app/Services/Deployment/FacebookAdsDeploymentStrategy.php
namespace App\Services\Deployment;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\FacebookAds\FacebookAdsOrchestrationService;

class FacebookAdsDeploymentStrategy implements DeploymentStrategy
{
    protected Customer $customer;

    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
    }

    public function deploy(Campaign $campaign, Strategy $strategy): bool
    {
        // 1. Create campaign
        // 2. Create ad set with targeting
        // 3. Upload images/videos to Facebook CDN
        // 4. Create ad creative
        // 5. Create ad linking creative to ad set
        // 6. Store Facebook IDs on strategy model
    }
}
```

### 2. DeploymentStrategy Interface Inconsistency

**Priority:** HIGH
**Current Issue:** Interface requires 3 parameters, but implementations use 2

**Fix Required:**
```php
// Option A: Update interface to match current usage
interface DeploymentStrategy
{
    public function deploy(Campaign $campaign, Strategy $strategy): bool;
}

// Option B: Add Connection model and update all implementations
// (Better for multi-account support)
```

### 3. Facebook Page Selection & Storage

**Priority:** CRITICAL
**Current Issue:** Ad creatives require a Facebook Page ID, but none is stored

**Required Changes:**

**Database Migration:**
```php
Schema::table('customers', function (Blueprint $table) {
    $table->string('facebook_page_id')->nullable()->after('facebook_ads_account_id');
    $table->string('facebook_page_name')->nullable()->after('facebook_page_id');
});
```

**OAuth Enhancement:**
Update `FacebookOAuthController::callback()` to fetch and store user's pages:
```php
// After storing access token
$pages = $this->getPages($user->access_token);
if (!empty($pages)) {
    $customer->facebook_page_id = $pages[0]['id']; // Let user choose in UI
    $customer->facebook_page_name = $pages[0]['name'];
    $customer->save();
}
```

**Add Page Selection UI:**
- Profile page should show connected page
- Allow user to switch between multiple pages
- Required before deployment can work

### 4. Complete Creative Upload Implementation

**Priority:** HIGH
**Current Issue:** `CreativeService::uploadImage()` is incomplete (line 143-150)

**Required Implementation:**
```php
protected function uploadImage(string $accountId, string $imageUrl): ?string
{
    try {
        // Download image from S3
        $imageContent = Storage::disk('s3')->get($imageUrl);
        if (!$imageContent) {
            Log::error("Failed to get image from S3", ['path' => $imageUrl]);
            return null;
        }

        // Create temporary file
        $tempPath = sys_get_temp_dir() . '/' . uniqid('fb_image_') . '.jpg';
        file_put_contents($tempPath, $imageContent);

        // Upload to Facebook using multipart form
        $response = Http::asMultipart()
            ->withToken($this->accessToken)
            ->attach('source', fopen($tempPath, 'r'), basename($tempPath))
            ->post($this->getBaseUrl() . "/act_{$accountId}/adimages");

        unlink($tempPath); // Clean up

        $data = $response->json();
        if (isset($data['images'])) {
            $firstImage = reset($data['images']);
            return $firstImage['hash'] ?? null;
        }

        Log::error("Failed to upload image to Facebook", ['response' => $data]);
        return null;
    } catch (\Exception $e) {
        Log::error("Error uploading image: " . $e->getMessage());
        return null;
    }
}
```

**Video Upload:**
Similar implementation needed for `uploadVideo()` using `/videos` endpoint.

### 5. Ad Creative Format Mapping

**Priority:** MEDIUM
**Current Issue:** Your ad copy structure may not match Facebook's format requirements

**Required Mapping:**
```php
// Your ad copy format → Facebook creative format
[
    'headlines' => $adCopy->headlines, // Facebook: 25 char limit
    'descriptions' => $adCopy->descriptions, // Facebook: 30 char limit
    'primary_text' => $adCopy->descriptions[0], // Facebook: 125 char limit (main text)
    'link_url' => $campaign->landing_page_url,
    'call_to_action' => 'LEARN_MORE', // Facebook enum: LEARN_MORE, SHOP_NOW, SIGN_UP, etc.
]
```

**Validation Needed:**
- Headline length (25 chars)
- Description length (30 chars)
- Primary text length (125 chars)
- Image dimensions (1200x628 recommended)
- Video specs (MP4, max 4GB, 1:1 or 9:16 aspect ratio)

### 6. Targeting Implementation

**Priority:** HIGH
**Current Issue:** Ad sets require targeting parameters, but none are defined

**Required Fields:**
```php
$targeting = [
    'geo_locations' => [
        'countries' => ['US'], // From campaign or strategy
    ],
    'age_min' => 18,
    'age_max' => 65,
    'genders' => [0], // 0 = all, 1 = male, 2 = female
    'targeting_optimization' => 'none', // or 'expansion_all' for auto-expansion
];
```

**Enhancement Opportunity:**
Store targeting preferences in `strategies` table or create `targeting_configs` table:
```php
Schema::create('targeting_configs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('strategy_id')->constrained()->onDelete('cascade');
    $table->json('geo_locations'); // Countries, regions, cities
    $table->integer('age_min')->default(18);
    $table->integer('age_max')->default(65);
    $table->json('genders')->default([0]); // All genders
    $table->json('interests')->nullable(); // Facebook interest IDs
    $table->json('behaviors')->nullable(); // Facebook behavior IDs
    $table->timestamps();
});
```

### 7. Budget Configuration

**Priority:** MEDIUM
**Current Issue:** Budget in strategies table vs. Facebook's requirements

**Facebook Budget Types:**
- **Daily Budget**: Spent per day (recommended for beginners)
- **Lifetime Budget**: Total budget across campaign duration

**Required Conversion:**
```php
// Your strategy has budget in dollars
$budgetInCents = $strategy->budget * 100;

// Campaign level
$campaignBudget = [
    'daily_budget' => $budgetInCents,
    // OR
    'lifetime_budget' => $budgetInCents * 30, // 30 days
];

// Ad set level (distribute budget)
$adSetBudget = [
    'daily_budget' => $budgetInCents,
    'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP', // or 'LOWEST_COST_WITH_BID_CAP'
];
```

### 8. Campaign Objective Mapping

**Priority:** HIGH
**Current Issue:** Need to determine objective based on campaign goal

**Facebook Objectives:**
```php
// Map your campaign type to Facebook objective
$objectiveMapping = [
    'awareness' => 'REACH',
    'consideration' => 'LINK_CLICKS',
    'conversions' => 'CONVERSIONS',
    'traffic' => 'LINK_CLICKS',
    'engagement' => 'POST_ENGAGEMENT',
    'app_installs' => 'APP_INSTALLS',
    'video_views' => 'VIDEO_VIEWS',
    'lead_generation' => 'LEAD_GENERATION',
    'messages' => 'MESSAGES',
];

// Add to campaigns table:
Schema::table('campaigns', function (Blueprint $table) {
    $table->string('objective')->default('LINK_CLICKS')->after('name');
});
```

### 9. Error Handling & Retry Logic

**Priority:** MEDIUM
**Current Issue:** Facebook API can be flaky; need robust error handling

**Recommended Implementation:**
```php
// In FacebookAdsDeploymentStrategy
protected function deployWithRetry(callable $action, int $maxRetries = 3): mixed
{
    $attempt = 0;
    $lastException = null;

    while ($attempt < $maxRetries) {
        try {
            return $action();
        } catch (\Facebook\Exceptions\FacebookResponseException $e) {
            $lastException = $e;
            $attempt++;
            
            // Check if error is retryable
            if (!$this->isRetryableError($e->getCode())) {
                throw $e;
            }
            
            // Exponential backoff
            sleep(pow(2, $attempt));
            Log::warning("Facebook API retry attempt {$attempt}/{$maxRetries}", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    throw $lastException;
}

protected function isRetryableError(int $code): bool
{
    // Facebook retryable error codes
    return in_array($code, [
        1, // API unknown error
        2, // API service error
        4, // API too many calls
        17, // API user request limit reached
        80001, // API internal error
    ]);
}
```

### 10. Campaign Status Management

**Priority:** MEDIUM
**Current Issue:** Need to track deployment status and platform campaign IDs

**Database Enhancement:**
```php
Schema::table('strategies', function (Blueprint $table) {
    $table->string('facebook_campaign_id')->nullable()->after('platform');
    $table->string('facebook_adset_id')->nullable()->after('facebook_campaign_id');
    $table->string('facebook_ad_id')->nullable()->after('facebook_adset_id');
    $table->string('facebook_creative_id')->nullable()->after('facebook_ad_id');
    $table->enum('facebook_status', ['draft', 'pending', 'active', 'paused', 'deleted'])
        ->nullable()
        ->after('facebook_creative_id');
});
```

## Implementation Checklist

### Phase 1: Foundation (1-2 days)
- [ ] Fix `DeploymentStrategy` interface inconsistency
- [ ] Add Facebook Page ID to customers table
- [ ] Update OAuth flow to fetch and store pages
- [ ] Add page selection to profile UI

### Phase 2: Creative Upload (2-3 days)
- [ ] Complete `uploadImage()` implementation
- [ ] Complete `uploadVideo()` implementation
- [ ] Add image dimension validation
- [ ] Add video format validation
- [ ] Test S3 → Facebook upload pipeline

### Phase 3: Deployment Strategy (3-4 days)
- [ ] Create `FacebookAdsDeploymentStrategy` class
- [ ] Implement campaign creation
- [ ] Implement ad set creation with targeting
- [ ] Implement creative creation
- [ ] Implement ad creation
- [ ] Store Facebook IDs on strategy model

### Phase 4: Configuration & Validation (2-3 days)
- [ ] Add objective field to campaigns table
- [ ] Create targeting configuration system
- [ ] Add ad copy length validation
- [ ] Add image/video specs validation
- [ ] Implement budget conversion logic

### Phase 5: Testing & Polish (2-3 days)
- [ ] Add error handling and retry logic
- [ ] Test complete deployment flow
- [ ] Add deployment status tracking
- [ ] Update UI to show Facebook campaign links
- [ ] Add ability to pause/resume Facebook campaigns

### Phase 6: Monitoring & Optimization (1-2 days)
- [ ] Add deployment success/failure tracking
- [ ] Add logging for all Facebook API calls
- [ ] Create admin dashboard for deployment stats
- [ ] Add alerts for failed deployments

## Total Estimated Time: 11-17 days

## Testing Requirements

### Before Production:

1. **OAuth Flow:**
   - Connect Facebook account
   - Verify page selection works
   - Test disconnection

2. **Creative Upload:**
   - Upload various image formats
   - Upload various video formats
   - Test S3 permission edge cases

3. **Full Deployment:**
   - Create test campaign with all collateral
   - Deploy to Facebook sandbox account
   - Verify campaign appears in Facebook Ads Manager
   - Check all targeting/budget settings
   - Verify creative renders correctly

4. **Error Scenarios:**
   - Invalid access token
   - Missing Facebook page
   - Image too large
   - Invalid targeting
   - Insufficient permissions

## Additional Recommendations

### 1. Facebook Business SDK

Consider using Facebook's official PHP SDK instead of raw HTTP:

```bash
composer require facebook/php-business-sdk
```

Benefits:
- Type safety
- Better error handling
- Auto-generated API methods
- Easier to maintain

### 2. Deployment Preview

Add a "Preview Deployment" feature:
- Show user what will be created
- Display targeting summary
- Show budget breakdown
- Preview creative rendering
- Confirm before actual deployment

### 3. Incremental Deployment

Instead of all-or-nothing:
- Deploy campaigns one at a time
- Allow partial success (deploy what you can)
- Provide clear feedback on what succeeded/failed
- Allow retry of failed deployments

### 4. Facebook Catalog Integration

For e-commerce:
- Sync product catalog to Facebook
- Use dynamic ads
- Auto-create product sets
- Enable retargeting

### 5. Conversion Tracking

Already partially implemented with GTM, but enhance:
- Verify pixel is installed before deployment
- Map GTM events to Facebook events
- Test conversion tracking before campaign launch
- Provide conversion setup checklist

## Documentation Needs

Once implemented, create:
- [ ] Facebook deployment user guide
- [ ] Troubleshooting guide for common errors
- [ ] Admin guide for monitoring deployments
- [ ] Developer guide for extending deployment strategies

## Maintenance Considerations

- Facebook API version updates (currently using v19.0)
- Permission requirements may change
- Ad format specifications evolve
- Policy compliance requirements
- Budget and billing limits

## Security Notes

- Access tokens are encrypted ✅
- Need token refresh logic (tokens expire)
- Implement permission scopes review
- Add audit logging for all deployments
- Consider webhook verification for status updates
