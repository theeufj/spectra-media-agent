<?php

namespace App\Http\Controllers;

use App\Enums\CampaignStatus;
use App\Models\Strategy;
use App\Notifications\SiteScanFailed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SetupProgressController extends Controller
{
    /**
     * How long a scan may plausibly still be running. A customer with a
     * website, no knowledge base and an account older than this is stuck,
     * not scanning.
     */
    private const SCAN_GRACE_MINUTES = 120;

    /**
     * Setup progress for the active customer.
     *
     * The steps mirror the funnel a client actually travels — scan, campaign,
     * budget, payment, deploy. The old checklist measured internal artifacts
     * (knowledge base rows, a "verify brand guidelines" flag nothing in the
     * self-serve path ever set) and could read 100% for an account that had
     * never launched an ad.
     *
     * Each step carries a `status` of completed | in_progress | failed |
     * pending; `completed` stays as a boolean alongside it for compatibility.
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            $customer = $user?->customers()->find(session('active_customer_id'));

            if (! $customer) {
                return $this->emptyResponse();
            }

            // --- 1. Site scan -------------------------------------------------
            $hasContent = \App\Models\KnowledgeBase::where('customer_id', $customer->id)->exists()
                || $customer->brandGuideline()->exists();

            $scanFailed = ! $hasContent && $user->notifications()
                ->where('type', SiteScanFailed::class)
                ->where('data->customer_id', $customer->id)
                ->exists();

            if ($hasContent) {
                $scanStatus = 'completed';
            } elseif (! $customer->website) {
                $scanStatus = 'pending';
            } elseif ($scanFailed || $customer->created_at->lt(now()->subMinutes(self::SCAN_GRACE_MINUTES))) {
                $scanStatus = 'failed';
            } else {
                $scanStatus = 'in_progress';
            }

            // --- 2. First campaign -------------------------------------------
            $campaigns = $customer->campaigns()->get(['id', 'status', 'auto_generated_at', 'budget_confirmed_at']);
            $firstCampaign = $campaigns->sortBy('id')->first();
            $hasCampaign = $campaigns->isNotEmpty();

            // --- 3. Budget confirmed -----------------------------------------
            // A hand-built campaign had its budget typed in by the user; only
            // the auto-generated one carries a proposal awaiting confirmation.
            $budgetConfirmed = $campaigns->contains(
                fn ($c) => $c->auto_generated_at === null || $c->budget_confirmed_at !== null
            );

            // --- 4. Payment method -------------------------------------------
            // Same rule the deploy endpoint applies: the user or any teammate
            // on this customer with a subscription or card counts.
            $hasPayment = $user->subscribed('default')
                || $user->hasDefaultPaymentMethod()
                || $user->subscription_status === 'active'
                || $customer->users()
                    ->where(function ($q) {
                        $q->where('subscription_status', 'active')
                            ->orWhereNotNull('pm_type')
                            ->orWhereHas('subscriptions', fn ($sq) => $sq->where('stripe_status', 'active'));
                    })
                    ->exists();

            // --- 5. Deployed --------------------------------------------------
            $hasDeployed = $campaigns->contains(fn ($c) => $c->status === CampaignStatus::Active)
                || Strategy::whereIn('campaign_id', $campaigns->pluck('id'))
                    ->whereIn('deployment_status', Strategy::DEPLOYED_STATUSES)
                    ->exists();
            $deployPending = ! $hasDeployed
                && $campaigns->contains(fn ($c) => $c->status === CampaignStatus::PendingAdminDeployment);

            $steps = [
                [
                    'key' => 'site_scan',
                    'title' => 'Scan your website',
                    'description' => match ($scanStatus) {
                        'in_progress' => 'We\'re reading your site to learn your business — this usually takes a few minutes.',
                        'failed' => 'The scan couldn\'t finish. Add a few pages or a description manually and we\'ll work from that.',
                        'pending' => 'No website on file — add your content so the AI knows your business.',
                        default => 'Your site has been scanned and your knowledge base is ready.',
                    },
                    'completed' => $scanStatus === 'completed',
                    'status' => $scanStatus,
                    'action_url' => route('knowledge-base.create'),
                    'action_text' => $scanStatus === 'failed' || $scanStatus === 'pending' ? 'Add Content' : 'View Content',
                ],
                [
                    'key' => 'first_campaign',
                    'title' => $hasCampaign ? 'Review your campaign' : 'Create your first campaign',
                    'description' => $hasCampaign
                        ? 'Your campaign and its strategies are ready to review.'
                        : 'We draft one automatically after the scan — or build your own.',
                    'completed' => $hasCampaign,
                    'status' => $hasCampaign ? 'completed' : 'pending',
                    'action_url' => $firstCampaign
                        ? route('campaigns.show', $firstCampaign->id)
                        : route('campaigns.wizard'),
                    'action_text' => $hasCampaign ? 'Review Campaign' : 'Create Campaign',
                ],
                [
                    'key' => 'budget_confirmed',
                    'title' => 'Confirm your budget',
                    'description' => 'Approve the daily spend before anything goes live — nothing is charged until you do.',
                    'completed' => $budgetConfirmed,
                    'status' => $budgetConfirmed ? 'completed' : 'pending',
                    'action_url' => $firstCampaign
                        ? route('campaigns.show', $firstCampaign->id)
                        : route('campaigns.wizard'),
                    'action_text' => 'Review Budget',
                ],
                [
                    'key' => 'conversion_tracking',
                    'title' => 'Install your tracking snippet',
                    'description' => $customer->gtm_installed
                        ? 'Conversion tracking is active — every lead your ads bring is counted.'
                        : 'Two minutes on your website so we can count the leads and sales your ads bring.',
                    'completed' => (bool) $customer->gtm_installed,
                    'status' => $customer->gtm_installed ? 'completed' : 'pending',
                    'action_url' => route('customers.gtm.setup', $customer->id),
                    'action_text' => $customer->gtm_installed ? 'View Tracking' : 'Install Snippet',
                ],
                [
                    'key' => 'payment',
                    'title' => 'Add a payment method',
                    'description' => 'Building is free — a payment method is only needed to deploy.',
                    'completed' => $hasPayment,
                    'status' => $hasPayment ? 'completed' : 'pending',
                    'action_url' => route('subscription.pricing'),
                    'action_text' => 'Choose a Plan',
                ],
                [
                    'key' => 'deployed',
                    'title' => 'Deploy your ads',
                    'description' => $deployPending
                        ? 'Our team is completing your account setup — your ads launch within 24 hours.'
                        : 'Launch your campaign across your ad platforms.',
                    'completed' => $hasDeployed,
                    'status' => $hasDeployed ? 'completed' : ($deployPending ? 'in_progress' : 'pending'),
                    'action_url' => $firstCampaign
                        ? route('campaigns.show', $firstCampaign->id)
                        : route('campaigns.wizard'),
                    'action_text' => 'Deploy',
                ],
            ];

            $completedSteps = collect($steps)->where('completed', true)->count();
            $totalSteps = count($steps);
            $progress = round(($completedSteps / $totalSteps) * 100);

            return response()->json([
                'progress' => $progress,
                'completed_steps' => $completedSteps,
                'total_steps' => $totalSteps,
                'steps' => $steps,
                'is_new_user' => $progress < 100,
                'current_step' => collect($steps)->firstWhere('completed', false),
                // True while a poll is worth scheduling: something is moving
                // server-side that will change this payload without user input.
                'is_working' => collect($steps)->contains(fn ($s) => $s['status'] === 'in_progress'),
            ]);
        } catch (\Throwable $e) {
            report($e);
            Log::error('SetupProgressController error: '.$e->getMessage());

            return $this->emptyResponse();
        }
    }

    private function emptyResponse()
    {
        return response()->json([
            'progress' => 0,
            'steps' => [],
            'is_new_user' => true,
            'completed_steps' => 0,
            'total_steps' => 5,
            'current_step' => null,
            'is_working' => false,
        ]);
    }
}
