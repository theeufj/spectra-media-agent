<?php

namespace App\Services;

use App\Models\CreativeBoostPurchase;
use App\Models\CreativeUsage;
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
     * The customer is resolved from the user's current selection; user_id is
     * still recorded so usage remains attributable to a person.
     */
    public function getOrCreateUsage(User $user): CreativeUsage
    {
        $period = $this->getCurrentPeriod();
        $customerId = $this->customerIdFor($user);

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
     * The active customer lives in the session (set by CustomerController::switch),
     * not on the user, so it is only available in a web request — a queued job
     * has none. Ownership is re-checked rather than trusted: a stale session
     * value must not let usage be booked against someone else's quota.
     */
    private function customerIdFor(User $user): ?int
    {
        $active = session('active_customer_id');

        if ($active && $user->customers()->where('customers.id', $active)->exists()) {
            return (int) $active;
        }

        return $user->customers()->value('customers.id');
    }

    /**
     * Check if the user can perform a generation of the given type.
     *
     * @param  string  $type  'image', 'video', or 'refinement'
     */
    public function canGenerate(User $user, string $type): bool
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

        $usage = $this->getOrCreateUsage($user);

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
    public function recordUsage(User $user, string $type, int $count = 1): void
    {
        $limits = $this->getLimits($user);

        // Don't track for unlimited (agency) users
        if ($limits === null) {
            return;
        }

        $usage = $this->getOrCreateUsage($user);

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
    public function getUsageSummary(User $user): array
    {
        $limits = $this->getLimits($user);
        $usage = $this->getOrCreateUsage($user);
        $plan = $user->resolveCurrentPlan();
        $isUnlimited = $limits === null;

        return [
            'plan_name' => $plan?->name ?? 'Free',
            'is_unlimited' => $isUnlimited,
            'period' => $this->getCurrentPeriod(),
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
     */
    public function applyBoost(User $user, CreativeBoostPurchase $purchase): void
    {
        $usage = $this->getOrCreateUsage($user);

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
     * Get the current billing period string (Y-m format).
     */
    public function getCurrentPeriod(): string
    {
        return Carbon::now()->format('Y-m');
    }
}
