<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\ImageCollateral;
use App\Models\Strategy;
use App\Prompts\CreativeVariant;
use App\Prompts\ImagePrompt;
use App\Prompts\ImagePromptSplitterPrompt;
use App\Services\AdminMonitorService;
use App\Services\GeminiService;
use App\Services\StorageHelper;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Laravel\Facades\Image;

class GenerateImage implements ShouldQueue
{
    /**
     * A soft-deleted customer/campaign mid-queue means the work is moot —
     * discard quietly instead of filling failed_jobs with ModelNotFound.
     */
    public $deleteWhenMissingModels = true;

    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 900; // 15 minutes

    /**
     * Create a new job instance.
     */
    /**
     * @param  int  $slot  which creative in the set this job is producing
     *
     * The slot is the job's position in the set, and it decides which lens the
     * scene is generated through. It has to come from the caller, because the
     * set is three separate jobs: GenerateStrategyCollateral dispatches
     * GenerateImage three times, each with its own splitter call and its own
     * loop counting from zero. Left to itself every job took slot 0 — the
     * un-lensed scene as briefed — so all three asked for the same picture and
     * the lenses never reached the work at all. Three photographs of the same
     * woman in the same apron, which is exactly what came back.
     */
    public function __construct(
        protected Campaign $campaign,
        protected Strategy $strategy,
        protected int $slot = 0
    ) {}

    /**
     * Execute the job.
     */
    public function handle(GeminiService $geminiService, AdminMonitorService $adminMonitorService): void
    {
        Log::info("Starting image generation job for Campaign ID: {$this->campaign->id}, Strategy ID: {$this->strategy->id}");

        try {
            // Check free-tier image limit before generating
            if (! ImageCollateral::canGenerateForCampaign($this->campaign)) {
                Log::info("Image limit reached for Campaign ID: {$this->campaign->id}, skipping generation");

                return;
            }

            // Fetch brand guidelines if available
            $brandGuidelines = $this->campaign->customer->brandGuideline ?? null;
            if (! $brandGuidelines) {
                Log::warning("No brand guidelines found for customer ID: {$this->campaign->customer_id}");
            }

            // Fetch selected product pages
            $productContext = [];
            $selectedPages = $this->campaign->pages; // Assuming relationship is defined
            if ($selectedPages->isNotEmpty()) {
                $productContext = $selectedPages->map(function ($page) {
                    return [
                        'title' => $page->title,
                        'description' => $page->meta_description,
                        'image_url' => $page->metadata['image'] ?? null, // Pass image URL if available
                    ];
                })->toArray();
            }

            $strategyPrompt = $this->strategy->imagery_strategy;

            // Skip generation if the strategy is explicitly "N/A" or similar, without treating it as an error
            if (strlen(trim($strategyPrompt)) < 50 && (stripos($strategyPrompt, 'N/A') !== false || stripos($strategyPrompt, 'Not Applicable') !== false)) {
                Log::info("Skipping image generation for Strategy ID: {$this->strategy->id} due to N/A strategy.");

                return;
            }

            $review = $adminMonitorService->reviewImagePrompt($strategyPrompt);

            if (! $review['is_valid']) {
                $feedback = implode(' ', $review['feedback']);
                throw new \Exception("Image prompt failed validation: {$feedback}");
            }

            // --- AI-Powered Prompt Splitting ---
            $splitterPrompt = (new ImagePromptSplitterPrompt($strategyPrompt))->getPrompt();
            $splitterResponse = $geminiService->generateContent(config('ai.models.default'), $splitterPrompt);

            $prompts = [];
            try {
                $cleanedJson = preg_replace('/^```json\s*|\s*```$/', '', trim($splitterResponse['text']));
                $decoded = json_decode($cleanedJson, true);
                if (json_last_error() !== JSON_ERROR_NONE || ! isset($decoded['prompts']) || ! is_array($decoded['prompts'])) {
                    throw new \Exception('Failed to decode prompts from the splitter model.');
                }
                $prompts = $decoded['prompts'];
            } catch (\Throwable $e) {
                Log::error('Failed to parse prompts from ImagePromptSplitter: '.$e->getMessage(), ['response' => $splitterResponse['text'] ?? null]);
                // Fallback to the original strategy if splitting fails
                $prompts = [$strategyPrompt];
            }

            if (empty($prompts)) {
                // If splitting results in no prompts, fall back to the original strategy
                $prompts = [$strategyPrompt];
                Log::warning('Image prompt splitter returned no prompts. Falling back to the original strategy.');
            }
            // --- End Prompt Splitting ---

            /*
               The only words allowed inside a generated creative are the
               strategy's approved ad copy — the model composes a headline from
               these rather than inventing claims of its own.

               All of them, deliberately. This used to pass three of the five
               headlines and one of the three descriptions, so the model chose
               its headline from a set we had already narrowed for no reason:
               "Launch Your Store with AI" and "Try Free - No Card Required"
               were approved, paid for, and never once shown to the thing
               drawing the ad. Nothing here is a budget — the copy is short by
               construction, Google caps a headline at 30 characters — and a
               model asked for one good line does better given every line it is
               allowed to use.
            */
            $adCopy = $this->strategy->adCopies()->first();
            $adText = $this->renderableCopy($adCopy);

            $successfulUploads = 0;

            // Visual reference for generation, loaded once — not per prompt,
            // which re-downloaded every seed from S3 for every image.
            // Explicit seeds (the wizard's "AI Seed" uploads) win; without
            // any, fall back to the customer's own harvested product and
            // lifestyle photos, so generated ads look like their business
            // rather than a generic render.
            $seedContextImages = [];
            $seeds = $this->campaign->imageCollaterals()
                ->where('is_seed', true)
                ->where('is_active', true)
                ->get();

            if ($seeds->isEmpty()) {
                $seeds = \App\Models\HarvestedAsset::where('customer_id', $this->campaign->customer_id)
                    ->usable()
                    ->whereIn('classification', ['product', 'lifestyle'])
                    ->latest()
                    ->limit(3)
                    ->get();
            }

            foreach ($seeds as $seed) {
                $seedData = StorageHelper::get($seed->s3_path);
                if ($seedData) {
                    $seedContextImages[] = [
                        'mime_type' => StorageHelper::mimeType($seed->s3_path) ?? 'image/jpeg',
                        'data' => base64_encode($seedData),
                    ];
                }
            }

            // Cost attribution for every creative call below. Gemini gets this
            // as well as OpenRouter, which is what makes a provider fallback
            // auditable: without it the Gemini rows land with a NULL
            // customer_id and a per-customer cost view can only ever show Grok.
            $creativeContext = [
                'campaign_id' => $this->campaign->id,
                'customer_id' => $this->campaign->customer_id,
                'task_type' => 'image_generation',
            ];

            foreach ($prompts as $index => $prompt) {
                Log::info('Generating image '.($index + 1).'/'.count($prompts)." for Strategy ID: {$this->strategy->id}");

                /*
                   The lens, not just the scene.

                   The splitter is asked for three distinct scenes and told
                   that paraphrasing is a failure; it still returns three
                   versions of the same picture. Asking a model to vary its own
                   output is a request. This makes the variation structural, so
                   three identical scenes still produce three different
                   photographs — see CreativeVariant.
                */
                $lens = $this->slot + $index;
                $scene = CreativeVariant::apply($prompt, $lens);

                $imagePrompt = (new ImagePrompt($scene, $brandGuidelines, $productContext, $adText))->getPrompt();

                /*
                   One line per creative, readable without reassembling it.

                   The old entry logged the whole template with its newlines
                   intact, so every prompt spanned forty lines of the log and
                   `grep` returned only the first — which is the same for every
                   creative ever generated. Diagnosing "why do these all look
                   the same" was impossible from the one place that knows.

                   The scene and the lens are what actually vary, so they are
                   their own fields, flattened to a single line each. The full
                   prompt stays available under its own key for when the answer
                   is somewhere in the boilerplate.
                */
                Log::info('Image prompt built', [
                    'campaign_id' => $this->campaign->id,
                    'strategy_id' => $this->strategy->id,
                    'slot' => $this->slot,
                    'variant' => $index + 1 .' of '.count($prompts),
                    'lens' => CreativeVariant::label($lens),
                    'scene' => $this->oneLine($prompt),
                    'scene_with_lens' => $this->oneLine($scene),
                    'ad_text' => $this->oneLine($adText),
                    'prompt' => $this->oneLine($imagePrompt),
                ]);

                // Each format is generated at its own aspect ratio.
                //
                // These used to be three centre-crops of a single square, and
                // cover() discards 23.8% of the height off the top AND the
                // bottom to reach 1200x628 — 47.7% of the picture. Measured,
                // not estimated: a band drawn across the top 11.7% of a
                // 1024x1024 source does not survive the landscape crop at all.
                // It decapitated the headline and sliced the CTA card off the
                // bottom of every landscape creative.
                //
                // A photograph survives that treatment. Artwork with type set
                // into it does not, and the prompt asks for artwork with type
                // set into it. Generated natively per aspect, the only trim
                // left is the difference between the model's ratio and the
                // exact pixel size: none for square, 8.3% for MREC, ~3.5% for
                // 16:9 down to 1200x628.
                $adFormats = [
                    'square' => ['size' => [1024, 1024], 'aspect' => '1:1'],
                    'landscape' => ['size' => [1200, 628], 'aspect' => '16:9'],
                    // 300x250 is 6:5. Cropped from a square it lost a fifth of
                    // the height; 4:3 is the nearest ratio either provider
                    // generates natively, so the trim is a sliver rather than a
                    // slice through the subject.
                    'mrec' => ['size' => [300, 250], 'aspect' => '4:3'],
                ];

                $bases = [];
                foreach (array_unique(array_column($adFormats, 'aspect')) as $aspect) {
                    $generated = $this->generateAtAspect(
                        $imagePrompt,
                        $aspect,
                        $seedContextImages,
                        $creativeContext,
                        $geminiService
                    );

                    if ($generated) {
                        $bases[$aspect] = $generated;
                    }
                }

                if ($bases === []) {
                    Log::error('Failed to generate any image for prompt index '.($index + 1));

                    continue;
                }

                // Identical for every format — computed once per prompt.
                $customer = $this->campaign->customer;
                // Asked whether some person on the account was paying. A paid
                // setup-only customer has no subscription and is still entitled,
                // which that got wrong; isOnPaidPlan() covers both.
                $isSubscribed = $customer->isOnPaidPlan();

                $brandName = $customer->name ?? '';
                $tagline = $isSubscribed ? $this->taglineFor($adCopy, $brandGuidelines) : null;

                /*
                 * The cap is checked here, per scene, and not before dispatch.
                 *
                 * GenerateStrategyCollateral consulted it three times in a row
                 * before a single row existed, so it always passed — and each
                 * of the three jobs then wrote as many scenes as the splitter
                 * happened to return. That is how one campaign ended up with
                 * eight pictures and another with four from the same code.
                 */
                /*
                 * A cheap check before spending on uploads. It is not the gate
                 * — three of these jobs run at once, so any read-then-write is
                 * a race — but it stops the obvious case without a lock. The
                 * real decision is made inside createConcept() below.
                 */
                $written = ImageCollateral::conceptsForCampaign($this->campaign);

                if ($written >= ImageCollateral::capForCampaign($this->campaign)) {
                    Log::info("Image cap reached for campaign {$this->campaign->id}; stopping at {$written} pictures");

                    break;
                }

                // Collected, not written: the rows go in together under one
                // lock once every format has been uploaded.
                $pendingRows = [];

                foreach ($adFormats as $format => $spec) {
                    [$targetW, $targetH] = $spec['size'];

                    // One aspect failing while the other succeeded still
                    // yields a usable ad — cropped, as it was before, but
                    // present rather than missing.
                    $imageData = $bases[$spec['aspect']] ?? reset($bases);

                    $decodedImage = base64_decode($imageData['data'], true);
                    if ($decodedImage === false) {
                        Log::warning("Failed to decode the generated image for format {$format}");

                        continue;
                    }

                    $extension = $this->getExtensionFromMimeType($imageData['mimeType']);

                    try {
                        $img = Image::read($decodedImage);
                        $img->cover($targetW, $targetH);

                        $w = $img->width();
                        $h = $img->height();

                        if ($isSubscribed) {
                            $bannerH = (int) ($h * 0.18);
                            $img->drawRectangle(0, $h - $bannerH, function ($draw) use ($w, $bannerH) {
                                $draw->size($w, $bannerH);
                                $draw->background('rgba(0, 0, 0, 0.65)');
                            });

                            $fontPath = $this->resolveFont();

                            if ($brandName) {
                                $img->text($brandName, (int) ($w / 2), $h - $bannerH + (int) ($bannerH * 0.38), function ($font) use ($fontPath, $bannerH) {
                                    if ($fontPath) {
                                        $font->filename($fontPath);
                                    }
                                    $font->size((int) ($bannerH * 0.38));
                                    $font->color('ffffff');
                                    $font->align('center');
                                    $font->valign('middle');
                                });
                            }

                            if ($tagline) {
                                $img->text($tagline, (int) ($w / 2), $h - $bannerH + (int) ($bannerH * 0.72), function ($font) use ($fontPath, $bannerH) {
                                    if ($fontPath) {
                                        $font->filename($fontPath);
                                    }
                                    $font->size((int) ($bannerH * 0.22));
                                    $font->color('rgba(220, 220, 220, 1)');
                                    $font->align('center');
                                    $font->valign('middle');
                                });
                            }
                        } else {
                            $fontPath = $this->resolveFont();
                            $img->text('Preview', $w - 20, $h - 20, function ($font) use ($fontPath) {
                                if ($fontPath) {
                                    $font->filename($fontPath);
                                }
                                $font->size(24);
                                $font->color('ffffff');
                                $font->align('right');
                                $font->valign('bottom');
                            });
                        }

                        $encoded = (string) $img->encode();
                    } catch (\Throwable $e) {
                        Log::warning("Failed to apply overlay for format {$format}: ".$e->getMessage());
                        $encoded = $decodedImage;
                    }

                    $filename = uniqid('img_', true)."_{$format}.{$extension}";
                    $storagePath = "collateral/images/{$this->campaign->id}/{$filename}";
                    [$s3Path, $cloudFrontUrl] = StorageHelper::put($storagePath, $encoded, $imageData['mimeType']);

                    $pendingRows[] = [
                        'campaign_id' => $this->campaign->id,
                        'strategy_id' => $this->strategy->id,
                        'platform' => $this->strategy->platform,
                        's3_path' => $s3Path,
                        'cloudfront_url' => $cloudFrontUrl,
                        'format' => $format,
                    ];

                    Log::info("Image uploaded [{$format}]: {$s3Path}");
                }

                // The cap is enforced here, not above: this is the only point
                // where reading the count and writing the rows happen together.
                $conceptKey = ImageCollateral::createConcept($this->campaign, $pendingRows);

                if ($conceptKey === null) {
                    Log::info("Image cap reached for campaign {$this->campaign->id} while uploading; discarding this picture");

                    break;
                }

                $successfulUploads++;
            }

            $existing = $this->strategy->collateral_errors ?? [];

            if ($successfulUploads === 0) {
                /*
                 * Nothing was produced, and that is a failure however calmly
                 * the loop arrived here.
                 *
                 * This line used to read "Successfully generated and stored 0
                 * image(s)" and then clear collateral_errors['image'] — wiping
                 * the record of the failure on the way past. The job completed,
                 * so the queue recorded success, nothing reached failed_jobs,
                 * and the customer sat on "Generating your collateral... this
                 * usually takes 1-2 minutes" indefinitely. Both image providers
                 * were down at once (OpenRouter 402, Gemini 429) and the only
                 * place that fact existed was a log line nobody reads.
                 */
                Log::error("GenerateImage produced no images for Strategy ID: {$this->strategy->id}", [
                    'campaign_id' => $this->campaign->id,
                    'prompts' => count($prompts),
                ]);

                // Reaches the admin dashboard, which a Log::error alone does not.
                // Recorded whether or not the customer sees anything: a slot
                // that produced nothing is worth knowing about even when its
                // siblings covered for it.
                report(new \RuntimeException("GenerateImage produced no images for strategy {$this->strategy->id}"));

                /*
                 * Only tell the customer when they actually have nothing.
                 *
                 * Three of these jobs run per strategy. On the first run of
                 * this check, two succeeded and one did not, and the page
                 * showed nine perfectly good images above "We could not
                 * generate images just now" — which is worse than either
                 * outcome on its own, because it makes the page look broken
                 * when it is not.
                 */
                if (ImageCollateral::where('campaign_id', $this->campaign->id)->where('is_active', true)->exists()) {
                    Log::info("Strategy {$this->strategy->id} already has images from another slot; not reporting a failure to the customer");

                    return;
                }

                $existing['image'] = 'We could not generate images just now — the image service is unavailable. Nothing else about your campaign is affected, and you can try again from this page.';
                $this->strategy->update(['collateral_errors' => $existing]);

                return;
            }

            Log::info("Successfully generated and stored {$successfulUploads} image(s) for Strategy ID: {$this->strategy->id}");

            unset($existing['image']);
            $this->strategy->update(['collateral_errors' => empty($existing) ? null : $existing]);

        } catch (\Throwable $e) {
            Log::error("Error in GenerateImage job for Strategy ID {$this->strategy->id}: ".$e->getMessage());
            $this->fail($e);
        }
    }

    /**
     * OpenRouter takes pixel dimensions where Gemini takes a ratio.
     */
    /**
     * What fits on the banner's second line at 22% of banner height. Google
     * caps ad headlines at 30 characters, so approved copy clears this by
     * construction — which is the point of drawing from it.
     */
    private const TAGLINE_MAX_CHARS = 32;

    private const GROK_SIZES = [
        '1:1' => '1024x1024',
        '16:9' => '1344x768',
        '4:3' => '1152x896',
    ];

    /**
     * A multi-line prompt as one greppable line.
     *
     * Monolog writes context values verbatim, so an embedded newline splits one
     * log entry across dozens of lines and every tool that reads logs a line at
     * a time sees only the first of them.
     */
    private function oneLine(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Every word the image model is allowed to render.
     *
     * All of the approved copy, deliberately. This used to pass three of the
     * five headlines and one of the three descriptions, so the model chose its
     * headline from a set we had already narrowed for no reason: lines that
     * were written, approved and paid for were never shown to the thing
     * drawing the ad.
     *
     * Nothing here is a payload budget. Google caps a headline at 30
     * characters, so the whole set is a few hundred bytes, and a model asked
     * for one good line does better given every line it may legitimately use.
     *
     * @return string empty when there is no approved copy — the template's
     *                rules then produce a composition with no words at all
     */
    private function renderableCopy(?\App\Models\AdCopy $adCopy): string
    {
        if (! $adCopy) {
            return '';
        }

        return trim(implode("\n", array_filter(array_map(
            fn ($line) => trim((string) $line),
            array_merge($adCopy->headlines ?? [], $adCopy->descriptions ?? []),
        ))));
    }

    /**
     * The one line of brand copy burnt into the banner.
     *
     * Was unique_selling_propositions[0] cut to 29 characters with an ellipsis
     * appended. A USP is a paragraph written for the strategy model to reason
     * from — 133 characters in the case that exposed this — so the cut landed
     * mid-clause and every creative in the set carried the same dangling
     * fragment: "Conversational AI agent that …". Nine ads, one broken
     * sentence, burnt into the pixels.
     *
     * Ad headlines are the right source and cost nothing. Google caps them at
     * 30 characters, so they are already short enough by construction; they
     * are written as ad copy rather than as analysis; and they are the exact
     * words the customer approved. The longest that still fits is preferred —
     * it carries the most, and all of them fit.
     *
     * When there is no approved copy the banner carries the brand name alone.
     * A tagline is worth having; a truncated one is worse than none, and that
     * judgement is what the old code got backwards.
     */
    private function taglineFor(?\App\Models\AdCopy $adCopy, ?\App\Models\BrandGuideline $brandGuidelines): ?string
    {
        $candidates = collect($adCopy->headlines ?? [])
            ->map(fn ($h) => trim((string) $h))
            ->filter(fn ($h) => $h !== '' && mb_strlen($h) <= self::TAGLINE_MAX_CHARS);

        if ($candidates->isNotEmpty()) {
            return $candidates->sortByDesc(fn ($h) => mb_strlen($h))->first();
        }

        /*
           Nothing approved to draw from. A messaging theme occasionally fits
           on its own — it is a phrase rather than a paragraph — so it is worth
           one look, but only if it fits as written. Nothing is truncated here.
        */
        foreach ($brandGuidelines->messaging_themes ?? [] as $theme) {
            $theme = trim(preg_replace('/^[^:]+:\s*/', '', (string) $theme));

            if ($theme !== '' && mb_strlen($theme) <= self::TAGLINE_MAX_CHARS) {
                return $theme;
            }
        }

        return null;
    }

    /**
     * One generated image at a given aspect ratio, with retries.
     *
     * Seeded generation stays on Gemini, which is the only provider here with
     * reference-image support. Fresh generation goes to the configured
     * provider — Grok via OpenRouter by default, chosen in the 2026-08-24
     * shootout — with Gemini as the automatic fallback.
     *
     * @param  list<array{mime_type: string, data: string}>  $seedContextImages
     * @param  array<string, mixed>  $creativeContext
     * @return array{data: string, mimeType: string}|null
     */
    private function generateAtAspect(
        string $imagePrompt,
        string $aspect,
        array $seedContextImages,
        array $creativeContext,
        GeminiService $geminiService,
    ): ?array {
        $maxRetries = 3;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            if ($attempt > 1) {
                /*
                 * Base delay is configurable so the suite does not sleep.
                 *
                 * Two tests covering the both-providers-down path took 28
                 * seconds each, entirely in backoff — half again on the whole
                 * suite to wait for something the test is not measuring.
                 * Zeroed in phpunit.xml; unchanged in production.
                 */
                $base = (int) config('ai.image_retry_base_delay', 2);
                $waitTime = $base > 0 ? pow($base, $attempt - 1) : 0;

                if ($waitTime > 0) {
                    Log::info("Retrying image generation after {$waitTime} seconds (attempt {$attempt}/{$maxRetries})");
                    sleep($waitTime);
                }
            }

            $imageData = null;
            $usedProvider = 'gemini';

            if (! empty($seedContextImages)) {
                // Say so out loud: auto-seeding from harvested assets routes
                // this customer onto Gemini even though ai.image_provider says
                // grok, and that used to leave no trace in the logs or in the
                // cost table.
                Log::info('Generating image on Gemini with '.count($seedContextImages).' seed image(s) as reference', [
                    'configured_provider' => config('ai.image_provider'),
                    'reason' => 'reference images require Gemini',
                    'aspect_ratio' => $aspect,
                ]);

                $imageData = $geminiService->refineImage(
                    $imagePrompt,
                    $seedContextImages,
                    context: $creativeContext,
                    aspectRatio: $aspect
                );
            } else {
                if (config('ai.image_provider') === 'grok') {
                    $imageData = app(\App\Services\OpenRouterService::class)->generateImage(
                        $imagePrompt,
                        $creativeContext,
                        self::GROK_SIZES[$aspect] ?? self::GROK_SIZES['1:1']
                    );

                    if ($imageData) {
                        $usedProvider = 'grok';
                    } else {
                        Log::warning('Grok image generation failed — falling back to Gemini.', [
                            'strategy_id' => $this->strategy->id,
                            'attempt' => $attempt,
                            'aspect_ratio' => $aspect,
                        ]);
                    }
                }

                $imageData ??= $geminiService->generateImage(
                    $imagePrompt,
                    context: $creativeContext,
                    aspectRatio: $aspect
                );
            }

            if ($imageData && isset($imageData['data'], $imageData['mimeType'])) {
                Log::info("Successfully generated {$aspect} image on attempt {$attempt} via {$usedProvider}");

                return $imageData;
            }

            Log::warning("Failed to generate {$aspect} image data on attempt {$attempt}/{$maxRetries}");
        }

        return null;
    }

    /**
     * Resolve a usable font path for Intervention Image text rendering.
     * Falls back through a chain of common system font locations.
     */
    private function resolveFont(): ?string
    {
        $candidates = [
            public_path('fonts/Arial.ttf'),
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
            '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
            '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
        ];

        foreach ($candidates as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Get the file extension from a MIME type.
     */
    private function getExtensionFromMimeType(string $mimeType): string
    {
        $parts = explode('/', $mimeType);

        return end($parts) ?: 'png'; // Default to png if detection fails
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateImage failed: '.$exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);

        $existing = $this->strategy->collateral_errors ?? [];
        $existing['image'] = $exception->getMessage();
        $this->strategy->update(['collateral_errors' => $existing]);
    }
}
