<?php

namespace Tests\Feature\LinkedInAds;

use Tests\TestCase;

/**
 * @deprecated Per-customer LinkedIn OAuth has been removed.
 *
 * LinkedIn Ads uses the management account pattern — all API calls use the
 * platform-level refresh token from config('linkedinads.refresh_token').
 *
 * @see config/platform_architecture.php
 */
class LinkedInAdsOAuthTest extends TestCase
{
    public function test_management_pattern_is_enforced(): void
    {
        // Verify no per-customer OAuth routes exist
        $this->assertFalse(
            \Illuminate\Support\Facades\Route::has('linkedin-ads.redirect'),
            'Per-customer LinkedIn OAuth redirect route should not exist'
        );
        $this->assertFalse(
            \Illuminate\Support\Facades\Route::has('linkedin-ads.callback'),
            'Per-customer LinkedIn OAuth callback route should not exist'
        );
    }
}
