<?php

namespace App\Services\Billing;

use App\Models\AdSpendCredit;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\DeploymentService;
use Illuminate\Support\Facades\DB;

/** Reserve from one shared wallet without selling the same available credit twice. */
class LaunchFunding
{
    public static function cents(mixed $amount): int
    {
        $micros = CampaignSpendGuardrails::moneyToMicros($amount);
        if ($micros % 10000 !== 0) {
            throw new \InvalidArgumentException('An approved amount must use whole cents.');
        }

        return intdiv($micros, 10000);
    }

    public static function runwayDays(Campaign $campaign): int
    {
        if ($campaign->getAttribute('end_date') === null) {
            return 7;
        }
        $today = now($campaign->customer?->timezone ?: config('app.timezone'))->startOfDay()->toDateString();
        $start = max($today, $campaign->getAttribute('start_date') === null ? $today : $campaign->start_date->toDateString());
        $end = $campaign->end_date->toDateString();
        if ($end < $start) {
            return 0;
        }

        return min(7, 1 + (int) \Carbon\CarbonImmutable::parse($start, 'UTC')->diffInDays(\Carbon\CarbonImmutable::parse($end, 'UTC')));
    }

    public function reserve(Strategy $strategy, ?array $proof = null): array
    {
        $customer = $strategy->campaign->customer;
        $platform = DeploymentService::normalizePlatform($strategy->platform);
        $proof ??= app(PlatformFunding::class)->assess($customer, $platform);
        $id = $strategy->launch_intent['id'] ?? null;
        $days = self::runwayDays($strategy->campaign);
        $target = self::cents($strategy->daily_budget) * $days;
        if (! $id || ! $proof['ready'] || ! $target || $strategy->deployment_status !== 'awaiting_launch'
            || ! $strategy->signed_off_at || $customer->service_type === 'setup_only') {
            return ['ready' => false, 'message' => 'The approved channel is awaiting account, budget or payer verification.'];
        }
        $hold = DB::transaction(function () use ($strategy, $customer, $platform, $proof, $id, $target, $days) {
            $customer = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $current = Strategy::whereKey($strategy->id)->lockForUpdate()->firstOrFail();
            if (($current->launch_intent['id'] ?? null) !== $id || $current->deployment_status !== 'awaiting_launch'
                || self::cents($current->daily_budget) * $days !== $target || $current->campaign->spend_safety_hold
                || ($current->launch_intent['daily_cents'] ?? null) !== intdiv($target, $days)
                || $target > ($current->launch_intent['max_reserve_cents'] ?? 0)
                || $proof['account'] !== $this->account($customer, $platform) || $proof['currency'] !== $customer->billingCurrency()) {
                return null;
            }
            $holds = DB::table('launch_funding_reservations');
            $row = (clone $holds)->where('launch_id', $id)->first();
            if ($row) {
                return $row->target_cents >= $target && $row->target_cents <= $current->launch_intent['max_reserve_cents'] && $row->currency === $proof['currency'] && $row->account_id === $proof['account']
                    && $row->payer === $proof['payer'] ? $row : null;
            }
            $payerId = $current->launch_intent['payer_user_id'] ?? null;
            $payer = $payerId ? $customer->users()->find($payerId) : null;
            $available = $shortfall = 0;
            if ($proof['payer'] === 'agency') {
                $wallet = $customer->adSpendCredit()->lockForUpdate()->first();
                if (! $wallet && (! $payer || ! $payer->hasStripeId() || ! $payer->hasDefaultPaymentMethod())) {
                    return null;
                }
                $wallet ??= $customer->adSpendCredit()->create([
                    'currency' => $proof['currency'], 'initial_credit_amount' => '0.00', 'current_balance' => '0.00',
                    'status' => AdSpendCredit::STATUS_DEPLETED, 'payment_status' => AdSpendCredit::PAYMENT_CURRENT,
                ]);
                if (strtoupper($wallet->currency) !== $proof['currency'] || ! $wallet->isInGoodStanding()) {
                    return null;
                }
                $committed = (clone $holds)->where('customer_id', $customer->id)->where('currency', $proof['currency'])
                    ->whereIn('status', ['collecting', 'held'])->sum('held_cents');
                $available = min($target, max(0, self::cents($wallet->current_balance) - (int) $committed));
                $shortfall = $target - $available;
            }
            if ($shortfall && (! $payer || ! $payer->hasStripeId() || ! $payer->hasDefaultPaymentMethod())) {
                return null;
            }
            $key = $holds->insertGetId([
                'launch_id' => $id, 'strategy_id' => $strategy->id, 'customer_id' => $customer->id, 'payer_user_id' => $payer?->id, 'stripe_customer_id' => $payer?->stripe_id,
                'platform' => $platform, 'account_id' => $proof['account'], 'currency' => $proof['currency'], 'payer' => $proof['payer'],
                'target_cents' => $target, 'held_cents' => $available, 'charge_cents' => $shortfall, 'status' => $shortfall ? 'collecting' : 'held',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return $holds->where('id', $key)->first();
        });
        if (! $hold || $hold->status === 'released') {
            if (! $hold && ! $customer->adSpendPayer()) {
                return ['ready' => false, 'code' => 'payment_method_required', 'owner' => 'Customer', 'setup_url' => route('billing.ad-spend'),
                    'message' => 'Add a workspace payment method or sufficient available credit for this channel.'];
            }

            return ['ready' => false, 'code' => 'wallet_not_ready', 'message' => 'Check the approved allocation, wallet currency and available credit for this channel.'];
        }
        if ($hold->status === 'held') {
            return ['ready' => true, 'message' => 'The approved advertising allocation is funded.'];
        }
        if ((int) $hold->target_cents > $target) {
            $key = $this->paymentKey($hold);
            $attempt = DB::table('ad_spend_charge_attempts')->where('customer_id', $customer->id)->where('idempotency_key', $key)->first();
            if ($attempt) {
                $gateway = app(AdSpendPaymentGateway::class);
                $payment = $attempt->payment_intent_id ? $gateway->inspect($attempt->payment_intent_id) : null;
                if ($payment?->status === 'succeeded') {
                    $this->creditPayment($hold->id, $payment);

                    return ['ready' => $this->funded($strategy), 'message' => 'The previously captured prepayment was reconciled.'];
                }
                if (! $payment || ! $this->paymentMatches($hold, $payment)) {
                    return ['ready' => false, 'message' => 'The prior payment is awaiting reconciliation. The revised amount will not be collected until Stripe confirms its status.'];
                }
                if (in_array($payment->status, ['requires_payment_method', 'requires_action', 'requires_confirmation'], true)) {
                    $payment = $gateway->cancelUncollected($payment->id, $key.':cancel');
                }
                if ($payment->status !== 'canceled') {
                    return ['ready' => false, 'message' => 'Stripe is settling the earlier payment. Funding will resume after reconciliation.'];
                }
            }
            DB::table('launch_funding_reservations')->where('id', $hold->id)->where('status', 'collecting')->update([
                'target_cents' => $target, 'held_cents' => min($target, (int) $hold->held_cents),
                'charge_cents' => max(0, $target - (int) $hold->held_cents), 'status' => $target <= $hold->held_cents ? 'held' : 'collecting', 'updated_at' => now(),
            ]);

            return $this->reserve($strategy, $proof);
        }
        $payer = $customer->users()->find($hold->payer_user_id);
        if (! $payer || ! $payer->hasStripeId() || $payer->stripe_id !== $hold->stripe_customer_id || ! $payer->hasDefaultPaymentMethod() || ! ($method = $payer->defaultPaymentMethod())) {
            return ['ready' => false, 'code' => 'payment_method_required', 'owner' => 'Customer', 'setup_url' => route('billing.ad-spend'),
                'message' => 'Add a payment method for the approved workspace payer.'];
        }
        try {
            $intent = app(AdSpendPaymentGateway::class)->collect($customer, [
                'amount' => (int) $hold->charge_cents, 'currency' => strtolower($hold->currency), 'customer' => $payer->stripe_id,
                'payment_method' => $method->id, 'payment_method_types' => ['card'], 'off_session' => true, 'confirm' => true,
                'description' => $days.' days of prepaid advertising for '.$platform,
                'metadata' => ['type' => 'ad_spend_credit', 'customer_id' => (string) $customer->id, 'launch_reservation_id' => (string) $hold->id],
            ], $this->paymentKey($hold));
            if ($intent->status !== 'succeeded') {
                return $this->paymentPending($hold->id, $intent->status);
            }
            $this->creditPayment($hold->id, $intent);
        } catch (\Stripe\Exception\CardException $error) {
            $status = $error->getError()?->code === 'authentication_required' ? 'requires_action' : 'requires_payment_method';

            return $this->paymentPending($hold->id, $status);
        } catch (\Throwable $e) {
            report($e);

            return ['ready' => false, 'message' => 'The seven-day prepayment has not been confirmed. Your channel is waiting.'];
        }

        return ['ready' => $this->funded($strategy), 'message' => 'The seven-day prepayment was reconciled.'];
    }

    private function paymentPending(int $reservationId, string $status): array
    {
        if (in_array($status, ['requires_action', 'requires_confirmation', 'requires_payment_method'], true)) {
            return ['ready' => false, 'code' => 'requires_action', 'owner' => 'Customer',
                'setup_url' => route('billing.ad-spend.confirm-reservation', $reservationId),
                'message' => $status === 'requires_payment_method' ? 'Your bank declined this prepayment. The workspace payer can choose another card.' : 'The workspace payer needs to authenticate this prepayment with their bank.'];
        }

        return ['ready' => false, 'code' => 'payment_processing', 'owner' => 'Stripe',
            'message' => 'Stripe is processing this prepayment. Funding will resume after it settles.'];
    }

    public function confirmationPayment(Customer $customer, User $payer, int $reservationId): ?string
    {
        $hold = DB::table('launch_funding_reservations')->where('id', $reservationId)->where('customer_id', $customer->id)
            ->where('payer_user_id', $payer->id)->where('stripe_customer_id', $payer->stripe_id)->where('status', 'collecting')->first();
        if (! $hold || ! $payer->stripe_id) {
            return null;
        }
        $strategy = Strategy::whereKey($hold->strategy_id)->first();
        if (! $strategy || ($strategy->launch_intent['id'] ?? null) !== $hold->launch_id
            || ($strategy->launch_intent['funding']['code'] ?? null) !== 'requires_action'
            || $strategy->deployment_status !== 'awaiting_launch'
            || self::runwayDays($strategy->campaign) * self::cents($strategy->daily_budget) < $hold->target_cents
            || ! app(\App\Services\Deployment\CampaignLaunchIntent::class)->approvalCurrent($strategy)) {
            return null;
        }
        $account = app(PlatformFunding::class)->assess($customer, $hold->platform);
        $outcome = app(\App\Services\Conversions\CampaignOutcomeEvidence::class)->forStrategy($strategy);
        if (! $account['ready'] || $account['account'] !== $hold->account_id || $account['currency'] !== $hold->currency
            || $account['payer'] !== $hold->payer || ($outcome['requires_verification'] && ! $outcome['verified_for_launch'])) {
            return null;
        }
        $intentId = DB::table('ad_spend_charge_attempts')->where('customer_id', $customer->id)
            ->where('idempotency_key', $this->paymentKey($hold))->value('payment_intent_id');
        if (! $intentId) {
            return null;
        }
        $payment = app(AdSpendPaymentGateway::class)->inspect($intentId);

        return in_array($payment->status, ['requires_action', 'requires_confirmation', 'requires_payment_method'], true)
            && $this->paymentMatches($hold, $payment) ? $intentId : null;
    }

    private function paymentMatches(object $hold, \Stripe\PaymentIntent $payment): bool
    {
        return $hold->stripe_customer_id && $payment->customer === $hold->stripe_customer_id && $payment->amount === (int) $hold->charge_cents
            && strtoupper($payment->currency) === $hold->currency
            && (string) ($payment->metadata['launch_reservation_id'] ?? '') === (string) $hold->id
            && (string) ($payment->metadata['customer_id'] ?? '') === (string) $hold->customer_id;
    }

    private function paymentKey(object $hold): string
    {
        return 'launch-reservation:'.$hold->launch_id.':'.$hold->charge_cents;
    }

    /** Replayed Stripe responses/webhooks credit money once even if approval was subsequently cancelled. */
    public function creditPayment(int $reservationId, \Stripe\PaymentIntent $payment): void
    {
        DB::transaction(function () use ($reservationId, $payment) {
            $found = DB::table('launch_funding_reservations')->where('id', $reservationId)->firstOrFail();
            $customer = Customer::whereKey($found->customer_id)->lockForUpdate()->firstOrFail();
            $hold = DB::table('launch_funding_reservations')->where('id', $reservationId)->lockForUpdate()->firstOrFail();
            if ($payment->status !== 'succeeded' || $payment->amount_received !== (int) $hold->charge_cents
                || ! $this->paymentMatches($hold, $payment)
                || ($hold->payment_intent_id && $hold->payment_intent_id !== $payment->id)) {
                throw new \RuntimeException('The Stripe payment does not match the approved channel reservation.');
            }
            if ($hold->payment_intent_id === $payment->id) {
                return;
            }
            $wallet = $customer->adSpendCredit()->lockForUpdate()->firstOrFail();
            if (strtoupper($wallet->currency) !== $hold->currency) {
                throw new \RuntimeException('The prepaid wallet currency changed while the payment was pending.');
            }
            $wallet->addCredit($hold->charge_cents / 100, 'Seven-day '.$hold->platform.' advertising prepayment', $payment->id);
            $active = $hold->status === 'collecting';
            DB::table('launch_funding_reservations')->where('id', $hold->id)->update([
                'held_cents' => $active ? max(0, $hold->target_cents - $hold->refunded_cents) : 0, 'status' => $active ? 'held' : 'released',
                'payment_intent_id' => $payment->id, 'updated_at' => now(),
            ]);
            if ($hold->refunded_cents) {
                \App\Http\Controllers\StripeWebhookController::recordAdSpendRefund($hold->refund_charge_id, $hold->refunded_cents / 100, $payment->id);
            }
        });
    }

    public function recordRefund(int $reservationId, array $charge): void
    {
        DB::transaction(function () use ($reservationId, $charge) {
            $hold = DB::table('launch_funding_reservations')->where('id', $reservationId)->lockForUpdate()->firstOrFail();
            $refunded = (int) ($charge['amount_refunded'] ?? 0);
            $intent = $charge['payment_intent'] ?? null;
            $intent = is_array($intent) ? ($intent['id'] ?? null) : $intent;
            if (! $intent || ($charge['customer'] ?? null) !== $hold->stripe_customer_id
                || strtoupper($charge['currency'] ?? '') !== $hold->currency || $refunded > $hold->charge_cents
                || ($hold->payment_intent_id && $intent !== $hold->payment_intent_id)
                || (int) ($charge['metadata']['customer_id'] ?? 0) !== (int) $hold->customer_id) {
                throw new \RuntimeException('Refund identity does not match the advertising reservation.');
            }
            if ($refunded <= $hold->refunded_cents) {
                return;
            }
            DB::table('launch_funding_reservations')->where('id', $hold->id)->update(['refunded_cents' => $refunded,
                'refund_charge_id' => $charge['id'], 'held_cents' => max(0, $hold->held_cents - ($refunded - $hold->refunded_cents)), 'updated_at' => now()]);
        });
    }

    public function funded(Strategy $strategy): bool
    {
        $customer = $strategy->campaign->customer;
        if ($customer->service_type === 'setup_only') {
            return $customer->isPaidSetupOnly();
        }
        $hold = DB::table('launch_funding_reservations')->where('strategy_id', $strategy->id)->where('launch_id', $strategy->launch_intent['id'] ?? '')
            ->where('status', 'held')->first();
        if (! $hold || $hold->refunded_cents > 0 || $hold->customer_id != $customer->id || $hold->currency !== $customer->billingCurrency()
            || $hold->account_id !== $this->account($customer, $hold->platform)
            || (int) $hold->target_cents > ($strategy->launch_intent['max_reserve_cents'] ?? 0)
            || ($strategy->launch_intent['daily_cents'] ?? null) !== self::cents($strategy->daily_budget)) {
            return false;
        }
        if ($hold->payer === 'customer') {
            return true;
        }
        $wallet = $customer->adSpendCredit()->first();
        $obligations = DB::table('launch_funding_reservations')->where('customer_id', $customer->id)->whereIn('status', ['held', 'collecting'])->sum('held_cents');

        return $wallet && $wallet->canRunCampaigns() && strtoupper($wallet->currency) === $hold->currency
            && self::cents($wallet->current_balance) >= (int) $obligations;
    }

    public function release(Strategy $strategy): void
    {
        $id = $strategy->launch_intent['id'] ?? null;
        if (! $id) {
            return;
        }
        DB::table('launch_funding_reservations')->where('strategy_id', $strategy->id)->where('launch_id', $id)
            ->whereIn('status', ['collecting', 'held'])->update(['status' => 'released', 'held_cents' => 0, 'updated_at' => now()]);
    }

    /** Release never-launched/cancelled holds; allow final provider reporting before releasing deployed money. */
    public function releaseClosed(?int $customerId = null): void
    {
        DB::table('launch_funding_reservations')->whereIn('status', ['held', 'collecting'])
            ->when($customerId !== null, fn ($q) => $q->where('customer_id', $customerId))->orderBy('id')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $hold) {
                    try {
                        $strategy = Strategy::with('campaign.customer')->find($hold->strategy_id);
                        $campaign = $strategy?->campaign;
                        if (! $campaign) {
                            continue;
                        }
                        $intentMatches = ($strategy->launch_intent['id'] ?? null) === $hold->launch_id;
                        $closed = $campaign->hasPassedEndDate() || in_array($campaign->status, [\App\Enums\CampaignStatus::Paused,
                            \App\Enums\CampaignStatus::Completed, \App\Enums\CampaignStatus::Ended], true);
                        $noCreateRequest = ($strategy->launch_intent['campaign_create_checkpoint'] ?? false) && ! isset($strategy->launch_intent['campaign_create']);
                        $unpublished = ! $strategy->hasDeploymentFootprint() && ($noCreateRequest || ! isset($strategy->launch_intent['platform_submission_started_at']));
                        $cancelled = ! $intentMatches || in_array($strategy->deployment_status, ['failed', 'skipped_plan', 'cancelled'], true);
                        $finalDay = $campaign->getAttribute('end_date') === null ? null : $campaign->end_date->toDateString();
                        $settled = $closed && $finalDay && $campaign->end_date->lt(now()->subDays(3))
                            && DB::table('ad_spend_billing_runs')->where('customer_id', $hold->customer_id)
                                ->where('spend_date', $finalDay)->where('status', 'completed')->exists();
                        if (($unpublished && ($closed || $cancelled)) || $settled) {
                            DB::table('launch_funding_reservations')->where('id', $hold->id)->whereIn('status', ['held', 'collecting'])
                                ->update(['status' => 'released', 'held_cents' => 0, 'updated_at' => now()]);
                        }
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            });
    }

    /** Provider daily rows can first arrive or increase after the original nightly settlement closed. */
    public function reopenAmendedSpend(): void
    {
        foreach (['google', 'facebook'] as $platform) {
            $field = 'metric.'.$platform.'_campaign_id';
            $changed = DB::table($platform.'_ads_performance_data as metric')
                ->join('launch_funding_reservations as hold', 'hold.strategy_id', '=', 'metric.strategy_id')
                ->leftJoin('launch_funding_settlements as prior', function ($join) use ($field) {
                    $join->on('prior.reservation_id', '=', 'hold.id')->on('prior.spend_date', '=', 'metric.date')->on('prior.resource_id', '=', $field);
                })
                ->whereColumn('metric.'.$platform.'_account_id', 'hold.account_id')->whereColumn('metric.currency', 'hold.currency')
                ->where('hold.platform', $platform)->where('hold.payer', 'agency')->whereIn('hold.status', ['held', 'released'])
                ->whereNotNull($field)->whereColumn('hold.customer_id', 'ad_spend_billing_runs.customer_id')
                ->whereColumn('metric.date', 'ad_spend_billing_runs.spend_date')
                ->whereRaw('ROUND(metric.cost * 100) > COALESCE(prior.observed_cents, 0)');
            DB::table('ad_spend_billing_runs')->where('status', 'completed')->whereExists($changed)
                ->update(['status' => 'pending', 'updated_at' => now()]);
        }
    }

    /** Consume a hold alongside the actual wallet debit, using cumulative provider spend for the resource/day. */
    public function settle(int $strategyId, string $resourceId, string $day, int $observedCents): void
    {
        if ($observedCents <= 0) {
            return;
        }
        DB::transaction(function () use ($strategyId, $resourceId, $day, $observedCents) {
            $hold = DB::table('launch_funding_reservations')->where('strategy_id', $strategyId)->whereIn('status', ['held', 'released'])
                ->where('payer', 'agency')->orderByDesc('id')->lockForUpdate()->first();
            if (! $hold) {
                return;
            }
            $match = ['reservation_id' => $hold->id, 'resource_id' => $resourceId, 'spend_date' => $day];
            $prior = DB::table('launch_funding_settlements')->where($match)->first();
            $delta = $observedCents - (int) ($prior->observed_cents ?? 0);
            if ($delta <= 0) {
                return;
            }
            $consumed = min($delta, (int) $hold->held_cents);
            DB::table('launch_funding_settlements')->updateOrInsert($match, ['observed_cents' => $observedCents,
                'consumed_cents' => ((int) ($prior->consumed_cents ?? 0)) + $consumed, 'created_at' => $prior->created_at ?? now(), 'updated_at' => now()]);
            DB::table('launch_funding_reservations')->where('id', $hold->id)->decrement('held_cents', $consumed, ['updated_at' => now()]);
        });
    }

    private function account(Customer $customer, string $platform): string
    {
        return $platform === 'google' ? $customer->cleanGoogleCustomerId() : (string) preg_replace('/^act_/', '', (string) $customer->facebook_ads_account_id);
    }
}
