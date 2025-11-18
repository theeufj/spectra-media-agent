<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\GTM\GTMContainerService;
use App\Services\GTM\GTMDetectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class GTMSetupController extends Controller
{
    /**
     * Show the GTM setup page.
     * 
     * This page automatically shows the correct path (A or B)
     * based on whether GTM was detected on the customer's website.
     */
    public function show(Request $request, Customer $customer)
    {
        try {
            Log::info('Showing GTM setup page', [
                'customer_id' => $customer->id,
                'gtm_detected' => $customer->gtm_detected,
                'gtm_container_id' => $customer->gtm_container_id,
            ]);

            return Inertia::render('Customers/GTM/Setup', [
                'customer' => $customer,
            ]);
        } catch (\Exception $e) {
            Log::error('Error showing GTM setup page', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to load GTM setup page: ' . $e->getMessage());
        }
    }

    /**
     * Path A: Link existing GTM container
     * 
     * Customer provides their existing GTM container ID
     */
    public function linkExistingContainer(Request $request, Customer $customer, GTMContainerService $gtmService)
    {
        try {
            $validated = $request->validate([
                'gtm_container_id' => 'required|string|regex:/^(GTM|GT)-[A-Z0-9]+$/',
            ], [
                'gtm_container_id.regex' => 'Container ID must be in format GTM-XXXXXX',
            ]);

            Log::info('Path A: Linking existing GTM container', [
                'customer_id' => $customer->id,
                'container_id' => $validated['gtm_container_id'],
            ]);

            // Link the container via GTM service
            $result = $gtmService->linkExistingContainer($customer, $validated['gtm_container_id']);

            if (!$result['success']) {
                return back()->withErrors(['gtm_container_id' => $result['error']]);
            }

            // Update customer with linked container
            $customer->update([
                'gtm_container_id' => $validated['gtm_container_id'],
                'gtm_installed' => true,
            ]);

            Log::info('GTM container linked successfully', [
                'customer_id' => $customer->id,
                'container_id' => $validated['gtm_container_id'],
            ]);

            return back()->with([
                'success' => 'GTM container linked successfully!',
                'customer' => $customer->fresh(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error linking GTM container', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to link GTM container: ' . $e->getMessage());
        }
    }

    /**
     * Path B: Create new GTM container
     * 
     * For customers without GTM, we create a new container
     * and provide installation instructions
     */
    public function createNewContainer(Request $request, Customer $customer, GTMContainerService $gtmService)
    {
        try {
            $validated = $request->validate([
                'container_name' => 'required|string|max:255',
                'website_url' => 'required|url',
            ]);

            Log::info('Path B: Creating new GTM container', [
                'customer_id' => $customer->id,
                'container_name' => $validated['container_name'],
            ]);

            // TODO: Implement container creation via GTM API
            // This requires:
            // 1. Create new GTM container via API
            // 2. Store container ID and workspace ID
            // 3. Generate and provide installation snippet

            return back()->with('error', 'New container creation not yet implemented. Coming soon!');
        } catch (\Exception $e) {
            Log::error('Error creating new GTM container', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to create GTM container: ' . $e->getMessage());
        }
    }

    /**
     * Get the current GTM setup status for a customer
     */
    public function getStatus(Customer $customer, GTMContainerService $gtmService)
    {
        try {
            Log::info('Getting GTM setup status', [
                'customer_id' => $customer->id,
            ]);

            $status = [
                'gtm_detected' => $customer->gtm_detected,
                'gtm_installed' => $customer->gtm_installed,
                'container_id' => $customer->gtm_container_id,
                'detected_at' => $customer->gtm_detected_at,
                'installed_at' => $customer->updated_at,
                'path' => $customer->gtm_detected ? 'A' : 'B',
            ];

            // If container is linked, verify access
            if ($customer->gtm_container_id) {
                $verifyResult = $gtmService->verifyContainerAccess($customer);
                $status['verified'] = $verifyResult['success'];
                $status['last_verified_at'] = $customer->gtm_last_verified;
            }

            return response()->json([
                'success' => true,
                'status' => $status,
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting GTM setup status', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to get GTM status',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Verify GTM container access and connectivity
     */
    public function verifyAccess(Customer $customer, GTMContainerService $gtmService)
    {
        try {
            Log::info('Verifying GTM container access', [
                'customer_id' => $customer->id,
            ]);

            $result = $gtmService->verifyContainerAccess($customer);

            if (!$result['success']) {
                return back()->with('error', $result['error']);
            }

            // Update verification timestamp
            $customer->update([
                'gtm_last_verified' => now(),
            ]);

            return back()->with([
                'success' => 'GTM container access verified successfully!',
                'customer' => $customer->fresh(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error verifying GTM container access', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to verify GTM container access: ' . $e->getMessage());
        }
    }

    /**
     * Re-scan customer website for GTM installation
     */
    public function rescan(Request $request, Customer $customer, GTMDetectionService $gtmDetectionService)
    {
        try {
            if (!$customer->website) {
                return back()->with('error', 'Customer does not have a website URL configured');
            }

            Log::info('Re-scanning customer website for GTM', [
                'customer_id' => $customer->id,
                'website' => $customer->website,
            ]);

            // Fetch website content (simplified - ideally use ScrapeCustomerWebsite job)
            $htmlContent = @file_get_contents($customer->website);

            if (!$htmlContent) {
                return back()->with('error', 'Could not fetch website content. Please check the website URL.');
            }

            // Detect GTM
            $metadata = $gtmDetectionService->getDetectionMetadata($htmlContent);

            // Update customer
            $customer->update([
                'gtm_detected' => $metadata['detected'],
                'gtm_container_id' => $metadata['container_id'],
                'gtm_detected_at' => $metadata['detected_at'],
            ]);

            Log::info('Website re-scan completed', [
                'customer_id' => $customer->id,
                'gtm_detected' => $metadata['detected'],
            ]);

            $message = $metadata['detected'] 
                ? 'GTM detected on your website! Container ID: ' . $metadata['container_id']
                : 'No GTM container detected on your website.';

            return back()->with([
                'success' => $message,
                'customer' => $customer->fresh(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error re-scanning website for GTM', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to re-scan website: ' . $e->getMessage());
        }
    }
}
