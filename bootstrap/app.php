<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(prepend: [
            \App\Http\Middleware\SecurityHeaders::class,
            \App\Http\Middleware\CanonicalRedirect::class,
            \App\Http\Middleware\CaptureClickIds::class,
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'email/verify/*',
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\ForceHttpsUrls::class,
            \App\Http\Middleware\ImpersonationMiddleware::class,
            \App\Http\Middleware\CheckForBannedUser::class,
        ]);

        $middleware->trustProxies(
            at: '*',
            headers: \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR | \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST | \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT | \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO | \Illuminate\Http\Request::HEADER_X_FORWARDED_AWS_ELB
        );

        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
            'ensureUserHasCustomer' => \App\Http\Middleware\EnsureUserHasCustomer::class,
            'subscribed' => \App\Http\Middleware\EnsureSubscribed::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Log all runtime exceptions to the database for the admin portal
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

                \App\Models\RuntimeException::create([
                    'type' => get_class($e),
                    'source' => $source,
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'message' => mb_substr($e->getMessage(), 0, 65535),
                    'trace' => mb_substr($e->getTraceAsString(), 0, 65535),
                    'url' => $source === 'http' ? $request?->fullUrl() : null,
                    'method' => $source === 'http' ? $request?->method() : null,
                    'job_class' => $jobClass,
                    'user_id' => $request?->user()?->id,
                    'customer_id' => session('active_customer_id'),
                    'context' => [
                        'input' => $source === 'http' ? $request?->except(['password', 'password_confirmation', 'token']) : null,
                        'headers' => $source === 'http' ? collect($request?->headers?->all())->only(['user-agent', 'referer', 'accept'])->toArray() : null,
                    ],
                ]);
            } catch (\Throwable $logException) {
                // Silently fail — never let exception logging break the app
            }

            // Return false to allow Laravel's default logging to continue
            return false;
        });
    })->create();
