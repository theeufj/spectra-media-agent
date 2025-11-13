# Facebook Ads Service (`FacebookAdsService.php`)

This document provides an overview of the `FacebookAdsService` and the steps required to make it functional. This service is designed to allow your application to create and manage Facebook Ad campaigns on behalf of your users (other organizations).

## How It Works

The service is structured to follow the standard hierarchy of a Facebook Ad campaign:

1. **Campaign:** The top-level container that defines the overall objective (e.g., leads, traffic).
2. **Ad Set:** Sits inside a campaign and defines the targeting, budget, and schedule.
3. **Ad Creative:** The visual part of the ad, including the image/video and the text (headlines, descriptions).
4. **Ad:** The final object that brings the ad set and the ad creative together.

The service contains placeholder methods for each of these steps (`createCampaign`, `createAdSet`, `createAdCreative`, `createAd`).

## How to Get the Necessary Keys and Permissions

To make this service work, you need to set up a **Facebook App** and go through the OAuth 2.0 process to get permission to manage your users' ad accounts. This is a multi-step process that ensures security and proper authorization.

### Step 1: Create a Facebook for Developers App

1. **Go to Facebook for Developers:** Navigate to [https://developers.facebook.com/](https://developers.facebook.com/) and create a developer account if you don't have one.
2. **Create a New App:**
    * Click on "My Apps" and then "Create App".
    * Select the "Business" app type.
    * Provide a name for your app (e.g., "Spectra Media Agent") and your contact email.
    * Connect it to your Facebook Business Manager account if you have one.

### Step 2: Configure App Settings and Permissions

1. **Add the "Marketing API" Product:**
    * In your app's dashboard, go to "Add products" and set up the **Marketing API**.
2. **Configure OAuth Redirect URIs:**
    * In the app dashboard, go to "Facebook Login for Business" -> "Settings".
    * In the "Valid OAuth Redirect URIs" field, you must add the callback URL from your application. For local development, this would be something like:
        `http://localhost:8000/auth/facebook/callback`
3. **Request Necessary Permissions:**
    * Your application will need to request the following permissions from your users via the OAuth login flow:
        * `ads_management`: This is the primary permission required to create and manage ad campaigns on behalf of a user.
        * `business_management`: This may be required to access business-level assets.
        * `ads_read`: To read and analyze campaign performance.

### Step 3: Implement the OAuth 2.0 Flow

This is the most critical part. You will need to build a flow in your application where your users can "Connect their Facebook Account."

1. **The "Log in with Facebook" Button:**
    * You'll create a button in your UI that directs the user to a special Facebook authorization URL. This URL will include your `client_id` and the `scope` (the permissions you're requesting, like `ads_management`).
2. **User Authorization:**
    * The user will be taken to Facebook, where they will be asked to grant your app permission to manage their ads.
3. **The Redirect and Authorization Code:**
    * After the user approves, Facebook will redirect them back to your "Valid OAuth Redirect URI" with a temporary `code` in the URL.
4. **Exchange the Code for an Access Token:**
    * Your back-end must immediately take this `code`, combine it with your `client_id` and `client_secret`, and make a server-to-server request to Facebook to exchange it for a long-lived **Access Token**.
5. **Store the Access Token:**
    * This Access Token is the key to making API calls on behalf of the user. You must securely store this token in your database, associated with the user's account.

### Step 4: Using the Service

Once you have the user's Access Token, you can use the `FacebookAdsService`:

```php
// Example usage in a controller or job

// 1. Retrieve the user's stored access token from your database.
$accessToken = $user->facebook_access_token;
$adAccountId = $user->facebook_ad_account_id; // You'll also need to get this from the user

// 2. Instantiate the service with the token.
$facebookService = new \App\Services\FacebookAdsService($accessToken);

// 3. Call the methods to create the campaign.
$campaignId = $facebookService->createCampaign($localCampaign, $adAccountId);
// ... and so on for ad sets, creatives, and ads.
```

This service is currently a placeholder. The next step in development would be to replace the placeholder logic with actual `Http::post` calls to the Facebook Graph API endpoints, using the access token for authorization.
