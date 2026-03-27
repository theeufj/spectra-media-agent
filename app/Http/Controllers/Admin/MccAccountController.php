<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MccAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Inertia\Inertia;

class MccAccountController extends Controller
{
    public function index()
    {
        $accounts = MccAccount::orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn ($account) => [
                'id' => $account->id,
                'name' => $account->name,
                'google_customer_id' => $account->google_customer_id,
                'is_active' => $account->is_active,
                'notes' => $account->notes,
                'has_refresh_token' => !empty($account->refresh_token),
                'created_at' => $account->created_at,
                'updated_at' => $account->updated_at,
            ]);

        // Check if currently using env fallback (no DB accounts active)
        $usingEnvFallback = !MccAccount::active()->exists()
            && config('googleads.mcc_customer_id')
            && config('googleads.mcc_refresh_token');

        return Inertia::render('Admin/MccAccounts', [
            'accounts' => $accounts,
            'usingEnvFallback' => $usingEnvFallback,
            'envCustomerId' => $usingEnvFallback ? config('googleads.mcc_customer_id') : null,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'google_customer_id' => 'required|string|max:20',
            'refresh_token' => 'required|string',
            'notes' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
        ]);

        // Strip dashes from customer ID (users may paste "558-450-6211")
        $validated['google_customer_id'] = preg_replace('/[^0-9]/', '', $validated['google_customer_id']);

        // Encrypt the refresh token before storing
        $validated['refresh_token'] = Crypt::encryptString($validated['refresh_token']);

        $account = MccAccount::create($validated);

        // If set as active, deactivate others
        if ($account->is_active) {
            $account->activate();
        }

        return redirect()->route('admin.mcc-accounts.index')->with('flash', [
            'type' => 'success',
            'message' => 'MCC account added successfully.',
        ]);
    }

    public function update(Request $request, MccAccount $mccAccount)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'google_customer_id' => 'required|string|max:20',
            'refresh_token' => 'nullable|string',
            'notes' => 'nullable|string|max:1000',
        ]);

        $validated['google_customer_id'] = preg_replace('/[^0-9]/', '', $validated['google_customer_id']);

        // Only update refresh token if a new one is provided
        if (!empty($validated['refresh_token'])) {
            $validated['refresh_token'] = Crypt::encryptString($validated['refresh_token']);
        } else {
            unset($validated['refresh_token']);
        }

        $mccAccount->update($validated);

        return redirect()->route('admin.mcc-accounts.index')->with('flash', [
            'type' => 'success',
            'message' => 'MCC account updated successfully.',
        ]);
    }

    public function activate(MccAccount $mccAccount)
    {
        $mccAccount->activate();

        return redirect()->route('admin.mcc-accounts.index')->with('flash', [
            'type' => 'success',
            'message' => "'{$mccAccount->name}' is now the active MCC account.",
        ]);
    }

    public function destroy(MccAccount $mccAccount)
    {
        if ($mccAccount->is_active) {
            return redirect()->route('admin.mcc-accounts.index')->with('flash', [
                'type' => 'error',
                'message' => 'Cannot delete the active MCC account. Activate another account first.',
            ]);
        }

        $mccAccount->delete();

        return redirect()->route('admin.mcc-accounts.index')->with('flash', [
            'type' => 'success',
            'message' => 'MCC account deleted.',
        ]);
    }
}
