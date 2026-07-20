<?php

/**
 * AI model configuration.
 *
 * All Gemini model names are defined here. No model string should ever be
 * hardcoded in application code — always reference via config('ai.models.*').
 *
 * Override any key in .env using the AI_MODEL_* variables below.
 */
return [

    /*
     * Guardrails for the seasonal strategy job. new_daily_budget may come from an
     * LLM, so budgets are clamped to a band around the campaign's current daily
     * budget and hard-capped by these absolute ceilings.
     */
    'seasonal' => [
        'max_daily_budget' => (float) env('AI_SEASONAL_MAX_DAILY_BUDGET', 2000.0),
        'no_baseline_cap'  => (float) env('AI_SEASONAL_NO_BASELINE_CAP', 500.0),
    ],

    'models' => [

        /*
         * Default model for all general-purpose AI tasks:
         * copy generation, analysis, recommendations, reporting, etc.
         */
        'default' => env('AI_MODEL_DEFAULT', 'gemini-3.5-flash'),

        /*
         * Pro model for complex reasoning tasks (competitor strategy,
         * campaign planning, detailed analysis).
         */
        'pro' => env('AI_MODEL_PRO', 'gemini-3.1-pro-preview'),

        /*
         * Lite/budget model for high-volume, low-complexity tasks
         * (batch keyword categorisation, simple scoring, extraction).
         */
        'lite' => env('AI_MODEL_LITE', 'gemini-3.1-flash-lite-preview'),

        /*
         * Embedding model for vector search and semantic similarity.
         * gemini-embedding-2-preview is not available on Vertex AI global endpoint;
         * gemini-embedding-001 returns 3072-dim vectors and works reliably.
         */
        'embedding' => env('AI_MODEL_EMBEDDING', 'gemini-embedding-2-preview'),

        /*
         * Image generation model.
         */
        'image' => env('AI_MODEL_IMAGE', 'gemini-3.1-flash-image-preview'),

        /*
         * Video generation model.
         */
        'video' => env('AI_MODEL_VIDEO', 'veo-3.1-generate-001'),

    ],

    /*
     * Model fallback chain. When a model fails all retries, the service
     * will automatically try the next model in this chain before giving up.
     */
    'fallback_chain' => [
        'gemini-3.1-pro-preview'        => 'gemini-3.5-flash',
        'gemini-2.5-pro'                => 'gemini-3.5-flash',
        'gemini-3.5-flash'              => 'gemini-2.5-flash',
        'gemini-3-flash-preview'        => 'gemini-3.5-flash',
        'gemini-2.5-flash'              => 'gemini-3.1-flash-lite-preview',
        'gemini-3.1-flash-lite-preview' => 'gemini-2.5-flash-lite',
    ],

    /*
     * Per-task generation config presets. Pass 'task_type' in the context
     * array to apply a preset. Explicit $config values override these.
     *
     * - creative:      ad copy, captions, brainstorming
     * - analytical:    campaign analysis, recommendations, health checks
     * - extraction:    JSON parsing, data extraction, structured output
     * - classification: keyword scoring, intent labelling, categorisation
     * - conversational: copilot, chat responses
     * - strategy:      full campaign strategy, competitive research
     */
    'task_config' => [
        'creative'       => ['temperature' => 1.0,  'topP' => 0.95, 'topK' => 40],
        'analytical'     => ['temperature' => 0.7,  'topP' => 0.90, 'topK' => 32],
        'extraction'     => ['temperature' => 0.15, 'topP' => 0.85, 'topK' => 16],
        'classification' => ['temperature' => 0.10, 'topP' => 0.80, 'topK' => 8],
        'conversational' => ['temperature' => 0.85, 'topP' => 0.95, 'topK' => 40],
        'strategy'       => ['temperature' => 0.80, 'topP' => 0.92, 'topK' => 40],
    ],

    /*
     * Default model tier per task type. References the model keys above.
     * Agents should pass task_type in context rather than hardcoding model names.
     */
    'task_models' => [
        'creative'       => 'default',
        'analytical'     => 'default',
        'extraction'     => 'lite',
        'classification' => 'lite',
        'conversational' => 'default',
        'strategy'       => 'pro',
    ],

    /*
     * Pricing per 1M tokens (USD). Used to calculate costs stored in ai_costs.
     * Sourced from the Vertex / Gemini Enterprise Agent Platform pricing page
     * (verified 2026-07-19). Pro tiers use the <=200K-token rate; Flash uses the
     * global rate. Veo is billed per second, not per token, so it stays at 0.
     */
    'pricing' => [
        'gemini-3.1-pro-preview'        => ['input' => 2.00,   'output' => 12.00, 'cached' => 0.20],
        'gemini-2.5-pro'                => ['input' => 1.25,   'output' => 10.00, 'cached' => 0.13],
        'gemini-3.5-flash'              => ['input' => 1.50,   'output' => 9.00,  'cached' => 0.15],
        'gemini-3-flash-preview'        => ['input' => 0.50,   'output' => 3.00,  'cached' => 0.05],
        'gemini-2.5-flash'              => ['input' => 0.30,   'output' => 2.50,  'cached' => 0.03],
        'gemini-3.1-flash-lite-preview' => ['input' => 0.25,   'output' => 1.50,  'cached' => 0.025],
        'gemini-2.5-flash-lite'         => ['input' => 0.10,   'output' => 0.40,  'cached' => 0.01],
        // Image model: output priced at the image-output rate ($60/1M tokens).
        'gemini-3.1-flash-image-preview'=> ['input' => 0.50,   'output' => 60.00, 'cached' => 0.05],
        'gemini-embedding-001'          => ['input' => 0.0010, 'output' => 0.00,  'cached' => 0.00],
        'text-embedding-005'            => ['input' => 0.0010, 'output' => 0.00,  'cached' => 0.00],
        'veo-3.1-generate-001'      => ['input' => 0.00,   'output' => 0.00,  'cached' => 0.00], // billed per second
    ],

];
