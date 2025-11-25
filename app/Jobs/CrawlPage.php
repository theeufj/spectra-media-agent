<?php

namespace App\Jobs;

use App\Models\CustomerPage;
use App\Models\KnowledgeBase;
use App\Models\User;
use App\Models\Customer;
use App\Prompts\ChunkingPrompt;
use App\Services\GeminiService;
use App\Services\LandingPageCROAuditService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Pgvector\Laravel\Vector;
use Spatie\Browsershot\Browsershot;
use Spatie\Robots\RobotsTxt;
use Symfony\Component\DomCrawler\Crawler;
use App\Models\LandingPageAudit;

class CrawlPage implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
     * @var array
     */
    public $metadata;

    /**
     * Create a new job instance.
     *
     * @param User $user
     * @param string $url
     * @param int|null $customerId
     * @param array $metadata
     */
    public function __construct(User $user, string $url, ?int $customerId = null, array $metadata = [])
    {
        $this->user = $user;
        $this->url = $url;
        $this->customerId = $customerId;
        $this->metadata = $metadata;
    }

    /**
     * Check if customer can run CRO audit (subscription-based limit)
     */
    protected function canRunCROAudit(Customer $customer): bool
    {
        // Get first user associated with customer to check subscription
        $user = $customer->users()->first();
        
        if (!$user) {
            return false;
        }
        
        // Pro users have unlimited audits
        if ($user->subscribed('default') || $user->subscription_status === 'active') {
            return true;
        }
        
        // Free users limited to 3 CRO audits
        return $customer->cro_audits_used < 3;
    }
    
    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Check if the batch has been cancelled
        if ($this->batch()?->cancelled()) {
            Log::info("CrawlPage: Batch cancelled, skipping URL: {$this->url}");
            return;
        }

        // Check robots.txt
        $parsedUrl = parse_url($this->url);
        if (isset($parsedUrl['scheme']) && isset($parsedUrl['host'])) {
            $baseUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
            $robotsUrl = $baseUrl . '/robots.txt';

            $robotsTxtContent = Cache::remember("robots_txt_{$parsedUrl['host']}", 3600, function () use ($robotsUrl) {
                try {
                    $response = Http::get($robotsUrl);
                    return $response->successful() ? $response->body() : '';
                } catch (\Exception $e) {
                    Log::warning("CrawlPage: Could not fetch robots.txt for {$this->url}: " . $e->getMessage());
                    return '';
                }
            });

            if (!empty($robotsTxtContent)) {
                $robots = new RobotsTxt($robotsTxtContent);
                // Use a default user agent if not configured
                $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36';
                
                if (!$robots->allows($this->url, $userAgent)) {
                    Log::warning("CrawlPage: URL disallowed by robots.txt: {$this->url}");
                    return;
                }
            }
        }
        
        try {
            // Ethical scraping delay
            sleep(rand(5, 10));

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

            // Extract text content from the body after removing irrelevant elements
            $textContent = $crawler->filter('body')->text();
            $cleanedContent = preg_replace('/\s+/', ' ', $textContent);

            // Detect page type (Product/Money page)
            $pageType = $this->detectPageType($crawler);
            Log::info("CrawlPage: Detected page type: {$pageType} for URL: {$this->url}");

            if ($this->customerId) {
                $metadata = $this->metadata ?? [];
                $metadata['headings'] = $headings;

                // Initialize Gemini Service for embedding
                $geminiService = new GeminiService();
                $embeddingText = substr($title . "\n" . $metaDescription . "\n" . $cleanedContent, 0, 8000); // Limit context
                $embedding = $geminiService->embedContent('text-embedding-004', $embeddingText);

                CustomerPage::updateOrCreate(
                    [
                        'customer_id' => $this->customerId,
                        'url' => $this->url,
                    ],
                    [
                        'title' => $title,
                        'meta_description' => $metaDescription,
                        'page_type' => $pageType,
                        'metadata' => $metadata,
                        'content' => $cleanedContent,
                        'embedding' => $embedding ? new Vector($embedding) : null,
                    ]
                );
                
                Log::info("Successfully crawled and stored customer page: {$this->url}");
                
                // Run CRO Audit for customer pages (product/money pages)
                if (in_array($pageType, ['product', 'money', 'landing'])) {
                    try {
                        $customer = Customer::find($this->customerId);
                        
                        // Check subscription limits for CRO audits
                        if (!$this->canRunCROAudit($customer)) {
                            Log::info("CRO audit skipped - limit reached for free users", [
                                'customer_id' => $this->customerId,
                                'url' => $this->url,
                            ]);
                            return;
                        }
                        
                        Log::info("Running CRO audit for page", [
                            'customer_id' => $this->customerId,
                            'url' => $this->url,
                            'page_type' => $pageType,
                        ]);
                        
                        $croAuditService = new LandingPageCROAuditService(new GeminiService());
                        $audit = $croAuditService->auditPage($customer, $this->url, $html);
                        
                        // Increment usage counter
                        $customer->increment('cro_audits_used');
                        
                        Log::info("CRO audit completed", [
                            'customer_id' => $this->customerId,
                            'url' => $this->url,
                            'score' => $audit->overall_score,
                            'issues_count' => count($audit->issues ?? []),
                        ]);
                    } catch (\Exception $e) {
                        Log::warning("CRO audit failed for page", [
                            'customer_id' => $this->customerId,
                            'url' => $this->url,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
                
                return; // Skip knowledge base processing for customer pages
            }

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

    /**
     * Detect if the page is a product or money page.
     */
    private function detectPageType(Crawler $crawler): string
    {
        // Check for Schema.org Product
        try {
            $jsonLd = $crawler->filter('script[type="application/ld+json"]')->each(function (Crawler $node) {
                return $node->text();
            });
            
            foreach ($jsonLd as $json) {
                if (str_contains($json, '"@type":"Product"') || str_contains($json, '"@type": "Product"')) {
                    return 'product';
                }
            }
        } catch (\Exception $e) {
            // Ignore parsing errors
        }

        // Check for common e-commerce elements in HTML
        $html = $crawler->html();
        if (preg_match('/add to cart|buy now|checkout/i', $html) && preg_match('/\$\d+(\.\d{2})?/', $html)) {
            return 'product';
        }

        return 'general';
    }
}
