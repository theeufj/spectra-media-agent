<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\KnowledgeBase;
use App\Services\StorageHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser;

class ProcessKnowledgeBaseFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 3;

    public int $timeout = 180;

    /**
     * @var \App\Models\KnowledgeBase
     */
    public $knowledgeBase;

    private ?string $expectedFilePath = null;

    /**
     * Create a new job instance.
     */
    public function __construct(KnowledgeBase $knowledgeBase)
    {
        $this->knowledgeBase = $knowledgeBase;
        $this->expectedFilePath = $knowledgeBase->file_path;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (! $this->isCurrentDocument()) {
            return;
        }
        try {
            $started = DB::transaction(function () {
                $this->knowledgeBase = KnowledgeBase::whereKey($this->knowledgeBase->id)->lockForUpdate()->firstOrFail();
                if (! $this->isCurrentDocument()) {
                    return false;
                }
                $this->knowledgeBase->update(['processing_status' => 'reading', 'processing_error' => null]);

                return true;
            });
            if (! $started) {
                return;
            }
            $filePath = $this->knowledgeBase->file_path;
            $content = $filePath ? match ($this->knowledgeBase->source_type) {
                'pdf' => $this->extractPdfContent($filePath),
                'text' => $this->extractTextContent($filePath),
                default => '',
            } : $this->knowledgeBase->content;
            if (trim($content) === '') {
                throw new \RuntimeException('No readable content was found. For a scanned PDF, upload a text version.');
            }
            $source = DB::transaction(function () use ($content) {
                $this->knowledgeBase = KnowledgeBase::whereKey($this->knowledgeBase->id)->lockForUpdate()->firstOrFail();
                if (! $this->isCurrentDocument()) {
                    return null;
                }

                return app(\App\Services\KnowledgeBase\KnowledgeBaseIndexer::class)->prepare($this->knowledgeBase, $content);
            });
            if (! $source) {
                return;
            }
            IndexKnowledgeBase::dispatch($source, $source->source_version);

            $customer = Customer::find($this->knowledgeBase->customer_id);
            if ($customer) {
                ExtractBrandGuidelines::dispatch($customer, force: true, sourceRefresh: true);
            }

            Log::info('Uploaded knowledge base document processed', [
                'kb_id' => $this->knowledgeBase->id,
                'status' => 'indexing',
            ]);
        } catch (\Throwable $e) {
            Log::error('Knowledge base document processing failed', [
                'kb_id' => $this->knowledgeBase->id,
                'error' => $e->getMessage(),
            ]);
            KnowledgeBase::whereKey($this->knowledgeBase->id)->where('file_path', $this->expectedFilePath)->whereNull('excluded_at')->update([
                'processing_status' => 'failed',
                'processing_error' => 'We could not read this document. Check that it contains selectable text, or paste the information as a note.',
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
        KnowledgeBase::whereKey($this->knowledgeBase->id)->where('file_path', $this->expectedFilePath)->whereNull('excluded_at')->update(['processing_status' => 'failed', 'processing_error' => 'Document reading did not finish. Refresh this source, replace the file, or add a business note.']);
        Log::error('ProcessKnowledgeBaseFile failed: '.$exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }

    private function isCurrentDocument(): bool
    {
        $this->knowledgeBase->refresh();

        return ! $this->knowledgeBase->excluded_at && $this->knowledgeBase->file_path === $this->expectedFilePath;
    }
}
