<?php

namespace Tests\Feature;

use App\Jobs\CrawlPage;
use App\Jobs\IndexKnowledgeBase;
use App\Models\Customer;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeImport;
use App\Models\User;
use App\Services\GeminiService;
use App\Services\KnowledgeBase\KnowledgeBaseIndexer;
use App\Services\KnowledgeBase\KnowledgeHealth;
use App\Services\KnowledgeBaseSearchService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Pgvector\Laravel\Vector;
use Tests\TestCase;

class KnowledgeWorkspaceJourneyTest extends TestCase
{
    use DatabaseTransactions;

    private function source(Customer $customer, User $user, string $content): KnowledgeBase
    {
        $source = KnowledgeBase::create(['customer_id' => $customer->id, 'user_id' => $user->id,
            'url' => 'note:'.fake()->uuid(), 'title' => 'Business facts', 'source_type' => 'text', 'content' => '']);

        return app(KnowledgeBaseIndexer::class)->prepare($source, $content);
    }

    private function textSearch(): KnowledgeBaseSearchService
    {
        $this->mock(GeminiService::class, fn ($mock) => $mock->shouldReceive('embedContent')->andReturnNull());

        return app(KnowledgeBaseSearchService::class);
    }

    public function test_plain_text_supports_real_questions_when_embedding_capacity_is_unavailable(): void
    {
        $user = User::factory()->create();
        $a = Customer::factory()->create();
        $b = Customer::factory()->create();
        $user->customers()->attach([$a->id, $b->id]);
        $source = $this->source($a, $user, 'SOC 2 compliance is included in our enterprise plan.');
        $this->source($b, $user, 'SOC 2 services for a different business.');

        $results = $this->textSearch()->passages($a->id, 'Do we support SOC 2?');

        $this->assertCount(1, $results);
        $this->assertSame($source->id, $results[0]['kb_id']);
        $this->assertStringContainsString('enterprise plan', $results[0]['chunk']);
        $this->assertSame('text', $results[0]['method']);
    }

    public function test_the_matching_passage_is_retrieved_from_deep_in_a_long_document(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $source = $this->source($customer, $user, str_repeat('Unrelated introduction. ', 120)."\n\n".'Refunds are available for 30 days after purchase.');
        $queryVector = array_fill(0, 3072, 0.0);
        $queryVector[0] = 1.0;
        foreach ($source->chunks()->get() as $chunk) {
            $vector = $queryVector;
            $vector[0] = str_contains($chunk->content, 'Refunds') ? 1.0 : -1.0;
            $chunk->update(['embedding' => new Vector($vector), 'embedding_model' => config('ai.models.embedding')]);
        }
        $this->mock(GeminiService::class, function ($mock) use ($queryVector) {
            $mock->shouldReceive('embedContent')->andReturnUsing(function ($model, $text, $context, &$usedModel) use ($queryVector) {
                $usedModel = $model;

                return $queryVector;
            });
        });

        $results = app(KnowledgeBaseSearchService::class)->passages($customer->id, 'What is the returns policy?');

        $this->assertCount(1, $results);
        $this->assertGreaterThan(0, $results[0]['position']);
        $this->assertStringContainsString('30 days', $results[0]['chunk']);
        $this->assertSame('semantic', $results[0]['method']);
    }

    public function test_refresh_preserves_used_versions_and_exclusion_removes_all_current_retrieval(): void
    {
        $customer = Customer::factory()->create();
        $user = User::factory()->create();
        $source = $this->source($customer, $user, 'Refund window is 30 days.');
        $search = $this->textSearch();
        $search->search($customer->id, 'refund window');
        $this->assertDatabaseHas('knowledge_retrievals', ['knowledge_base_id' => $source->id, 'source_version' => 1]);

        $source = app(KnowledgeBaseIndexer::class)->prepare($source, 'Refund window is 14 days.');
        $this->assertSame(2, $source->source_version);
        $this->assertStringContainsString('30 days', $source->chunks()->where('source_version', 1)->first()->content);
        $results = $search->passages($customer->id, 'refund window');
        $this->assertSame(2, $results[0]['source_version']);
        $this->assertStringNotContainsString('30 days', implode(' ', array_column($results, 'chunk')));
        $source->update(['excluded_at' => now()]);
        $this->assertSame([], $search->passages($customer->id, 'refund window'));
    }

    public function test_health_distinguishes_readable_fallback_passages_from_primary_readiness(): void
    {
        $customer = Customer::factory()->create();
        $source = $this->source($customer, User::factory()->create(), 'Our help desk is available on weekdays.');
        $source->chunks()->first()->update(['embedding' => new Vector(array_fill(0, 3072, 1.0)), 'embedding_model' => 'test-fallback-space']);
        $source->update(['processing_status' => 'needs_attention']);

        $health = app(KnowledgeHealth::class)->forCustomer($customer->id);
        $this->assertSame(1, $health['readable']);
        $this->assertSame(0, $health['ready']);
        $this->assertSame(1, $health['mismatched_passages']);
    }

    public function test_source_changes_are_shared_by_customer_members_and_stale_edits_are_rejected(): void
    {
        $customer = Customer::factory()->create();
        $uploader = User::factory()->create();
        $colleague = User::factory()->create();
        $customer->users()->attach([$uploader->id, $colleague->id]);
        $source = $this->source($customer, $uploader, 'Offer version one.');
        $this->actingAs($colleague)->withSession(['active_customer_id' => $customer->id]);
        $this->put(route('knowledge-base.update', $source), ['title' => 'Shared offer', 'content' => 'Offer version two.', 'source_version' => 1])->assertSessionHasNoErrors();
        Queue::assertPushed(IndexKnowledgeBase::class);
        $this->assertSame(2, $source->fresh()->source_version);
        $this->put(route('knowledge-base.update', $source), ['content' => 'Outdated editor replacement.', 'source_version' => 1])->assertSessionHasErrors('content');
        $this->assertSame('Offer version two.', $source->fresh()->content);
    }

    public function test_a_foreign_source_is_not_visible_and_import_accepts_only_previewed_urls(): void
    {
        $user = User::factory()->create();
        $ours = Customer::factory()->create();
        $user->customers()->attach($ours->id);
        $foreign = $this->source(Customer::factory()->create(), User::factory()->create(), 'Private facts.');
        $this->actingAs($user)->withSession(['active_customer_id' => $ours->id]);
        $this->get(route('knowledge-base.show', $foreign))->assertNotFound();
        $import = KnowledgeImport::create(['customer_id' => $ours->id, 'user_id' => $user->id, 'website_url' => 'https://example.com', 'status' => 'review', 'candidates' => [['url' => 'https://example.com/services']]]);
        $this->post(route('knowledge-base.imports.start', $import), ['urls' => ['https://example.com/not-previewed']])->assertSessionHasErrors('urls');
        $this->assertSame('review', $import->fresh()->status);
        $this->assertSame(0, KnowledgeBase::where('customer_id', $ours->id)->count());
    }

    public function test_import_reads_only_selected_pages_and_free_source_cap_cannot_be_bypassed_by_inclusion(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $user->customers()->attach($customer->id);
        $this->actingAs($user)->withSession(['active_customer_id' => $customer->id]);
        $import = KnowledgeImport::create(['customer_id' => $customer->id, 'user_id' => $user->id, 'website_url' => 'https://example.com', 'status' => 'review', 'candidates' => [['url' => 'https://example.com/services'], ['url' => 'https://example.com/legal']]]);
        $this->post(route('knowledge-base.imports.start', $import), ['urls' => ['https://example.com/services']])->assertSessionHasNoErrors();
        Bus::assertBatched(fn ($batch) => count($batch->jobs) === 1 && $batch->jobs[0] instanceof CrawlPage && $batch->jobs[0]->url === 'https://example.com/services');
        $this->assertDatabaseMissing('knowledge_bases', ['customer_id' => $customer->id, 'url' => 'https://example.com/legal']);
        $this->source($customer, $user, 'Second source.');
        $excluded = $this->source($customer, $user, 'Excluded source.');
        $excluded->update(['excluded_at' => now()]);
        $this->source($customer, $user, 'Third source.');
        $this->put(route('knowledge-base.update', $excluded), ['included' => true, 'source_version' => 1])->assertSessionHasErrors('sources');
        $this->assertNotNull($excluded->fresh()->excluded_at);
    }

    public function test_refresh_archives_a_legacy_source_before_replacing_its_readable_text(): void
    {
        $customer = Customer::factory()->create();
        $source = KnowledgeBase::create(['customer_id' => $customer->id, 'user_id' => User::factory()->create()->id,
            'url' => 'note:legacy', 'source_type' => 'text', 'content' => 'Our legacy refund window is 30 days.']);

        $source = app(KnowledgeBaseIndexer::class)->prepare($source, 'Our current refund window is 14 days.');

        $this->assertSame(2, $source->source_version);
        $this->assertSame('Our legacy refund window is 30 days.', $source->chunks()->where('source_version', 1)->first()->content);
        $this->assertSame('Our current refund window is 14 days.', $source->chunks()->where('source_version', 2)->first()->content);
    }

    public function test_a_superseded_embedding_worker_cannot_publish_its_vector_to_the_current_page(): void
    {
        $customer = Customer::factory()->create();
        $source = $this->source($customer, User::factory()->create(), 'Previous offer.');
        $source->update(['source_type' => 'url', 'url' => 'https://example.com/offer']);
        $page = \App\Models\CustomerPage::create(['customer_id' => $customer->id, 'url' => $source->url,
            'content' => 'Previous offer.', 'embedding' => new Vector(array_fill(0, 3072, 2.0)), 'embedding_model' => 'test-previous-space']);
        $gemini = $this->mock(GeminiService::class, function ($mock) use ($source) {
            $mock->shouldReceive('embedContent')->andReturnUsing(function ($model, $text, $context, &$usedModel) use ($source) {
                app(KnowledgeBaseIndexer::class)->prepare($source, 'Replacement offer.');
                $usedModel = $model;

                return array_fill(0, 3072, 1.0);
            });
        });

        $this->assertTrue(app(KnowledgeBaseIndexer::class)->index($source, $gemini, 1));
        $this->assertSame(2, $source->fresh()->source_version);
        $this->assertSame('indexing', $source->fresh()->processing_status);
        $this->assertNull($source->fresh()->embedding);
        $this->assertSame('test-previous-space', $page->fresh()->getAttribute('embedding_model'));
        $this->assertSame(2.0, (float) $page->fresh()->embedding->toArray()[0]);
    }

    public function test_changing_the_primary_model_invalidates_stale_ready_health(): void
    {
        $customer = Customer::factory()->create();
        $source = $this->source($customer, User::factory()->create(), 'Included information remains readable.');
        $source->chunks()->first()->update(['embedding' => new Vector(array_fill(0, 3072, 1.0)), 'embedding_model' => config('ai.models.embedding')]);
        $source->update(['processing_status' => 'ready', 'embedding_model' => config('ai.models.embedding')]);
        config(['ai.models.embedding' => 'test-replacement-space']);

        $health = app(KnowledgeHealth::class)->forCustomer($customer->id);

        $this->assertSame(0, $health['ready']);
        $this->assertSame(1, $health['readable']);
        $this->assertSame(1, $health['mismatched_passages']);
        $this->assertSame('needs_attention', $source->fresh()->processing_status);
    }

    public function test_an_old_indexer_cannot_mark_an_unread_replacement_ready(): void
    {
        $customer = Customer::factory()->create();
        $source = $this->source($customer, User::factory()->create(), 'Previously readable document.');
        $gemini = $this->mock(GeminiService::class, function ($mock) use ($source) {
            $mock->shouldReceive('embedContent')->andReturnUsing(function ($model, $text, $context, &$usedModel) use ($source) {
                $source->update(['processing_status' => 'queued', 'file_path' => 'new-replacement.txt']);
                $usedModel = $model;

                return array_fill(0, 3072, 1.0);
            });
        });

        $this->assertTrue(app(KnowledgeBaseIndexer::class)->index($source, $gemini, 1));
        $this->assertSame('queued', $source->fresh()->processing_status);
        $this->assertSame('Previously readable document.', $source->fresh()->content);
        $this->assertNull($source->fresh()->embedding);
    }

    public function test_a_reserved_business_brief_does_not_wait_for_or_duplicate_a_retrieval_slot(): void
    {
        $customer = Customer::factory()->create();
        $user = User::factory()->create();
        $brief = $this->source($customer, $user, 'Our business brief must always reach the writer.');
        $brief->update(['original_filename' => 'onboarding-business-brief.txt']);
        $this->source($customer, $user, 'Additional product information.');
        $this->mock(GeminiService::class)->shouldNotReceive('embedContent');

        $result = app(\App\Services\KnowledgeBase\KnowledgeBaseRetriever::class)->search($customer, 'Describe the product', 1);

        $this->assertCount(1, $result);
        $this->assertStringContainsString('Our business brief', $result[0]['excerpt']);
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('knowledge_retrievals')->where('customer_id', $customer->id)->count());
    }
}
