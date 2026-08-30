<?php

namespace App\Providers;

use App\Models\AdSpendCredit;
use App\Models\BrandGuideline;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\KnowledgeBase;
use App\Models\Proposal;
use App\Models\Scopes\CustomerScope;
use App\Models\Strategy;
use App\Models\SupportTicket;
use App\Observers\CustomerObserver;
use App\Policies\AdSpendCreditPolicy;
use App\Policies\BrandGuidelinePolicy;
use App\Policies\CampaignPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\GenericCustomerPolicy;
use App\Policies\KnowledgeBasePolicy;
use App\Policies\ProposalPolicy;
use App\Policies\StrategyPolicy;
use App\Policies\SupportTicketPolicy;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
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
     * Customer-owned models whose only authorization rule is ownership.
     *
     * Kept as an explicit list rather than discovered at boot: a filesystem
     * scan on every request to save typing thirty lines is a bad trade, and an
     * explicit list is greppable. AuthorizationCoverageTest fails when a model
     * carrying BelongsToCustomer is missing from here.
     *
     * @var list<class-string>
     */
    private const GENERIC_CUSTOMER_OWNED_MODELS = [
        \App\Models\AgentActivity::class,
        \App\Models\AttributionConversion::class,
        \App\Models\AttributionTouchpoint::class,
        \App\Models\Audience::class,
        \App\Models\CampaignConversation::class,
        \App\Models\CampaignHourlyPerformance::class,
        \App\Models\Competitor::class,
        \App\Models\CreativeBrief::class,
        \App\Models\CrmIntegration::class,
        \App\Models\CrossChannelRebalanceLog::class,
        \App\Models\CustomerPage::class,
        \App\Models\EmailLog::class,
        \App\Models\HarvestedAsset::class,
        \App\Models\Keyword::class,
        \App\Models\KeywordQualityScore::class,
        \App\Models\LandingPageAudit::class,
        \App\Models\NegativeKeywordList::class,
        \App\Models\OfflineConversion::class,
        \App\Models\Persona::class,
        \App\Models\PlatformBudgetAllocation::class,
        \App\Models\Product::class,
        \App\Models\ProductFeed::class,
        \App\Models\SeoAudit::class,
        \App\Models\SeoRanking::class,
    ];

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

        // CustomerScope memoises the acting user's customer list for the life of
        // the request. Authentication changes who is acting, so the memo has to
        // go with it — otherwise a login immediately after a logout in the same
        // request would inherit the previous user's tenancy.
        Event::listen(Login::class, fn () => CustomerScope::flush());
        Event::listen(Logout::class, fn () => CustomerScope::flush());

        // Rate limiter for Resend's 5 req/s API limit — applied via RateLimited middleware on queued mailables.
        RateLimiter::for('resend', fn () => Limit::perSecond(4));

        // Crawl rate, applied per host via RateLimited middleware on CrawlPage.
        //
        // Keyed by host rather than globally, because the limit that matters is
        // the one the site enforces: crawling twenty sites at once is fine,
        // hammering one is not. A Shopify store answered 1,205 of 1,236
        // requests with "local_rate_limited" under the previous arrangement,
        // which was a sleep() inside each job — that slows one worker while
        // Horizon runs the rest concurrently, so the aggregate rate was
        // unbounded.
        //
        // Twelve a minute is roughly one page every five seconds per site,
        // which matches the delay the job always intended to apply.
        RateLimiter::for('crawling', function (object $job) {
            $host = parse_url($job->url ?? '', PHP_URL_HOST) ?: 'unknown';

            return Limit::perMinute(12)->by($host);
        });

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

        // Register authorization policies.
        //
        // Models with rules of their own come first; everything else that
        // belongs to a Customer gets the generic ownership policy. Registering
        // all of them is the point — a model with no policy is a model whose
        // authorization is whatever each controller happened to remember, which
        // is how BrandGuidelinePolicy came to be registered and never invoked.
        Gate::policy(Campaign::class, CampaignPolicy::class);
        Gate::policy(Strategy::class, StrategyPolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(AdSpendCredit::class, AdSpendCreditPolicy::class);
        Gate::policy(Proposal::class, ProposalPolicy::class);
        Gate::policy(BrandGuideline::class, BrandGuidelinePolicy::class);
        Gate::policy(KnowledgeBase::class, KnowledgeBasePolicy::class);
        Gate::policy(SupportTicket::class, SupportTicketPolicy::class);
        Gate::policy(\App\Models\ImageCollateral::class, \App\Policies\ImageCollateralPolicy::class);
        Gate::policy(\App\Models\VideoCollateral::class, \App\Policies\VideoCollateralPolicy::class);

        foreach (self::GENERIC_CUSTOMER_OWNED_MODELS as $model) {
            Gate::policy($model, GenericCustomerPolicy::class);
        }

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
