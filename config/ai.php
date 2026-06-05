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
        'default' => env('AI_MODEL_DEFAULT', 'gemini-2.5-flash'),

        /*
         * Pro model for complex reasoning tasks (competitor strategy,
         * campaign planning, detailed analysis).
         */
        'pro' => env('AI_MODEL_PRO', 'gemini-2.5-pro'),

        /*
         * Lite/budget model for high-volume, low-complexity tasks
         * (batch keyword categorization, simple scoring).
         */
        'lite' => env('AI_MODEL_LITE', 'gemini-2.5-flash-lite'),

        /*
         * Embedding model for vector search and semantic similarity.
         * Vertex AI native text embedding model.
         */
        'embedding' => env('AI_MODEL_EMBEDDING', 'text-embedding-005'),

        /*
         * Image generation model.
         */
        'image' => env('AI_MODEL_IMAGE', 'imagen-3.0-generate-002'),

        /*
         * Video generation model.
         */
        'video' => env('AI_MODEL_VIDEO', 'veo-3.0-generate-preview'),

    ],

];
