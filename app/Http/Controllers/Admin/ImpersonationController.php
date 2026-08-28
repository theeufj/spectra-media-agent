<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ImpersonationController extends Controller
{
    /**
     * Start impersonating a user.
     */
    public function start(User $user)
    {
        return $this->begin($user);
    }

    /**
     * Start impersonating a customer: step in as one of the humans attached
     * to the account (owner preferred) with that customer pinned as the
     * active workspace — the user may belong to several customers, and
     * landing in the wrong one defeats the point.
     */
    public function startCustomer(Customer $customer)
    {
        $target = $customer->users()
            ->orderByRaw("customer_user.role = 'owner' desc")
            ->orderBy('customer_user.created_at')
            ->get()
            ->first(fn (User $user) => ! $user->hasRole('admin'));

        if (! $target) {
            return redirect()->back()->with('flash', [
                'type' => 'error',
                'message' => 'This customer has no non-admin user to impersonate.',
            ]);
        }

        return $this->begin($target, $customer);
    }

    protected function begin(User $user, ?Customer $customer = null)
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

        // Land in the impersonated user's workspace, not whatever customer
        // the admin's session was pointing at. Left unset, the middleware
        // re-resolves to the user's first customer.
        session()->forget('active_customer_id');
        if ($customer) {
            session(['active_customer_id' => $customer->id]);
        }

        Log::info("Admin {$admin->email} started impersonating user {$user->email}");

        return redirect()->route('dashboard')->with('flash', [
            'type' => 'success',
            'message' => $customer
                ? "You are now impersonating {$user->name} on {$customer->name}."
                : "You are now impersonating {$user->name}.",
        ]);
    }

    /**
     * Stop impersonating and return to admin account.
     */
    public function stop()
    {
        $impersonatedUserId = session('impersonate_user_id');
        $adminId = session('impersonate_admin_id');

        if (! $adminId) {
            return redirect()->route('dashboard');
        }

        $admin = User::find($adminId);
        $impersonatedUser = User::find($impersonatedUserId);

        if ($impersonatedUser) {
            // Log with admin context before clearing session
            Auth::setUser($admin);
            ActivityLogger::impersonateStop($impersonatedUser);
        }

        // Clear impersonation session — including the customer the admin was
        // browsing as, which isn't theirs.
        session()->forget(['impersonate_admin_id', 'impersonate_user_id', 'impersonate_user_name', 'active_customer_id']);

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
        if (! self::isImpersonating()) {
            return null;
        }

        return [
            'isImpersonating' => true,
            'userName' => session('impersonate_user_name'),
            'userId' => session('impersonate_user_id'),
        ];
    }
}
