<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class NotificationController extends Controller
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Display the notifications page.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Inertia\Response
     */
    public function page(Request $request)
    {
        $user = $request->user();
        $customerId = session('active_customer_id');
        
        // Get all notifications (not just recent)
        $notifications = Notification::where('user_id', $user->id)
            ->when($customerId, function ($query) use ($customerId) {
                $query->where(function ($q) use ($customerId) {
                    $q->where('customer_id', $customerId)
                      ->orWhereNull('customer_id');
                });
            })
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        // Also get dynamic notifications
        $dynamicNotifications = $this->getDynamicNotifications($user, $customerId);

        return Inertia::render('Notifications/Index', [
            'notifications' => $notifications,
            'dynamicNotifications' => $dynamicNotifications->toArray(),
            'unreadCount' => Notification::where('user_id', $user->id)
                ->whereNull('read_at')
                ->count(),
        ]);
    }

    /**
     * Get notifications for the authenticated user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json(['notifications' => [], 'unread_count' => 0]);
            }
            
            $customerId = session('active_customer_id');
            
            // Get persisted notifications from database
            $persistedNotifications = collect($this->notificationService->getRecentNotifications(
                $user, 
                $customerId, 
                20
            ));

            // Also get dynamic notifications (campaigns needing attention)
            $dynamicNotifications = $this->getDynamicNotifications($user, $customerId);

            // Merge and sort by created_at
            $allNotifications = $persistedNotifications
                ->concat($dynamicNotifications)
                ->sortByDesc('created_at')
                ->take(20)
                ->values();

            // Count unread
            $unreadCount = $allNotifications->filter(function ($n) {
                return empty($n['read_at']) && (isset($n['read']) ? !$n['read'] : true);
            })->count();

            return response()->json([
                'notifications' => $allNotifications->toArray(),
                'unread_count' => $unreadCount,
            ]);
        } catch (\Exception $e) {
            \Log::error('NotificationController error: ' . $e->getMessage());
            return response()->json(['notifications' => [], 'unread_count' => 0]);
        }
    }

    /**
     * Get dynamic notifications based on campaign/strategy status.
     */
    protected function getDynamicNotifications($user, $customerId): \Illuminate\Support\Collection
    {
        $notifications = collect();
        
        if (!$customerId) {
            return $notifications;
        }

        $customer = $user->customers()->find($customerId);
        
        if (!$customer) {
            return $notifications;
        }

        // Get dismissed notifications from session
        $dismissedNotifications = session('dismissed_notifications', []);

        // Check for campaigns needing attention (strategy not signed off)
        try {
            $campaignsNeedingSignoff = $customer->campaigns()
                ->whereHas('strategies', function ($query) {
                    $query->whereNull('signed_off_at');
                })
                ->where(function ($q) {
                    $q->whereNotNull('strategy_generation_completed_at')
                      ->orWhereHas('strategies');
                })
                ->with(['strategies' => function ($query) {
                    $query->whereNull('signed_off_at');
                }])
                ->get();

            foreach ($campaignsNeedingSignoff as $campaign) {
                $notificationId = 'signoff-' . $campaign->id;
                $unsignedCount = $campaign->strategies->count();
                
                if ($unsignedCount > 0) {
                    // Check if this notification was dismissed
                    $readAt = $dismissedNotifications[$notificationId] ?? null;
                    
                    $notifications->push([
                        'id' => $notificationId,
                        'type' => Notification::TYPE_STRATEGY_READY,
                        'title' => 'Strategy Ready for Review',
                        'message' => "Campaign \"{$campaign->name}\" has {$unsignedCount} " . ($unsignedCount === 1 ? 'strategy' : 'strategies') . " ready for sign-off.",
                        'icon' => '📋',
                        'action_url' => route('campaigns.show', $campaign),
                        'action_text' => 'Review Strategies',
                        'read_at' => $readAt,
                        'created_at' => $campaign->updated_at->toISOString(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            \Log::warning('Failed to fetch campaigns needing signoff: ' . $e->getMessage());
        }

        // Check for campaigns with completed collateral ready to deploy
        try {
            $campaignsReadyToDeploy = $customer->campaigns()
                ->whereHas('strategies', function ($query) {
                    $query->whereNotNull('signed_off_at')
                        ->whereNull('deployed_at')
                        ->where(function ($q) {
                            $q->whereHas('adCopies')
                                ->orWhereHas('imageCollaterals');
                        });
                })
                ->with(['strategies' => function ($query) {
                    $query->whereNotNull('signed_off_at')
                        ->whereNull('deployed_at');
                }])
                ->get();

            foreach ($campaignsReadyToDeploy as $campaign) {
                $strategy = $campaign->strategies->first();
                if ($strategy) {
                    $notificationId = 'deploy-' . $campaign->id;
                    // Check if this notification was dismissed
                    $readAt = $dismissedNotifications[$notificationId] ?? null;
                    
                    $notifications->push([
                        'id' => $notificationId,
                        'type' => Notification::TYPE_COLLATERAL_READY,
                        'title' => 'Collateral Ready',
                        'message' => "Campaign \"{$campaign->name}\" has collateral ready to deploy.",
                        'icon' => '🎨',
                        'action_url' => route('campaigns.collateral.show', ['campaign' => $campaign->id, 'strategy' => $strategy->id]),
                        'action_text' => 'View Collateral',
                        'read_at' => $readAt,
                        'created_at' => $strategy->updated_at->toISOString(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            \Log::warning('Failed to fetch campaigns ready to deploy: ' . $e->getMessage());
        }

        return $notifications;
    }

    /**
     * Mark a notification as read.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $notificationId
     * @return \Illuminate\Http\JsonResponse
     */
    public function markAsRead(Request $request, string $notificationId)
    {
        // Handle dynamic notification IDs (e.g., 'signoff-123')
        if (str_starts_with($notificationId, 'signoff-') || str_starts_with($notificationId, 'deploy-') || str_starts_with($notificationId, 'deployed-')) {
            // Store in session that this notification was dismissed
            $dismissed = session('dismissed_notifications', []);
            $dismissed[$notificationId] = now()->toISOString();
            session(['dismissed_notifications' => $dismissed]);
            
            return response()->json(['success' => true]);
        }

        // Handle persistent notifications
        $success = $this->notificationService->markAsRead($notificationId);
        
        return response()->json(['success' => $success]);
    }

    /**
     * Mark all notifications as read.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function markAllAsRead(Request $request)
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $customerId = session('active_customer_id');
        
        // Mark all persistent notifications as read
        $count = $this->notificationService->markAllAsRead($user, $customerId);

        // Also mark all dynamic notifications as read by adding them to dismissed
        $dynamicNotifications = $this->getDynamicNotifications($user, $customerId);
        $dismissed = session('dismissed_notifications', []);
        $now = now()->toISOString();
        
        foreach ($dynamicNotifications as $notification) {
            if (empty($notification['read_at'])) {
                $dismissed[$notification['id']] = $now;
            }
        }
        
        session(['dismissed_notifications' => $dismissed]);
        
        return response()->json([
            'success' => true,
            'marked_count' => $count + $dynamicNotifications->whereNull('read_at')->count(),
        ]);
    }

    /**
     * Delete a notification.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $notificationId
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(Request $request, string $notificationId)
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $notification = Notification::where('id', $notificationId)
            ->where('user_id', $user->id)
            ->first();

        if (!$notification) {
            return response()->json(['success' => false, 'message' => 'Notification not found'], 404);
        }

        $notification->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Get notification preferences.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function preferences(Request $request)
    {
        $user = $request->user();
        
        // Default preferences
        $defaults = [
            'email_notifications' => true,
            'browser_notifications' => true,
            'notification_types' => [
                'campaign.strategy_ready' => true,
                'campaign.collateral_ready' => true,
                'deployment.started' => true,
                'deployment.completed' => true,
                'deployment.failed' => true,
                'billing.warning' => true,
                'billing.success' => true,
            ],
        ];

        $preferences = $user->notification_preferences ?? $defaults;

        return response()->json([
            'preferences' => array_merge($defaults, $preferences),
        ]);
    }

    /**
     * Update notification preferences.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updatePreferences(Request $request)
    {
        $user = $request->user();
        
        $validated = $request->validate([
            'email_notifications' => 'boolean',
            'browser_notifications' => 'boolean',
            'notification_types' => 'array',
            'notification_types.*' => 'boolean',
        ]);

        $user->update([
            'notification_preferences' => $validated,
        ]);

        return response()->json([
            'success' => true,
            'preferences' => $validated,
        ]);
    }
}
