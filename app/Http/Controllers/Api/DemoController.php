<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BrandGuidelineExtractorService;
use App\Services\GeminiService;
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
            'url'        => 'required|url|max:255',
            'first_name' => 'nullable|string|max:100',
            'email'      => 'nullable|email|max:255',
        ]);

        $url       = $request->input('url');
        $firstName = $request->input('first_name', '');
        $userEmail = $request->input('email', '');

        Log::info("DemoController: Starting full extraction demo for {$url}");

        // Notify the team every time someone hits Try Now — one email to all recipients
        try {
            $ip        = $request->ip();
            $userAgent = $request->userAgent() ?? 'unknown';
            $time      = now()->format('d M Y, H:i') . ' UTC';
            $subject   = $firstName ? "Try Now: {$firstName} — {$url}" : "Try Now: {$url}";

            $displayName = e($firstName ?: '—');
            $displayEmail = $userEmail
                ? '<a href="mailto:' . e($userEmail) . '" style="color:#ff4d00;text-decoration:none;">' . e($userEmail) . '</a>'
                : '—';
            $displayUrl = '<a href="' . e($url) . '" style="color:#ff4d00;text-decoration:none;">' . e($url) . '</a>';
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
                ->to('theeufj@gmail.com')
                ->cc(['mattware75@gmail.com', 'james.ward@beyondd.com.au'])
                ->subject($subject)
            );
        } catch (\Exception $e) {
            Log::warning("DemoController: Failed to send Try Now notification: " . $e->getMessage());
        }

        // 1. Scrape text using basic HTTP
        $textContent = '';
        $cssColors = [];
        $html = '';
        try {
            $response = Http::timeout(10)->get($url);
            if ($response->successful()) {
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

                $textContent = "Title: {$title}\nDescription: {$description}\nHeadings: {$h1s}";

                // Extract colors directly from HTML styles
                preg_match_all('/#([a-fA-F0-9]{6})\b/', $html, $colorMatches);
                if (!empty($colorMatches[0])) {
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
                        if (!str_starts_with($cssPath, 'http')) {
                            $parsedUrl = parse_url($url);
                            $basePath = ($parsedUrl['scheme'] ?? 'https') . '://' . ($parsedUrl['host'] ?? '');
                            $cssUrlToFetch = str_starts_with($cssPath, '/') ? $basePath . $cssPath : $basePath . '/' . $cssPath;
                        } else {
                            $cssUrlToFetch = $cssPath;
                        }

                        $cssResponse = Http::timeout(5)->get($cssUrlToFetch);
                        if ($cssResponse->successful()) {
                            preg_match_all('/#([a-fA-F0-9]{6})\b/', $cssResponse->body(), $cssFileColorMatches);
                            if (!empty($cssFileColorMatches[0])) {
                                $cssColors = array_merge($cssColors, $cssFileColorMatches[0]);
                            }
                        }
                    } catch (\Exception $e) {
                        Log::warning("Could not fetch CSS from: " . $cssPath);
                    }
                }

                // Get unique, lowercased colors and take top 5 most frequent (or just unique)
                if (!empty($cssColors)) {
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
        } catch (\Exception $e) {
            Log::warning("DemoController text scraping failed: " . $e->getMessage());
            $textContent = "Domain: " . parse_url($url, PHP_URL_HOST);
        }

        // 2. Generate Ad Copy with Gemini
        $adCopy = ['headlines' => [], 'descriptions' => []];
        try {
            $prompt = "Based on this website data, write 3 Google Search Ad headlines (max 30 chars each) and 2 descriptions (max 90 chars each). Keep it punchy and highlight the value proposition. Return strict JSON with the keys 'headlines' (array of strings) and 'descriptions' (array of strings).\n\nWebsite Data:\n" . substr($textContent, 0, 1000);

            $aiResponse = $this->geminiService->generateContent(
                config('ai.models.default', 'gemini-1.5-flash-latest'), // Adjust to your text model
                $prompt,
                ['responseMimeType' => 'application/json']
            );

            if ($aiResponse && isset($aiResponse['text'])) {
                $strippedJSON = preg_replace('/```json\s*|\s*```/', '', $aiResponse['text']);
                $parsedAdCopy = json_decode($strippedJSON, true);
                if ($parsedAdCopy && isset($parsedAdCopy['headlines'], $parsedAdCopy['descriptions'])) {
                    $adCopy = $parsedAdCopy;
                }
            }
        } catch (\Exception $e) {
            Log::warning("DemoController ad copy generation failed: " . $e->getMessage());
            $adCopy = [
                'headlines' => ['Leading Industry Solution', 'Start Your Free Trial', 'Transform Your Business'],
                'descriptions' => ['Discover why thousands trust our platform. Get started today and see results fast.', 'The all-in-one solution you have been looking for. Flexible pricing to suit any scale.']
            ];
        }

        // 3. Extract Visuals via Browsershot + Gemini Vision
        $rawVisuals = $this->brandService->analyzeVisualStyle($url);

        // Normalize the JSON keys from Gemini Vision so the frontend receives what it expects
        $visuals = [
            'colors' => $rawVisuals['primary_colors'] ?? $rawVisuals['colors'] ?? [],
            'fonts' => $rawVisuals['fonts'] ?? [],
            'style_description' => $rawVisuals['image_style'] ?? $rawVisuals['style_description'] ?? 'Professional & Modern',
        ];

        // Fallback to CSS colors if Vision failed to extract them
        if (empty($visuals['colors']) && !empty($cssColors)) {
            $visuals['colors'] = $cssColors;
        }

        return response()->json([
            'success' => true,
            'url' => $url,
            'ad_copy' => $adCopy,
            'visuals' => $visuals,
        ]);
    }
}
