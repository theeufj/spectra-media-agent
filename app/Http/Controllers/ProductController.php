<?php

namespace App\Http\Controllers;

use App\Jobs\SyncProductFeed;
use App\Models\Product;
use App\Models\ProductFeed;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $customer = $this->getActiveCustomer($request);
        if (!$customer) return redirect()->route('dashboard');
        $feeds = ProductFeed::where('customer_id', $customer->id)->get();

        $stats = Product::where('customer_id', $customer->id)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'disapproved' THEN 1 ELSE 0 END) as disapproved,
                SUM(CASE WHEN availability = 'out_of_stock' THEN 1 ELSE 0 END) as out_of_stock,
                SUM(clicks) as total_clicks,
                SUM(conversions) as total_conversions
            ")->first();

        return Inertia::render('Products/Index', [
            'feeds' => $feeds,
            'stats' => $stats,
        ]);
    }

    public function createFeed(Request $request)
    {
        $customer = $this->getActiveCustomer($request);
        if (!$customer) return redirect()->route('dashboard');
        $validated = $request->validate([
            'feed_name' => 'required|string|max:255',
            'merchant_id' => 'required|string',
            'source_type' => 'required|in:api',
            'source_url' => 'nullable|url',
            'sync_frequency' => 'in:hourly,daily,weekly',
        ]);

        $feed = ProductFeed::create(array_merge($validated, [
            'customer_id' => $customer->id,
            'status' => 'pending',
        ]));

        // Trigger initial sync
        SyncProductFeed::dispatch($feed->id);

        return back()->with('success', 'Product feed created. Syncing products...');
    }

    public function syncFeed(Request $request, ProductFeed $feed)
    {
        $customer = $this->getActiveCustomer($request);
        if (!$customer || $feed->customer_id !== $customer->id) {
            abort(403);
        }

        SyncProductFeed::dispatch($feed->id);

        return back()->with('success', 'Feed sync started.');
    }

    public function deleteFeed(Request $request, ProductFeed $feed)
    {
        $customer = $this->getActiveCustomer($request);
        if (!$customer || $feed->customer_id !== $customer->id) {
            abort(403);
        }

        $feed->delete();

        return back()->with('success', 'Feed deleted.');
    }

    public function products(Request $request)
    {
        $customer = $this->getActiveCustomer($request);
        if (!$customer) return redirect()->route('dashboard');
        $query = Product::where('customer_id', $customer->id);

        if ($request->status) {
            $query->where('status', $request->status);
        }

        $products = $query->orderBy('impressions', 'desc')->limit(200)->get();

        return Inertia::render('Products/List', [
            'products' => $products,
            'filter' => $request->status,
        ]);
    }
}
