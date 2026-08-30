<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

/**
 * Admin management of user accounts: roles, plans, inbox assignment, bans.
 *
 * Extracted from the former 1,000-line AdminController.
 */
class UserController extends Controller
{
    public function usersIndex()
    {
        $users = User::with(['roles', 'assignedPlan', 'emailInbox'])->get();
        $plans = \App\Models\Plan::active()->ordered()->get();

        return Inertia::render('Admin/Users', [
            'users' => $users,
            'plans' => $plans,
        ]);
    }

    public function assignInbox(Request $request, User $user)
    {
        $validated = $request->validate([
            'email_address' => 'required|email|unique:email_inboxes,email_address,'.($user->emailInbox?->id ?? 0),
            'display_name' => 'required|string|max:100',
        ]);

        \App\Models\EmailInbox::updateOrCreate(
            ['user_id' => $user->id],
            [
                'email_address' => strtolower($validated['email_address']),
                'display_name' => $validated['display_name'],
            ]
        );

        Log::info('Admin assigned inbox to user', ['user_id' => $user->id, 'email' => $validated['email_address']]);

        return redirect()->back()->with('success', "Inbox {$validated['email_address']} assigned to {$user->name}.");
    }

    public function removeInbox(User $user)
    {
        $user->emailInbox?->delete();

        Log::info('Admin removed inbox from user', ['user_id' => $user->id]);

        return redirect()->back()->with('success', "Inbox removed from {$user->name}.");
    }

    public function assignPlan(Request $request, User $user)
    {
        $validated = $request->validate([
            'plan_id' => 'nullable|exists:plans,id',
        ]);

        $user->assigned_plan_id = $validated['plan_id'];
        // When admin promotes a user to a plan, mark them as active so they pass subscription gates.
        // When a plan is removed, revert to guest.
        $user->subscription_status = $validated['plan_id'] ? 'active' : 'guest';
        $user->save();

        $planName = $validated['plan_id'] ? \App\Models\Plan::find($validated['plan_id'])->name : 'None';
        Log::info('Admin assigned plan to user', [
            'user_id' => $user->id,
            'plan_id' => $validated['plan_id'],
            'plan_name' => $planName,
        ]);

        return redirect()->back()->with('success', "Plan '{$planName}' assigned to {$user->name}.");
    }

    public function promoteToAdmin(User $user)
    {
        $adminRole = Role::where('name', 'admin')->first();
        $user->roles()->syncWithoutDetaching([$adminRole->id]);

        ActivityLogger::userPromoted($user);

        return redirect()->back();
    }

    public function deleteUser(User $user)
    {
        if ($user->hasRole('admin')) {
            return redirect()->back()->with('error', 'Cannot delete an admin user.');
        }

        ActivityLogger::log('user_deleted', "Deleted user: {$user->name} ({$user->email})");
        $user->roles()->detach();
        // No customers()->detach() here: UserObserver::deleting reads the pivot
        // to decide each customer's fate, and detaching first left it looking at
        // a user who owned nothing — silently orphaning every customer they had.
        $user->delete();

        return redirect()->back()->with('success', "User '{$user->name}' has been deleted.");
    }

    public function banUser(User $user)
    {
        $user->update(['banned_at' => now()]);
        ActivityLogger::userBanned($user);

        return redirect()->back();
    }

    public function unbanUser(User $user)
    {
        $user->update(['banned_at' => null]);
        ActivityLogger::userUnbanned($user);

        return redirect()->back();
    }
}
