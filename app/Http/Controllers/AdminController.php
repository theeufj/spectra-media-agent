<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Role;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;

class AdminController extends Controller
{
    public function index()
    {
        return redirect()->route('admin.users.index');
    }

    public function usersIndex()
    {
        $users = User::with('roles')->get();
        return Inertia::render('Admin/Users', [
            'users' => $users,
        ]);
    }

    public function customersIndex()
    {
        $customers = Customer::with('user')->get();
        return Inertia::render('Admin/Customers', [
            'customers' => $customers,
        ]);
    }

    public function notificationsIndex()
    {
        return Inertia::render('Admin/Notifications');
    }

    public function promoteToAdmin(User $user)
    {
        $adminRole = Role::where('name', 'admin')->first();
        $user->roles()->syncWithoutDetaching([$adminRole->id]);

        return redirect()->back();
    }

    public function deleteCustomer(Customer $customer)
    {
        $customer->delete();

        return redirect()->back();
    }

    public function banUser(User $user)
    {
        $user->update(['banned_at' => now()]);

        return redirect()->back();
    }

    public function unbanUser(User $user)
    {
        $user->update(['banned_at' => null]);

        return redirect()->back();
    }

    public function sendNotification(Request $request)
    {
        $request->validate([
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
        ]);

        $users = User::all();

        foreach ($users as $user) {
            Mail::to($user->email)->send(new \App\Mail\AdminNotification($user, $request->subject, $request->body));
        }

        return redirect()->back()->with('flash', [
            'type' => 'success',
            'message' => 'Notification sent to all users successfully.'
        ]);
    }
}
