<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

class SignupFunnel
{
    public const CLIENT_STAGES = ['landing_visible', 'preview_started', 'preview_submitted', 'preview_succeeded', 'preview_failed', 'save_clicked', 'signup_cta_clicked', 'signup_started', 'signup_failed'];

    public const CLIENT_REASONS = ['validation', 'rate_limited', 'timeout', 'network', 'unavailable', 'oauth_cancelled', 'unknown'];

    private const SESSION_KEY = 'sitetospend_signup_journey';

    public function ownHost(Request $request): bool
    {
        return in_array(strtolower($request->getHost()), ['sitetospend.com', 'www.sitetospend.com'], true)
            && data_get($request->attributes->get('tenant'), 'key') === 'sitetospend';
    }

    public function optedOut(Request $request): bool
    {
        return $request->header('Sec-GPC') === '1' || $request->header('DNT') === '1';
    }

    public function suppress(Request $request): void
    {
        if (! $this->ownHost($request) || ! $request->hasSession()) {
            return;
        }
        $id = $request->session()->get(self::SESSION_KEY);
        $request->session()->put(self::SESSION_KEY, false);
        $user = $request->user();
        $pending = $user instanceof User && $user->tenant_key === 'sitetospend' ? $user->signup_funnel_journey_id : null;
        $ids = array_filter([$id, $pending], static fn ($id) => is_string($id) && Str::isUuid($id));
        if ($ids) {
            $this->observe(fn () => DB::table('signup_funnel_journeys')->whereIn('id', $ids)->delete());
        }
    }

    public function page(Request $request, string $stage): void
    {
        if (! $this->allowed($request) || $request->user() || ! in_array($stage, ['landing_view', 'signup_viewed'], true)) {
            return;
        }
        $this->observe(function () use ($request, $stage) {
            $id = $this->sessionId($request);
            if ($id === null) {
                // A shared NAT can produce many real visitors; the limiter only caps
                // creation of new diagnostic cohorts. It never blocks the public page.
                $key = 'signup-funnel-entries:'.hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'));
                if (RateLimiter::tooManyAttempts($key, 120)) {
                    return;
                }
                RateLimiter::hit($key, 60);
                // Tie concurrent first renders to the same random web session
                // without storing its identifier or exposing a reusable login token.
                $opaque = hash_hmac('sha256', 'sitetospend/signup-funnel:'.$request->session()->getId(), (string) config('app.key'));
                $id = Uuid::uuid5(Uuid::NAMESPACE_URL, $opaque)->toString();
                [$source, $campaign] = $this->acquisition($request);
                DB::table('signup_funnel_journeys')->insertOrIgnore(['id' => $id, 'source' => $source, 'campaign_key' => $campaign, 'entry_surface' => $this->entrySurface($request), 'started_at' => now()->toIso8601String()]);
                $this->write($id, $stage);
                $request->session()->put(self::SESSION_KEY, $id);

                return;
            }
            if (DB::table('signup_funnel_journeys')->where('id', $id)->exists()) {
                $this->write($id, $stage);
            } else {
                // The old session can still be in flight after opt-out or pruning.
                $request->session()->put(self::SESSION_KEY, false);
            }
        });
    }

    public function entrySurface(Request $request): ?string
    {
        return match ($request->route()?->getName()) {
            'landing' => 'home_preview',
            'google-ads-management' => 'google_ads_service',
            'pricing' => 'pricing',
            'features', 'how-it-works', 'about', 'ai-ads-management' => 'other_marketing',
            'blog.index', 'blog.show' => 'guides',
            'register' => 'register',
            default => null,
        };
    }

    public function isPageNavigation(Request $request): bool
    {
        return $request->getMethod() === 'GET'
            && ($request->acceptsHtml() || $request->header('X-Inertia') === 'true')
            && ! $request->prefetch() && ! $request->hasHeader('X-Inertia-Partial-Component')
            && ! preg_match('/prefetch|prerender/i', $request->header('Sec-Purpose', '').' '.$request->header('Purpose', ''))
            && ! preg_match('/bot\b|crawler|spider|facebookexternalhit|headlesschrome|lighthouse|previewbot|curl\/|wget\//i', (string) $request->userAgent());
    }

    /** Called only after the save link's draft and browser session have been verified. */
    public function saveClicked(Request $request): void
    {
        $id = $this->sessionId($request);
        if (! $id || $request->user() || ! $this->isPageNavigation($request)) {
            return;
        }
        $this->observe(function () use ($id) {
            // Following the rendered draft's Save link also confirms its display,
            // even if navigation overtook the browser's visibility beacon.
            $this->write($id, 'landing_visible');
            $this->write($id, 'preview_succeeded');
            $this->write($id, 'save_clicked');
        });
    }

    public static function validClientStage(mixed $stage, mixed $reason): bool
    {
        return in_array($stage, self::CLIENT_STAGES, true)
            && (in_array($stage, ['preview_failed', 'signup_failed'], true) ? in_array($reason, self::CLIENT_REASONS, true) : $reason === null);
    }

    public function clientStage(Request $request, string $stage, ?string $reason): void
    {
        $id = $this->sessionId($request);
        if (! $id || ! self::validClientStage($stage, $reason)) {
            return;
        }
        $views = match ($stage) {
            'landing_visible' => ['signup_viewed', 'landing_view'],
            'save_clicked' => ['preview_succeeded'],
            'signup_started', 'signup_failed' => ['signup_viewed'],
            default => ['landing_view'],
        };
        $this->observe(function () use ($id, $stage, $reason, $views) {
            if (DB::table('signup_funnel_stages')->where('journey_id', $id)->whereIn('stage', $views)->exists()) {
                if ($stage === 'preview_succeeded') {
                    $this->write($id, 'landing_visible');
                }
                $this->write($id, $stage, $reason);
            }
        });
    }

    public function registrationSubmitted(Request $request): void
    {
        $id = $this->sessionId($request);
        if ($id) {
            $this->observe(function () use ($id) {
                $this->write($id, 'signup_started');
                $this->write($id, 'signup_submitted');
            });
        }
    }

    public function registrationFailed(Request $request, ValidationException $exception): void
    {
        $id = $this->sessionId($request);
        if (! $id) {
            return;
        }
        $failed = $exception->validator->failed();
        $reason = isset($failed['email']['Unique']) ? 'email_taken' : (isset($failed['cf_turnstile_response']) ? 'captcha' : 'validation');
        $this->observe(function () use ($id, $reason) {
            $this->write($id, 'signup_failed', $reason);
            // A native form error may have been seen first. Prefer the server's
            // classified validation cause over a less specific browser signal.
            if ($reason !== 'validation') {
                DB::table('signup_funnel_stages')->where('journey_id', $id)->where('stage', 'signup_failed')
                    ->whereIn('reason', ['unknown', 'validation'])->update(['reason' => $reason]);
            }
        });
    }

    public function registered(Request $request, User $user): void
    {
        $id = $this->sessionId($request);
        if (! $id || $user->tenant_key !== 'sitetospend' || ! $user->wasRecentlyCreated) {
            return;
        }
        $this->observe(function () use ($id, $user) {
            if (! DB::table('signup_funnel_journeys')->where('id', $id)->lockForUpdate()->first()
                || DB::table('signup_funnel_stages')->where('journey_id', $id)->where('stage', 'signup_completed')->exists()) {
                return;
            }
            $this->write($id, 'signup_completed');
            DB::table('users')->where('id', $user->id)->whereNull('signup_funnel_journey_id')->update(['signup_funnel_journey_id' => $id]);
            if ($user->saved_ad_preview !== null) {
                $this->write($id, 'signup_saved_draft');
            }
            if ($user->email_verified_at) {
                $this->write($id, 'email_verified');
            }
        });
    }

    public function verified(Request $request, User $user): void
    {
        if (! $this->allowed($request) || $user->tenant_key !== 'sitetospend' || ! $user->email_verified_at) {
            return;
        }
        $this->observe(function () use ($user) {
            $id = DB::table('users')->where('id', $user->id)->whereNotNull('email_verified_at')
                ->lockForUpdate()->value('signup_funnel_journey_id');
            if ($id) {
                $this->write($id, 'email_verified');
            }
        });
    }

    public function paid(User $user, CarbonInterface $paidAt): void
    {
        $id = $user->signup_funnel_journey_id;
        if (! $id || $user->tenant_key !== 'sitetospend') {
            return;
        }
        $this->observe(function () use ($id, $paidAt) {
            $signup = DB::table('signup_funnel_stages')->where('journey_id', $id)->where('stage', 'signup_completed')->lockForUpdate()->first();
            if (! $signup || $paidAt->getTimestamp() < strtotime($signup->occurred_at)) {
                return;
            }
            $payment = DB::table('signup_funnel_stages')->where('journey_id', $id)->where('stage', 'paid_subscription');
            // A bare datetime is interpreted in the database session's timezone.
            $settledAt = $paidAt->toIso8601String();
            $payment->insertOrIgnore(['journey_id' => $id, 'stage' => 'paid_subscription', 'occurred_at' => $settledAt]);
            // Stripe may deliver the first invoice after a later renewal.
            $payment->where('occurred_at', '>', $settledAt)->update(['occurred_at' => $settledAt]);
        });
    }

    private function acquisition(Request $request): array
    {
        $source = $request->query('utm_source');
        $medium = $request->query('utm_medium');
        $channel = match (true) {
            $source === 'google' && in_array($medium, ['cpc', 'ppc', 'paid_search', 'performance_max', 'search', 'display', 'shopping', 'video', 'demand_gen'], true) => 'google',
            in_array($source, ['facebook', 'instagram', 'meta'], true) && in_array($medium, ['cpc', 'paid_social', 'social', 'facebook', 'traffic', 'leads', 'sales', 'conversions', 'awareness', 'engagement', 'display', 'video', 'lead_generation', 'app_promotion'], true) => 'meta',
            default => 'unattributed',
        };
        $campaign = $request->query('utm_campaign');

        return [$channel, $channel !== 'unattributed' && is_string($campaign) && preg_match('/\Aspectra_[1-9][0-9]{0,17}\z/D', $campaign) ? $campaign : null];
    }

    private function allowed(Request $request): bool
    {
        return $this->ownHost($request) && $request->hasSession() && ! $this->optedOut($request)
            && $request->session()->get(self::SESSION_KEY) !== false;
    }

    private function sessionId(Request $request): ?string
    {
        if (! $this->allowed($request)) {
            return null;
        }
        $id = $request->session()->get(self::SESSION_KEY);

        return is_string($id) && Str::isUuid($id) ? $id : null;
    }

    private function write(string $id, string $stage, ?string $reason = null): void
    {
        DB::table('signup_funnel_stages')->insertOrIgnore(['journey_id' => $id, 'stage' => $stage, 'reason' => $reason, 'occurred_at' => now()->toIso8601String()]);
    }

    private function observe(callable $record): void
    {
        try {
            // Roll back a failed PostgreSQL statement to a savepoint before
            // returning to a caller that already has a business transaction.
            DB::transaction($record);
        } catch (\Throwable $exception) {
            // Diagnostics must not interrupt previews, registration or signed email
            // verification. Raw request/error messages can contain form values.
            Log::warning('Could not record signup funnel diagnostics.', ['exception_type' => $exception::class]);
        }
    }
}
