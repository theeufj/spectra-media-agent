# Launching a new tenant skin

Everything a vertical skin (like realpropertyads.com) needs before real
signups. Each item here broke, silently, during the Real Property Ads
launch — hence the list. Run `php artisan tenant:check <domain>` to verify
the automatable parts.

## 1. Code / config (deployable)

- [ ] Add the domain to `config/tenants.php`: `key`, `name`, `tagline`,
      `vertical` (+ `locked_vertical`), `colors` (primary/dark/darker/accent),
      `email_from`, `logo_text`.
- [ ] Point the domain at the Forge server and add it to the site's
      domains + SSL certificate.
- [ ] Nothing else in code: titles, SEO fallbacks, emails, CSS, sitemap and
      robots all derive from the tenant config at runtime.

## 2. Third-party consoles (manual — the silent breakers)

- [ ] **Cloudflare Turnstile** — add the domain (and `www.`) to the
      hostname allowlist of the widget whose site key is
      `CLOUDFLARE_TURNSTILE_SITE_KEY`. Without this, registration is
      impossible on the new domain (error 110200; the token never issues).
- [ ] **Google OAuth** — add `https://<domain>/auth/google/callback` to the
      OAuth client's authorized redirect URIs. Until then, "Sign up with
      Google" bounces through the canonical domain and lands the user on
      the wrong brand.
- [ ] **Facebook OAuth** — same, for the Facebook app's redirect URIs.
- [ ] **Resend** — verify the domain so `email_from` can actually send.
      Until verified, mail goes out from the global `MAIL_FROM_ADDRESS`.

## 3. Verify

- [ ] `php artisan tenant:check <domain>` passes.
- [ ] Register a test account ON the new domain: the tab title, colors,
      verification email (branding AND link domain), and welcome email all
      wear the skin.
- [ ] Complete quick-start: the created customer's `tenant_key` matches,
      and the scan holding screen runs through to brand-guidelines review.
- [ ] Clean up the test account afterwards — and remember test signups no
      longer create Google Ads sub-accounts (provisioning fires at budget
      confirmation), so there is nothing to cancel in the MCC.
