<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Notification;
use App\Models\Strategy;
use App\Models\User;

class NotificationService
{
    /**
     * Create a notification for a user.
     */
    public function notify(
        User $user,
        string $type,
        string $title,
        string $message,
        ?string $actionUrl = null,
        ?string $actionText = null,
        ?Customer $customer = null,
        ?array $data = null
    ): Notification {
        return Notification::notify(
            $user,
            $type,
            $title,
            $message,
            $actionUrl,
            $actionText,
            $customer,
            $data
        );
    }

    /**
     * The person to tell about something that happened to a customer.
     *
     * Every helper below reached for `$customer->user` — a singular relation
     * that does not exist. Ownership is the `customers` pivot on User, and the
     * `customers.user_id` column was dropped; only `users()` remains. So each
     * of these threw the moment it was called, which is why not one of them had
     * a caller and why a client was never told their strategy or their creative
     * was ready.
     */
    private function ownerOf(?Customer $customer): ?User
    {
        if (! $customer) {
            return null;
        }

        return $customer->users()->wherePivot('role', 'owner')->first()
            ?? $customer->users()->first();
    }

    /**
     * Notify about a strategy ready for review.
     */
    public function notifyStrategyReady(Campaign $campaign, Strategy $strategy): ?Notification
    {
        $user = $this->ownerOf($campaign->customer);

        if (! $user) {
            return null;
        }

        return $this->notify(
            $user,
            Notification::TYPE_STRATEGY_READY,
            'Strategy Ready for Review',
            "Campaign \"{$campaign->name}\" has a new strategy ready for your review.",
            route('campaigns.show', ['campaign' => $campaign->id]),
            'Review Strategy',
            $campaign->customer,
            [
                'campaign_id' => $campaign->id,
                'strategy_id' => $strategy->id,
            ]
        );
    }

    /**
     * Notify about collateral ready for deployment.
     */
    public function notifyCollateralReady(Campaign $campaign, Strategy $strategy): ?Notification
    {
        $user = $this->ownerOf($campaign->customer);

        if (! $user) {
            return null;
        }

        return $this->notify(
            $user,
            Notification::TYPE_COLLATERAL_READY,
            'Collateral Ready',
            "Campaign \"{$campaign->name}\" has collateral ready to deploy.",
            route('campaigns.collateral.show', ['campaign' => $campaign->id, 'strategy' => $strategy->id]),
            'View Collateral',
            $campaign->customer,
            [
                'campaign_id' => $campaign->id,
                'strategy_id' => $strategy->id,
            ]
        );
    }

    /**
     * Notify about deployment started.
     */
    public function notifyDeploymentStarted(Campaign $campaign, Strategy $strategy): ?Notification
    {
        $user = $this->ownerOf($campaign->customer);

        if (! $user) {
            return null;
        }

        return $this->notify(
            $user,
            Notification::TYPE_DEPLOYMENT_STARTED,
            'Deployment Started',
            "Campaign \"{$campaign->name}\" is being deployed to ad platforms.",
            route('campaigns.deployment-status', $campaign),
            'View Progress',
            $campaign->customer,
            [
                'campaign_id' => $campaign->id,
                'strategy_id' => $strategy->id,
            ]
        );
    }

    /**
     * Notify about deployment completed.
     */
    public function notifyDeploymentCompleted(Campaign $campaign, Strategy $strategy): ?Notification
    {
        $user = $this->ownerOf($campaign->customer);

        if (! $user) {
            return null;
        }

        return $this->notify(
            $user,
            Notification::TYPE_DEPLOYMENT_COMPLETED,
            'Deployment Complete',
            "Campaign \"{$campaign->name}\" has been successfully deployed!",
            route('campaigns.show', $campaign),
            'View Campaign',
            $campaign->customer,
            [
                'campaign_id' => $campaign->id,
                'strategy_id' => $strategy->id,
            ]
        );
    }

    /**
     * Notify about deployment failure.
     */
    public function notifyDeploymentFailed(Campaign $campaign, Strategy $strategy, string $error): ?Notification
    {
        $user = $this->ownerOf($campaign->customer);

        if (! $user) {
            return null;
        }

        return $this->notify(
            $user,
            Notification::TYPE_DEPLOYMENT_FAILED,
            'Deployment Failed',
            "Campaign \"{$campaign->name}\" deployment failed: {$error}",
            route('campaigns.deployment-status', $campaign),
            'View Details',
            $campaign->customer,
            [
                'campaign_id' => $campaign->id,
                'strategy_id' => $strategy->id,
                'error' => $error,
            ]
        );
    }

    /**
     * Notify about low ad spend balance.
     */
    public function notifyLowBalance(User $user, Customer $customer, float $balance): Notification
    {
        return $this->notify(
            $user,
            Notification::TYPE_BILLING_WARNING,
            'Low Ad Spend Balance',
            "Your ad spend balance is running low (\${$balance} remaining).",
            route('billing.ad-spend'),
            'Add Credits',
            $customer,
            ['balance' => $balance]
        );
    }

    /**
     * Notify about successful payment.
     */
    public function notifyPaymentSuccess(User $user, Customer $customer, float $amount): Notification
    {
        return $this->notify(
            $user,
            Notification::TYPE_BILLING_SUCCESS,
            'Payment Successful',
            "Your payment of \${$amount} has been processed successfully.",
            route('billing.ad-spend'),
            'View Balance',
            $customer,
            ['amount' => $amount]
        );
    }

    /**
     * Notify about system health warning.
     */
    public function notifyHealthWarning(User $user, string $service, string $message): Notification
    {
        return $this->notify(
            $user,
            Notification::TYPE_HEALTH_WARNING,
            "Service Warning: {$service}",
            $message,
            null,
            null,
            null,
            ['service' => $service]
        );
    }

    /**
     * Notify about A/B test reaching significance.
     */
    public function notifyABTestComplete(
        \App\Models\ABTest $test,
        array $winner,
        float $confidence,
        float $liftPct
    ): ?Notification {
        $user = $this->ownerOf($test->campaign->customer);

        if (! $user) {
            return null;
        }

        return $this->notify(
            $user,
            Notification::TYPE_AB_TEST_COMPLETE,
            'A/B Test Winner Found',
            "Your {$test->test_type} test reached ".round($confidence * 100, 1).'% confidence. '.
            "\"{$winner['label']}\" won with a ".round($liftPct, 1).'% lift in CTR.',
            route('campaigns.show', $test->campaign_id),
            'View Results',
            $test->campaign->customer,
            [
                'test_id' => $test->id,
                'test_type' => $test->test_type,
                'winner' => $winner['label'],
                'confidence' => round($confidence * 100, 1),
                'lift_pct' => round($liftPct, 1),
            ]
        );
    }

    /**
     * Get unread notifications count for a user.
     */
    public function getUnreadCount(User $user, ?int $customerId = null): int
    {
        $query = Notification::where('user_id', $user->id)->unread();

        if ($customerId) {
            $query->where(function ($q) use ($customerId) {
                $q->where('customer_id', $customerId)
                    ->orWhereNull('customer_id');
            });
        }

        return $query->count();
    }

    /**
     * Get recent notifications for a user.
     */
    public function getRecentNotifications(User $user, ?int $customerId = null, int $limit = 20): array
    {
        $query = Notification::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->limit($limit);

        if ($customerId) {
            $query->where(function ($q) use ($customerId) {
                $q->where('customer_id', $customerId)
                    ->orWhereNull('customer_id');
            });
        }

        return $query->get()->toArray();
    }

    /**
     * Mark notification as read.
     */
    public function markAsRead(string $notificationId): bool
    {
        $notification = Notification::find($notificationId);

        if ($notification) {
            $notification->markAsRead();

            return true;
        }

        return false;
    }

    /**
     * Mark all notifications as read for a user.
     */
    public function markAllAsRead(User $user, ?int $customerId = null): int
    {
        $query = Notification::where('user_id', $user->id)->unread();

        if ($customerId) {
            $query->where(function ($q) use ($customerId) {
                $q->where('customer_id', $customerId)
                    ->orWhereNull('customer_id');
            });
        }

        return $query->update(['read_at' => now()]);
    }

    /**
     * Delete old notifications (older than specified days).
     */
    public function cleanupOldNotifications(int $days = 30): int
    {
        return Notification::where('created_at', '<', now()->subDays($days))
            ->whereNotNull('read_at')
            ->delete();
    }
}
