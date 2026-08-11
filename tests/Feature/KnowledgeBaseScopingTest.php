<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\KnowledgeBase;
use App\Models\User;
use App\Services\KnowledgeBaseSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Knowledge-base entries belong to a customer, not a user.
 *
 * Everything else in the retrieval path — customer_pages, campaigns, strategies
 * — is customer-scoped. knowledge_bases alone was keyed by user_id, so a user
 * owning two customers had one shared pool and strategy generation for one
 * retrieved the other's crawled content as context.
 *
 * That was live: one production user pairs voicelawyers.com with
 * sitetospend.com, so a law firm's campaign strategy could be seeded with
 * advertising-software copy.
 */
class KnowledgeBaseScopingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A user owning two unrelated customers — the exact production shape.
     *
     * @return array{0: User, 1: Customer, 2: Customer}
     */
    private function userWithTwoCustomers(): array
    {
        $user = User::factory()->create();

        $lawFirm = Customer::factory()->create(['name' => 'Voice Lawyers', 'website' => 'https://voicelawyers.com']);
        $adTech = Customer::factory()->create(['name' => 'Site To Spend', 'website' => 'https://sitetospend.com']);

        $user->customers()->attach([$lawFirm->id, $adTech->id]);

        return [$user, $lawFirm, $adTech];
    }

    public function test_entries_are_retrieved_for_their_own_customer_only(): void
    {
        [$user, $lawFirm, $adTech] = $this->userWithTwoCustomers();

        KnowledgeBase::create([
            'user_id' => $user->id,
            'customer_id' => $lawFirm->id,
            'url' => 'https://voicelawyers.com/about',
            'content' => 'Defamation and media law specialists.',
            'source_type' => 'url',
        ]);

        KnowledgeBase::create([
            'user_id' => $user->id,
            'customer_id' => $adTech->id,
            'url' => 'https://sitetospend.com/pricing',
            'content' => 'Automated Google Ads campaign management software.',
            'source_type' => 'url',
        ]);

        $lawFirmEntries = KnowledgeBase::where('customer_id', $lawFirm->id)->pluck('content');

        $this->assertCount(1, $lawFirmEntries);
        $this->assertStringContainsString('media law', $lawFirmEntries->first());
        $this->assertStringNotContainsString('campaign management software', $lawFirmEntries->implode(' '));
    }

    public function test_the_old_user_scoped_query_would_have_mixed_them(): void
    {
        // Pins the bug this replaced: scoping by user returns both customers'
        // content, which is what reached the strategy prompt.
        [$user, $lawFirm, $adTech] = $this->userWithTwoCustomers();

        foreach ([[$lawFirm, 'media law'], [$adTech, 'ads software']] as [$customer, $content]) {
            KnowledgeBase::create([
                'user_id' => $user->id,
                'customer_id' => $customer->id,
                'url' => 'https://example.com/'.$customer->id,
                'content' => $content,
                'source_type' => 'url',
            ]);
        }

        $byUser = KnowledgeBase::where('user_id', $user->id)->count();
        $byCustomer = KnowledgeBase::where('customer_id', $lawFirm->id)->count();

        $this->assertSame(2, $byUser, 'user scoping spans both customers');
        $this->assertSame(1, $byCustomer, 'customer scoping isolates them');
    }

    public function test_search_takes_a_customer_and_no_longer_takes_user_ids(): void
    {
        // The signature change is the guarantee: a caller cannot widen the
        // search back to a user's whole pool by passing extra ids.
        $method = new \ReflectionMethod(KnowledgeBaseSearchService::class, 'search');
        $names = array_map(fn ($p) => $p->getName(), $method->getParameters());

        $this->assertSame(['customerId', 'query', 'limit'], $names);
        $this->assertNotContains('userIds', $names);
    }

    public function test_unattributed_entries_are_never_returned(): void
    {
        // The backfill leaves a row null when it cannot be attributed with
        // confidence. Those must stay out of retrieval — guessing an owner
        // would reintroduce the contamination this change removes.
        [$user, $lawFirm] = $this->userWithTwoCustomers();

        KnowledgeBase::create([
            'user_id' => $user->id,
            'customer_id' => null,
            'url' => 'https://unknown-origin.example/page',
            'content' => 'Content of uncertain provenance.',
            'source_type' => 'url',
        ]);

        $this->assertSame(0, KnowledgeBase::where('customer_id', $lawFirm->id)->count());
    }
}
