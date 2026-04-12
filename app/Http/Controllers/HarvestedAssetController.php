<?php

namespace App\Http\Controllers;

use App\Jobs\HarvestWebsiteAssets;
use App\Jobs\ProcessHarvestedAsset;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\HarvestedAsset;
use App\Models\ImageCollateral;
use App\Models\Strategy;
use App\Services\StorageHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class HarvestedAssetController extends Controller
{
    /**
     * List harvested assets for the customer (JSON endpoint for the collateral page).
     */
    public function index(Request $request)
    {
        $customer = $this->resolveCustomer($request);
        if (!$customer) {
            return response()->json(['assets' => []]);
        }

        $assets = HarvestedAsset::where('customer_id', $customer->id)
            ->whereIn('status', ['classified', 'processed'])
            ->whereIn('classification', ['product', 'lifestyle', 'team'])
            ->orderByRaw("CASE classification WHEN 'product' THEN 1 WHEN 'lifestyle' THEN 2 WHEN 'team' THEN 3 ELSE 4 END")
            ->orderByDesc('original_width')
            ->get()
            ->map(fn (HarvestedAsset $a) => [
                'id' => $a->id,
                'cloudfront_url' => $a->cloudfront_url,
                'bg_removed_url' => $a->bg_removed_url,
                'classification' => $a->classification,
                'description' => $a->classification_details['description'] ?? null,
                'width' => $a->original_width,
                'height' => $a->original_height,
                'status' => $a->status,
                'variants' => $a->variants,
                'source_page_url' => $a->source_page_url,
            ]);

        return response()->json(['assets' => $assets]);
    }

    /**
     * Trigger asset harvesting for the customer's website.
     */
    public function harvest(Request $request)
    {
        $customer = $this->resolveCustomer($request);
        if (!$customer) {
            return redirect()->route('customers.create');
        }

        // Check if harvest is already running or recently completed
        $recentCount = HarvestedAsset::where('customer_id', $customer->id)
            ->where('created_at', '>', now()->subHours(1))
            ->count();

        if ($recentCount > 0) {
            return redirect()->back()->with('flash', [
                'type' => 'warning',
                'message' => 'Asset harvesting was already run recently. Assets are being processed.',
            ]);
        }

        HarvestWebsiteAssets::dispatch($customer);

        return redirect()->back()->with('flash', [
            'type' => 'success',
            'message' => 'Asset harvesting started. Images will appear on your collateral page as they are processed.',
        ]);
    }

    /**
     * Use a harvested asset as collateral for a campaign strategy.
     * This creates an ImageCollateral record from the harvested asset.
     */
    public function useAsCollateral(Request $request, HarvestedAsset $asset)
    {
        $request->validate([
            'campaign_id' => ['required', 'integer', 'exists:campaigns,id'],
            'strategy_id' => ['required', 'integer', 'exists:strategies,id'],
            'variant' => ['nullable', 'string', 'in:landscape,square,vertical,original,bg_removed'],
        ]);

        $customer = $this->resolveCustomer($request);
        if (!$customer || $asset->customer_id !== $customer->id) {
            abort(403);
        }

        $campaign = Campaign::findOrFail($request->input('campaign_id'));
        if ($campaign->customer_id !== $customer->id) {
            abort(403);
        }

        $strategy = Strategy::findOrFail($request->input('strategy_id'));
        if ($strategy->campaign_id !== $campaign->id) {
            abort(403);
        }

        // Determine which image to use
        $variant = $request->input('variant', 'original');
        $s3Path = $asset->s3_path;
        $url = $asset->cloudfront_url;

        if ($variant === 'bg_removed' && $asset->bg_removed_s3_path) {
            $s3Path = $asset->bg_removed_s3_path;
            $url = $asset->bg_removed_url;
        } elseif (in_array($variant, ['landscape', 'square', 'vertical'])) {
            $variants = $asset->variants ?? [];
            if (isset($variants[$variant])) {
                $s3Path = $variants[$variant]['s3_path'];
                $url = $variants[$variant]['url'];
            }
        }

        // If variant was requested but doesn't exist yet, process the asset first
        $needsProcessing = false;
        if ($variant === 'bg_removed' && !$asset->bg_removed_s3_path) {
            $needsProcessing = true;
        } elseif (in_array($variant, ['landscape', 'square', 'vertical']) && !isset(($asset->variants ?? [])[$variant])) {
            $needsProcessing = true;
        }

        if ($needsProcessing) {
            // Dispatch processing and use original for now
            ProcessHarvestedAsset::dispatch($asset);

            return redirect()->back()->with('flash', [
                'type' => 'info',
                'message' => 'Processing this asset for the requested variant. It will be ready shortly — using the original in the meantime.',
            ]);
        }

        $collateral = ImageCollateral::create([
            'campaign_id' => $campaign->id,
            'strategy_id' => $strategy->id,
            'platform' => $strategy->platform,
            's3_path' => $s3Path,
            'cloudfront_url' => $url,
            'is_active' => true,
            'source' => 'harvested',
        ]);

        return redirect()->back()->with('flash', [
            'type' => 'success',
            'message' => 'Asset added to campaign collateral.',
        ]);
    }

    private function resolveCustomer(Request $request): ?Customer
    {
        $user = Auth::user();
        return $user->customer ?? $user->customers()->first();
    }
}
