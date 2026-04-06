# LinkedIn Ads Integration Setup Guide

This guide walks through configuring LinkedIn's Marketing API so Site to Spend can create and manage LinkedIn Ads campaigns on behalf of your customers.

## How It Works

Unlike Google Ads (which uses a centralized MCC manager account), LinkedIn Ads follows the same model as Facebook: each customer authorizes your app via OAuth, granting delegated access to their ad account. Your app then manages their campaigns using their access token.

- **OAuth2 flow**: Customer clicks "Connect LinkedIn Ads" → redirected to LinkedIn → grants permissions → callback stores tokens
- **Token lifetime**: Access tokens last **60 days** and are automatically refreshed by `BaseLinkedInAdsService`
- **API**: LinkedIn Marketing REST API (versioned via `LinkedIn-Version` header)

## Prerequisites

- A LinkedIn account with admin access to a [LinkedIn Company Page](https://www.linkedin.com/company/)
- A LinkedIn Ad Account at [Campaign Manager](https://www.linkedin.com/campaignmanager/)

---

## Step 1: Create a LinkedIn Developer App

1. Go to [LinkedIn Developers](https://www.linkedin.com/developers/)
2. Click **Create App**
3. Fill in:
   - **App name**: `Site to Spend`
   - **LinkedIn Page**: Select your company page (e.g., Spectra Media)
   - **App logo**: Upload your logo
   - **Legal agreement**: Check the box
4. Click **Create App**

This gives you a **Client ID** and **Client Secret** under the **Auth** tab.

## Step 2: Request API Products

Under your app's **Products** tab, request access to:

| Product | Why | Scopes Granted |
|---------|-----|----------------|
| **Advertising API** | Campaign CRUD, ad account management | `r_ads`, `rw_ads` |
| **Reporting & ROI** | Performance metrics, analytics | `r_ads_reporting` |
| **Community Management API** | Posting sponsored content | `r_organization_social`, `w_organization_social` |

> **Note**: Advertising API access requires LinkedIn review and can take **2–5 business days** to approve. The other products are typically instant.

## Step 3: Configure OAuth Redirect URI

Under the **Auth** tab of your app:

1. Scroll to **Authorized redirect URLs for your app**
2. Add: `https://sitetospend.com/auth/linkedin-ads/callback`
3. For local development, also add: `http://localhost:8000/auth/linkedin-ads/callback`

## Step 4: Set Environment Variables

Add to your `.env` file (and production environment):

```env
LINKEDIN_ADS_CLIENT_ID=your_client_id_here
LINKEDIN_ADS_CLIENT_SECRET=your_client_secret_here
LINKEDIN_ADS_REDIRECT_URI=https://sitetospend.com/auth/linkedin-ads/callback
```

For local development:

```env
LINKEDIN_ADS_REDIRECT_URI=http://localhost:8000/auth/linkedin-ads/callback
```

## Step 5: Test the Connection

1. Log in to Site to Spend
2. Go to **Profile** → find your customer account card
3. Click **Connect LinkedIn Ads** (sky-blue button below the Facebook section)
4. Authorize on LinkedIn's consent screen
5. You'll be redirected back with the ad account connected

If the customer has multiple LinkedIn ad accounts, they'll be prompted to select one.

---

## Architecture Overview

### OAuth Flow

```
User clicks "Connect LinkedIn Ads"
    → GET /auth/linkedin-ads/redirect
    → Redirect to linkedin.com/oauth/v2/authorization
    → User grants permissions
    → LinkedIn redirects to /auth/linkedin-ads/callback
    → Exchange code for access_token + refresh_token
    → Fetch ad accounts from LinkedIn API
    → Store tokens + account ID on Customer model
```

### Key Files

| File | Purpose |
|------|---------|
| `config/linkedinads.php` | API credentials, version, rate limits |
| `app/Http/Controllers/Auth/LinkedInAdsOAuthController.php` | OAuth redirect, callback, disconnect |
| `app/Services/LinkedInAds/BaseLinkedInAdsService.php` | API client, token refresh, HTTP calls |
| `app/Services/LinkedInAds/CampaignService.php` | Campaign CRUD, targeting, Lead Gen Forms |
| `app/Services/LinkedInAds/PerformanceService.php` | Analytics sync, performance summaries |
| `app/Services/Agents/LinkedInAdsExecutionAgent.php` | AI-driven campaign deployment agent |
| `app/Models/LinkedInAdsPerformanceData.php` | Performance data model |

### Routes

| Route | Method | Purpose |
|-------|--------|---------|
| `/auth/linkedin-ads/redirect` | GET | Start OAuth flow |
| `/auth/linkedin-ads/callback` | GET | Handle OAuth callback |
| `/auth/linkedin-ads/select-account` | POST | Choose ad account (multi-account) |
| `/auth/linkedin-ads/disconnect` | POST | Remove LinkedIn connection |

### Customer Model Fields

| Column | Type | Purpose |
|--------|------|---------|
| `linkedin_ads_account_id` | string | Selected ad account ID |
| `linkedin_oauth_access_token` | string (hidden) | OAuth access token |
| `linkedin_oauth_refresh_token` | string (hidden) | OAuth refresh token |
| `linkedin_token_expires_at` | datetime | Token expiry (60 days) |

---

## Campaign Types Supported

- **Sponsored Content** — Native ads in the LinkedIn feed
- **Message Ads (InMail)** — Direct messages to prospects
- **Lead Gen Forms** — In-platform lead capture without landing pages
- **LinkedIn Insight Tag** — Conversion tracking pixel

## Targeting Capabilities

LinkedIn's B2B targeting is built into `CampaignService::buildTargetingCriteria()`:

- Job titles & job functions
- Industries
- Company size (staff count ranges)
- Seniority levels
- Skills
- Geographic locations
- Specific companies (account-based marketing)

---

## Troubleshooting

### "LinkedIn API credentials are not configured"
→ Check that `LINKEDIN_ADS_CLIENT_ID` and `LINKEDIN_ADS_CLIENT_SECRET` are set in `.env`

### "Invalid OAuth state"
→ Session expired between redirect and callback. Try again.

### "Failed to get LinkedIn access token"
→ Check that the redirect URI in `.env` exactly matches what's configured in the LinkedIn Developer App (including trailing slashes).

### Advertising API not approved yet
→ The "Connect LinkedIn Ads" button will work for OAuth, but campaign creation will fail with a 403 until LinkedIn approves Advertising API access for your app.

### Token expired
→ Tokens last 60 days. `BaseLinkedInAdsService` auto-refreshes them. If refresh fails, the customer needs to reconnect via the Profile page.
