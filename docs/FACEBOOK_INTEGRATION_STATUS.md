# Facebook/Instagram Ads Integration Status

**Last Updated:** April 12, 2026
**Architecture:** Platform Business Manager (System User Token)
**Overall Completion:** ~95% ✅

---

## Architecture

Facebook Ads uses the **management account pattern**:

- **System User Token**: A single token from Spectra's Business Manager handles all API calls
- **No per-customer OAuth**: Customers never authenticate with Facebook
- **Ad account assignment**: Admin creates/assigns BM-owned ad accounts via `FacebookAdAccountController`
- **Platform Page**: Spectra's Facebook Page is used as the publisher for all customer campaigns

> See `config/platform_architecture.php` for the full architecture rules.

---

## ✅ Complete Features

### 1. Business Manager Integration (System User)
- **Platform-level authentication** via System User token
- **BM-owned ad accounts** assigned per customer
- **System User access verification** endpoint
- **No per-customer tokens stored** on Customer model

**Files:**
- `app/Services/FacebookAds/BaseFacebookAdsService.php` — uses `config('services.facebook.system_user_token')`
- `app/Http/Controllers/FacebookAdAccountController.php` — admin ad account assignment
- `app/Services/FacebookAds/BusinessManagerService.php` — BM account management

### 2. Campaign Deployment
- **Full campaign creation flow**: Campaign → AdSet → Ad
- **Display, Video, Carousel campaigns** supported
- **AI-powered execution planning** via `FacebookAdsExecutionAgent`
- **Placement optimization** (Feed, Stories, Reels, Explore)

**Files:**
- `app/Services/FacebookAds/FacebookAdsOrchestrationService.php`
- `app/Services/FacebookAds/FacebookAdsDeploymentStrategy.php`
- `app/Services/Agents/FacebookAdsExecutionAgent.php`

### 3. Creative Management
- **Image/Video upload** to Facebook CDN
- **Carousel creative** creation
- **Creative creation** for ads

**Files:**
- `app/Services/FacebookAds/CreativeService.php`

### 4. Campaign Management
- **Campaign, AdSet, Ad CRUD** operations with targeting
- **Pause/Resume** campaigns

**Files:**
- `app/Services/FacebookAds/CampaignService.php`
- `app/Services/FacebookAds/AdSetService.php`
- `app/Services/FacebookAds/AdService.php`

### 5. Performance Monitoring
- **Insights retrieval** at campaign/adset/ad level
- **Performance data storage** in database

**Files:**
- `app/Services/FacebookAds/InsightService.php`
- `app/Jobs/FetchFacebookAdsPerformanceData.php`
- `app/Models/FacebookAdsPerformanceData.php`

### 6. Ad Spend Billing Integration
- **Get Facebook Ads spend** via Insights API
- **Daily billing calculation** includes Facebook spend
- **Pause/Resume campaigns** on payment failure/recovery

### 7. Custom Audiences
- **Customer list audiences** (email/phone with SHA256 hashing)
- **Website custom audiences** (pixel-based)
- **Lookalike audience creation**

**Files:**
- `app/Services/FacebookAds/CustomAudienceService.php`

### 8. Conversion API (CAPI)
- **Server-side event tracking** (PageView, Purchase, Lead, AddToCart, etc.)
- **User data hashing** per Facebook requirements
- **Event deduplication** with Pixel via event_id

**Files:**
- `app/Services/FacebookAds/ConversionsApiService.php`

### 9. System User Token Health Check
- **Daily health check** verifies token validity
- **Admin notification** if token is invalid or expiring

**Files:**
- `app/Services/FacebookAds/TokenService.php` — platform token debugging only
- `app/Jobs/RefreshFacebookTokens.php` — verifies System User token health

---

## Configuration

### Environment Variables
```env
FACEBOOK_APP_ID=your_app_id
FACEBOOK_APP_SECRET=your_app_secret
FACEBOOK_BUSINESS_MANAGER_ID=your_bm_id
FACEBOOK_SYSTEM_USER_TOKEN=your_system_user_token
FACEBOOK_PAGE_ID=your_platform_page_id
```

### Customer Model Fields
```
facebook_ads_account_id   — BM-owned ad account ID (identifier only)
facebook_page_id          — Customer's Facebook Page ID (optional)
facebook_page_name        — Customer's Facebook Page name (optional)
facebook_bm_owned         — Whether the ad account is BM-owned (boolean)
```

> No access tokens, refresh tokens, or expiry timestamps are stored on the Customer model.

---

## Scheduled Jobs

| Job | Schedule | Description |
|-----|----------|-------------|
| `RefreshFacebookTokens` | Daily at 03:00 | Verifies System User token health |
| `FetchFacebookAdsPerformanceData` | Hourly | Syncs performance data for active campaigns |
| `ProcessDailyAdSpendBilling` | Daily at 06:00 | Bills for Facebook ad spend |

---

## Admin Routes

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/customers/{customer}/facebook/setup` | Ad account setup page |
| POST | `/customers/{customer}/facebook/assign` | Assign BM ad account |
| POST | `/customers/{customer}/facebook/verify` | Verify System User access |
