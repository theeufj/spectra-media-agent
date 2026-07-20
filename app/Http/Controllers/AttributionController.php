<?php

namespace App\Http\Controllers;

use App\Models\AttributionConversion;
use App\Models\AttributionTouchpoint;
use App\Models\Campaign;
use App\Services\Attribution\AttributionService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AttributionController extends Controller
{
    public function __construct(protected AttributionService $attributionService)
    {}

    /**
     * Show attribution dashboard for a campaign.
     */
    public function show(Request $request, Campaign $campaign)
    {
        $user = $request->user();
        if (!$user->customers()->where('customers.id', $campaign->customer_id)->exists()) {
            abort(403);
        }

        $customerId = $campaign->customer_id;

        // Get conversions attributed to this campaign (via utm_campaign = spectra_{id})
        $campaignTag = 'spectra_' . $campaign->id;

        // Fetch recent conversions for the customer and match the campaign tag in PHP.
        // Avoids DB-specific JSON-path SQL (prod is Postgres, tests are sqlite) — the
        // previous MySQL JSON_EXTRACT would throw a QueryException on Postgres.
        $conversions = AttributionConversion::forCustomer($customerId)
            ->orderBy('created_at', 'desc')
            ->limit(2000)
            ->get()
            ->filter(fn ($c) => collect($c->touchpoints ?? [])
                ->contains(fn ($t) => is_array($t) && ($t['utm_campaign'] ?? null) === $campaignTag))
            ->take(500)
            ->values()
            ->toArray();

        // Aggregate by channel for each model
        $models = ['last_click', 'first_click', 'linear', 'time_decay', 'position_based'];
        $channelBreakdown = [];
        foreach ($models as $model) {
            $channelBreakdown[$model] = $this->attributionService->aggregateByChannel($conversions, $model);
        }

        // Recent touchpoints for journey visualization
        $recentTouchpoints = AttributionTouchpoint::forCustomer($customerId)
            ->where('utm_campaign', $campaignTag)
            ->orderBy('touched_at', 'desc')
            ->limit(100)
            ->get()
            ->toArray();

        // Summary stats
        $totalConversions = count($conversions);
        $totalValue = array_sum(array_column($conversions, 'conversion_value'));
        $avgTouchpoints = $totalConversions > 0
            ? array_sum(array_map(fn($c) => count($c['touchpoints'] ?? []), $conversions)) / $totalConversions
            : 0;

        return Inertia::render('Campaigns/Attribution', [
            'campaign' => $campaign->only('id', 'name'),
            'pixelConfig' => [
                'customer_id' => $campaign->customer_id,
                'signing_secret' => $campaign->customer->tracking_signing_secret,
            ],
            'summary' => [
                'total_conversions' => $totalConversions,
                'total_value' => round($totalValue, 2),
                'avg_touchpoints' => round($avgTouchpoints, 1),
            ],
            'channelBreakdown' => $channelBreakdown,
            'recentTouchpoints' => $recentTouchpoints,
            'conversions' => array_slice($conversions, 0, 50),
        ]);
    }
}
