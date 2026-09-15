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

            /*
             * Wait for the copy rather than draw a picture with no words on it.
             *
             * GenerateStrategyCollateral dispatches GenerateAdCopy five seconds
             * after sign-off and the image jobs ten seconds after, and that is
             * a race the image jobs can lose: on campaign 44 the copy landed at
             * 08:51:13 and the first image job had already read this line at
             * about 08:51:10. The headline is composited from that copy, so
             * losing the race does not mean a slightly worse ad — it means a
             * stock photograph with nothing written on it, which is not an
             * advertisement and is not what the customer approved.
             *
             * Releasing costs twenty seconds. Generating a creative nobody can
             * run costs the whole slot.
             */
            if (! $adCopy && $this->attempts() < self::COPY_WAIT_ATTEMPTS) {
                Log::info("Ad copy not written yet for strategy {$this->strategy->id}; releasing image slot {$this->slot}", [
                    'attempt' => $this->attempts(),
                ]);

                $this->release(self::COPY_WAIT_SECONDS);

                return;
            }

            if (! $adCopy) {
                // Out of patience: a picture with no headline is still better
                // than no creative, and the copy has clearly failed elsewhere.
                Log::warning("Generating images without ad copy for strategy {$this->strategy->id} after {$this->attempts()} attempts");
            }

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
                /*
                 * Unique across the campaign, not just within this job.
                 *
                 * This was $this->slot + $index, so slot 0's second scene and
                 * slot 1's first both computed to 1 — two creatives with the
                 * same lens AND the same headline, in a set of four whose
                 * entire purpose is to differ. Counting what the campaign
                 * already holds gives each picture its own index, because a
                 * concept's rows are written before the next one is composed.
                 */
                $lens = ImageCollateral::conceptsForCampaign($this->campaign);
                $scene = CreativeVariant::apply($prompt, $lens);

                /*
                 * A different approved headline on each creative.
                 *
                 * Given all five and asked for "one of those lines", the model
                 * picked the same one four times: four photographs of a single
                 * message. Five headlines were written, approved and paid for
                 * precisely so the account can find out which of them works —
                 * and Google cannot learn that from four copies of one.
                 *
                 * Keyed on the lens, so the thing that makes each creative
                 * visually distinct also makes it say something distinct. The
                 * full set still goes in the markers: it is the vocabulary the
                 * model may draw from, and this is which line leads.
                 */
                $headline = $this->headlineForLens($adCopy, $lens);

                $imagePrompt = (new ImagePrompt(
                    $scene,
                    $brandGuidelines,
                    $productContext,
                    $adText,
                    // The banner is only composited for paying accounts, and
                    // reserving space for one that never arrives is what put a
                    // flat navy band across the bottom of a free account's
                    // creative.
                    bannerComposited: $this->campaign->customer->isOnPaidPlan(),
                    headline: $headline,
                ))->getPrompt();

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

                    /*
                     * One aspect failing still yields a usable ad, but not at
                     * any price.
                     *
                     * This was `?? reset($bases)` — the first base generated,
                     * whatever shape it was. Substituting the square into the
                     * 1200x628 slot means cover() discards 47.7% of the
                     * height: half the photograph, silently, and increasingly
                     * often while the providers are rate-limiting. It now takes
                     * the nearest shape available and says what that costs.
                     */
                    $fill = $this->nearestBase($bases, $spec['aspect'], $targetW, $targetH, $format);
                    $imageData = $fill['base'] ?? null;

                    if ($imageData === null) {
                        Log::warning("No usable base for format {$format}; skipping it rather than shipping a severe crop", [
                            'strategy_id' => $this->strategy->id,
                            'available' => array_keys($bases),
                        ]);

                        continue;
                    }

                    $decodedImage = base64_decode($imageData['data'], true);
                    if ($decodedImage === false) {
                        Log::warning("Failed to decode the generated image for format {$format}");

                        continue;
                    }

                    $extension = $this->getExtensionFromMimeType($imageData['mimeType']);

                    try {
                        $img = Image::read($decodedImage);

                        /*
                         * Scaled to the slot, not cropped into it.
                         *
                         * cover() fills the slot and throws away the overflow:
                         * 6.2% of the height reaching 1200x628, 6.7% of the
                         * width reaching 300x250, and 47.7% when a
                         * wrong-shaped base was substituted. None of it was
                         * measured and all of it was content somebody briefed.
                         *
                         * resize() keeps the whole photograph and stretches it
                         * to fit instead. That is only honest while the
                         * mismatch is small, which is why the sizes requested
                         * now match the slots and why nearestBase refuses a
                         * substitute whose shape is too far off — a 6% stretch
                         * is invisible, a 48% one would be a funhouse mirror.
                         */
                        if (($fill['mode'] ?? 'scale') === 'scale') {
                            $img->resize($targetW, $targetH);
                        } else {
                            // Too far off to stretch without it showing.
                            $img->cover($targetW, $targetH);
                        }

                        $w = $img->width();
                        $h = $img->height();

                        /*
                         * Drawn after the crop, so it is measured against the
                         * pixels that will actually ship.
                         *
                         * Asking the model for it failed twice on the same
                         * creative, and the second attempt to word the margin
                         * rule drew a navy border around a different one. Set
                         * here it cannot be cropped, and the square, landscape
                         * and MREC crops of one picture carry the same words in
                         * the same place instead of three separate renderings.
                         */
                        if ($headline !== null) {
                            $this->drawHeadline($img, $headline, $this->resolveFont());
                        }

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

    /**
     * Pixel sizes requested from Grok, keyed by the ratio Gemini is asked for.
     *
     * The values match the AD FORMAT each ratio serves, not the ratio's own
     * name, because cover() crops whatever does not fit and the difference was
     * being thrown away: a 1344x768 source (1.778) trimmed to a 1200x628 slot
     * (1.911) lost 6.2% of its height, and 1152x896 (1.333) trimmed to 300x250
     * (1.200) lost 6.7% of its width. Asking for the slot's own proportions
     * makes the same call a pure downscale.
     *
     * Gemini takes a ratio from a fixed set rather than a pixel size, so 16:9
     * and 4:3 remain the closest it offers and those two still cost the few
     * per cent above.
     */
    /**
     * How far a base may be stretched to fill a slot it was not generated for.
     *
     * The slot is filled by scaling now rather than cropping, so the cost of a
     * mismatch is distortion instead of lost picture. Around twelve per cent
     * it stops being invisible and starts showing on a face.
     */
    /**
     * How long an image job waits for the ad copy it draws its headline from.
     *
     * Six attempts at twenty seconds is two minutes — comfortably longer than
     * GenerateAdCopy takes, and bounded so a copy job that has genuinely failed
     * does not hold the creative hostage for ever.
     */
    private const COPY_WAIT_ATTEMPTS = 6;

    private const COPY_WAIT_SECONDS = 20;

    private const MAX_STRETCH = 0.12;

    /**
     * How much of a substituted picture may be cropped away to save a format.
     *
     * Only reached when the shape is too far off to scale without the stretch
     * showing. Past a third the composition is gone rather than tightened.
     */
    private const MAX_CROP = 0.34;

    private const GROK_SIZES = [
        '1:1' => '1024x1024',
        // 1.911, matching 1200x628.
        '16:9' => '1376x720',
        // 1.200, matching 300x250 exactly.
        '4:3' => '1200x1000',
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
    /**
     * Which approved headline this creative leads with.
     *
     * Rotates through the approved set by lens, so a set of four creatives
     * carries four different messages rather than four pictures of one. Null
     * when there is no approved copy, which leaves the choice to the model as
     * before — there is nothing to nominate.
     */
    private function headlineForLens(?\App\Models\AdCopy $adCopy, int $lens): ?string
    {
        $headlines = array_values(array_filter(array_map(
            fn ($line) => trim((string) $line),
            $adCopy->headlines ?? [],
        )));

        if ($headlines === []) {
            return null;
        }

        return $headlines[$lens % count($headlines)];
    }

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
    /**
     * The generated base closest in shape to the slot being filled.
     *
     * Returns the base and how to fit it: 'scale' while the stretch is
     * invisible, 'crop' when the shape is too far off to stretch but a tighter
     * composition still beats no creative, and null when even cropping would
     * take the picture rather than tighten it.
     *
     * @param  array<string, array{data: string, mimeType: string}>  $bases
     * @return array{base: array{data: string, mimeType: string}, mode: string}|null
     */
    private function nearestBase(array $bases, string $wanted, int $targetW, int $targetH, string $format): ?array
    {
        if ($bases === []) {
            return null;
        }

        if (isset($bases[$wanted])) {
            return ['base' => $bases[$wanted], 'mode' => 'scale'];
        }

        $targetRatio = $targetW / $targetH;
        $best = null;
        $bestStretch = null;
        $bestAspect = 'unknown';

        foreach ($bases as $aspect => $base) {
            $size = @getimagesizefromstring(base64_decode($base['data'], true) ?: '');

            if (! $size || $size[0] <= 0 || $size[1] <= 0) {
                continue;
            }

            /*
             * How far this base would have to be stretched, now that the slot
             * is filled by scaling rather than cropping. Symmetric, so
             * squashing by a third and stretching by a third score alike.
             */
            $ratio = $size[0] / $size[1];
            $stretch = abs(1 - ($ratio / $targetRatio));

            if ($bestStretch === null || $stretch < $bestStretch) {
                $best = $base;
                $bestStretch = $stretch;
                $bestAspect = $aspect;
            }
        }

        if ($best === null || $bestStretch === null) {
            return null;
        }

        Log::warning("Substituting a {$bestAspect} base for the {$format} slot", [
            'strategy_id' => $this->strategy->id,
            'wanted' => $wanted,
            'target_ratio' => round($targetRatio, 3),
            'stretch_percent' => round($bestStretch * 100, 1),
        ]);

        /*
         * Scale while the stretch is invisible; crop rather than lose the slot.
         *
         * Refusing everything past twelve per cent was too strict in practice.
         * One 4:3 generation failing left a campaign with three rows across two
         * concepts — an ad set that cannot serve the display placements at all,
         * which is worse for the customer than a tighter crop of a 300x250
         * banner. A square into the MREC slot is a 16.7% stretch, visible on a
         * face, but only a 16.7% crop of the height, and the brief already
         * keeps subjects clear of the outer 10%.
         */
        if ($bestStretch <= self::MAX_STRETCH) {
            return ['base' => $best, 'mode' => 'scale'];
        }

        $size = @getimagesizefromstring(base64_decode($best['data'], true) ?: '');
        $cropLoss = 1.0;

        if ($size && $size[0] > 0 && $size[1] > 0) {
            $scale = max($targetW / $size[0], $targetH / $size[1]);
            $cropLoss = 1 - ($targetW * $targetH) / (($size[0] * $scale) * ($size[1] * $scale));
        }

        // A third is where tightening the composition becomes destroying it.
        // The square into 1200x628 is 47.7%, and still goes unfilled.
        return $cropLoss > self::MAX_CROP ? null : ['base' => $best, 'mode' => 'crop'];
    }

    /**
     * Fit a headline into a width, exactly, by measuring it.
     *
     * Two prompt revisions asked an image model to keep the headline inside
     * the frame and both failed on the same creative — "Build a Store in 5
     * Minute", then "e a Chat, Get a S" — and the second revision drew a navy
     * border around a different creative while trying. A diffusion model
     * sizing type has no notion of a safe area, and every attempt to describe
     * one perturbs the rest of the picture.
     *
     * imagettfbbox() measures the actual glyphs at an actual size, so the
     * answer is arithmetic rather than persuasion. Returns the lines to draw
     * and the size to draw them at, or null when even one word cannot be made
     * to fit.
     *
     * @return array{lines: list<string>, size: int}|null
     */
    private function fitHeadline(string $text, string $fontPath, int $maxWidth, int $startSize, int $maxLines = 2): ?array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];

        if ($words === []) {
            return null;
        }

        $widthAt = function (string $s, int $size) use ($fontPath): int {
            $box = imagettfbbox($size, 0, $fontPath, $s);

            return $box === false ? PHP_INT_MAX : (int) abs($box[2] - $box[0]);
        };

        // Shrink until the words can be arranged inside maxLines lines that
        // each fit. Floor at a size that is still legible as a thumbnail;
        // below it, no headline beats an unreadable one.
        for ($size = $startSize; $size >= (int) ($startSize * 0.45); $size -= 2) {
            $lines = [];
            $current = '';

            foreach ($words as $word) {
                if ($widthAt($word, $size) > $maxWidth) {
                    // A single word wider than the frame cannot be wrapped out
                    // of trouble; only a smaller size helps.
                    $lines = [];
                    break;
                }

                $candidate = $current === '' ? $word : $current.' '.$word;

                if ($widthAt($candidate, $size) <= $maxWidth) {
                    $current = $candidate;

                    continue;
                }

                $lines[] = $current;
                $current = $word;
            }

            if ($lines === [] && $current === '') {
                continue;
            }

            if ($current !== '') {
                $lines[] = $current;
            }

            if (count($lines) <= $maxLines) {
                return ['lines' => $lines, 'size' => $size];
            }
        }

        return null;
    }

    /**
     * Whether the area the headline will occupy is light or dark.
     *
     * The prompt asks for an uncluttered corner, not a white one, and the
     * photographs come back with brick, glass, sunlight and shadow up there.
     * Sampling decides the ink colour instead of guessing it: a fixed white
     * headline disappears against a bright wall and a fixed dark one
     * disappears against a shadow.
     */
    private function regionIsLight(\Intervention\Image\Interfaces\ImageInterface $img, int $x, int $y, int $w, int $h): bool
    {
        $total = 0;
        $samples = 0;

        // A coarse grid is plenty — this decides one bit.
        for ($i = 1; $i <= 4; $i++) {
            for ($j = 1; $j <= 3; $j++) {
                $px = min($img->width() - 1, max(0, $x + (int) ($w * $i / 5)));
                $py = min($img->height() - 1, max(0, $y + (int) ($h * $j / 4)));

                try {
                    $c = $img->pickColor($px, $py)->toArray();
                } catch (\Throwable) {
                    continue;
                }

                // Rec. 601 luma: green carries most of perceived brightness.
                $total += 0.299 * ($c[0] ?? 0) + 0.587 * ($c[1] ?? 0) + 0.114 * ($c[2] ?? 0);
                $samples++;
            }
        }

        return $samples === 0 ? true : ($total / $samples) > 140;
    }

    /**
     * Draw the approved headline onto the artwork.
     *
     * Set by us, at a measured size, inside a safe area — rather than asked
     * for in the prompt and cropped by the model. It is also the same words in
     * the same place across the square, landscape and MREC crops of one
     * picture, which three separate renderings by the model could never be.
     */
    private function drawHeadline(\Intervention\Image\Interfaces\ImageInterface $img, string $headline, ?string $fontPath): void
    {
        if ($fontPath === null || ! function_exists('imagettfbbox')) {
            return;
        }

        $w = $img->width();
        $h = $img->height();

        // The safe area the prompt reserves: inset from every edge, and clear
        // of the bottom where a brand banner may be composited.
        $margin = (int) ($w * 0.08);
        $maxWidth = $w - ($margin * 2);

        $fitted = $this->fitHeadline($headline, $fontPath, $maxWidth, (int) ($h * 0.085));

        if ($fitted === null) {
            Log::warning('Headline could not be fitted and was left off the creative', [
                'headline' => $headline,
                'width' => $w,
            ]);

            return;
        }

        $size = $fitted['size'];
        $lineHeight = (int) ($size * 1.24);
        $blockHeight = $lineHeight * count($fitted['lines']);
        $top = (int) ($h * 0.075);

        $light = $this->regionIsLight($img, $margin, $top, $maxWidth, $blockHeight);
        $ink = $light ? '101828' : 'ffffff';
        $shadow = $light ? 'rgba(255, 255, 255, 0.55)' : 'rgba(0, 0, 0, 0.45)';

        foreach ($fitted['lines'] as $i => $line) {
            $y = $top + ($i * $lineHeight) + (int) ($size * 0.85);

            // A soft counter-coloured offset keeps it readable where the
            // photograph turns out busier than the brief asked for.
            foreach ([[2, 2, $shadow], [0, 0, $ink]] as [$dx, $dy, $colour]) {
                $img->text($line, $margin + $dx, $y + $dy, function ($font) use ($fontPath, $size, $colour) {
                    $font->filename($fontPath);
                    $font->size($size);
                    $font->color($colour);
                    $font->align('left');
                    $font->valign('bottom');
                });
            }
        }
    }

    private function resolveFont(): ?string
    {
        $candidates = [
            /*
             * Bundled first, so the typography is the same everywhere.
             *
             * This list was entirely system paths, so which face a headline
             * was set in depended on what the box happened to have installed —
             * and on a machine with none, the text was silently left off. It
             * also meant the tests covering the fitting arithmetic skipped
             * rather than ran, locally and in CI both, which is the same as
             * not having them. DejaVu is freely redistributable; the system
             * paths stay as a fallback.
             */
            public_path('fonts/DejaVuSans-Bold.ttf'),
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
