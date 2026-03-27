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
     * Passes the container ID (if provisioned) and snippet data to the view.
     */
    public function show(Request $request, Customer $customer, GTMContainerService $gtmService)
    {
        $snippet = null;
        if ($customer->gtm_container_id) {
            $snippet = $gtmService->getSnippetHtml($customer->gtm_container_id);
        }

        return Inertia::render('Customers/GTM/Setup', [
            'customer' => $customer,
            'snippet'  => $snippet,
        ]);
    }

    /**
     * Provision a platform-managed GTM container for this customer.
     * Creates the container under our platform GTM account, stores the ID,
     * and returns the snippet for installation.
     */
    public function provision(Request $request, Customer $customer, GTMContainerService $gtmService)
    {
        if ($customer->gtm_container_id) {
            $snippet = $gtmService->getSnippetHtml($customer->gtm_container_id);
            return back()->with([
                'success'  => 'GTM container already provisioned.',
                'snippet'  => $snippet,
                'customer' => $customer->fresh(),
            ]);
        }

        $result = $gtmService->provisionContainerForCustomer($customer);

        if (!$result['success']) {
            return back()->with('error', 'Failed to provision GTM container: ' . $result['error']);
        }

        $snippet = $gtmService->getSnippetHtml($result['container_id']);

        return back()->with([
            'success'  => 'GTM container created! Add the snippet below to your website.',
            'snippet'  => $snippet,
            'customer' => $customer->fresh(),
        ]);
    }

    /**
     * Verify the GTM snippet is installed on the customer's website by scanning it.
     */
    public function verifyInstalled(Request $request, Customer $customer, GTMContainerService $gtmService)
    {
        if (!$customer->gtm_container_id) {
            return back()->with('error', 'No GTM container has been provisioned yet.');
        }

        if (!$customer->website) {
            return back()->with('error', 'No website URL is configured for this customer.');
        }

        $result = $gtmService->verifySnippetInstalled($customer);

        if (!$result['success']) {
            return back()->with('error', 'Could not verify installation: ' . $result['error']);
        }

        if ($result['installed']) {
            return back()->with([
                'success'  => 'Snippet verified! Conversion tracking is active.',
                'customer' => $customer->fresh(),
            ]);
        }

        return back()->with([
            'error'    => 'Snippet not detected yet. Make sure you\'ve added both the <head> and <body> snippets, then try again.',
            'customer' => $customer->fresh(),
        ]);
    }

    /**
     * Re-scan customer website for any GTM container (including pre-existing ones).
     */
    public function rescan(Request $request, Customer $customer, GTMDetectionService $gtmDetectionService)
    {
        if (!$customer->website) {
            return back()->with('error', 'Customer does not have a website URL configured');
        }

        try {
            $htmlContent = \Spatie\Browsershot\Browsershot::url($customer->website)
                ->setNodeBinary(config('browsershot.node_binary_path'))
                ->addChromiumArguments(config('browsershot.chrome_args', []))
                ->timeout(30)
                ->waitUntilNetworkIdle()
                ->bodyHtml();
        } catch (\Exception $e) {
            Log::warning('GTMSetupController: Browsershot failed, falling back to HTTP', ['error' => $e->getMessage()]);
            $htmlContent = @file_get_contents($customer->website);
        }

        if (!$htmlContent) {
            return back()->with('error', 'Could not fetch website content. Please check the website URL.');
        }

        $metadata = $gtmDetectionService->getDetectionMetadata($htmlContent);

        $customer->update([
            'gtm_detected'    => $metadata['detected'],
            'gtm_detected_at' => $metadata['detected_at'],
        ]);

        // If they already have a provisioned container this is just a detection scan
        $message = $metadata['detected']
            ? 'GTM detected on your website (container: ' . $metadata['container_id'] . ')'
            : 'No GTM container detected on your website.';

        return back()->with(['success' => $message, 'customer' => $customer->fresh()]);
    }

    /**
     * JSON endpoint: return current GTM status.
     */
    public function getStatus(Customer $customer, GTMContainerService $gtmService)
    {
        return response()->json([
            'success' => true,
            'status'  => [
                'provisioned'    => (bool) $customer->gtm_container_id,
                'container_id'   => $customer->gtm_container_id,
                'gtm_installed'  => $customer->gtm_installed,
                'last_verified'  => $customer->gtm_last_verified,
                'gtm_detected'   => $customer->gtm_detected,
            ],
        ]);
    }
}
