<?php

namespace App\Jobs;

use App\Models\Proposal;
use App\Prompts\ProposalPrompt;
use App\Services\GeminiService;
use App\Services\ProposalPdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Spatie\Browsershot\Browsershot;
use Symfony\Component\DomCrawler\Crawler;

class GenerateProposal implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 300;

    public function __construct(protected Proposal $proposal)
    {}

    public function handle(GeminiService $geminiService, ProposalPdfService $pdfService): void
    {
        try {
            Log::info("GenerateProposal: Starting for proposal #{$this->proposal->id}");

            // Step 1: Crawl client website (if provided)
            $websiteContent = null;
            if ($this->proposal->website_url) {
                $websiteContent = $this->crawlWebsite($this->proposal->website_url);
            }

            // Step 2: Generate proposal via Gemini
            $prompt = ProposalPrompt::build(
                clientName: $this->proposal->client_name,
                industry: $this->proposal->industry ?? 'General',
                websiteContent: $websiteContent,
                budget: (float) $this->proposal->budget,
                goals: $this->proposal->goals,
                platforms: $this->proposal->platforms ?? ['Google Ads'],
            );

            $response = $geminiService->generateContent(
                model: 'gemini-3-flash-preview',
                prompt: $prompt,
                config: ['temperature' => 0.7, 'maxOutputTokens' => 8192],
                systemInstruction: ProposalPrompt::getSystemInstruction(),
            );

            if (!$response || empty($response['text'])) {
                $this->proposal->markFailed('AI generation returned empty response.');
                return;
            }

            // Parse JSON from response
            $proposalData = $this->parseJson($response['text']);
            if (!$proposalData) {
                $this->proposal->markFailed('Failed to parse proposal JSON from AI response.');
                return;
            }

            // Step 3: Generate hero image for the proposal
            $heroImage = $this->generateHeroImage($geminiService);

            if ($heroImage) {
                $proposalData['hero_image'] = $heroImage;
            }

            // Step 4: Generate PDF
            $pdfPath = $pdfService->generate($this->proposal, $proposalData);

            // Step 5: Mark ready
            $this->proposal->markReady($proposalData, $pdfPath);

            Log::info("GenerateProposal: Completed for proposal #{$this->proposal->id}");

        } catch (\Throwable $e) {
            Log::error("GenerateProposal: Failed for proposal #{$this->proposal->id}: {$e->getMessage()}");
            $this->proposal->markFailed($e->getMessage());
        }
    }

    /**
     * Crawl the client's website and extract meaningful content.
     */
    protected function crawlWebsite(string $url): ?string
    {
        try {
            $html = Browsershot::url($url)
                ->setNodeBinary(config('browsershot.node_binary_path'))
                ->addChromiumArguments(config('browsershot.chrome_args', []))
                ->timeout(60)
                ->waitUntilNetworkIdle()
                ->bodyHtml();

            $crawler = new Crawler($html, $url);

            // Remove non-content elements
            $crawler->filter('script, style')->each(function (Crawler $node) {
                foreach ($node as $n) {
                    $n->parentNode->removeChild($n);
                }
            });

            $crawler->filter('header, nav, footer, .header, .nav, .footer, #header, #nav, #footer')->each(function (Crawler $node) {
                foreach ($node as $n) {
                    $n->parentNode->removeChild($n);
                }
            });

            $title = $crawler->filter('title')->first()->text('');
            $metaDescription = $crawler->filter('meta[name="description"]')->first()->attr('content', '');
            $headings = [];
            $crawler->filter('h1, h2, h3')->each(function (Crawler $node) use (&$headings) {
                $headings[] = $node->text();
            });

            $textContent = $crawler->filter('body')->text();
            $cleanedContent = preg_replace('/\s+/', ' ', $textContent);

            // Truncate to fit within prompt context
            $cleanedContent = substr($cleanedContent, 0, 6000);

            $parts = ["Title: {$title}"];
            if ($metaDescription) {
                $parts[] = "Description: {$metaDescription}";
            }
            if (!empty($headings)) {
                $parts[] = "Key Headings: " . implode(', ', array_slice($headings, 0, 15));
            }
            $parts[] = "Content: {$cleanedContent}";

            return implode("\n", $parts);

        } catch (\Throwable $e) {
            Log::warning("GenerateProposal: Failed to crawl {$url}: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Generate a hero image for the proposal cover.
     */
    protected function generateHeroImage(GeminiService $geminiService): ?string
    {
        try {
            $imagePrompt = "Create a clean, modern, professional hero image for an advertising proposal document. "
                . "The image should convey digital marketing success with abstract elements like rising graphs, "
                . "connected nodes, and a gradient blue-to-indigo color palette. "
                . "Industry: {$this->proposal->industry}. Minimalist corporate style. No text.";

            $result = $geminiService->generateImage($imagePrompt, 'gemini-3.1-flash-image-preview', '1K');

            if ($result && !empty($result['data'])) {
                return 'data:' . ($result['mimeType'] ?? 'image/png') . ';base64,' . $result['data'];
            }
        } catch (\Throwable $e) {
            Log::warning("GenerateProposal: Hero image generation failed: {$e->getMessage()}");
        }

        return null;
    }

    /**
     * Parse JSON from Gemini's response text.
     */
    protected function parseJson(string $text): ?array
    {
        // Try direct parse
        $decoded = json_decode($text, true);
        if ($decoded) {
            return $decoded;
        }

        // Try extracting JSON from markdown code block
        if (preg_match('/```(?:json)?\s*\n?(.*?)\n?```/s', $text, $matches)) {
            $decoded = json_decode($matches[1], true);
            if ($decoded) {
                return $decoded;
            }
        }

        // Try finding JSON object in text
        if (preg_match('/\{.*\}/s', $text, $matches)) {
            $decoded = json_decode($matches[0], true);
            if ($decoded) {
                return $decoded;
            }
        }

        return null;
    }
}
