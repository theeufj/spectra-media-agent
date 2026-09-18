<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\KnowledgeBase;
use App\Models\User;
use App\Services\GeminiService;
use App\Support\Embeddings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The repair tool has to actually repair something.
 *
 * `embeddings:refresh` decoded knowledge_bases.content as JSON and returned
 * early unless it got an array. That column holds the cleaned page text and
 * always has, so the guard was true for every row in the table: the command
 * walked all of them, wrote nothing, advanced the progress bar to 100% and
 * printed "Done." Production had 283 rows with no vector at all — 193 of them
 * one customer's entire knowledge base — and the tool that exists to fix
 * exactly that could never have fixed any of it.
 *
 * A no-op that reports success is worse than a crash; a crash gets
 * investigated. So the assertion here is not that the command exits zero, it
 * is that a row which had no vector has one afterwards.
 */
class EmbeddingRepairTest extends TestCase
{
    use DatabaseTransactions;

    private function knowledgeBase(): KnowledgeBase
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $user->customers()->attach($customer->id, ['role' => 'owner']);

        return KnowledgeBase::create([
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'url' => 'https://example.test/pricing',
            // Plain text, exactly as CrawlPage writes it.
            'content' => "Perks for startups.\n\nCredits from vendors you already use.\n\nVerified eligibility.",
            'css_content' => '',
            'embedding' => null,
            'embedding_model' => null,
        ]);
    }

    private function fakeEmbedder(): void
    {
        $model = config('ai.models.embedding');

        $this->mock(GeminiService::class, function ($mock) use ($model) {
            $mock->shouldReceive('embedContent')
                ->andReturnUsing(function ($m, $text, $context = [], &$usedModel = null) use ($model) {
                    $usedModel = $model;

                    return array_fill(0, 3072, 1.0);
                });
        });
    }

    public function test_a_row_with_no_vector_has_one_afterwards(): void
    {
        $kb = $this->knowledgeBase();
        $this->fakeEmbedder();

        $this->artisan('embeddings:refresh', ['--mismatched' => true, '--only' => 'knowledge'])
            ->assertSuccessful();

        $this->assertNotNull(
            $kb->fresh()->embedding,
            'the repair command reported success without writing a vector',
        );
    }

    public function test_the_stored_vector_is_flat_rather_than_a_list_of_chunk_vectors(): void
    {
        $kb = $this->knowledgeBase();
        $this->fakeEmbedder();

        $this->artisan('embeddings:refresh', ['--mismatched' => true, '--only' => 'knowledge'])
            ->assertSuccessful();

        // new Vector($allEmbeddings) would nest one array per chunk here.
        $stored = $kb->fresh()->embedding->toArray();

        $this->assertCount(3072, $stored);
        // The nested case is the one that matters: a list of chunk vectors
        // would put an array here rather than a number.
        $this->assertIsNotArray($stored[0]);
        $this->assertIsNumeric($stored[0]);
    }
}
