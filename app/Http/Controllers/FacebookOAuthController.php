<?php

namespace App\Http\Controllers;

/**
 * @deprecated This controller is no longer used.
 *
 * Facebook Ads now uses the management account pattern exclusively.
 * All API calls use the platform System User token from
 * config('services.facebook.system_user_token').
 *
 * Ad accounts are assigned via FacebookAdAccountController (admin only).
 * No per-customer OAuth flow exists.
 *
 * @see \App\Http\Controllers\FacebookAdAccountController
 * @see config/platform_architecture.php
 */
class FacebookOAuthController extends Controller
{
    // Intentionally empty — per-customer OAuth is prohibited.
    // See config/platform_architecture.php for architecture rules.
}
