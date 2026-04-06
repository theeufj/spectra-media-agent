<?php

namespace App\Http\Controllers;

use App\Jobs\SyncCrmConversions;
use App\Jobs\UploadOfflineConversions;
use App\Models\CrmIntegration;
use App\Models\OfflineConversion;
use App\Services\Crm\CrmConnectorFactory;
use Illuminate\Http\Request;
use Inertia\Inertia;

class IntegrationController extends Controller
{
    public function index(Request $request)
    {
        $customer = $request->user()->customer;
        $integrations = CrmIntegration::where('customer_id', $customer->id)->get();

        $conversionStats = OfflineConversion::where('customer_id', $customer->id)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN upload_status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN upload_status LIKE 'uploaded%' THEN 1 ELSE 0 END) as uploaded,
                SUM(CASE WHEN upload_status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(conversion_value) as total_value
            ")->first();

        return Inertia::render('Integrations/Index', [
            'integrations' => $integrations,
            'conversionStats' => $conversionStats,
            'availableProviders' => [
                ['id' => 'hubspot', 'name' => 'HubSpot', 'description' => 'Sync deals and contacts from HubSpot CRM'],
                ['id' => 'salesforce', 'name' => 'Salesforce', 'description' => 'Import opportunities from Salesforce'],
            ],
        ]);
    }

    public function connect(Request $request)
    {
        $customer = $request->user()->customer;
        $validated = $request->validate([
            'provider' => 'required|in:hubspot,salesforce',
            'access_token' => 'required|string',
            'instance_url' => 'nullable|url', // for Salesforce
        ]);

        $credentials = ['access_token' => $validated['access_token']];
        if ($validated['instance_url'] ?? null) {
            $credentials['instance_url'] = $validated['instance_url'];
        }

        $integration = CrmIntegration::updateOrCreate(
            ['customer_id' => $customer->id, 'provider' => $validated['provider']],
            ['credentials' => $credentials, 'status' => 'connected']
        );

        // Test connection
        try {
            $connector = CrmConnectorFactory::make($integration);
            if (!$connector->testConnection()) {
                $integration->update(['status' => 'error', 'last_error' => 'Connection test failed']);
                return back()->with('error', 'Could not connect. Check your credentials.');
            }
        } catch (\Exception $e) {
            $integration->update(['status' => 'error', 'last_error' => $e->getMessage()]);
            return back()->with('error', 'Connection failed: ' . $e->getMessage());
        }

        return back()->with('success', ucfirst($validated['provider']) . ' connected successfully.');
    }

    public function disconnect(Request $request, CrmIntegration $integration)
    {
        if ($integration->customer_id !== $request->user()->customer->id) {
            abort(403);
        }

        $integration->update([
            'status' => 'disconnected',
            'credentials' => null,
        ]);

        return back()->with('success', 'Integration disconnected.');
    }

    public function sync(Request $request, CrmIntegration $integration)
    {
        if ($integration->customer_id !== $request->user()->customer->id) {
            abort(403);
        }

        if (!$integration->isConnected()) {
            return back()->with('error', 'Integration is not connected.');
        }

        SyncCrmConversions::dispatch($integration->id);

        return back()->with('success', 'Sync started. Conversions will be processed in the background.');
    }

    public function conversions(Request $request)
    {
        $customer = $request->user()->customer;
        $conversions = OfflineConversion::where('customer_id', $customer->id)
            ->orderBy('conversion_time', 'desc')
            ->limit(100)
            ->get();

        return Inertia::render('Integrations/Conversions', [
            'conversions' => $conversions,
        ]);
    }

    public function retryUpload(Request $request)
    {
        $customer = $request->user()->customer;
        OfflineConversion::where('customer_id', $customer->id)
            ->where('upload_status', 'failed')
            ->update(['upload_status' => 'pending']);

        UploadOfflineConversions::dispatch($customer->id);

        return back()->with('success', 'Retrying failed uploads.');
    }
}
