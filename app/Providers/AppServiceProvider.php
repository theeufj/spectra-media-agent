<?php

namespace App\Providers;

use App\Models\AdSpendCredit;
use App\Models\BrandGuideline;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\KnowledgeBase;
use App\Models\Proposal;
use App\Models\Strategy;
use App\Observers\CustomerObserver;
use App\Policies\AdSpendCreditPolicy;
use App\Policies\BrandGuidelinePolicy;
use App\Policies\CampaignPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\KnowledgeBasePolicy;
use App\Policies\ProposalPolicy;
use App\Policies\StrategyPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Routes agents to live or synthetic platform services per customer, so
        // the real agents can be run against sandbox data without touching an
        // ad account. Singleton so a sandbox run can retrieve the same recorder
        // afterwards and report what the agent actually decided.
        $this->app->singleton(
            \App\Contracts\Ads\AdsServiceFactory::class,
            \App\Services\Ads\CustomerRoutedAdsServiceFactory::class
        );

        // Default binding for anything type-hinting the contract directly
        // (MetricsFetcher). Sandbox runs inject their own instance explicitly
        // rather than rebinding this, so no global state is ever mutated.
        $this->app->bind(
            \App\Contracts\Ads\CampaignPerformanceSource::class,
            \App\Services\GoogleAds\CommonServices\GetCampaignPerformance::class
        );

        $this->app->singleton(StripeClient::class, function () {
            $secret = config('services.stripe.secret');

            if (empty($secret)) {
                throw new \RuntimeException('Stripe secret key is not configured. Set STRIPE_SECRET_KEY or STRIPE_SECRET in your environment.');
            }

            return new StripeClient($secret);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Rate limiter for Resend's 5 req/s API limit — applied via RateLimited middleware on queued mailables.
        RateLimiter::for('resend', fn () => Limit::perSecond(4));

        // Block scratch Test* commands that create/send REAL ad resources when running
        // in production. They are auto-discovered and would otherwise be runnable live
        // (e.g. minting a real MCC sub-account or publishing a real campaign). Read-only
        // connection diagnostics are intentionally left available. (QUAL-3)
        Event::listen(function (CommandStarting $event) {
            $blockedInProduction = [
                'googleads:test-all-campaigns',
                'googleads:test-campaign-publish',
                'googleads:test-mcc-auto-create',
                'microsoftads:test',
                'datamanager:test-event',
                'facebook:test',
            ];

            if (app()->environment('production') && in_array($event->command, $blockedInProduction, true)) {
                $event->output?->writeln("<error>'{$event->command}' creates real ad resources and is disabled in production.</error>");
                throw new \RuntimeException("Command '{$event->command}' is disabled in production.");
            }
        });

        // Register model observers
        Customer::observe(CustomerObserver::class);

        // Register authorization policies
        Gate::policy(Campaign::class, CampaignPolicy::class);
        Gate::policy(Strategy::class, StrategyPolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(AdSpendCredit::class, AdSpendCreditPolicy::class);
        Gate::policy(Proposal::class, ProposalPolicy::class);
        Gate::policy(BrandGuideline::class, BrandGuidelinePolicy::class);
        Gate::policy(KnowledgeBase::class, KnowledgeBasePolicy::class);

        // Log queue job failures to runtime_exceptions table
        Queue::failing(function (JobFailed $event) {
            try {
                $e = $event->exception;
                \App\Models\ExceptionLog::create([
                    'type' => get_class($e),
                    'source' => 'queue',
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'message' => mb_substr($e->getMessage(), 0, 65535),
                    'trace' => mb_substr($e->getTraceAsString(), 0, 65535),
                    'job_class' => $event->job->resolveName(),
                    'context' => [
                        'connection' => $event->connectionName,
                        'queue' => $event->job->getQueue(),
                        'attempts' => $event->job->attempts(),
                    ],
                ]);
            } catch (\Throwable $logException) {
                \Illuminate\Support\Facades\Log::warning('Failed to write queue failure to runtime_exceptions: '.$logException->getMessage());
            }
        });
    }
}
