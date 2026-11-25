<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StarterPlanSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $plan = Plan::where('slug', 'starter')->lockForUpdate()->first() ?? new Plan(['slug' => 'starter']);
            $price = config('services.stripe.starter_price_id');
            $pastPrices = array_merge($plan->legacy_stripe_price_ids ?? [], [$plan->stripe_price_id, 'price_1Tfzd0ClfeR0n0yLuYUEUg6D']);
            $plan->legacy_stripe_price_ids = array_values(array_unique(array_filter($pastPrices, fn ($id) => is_string($id) && $id !== '' && $id !== $price)));
            $plan->fill([
                'name' => 'Starter', 'description' => 'Google Ads management for one business workspace.',
                'price_cents' => Plan::STARTER_MONTHLY_PRICE_CENTS, 'billing_interval' => 'month', 'stripe_price_id' => $price,
                'features' => [
                    'Google Ads for one business workspace',
                    '12 image concepts and 2 videos each month',
                    '6 creative refinements each month',
                    'Up to 2 refinements per image and 1 extension per video',
                    'Campaign reporting and conversion tracking',
                ],
                'creative_limits' => [
                    'image_generations' => 12, 'video_generations' => 2, 'refinements' => 6,
                    'max_refinements_per_item' => 2, 'max_extensions_per_video' => 1,
                ],
                'is_active' => true, 'is_free' => false, 'is_popular' => false,
                'cta_text' => 'Choose Starter', 'badge_text' => null, 'sort_order' => 1,
            ])->save();
        });
    }
}
