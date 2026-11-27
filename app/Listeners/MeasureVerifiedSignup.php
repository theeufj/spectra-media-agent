<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\SiteSignupConversionService;
use Illuminate\Auth\Events\Verified;

class MeasureVerifiedSignup
{
    public function handle(Verified $event): void
    {
        if ($event->user instanceof User) {
            app(\App\Services\SignupFunnel::class)->verified(request(), $event->user);
            app(SiteSignupConversionService::class)->emailVerified($event->user);
        }
    }
}
