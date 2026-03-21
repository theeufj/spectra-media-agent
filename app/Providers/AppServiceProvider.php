<?php

namespace App\Providers;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Observers\CustomerObserver;
use App\Policies\CampaignPolicy;
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
            return new StripeClient(config('services.stripe.secret'));
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
    }
}

