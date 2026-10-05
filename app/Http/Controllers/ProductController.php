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
        if (! $customer) {
            return redirect()->route('dashboard');
        }
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

        $payload = ['feeds' => $feeds, 'stats' => $stats, 'working' => $feeds->contains(fn ($feed) => in_array($feed->status, ['pending', 'processing'], true))];

        return $request->expectsJson() ? response()->json($payload) : Inertia::render('Products/Index', $payload);
    }

    public function createFeed(Request $request)
    {
        $customer = $this->getActiveCustomer($request);
        if (! $customer) {
            return redirect()->route('dashboard');
        }
        $this->authorize('create', ProductFeed::class);
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

    public function updateFeed(Request $request, ProductFeed $feed)
    {
        $this->authorize('update', $feed);
        $data = $request->validate(['feed_name' => 'required|string|max:255', 'merchant_id' => 'required|string|max:100', 'sync_frequency' => 'required|in:hourly,daily,weekly']);
        $feed->update($data + ['source_type' => 'api', 'status' => 'pending', 'last_error' => null]);
        SyncProductFeed::dispatch($feed->id);

        return back()->with('success', 'Feed updated. Checking access and syncing products.');
    }

    public function syncFeed(Request $request, ProductFeed $feed)
    {
        $customer = $this->getActiveCustomer($request);
        if (! $customer || $feed->customer_id !== $customer->id) {
            abort(403);
        }

        $this->authorize('update', $feed);
        if (in_array($feed->status, ['pending', 'processing'], true)) {
            return back()->with('success', 'This feed is already queued or syncing.');
        }
        $feed->update(['status' => 'pending', 'last_error' => null]);
        SyncProductFeed::dispatch($feed->id);

        return back()->with('success', 'Feed sync queued. This page will update when it finishes.');
    }

    public function deleteFeed(Request $request, ProductFeed $feed)
    {
        $customer = $this->getActiveCustomer($request);
        if (! $customer || $feed->customer_id !== $customer->id) {
            abort(403);
        }

        $this->authorize('delete', $feed);
        $feed->delete();

        return back()->with('success', 'Feed deleted.');
    }

    public function products(Request $request)
    {
        $customer = $this->getActiveCustomer($request);
        if (! $customer) {
            return redirect()->route('dashboard');
        }
        $query = Product::where('customer_id', $customer->id);

        if ($request->filled('feed')) {
            $feed = ProductFeed::where('customer_id', $customer->id)->findOrFail($request->integer('feed'));
            $query->where('product_feed_id', $feed->id);
        }

        if ($request->status) {
            $query->where('status', $request->status);
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(fn ($query) => $query->where('title', 'ilike', '%'.$search.'%')->orWhere('offer_id', 'ilike', '%'.$search.'%')->orWhere('brand', 'ilike', '%'.$search.'%'));
        }
        $products = $query->orderBy('impressions', 'desc')->orderBy('id')->paginate(50)->withQueryString();

        return Inertia::render('Products/List', [
            'products' => $products->items(),
            'filter' => $request->status,
            'search' => $search,
            'feed' => $request->integer('feed') ?: null,
            'pagination' => ['total' => $products->total(), 'from' => $products->firstItem(), 'to' => $products->lastItem(), 'previous' => $products->previousPageUrl(), 'next' => $products->nextPageUrl()],
        ]);
    }
}
