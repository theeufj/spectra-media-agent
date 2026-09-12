<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LandingLead;
use App\Rules\SafePublicUrl;
use App\Services\BrandGuidelineExtractorService;
use App\Services\Demo\CampaignForecastPreview;
use App\Services\GeminiService;
use App\Services\Onboarding\PlaceholderSiteDetector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class DemoController extends Controller
{
    protected BrandGuidelineExtractorService $brandService;

    protected GeminiService $geminiService;

    public function __construct(BrandGuidelineExtractorService $brandService, GeminiService $geminiService)
    {
        $this->brandService = $brandService;
        $this->geminiService = $geminiService;
    }

    public function generateFull(Request $request)
    {
        $request->validate([
            // This endpoint is unauthenticated and fetches whatever it is given
            // from the production box, so the host has to be a real public one:
            // without SafePublicUrl the whole internal network, and the cloud
            // metadata endpoint with it, is three requests a minute away.
            'url' => ['required', 'url', 'max:255', new SafePublicUrl],
            'first_name' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:255',
        ]);

        $url = $request->input('url');
        $firstName = $request->input('first_name', '');
        $userEmail = $request->input('email', '');

        Log::info("DemoController: Starting full extraction demo for {$url}");

        // Keep the lead. Until now this endpoint emailed the team and threw the
        // contact away, so everyone who asked us to look at their website and
        // did not go on to register was lost the moment the page closed. They
        // gave an address to get a result back, which makes them a contact who
        // asked to hear from us rather than a scraped one.
        $this->rememberLead($userEmail, $firstName, $url);

        // Notify the team every time someone hits Try Now — one email to all recipients
        try {
            $ip = $request->ip();
            $userAgent = $request->userAgent() ?? 'unknown';
            $time = now()->format('d M Y, H:i').' UTC';
            $subject = $firstName ? "Try Now: {$firstName} — {$url}" : "Try Now: {$url}";

            $displayName = e($firstName ?: '—');
            $displayEmail = $userEmail
                ? '<a href="mailto:'.e($userEmail).'" style="color:#ff4d00;text-decoration:none;">'.e($userEmail).'</a>'
                : '—';
            $displayUrl = '<a href="'.e($url).'" style="color:#ff4d00;text-decoration:none;">'.e($url).'</a>';
            $displayIp = e($ip);
            $displayTime = e($time);

            $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f5f0ed;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f0ed;padding:40px 16px;">
    <tr><td align="center">
      <table width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;">

        <!-- Header -->
        <tr><td style="background:#1c0800;border-radius:12px 12px 0 0;padding:28px 32px;">
          <table width="100%" cellpadding="0" cellspacing="0">
            <tr>
              <td>
                <span style="display:inline-block;background:#ff4d00;border-radius:8px;width:36px;height:36px;line-height:36px;text-align:center;font-size:18px;font-weight:900;color:#fff;vertical-align:middle;">S</span>
                <span style="color:#fff;font-size:18px;font-weight:700;vertical-align:middle;margin-left:10px;">Site<span style="color:#ff4d00;">ToSpend</span></span>
              </td>
              <td align="right">
                <span style="background:#ff4d00;color:#fff;font-size:11px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;padding:4px 10px;border-radius:20px;">Try Now</span>
              </td>
            </tr>
          </table>
        </td></tr>

        <!-- Body -->
        <tr><td style="background:#fff;padding:32px;border-left:1px solid #e8ddd8;border-right:1px solid #e8ddd8;">
          <p style="margin:0 0 6px;font-size:20px;font-weight:700;color:#1c0800;">New lead on the landing page</p>
          <p style="margin:0 0 28px;font-size:14px;color:#6b5040;">Someone just ran the Try Now demo.</p>

          <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e8ddd8;border-radius:8px;overflow:hidden;">
            <tr style="background:#faf7f5;">
              <td style="padding:12px 16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:#9e7e6e;width:90px;border-bottom:1px solid #e8ddd8;">Name</td>
              <td style="padding:12px 16px;font-size:14px;color:#1c0800;font-weight:600;border-bottom:1px solid #e8ddd8;">{$displayName}</td>
            </tr>
            <tr>
              <td style="padding:12px 16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:#9e7e6e;border-bottom:1px solid #e8ddd8;">Email</td>
              <td style="padding:12px 16px;font-size:14px;border-bottom:1px solid #e8ddd8;">{$displayEmail}</td>
            </tr>
            <tr style="background:#faf7f5;">
              <td style="padding:12px 16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:#9e7e6e;border-bottom:1px solid #e8ddd8;">URL</td>
              <td style="padding:12px 16px;font-size:14px;border-bottom:1px solid #e8ddd8;">{$displayUrl}</td>
            </tr>
            <tr>
              <td style="padding:12px 16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:#9e7e6e;border-bottom:1px solid #e8ddd8;">IP</td>
              <td style="padding:12px 16px;font-size:13px;color:#6b5040;font-family:monospace;border-bottom:1px solid #e8ddd8;">{$displayIp}</td>
            </tr>
            <tr style="background:#faf7f5;">
              <td style="padding:12px 16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:#9e7e6e;">Time</td>
              <td style="padding:12px 16px;font-size:13px;color:#6b5040;">{$displayTime}</td>
            </tr>
          </table>
        </td></tr>

        <!-- Footer -->
        <tr><td style="background:#faf7f5;border:1px solid #e8ddd8;border-top:none;border-radius:0 0 12px 12px;padding:16px 32px;text-align:center;">
          <p style="margin:0;font-size:11px;color:#9e7e6e;">SiteToSpend · <a href="https://sitetospend.com" style="color:#9e7e6e;">sitetospend.com</a></p>
        </td></tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

            Mail::html($html, fn ($m) => $m
                ->to(config('app.admin_email'))
                ->cc(['mattware75@gmail.com', 'james.ward@beyondd.com.au'])
                ->subject($subject)
            );
        } catch (\Throwable $e) {
            report($e);
            Log::warning('DemoController: Failed to send Try Now notification: '.$e->getMessage());
        }

        // 1. Scrape text using basic HTTP
        $textContent = '';
        $cssColors = [];
        $html = '';
        try {
            $response = $this->fetchPublicUrl($url, 10);
            if ($response && $response->successful()) {
                $html = $response->body();
                // Extract title
                preg_match('/<title>(.*?)<\/title>/is', $html, $titleMatch);
                $title = $titleMatch[1] ?? '';

                // Extract description
                preg_match('/<meta[^>]*name="description"[^>]*content="([^"]*)"[^>]*>/is', $html, $descMatch);
                $description = $descMatch[1] ?? '';

                // Extract h1s
                preg_match_all('/<h1[^>]*>(.*?)<\/h1>/is', $html, $h1Matches);
                $h1s = implode(' ', $h1Matches[1] ?? []);

                $meta = [];

                /*
                   Everything the page says about itself, cheaply.

                   The <h1> regex was the only body signal, and a page that
                   builds itself in the browser has none — which is how this
                   ended up with a couple of hundred characters and wrote
                   nothing. But a client-rendered page still ships its pitch in
                   the head, because that is what search engines and link
                   previews read: yourfirststore.com's meta description is
                   "Build and launch your online store in minutes. Accept
                   payments with Stripe, manage products, track analytics, and
                   sell globally. No coding required." That is an ad brief.

                   I briefly solved this by rendering the page through Chromium
                   for its text. It works — 52 characters becomes 3,900 — but it
                   is a second headless browser on top of the screenshot, and it
                   put the request over PHP's 30-second limit, which turned a
                   thin result into a fatal one. Reading the head costs nothing
                   and answers the same question for almost every site.
                */
                foreach ([
                    'og:title', 'og:description', 'og:site_name',
                    'twitter:title', 'twitter:description',
                ] as $property) {
                    if (preg_match('/<meta[^>]*(?:property|name)=["\']'.preg_quote($property, '/').'["\'][^>]*content=["\']([^"\']*)["\']/i', $html, $m)
                        && ! empty(trim($m[1]))) {
                        $meta[$property] = trim($m[1]);
                    }
                }

                // Body copy where the server renders it, headings either way.
                preg_match_all('/<h([1-3])[^>]*>(.*?)<\/h\1>/is', $html, $headingMatches);
                $headings = trim(strip_tags(implode(' · ', $headingMatches[2])));

                $body = preg_replace('#<(script|style|noscript|svg|head)[^>]*>.*?</\1>#is', ' ', $html);
                $body = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) preg_replace('/<[^>]+>/', ' ', (string) $body))));

                $textContent = self::describePage($url, $title, $description, $meta, $headings, $body);

                // Extract colors directly from HTML styles
                preg_match_all('/#([a-fA-F0-9]{6})\b/', $html, $colorMatches);
                if (! empty($colorMatches[0])) {
                    $cssColors = array_merge($cssColors, $colorMatches[0]);
                }

                // Extract linked CSS files
                preg_match_all('/<link[^>]*rel=["\']stylesheet["\'][^>]*href=["\']([^"\']+)["\'][^>]*>/i', $html, $cssMatches);
                $cssUrls = $cssMatches[1] ?? [];

                // Only try the first 2 CSS files to save time
                $cssUrlsToFetch = array_slice($cssUrls, 0, 2);
                foreach ($cssUrlsToFetch as $cssPath) {
                    try {
                        // Handle relative URLs
                        if (! str_starts_with($cssPath, 'http')) {
                            $parsedUrl = parse_url($url);
                            $basePath = ($parsedUrl['scheme'] ?? 'https').'://'.($parsedUrl['host'] ?? '');
                            $cssUrlToFetch = str_starts_with($cssPath, '/') ? $basePath.$cssPath : $basePath.'/'.$cssPath;
                        } else {
                            $cssUrlToFetch = $cssPath;
                        }

                        // Second hop, second check: the stylesheet href comes
                        // from the fetched page, so an attacker's public site
                        // can point it anywhere it likes.
                        $cssResponse = $this->fetchPublicUrl($cssUrlToFetch, 5);
                        if ($cssResponse && $cssResponse->successful()) {
                            preg_match_all('/#([a-fA-F0-9]{6})\b/', $cssResponse->body(), $cssFileColorMatches);
                            if (! empty($cssFileColorMatches[0])) {
                                $cssColors = array_merge($cssColors, $cssFileColorMatches[0]);
                            }
                        }
                    } catch (\Throwable $e) {
                        report($e);
                        Log::warning('Could not fetch CSS from: '.$cssPath);
                    }
                }

                // Get unique, lowercased colors and take top 5 most frequent (or just unique)
                if (! empty($cssColors)) {
                    $cssColors = array_map('strtolower', $cssColors);
                    $colorCounts = array_count_values($cssColors);

                    // Filter out neutral/grayscale colors
                    foreach ($colorCounts as $hex => $count) {
                        if (strlen($hex) === 7) {
                            $r = hexdec(substr($hex, 1, 2));
                            $g = hexdec(substr($hex, 3, 2));
                            $b = hexdec(substr($hex, 5, 2));

                            $max = max($r, $g, $b);
                            $min = min($r, $g, $b);
                            // If difference between max and min is small, it's very gray/neutral
                            // Also filter out very light and very dark colors
                            if (($max - $min) < 30 || $max < 30 || $min > 225) {
                                unset($colorCounts[$hex]);
                            }
                        } else {
                            unset($colorCounts[$hex]);
                        }
                    }

                    arsort($colorCounts);
                    $cssColors = array_slice(array_keys($colorCounts), 0, 5);
                }
            }
        } catch (\Throwable $e) {
            report($e);
            Log::warning('DemoController text scraping failed: '.$e->getMessage());
            $textContent = 'Domain: '.parse_url($url, PHP_URL_HOST);
        }

        /*
           2. Generate Ad Copy with Gemini.

           Two ways this used to end up lying to a visitor. The catch block
           substituted "Leading Industry Solution / Start Your Free Trial /
           Transform Your Business" — boilerplate that describes no business in
           particular, returned under the heading "Your AI-Generated Ad Package".
           And a response Gemini returned but that did not parse raised no
           exception at all, so $adCopy stayed empty, success came back true, and
           the frontend's own || fallbacks invented the same copy client-side.

           Now: empty is empty, it is reported as such, and nothing is written
           on the visitor's behalf.
        */
        $adCopy = ['headlines' => [], 'descriptions' => []];
        $notes = [];

        /*
           This is the primary call to action. It does not get to fail.

           It has failed two different ways already. First it substituted
           hardcoded filler — "Transform Your Business | Sign Up Today" — and
           presented it as a reading of the visitor's site. Then I replaced that
           with an honest "we could not write ad copy from that page", which is
           worse: it is the product saying it cannot do the one thing the page
           promises, on a page whose meta description is a ready-made ad brief.

           So: two attempts, the second one told plainly that it may not come
           back empty. A page that returns HTTP 200 says enough about itself to
           advertise — it has a title, a description, a domain — and the job is
           to use them, not to grade the page.
        */
        foreach ([false, true] as $insist) {
            try {
                $prompt = self::adCopyPrompt($textContent, $insist);

                $aiResponse = $this->geminiService->generateContent(
                    config('ai.models.default'),
                    $prompt,
                    ['responseMimeType' => 'application/json']
                );

                if ($aiResponse && isset($aiResponse['text'])) {
                    $strippedJSON = preg_replace('/```json\s*|\s*```/', '', $aiResponse['text']);
                    $parsedAdCopy = json_decode($strippedJSON, true);

                    if ($parsedAdCopy && isset($parsedAdCopy['headlines'], $parsedAdCopy['descriptions'])) {
                        $adCopy = [
                            'headlines' => array_values(array_filter(array_map('trim', (array) $parsedAdCopy['headlines']))),
                            'descriptions' => array_values(array_filter(array_map('trim', (array) $parsedAdCopy['descriptions']))),
                        ];
                    }
                }
            } catch (\Throwable $e) {
                report($e);
                Log::warning('DemoController ad copy generation failed: '.$e->getMessage());
            }

            if (! empty($adCopy['headlines'])) {
                break;
            }

            Log::warning('DemoController: ad copy came back empty', [
                'url' => $url,
                'insisted' => $insist,
                'input_chars' => mb_strlen($textContent),
            ]);
        }

        if (empty($adCopy['headlines'])) {
            // Both attempts produced nothing. That is ours to fix, not the
            // visitor's to read about — it is reported and the panel simply
            // leads with Google's forecast instead.
            report(new \RuntimeException('Demo produced no ad copy for '.$url));
        }

        // 3. Extract Visuals via Browsershot + Gemini Vision
        $rawVisuals = $this->brandService->analyzeVisualStyle($url);

        // Normalize the JSON keys from Gemini Vision so the frontend receives what it expects
        $visuals = [
            'colors' => $rawVisuals['primary_colors'] ?? $rawVisuals['colors'] ?? [],
            'fonts' => self::brandFonts($rawVisuals['fonts'] ?? []),
            'style_description' => $rawVisuals['image_style'] ?? $rawVisuals['style_description'] ?? null,
        ];

        // Fallback to CSS colors if Vision failed to extract them
        if (empty($visuals['colors']) && ! empty($cssColors)) {
            $visuals['colors'] = $cssColors;
        }

        if (empty($visuals['colors']) && empty($visuals['fonts'])) {
            $notes[] = 'We could not pick out a distinctive palette or typeface.';
        }

        /*
           Is this the visitor's business at all, or the page standing where it
           should be? The same check signup runs — a parked domain, a for-sale
           listing or an unlaunched template extracts beautifully and describes
           somebody else. Worth more on the demo than anywhere: this is the
           first thing a stranger sees us do.
        */
        if ($placeholder = app(PlaceholderSiteDetector::class)->warningFor($textContent, $url)) {
            $notes[] = $placeholder;
        }

        // End on evidence rather than on a plan. Ad copy and brand colours are
        // a claim the visitor cannot check; Google's own volume, bids and
        // forecast for their market are numbers they can. Null when Keyword
        // Planner is unavailable — the rest of the demo still returns.
        $forecast = app(CampaignForecastPreview::class)->forUrl($url);

        return response()->json([
            'success' => true,
            'url' => $url,
            'ad_copy' => $adCopy,
            'visuals' => $visuals,
            'forecast' => $forecast,
            // What we could not do, said plainly. The page used to fill these
            // gaps with invented copy rather than admit to them.
            'notes' => $notes,
        ]);
    }

    /**
     * The brief.
     *
     * The original — "keep it punchy and highlight the value proposition" over
     * 1,000 characters — is a brief for "Transform Your Business", which is
     * what it returned. My replacement then added "if the page does not say
     * enough, return empty arrays", which handed the model an exit it took.
     *
     * A page that answered HTTP 200 has a title, a description and a domain.
     * That is enough. The second pass says so in as many words.
     */
    private static function adCopyPrompt(string $pageDescription, bool $insist): string
    {
        $brief = <<<'PROMPT'
            Write Google Search ads for the business described below.

            Return strict JSON: 'headlines' (3 strings, max 30 characters each)
            and 'descriptions' (2 strings, max 90 characters each).

            Use the specifics on the page — what it sells, who for, the price,
            the mechanism, the offer. A reader should be able to tell which
            business the ad is for without being told.

            Do not write generic SaaS filler. Phrases like "Transform Your
            Business", "Leading Industry Solution", "Take It To The Next Level",
            "Trusted By Thousands", "The All-In-One Solution" or "Sign Up Today"
            say nothing and must not appear.
            PROMPT;

        if ($insist) {
            $brief .= "\n\n".<<<'PROMPT'
                You must return three headlines and two descriptions. Empty
                arrays are not an acceptable answer. If the page is sparse, work
                from its title, its meta description and its domain name — those
                are always enough to say what the business does. Write the
                strongest honest ad the available detail supports, and do not
                state anything the page does not.
                PROMPT;
        }

        return $brief."\n\nWebsite data:\n".mb_substr($pageDescription, 0, 6000);
    }

    /**
     * Everything we know about the page, in the order a person would say it.
     *
     * @param  array<string, string>  $meta
     */
    private static function describePage(
        string $url,
        string $title,
        string $description,
        array $meta,
        string $headings,
        string $body,
    ): string {
        $parts = ['Website: '.$url];

        if ($title !== '') {
            $parts[] = 'Page title: '.trim(strip_tags($title));
        }

        if ($description !== '') {
            $parts[] = 'Meta description: '.trim($description);
        }

        foreach ($meta as $key => $value) {
            $parts[] = $key.': '.$value;
        }

        if ($headings !== '') {
            $parts[] = 'Headings: '.mb_substr($headings, 0, 1500);
        }

        // Last, and capped: on a server-rendered page this is the richest
        // signal, and on a client-rendered one it is navigation chrome.
        if (mb_strlen($body) > 40) {
            $parts[] = 'Page text: '.mb_substr($body, 0, 5000);
        }

        return implode("\n", $parts);
    }

    /**
     * Typefaces that say something about a brand.
     *
     * Vision reports whatever the page renders in, and a site with no font
     * stack of its own renders in the browser's — so the demo listed "Arial,
     * Helvetica" under Typography as though it had discovered something. Those
     * are the absence of a choice, not a choice.
     *
     * @param  array<int, mixed>  $fonts
     * @return list<string>
     */
    private static function brandFonts(array $fonts): array
    {
        $generic = [
            'arial', 'helvetica', 'helvetica neue', 'sans-serif', 'serif',
            'times', 'times new roman', 'system-ui', '-apple-system',
            'segoe ui', 'roboto', 'monospace', 'inherit', 'initial',
            'blinkmacsystemfont', 'ui-sans-serif', 'ui-serif',
        ];

        return array_values(array_filter(
            array_map(fn ($font) => trim((string) $font, " \t\n\r\0\x0B\"'"), $fonts),
            fn (string $font) => $font !== '' && ! in_array(mb_strtolower($font), $generic, true),
        ));
    }

    /**
     * Fetch a URL a stranger typed, without it becoming a request into our own
     * network.
     *
     * The validator already checked the host, but that was a DNS lookup ago,
     * and Guzzle follows redirects by default — a perfectly public page that
     * 302s to http://169.254.169.254/ is the standard way around a validated
     * hostname. Every hop is re-checked and a bad one aborts the chain, which
     * the caller's catch turns into "we couldn't read that site".
     */
    private function fetchPublicUrl(string $url, int $timeout): ?\Illuminate\Http\Client\Response
    {
        if (! SafePublicUrl::isSafe($url)) {
            Log::warning('DemoController: refused to fetch a non-public URL', ['url' => $url]);

            return null;
        }

        return Http::timeout($timeout)
            ->withOptions(['allow_redirects' => [
                'max' => 5,
                'protocols' => ['http', 'https'],
                'on_redirect' => function ($request, $response, $uri) {
                    if (! SafePublicUrl::isSafe((string) $uri)) {
                        throw new \RuntimeException("Refusing to follow a redirect to a non-public host: {$uri}");
                    }
                },
            ]])
            ->get($url);
    }

    /**
     * Record the person behind a Try Now, without ever failing the request.
     *
     * They are waiting on a demo. A duplicate address, a database hiccup or a
     * malformed email must not cost them the thing they actually came for.
     */
    private function rememberLead(string $email, string $firstName, string $url): void
    {
        $email = trim(strtolower($email));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        try {
            LandingLead::updateOrCreate(
                ['email' => $email],
                [
                    // Keep the earliest name and the latest URL: people try
                    // more than one site, but the name they gave first is the
                    // one they introduced themselves with.
                    'first_name' => LandingLead::where('email', $email)->value('first_name') ?: ($firstName ?: null),
                    'url' => $url,
                ],
            );
        } catch (\Throwable $e) {
            report($e);
            Log::error('Failed to record landing lead', ['email' => $email, 'error' => $e->getMessage()]);
        }
    }
}
