<?php

namespace Tests\Feature\Campaigns;

use App\Models\Customer;
use App\Models\User;
use App\Services\GeminiService;
use App\Services\KnowledgeBase\KnowledgeBaseRetriever;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Retrieve the customer's own pages, by meaning, from the database.
 *
 * The crawl stores page text alongside a 3072-dimension embedding in a real
 * pgvector column, so relevance is a `<=>` ordering in SQL. The only other
 * place that searches this data loads every row into PHP and computes cosine
 * similarity in a loop — the largest production account has 1,236 pages.
 *
 * The scoping test is the one that matters. This content is fed straight into
 * AI prompts, so a mis-scoped query would put one customer's website into
 * another customer's campaign.
 */
class KnowledgeBaseRetrieverTest extends TestCase
{
    use DatabaseTransactions;

    private Customer $ours;

    private Customer $theirs;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Customer::unsetEventDispatcher();

        $this->ours = Customer::factory()->create(['name' => 'Ours']);
        $this->theirs = Customer::factory()->create(['name' => 'Theirs']);
        // knowledge_bases.user_id is NOT NULL — the crawl always runs as someone.
        $this->user = User::factory()->create();
    }

    /**
     * A 3072-dimension vector that leans in one direction, so ordering is
     * predictable without needing a real embedding model.
     */
    private function vector(float $lead): string
    {
        $values = array_fill(0, 3072, 0.0);
        $values[0] = $lead;

        return '['.implode(',', $values).']';
    }

    private function page(Customer $customer, string $url, string $content, float $lead): void
    {
        // Padded past the chrome threshold — anything shorter is filtered as a
        // nav bar or an error page, which is what test_chrome_is_skipped covers.
        $content = str_pad($content, 400, ' Further detail about this page.');

        DB::statement(
            'INSERT INTO knowledge_bases (customer_id, user_id, url, content, embedding, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?::vector, now(), now())',
            [$customer->id, $this->user->id, $url, $content, $this->vector($lead)],
        );
    }

    private function retrieverReturning(float $lead): KnowledgeBaseRetriever
    {
        $embedding = array_fill(0, 3072, 0.0);
        $embedding[0] = $lead;

        return new KnowledgeBaseRetriever(new class($embedding) extends GeminiService
        {
            public function __construct(private readonly array $embedding) {}

            public function embedContent(string $model, string $text, array $context = []): array
            {   // narrower than the parent's ?array: this stub always embeds
                return $this->embedding;
            }
        });
    }

    public function test_it_returns_only_the_bound_customers_pages(): void
    {
        $this->page($this->ours, 'https://ours.test/services', 'We install heat pumps across Sydney.', 1.0);
        $this->page($this->theirs, 'https://theirs.test/services', 'We sell luxury watches.', 1.0);

        $results = $this->retrieverReturning(1.0)->search($this->ours, 'what do they sell?', 10);

        $urls = array_column($results, 'url');

        // This text goes straight into an AI prompt. A leak here writes one
        // customer's business into another customer's campaign.
        $this->assertContains('https://ours.test/services', $urls);
        $this->assertNotContains('https://theirs.test/services', $urls);
    }

    public function test_it_orders_by_relevance(): void
    {
        $this->page($this->ours, 'https://ours.test/far', 'Unrelated page.', -1.0);
        $this->page($this->ours, 'https://ours.test/near', 'Exactly what they sell.', 1.0);

        $results = $this->retrieverReturning(1.0)->search($this->ours, 'what do they sell?', 10);

        $this->assertSame('https://ours.test/near', $results[0]['url']);
    }

    public function test_it_respects_the_limit(): void
    {
        foreach (range(1, 6) as $i) {
            $this->page($this->ours, "https://ours.test/page-{$i}", "Page {$i} content.", 1.0);
        }

        $this->assertCount(3, $this->retrieverReturning(1.0)->search($this->ours, 'anything', 3));
    }

    public function test_excerpts_are_capped_and_whitespace_collapsed(): void
    {
        // Crawled text carries navigation and footers; the excerpt budget
        // should go on prose, not on the blank lines between menu items.
        $this->page($this->ours, 'https://ours.test/long', "Heat   pumps\n\n\n".str_repeat('x', 4000), 1.0);

        $excerpt = $this->retrieverReturning(1.0)->search($this->ours, 'anything', 1)[0]['excerpt'];

        $this->assertStringStartsWith('Heat pumps x', $excerpt);
        $this->assertLessThan(1400, mb_strlen($excerpt));
    }

    public function test_chrome_is_skipped(): void
    {
        // One production store has 1,236 crawled pages and 3 with real text on
        // them; the rest are menus and "there was a problem loading this
        // website". Feeding those to a model pads the prompt with fragments
        // that say nothing about the business.
        DB::statement(
            'INSERT INTO knowledge_bases (customer_id, user_id, url, content, embedding, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?::vector, now(), now())',
            [$this->ours->id, $this->user->id, 'https://ours.test/menu', 'Home About Contact', $this->vector(1.0)],
        );

        $this->assertSame([], $this->retrieverReturning(1.0)->search($this->ours, 'anything', 10));
    }

    public function test_pages_without_an_embedding_are_skipped(): void
    {
        DB::table('knowledge_bases')->insert([
            'customer_id' => $this->ours->id,
            'user_id' => $this->user->id,
            'url' => 'https://ours.test/unembedded',
            'content' => 'Never indexed.',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame([], $this->retrieverReturning(1.0)->search($this->ours, 'anything', 10));
    }

    public function test_a_failed_embedding_returns_nothing_rather_than_throwing(): void
    {
        $this->page($this->ours, 'https://ours.test/a', 'Content.', 1.0);

        $retriever = new KnowledgeBaseRetriever(new class extends GeminiService
        {
            public function __construct() {}

            public function embedContent(string $model, string $text, array $context = []): ?array
            {
                return null;
            }
        });

        // Callers degrade to what they had before; a retrieval miss must not
        // cost the whole job.
        $this->assertSame([], $retriever->search($this->ours, 'anything', 10));
    }
}
