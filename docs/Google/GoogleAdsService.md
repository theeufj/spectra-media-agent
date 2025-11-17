# Google Ads Service (`GoogleAdsService.php`)

This document provides an overview of the `GoogleAdsService` and the extensive steps required to make it functional. This service is designed to allow your application to create and manage Google Ads campaigns on behalf of your users (other organizations), typically through a Manager Account (MCC).

## How It Works

The service is structured to follow the standard hierarchy of a Google Ads campaign:

1. **Campaign:** The top-level container that defines the budget, network settings, and overall goal.
2. **Ad Group:** Sits inside a campaign and contains a set of related ads and keywords.
3. **Ad:** The final ad creative, containing headlines, descriptions, and URLs.

The service contains placeholder methods for each of these steps (`createCampaign`, `createAdGroup`, `createAd`). The Google Ads API is complex, and a full implementation requires creating many dependent objects (like `CampaignBudget`).

## How to Get the Necessary Keys and Permissions

To make this service work, you need to go through several steps with Google Cloud and the Google Ads API. This is a highly regulated API, so the setup is more involved than with other platforms.

### Step 1: Prerequisites

1. **Google Ads Manager Account (MCC):** To manage other people's ad accounts, you **must** have a Google Ads Manager Account. If you don't have one, create one at [ads.google.com](https://ads.google.com/).
2. **Google Cloud Project:** You need a Google Cloud project to manage billing and API access. Create one at the [Google Cloud Console](https://console.cloud.google.com/).

### Step 2: Enable the Google Ads API

1. In your Google Cloud project, navigate to the "APIs & Services" -> "Library".
2. Search for "Google Ads API" and **enable it**.

### Step 3: Obtain a Developer Token

1. **Apply for API Access:** Log in to your Google Ads Manager Account. Navigate to "Tools & Settings" -> "API Center".
2. **Fill out the Application:** You will need to apply for a Developer Token. You'll be asked for details about your application and its purpose. Start with "Test Account" access.
3. **Receive Your Token:** Once approved, your Developer Token will appear in the API Center. This is a critical credential. **Store it securely.**

### Step 4: Create OAuth 2.0 Credentials

This is how your application will get permission from your users to manage their accounts.

1. **Go to the Credentials Page:** In the Google Cloud Console, go to "APIs & Services" -> "Credentials".
2. **Create Credentials:**
    * Click "Create Credentials" -> "OAuth client ID".
    * Select "Web application" as the application type.
    * Give it a name (e.g., "Spectra Media Agent Web Client").
3. **Configure Redirect URIs:**
    * Under "Authorized redirect URIs", you must add the callback URL from your application. For local development, this would be:
        `http://localhost:8000/auth/google-ads/callback`
4. **Get Your Client ID and Secret:** After creation, you will be given a **Client ID** and a **Client Secret**. **Store these securely.**

### Step 5: Implement the OAuth 2.0 Flow

Similar to the Facebook flow, you need to build a process for your users to authorize your application.

1. **The "Connect Google Ads" Button:**
    * Create a button that directs the user to the Google Consent Screen URL. This URL will include your `client_id`, the `scope` for the Google Ads API (`https://www.googleapis.com/auth/adwords`), and a parameter for `access_type=offline` to request a **Refresh Token**.
2. **User Authorization:**
    * The user will be taken to a Google login screen and asked to grant your app permission to "Manage your Google Ads campaigns."
3. **The Redirect and Authorization Code:**
    * After approval, Google will redirect the user back to your callback URI with a temporary `code`.
4. **Exchange for Tokens:**
    * Your back-end must take this `code` and exchange it for two tokens:
        * An **Access Token** (which expires in 1 hour).
        * A **Refresh Token** (which is long-lived and can be used to get new access tokens).
5. **Store the Refresh Token:**
    * You **must** securely store the **Refresh Token** in your database, associated with the user's account. The Access Token is temporary and can be discarded.

### Step 6: Using the Service

When you need to make an API call on behalf of a user:

1. **Get a Fresh Access Token:** Use the stored Refresh Token to request a new, valid Access Token from Google.
2. **Gather All Credentials:** You will need:
    * The new **Access Token**.
    * Your **Developer Token**.
    * The **Customer ID** of the ad account you want to manage (e.g., `123-456-7890`).
    * Your **Login Customer ID** (the ID of your Manager Account, without dashes, e.g., `9876543210`). This is required to tell Google you are acting on behalf of a client.
3. **Instantiate and Use the Service:**

```php
// Example usage in a job

$accessToken = $this->getFreshAccessToken($user->google_ads_refresh_token);
$developerToken = config('services.google_ads.developer_token');
$customerId = $user->google_ads_customer_id;
$loginCustomerId = config('services.google_ads.login_customer_id');

$googleAdsService = new \App\Services\GoogleAdsService($accessToken, $developerToken, $customerId, $loginCustomerId);

$campaignResourceName = $googleAdsService->createCampaign($localCampaign);
// ... and so on.
```

This service is currently a placeholder. A full implementation is significantly more complex than the Facebook API and often involves using the official [Google Ads PHP Client Library](https://github.com/googleads/google-ads-php) to handle the complexities of the API.
