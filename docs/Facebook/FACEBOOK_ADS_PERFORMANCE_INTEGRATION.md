# Facebook Ads Performance Data Integration

This document explains how the Spectra Media Agent now fetches and analyzes performance data from Facebook Ads alongside Google Ads, enabling the autonomous agent to make recommendations across both platforms.

## Overview

The system now automatically collects performance metrics from both Google Ads and Facebook Ads campaigns. This performance data feeds into the recommendation engine, allowing the agent to analyze campaign results and make intelligent optimization suggestions.

## Architecture

### Data Flow

```
Facebook Ads Campaign
        ↓
InsightService (fetches metrics via Graph API)
        ↓
FetchFacebookAdsPerformanceData Job
        ↓
FacebookAdsPerformanceData Table (stores daily metrics)
        ↓
RecommendationGenerationService (analyzes & generates insights)
        ↓
Recommendation Table (stores actionable recommendations)
        ↓
Agent (reviews & executes recommendations)
```

## Components

### 1. FacebookAdsPerformanceData Model

**Location:** `app/Models/FacebookAdsPerformanceData.php`

Stores daily performance metrics for Facebook Ads campaigns.

**Attributes:**
- `campaign_id` - Reference to Campaign model
- `facebook_campaign_id` - Facebook's campaign ID
- `date` - Performance date
- `impressions` - Number of impressions
- `clicks` - Number of clicks
- `cost` - Cost in dollars
- `conversions` - Number of conversions (parsed from actions)
- `reach` - Unique people reached
- `frequency` - Average frequency per person
- `cpc` - Cost per click
- `cpm` - Cost per thousand impressions
- `cpa` - Cost per acquisition

### 2. InsightService

**Location:** `app/Services/FacebookAds/InsightService.php`

Fetches performance insights from Facebook Graph API.

**Key Methods:**

#### `getCampaignInsights()`
Retrieves daily performance data for a campaign.

```php
$insightService = new InsightService($customer);
$insights = $insightService->getCampaignInsights(
    'campaign_id',
    '2025-11-14',
    '2025-11-17'
);
```

Returns array of daily insights with metrics.

#### `getAdSetInsights()`
Retrieves performance data for an ad set.

```php
$insights = $insightService->getAdSetInsights(
    'ad_set_id',
    '2025-11-14',
    '2025-11-17'
);
```

#### `getAdInsights()`
Retrieves performance data for individual ads.

```php
$insights = $insightService->getAdInsights(
    'ad_id',
    '2025-11-14',
    '2025-11-17'
);
```

#### `getAccountInsights()`
Retrieves account-level performance data.

```php
$insights = $insightService->getAccountInsights(
    'account_id',
    '2025-11-14',
    '2025-11-17'
);
```

#### `parseAction()`
Extracts specific action types from insights response (e.g., conversions).

```php
$conversions = $insightService->parseAction($insight['actions'], 'purchase');
```

### 3. FetchFacebookAdsPerformanceData Job

**Location:** `app/Jobs/FetchFacebookAdsPerformanceData.php`

Queued job that fetches and stores performance data.

**Features:**
- Fetches last 3 days of performance data
- Parses conversions from Facebook actions
- Handles cost conversion (Facebook returns in cents)
- Updates or creates performance records
- Generates recommendations based on performance
- Includes circuit breaker for API resilience
- Comprehensive error handling and logging

**Flow:**
1. Validates campaign has `facebook_ads_campaign_id`
2. Validates customer has `facebook_ads_access_token`
3. Acquires distributed lock to prevent concurrent runs
4. Calls InsightService to fetch metrics
5. Normalizes and stores data
6. Generates recommendations if performance data exists
7. Records job completion/failure for metrics

### 4. Console Command Updates

**Location:** `app/Console/Commands/CampaignFetchPerformanceData.php`

**Updated to:**
- Dispatch both Google Ads and Facebook Ads performance jobs
- Only dispatch Facebook job if campaign has `facebook_ads_campaign_id`
- Report count of jobs dispatched for each platform

**Usage:**
```bash
php artisan campaign:fetch-performance-data
```

This dispatches jobs for:
- All active campaigns with Google Ads IDs
- All active campaigns with Facebook Ads IDs

### 5. Campaign Model Updates

**File:** `app/Models/Campaign.php`

Added fields to fillable array:
- `google_ads_campaign_id`
- `facebook_ads_campaign_id`

These can now be set when creating/updating campaigns:

```php
$campaign = Campaign::create([
    'name' => 'Q4 Campaign',
    'google_ads_campaign_id' => 'customers/1234567890/campaigns/9876543210',
    'facebook_ads_campaign_id' => '23847523847523', // Facebook campaign ID
]);
```

## Database Schema

### facebook_ads_performance_data Table

```sql
CREATE TABLE facebook_ads_performance_data (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    campaign_id BIGINT NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
    facebook_campaign_id VARCHAR(255) NOT NULL,
    date DATE NOT NULL,
    impressions BIGINT DEFAULT 0,
    clicks BIGINT DEFAULT 0,
    cost DECIMAL(12, 2) DEFAULT 0,
    conversions DECIMAL(10, 2) DEFAULT 0,
    reach BIGINT,
    frequency DECIMAL(8, 2),
    cpc DECIMAL(10, 4),
    cpm DECIMAL(10, 4),
    cpa DECIMAL(10, 4),
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    UNIQUE KEY unique_campaign_date (campaign_id, facebook_campaign_id, date),
    INDEX idx_campaign_date (campaign_id, date)
);
```

### Migrations Applied

1. `2025_11_17_121456_create_facebook_ads_performance_data_table.php`
   - Creates performance data table with proper indexes

2. `2025_11_17_121500_add_facebook_ads_campaign_id_to_campaigns_table.php`
   - Adds `facebook_ads_campaign_id` column to campaigns table

## Integration with Recommendation System

Performance data automatically integrates with the existing recommendation engine:

1. **Data Collection** → FetchFacebookAdsPerformanceData job runs
2. **Storage** → Metrics saved to FacebookAdsPerformanceData table
3. **Analysis** → RecommendationGenerationService analyzes metrics
4. **Recommendations** → Recommendations saved with `platform: 'facebook'`
5. **Execution** → Agent reviews and executes using FacebookAds services

## Example Workflow

### Setting up a Facebook Campaign with Performance Tracking

```php
// 1. Create campaign (assuming OAuth connection already exists)
$campaign = Campaign::create([
    'customer_id' => $customer->id,
    'name' => 'Black Friday Campaign',
    'google_ads_campaign_id' => 'customers/1234567890/campaigns/9876543210',
    'facebook_ads_campaign_id' => '123456789012345', // From Facebook
]);

// 2. Campaign is now set up for performance tracking
// 3. Run the fetch command (manually or via scheduler)
// php artisan campaign:fetch-performance-data

// 4. Job automatically:
//    - Fetches last 3 days of metrics from Facebook
//    - Normalizes cost and conversions
//    - Stores in database
//    - Generates recommendations

// 5. Check stored performance data
$performanceData = FacebookAdsPerformanceData::where('campaign_id', $campaign->id)
    ->orderBy('date', 'desc')
    ->get();

foreach ($performanceData as $day) {
    echo "Date: {$day->date}, Spend: {$day->cost}, Conversions: {$day->conversions}, ROAS: " . ($day->cost > 0 ? $day->conversions / $day->cost : 0);
}

// 6. Check recommendations generated
$recommendations = Recommendation::where('campaign_id', $campaign->id)
    ->where('platform', 'facebook')
    ->where('status', 'pending')
    ->get();
```

## Scheduling

To automatically fetch performance data at regular intervals, add to `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule)
{
    // Fetch performance data every 2 hours
    $schedule->command('campaign:fetch-performance-data')
        ->everyTwoHours()
        ->runInBackground();
}
```

## Metrics Available from Facebook

The InsightService fetches these metrics from Facebook Graph API:

| Metric | Description |
|--------|-------------|
| impressions | How many times ads were displayed |
| clicks | How many times people clicked on ads |
| spend | Total cost in cents (converted to dollars) |
| reach | Unique people who saw the ads |
| frequency | Average times each person saw the ads |
| actions | Array of user actions (purchases, adds to cart, etc.) |
| cpc | Cost per click |
| cpm | Cost per thousand impressions |
| cpa | Cost per acquisition/action |

## Error Handling

The system includes comprehensive error handling:

1. **Missing Credentials**
   - Logs warning if customer lacks Facebook token
   - Skips fetch gracefully

2. **Circuit Breaker Pattern**
   - Tracks API failures
   - Opens circuit if too many failures
   - Prevents cascading failures

3. **Lock Mechanism**
   - Prevents concurrent runs for same campaign
   - Timeout: 10 minutes

4. **Retry Logic**
   - Job retries up to 5 times
   - Exponential backoff: 10, 20, 30, 40, 50 seconds

5. **Comprehensive Logging**
   - All fetch attempts logged
   - Metrics counts logged
   - Errors logged with full context

## Troubleshooting

### Campaign not fetching data
```php
// Check if campaign has Facebook campaign ID
$campaign = Campaign::find($campaignId);
if (!$campaign->facebook_ads_campaign_id) {
    // Set it
    $campaign->update(['facebook_ads_campaign_id' => 'the_facebook_id']);
}

// Check if customer has token
if (!$customer->facebook_ads_access_token) {
    // Need to reconnect via OAuth
}
```

### No performance data appearing
```php
// Check if job ran
Log::channel('stack')->get(); // Check logs

// Manually trigger fetch
FetchFacebookAdsPerformanceData::dispatch($campaign);

// Check queue status
php artisan queue:work
```

### Recommendations not generating
```php
// Verify recommendation service gets data
$performanceData = FacebookAdsPerformanceData::where('campaign_id', $campaign->id)->get();
if ($performanceData->isEmpty()) {
    // No performance data collected yet
}

// Check recommendation status
$recommendations = Recommendation::where('campaign_id', $campaign->id)->get();
```

## Next Steps

The agent now has access to Facebook Ads performance data and can:

1. **Analyze** performance trends across both platforms
2. **Compare** performance between Google and Facebook campaigns
3. **Identify** underperforming ads and budget allocation issues
4. **Generate** platform-specific recommendations
5. **Execute** optimizations using FacebookAds services
6. **Track** results of optimizations

All existing Google Ads functionality continues to work unchanged, with Facebook Ads now running in parallel.
