<?php

namespace App\Http\Controllers;

use App\Models\AttributionConversion;
use App\Models\AttributionTouchpoint;
use App\Models\Customer;
use App\Services\Attribution\AttributionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class TrackingController extends Controller
{
    /**
     * Verify the HMAC signature on a tracking request.
     * The pixel must sign: customer_id + timestamp using the customer's signing secret.
     */
    protected function verifyTrackingSignature(Request $request): ?Customer
    {
        $signature = $request->header('X-Tracking-Signature');
        $timestamp = $request->input('timestamp') ?? $request->header('X-Tracking-Timestamp');
        $customerId = $request->input('customer_id');

        if (!$signature || !$timestamp || !$customerId) {
            return null;
        }

        // Reject if timestamp is more than 5 minutes old (replay protection)
        try {
            $requestTime = \Carbon\Carbon::parse($timestamp);
            if ($requestTime->diffInMinutes(now(), absolute: true) > 5) {
                return null;
            }
        } catch (\Exception $e) {
            return null;
        }

        $customer = Customer::find($customerId);
        if (!$customer || !$customer->tracking_signing_secret) {
            return null;
        }

        $expectedSignature = hash_hmac('sha256', $customerId . '|' . $timestamp, $customer->tracking_signing_secret);

        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        return $customer;
    }

    /**
     * Record a touchpoint from the Spectra pixel.
     * Public endpoint — rate-limited, HMAC-verified.
     */
    public function touchpoint(Request $request): JsonResponse
    {
        // Rate limit: 60 requests per minute per IP
        $key = 'touchpoint:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 60)) {
            return response()->json(['error' => 'Too many requests'], 429);
        }
        RateLimiter::hit($key, 60);

        // Verify HMAC signature
        $customer = $this->verifyTrackingSignature($request);
        if (!$customer) {
            return response()->json(['error' => 'Invalid or missing tracking signature'], 403);
        }

        $validated = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'visitor_id' => 'required|string|max:64',
            'utm_source' => 'nullable|string|max:255',
            'utm_medium' => 'nullable|string|max:255',
            'utm_campaign' => 'nullable|string|max:255',
            'utm_content' => 'nullable|string|max:255',
            'utm_term' => 'nullable|string|max:255',
            'page_url' => 'nullable|string|max:2048',
            'referrer' => 'nullable|string|max:2048',
            'timestamp' => 'nullable|date',
        ]);

        AttributionTouchpoint::create([
            'customer_id' => $validated['customer_id'],
            'visitor_id' => Str::limit($validated['visitor_id'], 64, ''),
            'utm_source' => $validated['utm_source'] ?? null,
            'utm_medium' => $validated['utm_medium'] ?? null,
            'utm_campaign' => $validated['utm_campaign'] ?? null,
            'utm_content' => $validated['utm_content'] ?? null,
            'utm_term' => $validated['utm_term'] ?? null,
            'page_url' => $validated['page_url'] ?? null,
            'referrer' => $validated['referrer'] ?? null,
            'touched_at' => $validated['timestamp'] ?? now(),
        ]);

        return response()->json(['ok' => true], 201);
    }

    /**
     * Record a conversion and run attribution models.
     * Public endpoint — rate-limited.
     */
    public function conversion(Request $request, AttributionService $attributionService): JsonResponse
    {
        // Rate limit: 30 conversions per minute per IP
        $key = 'conversion:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 30)) {
            return response()->json(['error' => 'Too many requests'], 429);
        }
        RateLimiter::hit($key, 60);

        // Verify HMAC signature
        $customer = $this->verifyTrackingSignature($request);
        if (!$customer) {
            return response()->json(['error' => 'Invalid or missing tracking signature'], 403);
        }

        $validated = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'visitor_id' => 'required|string|max:64',
            'conversion_type' => 'nullable|string|max:100',
            'conversion_value' => 'nullable|numeric|min:0',
            'touchpoints' => 'nullable|array',
        ]);

        // Fetch stored touchpoints for this visitor
        $storedTouchpoints = AttributionTouchpoint::forVisitor($validated['visitor_id'])
            ->forCustomer($validated['customer_id'])
            ->get()
            ->toArray();

        // Merge with client-provided touchpoints (may contain additional data)
        $journey = !empty($storedTouchpoints) ? $storedTouchpoints : ($validated['touchpoints'] ?? []);

        // Run all attribution models
        $attribution = $attributionService->attributeAll($journey, $validated['conversion_value'] ?? 0);

        $conversion = AttributionConversion::create([
            'customer_id' => $validated['customer_id'],
            'visitor_id' => $validated['visitor_id'],
            'conversion_type' => $validated['conversion_type'] ?? 'purchase',
            'conversion_value' => $validated['conversion_value'] ?? 0,
            'touchpoints' => $journey,
            'attributed_to' => $attribution,
        ]);

        Log::info('Attribution conversion recorded', [
            'customer_id' => $validated['customer_id'],
            'conversion_id' => $conversion->id,
            'touchpoints_count' => count($journey),
        ]);

        return response()->json(['ok' => true, 'conversion_id' => $conversion->id], 201);
    }
}
