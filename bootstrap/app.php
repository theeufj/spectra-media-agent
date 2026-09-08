<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(prepend: [
            \App\Http\Middleware\DetectTenant::class,
            \App\Http\Middleware\SecurityHeaders::class,
            \App\Http\Middleware\CanonicalRedirect::class,
        ]);

        $middleware->web(append: [
            // MUST run after StartSession: it persists ad click IDs (gclid/fbclid/…)
            // into the session for capture at registration. Prepended (pre-session) the
            // writes were silently discarded, so no user ever got a gclid.
            \App\Http\Middleware\CaptureClickIds::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'email/verify/*',
            // Server-to-server callback from Resend; authenticity comes from
            // the Svix signature, which the controller verifies.
            'webhooks/resend/*',
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\ForceHttpsUrls::class,
            \App\Http\Middleware\ImpersonationMiddleware::class,
            \App\Http\Middleware\CheckForBannedUser::class,
        ]);

        // X_FORWARDED_HOST is deliberately absent from this bitmask. Forge runs
        // nginx → php-fpm on one box, so REMOTE_ADDR is the caller itself and
        // at: '*' trusts every caller as its own proxy. With the header trusted,
        // `POST /forgot-password` carrying `X-Forwarded-Host: evil.com` made
        // $request->root() — and therefore the reset link in the mail the victim
        // receives — attacker-controlled. Nothing in front of this app sets the
        // header (nginx passes the real Host straight through), so dropping it
        // costs nothing. trustHosts below is the second half of the same fix.
        $middleware->trustProxies(
            at: '*',
            headers: \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR | \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT | \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO | \Illuminate\Http\Request::HEADER_X_FORWARDED_AWS_ELB
        );

        // The hosts this application answers to: every tenant skin's domain
        // (with or without www., which DetectTenant strips before matching),
        // the Forge site's own hostname, and localhost for on-box health
        // checks. Symfony answers any other Host with a 400, so a forged Host
        // header cannot become the root of a password-reset or verification
        // link either — the header itself is the whole attack, whichever way
        // it arrives.
        //
        // Derived from config/tenants.php rather than listed twice: a skin
        // added there must keep working, and a domain that is not a skin has
        // no business reaching this app. Passed as a closure because this
        // callback runs when the HTTP kernel is resolved, which is before
        // config is loaded — an array built here would be empty.
        $middleware->trustHosts(at: fn () => collect(config('tenants'))
            ->filter(fn ($tenant) => is_array($tenant) && isset($tenant['key']))
            ->keys()
            ->push(config('tenants.forge_domain'))
            ->push(parse_url((string) config('app.url'), PHP_URL_HOST))
            ->push('localhost')
            ->filter()
            ->unique()
            ->map(fn (string $host) => '^(www\.)?'.preg_quote($host).'$')
            ->values()
            ->all(), subdomains: false);

        // 'admin' is a group, not a single alias, so that every admin route is
        // audited by virtue of being an admin route. Doing this per controller
        // meant remembering nineteen times, and three of nineteen remembered.
        $middleware->group('admin', [
            // Generous enough that no honest admin will notice, tight enough
            // that a stolen session cannot enumerate or mass-delete at machine
            // speed. Nothing under /admin was rate limited at all, while the
            // public endpoints were held to 5 and 3 a minute.
            'throttle:120,1',
            \App\Http\Middleware\AdminMiddleware::class,
            \App\Http\Middleware\RequireTwoFactor::class,
            \App\Http\Middleware\ConfirmDestructiveAction::class,
            \App\Http\Middleware\LogAdminActions::class,
        ]);

        $middleware->alias([
            'ensureUserHasCustomer' => \App\Http\Middleware\EnsureUserHasCustomer::class,
            'subscribed' => \App\Http\Middleware\EnsureSubscribed::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Log all runtime exceptions to the database for the admin portal.
        //
        // The model is ExceptionLog, not RuntimeException — a class named
        // RuntimeException in App\Models would shadow SPL's for any unqualified
        // `throw new RuntimeException` in that namespace. This referenced the
        // non-existent name for months: the Error was swallowed by the catch
        // below, so every report() in the codebase wrote nothing here and the
        // dashboard only ever showed whole-job failures via Queue::failing.
        // bootstrap/ was outside the PHPStan paths, which is why nothing caught it.
        $exceptions->report(function (\Throwable $e) {
            try {
                $request = request();
                $source = 'http';
                $jobClass = null;

                // Detect if this is a queue job failure
                if (app()->runningInConsole()) {
                    $source = 'console';
                }

                // Check if the exception context indicates a queue job
                if ($e instanceof \Illuminate\Queue\MaxAttemptsExceededException ||
                    $e->getPrevious() instanceof \Illuminate\Queue\MaxAttemptsExceededException) {
                    $source = 'queue';
                }

                // Try to detect job class from the trace
                foreach ($e->getTrace() as $frame) {
                    if (isset($frame['class']) && str_starts_with($frame['class'], 'App\\Jobs\\')) {
                        $source = 'queue';
                        $jobClass = $frame['class'];
                        break;
                    }
                }

                \App\Models\ExceptionLog::create([
                    'type' => get_class($e),
                    'source' => $source,
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'message' => mb_substr($e->getMessage(), 0, 65535),
                    'trace' => mb_substr($e->getTraceAsString(), 0, 65535),
                    'url' => $source === 'http' ? $request->fullUrl() : null,
                    'method' => $source === 'http' ? $request->method() : null,
                    'job_class' => $jobClass,
                    'user_id' => $request->user()?->id,
                    // Queue workers and scheduled commands have no session, and
                    // that is where most reports come from. Context is set by the
                    // batch jobs that iterate tenants; the session is the
                    // fallback for genuine HTTP requests.
                    'customer_id' => \Illuminate\Support\Facades\Context::get('customer_id')
                        ?? session('active_customer_id'),
                    'context' => [
                        'input' => $source === 'http' ? $request->except(['password', 'password_confirmation', 'token']) : null,
                        'headers' => $source === 'http' ? collect($request->headers->all())->only(['user-agent', 'referer', 'accept'])->toArray() : null,
                    ],
                ]);
            } catch (\Throwable $logException) {
                // Never let exception logging break the app — but never let it
                // fail silently either. Swallowing this is what hid the broken
                // class name. Log directly rather than via report(), which
                // would re-enter this handler.
                \Illuminate\Support\Facades\Log::warning(
                    'Failed to write to runtime_exceptions: '.$logException->getMessage()
                );
            }

            // Return false to allow Laravel's default logging to continue
            return false;
        });
    })->create();
