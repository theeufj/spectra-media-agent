# Project audit changes

## Creative generation

- Strategy, image and video prompts now ask for offer-specific concepts, product demonstrations, useful benefits and deliberate art direction. Generic stressed-owner scenes are no longer the default.
- Campaign and individual-strategy sign-off use one collateral plan. Explicit video eligibility takes precedence over prose. Each image slot generates one concept; video reservations distinguish concepts and enforce plan allowance atomically.
- Full strategy responses validate before an atomic replacement. A failed generation retains the previous reviewed strategy and assets. Successful replacement cleans up generated files after commit, while preserving customer uploads.
- Images use clean, headline or light signature treatments. Search assets stay clean; the fixed dark footer has been removed. Layout composition lives in its own service.
- New assets retain generation ID, concept, prompt fingerprint, provider/model and reference information. A fixed six-business creative benchmark checks a review of actual rendered files. This does not claim that the new creative has already been visually approved or has improved campaign performance.

## Money and recovery

- Budget writers share platform allocation, cent-based rounding, an approved envelope and persistent billing reductions. A neutral hourly multiplier restores the base. Automatic inter-campaign transfers remain review recommendations because remote platform writes cannot be atomic.
- Budget/bidding cooldowns use canonical recommendation types and timestamps of actual changes, separate from analysis timestamps.
- Initial account and ledger writes commit together, with one account per customer enforced by the database. Stripe payment attempts retain durable intent IDs; uncertain old attempts fail closed.
- Billing records use the account's spend date, owned expiring leases, attempts and completion state. Technical failures retry and remain available for catch-up. Declined-payment days remain outstanding with a retry delay. Reconciliation matches the day billed rather than the date a catch-up entry was posted.

## Crawler and maintainability

- Shared HTTP fetching validates every redirect, rejects non-public destinations and pins DNS answers to the connection. Website HTML, stylesheets, sitemaps and harvested images use it.
- Untrusted page JavaScript no longer runs in the application worker. The optional renderer has an internal-only network and a public-address proxy. Without it, the application uses safe HTTP without JavaScript rendering.
- Extracted budget policy, collateral planning, strategy validation, image composition, creative polling and wizard presentation/configuration. React hook-order checks are enabled globally and dependency checks on hooks. Creative polling validates response shape; budget previews use explicit cents.

## Verification and production follow-through

The automated suites, static analysis, frontend build, proxy checks and exact-commit gate tests verify the local implementation. The backup verification tool successfully restored a copy of the local test database and checked its ledger, then removed the temporary database.

Production remains separate: configure Forge's exact-commit CI gate, deploy and smoke-test the isolated renderer, review actual generated creative against the benchmark, and restore an actual production backup on an isolated host. Docker was unavailable locally, so container runtime behaviour is not yet verified. Legacy billing claims and any pre-existing incomplete funding ledger require reconciliation against actual spend and Stripe records; no financial history was invented or silently rewritten. See [rollout instructions](rollout.md).
