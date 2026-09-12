<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Forecasting\CustomerMarketForecast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * What the customer's market is worth, and what one customer is worth to them.
 *
 * Two endpoints that belong together: the panel that shows the forecast is also
 * the panel that asks for the one input the forecast cannot measure.
 */
class ForecastController extends Controller
{
    /**
     * The forecast for the active customer, optionally at a specific budget.
     *
     * Answers 200 with `{"forecast": null}` rather than an error status when
     * there is nothing to show. The panel is one component on a page the
     * customer is already reading, and Keyword Planner being unavailable is not
     * a failure of their request.
     */
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // The budget the customer is currently considering, per month.
            'monthly_budget' => 'nullable|numeric|min:0|max:10000000',
            'campaign_id' => 'nullable|integer',
        ]);

        $customer = $this->activeCustomer($request);

        if (! $customer) {
            return response()->json(['forecast' => null]);
        }

        $service = app(CustomerMarketForecast::class);

        $campaign = isset($validated['campaign_id'])
            ? Campaign::where('customer_id', $customer->id)->find($validated['campaign_id'])
            : null;

        $forecast = isset($validated['monthly_budget']) && (float) $validated['monthly_budget'] > 0
            ? $service->atBudget($customer, (float) $validated['monthly_budget'])
            : $service->for($customer, $campaign);

        return response()->json(['forecast' => $forecast]);
    }

    /**
     * Record what a customer is worth, which is what turns clicks into money.
     *
     * Never been collected anywhere in the product: 0 of 17 production
     * customers had it set. Beyond the forecast panel it unblocks
     * BudgetIntelligenceAgent, which skips reallocation entirely for a customer
     * without one, and LinkedIn conversion values, which compute as zero.
     */
    public function storeOrderValue(Request $request): JsonResponse
    {
        $validated = $request->validate([
            /*
             * Bounded on both sides on purpose. This is self-reported and does
             * not stay on the page — it drives budget reallocation once set, so
             * a fat-fingered 18000000 would quietly distort real spending
             * decisions later.
             */
            'average_order_value' => 'required|numeric|min:1|max:1000000',

            /*
             * The budget the panel is currently showing.
             *
             * Without it this replied with a forecast framed at the demo
             * default, so entering an order value on a campaign budgeted at
             * $1,350/mo silently redrew the panel at $1,500/mo — the heading,
             * the clicks and the spend all jumped to a budget the customer had
             * never set, in the same instant they were being shown revenue for
             * the first time.
             */
            'monthly_budget' => 'nullable|numeric|min:0|max:10000000',
            'campaign_id' => 'nullable|integer',
        ]);

        $customer = $this->activeCustomer($request);

        if (! $customer) {
            return response()->json(['message' => 'No active customer.'], 404);
        }

        // Structural authorization, not a hand-rolled pivot query.
        $this->authorize('update', $customer);

        $customer->update(['average_order_value' => $validated['average_order_value']]);

        Log::info('Order value recorded', [
            'customer_id' => $customer->id,
            'average_order_value' => $validated['average_order_value'],
        ]);

        $fresh = $customer->fresh();
        $service = app(CustomerMarketForecast::class);

        $campaign = isset($validated['campaign_id'])
            ? Campaign::where('customer_id', $customer->id)->find($validated['campaign_id'])
            : null;

        // Reply at the budget the panel was already showing, so the only thing
        // that changes on screen is the revenue they just unlocked.
        $forecast = isset($validated['monthly_budget']) && (float) $validated['monthly_budget'] > 0
            ? $service->atBudget($fresh, (float) $validated['monthly_budget'])
            : $service->for($fresh, $campaign);

        return response()->json(['forecast' => $forecast]);
    }

    /**
     * The customer this session is working on.
     *
     * Scoped through the user's own relation, so a tampered session id resolves
     * to nothing rather than to somebody else's customer.
     */
    private function activeCustomer(Request $request): ?Customer
    {
        return $request->user()?->customers()->find(session('active_customer_id'));
    }
}
