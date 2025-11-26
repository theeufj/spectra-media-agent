<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ActivityLogController extends Controller
{
    /**
     * Display the activity log dashboard.
     */
    public function index(Request $request)
    {
        $query = ActivityLog::with('user')
            ->orderBy('created_at', 'desc');

        // Filter by action type
        if ($request->action) {
            $query->where('action', $request->action);
        }

        // Filter by user
        if ($request->user_id) {
            $query->where('user_id', $request->user_id);
        }

        // Filter by date range
        if ($request->from) {
            $query->whereDate('created_at', '>=', $request->from);
        }
        if ($request->to) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        // Search in description
        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('description', 'like', "%{$request->search}%")
                  ->orWhere('user_email', 'like', "%{$request->search}%")
                  ->orWhere('user_name', 'like', "%{$request->search}%");
            });
        }

        $logs = $query->paginate(50)->withQueryString();

        // Transform logs for frontend
        $logs->getCollection()->transform(function ($log) {
            return [
                'id' => $log->id,
                'action' => $log->action,
                'actionLabel' => $log->action_label,
                'actionIcon' => $log->action_icon,
                'actionColor' => $log->action_color,
                'description' => $log->description,
                'user' => $log->user ? [
                    'id' => $log->user->id,
                    'name' => $log->user->name,
                    'email' => $log->user->email,
                ] : [
                    'name' => $log->user_name ?? 'System',
                    'email' => $log->user_email,
                ],
                'subject_type' => $log->subject_type ? class_basename($log->subject_type) : null,
                'subject_id' => $log->subject_id,
                'properties' => $log->properties,
                'ip_address' => $log->ip_address,
                'user_agent' => $log->user_agent,
                'created_at' => $log->created_at->format('Y-m-d H:i:s'),
                'created_at_human' => $log->created_at->diffForHumans(),
            ];
        });

        // Get action types for filter dropdown
        $actionTypes = ActivityLog::distinct()
            ->pluck('action')
            ->sort()
            ->values();

        // Get recent users for filter dropdown
        $recentUsers = ActivityLog::whereNotNull('user_id')
            ->distinct()
            ->limit(50)
            ->get(['user_id', 'user_name', 'user_email'])
            ->unique('user_id')
            ->values();

        return Inertia::render('Admin/ActivityLogs', [
            'logs' => $logs,
            'actionTypes' => $actionTypes,
            'recentUsers' => $recentUsers,
            'filters' => [
                'action' => $request->action,
                'user_id' => $request->user_id,
                'from' => $request->from,
                'to' => $request->to,
                'search' => $request->search,
            ],
        ]);
    }

    /**
     * Get activity stats for a quick overview.
     */
    public function stats()
    {
        $todayCount = ActivityLog::whereDate('created_at', today())->count();
        $weekCount = ActivityLog::where('created_at', '>=', now()->subWeek())->count();
        
        $recentLogins = ActivityLog::where('action', 'login')
            ->where('created_at', '>=', now()->subDay())
            ->count();

        $recentImpersonations = ActivityLog::where('action', 'impersonate_start')
            ->where('created_at', '>=', now()->subWeek())
            ->count();

        return response()->json([
            'today' => $todayCount,
            'week' => $weekCount,
            'recentLogins' => $recentLogins,
            'recentImpersonations' => $recentImpersonations,
        ]);
    }

    /**
     * Export activity logs to CSV.
     */
    public function export(Request $request)
    {
        $query = ActivityLog::orderBy('created_at', 'desc');

        // Apply same filters as index
        if ($request->action) {
            $query->where('action', $request->action);
        }
        if ($request->user_id) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->from) {
            $query->whereDate('created_at', '>=', $request->from);
        }
        if ($request->to) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        $logs = $query->limit(10000)->get();

        $filename = 'activity_logs_' . now()->format('Y-m-d_His') . '.csv';
        
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($logs) {
            $file = fopen('php://output', 'w');
            
            // Header row
            fputcsv($file, [
                'Date',
                'Action',
                'User',
                'Email',
                'Description',
                'IP Address',
                'Subject Type',
                'Subject ID',
            ]);

            foreach ($logs as $log) {
                fputcsv($file, [
                    $log->created_at->format('Y-m-d H:i:s'),
                    $log->action,
                    $log->user_name,
                    $log->user_email,
                    $log->description,
                    $log->ip_address,
                    $log->subject_type ? class_basename($log->subject_type) : '',
                    $log->subject_id,
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
