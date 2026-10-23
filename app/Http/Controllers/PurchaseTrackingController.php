<?php

namespace App\Http\Controllers;

use App\Services\OwnSitePurchaseTracking;
use Illuminate\Http\Request;

class PurchaseTrackingController extends Controller
{
    public function pending(Request $request, OwnSitePurchaseTracking $tracking)
    {
        return response()->json($tracking->forBrowser($request->user(), $request))->header('Cache-Control', 'private, no-store');
    }

    public function invoked(Request $request, int $receipt, OwnSitePurchaseTracking $tracking)
    {
        $data = $request->validate(['binding_id' => 'required|string|size:64']);
        $tracking->acknowledge($request->user(), $request, $receipt, $data['binding_id']);

        return response()->json(['invocation_recorded' => true, 'google_conversion_verified' => false]);
    }
}
