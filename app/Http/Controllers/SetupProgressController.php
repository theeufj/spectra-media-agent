<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SetupProgressController extends Controller
{
    /**
     * Get the setup progress for the current customer.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'progress' => 0,
                    'steps' => [],
                    'is_new_user' => true,
                    'completed_steps' => 0,
                    'total_steps' => 3,
                    'current_step' => null,
                ]);
            }
            
            $customer = $user->customers()->find(session('active_customer_id'));
            
            if (!$customer) {
                return response()->json([
                    'progress' => 0,
                    'steps' => [],
                    'is_new_user' => true,
                    'completed_steps' => 0,
                    'total_steps' => 3,
                    'current_step' => null,
                ]);
            }

            // Calculate setup progress
            // Knowledge base is tied to user, not customer
            $hasKnowledgeBase = \App\Models\KnowledgeBase::where('user_id', $user->id)->count() > 0;
            $hasBrandGuidelines = $customer->brandGuideline?->user_verified ?? false;
            $hasCampaign = $customer->campaigns()->count() > 0;
            
            $steps = [
                [
                    'key' => 'knowledge_base',
                    'title' => 'Add Knowledge Base',
                    'description' => 'Import your website content to help AI understand your business',
                    'completed' => $hasKnowledgeBase,
                    'action_url' => route('knowledge-base.create'),
                    'action_text' => 'Add Content',
                ],
                [
                    'key' => 'brand_guidelines',
                    'title' => 'Verify Brand Guidelines',
                    'description' => 'Review AI-extracted brand guidelines for accuracy',
                    'completed' => $hasBrandGuidelines,
                    'action_url' => route('brand-guidelines.index'),
                    'action_text' => 'Review Guidelines',
                ],
                [
                    'key' => 'first_campaign',
                    'title' => 'Create First Campaign',
                    'description' => 'Launch your first AI-powered ad campaign',
                    'completed' => $hasCampaign,
                    'action_url' => route('campaigns.wizard'),
                    'action_text' => 'Create Campaign',
                ],
            ];

        $completedSteps = collect($steps)->where('completed', true)->count();
        $totalSteps = count($steps);
        $progress = $totalSteps > 0 ? round(($completedSteps / $totalSteps) * 100) : 0;

        // Determine if user is "new" (hasn't completed setup)
        $isNewUser = $progress < 100;

        return response()->json([
            'progress' => $progress,
            'completed_steps' => $completedSteps,
            'total_steps' => $totalSteps,
            'steps' => $steps,
            'is_new_user' => $isNewUser,
            'current_step' => collect($steps)->firstWhere('completed', false),
        ]);
        } catch (\Exception $e) {
            \Log::error('SetupProgressController error: ' . $e->getMessage());
            return response()->json([
                'progress' => 0,
                'steps' => [],
                'is_new_user' => true,
                'completed_steps' => 0,
                'total_steps' => 3,
                'current_step' => null,
            ]);
        }
    }
}
