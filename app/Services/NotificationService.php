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
     * Everyone to tell about something that happened to a customer.
     *
     * Every helper below reached for `$customer->user` — a singular relation
     * that does not exist. Ownership is the `customers` pivot on User, and the
     * `customers.user_id` column was dropped; only `users()` remains. So each
     * of these threw the moment it was called, which is why not one of them had
     * a caller and why a client was never told their strategy or their creative
     * was ready.
     *
     * Replacing it with a single owner lookup fixed the throw and introduced a
     * quieter version of the same problem. An account is routinely held by
     * several people — sitetospend's own has four, two carrying the `owner`
     * pivot role and two `admin` — and `first()` picks whichever the database
     * returns first among them. Three of the four would never learn that a
     * deployment failed, and which one did was not decided by anything.
     *
     * Fanning out is also what the rest of the product does: DeployCampaign,
     * ReconcileStuckDeployments, GenerateExecutiveReport and
     * AdSpendBillingService::notifyInApp() all loop `$customer->users`. These
     * six were the exception, not the convention.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    private function recipientsFor(?Customer $customer): \Illuminate\Database\Eloquent\Collection
    {
        if (! $customer) {
            /** @var \Illuminate\Database\Eloquent\Collection<int, User> $none */
            $none = User::query()->whereRaw('1 = 0')->get();

            return $none;
        }

        return $customer->users()->get();
    }

    /**
     * Send one notification to every person on the account.
     *
     * @return \Illuminate\Support\Collection<int, Notification>
     */
    private function notifyAll(
        ?Customer $customer,
        string $type,
        string $title,
        string $message,
        ?string $actionUrl = null,
        ?string $actionText = null,
        ?array $data = null,
    ): \Illuminate\Support\Collection {
        return $this->recipientsFor($customer)->map(fn (User $user) => $this->notify(
            $user,
            $type,
            $title,
            $message,
            $actionUrl,
            $actionText,
            $customer,
            $data,
        ))->values();
    }

    /**
     * Notify about a strategy ready for review.
     *
     * @return \Illuminate\Support\Collection<int, Notification>
     */
    public function notifyStrategyReady(Campaign $campaign, Strategy $strategy): \Illuminate\Support\Collection
    {
        return $this->notifyAll(
            $campaign->customer,
            Notification::TYPE_STRATEGY_READY,
            'Strategy Ready for Review',
            "Campaign \"{$campaign->name}\" has a new strategy ready for your review.",
            route('campaigns.show', ['campaign' => $campaign->id]),
            'Review Strategy',
            [
                'campaign_id' => $campaign->id,
                'strategy_id' => $strategy->id,
            ]
        );
    }

    /**
     * Notify about collateral ready for deployment.
     *
     * @return \Illuminate\Support\Collection<int, Notification>
     */
    public function notifyCollateralReady(Campaign $campaign, Strategy $strategy): \Illuminate\Support\Collection
    {
        return $this->notifyAll(
            $campaign->customer,
            Notification::TYPE_COLLATERAL_READY,
            'Collateral Ready',
            "Campaign \"{$campaign->name}\" has collateral ready to deploy.",
            route('campaigns.collateral.show', ['campaign' => $campaign->id, 'strategy' => $strategy->id]),
            'View Collateral',
            [
                'campaign_id' => $campaign->id,
                'strategy_id' => $strategy->id,
            ]
        );
    }

    /**
     * Notify about deployment started.
     *
     * @return \Illuminate\Support\Collection<int, Notification>
     */
    public function notifyDeploymentStarted(Campaign $campaign, Strategy $strategy): \Illuminate\Support\Collection
    {
        return $this->notifyAll(
            $campaign->customer,
            Notification::TYPE_DEPLOYMENT_STARTED,
            'Deployment Started',
            "Campaign \"{$campaign->name}\" is being deployed to ad platforms.",
            route('campaigns.deployment-status', $campaign),
            'View Progress',
            [
                'campaign_id' => $campaign->id,
                'strategy_id' => $strategy->id,
            ]
        );
    }

    /**
     * Notify about deployment completed.
     *
     * @return \Illuminate\Support\Collection<int, Notification>
     */
    public function notifyDeploymentCompleted(Campaign $campaign, Strategy $strategy): \Illuminate\Support\Collection
    {
        return $this->notifyAll(
            $campaign->customer,
            Notification::TYPE_DEPLOYMENT_COMPLETED,
            'Deployment Complete',
            "Campaign \"{$campaign->name}\" has been successfully deployed!",
            route('campaigns.show', $campaign),
            'View Campaign',
            [
                'campaign_id' => $campaign->id,
                'strategy_id' => $strategy->id,
            ]
        );
    }

    /**
     * Notify about deployment failure.
     *
     * @return \Illuminate\Support\Collection<int, Notification>
     */
    public function notifyDeploymentFailed(Campaign $campaign, Strategy $strategy, string $error): \Illuminate\Support\Collection
    {
        return $this->notifyAll(
            $campaign->customer,
            Notification::TYPE_DEPLOYMENT_FAILED,
            'Deployment Failed',
            "Campaign \"{$campaign->name}\" deployment failed: {$error}",
            route('campaigns.deployment-status', $campaign),
            'View Details',
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
     *
     * @return \Illuminate\Support\Collection<int, Notification>
     */
    public function notifyABTestComplete(
        \App\Models\ABTest $test,
        array $winner,
        float $confidence,
        float $liftPct
    ): \Illuminate\Support\Collection {
        return $this->notifyAll(
            $test->campaign->customer,
            Notification::TYPE_AB_TEST_COMPLETE,
            'A/B Test Winner Found',
            "Your {$test->test_type} test reached ".round($confidence * 100, 1).'% confidence. '.
            "\"{$winner['label']}\" won with a ".round($liftPct, 1).'% lift in CTR.',
            route('campaigns.show', $test->campaign_id),
            'View Results',
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
