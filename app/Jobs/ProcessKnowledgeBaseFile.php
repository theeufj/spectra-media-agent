<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\KnowledgeBase;
use App\Services\GeminiService;
use App\Services\StorageHelper;
use App\Support\Embeddings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Pgvector\Laravel\Vector;
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
            if (! $filePath) {
                throw new \RuntimeException('The uploaded document has no storage path.');
            }

            $content = match ($this->knowledgeBase->source_type) {
                'pdf' => $this->extractPdfContent($filePath),
                'text' => $this->extractTextContent($filePath),
                default => '',
            };
            if (trim($content) === '') {
                throw new \RuntimeException('No readable content was found in the uploaded document.');
            }

            // The business description remains usable even if embedding is
            // unavailable. A failed AI call must not erase the uploaded text.
            $this->knowledgeBase->update([
                'content' => $content, 'embedding' => null, 'embedding_model' => null,
            ]);

            $gemini = app(GeminiService::class);
            $vectors = [];
            $models = [];
            // Splitting locally avoids an extra generative call and preserves
            // every passage instead of asking a model to rewrite the source.
            foreach (Embeddings::split($content) as $chunk) {
                $vector = $gemini->embedContent(config('ai.models.embedding'), $chunk, [], $model);
                if ($vector !== null && $model !== null) {
                    $vectors[] = $vector;
                    $models[] = $model;
                }
            }

            // pgvector stores ONE vector per document, not a matrix of chunks.
            // A rate-limit fallback can also change spaces at the same size.
            $singleModel = count(array_unique($models)) === 1;
            $average = $singleModel ? Embeddings::average($vectors) : null;
            $this->knowledgeBase->update([
                'embedding' => $average ? new Vector($average) : null,
                'embedding_model' => $average ? $models[0] : null,
            ]);

            $customer = Customer::find($this->knowledgeBase->customer_id);
            if ($customer && ! $customer->brandGuideline()->exists()) {
                ExtractBrandGuidelines::dispatch($customer);
            }

            Log::info('Uploaded knowledge base document processed', [
                'kb_id' => $this->knowledgeBase->id,
                'embedded_chunks' => count($vectors),
            ]);
        } catch (\Throwable $e) {
            Log::error('Knowledge base document processing failed', [
                'kb_id' => $this->knowledgeBase->id,
                'error' => $e->getMessage(),
            ]);
            // Let the queue retry and report terminal failure normally.
            throw $e;
        }
    }

    /**
     * Extract content from a PDF file.
     */
    private function extractPdfContent(string $filePath): string
    {
        try {
            Log::info('Extracting PDF content', [
                'file_path' => $filePath,
            ]);

            // Get the file content from storage
            if (! StorageHelper::exists($filePath)) {
                Log::error('File not found in storage', [
                    'file_path' => $filePath,
                    'kb_id' => $this->knowledgeBase->id,
                ]);

                return '';
            }

            $fileContent = StorageHelper::get($filePath);

            if (empty($fileContent)) {
                Log::warning('Retrieved empty content from storage for PDF', [
                    'file_path' => $filePath,
                    'kb_id' => $this->knowledgeBase->id,
                ]);

                return '';
            }

            Log::info('Successfully retrieved PDF content from storage', [
                'file_path' => $filePath,
                'content_size' => strlen($fileContent),
            ]);

            $parser = new Parser;
            $pdf = $parser->parseContent($fileContent);
            $text = $pdf->getText();

            Log::info('PDF content parsed successfully', [
                'file_path' => $filePath,
                'extracted_text_length' => strlen($text),
            ]);

            return $text;
        } catch (\Throwable $e) {
            report($e);
            Log::error("Error extracting PDF content for {$filePath}: ".$e->getMessage(), [
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
        // Use the same storage backend as the upload, including local storage.
        // A public CDN URL need not exist or allow reads for an uploaded file.
        return StorageHelper::get($filePath) ?? '';
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessKnowledgeBaseFile failed: '.$exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
