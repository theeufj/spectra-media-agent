<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Notification;
use App\Models\User;
use App\Notifications\CampaignStatusUpdated;
use App\Notifications\StrategyGenerationFailed;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * A notification in the bell has to say what happened.
 *
 * The Notification model lifts title/message/action_url out of a
 * notification's payload and falls back to the literal string "Notification"
 * and an empty body to satisfy its NOT NULL columns. Anything whose payload
 * omits those keys therefore lands in the bell as a blank row.
 *
 * In production that was 975 of 2,143 rows — 45% — and they were the two
 * events that matter most: 702 CampaignStatusUpdated ("your ads stopped
 * serving") and 273 StrategyGenerationFailed, whose actual reason was sitting
 * unread in the data column the whole time.
 */
class NotificationLegibilityTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{User, Campaign} */
    private function customerCampaign(array $campaignAttributes = []): array
    {
        $customer = Customer::factory()->create();
        $user = User::factory()->create();
        $customer->users()->attach($user->id, ['role' => 'owner']);

        return [$user, Campaign::factory()->create(array_merge(
            ['customer_id' => $customer->id, 'name' => 'Spring Lead Gen'],
            $campaignAttributes
        ))];
    }

    public function test_a_strategy_failure_says_why_in_the_bell(): void
    {
        [$user, $campaign] = $this->customerCampaign();

        // notifyNow, not notify: this one is ShouldQueue and the base TestCase
        // fakes the queue, so notify() would never reach the database channel.
        $user->notifyNow(new StrategyGenerationFailed($campaign, 'No knowledge base content found.'));

        $row = Notification::where('user_id', $user->id)->latest()->firstOrFail();

        $this->assertNotSame('Notification', $row->title);
        $this->assertStringContainsString('Spring Lead Gen', $row->title);
        $this->assertSame('No knowledge base content found.', $row->message);
        $this->assertNotNull($row->action_url);
    }

    public function test_a_campaign_that_stops_serving_says_so_in_plain_words(): void
    {
        [$user, $campaign] = $this->customerCampaign(['primary_status' => 'PAUSED']);

        $user->notify(new CampaignStatusUpdated($campaign));

        $row = Notification::where('user_id', $user->id)->latest()->firstOrFail();

        $this->assertSame('Spring Lead Gen has been paused', $row->title);
        $this->assertStringContainsString('not serving ads', $row->message);

        // The SDK's own enum name is not a word to show a customer.
        $this->assertStringNotContainsString('PAUSED', $row->title.$row->message);
    }

    public function test_a_status_change_reaches_the_bell_at_all(): void
    {
        // It was mail-only. An email nobody opens is not a notification.
        $this->assertContains(
            'database',
            (new CampaignStatusUpdated(Campaign::factory()->create()))->via(new User)
        );
    }

    /** @return array<string, array{string, string}> */
    public static function statuses(): array
    {
        return [
            'eligible' => ['ELIGIBLE', 'running'],
            'paused' => ['PAUSED', 'paused'],
            'limited' => ['LIMITED', 'limited'],
            'misconfigured' => ['MISCONFIGURED', 'attention'],
            'ended' => ['ENDED', 'finished'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('statuses')]
    public function test_every_status_gets_its_own_wording(string $status, string $expected): void
    {
        [$user, $campaign] = $this->customerCampaign(['primary_status' => $status]);

        $payload = (new CampaignStatusUpdated($campaign))->toArray($user);

        $this->assertStringContainsString($expected, strtolower($payload['title']));
        $this->assertNotSame('', $payload['message']);
    }

    public function test_an_unknown_status_still_produces_something_readable(): void
    {
        // Google adds enum values between SDK releases; the bell must not go
        // blank because of one.
        [$user, $campaign] = $this->customerCampaign(['primary_status' => 'SOMETHING_NEW']);

        $payload = (new CampaignStatusUpdated($campaign))->toArray($user);

        $this->assertStringContainsString('Spring Lead Gen', $payload['title']);
        $this->assertNotSame('', $payload['message']);
        $this->assertStringNotContainsString('SOMETHING_NEW', $payload['title']);
    }
}
