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
                    'total_steps' => 4,
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
                    'total_steps' => 4,
                    'current_step' => null,
                ]);
            }

            // Calculate setup progress
            // Knowledge base is tied to user, not customer
            $hasKnowledgeBase = \App\Models\KnowledgeBase::where('user_id', $user->id)->count() > 0;
            $hasBrandGuidelines = $customer->brandGuideline?->user_verified ?? false;
            
            // Check for ad platform connections specifically
            $connectionInfo = $this->getConnectionInfo($user);
            $hasAdConnection = $connectionInfo['has_ad_platform'];
            
            $hasCampaign = $customer->campaigns()->count() > 0;
            
            // Build connection step with dynamic description
            $connectionDescription = 'Link your Google Ads or Facebook Ads account';
            $connectionActionText = 'Connect Platform';
            
            if ($connectionInfo['has_google_login'] && !$connectionInfo['has_google_ads']) {
                $connectionDescription = 'You\'re signed in with Google. Connect Google Ads to run campaigns.';
                $connectionActionText = 'Connect Ad Account';
            }
            
            if ($connectionInfo['connected_platforms']) {
                $platforms = implode(', ', $connectionInfo['connected_platforms']);
                $connectionDescription = "Connected: {$platforms}. Add more platforms for broader reach.";
                $connectionActionText = 'Manage Connections';
            }
            
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
                    'key' => 'platform_connection',
                    'title' => 'Connect Ad Platform',
                    'description' => $connectionDescription,
                    'completed' => $hasAdConnection,
                    'partial' => $connectionInfo['has_google_login'] && !$hasAdConnection,
                    'action_url' => route('profile.edit') . '#connections',
                    'action_text' => $connectionActionText,
                    'connected_platforms' => $connectionInfo['connected_platforms'],
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
                'total_steps' => 4,
                'current_step' => null,
            ]);
        }
    }

    /**
     * Get detailed connection information for the user.
     *
     * @param  \App\Models\User  $user
     * @return array
     */
    private function getConnectionInfo($user): array
    {
        $connections = $user->connections()->get();
        
        $hasGoogleLogin = $connections->where('platform', 'google')->count() > 0;
        $hasGoogleAds = $connections->where('platform', 'google_ads')->count() > 0;
        $hasFacebookAds = $connections->where('platform', 'facebook_ads')->count() > 0;
        $hasFacebook = $connections->where('platform', 'facebook')->count() > 0;
        
        // Build list of connected ad platforms for display
        // Google login with ads scope counts as Google Ads connection
        $connectedPlatforms = [];
        if ($hasGoogleAds || $hasGoogleLogin) $connectedPlatforms[] = 'Google Ads';
        if ($hasFacebookAds) $connectedPlatforms[] = 'Facebook Ads';
        
        // Has at least one ad platform connection
        // Google login includes Google Ads permissions, so it counts
        $hasAdPlatform = $hasGoogleAds || $hasGoogleLogin || $hasFacebookAds;
        
        return [
            'has_google_login' => $hasGoogleLogin,
            'has_google_ads' => $hasGoogleAds || $hasGoogleLogin, // Google login includes ads permissions
            'has_facebook' => $hasFacebook,
            'has_facebook_ads' => $hasFacebookAds,
            'has_ad_platform' => $hasAdPlatform,
            'connected_platforms' => $connectedPlatforms,
            'total_connections' => $connections->count(),
        ];
    }

    /**
     * Check if user has connected any advertising platform.
     *
     * @param  \App\Models\User  $user
     * @return bool
     */
    private function hasConnectedPlatform($user): bool
    {
        $platformConnections = $user->connections()
            ->whereIn('platform', ['google_ads', 'facebook_ads'])
            ->count();
        
        return $platformConnections > 0;
    }

    /**
     * Mark a setup step as skipped (for optional steps).
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $stepKey
     * @return \Illuminate\Http\JsonResponse
     */
    public function skipStep(Request $request, string $stepKey)
    {
        $user = $request->user();
        $customer = $user->customers()->find(session('active_customer_id'));
        
        if (!$customer) {
            return response()->json(['error' => 'No active customer'], 404);
        }

        // Store skipped steps in customer metadata (you might want to add this column)
        // For now, just return success
        return response()->json(['success' => true, 'step' => $stepKey]);
    }
}
