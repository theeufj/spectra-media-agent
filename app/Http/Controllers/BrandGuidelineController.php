<?php

namespace App\Http\Controllers;

use App\Models\BrandGuideline;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class BrandGuidelineController extends Controller
{
    /**
     * Display the brand guidelines for the authenticated user's customer.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        
        // Get the active customer from session
        $activeCustomerId = session('active_customer_id');
        
        if (!$activeCustomerId) {
            return redirect()->route('dashboard')->with('error', 'No active customer selected.');
        }
        
        // Ensure user has access to this customer
        $customer = $user->customers()->findOrFail($activeCustomerId);
        
        $brandGuideline = $customer->brandGuideline;
        
        return Inertia::render('BrandGuidelines/Index', [
            'brandGuideline' => $brandGuideline,
            'customer' => $customer->only(['id', 'name', 'website']),
            'canEdit' => true, // You can add permission logic here
        ]);
    }
    
    /**
     * Update the brand guidelines.
     */
    public function update(Request $request, BrandGuideline $brandGuideline)
    {
        $user = $request->user();
        $activeCustomerId = session('active_customer_id');
        
        // Authorization: Ensure user has access to this customer and owns this brand guideline
        $customer = $user->customers()->findOrFail($activeCustomerId);
        
        if ($customer->id !== $brandGuideline->customer_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        $validated = $request->validate([
            'brand_voice' => 'nullable|array',
            'brand_voice.primary_voice' => 'nullable|string|max:255',
            'brand_voice.voice_descriptors' => 'nullable|array',
            'brand_voice.voice_descriptors.*' => 'string|max:100',
            
            'tone_attributes' => 'nullable|array',
            'tone_attributes.primary_tones' => 'nullable|array',
            'tone_attributes.primary_tones.*' => 'string|max:100',
            'tone_attributes.contextual_tones' => 'nullable|array',
            
            'color_palette' => 'nullable|array',
            'color_palette.primary_colors' => 'nullable|array',
            'color_palette.secondary_colors' => 'nullable|array',
            'color_palette.accent_colors' => 'nullable|array',
            
            'typography' => 'nullable|array',
            'typography.primary_font' => 'nullable|string|max:100',
            'typography.secondary_font' => 'nullable|string|max:100',
            'typography.font_context' => 'nullable|string|max:500',
            
            'visual_style' => 'nullable|array',
            'visual_style.overall_aesthetic' => 'nullable|string|max:255',
            'visual_style.imagery_style' => 'nullable|string|max:255',
            'visual_style.description' => 'nullable|string|max:1000',
            
            'messaging_themes' => 'nullable|array',
            'messaging_themes.primary_themes' => 'nullable|array',
            'messaging_themes.primary_themes.*' => 'string|max:255',
            'messaging_themes.emotional_appeal' => 'nullable|string|max:255',
            'messaging_themes.proof_points' => 'nullable|string|max:1000',
            
            'unique_selling_propositions' => 'nullable|array',
            'unique_selling_propositions.*' => 'string|max:500',
            
            'target_audience' => 'nullable|array',
            'target_audience.demographics' => 'nullable|string|max:500',
            'target_audience.psychographics' => 'nullable|string|max:500',
            'target_audience.pain_points' => 'nullable|array',
            'target_audience.aspirations' => 'nullable|array',
            
            'brand_personality' => 'nullable|array',
            'brand_personality.traits' => 'nullable|array',
            'brand_personality.traits.*' => 'string|max:100',
            'brand_personality.archetype' => 'nullable|string|max:100',
            'brand_personality.communication_style' => 'nullable|string|max:255',
            
            'competitor_differentiation' => 'nullable|array',
            'competitor_differentiation.differentiation_points' => 'nullable|array',
            'competitor_differentiation.differentiation_points.*' => 'string|max:500',
            'competitor_differentiation.competitive_advantage' => 'nullable|string|max:500',
            
            'do_not_use' => 'nullable|array',
            'do_not_use.*' => 'string|max:255',
        ]);
        
        $brandGuideline->update($validated);
        
        // Mark as user-verified since they edited it
        $brandGuideline->update(['user_verified' => true]);
        
        Log::info('Brand guidelines updated by user', [
            'user_id' => $user->id,
            'customer_id' => $user->customer->id,
            'brand_guideline_id' => $brandGuideline->id,
        ]);
        
        return back()->with('success', 'Brand guidelines updated successfully!');
    }
    
    /**
     * Mark brand guidelines as verified by the user.
     */
    public function verify(Request $request, BrandGuideline $brandGuideline)
    {
        $user = $request->user();
        $activeCustomerId = session('active_customer_id');
        
        // Authorization
        $customer = $user->customers()->findOrFail($activeCustomerId);
        
        if ($customer->id !== $brandGuideline->customer_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        $brandGuideline->update(['user_verified' => true]);
        
        return back()->with('success', 'Brand guidelines verified!');
    }
    
    /**
     * Trigger re-extraction of brand guidelines from knowledge base.
     */
    public function reExtract(Request $request)
    {
        $user = $request->user();
        $activeCustomerId = session('active_customer_id');
        
        if (!$activeCustomerId) {
            return redirect()->back()->with('error', 'No active customer selected.');
        }
        
        // Ensure user has access to this customer
        $customer = $user->customers()->findOrFail($activeCustomerId);
        
        \App\Jobs\ExtractBrandGuidelines::dispatch($customer);
        
        Log::info('Brand guideline re-extraction triggered', [
            'user_id' => $user->id,
            'customer_id' => $customer->id,
        ]);
        
        return back()->with('success', 'Brand guideline extraction started! This may take a few minutes.');
    }
}
