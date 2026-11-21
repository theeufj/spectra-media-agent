<?php

namespace Tests\Feature;

use App\Jobs\DeliverCampaignOutcome;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Conversions\AdvertiserOutcomeOrigin;
use App\Services\Conversions\CampaignOutcomeEvidence;
use App\Services\GoogleAds\DataManagerService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MetaWebsiteProofTest extends TestCase
{
    use DatabaseTransactions;

    private Strategy $strategy;

    private string $browser = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36';

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSecond();
        config(['services.facebook.system_user_token' => 'fixture', 'services.facebook.graph_version' => 'v25.0']);
        $customer = Customer::factory()->create(['website' => 'https://shop.example', 'facebook_ads_account_id' => 'act_19871', 'facebook_pixel_id' => '12182']);
        $file = app(AdvertiserOutcomeOrigin::class)->file($customer, 'https://shop.example');
        $customer->forceFill(['verified_outcome_origin' => hash('sha256', $file['contents']), 'outcome_origin_verified_at' => now()])->save();
        $user = User::factory()->create();
        $customer->users()->attach($user->id, ['role' => 'owner']);
        $this->actingAs($user);
        $this->strategy = Strategy::factory()->for(Campaign::factory()->for($customer))->create(['platform' => 'Facebook', 'conversion_goals' => ['primary_goal' => 'Signup']]);
        $this->strategy->forceFill(['conversion_webhook_secret' => 'signed-site-fixture'])->save();
        Http::fake(['*/act_19871/adspixels*' => Http::response(['data' => [['id' => '12182']]]), '*/12182/events' => Http::response(['events_received' => 1, 'fbtrace_id' => 'confirmed-meta'])]);
    }

    private function submit(array $specific)
    {
        $body = json_encode(array_merge(['source_id' => 'site-event-1', 'mode' => 'test', 'occurred_at' => now()->toIso8601String(),
            'source_url' => 'https://shop.example/confirmation', 'goal' => 'signup', 'ad_user_data' => 'granted'], $specific), JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;

        return $this->call('POST', route('outcomes.record', $this->strategy->uuid), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_OUTCOME_TIMESTAMP' => $timestamp, 'HTTP_X_OUTCOME_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$body, 'signed-site-fixture')], $body);
    }

    private function challenge(): string
    {
        return $this->postJson(route('campaigns.outcome.test', [$this->strategy->campaign, $this->strategy->uuid]))
            ->assertOk()->assertJsonPath('destination', '12182')->json('challenge');
    }

    public static function eventMatches(): array
    {
        return [['signup', 'meta_email_sha256', 'em', 'CompleteRegistration'], ['purchase', 'meta_external_id_sha256', 'external_id', 'Purchase']];
    }

    #[DataProvider('eventMatches')]
    public function test_the_signed_browser_event_reaches_the_selected_dataset_without_any_ad_click(string $goal, string $inputField, string $wireField, string $eventName): void
    {
        $this->strategy->update(['conversion_goals' => ['primary_goal' => ucfirst($goal)]]);
        $hash = hash('sha256', $goal === 'signup' ? 'owner@shop.example' : 'SHOP-CUSTOMER-100');
        $input = ['challenge' => $this->challenge(), 'goal' => $goal, 'test_code' => 'TEST98765', $inputField => $hash, 'client_user_agent' => $this->browser];
        if ($goal === 'purchase') {
            $input += ['amount' => '24.95', 'currency' => 'AUD', 'order_id' => 'order-482'];
        }
        $event = $this->submit($input)->assertAccepted()->json('event_id');
        (new DeliverCampaignOutcome($event))->handle(app(CampaignOutcomeEvidence::class), app(DataManagerService::class));
        Http::assertSent(function (Request $request) use ($hash, $wireField, $eventName, $goal) {
            if ($request->method() !== 'POST' || $request->url() !== 'https://graph.facebook.com/v25.0/12182/events' || $request['test_event_code'] !== 'TEST98765') {
                return false;
            }
            $event = json_decode($request['data'], true, flags: JSON_THROW_ON_ERROR)[0];

            return $event['event_name'] === $eventName && $event['event_id'] === 'site-event-1' && $event['action_source'] === 'website' && $event['opt_out'] === true
                && $event['event_source_url'] === 'https://shop.example/confirmation' && $event['event_time'] === now()->timestamp
                && $event['user_data'] === [$wireField => [$hash], 'client_user_agent' => $this->browser]
                && ($goal !== 'purchase' || $event['custom_data'] === ['value' => 24.95, 'currency' => 'AUD']);
        });
        $evidence = app(CampaignOutcomeEvidence::class)->forStrategy($this->strategy);
        $this->assertTrue($evidence['verified_for_launch']);
        $this->assertSame(0, $evidence['verified_events']);
        $this->assertSame('unverified', $evidence['provider_attribution']);
        $this->assertDatabaseHas('campaign_outcome_events', ['id' => $event, 'delivery_status' => 'verified', 'provider_receipt' => 'confirmed-meta', 'payload' => null]);
    }

    public function test_missing_or_unconsented_match_data_never_sends_and_does_not_consume_a_correctable_challenge(): void
    {
        $challenge = $this->challenge();
        $base = ['challenge' => $challenge, 'test_code' => 'TEST98765', 'client_user_agent' => $this->browser];
        $this->submit($base)->assertUnprocessable()->assertJsonValidationErrors(['meta_email_sha256']);
        $this->submit($base + ['meta_email_sha256' => 'unhashed@shop.example'])->assertUnprocessable()->assertJsonValidationErrors(['meta_email_sha256']);
        $this->submit(['client_user_agent' => null, 'meta_external_id_sha256' => hash('sha256', 'customer-1')] + $base)->assertUnprocessable()->assertJsonValidationErrors(['client_user_agent']);
        $this->assertDatabaseHas('campaign_outcome_challenges', ['id' => $challenge, 'source_key' => null]);
        $id = $this->submit($base + ['ad_user_data' => 'denied', 'meta_email_sha256' => hash('sha256', 'private@shop.example')])->assertAccepted()->json('event_id');
        $stored = DB::table('campaign_outcome_events')->where('id', $id)->value('payload');
        if ($stored !== null) {
            $input = json_decode(Crypt::decryptString($stored), true, flags: JSON_THROW_ON_ERROR);
            $this->assertArrayNotHasKey('meta_email_sha256', $input);
            $this->assertArrayNotHasKey('client_user_agent', $input);
        }
        (new DeliverCampaignOutcome($id))->handle(app(CampaignOutcomeEvidence::class), app(DataManagerService::class));
        $this->assertFalse(app(CampaignOutcomeEvidence::class)->forStrategy($this->strategy)['verified_for_launch']);
        Http::assertNothingSent();
    }
}
