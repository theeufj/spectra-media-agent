# Spectra Media Agent

AI-powered advertising platform. Clients create campaigns; the system autonomously deploys
across Google, Facebook, Microsoft and LinkedIn Ads, then monitors, optimises, heals and bills.

See `README.md` for the full architecture diagram and job-by-job walkthrough.

## Tech stack

- Laravel 12 / PHP 8.5
- Inertia.js + React 18, Tailwind
- Postgres (with `pgvector` for embeddings)
- Horizon (Redis) for queues
- Stripe via Cashier — subscriptions + `AdSpendBillingService` (7-day prepay credits)
- Deployed by Laravel Forge

## Critical architecture rule: the management-account pattern

**Every ad platform integration uses a management/MCC account owned by Spectra.** This is the
single most important constraint in the codebase.

- One platform-level credential (refresh token or system-user token) serves all API calls.
- Customer ad accounts are sub-accounts under Spectra's management account.
- The target customer account is passed per-request via header/parameter.

Never do:

- Per-customer OAuth flows (`/auth/{platform}/redirect`, `/auth/{platform}/callback`).
- Store `access_token` / `refresh_token` / `token_expires_at` on `Customer` for an ad platform.
- "Connect your {Platform} Account" buttons in the UI.
- `Refresh{Platform}Tokens` jobs that iterate customer records.
- Check `customer->{platform}_access_token` in a base service's auth method.

Do:

- Keep platform credentials in `.env` (fallback) or an encrypted table (preferred — see `MccAccount`).
- Authenticate in `Base{Platform}Service` from `config('{platform}.refresh_token')` or equivalent.
- Set the management account as the login/manager ID; pass the customer sub-account ID per request.
- Store only identifiers on `Customer` (`{platform}_customer_id`, `{platform}_account_id`).

| Pattern | Reference file |
|---|---|
| MCC auth (gold standard) | `app/Services/GoogleAds/BaseGoogleAdsService.php` |
| System-user token auth | `app/Services/FacebookAds/BaseFacebookAdsService.php` |
| Management OAuth auth | `app/Services/MicrosoftAds/BaseMicrosoftAdsService.php` |
| MCC account model | `app/Models/MccAccount.php` |
| Architecture rules | `config/platform_architecture.php` |

## Layout conventions

- Platform services: `app/Services/{Platform}/` with a `Base{Platform}Service.php`.
- Execution agents: `app/Services/Agents/{Platform}ExecutionAgent.php`, extending
  `PlatformExecutionAgent` and returning `ExecutionResult` (never throwing for partial failure).
- Platform config: `config/{platform}.php`. Ad-copy rules: `config/platform_rules.php`.
- New platforms must be registered in `config/platform_architecture.php` under `platforms`.
- Cross-platform agents (HealthCheck, Optimization, SelfHealing, Creative, Audience) must
  support every active platform.

## AI models

Never hardcode a model string. Always read `config('ai.models.*')`, which is env-overridable
via `AI_MODEL_*`. Cost-per-token tables and the fallback chain also live in `config/ai.php`.

Prefer the Gemini 3.x series. `models.image` is `gemini-3.1-flash-image` (Nano Banana 2),
validated end to end 2026-08-24. Two 3.x-specific requirements live in `GeminiService`:
image requests must send an explicit `imageConfig.aspect_ratio` (3.x does not default to
square the way 2.5 did), and inline reference images are downscaled before sending
(multi-MB payloads get an HTML 417 from Google's anti-abuse layer, not an API error).
Keep both if you change the image model again.

## Money

- Money is stored as integer cents (`plans.price_cents` is the reference pattern) or
  `decimal(12,2)`. Never `float` — cross-platform `SUM()` over floats loses precision.
- Any path that mutates a balance and writes a ledger row must be inside a single
  `DB::transaction` with `lockForUpdate()` on the balance row.
- Charges to Stripe need a deterministic idempotency key.

## Authorization

Ownership is via the `customers` pivot on `User`, **not** a `customers.user_id` column
(that column was dropped). The correct check is a policy; if you must inline it:

```php
$user->customers()->where('customers.id', $customer->id)->exists()
```

Prefer `$this->authorize()` / `->middleware('can:...')` over hand-rolled checks. Policies live
in `app/Policies`.

## Error handling

Batch jobs iterate over customers/campaigns and guard each item with its own
try/catch so one bad record doesn't abort the run. That is the right shape — keep it.

The thing to remember: `Log::error()` alone does **not** reach the admin exception
dashboard. `runtime_exceptions` is fed by `Queue::failing` (whole-job failure) and
by Laravel's report handler. A per-item catch that only logs is invisible there, so
campaigns can fail silently every night. In money and deploy paths, call `report($e)`
alongside the log:

```php
} catch (\Throwable $e) {
    report($e);            // surfaces in the admin dashboard
    Log::error('...');     // keeps the batch running
}
```

Catch `\Throwable`, not `\Exception`, in defensive blocks — `\Exception` does not
catch `\Error`, so `TypeError` / `ArgumentCountError` / missing-class bugs sail
straight past a handler that was meant to contain them.

Don't wrap a whole `handle()` in a swallowing try/catch: the queue then records
success and never retries.

## Frontend

Use Inertia's `router` / `useForm` for anything that navigates or submits a form.
For the JSON endpoints that sit alongside Inertia:

- `@/utils/http` — `fetchJson(url, {method, json})`. Adds Accept + CSRF, throws
  `HttpError` on non-2xx. Don't re-read the CSRF meta tag by hand.
- `@/hooks/usePolling` — `usePolling(url, {interval, enabled, until})`. Handles
  cleanup on unmount and skips overlapping requests while one is in flight.

## Before you commit

```bash
vendor/bin/pint            # format
vendor/bin/phpstan analyse # static analysis against the baseline
php artisan test
```

Don't add to `phpstan-baseline.neon` — it's there to freeze existing debt, not to
absorb new debt. Regenerate it only when a refactor moves existing errors between
files.

Note on the test suite: several tests make live outbound HTTP calls and hang
indefinitely (`AdSpendCreditTest`, `GeminiServiceTest`, `ProductModelTest`,
`ProductFeedModelTest`). This predates the current work. `DatabaseTransactions`
works fine; it's `RefreshDatabase` plus unmocked HTTP that hangs — prefer the
former in new tests, and fake HTTP.

## Deployment

Push to `master` auto-deploys via Forge. Never `git pull` manually on the server.
