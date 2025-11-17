# Google Ads Additional Services Implementation

This document outlines the design and implementation approach for extending Spectra's Google Ads integration to support Display and YouTube (Video) advertising campaign types. These services are intended to be callable by strategy-executing agents.

## 1. Core Principles

All new services will extend the existing `BaseGoogleAdsService` to leverage the pre-configured `GoogleAdsClient` for authentication and API communication. Each service should encapsulate a single, logical API operation or a sequence of tightly related operations.

Error handling will follow the existing pattern of logging errors and returning `null` or appropriate error indicators upon failure, allowing the calling agent to handle retry logic or alternative strategies.

## 2. Display Ads Implementation

Display advertising campaigns require specific services for campaign setup, ad group creation, ad creation (Responsive Display Ads), and asset management.

### 2.1. CreateDisplayCampaign

**Purpose:** Creates a new Google Ads Display campaign under a specified customer account.

**Agent Call (Conceptual):**

```php
($createDisplayCampaignService)(string $customerId, array $campaignData): ?string
```

**`campaignData` structure (example):**

```php
[
    'businessName' => 'My Business Display',
    'budget' => 50.00, // Daily budget in host currency
    'startDate' => 'YYYY-MM-DD',
    'endDate' => 'YYYY-MM-DD',
    'targetGeoLocation' => ['geoTargetConstants/2840'], // Example: USA
    // ... other display campaign specific settings
]
```

**Key API Interactions:**

- `CampaignService::mutateCampaigns`:
- `advertising_channel_type`: `AdvertisingChannelTypeEnum::DISPLAY`
- `campaign_budget`: Resource name from `CreateCampaignBudget`.
- Specific bidding strategy for display campaigns (e.g., `TargetCpa`, `MaximizeConversions`).

### 2.2. CreateDisplayAdGroup

**Purpose:** Creates an ad group within an existing Display campaign.

**Agent Call (Conceptual):**

```php
($createDisplayAdGroupService)(string $customerId, string $campaignResourceName, string $adGroupName): ?string
```

**Key API Interactions:**

- `AdGroupService::mutateAdGroups`:
- `campaign`: The resource name of the parent Display campaign.
- `type`: `AdGroupTypeEnum::DISPLAY_STANDARD` or other relevant display types.

### 2.3. UploadImageAsset

**Purpose:** Uploads an image file to be used as an asset in Responsive Display Ads.

**Agent Call (Conceptual):**

```php
($uploadImageAssetService)(string $customerId, string $imageFilePath, string $imageFileName): ?string
```

**Key API Interactions:**

- `AssetService::mutateAssets`:
- `type`: `AssetTypeEnum::IMAGE`
- `image_asset`: Base64 encoded image data.

### 2.4. CreateResponsiveDisplayAd

**Purpose:** Creates a Responsive Display Ad within a Display Ad Group, linking headlines, descriptions, and image assets.

**Agent Call (Conceptual):**

```php
($createResponsiveDisplayAdService)(string $customerId, string $adGroupResourceName, array $adData): ?string
```

**`adData` structure (example):**

```php
[
    'finalUrls' => ['https://www.example.com'],
    'headlines' => ['Short Headline 1', 'Short Headline 2'], // Text assets
    'longHeadlines' => ['Longer Headline 1'], // Text assets
    'descriptions' => ['Description 1', 'Description 2'], // Text assets
    'imageAssets' => ['assetResourceName1', 'assetResourceName2'], // Resource names from UploadImageAsset
    'logoAssets' => ['assetResourceNameLogo'],
    // ... other responsive display ad specific assets
]
```

**Key API Interactions:**

- `AdService::mutateAds`:
- `ad_group`: The resource name of the parent Display Ad Group.
- `responsive_display_ad`: Configure various text and image assets.

## 3. YouTube (Video) Ads Implementation

Video advertising campaigns have distinct requirements for campaign setup, ad group creation, video ad formats, and video asset management.

### 3.1. CreateVideoCampaign

**Purpose:** Creates a new Google Ads Video campaign.

**Agent Call (Conceptual):**

```php
($createVideoCampaignService)(string $customerId, array $campaignData): ?string
```

**`campaignData` structure (example):**

```php
[
    'businessName' => 'My Business Video',
    'budget' => 20.00,
    'videoCampaignSubType' => 'NON_SKIPPABLE_INSTREAM',
    // ... other video campaign specific settings
]
```

**Key API Interactions:**

- `CampaignService::mutateCampaigns`:
- `advertising_channel_type`: `AdvertisingChannelTypeEnum::VIDEO`
- `advertising_channel_sub_type`: `AdvertisingChannelSubTypeEnum::VIDEO_NON_SKIPPABLE_INSTREAM` (or other video types).
- Bidding strategy appropriate for video campaigns.

### 3.2. UploadVideoAsset

**Purpose:** Uploads a video file or references an existing YouTube video to be used as an asset.

**Agent Call (Conceptual):**

```php
($uploadVideoAssetService)(string $customerId, string $videoFilePathOrYouTubeId, bool $isYouTubeId = false): ?string
```

**Key API Interactions:**

- `AssetService::mutateAssets`:
- `type`: `AssetTypeEnum::VIDEO`
- `video_asset`: Set either `youtube_video_id` or upload raw `media_file`. (Note: Direct raw video upload is complex; linking YouTube IDs is more common).

### 3.3. CreateVideoAdGroup

**Purpose:** Creates an ad group within an existing Video campaign.

**Agent Call (Conceptual):**

```php
($createVideoAdGroupService)(string $customerId, string $campaignResourceName, string $adGroupName): ?string
```

**Key API Interactions:**

- `AdGroupService::mutateAdGroups`:
- `campaign`: The resource name of the parent Video campaign.
- `type`: `AdGroupTypeEnum::VIDEO_BUMPER` or other relevant video types.

### 3.4. CreateYoutubeVideoAd

**Purpose:** Creates a YouTube Video Ad (e.g., In-stream, Bumper) within a Video Ad Group, linking a video asset.

**Agent Call (Conceptual):**

```php
($createYoutubeVideoAdService)(string $customerId, string $adGroupResourceName, string $videoAssetResourceName, array $adData): ?string
```

**`adData` structure (example):**

```php
[
    'finalUrl' => 'https://www.example.com/video-landing',
    'callToActionText' => 'Learn More',
    // ... other video ad specific settings like companion banners
]
```

**Key API Interactions:**

- `AdService::mutateAds`:
- `ad_group`: The resource name of the parent Video Ad Group.
- `video_ad`: Configure `in_stream_video_ad`, `bumper_video_ad`, etc., and link `video_asset`..

## 4. Targeting Services (Common for Display & Video)

Targeting is crucial for these campaign types. Generic services could be developed to add various targeting criteria to ad groups.

### 4.1. AddAdGroupCriterion

**Purpose:** Adds various criteria (e.g., audience, topic, placement, demographic) to a given ad group.

**Agent Call (Conceptual):**

```php
($addAdGroupCriterionService)(string $customerId, string $adGroupResourceName, array $criterionData): ?string
```

**`criterionData` structure (example):**

```php
[
    'type' => 'AUDIENCE', // e.g., 'TOPIC', 'PLACEMENT', 'DEMOGRAPHIC'
    'audienceId' => '123456789', // For AUDIENCE type
    // ... specific fields based on criterion type
]
```

**Key API Interactions:**

- `AdGroupCriterionService::mutateAdGroupCriteria`:
- Set `audience_info`, `topic_info`, `placement_info`, `demographic_info` based on the `type`.

## 5. Implementation Notes & Best Practices

-   **Abstraction:** Keep the services focused on API interaction. Business logic should reside in higher-level application logic.
-   **Resource Names:** All API interactions heavily rely on resource names (e.g., `customers/123/campaignBudgets/456`). Ensure services return these names for chaining operations.
-   **Enums:** Make extensive use of the Google Ads API PHP client's Enum classes for setting campaign types, statuses, etc., to ensure type safety and valid API values.
-   **Error Logging:** Continue robust logging with `Log::error` to capture API failures for debugging and agent introspection.
-   **Idempotency:** Consider making services idempotent where possible, especially for creation operations, to handle retries gracefully.

This document serves as a guide for implementing these additional Google Ads capabilities, enabling strategy agents to programmatically create and manage a broader range of campaign types. 