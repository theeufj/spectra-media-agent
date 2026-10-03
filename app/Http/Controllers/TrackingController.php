<?php

namespace App\Http\Controllers;

use App\Models\AttributionConversion;
use App\Models\AttributionTouchpoint;
use App\Models\Customer;
use App\Services\Attribution\AttributionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Browser telemetry for the optional website attribution report.
 *
 * The site ID is public. Origin checks prevent another website from using a
 * customer's ID in a browser, but a non-browser client can forge Origin. These
 * events must never be treated as verified sales or uploaded to an ad platform.
 */
class TrackingController extends Controller
{
    private function site(Request $request): ?Customer
    {
        $siteId = $request->input('site_id');
        if (! is_string($siteId) || ! Str::isUuid($siteId)) {
            return null;
        }

        $customer = Customer::where('uuid', $siteId)->first();
        if (! $customer || ! $customer->website) {
            return null;
        }

        $origin = $request->header('Origin');
        $pageUrl = $request->input('page_url');
        $registeredHost = parse_url($customer->website, PHP_URL_HOST);
        $originHost = is_string($origin) ? parse_url($origin, PHP_URL_HOST) : null;
        $pageHost = is_string($pageUrl) ? parse_url($pageUrl, PHP_URL_HOST) : null;

        if (! is_string($registeredHost) || ! is_string($originHost) || ! is_string($pageHost)
            || parse_url($origin, PHP_URL_SCHEME) !== 'https'
            || parse_url($pageUrl, PHP_URL_SCHEME) !== 'https'
            || $this->canonicalHost($registeredHost) !== $this->canonicalHost($originHost)
            || $this->canonicalHost($registeredHost) !== $this->canonicalHost($pageHost)) {
            return null;
        }

        return $customer;
    }

    private function canonicalHost(string $host): string
    {
        return preg_replace('/^www\./', '', strtolower($host));
    }

    private function limit(Request $request, string $event, int $perMinute): ?JsonResponse
    {
        $siteId = $request->input('site_id');
        $key = 'tracking:'.$event.':'.hash('sha256', is_string($siteId) ? $siteId : '').':'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            return response()->json(['error' => 'Too many requests'], 429);
        }
        RateLimiter::hit($key, 60);

        return null;
    }

    public function touchpoint(Request $request): JsonResponse
    {
        if ($limited = $this->limit($request, 'touchpoint', 300)) {
            return $limited;
        }

        $customer = $this->site($request);
        if (! $customer) {
            return response()->json(['error' => 'Unregistered tracking site'], 403);
        }

        $validated = $request->validate([
            'visitor_id' => 'required|uuid',
            'utm_source' => 'nullable|string|max:255',
            'utm_medium' => 'nullable|string|max:255',
            'utm_campaign' => 'nullable|string|max:255',
            'utm_content' => 'nullable|string|max:255',
            'utm_term' => 'nullable|string|max:255',
            'page_url' => 'required|url|max:2048',
            'referrer' => 'nullable|url|max:2048',
        ]);

        AttributionTouchpoint::create([
            'customer_id' => $customer->id,
            'visitor_id' => $validated['visitor_id'],
            'utm_source' => $validated['utm_source'] ?? null,
            'utm_medium' => $validated['utm_medium'] ?? null,
            'utm_campaign' => $validated['utm_campaign'] ?? null,
            'utm_content' => $validated['utm_content'] ?? null,
            'utm_term' => $validated['utm_term'] ?? null,
            'page_url' => $validated['page_url'],
            'referrer' => $validated['referrer'] ?? null,
            'touched_at' => now(),
        ]);

        return response()->json(['ok' => true], 201);
    }

    public function conversion(Request $request, AttributionService $attributionService): JsonResponse
    {
        if ($limited = $this->limit($request, 'conversion', 60)) {
            return $limited;
        }

        $customer = $this->site($request);
        if (! $customer) {
            return response()->json(['error' => 'Unregistered tracking site'], 403);
        }

        $validated = $request->validate([
            'visitor_id' => 'required|uuid',
            'event_id' => 'required|uuid',
            'page_url' => 'required|url|max:2048',
            'conversion_type' => 'required|string|max:100',
            'conversion_value' => 'nullable|numeric|min:0|max:9999999999',
        ]);

        // Only touchpoints already accepted for this site and visitor are eligible.
        $journey = AttributionTouchpoint::forCustomer($customer->id)
            ->where('visitor_id', $validated['visitor_id'])
            ->where('touched_at', '>=', now()->subDays(90))
            ->orderByDesc('touched_at')
            ->limit(20)
            ->get()
            ->reverse()
            ->values()
            ->toArray();

        $conversion = AttributionConversion::query()->createOrFirst(
            ['customer_id' => $customer->id, 'event_id' => $validated['event_id']],
            [
                'visitor_id' => $validated['visitor_id'],
                'conversion_type' => $validated['conversion_type'],
                'conversion_value' => $validated['conversion_value'] ?? 0,
                'touchpoints' => $journey,
                'attributed_to' => $attributionService->attributeAll($journey, (float) ($validated['conversion_value'] ?? 0)),
            ]
        );

        return response()->json(['ok' => true, 'conversion_id' => $conversion->id], $conversion->wasRecentlyCreated ? 201 : 200);
    }
}
