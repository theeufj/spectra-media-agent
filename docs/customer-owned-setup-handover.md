# One-time setup: account access and tracking

The US$999 flow separates campaign creation, account ownership and readiness to advertise. Creating paused ads, sending an invitation or finding a website snippet cannot complete all three.

## Customer workflow

1. The customer confirms their Google identity from **Account access & tracking**. This uses the existing Google sign-in callback with identity-only scopes; it does not switch their SiteToSpend login or store customer API credentials.
2. After payment and child-account creation, the platform MCC invites that confirmed identity to the child's Google Ads account. `customer_user_access` with `ADMIN` proves acceptance. An invitation or multi-party review request remains pending.
3. The customer selects an existing web container in a Tag Manager account they administer. They grant the platform tracking identity account Administrator and selected-container Publish access so we can verify ownership and publish approved configuration. Google does not provide a Tag Manager account-creation API; customers without an account create it in Google first.
4. The existing snippet is reused if it matches the selected container. Selecting a different container invalidates the old website-tag configuration for review. Detection does not automatically assign a container to a customer, even when the domain matches.
5. Configure the campaign conversion action/trigger, verify snippet installation and test the intended event with Tag Assistant. Billing and activation happen separately in Google Ads.

The same container can serve later campaigns for the same customer. A shared platform container or another customer's container cannot be silently adopted. Legacy installations are preserved until a customer explicitly chooses their own container; do not replace a live snippet just because another campaign was created.

`handover_at` now requires verified campaign creation, accepted Ads administrator access and a fresh customer GTM ownership check. It does not claim a website event test or billing verification. Legacy timestamps alone are not sufficient evidence. Pending handovers are rechecked by queued jobs every fifteen minutes; no ads are activated by this workflow.

## Platform authorization

Open `/settings/google-api` as a full administrator and use **Authorize platform tracking**. Choose an administrator of `GTM_PLATFORM_ACCOUNT_ID`, then approve the listed GTM scopes, including `tagmanager.manage.users`. The callback verifies the granted scopes, Google identity and administrator membership before saving one encrypted `Connection` record with platform `gtm_platform`. Runtime GTM calls use that platform credential for all customer accounts to which it has access. Google Ads MCC credentials are unchanged.

`GTM_PLATFORM_REFRESH_TOKEN` and `GTM_PLATFORM_EMAIL` remain fallbacks. An email guessed from an application's login or an unverified token is not ownership proof. Failed/partial authorization keeps the existing credential.

## Independence and limits

Published website tags send to conversion actions in the customer's Google Ads child account. Removing SiteToSpend's access does not remove those published tags or the customer's own action. It prevents SiteToSpend from reading, updating or verifying the accounts. SiteToSpend-hosted upload/attribution features are separate and must not be described as independent customer-owned tracking.

A Tag Manager ownership check is not proof that an event fires, and zero attributed conversions do not alone prove tracking is broken. The product does not tick off an event test from a manually selected checkbox.
