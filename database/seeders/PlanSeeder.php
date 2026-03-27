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
                    '3 Brand Sources (URLs or Files)',
                    '4 AI-Generated Images per Campaign',
                    '3 Landing Page CRO Audits',
                    'Unlimited Ad Copy Generation',
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
                    '1 Brand Identity (Vision AI Extraction)',
                    'Google & Facebook Deployment',
                    '3 Landing Page CRO Audits',
                    'Standard AI Copy & Image Generation',
                    'Weekly Performance Optimization',
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
                    'Unlimited Brand Identities',
                    'Unlimited Landing Page CRO Audits',
                    'Advanced Creative Suite (Video & Carousel)',
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
                'price_cents' => 49900,
                'billing_interval' => 'month',
                'stripe_price_id' => 'price_1TFYpRClfeR0n0yLvpQZoKbV',
                'features' => [
                    'Everything in Growth, plus:',
                    'Multi-Client Management (10 sub-accounts)',
                    'White-Label Reports',
                    'Real-Time Bidding',
                    'Dedicated Account Success Manager',
                    'Early Access to Beta Features',
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
