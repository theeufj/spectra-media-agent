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
     * Seconds to back off between image generation retries, as a base for
     * exponential growth (2 -> 2s, 4s). Zero disables the wait entirely, which
     * is what the test suite sets: two tests of the both-providers-down path
     * spent 28 seconds each sleeping for something they were not measuring.
     */
    'image_retry_base_delay' => env('AI_IMAGE_RETRY_BASE_DELAY', 2),

    /*
     * Guardrails for the seasonal strategy job. new_daily_budget may come from an
     * LLM, so budgets are clamped to a band around the campaign's current daily
     * budget and hard-capped by these absolute ceilings.
     */
    'seasonal' => [
        'max_daily_budget' => (float) env('AI_SEASONAL_MAX_DAILY_BUDGET', 2000.0),
        'no_baseline_cap' => (float) env('AI_SEASONAL_NO_BASELINE_CAP', 500.0),
    ],

    'models' => [

        /*
         * Default model for all general-purpose AI tasks:
         * copy generation, analysis, recommendations, reporting, etc.
         */
        'default' => env('AI_MODEL_DEFAULT', 'gemini-3.7-flash'),

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
         *
         * gemini-embedding-2-preview is served only from the regional
         * :embedContent endpoint — the global one 404s. Older models
         * (gemini-embedding-001, text-embedding-*) use :predict on the global
         * endpoint. GeminiService picks the endpoint from 'regional_embedding_models'
         * below rather than sniffing the model name.
         */
        'embedding' => env('AI_MODEL_EMBEDDING', 'gemini-embedding-2-preview'),

        /*
         * Where embedding calls go when the primary returns 429.
         *
         * This model has its own quota pool, which is the point — but it also
         * embeds into a *different space* at the same 3072 dimensions, so its
         * vectors are not comparable to the primary's. Rows record which model
         * produced them (`embedding_model`) and vector search only compares
         * within one space; `embeddings:refresh --mismatched` rebuilds the rest.
         * Set to null to fail the embedding instead of falling back.
         */
        'embedding_fallback' => env('AI_MODEL_EMBEDDING_FALLBACK', 'gemini-embedding-001'),

        /*
         * Image generation model. gemini-3.1-flash-image (Nano Banana 2) —
         * validated end to end against production 2026-08-24: requires an
         * explicit imageConfig.aspect_ratio (it does not default to square
         * the way 2.5 did), and inline reference images must be downscaled
         * (multi-MB payloads trip Google's anti-abuse layer). Both are
         * handled in GeminiService.
         */
        'image' => env('AI_MODEL_IMAGE', 'gemini-3.1-flash-image'),

        /*
         * Video generation model. Veo 3.1 is the current generation as of
         * 2026-08 (the March 2026 refresh added Lite/Fast variants but no
         * new major version).
         */
        'video' => env('AI_MODEL_VIDEO', 'veo-3.1-generate-001'),

        /*
         * Speech synthesis (video narration). A named Gemini TTS voice is
         * deterministic across calls — the property Veo's built-in narration
         * lacks, and the reason chained extensions changed voice mid-video.
         * Only used on the Veo path; Grok video has single-pass native audio.
         */
        'tts' => env('AI_MODEL_TTS', 'gemini-2.5-flash-preview-tts'),

        /*
         * OpenRouter (Grok Imagine) models — the default creative providers
         * after the 2026-08-24 shootout. Gemini/Veo remain as fallbacks and
         * for reference-image work.
         */
        /*
         * Text of last resort, through OpenRouter.
         *
         * Google moved this project from postpay to prepay without notice, so
         * the whole Gemini chain returns 403 BILLING_DISABLED the moment the
         * balance runs dry — which took strategy, brand extraction, creative,
         * the copilot and the public demo down together, for a day, silently.
         * A second vendor on a separate balance means one lapse is a cost
         * problem rather than an outage.
         */
        /*
         * xAI's own names, which are not OpenRouter's.
         *
         * OpenRouter namespaces every model ("x-ai/grok-4-fast"); xAI does
         * not. Sending one to the other is a 404 at request time and nothing
         * sooner, so the two providers keep separate keys rather than sharing
         * one and hoping.
         */
        'text_xai' => env('AI_MODEL_TEXT_XAI', 'grok-4-fast'),
        'image_xai' => env('AI_MODEL_IMAGE_XAI', 'grok-2-image-1212'),
        'video_xai' => env('AI_MODEL_VIDEO_XAI', 'grok-imagine-video'),

        'text_grok' => env('AI_MODEL_TEXT_GROK', 'x-ai/grok-4-fast'),
        'image_grok' => env('AI_MODEL_IMAGE_GROK', 'x-ai/grok-imagine-image-2.0'),
        'video_grok' => env('AI_MODEL_VIDEO_GROK', 'x-ai/grok-imagine-video-1.5'),

    ],

    /*
     * Embedding models served only from the regional Vertex host, via
     * :embedContent with a {content: {parts: []}} body. Everything else uses
     * :predict on the global host with {instances: [{content: ""}]}.
     *
     * Matched as name prefixes. This was a `str_starts_with($model, 'gemini-embedding-2')`
     * buried in the service, which meant the endpoint a new embedding model
     * needed was a code change rather than a config line.
     */
    'regional_embedding_models' => [
        'gemini-embedding-2',
    ],

    /*
     * Model fallback chain. When a model fails all retries, the service
     * will automatically try the next model in this chain before giving up.
     */
    'fallback_chain' => [
        'gemini-3.7-flash' => 'gemini-3.5-flash',
        'gemini-3.1-pro-preview' => 'gemini-3.5-flash',
        'gemini-2.5-pro' => 'gemini-3.5-flash',
        'gemini-3.5-flash' => 'gemini-2.5-flash',
        'gemini-3-flash-preview' => 'gemini-3.5-flash',
        'gemini-2.5-flash' => 'gemini-3.1-flash-lite-preview',
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
        'creative' => ['temperature' => 1.0,  'topP' => 0.95, 'topK' => 40],
        'analytical' => ['temperature' => 0.7,  'topP' => 0.90, 'topK' => 32],
        'extraction' => ['temperature' => 0.15, 'topP' => 0.85, 'topK' => 16],
        'classification' => ['temperature' => 0.10, 'topP' => 0.80, 'topK' => 8],
        'conversational' => ['temperature' => 0.85, 'topP' => 0.95, 'topK' => 40],
        'strategy' => ['temperature' => 0.80, 'topP' => 0.92, 'topK' => 40],
    ],

    /*
     * Default model tier per task type. References the model keys above.
     * Agents should pass task_type in context rather than hardcoding model names.
     */
    'task_models' => [
        'creative' => 'default',
        'analytical' => 'default',
        'extraction' => 'lite',
        'classification' => 'lite',
        'conversational' => 'default',
        'strategy' => 'pro',
    ],

    /*
     * Pricing per 1M tokens (USD). Used to calculate costs stored in ai_costs.
     * Sourced from the Vertex / Gemini Enterprise Agent Platform pricing page
     * (verified 2026-07-19). Pro tiers use the <=200K-token rate; Flash uses the
     * global rate. Veo is billed per second — see video_cost_per_second below.
     */
    'pricing' => [
        'gemini-3.1-pro-preview' => ['input' => 2.00,   'output' => 12.00, 'cached' => 0.20],
        'gemini-2.5-pro' => ['input' => 1.25,   'output' => 10.00, 'cached' => 0.13],
        // Standard rates, in force from 1 Jan 2027. The introductory rates that
        // apply until then are declared alongside rather than in place of these:
        // config is cached in production, so a date check evaluated here would
        // freeze at whatever the date was when the cache was written, and every
        // cost from January would read half what it should.
        'gemini-3.7-flash' => [
            'input' => 1.50, 'output' => 7.50, 'cached' => 0.15,
            'introductory' => ['until' => '2027-01-01', 'input' => 0.75, 'output' => 3.75, 'cached' => 0.075],
        ],
        'gemini-3.5-flash' => ['input' => 1.50,   'output' => 9.00,  'cached' => 0.15],
        'gemini-3-flash-preview' => ['input' => 0.50,   'output' => 3.00,  'cached' => 0.05],
        'gemini-2.5-flash' => ['input' => 0.30,   'output' => 2.50,  'cached' => 0.03],
        'gemini-3.1-flash-lite-preview' => ['input' => 0.25,   'output' => 1.50,  'cached' => 0.025],
        'gemini-2.5-flash-lite' => ['input' => 0.10,   'output' => 0.40,  'cached' => 0.01],
        // Image model: output priced at the image-output rate ($60/1M tokens).
        'gemini-2.5-flash-image' => ['input' => 0.50,   'output' => 60.00, 'cached' => 0.05],
        'gemini-3.1-flash-image' => ['input' => 0.50,   'output' => 60.00, 'cached' => 0.05],
        'gemini-3.1-flash-image-preview' => ['input' => 0.50,   'output' => 60.00, 'cached' => 0.05],
        // TTS: text in at $0.50/1M, audio out at $10/1M tokens.
        'gemini-2.5-flash-preview-tts' => ['input' => 0.50, 'output' => 10.00, 'cached' => 0.00],
        'gemini-embedding-2-preview' => ['input' => 0.0010, 'output' => 0.00,  'cached' => 0.00],
        'gemini-embedding-001' => ['input' => 0.0010, 'output' => 0.00,  'cached' => 0.00],
        'text-embedding-005' => ['input' => 0.0010, 'output' => 0.00,  'cached' => 0.00],
        'veo-3.1-generate-001' => ['input' => 0.00,   'output' => 0.00,  'cached' => 0.00], // billed per second
        // OpenRouter Grok models bill flat per artefact, not per token:
        // images via openrouter_image_cost, video via video_cost_per_second.
        // Both are recorded as cost_override at dispatch; the zero entries
        // here exist so token-based cost tracking knows they are covered.
        // Per million tokens, OpenRouter's published rate. A model priced at
        // zero here has its spend recorded as zero, which is how twelve of them
        // went unnoticed.
        /*
         * xAI direct. The same models as the OpenRouter rows below, so the
         * same rates until an invoice says otherwise — OpenRouter resells at
         * list price rather than marking up.
         *
         * Present at all because a model named in code and missing here has
         * its spend recorded as zero, which is how a provider's entire cost
         * disappears from every per-customer view without anything failing.
         */
        'grok-4-fast' => ['input' => 0.20, 'output' => 0.50, 'cached' => 0.05],
        'grok-2-image-1212' => ['input' => 0.00, 'output' => 0.00, 'cached' => 0.00], // flat per image
        'grok-imagine-video' => ['input' => 0.00, 'output' => 0.00, 'cached' => 0.00], // billed per second

        'x-ai/grok-4-fast' => ['input' => 0.20, 'output' => 0.50, 'cached' => 0.05],
        'x-ai/grok-imagine-image-2.0' => ['input' => 0.00, 'output' => 0.00, 'cached' => 0.00], // flat per image
        'x-ai/grok-imagine-video-1.5' => ['input' => 0.00, 'output' => 0.00, 'cached' => 0.00], // billed per second
    ],

    /*
     * Per-second billing for video models (USD). Vertex Veo 3.1 with audio at
     * 720p/1080p is $0.40/s (verified 2026-08-24). Recorded at dispatch via
     * cost_override — the token-based table above prices every Veo run at $0.
     */
    'video_cost_per_second' => [
        'veo-3.1-generate-001' => 0.40,
        // Grok 1.5 via OpenRouter: $0.08/s at 480p, $0.25/s at 1080p; 720p
        // rate unpublished — estimated between the two.
        'x-ai/grok-imagine-video-1.5' => 0.15,
        // The same model on xAI's own account, so the same rate.
        'grok-imagine-video' => 0.15,
        'default' => 0.40,
    ],

    /*
     * Providers for fresh creative generation. 'grok' routes through
     * OpenRouter (requires OPENROUTER_API_KEY) with automatic fallback to
     * Gemini/Veo; 'gemini' uses the Google stack directly. Seeded image
     * generation and image edits always use Gemini (reference-image
     * support), regardless of this setting.
     */
    /*
     * Who writes the words.
     *
     * Every text path — ad copy, strategy, brand extraction, the copilot, the
     * public demo — goes through GeminiService::generateContent(), which is
     * the one place that reads this. 'xai' sends them to Grok on xAI's own
     * API and falls back to Gemini when it cannot answer; 'gemini' is the
     * previous behaviour.
     *
     * The fallback is the point: two vendors on separate balances means one
     * lapse is a cost problem rather than an outage, which is exactly what a
     * single dry balance cost us before.
     */
    'text_provider' => env('AI_TEXT_PROVIDER', 'gemini'),

    'image_provider' => env('AI_IMAGE_PROVIDER', 'grok'),
    'video_provider' => env('AI_VIDEO_PROVIDER', 'grok'),

    /*
     * Flat per-image cost for Grok Imagine 2.0 at 1K via OpenRouter.
     */
    'openrouter_image_cost' => 0.04,

    /*
     * TTS voice for video narration. Any Gemini prebuilt voice name; the
     * same name always produces the same voice.
     */
    'tts_voice' => env('AI_TTS_VOICE', 'Charon'),

    /*
     * Replace chained-video audio with a single consistent TTS narration.
     */
    'video_narration_tts' => env('AI_VIDEO_NARRATION_TTS', true),

    /*
     * How many distinct video concepts a campaign gets. Each one is produced
     * twice — 16:9 for Google and YouTube, 9:16 for Meta — so the number of
     * videos generated is double this.
     *
     * Two by default, and the default is a cost decision rather than a creative
     * one. Measured from ai_costs: an image is $0.04 and a video is $1.88 on
     * Grok, or $3.20 per 8-second segment when the Veo fallback runs, so a
     * 24-second script is $9.60. Variety in video costs 40 to 220 times what
     * variety in images costs. Two concepts is $7.50 on Grok and $38 on Veo;
     * three would be $11 and $58, which is most of a Starter subscription
     * spent on one campaign's creative.
     *
     * The plan's own video allowance still applies on top of this and is the
     * harder limit — see VideoCollateral::remainingForCampaign().
     */
    'video_concepts_per_campaign' => (int) env('AI_VIDEO_CONCEPTS_PER_CAMPAIGN', 2),

];
