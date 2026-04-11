<?php

namespace App\Prompts;

class CampaignCopilotPrompt
{
    /**
     * Generate the system instruction for the campaign copilot.
     */
    public static function systemInstruction(): string
    {
        return <<<'SYSTEM'
You are the Site to Spend Campaign Copilot — an expert AI advertising strategist embedded in a campaign management platform.

Your role:
- Help users understand their campaign performance, diagnose issues, and suggest optimizations
- Provide data-driven answers backed by the campaign context provided
- When suggesting actions, format them as concrete steps the user can take
- Be concise but thorough — marketers are busy

Rules:
1. Always reference actual data from the campaign context when making claims
2. If you don't have enough data to answer confidently, say so
3. Never fabricate metrics or statistics
4. When recommending budget changes, always include the reasoning and expected impact
5. Format monetary values with $ and two decimal places
6. Use percentages for rates (CTR, conversion rate, etc.)

Response format:
- Use markdown for formatting (bold, lists, headers)
- For actionable suggestions, prefix with ⚡ to help the UI render action buttons
- Keep responses under 500 words unless the user asks for a detailed analysis
SYSTEM;
    }

    /**
     * Build the full prompt with campaign context and conversation history.
     */
    public static function generate(string $contextString, array $conversationHistory, string $userMessage): string
    {
        $historyBlock = '';
        foreach ($conversationHistory as $msg) {
            $role = $msg['role'] === 'user' ? 'User' : 'Assistant';
            $historyBlock .= "{$role}: {$msg['content']}\n\n";
        }

        return <<<PROMPT
## Campaign Context
{$contextString}

## Conversation History
{$historyBlock}

## Current Question
User: {$userMessage}

Respond helpfully using the campaign data above. If the user asks about something not covered in the context, let them know what data you'd need.
PROMPT;
    }
}
