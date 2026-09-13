<?php

namespace App\Services;

use App\Models\CreativeBoostPurchase;
use App\Models\CreativeUsage;
use App\Models\Customer;
use App\Models\ImageCollateral;
use App\Models\User;
use App\Models\VideoCollateral;
use Carbon\Carbon;

class CreativeQuotaService
{
    /**
     * Get or create the usage record for the current billing period.
     */
    /**
     * Quota is held by the customer, not the person using the tool.
     *
     * Keyed on user_id previously, so a customer with three users received
     * three separate quotas. Generation allowance is sold per account.
     *
     * The customer is resolved from the caller's explicit argument where there
     * is one and from the user's current selection otherwise; user_id is still
     * recorded so usage remains attributable to a person.
     */
    public function getOrCreateUsage(User $user, ?Customer $customer = null): CreativeUsage
    {
        $customerId = $this->customerIdFor($user, $customer);

        // Resolved from the id rather than trusting the argument: customerIdFor
        // re-checks ownership and may land on a different account than the one
        // passed, and the period has to follow the account actually billed.
        $resolved = $customerId ? Customer::find($customerId) : null;
        $period = $this->periodFor($resolved);

        if (! $customerId) {
            // No customer yet (mid-onboarding). Fall back to per-user so the
            // tool still works rather than failing closed.
            return CreativeUsage::firstOrCreate(
                ['user_id' => $user->id, 'customer_id' => null, 'period' => $period],
                [
                    'image_generations_used' => 0,
                    'video_generations_used' => 0,
                    'refinements_used' => 0,
                    'bonus_image_generations' => 0,
                    'bonus_video_generations' => 0,
                    'bonus_refinements' => 0,
                ]
            );
        }

        return CreativeUsage::firstOrCreate(
            ['customer_id' => $customerId, 'period' => $period],
            [
                'image_generations_used' => 0,
                'video_generations_used' => 0,
                'refinements_used' => 0,
                'bonus_image_generations' => 0,
                'bonus_video_generations' => 0,
                'bonus_refinements' => 0,
                'user_id' => $user->id,
            ]
        );
    }

    /**
     * The customer this user's usage should count against.
     *
     * Pass the customer explicitly wherever one is known. The active customer
     * lives in the session (set by CustomerController::switch), not on the user,
     * so it exists only inside a web request — the Stripe webhook and every
     * queued job have none, and the last-resort lookup below then picks whatever
     * row the database returns first. That is how a paid boost pack landed on an
     * arbitrary customer of a multi-account user, with nothing recorded on
     * creative_boost_purchases to correct it afterwards.
     *
     * Ownership is re-checked rather than trusted: neither a stale session value
     * nor a caller's argument may book usage against someone else's quota.
     */
    private function customerIdFor(User $user, ?Customer $customer = null): ?int
    {
        if ($customer?->getKey() && $user->customers()->where('customers.id', $customer->getKey())->exists()) {
            return (int) $customer->getKey();
        }

        $active = session('active_customer_id');

        if ($active && $user->customers()->where('customers.id', $active)->exists()) {
            return (int) $active;
        }

        // Ordered, so a user with several customers at least resolves to the
        // same one on every call instead of to whatever the planner returns.
        return $user->customers()->orderBy('customers.id')->value('customers.id');
    }

    /**
     * Check if the user can perform a generation of the given type.
     *
     * @param  string  $type  'image', 'video', or 'refinement'
     */
    public function canGenerate(User $user, string $type, ?Customer $customer = null): bool
    {
        $limits = $this->getLimits($user);

        // null limits = unlimited (agency tier)
        if ($limits === null) {
            return true;
        }

        $limitKey = match ($type) {
            'image' => 'image_generations',
            'video' => 'video_generations',
            'refinement' => 'refinements',
            default => null,
        };

        if ($limitKey === null || ! isset($limits[$limitKey])) {
            return false;
        }

        $limit = (int) $limits[$limitKey];
        if ($limit <= 0) {
            return false;
        }

        $usage = $this->getOrCreateUsage($user, $customer);

        $used = match ($type) {
            'image' => $usage->image_generations_used,
            'video' => $usage->video_generations_used,
            'refinement' => $usage->refinements_used,
        };

        $bonus = match ($type) {
            'image' => $usage->bonus_image_generations,
            'video' => $usage->bonus_video_generations,
            'refinement' => $usage->bonus_refinements,
        };

        return $used < ($limit + $bonus);
    }

    /**
     * Record a generation usage.
     *
     * @param  string  $type  'image', 'video', or 'refinement'
     */
    public function recordUsage(User $user, string $type, int $count = 1, ?Customer $customer = null): void
    {
        $limits = $this->getLimits($user);

        // Don't track for unlimited (agency) users
        if ($limits === null) {
            return;
        }

        $usage = $this->getOrCreateUsage($user, $customer);

        $column = match ($type) {
            'image' => 'image_generations_used',
            'video' => 'video_generations_used',
            'refinement' => 'refinements_used',
            default => null,
        };

        if ($column) {
            $usage->increment($column, $count);
        }
    }

    /**
     * Check if a specific image can be refined (per-item depth check).
     */
    public function canRefineImage(ImageCollateral $image, User $user): bool
    {
        $limits = $this->getLimits($user);

        if ($limits === null) {
            // Agency: still enforce per-item cap of 3
            return ($image->refinement_depth ?? 0) < 3;
        }

        $maxPerItem = (int) ($limits['max_refinements_per_item'] ?? 0);

        return $maxPerItem > 0 && ($image->refinement_depth ?? 0) < $maxPerItem;
    }

    /**
     * Check if a specific video can be extended (per-item depth check).
     */
    public function canExtendVideo(VideoCollateral $video, User $user): bool
    {
        $limits = $this->getLimits($user);

        if ($limits === null) {
            // Agency: still enforce per-item cap of 3
            return ($video->refinement_depth ?? 0) < 3;
        }

        $maxPerVideo = (int) ($limits['max_extensions_per_video'] ?? 0);

        return $maxPerVideo > 0 && ($video->refinement_depth ?? 0) < $maxPerVideo;
    }

    /**
     * Get the full usage summary for the frontend.
     */
    public function getUsageSummary(User $user, ?Customer $customer = null): array
    {
        $limits = $this->getLimits($user);
        $usage = $this->getOrCreateUsage($user, $customer);
        $plan = $user->resolveCurrentPlan();
        $isUnlimited = $limits === null;

        return [
            'plan_name' => $plan?->name ?? 'Free',
            'is_unlimited' => $isUnlimited,
            // The row's own key, not today's date — a one-time account books
            // against 'once' and reporting Y-m here would describe a bucket
            // nothing was read from.
            'period' => $usage->period,
            'is_one_time' => $usage->period === self::ONE_TIME_PERIOD,
            'image_generations' => [
                'used' => $usage->image_generations_used,
                'limit' => $isUnlimited ? null : (int) ($limits['image_generations'] ?? 0),
                'bonus' => $usage->bonus_image_generations,
                'remaining' => $isUnlimited ? null : max(0, (int) ($limits['image_generations'] ?? 0) + $usage->bonus_image_generations - $usage->image_generations_used),
            ],
            'video_generations' => [
                'used' => $usage->video_generations_used,
                'limit' => $isUnlimited ? null : (int) ($limits['video_generations'] ?? 0),
                'bonus' => $usage->bonus_video_generations,
                'remaining' => $isUnlimited ? null : max(0, (int) ($limits['video_generations'] ?? 0) + $usage->bonus_video_generations - $usage->video_generations_used),
            ],
            'refinements' => [
                'used' => $usage->refinements_used,
                'limit' => $isUnlimited ? null : (int) ($limits['refinements'] ?? 0),
                'bonus' => $usage->bonus_refinements,
                'remaining' => $isUnlimited ? null : max(0, (int) ($limits['refinements'] ?? 0) + $usage->bonus_refinements - $usage->refinements_used),
            ],
            'max_refinements_per_item' => $isUnlimited ? 3 : (int) ($limits['max_refinements_per_item'] ?? 0),
            'max_extensions_per_video' => $isUnlimited ? 3 : (int) ($limits['max_extensions_per_video'] ?? 0),
        ];
    }

    /**
     * Apply a boost pack purchase to the user's current period.
     *
     * $customer must be supplied by the Stripe webhook: it runs without a
     * session, so without it the boost is credited to whichever of the buyer's
     * customers comes back first.
     */
    public function applyBoost(User $user, CreativeBoostPurchase $purchase, ?Customer $customer = null): void
    {
        $usage = $this->getOrCreateUsage($user, $customer);

        $usage->increment('bonus_image_generations', $purchase->image_generations);
        $usage->increment('bonus_video_generations', $purchase->video_generations);
        $usage->increment('bonus_refinements', $purchase->refinements);
    }

    /**
     * Get the creative_limits array for the user's current plan.
     * Returns null for unlimited (agency) tier.
     */
    private function getLimits(User $user): ?array
    {
        $plan = $user->resolveCurrentPlan();

        return $plan?->creative_limits;
    }

    /**
     * The period a one-time engagement's allowance is booked against.
     *
     * A literal rather than a date, so firstOrCreate keeps finding the same row
     * however long the customer has been with us.
     */
    public const ONE_TIME_PERIOD = 'once';

    /**
     * Which bucket this account's usage counts against.
     *
     * Every plan billed a month at a time refills on the first, and Y-m is how
     * that refill happens: a new month is a new key, so firstOrCreate hands
     * back a fresh row at zero. That is correct for a subscription and wrong
     * for the one-time setup, where the customer pays US$999 once. On Y-m they
     * would receive a new allowance every month for the rest of the account's
     * life — five videos a month, forever, against a single payment. At the
     * measured $1.88 a video on Grok (or $3.20 per 8-second segment when the
     * Veo fallback runs) a year of that outruns the difference between every
     * other number on this plan put together.
     *
     * So a setup-only account books against one fixed key. Spending the
     * allowance ends it, which is what "one payment, nothing recurring" means
     * from our side of the ledger as well as theirs.
     */
    public function periodFor(?Customer $customer = null): string
    {
        if ($customer && $customer->service_type === 'setup_only') {
            return self::ONE_TIME_PERIOD;
        }

        return Carbon::now()->format('Y-m');
    }

    /**
     * Get the current billing period string (Y-m format).
     *
     * Kept for callers with no customer in hand; prefer periodFor().
     */
    public function getCurrentPeriod(): string
    {
        return Carbon::now()->format('Y-m');
    }
}
