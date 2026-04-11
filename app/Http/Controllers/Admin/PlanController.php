<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PlanController extends Controller
{
    public function index()
    {
        $plans = Plan::ordered()->get();

        return Inertia::render('Admin/Plans', [
            'plans' => $plans,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:plans,slug',
            'description' => 'nullable|string|max:1000',
            'price_cents' => 'required|integer|min:0',
            'billing_interval' => 'required|in:month,year',
            'stripe_price_id' => 'nullable|string|max:255',
            'features' => 'nullable|array',
            'features.*' => 'string|max:255',
            'creative_limits' => 'nullable|array',
            'creative_limits.image_generations' => 'integer|min:0',
            'creative_limits.video_generations' => 'integer|min:0',
            'creative_limits.refinements' => 'integer|min:0',
            'creative_limits.max_refinements_per_item' => 'integer|min:0|max:10',
            'creative_limits.max_extensions_per_video' => 'integer|min:0|max:10',
            'is_active' => 'boolean',
            'is_free' => 'boolean',
            'is_popular' => 'boolean',
            'cta_text' => 'nullable|string|max:255',
            'badge_text' => 'nullable|string|max:255',
            'sort_order' => 'integer|min:0',
        ]);

        Plan::create($validated);

        return redirect()->route('admin.plans.index')->with('flash', [
            'type' => 'success',
            'message' => 'Plan created successfully.',
        ]);
    }

    public function update(Request $request, Plan $plan)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:plans,slug,' . $plan->id,
            'description' => 'nullable|string|max:1000',
            'price_cents' => 'required|integer|min:0',
            'billing_interval' => 'required|in:month,year',
            'stripe_price_id' => 'nullable|string|max:255',
            'features' => 'nullable|array',
            'features.*' => 'string|max:255',
            'creative_limits' => 'nullable|array',
            'creative_limits.image_generations' => 'integer|min:0',
            'creative_limits.video_generations' => 'integer|min:0',
            'creative_limits.refinements' => 'integer|min:0',
            'creative_limits.max_refinements_per_item' => 'integer|min:0|max:10',
            'creative_limits.max_extensions_per_video' => 'integer|min:0|max:10',
            'is_active' => 'boolean',
            'is_free' => 'boolean',
            'is_popular' => 'boolean',
            'cta_text' => 'nullable|string|max:255',
            'badge_text' => 'nullable|string|max:255',
            'sort_order' => 'integer|min:0',
        ]);

        $plan->update($validated);

        return redirect()->route('admin.plans.index')->with('flash', [
            'type' => 'success',
            'message' => 'Plan updated successfully.',
        ]);
    }

    public function destroy(Plan $plan)
    {
        $plan->delete();

        return redirect()->route('admin.plans.index')->with('flash', [
            'type' => 'success',
            'message' => 'Plan deleted successfully.',
        ]);
    }
}
