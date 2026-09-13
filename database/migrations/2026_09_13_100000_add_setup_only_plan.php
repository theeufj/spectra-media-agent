<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The limits profile a paid one-time setup customer resolves to.
 *
 * Seeded rather than seeder-only because production does not run PlanSeeder on
 * deploy, and without the row a US$999 customer falls back to 'free': four
 * images, no video, no refinements. Their dashboard read "Videos — Not on your
 * plan" and "Refinements — Not on your plan" to someone who had just paid more
 * than four months of Growth for a build whose whole deliverable is creative.
 *
 * Creative allowances are Growth's, exactly. is_active is false so it never
 * appears on the pricing grid, which renders Plan::active() only.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // Idempotent: the seeder defines the same row, and either may run first.
        if (DB::table('plans')->where('slug', 'setup_only')->exists()) {
            return;
        }

        DB::table('plans')->insert([
            'name' => 'One-time setup',
            'slug' => 'setup_only',
            'description' => 'Limits profile for the one-time Google Ads setup. Never shown on pricing.',
            'price_cents' => 99900,
            'billing_interval' => 'one_time',
            'stripe_price_id' => null,
            'features' => json_encode([
                'Google Ads account, built and handed over',
                'Conversion tracking installed',
                'Campaign, ad copy and creative written for you',
                '10 images, 5 videos and 5 refinements for the build',
                'No subscription, no agents, nothing recurring',
            ]),
            'is_active' => false,
            'is_free' => false,
            'is_popular' => false,
            'cta_text' => 'Pay setup fee',
            'sort_order' => 99,
            'creative_limits' => json_encode([
                'image_generations' => 10,
                'video_generations' => 5,
                'refinements' => 5,
                'max_refinements_per_item' => 3,
                'max_extensions_per_video' => 3,
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        // Customers pointing at it would silently drop to free limits, so the
        // row only goes once nothing references it.
        DB::table('customers')->where('plan_id', function ($q) {
            $q->select('id')->from('plans')->where('slug', 'setup_only');
        })->update(['plan_id' => null]);

        DB::table('plans')->where('slug', 'setup_only')->delete();
    }
};
