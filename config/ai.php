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
        'embedding' => env('AI_MODEL_EMBEDDING', 'gemini-embedding-001'),

        /*
         * Image generation model.
         */
        'image' => env('AI_MODEL_IMAGE', 'gemini-3.1-flash-image-preview'),

        /*
         * Video generation model.
         */
        'video' => env('AI_MODEL_VIDEO', 'veo-3.1-generate-preview'),

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
     * Sourced from ai.google.dev pricing page — update when Google changes rates.
     */
    'pricing' => [
        'gemini-3.1-pro-preview'        => ['input' => 1.25,   'output' => 5.00,  'cached' => 0.3125],
        'gemini-2.5-pro'                => ['input' => 1.25,   'output' => 5.00,  'cached' => 0.3125],
        'gemini-3.5-flash'              => ['input' => 0.075,  'output' => 0.30,  'cached' => 0.01875],
        'gemini-3-flash-preview'        => ['input' => 0.075,  'output' => 0.30,  'cached' => 0.01875],
        'gemini-2.5-flash'              => ['input' => 0.075,  'output' => 0.30,  'cached' => 0.01875],
        'gemini-3.1-flash-lite-preview' => ['input' => 0.018,  'output' => 0.072, 'cached' => 0.0045],
        'gemini-2.5-flash-lite'         => ['input' => 0.018,  'output' => 0.072, 'cached' => 0.0045],
        'gemini-3.1-flash-image-preview'=> ['input' => 0.075,  'output' => 0.30,  'cached' => 0.01875],
        'gemini-embedding-001'          => ['input' => 0.0010, 'output' => 0.00,  'cached' => 0.00],
        'text-embedding-005'            => ['input' => 0.0010, 'output' => 0.00,  'cached' => 0.00],
        'veo-3.1-generate-preview'      => ['input' => 0.00,   'output' => 0.00,  'cached' => 0.00], // billed per second
    ],

];
