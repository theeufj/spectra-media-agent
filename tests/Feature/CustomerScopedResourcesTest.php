<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignConversation;
use App\Models\CreativeUsage;
use App\Models\Customer;
use App\Models\User;
use App\Services\CreativeQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A customer may have many users. Anything describing the advertising account
 * belongs to the customer; only things describing a person belong to the user.
 *
 * Two resources were keyed the wrong way round:
 *
 *  - campaign_conversations on (campaign_id, user_id), so two colleagues
 *    discussing the same campaign got separate threads and neither saw the
 *    other's context — despite the campaign belonging to one customer.
 *  - creative_usages on (user_id, period), so a customer with three users
 *    received three separate generation quotas. Quota is sold per account.
 */
class CustomerScopedResourcesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Two colleagues on one customer — the shape the old keying got wrong.
     *
     * @return array{0: Customer, 1: User, 2: User}
     */
    private function customerWithTwoUsers(): array
    {
        $customer = Customer::factory()->create();
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $alice->customers()->attach($customer->id);
        $bob->customers()->attach($customer->id);

        return [$customer, $alice, $bob];
    }

    public function test_colleagues_share_one_conversation_thread(): void
    {
        [$customer, $alice, $bob] = $this->customerWithTwoUsers();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);

        $first = CampaignConversation::getOrCreate($campaign->id, $customer->id, $alice->id);
        $first->addMessage('user', 'Should we raise the budget?');

        $second = CampaignConversation::getOrCreate($campaign->id, $customer->id, $bob->id);

        $this->assertSame($first->id, $second->id, 'colleagues must land on the same thread');
        $this->assertCount(1, $second->fresh()->messages);
    }

    public function test_the_thread_records_the_most_recent_participant(): void
    {
        // The conversation belongs to the customer, but who last spoke is
        // genuinely per-person and worth keeping.
        [$customer, $alice, $bob] = $this->customerWithTwoUsers();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);

        CampaignConversation::getOrCreate($campaign->id, $customer->id, $alice->id);
        $conversation = CampaignConversation::getOrCreate($campaign->id, $customer->id, $bob->id);

        $this->assertSame($bob->id, $conversation->fresh()->user_id);
    }

    public function test_different_customers_still_get_separate_threads(): void
    {
        $campaignA = Campaign::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $campaignB = Campaign::factory()->create(['customer_id' => Customer::factory()->create()->id]);

        $a = CampaignConversation::getOrCreate($campaignA->id, $campaignA->customer_id);
        $b = CampaignConversation::getOrCreate($campaignB->id, $campaignB->customer_id);

        $this->assertNotSame($a->id, $b->id);
    }

    public function test_colleagues_draw_on_one_shared_generation_quota(): void
    {
        [$customer, $alice, $bob] = $this->customerWithTwoUsers();
        $service = app(CreativeQuotaService::class);

        $forAlice = $service->getOrCreateUsage($alice);
        $forBob = $service->getOrCreateUsage($bob);

        $this->assertSame($forAlice->id, $forBob->id, 'quota is sold per account, not per seat');
        $this->assertSame($customer->id, $forAlice->customer_id);
        $this->assertSame(1, CreativeUsage::where('customer_id', $customer->id)->count());
    }

    public function test_separate_customers_keep_separate_quotas(): void
    {
        $one = Customer::factory()->create();
        $two = Customer::factory()->create();
        $user = User::factory()->create();
        $user->customers()->attach([$one->id, $two->id]);

        $service = app(CreativeQuotaService::class);

        session(['active_customer_id' => $one->id]);
        $first = $service->getOrCreateUsage($user);

        session(['active_customer_id' => $two->id]);
        $second = $service->getOrCreateUsage($user);

        $this->assertNotSame($first->id, $second->id);
    }

    public function test_a_stale_session_cannot_book_usage_against_another_customer(): void
    {
        // The active customer comes from the session, so it is attacker- and
        // accident-influenced. Ownership is re-checked rather than trusted.
        [$customer, $alice] = $this->customerWithTwoUsers();
        $someoneElse = Customer::factory()->create();

        session(['active_customer_id' => $someoneElse->id]);

        $usage = app(CreativeQuotaService::class)->getOrCreateUsage($alice);

        $this->assertSame($customer->id, $usage->customer_id);
        $this->assertNotSame($someoneElse->id, $usage->customer_id);
    }

    public function test_a_user_without_a_customer_still_gets_a_quota_row(): void
    {
        // Mid-onboarding there is no customer yet. The tool should degrade to
        // per-user rather than fail closed.
        $user = User::factory()->create();

        $usage = app(CreativeQuotaService::class)->getOrCreateUsage($user);

        $this->assertNull($usage->customer_id);
        $this->assertSame($user->id, $usage->user_id);
    }
}
