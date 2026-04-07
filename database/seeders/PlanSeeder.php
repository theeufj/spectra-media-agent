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
                'description' => 'Explore the platform with generous limits.',
                'price_cents' => 0,
                'billing_interval' => 'month',
                'stripe_price_id' => null,
                'features' => [
                    'Google Ads Only',
                    '1 Campaign',
                    '1 Brand Identity (Vision AI Extraction)',
                    '4 AI-Generated Images per Campaign',
                    '3 Landing Page CRO Audits',
                    'Unlimited Ad Copy Generation',
                    'Analytics Dashboard',
                ],
                'is_active' => true,
                'is_free' => true,
                'is_popular' => false,
                'cta_text' => 'Get Started Free',
                'sort_order' => 0,
            ]
        );

        Plan::updateOrCreate(
            ['slug' => 'starter'],
            [
                'name' => 'Starter',
                'description' => 'For local businesses and early-stage startups.',
                'price_cents' => 9900,
                'billing_interval' => 'month',
                'stripe_price_id' => 'price_1TFYotClfeR0n0yLuE5IjLuI',
                'features' => [
                    '3 Brand Identities (Vision AI Extraction)',
                    'Google & Facebook Ads',
                    'Unlimited Campaigns',
                    '3 Landing Page CRO Audits',
                    'AI Copy & Image Generation',
                    'Weekly Performance Optimization',
                    'Analytics Dashboard',
                    'Basic Email Support',
                ],
                'is_active' => true,
                'is_free' => false,
                'is_popular' => false,
                'cta_text' => 'Start Free Trial',
                'badge_text' => null,
                'sort_order' => 1,
            ]
        );

        Plan::updateOrCreate(
            ['slug' => 'growth'],
            [
                'name' => 'Growth',
                'description' => 'For e-commerce brands ready to scale.',
                'price_cents' => 24900,
                'billing_interval' => 'month',
                'stripe_price_id' => 'price_1TFYpAClfeR0n0yLugIalDeU',
                'features' => [
                    'Everything in Starter, plus:',
                    'All 4 Ad Platforms (Google, Facebook, Microsoft, LinkedIn)',
                    'Unlimited Brand Identities',
                    'Unlimited Landing Page CRO Audits',
                    'Video & Carousel Creative',
                    'Competitor Intelligence & Analysis',
                    'Daily Performance Optimization',
                    'Strategy Agent "War Room" Access',
                    'Priority Support',
                ],
                'is_active' => true,
                'is_free' => false,
                'is_popular' => true,
                'cta_text' => 'Start Scaling Now',
                'badge_text' => 'MOST POPULAR',
                'sort_order' => 2,
            ]
        );

        Plan::updateOrCreate(
            ['slug' => 'agency'],
            [
                'name' => 'Agency',
                'description' => 'For high-volume advertisers and marketing agencies.',
                'price_cents' => 0,
                'billing_interval' => 'month',
                'stripe_price_id' => null,
                'features' => [
                    'Everything in Growth, plus:',
                    'Multi-Client Management (10 sub-accounts)',
                    'White-Label Reports',
                    'Dedicated Account Success Manager',
                    'Early Access to New Features',
                ],
                'is_active' => true,
                'is_free' => false,
                'is_popular' => false,
                'cta_text' => 'Contact Sales',
                'badge_text' => null,
                'sort_order' => 3,
            ]
        );
    }
}
