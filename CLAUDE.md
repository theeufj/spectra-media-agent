# Spectra Media Agent

AI-powered advertising platform. Clients create campaigns; the system autonomously deploys
across Google, Facebook, Microsoft and LinkedIn Ads, then monitors, optimises, heals and bills.

See `README.md` for the full architecture diagram and job-by-job walkthrough.

## Tech stack

- Laravel 12 / PHP 8.4 on the server (Forge has 8.2 and 8.4 pools; local dev may be
  8.5 — `composer.json` allows ^8.2, and CI runs 8.4 to match the box that serves
  traffic). Raising this means upgrading Forge first, not editing this line.
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
  `PlatformExecutionAgent::execute()` is **final** — it is the template method that owns the
  order (boot → validate → plan → execute), the `\Throwable` catch, the `report()` call and
  the recovery-plan handoff. Subclasses supply the steps, never the flow; four divergent
  copies of it is what this replaced. `DeploymentRecoveryTest` fails if one grows back.
- Agent errors and warnings are always `AgentIssue` (a code plus a message), never a
  `string[]|array[]` union. `ExecutionResult` has no `message`/`data` — use `errors`,
  `warnings` and `metadata`, and `errorMessage()` for the single human-readable string.
- Platform config: `config/{platform}.php`. Ad-copy rules: `config/platform_rules.php`.
- New platforms must be registered in `config/platform_architecture.php` under `platforms`.
- Cross-platform agents (HealthCheck, Optimization, SelfHealing, Creative, Audience) must
  support every active platform.

## AI models

Never hardcode a model string. Always read `config('ai.models.*')`, which is env-overridable
via `AI_MODEL_*`. Cost-per-token tables and the fallback chain also live in `config/ai.php`.

This includes the **second argument** to `config()`: `config('ai.models.image', 'gemini-2.5-flash-image')`
reads as compliant and pins a two-generation-old model the moment the key is missing. That is
how twelve of them accumulated. A model named in code but absent from the pricing table also
has its spend recorded as zero.

Embeddings carry their provenance: every stored vector records the model that produced it in
`embedding_model`, because a 429 falls back to a model that embeds into a *different space* at
the same 3072 dimensions. Vector search only compares within one space;
`php artisan embeddings:refresh --mismatched` rebuilds the rest.

Prefer the Gemini 3.x series for text. Creative generation defaults to Grok Imagine via
OpenRouter (`ai.image_provider` / `ai.video_provider` = 'grok', chosen in a 2026-08-24
side-by-side shootout; needs `OPENROUTER_API_KEY`), with automatic fallback to the Google
stack. Seeded/reference image work always uses Gemini `gemini-3.1-flash-image`
(Nano Banana 2). Two 3.x-specific requirements live in `GeminiService`: image requests
must send an explicit `imageConfig.aspect_ratio` (3.x does not default to square the way
2.5 did), and inline reference images are downscaled before sending (multi-MB payloads
get an HTML 417 from Google's anti-abuse layer). Veo-path videos chain 8s extensions to
cover their script and are re-voiced with one Gemini TTS pass (Veo regenerates its
narrator per call); Grok videos are single-pass with native audio, so neither step runs
for them.

## Money

- Money is stored as integer cents (`plans.price_cents` is the reference pattern) or
  `decimal(12,2)`. Never `float` — cross-platform `SUM()` over floats loses precision.
- Any path that mutates a balance and writes a ledger row must be inside a single
  `DB::transaction` with `lockForUpdate()` on the balance row.
- Charges to Stripe need a deterministic idempotency key.

## Authorization

Ownership is via the `customers` pivot on `User`, **not** a `customers.user_id` column
(that column was dropped). It is enforced structurally, in two layers:

1. **`App\Models\Concerns\BelongsToCustomer`** puts `CustomerScope` on the model, so a query
   for another tenant's rows returns nothing. The scope engages only when a non-admin user is
   authenticated — queue workers and scheduled commands have no acting user, so the nightly
   batch jobs that iterate every customer are unaffected, and admins read across tenants by
   design. Deliberate cross-tenant reads say so: `Model::withoutCustomerScope()`.
2. **A policy extending `App\Policies\CustomerOwnedPolicy`**, registered in
   `AppServiceProvider`. Use `$this->authorize()` or `->middleware('can:...')`; do not
   hand-roll the pivot query.

`AuthorizationCoverageTest` fails if a customer-owned model has no policy, is missing the
scope, or is bound from an unauthenticated route.

A model whose `customer_id` is legitimately optional must **not** carry the trait — a NULL
never matches `IN`, so the row would vanish for everyone. `SupportTicket` (owned by `user_id`)
and `CreativeUsage` (which writes `customer_id` NULL mid-onboarding) are the two exceptions.

Cross-tenant access returns **404, not 403**: route-model binding cannot find the row. That is
the stronger answer — a 403 confirms the ID exists.

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
npm test                   # frontend (vitest) — resources/js/tests/
```

Don't add to `phpstan-baseline.neon` — it's there to freeze existing debt, not to
absorb new debt. Regenerate it only when a refactor moves existing errors between
files. CI runs all four of the above plus `bin/check-fatal-classes` and
`bin/check-baseline-growth`, on PHP 8.4 (matching the Forge server).

Several rules in this file are now tests rather than requests. If one of these fails,
the fix is the code, not the test:

| Rule | Enforced by |
|---|---|
| No model name outside `config/ai.php` | `NoHardcodedModelsTest` |
| Every customer-owned model is scoped and has a policy | `AuthorizationCoverageTest` |
| No agent reimplements `execute()` | `DeploymentRecoveryTest` |
| Nothing substantial runs in the scheduler tick | `ScheduledFanOutJobsTest` |
| Every project env key is in `.env.example` | `EnvExampleCoverageTest` |

The full suite runs locally again (`php artisan test`, ~90s, integration
suites self-skip without their RUN_*_INTEGRATION_TESTS flags). The old hang
(live HTTP from `AdSpendCreditTest`/`GeminiServiceTest`/`ProductModelTest`/
`ProductFeedModelTest`) was cured by the base TestCase's global
`Queue::fake()` + `Http::preventStrayRequests()`. Keep new tests on
`DatabaseTransactions` (the test DB is pre-migrated) and fake any HTTP.

## Deployment

Push to `master` auto-deploys via Forge. Never `git pull` manually on the server.
