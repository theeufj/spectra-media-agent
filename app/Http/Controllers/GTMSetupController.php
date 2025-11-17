<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\GTM\GTMContainerService;
use App\Services\GTM\GTMDetectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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

            // Determine which path the customer should take
            $path = $customer->gtm_detected ? 'A' : 'B';

            return response()->json([
                'path' => $path,
                'message' => $path === 'A' 
                    ? 'Great! We detected Google Tag Manager on your website.'
                    : 'We didn\'t detect Google Tag Manager on your website yet.',
                'gtm_detected' => $customer->gtm_detected,
                'detected_container_id' => $customer->gtm_container_id,
            ]);
        } catch (\Exception $e) {
            Log::error('Error showing GTM setup page', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to load GTM setup page',
                'message' => $e->getMessage(),
            ], 500);
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
                'container_id' => 'required|string|regex:/^(GTM|GT)-[A-Z0-9]+$/',
            ], [
                'container_id.regex' => 'Container ID must be in format GTM-XXXXXX',
            ]);

            Log::info('Path A: Linking existing GTM container', [
                'customer_id' => $customer->id,
                'container_id' => $validated['container_id'],
            ]);

            // Link the container via GTM service
            $result = $gtmService->linkExistingContainer($customer, $validated['container_id']);

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'error' => $result['error'],
                ], 400);
            }

            // Update customer with linked container
            $customer->update([
                'gtm_container_id' => $validated['container_id'],
                'gtm_installed' => true,
            ]);

            Log::info('GTM container linked successfully', [
                'customer_id' => $customer->id,
                'container_id' => $validated['container_id'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'GTM container linked successfully',
                'data' => $result['data'],
                'next_step' => 'gtm.setup.conversion_tags',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error linking GTM container', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to link GTM container',
                'message' => $e->getMessage(),
            ], 500);
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
            Log::info('Path B: Creating new GTM container', [
                'customer_id' => $customer->id,
            ]);

            // TODO: Implement container creation via GTM API
            // This requires:
            // 1. Customer OAuth authorization for GTM account access
            // 2. Create new GTM container
            // 3. Generate install snippet
            // 4. Provide to customer

            return response()->json([
                'success' => false,
                'message' => 'New container creation not yet implemented',
                'status' => 'coming_soon',
            ], 501);
        } catch (\Exception $e) {
            Log::error('Error creating new GTM container', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to create GTM container',
                'message' => $e->getMessage(),
            ], 500);
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
                return response()->json([
                    'success' => false,
                    'error' => $result['error'],
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => 'GTM container access verified',
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error('Error verifying GTM container access', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to verify GTM container access',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Re-scan customer website for GTM installation
     */
    public function rescan(Request $request, Customer $customer, GTMDetectionService $gtmDetectionService)
    {
        try {
            if (!$customer->website) {
                return response()->json([
                    'success' => false,
                    'error' => 'Customer does not have a website URL configured',
                ], 400);
            }

            Log::info('Re-scanning customer website for GTM', [
                'customer_id' => $customer->id,
                'website' => $customer->website,
            ]);

            // Fetch website content (simplified - ideally use ScrapeCustomerWebsite job)
            $htmlContent = @file_get_contents($customer->website);

            if (!$htmlContent) {
                return response()->json([
                    'success' => false,
                    'error' => 'Could not fetch website content',
                ], 400);
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

            return response()->json([
                'success' => true,
                'message' => 'Website scanned successfully',
                'data' => $metadata,
            ]);
        } catch (\Exception $e) {
            Log::error('Error re-scanning website for GTM', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to re-scan website',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
