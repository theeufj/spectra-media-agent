<?php

namespace App\Services\Billing;

use App\Models\Customer;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Cashier\Cashier;
use Stripe\Checkout\Session;
use Stripe\Exception\InvalidRequestException;

class ManagedSubscriptionCheckout
{
    private const TABLE = 'managed_subscription_checkouts';

    public function hasSubscription(User $user): bool
    {
        return $user->subscriptions()->where('type', 'default')->where(function ($query) {
            $query->whereNotIn('stripe_status', ['canceled', 'incomplete_expired'])->orWhere('ends_at', '>', now());
        })->exists();
    }

    public function start(User $payer, Customer $workspace, Plan $plan): array
    {
        // Persist the original parameters and key before a request can reach Stripe.
        // A lost response can then be replayed even if the worker dies before saving the session ID.
        $expected = null;
        for ($i = 0; $i < 4; $i++) {
            $result = DB::transaction(function () use ($payer, $workspace, $plan, $expected) {
                $user = User::lockForUpdate()->findOrFail($payer->id);
                $customer = Customer::lockForUpdate()->findOrFail($workspace->id);
                if ($customer->adSpendPayer(allowWithoutPaymentMethod: true)?->id !== $user->id || $customer->is_sandbox || $customer->service_type === 'setup_only') {
                    return ['state' => 'blocked', 'message' => 'The billing contact must start checkout for this managed workspace.'];
                }
                if ($this->hasSubscription($user)) {
                    return ['state' => 'portal'];
                }
                if ($customer->subscribedPlan()) {
                    return ['state' => 'blocked', 'message' => 'This workspace already has a subscription. Its subscriber can manage it in the billing portal.'];
                }
                $attempt = DB::table(self::TABLE)->whereNull('closed_at')
                    ->where(fn ($q) => $q->where('customer_id', $customer->id)->orWhere('user_id', $user->id))
                    ->orderBy('created_at')->lockForUpdate()->first();
                if ($expected && $attempt?->id !== $expected) {
                    return ['state' => 'blocked', 'message' => 'Another checkout request changed this selection. Return to pricing to see its current status.'];
                }
                if ($attempt) {
                    return $this->resume($attempt, $user, $customer, $plan);
                }
                if ($user->hasStripeId() && $this->hasUnrecordedCheckout($user)) {
                    return ['state' => 'blocked', 'message' => 'Stripe already has a subscription or checkout in progress. Finish that checkout or wait for billing confirmation.'];
                }
                if (! $user->hasStripeId()) {
                    $user->createAsStripeCustomer([], ['idempotency_key' => 'managed-subscription-customer:'.$user->id]);
                }
                $id = (string) Str::uuid();
                DB::table(self::TABLE)->insert([
                    'id' => $id, 'customer_id' => $customer->id, 'user_id' => $user->id, 'stripe_customer_id' => $user->stripe_id,
                    'price_id' => $plan->stripe_price_id, 'parameters' => json_encode($this->parameters($id, $user, $customer, $plan), JSON_THROW_ON_ERROR),
                    'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
                ]);

                return ['state' => 'retry', 'attempt' => $id];
            });
            if ($result['state'] !== 'retry') {
                return $result;
            }
            $expected = $result['attempt'] ?? null;
        }

        return ['state' => 'blocked', 'message' => 'The checkout selection changed. Please check pricing again.'];
    }

    private function resume(object $attempt, User $user, Customer $customer, Plan $plan): array
    {
        $storage = DB::table(self::TABLE)->where('id', $attempt->id);
        $parameters = json_decode($attempt->parameters, true, flags: JSON_THROW_ON_ERROR);
        $api = Cashier::stripe();
        $sessions = $api->checkout->sessions;
        if ($attempt->stripe_session_id) {
            $session = $sessions->retrieve($attempt->stripe_session_id);
        } elseif ($parameters['expires_at'] > now()->timestamp + 1800) {
            $session = $sessions->create($parameters, ['idempotency_key' => 'managed-subscription:'.$attempt->id]);
        } else {
            // Stripe requires at least 30 minutes before expiry to create a session. Search the
            // complete original creation interval for a lost reply before ever using another key.
            $session = null;
            $created = ['gte' => \Illuminate\Support\Carbon::parse($attempt->created_at)->timestamp - 60, 'lte' => $parameters['expires_at']];
            foreach ($sessions->all(['customer' => $attempt->stripe_customer_id, 'created' => $created, 'limit' => 100])->autoPagingIterator() as $candidate) {
                if ($candidate->client_reference_id === $attempt->id) {
                    $session = $candidate;
                    break;
                }
            }
            if (! $session && $parameters['expires_at'] > now()->timestamp) {
                return ['state' => 'blocked', 'message' => 'We are confirming the previous checkout with Stripe. Try again after its expiry.'];
            }
        }
        if ($session && ($session->customer !== $attempt->stripe_customer_id || $session->mode !== 'subscription' || $session->client_reference_id !== $attempt->id)) {
            throw new \UnexpectedValueException('Stripe returned a checkout for a different billing obligation.');
        }
        $samePurchase = (int) $attempt->user_id === $user->id && (int) $attempt->customer_id === $customer->id
            && $attempt->stripe_customer_id === $user->stripe_id && $attempt->price_id === $plan->stripe_price_id;
        if ($session?->status === Session::STATUS_OPEN && ! $samePurchase) {
            if ((int) $attempt->customer_id !== $customer->id) {
                return ['state' => 'blocked', 'message' => 'Finish or cancel the checkout for your other workspace before starting this one.'];
            }
            $session = $this->expire($session, $attempt->id);
        }
        $status = $session ? $session->status : 'expired';
        if (! in_array($status, [Session::STATUS_OPEN, Session::STATUS_COMPLETE, Session::STATUS_EXPIRED], true)) {
            throw new \UnexpectedValueException('Stripe returned an unrecognized checkout state.');
        }
        if ($session && $status === Session::STATUS_COMPLETE && $session->subscription) {
            $subscription = $api->subscriptions->retrieve($session->subscription);
            if ($subscription->customer !== $attempt->stripe_customer_id) {
                throw new \UnexpectedValueException('Stripe returned a subscription belonging to another payer.');
            }
            if (in_array($subscription->status, ['canceled', 'incomplete_expired'], true)) {
                $status = 'canceled';
            }
        }
        $closed = in_array($status, ['expired', 'canceled'], true);
        $storage->update(['stripe_session_id' => $session?->id, 'status' => $status, 'closed_at' => $closed ? now() : null, 'updated_at' => now()]);
        if ($closed) {
            return ['state' => 'retry'];
        }
        if ($samePurchase && $status === Session::STATUS_OPEN && $session->url) {
            return ['state' => 'open', 'url' => $session->url];
        }

        return ['state' => 'blocked', 'message' => 'Stripe is confirming the existing subscription. Your billing status will update after confirmation.'];
    }

    public function cancel(User $user, string $id): void
    {
        DB::transaction(function () use ($user, $id) {
            $payer = User::lockForUpdate()->findOrFail($user->id);
            $attempt = DB::table(self::TABLE)->where(['id' => $id, 'user_id' => $payer->id])->whereNull('closed_at')->lockForUpdate()->first();
            if (! $attempt || ! $attempt->stripe_session_id || $attempt->stripe_customer_id !== $payer->stripe_id) {
                return;
            }
            $session = Cashier::stripe()->checkout->sessions->retrieve($attempt->stripe_session_id);
            if ($session->customer !== $payer->stripe_id || $session->mode !== 'subscription' || $session->client_reference_id !== $id) {
                throw new \UnexpectedValueException('Stripe returned a different checkout while canceling.');
            }
            if ($session->status === Session::STATUS_OPEN) {
                $session = $this->expire($session, $id);
            }
            DB::table(self::TABLE)->where('id', $id)->update(['status' => $session->status,
                'closed_at' => $session->status === 'expired' ? now() : null, 'updated_at' => now()]);
        });
    }

    private function expire(Session $session, string $id): Session
    {
        $sessions = Cashier::stripe()->checkout->sessions;
        try {
            return $sessions->expire($session->id, [], ['idempotency_key' => 'expire-managed-subscription:'.$id]);
        } catch (InvalidRequestException $e) {
            // Completion can win the race against expiry.
            $current = $sessions->retrieve($session->id);
            if ($current->status === Session::STATUS_OPEN) {
                throw $e;
            }

            return $current;
        }
    }

    private function hasUnrecordedCheckout(User $user): bool
    {
        // Protect purchases from before the checkout ledger existed, including a delayed webhook.
        $api = Cashier::stripe();
        foreach ($api->subscriptions->all(['customer' => $user->stripe_id, 'status' => 'all', 'limit' => 100])->autoPagingIterator() as $sub) {
            if (! in_array($sub->status, ['canceled', 'incomplete_expired'], true)
                && (($sub->metadata['type'] ?? null) === 'default' || Plan::forStripePrices(collect($sub->items->data)->pluck('price.id')->all())->exists())) {
                return true;
            }
        }
        foreach ($api->checkout->sessions->all(['customer' => $user->stripe_id, 'status' => 'open', 'limit' => 100])->autoPagingIterator() as $session) {
            if ($session->mode === 'subscription') {
                $items = $api->checkout->sessions->allLineItems($session->id);
                if (Plan::forStripePrices(collect($items->data)->pluck('price.id')->all())->exists()) {
                    return true;
                }
            }
        }

        return false;
    }

    private function parameters(string $id, User $user, Customer $workspace, Plan $plan): array
    {
        $metadata = ['type' => 'default', 'name' => 'default', 'is_on_session_checkout' => 'true', 'workspace_id' => (string) $workspace->id, 'billing_user_id' => (string) $user->id];
        if ($plan->slug === 'starter') {
            $metadata['starter_customer_id'] = (string) $workspace->id;
        }
        $items = [['price' => $plan->stripe_price_id, 'quantity' => 1]];
        if ($priceRates = $user->priceTaxRates()[$plan->stripe_price_id] ?? []) {
            $items[0]['tax_rates'] = $priceRates;
        }
        if ($plan->stripe_ad_spend_price_id && config('billing.metered_ad_spend_enabled')) {
            $items[] = ['price' => $plan->stripe_ad_spend_price_id];
        }
        $parameters = ['customer' => $user->stripe_id, 'client_reference_id' => $id, 'mode' => 'subscription', 'allow_promotion_codes' => true,
            'expires_at' => now()->addHours(23)->timestamp, 'line_items' => $items, 'subscription_data' => ['metadata' => $metadata],
            'success_url' => route('dashboard', ['purchase' => 'success']), 'cancel_url' => URL::temporarySignedRoute('subscription.checkout.cancel', now()->addDay(), ['attempt' => $id])];
        if ($rates = $user->taxRates()) {
            $parameters['subscription_data']['default_tax_rates'] = $rates;
        }
        if (Cashier::$calculatesTaxes) {
            $parameters += ['automatic_tax' => ['enabled' => true], 'tax_id_collection' => ['enabled' => true], 'billing_address_collection' => 'required', 'customer_update' => ['name' => 'auto']];
        }

        return $parameters;
    }
}
