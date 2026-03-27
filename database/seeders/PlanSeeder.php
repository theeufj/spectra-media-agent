<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        Plan::updateOrCreate(
            ['slug' => 'free'],
            [
                'name' => 'Free',
                'description' => 'For individuals and small projects to explore our features.',
                'price_cents' => 0,
                'billing_interval' => 'month',
                'stripe_price_id' => null,
                'features' => [
                    'Create Marketing Strategies',
                    'Generate Ad Collateral',
                    'Manual Campaign Asset Downloads',
                ],
                'is_active' => true,
                'is_free' => true,
                'sort_order' => 0,
            ]
        );

        Plan::updateOrCreate(
            ['slug' => 'spectra-pro'],
            [
                'name' => 'Spectra Pro',
                'description' => 'For businesses who want to automate and optimize their advertising.',
                'price_cents' => 20000,
                'billing_interval' => 'month',
                'stripe_price_id' => null, // Set via admin panel with your Stripe price ID
                'features' => [
                    'Everything in Free',
                    'Automated Campaign Publishing',
                    'Performance Analytics & Optimization',
                    'Daily Ad Spend Billing',
                ],
                'is_active' => true,
                'is_free' => false,
                'sort_order' => 1,
            ]
        );
    }
}
