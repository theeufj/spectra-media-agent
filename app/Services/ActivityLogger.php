<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ActivityLogger
{
    /**
     * Log a user login.
     */
    public static function login(): ActivityLog
    {
        return ActivityLog::log('login', 'User logged in');
    }

    /**
     * Log a user logout.
     */
    public static function logout(): ActivityLog
    {
        return ActivityLog::log('logout', 'User logged out');
    }

    /**
     * Log impersonation start.
     */
    public static function impersonateStart(User $targetUser): ActivityLog
    {
        return ActivityLog::log(
            'impersonate_start',
            "Started impersonating {$targetUser->name} ({$targetUser->email})",
            $targetUser
        );
    }

    /**
     * Log impersonation stop.
     */
    public static function impersonateStop(User $targetUser): ActivityLog
    {
        return ActivityLog::log(
            'impersonate_stop',
            "Stopped impersonating {$targetUser->name} ({$targetUser->email})",
            $targetUser
        );
    }

    /**
     * Log user banned.
     */
    public static function userBanned(User $user): ActivityLog
    {
        return ActivityLog::log('user_banned', "Banned user {$user->name}", $user);
    }

    /**
     * Log user unbanned.
     */
    public static function userUnbanned(User $user): ActivityLog
    {
        return ActivityLog::log('user_unbanned', "Unbanned user {$user->name}", $user);
    }

    /**
     * Log user promoted to admin.
     */
    public static function userPromoted(User $user): ActivityLog
    {
        return ActivityLog::log('user_promoted', "Promoted {$user->name} to admin", $user);
    }

    /**
     * Log campaign action.
     */
    public static function campaign(string $action, Campaign $campaign, array $properties = []): ActivityLog
    {
        $description = match ($action) {
            'created' => "Created campaign: {$campaign->name}",
            'updated' => "Updated campaign: {$campaign->name}",
            'deleted' => "Deleted campaign: {$campaign->name}",
            'paused' => "Paused campaign: {$campaign->name}",
            'started' => "Started campaign: {$campaign->name}",
            // Deployment runs on the queue, so there is no authenticated user
            // on the row. The description has to carry the whole story on its
            // own or the entry reads as an anonymous "campaign action".
            'deployed' => "Deployed campaign to platforms: {$campaign->name}",
            'deploy_blocked' => "Deployment did not fully succeed: {$campaign->name}",
            default => "Campaign action: {$action}",
        };

        return ActivityLog::log("campaign_{$action}", $description, $campaign, $properties);
    }

    /**
     * Log customer action.
     */
    public static function customer(string $action, Customer $customer): ActivityLog
    {
        $description = match ($action) {
            'created' => "Created customer: {$customer->name}",
            'deleted' => "Deleted customer: {$customer->name}",
            default => "Customer action: {$action}",
        };

        return ActivityLog::log("customer_{$action}", $description, $customer);
    }

    /**
     * Log that an account became billable.
     *
     * The readiness audit found 14 of 15 accounts had no ad-spend credit
     * account, so this is the event that unblocks the platform. Worth seeing
     * the moment it happens.
     */
    public static function adSpendBillingSetup(Customer $customer, float $dailyBudget): ActivityLog
    {
        return ActivityLog::log(
            'ad_spend_billing_setup',
            "Ad-spend billing set up for {$customer->name}",
            $customer,
            ['daily_budget' => $dailyBudget],
        );
    }

    /**
     * Log settings update.
     */
    public static function settingsUpdated(array $changes): ActivityLog
    {
        return ActivityLog::log('settings_updated', 'Updated system settings', null, $changes);
    }

    /**
     * Generic log method for custom actions.
     */
    public static function log(string $action, ?string $description = null, ?Model $subject = null, array $properties = []): ActivityLog
    {
        return ActivityLog::log($action, $description, $subject, $properties);
    }
}
