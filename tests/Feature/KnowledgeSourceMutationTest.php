<?php

namespace Tests\Feature;

use App\Jobs\ProcessKnowledgeBaseFile;
use App\Models\Customer;
use App\Models\KnowledgeBase;
use App\Models\User;
use App\Services\KnowledgeBase\KnowledgeBaseIndexer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KnowledgeSourceMutationTest extends TestCase
{
    use DatabaseTransactions;

    private function account(): array
    {
        Storage::fake('public');
        $user = User::factory()->create(['email_verified_at' => now()]);
        $customer = Customer::factory()->create();
        $user->customers()->attach($customer->id, ['role' => 'owner']);
        $this->actingAs($user)->withSession(['active_customer_id' => $customer->id]);

        return [$user, $customer];
    }

    private function document(User $user, Customer $customer): KnowledgeBase
    {
        Storage::disk('public')->put('knowledge-base/original.txt', 'Original business facts.');
        $source = KnowledgeBase::create([
            'customer_id' => $customer->id, 'user_id' => $user->id,
            'url' => 'https://example.com/original.txt', 'file_path' => 'knowledge-base/original.txt',
            'original_filename' => 'original.txt', 'source_type' => 'text', 'content' => '',
        ]);

        return app(KnowledgeBaseIndexer::class)->prepare($source, 'Original business facts.');
    }

    public function test_source_limit_rejection_does_not_leave_an_uploaded_file(): void
    {
        [$user, $customer] = $this->account();
        for ($i = 0; $i < 3; $i++) {
            KnowledgeBase::create(['customer_id' => $customer->id, 'user_id' => $user->id, 'url' => "note:{$i}", 'source_type' => 'text', 'content' => 'Saved']);
        }
        $this->post(route('knowledge-base.store'), ['mode' => 'document', 'document' => UploadedFile::fake()->createWithContent('extra.txt', 'Extra facts.')])
            ->assertRedirect()->assertSessionHasErrors('sources');
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame(3, KnowledgeBase::where('customer_id', $customer->id)->count());
    }

    public function test_storage_and_reader_use_the_validated_content_type_instead_of_the_client_filename(): void
    {
        [$user, $customer] = $this->account();
        $file = UploadedFile::fake()->createWithContent('facts.txt', 'Plain business facts.');
        $renamed = new UploadedFile($file->getRealPath(), 'business.pdf', 'text/plain', UPLOAD_ERR_OK, true);
        $this->post(route('knowledge-base.store'), ['mode' => 'document', 'document' => $renamed])
            ->assertRedirect()->assertSessionHasNoErrors();
        $source = KnowledgeBase::where('customer_id', $customer->id)->firstOrFail();
        $this->assertStringEndsWith('.txt', $source->file_path);
        $this->assertSame('text', $source->source_type);
        Storage::disk('public')->assertExists($source->file_path);
    }

    public function test_replacement_keeps_readable_content_and_rejects_a_second_stale_upload(): void
    {
        [$user, $customer] = $this->account();
        $source = $this->document($user, $customer);
        $revision = hash('sha256', $source->file_path);
        $this->post(route('knowledge-base.replace', $source), ['source_version' => 1, 'file_revision' => $revision,
            'document' => UploadedFile::fake()->createWithContent('revised.txt', 'New business facts.')])
            ->assertRedirect()->assertSessionHasNoErrors();
        $path = $source->fresh()->file_path;
        $this->assertSame('Original business facts.', $source->fresh()->content);
        $this->assertSame(1, $source->fresh()->source_version);
        $this->post(route('knowledge-base.replace', $source), ['source_version' => 1, 'file_revision' => $revision,
            'document' => UploadedFile::fake()->createWithContent('stale.txt', 'Stale replacement facts.')])
            ->assertRedirect()->assertSessionHasErrors('document');
        $this->assertSame($path, $source->fresh()->file_path);
        $this->assertSame([$path], Storage::disk('public')->allFiles());
        (new ProcessKnowledgeBaseFile($source->fresh()))->handle();
        $this->assertSame('New business facts.', $source->fresh()->content);
        $this->assertSame(2, $source->fresh()->source_version);
        $this->assertSame('Original business facts.', $source->chunks()->where('source_version', 1)->firstOrFail()->content);
    }

    public function test_a_delayed_file_reader_and_its_failure_hook_cannot_overwrite_a_replacement(): void
    {
        [$user, $customer] = $this->account();
        $source = $this->document($user, $customer);
        $oldJob = new ProcessKnowledgeBaseFile($source);
        $revision = hash('sha256', $source->file_path);
        $this->post(route('knowledge-base.replace', $source), ['source_version' => 1, 'file_revision' => $revision,
            'document' => UploadedFile::fake()->createWithContent('revised.txt', 'New business facts.')])->assertRedirect();
        $oldJob->handle();
        $oldJob->failed(new \RuntimeException('Old reader failed'));
        $this->assertSame('queued', $source->fresh()->processing_status);
        $this->assertNull($source->fresh()->processing_error);
        $this->assertSame('Original business facts.', $source->fresh()->content);
    }

    public function test_a_queue_outage_leaves_a_saved_source_with_a_visible_retry_state(): void
    {
        [$user, $customer] = $this->account();
        $this->app->instance(\Illuminate\Contracts\Bus\Dispatcher::class, new class($this->app) extends \Illuminate\Bus\Dispatcher
        {
            public function dispatch($command)
            {
                throw new \RuntimeException('Private queue connection error');
            }
        });
        $this->post(route('knowledge-base.store'), ['mode' => 'document', 'document' => UploadedFile::fake()->createWithContent('facts.txt', 'Saved facts.')])
            ->assertRedirect()->assertSessionHas('flash.type', 'error');
        $source = KnowledgeBase::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('failed', $source->processing_status);
        $this->assertStringContainsString('Retry from source details', $source->processing_error);
        $this->assertStringNotContainsString('Private queue', $source->processing_error);
        Storage::disk('public')->assertExists($source->file_path);
    }

    public function test_reincluded_and_retried_readable_notes_intentionally_resume_indexing(): void
    {
        [$user, $customer] = $this->account();
        $source = KnowledgeBase::create(['customer_id' => $customer->id, 'user_id' => $user->id, 'url' => 'note:resume',
            'source_type' => 'text', 'content' => 'Readable facts.', 'excluded_at' => now(), 'processing_status' => 'failed']);
        $this->put(route('knowledge-base.update', $source), ['included' => true, 'source_version' => 1])->assertRedirect();
        $this->assertSame('indexing', $source->fresh()->processing_status);
        $source->update(['processing_status' => 'failed']);
        $this->post(route('knowledge-base.retry', $source))->assertRedirect();
        $this->assertSame('indexing', $source->fresh()->processing_status);
    }
}
