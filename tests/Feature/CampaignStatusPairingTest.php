<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two status columns must move together.
 *
 * `platform_status` is what the ad platform reports; `status` is our lifecycle
 * state. AdSpendBillingService filters on `status`, so a write that sets only
 * `platform_status` stops the ads while billing keeps charging for a campaign it
 * still believes is running.
 *
 * That has caused two separate billing leaks — BILL-8, and again in the admin
 * pause path, where pausing from the UI stopped the spend on Google and left the
 * customer being charged. Both times the code looked right in isolation; the
 * defect was that one of the pair was missing.
 */
class CampaignStatusPairingTest extends TestCase
{
    use RefreshDatabase;

    public function test_pausing_sets_both_columns(): void
    {
        $campaign = Campaign::factory()->create(['status' => CampaignStatus::Active]);

        $this->assertTrue($campaign->applyPlatformStatus('PAUSED'));

        $campaign->refresh();
        $this->assertSame(CampaignStatus::Paused, $campaign->status);
        $this->assertSame('PAUSED', $campaign->platform_status);
    }

    public function test_a_paused_campaign_is_not_billable(): void
    {
        // The specific query billing runs. If this ever passes a paused campaign,
        // the customer is being charged for ads that are not running.
        $campaign = Campaign::factory()->create(['status' => CampaignStatus::Active]);
        $campaign->applyPlatformStatus('PAUSED');

        $this->assertSame(
            0,
            Campaign::where('id', $campaign->id)->where('status', 'active')->count(),
            'a paused campaign must not appear in the billing query'
        );
    }

    public function test_resuming_sets_both_columns(): void
    {
        $campaign = Campaign::factory()->create(['status' => CampaignStatus::Paused]);

        $this->assertTrue($campaign->applyPlatformStatus('ENABLED'));

        $campaign->refresh();
        $this->assertSame(CampaignStatus::Active, $campaign->status);
        $this->assertSame('ENABLED', $campaign->platform_status);
    }

    public function test_removed_ends_the_campaign(): void
    {
        $campaign = Campaign::factory()->create(['status' => CampaignStatus::Active]);

        $campaign->applyPlatformStatus('REMOVED');

        $this->assertSame(CampaignStatus::Ended, $campaign->fresh()->status);
    }

    public function test_unknown_records_the_platform_status_without_guessing(): void
    {
        // Not knowing what the platform is doing is not a reason to change our
        // own record of what the campaign is for.
        $campaign = Campaign::factory()->create(['status' => CampaignStatus::Active]);

        $this->assertFalse($campaign->applyPlatformStatus('UNKNOWN'));

        $campaign->refresh();
        $this->assertSame(CampaignStatus::Active, $campaign->status);
        $this->assertSame('UNKNOWN', $campaign->platform_status);
    }

    public function test_a_draft_is_never_flipped_live_by_the_platform(): void
    {
        // A draft carrying a platform id is a data problem to investigate, not
        // one to paper over by marking it active.
        $campaign = Campaign::factory()->create(['status' => CampaignStatus::Draft]);

        $this->assertFalse($campaign->applyPlatformStatus('ENABLED'));

        $campaign->refresh();
        $this->assertSame(CampaignStatus::Draft, $campaign->status);
        $this->assertSame('ENABLED', $campaign->platform_status);
    }

    public function test_lowercase_platform_status_is_normalised(): void
    {
        $campaign = Campaign::factory()->create(['status' => CampaignStatus::Active]);

        $campaign->applyPlatformStatus('paused');

        $campaign->refresh();
        $this->assertSame('PAUSED', $campaign->platform_status);
        $this->assertSame(CampaignStatus::Paused, $campaign->status);
    }

    public function test_repeating_a_status_is_a_no_op_but_still_records_it(): void
    {
        $campaign = Campaign::factory()->create(['status' => CampaignStatus::Paused]);

        $this->assertFalse($campaign->applyPlatformStatus('PAUSED'));
        $this->assertSame('PAUSED', $campaign->fresh()->platform_status);
    }
}
