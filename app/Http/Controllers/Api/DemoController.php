<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BrandGuidelineExtractorService;
use App\Services\GeminiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
            'url' => 'required|url|max:255',
        ]);

        $url = $request->input('url');

        Log::info("DemoController: Starting full extraction demo for {$url}");

        // 1. Scrape text using basic HTTP
        $textContent = '';
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
        $visuals = $this->brandService->analyzeVisualStyle($url);

        return response()->json([
            'success' => true,
            'url' => $url,
            'ad_copy' => $adCopy,
            'visuals' => $visuals,
        ]);
    }
}
