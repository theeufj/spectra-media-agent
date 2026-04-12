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
use Illuminate\Support\Facades\Gate;
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
    }
}

