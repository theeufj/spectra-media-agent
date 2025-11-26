<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use App\Models\Customer;
use App\Models\Campaign;
use App\Models\Strategy;

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
     * Notify about a strategy ready for review.
     */
    public function notifyStrategyReady(Campaign $campaign, Strategy $strategy): Notification
    {
        $user = $campaign->customer->user;
        
        return $this->notify(
            $user,
            Notification::TYPE_STRATEGY_READY,
            'Strategy Ready for Review',
            "Campaign \"{$campaign->name}\" has a new strategy ready for your review.",
            route('campaigns.strategies.show', ['campaign' => $campaign->id, 'strategy' => $strategy->id]),
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
    public function notifyCollateralReady(Campaign $campaign, Strategy $strategy): Notification
    {
        $user = $campaign->customer->user;
        
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
    public function notifyDeploymentStarted(Campaign $campaign, Strategy $strategy): Notification
    {
        $user = $campaign->customer->user;
        
        return $this->notify(
            $user,
            Notification::TYPE_DEPLOYMENT_STARTED,
            'Deployment Started',
            "Campaign \"{$campaign->name}\" is being deployed to ad platforms.",
            route('campaigns.deployment.status', $campaign),
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
    public function notifyDeploymentCompleted(Campaign $campaign, Strategy $strategy): Notification
    {
        $user = $campaign->customer->user;
        
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
    public function notifyDeploymentFailed(Campaign $campaign, Strategy $strategy, string $error): Notification
    {
        $user = $campaign->customer->user;
        
        return $this->notify(
            $user,
            Notification::TYPE_DEPLOYMENT_FAILED,
            'Deployment Failed',
            "Campaign \"{$campaign->name}\" deployment failed: {$error}",
            route('campaigns.deployment.status', $campaign),
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
