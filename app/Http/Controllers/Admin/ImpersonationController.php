<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ImpersonationController extends Controller
{
    /**
     * Start impersonating a user.
     */
    public function start(User $user)
    {
        $admin = auth()->user();

        // Can't impersonate yourself
        if ($admin->id === $user->id) {
            return redirect()->back()->with('flash', [
                'type' => 'error',
                'message' => 'You cannot impersonate yourself.',
            ]);
        }

        // Can't impersonate another admin
        if ($user->hasRole('admin')) {
            return redirect()->back()->with('flash', [
                'type' => 'error',
                'message' => 'You cannot impersonate another admin.',
            ]);
        }

        // Log the impersonation
        ActivityLogger::impersonateStart($user);

        // Store admin's ID and start impersonation
        session([
            'impersonate_admin_id' => $admin->id,
            'impersonate_user_id' => $user->id,
            'impersonate_user_name' => $user->name,
        ]);

        Log::info("Admin {$admin->email} started impersonating user {$user->email}");

        return redirect()->route('dashboard')->with('flash', [
            'type' => 'success',
            'message' => "You are now impersonating {$user->name}.",
        ]);
    }

    /**
     * Stop impersonating and return to admin account.
     */
    public function stop()
    {
        $impersonatedUserId = session('impersonate_user_id');
        $adminId = session('impersonate_admin_id');

        if (!$adminId) {
            return redirect()->route('dashboard');
        }

        $admin = User::find($adminId);
        $impersonatedUser = User::find($impersonatedUserId);

        if ($impersonatedUser) {
            // Log with admin context before clearing session
            Auth::setUser($admin);
            ActivityLogger::impersonateStop($impersonatedUser);
        }

        // Clear impersonation session
        session()->forget(['impersonate_admin_id', 'impersonate_user_id', 'impersonate_user_name']);

        // Re-authenticate as admin
        Auth::login($admin);

        Log::info("Admin {$admin->email} stopped impersonating user");

        return redirect()->route('admin.users.index')->with('flash', [
            'type' => 'success',
            'message' => 'You have stopped impersonating.',
        ]);
    }

    /**
     * Check if currently impersonating.
     */
    public static function isImpersonating(): bool
    {
        return session()->has('impersonate_user_id');
    }

    /**
     * Get impersonation info for the frontend.
     */
    public static function getImpersonationInfo(): ?array
    {
        if (!self::isImpersonating()) {
            return null;
        }

        return [
            'isImpersonating' => true,
            'userName' => session('impersonate_user_name'),
            'userId' => session('impersonate_user_id'),
        ];
    }
}
