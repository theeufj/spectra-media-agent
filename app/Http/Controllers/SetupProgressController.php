<?php

namespace App\Http\Controllers;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Customer;
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
            $campaigns = $customer->campaigns()->get(['id', 'uuid', 'status', 'auto_generated_at', 'budget_confirmed_at']);
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

            /*
               One-time setup is a different journey, not the managed one with a
               different payment step.

               The managed list — scan, build a campaign, confirm a budget,
               install tracking, pay, deploy — asked a US$999 customer to do four
               things they had just paid us to do. Worse, it asked for them in an
               order that cannot work: the Google Ads account is created when the
               fee is paid, at step five, and the campaign wizard at step two
               needs that account to exist. So they were sent to build a campaign
               for an account that did not exist and told to "contact admin",
               which is nobody's job.

               What they actually do is confirm their brand and pay. Everything
               after that is ours, and is shown as our progress rather than their
               to-do list.
            */
            if ($customer->service_type === 'setup_only') {
                $steps = $this->setupOnlySteps($customer, $scanStatus, $hasDeployed, $deployPending, $firstCampaign);
            } else {
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
                            ? route('campaigns.show', $firstCampaign)
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
                            ? route('campaigns.show', $firstCampaign)
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
                        'action_url' => route('customers.gtm.setup', $customer),
                        'action_text' => $customer->gtm_installed ? 'View Tracking' : 'Install Snippet',
                    ],
                    [
                        // One-time setup has its own journey entirely — see
                        // setupOnlySteps(). This branch is the managed plan.
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
                            ? route('campaigns.show', $firstCampaign)
                            : route('campaigns.wizard'),
                        'action_text' => 'Deploy',
                    ],
                ];
            }

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

    /**
     * The one-time setup journey: two things they do, three we do.
     *
     * Ordered the way the work actually happens. Paying is what creates the
     * Google Ads account, so it comes before anything that needs one — which is
     * everything. The build steps are reported, not assigned: a customer who
     * paid US$999 to avoid Google Ads should not be handed a campaign wizard.
     *
     * @return list<array<string, mixed>>
     */
    private function setupOnlySteps(
        Customer $customer,
        string $scanStatus,
        bool $hasDeployed,
        bool $deployPending,
        ?Campaign $firstCampaign,
    ): array {
        $brandConfirmed = $customer->brandGuideline?->user_verified === true;
        $paid = $customer->setup_fee_paid_at !== null;
        $accountReady = ! empty($customer->google_ads_customer_id);
        $handedOver = $customer->handover_at !== null;

        return [
            [
                'key' => 'site_scan',
                'title' => 'We read your website',
                'description' => match ($scanStatus) {
                    'in_progress' => 'Reading your site to learn your business — a few minutes.',
                    'failed' => 'We could not finish reading your site. Add a few pages and we will work from those.',
                    'pending' => 'No website on file yet.',
                    default => 'Done — we know what you sell and who for.',
                },
                'completed' => $scanStatus === 'completed',
                'status' => $scanStatus,
                'action_url' => route('knowledge-base.create'),
                'action_text' => $scanStatus === 'failed' || $scanStatus === 'pending' ? 'Add Content' : 'View',
            ],
            [
                'key' => 'brand_confirmed',
                'title' => 'Confirm your brand profile',
                'description' => $brandConfirmed
                    ? 'Confirmed — your ads will be written in this voice.'
                    : 'Check we have your business right. This is the one thing only you can tell us.',
                'completed' => $brandConfirmed,
                'status' => $brandConfirmed ? 'completed' : ($scanStatus === 'completed' ? 'pending' : 'pending'),
                'action_url' => route('brand-guidelines.index'),
                'action_text' => $brandConfirmed ? 'View' : 'Review',
            ],
            [
                'key' => 'payment',
                'title' => 'Pay your one-time setup fee',
                'description' => $paid
                    ? 'Paid. Nothing recurring — this is the whole engagement.'
                    : 'US$999 once. This is what creates your Google Ads account and starts the build.',
                'completed' => $paid,
                'status' => $paid ? 'completed' : 'pending',
                'action_url' => route('subscription.pricing'),
                'action_text' => $paid ? 'View Receipt' : 'Pay Setup Fee',
            ],
            [
                /*
                   Theirs, and the only place they see the work before it exists.

                   We write the campaign and the ads; they read them and press
                   Create. The button is not called Deploy because nothing goes
                   live — a setup-only campaign is paused as it deploys, so
                   pressing it puts the ads in their account and leaves the
                   switch alone.
                */
                'key' => 'review_ads',
                'title' => 'Review and create your ads',
                'description' => match (true) {
                    ! $paid => 'We write the campaign and the ads. You read them and decide. Starts the moment you pay.',
                    $hasDeployed => 'Created — they are in your Google Ads account, paused until you switch them on.',
                    ! $accountReady => 'Building your Google Ads account and conversion tracking now.',
                    $firstCampaign !== null => 'We have written your campaign. Read it over and create the ads when you are happy.',
                    default => 'Writing your campaign and ads.',
                },
                'completed' => $hasDeployed,
                'status' => match (true) {
                    $hasDeployed => 'completed',
                    $paid && ! $accountReady => 'in_progress',
                    default => 'pending',
                },
                'action_url' => $firstCampaign ? route('campaigns.show', $firstCampaign) : null,
                'action_text' => $firstCampaign && ! $hasDeployed ? 'Review' : null,
            ],
            [
                'key' => 'handover',
                'title' => 'The keys are yours',
                'description' => $handedOver
                    ? 'Handed over. Accept the Google invitation, add your billing, and switch the campaign on when you are ready.'
                    : 'We invite you into the account as its admin. You add your own billing and spend what you want to spend.',
                'completed' => $handedOver,
                'status' => $handedOver ? 'completed' : 'pending',
                'action_url' => null,
                'action_text' => null,
            ],
        ];
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
