<?php

namespace App\Http\Controllers;

use App\Models\CreativeBoostPurchase;
use App\Services\CreativeQuotaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class CreativeBoostController extends Controller
{
    /**
     * Create a Stripe Checkout session for a Creative Boost pack ($29).
     * Pack contents: 25 image generations + 5 video generations + 25 refinements.
     */
    public function checkout(Request $request)
    {
        $user = Auth::user();
        $quotaService = app(CreativeQuotaService::class);
        $period = $quotaService->getCurrentPeriod();

        // Ensure user is a Stripe customer
        if (!$user->hasStripeId()) {
            $user->createAsStripeCustomer();
        }

        // Create a pending purchase record
        $purchase = CreativeBoostPurchase::create([
            'user_id' => $user->id,
            'image_generations' => 25,
            'video_generations' => 5,
            'refinements' => 25,
            'amount_cents' => 2900,
            'period' => $period,
            'status' => 'pending',
        ]);

        try {
            $stripe = new \Stripe\StripeClient(config('services.stripe.secret'));

            $session = $stripe->checkout->sessions->create([
                'customer' => $user->stripe_id,
                'mode' => 'payment',
                'line_items' => [[
                    'price_data' => [
                        'currency' => 'usd',
                        'product_data' => [
                            'name' => 'Creative Boost Pack',
                            'description' => '25 image generations + 5 video generations + 25 refinements',
                        ],
                        'unit_amount' => 2900,
                    ],
                    'quantity' => 1,
                ]],
                'metadata' => [
                    'type' => 'creative_boost',
                    'purchase_id' => $purchase->id,
                    'user_id' => $user->id,
                    'period' => $period,
                ],
                'success_url' => route('creative-usage') . '?boost=success',
                'cancel_url' => route('creative-usage') . '?boost=cancelled',
            ]);

            $purchase->update(['stripe_checkout_session_id' => $session->id]);

            return Inertia::location($session->url);
        } catch (\Exception $e) {
            Log::error('Creative Boost checkout failed: ' . $e->getMessage());
            $purchase->update(['status' => 'failed']);

            return redirect()->back()->with('flash', [
                'type' => 'error',
                'message' => 'Failed to create checkout session. Please try again.',
            ]);
        }
    }

    /**
     * Show the creative usage page.
     */
    public function index()
    {
        $user = Auth::user();
        $quotaService = app(CreativeQuotaService::class);

        return Inertia::render('CreativeUsage', [
            'creativeUsage' => $quotaService->getUsageSummary($user),
            'purchases' => CreativeBoostPurchase::where('user_id', $user->id)
                ->where('status', 'completed')
                ->orderByDesc('created_at')
                ->limit(20)
                ->get(),
        ]);
    }
}
