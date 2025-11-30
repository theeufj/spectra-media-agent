<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CloudflareTurnstile implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Skip validation if Turnstile is not configured
        $secretKey = config('services.cloudflare.turnstile_secret_key');
        
        if (empty($secretKey)) {
            Log::warning('Cloudflare Turnstile secret key not configured, skipping validation');
            return;
        }

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

            if (!($result['success'] ?? false)) {
                Log::warning('Cloudflare Turnstile validation failed', [
                    'error_codes' => $result['error-codes'] ?? [],
                    'ip' => request()->ip(),
                ]);
                $fail('Security verification failed. Please try again.');
            }
        } catch (\Exception $e) {
            Log::error('Cloudflare Turnstile verification error', [
                'error' => $e->getMessage(),
            ]);
            // Don't block the user if Turnstile service is down
            // You can change this to $fail() if you want strict enforcement
        }
    }
}
