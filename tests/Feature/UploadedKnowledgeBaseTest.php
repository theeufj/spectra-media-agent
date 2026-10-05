<?php

namespace Tests\Feature;

use App\Jobs\ExtractBrandGuidelines;
use App\Jobs\IndexKnowledgeBase;
use App\Jobs\ProcessKnowledgeBaseFile;
use App\Models\KnowledgeBase;
use App\Services\GeminiService;
use App\Services\KnowledgeBase\KnowledgeBaseIndexer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadedKnowledgeBaseTest extends TestCase
{
    use DatabaseTransactions;

    private function document(string $content): KnowledgeBase
    {
        Storage::fake('public');
        Storage::disk('public')->put('knowledge-base/business.txt', $content);

        return KnowledgeBase::factory()->create([
            'source_type' => 'text',
            'file_path' => 'knowledge-base/business.txt',
            'content' => '',
        ]);
    }

    public function test_upload_reads_its_storage_and_persists_one_vector_then_starts_brand_extraction(): void
    {
        $content = str_repeat('Listing campaigns. ', 70)."\n\n".str_repeat('Weekly reports. ', 80);
        $document = $this->document($content);
        $calls = 0;
        $this->mock(GeminiService::class, function ($mock) use (&$calls) {
            $mock->shouldReceive('embedContent')->twice()
                ->andReturnUsing(function ($requested, $text, $options, &$model) use (&$calls) {
                    $model = $requested;

                    return array_fill(0, 3072, ++$calls === 1 ? 1.0 : 3.0);
                });
        });

        (new ProcessKnowledgeBaseFile($document))->handle();
        Queue::assertPushed(IndexKnowledgeBase::class, 1);
        $this->assertSame('indexing', $document->fresh()->processing_status);
        (new IndexKnowledgeBase($document))->handle(app(KnowledgeBaseIndexer::class), app(GeminiService::class));

        $document->refresh();
        $this->assertSame($content, $document->content);
        $this->assertEquals(array_fill(0, 3072, 2.0), $document->embedding->toArray());
        $this->assertSame(config('ai.models.embedding'), $document->getAttribute('embedding_model'));
        Queue::assertPushed(ExtractBrandGuidelines::class, 1);
        Http::assertNothingSent();
    }

    public function test_mixed_embedding_spaces_do_not_get_averaged(): void
    {
        $content = str_repeat('Listing campaigns. ', 70)."\n\n".str_repeat('Weekly reports. ', 80);
        $document = $this->document($content);
        $calls = 0;
        $this->mock(GeminiService::class, function ($mock) use (&$calls) {
            $mock->shouldReceive('embedContent')->twice()
                ->andReturnUsing(function ($requested, $text, $options, &$model) use (&$calls) {
                    $model = 'test-space-'.++$calls;

                    return array_fill(0, 3072, 1.0);
                });
        });

        (new ProcessKnowledgeBaseFile($document))->handle();
        Queue::assertPushed(IndexKnowledgeBase::class, 1);
        $this->assertSame('indexing', $document->fresh()->processing_status);
        (new IndexKnowledgeBase($document))->handle(app(KnowledgeBaseIndexer::class), app(GeminiService::class));

        $document->refresh();
        $this->assertSame($content, $document->content);
        $this->assertNull($document->embedding);
        $this->assertNull($document->getAttribute('embedding_model'));
        $this->assertSame('needs_attention', $document->processing_status);
        $this->assertSame(['test-space-1', 'test-space-2'], $document->chunks()->orderBy('position')->pluck('embedding_model')->all());
    }

    public function test_embedding_failure_does_not_block_using_the_uploaded_business_content(): void
    {
        $document = $this->document('Property campaign setup for real estate agents.');
        $this->mock(GeminiService::class, function ($mock) {
            $mock->shouldReceive('embedContent')->once()->andReturnNull();
        });

        (new ProcessKnowledgeBaseFile($document))->handle();
        Queue::assertPushed(IndexKnowledgeBase::class, 1);
        $this->assertSame('indexing', $document->fresh()->processing_status);
        (new IndexKnowledgeBase($document))->handle(app(KnowledgeBaseIndexer::class), app(GeminiService::class));

        $this->assertSame('Property campaign setup for real estate agents.', $document->fresh()->content);
        $this->assertNull($document->fresh()->embedding);
        Queue::assertPushed(ExtractBrandGuidelines::class, 1);
    }
}
