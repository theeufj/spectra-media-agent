<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\CampaignConversation;
use App\Prompts\CampaignCopilotPrompt;
use App\Services\CopilotContextService;
use App\Services\GeminiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CampaignCopilotController extends Controller
{
    public function __construct(
        protected GeminiService $gemini,
        protected CopilotContextService $contextService,
    ) {}

    /**
     * Send a message to the campaign copilot.
     */
    public function chat(Request $request, Campaign $campaign): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:2000',
        ]);

        $user = $request->user();

        // Verify user owns this campaign
        if ($campaign->customer?->user_id !== $user->id) {
            abort(403);
        }

        $userMessage = $request->input('message');

        // Get or create conversation
        $conversation = CampaignConversation::getOrCreate($campaign->id, $user->id);

        // Record user message
        $conversation->addMessage('user', $userMessage);

        try {
            // Build campaign context
            $context = $this->contextService->buildContext($campaign);
            $contextString = $this->contextService->formatContextForPrompt($context);

            // Build prompt with history
            $history = $conversation->messages ?? [];
            // Exclude the message we just added (it's in the prompt already)
            $historyForPrompt = array_slice($history, 0, -1);

            $prompt = CampaignCopilotPrompt::generate($contextString, $historyForPrompt, $userMessage);

            $response = $this->gemini->generateContent(
                model: config('ai.models.default'),
                prompt: $prompt,
                config: [
                    'temperature' => 0.7,
                    'maxOutputTokens' => 2048,
                ],
                systemInstruction: CampaignCopilotPrompt::systemInstruction(),
            );

            $assistantMessage = $response['text'] ?? 'Sorry, I couldn\'t generate a response. Please try again.';

            // Record assistant response
            $conversation->addMessage('assistant', $assistantMessage);

            return response()->json([
                'message' => $assistantMessage,
                'conversation_id' => $conversation->id,
            ]);
        } catch (\Exception $e) {
            Log::error('CampaignCopilot: Failed to generate response', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'I encountered an error processing your request. Please try again.',
                'error' => true,
            ], 500);
        }
    }

    /**
     * Get conversation history for a campaign.
     */
    public function history(Request $request, Campaign $campaign): JsonResponse
    {
        $user = $request->user();

        if ($campaign->customer?->user_id !== $user->id) {
            abort(403);
        }

        $conversation = CampaignConversation::where('campaign_id', $campaign->id)
            ->where('user_id', $user->id)
            ->first();

        return response()->json([
            'messages' => $conversation?->messages ?? [],
        ]);
    }

    /**
     * Clear conversation history for a campaign.
     */
    public function clear(Request $request, Campaign $campaign): JsonResponse
    {
        $user = $request->user();

        if ($campaign->customer?->user_id !== $user->id) {
            abort(403);
        }

        CampaignConversation::where('campaign_id', $campaign->id)
            ->where('user_id', $user->id)
            ->delete();

        return response()->json(['cleared' => true]);
    }
}
