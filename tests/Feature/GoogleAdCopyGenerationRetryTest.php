<?php

namespace Tests\Feature;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Services\GoogleAds\GenerateReviewedRsaCopy;
use App\Services\GoogleAds\GoogleAdStrengthRepair;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoogleAdCopyGenerationRetryTest extends TestCase
{
    use DatabaseTransactions;

    private const AD = 'customers/123/adGroupAds/456~789';

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
    }

    private function campaign(): Campaign
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '123']);

        return Campaign::factory()->create(['customer_id' => $customer->id,
            'google_ads_campaign_id' => 'customers/123/campaigns/9']);
    }

    private function failure(Campaign $campaign, array $details = []): AgentActivity
    {
        return AgentActivity::record('quality_score', 'ad_strength_repair_attempt',
            'Replacement RSA copy did not pass evidence and relevance review.', $campaign->customer_id, $campaign->id,
            array_merge(['ad_resource' => self::AD, 'reason' => 'repair_failed',
                'error' => 'Replacement RSA copy did not pass evidence and relevance review.',
                'error_code' => 'ad_strength_repair_failed', 'retry_after' => now()->subMinute()->toIso8601String()], $details), 'needs_review');
    }

    private function exhausted(Campaign $campaign): AgentActivity
    {
        $this->failure($campaign, ['error' => 'RSA assets must contain 1–90 characters.']);
        $this->failure($campaign);

        return $this->failure($campaign);
    }

    private function ad(): array
    {
        return ['resource_name' => self::AD, 'ad_strength' => 'POOR', 'action_items' => []];
    }

    public function test_one_audited_authorization_permits_one_attempt_without_removing_history(): void
    {
        $campaign = $this->campaign();
        $last = $this->exhausted($campaign);
        $history = AgentActivity::where('campaign_id', $campaign->id)->pluck('id')->all();
        $state = app(GoogleAdStrengthRepair::class);
        $grant = $state->authorizeGenerationRetry($campaign, self::AD, 'Operator requested one retry after deploying reviewer feedback corrections.');
        $this->assertSame($last->id, $grant->details['failed_attempt_id']);
        $this->assertSame(GenerateReviewedRsaCopy::REVISION, $grant->details['generation_revision']);
        $this->assertSame('pending', $grant->status);
        $this->assertTrue($state->hasAuthorizedGenerationRetry($campaign, self::AD));

        $attempt = $state->start($campaign, $this->ad());

        $this->assertSame(4, $state->attemptCount($campaign, self::AD));
        $this->assertSame($grant->id, $attempt->details['generation_retry_authorization_id']);
        $this->assertSame(GenerateReviewedRsaCopy::REVISION, $attempt->details['generation_revision']);
        $this->assertSame('completed', $grant->fresh()->status);
        $this->assertSame($attempt->id, $grant->fresh()->details['attempt_id']);
        $this->assertSame(3, AgentActivity::whereIn('id', $history)->count());
        $this->assertFalse($state->hasAuthorizedGenerationRetry($campaign, self::AD));
        $this->expectException(\DomainException::class);
        $state->start($campaign, $this->ad());
    }

    public function test_normal_attempts_record_revision_and_cannot_start_a_fourth_without_authorization(): void
    {
        $campaign = $this->campaign();
        $state = app(GoogleAdStrengthRepair::class);
        $attempt = $state->start($campaign, $this->ad());
        $this->assertSame(GenerateReviewedRsaCopy::REVISION, $attempt->details['generation_revision']);
        $this->assertArrayNotHasKey('generation_retry_authorization_id', $attempt->details);
        $this->failure($campaign);
        $this->failure($campaign);
        $this->expectException(\DomainException::class);
        $state->start($campaign, $this->ad());
    }

    public function test_duplicate_authorizations_are_denied_for_seven_days_even_after_use(): void
    {
        $campaign = $this->campaign();
        $this->exhausted($campaign);
        $state = app(GoogleAdStrengthRepair::class);
        $state->authorizeGenerationRetry($campaign, self::AD, 'One reviewed recovery.');
        try {
            $state->authorizeGenerationRetry($campaign, self::AD, 'A duplicate pending grant.');
            $this->fail('An existing authorization must prevent a second grant.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('already been authorized', $e->getMessage());
        }
        $attempt = $state->start($campaign, $this->ad());
        $attempt->update(['status' => 'needs_review', 'details' => array_merge($attempt->details, [
            'reason' => 'repair_failed', 'error_code' => 'ad_strength_copy_rejected',
        ])]);
        $this->expectException(\DomainException::class);
        $state->authorizeGenerationRetry($campaign, self::AD, 'Attempting to bypass the bounded recovery.');
    }

    public function test_a_previously_overshot_limit_cannot_receive_another_authorization(): void
    {
        $campaign = $this->campaign();
        $this->exhausted($campaign);
        $this->failure($campaign);
        $state = app(GoogleAdStrengthRepair::class);
        $this->assertSame(4, $state->attemptCount($campaign, self::AD));
        $this->assertFalse($state->hasAuthorizedGenerationRetry($campaign, self::AD));
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('exact repair limit');
        $state->authorizeGenerationRetry($campaign, self::AD, 'Do not expand an already exceeded repair limit.');
    }

    public function test_authorization_does_not_bypass_the_existing_retry_cooldown(): void
    {
        $campaign = $this->campaign();
        $last = $this->exhausted($campaign);
        $last->update(['details' => array_merge($last->details, ['retry_after' => now()->addHour()->toIso8601String()])]);
        $state = app(GoogleAdStrengthRepair::class);
        $state->authorizeGenerationRetry($campaign, self::AD, 'Wait for the existing cooldown then retry once.');
        $this->assertFalse($state->hasAuthorizedGenerationRetry($campaign, self::AD));
        $this->travel(61)->minutes();
        $this->assertTrue($state->hasAuthorizedGenerationRetry($campaign, self::AD));
    }

    public static function unsafeAttempts(): array
    {
        return [
            'submitted copy' => [['submitted_at' => '2026-01-01T00:00:00Z']],
            'unconfirmed API write' => [['google_write_started_at' => '2026-01-01T00:00:00Z']],
            'generic API rejection' => [['error' => 'Google did not accept the RSA update.']],
            'policy failure' => [['reason' => 'policy_disapproved']],
            'provider uncertainty' => [['error' => 'Provider timed out.']],
        ];
    }

    #[DataProvider('unsafeAttempts')]
    public function test_submitted_unknown_and_policy_failures_cannot_receive_a_generation_retry(array $details): void
    {
        $campaign = $this->campaign();
        $last = $this->exhausted($campaign);
        $last->update(['details' => array_merge($last->details, $details)]);
        $this->expectException(\DomainException::class);
        app(GoogleAdStrengthRepair::class)->authorizeGenerationRetry($campaign, self::AD, 'Recovery must not bypass a Google write or unknown outcome.');
    }

    public function test_an_authorization_cannot_be_reused_for_a_newer_failure(): void
    {
        $campaign = $this->campaign();
        $this->exhausted($campaign);
        $state = app(GoogleAdStrengthRepair::class);
        $grant = $state->authorizeGenerationRetry($campaign, self::AD, 'Only the recorded failed attempt.');
        $this->failure($campaign);
        $this->assertFalse($state->hasAuthorizedGenerationRetry($campaign, self::AD));
        $this->assertSame('pending', $grant->fresh()->status);
        $this->expectException(\DomainException::class);
        $state->start($campaign, $this->ad());
    }

    public function test_expired_and_wrong_revision_authorizations_are_not_usable(): void
    {
        $campaign = $this->campaign();
        $this->exhausted($campaign);
        $state = app(GoogleAdStrengthRepair::class);
        $grant = $state->authorizeGenerationRetry($campaign, self::AD, 'Only this deployed pipeline revision.');
        $details = $grant->details;
        $grant->update(['details' => array_merge($details, ['generation_revision' => 'older_revision'])]);
        $this->assertFalse($state->hasAuthorizedGenerationRetry($campaign, self::AD));
        $grant->update(['details' => $details]);
        $this->travel(25)->hours();
        $this->assertFalse($state->hasAuthorizedGenerationRetry($campaign, self::AD));
    }

    public function test_authorization_requires_an_exhausted_limit_and_customer_owned_resource(): void
    {
        $campaign = $this->campaign();
        $this->failure($campaign);
        $state = app(GoogleAdStrengthRepair::class);
        try {
            $state->authorizeGenerationRetry($campaign, self::AD, 'Too early.');
            $this->fail('An unexhausted repair limit must not receive a grant.');
        } catch (\DomainException) {
            $this->assertSame(0, AgentActivity::where('campaign_id', $campaign->id)->where('action', 'ad_copy_generation_retry_authorized')->count());
        }
        $this->expectException(\InvalidArgumentException::class);
        $state->authorizeGenerationRetry($campaign, 'customers/999/adGroupAds/456~789', 'Wrong customer account.');
    }
}
