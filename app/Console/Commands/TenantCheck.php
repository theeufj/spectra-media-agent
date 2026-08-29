<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Launch checklist for a tenant skin. The realpropertyads.com launch failed
 * three separate ways that nothing checked for — the Turnstile hostname
 * allowlist, the OAuth redirect URIs, and a build-time app name — so every
 * future skin gets a runnable checklist instead of rediscovery.
 */
class TenantCheck extends Command
{
    protected $signature = 'tenant:check {domain? : Tenant domain (default: every non-default tenant)}';

    protected $description = 'Verify a tenant skin is fully launched: config, live pages, sitemap, plus the manual items (Turnstile, OAuth, Resend)';

    public function handle(): int
    {
        $tenants = collect(config('tenants'))
            ->filter(fn ($v, $k) => is_array($v) && $k !== config('tenants.default'))
            ->when($this->argument('domain'), fn ($c) => $c->only([$this->argument('domain')]));

        if ($tenants->isEmpty()) {
            $this->error('No matching tenant in config/tenants.php — add the domain there first.');

            return self::FAILURE;
        }

        $failed = false;

        foreach ($tenants as $domain => $config) {
            $this->info("── {$domain}");

            $failed = ! $this->checkConfig($config) || $failed;
            $failed = ! $this->checkRegisterPage($domain, $config) || $failed;
            $failed = ! $this->checkSitemap($domain) || $failed;

            $this->line('  MANUAL — verify these by hand:');
            $this->line('    • Cloudflare Turnstile: add '.$domain.' (and www.) to the widget\'s hostname allowlist');
            $this->line('    • Google/Facebook OAuth: register https://'.$domain.'/auth/{provider}/callback redirect URIs');
            $this->line('    • Resend: verify '.$domain.' so '.($config['email_from'] ?? 'the from-address').' can send');
            $this->newLine();
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function checkConfig(array $config): bool
    {
        $missing = array_filter(
            ['key', 'name', 'colors', 'email_from', 'logo_text'],
            fn ($field) => empty($config[$field])
        );

        if ($missing) {
            $this->error('  ✗ config incomplete: missing '.implode(', ', $missing));

            return false;
        }

        $this->line('  ✓ config complete (key: '.$config['key'].')');

        return true;
    }

    private function checkRegisterPage(string $domain, array $config): bool
    {
        try {
            $response = Http::timeout(10)->get("https://{$domain}/register");
        } catch (\Throwable $e) {
            $this->error("  ✗ register page unreachable: {$e->getMessage()}");

            return false;
        }

        if (! $response->successful()) {
            $this->error('  ✗ register page returned '.$response->status());

            return false;
        }

        $ok = true;

        if (! str_contains($response->body(), $config['name'])) {
            $this->error("  ✗ register page does not mention \"{$config['name']}\" — tenant detection may be off");
            $ok = false;
        } else {
            $this->line('  ✓ register page live and wearing the skin');
        }

        if (! str_contains($response->body(), 'turnstileSiteKey')) {
            $this->warn('  ! no Turnstile site key in the page props — registration may be unprotected');
        }

        return $ok;
    }

    private function checkSitemap(string $domain): bool
    {
        try {
            $body = Http::timeout(10)->get("https://{$domain}/sitemap.xml")->body();
        } catch (\Throwable $e) {
            $this->error("  ✗ sitemap unreachable: {$e->getMessage()}");

            return false;
        }

        if (str_contains($body, config('tenants.default', 'sitetospend.com')) && ! str_contains($body, $domain)) {
            $this->error('  ✗ sitemap still advertises the default domain');

            return false;
        }

        $this->line('  ✓ sitemap speaks '.$domain);

        return true;
    }
}
