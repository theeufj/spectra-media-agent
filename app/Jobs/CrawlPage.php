<?php

namespace App\Jobs;

use App\Models\Competitor;
use App\Models\KnowledgeBase;
use App\Models\User;
use App\Prompts\ChunkingPrompt;
use App\Services\GeminiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Pgvector\Laravel\Vector;
use Spatie\Browsershot\Browsershot;
use Symfony\Component\DomCrawler\Crawler;

class CrawlPage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var \App\Models\User
     */
    public $user;

    /**
     * @var string
     */
    public $customerId;

    /**
     * @var string
     */
    public $url;

    /**
     * Create a new job instance.
     *
     * @param User $user
     * @param string $url
     * @param int|null $customerId
     */
    public function __construct(User $user, string $url, ?int $customerId = null)
    {
        $this->user = $user;
        $this->url = $url;
        $this->customerId = $customerId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // Step 1: Use a headless browser to get the fully rendered HTML.
            // This executes the JavaScript on the page, just like a real browser.
            $html = Browsershot::url($this->url)
                ->setNodeBinary(config('browsershot.node_binary_path')) // Use the new config value
                ->bodyHtml();

            // Step 2: Extract meaningful text from the HTML.
            // We use the Symfony DomCrawler component for this.
            // It's a robust way to parse and traverse HTML/XML documents.
            $crawler = new Crawler($html, $this->url); // Pass the URL as the second argument to resolve relative links.

            // Remove script and style tags first
            $crawler->filter('script, style')->each(function (Crawler $node) {
                foreach ($node as $n) {
                    $n->parentNode->removeChild($n);
                }
            });

            // Remove common header, navigation, and footer elements
            $crawler->filter('header, nav, footer, .header, .nav, .footer, #header, #nav, #footer')->each(function (Crawler $node) {
                foreach ($node as $n) {
                    $n->parentNode->removeChild($n);
                }
            });

            $title = $crawler->filter('title')->first()->text('');
            $metaDescription = $crawler->filter('meta[name="description"]')->first()->attr('content', '');
            $headings = [];
            $crawler->filter('h1, h2, h3')->each(function (Crawler $node) use (&$headings) {
                $headings[$node->nodeName()][] = $node->text();
            });

            if ($this->customerId) {
                Competitor::updateOrCreate(
                    [
                        'customer_id' => $this->customerId,
                        'url' => $this->url,
                    ],
                    [
                        'title' => $title,
                        'meta_description' => $metaDescription,
                        'headings' => json_encode($headings),
                        'raw_content' => $cleanedContent,
                    ]
                );
                Log::info("Successfully crawled and stored competitor page: {$this->url}");
                return; // Skip knowledge base processing for competitors
            }

            // Extract text content from the body after removing irrelevant elements
            $textContent = $crawler->filter('body')->text();
            $cleanedContent = preg_replace('/\s+/', ' ', $textContent);

            // Step 2.5: Extract, fetch, and combine all CSS content.
            $cssContent = '';
            $crawler->filter('link[rel="stylesheet"]')->each(function (Crawler $node) use (&$cssContent) {
                $stylesheetUrl = $node->link()->getUri();
                try {
                    $cssResponse = Http::timeout(30)->get($stylesheetUrl);
                    if ($cssResponse->successful()) {
                        $cssContent .= $cssResponse->body() . "\n\n";
                    }
                } catch (\Exception $e) {
                    Log::warning("Failed to fetch CSS from {$stylesheetUrl}: " . $e->getMessage());
                }
            });

            if (empty($cleanedContent)) {
                Log::info("No content found on page: {$this->url}");
                return;
            }

            // Initialize Gemini Service
            $geminiService = new GeminiService();

            // Step 3: Use Gemini's Generative Content API to break content into semantically meaningful chunks.
            $chunkingPrompt = (new ChunkingPrompt($cleanedContent))->getPrompt();
            $generatedResponse = $geminiService->generateContent('gemini-2.5-pro', $chunkingPrompt);

            if (is_null($generatedResponse)) {
                Log::error("Failed to get chunks from Gemini for {$this->url}: Generated text was null.");
                return;
            }

            // Extract text from the response array
            $generatedText = $generatedResponse['text'] ?? null;
            if (is_null($generatedText)) {
                Log::error("Failed to get chunks from Gemini for {$this->url}: No text field in response.");
                return;
            }
            
            // Attempt to parse the generated text as JSON
            $chunks = [];
            try {
                // Clean the JSON string by removing markdown fences and trimming whitespace
                $cleanedJson = preg_replace('/^```json\s*|\s*```$/', '', trim($generatedText));
                $chunks = json_decode($cleanedJson, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception("JSON decode error: " . json_last_error_msg());
                }

                if (!is_array($chunks)) {
                    throw new \Exception("Gemini did not return a valid JSON array of chunks.");
                }
            } catch (\Exception $e) {
                Log::error("Failed to parse Gemini's chunking response for {$this->url}: " . $e->getMessage(), [
                    'generated_text' => $generatedText,
                ]);
                return;
            }

            if (empty($chunks)) {
                Log::info("Gemini returned no chunks for {$this->url}");
                return;
            }

            $allEmbeddings = [];
            $allChunkContents = [];

            // Step 4: Generate embeddings for each individual chunk.
            foreach ($chunks as $chunk) {
                if (empty(trim($chunk))) {
                    continue;
                }

                $embedding = $geminiService->embedContent('text-embedding-004', $chunk);

                if (is_null($embedding)) {
                    Log::warning("Failed to get embedding for a chunk from {$this->url}. Skipping chunk.", [
                        'chunk' => substr($chunk, 0, 100) . '...',
                    ]);
                    continue;
                }

                $allEmbeddings[] = $embedding;
                $allChunkContents[] = $chunk;
            }

            if (empty($allEmbeddings)) {
                Log::warning("No embeddings generated for any chunks from {$this->url}");
                return;
            }

            // Step 5: Save the URL, all chunk contents, and all embeddings to the database.
            // Store chunks and embeddings as JSON arrays.
            KnowledgeBase::updateOrCreate(
                [
                    'user_id' => $this->user->id,
                    'url' => $this->url,
                ],
                [
                    'content' => $cleanedContent,
                    'css_content' => $cssContent, // Save the combined CSS content.
                    'embedding' => new Vector($embedding), // The pgvector package provides this handy Vector class.
                ]
            );

            Log::info("Successfully crawled and embedded: {$this->url}");

        } catch (\Exception $e) {
            Log::error("Error processing page {$this->url}: " . $e->getMessage());
        }
    }
}
