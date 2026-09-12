<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CloudflareTurnstile implements ValidationRule
{
    /**
     * Whether bot protection is switched on.
     *
     * BOTH keys, deliberately. The widget used to render off the site key while
     * the validation rules keyed off the secret, so setting one without the
     * other produced a signup form that either challenged nobody or demanded a
     * token no widget existed to mint — the second of which fails closed on an
     * unauthenticated endpoint, with an error the visitor cannot resolve.
     *
     * This is also the off switch: clear either key and registration and login
     * stop asking. There is no separate feature flag to keep in sync.
     *
     * Note for whoever is debugging a signup that will not go through on a new
     * domain — a Turnstile widget only renders on hostnames listed in the
     * widget's allowlist in the Cloudflare dashboard. A tenant skin served from
     * a new domain therefore fails here until that domain (and its www. form)
     * is added. `php artisan tenant:check` lists it as a launch step.
     */
    public static function enabled(): bool
    {
        return filled(config('services.cloudflare.turnstile_site_key'))
            && filled(config('services.cloudflare.turnstile_secret_key'));
    }

    /**
     * Run the validation rule.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::enabled()) {
            Log::warning('Cloudflare Turnstile is not fully configured, skipping validation');

            return;
        }

        $secretKey = config('services.cloudflare.turnstile_secret_key');

        if (empty($value)) {
            $fail('Please complete the security verification.');

            return;
        }

        try {
            $response = Http::asForm()->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => $secretKey,
                'response' => $value,
                'remoteip' => request()->ip(),
            ]);

            $result = $response->json();

            if (! ($result['success'] ?? false)) {
                Log::warning('Cloudflare Turnstile validation failed', [
                    'error_codes' => $result['error-codes'] ?? [],
                    'ip' => request()->ip(),
                ]);
                $fail('Security verification failed. Please try again.');
            }
        } catch (\Throwable $e) {
            report($e);
            Log::error('Cloudflare Turnstile verification error', [
                'error' => $e->getMessage(),
            ]);
            // Don't block the user if Turnstile service is down
            // You can change this to $fail() if you want strict enforcement
        }
    }
}
