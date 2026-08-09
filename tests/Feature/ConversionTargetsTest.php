<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\ConversionTargets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * A gtag conversion only counts when the account id and the label in `send_to`
 * belong to each other. Mismatch them and nothing complains: gtag fires, the
 * request succeeds, and Google discards the conversion.
 *
 * The frontend hardcoded AW-16797144138 while every provisioned label lived in
 * AW-18115663500, so roughly 48 real conversions across 30 days were dropped.
 * Maximize Conversions was left bidding on an empty history and the campaign
 * lost ~90% of its impression share to Ad Rank.
 *
 * These tests exist to make that class of drift impossible to reintroduce
 * quietly: the account id must always come from the same provisioning step as
 * the label it is paired with.
 */
class ConversionTargetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush(); // Setting::get memoises for an hour
    }

    public function test_send_to_pairs_the_label_with_its_own_provisioned_account(): void
    {
        Setting::set('conversion_label.try_now', 'PeiUCJmP6dIcEIytnL5D');
        Setting::set('conversion_aw_id.try_now', 'AW-18115663500');

        $this->assertSame(
            'AW-18115663500/PeiUCJmP6dIcEIytnL5D',
            ConversionTargets::sendTo('try_now')
        );
    }

    public function test_provisioned_account_wins_over_the_config_default(): void
    {
        // The regression: config drifted to a stale account while the real
        // action lived elsewhere. Whatever provisioning read off Google's own
        // tag snippet is authoritative.
        config(['conversions.aw_id' => 'AW-16797144138']);
        Setting::set('conversion_label.pricing_visit', 'qM7MCMfWzaccEIytnL5D');
        Setting::set('conversion_aw_id.pricing_visit', 'AW-18115663500');

        $this->assertSame(
            'AW-18115663500/qM7MCMfWzaccEIytnL5D',
            ConversionTargets::sendTo('pricing_visit')
        );
    }

    public function test_unprovisioned_event_yields_no_target_rather_than_a_broken_one(): void
    {
        // Better to fire nothing than to fire a send_to Google will throw away
        // while we read the local log and believe tracking works.
        $this->assertNull(ConversionTargets::sendTo('sandbox_launched'));
    }

    public function test_client_payload_only_contains_provisioned_client_events(): void
    {
        Setting::set('conversion_label.try_now', 'PeiUCJmP6dIcEIytnL5D');
        Setting::set('conversion_aw_id.try_now', 'AW-18115663500');

        $targets = ConversionTargets::forClient();

        $this->assertArrayHasKey('try_now', $targets);
        $this->assertSame('AW-18115663500/PeiUCJmP6dIcEIytnL5D', $targets['try_now']['send_to']);
        $this->assertArrayNotHasKey('sandbox_launched', $targets, 'unprovisioned events must be omitted');
    }

    public function test_signup_is_never_sent_client_side(): void
    {
        // Signup is uploaded server-side by RecordSiteGoogleConversion. If it
        // were also fired via gtag, both the WEBPAGE action and the upload
        // action are primary and every registration would count twice.
        Setting::set('conversion_label.signup', 'FHk5COLIz6ccEIytnL5D');
        Setting::set('conversion_aw_id.signup', 'AW-18115663500');

        $this->assertArrayNotHasKey('signup', ConversionTargets::forClient());
        $this->assertArrayNotHasKey('signup_import', ConversionTargets::forClient());
    }

    public function test_targets_carry_the_value_and_currency_from_config(): void
    {
        Setting::set('conversion_label.try_now', 'PeiUCJmP6dIcEIytnL5D');
        Setting::set('conversion_aw_id.try_now', 'AW-18115663500');

        $target = ConversionTargets::forClient()['try_now'];

        $this->assertSame(config('conversions.events.try_now.value'), $target['value']);
        $this->assertSame('USD', $target['currency']);
    }

    public function test_anonymous_visitor_conversion_records_the_gclid_from_the_session(): void
    {
        // Every one of the 48 logged events was anonymous, and the endpoint only
        // read $user->gclid — so all of them stored null and we could not tell
        // ad-driven conversions from organic ones.
        $this->withSession(['click_ids' => ['gclid' => 'TestGclid123']])
            ->postJson('/spectra/conversion', ['event' => 'try_now'])
            ->assertOk();

        $this->assertDatabaseHas('spectra_conversion_events', [
            'event' => 'try_now',
            'gclid' => 'TestGclid123',
            'user_id' => null,
        ]);
    }

    public function test_authenticated_visitor_falls_back_to_the_stored_gclid(): void
    {
        $user = User::factory()->create(['gclid' => 'StoredGclid456']);

        $this->actingAs($user)
            ->postJson('/spectra/conversion', ['event' => 'try_now'])
            ->assertOk();

        $this->assertDatabaseHas('spectra_conversion_events', [
            'event' => 'try_now',
            'gclid' => 'StoredGclid456',
            'user_id' => $user->id,
        ]);
    }

    public function test_unknown_events_are_rejected(): void
    {
        $this->postJson('/spectra/conversion', ['event' => 'not_a_real_event'])
            ->assertStatus(422);

        $this->assertDatabaseCount('spectra_conversion_events', 0);
    }
}
