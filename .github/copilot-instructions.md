# Copilot Instructions — Spectra Media Agent

## Project Overview

**Spectra Media Agent** is a Laravel + Inertia.js (React) multi-platform advertising management system. It deploys and manages ad campaigns across Google Ads, Facebook Ads, Microsoft Ads, and LinkedIn Ads using AI-powered agents and automation.

## Critical Architecture Rule: Management Account Pattern

**ALL ad platform integrations use a management/MCC account owned by Spectra.** This is the single most important architectural constraint in the codebase.

### What This Means

- Spectra owns a management-level account on every ad platform (Google MCC, Facebook Business Manager, Microsoft Manager Account, LinkedIn Organization).
- A single platform-level credential (refresh token or system user token) is used for ALL API calls.
- Customer ad accounts are sub-accounts created under Spectra's management account.
- The target customer account ID is specified per-request via headers or parameters.

### What You Must NEVER Do

- **NEVER** create per-customer OAuth flows (`/auth/{platform}/redirect`, `/auth/{platform}/callback`).
- **NEVER** store `access_token`, `refresh_token`, or `token_expires_at` on the `Customer` model for ad platforms.
- **NEVER** add "Connect your {Platform} Account" buttons to the UI.
- **NEVER** create `Refresh{Platform}Tokens` jobs that iterate over customer records.
- **NEVER** check `customer->{platform}_access_token` in a base service's auth method.

### What You SHOULD Do

- Store platform credentials in `.env` (fallback) or an encrypted database table (preferred, see `MccAccount` model for Google).
- In `Base{Platform}Service`, authenticate using `config('{platform}.refresh_token')` or equivalent platform-level credential.
- Set the management account as the login/manager ID on API calls, and pass the customer's sub-account ID per request.
- Only store `{platform}_customer_id` and `{platform}_account_id` on the Customer model (identifiers, not credentials).

## Reference Implementations

| Pattern | Reference File |
|---|---|
| MCC auth (gold standard) | `app/Services/GoogleAds/BaseGoogleAdsService.php` |
| System User token auth | `app/Services/FacebookAds/BaseFacebookAdsService.php` |
| Management OAuth auth | `app/Services/MicrosoftAds/BaseMicrosoftAdsService.php` |
| MCC account model | `app/Models/MccAccount.php` |
| Architecture rules | `config/platform_architecture.php` |
| Full guide | `docs/PLATFORM_ARCHITECTURE.md` |

## Tech Stack

- **Backend**: Laravel 11 (PHP 8.3)
- **Frontend**: Inertia.js + React, Tailwind CSS
- **AI**: Google Gemini API (text: gemini-3-flash-preview, images: gemini-3.1-flash-image-preview)
- **Queue**: Laravel Horizon (Redis)
- **Billing**: Stripe subscriptions + AdSpendBillingService (7-day prepay credits)
- **Deployment**: Laravel Forge

## Code Conventions

- Platform services live in `app/Services/{PlatformName}/` with a `Base{PlatformName}Service.php`.
- Execution agents live in `app/Services/Agents/{PlatformName}ExecutionAgent.php`.
- Platform configs live in `config/{platformname}.php`.
- Ad copy validation rules live in `config/platform_rules.php`.
- All new platform integrations must be added to `config/platform_architecture.php` under `platforms`.
- Cross-platform agents (HealthCheck, Optimization, SelfHealing, Creative, Audience) must support all active platforms.

## Gemini Model Policy

- NEVER use Gemini 2.x models — they are deprecated.
- Always use latest Gemini 3.x series models.
- Image generation: `gemini-3.1-flash-image-preview` (fast) or `gemini-3-pro-image-preview` (pro/4K).
- Text: `gemini-3-flash-preview`.
- Video: `veo-3.1-generate-preview`.
