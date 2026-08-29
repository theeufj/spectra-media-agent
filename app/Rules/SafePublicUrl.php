<?php

namespace App\Rules;

use App\Support\Url;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The URL must point at a publicly routable host. Everything a user submits
 * here gets fetched BY OUR SERVERS (crawler, Browsershot, GTM verification)
 * — without this, "your website" could be the cloud metadata endpoint or an
 * internal service. See Url::isSafePublicHost.
 */
class SafePublicUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! Url::isSafePublicHost(Url::forceHttps(is_string($value) ? $value : null))) {
            $fail('That address doesn\'t look like a reachable public website.');
        }
    }
}
