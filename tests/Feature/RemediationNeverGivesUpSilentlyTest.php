<?php

namespace Tests\Feature;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Agents\CampaignRemediationAgent;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

/**
 * The failure this exists to prevent.
 *
 * CampaignDiagnosticsAgent correctly raised `display_only_traffic` on the live
 * campaign 221 times across three weeks. Its prescribed remedy was
 * add_audience_signals, which had already been applied — so addAudienceSignals()
 * hit a bare `return`, recorded no action, sent no alert, and the campaign kept
 * spending 89% of its impressions on mobile-app inventory. Every diagnosis read
 * `{"actions_taken":[],"alerts_sent":[]}`.
 *
 * Two invariants now hold:
 *   1. Every finding ends in an action, an alert, or a recorded unresolved
 *      finding. Silence is not an option.
 *   2. A finding that keeps recurring unresolved escalates to a human, because
 *      a remedy that has run three times without clearing it is the wrong remedy.
 */
class RemediationNeverGivesUpSilentlyTest extends TestCase
{
    use RefreshDatabase;

    private function agent(): CampaignRemediationAgent
    {
        /** @var GeminiService&\Mockery\MockInterface $gemini */
        $gemini = Mockery::mock(GeminiService::class);

        return new CampaignRemediationAgent($gemini);
    }

    private function campaign(): Campaign
    {
        return Campaign::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
        ]);
    }

    /**
     * A finding whose auto_fix_action has no handler. The `default` arm of the
     * match routes it to alertCustomer, so it must not vanish.
     */
    private function unhandledFinding(): array
    {
        return [
            'type' => 'display_only_traffic',
            'severity' => 'high',
            'platform' => 'google_ads',
            'message' => '38,486 impressions with zero search-intent traffic',
            'can_auto_fix' => true,
            'auto_fix_action' => 'an_action_with_no_handler',
        ];
    }

    public function test_a_finding_that_cannot_be_actioned_is_never_silent(): void
    {
        Cache::flush();
        $campaign = $this->campaign();

        $results = $this->agent()->remediate($campaign, [$this->unhandledFinding()]);

        $accountedFor = count($results['actions_taken'])
            + count($results['alerts_sent'])
            + count($results['unresolved']);

        $this->assertGreaterThan(0, $accountedFor, 'the finding disappeared without a trace');
    }

    public function test_a_finding_that_cannot_even_be_alerted_is_still_recorded(): void
    {
        // The exact shape of the original bug: no action taken AND no alert
        // possible, because alertCustomer bails when the customer has no users.
        // Previously this produced literally nothing anywhere.
        Cache::flush();
        $campaign = Campaign::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
        ]);

        $results = $this->agent()->remediate($campaign, [$this->unhandledFinding()]);

        $this->assertNotEmpty($results['unresolved'], 'the finding was not recorded as unresolved');

        $this->assertTrue(
            AgentActivity::where('campaign_id', $campaign->id)
                ->where('action', 'unresolved_finding')->exists(),
            'nothing was written to agent_activities'
        );
    }

    public function test_a_second_pass_within_the_dedup_window_is_not_silent(): void
    {
        // alertCustomer caches for 24h, so the second hourly pass sends nothing.
        // That must surface as unresolved rather than as apparent success — the
        // silence that hid this for three weeks.
        Cache::flush();
        $campaign = $this->campaign();

        $this->agent()->remediate($campaign, [$this->unhandledFinding()]);
        $second = $this->agent()->remediate($campaign, [$this->unhandledFinding()]);

        $this->assertNotEmpty(
            $second['unresolved'],
            'a deduplicated alert must still register as an unresolved finding'
        );
    }

    public function test_a_recurring_finding_escalates_once_past_the_threshold(): void
    {
        Cache::flush();
        $campaign = $this->campaign();

        // Simulate the real history: the same finding diagnosed over and over.
        foreach (range(1, 5) as $i) {
            AgentActivity::record(
                'strategic_diagnosis',
                'diagnosed',
                '1 strategic issue(s) found',
                $campaign->customer_id,
                $campaign->id,
                ['findings' => [['type' => 'display_only_traffic', 'severity' => 'high']]]
            );
        }

        $this->agent()->remediate($campaign, [$this->unhandledFinding()]);

        $this->assertTrue(
            AgentActivity::where('campaign_id', $campaign->id)
                ->where('action', 'finding_escalated')->exists(),
            'a finding seen 5 times without resolution should reach a human'
        );
    }

    public function test_escalation_does_not_repeat_within_the_cooldown(): void
    {
        Cache::flush();
        $campaign = $this->campaign();

        foreach (range(1, 5) as $i) {
            AgentActivity::record(
                'strategic_diagnosis', 'diagnosed', 'issue',
                $campaign->customer_id, $campaign->id,
                ['findings' => [['type' => 'display_only_traffic']]]
            );
        }

        // Three passes in quick succession — an hourly job would do exactly this.
        $this->agent()->remediate($campaign, [$this->unhandledFinding()]);
        $this->agent()->remediate($campaign, [$this->unhandledFinding()]);
        $this->agent()->remediate($campaign, [$this->unhandledFinding()]);

        $this->assertSame(
            1,
            AgentActivity::where('campaign_id', $campaign->id)->where('action', 'finding_escalated')->count(),
            'escalation should be deduplicated, not sent every hour'
        );
    }

    public function test_a_finding_below_the_threshold_does_not_escalate(): void
    {
        Cache::flush();
        $campaign = $this->campaign();

        // Seen once. Worth recording, not worth waking anyone for.
        AgentActivity::record(
            'strategic_diagnosis', 'diagnosed', 'issue',
            $campaign->customer_id, $campaign->id,
            ['findings' => [['type' => 'display_only_traffic']]]
        );

        $this->agent()->remediate($campaign, [$this->unhandledFinding()]);

        $this->assertFalse(
            AgentActivity::where('campaign_id', $campaign->id)->where('action', 'finding_escalated')->exists()
        );
    }

    public function test_no_findings_still_returns_the_full_result_shape(): void
    {
        $results = $this->agent()->remediate($this->campaign(), []);

        foreach (['actions_taken', 'alerts_sent', 'unresolved', 'errors'] as $key) {
            $this->assertArrayHasKey($key, $results);
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
