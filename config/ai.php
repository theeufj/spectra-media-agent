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
         * (batch keyword categorization, simple scoring).
         */
        'lite' => env('AI_MODEL_LITE', 'gemini-2.5-flash-lite'),

        /*
         * Embedding model for vector search and semantic similarity.
         */
        'embedding' => env('AI_MODEL_EMBEDDING', 'text-embedding-005'),

        /*
         * Image generation model.
         */
        'image' => env('AI_MODEL_IMAGE', 'gemini-3.1-flash-image'),

        /*
         * Video generation model.
         */
        'video' => env('AI_MODEL_VIDEO', 'veo-3.0-generate-001'),

    ],

];
