<?php

namespace App\Services;

use App\Models\BrandGuideline;
use App\Models\Customer;
use App\Prompts\BrandGuidelineExtractionPrompt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\Browsershot\Browsershot;

class BrandGuidelineExtractorService
{
    private GeminiService $geminiService;

    public function __construct(GeminiService $geminiService)
    {
        $this->geminiService = $geminiService;
    }

    /**
     * Extract brand guidelines from customer's website and knowledge base
     */
    public function extractGuidelines(Customer $customer): ?BrandGuideline
    {
        try {
            Log::info("Starting brand guideline extraction for customer {$customer->id}");

            // Step 1: Gather all knowledge base content
            $websiteContent = $customer->user->knowledgeBase()
                ->pluck('content')
                ->implode("\n\n---PAGE BREAK---\n\n");

            if (empty($websiteContent)) {
                Log::warning("No knowledge base content found for customer {$customer->id}");
                return null;
            }

            // Step 2: Scrape and analyze homepage for visual elements
            $visualAnalysis = $this->analyzeVisualStyle($customer->website_url);

            // Step 3: Build extraction prompt
            $prompt = (new BrandGuidelineExtractionPrompt(
                $websiteContent,
                $visualAnalysis,
                $customer->industry ?? 'general'
            ))->getPrompt();

            Log::info("Calling Gemini for brand guideline extraction", [
                'customer_id' => $customer->id,
                'content_length' => strlen($websiteContent),
            ]);

            // Step 4: Call Gemini with extended thinking for deep analysis
            $response = $this->geminiService->generateContent('gemini-2.5-pro', $prompt);

            if (!$response || !isset($response['text'])) {
                Log::error("Failed to generate brand guidelines from Gemini", [
                    'customer_id' => $customer->id,
                ]);
                return null;
            }

            // Step 5: Parse and validate response
            $cleanedJson = $this->cleanJsonResponse($response['text']);
            $guidelines = json_decode($cleanedJson, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error("Failed to parse brand guidelines JSON", [
                    'customer_id' => $customer->id,
                    'error' => json_last_error_msg(),
                    'response_preview' => substr($response['text'], 0, 500),
                ]);
                return null;
            }

            // Step 6: Validate required fields
            if (!$this->validateGuidelines($guidelines)) {
                Log::error("Brand guidelines missing required fields", [
                    'customer_id' => $customer->id,
                    'guidelines' => $guidelines,
                ]);
                return null;
            }

            // Step 7: Store brand guidelines
            $brandGuideline = BrandGuideline::updateOrCreate(
                ['customer_id' => $customer->id],
                [
                    'brand_voice' => $guidelines['brand_voice'],
                    'tone_attributes' => $guidelines['tone_attributes'],
                    'writing_patterns' => $guidelines['writing_patterns'] ?? null,
                    'color_palette' => $guidelines['color_palette'],
                    'typography' => $guidelines['typography'],
                    'visual_style' => $guidelines['visual_style'],
                    'messaging_themes' => $guidelines['messaging_themes'],
                    'unique_selling_propositions' => $guidelines['unique_selling_propositions'],
                    'target_audience' => $guidelines['target_audience'],
                    'competitor_differentiation' => $guidelines['competitor_differentiation'] ?? null,
                    'brand_personality' => $guidelines['brand_personality'],
                    'do_not_use' => $guidelines['do_not_use'] ?? [],
                    'extraction_quality_score' => $guidelines['extraction_quality_score'] ?? 50,
                    'extracted_at' => now(),
                ]
            );

            Log::info("Successfully extracted brand guidelines", [
                'customer_id' => $customer->id,
                'brand_guideline_id' => $brandGuideline->id,
                'quality_score' => $brandGuideline->extraction_quality_score,
            ]);

            return $brandGuideline;

        } catch (\Exception $e) {
            Log::error("Error extracting brand guidelines", [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Analyze visual style from homepage HTML
     */
    private function analyzeVisualStyle(string $websiteUrl): array
    {
        try {
            Log::info("Analyzing visual style for: {$websiteUrl}");

            // Try Browsershot first for JavaScript-rendered sites
            try {
                $html = Browsershot::url($websiteUrl)
                    ->waitUntilNetworkIdle()
                    ->timeout(30)
                    ->bodyHtml();
            } catch (\Exception $e) {
                Log::warning("Browsershot failed, falling back to HTTP", [
                    'error' => $e->getMessage(),
                ]);
                // Fallback to simple HTTP request
                $response = Http::timeout(15)->get($websiteUrl);
                $html = $response->successful() ? $response->body() : '';
            }

            if (empty($html)) {
                Log::warning("Failed to fetch HTML for visual analysis");
                return $this->getDefaultVisualAnalysis();
            }

            return [
                'primary_colors' => $this->extractColors($html),
                'fonts' => $this->extractFonts($html),
                'image_style' => $this->detectImageStyle($html),
                'layout_style' => $this->detectLayoutStyle($html),
            ];

        } catch (\Exception $e) {
            Log::error("Error analyzing visual style: " . $e->getMessage());
            return $this->getDefaultVisualAnalysis();
        }
    }

    /**
     * Extract color palette from HTML/CSS
     */
    private function extractColors(string $html): array
    {
        $colors = [];
        
        // Extract hex colors from inline styles and style tags
        preg_match_all('/#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})/', $html, $matches);
        
        if (!empty($matches[0])) {
            // Normalize 3-digit hex to 6-digit
            $hexColors = array_map(function($color) {
                $color = strtoupper(ltrim($color, '#'));
                if (strlen($color) === 3) {
                    $color = $color[0].$color[0].$color[1].$color[1].$color[2].$color[2];
                }
                return '#' . $color;
            }, $matches[0]);
            
            // Count occurrences and get most common colors
            $colorCounts = array_count_values($hexColors);
            arsort($colorCounts);
            
            // Filter out near-white and near-black (usually backgrounds)
            $filteredColors = array_filter(array_keys($colorCounts), function($color) {
                // Skip #FFFFFF, #000000, and very close variants
                return !in_array($color, ['#FFFFFF', '#000000', '#FAFAFA', '#F5F5F5', '#111111']);
            });
            
            $colors = array_slice($filteredColors, 0, 6); // Top 6 colors
        }
        
        return !empty($colors) ? $colors : ['#0066CC', '#333333']; // Default fallback
    }

    /**
     * Extract font families from HTML/CSS
     */
    private function extractFonts(string $html): array
    {
        $fonts = [];
        
        // Look for font-family declarations
        preg_match_all('/font-family:\s*([^;}"]+)/i', $html, $matches);
        
        if (!empty($matches[1])) {
            foreach ($matches[1] as $fontDeclaration) {
                // Split by comma and clean up
                $fontList = explode(',', $fontDeclaration);
                foreach ($fontList as $font) {
                    $font = trim($font, " '\"");
                    // Skip generic font families
                    if (!in_array(strtolower($font), ['serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui'])) {
                        $fonts[] = $font;
                    }
                }
            }
            
            // Get unique fonts
            $fonts = array_unique($fonts);
            $fonts = array_slice($fonts, 0, 5); // Top 5 fonts
        }
        
        return !empty($fonts) ? $fonts : ['Arial', 'Helvetica']; // Default fallback
    }

    /**
     * Detect image style (photography, illustrations, etc.)
     */
    private function detectImageStyle(string $html): string
    {
        // Look for image file extensions and SVG usage
        $jpgCount = substr_count(strtolower($html), '.jpg') + substr_count(strtolower($html), '.jpeg');
        $pngCount = substr_count(strtolower($html), '.png');
        $svgCount = substr_count(strtolower($html), '.svg') + substr_count(strtolower($html), '<svg');
        $gifCount = substr_count(strtolower($html), '.gif');
        
        // Determine dominant image type
        if ($svgCount > ($jpgCount + $pngCount)) {
            return 'illustrations and icons';
        } elseif ($jpgCount > $pngCount * 2) {
            return 'photography-heavy';
        } elseif ($gifCount > 5) {
            return 'animated and dynamic';
        } else {
            return 'mixed photography and graphics';
        }
    }

    /**
     * Detect layout style
     */
    private function detectLayoutStyle(string $html): string
    {
        // Simple heuristic based on common patterns
        if (stripos($html, 'grid') !== false || stripos($html, 'display: grid') !== false) {
            return 'grid-based layout';
        } elseif (stripos($html, 'flex') !== false || stripos($html, 'display: flex') !== false) {
            return 'flexible, modern layout';
        } else {
            return 'traditional layout';
        }
    }

    /**
     * Get default visual analysis when scraping fails
     */
    private function getDefaultVisualAnalysis(): array
    {
        return [
            'primary_colors' => ['#0066CC', '#333333'],
            'fonts' => ['Arial', 'Helvetica'],
            'image_style' => 'mixed content',
            'layout_style' => 'modern',
        ];
    }

    /**
     * Clean JSON response from AI (remove markdown fences, etc.)
     */
    private function cleanJsonResponse(string $text): string
    {
        // Remove markdown code fences
        $cleaned = preg_replace('/^```json\s*|\s*```$/m', '', $text);
        
        // Trim whitespace
        $cleaned = trim($cleaned);
        
        return $cleaned;
    }

    /**
     * Validate that guidelines have all required fields
     */
    private function validateGuidelines(array $guidelines): bool
    {
        $requiredFields = [
            'brand_voice',
            'tone_attributes',
            'color_palette',
            'typography',
            'visual_style',
            'messaging_themes',
            'unique_selling_propositions',
            'target_audience',
            'brand_personality',
        ];

        foreach ($requiredFields as $field) {
            if (!isset($guidelines[$field]) || empty($guidelines[$field])) {
                Log::warning("Missing required field in brand guidelines: {$field}");
                return false;
            }
        }

        return true;
    }
}
