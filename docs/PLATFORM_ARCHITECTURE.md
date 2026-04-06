# Platform Architecture Guide

## Core Principle: Management Account Model

**Every ad platform integration MUST use a management/MCC account owned by Spectra.** Customers never bring their own ad accounts and never go through an OAuth flow for ad platforms.

Spectra owns and manages all ad spend across every platform. Individual customer ad accounts are created **under** the Spectra management account. A single platform-level credential (refresh token / system user token) is used for all API calls, with the target customer account specified per-request.

### Why This Pattern?

1. **Unified billing** — All spend flows through Spectra's accounts, simplifying reconciliation and markup.
2. **Zero customer friction** — No OAuth consent screens, no "connect your ad account" flows.
3. **Full control** — Spectra can pause, resume, and manage all campaigns without relying on customer-granted permissions.
4. **Token reliability** — A single credential to maintain per platform, no per-customer token refresh jobs.
5. **Security** — Fewer credentials stored, smaller attack surface.

---

## Architecture Pattern

```
┌────────────────────────────────────────────────────────┐
│                    Spectra Platform                     │
│                                                        │
│  .env / mcc_accounts table                             │
│  ┌──────────────────────────────────────┐              │
│  │  Platform Management Credential      │              │
│  │  (refresh_token or system_user_token)│              │
│  └──────────────┬───────────────────────┘              │
│                 │                                       │
│  ┌──────────────▼───────────────────────┐              │
│  │  BasePlatformService                 │              │
│  │  - Authenticates with mgmt credential│              │
│  │  - Sets loginCustomerId / mgr header │              │
│  │  - Routes to customer sub-account    │              │
│  └──────────────┬───────────────────────┘              │
│                 │                                       │
│       ┌─────────┼─────────┐                            │
│       ▼         ▼         ▼                            │
│   Customer A  Customer B  Customer C                   │
│   (sub-acct)  (sub-acct)  (sub-acct)                   │
└────────────────────────────────────────────────────────┘
```

---

## Current Implementations (Reference)

### Google Ads ✅ (Gold Standard)

| Component | Detail |
|---|---|
| **Management account** | MCC (Manager Customer Center) |
| **Credential storage** | `mcc_accounts` table (encrypted `refresh_token`) with `.env` fallback |
| **Auth flow** | `MccAccount::getActive()` → decrypt token → OAuth2 → `GoogleAdsClient` |
| **Per-request routing** | `withLoginCustomerId(mccId)` + target `customerId` on each API call |
| **SDK** | Official `googleads/google-ads-php` SDK (gRPC/protobuf) |
| **Config** | `config/googleads.php` — `mcc_customer_id`, `mcc_refresh_token`, INI path |
| **Customer fields** | `google_ads_customer_id`, `google_ads_manager_customer_id` |
| **Sub-account creation** | `MCCAccountManager::createStandardAccountUnderMCC()` |

### Facebook Ads ✅

| Component | Detail |
|---|---|
| **Management account** | Business Manager with System User |
| **Credential storage** | `.env` only (`FACEBOOK_SYSTEM_USER_TOKEN`) |
| **Auth flow** | `config('services.facebook.system_user_token')` — no OAuth, no refresh |
| **Per-request routing** | Customer's `facebook_ads_account_id` in API URL |
| **SDK** | Direct HTTP to Graph API v22.0 |
| **Config** | `config/services.php` `facebook` key — `business_manager_id`, `system_user_token` |
| **Customer fields** | `facebook_ads_account_id`, `facebook_bm_owned` |
| **Sub-account creation** | `CreateFacebookAdsAccount` service |

### Microsoft Ads ✅

| Component | Detail |
|---|---|
| **Management account** | Manager Account (equivalent to Google MCC) |
| **Credential storage** | `config/microsoftads.php` via `.env` (`MICROSOFT_ADS_REFRESH_TOKEN`) |
| **Auth flow** | Config refresh token → OAuth2 token exchange → Bearer token |
| **Per-request routing** | `CustomerId` + `CustomerAccountId` headers per request |
| **SDK** | REST JSON API v13 |
| **Config** | `config/microsoftads.php` — `manager_account_id`, `refresh_token`, `developer_token` |
| **Customer fields** | `microsoft_ads_customer_id`, `microsoft_ads_account_id` |

### LinkedIn Ads

| Component | Detail |
|---|---|
| **Management account** | Organization with ad account access |
| **Credential storage** | `config/linkedinads.php` via `.env` (`LINKEDIN_ADS_REFRESH_TOKEN`) |
| **Auth flow** | Config refresh token → OAuth2 token exchange → Bearer token |
| **Per-request routing** | Customer's `linkedin_ads_account_id` in API URL |
| **SDK** | REST with LinkedIn-Version header |
| **Config** | `config/linkedinads.php` — `client_id`, `client_secret`, `refresh_token` |
| **Customer fields** | `linkedin_ads_account_id` |

---

## Adding a New Platform

Follow this checklist when integrating a new ad platform.

### 1. Configuration (`config/{platform}.php`)

```php
return [
    // Management account credentials — from .env, NEVER hardcoded
    'client_id'          => env('{PLATFORM}_CLIENT_ID'),
    'client_secret'      => env('{PLATFORM}_CLIENT_SECRET'),
    'refresh_token'      => env('{PLATFORM}_REFRESH_TOKEN'),
    'manager_account_id' => env('{PLATFORM}_MANAGER_ACCOUNT_ID'),
    'developer_token'    => env('{PLATFORM}_DEVELOPER_TOKEN'),  // if applicable

    // API settings
    'environment' => env('{PLATFORM}_ENVIRONMENT', 'production'),

    // Rate limiting
    'rate_limit' => [
        'requests_per_minute' => 100,
        'retry_attempts'      => 3,
    ],
];
```

### 2. Base Service (`app/Services/{Platform}/Base{Platform}Service.php`)

Must follow this pattern:

```php
class BasePlatformService
{
    protected $customer;
    protected $accessToken;

    public function __construct(?Customer $customer = null)
    {
        $this->customer = $customer;
    }

    // Authenticate using PLATFORM-LEVEL management credential only.
    // NEVER use per-customer OAuth tokens.
    protected function authenticate(): void
    {
        $refreshToken = config('{platform}.refresh_token');
        // Exchange for access token via platform's OAuth endpoint
        // Store in $this->accessToken
    }

    // All API calls include the customer's sub-account ID
    protected function apiCall(string $method, string $endpoint, array $data = []): array
    {
        $this->authenticate();

        return Http::withHeaders([
            'Authorization'    => "Bearer {$this->accessToken}",
            'CustomerId'       => $this->customer->platform_customer_id,
            'CustomerAccountId'=> $this->customer->platform_account_id,
        ])->$method($endpoint, $data)->json();
    }
}
```

### 3. Customer Model Fields

Add to `customers` migration:
- `{platform}_customer_id` — the sub-account's customer ID
- `{platform}_account_id` — the sub-account's ad account ID

**Do NOT add** `{platform}_access_token`, `{platform}_refresh_token`, or `{platform}_token_expires_at` to the Customer model. Credentials live at the platform level.

### 4. Service Classes

Minimum set for any platform:

| Service | Purpose |
|---|---|
| `CampaignService` | Create, update, pause, resume campaigns |
| `AdGroupService` | Manage ad groups/sets |
| `PerformanceService` | Fetch metrics (impressions, clicks, cost, conversions) |

Optional but recommended:
| Service | Purpose |
|---|---|
| `ImportService` | Import campaigns from other platforms |
| `AccountService` | Create sub-accounts under management account |

### 5. Execution Agent (`app/Services/Agents/{Platform}ExecutionAgent.php`)

Implements `PlatformExecutionAgent` — receives an `ExecutionContext` and deploys campaigns using the platform services.

### 6. Platform Rules (`config/platform_rules.php`)

Add ad copy validation rules (headline/description lengths, counts, exclamation rules).

### 7. Scheduler Jobs

| Job | Schedule | Purpose |
|---|---|---|
| `Fetch{Platform}PerformanceData` | Hourly | Ingest metrics for all active campaigns |

### 8. Agent Parity

Update these cross-platform agents to support the new platform:
- `MonitorCampaignStatus` — status checks
- `HealthCheckAgent` — health monitoring
- `CampaignOptimizationAgent` — optimization metrics
- `SelfHealingAgent` — autonomous issue repair
- `CreativeIntelligenceAgent` — creative analysis
- `AudienceIntelligenceAgent` — audience management
- `AdSpendBillingService` — spend tracking and billing

### 9. DeploymentService

Add platform routing in `DeploymentService::normalizePlatform()`.

---

## Anti-Patterns (DO NOT)

- **DO NOT** create per-customer OAuth flows (no `/auth/{platform}/redirect` routes).
- **DO NOT** store access/refresh tokens on the Customer model.
- **DO NOT** add "Connect your {Platform} Account" buttons to the UI.
- **DO NOT** create `Refresh{Platform}Tokens` jobs that iterate customers.
- **DO NOT** let customers bring their own ad accounts from outside Spectra's management.
