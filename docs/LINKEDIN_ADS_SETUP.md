# LinkedIn Ads Integration Setup Guide

**Last Updated:** April 12, 2026

This guide covers configuring LinkedIn's Marketing API under the **management account pattern**. Spectra owns a single set of LinkedIn API credentials and manages all customer ad accounts centrally.

## Architecture

LinkedIn Ads follows the same management account pattern as all other platforms:

- **Single credential**: One OAuth refresh token in `.env` (or encrypted DB) for all API calls
- **No per-customer OAuth**: Customers never authenticate with LinkedIn
- **Account assignment**: Admin assigns a LinkedIn Ad Account ID to each customer
- **API calls**: `BaseLinkedInAdsService` authenticates using the platform refresh token and targets the customer's ad account per-request

> **Important**: Per-customer OAuth tokens are **prohibited**. See `config/platform_architecture.php`.

## Prerequisites

- A LinkedIn Developer App with **Advertising API** access approved
- A LinkedIn Ad Account at [Campaign Manager](https://www.linkedin.com/campaignmanager/)
- API Products: Advertising API, Reporting & ROI

---

## Step 1: Create a LinkedIn Developer App

1. Go to [LinkedIn Developers](https://www.linkedin.com/developers/)
2. Click **Create App**
3. Fill in:
   - **App name**: `Site to Spend`
   - **LinkedIn Page**: Select the Spectra Media company page
   - **App logo**: Upload your logo
4. Click **Create App**

## Step 2: Request API Products

Under your app's **Products** tab, request:

| Product | Why |
|---------|-----|
| **Advertising API** | Campaign CRUD, ad account management |
| **Reporting & ROI** | Performance metrics, analytics |

> Advertising API access requires LinkedIn review (2–5 business days).

## Step 3: Generate a Refresh Token

Use the three-legged OAuth flow **once** to generate a long-lived refresh token for the platform account:

1. Build the authorization URL:
   ```
   https://www.linkedin.com/oauth/v2/authorization?response_type=code&client_id=YOUR_CLIENT_ID&redirect_uri=https://sitetospend.com/auth/linkedin-ads/callback&scope=r_ads%20rw_ads%20r_ads_reporting%20r_organization_social%20w_organization_social
   ```
2. Authorize with the Spectra management LinkedIn account
3. Exchange the authorization code for tokens
4. Store the refresh token in `.env`

## Step 4: Set Environment Variables

```env
LINKEDIN_ADS_CLIENT_ID=your_client_id
LINKEDIN_ADS_CLIENT_SECRET=your_client_secret
LINKEDIN_ADS_REFRESH_TOKEN=your_management_refresh_token
LINKEDIN_ADS_API_VERSION=202404
```

## Step 5: Assign Ad Accounts to Customers

In the admin panel, set `linkedin_ads_account_id` on each customer record. This is the LinkedIn Ad Account ID visible in Campaign Manager.

---

## Key Files

| File | Purpose |
|------|---------|
| `config/linkedinads.php` | API credentials, version, rate limits |
| `app/Services/LinkedInAds/BaseLinkedInAdsService.php` | Platform-level auth, API client |
| `app/Services/LinkedInAds/CampaignService.php` | Campaign CRUD, targeting |
| `app/Services/LinkedInAds/PerformanceService.php` | Analytics sync |
| `app/Services/Agents/LinkedInAdsExecutionAgent.php` | AI-driven deployment |

## Customer Model Fields

| Column | Type | Purpose |
|--------|------|---------|
| `linkedin_ads_account_id` | string | Customer's ad account ID (identifier only) |

> No access tokens, refresh tokens, or expiry timestamps are stored on the Customer model.

---

## Troubleshooting

### "No management refresh token configured"
→ Set `LINKEDIN_ADS_REFRESH_TOKEN` in `.env`

### "LinkedIn Ads authentication failed"
→ The refresh token may have expired. Re-generate it using the OAuth flow in Step 3.

### Advertising API not approved
→ Campaign creation will fail with 403 until LinkedIn approves API access for your app.
