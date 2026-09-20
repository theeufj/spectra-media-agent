<?php

namespace App\Services\Onboarding;

use App\Jobs\GenerateFirstCampaign;
use App\Models\Customer;
use App\Models\KnowledgeBase;
use App\Models\Strategy;

/** A read-only description of the purchased setup, shared by every setup screen. */
class SetupJourney
{
    public function checkoutReady(Customer $customer): bool
    {
        return filled($customer->website) && $customer->brandGuideline?->user_verified === true && $this->hasSource($customer);
    }

    public function hasSource(Customer $customer): bool
    {
        return KnowledgeBase::where('customer_id', $customer->id)
            ->whereRaw('length(content) >= ?', [GenerateFirstCampaign::MIN_CONTENT_CHARS])->exists();
    }

    public function forCustomer(Customer $customer): array
    {
        $campaign = $customer->campaigns()->with('strategies')->oldest('id')->first();
        $strategies = $campaign->strategies ?? collect();
        $strategy = $strategies->first();
        $paid = $customer->setup_fee_paid_at !== null;
        $ready = $this->checkoutReady($customer);
        $signed = $strategies->isNotEmpty() && $strategies->every(fn (Strategy $s) => $s->signed_off_at !== null);
        $verified = $strategies->isNotEmpty() && $strategies->every(fn (Strategy $s) => $s->deployment_status === 'verified');
        $created = $strategies->isNotEmpty() && $strategies->every(fn (Strategy $s) => $s->isDeployed());
        $handedOver = $verified && $customer->handover_at !== null;
        $error = (bool) $campaign?->strategy_generation_error;
        $deploymentProblem = $strategies->contains(fn (Strategy $s) => in_array($s->deployment_status, ['failed', 'deploy_unverified'], true));
        $buildStarted = $campaign->strategy_generation_started_at ?? $customer->setup_fee_paid_at;
        $building = $paid && ! $signed && ! $error && $strategies->isEmpty()
            && $buildStarted?->gt(now()->subMinutes(30));
        $stalled = $paid && $strategies->isEmpty() && ! $building;
        $awaitingCreation = $strategies->contains(fn (Strategy $s) => in_array($s->deployment_status, ['deploying', 'deployed'], true));
        $recentActivity = $strategies->contains(fn (Strategy $s) => $s->updated_at->gt(now()->subMinutes(30)));
        $creating = $awaitingCreation && $recentActivity;
        $deploymentProblem = $deploymentProblem || ($awaitingCreation && ! $recentActivity);
        $awaitingInvitation = $verified && ! $handedOver && $recentActivity;
        $campaignUrl = $campaign ? route('campaigns.show', $campaign) : null;
        $reviewUrl = $campaign && $strategy?->signed_off_at
            ? route('campaigns.collateral.show', [$campaign, $strategy]) : $campaignUrl;

        $steps = [
            ['key' => 'business', 'title' => 'Your business', 'completed' => $ready,
                'description' => $ready ? 'Business details checked and ready to use.' : 'Check what you sell, who buys it and where you operate.',
                'status' => $ready ? 'completed' : 'pending',
                'action_url' => $customer->brandGuideline && $this->hasSource($customer) ? route('brand-guidelines.index') : route('quick-start.scanning', ['manual' => 1]), 'action_text' => 'Review business'],
            ['key' => 'campaign', 'title' => 'Your campaign', 'completed' => $signed,
                'description' => match (true) {
                    ! $paid => 'Review the one-time fee before we build your Google Ads account.',
                    (bool) $error, $stalled => 'The build needs attention. Your payment is recorded; do not pay again.',
                    $building => 'Payment received. We are preparing your account and campaign.',
                    default => 'Check the audience, destination and daily budget before preparing your ads.',
                },
                'status' => $signed ? 'completed' : ($error || $stalled ? 'failed' : ($building ? 'in_progress' : 'pending')),
                'action_url' => $paid ? $campaignUrl : ($ready ? route('subscription.pricing') : null),
                'action_text' => $paid ? ($campaignUrl ? 'Review campaign' : null) : 'Review setup fee'],
            ['key' => 'review_ads', 'title' => 'Review your ads', 'completed' => $verified,
                'description' => $verified ? 'Campaign creation verified in Google Ads.' : ($deploymentProblem ? 'Campaign creation needs attention. Your approved ads and budget are saved.' : ($creating ? 'Creating and verifying the approved campaign in Google Ads.' : 'Preview the ads, then create the campaign paused.')),
                'status' => $verified ? 'completed' : ($deploymentProblem ? 'failed' : ($creating ? 'in_progress' : 'pending')),
                'action_url' => $campaign && ($creating || $deploymentProblem) ? route('campaigns.deployment-status', $campaign) : ($signed ? $reviewUrl : null),
                'action_text' => $creating || $deploymentProblem ? 'View creation status' : ($signed ? 'Review ads' : null)],
            ['key' => 'handover', 'title' => 'Your account is ready', 'completed' => $handedOver,
                'description' => $handedOver ? 'Your account is built. Complete the checks below before switching on ads.' : 'We will confirm account creation and send your administrator invitation.',
                'status' => $handedOver ? 'completed' : ($awaitingInvitation ? 'in_progress' : ($verified ? 'failed' : 'pending')),
                'action_url' => $created ? route('dashboard') : null, 'action_text' => $created ? 'View handover' : null],
        ];
        $current = collect($steps)->firstWhere('completed', false) ?? $steps[3];
        $completed = collect($steps)->where('completed', true)->count();

        return [
            'steps' => $steps, 'current_step' => $current, 'completed_steps' => $completed,
            'total_steps' => 4, 'progress' => $completed * 25, 'is_new_user' => ! $handedOver,
            'is_working' => $building || $creating || $awaitingInvitation, 'setup_only' => true,
            'checkout_ready' => $ready, 'paid' => $paid,
            'business' => $customer->only(['name', 'website', 'country', 'currency_code']),
            'campaign' => $campaign ? [
                'name' => $campaign->name, 'url' => $campaignUrl, 'review_url' => $reviewUrl,
                'budget' => ($campaign->approved_daily_budget ?? $campaign->daily_budget), 'budget_confirmed' => $campaign->budget_confirmed_at !== null,
                'status' => $campaign->status->value,
            ] : null,
            'checklist' => [
                ['title' => 'Google Ads account', 'done' => (bool) $customer->google_ads_customer_id,
                    'detail' => $customer->google_ads_customer_id ? 'Account '.$customer->google_ads_customer_id.' created.' : 'Created after payment.'],
                ['title' => 'Campaign', 'done' => $verified,
                    'detail' => $verified ? 'Creation verified. Current status: '.$campaign->status->value.'.' : ($created ? 'Created; verification is still pending.' : 'Awaiting creation and verification.')],
                ['title' => 'Daily budget', 'done' => $campaign?->budget_confirmed_at !== null,
                    'detail' => $campaign?->budget_confirmed_at ? $customer->currency_code.' '.number_format((float) ($campaign->approved_daily_budget ?? $campaign->daily_budget), 2).' per day approved. Ad spend is paid separately to Google.' : 'Choose and approve this when reviewing your campaign.'],
                ['title' => 'Administrator invitation', 'done' => $customer->handover_at !== null,
                    'detail' => $customer->handover_at ? 'Invitation sent. Accept it in your email; acceptance is not confirmed here.' : 'Sent after the campaign is created.'],
                ['title' => 'Google billing', 'done' => false,
                    'detail' => 'Add or confirm your payment method in Google Ads. Billing readiness is not verified here.'],
                ['title' => 'Conversion tracking', 'done' => $customer->conversion_tracking_verified_at !== null,
                    'detail' => $customer->conversion_tracking_verified_at ? 'Tracking verified.' : ($customer->gtm_installed ? 'Website tag detected. Test your conversion before launching.' : 'Install your website tag, then test a conversion before launching.'),
                    'url' => $customer->gtm_container_id ? route('customers.gtm.setup', $customer) : null],
            ],
        ];
    }
}
