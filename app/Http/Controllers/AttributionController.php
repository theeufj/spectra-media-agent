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
        if ($campaign->customer?->user_id !== $user->id) {
            abort(403);
        }

        $customerId = $campaign->customer_id;

        // Get conversions attributed to this campaign (via utm_campaign = spectra_{id})
        $campaignTag = 'spectra_' . $campaign->id;

        $conversions = AttributionConversion::forCustomer($customerId)
            ->whereJsonContains('touchpoints', [['utm_campaign' => $campaignTag]])
            ->orWhere(function ($q) use ($customerId, $campaignTag) {
                $q->where('customer_id', $customerId)
                    ->whereRaw("JSON_EXTRACT(touchpoints, '$[*].utm_campaign') LIKE ?", ["%{$campaignTag}%"]);
            })
            ->orderBy('created_at', 'desc')
            ->limit(500)
            ->get()
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
