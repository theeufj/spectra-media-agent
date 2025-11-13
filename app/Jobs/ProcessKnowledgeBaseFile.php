<?php

namespace App\Jobs;

use App\Models\KnowledgeBase;
use App\Prompts\ChunkingPrompt;
use App\Services\GeminiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;

class ProcessKnowledgeBaseFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var \App\Models\KnowledgeBase
     */
    public $knowledgeBase;

    /**
     * Create a new job instance.
     */
    public function __construct(KnowledgeBase $knowledgeBase)
    {
        $this->knowledgeBase = $knowledgeBase;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $filePath = $this->knowledgeBase->file_path;
            
            Log::info("Starting file processing job", [
                'kb_id' => $this->knowledgeBase->id,
                'user_id' => $this->knowledgeBase->user_id,
                'file_path' => $filePath,
                'source_type' => $this->knowledgeBase->source_type,
                'url' => $this->knowledgeBase->url,
            ]);
            
            if (!$filePath) {
                Log::warning("No file path found for knowledge base {$this->knowledgeBase->id}");
                return;
            }

            $content = '';
            $sourceType = $this->knowledgeBase->source_type;

            if ($sourceType === 'pdf') {
                $content = $this->extractPdfContent($filePath);
            } elseif ($sourceType === 'text') {
                $content = $this->extractTextContent($filePath);
            }

            if ($content) {
                // Initialize Gemini Service
                $geminiService = new GeminiService();

                // Step 3: Use Gemini's Generative Content API to break content into semantically meaningful chunks.
                $chunkingPrompt = (new ChunkingPrompt($content))->getPrompt();
                $generatedResponse = $geminiService->generateContent('gemini-2.5-pro', $chunkingPrompt);

                if (is_null($generatedResponse)) {
                    Log::error("Failed to get chunks from Gemini for KB ID {$this->knowledgeBase->id}: Generated text was null.");
                    return;
                }

                // Extract text from the response array
                $generatedText = $generatedResponse['text'] ?? null;
                if (is_null($generatedText)) {
                    Log::error("Failed to get chunks from Gemini for KB ID {$this->knowledgeBase->id}: No text field in response.");
                    return;
                }
                
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
                    Log::error("Failed to parse Gemini's chunking response for KB ID {$this->knowledgeBase->id}: " . $e->getMessage(), [
                        'generated_text' => $generatedText,
                    ]);
                    return;
                }

                if (empty($chunks)) {
                    Log::info("Gemini returned no chunks for KB ID {$this->knowledgeBase->id}");
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
                        Log::warning("Failed to get embedding for a chunk from KB ID {$this->knowledgeBase->id}. Skipping chunk.", [
                            'chunk' => substr($chunk, 0, 100) . '...',
                        ]);
                        continue;
                    }

                    $allEmbeddings[] = $embedding;
                    $allChunkContents[] = $chunk;
                }

                if (empty($allEmbeddings)) {
                    Log::warning("No embeddings generated for any chunks from KB ID {$this->knowledgeBase->id}");
                    return;
                }

                // Update the knowledge base with extracted content and embeddings
                $this->knowledgeBase->update([
                    'content' => json_encode($allChunkContents),
                    'embedding' => new Vector($allEmbeddings),
                ]);

                Log::info("Successfully processed {$sourceType} file with generative chunking for knowledge base {$this->knowledgeBase->id}", [
                    'chunks_count' => count($allChunkContents),
                    'embeddings_count' => count($allEmbeddings),
                ]);
            } else {
                Log::warning("No content extracted from {$sourceType} file for knowledge base {$this->knowledgeBase->id}");
            }
        } catch (\Exception $e) {
            Log::error("Error processing knowledge base file {$this->knowledgeBase->id}: " . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }

    /**
     * Extract content from a PDF file.
     */
    private function extractPdfContent(string $filePath): string
    {
        try {
            Log::info("Extracting PDF content", [
                'file_path' => $filePath,
            ]);

            // Get the file content directly from S3
            if (!Storage::disk('s3')->exists($filePath)) {
                Log::error("File not found in S3", [
                    'file_path' => $filePath,
                    'kb_id' => $this->knowledgeBase->id,
                ]);
                return '';
            }

            $fileContent = Storage::disk('s3')->get($filePath);
            
            if (empty($fileContent)) {
                Log::warning("Retrieved empty content from S3 for PDF", [
                    'file_path' => $filePath,
                    'kb_id' => $this->knowledgeBase->id,
                ]);
                return '';
            }

            Log::info("Successfully retrieved PDF content from S3", [
                'file_path' => $filePath,
                'content_size' => strlen($fileContent),
            ]);

            $parser = new Parser();
            $pdf = $parser->parseContent($fileContent);
            $text = $pdf->getText();

            Log::info("PDF content parsed successfully", [
                'file_path' => $filePath,
                'extracted_text_length' => strlen($text),
            ]);

            return $text;
        } catch (\Exception $e) {
            Log::error("Error extracting PDF content for {$filePath}: " . $e->getMessage(), [
                'exception' => $e,
                'kb_id' => $this->knowledgeBase->id,
            ]);
            return '';
        }
    }

    /**
     * Extract content from a text file.
     */
    private function extractTextContent(string $filePath): string
    {
        try {
            Log::info("Extracting text file content", [
                'file_path' => $filePath,
            ]);

            // Get the file from S3 using CloudFront URL
            $cloudfrontDomain = config('filesystems.cloudfront_domain') ?: env('CLOUDFRONT_DOMAIN');
            $cloudfrontUrl = "{$cloudfrontDomain}/{$filePath}";
            
            Log::info("Attempting to fetch text file from CloudFront", [
                'cloudfront_domain' => $cloudfrontDomain,
                'cloudfront_url' => $cloudfrontUrl,
            ]);
            
            $content = file_get_contents($cloudfrontUrl);
            
            Log::info("Text file content fetched successfully", [
                'content_size_bytes' => strlen($content),
            ]);
            
            return $content;
        } catch (\Exception $e) {
            Log::error("Error extracting text file content: " . $e->getMessage(), [
                'file_path' => $filePath,
                'exception' => $e,
            ]);
            return '';
        }
    }
}
