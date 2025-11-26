<?php

namespace App\Services;

use App\Models\ActivityLog;
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
    public static function impersonateStart(Model $targetUser): ActivityLog
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
    public static function impersonateStop(Model $targetUser): ActivityLog
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
    public static function userBanned(Model $user): ActivityLog
    {
        return ActivityLog::log('user_banned', "Banned user {$user->name}", $user);
    }

    /**
     * Log user unbanned.
     */
    public static function userUnbanned(Model $user): ActivityLog
    {
        return ActivityLog::log('user_unbanned', "Unbanned user {$user->name}", $user);
    }

    /**
     * Log user promoted to admin.
     */
    public static function userPromoted(Model $user): ActivityLog
    {
        return ActivityLog::log('user_promoted', "Promoted {$user->name} to admin", $user);
    }

    /**
     * Log campaign action.
     */
    public static function campaign(string $action, Model $campaign, array $properties = []): ActivityLog
    {
        $description = match ($action) {
            'created' => "Created campaign: {$campaign->name}",
            'updated' => "Updated campaign: {$campaign->name}",
            'deleted' => "Deleted campaign: {$campaign->name}",
            'paused' => "Paused campaign: {$campaign->name}",
            'started' => "Started campaign: {$campaign->name}",
            default => "Campaign action: {$action}",
        };

        return ActivityLog::log("campaign_{$action}", $description, $campaign, $properties);
    }

    /**
     * Log customer action.
     */
    public static function customer(string $action, Model $customer): ActivityLog
    {
        $description = match ($action) {
            'created' => "Created customer: {$customer->name}",
            'deleted' => "Deleted customer: {$customer->name}",
            default => "Customer action: {$action}",
        };

        return ActivityLog::log("customer_{$action}", $description, $customer);
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
